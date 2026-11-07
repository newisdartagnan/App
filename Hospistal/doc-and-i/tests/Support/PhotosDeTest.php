<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Photos de test fabriquées octet par octet (GD n'est pas dans l'image
 * Docker) : de vraies images 2×2, avec ou sans métadonnées de position.
 */
trait PhotosDeTest
{
    private static string $jpeg = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2ODApLCBxdWFsaXR5ID0gODAK/9sAQwAGBAUGBQQGBgUGBwcGCAoQCgoJCQoUDg8MEBcUGBgXFBYWGh0lHxobIxwWFiAsICMmJykqKRkfLTAtKDAlKCko/9sAQwEHBwcKCAoTCgoTKBoWGigoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgo/8AAEQgAAgACAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A8+ooor9OPz8//9k=';

    private static string $png = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWNkSDjBwMDAxMDAwMDAAAANQgEsmQF68wAAAABJRU5ErkJggg==';

    private static string $webp = 'UklGRjwAAABXRUJQVlA4IDAAAAAQAgCdASoCAAIAAUAmJaACdLoB+AH4AAPIAP7yIl/9lXwmR/7Vf/1ODBz5+P8AAAA=';

    /** Paquet XMP tel qu'un téléphone l'écrit, avec la position. */
    private const XMP = '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:exif="http://ns.adobe.com/exif/1.0/" exif:GPSLatitude="4,19.5S" exif:GPSLongitude="15,18.7E"/></rdf:RDF></x:xmpmeta>';

    protected function jpegSimple(): string
    {
        return base64_decode(self::$jpeg);
    }

    protected function pngSimple(): string
    {
        return base64_decode(self::$png);
    }

    protected function webpSimple(): string
    {
        return base64_decode(self::$webp);
    }

    protected function photo(string $octets, string $nom = 'photo.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nom, $octets);
    }

    /**
     * Bloc EXIF (TIFF petit-boutiste) avec un IFD GPS : GPSLatitudeRef = S,
     * GPSLatitude = 4° 19′ 30″ (Kinshasa).
     */
    protected function exifAvecGps(): string
    {
        $entree = fn (int $tag, int $type, int $nombre, string $valeur) => pack('vvV', $tag, $type, $nombre).str_pad($valeur, 4, "\0");

        $ifd0 = pack('v', 1).$entree(0x8825, 4, 1, pack('V', 26)).pack('V', 0);
        $gps = pack('v', 2)
            .$entree(0x0001, 2, 2, "S\0")
            .$entree(0x0002, 5, 3, pack('V', 56))
            .pack('V', 0);
        $rationnels = pack('VVVVVV', 4, 1, 19, 1, 30, 1);

        return 'II'.pack('vV', 42, 8).$ifd0.$gps.$rationnels;
    }

    protected function jpegAvecGps(): string
    {
        $segment = fn (int $marqueur, string $donnees) => "\xFF".chr($marqueur).pack('n', strlen($donnees) + 2).$donnees;
        $jpeg = $this->jpegSimple();

        // Après SOI et APP0 (JFIF, gardé) : EXIF, XMP, un commentaire.
        $longueurApp0 = unpack('n', substr($jpeg, 4, 2))[1];
        $finApp0 = 4 + $longueurApp0;

        return substr($jpeg, 0, $finApp0)
            .$segment(0xE1, "Exif\0\0".$this->exifAvecGps())
            .$segment(0xE1, "http://ns.adobe.com/xap/1.0/\0".self::XMP)
            .$segment(0xFE, 'Prise à Lemba, Kinshasa')
            .substr($jpeg, $finApp0);
    }

    protected function pngAvecGps(): string
    {
        $bloc = fn (string $type, string $donnees) => pack('N', strlen($donnees)).$type.$donnees.pack('N', crc32($type.$donnees));
        $png = $this->pngSimple();
        $finIhdr = 8 + 12 + 13;

        return substr($png, 0, $finIhdr)
            .$bloc('eXIf', $this->exifAvecGps())
            .$bloc('tEXt', "Comment\0GPSLatitude 4.325S Lemba")
            .$bloc('iTXt', "XML:com.adobe.xmp\0\0\0\0\0".self::XMP)
            .$bloc('tIME', pack('nCCCCC', 2026, 12, 7, 9, 0, 0))
            .substr($png, $finIhdr);
    }

    protected function webpAvecGps(): string
    {
        $bloc = fn (string $fourcc, string $donnees) => $fourcc.pack('V', strlen($donnees)).$donnees.(strlen($donnees) % 2 ? "\0" : '');
        $vp8 = substr($this->webpSimple(), 12);
        // VP8X : drapeaux EXIF (0x08) et XMP (0x04), toile de 2×2 (stockée moins un, sur 24 bits).
        $vp8x = $bloc('VP8X', chr(0x0C)."\0\0\0".substr(pack('V', 1), 0, 3).substr(pack('V', 1), 0, 3));
        $blocs = $vp8x.$vp8.$bloc('EXIF', $this->exifAvecGps()).$bloc('XMP ', self::XMP);

        return 'RIFF'.pack('V', 4 + strlen($blocs)).'WEBP'.$blocs;
    }
}
