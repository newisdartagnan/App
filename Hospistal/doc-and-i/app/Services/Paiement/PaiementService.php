<?php

namespace App\Services\Paiement;

use App\Enums\MotifRemboursement;
use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\EvenementPaiement;
use App\Models\Facture;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Paiement\Fournisseur\ErreurFournisseur;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Services\Paiement\Fournisseur\RappelInvalide;
use App\Services\RendezVous\ReservationService;
use App\Sms\EnvoyeurSms;
use App\Support\Montant;
use App\Support\Telephone;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Paiement d'une consultation par le patient (PAY-02 à PAY-05, RG-01 à RG-03).
 *
 * Règle d'or (PAY-04, RG-01) : rien n'est confirmé sur la parole du
 * navigateur. initier() demande le débit ; seul un rappel serveur dont la
 * signature est vérifiée (recevoirRappel) encaisse, facture, inscrit au
 * journal et confirme le rendez-vous.
 *
 * Ordre des verrous, le même que ReservationService : agenda du médecin
 * (verrou consultatif), puis lignes (rendez-vous, paiement). L'appel au
 * prestataire se fait hors transaction : pas d'appel réseau sous verrou.
 */
class PaiementService
{
    public function __construct(
        private readonly FournisseurPaiement $fournisseur,
        private readonly TarificationService $tarification,
        private readonly FacturationService $facturation,
        private readonly JournalCaisse $journal,
        private readonly RemboursementService $remboursements,
        private readonly ReservationService $reservations,
        private readonly EnvoyeurSms $sms,
    ) {}

    /**
     * Demande le débit du rendez-vous en attente de paiement.
     *
     * RG-02 : une même clé d'idempotence (double clic, page renvoyée) rend la
     * même tentative ; une tentative déjà en cours pour ce rendez-vous est
     * rendue telle quelle, sans second débit.
     *
     * Tentative restée « initiée » après une erreur technique : un nouvel
     * envoi (même avec une autre clé de page) rend CETTE tentative et la
     * renvoie au prestataire avec SA clé ; on n'en ouvre pas une seconde tant
     * qu'elle n'est pas close (rappel signé ou expiration). Ainsi un débit
     * parti malgré l'erreur n'est jamais doublé par une seconde demande.
     *
     * $montantAttendu : le montant affiché au patient ; s'il ne correspond
     * plus au tarif recalculé (taux corrigé entre-temps), rien n'est débité.
     *
     * @throws PaiementRefuse
     */
    public function initier(RendezVous $rdv, User $patient, MoyenPaiement $moyen, string $devise, ?string $telephone, string $cle, ?string $montantAttendu = null): TentativePaiement
    {
        if (! preg_match('/^[A-Za-z0-9-]{16,64}$/', $cle)) {
            throw new PaiementRefuse('La page de paiement a expiré. Rechargez-la, puis réessayez.');
        }

        $numero = null;
        if ($moyen->estMobileMoney()) {
            $numero = Telephone::normaliser($telephone)
                ?? throw new PaiementRefuse('Indiquez le numéro Mobile Money à débiter, par exemple 081 234 56 78.');
        }

        try {
            $paiement = DB::transaction(fn () => $this->preparer($rdv, $patient, $moyen, $devise, $numero, $cle, $montantAttendu));
        } catch (UniqueConstraintViolationException $e) {
            // Deux envois simultanés : l'un a créé la tentative, l'autre la reprend.
            $paiement = Paiement::where('cle_idempotence', $cle)->first()
                ?? Paiement::where('rendez_vous_id', $rdv->id)->whereIn('statut', StatutPaiement::enCours())->first()
                ?? throw $e;

            if ($paiement->patient_id !== $patient->id) {
                throw new PaiementRefuse('La page de paiement a expiré. Rechargez-la, puis réessayez.');
            }
        }

        // Une tentative encore « initiée » n'a pas reçu la réponse du
        // prestataire (coupure) : on la lui redemande ; il est idempotent par
        // la clé, le patient n'est pas débité deux fois.
        return $paiement->statut === StatutPaiement::Initie
            ? $this->demanderAuFournisseur($paiement)
            : new TentativePaiement($paiement);
    }

