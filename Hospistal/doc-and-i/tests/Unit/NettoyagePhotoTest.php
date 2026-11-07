<?php

namespace Tests\Unit;

use App\Support\NettoyagePhoto;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\PhotosDeTest;

/** Revue de sécurité (TLC-03) : les métadonnées de position quittent la photo avant stockage, en PHP pur. */
class NettoyagePhotoTest extends TestCase
{
    use PhotosDeTest;

    /** @return array<string, array{string, string}> */
    public static function formats(): array
    {
        return ['JPEG' => ['jpeg', 'image/jpeg'], 'PNG' => ['png', 'image/png'], 'WebP' => ['webp', 'image/webp']];
    }

    #[DataProvider('formats')]
    public function test_la_position_gps_et_les_textes_sont_retires_et_l_image_reste_lisible(string $format, string $type): void
    {
        $avant = $this->{$format.'AvecGps'}();
        $this->assertSame($type, getimagesizefromstring($avant)['mime'], 'La photo de départ est une vraie image.');
        $this->assertStringContainsString('GPSLatitude', $avant);
        if (function_exists('exif_read_data') && $format === 'jpeg') {
            $this->assertArrayHasKey('GPSLatitude', exif_read_data('data://image/jpeg;base64,'.base64_encode($avant)));
        }

        $apres = NettoyagePhoto::nettoyer($avant, $type);

        $image = getimagesizefromstring($apres);
        $this->assertNotFalse($image);
        $this->assertSame([$type, 2, 2], [$image['mime'], $image[0], $image[1]]);
        $this->assertStringNotContainsString('GPSLatitude', $apres);
        $this->assertStringNotContainsString($this->exifAvecGps(), $apres, 'Le bloc EXIF binaire est parti.');
        $this->assertStringNotContainsString('Lemba', $apres);
        $this->assertLessThan(strlen($avant), strlen($apres));
    }

    public function test_le_jpeg_garde_son_en_tete_jfif(): void
    {
        $apres = NettoyagePhoto::nettoyer($this->jpegAvecGps(), 'image/jpeg');

        $this->assertStringStartsWith("\xFF\xD8\xFF\xE0", $apres);
        // L'image de base porte déjà un commentaire (« CREATOR: gd-jpeg ») : retiré lui aussi.
        $this->assertStringNotContainsString('CREATOR', $apres);
        $this->assertSame(NettoyagePhoto::nettoyer($this->jpegSimple(), 'image/jpeg'), $apres, 'Seuls les blocs de métadonnées sont retirés : l\'image est intacte.');
        $this->assertSame($apres, NettoyagePhoto::nettoyer($apres, 'image/jpeg'), 'Nettoyer deux fois ne change plus rien.');
    }

    public function test_le_png_ne_garde_que_ses_blocs_d_image(): void
    {
        $this->assertSame($this->pngSimple(), NettoyagePhoto::nettoyer($this->pngAvecGps(), 'image/png'));
    }

    public function test_le_webp_perd_ses_blocs_et_ses_drapeaux_exif_et_xmp(): void
    {
        $apres = NettoyagePhoto::nettoyer($this->webpAvecGps(), 'image/webp');

        $this->assertSame('VP8X', substr($apres, 12, 4));
        $this->assertSame(0, ord($apres[20]) & 0x0C, 'Drapeaux EXIF et XMP remis à zéro.');
        $this->assertStringNotContainsString('EXIF', $apres);
        $this->assertStringNotContainsString('XMP ', $apres);
        $this->assertSame(strlen($apres) - 8, unpack('V', substr($apres, 4, 4))[1], 'Taille RIFF recalculée.');
    }

    public function test_une_photo_sans_metadonnees_ne_change_pas(): void
    {
        $this->assertSame($this->pngSimple(), NettoyagePhoto::nettoyer($this->pngSimple(), 'image/png'));
        $this->assertSame($this->webpSimple(), NettoyagePhoto::nettoyer($this->webpSimple(), 'image/webp'));
    }

    public function test_une_structure_mal_formee_est_refusee(): void
    {
        foreach ([["\xFF\xD8\xFF\xE1\xFF\xFF", 'image/jpeg'], ["\x89PNG\r\n\x1a\n\0\0\0\xFFIHDR", 'image/png'], ['RIFF', 'image/webp'], ['GIF89a', 'image/gif']] as [$octets, $type]) {
            try {
                NettoyagePhoto::nettoyer($octets, $type);
                $this->fail("Accepté : {$type}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
