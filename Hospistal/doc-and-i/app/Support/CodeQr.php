<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR code dessiné sur le serveur, en SVG, livré comme image « data: » : ni
 * script, ni service tiers, et la CSP stricte (img-src 'self' data:) l'admet.
 */
final class CodeQr
{
    public static function svgEnDataUri(string $contenu, int $taille = 220): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle($taille, 2), new SvgImageBackEnd)))->writeString($contenu);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
