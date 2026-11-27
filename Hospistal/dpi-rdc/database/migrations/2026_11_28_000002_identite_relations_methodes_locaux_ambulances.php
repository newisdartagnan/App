<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le dossier administratif complet, tel que la fiche le demande :
 *
 *  - l'identité garde sa photo, ses « autres détails » et qui l'a enregistrée ;
 *  - les empreintes digitales capturées par le lecteur ;
 *  - les relations familiales (dossier familial) ;
 *  - les méthodes de paiement réutilisables d'un patient à l'autre ;
 *  - les locaux de service, entre le service et le lit ;
 *  - les ambulances et leurs sorties.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
            $table->text('autres_details')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // L'état civil que la fiche propose comprend l'union libre.
        DB::statement('ALTER TABLE patients DROP CONSTRAINT IF EXISTS patients_situation_matrimoniale_check');
        DB::statement("ALTER TABLE patients ADD CONSTRAINT patients_situation_matrimoniale_check
            CHECK (situation_matrimoniale IN ('celibataire', 'marie', 'divorce', 'veuf', 'union_libre', 'inconnu'))");

        // « Autre » vaccin : son nom s'écrit librement (hors calendrier PEV).
        Schema::table('vaccinations', function (Blueprint $table) {
            $table->string('antigene_libre', 150)->nullable();
        });

        Schema::create('empreintes_patient', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('doigt', 30);
            // Image du lecteur et/ou gabarit (template) qu'il exporte.
            $table->string('image_path')->nullable();
            $table->text('gabarit')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['patient_id', 'doigt']);
        });

        Schema::create('relations_familiales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('proche_id')->constrained('patients')->cascadeOnDelete();
            // Ce qu'est le proche pour le patient : « parent » = le proche est
            // son père ou sa mère.
            $table->enum('lien', ['parent', 'enfant', 'frere_soeur', 'conjoint', 'autre']);
            $table->string('precision', 100)->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['patient_id', 'proche_id']);
        });

        Schema::create('methodes_paiement', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('libelle', 150)->unique();
            $table->enum('type', ['direct', 'mutuelle', 'assurance', 'employeur', 'abonnement', 'indigent', 'autre'])->default('autre');
            // Quand la méthode est une assurance ou une convention connue,
            // l'ajouter au patient active aussi le tiers payant.
            $table->foreignUuid('assurance_id')->nullable()->constrained('assurances')->nullOnDelete();
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
        });

        Schema::create('patient_methodes_paiement', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('methode_paiement_id')->constrained('methodes_paiement')->cascadeOnDelete();
            $table->string('identifiant', 100)->nullable();
            $table->date('date_debut')->nullable();
            $table->date('date_fin')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['patient_id', 'methode_paiement_id']);
        });

        Schema::create('locaux', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignUuid('service_id')->constrained('services')->cascadeOnDelete();
            $table->string('nom', 100);
            $table->enum('type', ['salle', 'chambre', 'box', 'bureau', 'autre'])->default('salle');
            $table->string('etage', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['service_id', 'nom']);
        });

        Schema::table('lits', function (Blueprint $table) {
            $table->foreignUuid('local_id')->nullable()->constrained('locaux')->nullOnDelete();
        });

        Schema::create('ambulances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->string('libelle', 100);
            $table->string('immatriculation', 30)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sorties_ambulance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ambulance_id')->constrained('ambulances')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->string('chauffeur', 150)->nullable();
            $table->string('motif', 255);
            $table->string('destination', 255)->nullable();
            $table->dateTime('depart_at');
            $table->dateTime('arrivee_at')->nullable();
            $table->decimal('km_depart', 10, 1)->nullable();
            $table->decimal('km_arrivee', 10, 1)->nullable();
            $table->text('observations')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('depart_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sorties_ambulance');
        Schema::dropIfExists('ambulances');
        Schema::table('lits', fn (Blueprint $t) => $t->dropConstrainedForeignId('local_id'));
        Schema::dropIfExists('locaux');
        Schema::dropIfExists('patient_methodes_paiement');
        Schema::dropIfExists('methodes_paiement');
        Schema::dropIfExists('relations_familiales');
        Schema::dropIfExists('empreintes_patient');
        Schema::table('vaccinations', fn (Blueprint $t) => $t->dropColumn('antigene_libre'));
        Schema::table('patients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['photo_path', 'autres_details']);
        });
    }
};
