<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RDV-01 / RDV-02 : ce que le patient décrit, et ses réponses au
        // questionnaire de tri, gardées telles quelles (traçabilité).
        Schema::create('demandes_consultation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('users');
            $table->foreignUuid('specialite_id')->nullable()->constrained('specialites');
            $table->string('motif', 255);
            $table->text('symptomes')->nullable();
            $table->string('depuis', 50)->nullable();
            $table->json('tri_reponses');
            $table->boolean('tri_alerte');
            // ouverte → reservee ; orientee_urgence si le tri a trouvé un signe d'alerte.
            $table->string('statut', 30)->default('ouverte');
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
        });
        DB::statement("ALTER TABLE demandes_consultation ADD CONSTRAINT demandes_statut CHECK (statut IN ('ouverte', 'reservee', 'orientee_urgence', 'annulee'))");

        // Photos et anciens résultats joints à la demande : disque privé,
        // jamais d'adresse publique, servis par une route qui vérifie l'accès.
        Schema::create('pieces_jointes_demande', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('demande_id')->constrained('demandes_consultation')->cascadeOnDelete();
            $table->string('chemin', 255);
            $table->string('nom_original', 255);
            $table->string('type_mime', 100);
            $table->unsignedInteger('taille_octets');
            $table->timestamps();
        });

        Schema::create('rendez_vous', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('demande_id')->constrained('demandes_consultation');
            $table->foreignUuid('patient_id')->constrained('users');
            $table->foreignUuid('medecin_id')->constrained('medecins');
            $table->foreignUuid('type_consultation_id')->constrained('types_consultation');
            $table->timestamp('debut');
            $table->timestamp('fin');
            $table->string('statut', 30);
            // Fin du blocage du créneau pendant le paiement (RDV-05).
            $table->timestamp('expire_le')->nullable();
            // Prix et devise figés à la réservation (RG-13).
            $table->decimal('prix', 12, 2)->nullable();
            $table->char('devise', 3);
            $table->unsignedSmallInteger('reports')->default(0);
            $table->timestamp('annule_le')->nullable();
            $table->string('motif_annulation', 255)->nullable();
            // Part remboursable calculée à l'annulation (RG-04/05/06), en
            // pourcentage ; le remboursement lui-même relève du lot paiement.
            $table->unsignedTinyInteger('taux_remboursement')->nullable();
            $table->timestamp('rappel_24h_envoye_le')->nullable();
            $table->timestamp('rappel_1h_envoye_le')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'debut']);
            $table->index(['medecin_id', 'debut']);
            $table->index(['statut', 'expire_le']);
        });
        DB::statement('ALTER TABLE rendez_vous ADD CONSTRAINT rendez_vous_ordre CHECK (fin > debut)');
        DB::statement("ALTER TABLE rendez_vous ADD CONSTRAINT rendez_vous_statut CHECK (statut IN ('en_attente_paiement', 'confirme', 'expire', 'annule_patient', 'annule_medecin', 'termine', 'absent_patient', 'absent_medecin'))");
        DB::statement('ALTER TABLE rendez_vous ADD CONSTRAINT rendez_vous_taux CHECK (taux_remboursement IS NULL OR taux_remboursement IN (0, 50, 100))');

        // Garantie en base contre la double réservation : un médecin n'a qu'un
        // rendez-vous occupant par heure de début. Les chevauchements de
        // durées différentes sont refusés par le service, sous verrou.
        DB::statement("CREATE UNIQUE INDEX rendez_vous_un_occupant_par_creneau ON rendez_vous (medecin_id, debut) WHERE statut IN ('en_attente_paiement', 'confirme')");
        // Un patient ne bloque qu'un créneau à la fois en attente de paiement.
        DB::statement("CREATE UNIQUE INDEX rendez_vous_une_attente_par_patient ON rendez_vous (patient_id) WHERE statut = 'en_attente_paiement'");
    }

    public function down(): void
    {
        Schema::dropIfExists('rendez_vous');
        Schema::dropIfExists('pieces_jointes_demande');
        Schema::dropIfExists('demandes_consultation');
    }
};
