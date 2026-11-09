<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Circuit du laboratoire (PRE-04 à PRE-08, RG-01, RG-10), sur le socle
 * 2026_11_12_000100.
 *
 * - bons_examen : réception du patient au laboratoire (recu_le) et remise
 *   du code de retrait (code_retrait_remis_le ; le code lui-même n'est gardé
 *   que par son empreinte).
 * - resultats_examen : un dépôt vient d'un compte du portail (depose_par) OU
 *   d'un jeton d'API (jeton_id) ; empreinte et taille du PDF ; clé
 *   d'idempotence du dépôt ; validation par le coordinateur à la place du
 *   médecin (RG-10), tracée.
 * - alertes_coordination : escalades vers le coordinateur (résultat critique
 *   non traité sous 2 h, résultats à valider depuis plus de 48 h).
 * - acces_dossier : les lectures de résultats par le médecin ou le
 *   coordinateur sont journalisées.
 */
return new class extends Migration
{
    private const OBJETS_AVANT = ['dossier', 'dossier_appel', 'piece_jointe_appel', 'note', 'ordonnances', 'ordonnance_pdf', 'alertes_prescription'];

    private const OBJETS_LABORATOIRE = ['resultats_examen', 'resultat_pdf'];

    public function up(): void
    {
        Schema::table('bons_examen', function (Blueprint $table) {
            $table->timestamp('code_retrait_remis_le')->nullable();
            $table->timestamp('recu_le')->nullable();
            $table->foreignUuid('recu_par')->nullable()->constrained('users');
        });

        Schema::table('resultats_examen', function (Blueprint $table) {
            $table->foreignUuid('jeton_id')->nullable()->constrained('jetons_laboratoire');
            $table->string('source', 10)->default('portail');
            $table->unsignedInteger('pdf_taille')->nullable();
            $table->char('pdf_empreinte', 64)->nullable();
            $table->string('cle_idempotence', 64)->nullable()->unique();
            $table->boolean('valide_en_remplacement')->default(false);
        });
        DB::statement('ALTER TABLE resultats_examen ALTER COLUMN depose_par DROP NOT NULL');
        DB::statement('ALTER TABLE resultats_examen ADD CONSTRAINT resultats_deposant CHECK ((depose_par IS NOT NULL AND jeton_id IS NULL) OR (depose_par IS NULL AND jeton_id IS NOT NULL))');
        DB::statement("ALTER TABLE resultats_examen ADD CONSTRAINT resultats_source CHECK (source IN ('portail', 'api'))");

        Schema::create('alertes_coordination', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 30);
            $table->foreignUuid('bon_id')->constrained('bons_examen');
            $table->foreignUuid('alerte_medecin_id')->nullable()->constrained('alertes_medecin');
            $table->foreignUuid('patient_id')->constrained('users');
            $table->foreignUuid('medecin_id')->constrained('medecins');
            // Sans donnée de santé : « Résultat critique non traité depuis 2 h ».
            $table->string('message', 255);
            $table->timestamp('traitee_le')->nullable();
            $table->foreignUuid('traitee_par')->nullable()->constrained('users');
            $table->timestamps();

            // Une escalade par bon et par motif : la tâche relancée ne la double pas.
            $table->unique(['type', 'bon_id']);
            $table->index('traitee_le');
        });
        DB::statement("ALTER TABLE alertes_coordination ADD CONSTRAINT alertes_coordination_type CHECK (type IN ('critique_non_traite', 'validation_en_retard'))");

        DB::statement('ALTER TABLE acces_dossier DROP CONSTRAINT acces_dossier_objet');
        DB::statement("ALTER TABLE acces_dossier ADD CONSTRAINT acces_dossier_objet CHECK (objet IN ('".implode("', '", [...self::OBJETS_AVANT, ...self::OBJETS_LABORATOIRE])."'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE acces_dossier DROP CONSTRAINT acces_dossier_objet');
        DB::statement("ALTER TABLE acces_dossier ADD CONSTRAINT acces_dossier_objet CHECK (objet IN ('".implode("', '", self::OBJETS_AVANT)."'))");
        Schema::dropIfExists('alertes_coordination');
        DB::statement('ALTER TABLE resultats_examen DROP CONSTRAINT resultats_source');
        DB::statement('ALTER TABLE resultats_examen DROP CONSTRAINT resultats_deposant');
        Schema::table('resultats_examen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('jeton_id');
            $table->dropColumn(['source', 'pdf_taille', 'pdf_empreinte', 'cle_idempotence', 'valide_en_remplacement']);
        });
        Schema::table('bons_examen', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recu_par');
            $table->dropColumn(['code_retrait_remis_le', 'recu_le']);
        });
    }
};
