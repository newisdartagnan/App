<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qui manquait à la banque de sang : l'après.
 *
 * Délivrer une poche n'est pas transfuser. Tant que personne ne vient dire à
 * quelle heure la transfusion s'est terminée, ce que l'hémoglobine est
 * devenue et si le malade a frissonné, la poche reste un mouvement de stock
 * et non un acte de soin. L'accident transfusionnel, lui, se déclare dans les
 * quinze premières minutes : il faut un endroit pour l'écrire.
 *
 * Et puisque la poche coûte — prélèvement, dépistage, conservation, chaîne du
 * froid — elle se facture comme le reste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfusions', function (Blueprint $table) {
            // Une transfusion close est une transfusion dont on connaît la
            // fin. Tant que cette date est nulle, la poche coule encore.
            $table->timestamp('cloturee_le')->nullable()->after('hemoglobine_apres');
            $table->foreignUuid('facture_id')->nullable()->after('cloturee_le')
                ->constrained('factures')->nullOnDelete();
            $table->foreignUuid('cloturee_par')->nullable()->after('facture_id')
                ->constrained('users')->nullOnDelete();

            $table->index(['jour', 'incident']);
        });

        // Le sang devient une catégorie facturable à part entière : il ne se
        // confond ni avec un médicament ni avec un acte chirurgical, et la
        // convention doit pouvoir le couvrir séparément.
        DB::statement('ALTER TABLE lignes_facture DROP CONSTRAINT IF EXISTS lignes_facture_type_check');
        DB::statement(
            "ALTER TABLE lignes_facture ADD CONSTRAINT lignes_facture_type_check CHECK (type::text = ANY (ARRAY[
                'consultation','medicament','examen_labo','acte_chirurgical',
                'hospitalisation','imagerie','diete','dialyse','transfusion','autre'
            ]::text[]))"
        );

        DB::statement('ALTER TABLE assurance_couvertures DROP CONSTRAINT IF EXISTS assurance_couvertures_type_check');
        DB::statement(
            "ALTER TABLE assurance_couvertures ADD CONSTRAINT assurance_couvertures_type_check
             CHECK (type::text = ANY (ARRAY[
                'consultation','examen_labo','medicament','acte_chirurgical',
                'hospitalisation','imagerie','diete','dialyse','transfusion','autre'
             ]::text[]))"
        );
    }

    public function down(): void
    {
        Schema::table('transfusions', function (Blueprint $table) {
            $table->dropIndex(['jour', 'incident']);
            $table->dropConstrainedForeignId('facture_id');
            $table->dropConstrainedForeignId('cloturee_par');
            $table->dropColumn('cloturee_le');
        });

        DB::statement('ALTER TABLE lignes_facture DROP CONSTRAINT IF EXISTS lignes_facture_type_check');
        DB::statement(
            "ALTER TABLE lignes_facture ADD CONSTRAINT lignes_facture_type_check CHECK (type::text = ANY (ARRAY[
                'consultation','medicament','examen_labo','acte_chirurgical',
                'hospitalisation','imagerie','diete','dialyse','autre'
            ]::text[]))"
        );

        DB::statement('ALTER TABLE assurance_couvertures DROP CONSTRAINT IF EXISTS assurance_couvertures_type_check');
        DB::statement(
            "ALTER TABLE assurance_couvertures ADD CONSTRAINT assurance_couvertures_type_check
             CHECK (type::text = ANY (ARRAY[
                'consultation','examen_labo','medicament','acte_chirurgical',
                'hospitalisation','imagerie','diete','dialyse','autre'
             ]::text[]))"
        );
    }
};
