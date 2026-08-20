<?php

namespace App\Services;

use App\Models\Assurance;
use App\Models\Billetage;
use App\Models\Facture;
use App\Models\FactureConvention;
use App\Models\LigneFactureConvention;
use App\Models\Paiement;
use App\Models\ReglementConvention;
use Illuminate\Support\Facades\DB;

/**
 * Facturation aux sociétés et conventions, et contrôle de la caisse physique.
 */
class ConventionService
{
    /**
     * Factures patients d'une période dont une part reste à la charge d'une
     * convention et qui n'ont pas encore été refacturées à celle-ci.
     */
    public function facturesARefacturer(Assurance $assurance, string $debut, string $fin)
    {
        $dejaFacturees = LigneFactureConvention::query()
            ->join('factures_convention as fc', 'fc.id', '=', 'lignes_facture_convention.facture_convention_id')
            ->where('fc.statut', '!=', 'annulee')
            ->pluck('lignes_facture_convention.facture_id');

        return Facture::with(['patient', 'lignesTiersPayant', 'visit'])
            ->whereBetween('date_facture', [$debut.' 00:00:00', $fin.' 23:59:59'])
            ->where('assurance_part', '>', 0)
            ->whereNotIn('id', $dejaFacturees)
            ->whereHas('lignesTiersPayant', fn ($q) => $q->where('assurance_id', $assurance->id))
            ->orderBy('date_facture')
            ->get();
    }

    /**
     * Émet la facture de convention regroupant la part prise en charge.
     *
     * Le mode ne change pas les données, seulement leur présentation :
     * un document global, ou un document par bénéficiaire.
     *
     * @param  array<int, string>|null  $factureIds  restreindre à ces factures
     */
    public function emettre(
        Assurance $assurance,
        string $debut,
        string $fin,
        string $mode = 'collective',
        string $devise = 'CDF',
        float $tauxChange = 1.0,
        ?array $factureIds = null
    ): FactureConvention {
        $factures = $this->facturesARefacturer($assurance, $debut, $fin)
            ->when($factureIds !== null, fn ($c) => $c->whereIn('id', $factureIds));

        if ($factures->isEmpty()) {
            throw new \RuntimeException('Aucune facture à refacturer pour cette convention sur la période.');
        }

        return DB::transaction(function () use ($assurance, $debut, $fin, $mode, $devise, $tauxChange, $factures) {
            $facture = FactureConvention::create([
                'numero' => FactureConvention::genererNumero(),
                'assurance_id' => $assurance->id,
                'emise_par' => auth()->id(),
                'periode_debut' => $debut,
                'periode_fin' => $fin,
                'mode' => $mode,
                'devise' => $devise,
                'taux_change' => $tauxChange > 0 ? $tauxChange : 1,
                'statut' => 'emise',
            ]);

            $total = 0.0;
            foreach ($factures as $f) {
                // Ne retenir que la part couverte par CETTE convention
                $part = (float) $f->lignesTiersPayant
                    ->where('assurance_id', $assurance->id)
                    ->sum('part_assurance');

                if ($part <= 0) {
                    continue;
                }

                LigneFactureConvention::create([
                    'facture_convention_id' => $facture->id,
                    'facture_id' => $f->id,
                    'patient_id' => $f->patient_id,
                    'part_assurance' => $part,
                ]);
                $total += $part;
            }

            // Les montants sont saisis en CDF ; le taux convertit vers la devise
            $facture->update(['montant_total' => round($total / max($tauxChange, 0.0001), 2)]);

            return $facture->fresh(['lignes.patient', 'assurance']);
        });
    }

    public function enregistrerReglement(
        FactureConvention $facture,
        float $montant,
        string $mode = 'virement',
        ?string $reference = null
    ): ReglementConvention {
        if ($montant <= 0) {
            throw new \RuntimeException('Le montant du règlement doit être positif.');
        }

        return DB::transaction(function () use ($facture, $montant, $mode, $reference) {
            $reglement = ReglementConvention::create([
                'facture_convention_id' => $facture->id,
                'encaisse_par' => auth()->id(),
                'montant' => $montant,
                'mode_paiement' => $mode,
                'reference' => $reference,
                'date_reglement' => now(),
            ]);

            $regle = (float) $facture->reglements()->sum('montant');
            $facture->update([
                'montant_regle' => $regle,
                'statut' => $regle >= (float) $facture->montant_total - 0.01
                    ? 'reglee'
                    : 'partiellement_reglee',
                'date_reglement' => $regle >= (float) $facture->montant_total - 0.01 ? now() : null,
            ]);

            return $reglement;
        });
    }

    /**
     * Dettes à recouvrer, par convention.
     */
    public function dettesParConvention()
    {
        return FactureConvention::with('assurance')
            ->whereIn('statut', ['emise', 'partiellement_reglee'])
            ->get()
            ->groupBy('assurance_id')
            ->map(fn ($factures) => [
                'assurance' => $factures->first()->assurance,
                'factures' => $factures->count(),
                'du' => $factures->sum(fn ($f) => $f->resteDu()),
                'plus_ancienne' => $factures->min('periode_debut'),
            ])
            ->sortByDesc('du');
    }

    // ══════════════════════════════════════════════════════════════
    // Contrôle de la caisse
    // ══════════════════════════════════════════════════════════════

    /**
     * Espèces théoriquement en caisse pour une devise donnée.
     *
     * Le comptage porte sur des billets bien réels : un tiroir de francs se
     * compare aux encaissements en francs, pas à la somme de toutes les
     * devises confondues.
     */
    public function recettesEspeces(string $debut, string $fin, string $devise = 'CDF'): float
    {
        return (float) Paiement::whereBetween('date_paiement', [$debut, $fin])
            ->where('mode_paiement', 'especes')
            ->where('devise', $devise)
            ->sum('montant');
    }

    /**
     * Enregistre un comptage de caisse et calcule l'écart.
     *
     * @param  array<int|string, int>  $coupures  [valeur de la coupure => nombre]
     */
    public function enregistrerBilletage(
        array $coupures,
        string $devise,
        string $debut,
        string $fin,
        ?string $observation = null
    ): Billetage {
        $comptees = [];
        $total = 0.0;

        foreach (Billetage::coupuresPour($devise) as $valeur) {
            $nombre = (int) ($coupures[$valeur] ?? 0);
            if ($nombre > 0) {
                $comptees[(string) $valeur] = $nombre;
                $total += $valeur * $nombre;
            }
        }

        $theorique = $this->recettesEspeces($debut, $fin, $devise);

        return Billetage::create([
            'establishment_id' => auth()->user()->establishment_id,
            'caissier_id' => auth()->id(),
            'devise' => $devise,
            'coupures' => $comptees,
            'total_compte' => $total,
            'total_theorique' => $theorique,
            'ecart' => $total - $theorique,
            'debut_periode' => $debut,
            'fin_periode' => $fin,
            'observation' => $observation,
        ]);
    }
}
