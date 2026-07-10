<?php

namespace Database\Seeders;

use App\Models\Assurance;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\PatientAssurance;
use App\Services\DossierNumberService;
use App\Services\PatientDeduplicationService;
use Illuminate\Database\Seeder;

class DemoPatientSeeder extends Seeder
{
    public function run(): void
    {
        $establishment = Establishment::where('is_active', true)->first();
        if (! $establishment) {
            return;
        }

        $dedup = app(PatientDeduplicationService::class);
        $dossier = app(DossierNumberService::class);

        $assurance = Assurance::firstOrCreate(
            ['code' => 'MUTAS'],
            [
                'nom' => 'Mutuelle ASBL Santé',
                'taux_couverture' => 80,
                'plafond_annuel_cdf' => 5000000,
                'est_actif' => true,
            ]
        );

        $patients = [
            [
                'nom' => 'KABONGO',
                'prenom' => 'Marie',
                'sexe' => 'F',
                'date_naissance' => '1990-03-15',
                'type_prise_en_charge' => 'prive',
                'telephone' => '+243900000001',
                'province' => 'Kinshasa',
            ],
            [
                'nom' => 'MULUMBA',
                'prenom' => 'Jean-Paul',
                'sexe' => 'M',
                'date_naissance' => '1985-07-22',
                'type_prise_en_charge' => 'assurance',
                'telephone' => '+243900000002',
                'province' => 'Kinshasa',
                'assurance' => $assurance,
                'numero_police' => 'POL-2026-0042',
            ],
            [
                'nom' => 'TSHILOMBO',
                'prenom' => 'Grace',
                'sexe' => 'F',
                'date_naissance' => '2000-11-08',
                'type_prise_en_charge' => 'indigent',
                'province' => 'Kinshasa',
            ],
        ];

        foreach ($patients as $data) {
            $existing = Patient::where('nom', $data['nom'])
                ->where('prenom', $data['prenom'])
                ->first();

            if ($existing) {
                continue;
            }

            $patient = Patient::create([
                'establishment_id' => $establishment->id,
                'dossier_number' => $dossier->generate($establishment->code),
                'nom' => $data['nom'],
                'prenom' => $data['prenom'],
                'nom_soundex' => $dedup->metaphone($data['nom']),
                'prenom_soundex' => $dedup->metaphone($data['prenom']),
                'sexe' => $data['sexe'],
                'date_naissance' => $data['date_naissance'],
                'type_prise_en_charge' => $data['type_prise_en_charge'],
                'telephone' => $data['telephone'] ?? null,
                'province' => $data['province'] ?? null,
                'nationalite' => 'Congolaise',
            ]);

            if (($data['type_prise_en_charge'] ?? '') === 'assurance' && isset($data['assurance'])) {
                PatientAssurance::firstOrCreate(
                    [
                        'patient_id' => $patient->id,
                        'assurance_id' => $data['assurance']->id,
                    ],
                    [
                        'numero_police' => $data['numero_police'],
                        'est_actif' => true,
                        'annee_courante' => (int) date('Y'),
                    ]
                );
                $patient->update([
                    'assurance_nom' => $data['assurance']->nom,
                    'assurance_numero' => $data['numero_police'],
                ]);
            }
        }
    }
}
