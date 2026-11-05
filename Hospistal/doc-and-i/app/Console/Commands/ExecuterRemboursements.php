<?php

namespace App\Console\Commands;

use App\Enums\StatutRemboursement;
use App\Models\Remboursement;
use App\Services\Paiement\RemboursementService;
use Illuminate\Console\Command;

/**
 * PAY-07 : envoie au prestataire les remboursements validés (par la caisse,
 * ou nés validés : paiement tardif, en double). Planifiée chaque minute ;
 * une reprise est sans risque, le prestataire est idempotent.
 */
class ExecuterRemboursements extends Command
{
    protected $signature = 'paiement:executer-remboursements {--limite=100 : Remboursements traités par passage}';

    protected $description = 'Envoie au prestataire les remboursements validés';

    public function handle(RemboursementService $remboursements): int
    {
        $envoyes = 0;
        $echoues = 0;

        Remboursement::where('statut', StatutRemboursement::Valide->value)
            ->orderBy('valide_le')->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limite')))
            ->get()
            ->each(function (Remboursement $r) use ($remboursements, &$envoyes, &$echoues) {
                $resultat = $remboursements->executer($r);
                $resultat->statut === StatutRemboursement::Echoue ? $echoues++ : $envoyes++;
            });

        $this->info("{$envoyes} remboursement(s) envoyé(s), {$echoues} en échec.");

        return self::SUCCESS;
    }
}
