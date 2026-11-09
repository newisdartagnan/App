<?php

namespace App\Console\Commands;

use App\Models\Patient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Les données chiffrées se lisent-elles avec la clé actuelle ?
 *
 * Le téléphone, l'adresse et le contact d'urgence du patient sont chiffrés
 * avec APP_KEY. Une installation remontée avec un nouveau .env — un autre
 * dossier, un poste réinstallé — reçoit souvent une clé neuve : ces champs
 * deviennent alors illisibles, et chaque fiche qui les affiche tombe en
 * erreur « The payload is invalid ».
 *
 * La commande compte d'abord ce qui ne se lit plus. Le bon remède est de
 * remettre l'ancienne clé. Si elle est perdue pour de bon, --effacer vide
 * les seuls champs illisibles : le reste du dossier est intact, et ces
 * coordonnées se ressaisissent au prochain passage du patient.
 */
class ControlerChiffrement extends Command
{
    /** Les colonnes chiffrées, modèle par modèle. */
    private const CHAMPS = [
        Patient::class => ['telephone', 'adresse', 'contact_urgence_telephone'],
    ];

    protected $signature = 'dpi:controler-chiffrement
        {--effacer : Vider les champs que la clé actuelle ne sait plus lire}
        {--force : Ne pas demander de confirmation}';

    protected $description = 'Vérifie que les données chiffrées se lisent avec APP_KEY, et vide au besoin celles qui ne se lisent plus';

    public function handle(): int
    {
        if (blank(config('app.key'))) {
            $this->error('APP_KEY est vide : renseignez-la dans le .env (l\'ancienne si possible), puis relancez les conteneurs.');

            return self::FAILURE;
        }

        $illisibles = $this->illisibles();
        $total = array_sum(array_map('count', $illisibles));

        if ($total === 0) {
            $this->info('Toutes les données chiffrées se lisent avec la clé actuelle.');

            return self::SUCCESS;
        }

        $fiches = count(array_unique(array_merge(...array_map(
            fn ($champs) => array_column($champs, 'id'),
            array_values($illisibles)
        ))));

        $this->warn("{$total} champ(s) chiffré(s) illisible(s) avec la clé actuelle, sur {$fiches} fiche(s).");
        $this->line('Ils ont été chiffrés avec une autre APP_KEY. Le bon remède : remettre l\'ancienne clé dans le .env.');

        if (! $this->option('effacer')) {
            $this->line('Si l\'ancienne clé est perdue pour de bon : php artisan dpi:controler-chiffrement --effacer');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm('Vider ces champs ? Ils ne pourront plus être relus, même avec l\'ancienne clé.')) {
            $this->line('Rien n\'a été modifié.');

            return self::FAILURE;
        }

        foreach ($illisibles as $table => $champs) {
            foreach ($champs as ['id' => $id, 'colonne' => $colonne]) {
                DB::table($table)->where('id', $id)->update([$colonne => null]);
            }
        }

        $this->info("{$total} champ(s) vidé(s). Les fiches s'ouvrent de nouveau ; ces coordonnées sont à ressaisir.");

        return self::SUCCESS;
    }

    /**
     * Ce que la clé actuelle ne sait pas déchiffrer, table par table.
     *
     * @return array<string, array<int, array{id: string, colonne: string}>>
     */
    private function illisibles(): array
    {
        $resultat = [];

        foreach (self::CHAMPS as $modele => $colonnes) {
            $table = (new $modele)->getTable();
            $resultat[$table] = [];

            DB::table($table)
                ->select(array_merge(['id'], $colonnes))
                ->where(fn ($q) => collect($colonnes)->each(fn ($c) => $q->orWhereNotNull($c)))
                ->orderBy('id')
                ->chunk(500, function ($lignes) use (&$resultat, $table, $colonnes) {
                    foreach ($lignes as $ligne) {
                        foreach ($colonnes as $colonne) {
                            if ($ligne->{$colonne} === null || $ligne->{$colonne} === '') {
                                continue;
                            }

                            try {
                                Crypt::decryptString($ligne->{$colonne});
                            } catch (Throwable) {
                                $resultat[$table][] = ['id' => $ligne->id, 'colonne' => $colonne];
                            }
                        }
                    }
                });
        }

        return $resultat;
    }
}
