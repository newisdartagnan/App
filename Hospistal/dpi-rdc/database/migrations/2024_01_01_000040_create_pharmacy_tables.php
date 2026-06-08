<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medicaments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('code_ucd', 50)->nullable();
            $table->string('denomination_commune');
            $table->string('nom_commercial')->nullable();
            $table->enum('forme', ['comprime', 'gelule', 'sirop', 'injectable', 'pommade', 'creme', 'suppositoire', 'collyre', 'sachet', 'autre'])->default('comprime');
            $table->string('dosage', 100)->nullable();
            $table->string('unite_dispensation', 50)->nullable();
            $table->string('classe_therapeutique', 150)->nullable();
            $table->boolean('necessite_ordonnance')->default(true);
            $table->boolean('est_actif')->default(true);
            $table->timestamps();

            $table->index(['establishment_id', 'denomination_commune']);
        });

        Schema::create('stock_medicaments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medicament_id')->constrained('medicaments')->cascadeOnDelete();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->decimal('quantite_disponible', 10, 2)->default(0);
            $table->integer('quantite_alerte')->default(10);
            $table->integer('quantite_commande')->default(0);
            $table->decimal('prix_unitaire_vente', 10, 2)->nullable();
            $table->decimal('prix_unitaire_achat', 10, 2)->nullable();
            $table->date('date_peremption')->nullable();
            $table->string('lot', 100)->nullable();
            $table->string('emplacement', 100)->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->unique(['medicament_id', 'establishment_id', 'lot']);
        });

        Schema::create('prescriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultation_id')->nullable()->constrained('consultations')->nullOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('prescripteur_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('date_prescription');
            $table->enum('statut', ['en_attente', 'dispensee', 'partiellement_dispensee', 'annulee'])->default('en_attente');
            $table->text('observations')->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('pending');
            $table->timestamps();
        });

        Schema::create('lignes_prescription', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
            $table->foreignUuid('medicament_id')->constrained('medicaments')->cascadeOnDelete();
            $table->string('dose', 100)->nullable();
            $table->string('frequence', 100)->nullable();
            $table->integer('duree_jours')->nullable();
            $table->enum('voie_administration', ['orale', 'injectable_iv', 'injectable_im', 'topique', 'rectale', 'ophtalmique', 'autre'])->default('orale');
            $table->text('instructions')->nullable();
            $table->decimal('quantite_totale', 10, 2);
            $table->decimal('quantite_dispensee', 10, 2)->default(0);
            $table->boolean('est_substituable')->default(false);
            $table->timestamps();
        });

        Schema::create('dispensations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ligne_prescription_id')->constrained('lignes_prescription')->cascadeOnDelete();
            $table->foreignUuid('pharmacien_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('date_dispensation');
            $table->decimal('quantite_dispensee', 10, 2);
            $table->string('lot', 100)->nullable();
            $table->decimal('prix_applique', 10, 2)->nullable();
            $table->text('observations')->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('pending');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medicament_id')->constrained('medicaments')->cascadeOnDelete();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('type', ['entree', 'sortie_dispensation', 'sortie_peremption', 'ajustement_inventaire', 'transfert']);
            $table->decimal('quantite', 10, 2);
            $table->decimal('quantite_avant', 10, 2);
            $table->decimal('quantite_apres', 10, 2);
            $table->string('reference', 200)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['establishment_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
        Schema::dropIfExists('dispensations');
        Schema::dropIfExists('lignes_prescription');
        Schema::dropIfExists('prescriptions');
        Schema::dropIfExists('stock_medicaments');
        Schema::dropIfExists('medicaments');
    }
};