    /**
     * Le patient relance une tentative restée sans réponse de l'opérateur
     * (« initiée » après une erreur technique) : même clé, pas de second
     * débit. Sans effet sur une tentative acceptée, close, ou dont le
     * créneau n'est plus à payer.
     *
     * @throws PaiementRefuse
     */
    public function relancer(Paiement $paiement, User $patient): TentativePaiement
    {
        if ($paiement->patient_id !== $patient->id) {
            throw new PaiementRefuse('Ce paiement n\'est pas le vôtre.');
        }

        $paiement = $paiement->fresh();
        $rdv = $paiement->rendezVous;

        if ($paiement->statut !== StatutPaiement::Initie
            || ! $rdv
            || $rdv->statut !== StatutRendezVous::EnAttentePaiement
            || $this->reservations->estEchue($rdv)) {
            return new TentativePaiement($paiement);
        }

        return $this->demanderAuFournisseur($paiement);
    }

    /**
     * Rappel serveur du prestataire (PAY-04). Signature vérifiée par le
     * pilote ; l'événement est enregistré ET traité dans une seule
     * transaction : un rappel rejoué n'a pas d'effet, un plantage n'enregistre
     * rien et l'opérateur renverra le rappel.
     *
     * @return int code HTTP à rendre au prestataire
     */
    public function recevoirRappel(Request $requete): int
    {
        try {
            $rappel = $this->fournisseur->lireRappel($requete);
        } catch (RappelInvalide $e) {
            // Ni la charge utile ni l'en-tête dans le journal : seulement la raison.
            Log::warning('Rappel de paiement rejeté.', [
                'fournisseur' => $this->fournisseur->nom(),
                'raison' => $e->getMessage(),
                'ip' => $requete->ip(),
            ]);

            return 401;
        }

        try {
            $sms = DB::transaction(fn () => $this->enregistrerEtTraiter($rappel));
        } catch (UniqueConstraintViolationException $e) {
            // Même rappel reçu deux fois en même temps : l'autre l'a traité.
            if ($this->evenementConnu($rappel)) {
                return 200;
            }
            throw $e;
        }

        // Reçu par SMS après validation de la transaction (aucune donnée de santé).
        if ($sms !== null) {
            $this->envoyerSms(...$sms);
        }

        return 200;
    }

    /** Tentatives sans réponse de l'opérateur dans le délai : closes chez nous. Le patient peut réessayer. */
    public function expirerTentatives(): int
    {
        $limite = CarbonImmutable::now()->subMinutes((int) config('doc-and-i.paiement.delai_confirmation_minutes'));
        $expirees = 0;

        $ids = Paiement::whereIn('statut', StatutPaiement::enCours())->where('created_at', '<=', $limite)->orderBy('id')->pluck('id');

        foreach ($ids as $id) {
            $expirees += DB::transaction(function () use ($id, $limite) {
                $paiement = Paiement::whereKey($id)->lockForUpdate()->first();
                if (! $paiement || ! $paiement->statut->estEnCours() || $paiement->created_at > $limite) {
                    return 0;
                }

                $paiement->transitionVers(StatutPaiement::Expire);
                $paiement->motif_echec = 'Pas de confirmation de l\'opérateur dans le délai.';
                $paiement->save();

                return 1;
            });
        }

        return $expirees;
    }

