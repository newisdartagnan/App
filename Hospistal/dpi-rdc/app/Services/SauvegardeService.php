<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Sauvegardes de la base de données, déclenchées à la main depuis la page
 * Maintenance ou chaque jour par le planificateur.
 *
 * pg_dump au format « custom » (compressé, restaurable avec pg_restore),
 * dans storage/app/sauvegardes. On garde les N dernières : un disque plein
 * arrête un hôpital aussi sûrement qu'une coupure.
 */
class SauvegardeService
{
    public function dossier(): string
    {
        $dossier = storage_path('app/sauvegardes');
        File::ensureDirectoryExists($dossier);

        return $dossier;
    }

    /** @return array{ok: bool, fichier: ?string, message: string} */
    public function sauvegarder(): array
    {
        $cfg = config('database.connections.'.config('database.default'));
        if (($cfg['driver'] ?? null) !== 'pgsql') {
            return ['ok' => false, 'fichier' => null, 'message' => 'Sauvegarde prévue pour PostgreSQL uniquement.'];
        }

        $nom = 'dpi_'.config('dpi.establishment_code', 'site').'_'.now()->format('Ymd_His').'.dump';
        $chemin = $this->dossier().'/'.$nom;

        $process = new Process([
            'pg_dump', '--format=custom', '--no-owner',
            '--host='.$cfg['host'], '--port='.$cfg['port'],
            '--username='.$cfg['username'], '--file='.$chemin, $cfg['database'],
        ], null, ['PGPASSWORD' => (string) $cfg['password']], null, 600);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return ['ok' => false, 'fichier' => null, 'message' => 'pg_dump introuvable ou en échec : '.$e->getMessage()];
        }

        if (! $process->isSuccessful()) {
            @unlink($chemin);

            return ['ok' => false, 'fichier' => null, 'message' => 'Échec de la sauvegarde : '.trim($process->getErrorOutput())];
        }

        $this->purger((int) config('dpi.sauvegardes_conservees', 7));

        return ['ok' => true, 'fichier' => $nom, 'message' => 'Sauvegarde créée : '.$nom];
    }

    /** @return Collection<int, array{nom: string, taille: int, date: \Illuminate\Support\Carbon}> */
    public function liste(): Collection
    {
        return collect(File::files($this->dossier()))
            ->filter(fn ($f) => str_ends_with($f->getFilename(), '.dump'))
            ->map(fn ($f) => [
                'nom' => $f->getFilename(),
                'taille' => $f->getSize(),
                'date' => \Illuminate\Support\Carbon::createFromTimestamp($f->getMTime()),
            ])
            ->sortByDesc('date')
            ->values();
    }

    public function chemin(string $nom): ?string
    {
        $nom = basename($nom);
        $chemin = $this->dossier().'/'.$nom;

        return str_ends_with($nom, '.dump') && is_file($chemin) ? $chemin : null;
    }

    public function purger(int $garder): void
    {
        $this->liste()->slice(max(1, $garder))->each(fn ($s) => @unlink($this->dossier().'/'.$s['nom']));
    }
}
