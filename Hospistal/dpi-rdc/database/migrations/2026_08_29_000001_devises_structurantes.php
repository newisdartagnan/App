<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * La devise était décorative : stockée sur les acomptes sans jamais être
     * lue, absente des paiements et des factures. Un acompte de 100 $ était
     * donc imputé comme 100 CDF.
     *
     * Chaque écriture monétaire porte désormais trois colonnes : la devise
     * saisie, le taux appliqué au moment de l'opération, et la contre-valeur
     * en francs congolais. Figer le taux évite qu'une révision du change
     * réécrive le passé.
     */
    public function up(): void
    {
        $tauxUsd = (float) config('dpi.devises.USD.taux_cdf', 2300);

        // ── Paiements : la devise n'était même pas enregistrée ──────────
        Schema::table('paiements', function (Blueprint $table) {
            $table->string('devise', 5)->default('CDF')->after('montant');
            $table->decimal('taux_change', 12, 4)->default(1)->after('devise');
            $table->decimal('montant_cdf', 14, 2)->default(0)->after('taux_change');
        });

        DB::statement('UPDATE paiements SET montant_cdf = montant WHERE montant_cdf = 0');

        // ── Factures : tout était implicitement en francs ───────────────
        Schema::table('factures', function (Blueprint $table) {
            $table->string('devise', 5)->default('CDF')->after('type_prise_en_charge');
            $table->decimal('taux_change', 12, 4)->default(1)->after('devise');
        });

        // ── Acomptes : la devise était stockée mais jamais appliquée ────
        Schema::table('cautions', function (Blueprint $table) {
            $table->decimal('taux_change', 12, 4)->default(1)->after('devise');
            $table->decimal('montant_cdf', 14, 2)->default(0)->after('taux_change');
            $table->decimal('montant_impute_cdf', 14, 2)->default(0)->after('montant_impute');
            $table->decimal('montant_rembourse_cdf', 14, 2)->default(0)->after('montant_rembourse');
        });

        // Reprise : les acomptes déjà saisis en dollars valaient bien des
        // dollars, même si l'application les comptait comme des francs.
        DB::update(
            'UPDATE cautions SET taux_change = ?, montant_cdf = ROUND(montant * ?, 2),
                 montant_impute_cdf = ROUND(montant_impute * ?, 2),
                 montant_rembourse_cdf = ROUND(montant_rembourse * ?, 2)
             WHERE devise = ?',
            [$tauxUsd, $tauxUsd, $tauxUsd, $tauxUsd, 'USD']
        );

        DB::statement(
            "UPDATE cautions SET montant_cdf = montant,
                 montant_impute_cdf = montant_impute,
                 montant_rembourse_cdf = montant_rembourse
             WHERE devise = 'CDF'"
        );

        // ── Imputations : montant dans la devise de la facture + pivot ──
        Schema::table('imputations_acompte', function (Blueprint $table) {
            $table->string('devise', 5)->default('CDF')->after('montant');
            $table->decimal('taux_change', 12, 4)->default(1)->after('devise');
            $table->decimal('montant_cdf', 14, 2)->default(0)->after('taux_change');
            // Ce que l'imputation a réellement prélevé sur l'acompte, dans la
            // devise de celui-ci : un acompte en dollars se vide en dollars.
            $table->decimal('montant_acompte', 14, 2)->default(0)->after('montant_cdf');
        });

        DB::statement(
            'UPDATE imputations_acompte SET montant_cdf = montant, montant_acompte = montant
             WHERE montant_cdf = 0'
        );

        // ── Billetage : l'euro rejoint les devises comptées au guichet ──
        DB::statement('ALTER TABLE billetages DROP CONSTRAINT IF EXISTS billetages_devise_check');
        DB::statement(
            "ALTER TABLE billetages ADD CONSTRAINT billetages_devise_check
             CHECK (devise::text = ANY (ARRAY['CDF','USD','EUR']::text[]))"
        );

        // Les coupures de 1 à 20 CDF ne circulent plus : un comptage qui en
        // contenait est ramené aux billets réellement manipulés.
        foreach (DB::table('billetages')->where('devise', 'CDF')->get() as $billetage) {
            $coupures = json_decode($billetage->coupures ?? '{}', true) ?: [];
            $retenues = array_filter(
                $coupures,
                fn ($n, $valeur) => (int) $valeur >= 50,
                ARRAY_FILTER_USE_BOTH
            );

            if (count($retenues) !== count($coupures)) {
                DB::table('billetages')->where('id', $billetage->id)
                    ->update(['coupures' => json_encode($retenues)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('imputations_acompte', function (Blueprint $table) {
            $table->dropColumn(['devise', 'taux_change', 'montant_cdf', 'montant_acompte']);
        });

        Schema::table('cautions', function (Blueprint $table) {
            $table->dropColumn(['taux_change', 'montant_cdf', 'montant_impute_cdf', 'montant_rembourse_cdf']);
        });

        Schema::table('factures', function (Blueprint $table) {
            $table->dropColumn(['devise', 'taux_change']);
        });

        Schema::table('paiements', function (Blueprint $table) {
            $table->dropColumn(['devise', 'taux_change', 'montant_cdf']);
        });

        DB::statement('ALTER TABLE billetages DROP CONSTRAINT IF EXISTS billetages_devise_check');
        DB::statement(
            "ALTER TABLE billetages ADD CONSTRAINT billetages_devise_check
             CHECK (devise::text = ANY (ARRAY['CDF','USD']::text[]))"
        );
    }
};
