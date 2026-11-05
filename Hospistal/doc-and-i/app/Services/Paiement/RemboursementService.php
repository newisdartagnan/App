<?php

namespace App\Services\Paiement;

use App\Enums\MotifRemboursement;
use App\Enums\MoyenPaiement;
use App\Enums\StatutPaiement;
use App\Enums\StatutRemboursement;
use App\Models\EvenementPaiement;
use App\Models\Paiement;
use App\Models\Remboursement;
use App\Models\User;
use App\Services\Paiement\Fournisseur\ErreurFournisseur;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\Fournisseur\RappelFournisseur;
use App\Sms\EnvoyeurSms;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;

/**
 * PAY-07 : remboursements, toujours sur le moyen de paiement d'origine.
 *
 * Socle (ce fichier) : demander() et desengager(), qui tiennent le cumul
 * paiements.montant_rembourse sous verrou du paiement. La base refuse tout
 * cumul supérieur au montant payé.
 *
 * Lot caisse : valider() / refuser() (quatre yeux), executer() auprès du
 * prestataire, traiterRappel() du prestataire, avoir et journal.
 */
class RemboursementService
{
    /**
     * Crée la demande. Remboursement de règle (paiement tardif, en double) :
     * né validé, sans validation humaine. Les autres attendent la caisse.
     * Idempotent par motif (sauf geste commercial) : une annulation rejouée
     * rend la demande existante.
     *
     * @param  string  $montant  chaîne décimale (« 5.00 »), dans la devise du paiement
     *
     * @throws PaiementRefuse
     */
    public function demander(Paiement $paiement, string $montant, MotifRemboursement $motif, ?User $demandeur = null, ?string $justification = null): Remboursement
    {
        $centimes = Montant::enCentimes($montant);
        if ($centimes <= 0) {
            throw new PaiementRefuse('Le montant à rembourser doit être positif.');
        }

        if ($motif === MotifRemboursement::GesteCommercial && $demandeur === null) {
            throw new PaiementRefuse('Un geste commercial est demandé par un agent de caisse identifié.');
        }

        return DB::transaction(function () use ($paiement, $centimes, $motif, $demandeur, $justification) {
            $paiement = Paiement::whereKey($paiement->id)->lockForUpdate()->firstOrFail();

            if ($paiement->statut !== StatutPaiement::Reussi) {
                throw new PaiementRefuse('Seul un paiement encaissé peut être remboursé.');
            }

            if ($motif !== MotifRemboursement::GesteCommercial) {
                $existant = Remboursement::where('paiement_id', $paiement->id)
                    ->where('motif', $motif->value)
                    ->whereIn('statut', StatutRemboursement::engages())
                    ->first();
                if ($existant) {
                    return $existant;
                }
            }

            if ($centimes > $paiement->resteRemboursableEnCentimes()) {
                throw new PaiementRefuse('Ce remboursement dépasse ce qui reste remboursable sur ce paiement.');
            }

            $remboursement = new Remboursement;
            $remboursement->forceFill([
                'paiement_id' => $paiement->id,
                'montant' => Montant::depuisCentimes($centimes),
                'devise' => $paiement->devise,
                'motif' => $motif,
                'justification' => $justification === null ? null : mb_substr(trim($justification), 0, 255),
                'statut' => $motif->estAutomatique() ? StatutRemboursement::Valide : StatutRemboursement::AValider,
                'automatique' => $motif->estAutomatique(),
                'demande_par' => $demandeur?->id,
                'cle_idempotence' => (string) Str::uuid(),
            ])->save();

            $paiement->montant_rembourse = Montant::depuisCentimes(Montant::enCentimes((string) $paiement->montant_rembourse) + $centimes);
            $paiement->save();

            return $remboursement;
        });
    }

    /** Part remboursable d'un paiement pour un taux (RG-04/05), arrondie au centime inférieur. */
    public function montantPourTaux(Paiement $paiement, int $taux): string
    {
        return Montant::depuisCentimes(self::arrondiPourLaDevise(
            intdiv(Montant::enCentimes((string) $paiement->montant) * $taux, 100), $paiement->devise));
    }

    /** Mobile Money en francs congolais : pas de centimes, arrondi au franc inférieur. */
    private static function arrondiPourLaDevise(int $centimes, string $devise): int
    {
        return $devise === 'CDF' ? intdiv($centimes, 100) * 100 : $centimes;
    }

