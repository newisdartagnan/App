<?php

namespace App\Services;

use App\Models\Medicament;
use App\Models\Patient;
use App\Models\PatientReferentielMedical;
use App\Models\ReferentielMedical;

/**
 * Antécédents et allergies du patient, choisis dans un référentiel structuré.
 *
 * La codification permet ce que le texte libre interdit : confronter une
 * prescription aux allergies connues du patient avant de la valider.
 */
class DossierMedicalService
{
    public function antecedents(Patient $patient)
    {
        return PatientReferentielMedical::with(['referentiel', 'saisiPar'])
            ->where('patient_id', $patient->id)
            ->whereHas('referentiel', fn ($q) => $q->where('type', 'antecedent'))
            ->get();
    }

    public function allergies(Patient $patient)
    {
        return PatientReferentielMedical::with(['referentiel', 'saisiPar'])
            ->where('patient_id', $patient->id)
            ->whereHas('referentiel', fn ($q) => $q->where('type', 'allergie'))
            ->get();
    }

    public function ajouter(
        Patient $patient,
        string $referentielId,
        ?string $severite = null,
        ?string $precision = null,
        ?string $dateConstat = null
    ): PatientReferentielMedical {
        return PatientReferentielMedical::updateOrCreate(
            ['patient_id' => $patient->id, 'referentiel_id' => $referentielId],
            [
                'saisi_par' => auth()->id(),
                'severite' => $severite,
                'precision' => $precision,
                'date_constat' => $dateConstat,
            ]
        );
    }

    /**
     * Confronte une liste de médicaments aux allergies connues du patient.
     *
     * La correspondance se fait sur la molécule déclarée dans le référentiel,
     * recherchée dans la dénomination commune et le nom commercial du produit.
     *
     * @param  array<int, string>  $medicamentIds
     * @return array<int, array{medicament: string, allergie: string, severite: ?string}>
     */
    public function alertesAllergie(Patient $patient, array $medicamentIds): array
    {
        $medicamentIds = array_values(array_filter($medicamentIds));
        if ($medicamentIds === []) {
            return [];
        }

        $allergies = $this->allergies($patient)
            ->filter(fn ($a) => filled($a->referentiel->molecule));

        if ($allergies->isEmpty()) {
            return [];
        }

        $medicaments = Medicament::whereIn('id', $medicamentIds)->get();
        $alertes = [];

        foreach ($medicaments as $medicament) {
            $cible = mb_strtolower(
                $medicament->denomination_commune . ' ' . ($medicament->nom_commercial ?? '')
            );

            foreach ($allergies as $allergie) {
                $molecule = mb_strtolower($allergie->referentiel->molecule);

                if ($molecule !== '' && str_contains($cible, $molecule)) {
                    $alertes[] = [
                        'medicament' => trim($medicament->denomination_commune . ' ' . $medicament->dosage),
                        'allergie' => $allergie->referentiel->libelle,
                        'severite' => $allergie->severite,
                    ];
                }
            }
        }

        return $alertes;
    }

    /**
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, ReferentielMedical>>
     */
    public function catalogue(string $type)
    {
        return ReferentielMedical::where('type', $type)
            ->where('est_actif', true)
            ->orderBy('categorie')
            ->orderBy('libelle')
            ->get()
            ->groupBy(fn ($r) => $r->categorie ?: 'Autres');
    }
}
