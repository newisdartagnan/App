<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('examens_laboratoire', function (Blueprint $table) {
            if (! Schema::hasColumn('examens_laboratoire', 'facture_id')) {
                $table->foreignUuid('facture_id')->nullable()->after('laborantin_id')
                    ->constrained('factures')->nullOnDelete();
            }
            if (! Schema::hasColumn('examens_laboratoire', 'domaine')) {
                $table->enum('domaine', ['labo', 'imagerie'])->default('labo')->after('statut');
            }
        });

        Schema::table('bons_sortie', function (Blueprint $table) {
            if (! Schema::hasColumn('bons_sortie', 'examen_id')) {
                $table->foreignUuid('examen_id')->nullable()->after('prescription_id')
                    ->constrained('examens_laboratoire')->nullOnDelete();
            }
        });

        Schema::create('actes_cliniques', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('prescripteur_id')->constrained('users')->cascadeOnDelete();
            $table->enum('domaine', ['chirurgie', 'maternite', 'hospitalisation']);
            $table->string('libelle');
            $table->decimal('prix', 10, 2);
            $table->decimal('quantite', 10, 2)->default(1);
            $table->enum('statut', ['prescrit', 'planifie', 'realise', 'facture', 'annule'])->default('prescrit');
            $table->text('compte_rendu')->nullable();
            $table->timestamp('date_realisation')->nullable();
            $table->foreignUuid('facture_id')->nullable()->constrained('factures')->nullOnDelete();
            $table->timestamps();

            $table->index(['visit_id', 'domaine']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE bons_sortie DROP CONSTRAINT IF EXISTS bons_sortie_type_check");
            DB::statement("ALTER TABLE bons_sortie ADD CONSTRAINT bons_sortie_type_check CHECK (type IN ('pharmacie','labo','imagerie','hospitalisation'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('actes_cliniques');

        Schema::table('bons_sortie', function (Blueprint $table) {
            if (Schema::hasColumn('bons_sortie', 'examen_id')) {
                $table->dropForeign(['examen_id']);
                $table->dropColumn('examen_id');
            }
        });

        Schema::table('examens_laboratoire', function (Blueprint $table) {
            if (Schema::hasColumn('examens_laboratoire', 'facture_id')) {
                $table->dropForeign(['facture_id']);
                $table->dropColumn('facture_id');
            }
            if (Schema::hasColumn('examens_laboratoire', 'domaine')) {
                $table->dropColumn('domaine');
            }
        });
    }
};
