<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RG-04 : plus de 2 h avant, le patient déplace librement son rendez-vous ;
     * à moins de 2 h, un nombre limité de fois (reports_max_tardifs). Le
     * compteur « reports » compte tous les reports ; celui-ci, les tardifs.
     */
    public function up(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->unsignedSmallInteger('reports_tardifs')->default(0)->after('reports');
        });
    }

    public function down(): void
    {
        Schema::table('rendez_vous', function (Blueprint $table) {
            $table->dropColumn('reports_tardifs');
        });
    }
};
