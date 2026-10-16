<?php

namespace App\Services;

use App\Models\Assurance;
use App\Models\Facture;
use App\Models\FactureConvention;
use App\Models\RejetConvention;
use App\Models\RelanceConvention;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Le tableau de bord du recouvrement.
 *
 * Tous les montants sont ramenés en francs congolais, monnaie de compte :
 * une facture en dollars pèse son montant au taux figé à l'émission, un
 * bordereau en dollars au taux de son émission. Sans cela on additionnerait
 * des francs et des dollars.
 *
 * Les définitions suivent celles du métier :
 * - taux de recouvrement = encaissements de la période ÷ facturation de la
 *   période ;
 * - DSO = créances ÷ facturation de la période × nombre de jours ;
 * - taux de rejet = rejeté ÷ facturé, sur les bordereaux émis dans la
 *   période, en valeur et en nombre de factures ;
 * - balance âgée = créances classées par retard sur leur échéance.
 */
class RecouvrementService
{
    public const TRANCHES = [
        'non_echu' => 'Non échues',
        '0_30' => '1 à 30 j',
        '31_60' => '31 à 60 j',
        '61_90' => '61 à 90 j',
        'plus_90' => 'Plus de 90 j',
    ];

    /** Sans relance depuis ce nombre de jours, un bordereau échu est à relancer. */
    public const RELANCE_TOUS_LES = 15;

