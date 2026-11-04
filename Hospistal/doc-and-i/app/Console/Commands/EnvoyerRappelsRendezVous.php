<?php

namespace App\Console\Commands;

use App\Services\RendezVous\RappelsService;
use Illuminate\Console\Command;

/** RDV-06 : rappels SMS 24 h et 1 h avant chaque rendez-vous confirmé (planifiée toutes les 5 minutes). */
class EnvoyerRappelsRendezVous extends Command
{
    protected $signature = 'rdv:envoyer-rappels';

    protected $description = 'Envoie les rappels SMS des rendez-vous confirmés (la veille et une heure avant).';

    public function handle(RappelsService $rappels): int
    {
        $this->info($rappels->envoyer().' rappel(s) envoyé(s).');

        return self::SUCCESS;
    }
}
