<?php

namespace App\Console\Commands;

use App\Models\Equipement;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Patient;
use App\Models\StockMedicament;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Importe les données de l'ancien système CSK (dumps MySQL) :
 * utilisateurs (profils + mots de passe bcrypt conservés), patients,
 * produits pharmacie + stocks, machines labo et équipements d'imagerie.
 *
 *   php artisan dpi:import-csk
 *   php artisan dpi:import-csk --base=/chemin/csk_base.sql
 */
class ImportCsk extends Command
{
    protected $signature = 'dpi:import-csk {--base= : Chemin du dump csk_base (MySQL)}';

    protected $description = 'Importe utilisateurs, patients, pharmacie et équipements depuis les dumps CSK';

    protected string $sql = '';

    public function handle(): int
    {
        $chemin = $this->option('base')
            ?: base_path('../CSK_docker/csk_base_20260309.sql');

        if (! is_file($chemin)) {
            $this->error("Dump introuvable : {$chemin}");

            return self::FAILURE;
        }

        $this->sql = file_get_contents($chemin);
        $etabId = auth()->user()->establishment_id
            ?? \App\Models\Establishment::first()?->id;

        if (! $etabId) {
            $this->error('Aucun établissement — lancez d\'abord les seeders.');

            return self::FAILURE;
        }

        $this->importUtilisateurs($etabId);
        $this->importPatients($etabId);
        $this->importPharmacie($etabId);
        $this->importEquipements();

        $this->info('Import CSK terminé.');

        return self::SUCCESS;
    }

    protected function importUtilisateurs(string $etabId): void
    {
        // Profils CSK → rôles Spatie du DPI
        $roles = [];
        foreach ($this->lignes('profiluser') as $p) {
            $roles[$p[0]] = match ($p[2]) {
                'admin' => 'super_admin',
                'receptionniste' => 'agent_admin',
                'facturier', 'caissier' => 'caissier',
                'medecin', 'docteur' => 'medecin',
                'infirmier', 'infirmiere' => 'infirmier',
                'laborantin', 'technicien_labo', 'biologiste' => 'laborantin',
                'pharmacien' => 'pharmacien',
                default => 'agent_admin',
            };
        }

        $n = 0;
        foreach ($this->lignes('utilisateur') as $u) {
            // idutilisateur, nom, prenom, username, password, email, telephone, idprofiluser, idsite, actif…
            $email = $u[5] ?: ($u[3] . '@import-csk.local');

            $user = User::updateOrCreate(
                ['email' => $email],
                [
                    'establishment_id' => $etabId,
                    'matricule' => 'CSK-' . $u[3],
                    'nom' => $u[1],
                    'prenom' => $u[2],
                    'telephone' => $u[6],
                    // Hash bcrypt du dump conservé : les utilisateurs gardent
                    // leur mot de passe de l'ancien système.
                    'password' => str_starts_with((string) $u[4], '$2y$') ? $u[4] : bcrypt(Str::random(24)),
                    'is_active' => (bool) $u[9],
                ]
            );
            $user->syncRoles([$roles[$u[7]] ?? 'agent_admin']);
            $n++;
        }
        $this->line("Utilisateurs importés : {$n} (mots de passe CSK conservés)");
    }

    protected function importPatients(string $etabId): void
    {
        $n = 0;
        foreach ($this->lignes('patient') as $p) {
            // idpatient, numero_dossier, nom, prenom, postnom, date_naissance, lieu_naissance,
            // sexe, etat_civil, profession, nationalite, idquartier, avenue, numero,
            // telephone1, telephone2, email, …, type_patient(20), …
            $prenom = trim($p[3] . ($p[4] ? ' ' . $p[4] : ''));
            $adresse = trim(($p[12] ?? '') . ' ' . ($p[13] ?? '')) ?: null;

            Patient::updateOrCreate(
                ['dossier_number' => $p[1]],
                [
                    'establishment_id' => $etabId,
                    'nom' => mb_strtoupper($p[2]),
                    'prenom' => $prenom,
                    'nom_soundex' => metaphone(mb_strtoupper($p[2])) ?: null,
                    'prenom_soundex' => metaphone(mb_strtoupper($prenom)) ?: null,
                    'date_naissance' => $p[5],
                    'lieu_naissance' => $p[6],
                    'sexe' => in_array($p[7], ['M', 'F']) ? $p[7] : 'Inconnu',
                    'situation_matrimoniale' => in_array($p[8], ['celibataire', 'marie', 'divorce', 'veuf']) ? $p[8] : 'inconnu',
                    'profession' => $p[9],
                    'nationalite' => $p[10] ?: 'Congolaise',
                    'adresse' => $adresse,
                    'telephone' => $p[14],
                    'type_prise_en_charge' => ($p[20] ?? null) === 'conventionne' ? 'assurance' : 'prive',
                ]
            );
            $n++;
        }
        $this->line("Patients importés : {$n}");
    }

