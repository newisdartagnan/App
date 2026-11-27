<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Photos (agents, patients) : réduites et réencodées en JPEG avant d'être
 * gardées. Un téléphone produit 4 Mo par cliché ; le serveur d'un centre de
 * santé n'a pas à les stocker tels quels, et un fichier réencodé ne porte
 * plus rien d'autre que l'image.
 */
class Image
{
    public static function enregistrerReduite(UploadedFile $fichier, string $dossier, int $cote = 400): string
    {
        $source = @imagecreatefromstring((string) file_get_contents($fichier->getRealPath()));
        if (! $source) {
            throw new \InvalidArgumentException('Image illisible.');
        }

        $l = imagesx($source);
        $h = imagesy($source);
        $ratio = min(1, $cote / max($l, $h));
        $nl = max(1, (int) round($l * $ratio));
        $nh = max(1, (int) round($h * $ratio));

        $cible = imagecreatetruecolor($nl, $nh);
        imagefill($cible, 0, 0, imagecolorallocate($cible, 255, 255, 255));
        imagecopyresampled($cible, $source, 0, 0, 0, 0, $nl, $nh, $l, $h);

        ob_start();
        imagejpeg($cible, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($source);
        imagedestroy($cible);

        $chemin = trim($dossier, '/').'/'.bin2hex(random_bytes(12)).'.jpg';
        Storage::disk('local')->put($chemin, $jpeg);

        return $chemin;
    }
}
