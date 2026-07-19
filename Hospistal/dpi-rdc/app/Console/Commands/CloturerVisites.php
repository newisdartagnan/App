<?php

namespace App\Console\Commands;

use App\Models\Visit;
use Illuminate\Console\Command;

/**
 * Une consultation ambulatoire simple se termine 24 h après l'arrivée.
 * Les hospitalisations restent « En cours » jusqu'à la sortie du lit.
 * Les factures déjà émises restent payables à la caisse après clôture.
 */
class CloturerVisites extends Command
{
    protected $signature = 'dpi:cloturer-visites';

    protected $description = 'Clôture les visites ambulatoires de plus de 24 h (les hospitalisations restent en cours)';

    public function handle(): int
    {
        $nombre = Visit::whereIn('type', ['consultation_externe', 'urgence'])
            ->where('statut', 'en_cours')
            ->where('date_entree', '<', now()->subHours(24))
            ->update([
                'statut' => 'termine',
                'date_sortie' => now(),
                'mode_sortie' => 'inconnu',
            ]);

        $this->info("Visites ambulatoires clôturées : {$nombre}");

        return self::SUCCESS;
    }
}
