<?php

namespace App\Console\Commands;

use App\Services\SauvegardeService;
use Illuminate\Console\Command;

class Sauvegarder extends Command
{
    protected $signature = 'dpi:sauvegarder';

    protected $description = 'Sauvegarde la base de données dans storage/app/sauvegardes (pg_dump)';

    public function handle(SauvegardeService $service): int
    {
        $resultat = $service->sauvegarder();
        $resultat['ok'] ? $this->info($resultat['message']) : $this->error($resultat['message']);

        return $resultat['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
