<?php

namespace App\Console\Commands;

use App\Services\Paiement\PaiementService;
use Illuminate\Console\Command;

/** PAY-04, RG-03 : tentatives sans réponse de l'opérateur closes après le délai (planifiée chaque minute). */
class ExpirerTentativesPaiement extends Command
{
    protected $signature = 'paiement:expirer-tentatives';

    protected $description = 'Passe en « expiré » les tentatives de paiement restées sans réponse de l\'opérateur.';

    public function handle(PaiementService $paiements): int
    {
        $this->info($paiements->expirerTentatives().' tentative(s) expirée(s).');

        return self::SUCCESS;
    }
}