    /**
     * Rend au paiement le montant d'un remboursement refusé ou échoué, pour
     * qu'une nouvelle demande reste possible. À appeler sous verrou du
     * paiement, dans la transaction qui change le statut du remboursement.
     */
    public function desengager(Remboursement $remboursement): void
    {
        if ($remboursement->statut->engageLeMontant()) {
            throw new LogicException("Remboursement {$remboursement->id} encore engagé : rien à libérer.");
        }

        $paiement = Paiement::whereKey($remboursement->paiement_id)->lockForUpdate()->firstOrFail();
        $paiement->montant_rembourse = Montant::depuisCentimes(
            Montant::enCentimes((string) $paiement->montant_rembourse) - Montant::enCentimes((string) $remboursement->montant));
        $paiement->save();
    }

    // --- Lot caisse -------------------------------------------------------------

    /**
     * PAY-07 : geste commercial demandé par un agent de caisse, motivé, dans
     * la limite de ce qui reste remboursable. Attend la validation d'un AUTRE agent.
     *
     * @throws PaiementRefuse
     */
    public function gesteCommercial(Paiement $paiement, string $montant, User $agent, string $justification): Remboursement
    {
        if (trim($justification) === '') {
            throw new PaiementRefuse('Le motif du geste commercial est obligatoire.');
        }

        $montant = Montant::depuisCentimes(self::arrondiPourLaDevise(Montant::enCentimes($montant), $paiement->devise));

        return $this->demander($paiement, $montant, MotifRemboursement::GesteCommercial, $agent, $justification);
    }

    /**
     * PAY-07, quatre yeux : jamais par celui qui l'a demandé. Une demande du
     * système (demande_par nul : annulation) peut être validée par tout agent.
     *
     * @throws PaiementRefuse
     */
    public function valider(Remboursement $remboursement, User $agent): Remboursement
    {
        return DB::transaction(function () use ($remboursement, $agent) {
            $r = Remboursement::whereKey($remboursement->id)->lockForUpdate()->firstOrFail();

            if ($r->statut !== StatutRemboursement::AValider) {
                throw new PaiementRefuse('Ce remboursement a déjà été traité ('.mb_strtolower($r->statut->libelle()).').');
            }

            if ($r->demande_par !== null && $r->demande_par === $agent->id) {
                throw new PaiementRefuse('Vous avez demandé ce remboursement : un autre agent de caisse doit le valider.');
            }

            $r->transitionVers(StatutRemboursement::Valide);
            $r->valide_par = $agent->id;
            $r->valide_le = CarbonImmutable::now();
            $r->save();

            return $r;
        });
    }

    /**
     * Refus motivé : le montant engagé est rendu au paiement, une nouvelle
     * demande reste possible.
     *
     * @throws PaiementRefuse
     */
    public function refuser(Remboursement $remboursement, User $agent, string $motif): Remboursement
    {
        if (trim($motif) === '') {
            throw new PaiementRefuse('Le motif du refus est obligatoire.');
        }

        return DB::transaction(function () use ($remboursement, $agent, $motif) {
            // Même ordre de verrous que demander() : le paiement, puis le remboursement.
            Paiement::whereKey($remboursement->paiement_id)->lockForUpdate()->firstOrFail();
            $r = Remboursement::whereKey($remboursement->id)->lockForUpdate()->firstOrFail();

            if ($r->statut !== StatutRemboursement::AValider) {
                throw new PaiementRefuse('Ce remboursement a déjà été traité ('.mb_strtolower($r->statut->libelle()).').');
            }

            $r->transitionVers(StatutRemboursement::Refuse);
            $r->valide_par = $agent->id === $r->demande_par ? null : $agent->id;
            $r->valide_le = CarbonImmutable::now();
            $r->motif_refus = mb_substr(trim($motif), 0, 255);
            $r->save();

            $this->desengager($r);

            return $r;
        });
    }

