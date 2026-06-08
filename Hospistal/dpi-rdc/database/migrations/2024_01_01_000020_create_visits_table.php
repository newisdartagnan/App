<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('type', ['consultation_externe', 'urgence', 'hospitalisation', 'chirurgie', 'accouchement', 'visite_domicile']);
            $table->enum('statut', ['en_attente', 'en_cours', 'termine', 'annule'])->default('en_attente');

            $table->timestamp('date_entree');
            $table->timestamp('date_sortie')->nullable();
            $table->integer('duree_sejour_jours')->nullable();

            $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUuid('lit_id')->nullable()->constrained('lits')->nullOnDelete();
            $table->enum('mode_entree', ['spontane', 'reference', 'transfert', 'urgence'])->default('spontane');
            $table->string('provenance', 200)->nullable();
            $table->enum('mode_sortie', ['gueri', 'ameliore', 'stationnaire', 'agrave', 'transfert', 'sortie_contre_avis', 'deces', 'inconnu'])->nullable();
            $table->string('transfert_vers', 200)->nullable();

            $table->decimal('poids_kg', 5, 2)->nullable();
            $table->decimal('taille_cm', 5, 1)->nullable();
            $table->decimal('imc', 4, 2)->nullable();
            $table->integer('tension_systolique')->nullable();
            $table->integer('tension_diastolique')->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->integer('frequence_cardiaque')->nullable();
            $table->integer('frequence_respiratoire')->nullable();
            $table->decimal('saturation_o2', 4, 1)->nullable();
            $table->integer('glasgow')->nullable();

            $table->text('motif_consultation')->nullable();
            $table->text('symptomes_principaux')->nullable();

            $table->decimal('tarif_consultation', 10, 2)->nullable();
            $table->boolean('est_payant')->default(true);

            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('pending');
            $table->timestamps();

            $table->index(['establishment_id', 'date_entree']);
            $table->index(['patient_id', 'date_entree']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
