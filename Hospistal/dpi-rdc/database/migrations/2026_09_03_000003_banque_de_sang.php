<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Banque de sang : donneurs, poches, demandes, transfusions.
 *
 * Chercher du sang au moment où il en faut, c'est déjà trop tard. Le registre
 * tient donc deux choses : ce qui est en stock ce soir, et qui peut donner
 * demain — le donneur de rappel, celui qu'on appelle à trois heures du matin.
 *
 * Une poche n'est jamais délivrée sans que sa compatibilité ABO-Rhésus ait été
 * vérifiée, ni si son dépistage n'est pas complet et négatif. C'est la règle
 * la plus dure du module, et elle est tenue par le code, pas par l'habitude.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Le groupe du patient sert au tri des poches compatibles : il est
        // rattaché au dossier, pas à un examen isolé.
        Schema::table('patients', function (Blueprint $table) {
            $table->string('groupe_sanguin', 5)->nullable();
        });

        Schema::create('donneurs_sang', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            // Un donneur peut être un patient connu de la maison : on garde le
            // lien plutôt que de recopier son identité.
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();

            $table->string('code', 30);
            $table->string('nom', 100);
            $table->string('postnom', 100)->nullable();
            $table->string('prenom', 100)->nullable();
            $table->char('sexe', 1)->nullable();
            $table->date('date_naissance')->nullable();
            $table->string('groupe_sanguin', 5);
            $table->string('telephone', 50)->nullable();
            $table->string('adresse', 255)->nullable();

            $table->string('type_donneur', 20)->default('benevole');
            $table->date('dernier_don')->nullable();
            $table->unsignedSmallInteger('nombre_dons')->default(0);
            // Un donneur écarté le reste tant qu'on ne l'a pas réhabilité :
            // sérologie positive, poids insuffisant, refus.
            $table->boolean('est_eligible')->default(true);
            $table->string('motif_exclusion', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['establishment_id', 'code']);
            $table->index(['establishment_id', 'groupe_sanguin', 'est_eligible']);
        });

        DB::statement("ALTER TABLE donneurs_sang ADD CONSTRAINT donneurs_sang_groupe_check
            CHECK (groupe_sanguin IN ('O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'))");
        DB::statement("ALTER TABLE donneurs_sang ADD CONSTRAINT donneurs_sang_type_check
            CHECK (type_donneur IN ('benevole', 'familial', 'remunere', 'autologue'))");

        Schema::create('poches_sang', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignUuid('donneur_id')->nullable()->constrained('donneurs_sang')->nullOnDelete();

            $table->string('numero', 40);
            $table->string('groupe_sanguin', 5);
            $table->string('type_produit', 30)->default('sang_total');
            $table->unsignedInteger('volume_ml')->default(450);

            $table->date('date_prelevement');
            $table->date('date_peremption');
            $table->string('statut', 20)->default('quarantaine');
            $table->string('emplacement', 100)->nullable();

            // Dépistage obligatoire avant toute délivrance. Tant qu'un seul
            // marqueur manque, la poche reste en quarantaine.
            $table->boolean('depistage_vih')->nullable();
            $table->boolean('depistage_hepatite_b')->nullable();
            $table->boolean('depistage_hepatite_c')->nullable();
            $table->boolean('depistage_syphilis')->nullable();
            $table->boolean('depistage_paludisme')->nullable();
            $table->date('date_depistage')->nullable();
            $table->foreignUuid('depiste_par')->nullable()->constrained('users')->nullOnDelete();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['establishment_id', 'numero']);
            $table->index(['establishment_id', 'statut', 'groupe_sanguin']);
        });

        DB::statement("ALTER TABLE poches_sang ADD CONSTRAINT poches_sang_groupe_check
            CHECK (groupe_sanguin IN ('O+', 'O-', 'A+', 'A-', 'B+', 'B-', 'AB+', 'AB-'))");
        DB::statement("ALTER TABLE poches_sang ADD CONSTRAINT poches_sang_produit_check
            CHECK (type_produit IN ('sang_total', 'concentre_globulaire', 'plasma_frais', 'plaquettes', 'cryoprecipite'))");
        DB::statement("ALTER TABLE poches_sang ADD CONSTRAINT poches_sang_statut_check
            CHECK (statut IN ('quarantaine', 'disponible', 'reservee', 'transfusee', 'perimee', 'detruite'))");

        Schema::create('demandes_sang', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('establishment_id')->constrained('establishments')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignUuid('demandeur_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('numero', 40);
            $table->string('groupe_demande', 5)->nullable();
            $table->string('type_produit', 30)->default('sang_total');
            $table->unsignedSmallInteger('nombre_poches')->default(1);
            $table->boolean('urgence')->default(false);
            $table->text('indication')->nullable();
            $table->decimal('hemoglobine', 4, 1)->nullable();
            $table->string('statut', 20)->default('en_attente');
            $table->text('motif_refus')->nullable();
            $table->timestamps();

            $table->unique(['establishment_id', 'numero']);
            $table->index(['establishment_id', 'statut']);
        });

        DB::statement("ALTER TABLE demandes_sang ADD CONSTRAINT demandes_sang_statut_check
            CHECK (statut IN ('en_attente', 'servie', 'partiellement_servie', 'refusee', 'annulee'))");

        // Une transfusion se note déjà au lit du malade, dans le dossier
        // infirmier. Plutôt que d'en tenir une seconde version, on raccroche
        // cette feuille à la poche délivrée : une seule vérité, remplie par
        // celui qui pose la perfusion.
        Schema::table('transfusions', function (Blueprint $table) {
            // Une transfusion n'attend pas l'admission : elle se pose aussi
            // aux urgences, avant qu'un séjour ne soit ouvert.
            $table->uuid('visit_id')->nullable()->change();

            $table->foreignUuid('poche_id')->nullable()->constrained('poches_sang')->nullOnDelete();
            $table->foreignUuid('demande_id')->nullable()->constrained('demandes_sang')->nullOnDelete();
            $table->foreignUuid('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            // Le contrôle ultime au lit du malade : c'est lui qui évite
            // l'accident ABO, il doit être tracé.
            $table->boolean('controle_ultime')->default(false);
            $table->decimal('hemoglobine_avant', 4, 1)->nullable();
            $table->decimal('hemoglobine_apres', 4, 1)->nullable();

            $table->index('poche_id');
        });
    }

    public function down(): void
    {
        Schema::table('transfusions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('poche_id');
            $table->dropConstrainedForeignId('demande_id');
            $table->dropConstrainedForeignId('patient_id');
            $table->dropColumn(['controle_ultime', 'hemoglobine_avant', 'hemoglobine_apres']);
        });

        Schema::dropIfExists('demandes_sang');
        Schema::dropIfExists('poches_sang');
        Schema::dropIfExists('donneurs_sang');

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('groupe_sanguin');
        });
    }
};
