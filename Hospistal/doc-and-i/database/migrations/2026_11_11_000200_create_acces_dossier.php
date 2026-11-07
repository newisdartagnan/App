<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot dossier médical, partie patient et lecture (DOS-01, DOS-03, DOS-04).
 *
 * - acces_dossier : journal des accès au dossier (revue de sécurité,
 *   cahier des charges section 6) : qui, quel patient, quoi, quand, depuis
 *   quelle adresse. Une ligne à chaque lecture par un médecin, appel
 *   compris. Ni modification, ni suppression, ni TRUNCATE (déclencheurs).
 *   Aucune donnée de santé : seulement la nature de ce qui a été lu.
 * - demandes_rectification.note_id : la réponse du médecin renvoie à la
 *   note corrigée par avenant (DOS-04).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acces_dossier', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lecteur_id')->constrained('users');
            $table->foreignUuid('patient_id')->constrained('users');
            $table->string('objet', 30);
            $table->uuid('objet_id')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('cree_le');

            $table->index(['patient_id', 'cree_le']);
            $table->index(['lecteur_id', 'cree_le']);
        });
        DB::statement("ALTER TABLE acces_dossier ADD CONSTRAINT acces_dossier_objet CHECK (objet IN ('dossier', 'dossier_appel', 'piece_jointe_appel'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER acces_dossier_non_modifiable BEFORE UPDATE OR DELETE ON acces_dossier
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_dossier();
            CREATE TRIGGER acces_dossier_sans_truncate BEFORE TRUNCATE ON acces_dossier
                FOR EACH STATEMENT EXECUTE FUNCTION refuser_modification_dossier();
        SQL);

        Schema::table('demandes_rectification', function (Blueprint $table) {
            $table->foreignUuid('note_id')->nullable()->constrained('notes_consultation');
        });
    }

    public function down(): void
    {
        Schema::table('demandes_rectification', function (Blueprint $table) {
            $table->dropConstrainedForeignId('note_id');
        });
        Schema::dropIfExists('acces_dossier');
    }
};
