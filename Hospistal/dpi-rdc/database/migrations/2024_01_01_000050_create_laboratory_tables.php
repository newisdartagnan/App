<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types_examens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->enum('categorie', ['hematologie', 'biochimie', 'microbiologie', 'serologie', 'parasitologie', 'anatomopathologie', 'autre']);
            $table->string('libelle');
            $table->integer('delai_heures')->nullable();
            $table->decimal('prix', 10, 2)->nullable();
            $table->jsonb('valeurs_reference')->default('{}');
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
        });

        Schema::create('examens_laboratoire', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('prescripteur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('laborantin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('date_prescription')->nullable();
            $table->timestamp('date_prelevement')->nullable();
            $table->timestamp('date_resultat')->nullable();
            $table->enum('statut', ['prescrit', 'preleve', 'en_analyse', 'resultat_disponible', 'valide', 'annule'])->default('prescrit');
            $table->boolean('urgence')->default(false);
            $table->text('observations_cliniques')->nullable();
            $table->text('observations_laborantin')->nullable();
            $table->enum('sync_status', ['pending', 'synced', 'conflict'])->default('pending');
            $table->timestamps();

            $table->index(['patient_id', 'statut']);
        });

        Schema::create('resultats_examens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('examen_id')->constrained('examens_laboratoire')->cascadeOnDelete();
            $table->foreignUuid('type_examen_id')->constrained('types_examens')->cascadeOnDelete();
            $table->text('valeur_brute')->nullable();
            $table->decimal('valeur_numerique', 12, 4)->nullable();
            $table->string('unite', 50)->nullable();
            $table->enum('interpretation', ['normal', 'bas', 'eleve', 'critique', 'positif', 'negatif', 'non_conclusif'])->nullable();
            $table->decimal('valeur_reference_min', 12, 4)->nullable();
            $table->decimal('valeur_reference_max', 12, 4)->nullable();
            $table->text('commentaire')->nullable();
            $table->foreignUuid('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultats_examens');
        Schema::dropIfExists('examens_laboratoire');
        Schema::dropIfExists('types_examens');
    }
};
