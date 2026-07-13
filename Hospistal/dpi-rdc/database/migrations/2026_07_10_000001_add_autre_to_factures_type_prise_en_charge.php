<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Les patients peuvent avoir type_prise_en_charge = 'autre' mais la
     * contrainte de la table factures ne l'acceptait pas : la facturation
     * plantait pour ces patients.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE factures DROP CONSTRAINT IF EXISTS factures_type_prise_en_charge_check');
        DB::statement("ALTER TABLE factures ADD CONSTRAINT factures_type_prise_en_charge_check CHECK (type_prise_en_charge IN ('prive','assurance','indigent','fonctionnaire','autre'))");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE factures DROP CONSTRAINT IF EXISTS factures_type_prise_en_charge_check');
        DB::statement("ALTER TABLE factures ADD CONSTRAINT factures_type_prise_en_charge_check CHECK (type_prise_en_charge IN ('prive','assurance','indigent','fonctionnaire'))");
    }
};
