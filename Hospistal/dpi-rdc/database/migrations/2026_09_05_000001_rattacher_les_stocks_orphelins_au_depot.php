<?php

use App\Models\Medicament;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Répare ce que le formulaire du catalogue laissait derrière lui.
 *
 * Un produit ajouté depuis l'écran de la pharmacie recevait un stock rattaché
 * à aucune officine : invisible du tableau des officines, du dépôt central et
 * de toute réquisition. Il ne manquait pas — il n'appartenait à personne.
 *
 * Le même formulaire ne demandait ni la voie ni le conditionnement : le
 * médecin ne lisait donc pas « voie orale / plaquette de 10 » en prescrivant,
 * et la quantité ne se déduisait plus de la posologie. On les rétablit depuis
 * la forme galénique, qui les dit dans l'immense majorité des cas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $depot = DB::table('officines')
            ->where('type', 'depot_central')
            ->where('est_actif', true)
            ->value('id');

        if ($depot) {
            DB::table('stock_medicaments')->whereNull('officine_id')->update(['officine_id' => $depot]);
            DB::table('mouvements_stock')->whereNull('officine_id')->update(['officine_id' => $depot]);
        }

        foreach (Medicament::CONDITIONNEMENT_PAR_FORME as $forme => [$contenant, $unites]) {
            DB::table('medicaments')
                ->where('forme', $forme)
                ->where(fn ($q) => $q->whereNull('conditionnement')->orWhereNull('unites_par_conditionnement'))
                ->update([
                    'conditionnement' => $contenant,
                    'unites_par_conditionnement' => $unites,
                ]);

            DB::table('medicaments')
                ->where('forme', $forme)
                ->whereNull('voie_administration')
                ->update(['voie_administration' => Medicament::VOIE_PAR_FORME[$forme] ?? 'autre']);
        }

        // Une forme inattendue ne doit pas laisser un produit sans repère.
        DB::table('medicaments')->whereNull('conditionnement')->update([
            'conditionnement' => 'unite',
            'unites_par_conditionnement' => 1,
        ]);
        DB::table('medicaments')->whereNull('voie_administration')->update(['voie_administration' => 'autre']);
    }

    public function down(): void
    {
        // Rien à défaire : rattacher un stock à son dépôt et nommer la voie
        // d'un médicament sont des réparations, pas des choix de schéma.
    }
};
