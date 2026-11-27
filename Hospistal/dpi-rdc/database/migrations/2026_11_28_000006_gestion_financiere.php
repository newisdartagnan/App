<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gestion financière de l'établissement.
 *
 * La caisse savait encaisser ; l'hôpital ne savait pas ce qu'il dépensait.
 * Comptes de trésorerie (caisse, banque, mobile money), dépenses avec
 * circuit saisie → approbation → paiement, transferts entre comptes
 * (versement de la caisse à la banque), budget annuel par rubrique, et
 * clôture mensuelle qui fige une période contrôlée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comptes_tresorerie', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 150);
            $table->enum('type', ['caisse', 'banque', 'mobile_money']);
            $table->string('devise', 3)->default('CDF');
            $table->string('numero', 100)->nullable();
            // Les encaissements de ces modes de paiement arrivent sur ce compte.
            $table->jsonb('modes_paiement')->default('[]');
            $table->decimal('solde_initial', 16, 2)->default(0);
            $table->date('date_solde_initial')->nullable();
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
        });

        Schema::create('rubriques_depense', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('libelle', 150);
            $table->enum('nature', ['personnel', 'achats_medicaux', 'fonctionnement', 'entretien', 'investissement', 'autre'])->default('fonctionnement');
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
        });

        Schema::create('depenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 40)->unique();
            $table->date('date_depense');
            $table->foreignUuid('rubrique_id')->constrained('rubriques_depense');
            $table->foreignUuid('compte_tresorerie_id')->nullable()->constrained('comptes_tresorerie')->nullOnDelete();
            $table->foreignUuid('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignUuid('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            $table->foreignUuid('reception_id')->nullable()->constrained('receptions_stock')->nullOnDelete();
            $table->string('beneficiaire', 200)->nullable();
            $table->string('libelle', 255);
            $table->decimal('montant', 16, 2);
            $table->string('devise', 3)->default('CDF');
            $table->decimal('taux_change', 12, 4)->default(1);
            $table->decimal('montant_cdf', 16, 2);
            $table->string('mode_paiement', 30)->nullable();
            $table->string('reference_piece', 100)->nullable();
            $table->string('piece_path')->nullable();
            $table->enum('statut', ['en_attente', 'approuvee', 'payee', 'rejetee'])->default('en_attente');
            $table->text('motif_rejet')->nullable();
            $table->foreignUuid('saisie_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approuvee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approuvee_at')->nullable();
            $table->foreignUuid('payee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('payee_at')->nullable();
            $table->timestamps();
            $table->index(['statut', 'date_depense']);
        });

        Schema::create('transferts_tresorerie', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 40)->unique();
            $table->date('date_transfert');
            $table->foreignUuid('compte_source_id')->constrained('comptes_tresorerie');
            $table->foreignUuid('compte_destination_id')->constrained('comptes_tresorerie');
            $table->decimal('montant_cdf', 16, 2);
            $table->string('reference', 100)->nullable();
            $table->text('observations')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedSmallInteger('annee');
            $table->foreignUuid('rubrique_id')->constrained('rubriques_depense')->cascadeOnDelete();
            $table->decimal('montant_prevu_cdf', 16, 2)->default(0);
            $table->timestamps();
            $table->unique(['annee', 'rubrique_id']);
        });

        Schema::create('clotures_financieres', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mois', 7)->unique(); // AAAA-MM
            $table->decimal('total_recettes_cdf', 16, 2)->default(0);
            $table->decimal('total_depenses_cdf', 16, 2)->default(0);
            $table->text('observations')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Rubriques de départ, à compléter par l'établissement.
        $rubriques = [
            ['SAL', 'Salaires et primes du personnel', 'personnel'],
            ['MED', 'Achats de médicaments et intrants', 'achats_medicaux'],
            ['LAB', 'Réactifs et consommables de laboratoire', 'achats_medicaux'],
            ['ENE', 'Énergie (électricité, carburant groupe)', 'fonctionnement'],
            ['EAU', 'Eau', 'fonctionnement'],
            ['COM', 'Communication et internet', 'fonctionnement'],
            ['FOU', 'Fournitures de bureau', 'fonctionnement'],
            ['ENT', 'Entretien et réparations', 'entretien'],
            ['TRA', 'Transport et déplacements', 'fonctionnement'],
            ['INV', 'Équipements et investissements', 'investissement'],
            ['DIV', 'Dépenses diverses', 'autre'],
        ];
        foreach ($rubriques as [$code, $libelle, $nature]) {
            DB::table('rubriques_depense')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'code' => $code, 'libelle' => $libelle,
                'nature' => $nature, 'est_actif' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $comptes = [
            ['Caisse principale', 'caisse', ['especes']],
            ['Mobile Money (M-Pesa / Airtel Money)', 'mobile_money', ['mobile_money']],
            ['Banque', 'banque', ['virement', 'cheque', 'carte', 'autre']],
        ];
        foreach ($comptes as [$nom, $type, $modes]) {
            DB::table('comptes_tresorerie')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'nom' => $nom, 'type' => $type, 'devise' => 'CDF',
                'modes_paiement' => json_encode($modes), 'solde_initial' => 0, 'est_actif' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clotures_financieres');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('transferts_tresorerie');
        Schema::dropIfExists('depenses');
        Schema::dropIfExists('rubriques_depense');
        Schema::dropIfExists('comptes_tresorerie');
    }
};
