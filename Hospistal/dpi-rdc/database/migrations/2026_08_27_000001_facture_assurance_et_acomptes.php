<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La facture est une pièce comptable : elle doit porter le nom de
        // l'assureur tel qu'il était au moment de l'émission, et non « assurance ».
        Schema::table('factures', function (Blueprint $table) {
            $table->string('assurance_nom', 150)->nullable()->after('type_prise_en_charge');
            $table->string('assurance_numero', 100)->nullable()->after('assurance_nom');
            // Acomptes imputés sur cette facture, pour que le reste à payer
            // au guichet tienne compte de ce qui a déjà été avancé.
            $table->decimal('acompte_impute', 12, 2)->default(0)->after('assurance_part');
        });

        // Reprise des factures déjà émises, d'après le lien d'assurance actif.
        DB::statement("
            UPDATE factures f
            SET assurance_nom = a.nom, assurance_numero = pa.numero_police
            FROM patient_assurances pa
            JOIN assurances a ON a.id = pa.assurance_id
            WHERE pa.patient_id = f.patient_id
              AND pa.est_actif = true
              AND f.type_prise_en_charge = 'assurance'
              AND f.assurance_nom IS NULL
        ");

        DB::statement("
            UPDATE factures f
            SET assurance_nom = p.assurance_nom, assurance_numero = p.assurance_numero
            FROM patients p
            WHERE p.id = f.patient_id
              AND f.type_prise_en_charge = 'assurance'
              AND f.assurance_nom IS NULL
              AND p.assurance_nom IS NOT NULL
        ");

        // ══════════════════════════════════════════════════════════════
        // ACOMPTES — la table « cautions » existait sans jamais servir.
        // On la complète pour couvrir les avances de soins des urgences
        // et des hospitalisations : motif, échéance, et traçabilité des
        // imputations facture par facture.
        // ══════════════════════════════════════════════════════════════
        Schema::table('cautions', function (Blueprint $table) {
            $table->string('type', 20)->default('acompte')->after('patient_id');
            $table->string('mode_paiement', 20)->default('especes')->after('devise');
            $table->text('motif')->nullable()->after('mode_paiement');
        });

        Schema::create('imputations_acompte', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('caution_id')->constrained('cautions')->cascadeOnDelete();
            $table->foreignUuid('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('montant', 12, 2);
            $table->timestamps();

            $table->index(['caution_id', 'facture_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('imputations_acompte');

        Schema::table('cautions', function (Blueprint $table) {
            $table->dropColumn(['type', 'mode_paiement', 'motif']);
        });

        Schema::table('factures', function (Blueprint $table) {
            $table->dropColumn(['assurance_nom', 'assurance_numero', 'acompte_impute']);
        });
    }
};
