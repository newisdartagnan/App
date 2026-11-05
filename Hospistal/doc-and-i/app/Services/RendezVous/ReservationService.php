<?php

namespace App\Services\RendezVous;

use App\Enums\MotifRemboursement;
use App\Enums\StatutPaiement;
use App\Enums\StatutRendezVous;
use App\Models\DemandeConsultation;
use App\Models\Medecin;
use App\Models\Paiement;
use App\Models\RendezVous;
use App\Models\TypeConsultation;
use App\Services\Paiement\RemboursementService;
use App\Support\Montant;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Réservation, confirmation, expiration, annulation et report d'un
 * rendez-vous (RDV-05, RDV-07, RG-03 à RG-05, RG-13).
 *
 * Chaque opération qui touche l'agenda d'un médecin se fait dans une
 * transaction, sous le verrou consultatif de ce médecin
 * (pg_advisory_xact_lock) : deux réservations simultanées chez le même
 * médecin passent l'une après l'autre, et la seconde voit la première. Les
 * index uniques partiels de rendez_vous restent la dernière défense ; leur
 * violation devient un refus lisible, jamais une erreur 500.
 */
class ReservationService
{
    private const INDEX_CRENEAU = 'rendez_vous_un_occupant_par_creneau';

    private const INDEX_ATTENTE_PATIENT = 'rendez_vous_une_attente_par_patient';

    /** Issues de confirmerApresPaiement(). */
    public const CONFIRME = 'confirme';

    public const DEJA_CONFIRME = 'deja_confirme';

    public const REPRIS = 'repris';

    public const IMPOSSIBLE = 'impossible';

    public function __construct(
        private readonly CreneauxService $creneaux,
        private readonly ?RemboursementService $remboursements = null,
    ) {}

    /**
     * Paiement encaissé (rappel signé de l'opérateur, lot paiement) : le
     * rendez-vous est confirmé, ou repris s'il a expiré entre-temps (RG-03).
     *
     * - en attente, dans le délai : confirmé ;
     * - en attente échue, ou expiré : repris si le créneau est encore libre,
     *   la demande toujours ouverte et l'heure encore réservable ; sinon
     *   IMPOSSIBLE (le paiement sera remboursé) ;
     * - déjà confirmé : DEJA_CONFIRME ; annulé ou autre : IMPOSSIBLE.
     *
     * Sous verrou du médecin et du rendez-vous ; ne lève pas d'exception de
     * refus : l'argent est déjà encaissé, l'appelant décide du remboursement.
     */
    public function confirmerApresPaiement(RendezVous $rendezVous): string
    {
        return DB::transaction(function () use ($rendezVous) {
            $this->verrouillerMedecin($rendezVous->medecin_id);
            $rdv = RendezVous::whereKey($rendezVous->id)->lockForUpdate()->firstOrFail();

            if ($rdv->statut === StatutRendezVous::Confirme) {
                return self::DEJA_CONFIRME;
            }

            if ($rdv->statut === StatutRendezVous::EnAttentePaiement && ! $this->estEchue($rdv)) {
                $rdv->transitionVers(StatutRendezVous::Confirme);
                $rdv->save();

                return self::CONFIRME;
            }

            if ($rdv->statut === StatutRendezVous::EnAttentePaiement) {
                $this->expirer($rdv);
            }

            if ($rdv->statut !== StatutRendezVous::Expire) {
                return self::IMPOSSIBLE;
            }

            $demande = DemandeConsultation::whereKey($rdv->demande_id)->lockForUpdate()->firstOrFail();
            $medecin = Medecin::findOrFail($rdv->medecin_id);
            $type = TypeConsultation::findOrFail($rdv->type_consultation_id);
            $this->expirerAttentesEchues($medecin->id);

            if ($demande->statut !== 'ouverte'
                || ! $medecin->estReservable()
                || ! $this->creneaux->estPropose($medecin, $type, $rdv->debut, $rdv->id)) {
                return self::IMPOSSIBLE;
            }

            $rdv->reprendreApresPaiementTardif();
            $rdv->save();
            $demande->statut = 'reservee';
            $demande->save();

            return self::REPRIS;
        });
    }

    /** Verrou de l'agenda du médecin, pour l'ordre des verrous des autres lots (médecin, puis lignes). */
    public function verrouillerAgenda(string $medecinId): void
    {
        $this->verrouillerMedecin($medecinId);
    }

