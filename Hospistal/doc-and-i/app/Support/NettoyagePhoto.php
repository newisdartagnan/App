<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Retire les métadonnées d'une photo avant stockage (revue de sécurité,
 * TLC-03) : un téléphone écrit souvent la position GPS du patient, l'appareil
 * et la date dans EXIF, XMP ou des textes. PHP pur (GD absent de l'image
 * Docker), sans décoder l'image : on retire seulement des blocs.
 *
 * - JPEG : segments APP1 à APP15 (EXIF, XMP, ICC, Photoshop…) et COM ; APP0 (JFIF) gardé.
 * - PNG : eXIf, tEXt, zTXt, iTXt, tIME.
 * - WebP : EXIF et « XMP » ; drapeaux correspondants de VP8X remis à zéro.
 *
 * Toute structure inattendue est refusée (InvalidArgumentException) plutôt
 * que stockée telle quelle.
 */
final class NettoyagePhoto
{
    private const PNG_RETIRES = ['eXIf', 'tEXt', 'zTXt', 'iTXt', 'tIME'];

    private const WEBP_RETIRES = ['EXIF', 'XMP '];

    public static function nettoyer(string $octets, string $type): string
    {
        return match ($type) {
            'image/jpeg' => self::jpeg($octets),
            'image/png' => self::png($octets),
            'image/webp' => self::webp($octets),
            default => throw new InvalidArgumentException("Type de photo non pris en charge : {$type}."),
        };
    }

    private static function jpeg(string $o): string
    {
        if (substr($o, 0, 2) !== "\xFF\xD8") {
            throw new InvalidArgumentException('JPEG sans marqueur de début.');
        }

        $sortie = "\xFF\xD8";
        $i = 2;
        $n = strlen($o);

        while ($i < $n) {
            if ($o[$i] !== "\xFF") {
                throw new InvalidArgumentException('JPEG mal formé.');
            }
            // Octets de remplissage 0xFF entre deux segments.
            while ($i + 1 < $n && $o[$i + 1] === "\xFF") {
                $i++;
            }
            if ($i + 1 >= $n) {
                throw new InvalidArgumentException('JPEG tronqué.');
            }

            $marqueur = ord($o[$i + 1]);

            // Début des données compressées : le reste est l'image elle-même.
            if ($marqueur === 0xDA) {
                return $sortie.substr($o, $i);
            }
            if ($marqueur === 0xD9) {
                return $sortie."\xFF\xD9";
            }
            // Marqueurs sans longueur (RST, TEM).
            if (($marqueur >= 0xD0 && $marqueur <= 0xD7) || $marqueur === 0x01) {
                $sortie .= substr($o, $i, 2);
                $i += 2;

                continue;
            }

            if ($i + 4 > $n) {
                throw new InvalidArgumentException('JPEG tronqué.');
            }
            $longueur = unpack('n', substr($o, $i + 2, 2))[1];
            if ($longueur < 2 || $i + 2 + $longueur > $n) {
                throw new InvalidArgumentException('Segment JPEG de longueur invalide.');
            }

            $retire = ($marqueur >= 0xE1 && $marqueur <= 0xEF) || $marqueur === 0xFE;
            if (! $retire) {
                $sortie .= substr($o, $i, 2 + $longueur);
            }
            $i += 2 + $longueur;
        }

        throw new InvalidArgumentException('JPEG sans données d\'image.');
    }

    private static function png(string $o): string
    {
        if (substr($o, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            throw new InvalidArgumentException('PNG sans signature.');
        }

        $sortie = substr($o, 0, 8);
        $i = 8;
        $n = strlen($o);

        while ($i < $n) {
            if ($i + 12 > $n) {
                throw new InvalidArgumentException('PNG tronqué.');
            }
            $longueur = unpack('N', substr($o, $i, 4))[1];
            $typeBloc = substr($o, $i + 4, 4);
            $total = 12 + $longueur;
            if ($i + $total > $n) {
                throw new InvalidArgumentException('Bloc PNG de longueur invalide.');
            }

            if (! in_array($typeBloc, self::PNG_RETIRES, true)) {
                $sortie .= substr($o, $i, $total);
            }
            $i += $total;

            if ($typeBloc === 'IEND') {
                return $sortie;
            }
        }

        throw new InvalidArgumentException('PNG sans fin.');
    }

    private static function webp(string $o): string
    {
        if (substr($o, 0, 4) !== 'RIFF' || substr($o, 8, 4) !== 'WEBP') {
            throw new InvalidArgumentException('WebP sans en-tête RIFF.');
        }

        $blocs = '';
        $i = 12;
        $n = min(strlen($o), 8 + unpack('V', substr($o, 4, 4))[1]);

        while ($i < $n) {
            if ($i + 8 > $n) {
                throw new InvalidArgumentException('WebP tronqué.');
            }
            $fourcc = substr($o, $i, 4);
            $taille = unpack('V', substr($o, $i + 4, 4))[1];
            $total = 8 + $taille + ($taille % 2);
            if ($i + 8 + $taille > $n) {
                throw new InvalidArgumentException('Bloc WebP de taille invalide.');
            }

            $bloc = substr($o, $i, min($total, $n - $i));
            if ($fourcc === 'VP8X') {
                // Drapeaux : 0x08 EXIF, 0x04 XMP. Les blocs partent, les drapeaux aussi.
                $bloc[8] = chr(ord($bloc[8]) & ~0x0C);
            }
            if (! in_array($fourcc, self::WEBP_RETIRES, true)) {
                $blocs .= $bloc;
            }
            $i += $total;
        }

        return 'RIFF'.pack('V', 4 + strlen($blocs)).'WEBP'.$blocs;
    }
}
