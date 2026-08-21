<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche obstétricale : la grossesse, son suivi, l'accouchement, l'enfant.
 *
 * Une grossesse est une histoire qui court sur neuf mois et dépasse le séjour :
 * elle a sa propre fiche, ouverte à la première consultation prénatale et close
 * à l'accouchement. Les consultations s'y accrochent, l'accouchement la termine,
 * et chaque nouveau-né y est inscrit — vivant ou mort-né, la maternité doit
 * pouvoir le déclarer.
 *
 * La césarienne, elle, reste une intervention du bloc : l'accouchement s'y
 * rattache par l'acte clinique, sans le dupliquer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grossesses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();

            // La date des dernières règles commande tout : terme, date prévue
            // d'accouchement, interprétation de la hauteur utérine.
            $table->date('date_dernieres_regles')->nullable();
            $table->date('date_prevue_accouchement')->nullable();

            // Gestité : nombre de grossesses. Parité : nombre d'accouchements.
            $table->unsignedSmallInteger('gestite')->default(1);
            $table->unsignedSmallInteger('parite')->default(0);
            $table->unsignedSmallInteger('avortements')->default(0);
            $table->unsignedSmallInteger('enfants_vivants')->default(0);

            $table->string('groupe_sanguin', 5)->nullable();
            $table->text('antecedents')->nullable();
            $table->jsonb('serologies')->default('{}');
            $table->boolean('grossesse_a_risque')->default(false);
            $table->text('motif_risque')->nullable();

            $table->string('statut', 20)->default('en_cours');
            $table->date('date_cloture')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['establishment_id', 'statut']);
        });

        DB::statement("ALTER TABLE grossesses ADD CONSTRAINT grossesses_statut_check
            CHECK (statut IN ('en_cours', 'accouchee', 'interrompue'))");

        // Consultation prénatale : le carnet de suivi, une ligne par visite.
        Schema::create('consultations_prenatales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('grossesse_id')->constrained('grossesses')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('date_consultation');
            $table->unsignedSmallInteger('numero')->default(1);
            $table->unsignedSmallInteger('terme_semaines')->nullable();

            $table->decimal('poids_kg', 5, 2)->nullable();
            $table->unsignedSmallInteger('tension_systolique')->nullable();
            $table->unsignedSmallInteger('tension_diastolique')->nullable();
            $table->decimal('hauteur_uterine_cm', 4, 1)->nullable();
            $table->unsignedSmallInteger('bruits_coeur_foetal')->nullable();
            $table->string('presentation', 30)->nullable();

            // Dépistages de routine de la CPN : ce qui déclenche l'alerte.
            $table->string('oedemes', 20)->nullable();
            $table->string('albuminurie', 20)->nullable();
            $table->string('glycosurie', 20)->nullable();
            $table->decimal('hemoglobine', 4, 1)->nullable();

            $table->unsignedSmallInteger('vat_dose')->nullable();
            $table->boolean('fer_folates')->default(false);
            $table->boolean('sulfadoxine_pyrimethamine')->default(false);
            $table->boolean('moustiquaire_remise')->default(false);

            $table->text('observations')->nullable();
            $table->text('conduite_a_tenir')->nullable();
            $table->date('prochain_rendez_vous')->nullable();
            $table->timestamps();

            $table->index(['grossesse_id', 'date_consultation']);
        });

        Schema::create('accouchements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('grossesse_id')->constrained('grossesses')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            // Une césarienne est une intervention du bloc : l'accouchement s'y
            // rattache au lieu de la redécrire.
            $table->foreignUuid('acte_clinique_id')->nullable()->constrained('actes_cliniques')->nullOnDelete();

            $table->timestamp('debut_travail')->nullable();
            $table->timestamp('date_accouchement');
            $table->unsignedSmallInteger('terme_semaines')->nullable();

            $table->string('mode', 30)->default('voie_basse');
            $table->string('presentation', 30)->nullable();
            $table->string('delivrance', 30)->nullable();
            $table->boolean('episiotomie')->default(false);
            $table->string('dechirure', 20)->nullable();
            $table->unsignedInteger('saignement_ml')->nullable();
            $table->boolean('transfusion')->default(false);

            $table->foreignUuid('accoucheur_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sage_femme', 150)->nullable();

            $table->string('etat_mere', 20)->default('bon');
            $table->text('complications')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'date_accouchement']);
        });

        DB::statement("ALTER TABLE accouchements ADD CONSTRAINT accouchements_mode_check
            CHECK (mode IN ('voie_basse', 'ventouse', 'forceps', 'cesarienne', 'siege'))");
        DB::statement("ALTER TABLE accouchements ADD CONSTRAINT accouchements_etat_mere_check
            CHECK (etat_mere IN ('bon', 'complique', 'grave', 'deces'))");

        Schema::create('nouveau_nes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('accouchement_id')->constrained('accouchements')->cascadeOnDelete();
            // L'enfant vivant reçoit son propre dossier de patient : il aura
            // ses vaccins, ses consultations, son histoire.
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();

            $table->unsignedSmallInteger('rang')->default(1);
            $table->char('sexe', 1)->nullable();
            $table->unsignedInteger('poids_g')->nullable();
            $table->decimal('taille_cm', 4, 1)->nullable();
            $table->decimal('perimetre_cranien_cm', 4, 1)->nullable();

            $table->unsignedTinyInteger('apgar_1')->nullable();
            $table->unsignedTinyInteger('apgar_5')->nullable();
            $table->unsignedTinyInteger('apgar_10')->nullable();

            $table->string('statut', 20)->default('vivant');
            $table->boolean('reanimation')->default(false);
            $table->boolean('mise_au_sein_precoce')->default(false);
            $table->text('malformations')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE nouveau_nes ADD CONSTRAINT nouveau_nes_statut_check
            CHECK (statut IN ('vivant', 'mort_ne', 'decede'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('nouveau_nes');
        Schema::dropIfExists('accouchements');
        Schema::dropIfExists('consultations_prenatales');
        Schema::dropIfExists('grossesses');
    }
};
