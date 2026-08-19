<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════
        // PHARMACIE — réquisitions officine → dépôt central
        // ══════════════════════════════════════════════════════════════
        Schema::create('requisitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 30)->unique(); // REQ-2026-000001
            $table->foreignUuid('officine_id')->constrained('officines')->cascadeOnDelete();
            $table->foreignUuid('source_id')->nullable()->constrained('officines')->nullOnDelete(); // dépôt servant
            $table->foreignUuid('demandeur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('servie_par')->nullable()->constrained('users')->nullOnDelete();
            // brouillon → envoyee → servie / partiellement_servie / refusee
            $table->string('statut', 25)->default('envoyee');
            $table->text('motif')->nullable();
            $table->timestamp('date_demande');
            $table->timestamp('date_service')->nullable();
            $table->timestamps();

            $table->index(['officine_id', 'statut']);
        });

        Schema::create('lignes_requisition', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->foreignUuid('medicament_id')->constrained('medicaments')->cascadeOnDelete();
            $table->decimal('quantite_demandee', 10, 2);
            $table->decimal('quantite_servie', 10, 2)->default(0);
            $table->timestamps();
        });

        // Le stock devient propre à chaque officine : deux officines peuvent
        // détenir le même lot du même produit sans se marcher dessus.
        Schema::table('stock_medicaments', function (Blueprint $table) {
            $table->dropUnique('stock_medicaments_medicament_id_establishment_id_lot_unique');
            $table->unique(
                ['medicament_id', 'establishment_id', 'officine_id', 'lot'],
                'stock_medicaments_officine_lot_unique'
            );
        });

        // Traçabilité provenance / destination des mouvements de stock
        Schema::table('mouvements_stock', function (Blueprint $table) {
            if (! Schema::hasColumn('mouvements_stock', 'officine_id')) {
                $table->foreignUuid('officine_id')->nullable()->after('establishment_id')
                    ->constrained('officines')->nullOnDelete();
            }
            $table->string('provenance', 150)->nullable()->after('reference');
            $table->string('destination', 150)->nullable()->after('provenance');
        });

        // Types de mouvement liés au circuit officine (contrainte pgsql)
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE mouvements_stock DROP CONSTRAINT IF EXISTS mouvements_stock_type_check');
            DB::statement("ALTER TABLE mouvements_stock ADD CONSTRAINT mouvements_stock_type_check CHECK (type::text = ANY (ARRAY['entree','sortie_dispensation','sortie_peremption','sortie_perte','ajustement_inventaire','transfert_sortie','transfert_entree']::text[]))");
        }

        // ══════════════════════════════════════════════════════════════
        // DOSSIER PATIENT — référentiels antécédents / allergies
        // ══════════════════════════════════════════════════════════════
        Schema::create('referentiel_medical', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 20); // antecedent | allergie
            $table->string('code', 30)->nullable();
            $table->string('libelle', 200);
            $table->string('categorie', 60)->nullable(); // medical, chirurgical, familial… / medicament, alimentaire…
            // Pour une allergie médicamenteuse : molécule à confronter aux prescriptions
            $table->string('molecule', 150)->nullable();
            $table->boolean('est_actif')->default(true);
            $table->timestamps();

            $table->index(['type', 'categorie']);
        });

        Schema::create('patient_referentiel_medical', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('referentiel_id')->constrained('referentiel_medical')->cascadeOnDelete();
            $table->foreignUuid('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('severite', 20)->nullable(); // legere | moderee | severe (allergies)
            $table->text('precision')->nullable();
            $table->date('date_constat')->nullable();
            $table->timestamps();

            $table->unique(['patient_id', 'referentiel_id']);
        });

        // Documents cliniques (onglet Protocoles : certificat, rapport, courrier…)
        Schema::create('documents_cliniques', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->foreignUuid('auteur_id')->constrained('users')->cascadeOnDelete();
            // certificat_medical | certificat_aptitude | rapport_medical | courrier | protocole_soins
            $table->string('type', 40);
            $table->string('titre', 200);
            $table->text('contenu');
            $table->string('statut', 20)->default('redige'); // redige | valide
            $table->timestamp('valide_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'type']);
        });

        // ══════════════════════════════════════════════════════════════
        // URGENCES — triage structuré à 5 niveaux
        // ══════════════════════════════════════════════════════════════
        Schema::create('triages_urgence', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('niveau'); // 1 (réanimation) … 5 (non urgent)
            $table->unsignedSmallInteger('delai_cible_minutes');
            $table->json('criteres');          // critères cochés, par bloc
            $table->json('criteres_declencheurs')->nullable(); // ce qui a déterminé le niveau
            $table->boolean('atr')->default(false); // accident de travail / de la route
            $table->text('observation')->nullable();
            $table->timestamp('triage_at');
            $table->timestamps();

            $table->index(['visit_id', 'triage_at']);
            $table->index('niveau');
        });

        // ══════════════════════════════════════════════════════════════
        // HOSPITALISATION — plan d'administration des traitements (MAR 24 h)
        // ══════════════════════════════════════════════════════════════
        Schema::create('plans_administration', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('ligne_prescription_id')->nullable()
                ->constrained('lignes_prescription')->nullOnDelete();
            $table->string('libelle', 250);  // ex. « AMOXICILLINE 1 g x3/j IVD »
            $table->date('jour');
            $table->json('heures');          // heures prévues : [8, 14, 20]
            $table->foreignUuid('cree_par')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['visit_id', 'jour']);
        });

        Schema::create('administrations_traitement', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans_administration')->cascadeOnDelete();
            $table->unsignedTinyInteger('heure'); // 0 → 23
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('administre_at');
            $table->string('observation', 250)->nullable();
            $table->timestamps();

            $table->unique(['plan_id', 'heure']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('administrations_traitement');
        Schema::dropIfExists('plans_administration');
        Schema::dropIfExists('triages_urgence');
        Schema::dropIfExists('documents_cliniques');
        Schema::dropIfExists('patient_referentiel_medical');
        Schema::dropIfExists('referentiel_medical');
        Schema::dropIfExists('lignes_requisition');
        Schema::dropIfExists('requisitions');

        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->dropColumn(['provenance', 'destination']);
        });

        Schema::table('stock_medicaments', function (Blueprint $table) {
            $table->dropUnique('stock_medicaments_officine_lot_unique');
            $table->unique(['medicament_id', 'establishment_id', 'lot']);
        });
    }
};
