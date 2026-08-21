<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Le bloc opératoire, de la demande au registre.
 *
 * Une intervention traverse trois états : le chirurgien la demande (programme
 * préopératoire), le bloc la planifie dans une salle à une heure donnée, puis
 * l'équipe la clôture avec son compte rendu. Le registre en garde la trace.
 *
 * Ce qui manquait à l'acte clinique : la salle, l'anesthésiste et le type
 * d'anesthésie, les diagnostics avant et après, le kit consommé et les
 * heures réelles d'entrée et de sortie de salle.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Les salles d'opération : le bloc en compte quelques-unes, chacune
        // avec son horaire. Deux interventions ne peuvent s'y chevaucher.
        Schema::create('salles_operation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('nom', 100);
            $table->string('specialite', 100)->nullable();
            $table->text('equipement')->nullable();
            $table->boolean('est_actif')->default(true);
            $table->timestamps();

            $table->unique(['establishment_id', 'code']);
        });

        // Catalogue des kits : une boîte d'instruments prête à l'emploi, avec
        // ce qu'elle contient. Le bloc coche celui qu'il a ouvert.
        Schema::create('kits_operatoires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle', 150);
            $table->jsonb('contenu')->default('[]');
            $table->decimal('prix', 12, 2)->default(0);
            $table->boolean('est_actif')->default(true);
            $table->timestamps();

            $table->unique(['establishment_id', 'code']);
        });

        Schema::table('actes_cliniques', function (Blueprint $table) {
            $table->foreignUuid('salle_id')->nullable()->constrained('salles_operation')->nullOnDelete();
            $table->foreignUuid('anesthesiste_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('demandeur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type_anesthesie', 30)->nullable();
            $table->text('diagnostic_preop')->nullable();
            $table->text('diagnostic_postop')->nullable();
            $table->string('instrumentiste', 150)->nullable();
            $table->jsonb('kits')->default('[]');
            $table->text('incidents')->nullable();

            // Heures réelles d'occupation de la salle, renseignées à la
            // clôture : elles ne se confondent pas avec l'heure prévue.
            $table->timestamp('heure_entree_salle')->nullable();
            $table->timestamp('heure_sortie_salle')->nullable();

            $table->index(['domaine', 'statut', 'date_prevue']);
        });

        DB::statement("ALTER TABLE actes_cliniques ADD CONSTRAINT actes_cliniques_type_anesthesie_check
            CHECK (type_anesthesie IS NULL OR type_anesthesie IN
                ('generale', 'rachianesthesie', 'peridurale', 'locoregionale',
                 'locale', 'sedation', 'aucune'))");

        // Salles et kits de départ, pour chaque établissement déjà installé.
        foreach (DB::table('establishments')->pluck('id') as $etablissement) {
            $this->installerPour($etablissement);
        }
    }

    /** Dotation de départ d'un établissement : trois salles et six kits. */
    private function installerPour(string $etablissement): void
    {
        $salles = [
            ['SOP-1', 'Salle 1', 'Chirurgie générale'],
            ['SOP-2', 'Salle 2', 'Chirurgie obstétricale'],
            ['SOP-3', 'Salle 3', 'Urgences / septique'],
        ];

        foreach ($salles as [$code, $nom, $specialite]) {
            DB::table('salles_operation')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'establishment_id' => $etablissement,
                'code' => $code,
                'nom' => $nom,
                'specialite' => $specialite,
                'est_actif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $kits = [
            ['KIT-CESAR', 'Kit césarienne', ['Boîte de césarienne', 'Champs stériles', 'Fils résorbables', 'Compresses', 'Aspiration'], 45000],
            ['KIT-LAPARO', 'Kit laparotomie', ['Boîte de laparotomie', 'Écarteurs', 'Fils', 'Drains', 'Compresses'], 60000],
            ['KIT-PETITE-CHIR', 'Kit petite chirurgie', ['Boîte de petite chirurgie', 'Fils non résorbables', 'Compresses'], 15000],
            ['KIT-HERNIE', 'Kit cure de hernie', ['Boîte de hernie', 'Plaque prothétique', 'Fils'], 55000],
            ['KIT-ORTHO', 'Kit orthopédie', ['Boîte d\'ostéosynthèse', 'Moteur', 'Fils d\'acier'], 90000],
            ['KIT-ANESTH', 'Kit anesthésie', ['Plateau d\'intubation', 'Sondes', 'Seringues', 'Filtres'], 20000],
        ];

        foreach ($kits as [$code, $libelle, $contenu, $prix]) {
            DB::table('kits_operatoires')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'establishment_id' => $etablissement,
                'code' => $code,
                'libelle' => $libelle,
                'contenu' => json_encode($contenu, JSON_UNESCAPED_UNICODE),
                'prix' => $prix,
                'est_actif' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE actes_cliniques DROP CONSTRAINT IF EXISTS actes_cliniques_type_anesthesie_check');

        Schema::table('actes_cliniques', function (Blueprint $table) {
            $table->dropConstrainedForeignId('salle_id');
            $table->dropConstrainedForeignId('anesthesiste_id');
            $table->dropConstrainedForeignId('demandeur_id');
            $table->dropColumn([
                'type_anesthesie', 'diagnostic_preop', 'diagnostic_postop',
                'instrumentiste', 'kits', 'incidents',
                'heure_entree_salle', 'heure_sortie_salle',
            ]);
        });

        Schema::dropIfExists('kits_operatoires');
        Schema::dropIfExists('salles_operation');
    }
};
