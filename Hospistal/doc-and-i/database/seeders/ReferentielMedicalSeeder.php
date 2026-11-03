<?php

namespace Database\Seeders;

use App\Models\Specialite;
use App\Models\TypeConsultation;
use Illuminate\Database\Seeder;

/**
 * Spécialités et types de consultation proposés en ligne. Les PRIX ne sont
 * pas fixés ici : c'est une décision du client (cahier des charges, section
 * 17). Un type sans prix ne peut pas être réservé.
 */
class ReferentielMedicalSeeder extends Seeder
{
    public const SPECIALITES = [
        'medecine-generale' => 'Médecine générale',
        'pediatrie' => 'Pédiatrie',
        'gynecologie-obstetrique' => 'Gynécologie-obstétrique',
        'medecine-interne' => 'Médecine interne',
        'cardiologie' => 'Cardiologie',
        'dermatologie' => 'Dermatologie',
        'psychiatrie' => 'Psychiatrie',
        'nutrition' => 'Nutrition',
    ];

    public function run(): void
    {
        $ordre = 0;

        foreach (self::SPECIALITES as $slug => $nom) {
            $specialite = Specialite::updateOrCreate(['slug' => $slug], ['nom' => $nom, 'ordre' => ++$ordre]);

            foreach ([['Première consultation', 20], ['Consultation de suivi', 15]] as [$type, $duree]) {
                TypeConsultation::firstOrCreate(
                    ['specialite_id' => $specialite->id, 'nom' => $type],
                    ['duree_minutes' => $duree, 'devise' => 'USD']
                );
            }
        }
    }
}
