<?php

namespace App\Console\Commands;

use App\Services\Laboratoire\AlertesSoignants;
use Illuminate\Console\Command;

/**
 * PRE-08, RG-10 : résultat critique non traité sous 2 h, résultats à valider
 * depuis plus de 48 h : escalade au coordinateur. Planifiée toutes les
 * 5 minutes ; relancée, elle ne double rien.
 */
class EscaladerAlertesLaboratoire extends Command
{
    protected $signature = 'laboratoire:escalader-alertes';

    protected $description = 'Escalade au coordinateur les résultats critiques non traités et les validations en retard';

    public function handle(AlertesSoignants $alertes): int
    {
        $compte = $alertes->escalader();
        $this->info(sprintf('%d résultat(s) critique(s) escaladé(s), %d bon(s) à valider en retard.', $compte['critiques'], $compte['validations']));

        return self::SUCCESS;
    }
}
