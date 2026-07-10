<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            if (! Schema::hasColumn('factures', 'prescription_id')) {
                $table->foreignUuid('prescription_id')->nullable()->after('visit_id')
                    ->constrained('prescriptions')->nullOnDelete();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE prescriptions DROP CONSTRAINT IF EXISTS prescriptions_statut_check');
            DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_statut_check CHECK (statut IN ('brouillon','en_attente_paiement','en_attente','dispensee','partiellement_dispensee','annulee'))");
        }
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            if (Schema::hasColumn('factures', 'prescription_id')) {
                $table->dropForeign(['prescription_id']);
                $table->dropColumn('prescription_id');
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE prescriptions DROP CONSTRAINT IF EXISTS prescriptions_statut_check');
            DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_statut_check CHECK (statut IN ('en_attente','dispensee','partiellement_dispensee','annulee'))");
        }
    }
};
