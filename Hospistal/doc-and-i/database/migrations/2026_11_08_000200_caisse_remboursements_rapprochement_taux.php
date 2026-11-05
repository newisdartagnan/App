<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lot caisse (PAY-01, PAY-06, PAY-07) : garanties complémentaires du socle.
 */
return new class extends Migration
{
    public function up(): void
    {
        // PAY-07 : la raison d'un geste commercial, écrite par l'agent qui le
        // demande et lue par celui qui le valide (le motif reste l'énumération).
        Schema::table('remboursements', function (Blueprint $table) {
            $table->string('justification', 255)->nullable()->after('motif');
        });

        // PAY-06 : un écart n'est créé qu'une fois, même si le rapprochement
        // d'un jour est relancé (traité ou non, il n'est pas recréé).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX ecarts_rapprochement_uniques ON ecarts_rapprochement (
                jour, fournisseur, nature,
                COALESCE(paiement_id::text, ''), COALESCE(remboursement_id::text, ''), COALESCE(reference_fournisseur, '')
            )
        SQL);

        // PAY-01 : un taux qui a servi à un paiement ne change plus (le montant
        // payé en dépend) ; il ne se supprime pas non plus.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION refuser_modification_taux_utilise() RETURNS trigger AS $$
            BEGIN
                IF EXISTS (SELECT 1 FROM paiements WHERE taux_change_id = OLD.id)
                   AND (TG_OP = 'DELETE' OR NEW.cdf_par_usd <> OLD.cdf_par_usd OR NEW.jour <> OLD.jour) THEN
                    RAISE EXCEPTION 'taux_change : taux déjà utilisé par un paiement, non modifiable';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER taux_change_utilise_non_modifiable BEFORE UPDATE OR DELETE ON taux_change
                FOR EACH ROW EXECUTE FUNCTION refuser_modification_taux_utilise();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS taux_change_utilise_non_modifiable ON taux_change');
        DB::unprepared('DROP FUNCTION IF EXISTS refuser_modification_taux_utilise()');
        DB::statement('DROP INDEX IF EXISTS ecarts_rapprochement_uniques');
        Schema::table('remboursements', function (Blueprint $table) {
            $table->dropColumn('justification');
        });
    }
};