    /** Dans la transaction : vérifications et création de la tentative « initiée ». */
    private function preparer(RendezVous $rdv, User $patient, MoyenPaiement $moyen, string $devise, ?string $numero, string $cle, ?string $montantAttendu): Paiement
    {
        $this->reservations->verrouillerAgenda($rdv->medecin_id);
        $rdv = RendezVous::whereKey($rdv->id)->lockForUpdate()->firstOrFail();

        if ($rdv->patient_id !== $patient->id) {
            throw new PaiementRefuse('Ce rendez-vous n\'est pas le vôtre.');
        }

        if ($existant = Paiement::where('cle_idempotence', $cle)->first()) {
            if ($existant->patient_id !== $patient->id || $existant->rendez_vous_id !== $rdv->id) {
                throw new PaiementRefuse('La page de paiement a expiré. Rechargez-la, puis réessayez.');
            }

            return $existant;
        }

        if ($enCours = Paiement::where('rendez_vous_id', $rdv->id)->whereIn('statut', StatutPaiement::enCours())->first()) {
            return $enCours;
        }

        if (Paiement::where('rendez_vous_id', $rdv->id)->where('statut', StatutPaiement::Reussi->value)->exists()) {
            throw new PaiementRefuse('Ce rendez-vous est déjà payé.');
        }

        if ($rdv->statut !== StatutRendezVous::EnAttentePaiement) {
            throw new PaiementRefuse('Ce rendez-vous n\'attend pas de paiement.');
        }

        if ($this->reservations->estEchue($rdv)) {
            throw new PaiementRefuse('Le délai pour payer ce créneau est dépassé. Choisissez un autre créneau.');
        }

        if (Paiement::where('rendez_vous_id', $rdv->id)->count() >= (int) config('doc-and-i.paiement.tentatives_par_rendez_vous')) {
            throw new PaiementRefuse('Trop de tentatives de paiement pour ce rendez-vous. Choisissez un autre créneau plus tard, ou contactez le support.');
        }

        $tarif = $this->tarification->pourRendezVous($rdv, $devise);

        // Le patient paie le montant qu'il a vu, ou rien.
        if ($montantAttendu !== null && Montant::enCentimes($montantAttendu) !== Montant::enCentimes($tarif->montant)) {
            throw new PaiementRefuse('Le montant a changé : vérifiez le nouveau montant, puis payez.');
        }

        $paiement = new Paiement;
        $paiement->forceFill([
            'patient_id' => $patient->id,
            'rendez_vous_id' => $rdv->id,
            'objet' => 'consultation',
            'moyen' => $moyen,
            'telephone_payeur' => $numero,
            'montant' => $tarif->montant,
            'devise' => $tarif->devise,
            'montant_reference' => $tarif->montantReference,
            'devise_reference' => $tarif->deviseReference,
            'taux_change_id' => $tarif->taux?->id,
            'statut' => StatutPaiement::Initie,
            'cle_idempotence' => $cle,
            'fournisseur' => $this->fournisseur->nom(),
        ])->save();

        return $paiement;
    }

    /** Hors transaction : l'appel réseau, puis le résultat posé sous verrou. */
    private function demanderAuFournisseur(Paiement $paiement): TentativePaiement
    {
        try {
            $initiation = $this->fournisseur->initier($paiement);
        } catch (ErreurFournisseur $e) {
            if (! $e->refusDefinitif) {
                // Erreur technique (délai, coupure) : la demande est peut-être
                // partie et le patient peut valider sur son téléphone. La
                // tentative reste ouverte (« initiée ») : une relance repart
                // avec la même clé, le rappel signé ou l'expiration la clôt.
                // La marquer « échouée » ferait perdre un débit réel (revue de sécurité).
                return new TentativePaiement($paiement->fresh());
            }

            // Refus explicite : rien n'est parti, la tentative est close.
            $paiement = DB::transaction(function () use ($paiement, $e) {
                $p = Paiement::whereKey($paiement->id)->lockForUpdate()->firstOrFail();
                if ($p->statut === StatutPaiement::Initie) {
                    $p->transitionVers(StatutPaiement::Echoue);
                    $p->echoue_le = CarbonImmutable::now();
                    $p->motif_echec = mb_substr($e->getMessage() ?: 'L\'opérateur n\'a pas accepté la demande.', 0, 255);
                    $p->save();
                }

                return $p;
            });

            return new TentativePaiement($paiement);
        }

        $paiement = DB::transaction(function () use ($paiement, $initiation) {
            $p = Paiement::whereKey($paiement->id)->lockForUpdate()->firstOrFail();
            if ($p->statut === StatutPaiement::Initie) {
                $p->transitionVers(StatutPaiement::EnAttente);
            }
            $p->reference_fournisseur ??= mb_substr($initiation->referenceFournisseur, 0, 100);
            $p->save();

            return $p;
        });

        return new TentativePaiement($paiement, $initiation);
    }

