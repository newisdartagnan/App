<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce qui conditionne le tiers payant : un contrat en cours, un bon de prise
 * en charge valide, et le prix que la convention a négocié.
 *
 * Jusqu'ici, il suffisait qu'un patient soit lié à une société pour que
 * l'hôpital facture la part assureur — même après la fin de la convention,
 * même sans le bon que l'employeur exige pour chaque passage. L'assureur
 * rejetait ensuite la facture, des mois plus tard, et la créance était
 * perdue. Le contrôle se fait désormais au guichet, au moment où l'on peut
 * encore demander le bon ou faire payer le patient.
 *
 * - La convention a une date de début et de fin. Hors de ces dates, le
 *   tiers payant ne s'applique pas.
 * - Elle peut exiger un bon de prise en charge (BPC) : un numéro délivré
 *   par l'employeur ou l'assureur, valable jusqu'à une date, parfois pour
 *   un montant. Sans bon valide, pas de tiers payant.
 * - Elle peut avoir son propre tarif : un coefficient sur le prix public
 *   (« tarif SNEL = public + 20 % »), et des prix fixés pour certaines
 *   prestations (la journée d'hospitalisation, une consultation, un examen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assurances', function (Blueprint $table) {
            $table->date('contrat_debut')->nullable()->after('periodicite_facturation');
            $table->date('contrat_fin')->nullable()->after('contrat_debut');
            $table->boolean('exige_bon_prise_en_charge')->default(false)->after('contrat_fin');
            // 1 = prix public ; 1,2 = prix public majoré de 20 %.
            $table->decimal('coefficient_tarif', 6, 4)->default(1)->after('exige_bon_prise_en_charge');
        });

        Schema::create('bons_prise_en_charge', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('numero', 60);
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('assurance_id')->constrained('assurances')->cascadeOnDelete();
            $table->foreignUuid('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->date('date_emission');
            $table->date('date_expiration');
            // Montant que l'employeur accepte de couvrir ; sans montant, le bon
            // ne limite que la durée.
            $table->decimal('montant_autorise', 12, 2)->nullable();
            $table->string('devise', 5)->default('CDF');
            $table->decimal('montant_consomme', 12, 2)->default(0);
            $table->string('objet', 255)->nullable();
            $table->string('statut', 20)->default('valide');
            $table->foreignUuid('saisi_par')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['assurance_id', 'numero']);
            $table->index(['patient_id', 'assurance_id', 'date_expiration']);
        });

        Schema::table('factures', function (Blueprint $table) {
            $table->foreignUuid('bon_prise_en_charge_id')->nullable()->after('assurance_numero')
                ->constrained('bons_prise_en_charge')->nullOnDelete();
        });

        Schema::create('tarifs_convention', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('assurance_id')->constrained('assurances')->cascadeOnDelete();
            $table->string('type', 30);
            // Null : le prix vaut pour toute la catégorie (la journée
            // d'hospitalisation, par exemple).
            $table->uuid('reference_id')->nullable();
            $table->string('reference_libelle')->nullable();
            $table->decimal('prix', 12, 2);
            $table->timestamps();

            $table->unique(['assurance_id', 'type', 'reference_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tarifs_convention');

        Schema::table('factures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bon_prise_en_charge_id');
        });

        Schema::dropIfExists('bons_prise_en_charge');

        Schema::table('assurances', function (Blueprint $table) {
            $table->dropColumn(['contrat_debut', 'contrat_fin', 'exige_bon_prise_en_charge', 'coefficient_tarif']);
        });
    }
};