    /** @return array<string, mixed> */
    public function tableau(Carbon $debut, Carbon $fin): array
    {
        $debut = $debut->copy()->startOfDay();
        $fin = $fin->copy()->endOfDay();
        $jours = max(1, (int) $debut->diffInDays($fin) + 1);

        $bordereaux = $this->bordereauxOuverts();
        $patients = $this->creancesPatients();
        $aFacturer = $this->partsAFacturer();

        $facture = $this->facturation($debut, $fin);
        $encaisse = $this->encaissements($debut, $fin);

        $creanceConventions = (float) $bordereaux->sum('reste_cdf');
        $creancePatients = (float) $patients->sum('solde_cdf');
        $creanceAFacturer = (float) $aFacturer->sum('part_cdf');
        $creances = $creanceConventions + $creancePatients + $creanceAFacturer;

        return [
            'debut' => $debut,
            'fin' => $fin,
            'jours' => $jours,
            'indicateurs' => [
                'facture' => $facture,
                'encaisse' => $encaisse,
                'taux_recouvrement' => $facture > 0 ? round($encaisse['total'] / $facture * 100, 1) : null,
                'creances' => $creances,
                'creance_conventions' => $creanceConventions,
                'creance_patients' => $creancePatients,
                'creance_a_facturer' => $creanceAFacturer,
                'dso' => $facture > 0 ? (int) round($creances / $facture * $jours) : null,
            ],
            'balanceConventions' => $this->balanceConventions($bordereaux, $aFacturer),
            'balancePatients' => $this->balancePatients($patients),
            'parAssureur' => $this->parAssureur($bordereaux, $aFacturer, $debut, $fin, $jours),
            'rejetsParMotif' => $this->rejetsParMotif($debut, $fin),
            'rejetsATraiter' => RejetConvention::with(['factureConvention.assurance', 'facture.patient'])
                ->whereIn('statut', ['ouvert', 'conteste'])
                ->orderBy('date_rejet')
                ->get(),
            'relancesAFaire' => $this->relancesAFaire($bordereaux),
            'topDebiteurs' => $this->topDebiteurs($patients),
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // Flux de la période
    // ══════════════════════════════════════════════════════════════

    /** Ce qui a été facturé dans la période, net des avoirs, en CDF. */
    public function facturation(Carbon $debut, Carbon $fin): float
    {
        $brut = (float) Facture::whereBetween('date_facture', [$debut, $fin])
            ->sum(DB::raw('total_ttc * COALESCE(NULLIF(taux_change, 0), 1)'));

        $avoirs = (float) DB::table('avoirs')
            ->join('factures', 'factures.id', '=', 'avoirs.facture_id')
            ->whereBetween('factures.date_facture', [$debut, $fin])
            ->sum(DB::raw('avoirs.montant_total * COALESCE(NULLIF(avoirs.taux_change, 0), 1)'));

        return max(0, round($brut - $avoirs, 2));
    }

    /**
     * L'argent entré dans la période : guichet, acomptes, assureurs.
     *
     * @return array{guichet: float, acomptes: float, conventions: float, total: float}
     */
    public function encaissements(Carbon $debut, Carbon $fin): array
    {
        $guichet = (float) DB::table('paiements')->whereBetween('date_paiement', [$debut, $fin])->sum('montant_cdf');

        // Un crédit né d'un avoir n'est pas de l'argent reçu.
        $acomptes = (float) DB::table('cautions')
            ->whereBetween('created_at', [$debut, $fin])
            ->where('type', '!=', 'avoir')
            ->sum('montant_cdf');

        $conventions = (float) DB::table('reglements_convention')
            ->join('factures_convention', 'factures_convention.id', '=', 'reglements_convention.facture_convention_id')
            ->whereBetween('reglements_convention.date_reglement', [$debut, $fin])
            ->sum(DB::raw('reglements_convention.montant * COALESCE(NULLIF(factures_convention.taux_change, 0), 1)'));

        return [
            'guichet' => $guichet,
            'acomptes' => $acomptes,
            'conventions' => round($conventions, 2),
            'total' => round($guichet + $acomptes + $conventions, 2),
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // Créances à date
    // ══════════════════════════════════════════════════════════════

    /**
     * Bordereaux qui attendent encore un paiement, avec leur échéance et
     * leur retard.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function bordereauxOuverts(): Collection
    {
        $aujourdhui = now()->startOfDay();

        return FactureConvention::with(['assurance', 'relances' => fn ($q) => $q->orderByDesc('date_relance')])
            ->whereNotIn('statut', ['annulee', 'reglee'])
            ->get()
            ->map(function (FactureConvention $f) use ($aujourdhui) {
                $reste = $f->resteDu();
                $echeance = $f->echeance();
                $retard = (int) $echeance->copy()->startOfDay()->diffInDays($aujourdhui, false);

                return [
                    'bordereau' => $f,
                    'assurance' => $f->assurance,
                    'reste' => $reste,
                    'reste_cdf' => round($reste * (float) ($f->taux_change ?: 1), 2),
                    'echeance' => $echeance,
                    'retard' => $retard,
                    'tranche' => $this->tranche($retard),
                    'derniere_relance' => $f->relances->first(),
                ];
            })
            ->filter(fn ($b) => $b['reste'] > 0.009)
            ->values();
    }

    /**
     * Ce que chaque patient doit encore au guichet, facture par facture.
     * Calculé en une requête : un hôpital a des milliers de factures, on ne
     * les interroge pas une à une.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function creancesPatients(): Collection
    {
        $aujourdhui = now()->startOfDay();

        return Facture::with('patient')
            ->select('factures.*')
            ->selectSub('SELECT COALESCE(SUM(montant_patient), 0) FROM avoirs WHERE avoirs.facture_id = factures.id', 'av_patient')
            ->selectSub('SELECT COALESCE(SUM(credit_patient), 0) FROM avoirs WHERE avoirs.facture_id = factures.id', 'av_credit')
            ->selectSub('SELECT COALESCE(SUM(montant_cdf), 0) FROM paiements WHERE paiements.facture_id = factures.id', 'paye_cdf')
            ->whereIn('statut', ['emise', 'partiellement_payee'])
            ->get()
            ->map(function (Facture $f) use ($aujourdhui) {
                $taux = (float) ($f->taux_change ?: 1);
                $solde = (float) $f->patient_part - (float) $f->av_patient - (float) $f->acompte_impute
                    - (float) $f->paye_cdf / $taux + (float) $f->av_credit;
                $age = (int) $f->date_facture->copy()->startOfDay()->diffInDays($aujourdhui);

                return [
                    'facture' => $f,
                    'solde_cdf' => round(max(0, $solde) * $taux, 2),
                    'age' => $age,
                    // Le patient doit dès la facture : pas de tranche « non échue ».
                    'tranche' => $this->tranche(max(1, $age)),
                ];
            })
            ->filter(fn ($p) => $p['solde_cdf'] > 0.009)
            ->values();
    }

    /**
     * Part assureur des factures que nul bordereau ne réclame encore :
     * c'est une créance que l'hôpital n'a même pas présentée.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function partsAFacturer(): Collection
    {
        $dejaFacturees = DB::table('lignes_facture_convention')
            ->join('factures_convention', 'factures_convention.id', '=', 'lignes_facture_convention.facture_convention_id')
            ->where('factures_convention.statut', '!=', 'annulee')
            ->pluck('lignes_facture_convention.facture_id');

        return Facture::query()
            ->select('factures.*')
            ->selectSub('SELECT COALESCE(SUM(montant_assurance), 0) FROM avoirs WHERE avoirs.facture_id = factures.id', 'av_assurance')
            ->selectSub(
                'SELECT assurance_id FROM facture_tiers_payant WHERE facture_tiers_payant.facture_id = factures.id AND part_assurance > 0 LIMIT 1',
                'assurance_id'
            )
            ->where('assurance_part', '>', 0)
            ->where('statut', '!=', 'annulee')
            ->whereNotIn('id', $dejaFacturees)
            ->get()
            ->map(fn (Facture $f) => [
                'facture' => $f,
                'assurance_id' => $f->assurance_id,
                'part_cdf' => round(max(0, (float) $f->assurance_part - (float) $f->av_assurance) * (float) ($f->taux_change ?: 1), 2),
            ])
            ->filter(fn ($p) => $p['part_cdf'] > 0.009 && $p['assurance_id'])
            ->values();
    }

    // ══════════════════════════════════════════════════════════════
    // Tableaux
    // ══════════════════════════════════════════════════════════════

    /** @return array{lignes: Collection, total: array<string, float>} */
    private function balanceConventions(Collection $bordereaux, Collection $aFacturer): array
    {
        $assurances = Assurance::whereIn('id', $bordereaux->pluck('assurance.id')->merge($aFacturer->pluck('assurance_id'))->unique())
            ->orderBy('nom')->get();

        $lignes = $assurances->map(function (Assurance $a) use ($bordereaux, $aFacturer) {
            $siens = $bordereaux->where('assurance.id', $a->id);
            $ligne = ['assurance' => $a, 'a_facturer' => (float) $aFacturer->where('assurance_id', $a->id)->sum('part_cdf')];

            foreach (array_keys(self::TRANCHES) as $tranche) {
                $ligne[$tranche] = (float) $siens->where('tranche', $tranche)->sum('reste_cdf');
            }

            $ligne['total'] = (float) $siens->sum('reste_cdf');

            return $ligne;
        })->sortByDesc('total')->values();

        $total = ['a_facturer' => (float) $lignes->sum('a_facturer'), 'total' => (float) $lignes->sum('total')];
        foreach (array_keys(self::TRANCHES) as $tranche) {
            $total[$tranche] = (float) $lignes->sum($tranche);
        }

        return ['lignes' => $lignes, 'total' => $total];
    }

    /** @return array<string, float> */
    private function balancePatients(Collection $patients): array
    {
        $balance = [];

        foreach (array_keys(self::TRANCHES) as $tranche) {
            $balance[$tranche] = (float) $patients->where('tranche', $tranche)->sum('solde_cdf');
        }

        $balance['total'] = (float) $patients->sum('solde_cdf');

        return $balance;
    }

    /** @return Collection<int, array<string, mixed>> */
    private function parAssureur(Collection $bordereaux, Collection $aFacturer, Carbon $debut, Carbon $fin, int $jours): Collection
    {
        $emis = FactureConvention::with(['assurance', 'lignes', 'rejets'])
            ->where('statut', '!=', 'annulee')
            ->whereBetween('created_at', [$debut, $fin])
            ->get();

        $regle = DB::table('reglements_convention')
            ->join('factures_convention', 'factures_convention.id', '=', 'reglements_convention.facture_convention_id')
            ->whereBetween('reglements_convention.date_reglement', [$debut, $fin])
            ->groupBy('factures_convention.assurance_id')
            ->selectRaw('factures_convention.assurance_id, SUM(reglements_convention.montant * COALESCE(NULLIF(factures_convention.taux_change, 0), 1)) AS montant')
            ->pluck('montant', 'assurance_id');

        $ids = $emis->pluck('assurance_id')
            ->merge($bordereaux->pluck('assurance.id'))
            ->merge($aFacturer->pluck('assurance_id'))
            ->merge($regle->keys())
            ->unique()
            ->filter();

        return Assurance::whereIn('id', $ids)->orderBy('nom')->get()->map(function (Assurance $a) use ($emis, $bordereaux, $aFacturer, $regle, $jours) {
            $siens = $emis->where('assurance_id', $a->id);
            $cdf = fn (FactureConvention $f, float $m) => $m * (float) ($f->taux_change ?: 1);

            $facture = (float) $siens->sum(fn ($f) => $cdf($f, (float) $f->montant_total));
            $rejete = (float) $siens->sum(fn ($f) => $cdf($f, (float) $f->rejets->where('statut', '!=', 'leve')->sum('montant')));
            $nbFactures = (int) $siens->sum(fn ($f) => $f->lignes->count());
            $nbRejetees = (int) $siens->sum(fn ($f) => $f->rejets->where('statut', '!=', 'leve')->pluck('facture_id')->filter()->unique()->count());

            $ouverts = $bordereaux->where('assurance.id', $a->id);
            $solde = (float) $ouverts->sum('reste_cdf') + (float) $aFacturer->where('assurance_id', $a->id)->sum('part_cdf');
            // La dernière relance faite à cet assureur, sur n'importe lequel
            // de ses bordereaux : c'est elle qu'on lui rappellera au téléphone.
            $derniere = RelanceConvention::whereHas('factureConvention', fn ($q) => $q->where('assurance_id', $a->id))
                ->orderByDesc('date_relance')
                ->orderByDesc('created_at')
                ->first();

            return [
                'assurance' => $a,
                'facture' => $facture,
                'rejete' => $rejete,
                'taux_rejet_valeur' => $facture > 0 ? round($rejete / $facture * 100, 1) : null,
                'taux_rejet_nombre' => $nbFactures > 0 ? round($nbRejetees / $nbFactures * 100, 1) : null,
                'factures' => $nbFactures,
                'rejetees' => $nbRejetees,
                'encaisse' => round((float) ($regle[$a->id] ?? 0), 2),
                'solde' => $solde,
                'dso' => $facture > 0 ? (int) round($solde / $facture * $jours) : null,
                'en_retard' => (float) $ouverts->where('retard', '>', 0)->sum('reste_cdf'),
                'derniere_relance' => $derniere,
            ];
        })->sortByDesc('solde')->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function rejetsParMotif(Carbon $debut, Carbon $fin): Collection
    {
        return RejetConvention::with('factureConvention')
            ->whereBetween('date_rejet', [$debut->toDateString(), $fin->toDateString()])
            ->get()
            ->groupBy('motif_categorie')
            ->map(fn ($groupe, $motif) => [
                'libelle' => RejetConvention::MOTIFS[$motif] ?? $motif,
                'nombre' => $groupe->count(),
                'montant' => (float) $groupe->sum(fn ($r) => (float) $r->montant * (float) ($r->factureConvention?->taux_change ?: 1)),
            ])
            ->sortByDesc('montant')
            ->values();
    }

    /**
     * Bordereaux échus qu'il faut relancer : jamais relancés, relancés il y
     * a plus de quinze jours, ou dont la prochaine action prévue est due.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function relancesAFaire(Collection $bordereaux): Collection
    {
        $aujourdhui = now()->startOfDay();

        return $bordereaux
            ->filter(function ($b) use ($aujourdhui) {
                if ($b['retard'] <= 0) {
                    return false;
                }

                $relance = $b['derniere_relance'];

                if (! $relance) {
                    return true;
                }

                return $relance->prochaine_action
                    ? $relance->prochaine_action->lte($aujourdhui)
                    : $relance->date_relance->lt($aujourdhui->copy()->subDays(self::RELANCE_TOUS_LES));
            })
            ->sortByDesc('retard')
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function topDebiteurs(Collection $patients): Collection
    {
        return $patients->groupBy(fn ($p) => $p['facture']->patient_id)
            ->map(fn ($groupe) => [
                'patient' => $groupe->first()['facture']->patient,
                'solde' => (float) $groupe->sum('solde_cdf'),
                'factures' => $groupe->count(),
                'plus_ancienne' => (int) $groupe->max('age'),
            ])
            ->sortByDesc('solde')
            ->take(10)
            ->values();
    }

    /** Tranche de la balance âgée pour un retard donné (en jours). */
    public function tranche(int $retard): string
    {
        return match (true) {
            $retard <= 0 => 'non_echu',
            $retard <= 30 => '0_30',
            $retard <= 60 => '31_60',
            $retard <= 90 => '61_90',
            default => 'plus_90',
        };
    }
}
