<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Codes à usage unique basés sur le temps (RFC 6238 sur RFC 4226), tels que
 * les produisent Google Authenticator, FreeOTP ou Aegis : HMAC-SHA-1,
 * 6 chiffres, pas de 30 secondes. Écrit ici plutôt que tiré d'un paquet :
 * c'est court, et les vecteurs de la RFC le vérifient (tests/Unit/TotpTest).
 */
final class Totp
{
    public const CHIFFRES = 6;

    public const PERIODE = 30;

    /** Pas acceptés de part et d'autre du pas courant (décalage d'horloge du téléphone). */
    public const TOLERANCE = 1;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** 160 bits aléatoires (taille recommandée par la RFC 4226 pour SHA-1), en base 32. */
    public static function genererSecret(): string
    {
        return self::base32Encoder(random_bytes(20));
    }

    public static function pas(?int $instant = null): int
    {
        return intdiv($instant ?? now()->getTimestamp(), self::PERIODE);
    }

    public static function code(string $secret, int $pas, int $chiffres = self::CHIFFRES): string
    {
        $hmac = hash_hmac('sha1', pack('J', $pas), self::base32Decoder($secret), true);
        $decalage = ord($hmac[19]) & 0x0F;
        $binaire = ((ord($hmac[$decalage]) & 0x7F) << 24)
            | (ord($hmac[$decalage + 1]) << 16)
            | (ord($hmac[$decalage + 2]) << 8)
            | ord($hmac[$decalage + 3]);

        return str_pad((string) ($binaire % (10 ** $chiffres)), $chiffres, '0', STR_PAD_LEFT);
    }

    /**
     * Le pas reconnu si le code est bon à ±TOLERANCE pas, null sinon.
     * Refuse tout pas inférieur ou égal au dernier accepté : un code déjà
     * servi (ou plus ancien) ne sert pas deux fois. Comparaison à temps
     * constant, sur tous les pas de la fenêtre.
     */
    public static function verifier(string $secret, string $code, ?int $dernierPasAccepte = null, ?int $instant = null): ?int
    {
        if (preg_match('/^\d{'.self::CHIFFRES.'}$/', $code) !== 1) {
            return null;
        }

        $courant = self::pas($instant);
        $reconnu = null;

        for ($ecart = -self::TOLERANCE; $ecart <= self::TOLERANCE; $ecart++) {
            $pas = $courant + $ecart;
            if (hash_equals(self::code($secret, $pas), $code) && $reconnu === null) {
                $reconnu = $pas;
            }
        }

        if ($reconnu === null || ($dernierPasAccepte !== null && $reconnu <= $dernierPasAccepte)) {
            return null;
        }

        return $reconnu;
    }

    /** Adresse lue par les applications d'authentification (format « Key Uri » de Google). */
    public static function uriProvisionnement(string $secret, string $emetteur, string $compte): string
    {
        return 'otpauth://totp/'.rawurlencode($emetteur).':'.rawurlencode($compte).'?'.http_build_query([
            'secret' => $secret,
            'issuer' => $emetteur,
            'algorithm' => 'SHA1',
            'digits' => self::CHIFFRES,
            'period' => self::PERIODE,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Base 32 de la RFC 4648, sans remplissage « = ». */
    public static function base32Encoder(string $octets): string
    {
        $bits = '';
        foreach (str_split($octets) as $octet) {
            $bits .= str_pad(decbin(ord($octet)), 8, '0', STR_PAD_LEFT);
        }

        $resultat = '';
        foreach (str_split($bits, 5) as $groupe) {
            $resultat .= self::ALPHABET[bindec(str_pad($groupe, 5, '0'))];
        }

        return $octets === '' ? '' : $resultat;
    }

    /** Tolère minuscules, espaces et remplissage, comme une saisie manuelle. */
    public static function base32Decoder(string $texte): string
    {
        $texte = rtrim(strtoupper(preg_replace('/\s+/', '', $texte)), '=');

        $bits = '';
        foreach (str_split($texte) as $caractere) {
            $valeur = strpos(self::ALPHABET, $caractere);
            if ($valeur === false) {
                throw new InvalidArgumentException('Secret base 32 invalide.');
            }
            $bits .= str_pad(decbin($valeur), 5, '0', STR_PAD_LEFT);
        }

        $octets = '';
        foreach (str_split($bits, 8) as $groupe) {
            if (strlen($groupe) === 8) {
                $octets .= chr(bindec($groupe));
            }
        }

        return $octets;
    }
}
