<?php

namespace App\Console\Commands;

use App\Services\Teleconsultation\Signalisation;
use Illuminate\Console\Command;

/** TLC-01 : purge des signaux WebRTC des salles closes ou passées, et des signaux trop anciens. */
class PurgerSignauxConsultation extends Command
{
    protected $signature = 'teleconsultation:purger-signaux';

    protected $description = 'Supprime les signaux de mise en relation des consultations closes, passées ou trop anciens.';

    public function handle(Signalisation $signalisation): int
    {
        $this->info($signalisation->purgerAnciens().' signal(aux) purgé(s).');

        return self::SUCCESS;
    }
}
