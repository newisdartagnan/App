<?php

namespace App\Services\Laboratoire;

use finfo;

/**
 * Compte rendu PDF déposé par un laboratoire : le type est vérifié par le
 * CONTENU, jamais par le nom ni par l'en-tête Content-Type annoncé. Un PDF
 * qui embarque du contenu actif (JavaScript, lancement de programme, fichier
 * joint, formulaire XFA) est refusé : il serait ouvert par le médecin et le
 * patient.
 */
final class DocumentPdf
{
    private const CONTENU_ACTIF = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/RichMedia', '/XFA', '/SubmitForm', '/ImportData'];

    /** @throws LaboratoireRefuse */
    public static function verifier(string $octets): void
    {
        $max = (int) config('doc-and-i.laboratoire.pdf_octets_max');
        if ($octets === '') {
            throw new LaboratoireRefuse('Le compte rendu PDF est vide.');
        }
        if (strlen($octets) > $max) {
            throw new LaboratoireRefuse('Compte rendu trop lourd : '.intdiv($max, 1024 * 1024).' Mo au plus.');
        }
        if (! str_starts_with($octets, '%PDF-') || (new finfo(FILEINFO_MIME_TYPE))->buffer($octets) !== 'application/pdf') {
            throw new LaboratoireRefuse('Seul un compte rendu au format PDF est accepté.');
        }
        if (! str_contains(substr($octets, -1024), '%%EOF')) {
            throw new LaboratoireRefuse('Ce PDF est incomplet ou endommagé.');
        }
        foreach (self::CONTENU_ACTIF as $marqueur) {
            // Un nom PDF se termine au premier délimiteur : « /JS » n'est pas « /JSON ».
            if (preg_match('#'.preg_quote($marqueur, '#').'(?![A-Za-z0-9])#', $octets) === 1) {
                throw new LaboratoireRefuse('Ce PDF contient du contenu actif (script, pièce jointe ou formulaire) : envoyez un PDF simple.');
            }
        }
    }
}
