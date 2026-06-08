<?php

namespace App\Services;

use App\Models\Patient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PatientDeduplicationService
{
    public function findDuplicates(array $data, ?string $excludeId = null): Collection
    {
        $nom = $data['nom'] ?? '';
        $prenom = $data['prenom'] ?? '';
        $sexe = $data['sexe'] ?? null;
        $dateNaissance = $data['date_naissance'] ?? null;
        $lieuNaissance = $data['lieu_naissance'] ?? null;

        $query = Patient::query()
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->when($sexe, fn ($q) => $q->where('sexe', $sexe));

        if (DB::getDriverName() === 'pgsql' && $nom) {
            $query->where(function ($q) use ($nom, $prenom) {
                $q->whereRaw('nom % ?', [$nom])
                    ->orWhereRaw('prenom % ?', [$prenom]);
            });
        } else {
            $query->where(function ($q) use ($nom, $prenom) {
                $q->where('nom', 'ilike', "%{$nom}%")
                    ->orWhere('prenom', 'ilike', "%{$prenom}%");
            });
        }

        return $query->limit(20)->get()->map(function (Patient $patient) use ($data) {
            $patient->duplicate_score = $this->calculateScore($data, $patient);

            return $patient;
        })->filter(fn ($p) => $p->duplicate_score >= 0.65)
            ->sortByDesc('duplicate_score')
            ->values();
    }

    public function calculateScore(array $input, Patient $candidate): float
    {
        $score = 0.0;

        if (! empty($input['date_naissance']) && $candidate->date_naissance?->format('Y-m-d') === $input['date_naissance']) {
            $score += 0.45;
        }

        if (! empty($input['nom_soundex']) && $input['nom_soundex'] === $candidate->nom_soundex) {
            $score += 0.25;
        } elseif (strcasecmp($input['nom'] ?? '', $candidate->nom) === 0) {
            $score += 0.20;
        }

        if (! empty($input['prenom_soundex']) && $input['prenom_soundex'] === $candidate->prenom_soundex) {
            $score += 0.15;
        } elseif (strcasecmp($input['prenom'] ?? '', $candidate->prenom) === 0) {
            $score += 0.10;
        }

        if (! empty($input['lieu_naissance']) && strcasecmp($input['lieu_naissance'], $candidate->lieu_naissance ?? '') === 0) {
            $score += 0.10;
        }

        if (($input['sexe'] ?? null) === $candidate->sexe) {
            $score += 0.05;
        }

        return min(1.0, round($score, 2));
    }

    public function metaphone(string $value): string
    {
        $normalized = mb_strtoupper(trim($value));

        return metaphone($normalized) ?: $normalized;
    }
}
