<?php

namespace App\Services;

use App\Models\BonSortie;
use App\Models\Dispensation;
use App\Models\Medicament;
use App\Models\MouvementStock;
use App\Models\Prescription;
use App\Models\StockMedicament;
use Illuminate\Support\Facades\DB;

/**
 * Opérations pharmacie en logique serveur pure : utilisables depuis des
 * formulaires HTML classiques (aucune dépendance à Livewire côté client).
 */
class PharmacieService
{
    /**
     * Dispense une ordonnance.
     *
     * @param  array<string, mixed>  $quantites  [ligne_prescription_id => quantité]
     * @return array<string, string> erreurs (vide si succès)
     */
    public function dispenser(Prescription $prescription, array $quantites): array
    {
        $prescription->load(['lignes.medicament.stock', 'consultation.visit']);

        // Hospitalisation : servi à crédit durant le séjour (facturé, payé avant sortie)
        $aCredit = (bool) $prescription->consultation?->visit?->serviACredit();

        if (! $aCredit && ! in_array($prescription->statut, ['en_attente', 'partiellement_dispensee'], true)) {
            return ['general' => 'Ordonnance non disponible pour dispensation (paiement guichet requis).'];
        }

        if ($aCredit && in_array($prescription->statut, ['dispensee', 'annulee'], true)) {
            return ['general' => 'Ordonnance déjà dispensée ou annulée.'];
        }

        $bonValide = BonSortie::where('prescription_id', $prescription->id)
            ->where('statut', 'emis')
            ->where('expire_at', '>', now())
            ->exists();

        if (! $aCredit && ! $bonValide) {
            return ['general' => 'Bon pharmacie invalide ou expiré — vérifier le paiement au guichet.'];
        }

        // Contrôle des stocks avant toute écriture
        $erreurs = [];
        foreach ($prescription->lignes as $ligne) {
            $qte = (float) ($quantites[$ligne->id] ?? 0);
            if ($qte <= 0) {
                continue;
            }
            $stock = $ligne->medicament->stock;
            if (! $stock || $stock->quantite_disponible < $qte) {
                $erreurs[$ligne->id] = "Stock insuffisant pour {$ligne->medicament->denomination_commune} "
                    . '(disponible : ' . ($stock?->quantite_disponible ?? 0) . " {$ligne->medicament->unite_dispensation})";
            }
        }
        if ($erreurs !== []) {
            return $erreurs;
        }

        DB::transaction(function () use ($prescription, $quantites) {
            foreach ($prescription->lignes as $ligne) {
                $qte = (float) ($quantites[$ligne->id] ?? 0);
                if ($qte <= 0) {
                    continue;
                }
                $stock = $ligne->medicament->stock;

                Dispensation::create([
                    'ligne_prescription_id' => $ligne->id,
                    'pharmacien_id' => auth()->id(),
                    'date_dispensation' => now(),
                    'quantite_dispensee' => $qte,
                    'lot' => $stock->lot,
                    'prix_applique' => $stock->prix_unitaire_vente * $qte,
                ]);

                $avant = (float) $stock->quantite_disponible;
                $stock->decrement('quantite_disponible', $qte);
                $ligne->increment('quantite_dispensee', $qte);

                MouvementStock::create([
                    'medicament_id' => $ligne->medicament_id,
                    'establishment_id' => $stock->establishment_id,
                    'user_id' => auth()->id(),
                    'type' => 'sortie_dispensation',
                    'quantite' => $qte,
                    'quantite_avant' => $avant,
                    'quantite_apres' => $avant - $qte,
                    'reference' => 'Ordonnance ' . $prescription->id,
                    'created_at' => now(),
                ]);
            }

            $prescription->update(['statut' => 'dispensee']);

            BonSortie::where('prescription_id', $prescription->id)
                ->where('statut', 'emis')
                ->update(['statut' => 'utilise', 'utilise_at' => now()]);
        });

        return [];
    }

    /**
     * Crée un médicament avec son stock initial (mouvement d'entrée tracé).
     */
    public function creerMedicament(array $donnees): Medicament
    {
        return DB::transaction(function () use ($donnees) {
            $medicament = Medicament::create([
                'establishment_id' => auth()->user()->establishment_id,
                'denomination_commune' => $donnees['denomination_commune'],
                'nom_commercial' => $donnees['nom_commercial'] ?: null,
                'forme' => $donnees['forme'],
                'dosage' => $donnees['dosage'],
                'unite_dispensation' => $donnees['unite_dispensation'],
                'classe_therapeutique' => $donnees['classe_therapeutique'] ?: null,
                'necessite_ordonnance' => (bool) ($donnees['necessite_ordonnance'] ?? true),
            ]);

            $quantiteInitiale = (float) ($donnees['quantite_initiale'] ?? 0);

            StockMedicament::create([
                'medicament_id' => $medicament->id,
                'establishment_id' => auth()->user()->establishment_id,
                'quantite_disponible' => $quantiteInitiale,
                'quantite_alerte' => (int) ($donnees['quantite_alerte'] ?? 10),
                'prix_unitaire_vente' => $donnees['prix_unitaire_vente'],
                'prix_unitaire_achat' => $donnees['prix_unitaire_achat'] ?: null,
                'date_peremption' => $donnees['date_peremption'] ?: null,
                'lot' => $donnees['lot'] ?: null,
            ]);

            if ($quantiteInitiale > 0) {
                MouvementStock::create([
                    'medicament_id' => $medicament->id,
                    'establishment_id' => auth()->user()->establishment_id,
                    'user_id' => auth()->id(),
                    'type' => 'entree',
                    'quantite' => $quantiteInitiale,
                    'quantite_avant' => 0,
                    'quantite_apres' => $quantiteInitiale,
                    'reference' => 'Stock initial' . (! empty($donnees['lot']) ? " — lot {$donnees['lot']}" : ''),
                    'created_at' => now(),
                ]);
            }

            return $medicament;
        });
    }

    /**
     * Entrée / ajustement / sortie péremption sur un médicament existant.
     *
     * @return string|null message d'erreur, null si succès
     */
    public function mouvementStock(Medicament $medicament, string $type, float $quantite, ?string $lot = null, ?string $reference = null): ?string
    {
        if ($quantite == 0.0) {
            return 'La quantité ne peut pas être nulle.';
        }

        return DB::transaction(function () use ($medicament, $type, $quantite, $lot, $reference) {
            $stock = $medicament->stock ?: StockMedicament::create([
                'medicament_id' => $medicament->id,
                'establishment_id' => auth()->user()->establishment_id,
                'quantite_disponible' => 0,
                'quantite_alerte' => 10,
            ]);

            $avant = (float) $stock->quantite_disponible;
            $delta = $quantite;

            // Une sortie péremption retire toujours du stock ; une entrée en ajoute toujours
            if ($type === 'sortie_peremption') {
                $delta = -abs($delta);
            } elseif ($type === 'entree') {
                $delta = abs($delta);
            }

            $apres = $avant + $delta;
            if ($apres < 0) {
                return "Stock insuffisant (disponible : {$avant}).";
            }

            $stock->update([
                'quantite_disponible' => $apres,
                'lot' => $lot ?: $stock->lot,
            ]);

            MouvementStock::create([
                'medicament_id' => $medicament->id,
                'establishment_id' => auth()->user()->establishment_id,
                'user_id' => auth()->id(),
                'type' => $type,
                'quantite' => abs($delta),
                'quantite_avant' => $avant,
                'quantite_apres' => $apres,
                'reference' => $reference ?: ($lot ? "Lot {$lot}" : null),
                'created_at' => now(),
            ]);

            return null;
        });
    }
}
