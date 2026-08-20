<?php

namespace App\Services;

use App\Models\Caution;
use App\Models\Facture;
use App\Models\ImputationAcompte;
use App\Models\Visit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Acomptes de soins.
 *
 * Aux urgences comme en hospitalisation, le patient avance une somme avant
 * d'être servi. Cette somme n'est pas un paiement de facture : elle vit à
 * part, s'impute au fur et à mesure sur les factures du séjour, et son
 * reliquat lui revient à la sortie.
 */
class AcompteService
{
    /** Types de visite pour lesquels un acompte est attendu. */
    public const TYPES_VISITE = ['urgence', 'hospitalisation'];

    public function encaisser(
        Visit $visit,
        float $montant,
        string $devise = 'CDF',
        string $modePaiement = 'especes',
        string $type = 'acompte',
        ?string $motif = null,
        ?string $reference = null
    ): Caution {
        return DB::transaction(function () use ($visit, $montant, $devise, $modePaiement, $type, $motif, $reference) {
            $acompte = Caution::create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'caissier_id' => auth()->id(),
                'type' => $type,
                'montant' => $montant,
                'devise' => $devise,
                'mode_paiement' => $modePaiement,
                'motif' => $motif,
                'statut' => 'versee',
                'reference_paiement' => $reference,
            ]);

            // Un acompte encaissé après coup rattrape les factures déjà
            // émises et encore ouvertes, sans quoi le guichet réclamerait
            // au patient une somme qu'il vient de verser.
            $this->imputerSurFacturesOuvertes($visit);

            return $acompte->fresh();
        });
    }

    /**
     * Impute les acomptes disponibles sur une facture, du plus ancien au
     * plus récent, dans la limite de ce qui reste dû par le patient.
     */
    public function imputer(Facture $facture): float
    {
        if (! $facture->visit_id) {
            return 0.0;
        }

        return DB::transaction(function () use ($facture) {
            $facture->refresh();
            $restant = (float) $facture->patient_part
                - (float) $facture->acompte_impute
                - $facture->montantPaye();

            if ($restant <= 0) {
                return 0.0;
            }

            $total = 0.0;

            foreach ($this->disponibles($facture->visit_id) as $acompte) {
                if ($restant <= 0) {
                    break;
                }

                $part = min($acompte->resteDisponible(), $restant);

                if ($part <= 0) {
                    continue;
                }

                ImputationAcompte::create([
                    'caution_id' => $acompte->id,
                    'facture_id' => $facture->id,
                    'user_id' => auth()->id(),
                    'montant' => $part,
                ]);

                $acompte->increment('montant_impute', $part);
                $acompte->refresh()->rafraichirStatut();

                $restant -= $part;
                $total += $part;
            }

            if ($total > 0) {
                $facture->increment('acompte_impute', $total);
                $facture->refresh();

                if ($facture->estSoldee()) {
                    $facture->update(['statut' => 'payee']);
                } elseif ($facture->statut === 'emise') {
                    $facture->update(['statut' => 'partiellement_payee']);
                }
            }

            return $total;
        });
    }

    /** Passe toutes les factures ouvertes du séjour au crible des acomptes. */
    public function imputerSurFacturesOuvertes(Visit $visit): float
    {
        $total = 0.0;

        $factures = Facture::where('visit_id', $visit->id)
            ->whereIn('statut', ['emise', 'partiellement_payee'])
            ->orderBy('date_facture')
            ->get();

        foreach ($factures as $facture) {
            $total += $this->imputer($facture);
        }

        return $total;
    }

    /**
     * Acomptes du séjour encore mobilisables, du plus ancien au plus récent.
     *
     * @return Collection<int, Caution>
     */
    public function disponibles(string $visitId): Collection
    {
        return Caution::where('visit_id', $visitId)
            ->whereIn('statut', ['versee', 'remboursee_partiel'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Caution $a) => $a->resteDisponible() > 0)
            ->values();
    }

    /** Somme encore disponible sur le séjour. */
    public function soldeDisponible(string $visitId): float
    {
        return (float) $this->disponibles($visitId)->sum(fn (Caution $a) => $a->resteDisponible());
    }

    /** Total avancé par le patient sur ce séjour, imputé ou non. */
    public function totalVerse(string $visitId): float
    {
        return (float) Caution::where('visit_id', $visitId)->sum('montant');
    }

    /**
     * Rend au patient le reliquat de ses acomptes. Refusé tant que des
     * factures du séjour restent ouvertes : le reliquat leur revient
     * d'abord.
     */
    public function rembourser(Visit $visit, ?string $reference = null): float
    {
        return DB::transaction(function () use ($visit, $reference) {
            $this->imputerSurFacturesOuvertes($visit);

            $total = 0.0;

            foreach ($this->disponibles($visit->id) as $acompte) {
                $reste = $acompte->resteDisponible();

                $acompte->increment('montant_rembourse', $reste);
                $acompte->update(['reference_paiement' => $reference ?: $acompte->reference_paiement]);
                $acompte->refresh()->rafraichirStatut();

                $total += $reste;
            }

            return $total;
        });
    }
}
