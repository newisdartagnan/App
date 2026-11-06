<?php

namespace App\Console\Commands;

use App\Services\Teleconsultation\FinConsultation;
use Illuminate\Console\Command;

/**
 * RG-06, RG-07 : salles de consultation fermées sans conclusion. Planifiée
 * toutes les 5 minutes ; une reprise est sans risque (seuls les rendez-vous
 * encore « confirmés » sont traités, sous verrou).
 */
class ConstaterSallesFermees extends Command
{
    protected $signature = 'teleconsultation:constater-salles-fermees';

    protected $description = 'Termine les consultations restées ouvertes et constate les absences après fermeture de la salle';

    public function handle(FinConsultation $fin): int
    {
        $compte = $fin->constaterLesSallesFermees();

        $this->info(sprintf('%d terminée(s), %d patient(s) absent(s), %d médecin(s) absent(s).',
            $compte['terminees'], $compte['patient_absent'], $compte['medecin_absent']));

        return self::SUCCESS;
    }
}
