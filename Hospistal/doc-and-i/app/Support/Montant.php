<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Montants d'argent en centimes entiers : jamais de flottant pour de
 * l'argent. Les colonnes decimal(12,2) arrivent en chaînes (« 10.00 ») ;
 * on les convertit ici, on calcule en entiers, on rend une chaîne.
 */
final class Montant
{
    public static function enCentimes(string|int $montant): int
    {
        if (is_int($montant)) {
            return $montant * 100;
        }

        $montant = trim($montant);
        if (! preg_match('/^(-?)(\d{1,10})(?:\.(\d{1,2}))?$/', $montant, $m)) {
            throw new InvalidArgumentException("Montant illisible : « {$montant} ».");
        }

        $centimes = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '0', 2, '0');

        return $m[1] === '-' ? -$centimes : $centimes;
    }

    public static function depuisCentimes(int $centimes): string
    {
        $signe = $centimes < 0 ? '-' : '';
        $centimes = abs($centimes);

        return $signe.intdiv($centimes, 100).'.'.str_pad((string) ($centimes % 100), 2, '0', STR_PAD_LEFT);
    }

    /** « 10.00 » + « USD » → « 10,00 USD » ; le franc congolais sans décimales. */
    public static function afficher(string|int $montant, string $devise): string
    {
        $centimes = self::enCentimes($montant);
        $signe = $centimes < 0 ? '-' : '';
        $centimes = abs($centimes);
        // Espace ordinaire entre les milliers : l'espace fine insécable manque
        // à la police des PDF et sort de l'alphabet GSM des SMS.
        $entier = number_format(intdiv($centimes, 100), 0, ',', ' ');

        return $devise === 'CDF'
            ? $signe.$entier.' CDF'
            : $signe.$entier.','.str_pad((string) ($centimes % 100), 2, '0', STR_PAD_LEFT).' '.$devise;
    }
}