    /**
     * Bloque le créneau le temps du paiement : rendez-vous en attente de
     * paiement, prix et devise recopiés du type (RG-13).
     *
     * @throws ReservationRefusee
     */
    public function reserver(DemandeConsultation $demande, Medecin $medecin, TypeConsultation $type, DateTimeInterface $debut): RendezVous
    {
        $debut = CreneauxService::local($debut);

        return $this->traduireViolations(fn () => DB::transaction(function () use ($demande, $medecin, $type, $debut) {
            $this->verrouillerMedecin($medecin->id);

            $demande = DemandeConsultation::whereKey($demande->id)->lockForUpdate()->firstOrFail();
            $this->verifierDemandeOuverte($demande);

            $medecin = Medecin::findOrFail($medecin->id);
            $type = TypeConsultation::findOrFail($type->id);
            $this->creneaux->verifierOffre($medecin, $type);

            $maintenant = CarbonImmutable::now();
            $this->expirerAttentesEchues($medecin->id, $demande->patient_id);

            if (! $this->creneaux->estPropose($medecin, $type, $debut)) {
                throw new ReservationRefusee('Ce créneau n\'est plus disponible. Choisissez-en un autre.');
            }

            $rdv = new RendezVous;
            $rdv->forceFill([
                'demande_id' => $demande->id,
                'patient_id' => $demande->patient_id,
                'medecin_id' => $medecin->id,
                'type_consultation_id' => $type->id,
                'debut' => $debut,
                'fin' => $debut->addMinutes($type->duree_minutes),
                'statut' => StatutRendezVous::EnAttentePaiement,
                'expire_le' => $maintenant->addMinutes((int) config('doc-and-i.rendez_vous.delai_paiement_minutes')),
                'prix' => $type->prix,
                'devise' => $type->devise,
            ])->save();

            $demande->specialite_id = $type->specialite_id;
            $demande->statut = 'reservee';
            $demande->save();

            return $rdv;
        }));
    }

    /**
     * Paiement reçu (lot paiement) : en attente → confirmé, sous verrou.
     * Idempotent : un rendez-vous déjà confirmé est rendu tel quel (rappel de
     * l'opérateur reçu deux fois). Hors délai, le créneau est libéré et le
     * paiement refusé : RG-03 (autre créneau ou remboursement) relève du lot
     * paiement.
     *
     * @throws ReservationRefusee
     */
    public function confirmerPaiement(RendezVous $rendezVous): RendezVous
    {
        [$rdv, $tardif] = DB::transaction(function () use ($rendezVous) {
            $this->verrouillerMedecin($rendezVous->medecin_id);
            $rdv = RendezVous::whereKey($rendezVous->id)->lockForUpdate()->firstOrFail();

            if ($rdv->statut === StatutRendezVous::Confirme) {
                return [$rdv, false];
            }

            if ($rdv->statut !== StatutRendezVous::EnAttentePaiement) {
                throw new ReservationRefusee('Ce rendez-vous ne peut plus être confirmé ('.mb_strtolower($rdv->statut->libelle()).').');
            }

            if ($this->estEchue($rdv)) {
                $this->expirer($rdv);

                return [$rdv, true];
            }

            $rdv->transitionVers(StatutRendezVous::Confirme);
            $rdv->save();

            return [$rdv, false];
        });

        if ($tardif) {
            throw new ReservationRefusee('Le délai de paiement est dépassé : le créneau a été libéré.');
        }

        return $rdv;
    }

    /**
     * Passe en « expiré » les attentes de paiement échues : toutes, ou
     * seulement celles d'un médecin et d'un patient. Leur demande redevient
     * ouverte : le patient peut choisir un autre créneau.
     */
    public function expirerAttentesEchues(?string $medecinId = null, ?string $patientId = null): int
    {
        return DB::transaction(function () use ($medecinId, $patientId) {
            $echues = RendezVous::query()
                ->where('statut', StatutRendezVous::EnAttentePaiement->value)
                ->where('expire_le', '<=', CarbonImmutable::now())
                ->when($medecinId || $patientId, fn ($q) => $q->where(fn ($q) => $q
                    ->when($medecinId, fn ($q) => $q->orWhere('medecin_id', $medecinId))
                    ->when($patientId, fn ($q) => $q->orWhere('patient_id', $patientId))))
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($echues as $rdv) {
                $this->expirer($rdv);
            }

            return $echues->count();
        });
    }

