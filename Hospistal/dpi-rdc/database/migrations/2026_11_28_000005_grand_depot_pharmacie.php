<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le grand dépôt pharmaceutique.
 *
 * Fournisseurs, bons de commande, réceptions (avec lot, péremption et prix
 * d'achat, partielles s'il le faut), inventaires physiques avec écarts
 * justifiés, procès-verbaux de destruction des périmés. Le stock reste
 * celui des officines (stock_medicaments, par lot) : ces tables tracent ce
 * qui l'alimente et ce qui le corrige.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nom', 200);
            $table->string('contact', 150)->nullable();
            $table->string('telephone', 50)->nullable();
            $table->string('email', 150)->nullable();
            $table->text('adresse')->nullable();
            $table->string('identifiant_fiscal', 100)->nullable();
            $table->unsignedSmallInteger('delai_livraison_jours')->nullable();
            $table->boolean('est_actif')->default(true);
            $table->timestamps();
        });

        Schema::create('commandes_fournisseur', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 40)->unique();
            $table->foreignUuid('fournisseur_id')->constrained('fournisseurs');
            $table->foreignUuid('officine_id')->constrained('officines');
            $table->enum('statut', ['brouillon', 'envoyee', 'partiellement_recue', 'recue', 'annulee'])->default('brouillon');
            $table->date('date_commande');
            $table->date('date_livraison_prevue')->nullable();
            $table->string('devise', 3)->default('CDF');
            $table->text('observations')->nullable();
            $table->foreignUuid('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('envoyee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('envoyee_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lignes_commande_fournisseur', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('commande_id')->constrained('commandes_fournisseur')->cascadeOnDelete();
            $table->foreignUuid('medicament_id')->constrained('medicaments');
            $table->decimal('quantite_commandee', 12, 2);
            $table->decimal('quantite_recue', 12, 2)->default(0);
            $table->decimal('prix_unitaire', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('receptions_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 40)->unique();
            $table->foreignUuid('commande_id')->nullable()->constrained('commandes_fournisseur')->nullOnDelete();
            $table->foreignUuid('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            $table->foreignUuid('officine_id')->constrained('officines');
            $table->enum('origine', ['achat', 'don', 'transfert_externe', 'retour'])->default('achat');
            $table->date('date_reception');
            $table->string('numero_bon_livraison', 100)->nullable();
            $table->string('numero_facture_fournisseur', 100)->nullable();
            $table->string('devise', 3)->default('CDF');
            $table->decimal('montant_total', 14, 2)->default(0);
            $table->decimal('montant_paye', 14, 2)->default(0);
            $table->text('observations')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lignes_reception_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reception_id')->constrained('receptions_stock')->cascadeOnDelete();
            $table->foreignUuid('ligne_commande_id')->nullable()->constrained('lignes_commande_fournisseur')->nullOnDelete();
            $table->foreignUuid('medicament_id')->constrained('medicaments');
            $table->decimal('quantite', 12, 2);
            $table->string('lot', 100)->nullable();
            $table->date('date_peremption')->nullable();
            $table->decimal('prix_unitaire_achat', 12, 2)->nullable();
            $table->decimal('prix_unitaire_vente', 12, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('inventaires_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 40)->unique();
            $table->foreignUuid('officine_id')->constrained('officines');
            $table->enum('statut', ['en_cours', 'valide', 'annule'])->default('en_cours');
            $table->date('date_inventaire');
            $table->text('observations')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('valide_at')->nullable();
            $table->timestamps();
        });

        Schema::create('lignes_inventaire_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventaire_id')->constrained('inventaires_stock')->cascadeOnDelete();
            $table->foreignUuid('stock_medicament_id')->nullable()->constrained('stock_medicaments')->nullOnDelete();
            $table->foreignUuid('medicament_id')->constrained('medicaments');
            $table->string('lot', 100)->nullable();
            $table->date('date_peremption')->nullable();
            $table->decimal('quantite_theorique', 12, 2)->default(0);
            $table->decimal('quantite_comptee', 12, 2)->nullable();
            $table->string('motif_ecart', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('destructions_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 40)->unique();
            $table->foreignUuid('officine_id')->constrained('officines');
            $table->date('date_destruction');
            $table->string('motif', 255);
            $table->text('temoins')->nullable();
            $table->decimal('valeur_totale', 14, 2)->default(0);
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('lignes_destruction_stock', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('destruction_id')->constrained('destructions_stock')->cascadeOnDelete();
            $table->foreignUuid('medicament_id')->constrained('medicaments');
            $table->string('lot', 100)->nullable();
            $table->date('date_peremption')->nullable();
            $table->decimal('quantite', 12, 2);
            $table->decimal('prix_unitaire_achat', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_destruction_stock');
        Schema::dropIfExists('destructions_stock');
        Schema::dropIfExists('lignes_inventaire_stock');
        Schema::dropIfExists('inventaires_stock');
        Schema::dropIfExists('lignes_reception_stock');
        Schema::dropIfExists('receptions_stock');
        Schema::dropIfExists('lignes_commande_fournisseur');
        Schema::dropIfExists('commandes_fournisseur');
        Schema::dropIfExists('fournisseurs');
    }
};