    /**
     * Dans la transaction du rappel.
     *
     * @return array{0: User, 1: string, 2: string}|null SMS à envoyer après validation (patient, texte, paiement)
     */
    private function enregistrerEtTraiter(RappelFournisseur $rappel): ?array
    {
        if ($this->evenementConnu($rappel)) {
            return null; // Rappel rejoué : déjà traité, sans effet.
        }

        $evenement = new EvenementPaiement;
        $evenement->forceFill([
            'fournisseur' => $this->fournisseur->nom(),
            'identifiant_evenement' => $rappel->identifiantEvenement,
            'type' => $rappel->type,
            'charge_utile' => $rappel->chargeUtile,
            'recu_le' => CarbonImmutable::now(),
        ])->save();

        $sms = null;
        if ($rappel->type === RappelFournisseur::REMBOURSEMENT) {
            // Lot caisse (PAY-07) : exécution des remboursements.
            $this->remboursements->traiterRappel($rappel, $evenement);
        } else {
            $sms = $this->traiterRappelPaiement($rappel, $evenement);
        }

        $evenement->traite_le = CarbonImmutable::now();
        $evenement->save();

        return $sms;
    }

    /**
     * Rappel de paiement authentifié : retrouve le paiement par notre clé
     * (référence marchand) ET le prestataire ; montant et devise doivent être
     * exactement ceux demandés, sinon rien n'est confirmé (le rapprochement
     * de la caisse reprend l'écart).
     *
     * @return array{0: User, 1: string, 2: string}|null
     */
    private function traiterRappelPaiement(RappelFournisseur $rappel, EvenementPaiement $evenement): ?array
    {
        $cible = Paiement::where('cle_idempotence', $rappel->referenceMarchand)
            ->where('fournisseur', $evenement->fournisseur)
            ->first();

        if (! $cible) {
            $evenement->resultat = 'Référence inconnue : rien n\'est confirmé.';

            return null;
        }

        if ($cible->rendez_vous_id && ($medecinId = RendezVous::whereKey($cible->rendez_vous_id)->value('medecin_id'))) {
            $this->reservations->verrouillerAgenda($medecinId);
        }
        $paiement = Paiement::whereKey($cible->id)->lockForUpdate()->firstOrFail();
        $evenement->paiement_id = $paiement->id;

        if (Montant::enCentimes($rappel->montant) !== Montant::enCentimes((string) $paiement->montant) || $rappel->devise !== $paiement->devise) {
            $evenement->resultat = 'Montant ou devise différent du paiement : rien n\'est confirmé, écart à rapprocher.';

            return null;
        }

        if (! $rappel->reussi) {
            if (! $paiement->statut->estEnCours()) {
                $evenement->resultat = "Échec reçu pour un paiement {$paiement->statut->value} : sans effet.";

                return null;
            }

            $paiement->transitionVers(StatutPaiement::Echoue);
            $paiement->echoue_le = CarbonImmutable::now();
            $paiement->motif_echec = mb_substr($rappel->motifEchec ?? 'Paiement refusé par l\'opérateur.', 0, 255);
            $paiement->reference_fournisseur ??= $rappel->referenceFournisseur;
            $paiement->save();
            $evenement->resultat = 'Échec enregistré : le patient peut réessayer.';

            return null;
        }

        if ($paiement->statut === StatutPaiement::Reussi) {
            $evenement->resultat = 'Paiement déjà encaissé : sans effet.';

            return null;
        }

        if (! $paiement->statut->peutPasserA(StatutPaiement::Reussi)) {
            $evenement->resultat = "Réussite reçue pour un paiement {$paiement->statut->value} : écart à rapprocher.";

            return null;
        }

        // RG-01 : encaissement, facture et journal dans la même transaction.
        $paiement->transitionVers(StatutPaiement::Reussi);
        $paiement->confirme_le = CarbonImmutable::now();
        $paiement->reference_fournisseur ??= $rappel->referenceFournisseur;
        $paiement->save();

        $facture = $this->facturation->emettreFacture($paiement);
        $this->journal->encaissement($paiement);

        [$issue, $texte] = $this->rattacherAuRendezVous($paiement, $facture);
        $evenement->resultat = $issue;

        return [$paiement->patient, $texte, $paiement->id];
    }

