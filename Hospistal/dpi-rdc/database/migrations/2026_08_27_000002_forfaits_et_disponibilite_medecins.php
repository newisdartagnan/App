<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════
        // FORFAITS — prix tout compris couvrant un ensemble de prestations
        // ══════════════════════════════════════════════════════════════
        Schema::create('forfaits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('libelle', 150);
            $table->text('description')->nullable();
            // global   : tout le séjour est couvert, rien d'autre n'est facturé
            // partiel  : seules les catégories listées sont couvertes
            $table->string('portee', 15)->default('partiel');
            $table->decimal('montant', 12, 2);
            $table->string('devise', 5)->default('CDF');
            // Catégories de lignes couvertes (hospitalisation, examen_labo…)
            $table->jsonb('categories_couvertes')->default('[]');
            // Plafond de journées au-delà duquel le séjour redevient facturé
            // à l'acte : un forfait ne peut pas couvrir un séjour sans fin.
            $table->unsignedSmallInteger('jours_inclus')->nullable();
            // Un forfait peut être réservé à une convention / assurance
            $table->foreignUuid('assurance_id')->nullable()->constrained('assurances')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['establishment_id', 'code']);
        });

        // Forfait appliqué à un séjour : le montant est figé à l'application
        // pour qu'un changement de tarif ne réécrive pas le passé.
        Schema::table('visits', function (Blueprint $table) {
            $table->foreignUuid('forfait_id')->nullable()->after('gratuite')
                ->constrained('forfaits')->nullOnDelete();
            $table->decimal('forfait_montant', 12, 2)->nullable()->after('forfait_id');
            $table->foreignUuid('forfait_facture_id')->nullable()->after('forfait_montant')
                ->constrained('factures')->nullOnDelete();
        });

        // ══════════════════════════════════════════════════════════════
        // DISPONIBILITÉ DES MÉDECINS — plages de présence par spécialité
        // ══════════════════════════════════════════════════════════════
        Schema::create('disponibilites_medecin', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // 1 = lundi … 7 = dimanche (ISO)
            $table->unsignedTinyInteger('jour_semaine');
            $table->time('heure_debut');
            $table->time('heure_fin');
            $table->string('lieu', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'jour_semaine']);
        });

        // Absences ponctuelles : congé, mission, garde ailleurs.
        Schema::create('absences_medecin', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('debut');
            $table->date('fin');
            $table->string('motif', 150)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'debut', 'fin']);
        });

        // Un patient entré au cabinet ne doit plus figurer dans la file
        // d'attente, même si le médecin n'a pas encore enregistré sa note.
        Schema::table('visits', function (Blueprint $table) {
            $table->timestamp('consultation_debutee_at')->nullable()->after('triage_par');
            $table->foreignUuid('consultation_par')->nullable()->after('consultation_debutee_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consultation_par');
            $table->dropColumn('consultation_debutee_at');
            $table->dropConstrainedForeignId('forfait_facture_id');
            $table->dropConstrainedForeignId('forfait_id');
            $table->dropColumn('forfait_montant');
        });

        Schema::dropIfExists('absences_medecin');
        Schema::dropIfExists('disponibilites_medecin');
        Schema::dropIfExists('forfaits');
    }
};