    protected function importPharmacie(string $etabId): void
    {
        // Formes galéniques (frm_prod) pour les produits sans forme explicite
        $formes = [];
        foreach ($this->lignes('frm_prod') as $f) {
            $formes[$f[0]] = $f[1];
        }

        // Stock total par produit (somme des officines)
        $stocks = [];
        foreach ($this->lignes('stockpharma') as $s) {
            $stocks[$s[0]] = ($stocks[$s[0]] ?? 0) + (float) $s[2];
        }

        $n = 0;
        foreach ($this->lignes('prodpharma') as $p) {
            // idprodpharma, libelle, forme, code, type_produit, …, prix_achat(10),
            // prix_vente_externe(11), …, seuil_alerte(14), …, actif(17)
            if (($p[4] ?? 'medicament') !== 'medicament') {
                continue; // consommables : hors catalogue médicaments
            }

            $formeBrute = mb_strtolower((string) ($p[2] ?: ($formes[$p[7]] ?? '')));
            $forme = match (true) {
                str_contains($formeBrute, 'comprim') => 'comprime',
                str_contains($formeBrute, 'gélule') || str_contains($formeBrute, 'gelule') || str_contains($formeBrute, 'capsule') => 'gelule',
                str_contains($formeBrute, 'sirop') || str_contains($formeBrute, 'buvable') || str_contains($formeBrute, 'suspension') => 'sirop',
                str_contains($formeBrute, 'inject') || str_contains($formeBrute, 'perfusion') => 'injectable',
                str_contains($formeBrute, 'pommade') || str_contains($formeBrute, 'crème') || str_contains($formeBrute, 'creme') || str_contains($formeBrute, 'gel') => 'pommade',
                str_contains($formeBrute, 'suppositoire') || str_contains($formeBrute, 'ovule') => 'suppositoire',
                str_contains($formeBrute, 'collyre') || str_contains($formeBrute, 'ophtal') => 'collyre',
                default => 'autre',
            };

            // « PARACETAMOL 500mg » → DCI + dosage
            $libelle = trim($p[1]);
            $dosage = null;
            if (preg_match('/^(.*?)\s+([\d,.\/]+\s*(?:mg|g|ml|mcg|ui|%)[\w\/]*)$/iu', $libelle, $m)) {
                $libelle = trim($m[1]);
                $dosage = $m[2];
            }

            $med = Medicament::updateOrCreate(
                ['establishment_id' => $etabId, 'denomination_commune' => $libelle, 'dosage' => $dosage],
                [
                    'code_ucd' => $p[3],
                    'forme' => $forme,
                    'unite_dispensation' => $forme === 'injectable' ? 'flacon' : 'unité',
                    'est_actif' => (bool) ($p[17] ?? 1),
                ]
            );

            $quantite = $stocks[$p[0]] ?? 0;
            $stock = StockMedicament::updateOrCreate(
                ['medicament_id' => $med->id, 'establishment_id' => $etabId],
                [
                    'quantite_disponible' => $quantite,
                    'quantite_alerte' => (int) ($p[14] ?? 10),
                    'prix_unitaire_vente' => (float) ($p[11] ?? 0),
                    'prix_unitaire_achat' => (float) ($p[10] ?? 0),
                ]
            );

            if ($quantite > 0 && ! MouvementStock::where('medicament_id', $med->id)->where('reference', 'Import CSK')->exists()) {
                MouvementStock::create([
                    'medicament_id' => $med->id,
                    'establishment_id' => $etabId,
                    'type' => 'entree',
                    'quantite' => $quantite,
                    'quantite_avant' => 0,
                    'quantite_apres' => $quantite,
                    'reference' => 'Import CSK',
                    'created_at' => now(),
                ]);
            }
            $n++;
        }
        $this->line("Médicaments importés : {$n} (avec stocks cumulés des officines)");
    }

