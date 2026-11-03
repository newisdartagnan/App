<?php

namespace App\Sms;

/**
 * Envoi d'un SMS. Un SMS ne contient jamais de donnée médicale (NOT-02) :
 * seulement un code, un rappel d'heure, ou « un document vous attend ».
 */
interface EnvoyeurSms
{
    public function envoyer(string $telephone, string $message): void;
}
