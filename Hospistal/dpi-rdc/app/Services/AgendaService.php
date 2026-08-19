<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\RendezVous;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Agenda des prestataires : rendez-vous patients et créneaux bloqués,
 * avec recherche des disponibilités réelles.
 */
class AgendaService
{
    /** Amplitude d'ouverture des consultations. */
    public const HEURE_OUVERTURE = 8;

    public const HEURE_FERMETURE = 17;

    /**
     * Prestataires susceptibles de recevoir des rendez-vous.
     */
    public function prestataires()
    {
        return User::whereHas('roles', fn ($q) => $q->whereIn('name', ['medecin', 'infirmier_chef']))
            ->where('is_active', true)
            ->orderBy('nom')
            ->get();
    }

    /**
     * Agenda d'un prestataire pour une journée.
     */
    public function journee(User $prestataire, string $jour)
    {
        return RendezVous::with(['patient', 'typeConsultation'])
            ->where('prestataire_id', $prestataire->id)
            ->duJour($jour)
            ->orderBy('debut')
            ->get();
    }

    /**
     * Créneaux libres d'un prestataire sur une journée.
     *
     * @return array<int, \Illuminate\Support\Carbon>
     */
    public function creneauxLibres(User $prestataire, string $jour, int $duree = 30): array
    {
        $occupes = RendezVous::where('prestataire_id', $prestataire->id)
            ->occupants()
            ->duJour($jour)
            ->get();

        $libres = [];
        $curseur = Carbon::parse($jour)->setTime(self::HEURE_OUVERTURE, 0);
        $fermeture = Carbon::parse($jour)->setTime(self::HEURE_FERMETURE, 0);

        while ($curseur->copy()->addMinutes($duree)->lessThanOrEqualTo($fermeture)) {
            $finCreneau = $curseur->copy()->addMinutes($duree);

            $chevauche = $occupes->contains(
                fn ($rv) => $curseur->lessThan($rv->fin()) && $finCreneau->greaterThan($rv->debut)
            );

            // Un créneau déjà passé n'est pas proposable
            if (! $chevauche && $curseur->isFuture()) {
                $libres[] = $curseur->copy();
            }

            $curseur->addMinutes($duree);
        }

        return $libres;
    }

    /**
     * Le créneau est-il encore disponible ?
     */
    public function creneauDisponible(User $prestataire, Carbon $debut, int $duree, ?string $ignorerId = null): bool
    {
        $fin = $debut->copy()->addMinutes($duree);

        return ! RendezVous::where('prestataire_id', $prestataire->id)
            ->occupants()
            ->when($ignorerId, fn ($q) => $q->whereKeyNot($ignorerId))
            ->whereDate('debut', $debut->toDateString())
            ->get()
            ->contains(fn ($rv) => $debut->lessThan($rv->fin()) && $fin->greaterThan($rv->debut));
    }

    /**
     * Fixe un rendez-vous patient.
     */
    public function fixer(
        Patient $patient,
        User $prestataire,
        string $debut,
        int $duree,
        ?string $typeConsultationId = null,
        ?string $contact = null,
        ?string $motif = null
    ): RendezVous {
        $debutCarbon = Carbon::parse($debut);

        if (! $this->creneauDisponible($prestataire, $debutCarbon, $duree)) {
            throw new \RuntimeException('Ce créneau est déjà pris ou bloqué pour ce prestataire.');
        }

        return RendezVous::create([
            'establishment_id' => $patient->establishment_id,
            'patient_id' => $patient->id,
            'prestataire_id' => $prestataire->id,
            'type_consultation_id' => $typeConsultationId,
            'cree_par' => auth()->id(),
            'debut' => $debutCarbon,
            'duree_minutes' => $duree,
            'statut' => 'fixe',
            'contact' => $contact ?: $patient->telephone,
            'motif' => $motif,
        ]);
    }

    /**
     * Bloque un créneau sans patient (congé, réunion, garde).
     */
    public function bloquer(User $prestataire, string $debut, int $duree, ?string $motif = null): RendezVous
    {
        $debutCarbon = Carbon::parse($debut);

        if (! $this->creneauDisponible($prestataire, $debutCarbon, $duree)) {
            throw new \RuntimeException('Ce créneau chevauche un rendez-vous déjà fixé.');
        }

        return RendezVous::create([
            'establishment_id' => auth()->user()->establishment_id,
            'prestataire_id' => $prestataire->id,
            'cree_par' => auth()->id(),
            'debut' => $debutCarbon,
            'duree_minutes' => $duree,
            'statut' => 'bloque',
            'motif' => $motif ?: 'Indisponibilité',
        ]);
    }

    public function annuler(RendezVous $rendezVous, ?string $motif = null): void
    {
        $rendezVous->update([
            'statut' => 'annule',
            'annule_at' => now(),
            'annule_par' => auth()->id(),
            'observation' => $motif ?: $rendezVous->observation,
        ]);
    }

    /**
     * Rendez-vous du jour, tous prestataires confondus.
     */
    public function duJour(string $jour)
    {
        return RendezVous::with(['patient', 'prestataire', 'typeConsultation'])
            ->duJour($jour)
            ->orderBy('debut')
            ->get();
    }
}
