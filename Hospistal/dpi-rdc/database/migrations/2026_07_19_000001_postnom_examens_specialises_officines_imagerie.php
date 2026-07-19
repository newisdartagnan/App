<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * - Post-nom patient (usage RDC)
     * - Actes « examen spécialisé » (dentisterie, ORL…) dans le parcours
     * - Compte-rendu d'imagerie structuré (technique, recommandations)
     *   et fichiers joints (photos, images, vidéos, PDF)
     * - Officines de pharmacie (dépôt central, ambulatoire, services)
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            if (! Schema::hasColumn('patients', 'postnom')) {
                $table->string('postnom', 100)->nullable()->after('nom');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE actes_cliniques DROP CONSTRAINT IF EXISTS actes_cliniques_domaine_check');
            DB::statement("ALTER TABLE actes_cliniques ADD CONSTRAINT actes_cliniques_domaine_check CHECK (domaine IN ('chirurgie','maternite','hospitalisation','examen_specialise'))");
        }

        Schema::table('examens_laboratoire', function (Blueprint $table) {
            if (! Schema::hasColumn('examens_laboratoire', 'technique')) {
                $table->text('technique')->nullable()->after('observations_laborantin');
            }
            if (! Schema::hasColumn('examens_laboratoire', 'recommandations')) {
                $table->text('recommandations')->nullable()->after('conclusion');
            }
        });

        Schema::create('examen_fichiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('examen_id')->constrained('examens_laboratoire')->cascadeOnDelete();
            $table->string('nom_original');
            $table->string('chemin');
            $table->enum('type', ['image', 'video', 'pdf', 'autre'])->default('autre');
            $table->string('description')->nullable();
            $table->foreignUuid('ajoute_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('officines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 150);
            $table->enum('type', ['depot_central', 'ambulatoire', 'service']);
            $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
        });

        Schema::table('stock_medicaments', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_medicaments', 'officine_id')) {
                $table->foreignUuid('officine_id')->nullable()->after('establishment_id')
                    ->constrained('officines')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_medicaments', function (Blueprint $table) {
            if (Schema::hasColumn('stock_medicaments', 'officine_id')) {
                $table->dropForeign(['officine_id']);
                $table->dropColumn('officine_id');
            }
        });
        Schema::dropIfExists('officines');
        Schema::dropIfExists('examen_fichiers');

        Schema::table('examens_laboratoire', function (Blueprint $table) {
            foreach (['technique', 'recommandations'] as $col) {
                if (Schema::hasColumn('examens_laboratoire', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE actes_cliniques DROP CONSTRAINT IF EXISTS actes_cliniques_domaine_check');
            DB::statement("ALTER TABLE actes_cliniques ADD CONSTRAINT actes_cliniques_domaine_check CHECK (domaine IN ('chirurgie','maternite','hospitalisation'))");
        }

        Schema::table('patients', function (Blueprint $table) {
            if (Schema::hasColumn('patients', 'postnom')) {
                $table->dropColumn('postnom');
            }
        });
    }
};
