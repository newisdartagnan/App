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
 *
 * Un acompte est versé dans une devise et le reste : 100 $ se vident en
 * dollars. Pour imputer sur une facture libellée dans une autre devise, on
 * passe par le franc congolais au taux figé lors du versement — le change
 * du jour ne réécrit pas une avance déjà encaissée.
 */
class AcompteService
{
    /** Types de visite pour lesquels un acompte est attendu. */
    public const TYPES_VISITE = ['urgence', 'hospitalisation'];

    public function __construct(private readonly DeviseService $devises) {}

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
            $empreinte = $this->devises->empreinte($montant, $devise);

            $acompte = Caution::create([
                'visit_id' => $visit->id,
                'patient_id' => $visit->patient_id,
                'caissier_id' => auth()->id(),
                'type' => $type,
                'montant' => $montant,
                'devise' => $empreinte['devise'],
                'taux_change' => $empreinte['taux_change'],
                'montant_cdf' => $empreinte['montant_cdf'],
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
     *
     * Par défaut on ne mobilise que les acomptes du séjour concerné —
     * l'imputation automatique ne doit pas piocher dans une avance versée
     * pour autre chose. Le guichet peut en revanche mobiliser tous les
     * acomptes du patient, à la demande, via $toutLePatient.
     *
     * @return float Montant imputé, dans la devise de la facture.
     */
    public function imputer(Facture $facture, bool $toutLePatient = false): float
    {
        if (! $facture->visit_id && ! $toutLePatient) {
            return 0.0;
        }

        return DB::transaction(function () use ($facture, $toutLePatient) {
            $facture->refresh();

            $deviseFacture = $facture->devise ?: $this->devises->pivot();
            $tauxFacture = (float) ($facture->taux_change ?: $this->devises->taux($deviseFacture));

            // Tout le raisonnement se fait en monnaie de compte : c'est la
            // seule façon d'additionner des avances en dollars et en francs.
            $restantCdf = $this->devises->versCdf(
                (float) $facture->patient_part
                    - (float) $facture->acompte_impute
                    - $facture->montantPaye(),
                $deviseFacture,
                $tauxFacture
            );

            if ($restantCdf <= 0) {
                return 0.0;
            }

            $totalCdf = 0.0;

            $mobilisables = $toutLePatient
                ? $this->disponiblesPourPatient($facture->patient_id)
                : $this->disponibles($facture->visit_id);

            foreach ($mobilisables as $acompte) {
                if ($restantCdf <= 0) {
                    break;
                }

                $partCdf = min($acompte->resteDisponibleCdf(), $restantCdf);

                if ($partCdf <= 0.009) {
                    continue;
                }

                // Ce que cette imputation retire de l'acompte, dans sa propre
                // devise, et ce qu'elle apporte à la facture, dans la sienne.
                $partAcompte = $this->devises->depuisCdf($partCdf, $acompte->devise, $acompte->tauxApplique());
                $partFacture = $this->devises->depuisCdf($partCdf, $deviseFacture, $tauxFacture);

                ImputationAcompte::create([
                    'caution_id' => $acompte->id,
                    'facture_id' => $facture->id,
                    'user_id' => auth()->id(),
                    'montant' => $partFacture,
                    'devise' => $deviseFacture,
                    'taux_change' => $tauxFacture,
                    'montant_cdf' => $partCdf,
                    'montant_acompte' => $partAcompte,
                ]);

                $acompte->increment('montant_impute', $partAcompte);
                $acompte->increment('montant_impute_cdf', $partCdf);
                $acompte->refresh()->rafraichirStatut();

                $restantCdf -= $partCdf;
                $totalCdf += $partCdf;
            }

            if ($totalCdf <= 0) {
                return 0.0;
            }

            $totalFacture = $this->devises->depuisCdf($totalCdf, $deviseFacture, $tauxFacture);

            $facture->increment('acompte_impute', $totalFacture);
            $facture->refresh();

            if ($facture->estSoldee()) {
                $facture->update(['statut' => 'payee']);
            } elseif ($facture->statut === 'emise') {
                $facture->update(['statut' => 'partiellement_payee']);
            }

            return $totalFacture;
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
            ->filter(fn (Caution $a) => $a->resteDisponibleCdf() > 0)
            ->values();
    }

    /**
     * Tous les acomptes mobilisables du patient, séjours confondus. Un
     * patient qui a laissé une avance aux urgences doit pouvoir s'en servir
     * pour régler l'ordonnance qu'on lui délivre le lendemain.
     *
     * @return Collection<int, Caution>
     */
    public function disponiblesPourPatient(string $patientId): Collection
    {
        return Caution::where('patient_id', $patientId)
            ->whereIn('statut', ['versee', 'remboursee_partiel'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Caution $a) => $a->resteDisponibleCdf() > 0)
            ->values();
    }

    /** Somme encore disponible sur le séjour, en francs congolais. */
    public function soldeDisponible(string $visitId): float
    {
        return (float) $this->disponibles($visitId)->sum(fn (Caution $a) => $a->resteDisponibleCdf());
    }

    /** Somme encore disponible pour le patient, tous séjours confondus (CDF). */
    public function soldePatient(string $patientId): float
    {
        return (float) $this->disponiblesPourPatient($patientId)
            ->sum(fn (Caution $a) => $a->resteDisponibleCdf());
    }

    /**
     * Solde disponible ventilé par devise : le guichet doit savoir qu'il
     * détient 100 $ et 50 000 CDF, pas seulement une somme agrégée.
     *
     * @return array<string, float>
     */
    public function soldeParDevise(string $patientId): array
    {
        return $this->disponiblesPourPatient($patientId)
            ->groupBy('devise')
            ->map(fn ($groupe) => (float) $groupe->sum(fn (Caution $a) => $a->resteDisponible()))
            ->all();
    }

    /** Total avancé par le patient sur ce séjour, en francs congolais. */
    public function totalVerse(string $visitId): float
    {
        return (float) Caution::where('visit_id', $visitId)->sum('montant_cdf');
    }

    /**
     * Rend au patient le reliquat de ses acomptes, dans la devise de chaque
     * versement : une avance en dollars se rembourse en dollars.
     *
     * @return array<string, float> Montants remboursés par devise.
     */
    public function rembourser(Visit $visit, ?string $reference = null): array
    {
        return DB::transaction(function () use ($visit, $reference) {
            $this->imputerSurFacturesOuvertes($visit);

            $rendus = [];

            foreach ($this->disponibles($visit->id) as $acompte) {
                $reste = $acompte->resteDisponible();
                $resteCdf = $acompte->resteDisponibleCdf();

                $acompte->increment('montant_rembourse', $reste);
                $acompte->increment('montant_rembourse_cdf', $resteCdf);
                $acompte->update(['reference_paiement' => $reference ?: $acompte->reference_paiement]);
                $acompte->refresh()->rafraichirStatut();

                $rendus[$acompte->devise] = ($rendus[$acompte->devise] ?? 0) + $reste;
            }

            return $rendus;
        });
    }
}