    /**
     * RDV-07, RG-04 : annulation par le patient. Une attente de paiement
     * s'annule sans question de remboursement ; un rendez-vous confirmé reçoit
     * son taux de remboursement selon le délai avant le début, plafonné par
     * ses reports éventuels (tauxSiAnnulation).
     *
     * @throws ReservationRefusee
     */
    public function annulerParPatient(RendezVous $rendezVous, ?string $motif = null): RendezVous
    {
        return DB::transaction(function () use ($rendezVous, $motif) {
            $this->verrouillerMedecin($rendezVous->medecin_id);
            $rdv = RendezVous::whereKey($rendezVous->id)->lockForUpdate()->firstOrFail();
            $maintenant = CarbonImmutable::now();

            $taux = match ($rdv->statut) {
                StatutRendezVous::EnAttentePaiement => null,
                StatutRendezVous::Confirme => $maintenant >= $rdv->debut
                    ? throw new ReservationRefusee('La consultation a déjà commencé : elle ne peut plus être annulée.')
                    : $this->tauxSiAnnulation($rdv, $maintenant),
                default => throw new ReservationRefusee('Ce rendez-vous est déjà annulé ou terminé.'),
            };
            $etaitConfirme = $rdv->statut === StatutRendezVous::Confirme;

            $rdv->transitionVers(StatutRendezVous::AnnuleParPatient);
            $rdv->forceFill([
                'annule_le' => $maintenant,
                'motif_annulation' => $this->motif($motif),
                'taux_remboursement' => $taux,
            ])->save();

            // RG-04 : la demande de remboursement naît dans la même transaction
            // que l'annulation ; la caisse la valide (PAY-07).
            if ($etaitConfirme && $taux > 0) {
                $this->demanderRemboursement($rdv, $taux, MotifRemboursement::AnnulationPatient);
            }

            // Une attente annulée laisse la demande ouverte (autre créneau) ;
            // un rendez-vous confirmé annulé clôt la demande.
            $this->changerDemande($rdv, $etaitConfirme ? 'annulee' : 'ouverte');

            return $rdv;
        });
    }

    /**
     * RG-05 : annulation par le médecin, motif obligatoire, remboursement
     * total. La demande redevient ouverte : le patient peut reprendre un
     * créneau.
     *
     * @throws ReservationRefusee
     */
    public function annulerParMedecin(RendezVous $rendezVous, string $motif): RendezVous
    {
        $motif = $this->motif($motif) ?? throw new ReservationRefusee('Indiquez le motif de l\'annulation.');

        return DB::transaction(function () use ($rendezVous, $motif) {
            $this->verrouillerMedecin($rendezVous->medecin_id);
            $rdv = RendezVous::whereKey($rendezVous->id)->lockForUpdate()->firstOrFail();

            if ($rdv->statut !== StatutRendezVous::Confirme) {
                throw new ReservationRefusee('Seul un rendez-vous confirmé peut être annulé par le médecin.');
            }

            // Une consultation commencée ou passée ne s'annule plus : ce serait
            // rembourser en entier une consultation peut-être faite (revue de
            // sécurité). Son issue (terminée, absence) relève du lot téléconsultation.
            if (CarbonImmutable::now() >= $rdv->debut) {
                throw new ReservationRefusee('Ce rendez-vous a déjà commencé : il ne peut plus être annulé.');
            }

            $rdv->transitionVers(StatutRendezVous::AnnuleParMedecin);
            $rdv->forceFill([
                'annule_le' => CarbonImmutable::now(),
                'motif_annulation' => $motif,
                'taux_remboursement' => 100,
            ])->save();

            // RG-05 : remboursement total, demandé dans la même transaction.
            $this->demanderRemboursement($rdv, 100, MotifRemboursement::AnnulationMedecin);

            $this->changerDemande($rdv, 'ouverte');

            return $rdv;
        });
    }

