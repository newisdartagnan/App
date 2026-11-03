<?php

namespace Tests\Unit;

use App\Support\Telephone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TelephoneTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function memeNumero(): array
    {
        return [
            'format local' => ['0812345678', '+243812345678'],
            'local avec espaces' => ['081 234 56 78', '+243812345678'],
            'international' => ['+243812345678', '+243812345678'],
            'international espacé' => ['+243 812 345 678', '+243812345678'],
            'sans le plus' => ['243812345678', '+243812345678'],
            'préfixe 00' => ['00243812345678', '+243812345678'],
            'neuf chiffres' => ['812345678', '+243812345678'],
            'tirets et points' => ['081-234.56.78', '+243812345678'],
            'Airtel en 9' => ['0991234567', '+243991234567'],
        ];
    }

    #[DataProvider('memeNumero')]
    public function test_toutes_les_ecritures_d_un_numero_donnent_le_meme_compte(string $saisie, string $attendu): void
    {
        $this->assertSame($attendu, Telephone::normaliser($saisie));
    }

    /** @return array<string, array{?string}> */
    public static function pasUnMobileCongolais(): array
    {
        return [
            'vide' => [''],
            'null' => [null],
            'trop court' => ['08123456'],
            'trop long' => ['08123456789'],
            'fixe ou préfixe inconnu' => ['0212345678'],
            'autre pays' => ['+33612345678'],
            'lettres' => ['08123ABC78'],
        ];
    }

    #[DataProvider('pasUnMobileCongolais')]
    public function test_un_numero_qui_n_est_pas_un_mobile_congolais_est_refuse(?string $saisie): void
    {
        $this->assertNull(Telephone::normaliser($saisie));
    }

    public function test_le_numero_affiche_est_masque(): void
    {
        $this->assertSame('+243 81 ••• •• 78', Telephone::masquer('+243812345678'));
    }
}
