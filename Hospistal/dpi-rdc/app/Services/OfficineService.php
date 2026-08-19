<?php

namespace App\Services;

use App\Models\LigneRequisition;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Officine;
use App\Models\Requisition;
use App\Models\StockMedicament;
use Illuminate\Support\Facades\DB;

/**
 * Circuit pharmaceutique à deux niveaux : un dépôt central approvisionne
 * plusieurs officines (ambulatoire, hospitalisation, services), qui délivrent
 * aux patients. Les officines s'approvisionnent par réquisition.
 */
class OfficineService
{
    public const CLE_SESSION = 'officine_active';

    /**
     * Officine sur laquelle l'utilisateur travaille. Le choix est un préalable
     * explicite : sans officine sélectionnée, aucun stock n'est affiché.
     */
    public function officineActive(): ?Officine
    {
        $id = session(self::CLE_SESSION);

        return $id ? Officine::find($id) : null;
    }

    public function definirOfficineActive(Officine $officine): void
    {
        session([self::CLE_SESSION => $officine->id]);
    }

    public function depotCentral(): ?Officine
    {
        return Officine::where('type', 'depot_central')->where('est_actif', true)->first();
    }

    /**
     * Stock d'une officine, enrichi des alertes de seuil et de péremption.
     */
    public function stock(Officine $officine, ?string $recherche = null)
    {
        return StockMedicament::with('medicament')
            ->where('officine_id', $officine->id)
            ->when($recherche, fn ($q) => $q->whereHas(
                'medicament',
                fn ($m) => $m->where('denomination_commune', 'ilike', "%{$recherche}%")
                    ->orWhere('nom_commercial', 'ilike', "%{$recherche}%")
            ))
            ->get()
            ->sortBy(fn ($s) => $s->medicament->denomination_commune)
            ->values();
    }

    /**
     * Une officine demande des produits au dépôt central.
     *
     * @param  array<string, float>  $quantites  [medicament_id => quantité]
     */
    public function creerRequisition(Officine $officine, array $quantites, ?string $motif = null): Requisition
    {
        $lignes = collect($quantites)->filter(fn ($q) => (float) $q > 0);

        if ($lignes->isEmpty()) {
            throw new \RuntimeException('Indiquez au moins une quantité à demander.');
        }

        return DB::transaction(function () use ($officine, $lignes, $motif) {
            $requisition = Requisition::create([
                'numero' => Requisition::genererNumero(),
                'officine_id' => $officine->id,
                'source_id' => $this->depotCentral()?->id,
                'demandeur_id' => auth()->id(),
                'statut' => 'envoyee',
                'motif' => $motif,
                'date_demande' => now(),
            ]);

            foreach ($lignes as $medicamentId => $quantite) {
                LigneRequisition::create([
                    'requisition_id' => $requisition->id,
                    'medicament_id' => $medicamentId,
                    'quantite_demandee' => (float) $quantite,
                ]);
            }

            return $requisition->fresh('lignes');
        });
    }

    /**
     * Le dépôt sert une réquisition : le stock quitte le dépôt et entre dans
     * l'officine demandeuse. Le service peut être partiel, ligne par ligne.
     *
     * @param  array<string, float>  $servies  [ligne_requisition_id => quantité servie]
     * @return array<int, string> erreurs (vide si le service a eu lieu)
     */
    public function servirRequisition(Requisition $requisition, array $servies): array
    {
        $requisition->load('lignes.medicament', 'officine');
        $depot = $requisition->source ?: $this->depotCentral();

        if (! $depot) {
            return ['Aucun dépôt central n\'est configuré.'];
        }
        if ($requisition->statut === 'servie') {
            return ['Cette réquisition est déjà entièrement servie.'];
        }

        // Contrôle du stock du dépôt avant toute écriture
        $erreurs = [];
        foreach ($requisition->lignes as $ligne) {
            $qte = (float) ($servies[$ligne->id] ?? 0);
            if ($qte <= 0) {
                continue;
            }
            if ($qte > $ligne->reste()) {
                $erreurs[] = "{$ligne->medicament->denomination_commune} : quantité supérieure au reste à servir ({$ligne->reste()}).";
                continue;
            }
            $dispo = $this->quantiteDisponible($depot, $ligne->medicament_id);
            if ($dispo < $qte) {
                $erreurs[] = "{$ligne->medicament->denomination_commune} : stock dépôt insuffisant ({$dispo} disponible).";
            }
        }
        if ($erreurs !== []) {
            return $erreurs;
        }
        if (collect($servies)->filter(fn ($q) => (float) $q > 0)->isEmpty()) {
            return ['Indiquez au moins une quantité à servir.'];
        }

        DB::transaction(function () use ($requisition, $servies, $depot) {
            foreach ($requisition->lignes as $ligne) {
                $qte = (float) ($servies[$ligne->id] ?? 0);
                if ($qte <= 0) {
                    continue;
                }

                $this->deplacerStock(
                    $ligne->medicament,
                    $depot,
                    $requisition->officine,
                    $qte,
                    $requisition->numero
                );

                $ligne->increment('quantite_servie', $qte);
            }

            $requisition->load('lignes');
            $totalServi = $requisition->lignes->sum('quantite_servie');
            $totalDemande = $requisition->lignes->sum('quantite_demandee');

            $requisition->update([
                'statut' => $totalServi >= $totalDemande ? 'servie' : 'partiellement_servie',
                'servie_par' => auth()->id(),
                'date_service' => now(),
            ]);
        });

        return [];
    }

