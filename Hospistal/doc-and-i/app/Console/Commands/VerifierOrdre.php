<?php

namespace App\Console\Commands;

use App\Services\Personnel\ComptePersonnelService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** CPT-05 : l'administrateur atteste l'inscription du médecin à l'Ordre ; il devient réservable. */
class VerifierOrdre extends Command
{
    protected $signature = 'personnel:verifier-ordre {email : Courriel du médecin}';

    protected $description = 'Enregistre la vérification de l\'inscription d\'un médecin à l\'Ordre';

    public function handle(ComptePersonnelService $comptes): int
    {
        try {
            $medecin = $comptes->verifierOrdre($this->argument('email'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Inscription à l\'Ordre vérifiée le '.$medecin->ordre_verifie_le->format('d/m/Y H:i').' (numéro '.$medecin->numero_ordre.').');

        return self::SUCCESS;
    }
}
