<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dialyse : les générateurs et le calendrier des séances.
 *
 * Un dialysé revient trois fois par semaine, toute l'année, sur un poste qui
 * lui est réservé. La ressource rare n'est pas la salle mais le générateur :
 * deux patients ne peuvent y être branchés en même temps.
 *
 * La séance porte ce qui fait la qualité de la dialyse : le poids avant et
 * après, donc l'ultrafiltration réellement obtenue, la tension aux deux bouts,
 * l'abord vasculaire et les incidents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generateurs_dialyse', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('nom', 100);
            $table->string('marque', 100)->nullable();
            // Un générateur réservé aux porteurs de l'antigène HBs ne sert
            // qu'à eux : c'est une règle d'hygiène, pas une préférence.
            $table->boolean('reserve_hbs')->default(false);
            $table->boolean('est_actif')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['establishment_id', 'code']);
        });

        Schema::create('seances_dialyse', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignUuid('generateur_id')->nullable()->constrained('generateurs_dialyse')->nullOnDelete();
            // La séance réalisée devient un acte facturable ; on garde le lien
            // pour ne jamais la facturer deux fois.
            $table->foreignUuid('acte_clinique_id')->nullable()->constrained('actes_cliniques')->nullOnDelete();

            $table->timestamp('date_seance');
            $table->unsignedSmallInteger('duree_minutes')->default(240);
            $table->string('type', 30)->default('hemodialyse');
            $table->string('abord', 30)->nullable();
            $table->string('statut', 20)->default('planifiee');

            $table->decimal('poids_avant_kg', 5, 2)->nullable();
            $table->decimal('poids_apres_kg', 5, 2)->nullable();
            $table->decimal('poids_sec_kg', 5, 2)->nullable();
            $table->unsignedInteger('ultrafiltration_ml')->nullable();

            $table->unsignedSmallInteger('ta_avant_systolique')->nullable();
            $table->unsignedSmallInteger('ta_avant_diastolique')->nullable();
            $table->unsignedSmallInteger('ta_apres_systolique')->nullable();
            $table->unsignedSmallInteger('ta_apres_diastolique')->nullable();

            $table->string('anticoagulation', 50)->nullable();
            $table->boolean('erythropoietine')->default(false);
            $table->text('incidents')->nullable();
            $table->text('observations')->nullable();

            $table->foreignUuid('nephrologue_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('infirmier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['establishment_id', 'date_seance']);
            $table->index(['patient_id', 'date_seance']);
        });

        DB::statement("ALTER TABLE seances_dialyse ADD CONSTRAINT seances_dialyse_type_check
            CHECK (type IN ('hemodialyse', 'hemodialyse_epo', 'peritoneale'))");
        DB::statement("ALTER TABLE seances_dialyse ADD CONSTRAINT seances_dialyse_statut_check
            CHECK (statut IN ('planifiee', 'realisee', 'annulee', 'absente'))");
        DB::statement("ALTER TABLE seances_dialyse ADD CONSTRAINT seances_dialyse_abord_check
            CHECK (abord IS NULL OR abord IN ('fistule', 'catheter_tunnelise', 'catheter_femoral', 'catheter_jugulaire', 'peritoneal'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('seances_dialyse');
        Schema::dropIfExists('generateurs_dialyse');
    }
};