    /**
     * Envoie un remboursement validé au prestataire. L'appel réseau se fait
     * HORS transaction (aucun verrou tenu pendant l'attente) ; le prestataire
     * est idempotent par la clé du remboursement, une reprise ne rembourse
     * pas deux fois. Le résultat final arrive par rappel signé.
     *
     * Revue de sécurité : seul un refus EXPLICITE met en échec (et rend le
     * montant). Une erreur technique laisse le remboursement « validé » et
     * son montant engagé, car l'argent est peut-être parti : la tâche
     * planifiée le relance avec la même clé, un nombre borné de fois, puis le
     * bloque pour la caisse. Un paiement passé par un autre prestataire que
     * le pilote actif n'est jamais envoyé à ce dernier.
     */
    public function executer(Remboursement $remboursement): Remboursement
    {
        $r = Remboursement::with('paiement')->findOrFail($remboursement->id);
        if ($r->statut !== StatutRemboursement::Valide || $r->blocage !== null) {
            return $r;
        }

        $fournisseur = app(FournisseurPaiement::class);
        if ($r->paiement->fournisseur !== $fournisseur->nom()) {
            return $this->bloquer($r, sprintf('Paiement passé par « %s », prestataire actif « %s » : à rembourser à la main.', $r->paiement->fournisseur, $fournisseur->nom()));
        }

        try {
            $initiation = $fournisseur->rembourser($r);
            $erreur = null;
        } catch (ErreurFournisseur $e) {
            $initiation = null;
            $erreur = $e;
        }

        return DB::transaction(function () use ($r, $initiation, $erreur) {
            Paiement::whereKey($r->paiement_id)->lockForUpdate()->firstOrFail();
            $r = Remboursement::whereKey($r->id)->lockForUpdate()->firstOrFail();

            // Un autre exécuteur, ou le rappel, est passé entre-temps.
            if ($r->statut !== StatutRemboursement::Valide) {
                return $r;
            }

            $r->tentatives_envoi++;
            $r->derniere_tentative_le = CarbonImmutable::now();

            if ($initiation !== null) {
                $r->transitionVers(StatutRemboursement::EnCours);
                $r->reference_fournisseur = $initiation->referenceFournisseur;
                $r->derniere_erreur = null;
                $r->save();

                return $r;
            }

            if ($erreur->refusDefinitif) {
                // Le prestataire a dit non : rien n'est parti.
                $r->transitionVers(StatutRemboursement::Echoue);
                $r->motif_echec = mb_substr($erreur->getMessage(), 0, 255);
                $r->termine_le = CarbonImmutable::now();
                $r->save();
                $this->desengager($r);

                return $r;
            }

            // Erreur technique : la demande est peut-être partie. Rien n'est clos.
            $r->derniere_erreur = mb_substr($erreur->getMessage(), 0, 255);
            if ($r->tentatives_envoi >= (int) config('caisse.remboursements.tentatives_max')) {
                $r->blocage = sprintf('Sans réponse de l\'opérateur après %d essais : vérifier auprès de lui avant toute nouvelle demande.', $r->tentatives_envoi);
            }
            $r->save();
            Log::warning('Remboursement : erreur technique du prestataire, nouvel essai prévu.', [
                'remboursement_id' => $r->id, 'tentatives' => $r->tentatives_envoi, 'bloque' => $r->blocage !== null,
            ]);

            return $r;
        });
    }

    /** Plus d'envoi automatique : la caisse voit la raison et traite à la main. */
    private function bloquer(Remboursement $remboursement, string $raison): Remboursement
    {
        return DB::transaction(function () use ($remboursement, $raison) {
            $r = Remboursement::whereKey($remboursement->id)->lockForUpdate()->firstOrFail();
            if ($r->statut === StatutRemboursement::Valide && $r->blocage === null) {
                $r->blocage = mb_substr($raison, 0, 255);
                $r->save();
                Log::warning('Remboursement bloqué : envoi automatique impossible.', ['remboursement_id' => $r->id]);
            }

            return $r;
        });
    }

    /**
     * Rappel signé du prestataire sur un remboursement (appelé par le
     * contrôleur des rappels, une fois l'événement enregistré). Retrouvé par
     * sa clé d'idempotence ; montant et devise doivent être exacts. Réussi :
     * avoir, sortie de caisse, SMS au patient APRÈS validation de la
     * transaction. Échoué : le montant est rendu au paiement. Un rappel
     * rejoué sur un remboursement terminé ne change rien.
     */
    public function traiterRappel(RappelFournisseur $rappel, EvenementPaiement $evenement): void
    {
        $paiementId = Remboursement::where('cle_idempotence', $rappel->referenceMarchand)->value('paiement_id');

        $resultat = $paiementId === null
            ? 'remboursement inconnu'
            : DB::transaction(function () use ($rappel, $evenement, $paiementId) {
                $paiement = Paiement::whereKey($paiementId)->lockForUpdate()->firstOrFail();
                $r = Remboursement::where('cle_idempotence', $rappel->referenceMarchand)->lockForUpdate()->firstOrFail();
                $evenement->forceFill(['remboursement_id' => $r->id])->save();
                // Identifiants seulement dans le journal : ni téléphone, ni donnée de santé.
                $trace = ['remboursement_id' => $r->id, 'evenement_id' => $evenement->id];

                if ($paiement->fournisseur !== app(FournisseurPaiement::class)->nom()) {
                    Log::warning('Rappel de remboursement pour un paiement d\'un autre prestataire : ignoré.', $trace);

                    return 'prestataire différent : à traiter à la main';
                }

                $ouvert = in_array($r->statut, [StatutRemboursement::Valide, StatutRemboursement::EnCours], true);
                $echoueMaisReussi = $r->statut === StatutRemboursement::Echoue && $rappel->reussi;
                if (! $ouvert && ! $echoueMaisReussi) {
                    return 'déjà traité ('.$r->statut->value.')';
                }

                if ($rappel->devise !== $r->devise || Montant::enCentimes($rappel->montant) !== Montant::enCentimes((string) $r->montant)) {
                    Log::warning('Rappel de remboursement : montant ou devise différent, ignoré.', $trace);

                    return 'montant ou devise différent : ignoré';
                }

                if ($echoueMaisReussi) {
                    return $this->constaterReussiteApresEchec($r, $paiement, $rappel, $trace);
                }

                if ($r->statut === StatutRemboursement::Valide) {
                    $r->transitionVers(StatutRemboursement::EnCours);
                }
                $r->reference_fournisseur ??= $rappel->referenceFournisseur;
                $r->termine_le = CarbonImmutable::now();

                if (! $rappel->reussi) {
                    $r->transitionVers(StatutRemboursement::Echoue);
                    $r->motif_echec = mb_substr((string) ($rappel->motifEchec ?? 'Refusé par l\'opérateur'), 0, 255);
                    $r->save();
                    $this->desengager($r);

                    return 'échoué';
                }

                $r->transitionVers(StatutRemboursement::Reussi);
                $r->save();
                $this->emettrePiecesEtPrevenir($r);

                return 'réussi';
            });

        $evenement->forceFill(['traite_le' => CarbonImmutable::now(), 'resultat' => $resultat])->save();
    }

