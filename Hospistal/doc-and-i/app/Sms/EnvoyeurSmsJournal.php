<?php

namespace App\Sms;

use App\Support\Telephone;
use Illuminate\Support\Facades\Log;

/**
 * Développement : le SMS est écrit dans storage/logs/laravel.log au lieu
 * d'être envoyé. Le code de connexion s'y lit pour tester en local.
 * Refusé en production (AppServiceProvider) ; le numéro y est masqué.
 */
final class EnvoyeurSmsJournal implements EnvoyeurSms
{
    public function envoyer(string $telephone, string $message): void
    {
        Log::info('SMS (non envoyé, pilote « log ») à '.Telephone::masquer($telephone).' : '.$message);
    }
}
