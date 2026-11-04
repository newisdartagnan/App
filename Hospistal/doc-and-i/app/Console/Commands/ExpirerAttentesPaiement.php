<?php

namespace App\Console\Commands;

use App\Services\RendezVous\ReservationService;
use Illuminate\Console\Command;

/** RDV-05, RG-03 : libère les créneaux dont le délai de paiement est échu (planifiée chaque minute). */
class ExpirerAttentesPaiement extends Command
{
    protected $signature = 'rdv:expirer-attentes';

    protected $description = 'Passe en « expiré » les rendez-vous en attente de paiement dont le délai est dépassé.';

    public function handle(ReservationService $reservations): int
    {
        $this->info($reservations->expirerAttentesEchues().' attente(s) expirée(s).');

        return self::SUCCESS;
    }
}
