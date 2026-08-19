<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════
        // CAISSE — billetage (comptage physique par coupure)
        // ══════════════════════════════════════════════════════════════
        Schema::create('billetages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignUuid('caissier_id')->constrained('users')->cascadeOnDelete();
            $table->string('devise', 3)->default('CDF');
            // Coupures comptées : { "1000": 12, "5000": 3, … }
            $table->json('coupures');
            $table->decimal('total_compte', 14, 2)->default(0);
            // Recettes encaissées sur la période, pour rapprochement
            $table->decimal('total_theorique', 14, 2)->default(0);
            $table->decimal('ecart', 14, 2)->default(0);
            $table->timestamp('debut_periode')->nullable();
            $table->timestamp('fin_periode')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['establishment_id', 'created_at']);
        });

        // ══════════════════════════════════════════════════════════════
        // FACTURATION SOCIÉTÉ — factures de convention
        // ══════════════════════════════════════════════════════════════
        Schema::create('factures_convention', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 30)->unique(); // FCV-2026-000001
            $table->foreignUuid('assurance_id')->constrained('assurances')->cascadeOnDelete();
            $table->foreignUuid('emise_par')->constrained('users')->cascadeOnDelete();
            $table->date('periode_debut');
            $table->date('periode_fin');
            // collective (un document pour tous) | individuelle (par bénéficiaire)
            $table->string('mode', 20)->default('collective');
            $table->string('devise', 3)->default('CDF');
            $table->decimal('taux_change', 12, 4)->default(1);
            $table->decimal('montant_total', 14, 2)->default(0);
            $table->decimal('montant_regle', 14, 2)->default(0);
            // emise | partiellement_reglee | reglee | annulee
            $table->string('statut', 25)->default('emise');
            $table->timestamp('date_reglement')->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['assurance_id', 'statut']);
        });

        Schema::create('lignes_facture_convention', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('facture_convention_id')->constrained('factures_convention')->cascadeOnDelete();
            $table->foreignUuid('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->decimal('part_assurance', 14, 2);
            $table->timestamps();

            $table->unique(['facture_convention_id', 'facture_id']);
        });

        Schema::create('reglements_convention', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('facture_convention_id')->constrained('factures_convention')->cascadeOnDelete();
            $table->foreignUuid('encaisse_par')->constrained('users')->cascadeOnDelete();
            $table->decimal('montant', 14, 2);
            $table->string('mode_paiement', 30)->default('virement');
            $table->string('reference', 100)->nullable();
            $table->timestamp('date_reglement');
            $table->timestamps();
        });

        // Assuré principal et ayants droit (éligibilité datée)
        Schema::table('patient_assurances', function (Blueprint $table) {
            $table->foreignUuid('assure_principal_id')->nullable()->after('assurance_id')
                ->constrained('patient_assurances')->nullOnDelete();
            $table->string('lien_parente', 30)->nullable()->after('assure_principal_id');
        });

        // ══════════════════════════════════════════════════════════════
        // AGENDA — rendez-vous et créneaux bloqués
        // ══════════════════════════════════════════════════════════════
        Schema::create('rendez_vous', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            // Un créneau bloqué (congé, réunion) n'a pas de patient
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('prestataire_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('type_consultation_id')->nullable()
                ->constrained('types_consultation')->nullOnDelete();
            $table->foreignUuid('cree_par')->constrained('users')->cascadeOnDelete();
            $table->timestamp('debut');
            $table->unsignedSmallInteger('duree_minutes')->default(30);
            // fixe | honore | annule | absent | bloque
            $table->string('statut', 20)->default('fixe');
            $table->string('contact', 40)->nullable();
            $table->string('motif', 200)->nullable();
            $table->text('observation')->nullable();
            $table->timestamp('annule_at')->nullable();
            $table->foreignUuid('annule_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['prestataire_id', 'debut']);
            $table->index(['patient_id', 'debut']);
        });

        // ══════════════════════════════════════════════════════════════
        // DOSSIER INFIRMIER — bilan hydrique par tranche horaire
        // ══════════════════════════════════════════════════════════════
        Schema::create('bilans_hydriques', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('jour');
            $table->string('tranche', 10); // matin | apres_midi | nuit
            // Entrées (mL)
            $table->unsignedInteger('perfusion')->default(0);
            $table->unsignedInteger('apport_iv')->default(0);
            $table->unsignedInteger('transfusion')->default(0);
            $table->unsignedInteger('per_os')->default(0);
            $table->unsignedInteger('autres_entrees')->default(0);
            // Sorties (mL)
            $table->unsignedInteger('urines')->default(0);
            $table->unsignedInteger('vomissements')->default(0);
            $table->unsignedInteger('drains')->default(0);
            $table->unsignedInteger('selles')->default(0);
            $table->unsignedInteger('autres_sorties')->default(0);
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->unique(['visit_id', 'jour', 'tranche']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bilans_hydriques');
        Schema::dropIfExists('rendez_vous');
        Schema::table('patient_assurances', function (Blueprint $table) {
            $table->dropColumn(['assure_principal_id', 'lien_parente']);
        });
        Schema::dropIfExists('reglements_convention');
        Schema::dropIfExists('lignes_facture_convention');
        Schema::dropIfExists('factures_convention');
        Schema::dropIfExists('billetages');
    }
};
