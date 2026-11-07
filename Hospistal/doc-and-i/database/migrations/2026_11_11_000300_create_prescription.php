<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Note de consultation et ordonnance (DOS-02, PRE-01 à PRE-03, RG-11).
 *
 * - ordonnances.cle_idempotence, avenants_note.cle_idempotence : clé du
 *   formulaire ; deux clics (ou une page renvoyée) ne font pas deux
 *   ordonnances ni deux avenants.
 * - interactions_medicamenteuses : interactions majeures entre deux DCI.
 *   Livrée VIDE : son contenu est médical et doit être fourni et validé par
 *   le pharmacien du client ; l'application n'invente aucune donnée.
 * - alertes_prescription : trace de chaque alerte (allergie connue,
 *   interaction majeure) que le médecin a maintenue en connaissance de
 *   cause, par ligne d'ordonnance. Non modifiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ordonnances', function (Blueprint $table) {
            $table->uuid('cle_idempotence')->nullable()->unique();
        });
        Schema::table('avenants_note', function (Blueprint $table) {
            $table->uuid('cle_idempotence')->nullable()->unique();
        });

        Schema::create('interactions_medicamenteuses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('dci_a', 100);
            $table->string('dci_b', 100);
            $table->string('message', 500);
            $table->timestamps();

            $table->unique(['dci_a', 'dci_b']);
        });
        DB::statement("ALTER TABLE interactions_medicamenteuses ADD CONSTRAINT interactions_dci_non_vides CHECK (btrim(dci_a) <> '' AND btrim(dci_b) <> '' AND dci_a <> dci_b)");

        Schema::create('alertes_prescription', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ordonnance_id')->constrained('ordonnances');
            $table->unsignedSmallInteger('rang');
            $table->string('type', 20);
            $table->string('cle', 200);
            $table->string('message', 500);
            $table->foreignUuid('maintenue_par')->constrained('users');
            $table->timestamp('maintenue_le');
            $table->timestamps();

            $table->unique(['ordonnance_id', 'cle']);
        });
        DB::statement("ALTER TABLE alertes_prescription ADD CONSTRAINT alertes_prescription_type CHECK (type IN ('allergie', 'interaction'))");

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER alertes_prescription_non_modifiables BEFORE UPDATE OR DELETE ON alertes_prescription
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_dossier();
            CREATE TRIGGER alertes_prescription_sans_truncate BEFORE TRUNCATE ON alertes_prescription
                FOR EACH STATEMENT EXECUTE FUNCTION refuser_modification_dossier();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('alertes_prescription');
        Schema::dropIfExists('interactions_medicamenteuses');
        Schema::table('avenants_note', fn (Blueprint $table) => $table->dropColumn('cle_idempotence'));
        Schema::table('ordonnances', fn (Blueprint $table) => $table->dropColumn('cle_idempotence'));
    }
};
