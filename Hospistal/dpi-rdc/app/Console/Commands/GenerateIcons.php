<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class GenerateIcons extends Command
{
    protected $signature = 'icons:generate';

    protected $description = 'Génère les icônes PWA minimales (192 et 512)';

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->warn('Extension GD absente — icônes non générées.');

            return self::SUCCESS;
        }

        foreach ([192, 512] as $size) {
            $path = public_path("icons/icon-{$size}.png");
            if (file_exists($path)) {
                continue;
            }

            $img = imagecreatetruecolor($size, $size);
            $blue = imagecolorallocate($img, 30, 64, 175);
            $white = imagecolorallocate($img, 255, 255, 255);
            imagefill($img, 0, 0, $blue);
            imagestring($img, 5, (int) ($size / 2 - 20), (int) ($size / 2 - 8), 'DPI', $white);

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            imagepng($img, $path);
            imagedestroy($img);
            $this->info("Créé : {$path}");
        }

        return self::SUCCESS;
    }
}
