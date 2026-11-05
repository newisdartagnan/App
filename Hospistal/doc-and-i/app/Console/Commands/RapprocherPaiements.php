<?php

namespace App\Console\Commands;

use App\Services\Caisse\RapprochementService;
use App\Services\Paiement\Fournisseur\ErreurFournisseur;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * PAY-06 : rapprochement d'une journée avec le relevé de l'agrégateur.
 * Planifiée chaque nuit pour la veille (heure de Kinshasa) ; relançable sans
 * doublon, pour un jour donné : php artisan paiement:rapprocher 2026-12-07.
 */
class RapprocherPaiements extends Command
{
    protected $signature = 'paiement:rapprocher {jour? : Jour à rapprocher (AAAA-MM-JJ), la veille par défaut}';

    protected $description = 'Importe le relevé de l\'agrégateur et crée les écarts de rapprochement';

    public function handle(RapprochementService $rapprochement): int
    {
        $saisie = $this->argument('jour');

        if ($saisie !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $saisie)) {
            $this->error('Jour attendu au format AAAA-MM-JJ.');

            return self::FAILURE;
        }

        $jour = $saisie !== null
            ? CarbonImmutable::createFromFormat('!Y-m-d', $saisie, RapprochementService::FUSEAU)
            : CarbonImmutable::now(RapprochementService::FUSEAU)->subDay()->startOfDay();

        if ($jour === false || $jour->format('Y-m-d') !== ($saisie ?? $jour->format('Y-m-d'))) {
            $this->error('Jour invalide.');

            return self::FAILURE;
        }

        try {
            $resultat = $rapprochement->rapprocher($jour);
        } catch (ErreurFournisseur $e) {
            $this->error('Relevé indisponible : '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s : %d ligne(s) au relevé, %d nouvel(s) écart(s).', $jour->format('d/m/Y'), $resultat['lignes'], $resultat['ecarts']));

        return self::SUCCESS;
    }
}
