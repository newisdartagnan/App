<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Reliure de documents PDF.
 *
 * Un compte rendu d'imagerie arrive rarement seul : il traîne derrière lui le
 * protocole du constructeur, le rapport du confrère, la pièce scannée. Les
 * annoncer en fin de document — « consultable dans le dossier du patient » —
 * revient à demander au prescripteur d'aller les chercher dans un service où
 * il n'entre pas. Ils doivent être dans le document.
 *
 * Un PDF ne s'incorpore pas dans un autre par le moteur de rendu : il faut
 * relier les fichiers. C'est le travail de qpdf, qui lit toutes les versions
 * du format là où les bibliothèques PHP libres butent sur les tables de
 * références compressées des PDF récents.
 */
class AssemblagePdfService
{
    /** Au-delà, le document devient impossible à ouvrir sur un poste modeste. */
    private const PAGES_MAX = 400;

    private const DELAI_SECONDES = 30;

    /** L'outil de reliure est-il présent sur ce serveur ? */
    public function disponible(): bool
    {
        static $disponible = null;

        if ($disponible === null) {
            $disponible = $this->executer(['qpdf', '--version']) !== null;
        }

        return $disponible;
    }

    /**
     * Ce fichier est-il un PDF que l'on saura relier ?
     *
     * Un PDF corrompu ou chiffré ferait échouer toute la reliure : on le
     * repère avant, pour ne perdre que lui et non le compte rendu.
     */
    public function estReliable(string $chemin): bool
    {
        if (! is_file($chemin) || ! $this->disponible()) {
            return false;
        }

        return $this->nombreDePages($chemin) !== null;
    }

    /** Nombre de pages d'un PDF, null s'il est illisible. */
    public function nombreDePages(string $chemin): ?int
    {
        $sortie = $this->executer(['qpdf', '--show-npages', $chemin]);

        if ($sortie === null) {
            return null;
        }

        $pages = (int) trim($sortie);

        return $pages > 0 ? $pages : null;
    }

    /**
     * Relie des documents à la suite du premier.
     *
     * @param  string  $principal  chemin du document d'ouverture
     * @param  array<int, string>  $annexes  chemins des documents à joindre
     * @return string|null chemin du document relié, null si la reliure a échoué
     */
    public function relier(string $principal, array $annexes): ?string
    {
        $annexes = array_values(array_filter($annexes, fn ($c) => $this->estReliable($c)));

        if ($annexes === [] || ! $this->disponible()) {
            return null;
        }

        // Un document que le poste du médecin ne pourra pas ouvrir n'aide
        // personne : on s'arrête avant de le rendre inutilisable.
        $pages = $this->nombreDePages($principal) ?? 0;
        $retenues = [];

        foreach ($annexes as $annexe) {
            $pages += $this->nombreDePages($annexe) ?? 0;

            if ($pages > self::PAGES_MAX) {
                break;
            }

            $retenues[] = $annexe;
        }

        if ($retenues === []) {
            return null;
        }

        $sortie = tempnam(sys_get_temp_dir(), 'dpi-relie-').'.pdf';

        $commande = array_merge(
            ['qpdf', '--empty', '--pages', $principal],
            $retenues,
            ['--', $sortie]
        );

        if ($this->executer($commande) === null || ! is_file($sortie) || filesize($sortie) === 0) {
            @unlink($sortie);

            return null;
        }

        return $sortie;
    }

    /**
     * Lance une commande et rend sa sortie, null si elle a échoué.
     *
     * L'échec n'est jamais fatal : le compte rendu part sans ses annexes
     * plutôt que de ne pas partir du tout.
     */
    private function executer(array $commande): ?string
    {
        try {
            $process = new Process($commande);
            $process->setTimeout(self::DELAI_SECONDES);
            $process->run();

            // qpdf rend 3 sur avertissement — le fichier est réparé et
            // exploitable, ce n'est pas un motif d'abandon.
            if (! $process->isSuccessful() && $process->getExitCode() !== 3) {
                return null;
            }

            return $process->getOutput();
        } catch (ProcessFailedException|\RuntimeException $e) {
            Log::debug('Reliure PDF indisponible : '.$e->getMessage());

            return null;
        }
    }
}
