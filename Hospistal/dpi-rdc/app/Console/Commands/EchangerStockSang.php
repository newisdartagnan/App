<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use App\Services\ReseauSangService;
use Illuminate\Console\Command;

/**
 * L'échange des bulletins de stock avec le réseau.
 *
 * À planifier au quart d'heure : entre deux passages, l'écran du réseau
 * annonce l'âge de chaque ligne, et personne n'est induit en erreur. Le
 * bouton « Rafraîchir » de l'écran fait la même chose à la demande, pour
 * l'urgence qui n'attend pas.
 */
class EchangerStockSang extends Command
{
    protected $signature = 'dpi:sang-reseau {--etablissement= : Le code d\'un établissement précis}';

    protected $description = 'Publier le stock de sang au réseau et rapporter celui des autres hôpitaux';

    public function handle(ReseauSangService $reseau): int
    {
        if (! $reseau->configure()) {
            $this->warn('Aucun point de rendez-vous configuré (CENTRAL_API_URL) : rien à échanger.');

            return self::SUCCESS;
        }

        $maisons = Establishment::query()
            ->where('is_active', true)
            ->whereNotNull('central_sync_token')
            ->when($this->option('etablissement'), fn ($q, $code) => $q->where('code', $code))
            ->get();

        if ($maisons->isEmpty()) {
            $this->warn('Aucun établissement muni d\'un jeton de réseau.');

            return self::SUCCESS;
        }

        $echecs = 0;

        foreach ($maisons as $maison) {
            $resultat = $reseau->echanger($maison);

            $this->line("{$maison->code} : {$resultat['message']}");

            if (! $resultat['publie'] && $resultat['connus'] === 0) {
                $echecs++;
            }
        }

        // Une liaison coupée n'est pas une panne du logiciel : la tâche
        // planifiée ne doit pas sonner l'alarme à chaque orage.
        return $echecs === $maisons->count() ? self::FAILURE : self::SUCCESS;
    }
}
