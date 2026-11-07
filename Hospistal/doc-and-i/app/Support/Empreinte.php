<?php

namespace App\Support;

/**
 * Empreinte SHA-256 d'un contenu signé (note, avenant, ordonnance ; RG-11) :
 * JSON canonique (clés triées, UTF-8 tel quel), puis hachage. Recalculer
 * l'empreinte et la comparer à celle gardée en base révèle toute
 * altération faite hors de l'application.
 */
final class Empreinte
{
    public static function de(array $contenu): string
    {
        return hash('sha256', json_encode(self::trier($contenu), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public static function verifier(array $contenu, string $empreinte): bool
    {
        return hash_equals($empreinte, self::de($contenu));
    }

    private static function trier(array $valeur): array
    {
        if (! array_is_list($valeur)) {
            ksort($valeur);
        }

        return array_map(fn ($v) => is_array($v) ? self::trier($v) : $v, $valeur);
    }
}
