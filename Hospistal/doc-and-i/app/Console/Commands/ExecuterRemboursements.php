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
        $enAttente = 0;

        // Après une erreur technique : relance avec la même clé, pas avant
        // l'intervalle ; un remboursement bloqué attend la caisse.
        $relanceAvant = now()->subMinutes((int) config('caisse.remboursements.intervalle_minutes'));

        Remboursement::where('statut', StatutRemboursement::Valide->value)
            ->whereNull('blocage')
            ->where(fn ($q) => $q->whereNull('derniere_tentative_le')->orWhere('derniere_tentative_le', '<=', $relanceAvant))
            ->orderBy('valide_le')->orderBy('created_at')
            ->limit(max(1, (int) $this->option('limite')))
            ->get()
            ->each(function (Remboursement $r) use ($remboursements, &$envoyes, &$echoues, &$enAttente) {
                match ($remboursements->executer($r)->statut) {
                    StatutRemboursement::EnCours => $envoyes++,
                    StatutRemboursement::Echoue => $echoues++,
                    default => $enAttente++,
                };
            });

        $this->info("{$envoyes} remboursement(s) envoyé(s), {$echoues} refusé(s) par l'opérateur, {$enAttente} à relancer ou bloqué(s).");

        return self::SUCCESS;
    }
}