    /**
     * RDV-07, RG-04 : le patient déplace un rendez-vous confirmé vers un autre
     * créneau libre du même médecin et du même type, au même prix. Au plus
     * reports_max fois au total ; à moins de 2 h, reports_max_tardifs fois.
     * Le remboursement en cas d'annulation ultérieure reste plafonné au taux
     * du moment du report (tauxSiAnnulation).
     *
     * @throws ReservationRefusee
     */
    public function reporter(RendezVous $rendezVous, DateTimeInterface $nouveauDebut): RendezVous
    {
        $nouveauDebut = CreneauxService::local($nouveauDebut);

        return $this->traduireViolations(fn () => DB::transaction(function () use ($rendezVous, $nouveauDebut) {
            $this->verrouillerMedecin($rendezVous->medecin_id);
            $rdv = RendezVous::whereKey($rendezVous->id)->lockForUpdate()->firstOrFail();
            $maintenant = CarbonImmutable::now();

            if ($rdv->statut !== StatutRendezVous::Confirme) {
                throw new ReservationRefusee('Seul un rendez-vous confirmé peut être déplacé.');
            }

            if ($maintenant >= $rdv->debut) {
                throw new ReservationRefusee('La consultation a déjà commencé : elle ne peut plus être déplacée.');
            }

            $maxTotal = (int) config('doc-and-i.rendez_vous.reports_max');
            if ($rdv->reports >= $maxTotal) {
                throw new ReservationRefusee("Ce rendez-vous a déjà été déplacé {$maxTotal} fois : il ne peut plus l'être. Vous pouvez l'annuler.");
            }

            $tardif = $this->estTardif($rdv, $maintenant);
            $max = (int) config('doc-and-i.rendez_vous.reports_max_tardifs');
            if ($tardif && (int) $rdv->reports_tardifs >= $max) {
                $heures = (int) config('doc-and-i.rendez_vous.annulation.remboursement_partiel_heures');
                throw new ReservationRefusee($max === 0
                    ? "À moins de {$heures} heures du rendez-vous, il n'est plus possible de le déplacer."
                    : "À moins de {$heures} heures du rendez-vous, vous ne pouvez le déplacer qu'une fois"
                        .($max > 1 ? " ({$max} fois au plus)" : '').'. Vous l\'avez déjà fait.');
            }

            if ($nouveauDebut->getTimestamp() === $rdv->debut->getTimestamp()) {
                throw new ReservationRefusee('C\'est déjà l\'heure de votre rendez-vous. Choisissez un autre créneau.');
            }

            $medecin = Medecin::findOrFail($rdv->medecin_id);
            if (! $medecin->estReservable()) {
                throw new ReservationRefusee('Ce médecin ne reçoit pas de rendez-vous en ce moment.');
            }

            $type = TypeConsultation::findOrFail($rdv->type_consultation_id);
            $this->expirerAttentesEchues($medecin->id);

            if (! $this->creneaux->estPropose($medecin, $type, $nouveauDebut, $rdv->id)) {
                throw new ReservationRefusee('Ce créneau n\'est plus disponible. Choisissez-en un autre.');
            }

            [$minutesVeille, $minutesDernier] = array_map('intval', config('doc-and-i.rendez_vous.rappels_minutes'));

            $rdv->forceFill([
                'debut' => $nouveauDebut,
                'fin' => $nouveauDebut->addMinutes($type->duree_minutes),
                'reports' => $rdv->reports + 1,
                'reports_tardifs' => (int) $rdv->reports_tardifs + ($tardif ? 1 : 0),
                // RG-04 : le taux qu'une annulation aurait donné maintenant devient
                // un plafond ; reporter ne peut pas améliorer le remboursement.
                'taux_remboursement_plafond' => $this->tauxSiAnnulation($rdv, $maintenant),
                // RDV-06 : un rappel déjà envoyé reste valable si le nouvel horaire
                // tombe encore dans la fenêtre qu'il annonçait ; sinon il repartira.
                'rappel_24h_envoye_le' => $this->rappelEncoreValable($rdv->rappel_24h_envoye_le, $nouveauDebut, $minutesVeille),
                'rappel_1h_envoye_le' => $this->rappelEncoreValable($rdv->rappel_1h_envoye_le, $nouveauDebut, $minutesDernier),
            ])->save();

            return $rdv;
        }));
    }

    /**
     * RG-04 : taux de remboursement d'une annulation par le patient à cet
     * instant. Règle : le plus bas entre le taux calculé sur l'horaire actuel
     * et le plafond retenu au dernier report (le taux qui s'appliquait au
     * moment de chaque report). Ainsi un rendez-vous déplacé à moins de 2 h,
     * puis annulé, n'est pas remboursé ; déplacé dans la tranche à 50 %, il
     * reste au plus à 50 % ; déplacé plus de 24 h avant, il garde 100 %.
     */
    public function tauxSiAnnulation(RendezVous $rdv, DateTimeInterface $maintenant): int
    {
        return min(
            $this->tauxRemboursement($rdv->debut, $maintenant),
            $rdv->taux_remboursement_plafond ?? 100,
        );
    }

    /** Un rappel envoyé à $envoyeLe annonçait les débuts jusqu'à $envoyeLe + $fenetre minutes. */
    private function rappelEncoreValable(?DateTimeInterface $envoyeLe, CarbonImmutable $nouveauDebut, int $fenetre): ?DateTimeInterface
    {
        if ($envoyeLe === null) {
            return null;
        }

        return $nouveauDebut->getTimestamp() <= CarbonImmutable::instance($envoyeLe)->addMinutes($fenetre)->getTimestamp()
            ? $envoyeLe
            : null;
    }

