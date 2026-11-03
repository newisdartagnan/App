<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CPT-05 : un médecin n'est réservable qu'une fois son inscription à
        // l'Ordre vérifiée par un administrateur (ordre_verifie_le).
        Schema::create('medecins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('specialite_id')->constrained('specialites');
            $table->string('numero_ordre', 50)->unique();
            $table->timestamp('ordre_verifie_le')->nullable();
            $table->text('presentation')->nullable();
            $table->json('langues')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        // RDV-04 : plages hebdomadaires récurrentes, heure de Kinshasa.
        Schema::create('plages_disponibilite', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medecin_id')->constrained('medecins')->cascadeOnDelete();
            $table->unsignedTinyInteger('jour_semaine'); // 1 = lundi … 7 = dimanche (ISO-8601)
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->timestamps();

            $table->index(['medecin_id', 'jour_semaine']);
        });
        DB::statement('ALTER TABLE plages_disponibilite ADD CONSTRAINT plages_jour CHECK (jour_semaine BETWEEN 1 AND 7)');
        DB::statement('ALTER TABLE plages_disponibilite ADD CONSTRAINT plages_ordre CHECK (heure_fin > heure_debut)');

        // Congés, empêchements : aucun créneau proposé sur ces périodes.
        Schema::create('absences_medecin', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('medecin_id')->constrained('medecins')->cascadeOnDelete();
            $table->timestamp('debut');
            $table->timestamp('fin');
            $table->string('motif', 255)->nullable();
            $table->timestamps();

            $table->index(['medecin_id', 'debut']);
        });
        DB::statement('ALTER TABLE absences_medecin ADD CONSTRAINT absences_ordre CHECK (fin > debut)');
    }

    public function down(): void
    {
        Schema::dropIfExists('absences_medecin');
        Schema::dropIfExists('plages_disponibilite');
        Schema::dropIfExists('medecins');
    }
};
