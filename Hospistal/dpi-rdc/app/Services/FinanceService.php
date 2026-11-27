<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\ClotureFinanciere;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\Paiement;
use App\Models\ReceptionStock;
use App\Models\RubriqueDepense;
use App\Models\TransfertTresorerie;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trésorerie, dépenses et budget.
 *
 * Les montants se comparent en francs congolais (équivalent CDF au taux du
 * jour de l'opération) : la caisse encaisse en CDF et en USD, la direction
 * lit un seul total.
 */
class FinanceService
{
    public function __construct(private readonly DeviseService $devises) {}

    public function numero(string $prefixe, string $table): string
    {
        $annee = now()->format('Y');
        $n = DB::table($table)->where('numero', 'like', "{$prefixe}-{$annee}-%")->count() + 1;
        do {
            $numero = sprintf('%s-%s-%05d', $prefixe, $annee, $n++);
        } while (DB::table($table)->where('numero', $numero)->exists());

        return $numero;
    }

    public function enregistrerDepense(array $donnees): Depense
    {
        $this->verifierPeriodeOuverte($donnees['date_depense']);
        $devise = $donnees['devise'] ?? 'CDF';
        $taux = $devise === 'CDF' ? 1.0 : $this->devises->taux($devise);

        return Depense::create([
            ...$donnees,
            'numero' => $this->numero('DEP', 'depenses'),
            'devise' => $devise,
            'taux_change' => $taux,
            'montant_cdf' => round($this->devises->versCdf((float) $donnees['montant'], $devise, $taux), 2),
            'statut' => 'en_attente',
            'saisie_par' => auth()->id(),
        ]);
    }

    public function approuver(Depense $depense): void
    {
        if ($depense->statut !== 'en_attente') {
            throw new \RuntimeException('Seule une dépense en attente s\'approuve.');
        }
        if ($depense->saisie_par === auth()->id() && ! auth()->user()->hasRole('super_admin')) {
            throw new \RuntimeException('Celui qui saisit une dépense ne l\'approuve pas lui-même.');
        }
        $depense->update(['statut' => 'approuvee', 'approuvee_par' => auth()->id(), 'approuvee_at' => now()]);
    }

    public function rejeter(Depense $depense, string $motif): void
    {
        if (! in_array($depense->statut, ['en_attente', 'approuvee'], true)) {
            throw new \RuntimeException('Cette dépense ne peut plus être rejetée.');
        }
        $depense->update(['statut' => 'rejetee', 'motif_rejet' => $motif, 'approuvee_par' => auth()->id(), 'approuvee_at' => now()]);
    }

    /** Paiement depuis un compte : le solde doit le permettre. */
    public function payer(Depense $depense, CompteTresorerie $compte, ?string $mode = null): void
    {
        if ($depense->statut !== 'approuvee') {
            throw new \RuntimeException('Une dépense se paie après approbation.');
        }
        $this->verifierPeriodeOuverte(now());
        if ($this->solde($compte) < (float) $depense->montant_cdf - 0.005) {
            throw new \RuntimeException('Solde insuffisant sur « '.$compte->nom.' ».');
        }

        DB::transaction(function () use ($depense, $compte, $mode) {
            $depense->update([
                'statut' => 'payee',
                'compte_tresorerie_id' => $compte->id,
                'mode_paiement' => $mode ?? $depense->mode_paiement,
                'payee_par' => auth()->id(),
                'payee_at' => now(),
            ]);
            if ($depense->reception_id) {
                $reception = ReceptionStock::lockForUpdate()->find($depense->reception_id);
                $reception?->update(['montant_paye' => (float) $reception->montant_paye + (float) $depense->montant]);
            }
        });
    }

    public function transferer(CompteTresorerie $source, CompteTresorerie $destination, float $montantCdf, ?string $reference, ?string $observations, string $date): TransfertTresorerie
    {
        if ($source->id === $destination->id) {
            throw new \RuntimeException('Choisissez deux comptes différents.');
        }
        $this->verifierPeriodeOuverte($date);
        if ($this->solde($source) < $montantCdf - 0.005) {
            throw new \RuntimeException('Solde insuffisant sur « '.$source->nom.' ».');
        }

        return TransfertTresorerie::create([
            'numero' => $this->numero('TRF', 'transferts_tresorerie'),
            'date_transfert' => $date,
            'compte_source_id' => $source->id,
            'compte_destination_id' => $destination->id,
            'montant_cdf' => $montantCdf,
            'reference' => $reference,
            'observations' => $observations,
            'user_id' => auth()->id(),
        ]);
    }

    /** Encaissements des patients arrivés sur ce compte (selon le mode de paiement). */
    public function recettesDuCompte(CompteTresorerie $compte, ?string $du = null, ?string $au = null)
    {
        $modes = (array) ($compte->modes_paiement ?? []);

        return Paiement::query()
            ->whereIn('mode_paiement', $modes === [] ? ['__aucun__'] : $modes)
            ->when($compte->date_solde_initial, fn ($q) => $q->where('date_paiement', '>=', $compte->date_solde_initial->startOfDay()))
            ->when($du, fn ($q) => $q->where('date_paiement', '>=', $du.' 00:00:00'))
            ->when($au, fn ($q) => $q->where('date_paiement', '<=', $au.' 23:59:59'));
    }

    public function solde(CompteTresorerie $compte, ?string $jusquau = null): float
    {
        $recettes = (float) $this->recettesDuCompte($compte, null, $jusquau)->sum('montant_cdf');
        $depenses = (float) Depense::where('compte_tresorerie_id', $compte->id)->where('statut', 'payee')
            ->when($jusquau, fn ($q) => $q->where('payee_at', '<=', $jusquau.' 23:59:59'))->sum('montant_cdf');
        $entrees = (float) TransfertTresorerie::where('compte_destination_id', $compte->id)
            ->when($jusquau, fn ($q) => $q->where('date_transfert', '<=', $jusquau))->sum('montant_cdf');
        $sorties = (float) TransfertTresorerie::where('compte_source_id', $compte->id)
            ->when($jusquau, fn ($q) => $q->where('date_transfert', '<=', $jusquau))->sum('montant_cdf');

        return round((float) $compte->solde_initial + $recettes + $entrees - $sorties - $depenses, 2);
    }

    /**
     * Journal de trésorerie d'un compte : recettes du jour (groupées),
     * dépenses payées et transferts, avec le solde courant.
     */
    public function journal(CompteTresorerie $compte, string $du, string $au): Collection
    {
        $soldeDepart = $this->solde($compte, \Illuminate\Support\Carbon::parse($du)->subDay()->toDateString());

        $recettes = $this->recettesDuCompte($compte, $du, $au)
            ->selectRaw('DATE(date_paiement) AS jour, SUM(montant_cdf) AS total, COUNT(*) AS n')
            ->groupBy('jour')->get()
            ->map(fn ($r) => (object) ['date' => $r->jour, 'libelle' => "Encaissements patients ({$r->n})", 'piece' => null, 'entree' => (float) $r->total, 'sortie' => 0.0]);

        $depenses = Depense::with('rubrique')->where('compte_tresorerie_id', $compte->id)->where('statut', 'payee')
            ->whereBetween('payee_at', [$du.' 00:00:00', $au.' 23:59:59'])->get()
            ->map(fn ($d) => (object) ['date' => $d->payee_at->toDateString(), 'libelle' => $d->libelle.' — '.$d->rubrique?->libelle, 'piece' => $d->numero, 'entree' => 0.0, 'sortie' => (float) $d->montant_cdf]);

        $transferts = TransfertTresorerie::with(['source', 'destination'])
            ->where(fn ($q) => $q->where('compte_source_id', $compte->id)->orWhere('compte_destination_id', $compte->id))
            ->whereBetween('date_transfert', [$du, $au])->get()
            ->map(fn ($t) => (object) [
                'date' => $t->date_transfert->toDateString(),
                'libelle' => $t->compte_source_id === $compte->id ? 'Transfert vers '.$t->destination?->nom : 'Transfert reçu de '.$t->source?->nom,
                'piece' => $t->numero,
                'entree' => $t->compte_destination_id === $compte->id ? (float) $t->montant_cdf : 0.0,
                'sortie' => $t->compte_source_id === $compte->id ? (float) $t->montant_cdf : 0.0,
            ]);

        $solde = $soldeDepart;

        return collect([(object) ['date' => $du, 'libelle' => 'Solde au '.\Illuminate\Support\Carbon::parse($du)->format('d/m/Y'), 'piece' => null, 'entree' => 0.0, 'sortie' => 0.0, 'solde' => $soldeDepart]])
            ->concat($recettes->concat($depenses)->concat($transferts)->sortBy('date')->values()
                ->map(function ($l) use (&$solde) {
                    $solde += $l->entree - $l->sortie;
                    $l->solde = round($solde, 2);

                    return $l;
                }));
    }

    /** Compte de résultat simplifié sur une période. */
    public function resultat(string $du, string $au): array
    {
        $recettes = (float) Paiement::whereBetween('date_paiement', [$du.' 00:00:00', $au.' 23:59:59'])->sum('montant_cdf');
        $parMode = Paiement::whereBetween('date_paiement', [$du.' 00:00:00', $au.' 23:59:59'])
            ->selectRaw('mode_paiement, SUM(montant_cdf) AS total')->groupBy('mode_paiement')->pluck('total', 'mode_paiement');
        $depenses = Depense::with('rubrique')->where('statut', 'payee')
            ->whereBetween('payee_at', [$du.' 00:00:00', $au.' 23:59:59'])->get();
        $parRubrique = $depenses->groupBy(fn ($d) => $d->rubrique?->libelle ?? '—')->map(fn ($g) => (float) $g->sum('montant_cdf'))->sortDesc();
        $totalDepenses = (float) $depenses->sum('montant_cdf');

        return [
            'recettes' => $recettes,
            'recettes_par_mode' => $parMode,
            'depenses' => $totalDepenses,
            'depenses_par_rubrique' => $parRubrique,
            'resultat' => round($recettes - $totalDepenses, 2),
        ];
    }

    /** Budget annuel : prévu, réalisé (dépenses payées), écart, taux d'exécution. */
    public function budget(int $annee): Collection
    {
        $prevus = Budget::where('annee', $annee)->pluck('montant_prevu_cdf', 'rubrique_id');
        $realises = Depense::where('statut', 'payee')->whereYear('payee_at', $annee)
            ->selectRaw('rubrique_id, SUM(montant_cdf) AS total')->groupBy('rubrique_id')->pluck('total', 'rubrique_id');

        return RubriqueDepense::orderBy('code')->get()->map(function ($r) use ($prevus, $realises) {
            $prevu = (float) ($prevus[$r->id] ?? 0);
            $realise = (float) ($realises[$r->id] ?? 0);

            return (object) [
                'rubrique' => $r, 'prevu' => $prevu, 'realise' => $realise, 'ecart' => round($prevu - $realise, 2),
                'taux' => $prevu > 0 ? round($realise / $prevu * 100, 1) : null,
            ];
        });
    }

    public function cloturer(string $mois, ?string $observations = null): ClotureFinanciere
    {
        if (ClotureFinanciere::where('mois', $mois)->exists()) {
            throw new \RuntimeException("Le mois {$mois} est déjà clôturé.");
        }
        $debut = \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $mois.'-01')->startOfMonth();
        if ($debut->copy()->endOfMonth()->isFuture()) {
            throw new \RuntimeException('On ne clôture qu\'un mois terminé.');
        }
        if (Depense::whereIn('statut', ['en_attente', 'approuvee'])->whereBetween('date_depense', [$debut, $debut->copy()->endOfMonth()])->exists()) {
            throw new \RuntimeException('Des dépenses de ce mois attendent encore approbation ou paiement.');
        }
        $resultat = $this->resultat($debut->toDateString(), $debut->copy()->endOfMonth()->toDateString());

        return ClotureFinanciere::create([
            'mois' => $mois,
            'total_recettes_cdf' => $resultat['recettes'],
            'total_depenses_cdf' => $resultat['depenses'],
            'observations' => $observations,
            'user_id' => auth()->id(),
        ]);
    }

    public function verifierPeriodeOuverte(\DateTimeInterface|string $date): void
    {
        if (ClotureFinanciere::estClos($date)) {
            throw new \RuntimeException('Cette période est clôturée : aucune opération ne peut plus y être enregistrée.');
        }
    }
}