    /**
     * Le rendez-vous du paiement encaissé (RG-03) : confirmé, repris, ou
     * paiement remboursé automatiquement (tardif ou en double).
     *
     * @return array{0: string, 1: string} résultat pour l'événement, texte du SMS
     */
    private function rattacherAuRendezVous(Paiement $paiement, Facture $facture): array
    {
        $recu = 'MEDIHOME : paiement de '.$this->montantSms($paiement).' reçu (reçu '.$facture->numero.').';
        $rdv = $paiement->rendezVous;
        if (! $rdv) {
            return ['Encaissé.', $recu];
        }

        $dejaPaye = Paiement::where('rendez_vous_id', $paiement->rendez_vous_id)
            ->where('statut', StatutPaiement::Reussi->value)
            ->whereKeyNot($paiement->id)
            ->exists();

        $issue = $dejaPaye ? ReservationService::DEJA_CONFIRME : $this->reservations->confirmerApresPaiement($rdv);

        if (in_array($issue, [ReservationService::CONFIRME, ReservationService::REPRIS], true)) {
            $rdv->refresh();
            $debut = $rdv->debut->setTimezone('Africa/Kinshasa');

            return [
                $issue === ReservationService::REPRIS ? 'Encaissé ; rendez-vous expiré repris, créneau encore libre (RG-03).' : 'Encaissé ; rendez-vous confirmé.',
                $recu.' Votre rendez-vous du '.$debut->format('d/m').' à '.$debut->format('H:i').' est confirmé.',
            ];
        }

        if ($issue === ReservationService::DEJA_CONFIRME) {
            $this->remboursements->demander($paiement, (string) $paiement->montant, MotifRemboursement::PaiementEnDouble);

            return [
                'Encaissé ; rendez-vous déjà payé : remboursement automatique (paiement en double).',
                $recu.' Ce rendez-vous était déjà payé : ce paiement vous sera remboursé.',
            ];
        }

        $this->remboursements->demander($paiement, (string) $paiement->montant, MotifRemboursement::PaiementTardif);

        return [
            'Encaissé ; créneau plus disponible : remboursement automatique (RG-03).',
            $recu.' Le créneau n\'était plus disponible : vous serez remboursé. Ouvrez l\'application pour choisir un autre créneau.',
        ];
    }

    private function evenementConnu(RappelFournisseur $rappel): bool
    {
        return EvenementPaiement::where('fournisseur', $this->fournisseur->nom())
            ->where('identifiant_evenement', $rappel->identifiantEvenement)
            ->exists();
    }

    /** Montant lisible dans un SMS : espaces ordinaires (alphabet GSM). */
    private function montantSms(Paiement $paiement): string
    {
        return Montant::afficher((string) $paiement->montant, $paiement->devise);
    }

    private function envoyerSms(User $patient, string $texte, string $paiementId): void
    {
        if (! $patient->telephone) {
            return;
        }

        try {
            $this->sms->envoyer($patient->telephone, $texte);
        } catch (Throwable $e) {
            // L'encaissement est validé ; le reçu reste dans « Mes paiements ».
            // Seulement la classe de l'erreur : le message d'un pilote SMS peut
            // contenir le numéro du destinataire.
            Log::warning('Reçu de paiement non envoyé par SMS.', ['paiement' => $paiementId, 'erreur' => $e::class]);
        }
    }
}
