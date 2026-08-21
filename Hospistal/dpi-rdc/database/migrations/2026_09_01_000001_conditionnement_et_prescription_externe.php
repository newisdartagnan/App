<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le médicament tel qu'il se prescrit et tel qu'il se délivre.
 *
 * Le médecin raisonne en prises : « un comprimé, trois fois par jour, cinq
 * jours ». La pharmacie délivre des plaquettes et des flacons. La caisse
 * facture ce qui sort du tiroir. Il manquait au produit ce qui relie les
 * trois : sa voie, son conditionnement et le nombre d'unités qu'il contient.
 *
 * Conséquence directe sur les prix : ce qui était enregistré comme « prix
 * unitaire » était en réalité le prix de la boîte. Un comprimé de paracétamol
 * était facturé 500 CDF alors que ce sont les dix comprimés de la plaquette
 * qui valent 500 CDF. Les prix sont ramenés à l'unité réelle.
 */
return new class extends Migration
{
    /**
     * Conditionnement usuel par forme galénique : nom du contenant et
     * nombre d'unités qu'il contient.
     */
    private const CONDITIONNEMENTS = [
        'comprime' => ['plaquette', 10],
        'gelule' => ['plaquette', 10],
        'suppositoire' => ['plaquette', 10],
        'sachet' => ['boite', 10],
        'sirop' => ['flacon', 1],
        'injectable' => ['flacon', 1],
        'collyre' => ['flacon', 1],
        'pommade' => ['tube', 1],
        'creme' => ['tube', 1],
        'autre' => ['unite', 1],
    ];

    /** Voie d'administration déduite de la forme. */
    private const VOIES = [
        'comprime' => 'orale',
        'gelule' => 'orale',
        'sirop' => 'orale',
        'sachet' => 'orale',
        'injectable' => 'injectable',
        'suppositoire' => 'rectale',
        'pommade' => 'cutanee',
        'creme' => 'cutanee',
        'collyre' => 'oculaire',
        'autre' => 'autre',
    ];

    public function up(): void
    {
        Schema::table('medicaments', function (Blueprint $table) {
            $table->string('voie_administration', 30)->default('orale');
            $table->string('conditionnement', 30)->default('unite');
            $table->unsignedInteger('unites_par_conditionnement')->default(1);
        });

        foreach (self::CONDITIONNEMENTS as $forme => [$conditionnement, $unites]) {
            DB::table('medicaments')->where('forme', $forme)->update([
                'conditionnement' => $conditionnement,
                'unites_par_conditionnement' => $unites,
                'voie_administration' => self::VOIES[$forme] ?? 'orale',
            ]);
        }

        // Le prix enregistré était celui du conditionnement : on le ramène à
        // l'unité délivrée. Une plaquette de dix comprimés à 500 CDF donne un
        // comprimé à 50 CDF. Les produits vendus à l'unité (flacon, tube) ne
        // bougent pas, leur conditionnement valant une unité.
        DB::statement('
            UPDATE stock_medicaments s
               SET prix_unitaire_vente = ROUND(s.prix_unitaire_vente / m.unites_par_conditionnement, 2),
                   prix_unitaire_achat = ROUND(s.prix_unitaire_achat / m.unites_par_conditionnement, 2)
              FROM medicaments m
             WHERE m.id = s.medicament_id
               AND m.unites_par_conditionnement > 1
        ');

        Schema::table('lignes_prescription', function (Blueprint $table) {
            // Une ligne externe ne désigne aucun produit du dépôt : elle porte
            // le nom écrit par le médecin, et rien d'autre.
            $table->uuid('medicament_id')->nullable()->change();
            $table->boolean('est_externe')->default(false)->index();
            $table->string('libelle_externe', 255)->nullable();

            // Ce qui sera réellement sorti du tiroir, et donc facturé :
            // quinze comprimés se délivrent en deux plaquettes de dix.
            $table->decimal('quantite_facturee', 10, 2)->default(0);
            $table->unsignedInteger('conditionnements')->default(0);
        });

        // La ligne d'ordonnance et le produit parlaient deux langues pour la
        // même chose : « injectable_iv » d'un côté, « injectable » de l'autre.
        // On accepte les deux vocabulaires, l'ancien restant valable pour les
        // ordonnances déjà saisies.
        DB::statement('ALTER TABLE lignes_prescription DROP CONSTRAINT IF EXISTS lignes_prescription_voie_administration_check');
        DB::statement("ALTER TABLE lignes_prescription ADD CONSTRAINT lignes_prescription_voie_administration_check
            CHECK (voie_administration IN ('orale', 'injectable', 'injectable_iv', 'injectable_im',
                                           'cutanee', 'topique', 'rectale', 'vaginale', 'oculaire',
                                           'ophtalmique', 'auriculaire', 'nasale', 'inhalee', 'autre'))");

        Schema::table('prescriptions', function (Blueprint $table) {
            // L'officine qui doit servir l'ordonnance, décidée par le lieu de
            // soins : ambulatoire, urgences, ou l'officine du service.
            $table->foreignUuid('officine_id')->nullable()->constrained('officines')->nullOnDelete();
        });

        // Reprise : les lignes déjà saisies sont facturées telles quelles,
        // sans arrondi rétroactif — leurs factures existent déjà.
        DB::statement('UPDATE lignes_prescription SET quantite_facturee = quantite_totale');
    }

    public function down(): void
    {
        DB::statement('
            UPDATE stock_medicaments s
               SET prix_unitaire_vente = ROUND(s.prix_unitaire_vente * m.unites_par_conditionnement, 2),
                   prix_unitaire_achat = ROUND(s.prix_unitaire_achat * m.unites_par_conditionnement, 2)
              FROM medicaments m
             WHERE m.id = s.medicament_id
               AND m.unites_par_conditionnement > 1
        ');

        DB::statement('ALTER TABLE lignes_prescription DROP CONSTRAINT IF EXISTS lignes_prescription_voie_administration_check');
        DB::statement("ALTER TABLE lignes_prescription ADD CONSTRAINT lignes_prescription_voie_administration_check
            CHECK (voie_administration IN ('orale', 'injectable_iv', 'injectable_im',
                                           'topique', 'rectale', 'ophtalmique', 'autre'))");

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('officine_id');
        });

        Schema::table('lignes_prescription', function (Blueprint $table) {
            $table->dropColumn(['est_externe', 'libelle_externe', 'quantite_facturee', 'conditionnements']);
        });

        Schema::table('medicaments', function (Blueprint $table) {
            $table->dropColumn(['voie_administration', 'conditionnement', 'unites_par_conditionnement']);
        });
    }
};
