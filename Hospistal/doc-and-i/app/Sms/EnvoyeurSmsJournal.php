<?php

namespace App\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Développement : le SMS est écrit dans storage/logs/laravel.log au lieu
 * d'être envoyé. Le code de connexion s'y lit pour tester en local.
 */
final class EnvoyeurSmsJournal implements EnvoyeurSms
{
    public function envoyer(string $telephone, string $message): void
    {
        Log::info('SMS (non envoyé, pilote « log ») à '.$telephone.' : '.$message);
    }
}
