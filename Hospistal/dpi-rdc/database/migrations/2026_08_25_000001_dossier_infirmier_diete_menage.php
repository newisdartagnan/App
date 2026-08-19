<?php

use App\Models\TypeDiete;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════
        // PANSEMENT — suivi des soins de plaie, avec reprogrammation
        // ══════════════════════════════════════════════════════════════
        Schema::create('soins_pansement', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('realise_a');
            $table->string('localisation', 150);          // siège de la plaie
            $table->string('etat_plaie', 25);             // propre | bourgeonnante | …
            $table->text('protocole');                    // produits et technique employés
            $table->date('date_refaire')->nullable();     // prochain soin programmé
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'realise_a']);
            $table->index('date_refaire');
        });

        // ══════════════════════════════════════════════════════════════
        // GAVAGE — alimentation entérale par sonde
        // ══════════════════════════════════════════════════════════════
        Schema::create('soins_gavage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('realise_a');
            $table->string('sonde', 25);                              // naso_gastrique | …
            $table->unsignedInteger('residu_gastrique')->default(0);  // mL aspirés avant le repas
            $table->string('type_aliment', 150);
            $table->unsignedInteger('quantite_aliment');              // mL administrés
            $table->unsignedInteger('quantite_eliminee')->default(0); // mL rejetés / vomis
            $table->string('tolerance', 25)->default('bonne');        // bonne | vomissements | …
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'realise_a']);
        });

        // ══════════════════════════════════════════════════════════════
        // ÉVALUATION NEUROLOGIQUE — échelle de Glasgow, score calculé
        // ══════════════════════════════════════════════════════════════
        Schema::create('evaluations_neuro', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('evalue_a');
            $table->unsignedTinyInteger('ouverture_yeux');   // 1 à 4
            $table->unsignedTinyInteger('reponse_verbale');  // 1 à 5
            $table->unsignedTinyInteger('reponse_motrice');  // 1 à 6
            $table->unsignedTinyInteger('score')->index();   // 3 à 15, calculé
            $table->string('pupille_droite', 20)->nullable();
            $table->string('pupille_gauche', 20)->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'evalue_a']);
        });

        // ══════════════════════════════════════════════════════════════
        // TRANSFUSION — traçabilité poche par poche
        // ══════════════════════════════════════════════════════════════
        Schema::create('transfusions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('produit', 40);                 // cgr | sang_total | pfc | cp
            $table->string('groupe_donneur', 5);           // A+, O-, …
            $table->string('groupe_receveur', 5);
            $table->string('numero_poche', 50);
            $table->unsignedInteger('quantite');           // mL
            $table->date('jour');
            $table->time('heure_debut');
            $table->time('heure_fin')->nullable();
            $table->string('incident', 30)->default('aucun'); // aucun | frisson | …
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->unique(['numero_poche', 'visit_id']);
            $table->index(['visit_id', 'jour']);
        });

        // ══════════════════════════════════════════════════════════════
        // DIÈTE — référentiel des régimes servis par la cuisine
        // ══════════════════════════════════════════════════════════════
        Schema::create('types_diete', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('libelle', 100);
            $table->text('description')->nullable();
            $table->decimal('prix_journalier', 12, 2)->default(0); // prestation facturable
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['establishment_id', 'code']);
        });

        // Prescription de diète rattachée au séjour : celle dont `fin` est
        // nulle est la diète en cours. L'historique reste consultable.
        Schema::create('prescriptions_diete', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('type_diete_id')->constrained('types_diete')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('debut');
            $table->date('fin')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'fin']);
        });

        // ══════════════════════════════════════════════════════════════
        // MÉNAGE — service hôtelier quotidien de la chambre
        // ══════════════════════════════════════════════════════════════
        Schema::create('taches_menage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('jour');
            $table->string('type', 30);              // nettoyage | change_literie | …
            $table->string('statut', 15)->default('fait'); // fait | refuse | impossible
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->unique(['visit_id', 'jour', 'type']);
        });

        // Installations déjà en service : on garnit le référentiel tout de
        // suite. Sur une base neuve, le seeder s'en charge après la création
        // des établissements.
        foreach (DB::table('establishments')->pluck('id') as $etab) {
            foreach (TypeDiete::CATALOGUE as [$code, $libelle, $description, $prix]) {
                DB::table('types_diete')->insert([
                    'id' => (string) Str::uuid(),
                    'establishment_id' => $etab,
                    'code' => $code,
                    'libelle' => $libelle,
                    'description' => $description,
                    'prix_journalier' => $prix,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('taches_menage');
        Schema::dropIfExists('prescriptions_diete');
        Schema::dropIfExists('types_diete');
        Schema::dropIfExists('transfusions');
        Schema::dropIfExists('evaluations_neuro');
        Schema::dropIfExists('soins_gavage');
        Schema::dropIfExists('soins_pansement');
    }
};