    public function refuserRequisition(Requisition $requisition, ?string $motif = null): void
    {
        $requisition->update([
            'statut' => 'refusee',
            'servie_par' => auth()->id(),
            'date_service' => now(),
            'motif' => $motif ?: $requisition->motif,
        ]);
    }

    public function quantiteDisponible(Officine $officine, string $medicamentId): float
    {
        return (float) StockMedicament::where('officine_id', $officine->id)
            ->where('medicament_id', $medicamentId)
            ->sum('quantite_disponible');
    }

    /**
     * Transfert d'un produit d'une officine vers une autre, tracé des deux
     * côtés (sortie chez la source, entrée chez la destination).
     */
    public function deplacerStock(
        Medicament $medicament,
        Officine $source,
        Officine $destination,
        float $quantite,
        ?string $reference = null
    ): void {
        $etablissement = auth()->user()->establishment_id;

        $stockSource = StockMedicament::where('officine_id', $source->id)
            ->where('medicament_id', $medicament->id)
            ->where('quantite_disponible', '>', 0)
            ->orderBy('date_peremption')
            ->first();

        if (! $stockSource || $stockSource->quantite_disponible < $quantite) {
            throw new \RuntimeException("Stock insuffisant pour {$medicament->denomination_commune}.");
        }

        $avantSource = (float) $stockSource->quantite_disponible;
        $stockSource->decrement('quantite_disponible', $quantite);

        MouvementStock::create([
            'medicament_id' => $medicament->id,
            'establishment_id' => $etablissement,
            'officine_id' => $source->id,
            'user_id' => auth()->id(),
            'type' => 'transfert_sortie',
            'quantite' => $quantite,
            'quantite_avant' => $avantSource,
            'quantite_apres' => $avantSource - $quantite,
            'reference' => $reference,
            'destination' => $destination->nom,
            'created_at' => now(),
        ]);

        // Entrée côté destination, sur le même lot pour conserver la traçabilité
        $stockDest = StockMedicament::firstOrCreate(
            [
                'officine_id' => $destination->id,
                'medicament_id' => $medicament->id,
                'lot' => $stockSource->lot,
            ],
            [
                'establishment_id' => $etablissement,
                'quantite_disponible' => 0,
                'quantite_alerte' => $stockSource->quantite_alerte,
                'prix_unitaire_vente' => $stockSource->prix_unitaire_vente,
                'prix_unitaire_achat' => $stockSource->prix_unitaire_achat,
                'date_peremption' => $stockSource->date_peremption,
            ]
        );

        $avantDest = (float) $stockDest->quantite_disponible;
        $stockDest->increment('quantite_disponible', $quantite);

        MouvementStock::create([
            'medicament_id' => $medicament->id,
            'establishment_id' => $etablissement,
            'officine_id' => $destination->id,
            'user_id' => auth()->id(),
            'type' => 'transfert_entree',
            'quantite' => $quantite,
            'quantite_avant' => $avantDest,
            'quantite_apres' => $avantDest + $quantite,
            'reference' => $reference,
            'provenance' => $source->nom,
            'created_at' => now(),
        ]);
    }

    /**
     * Entrée fournisseur au dépôt central (achat, don, transfert externe).
     */
    public function entreeDepot(
        Officine $officine,
        Medicament $medicament,
        float $quantite,
        ?string $provenance = null,
        ?string $lot = null,
        ?string $peremption = null,
        ?float $prixVente = null
    ): void {
        DB::transaction(function () use ($officine, $medicament, $quantite, $provenance, $lot, $peremption, $prixVente) {
            $stock = StockMedicament::firstOrCreate(
                ['officine_id' => $officine->id, 'medicament_id' => $medicament->id, 'lot' => $lot],
                [
                    'establishment_id' => auth()->user()->establishment_id,
                    'quantite_disponible' => 0,
                    'quantite_alerte' => 10,
                    'prix_unitaire_vente' => $prixVente,
                    'date_peremption' => $peremption,
                ]
            );

            $avant = (float) $stock->quantite_disponible;
            $stock->increment('quantite_disponible', $quantite);
            if ($prixVente) {
                $stock->update(['prix_unitaire_vente' => $prixVente]);
            }

            MouvementStock::create([
                'medicament_id' => $medicament->id,
                'establishment_id' => auth()->user()->establishment_id,
                'officine_id' => $officine->id,
                'user_id' => auth()->id(),
                'type' => 'entree',
                'quantite' => $quantite,
                'quantite_avant' => $avant,
                'quantite_apres' => $avant + $quantite,
                'reference' => $lot ? "Lot {$lot}" : null,
                'provenance' => $provenance,
                'created_at' => now(),
            ]);
        });
    }
}
