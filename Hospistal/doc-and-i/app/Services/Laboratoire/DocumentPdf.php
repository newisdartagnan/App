<?php

namespace App\Services\Laboratoire;

use finfo;

/**
 * Compte rendu PDF déposé par un laboratoire : le type est vérifié par le
 * CONTENU, jamais par le nom ni par l'en-tête Content-Type annoncé. Un PDF
 * qui embarque du contenu actif (JavaScript, lancement de programme, fichier
 * joint, formulaire XFA) est refusé : il serait ouvert par le médecin et le
 * patient.
 *
 * Le contrôle ne se contourne ni par l'écriture échappée des noms PDF
 * (« /J#61vaScript ») ni en cachant les objets dans un flux d'objets
 * compressé (/ObjStm) : les noms sont décodés et chaque flux d'objets est
 * décompressé avant la recherche. Ce qui ne peut pas être lu (PDF chiffré,
 * flux d'objets à filtre inconnu ou illisible) est refusé.
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
        $texte = self::decoderNoms($octets);
        self::refuserMarqueurs($texte);
        self::inspecterFluxDObjets($octets, preg_match_all('#/Type\s*/ObjStm(?![A-Za-z0-9])#', $texte));
    }

    /** @throws LaboratoireRefuse */
    private static function refuserMarqueurs(string $texte): void
    {
        foreach (self::CONTENU_ACTIF as $marqueur) {
            // Un nom PDF se termine au premier délimiteur : « /JS » n'est pas « /JSON ».
            if (preg_match('#'.preg_quote($marqueur, '#').'(?![A-Za-z0-9])#', $texte) === 1) {
                throw new LaboratoireRefuse('Ce PDF contient du contenu actif (script, pièce jointe ou formulaire) : envoyez un PDF simple.');
            }
        }
        if (preg_match('#/Encrypt(?![A-Za-z0-9])#', $texte) === 1) {
            throw new LaboratoireRefuse('Ce PDF est protégé ou chiffré : envoyez un PDF simple, sans mot de passe.');
        }
    }

    /**
     * Flux d'objets (/ObjStm) : les dictionnaires qu'ils portent sont
     * compressés, donc invisibles à la recherche sur les octets bruts. Chacun
     * est décompressé (FlateDecode seul, taille bornée) puis inspecté. Tout
     * flux d'objets qui n'a pas pu être lu ainsi fait refuser le PDF.
     *
     * @throws LaboratoireRefuse
     */
    private static function inspecterFluxDObjets(string $octets, int $attendus): void
    {
        if ($attendus === 0) {
            return;
        }

        $max = (int) config('doc-and-i.laboratoire.pdf_octets_max');
        $lus = 0;
        preg_match_all('#(?<!end)stream\r?\n#', $octets, $flux, PREG_OFFSET_CAPTURE);
        foreach ($flux[0] as [$mot, $position]) {
            // En-tête de l'objet : depuis la fin de l'objet ou du flux précédent.
            $avant = substr($octets, 0, $position);
            $debut = max((int) strrpos($avant, 'endobj'), (int) strrpos($avant, 'endstream'));
            $entete = self::decoderNoms(substr($avant, $debut));
            if (preg_match('#/Type\s*/ObjStm(?![A-Za-z0-9])#', $entete) !== 1) {
                continue;
            }

            preg_match_all('#/([A-Za-z0-9]+)(?![A-Za-z0-9])#', $entete, $noms);
            $filtres = array_intersect($noms[1], ['ASCIIHexDecode', 'ASCII85Decode', 'LZWDecode', 'FlateDecode', 'RunLengthDecode', 'CCITTFaxDecode',
                'JBIG2Decode', 'DCTDecode', 'JPXDecode', 'Crypt', 'AHx', 'A85', 'LZW', 'Fl', 'RL', 'CCF', 'DCT', 'Predictor']);
            $donnees = substr($octets, $position + strlen($mot));
            $fin = strpos($donnees, 'endstream');
            if ($fin === false || array_diff($filtres, ['FlateDecode', 'Fl']) !== []) {
                throw new LaboratoireRefuse('Ce PDF a une structure inhabituelle : envoyez un PDF simple.');
            }
            $donnees = substr($donnees, 0, $fin);
            if ($filtres !== []) {
                // Taille décompressée bornée : pas de bombe de décompression.
                $donnees = @gzuncompress(rtrim($donnees, "\r\n"), $max);
                if ($donnees === false) {
                    throw new LaboratoireRefuse('Ce PDF est incomplet ou endommagé.');
                }
            }
            self::refuserMarqueurs(self::decoderNoms($donnees));
            $lus++;
        }

        // Un flux d'objets annoncé mais pas retrouvé ci-dessus : illisible pour nous, donc refusé.
        if ($lus < $attendus) {
            throw new LaboratoireRefuse('Ce PDF a une structure inhabituelle : envoyez un PDF simple.');
        }
    }

    /** « /J#61vaScript » s'écrit aussi « /JavaScript » : les échappements #xx des noms sont décodés. */
    private static function decoderNoms(string $texte): string
    {
        return preg_replace_callback('#/[^\s/<>\[\]()%{}]*#', fn (array $m) => preg_replace_callback(
            '/#([0-9A-Fa-f]{2})/', fn (array $h) => chr(hexdec($h[1])), $m[0],
        ), $texte);
    }
}
