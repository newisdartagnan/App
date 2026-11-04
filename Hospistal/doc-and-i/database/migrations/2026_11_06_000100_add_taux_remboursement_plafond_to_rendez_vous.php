<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RG-04 : un report ne doit pas améliorer le remboursement. À chaque
     * report, on retient le taux qu'une annulation aurait donné à ce moment ;
     * l'annulation donne ensuite le plus bas des deux : ce plafond, ou le taux
     * calculé sur le nouvel horaire. NULL : jamais reporté, pas de plafond.
     */
    public function up(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->unsignedTinyInteger('taux_remboursement_plafond')->nullable()->after('taux_remboursement');
        });
        DB::statement('ALTER TABLE rendez_vous ADD CONSTRAINT rendez_vous_taux_plafond CHECK (taux_remboursement_plafond IS NULL OR taux_remboursement_plafond IN (0, 50, 100))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rendez_vous DROP CONSTRAINT IF EXISTS rendez_vous_taux_plafond');
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropColumn('taux_remboursement_plafond');
        });
    }
};
