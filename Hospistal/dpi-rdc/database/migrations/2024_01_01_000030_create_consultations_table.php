<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('validated_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('date_consultation');
            $table->enum('type', ['initiale', 'suivi', 'urgence', 'teleconsultation'])->default('initiale');

            $table->text('histoire_maladie')->nullable();
            $table->text('antecedents_personnels')->nullable();
            $table->text('antecedents_familiaux')->nullable();
            $table->text('antecedents_chirurgicaux')->nullable();
            $table->text('allergies')->nullable();
            $table->jsonb('traitements_en_cours')->default('[]');

            $table->text('examen_general')->nullable();
            $table->jsonb('examen_physique')->default('{}');
            $table->jsonb('signes_vitaux')->default('{}');

            $table->text('hypotheses_diagnostiques')->nullable();
            $table->jsonb('diagnostics')->default('[]');
            $table->text('conclusion')->nullable();

            $table->text('conduite_a_tenir')->nullable();
            $table->text('observations')->nullable();

            $table->enum('statut', ['brouillon', 'finalise', 'valide'])->default('brouillon');
            $table->timestamp('finalise_at')->nullable();
            $table->timestamp('valide_at')->nullable();

            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('pending');
            $table->timestamps();

            $table->index(['visit_id', 'date_consultation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
