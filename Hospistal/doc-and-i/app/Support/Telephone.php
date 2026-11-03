<?php

namespace App\Support;

/**
 * Numéro de téléphone mobile congolais, au format international +243.
 *
 * Le patient tape son numéro comme il le connaît : 0812345678,
 * 081 234 56 78, +243 812 345 678 ou 243812345678. Tous désignent le même
 * compte ; on les ramène donc à une seule forme avant de chercher ou
 * d'enregistrer quoi que ce soit, sinon un même patient aurait deux dossiers.
 */
final class Telephone
{
    /**
     * Forme normalisée (+243 suivi de 9 chiffres, commençant par 8 ou 9),
     * ou null si ce n'est pas un numéro mobile congolais.
     */
    public static function normaliser(?string $saisie): ?string
    {
        if ($saisie === null) {
            return null;
        }

        $chiffres = preg_replace('/[\s.\-()]/', '', trim($saisie));

        if (str_starts_with($chiffres, '+')) {
            $chiffres = substr($chiffres, 1);
        } elseif (str_starts_with($chiffres, '00')) {
            $chiffres = substr($chiffres, 2);
        }

        if (! ctype_digit($chiffres)) {
            return null;
        }

        if (strlen($chiffres) === 10 && str_starts_with($chiffres, '0')) {
            $chiffres = '243'.substr($chiffres, 1);
        } elseif (strlen($chiffres) === 9) {
            $chiffres = '243'.$chiffres;
        }

        return preg_match('/^243[89]\d{8}$/', $chiffres) ? '+'.$chiffres : null;
    }

    /** +243812345678 → +243 81 ••• •• 78 : assez pour se reconnaître, pas pour être lu par-dessus l'épaule. */
    public static function masquer(string $telephone): string
    {
        return substr($telephone, 0, 4).' '.substr($telephone, 4, 2).' ••• •• '.substr($telephone, -2);
    }
}
