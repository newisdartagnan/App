<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Notifications internes entre services (modèle CSK services_notifications)
        Schema::create('notifications_internes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('service', 30)->default('general');   // labo | imagerie | pharmacie | general
            $table->string('type', 40)->default('info');          // prescription_recue | resultat_pret | medicament_delivre | alerte
            $table->string('reference_type', 40)->nullable();     // examen | prescription
            $table->uuid('reference_id')->nullable();
            $table->string('code_reference', 40)->nullable();     // LAB-2026-000001…
            $table->string('titre');
            $table->text('message');
            $table->foreignUuid('destinataire_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('groupe_destinataire', 40)->nullable(); // rôle Spatie : laborantin, pharmacien… ou « tous »
            $table->string('priorite', 15)->default('normale');    // normale | haute | urgente
            $table->boolean('lu')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->boolean('archive')->default(false);
            $table->timestamps();

            $table->index(['destinataire_id', 'lu']);
            $table->index(['groupe_destinataire', 'lu']);
            $table->index(['service', 'lu']);
        });

        // ── Dossier infirmier : transmissions et évolutions médicales (modèle GPS)
        Schema::create('notes_evolution', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 20)->default('evolution'); // evolution (médecin) | transmission (infirmier)
            $table->string('etat_general', 20)->nullable();   // bonne | stationnaire | degradee | critique
            $table->text('note');
            $table->timestamps();

            $table->index(['visit_id', 'created_at']);
        });

        // ── Dossier infirmier : surveillance des signes vitaux (répétée dans le séjour)
        Schema::create('signes_vitaux', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('mesure_at');
            $table->decimal('poids_kg', 5, 2)->nullable();
            $table->decimal('temperature', 4, 1)->nullable();
            $table->unsignedSmallInteger('tension_systolique')->nullable();
            $table->unsignedSmallInteger('tension_diastolique')->nullable();
            $table->unsignedSmallInteger('frequence_cardiaque')->nullable();
            $table->unsignedSmallInteger('frequence_respiratoire')->nullable();
            $table->unsignedTinyInteger('saturation_o2')->nullable();
            $table->decimal('glycemie', 5, 2)->nullable();
            $table->text('observation')->nullable();
            $table->timestamps();

            $table->index(['visit_id', 'mesure_at']);
        });

        // ── Programme opératoire (modèle GPS : préop → planifié → réalisé)
        Schema::table('actes_cliniques', function (Blueprint $table) {
            $table->timestamp('date_prevue')->nullable()->after('date_realisation');
            $table->foreignUuid('operateur_id')->nullable()->after('prescripteur_id')
                ->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('duree_minutes')->nullable()->after('date_prevue');
            $table->boolean('consentement')->default(false)->after('duree_minutes');
            $table->boolean('urgence')->default(false)->after('consentement');
            $table->string('indication')->nullable()->after('urgence');
        });

        // ── Services d'hospitalisation supplémentaires : réanimation, néonatologie
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE services DROP CONSTRAINT IF EXISTS services_type_check');
            DB::statement("ALTER TABLE services ADD CONSTRAINT services_type_check CHECK (type::text = ANY (ARRAY['urgence','medecine','chirurgie','maternite','pediatrie','reanimation','neonatologie','labo','pharmacie','autre']::text[]))");

            // Statuts de lit du modèle GPS : à nettoyer / à réparer
            DB::statement('ALTER TABLE lits DROP CONSTRAINT IF EXISTS lits_statut_check');
            DB::statement("ALTER TABLE lits ADD CONSTRAINT lits_statut_check CHECK (statut::text = ANY (ARRAY['libre','occupe','maintenance','reserve','a_nettoyer','a_reparer']::text[]))");
        }
    }

    public function down(): void
    {
        Schema::table('actes_cliniques', function (Blueprint $table) {
            $table->dropColumn(['date_prevue', 'operateur_id', 'duree_minutes', 'consentement', 'urgence', 'indication']);
        });
        Schema::dropIfExists('signes_vitaux');
        Schema::dropIfExists('notes_evolution');
        Schema::dropIfExists('notifications_internes');
    }
};
