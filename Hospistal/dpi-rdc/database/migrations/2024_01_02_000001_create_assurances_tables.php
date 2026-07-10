<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('assurances', function(Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('nom', 150);
            $t->string('code', 30)->unique();
            $t->decimal('taux_couverture', 5, 2)->default(80.00);
            $t->decimal('plafond_annuel_usd', 10, 2)->nullable();
            $t->decimal('plafond_annuel_cdf', 12, 2)->nullable();
            $t->boolean('est_actif')->default(true);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('assurance_couvertures', function(Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('assurance_id')->constrained('assurances')->cascadeOnDelete();
            $t->enum('type', ['consultation','examen_labo','medicament','acte_chirurgical','hospitalisation','imagerie']);
            $t->uuid('reference_id')->nullable();
            $t->string('reference_libelle', 255)->nullable();
            $t->boolean('couvert')->default(true);
            $t->decimal('taux_specifique', 5, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('patient_assurances', function(Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $t->foreignUuid('assurance_id')->constrained('assurances')->cascadeOnDelete();
            $t->string('numero_police', 100);
            $t->string('nom_beneficiaire', 200)->nullable();
            $t->date('date_debut')->nullable();
            $t->date('date_fin')->nullable();
            $t->integer('annee_courante')->default(2026);
            $t->decimal('consomme_annuel_usd', 10, 2)->default(0);
            $t->decimal('consomme_annuel_cdf', 12, 2)->default(0);
            $t->boolean('est_actif')->default(true);
            $t->timestamps();
        });
        Schema::create('cautions', function(Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $t->foreignUuid('patient_id')->constrained('patients');
            $t->foreignUuid('caissier_id')->constrained('users');
            $t->decimal('montant', 12, 2);
            $t->enum('devise', ['CDF','USD'])->default('CDF');
            $t->enum('statut', ['versee','soldee','remboursee_partiel','remboursee_total'])->default('versee');
            $t->decimal('montant_impute', 12, 2)->default(0);
            $t->decimal('montant_rembourse', 12, 2)->default(0);
            $t->string('reference_paiement', 200)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('bons_sortie', function(Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('numero', 50)->unique();
            $t->foreignUuid('facture_id')->constrained('factures')->cascadeOnDelete();
            $t->foreignUuid('patient_id')->constrained('patients');
            $t->foreignUuid('emis_par')->constrained('users');
            $t->enum('type', ['pharmacie','labo','imagerie'])->default('pharmacie');
            $t->enum('statut', ['emis','utilise','expire','annule'])->default('emis');
            $t->foreignUuid('prescription_id')->nullable()->constrained('prescriptions');
            $t->timestamp('expire_at')->nullable();
            $t->timestamp('utilise_at')->nullable();
            $t->boolean('imprime')->default(false);
            $t->timestamps();
        });
        Schema::create('facture_tiers_payant', function(Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('facture_id')->constrained('factures')->cascadeOnDelete();
            $t->foreignUuid('ligne_facture_id')->constrained('lignes_facture')->cascadeOnDelete();
            $t->foreignUuid('assurance_id')->constrained('assurances');
            $t->boolean('acte_couvert')->default(true);
            $t->decimal('taux_applique', 5, 2)->default(0);
            $t->decimal('montant_acte', 12, 2)->default(0);
            $t->decimal('part_assurance', 12, 2)->default(0);
            $t->decimal('part_patient', 12, 2)->default(0);
            $t->boolean('plafond_atteint')->default(false);
            $t->string('devise', 3)->default('CDF');
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('facture_tiers_payant');
        Schema::dropIfExists('bons_sortie');
        Schema::dropIfExists('cautions');
        Schema::dropIfExists('patient_assurances');
        Schema::dropIfExists('assurance_couvertures');
        Schema::dropIfExists('assurances');
    }
};