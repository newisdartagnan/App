<?php

namespace App\Support;

use RuntimeException;

/**
 * Sceau des contenus signés (note, avenant, ordonnance ; RG-11, PRE-03) :
 * HMAC-SHA256 du JSON canonique (clés triées, UTF-8 tel quel), avec une clé
 * secrète hors base (DOSSIER_SECRET_SIGNATURE).
 *
 * Revue de sécurité : un simple SHA-256 se recalcule par quiconque modifie
 * la base ; le HMAC exige en plus la clé, qui n'est ni en base ni dans le
 * dépôt. Une altération faite en base sans la clé est donc révélée. En
 * production, l'application tourne de plus avec un rôle PostgreSQL qui ne
 * possède pas les tables (voir README) : il ne peut pas désactiver les
 * déclencheurs d'immuabilité.
 */
final class Empreinte
{
    /** Version de la clé : à augmenter à chaque rotation (colonne signature_version). */
    public const VERSION = 1;

    public static function de(array $contenu): string
    {
        return hash_hmac('sha256', json_encode(self::trier($contenu), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), self::cle());
    }

    public static function verifier(array $contenu, string $empreinte): bool
    {
        return hash_equals($empreinte, self::de($contenu));
    }

    private static function cle(): string
    {
        $cle = (string) config('doc-and-i.dossier.secret_signature');
        if (strlen($cle) < 32) {
            throw new RuntimeException('DOSSIER_SECRET_SIGNATURE absent ou trop court (32 caractères au moins) : rien ne peut être signé.');
        }

        return $cle;
    }

    private static function trier(array $valeur): array
    {
        if (! array_is_list($valeur)) {
            ksort($valeur);
        }

        return array_map(fn ($v) => is_array($v) ? self::trier($v) : $v, $valeur);
    }
}
