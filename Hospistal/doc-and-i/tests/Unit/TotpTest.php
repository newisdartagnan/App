<?php

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** CPT-06 : codes TOTP (RFC 6238, SHA-1, 6 chiffres, pas de 30 s, ±1 pas). */
class TotpTest extends TestCase
{
    /** Secret ASCII « 12345678901234567890 » de l'annexe B de la RFC 6238, en base 32. */
    private const SECRET_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** @return array<string, array{int, string}> */
    public static function vecteursRfc6238(): array
    {
        return [
            'T=59' => [59, '94287082'],
            'T=1111111109' => [1111111109, '07081804'],
            'T=1111111111' => [1111111111, '14050471'],
            'T=1234567890' => [1234567890, '89005924'],
            'T=2000000000' => [2000000000, '69279037'],
            'T=20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('vecteursRfc6238')]
    public function test_les_vecteurs_de_la_rfc_6238_sont_respectes(int $instant, string $attendu): void
    {
        $this->assertSame($attendu, Totp::code(self::SECRET_RFC, intdiv($instant, 30), 8));
        $this->assertSame(substr($attendu, -6), Totp::code(self::SECRET_RFC, intdiv($instant, 30)));
    }

    public function test_le_base32_fait_l_aller_retour(): void
    {
        $this->assertSame(self::SECRET_RFC, Totp::base32Encoder('12345678901234567890'));
        $this->assertSame('12345678901234567890', Totp::base32Decoder(self::SECRET_RFC));
        $this->assertSame('12345678901234567890', Totp::base32Decoder('gezd gnbv gy3t qojq gezd gnbv gy3t qojq'));
    }

    public function test_un_secret_genere_fait_160_bits_en_base32(): void
    {
        $secret = Totp::genererSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
        $this->assertSame(20, strlen(Totp::base32Decoder($secret)));
        $this->assertNotSame($secret, Totp::genererSecret());
    }

    public function test_le_code_courant_et_les_pas_voisins_sont_acceptes(): void
    {
        $instant = 1_800_000_015;
        $pas = intdiv($instant, 30);

        $this->assertSame($pas, Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas), null, $instant));
        $this->assertSame($pas - 1, Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas - 1), null, $instant));
        $this->assertSame($pas + 1, Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas + 1), null, $instant));
    }

    public function test_un_code_hors_tolerance_est_refuse(): void
    {
        $instant = 1_800_000_015;
        $pas = intdiv($instant, 30);

        $this->assertNull(Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas - 2), null, $instant));
        $this->assertNull(Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas + 2), null, $instant));
    }

    public function test_un_code_deja_servi_ou_plus_ancien_est_refuse(): void
    {
        $instant = 1_800_000_015;
        $pas = intdiv($instant, 30);

        $this->assertNull(Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas), $pas, $instant), 'Rejeu dans le même pas.');
        $this->assertNull(Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas - 1), $pas, $instant), 'Code plus ancien que le dernier accepté.');
        $this->assertSame($pas + 1, Totp::verifier(self::SECRET_RFC, Totp::code(self::SECRET_RFC, $pas + 1), $pas, $instant));
    }

    public function test_une_saisie_mal_formee_est_refusee(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', '12 34 56x'] as $saisie) {
            $this->assertNull(Totp::verifier(self::SECRET_RFC, $saisie, null, 1_800_000_015), $saisie);
        }
    }

    public function test_l_adresse_de_provisionnement_suit_le_format_des_applications(): void
    {
        $uri = Totp::uriProvisionnement(self::SECRET_RFC, 'Doc&I', 'medecin@exemple.cd');

        $this->assertStringStartsWith('otpauth://totp/Doc%26I:medecin%40exemple.cd?', $uri);
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $parametres);
        $this->assertSame(['secret' => self::SECRET_RFC, 'issuer' => 'Doc&I', 'algorithm' => 'SHA1', 'digits' => '6', 'period' => '30'], $parametres);
    }
}
