<?php

namespace App\Services;

use App\Models\CommandeFournisseur;
use App\Models\DestructionStock;
use App\Models\InventaireStock;
use App\Models\LigneCommandeFournisseur;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Officine;
use App\Models\ReceptionStock;
use App\Models\StockMedicament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le grand dépôt : ce qu'il a, ce qu'il consomme, ce qu'il faut commander,
 * et les opérations qui font bouger son stock (réception, inventaire,
 * destruction) — chacune tracée dans les mouvements de stock.
 */
class GrandDepotService
{
    /** Couverture visée par une commande, en mois de consommation. */
    public const MOIS_COUVERTURE = 3;

    /** Péremption proche : à surveiller sous ce nombre de jours. */
    public const JOURS_PEREMPTION = 90;

    public function numero(string $prefixe, string $table): string
    {
        $annee = now()->format('Y');
        $n = DB::table($table)->where('numero', 'like', "{$prefixe}-{$annee}-%")->count() + 1;
        do {
            $numero = sprintf('%s-%s-%05d', $prefixe, $annee, $n++);
        } while (DB::table($table)->where('numero', $numero)->exists());

        return $numero;
    }

    /**
     * Situation de chaque produit au dépôt : stock, valeur, consommation
     * moyenne mensuelle (3 derniers mois), mois de stock, quantité déjà en
     * commande, statut et quantité à commander.
     */
    public function situation(Officine $depot, ?string $recherche = null): Collection
    {
        $stocks = StockMedicament::where('officine_id', $depot->id)
            ->selectRaw('medicament_id, SUM(quantite_disponible) AS stock, SUM(quantite_disponible * COALESCE(prix_unitaire_achat, 0)) AS valeur, MAX(quantite_alerte) AS seuil')
            ->groupBy('medicament_id')->get()->keyBy('medicament_id');

        $sorties = MouvementStock::where('officine_id', $depot->id)
            ->whereIn('type', ['transfert_sortie', 'sortie_dispensation'])
            ->where('created_at', '>=', now()->subMonths(3))
            ->selectRaw('medicament_id, SUM(ABS(quantite)) AS total')
            ->groupBy('medicament_id')->pluck('total', 'medicament_id');

        $enCommande = LigneCommandeFournisseur::query()
            ->whereHas('commande', fn ($q) => $q->where('officine_id', $depot->id)->whereIn('statut', ['brouillon', 'envoyee', 'partiellement_recue']))
            ->selectRaw('medicament_id, SUM(quantite_commandee - quantite_recue) AS reste')
            ->groupBy('medicament_id')->pluck('reste', 'medicament_id');

        return Medicament::where('est_actif', true)
            ->when($recherche, fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(denomination_commune) LIKE ?', ['%'.mb_strtolower($recherche).'%'])
                ->orWhereRaw('LOWER(COALESCE(nom_commercial, \'\')) LIKE ?', ['%'.mb_strtolower($recherche).'%'])))
            ->orderBy('denomination_commune')
            ->get()
            ->map(function (Medicament $m) use ($stocks, $sorties, $enCommande) {
                $stock = (float) ($stocks[$m->id]->stock ?? 0);
                $cmm = round((float) ($sorties[$m->id] ?? 0) / 3, 2);
                $commande = (float) ($enCommande[$m->id] ?? 0);
                $seuil = (float) ($stocks[$m->id]->seuil ?? 10);
                $moisStock = $cmm > 0 ? round($stock / $cmm, 1) : null;
                $aCommander = $cmm > 0
                    ? max(0, (int) ceil($cmm * self::MOIS_COUVERTURE - $stock - $commande))
                    : ($stock <= $seuil ? max(0, (int) ceil($seuil * 2 - $stock - $commande)) : 0);

                $statut = match (true) {
                    $stock <= 0 => 'rupture',
                    $stock <= $seuil || ($moisStock !== null && $moisStock < 1) => 'alerte',
                    $moisStock !== null && $moisStock > 6 => 'surstock',
                    default => 'normal',
                };

                return (object) [
                    'medicament' => $m,
                    'stock' => $stock,
                    'valeur' => (float) ($stocks[$m->id]->valeur ?? 0),
                    'cmm' => $cmm,
                    'mois_stock' => $moisStock,
                    'en_commande' => $commande,
                    'seuil' => $seuil,
                    'a_commander' => $aCommander,
                    'statut' => $statut,
                ];
            });
    }

    /** Lots périmés ou qui périment sous 90 jours, les plus urgents d'abord. */
    public function peremptions(Officine $depot): Collection
    {
        return StockMedicament::with('medicament')
            ->where('officine_id', $depot->id)
            ->where('quantite_disponible', '>', 0)
            ->whereNotNull('date_peremption')
            ->whereDate('date_peremption', '<=', now()->addDays(self::JOURS_PEREMPTION))
            ->orderBy('date_peremption')
            ->get();
    }

    /**
     * Réception de produits au dépôt, sur commande ou non (don, achat direct).
     *
     * @param  array<int, array{medicament_id: string, quantite: float, lot?: ?string, date_peremption?: ?string, prix_unitaire_achat?: ?float, prix_unitaire_vente?: ?float, ligne_commande_id?: ?string}>  $lignes
     */
    public function recevoir(Officine $depot, array $entete, array $lignes, ?CommandeFournisseur $commande = null): ReceptionStock
    {
        $lignes = array_values(array_filter($lignes, fn ($l) => ! empty($l['medicament_id']) && (float) ($l['quantite'] ?? 0) > 0));
        if ($lignes === []) {
            throw new \InvalidArgumentException('Aucune quantité reçue.');
        }

        return DB::transaction(function () use ($depot, $entete, $lignes, $commande) {
            $reception = ReceptionStock::create([
                'numero' => $this->numero('REC', 'receptions_stock'),
                'commande_id' => $commande?->id,
                'fournisseur_id' => $commande?->fournisseur_id ?? ($entete['fournisseur_id'] ?? null),
                'officine_id' => $depot->id,
                'origine' => $entete['origine'] ?? 'achat',
                'date_reception' => $entete['date_reception'] ?? today(),
                'numero_bon_livraison' => $entete['numero_bon_livraison'] ?? null,
                'numero_facture_fournisseur' => $entete['numero_facture_fournisseur'] ?? null,
                'devise' => $commande?->devise ?? ($entete['devise'] ?? 'CDF'),
                'observations' => $entete['observations'] ?? null,
                'user_id' => auth()->id(),
            ]);

            $total = 0.0;
            foreach ($lignes as $l) {
                $quantite = (float) $l['quantite'];
                $prixAchat = isset($l['prix_unitaire_achat']) && $l['prix_unitaire_achat'] !== '' ? (float) $l['prix_unitaire_achat'] : null;
                $ligneCommande = ! empty($l['ligne_commande_id'])
                    ? LigneCommandeFournisseur::where('commande_id', $commande?->id)->find($l['ligne_commande_id'])
                    : null;
                $prixAchat ??= $ligneCommande?->prix_unitaire !== null ? (float) $ligneCommande->prix_unitaire : null;

                $reception->lignes()->create([
                    'ligne_commande_id' => $ligneCommande?->id,
                    'medicament_id' => $l['medicament_id'],
                    'quantite' => $quantite,
                    'lot' => $l['lot'] ?? null,
                    'date_peremption' => $l['date_peremption'] ?? null,
                    'prix_unitaire_achat' => $prixAchat,
                    'prix_unitaire_vente' => $l['prix_unitaire_vente'] ?? null,
                ]);

                $this->entrerEnStock($depot, $l['medicament_id'], $quantite, $l['lot'] ?? null, $l['date_peremption'] ?? null,
                    $prixAchat, isset($l['prix_unitaire_vente']) && $l['prix_unitaire_vente'] !== '' ? (float) $l['prix_unitaire_vente'] : null,
                    $reception->numero, $reception->fournisseur?->nom ?? ReceptionStock::ORIGINES[$reception->origine]);

                $ligneCommande?->increment('quantite_recue', $quantite);
                $total += $quantite * (float) ($prixAchat ?? 0);
            }

            $reception->update(['montant_total' => round($total, 2)]);

            if ($commande) {
                $commande->load('lignes');
                $complete = $commande->lignes->every(fn ($lc) => $lc->resteARecevoir() <= 0.0001);
                $commande->update(['statut' => $complete ? 'recue' : 'partiellement_recue']);
            }

            return $reception->fresh(['lignes.medicament', 'fournisseur']);
        });
    }

    /** Entrée d'un lot au dépôt, avec son mouvement de stock. */
    public function entrerEnStock(Officine $depot, string $medicamentId, float $quantite, ?string $lot, ?string $peremption,
        ?float $prixAchat, ?float $prixVente, ?string $reference, ?string $provenance): void
    {
        $stock = StockMedicament::firstOrCreate(
            ['officine_id' => $depot->id, 'medicament_id' => $medicamentId, 'lot' => $lot],
            [
                'establishment_id' => auth()->user()?->establishment_id ?? \App\Models\Establishment::value('id'),
                'quantite_disponible' => 0,
                'quantite_alerte' => 10,
                'date_peremption' => $peremption,
            ]
        );

        $avant = (float) $stock->quantite_disponible;
        $stock->quantite_disponible = $avant + $quantite;
        if ($prixAchat !== null) {
            $stock->prix_unitaire_achat = $prixAchat;
        }
        if ($prixVente !== null) {
            $stock->prix_unitaire_vente = $prixVente;
        }
        if ($peremption && ! $stock->date_peremption) {
            $stock->date_peremption = $peremption;
        }
        $stock->save();

        MouvementStock::create([
            'medicament_id' => $medicamentId,
            'establishment_id' => $stock->establishment_id,
            'officine_id' => $depot->id,
            'user_id' => auth()->id(),
            'type' => 'entree',
            'quantite' => $quantite,
            'quantite_avant' => $avant,
            'quantite_apres' => $avant + $quantite,
            'reference' => $reference,
            'provenance' => $provenance,
            'created_at' => now(),
        ]);
    }

    /** Ouvre un inventaire : une ligne par lot du dépôt, quantité théorique figée. */
    public function demarrerInventaire(Officine $depot, ?string $observations = null): InventaireStock
    {
        if (InventaireStock::where('officine_id', $depot->id)->where('statut', 'en_cours')->exists()) {
            throw new \RuntimeException('Un inventaire est déjà en cours pour ce dépôt.');
        }

        return DB::transaction(function () use ($depot, $observations) {
            $inventaire = InventaireStock::create([
                'numero' => $this->numero('INV', 'inventaires_stock'),
                'officine_id' => $depot->id,
                'statut' => 'en_cours',
                'date_inventaire' => today(),
                'observations' => $observations,
                'user_id' => auth()->id(),
            ]);

            StockMedicament::where('officine_id', $depot->id)
                ->with('medicament')
                ->get()
                ->each(fn (StockMedicament $s) => $inventaire->lignes()->create([
                    'stock_medicament_id' => $s->id,
                    'medicament_id' => $s->medicament_id,
                    'lot' => $s->lot,
                    'date_peremption' => $s->date_peremption,
                    'quantite_theorique' => (float) $s->quantite_disponible,
                ]));

            return $inventaire;
        });
    }

    /**
     * Valide l'inventaire : chaque écart devient un ajustement de stock,
     * justifié. Une ligne non comptée garde la quantité théorique.
     */
    public function validerInventaire(InventaireStock $inventaire): int
    {
        if ($inventaire->statut !== 'en_cours') {
            throw new \RuntimeException('Cet inventaire est déjà clos.');
        }

        return DB::transaction(function () use ($inventaire) {
            $ajustements = 0;
            foreach ($inventaire->lignes()->get() as $ligne) {
                $ecart = $ligne->ecart();
                if ($ecart === null || abs($ecart) < 0.0001 || ! $ligne->stock_medicament_id) {
                    continue;
                }
                $stock = StockMedicament::lockForUpdate()->find($ligne->stock_medicament_id);
                if (! $stock) {
                    continue;
                }
                $avant = (float) $stock->quantite_disponible;
                $apres = max(0, $avant + $ecart);
                $stock->update(['quantite_disponible' => $apres]);

                MouvementStock::create([
                    'medicament_id' => $stock->medicament_id,
                    'establishment_id' => $stock->establishment_id,
                    'officine_id' => $inventaire->officine_id,
                    'user_id' => auth()->id(),
                    'type' => 'ajustement_inventaire',
                    'quantite' => abs($apres - $avant),
                    'quantite_avant' => $avant,
                    'quantite_apres' => $apres,
                    'reference' => $inventaire->numero.($ligne->motif_ecart ? ' — '.$ligne->motif_ecart : ''),
                    'created_at' => now(),
                ]);
                $ajustements++;
            }

            $inventaire->update(['statut' => 'valide', 'valide_par' => auth()->id(), 'valide_at' => now()]);

            return $ajustements;
        });
    }

    /**
     * Procès-verbal de destruction : les quantités sortent du stock en
     * « sortie péremption », la valeur détruite est chiffrée au prix d'achat.
     *
     * @param  array<string, float>  $quantites  stock_medicament_id => quantité
     */
    public function detruire(Officine $depot, array $quantites, string $motif, ?string $temoins = null): DestructionStock
    {
        $quantites = array_filter($quantites, fn ($q) => (float) $q > 0);
        if ($quantites === []) {
            throw new \InvalidArgumentException('Aucune quantité à détruire.');
        }

        return DB::transaction(function () use ($depot, $quantites, $motif, $temoins) {
            $pv = DestructionStock::create([
                'numero' => $this->numero('PVD', 'destructions_stock'),
                'officine_id' => $depot->id,
                'date_destruction' => today(),
                'motif' => $motif,
                'temoins' => $temoins,
                'user_id' => auth()->id(),
            ]);

            $valeur = 0.0;
            foreach ($quantites as $stockId => $quantite) {
                $stock = StockMedicament::where('officine_id', $depot->id)->lockForUpdate()->findOrFail($stockId);
                $quantite = min((float) $quantite, (float) $stock->quantite_disponible);
                if ($quantite <= 0) {
                    continue;
                }
                $avant = (float) $stock->quantite_disponible;
                $stock->update(['quantite_disponible' => $avant - $quantite]);

                $pv->lignes()->create([
                    'medicament_id' => $stock->medicament_id,
                    'lot' => $stock->lot,
                    'date_peremption' => $stock->date_peremption,
                    'quantite' => $quantite,
                    'prix_unitaire_achat' => $stock->prix_unitaire_achat,
                ]);

                MouvementStock::create([
                    'medicament_id' => $stock->medicament_id,
                    'establishment_id' => $stock->establishment_id,
                    'officine_id' => $depot->id,
                    'user_id' => auth()->id(),
                    'type' => 'sortie_peremption',
                    'quantite' => $quantite,
                    'quantite_avant' => $avant,
                    'quantite_apres' => $avant - $quantite,
                    'reference' => $pv->numero,
                    'destination' => 'Destruction',
                    'created_at' => now(),
                ]);
                $valeur += $quantite * (float) ($stock->prix_unitaire_achat ?? 0);
            }

            $pv->update(['valeur_totale' => round($valeur, 2)]);

            return $pv->fresh('lignes.medicament');
        });
    }

    /** Fiche de stock (kardex) d'un produit au dépôt : chaque mouvement et le solde. */
    public function ficheStock(Officine $depot, Medicament $medicament, string $du, string $au): Collection
    {
        return MouvementStock::with('user')
            ->where('officine_id', $depot->id)
            ->where('medicament_id', $medicament->id)
            ->whereBetween('created_at', [$du.' 00:00:00', $au.' 23:59:59'])
            ->orderBy('created_at')
            ->get();
    }
}