    /** RG-04 : part remboursable selon le délai entre maintenant et le début. */
    public function tauxRemboursement(DateTimeInterface $debut, DateTimeInterface $maintenant): int
    {
        $regles = config('doc-and-i.rendez_vous.annulation');
        $secondes = $debut->getTimestamp() - $maintenant->getTimestamp();

        return match (true) {
            $secondes > $regles['remboursement_total_heures'] * 3600 => 100,
            $secondes >= $regles['remboursement_partiel_heures'] * 3600 => (int) $regles['taux_partiel'],
            default => 0,
        };
    }

    /** À moins de 2 h du début (même seuil que le remboursement partiel, RG-04). */
    public function estTardif(RendezVous $rdv, DateTimeInterface $maintenant): bool
    {
        $seuil = (int) config('doc-and-i.rendez_vous.annulation.remboursement_partiel_heures') * 3600;

        return $rdv->debut->getTimestamp() - $maintenant->getTimestamp() < $seuil;
    }

    public function estEchue(RendezVous $rdv): bool
    {
        return $rdv->statut === StatutRendezVous::EnAttentePaiement
            && $rdv->expire_le !== null
            && $rdv->expire_le <= CarbonImmutable::now();
    }

    /**
     * Demande de remboursement d'une annulation (RG-04, RG-05) sur le paiement
     * encaissé du rendez-vous (le premier, s'il y en a eu deux : le second est
     * déjà rendu comme paiement en double). Sans paiement encaissé, rien à
     * rendre. Idempotent par motif (RemboursementService::demander).
     */
    private function demanderRemboursement(RendezVous $rdv, int $taux, MotifRemboursement $motif): void
    {
        $paiement = Paiement::where('rendez_vous_id', $rdv->id)
            ->where('statut', StatutPaiement::Reussi->value)
            ->orderBy('confirme_le')->orderBy('id')
            ->first();

        if (! $paiement) {
            return;
        }

        $remboursements = $this->remboursements ?? app(RemboursementService::class);

        // Plafonné à ce qui reste remboursable (un geste commercial a pu en
        // rendre une partie) ; rien à demander si tout est déjà rendu. Sinon
        // la demande serait refusée et l'annulation avec elle (revue de sécurité).
        $centimes = min(
            Montant::enCentimes($remboursements->montantPourTaux($paiement, $taux)),
            Paiement::whereKey($paiement->id)->lockForUpdate()->firstOrFail()->resteRemboursableEnCentimes(),
        );

        if ($centimes > 0) {
            $remboursements->demander($paiement, Montant::depuisCentimes($centimes), $motif);
        }
    }

    /** Verrou consultatif du médecin, rendu à la fin de la transaction. */
    private function verrouillerMedecin(string $medecinId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['medecin:'.$medecinId]);
    }

    private function verifierDemandeOuverte(DemandeConsultation $demande): void
    {
        match ($demande->statut) {
            'ouverte' => null,
            'orientee_urgence' => throw new ReservationRefusee('Vos réponses signalent une urgence : rendez-vous tout de suite dans un service d\'urgence.'),
            'reservee' => throw new ReservationRefusee('Cette demande a déjà un rendez-vous. Retrouvez-le dans « Mes rendez-vous ».'),
            default => throw new ReservationRefusee('Cette demande est close. Faites une nouvelle demande.'),
        };
    }

    /** À appeler sous verrou, dans une transaction. */
    private function expirer(RendezVous $rdv): void
    {
        $rdv->transitionVers(StatutRendezVous::Expire);
        $rdv->save();
        $this->changerDemande($rdv, 'ouverte');
    }

    private function changerDemande(RendezVous $rdv, string $statut): void
    {
        DemandeConsultation::whereKey($rdv->demande_id)
            ->where('statut', 'reservee')
            ->update(['statut' => $statut]);
    }

    private function motif(?string $motif): ?string
    {
        $motif = trim((string) $motif);

        return $motif === '' ? null : mb_substr($motif, 0, 255);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $operation
     * @return T
     */
    private function traduireViolations(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (UniqueConstraintViolationException $e) {
            throw match (true) {
                str_contains($e->getMessage(), self::INDEX_ATTENTE_PATIENT) => new ReservationRefusee(
                    'Vous avez déjà un créneau en attente de paiement. Payez-le ou annulez-le avant d\'en choisir un autre.', 0, $e),
                str_contains($e->getMessage(), self::INDEX_CRENEAU) => new ReservationRefusee(
                    'Ce créneau vient d\'être pris par quelqu\'un d\'autre. Choisissez-en un autre.', 0, $e),
                default => $e,
            };
        }
    }
}
