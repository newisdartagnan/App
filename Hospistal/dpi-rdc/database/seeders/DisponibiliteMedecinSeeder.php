<?php

namespace Database\Seeders;

use App\Models\DisponibiliteMedecin;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Plages de présence par défaut des médecins.
 *
 * Sans plage déclarée, un médecin est réputé disponible en permanence : le
 * tableau de couverture ne dit alors rien d'utile. On installe donc les
 * horaires habituels d'un hôpital congolais — matinée et après-midi en
 * semaine, matinée le samedi — que chaque établissement ajuste ensuite
 * depuis l'écran de disponibilité.
 *
 * Idempotent : un médecin qui a déjà des plages n'est pas touché.
 */
class DisponibiliteMedecinSeeder extends Seeder
{
    /** Horaires de référence : [jour ISO, début, fin]. */
    public const PLAGES_PAR_DEFAUT = [
        [1, '08:00', '12:30'], [1, '14:00', '17:00'],
        [2, '08:00', '12:30'], [2, '14:00', '17:00'],
        [3, '08:00', '12:30'], [3, '14:00', '17:00'],
        [4, '08:00', '12:30'], [4, '14:00', '17:00'],
        [5, '08:00', '12:30'], [5, '14:00', '17:00'],
        [6, '08:00', '12:00'],
    ];

    public function run(): void
    {
        $medecins = User::role('medecin')->where('is_active', true)->get();

        foreach ($medecins as $medecin) {
            self::installerPour($medecin);
        }
    }

    /** Installe les plages par défaut d'un médecin qui n'en a aucune. */
    public static function installerPour(User $medecin): int
    {
        if (DisponibiliteMedecin::where('user_id', $medecin->id)->exists()) {
            return 0;
        }

        foreach (self::PLAGES_PAR_DEFAUT as [$jour, $debut, $fin]) {
            DisponibiliteMedecin::create([
                'user_id' => $medecin->id,
                'jour_semaine' => $jour,
                'heure_debut' => $debut,
                'heure_fin' => $fin,
                'lieu' => 'Consultation externe',
                'is_active' => true,
            ]);
        }

        return count(self::PLAGES_PAR_DEFAUT);
    }
}
