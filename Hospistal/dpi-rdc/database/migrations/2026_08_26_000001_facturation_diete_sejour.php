<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La diète devient une ligne de facture à part entière, distincte du
        // séjour : la cuisine et la comptabilité lisent le même montant.
        DB::statement('ALTER TABLE lignes_facture DROP CONSTRAINT IF EXISTS lignes_facture_type_check');
        DB::statement(
            "ALTER TABLE lignes_facture ADD CONSTRAINT lignes_facture_type_check CHECK (type::text = ANY (ARRAY[
                'consultation','medicament','examen_labo','acte_chirurgical',
                'hospitalisation','imagerie','diete','dialyse','autre'
            ]::text[]))"
        );

        // Une diète déjà portée sur une facture ne peut plus être refacturée.
        Schema::table('prescriptions_diete', function (Blueprint $table) {
            $table->foreignUuid('facture_id')->nullable()->after('user_id')
                ->constrained('factures')->nullOnDelete();
            $table->unsignedSmallInteger('jours_factures')->nullable()->after('facture_id');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions_diete', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facture_id');
            $table->dropColumn('jours_factures');
        });

        DB::statement('ALTER TABLE lignes_facture DROP CONSTRAINT IF EXISTS lignes_facture_type_check');
        DB::statement(
            "ALTER TABLE lignes_facture ADD CONSTRAINT lignes_facture_type_check CHECK (type::text = ANY (ARRAY[
                'consultation','medicament','examen_labo','acte_chirurgical',
                'hospitalisation','imagerie','autre'
            ]::text[]))"
        );
    }
};
