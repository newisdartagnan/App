<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revue de sécurité du lot paiement, côté caisse (PAY-01, PAY-07, PAY-09).
 */
return new class extends Migration
{
    public function up(): void
    {
        // PAY-07 : une erreur technique du prestataire ne clôt pas un
        // remboursement (l'argent est peut-être parti) : il est relancé avec la
        // même clé, un nombre borné de fois. « blocage » : raison pour laquelle
        // il n'est plus envoyé automatiquement (relances épuisées, paiement
        // passé par un autre prestataire) ; la caisse le voit et le traite.
        Schema::table('remboursements', function (Blueprint $table) {
            $table->unsignedSmallInteger('tentatives_envoi')->default(0);
            $table->timestamp('derniere_tentative_le')->nullable();
            $table->string('derniere_erreur', 255)->nullable();
            $table->string('blocage', 255)->nullable();
        });

        // PAY-01 : un taux erroné déjà utilisé n'est jamais modifié ; il est
        // « remplacé » par un nouveau taux actif pour les paiements suivants.
        // Un écart de plus de 10 % avec le dernier taux attend la confirmation
        // d'un second agent (« a_confirmer »).
        Schema::table('taux_change', function (Blueprint $table) {
            $table->string('statut', 20)->default('actif');
            $table->foreignUuid('confirme_par')->nullable()->constrained('users');
            $table->timestamp('confirme_le')->nullable();
            $table->dropUnique(['jour']);
        });
        DB::statement("ALTER TABLE taux_change ADD CONSTRAINT taux_change_statut CHECK (statut IN ('actif', 'a_confirmer', 'remplace'))");
        DB::statement('ALTER TABLE taux_change ADD CONSTRAINT taux_change_quatre_yeux CHECK (confirme_par IS NULL OR confirme_par <> saisi_par)');
        DB::statement("CREATE UNIQUE INDEX taux_change_un_actif_par_jour ON taux_change (jour) WHERE statut = 'actif'");
        DB::statement("CREATE UNIQUE INDEX taux_change_un_a_confirmer_par_jour ON taux_change (jour) WHERE statut = 'a_confirmer'");

        // PAY-09 : un solde négatif (remboursement après reversement) devient
        // une dette reportée sur le reversement suivant, jamais abandonnée.
        Schema::table('reversements', function (Blueprint $table) {
            $table->decimal('solde_periode', 12, 2)->default(0);
            $table->decimal('dette_reprise', 12, 2)->default(0);
            $table->decimal('dette_reportee', 12, 2)->default(0);
        });
        DB::statement('UPDATE reversements SET solde_periode = montant_brut');
        DB::statement('ALTER TABLE reversements DROP CONSTRAINT reversements_statut');
        DB::statement("ALTER TABLE reversements ADD CONSTRAINT reversements_statut CHECK (statut IN ('prepare', 'paye', 'sans_versement'))");
        DB::statement('ALTER TABLE reversements ADD CONSTRAINT reversements_dettes CHECK (dette_reprise >= 0 AND dette_reportee >= 0
            AND montant_brut = GREATEST(solde_periode - dette_reprise, 0)
            AND dette_reportee = GREATEST(dette_reprise - solde_periode, 0))');
        DB::statement("ALTER TABLE reversements ADD CONSTRAINT reversements_sans_versement CHECK ((statut = 'sans_versement') = (montant_net = 0))");

        // Chaque mouvement de caisse n'entre que dans UN reversement.
        Schema::create('reversement_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reversement_id')->constrained('reversements');
            $table->foreignUuid('operation_caisse_id')->unique()->constrained('operations_caisse');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reversement_operations');
        DB::statement('ALTER TABLE reversements DROP CONSTRAINT IF EXISTS reversements_sans_versement');
        DB::statement('ALTER TABLE reversements DROP CONSTRAINT IF EXISTS reversements_dettes');
        DB::statement('ALTER TABLE reversements DROP CONSTRAINT reversements_statut');
        DB::statement("ALTER TABLE reversements ADD CONSTRAINT reversements_statut CHECK (statut IN ('prepare', 'paye'))");
        Schema::table('reversements', fn (Blueprint $t) => $t->dropColumn(['solde_periode', 'dette_reprise', 'dette_reportee']));

        DB::statement('DROP INDEX IF EXISTS taux_change_un_a_confirmer_par_jour');
        DB::statement('DROP INDEX IF EXISTS taux_change_un_actif_par_jour');
        DB::statement('ALTER TABLE taux_change DROP CONSTRAINT IF EXISTS taux_change_quatre_yeux');
        DB::statement('ALTER TABLE taux_change DROP CONSTRAINT IF EXISTS taux_change_statut');
        Schema::table('taux_change', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirme_par');
            $table->dropColumn(['statut', 'confirme_le']);
            $table->unique('jour');
        });

        Schema::table('remboursements', fn (Blueprint $t) => $t->dropColumn(['tentatives_envoi', 'derniere_tentative_le', 'derniere_erreur', 'blocage']));
    }
};
