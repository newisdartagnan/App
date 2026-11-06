<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot espace administrateur (ADM-01, ADM-02, CPT-05).
 *
 * - Un compte du personnel se désactive, il ne se supprime pas : son
 *   historique (rendez-vous, validations, pièces comptables) reste lisible.
 * - Laboratoires et pharmacies partenaires (ADM-01), référentiel des lots
 *   examens et ordonnances.
 * - Journal d'administration : qui a fait quoi, sur quoi, quand. Ni
 *   modification ni suppression (déclencheur), aucune donnée de santé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('desactive_le')->nullable();
            $table->string('motif_desactivation', 255)->nullable();
        });

        Schema::create('partenaires', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 20);
            $table->string('nom', 150);
            $table->string('commune', 100)->nullable();
            $table->string('adresse', 255)->nullable();
            $table->string('telephone', 16)->nullable();
            $table->string('email')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['type', 'nom']);
        });
        DB::statement("ALTER TABLE partenaires ADD CONSTRAINT partenaires_type CHECK (type IN ('laboratoire', 'pharmacie'))");

        Schema::create('journal_administration', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('auteur_id')->constrained('users');
            $table->string('action', 60);
            $table->string('cible_type', 60)->nullable();
            $table->uuid('cible_id')->nullable();
            $table->jsonb('details')->default('{}');
            $table->string('ip', 45)->nullable();
            $table->timestamp('cree_le');

            $table->index(['cree_le']);
            $table->index(['cible_type', 'cible_id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuser_modification_journal() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% : journal non modifiable', TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER journal_administration_non_modifiable BEFORE UPDATE OR DELETE ON journal_administration
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_journal();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_administration');
        DB::unprepared('DROP FUNCTION IF EXISTS refuser_modification_journal() CASCADE');
        Schema::dropIfExists('partenaires');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['desactive_le', 'motif_desactivation']);
        });
    }
};
