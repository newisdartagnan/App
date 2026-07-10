<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\Lit;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

class VisiteService
{
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
