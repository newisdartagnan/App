<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le recouvrement des conventions : ce qui se passe après l'émission du
 * bordereau.
 *
 * Un bordereau émis n'est pas un bordereau payé. Il se dépose chez
 * l'assureur contre accusé de réception — c'est le dépôt qui fait courir
 * le délai de paiement. L'assureur le contrôle et rejette ce qu'il refuse,
 * avec un motif ; l'hôpital corrige, conteste ou abandonne. Puis il faut
 * relancer, par téléphone, par courrier, jusqu'à la mise en demeure.
 *
 * Rien de cela n'avait de place : le délai courait depuis l'émission, un
 * rejet se perdait dans un classeur, une relance dans un agenda. Ce sont
 * pourtant les chiffres que la direction demande : balance âgée, délai
 * moyen de paiement, taux de rejet par assureur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures_convention', function (Blueprint $table) {
            $table->date('date_depot')->nullable()->after('statut');
            $table->string('accuse_reception', 100)->nullable()->after('date_depot');
        });

        Schema::create('rejets_convention', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('facture_convention_id')->constrained('factures_convention')->cascadeOnDelete();
            $table->foreignUuid('ligne_facture_convention_id')->nullable()
                ->constrained('lignes_facture_convention')->nullOnDelete();
            $table->foreignUuid('facture_id')->nullable()->constrained('factures')->nullOnDelete();
            $table->string('motif_categorie', 30);
            $table->text('motif')->nullable();
            // Dans la devise du bordereau, comme ce que l'assureur doit.
            $table->decimal('montant', 12, 2);
            $table->date('date_rejet');
            // ouvert : à traiter ; conteste : l'hôpital le réfute ;
            // leve : l'assureur paiera finalement ; abandonne : perdu.
            $table->string('statut', 20)->default('ouvert');
            $table->text('suite_donnee')->nullable();
            $table->timestamp('traite_le')->nullable();
            $table->foreignUuid('traite_par')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['facture_convention_id', 'statut']);
        });

        Schema::create('relances_convention', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('facture_convention_id')->constrained('factures_convention')->cascadeOnDelete();
            $table->date('date_relance');
            $table->string('mode', 20);
            $table->text('commentaire')->nullable();
            $table->date('prochaine_action')->nullable();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['facture_convention_id', 'date_relance']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relances_convention');
        Schema::dropIfExists('rejets_convention');

        Schema::table('factures_convention', function (Blueprint $table) {
            $table->dropColumn(['date_depot', 'accuse_reception']);
        });
    }
};
