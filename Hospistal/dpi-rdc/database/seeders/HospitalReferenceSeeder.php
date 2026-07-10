<?php

namespace Database\Seeders;

use App\Models\Establishment;
use App\Models\Lit;
use App\Models\Medicament;
use App\Models\Service;
use App\Models\StockMedicament;
use App\Models\TypeExamen;
use Illuminate\Database\Seeder;

class HospitalReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $establishment = Establishment::where('code', env('ESTABLISHMENT_CODE', 'HGR_KINSHASA_01'))->first();
        if (! $establishment) {
            return;
        }

        $this->seedServices($establishment);
        $this->seedMedicaments($establishment);
        $this->seedExamens();
    }

    protected function seedServices(Establishment $establishment): void
    {
        $defs = [
            ['code' => 'MED', 'nom' => 'Médecine interne', 'type' => 'medecine', 'lits' => 20],
            ['code' => 'CHIR', 'nom' => 'Chirurgie', 'type' => 'chirurgie', 'lits' => 12],
            ['code' => 'MAT', 'nom' => 'Maternité', 'type' => 'maternite', 'lits' => 15],
            ['code' => 'PED', 'nom' => 'Pédiatrie', 'type' => 'pediatrie', 'lits' => 10],
        ];

        foreach ($defs as $def) {
            $service = Service::firstOrCreate(
                ['establishment_id' => $establishment->id, 'code' => $def['code']],
                ['nom' => $def['nom'], 'type' => $def['type'], 'capacite_lits' => $def['lits']]
            );

            for ($i = 1; $i <= min($def['lits'], 8); $i++) {
                Lit::firstOrCreate(
                    ['establishment_id' => $establishment->id, 'numero' => $def['code'] . '-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)],
                    ['service_id' => $service->id, 'statut' => 'libre']
                );
            }
        }
    }

    protected function seedMedicaments(Establishment $establishment): void
    {
        $meds = [
            ['denomination_commune' => 'Paracétamol', 'dosage' => '500 mg', 'forme' => 'comprime', 'prix' => 500, 'qte' => 5000],
            ['denomination_commune' => 'Amoxicilline', 'dosage' => '500 mg', 'forme' => 'comprime', 'prix' => 1200, 'qte' => 2000],
            ['denomination_commune' => 'Artéméther-Luméfantrine', 'dosage' => '20/120 mg', 'forme' => 'comprime', 'prix' => 3500, 'qte' => 800],
            ['denomination_commune' => 'Metformine', 'dosage' => '850 mg', 'forme' => 'comprime', 'prix' => 800, 'qte' => 1500],
            ['denomination_commune' => 'Oméprazole', 'dosage' => '20 mg', 'forme' => 'comprime', 'prix' => 1500, 'qte' => 1000],
            ['denomination_commune' => 'Salbutamol', 'dosage' => '100 mcg', 'forme' => 'autre', 'prix' => 4500, 'qte' => 200],
            ['denomination_commune' => 'Ceftriaxone', 'dosage' => '1 g', 'forme' => 'injectable', 'prix' => 8000, 'qte' => 300],
            ['denomination_commune' => 'Ringer Lactate', 'dosage' => '500 ml', 'forme' => 'injectable', 'prix' => 2500, 'qte' => 400],
            ['denomination_commune' => 'Fer + Acide folique', 'dosage' => '200 mg', 'forme' => 'comprime', 'prix' => 600, 'qte' => 2000],
            ['denomination_commune' => 'Quinine', 'dosage' => '300 mg', 'forme' => 'comprime', 'prix' => 2000, 'qte' => 500],
        ];

        foreach ($meds as $m) {
            $med = Medicament::firstOrCreate(
                [
                    'establishment_id' => $establishment->id,
                    'denomination_commune' => $m['denomination_commune'],
                    'dosage' => $m['dosage'],
                ],
                [
                    'forme' => $m['forme'],
                    'unite_dispensation' => $m['forme'] === 'injectable' ? 'flacon' : 'cp',
                    'est_actif' => true,
                ]
            );

            StockMedicament::updateOrCreate(
                ['medicament_id' => $med->id, 'establishment_id' => $establishment->id, 'lot' => 'LOT-2026-01'],
                [
                    'quantite_disponible' => $m['qte'],
                    'prix_unitaire_vente' => $m['prix'],
                    'prix_unitaire_achat' => $m['prix'] * 0.7,
                    'quantite_alerte' => 50,
                ]
            );
        }
    }

    protected function seedExamens(): void
    {
        $labo = [
            ['code' => 'NFS', 'cat' => 'hematologie', 'libelle' => 'Numération formule sanguine', 'prix' => 15000, 'refs' => ['min' => null, 'max' => null, 'unite' => '']],
            ['code' => 'HB', 'cat' => 'hematologie', 'libelle' => 'Hémoglobine', 'prix' => 5000, 'refs' => ['min' => 12, 'max' => 16, 'unite' => 'g/dL']],
            ['code' => 'GLU', 'cat' => 'biochimie', 'libelle' => 'Glycémie à jeun', 'prix' => 3000, 'refs' => ['min' => 0.7, 'max' => 1.1, 'unite' => 'g/L']],
            ['code' => 'CREAT', 'cat' => 'biochimie', 'libelle' => 'Créatininémie', 'prix' => 4000, 'refs' => ['min' => 6, 'max' => 12, 'unite' => 'mg/L']],
            ['code' => 'UREE', 'cat' => 'biochimie', 'libelle' => 'Urée sanguine', 'prix' => 3500, 'refs' => ['min' => 0.15, 'max' => 0.45, 'unite' => 'g/L']],
            ['code' => 'PAL', 'cat' => 'biochimie', 'libelle' => 'Transaminases (ALAT/ASAT)', 'prix' => 8000, 'refs' => ['min' => 0, 'max' => 40, 'unite' => 'UI/L']],
            ['code' => 'CRP', 'cat' => 'biochimie', 'libelle' => 'Protéine C réactive', 'prix' => 6000, 'refs' => ['min' => 0, 'max' => 5, 'unite' => 'mg/L']],
            ['code' => 'GE', 'cat' => 'parasitologie', 'libelle' => 'Goutte épaisse (paludisme)', 'prix' => 5000, 'refs' => ['min' => null, 'max' => null, 'unite' => '']],
            ['code' => 'HIV', 'cat' => 'serologie', 'libelle' => 'Test VIH rapide', 'prix' => 8000, 'refs' => ['min' => null, 'max' => null, 'unite' => '']],
            ['code' => 'BU', 'cat' => 'biochimie', 'libelle' => 'Bandelette urinaire', 'prix' => 2500, 'refs' => ['min' => null, 'max' => null, 'unite' => '']],
        ];

        $imagerie = [
            ['code' => 'IMG-RX-TORAX', 'libelle' => 'Radiographie thorax', 'prix' => 25000],
            ['code' => 'IMG-RX-ABD', 'libelle' => 'Radiographie abdomen', 'prix' => 25000],
            ['code' => 'IMG-ECHO-ABD', 'libelle' => 'Échographie abdominale', 'prix' => 35000],
            ['code' => 'IMG-ECHO-OBST', 'libelle' => 'Échographie obstétricale', 'prix' => 40000],
            ['code' => 'IMG-TDM-CRAN', 'libelle' => 'Scanner cérébral', 'prix' => 150000],
        ];

        foreach ($labo as $e) {
            TypeExamen::firstOrCreate(
                ['code' => $e['code']],
                [
                    'categorie' => $e['cat'],
                    'libelle' => $e['libelle'],
                    'prix' => $e['prix'],
                    'valeurs_reference' => $e['refs'],
                    'delai_heures' => 4,
                    'est_actif' => true,
                ]
            );
        }

        foreach ($imagerie as $e) {
            TypeExamen::firstOrCreate(
                ['code' => $e['code']],
                [
                    'categorie' => 'autre',
                    'libelle' => $e['libelle'],
                    'prix' => $e['prix'],
                    'valeurs_reference' => [],
                    'delai_heures' => 24,
                    'est_actif' => true,
                ]
            );
        }
    }
}