    /**
     * L'opérateur confirme un remboursement que nous avions classé en échec :
     * l'argent est parti. Si le montant peut encore être engagé sur le
     * paiement, le remboursement est constaté (avoir, sortie de caisse) ;
     * sinon un écart de rapprochement est ouvert pour la caisse. Jamais
     * d'argent sorti sans pièce ni sans trace.
     */
    private function constaterReussiteApresEchec(Remboursement $r, Paiement $paiement, RappelFournisseur $rappel, array $trace): string
    {
        $centimes = Montant::enCentimes((string) $r->montant);

        if ($centimes > $paiement->resteRemboursableEnCentimes()) {
            DB::table('ecarts_rapprochement')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'jour' => CarbonImmutable::now('Africa/Kinshasa')->toDateString(),
                'fournisseur' => $paiement->fournisseur,
                'nature' => 'statut_different',
                'paiement_id' => null,
                'remboursement_id' => $r->id,
                'reference_fournisseur' => $rappel->referenceFournisseur,
                'montant_attendu' => null,
                'montant_releve' => (string) $r->montant,
                'devise' => $r->devise,
                'statut' => 'ouvert',
                'created_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
            Log::warning('Remboursement confirmé après échec, montant déjà engagé ailleurs : écart de rapprochement ouvert.', $trace);

            return 'réussi après échec, montant non ré-engageable : écart à traiter';
        }

        $paiement->montant_rembourse = Montant::depuisCentimes(Montant::enCentimes((string) $paiement->montant_rembourse) + $centimes);
        $paiement->save();

        $r->transitionVers(StatutRemboursement::Reussi);
        $r->reference_fournisseur ??= $rappel->referenceFournisseur;
        $r->termine_le = CarbonImmutable::now();
        $r->save();
        $this->emettrePiecesEtPrevenir($r);

        return 'réussi après échec : montant ré-engagé';
    }

    /** Avoir, sortie de caisse, puis SMS au patient APRÈS validation de la transaction. */
    private function emettrePiecesEtPrevenir(Remboursement $r): void
    {
        app(FacturationService::class)->emettreAvoir($r);
        app(JournalCaisse::class)->sortie($r);

        $paiement = $r->paiement()->with('patient')->first();
        // Espaces ordinaires : l'espace fine insécable n'est pas dans l'alphabet GSM des SMS.
        $texte = sprintf('MEDIHOME : remboursement de %s envoyé sur %s.',
            str_replace("\u{202F}", ' ', Montant::afficher((string) $r->montant, $r->devise)),
            $paiement->moyen === MoyenPaiement::Carte ? 'votre carte' : 'votre compte '.$paiement->moyen->libelle());
        $telephone = $paiement->patient?->telephone;

        if ($telephone !== null) {
            $id = $r->id;
            DB::afterCommit(function () use ($telephone, $texte, $id) {
                try {
                    app(EnvoyeurSms::class)->envoyer($telephone, $texte);
                } catch (\Throwable $e) {
                    // Une panne SMS ne remet pas en cause le remboursement ; sans numéro dans le journal.
                    Log::warning('SMS de remboursement non envoyé.', ['remboursement_id' => $id, 'erreur' => $e::class]);
                }
            });
        }
    }
}
