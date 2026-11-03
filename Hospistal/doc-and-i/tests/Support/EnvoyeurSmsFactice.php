<?php

namespace Tests\Support;

use App\Sms\EnvoyeurSms;

/** Retient les SMS au lieu de les envoyer, pour que les tests les lisent. */
final class EnvoyeurSmsFactice implements EnvoyeurSms
{
    /** @var list<array{telephone: string, message: string}> */
    public array $envoyes = [];

    public function envoyer(string $telephone, string $message): void
    {
        $this->envoyes[] = ['telephone' => $telephone, 'message' => $message];
    }

    public function dernierCode(): ?string
    {
        $dernier = end($this->envoyes);

        return $dernier && preg_match('/\b(\d{6})\b/', $dernier['message'], $m) ? $m[1] : null;
    }
}
