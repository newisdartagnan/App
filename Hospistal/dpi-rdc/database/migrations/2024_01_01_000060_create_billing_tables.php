<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('numero_facture', 50)->unique();
            $table->timestamp('date_facture');
            $table->enum('statut', ['brouillon', 'emise', 'partiellement_payee', 'payee', 'annulee'])->default('brouillon');
            $table->enum('type_prise_en_charge', ['prive', 'assurance', 'indigent', 'fonctionnaire'])->default('prive');
            $table->decimal('assurance_part', 10, 2)->default(0);
            $table->decimal('patient_part', 10, 2)->default(0);
            $table->decimal('remise', 10, 2)->default(0);
            $table->decimal('total_ht', 10, 2)->default(0);
            $table->decimal('total_ttc', 10, 2)->default(0);
            $table->text('observations')->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('pending');
            $table->timestamps();

            $table->index(['establishment_id', 'date_facture']);
        });

        Schema::create('lignes_facture', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->enum('type', ['consultation', 'medicament', 'examen_labo', 'acte_chirurgical', 'hospitalisation', 'imagerie', 'autre']);
            $table->string('libelle');
            $table->uuid('reference_id')->nullable();
            $table->decimal('quantite', 10, 2)->default(1);
            $table->decimal('prix_unitaire', 10, 2);
            $table->decimal('remise_ligne', 10, 2)->default(0);
            $table->decimal('total_ligne', 10, 2);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('paiements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->foreignUuid('caissier_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('date_paiement');
            $table->decimal('montant', 10, 2);
            $table->enum('mode_paiement', ['especes', 'mobile_money', 'virement', 'cheque', 'autre'])->default('especes');
            $table->string('reference_paiement', 200)->nullable();
            $table->string('recu_numero', 50)->nullable();
            $table->text('notes')->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('pending');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
        Schema::dropIfExists('lignes_facture');
        Schema::dropIfExists('factures');
    }
};
