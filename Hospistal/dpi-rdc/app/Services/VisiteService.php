<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\Facture;
use App\Models\Lit;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

class VisiteService
{
    /**
     * Accueil : le patient est envoyé à la caisse AVANT de voir le médecin.
     * Crée la visite (en_attente) et la facture de consultation à régler.
     */
    public function envoyerEnConsultation(
        Patient $patient,
        string $type,
        ?string $motif = null,
        ?string $typeConsultationId = null
    ): Visit {
        return DB::transaction(function () use ($patient, $type, $motif, $typeConsultationId) {
            // Contrôle de résultat gratuit : même type de consultation
            // dans les 7 derniers jours → pas de nouvelle facture.
            $gratuite = $typeConsultationId
                && Visit::where('patient_id', $patient->id)
                    ->where('type_consultation_id', $typeConsultationId)
                    ->where('date_entree', '>=', now()->subDays(7))
                    ->whereHas('consultations')
                    ->exists();

            $visit = Visit::create([
                'patient_id' => $patient->id,
                'establishment_id' => auth()->user()->establishment_id,
                'user_id' => auth()->id(),
                'type' => $type,
                'type_consultation_id' => $typeConsultationId,
                // Gratuite : directement dans la file du médecin, sans caisse
                'statut' => $gratuite ? 'en_cours' : 'en_attente',
                'gratuite' => $gratuite,
                'est_payant' => ! $gratuite,
                'date_entree' => now(),
                'motif_consultation' => $motif,
            ]);

            if (! $gratuite) {
                app(FacturationService::class)->creerFactureConsultation($visit);
            }

            return $visit->fresh(['typeConsultation', 'factures']);
        });
    }

    /**
     * Visite active (non terminée) d'un patient, s'il y en a une.
     */
    public function visiteActive(Patient $patient): ?Visit
    {
        return Visit::where('patient_id', $patient->id)
            ->whereIn('statut', ['en_attente', 'en_cours'])
            ->orderByDesc('date_entree')
            ->first();
    }

    public function hospitaliser(Visit $visit, string $serviceId, string $litId): Visit
    {
        return DB::transaction(function () use ($visit, $serviceId, $litId) {
            $lit = Lit::where('id', $litId)->where('statut', 'libre')->lockForUpdate()->firstOrFail();

            $visit->update([
                'type' => 'hospitalisation',
                'statut' => 'en_cours',
                'service_id' => $serviceId,
                'lit_id' => $lit->id,
            ]);

            $lit->update(['statut' => 'occupe']);

            return $visit->fresh(['service', 'lit', 'patient']);
        });
    }

    public function sortir(
        Visit $visit,
        string $modeSortie = 'gueri',
        ?string $observations = null
    ): Visit {
        return DB::transaction(function () use ($visit, $modeSortie, $observations) {
            if ($visit->lit_id) {
                Lit::where('id', $visit->lit_id)->update(['statut' => 'libre']);
            }

            $visit->update([
                'statut' => 'termine',
                'date_sortie' => now(),
                'duree_sejour_jours' => $visit->joursHospitalisation(),
                'mode_sortie' => $modeSortie,
                'lit_id' => null,
            ]);

            return $visit->fresh(['patient', 'factures', 'examensLaboratoire']);
        });
    }

    public function facturesImpayees(Visit $visit): int
    {
        return $visit->factures()
            ->whereIn('statut', ['emise', 'partiellement_payee'])
            ->count();
    }
}
