<?php

namespace App\Console\Commands;

use App\Support\Totp;
use Illuminate\Console\Command;

/** Code à 6 chiffres du moment pour les comptes de démonstration (local seulement), sans téléphone. */
class CodeDemonstration extends Command
{
    protected $signature = 'demo:code';

    protected $description = 'Affiche le code à 6 chiffres actuel des comptes du personnel de démonstration (local seulement)';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('Réservé à APP_ENV=local.');

            return self::FAILURE;
        }

        $restant = 30 - (time() % 30);
        $this->info(Totp::code(PreparerDemonstration::SECRET_TOTP, Totp::pas()).'  (valable encore '.$restant.' s)');

        return self::SUCCESS;
    }
}
