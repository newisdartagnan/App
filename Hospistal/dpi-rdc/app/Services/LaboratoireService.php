<?php

namespace App\Services;

use App\Models\BonSortie;
use App\Models\ExamenLaboratoire;
use App\Models\ResultatExamen;
use App\Models\TypeExamen;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

class LaboratoireService
{
    public function prescrireExamens(
        Visit $visit,
        array $typeExamenIds,
        string $domaine = 'labo',
        bool $urgence = false,
        ?string $observations = null
    ): ExamenLaboratoire {
        return DB::transaction(function () use ($visit, $typeExamenIds, $domaine, $urgence, $observations) {
            $visit->load('patient');

            $patient = $visit->patient;

            $examen = ExamenLaboratoire::create([
                'numero_bon' => $this->genererNumeroBon($domaine),
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'prescripteur_id' => auth()->id(),
                'date_prescription' => now(),
                'statut' => 'prescrit',
                'domaine' => $domaine,
                'urgence' => $urgence,
                'observations_cliniques' => $observations,
            ]);

            $types = TypeExamen::whereIn('id', $typeExamenIds)->where('est_actif', true)->get();

            foreach ($types as $type) {
                $refs = $type->valeurs_reference ?? [];

                // Panels multi-paramètres (NFS, ionogramme…) : une ligne de
                // résultat par paramètre, bornes résolues selon sexe / âge.
                $parametres = $refs['parametres'] ?? null;

                if (is_array($parametres) && $parametres !== []) {
                    foreach ($parametres as $param) {
                        [$min, $max] = $this->bornesPourPatient($param, $patient);
                        ResultatExamen::create([
                            'examen_id' => $examen->id,
                            'type_examen_id' => $type->id,
                            'parametre' => $param['nom'] ?? null,
                            'valeur_reference_min' => $min,
                            'valeur_reference_max' => $max,
                            'unite' => $param['unite'] ?? null,
                            'created_at' => now(),
                        ]);
                    }
                } else {
                    ResultatExamen::create([
                        'examen_id' => $examen->id,
                        'type_examen_id' => $type->id,
                        'valeur_reference_min' => $refs['min'] ?? null,
                        'valeur_reference_max' => $refs['max'] ?? null,
                        'unite' => $refs['unite'] ?? null,
                        'created_at' => now(),
                    ]);
                }
            }

            return $examen->fresh(['resultats.typeExamen', 'patient', 'visit']);
        });
    }

    /**
     * Bornes de référence selon le sexe et l'âge du patient
     * (homme / femme / enfant < 16 ans — catalogue CSK).
     */
    protected function bornesPourPatient(array $param, $patient): array
    {
        $age = $patient->date_naissance?->age;
        $groupe = ($age !== null && $age < 16) ? 'enfant'
            : ($patient->sexe === 'F' ? 'femme' : 'homme');

        $bornes = $param[$groupe] ?? $param['homme'] ?? [];

        return [$bornes['min'] ?? null, $bornes['max'] ?? null];
    }

    /**
     * Numéro de bon séquentiel par domaine et par année : LAB-2026-000123.
     */
    protected function genererNumeroBon(string $domaine): string
    {
        $prefix = ($domaine === 'imagerie' ? 'IMG-' : 'LAB-') . now()->format('Y') . '-';

        $last = ExamenLaboratoire::query()
            ->where('numero_bon', 'like', $prefix . '%')
            ->orderByDesc('numero_bon')
            ->value('numero_bon');

        $seq = $last ? (int) substr($last, -6) + 1 : 1;

        return $prefix . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    public function saisirResultats(ExamenLaboratoire $examen, array $valeurs): ExamenLaboratoire
    {
        return DB::transaction(function () use ($examen, $valeurs) {
            foreach ($valeurs as $resultatId => $data) {
                $resultat = ResultatExamen::where('examen_id', $examen->id)
                    ->where('id', $resultatId)
                    ->first();

                if (! $resultat) {
                    continue;
                }

                $valeurNumerique = isset($data['valeur_numerique']) && $data['valeur_numerique'] !== ''
                    ? (float) $data['valeur_numerique']
                    : null;

                $interpretation = $this->interpreter($resultat, $valeurNumerique, $data['valeur_brute'] ?? null);

                $resultat->update([
                    'valeur_brute' => $data['valeur_brute'] ?? null,
                    'valeur_numerique' => $valeurNumerique,
                    'interpretation' => $interpretation,
                    'commentaire' => $data['commentaire'] ?? null,
                ]);
            }

            $examen->update([
                'statut' => 'resultat_disponible',
                'date_resultat' => now(),
                'laborantin_id' => auth()->id(),
            ]);

            return $examen->fresh(['resultats.typeExamen']);
        });
    }

    public function valider(ExamenLaboratoire $examen, ?string $conclusion = null): ExamenLaboratoire
    {
        $examen->resultats()->update([
            'valide_par' => auth()->id(),
            'valide_at' => now(),
        ]);

        $examen->update([
            'statut' => 'valide',
            'conclusion' => $conclusion ?: $examen->conclusion,
        ]);

        return $examen->fresh(['resultats.typeExamen']);
    }

    public function peutRealiser(ExamenLaboratoire $examen): bool
    {
        if ($examen->facture_id) {
            $facture = $examen->facture;
            if ($facture && $facture->statut !== 'payee') {
                return false;
            }
        }

        return BonSortie::where('examen_id', $examen->id)
            ->where('statut', 'emis')
            ->exists() || $examen->statut === 'prescrit';
    }

    protected function interpreter(ResultatExamen $resultat, ?float $valeur, ?string $brute): ?string
    {
        if ($valeur === null) {
            if ($brute && in_array(strtolower($brute), ['positif', 'negatif'], true)) {
                return strtolower($brute) === 'positif' ? 'positif' : 'negatif';
            }
            return null;
        }

        $min = $resultat->valeur_reference_min;
        $max = $resultat->valeur_reference_max;

        if ($min !== null && $valeur < $min) {
            return 'bas';
        }
        if ($max !== null && $valeur > $max) {
            return $valeur > ($max * 1.5) ? 'critique' : 'eleve';
        }

        return 'normal';
    }
}
