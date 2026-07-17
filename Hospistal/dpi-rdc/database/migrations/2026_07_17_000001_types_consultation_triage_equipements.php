<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Consultations typées et tarifées (générale 20 $ / spécialisée 24 $),
     * triage infirmier avant le médecin, et parc d'équipements (machines
     * labo / imagerie) repris de l'ancien système CSK.
     */
    public function up(): void
    {
        Schema::create('types_consultation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 30)->unique();
            $table->string('libelle', 150);
            $table->enum('categorie', ['generale', 'specialisee']);
            $table->string('specialite', 100)->nullable();
            $table->decimal('prix_usd', 8, 2);
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
        });

        Schema::table('visits', function (Blueprint $table) {
            if (! Schema::hasColumn('visits', 'type_consultation_id')) {
                $table->foreignUuid('type_consultation_id')->nullable()->after('type')
                    ->constrained('types_consultation')->nullOnDelete();
            }
            if (! Schema::hasColumn('visits', 'gratuite')) {
                $table->boolean('gratuite')->default(false)->after('est_payant');
            }
            if (! Schema::hasColumn('visits', 'triage_fait_at')) {
                $table->timestamp('triage_fait_at')->nullable()->after('gratuite');
                $table->foreignUuid('triage_par')->nullable()->after('triage_fait_at')
                    ->constrained('users')->nullOnDelete();
            }
        });

        Schema::create('equipements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 150);
            $table->enum('type', ['labo', 'imagerie', 'autre'])->default('autre');
            $table->string('marque', 100)->nullable();
            $table->string('modele', 100)->nullable();
            $table->string('numero_serie', 100)->nullable();
            $table->string('statut', 30)->default('operationnel');
            $table->string('localisation', 150)->nullable();
            $table->date('date_acquisition')->nullable();
            $table->date('date_derniere_maintenance')->nullable();
            $table->date('prochaine_maintenance')->nullable();
            $table->text('observations')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipements');

        Schema::table('visits', function (Blueprint $table) {
            foreach (['type_consultation_id', 'gratuite', 'triage_fait_at', 'triage_par'] as $col) {
                if (Schema::hasColumn('visits', $col)) {
                    if (in_array($col, ['type_consultation_id', 'triage_par'])) {
                        $table->dropForeign([$col]);
                    }
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('types_consultation');
    }
};