    protected function importEquipements(): void
    {
        $n = 0;
        foreach ($this->lignes('machineslabo') as $m) {
            // id, nom, marque, modele, description, actif, created, statut, fabricant, n° série, acquisition, maintenance, obs
            Equipement::updateOrCreate(
                ['nom' => $m[1], 'type' => 'labo'],
                [
                    'marque' => $m[2] ?: $m[8],
                    'modele' => $m[3],
                    'numero_serie' => $m[9] ?? null,
                    'statut' => $m[7] ?: 'operationnel',
                    'date_acquisition' => $m[10] ?? null,
                    'date_derniere_maintenance' => $m[11] ?? null,
                    'observations' => $m[12] ?? ($m[4] ?? null),
                ]
            );
            $n++;
        }

        foreach ($this->lignes('equipements_imagerie') as $e) {
            // id, nom, type_equipement, numero_serie, marque, modele, installation,
            // derniere_maintenance, prochaine_maintenance, localisation, statut, notes
            Equipement::updateOrCreate(
                ['nom' => $e[1], 'type' => 'imagerie'],
                [
                    'marque' => $e[4],
                    'modele' => $e[5],
                    'numero_serie' => $e[3],
                    'statut' => $e[10] ?: 'operationnel',
                    'localisation' => $e[9],
                    'date_acquisition' => $e[6],
                    'date_derniere_maintenance' => $e[7],
                    'prochaine_maintenance' => $e[8],
                    'observations' => $e[11],
                ]
            );
            $n++;
        }
        $this->line("Équipements importés : {$n} (machines labo + imagerie)");
    }

    /**
     * Extrait les tuples d'un INSERT MySQL pour une table du dump.
     *
     * @return array<int, array<int, string|null>>
     */
    protected function lignes(string $table): array
    {
        $resultats = [];

        if (! preg_match_all("/INSERT INTO `{$table}`[^V]*VALUES\s*(.*?);\n/s", $this->sql, $blocs)) {
            return $resultats;
        }

        foreach ($blocs[1] as $bloc) {
            $profondeur = 0;
            $courant = '';
            $enChaine = false;
            $echappe = false;

            foreach (str_split($bloc) as $c) {
                if ($echappe) {
                    $courant .= $c;
                    $echappe = false;

                    continue;
                }
                if ($c === '\\' && $enChaine) {
                    $echappe = true;

                    continue;
                }
                if ($c === "'") {
                    $enChaine = ! $enChaine;
                    $courant .= $c;

                    continue;
                }
                if (! $enChaine) {
                    if ($c === '(' && $profondeur === 0) {
                        $profondeur = 1;
                        $courant = '';

                        continue;
                    }
                    if ($c === '(') {
                        $profondeur++;
                    }
                    if ($c === ')' && $profondeur === 1) {
                        $profondeur = 0;
                        $resultats[] = $this->champs($courant);

                        continue;
                    }
                    if ($c === ')') {
                        $profondeur--;
                    }
                    if ($profondeur === 0) {
                        continue;
                    }
                }
                $courant .= $c;
            }
        }

        return $resultats;
    }

    /**
     * @return array<int, string|null>
     */
    protected function champs(string $tuple): array
    {
        $champs = [];
        $courant = '';
        $enChaine = false;

        foreach (str_split($tuple) as $c) {
            if ($c === "'") {
                $enChaine = ! $enChaine;
                $courant .= $c;

                continue;
            }
            if ($c === ',' && ! $enChaine) {
                $champs[] = $courant;
                $courant = '';

                continue;
            }
            $courant .= $c;
        }
        $champs[] = $courant;

        return array_map(function ($f) {
            $f = trim($f);
            if ($f === 'NULL') {
                return null;
            }
            if (str_starts_with($f, "'") && str_ends_with($f, "'")) {
                return substr($f, 1, -1);
            }

            return $f;
        }, $champs);
    }
}
