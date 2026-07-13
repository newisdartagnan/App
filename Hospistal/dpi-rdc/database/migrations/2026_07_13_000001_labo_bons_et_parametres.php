<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bons d'examens numérotés (LAB-/IMG-AAAA-NNNNNN), résultats par
     * paramètre (NFS, ionogramme… : plusieurs paramètres par examen) et
     * conclusion du biologiste / compte-rendu du radiologue.
     */
    public function up(): void
    {
        Schema::table('examens_laboratoire', function (Blueprint $table) {
            if (! Schema::hasColumn('examens_laboratoire', 'numero_bon')) {
                $table->string('numero_bon', 30)->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('examens_laboratoire', 'conclusion')) {
                $table->text('conclusion')->nullable()->after('observations_laborantin');
            }
        });

        Schema::table('resultats_examens', function (Blueprint $table) {
            if (! Schema::hasColumn('resultats_examens', 'parametre')) {
                $table->string('parametre', 150)->nullable()->after('type_examen_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('resultats_examens', function (Blueprint $table) {
            if (Schema::hasColumn('resultats_examens', 'parametre')) {
                $table->dropColumn('parametre');
            }
        });

        Schema::table('examens_laboratoire', function (Blueprint $table) {
            if (Schema::hasColumn('examens_laboratoire', 'numero_bon')) {
                $table->dropColumn('numero_bon');
            }
            if (Schema::hasColumn('examens_laboratoire', 'conclusion')) {
                $table->dropColumn('conclusion');
            }
        });
    }
};
