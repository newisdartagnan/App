<?php

namespace App\Services\RendezVous;

use App\Enums\StatutRendezVous;
use App\Models\DemandeConsultation;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\TypeConsultation;
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

    public function __construct(private readonly CreneauxService $creneaux) {}

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
     * son taux de remboursement selon le délai avant le début.
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
                    : $this->tauxRemboursement($rdv->debut, $maintenant),
                default => throw new ReservationRefusee('Ce rendez-vous est déjà annulé ou terminé.'),
            };
            $etaitConfirme = $rdv->statut === StatutRendezVous::Confirme;

            $rdv->transitionVers(StatutRendezVous::AnnuleParPatient);
            $rdv->forceFill([
                'annule_le' => $maintenant,
                'motif_annulation' => $this->motif($motif),
                'taux_remboursement' => $taux,
            ])->save();

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

            $rdv->transitionVers(StatutRendezVous::AnnuleParMedecin);
            $rdv->forceFill([
                'annule_le' => CarbonImmutable::now(),
                'motif_annulation' => $motif,
                'taux_remboursement' => 100,
            ])->save();

            $this->changerDemande($rdv, 'ouverte');

            return $rdv;
        });
    }

    /**
     * RDV-07, RG-04 : le patient déplace un rendez-vous confirmé vers un autre
     * créneau libre du même médecin et du même type, au même prix. Librement
     * plus de 2 h avant ; à moins de 2 h, reports_max_tardifs fois.
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

            $rdv->forceFill([
                'debut' => $nouveauDebut,
                'fin' => $nouveauDebut->addMinutes($type->duree_minutes),
                'reports' => $rdv->reports + 1,
                'reports_tardifs' => (int) $rdv->reports_tardifs + ($tardif ? 1 : 0),
                // Nouvelle heure : les rappels (RDV-06) repartent de zéro.
                'rappel_24h_envoye_le' => null,
                'rappel_1h_envoye_le' => null,
            ])->save();

            return $rdv;
        }));
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
