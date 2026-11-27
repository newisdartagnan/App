<?php

namespace App\Http\Controllers;

use App\Models\Budget;
use App\Models\ClotureFinanciere;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\Fournisseur;
use App\Models\ReceptionStock;
use App\Models\RubriqueDepense;
use App\Models\Service;
use App\Models\TransfertTresorerie;
use App\Services\FinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gestion financière : tableau de bord, dépenses (saisie, approbation,
 * paiement), comptes de trésorerie et transferts, journal, budget, compte
 * de résultat, clôture mensuelle.
 */
class FinanceController extends Controller
{
    public function __construct(private readonly FinanceService $finance) {}

    public function tableau(): View
    {
        $this->autoriser('finance.view', 'finance.depense', 'finance.valider');
        $du = now()->startOfMonth()->toDateString();
        $au = now()->toDateString();

        return view('finance.tableau', [
            'resultat' => $this->finance->resultat($du, $au),
            'comptes' => CompteTresorerie::where('est_actif', true)->orderBy('type')->get()->map(fn ($c) => (object) ['compte' => $c, 'solde' => $this->finance->solde($c)]),
            'enAttente' => Depense::with('rubrique')->where('statut', 'en_attente')->orderBy('date_depense')->limit(10)->get(),
            'aPayer' => Depense::with('rubrique')->where('statut', 'approuvee')->orderBy('date_depense')->limit(10)->get(),
            'fournisseursAPayer' => ReceptionStock::with('fournisseur')->whereColumn('montant_paye', '<', 'montant_total')->where('montant_total', '>', 0)->orderBy('date_reception')->limit(10)->get(),
            'budget' => $this->finance->budget((int) now()->year)->filter(fn ($b) => $b->prevu > 0 || $b->realise > 0),
        ]);
    }

    // ── Dépenses ─────────────────────────────────────────────────────────

    public function depenses(Request $request): View|Response
    {
        $this->autoriser('finance.view', 'finance.depense', 'finance.valider');
        $du = $request->query('du', now()->startOfMonth()->toDateString());
        $au = $request->query('au', now()->toDateString());
        $statut = (string) $request->query('statut', '');
        $rubrique = (string) $request->query('rubrique', '');

        $requete = Depense::with(['rubrique', 'compte', 'service', 'saisiePar'])
            ->whereBetween('date_depense', [$du, $au])
            ->when($statut !== '', fn ($q) => $q->where('statut', $statut))
            ->when($rubrique !== '', fn ($q) => $q->where('rubrique_id', $rubrique));

        if ($request->query('format') === 'csv') {
            return $this->csvDepenses((clone $requete)->orderBy('date_depense')->get(), $du, $au);
        }

        return view('finance.depenses', [
            'depenses' => $requete->orderByDesc('date_depense')->orderByDesc('created_at')->paginate(30)->withQueryString(),
            'total' => (float) (clone $requete)->where('statut', '!=', 'rejetee')->sum('montant_cdf'),
            'rubriques' => RubriqueDepense::orderBy('libelle')->get(),
            'comptes' => CompteTresorerie::where('est_actif', true)->get(),
            'du' => $du, 'au' => $au, 'statut' => $statut, 'rubrique' => $rubrique,
        ]);
    }

    public function creerDepense(Request $request): View
    {
        $this->autoriser('finance.depense', 'finance.valider');
        $reception = $request->query('reception') ? ReceptionStock::with('fournisseur')->find($request->query('reception')) : null;

        return view('finance.depense-form', [
            'rubriques' => RubriqueDepense::where('est_actif', true)->orderBy('libelle')->get(),
            'services' => Service::where('is_active', true)->orderBy('nom')->get(['id', 'nom']),
            'fournisseurs' => Fournisseur::where('est_actif', true)->orderBy('nom')->get(['id', 'nom']),
            'reception' => $reception,
            'rubriqueMedicaments' => RubriqueDepense::where('code', 'MED')->value('id'),
        ]);
    }

    public function enregistrerDepense(Request $request): RedirectResponse
    {
        $this->autoriser('finance.depense', 'finance.valider');
        $donnees = $request->validate([
            'date_depense' => 'required|date|before_or_equal:today',
            'rubrique_id' => 'required|uuid|exists:rubriques_depense,id',
            'service_id' => 'nullable|uuid|exists:services,id',
            'fournisseur_id' => 'nullable|uuid|exists:fournisseurs,id',
            'reception_id' => 'nullable|uuid|exists:receptions_stock,id',
            'beneficiaire' => 'nullable|string|max:200',
            'libelle' => 'required|string|max:255',
            'montant' => 'required|numeric|min:0.01',
            'devise' => 'required|in:CDF,USD',
            'mode_paiement' => ['nullable', Rule::in(array_keys(CompteTresorerie::MODES))],
            'reference_piece' => 'nullable|string|max:100',
            'piece' => 'nullable|file|max:8192|mimes:pdf,jpg,jpeg,png',
        ], [
            'libelle.required' => 'Décrivez la dépense.',
            'montant.min' => 'Le montant doit être positif.',
        ]);

        if ($request->hasFile('piece')) {
            $donnees['piece_path'] = $request->file('piece')->store('depenses', 'local');
        }
        unset($donnees['piece']);

        try {
            $depense = $this->finance->enregistrerDepense($donnees);
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('finance.depenses')->with('success', "Dépense {$depense->numero} enregistrée — en attente d'approbation.");
    }

    public function approuver(Depense $depense): RedirectResponse
    {
        $this->autoriser('finance.valider');

        return $this->tenter(fn () => $this->finance->approuver($depense), "Dépense {$depense->numero} approuvée.");
    }

    public function rejeter(Request $request, Depense $depense): RedirectResponse
    {
        $this->autoriser('finance.valider');
        $motif = $request->validate(['motif_rejet' => 'required|string|max:1000'], ['motif_rejet.required' => 'Indiquez le motif du rejet.'])['motif_rejet'];

        return $this->tenter(fn () => $this->finance->rejeter($depense, $motif), "Dépense {$depense->numero} rejetée.");
    }

    public function payer(Request $request, Depense $depense): RedirectResponse
    {
        $this->autoriser('finance.depense', 'finance.valider');
        $donnees = $request->validate([
            'compte_tresorerie_id' => 'required|uuid|exists:comptes_tresorerie,id',
            'mode_paiement' => ['nullable', Rule::in(array_keys(CompteTresorerie::MODES))],
        ], ['compte_tresorerie_id.required' => 'Choisissez le compte qui paie.']);

        return $this->tenter(
            fn () => $this->finance->payer($depense, CompteTresorerie::findOrFail($donnees['compte_tresorerie_id']), $donnees['mode_paiement'] ?? null),
            "Dépense {$depense->numero} payée."
        );
    }

    public function piece(Depense $depense): Response
    {
        $this->autoriser('finance.view', 'finance.depense', 'finance.valider');
        abort_unless($depense->piece_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($depense->piece_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($depense->piece_path);
    }

    // ── Comptes et transferts ────────────────────────────────────────────

    public function comptes(): View
    {
        $this->autoriser('finance.view', 'finance.valider');

        return view('finance.comptes', [
            'comptes' => CompteTresorerie::orderBy('type')->get()->map(fn ($c) => (object) ['compte' => $c, 'solde' => $this->finance->solde($c)]),
            'transferts' => TransfertTresorerie::with(['source', 'destination'])->orderByDesc('date_transfert')->limit(20)->get(),
        ]);
    }

    public function enregistrerCompte(Request $request, ?CompteTresorerie $compte = null): RedirectResponse
    {
        $this->autoriser('finance.valider');
        $donnees = $request->validate([
            'nom' => 'required|string|max:150',
            'type' => ['required', Rule::in(array_keys(CompteTresorerie::TYPES))],
            'numero' => 'nullable|string|max:100',
            'modes_paiement' => 'nullable|array',
            'modes_paiement.*' => [Rule::in(array_keys(CompteTresorerie::MODES))],
            'solde_initial' => 'nullable|numeric',
            'date_solde_initial' => 'nullable|date',
        ]);

        // Un mode d'encaissement n'arrive que sur un seul compte.
        $modes = $donnees['modes_paiement'] ?? [];
        $conflit = CompteTresorerie::where('id', '!=', $compte?->id)->get()
            ->first(fn ($c) => array_intersect($modes, (array) $c->modes_paiement) !== []);
        if ($conflit) {
            return back()->withInput()->with('error', 'Un mode de paiement ne peut alimenter qu\'un compte : « '.$conflit->nom.' » le reçoit déjà.');
        }

        $compte ??= new CompteTresorerie(['devise' => 'CDF', 'est_actif' => true]);
        $compte->fill($donnees + ['modes_paiement' => $modes])->save();

        return back()->with('success', "Compte « {$compte->nom} » enregistré.");
    }

    public function transferer(Request $request): RedirectResponse
    {
        $this->autoriser('finance.depense', 'finance.valider');
        $donnees = $request->validate([
            'compte_source_id' => 'required|uuid|exists:comptes_tresorerie,id',
            'compte_destination_id' => 'required|uuid|exists:comptes_tresorerie,id|different:compte_source_id',
            'montant_cdf' => 'required|numeric|min:1',
            'date_transfert' => 'required|date|before_or_equal:today',
            'reference' => 'nullable|string|max:100',
            'observations' => 'nullable|string|max:1000',
        ], ['compte_destination_id.different' => 'Choisissez deux comptes différents.']);

        return $this->tenter(fn () => $this->finance->transferer(
            CompteTresorerie::findOrFail($donnees['compte_source_id']),
            CompteTresorerie::findOrFail($donnees['compte_destination_id']),
            (float) $donnees['montant_cdf'], $donnees['reference'] ?? null, $donnees['observations'] ?? null, $donnees['date_transfert']
        ), 'Transfert enregistré.');
    }

    public function journal(Request $request, CompteTresorerie $compte): View
    {
        $this->autoriser('finance.view', 'finance.valider');
        $du = $request->query('du', now()->startOfMonth()->toDateString());
        $au = $request->query('au', now()->toDateString());

        return view('finance.journal', ['compte' => $compte, 'lignes' => $this->finance->journal($compte, $du, $au), 'du' => $du, 'au' => $au]);
    }

    // ── Budget, résultat, clôture ────────────────────────────────────────

    public function budget(Request $request): View
    {
        $this->autoriser('finance.view', 'finance.valider');
        $annee = (int) $request->query('annee', now()->year);

        return view('finance.budget', ['annee' => $annee, 'lignes' => $this->finance->budget($annee)]);
    }

    public function enregistrerBudget(Request $request): RedirectResponse
    {
        $this->autoriser('finance.valider');
        $donnees = $request->validate([
            'annee' => 'required|integer|min:2020|max:2100',
            'prevus' => 'array',
            'prevus.*' => 'nullable|numeric|min:0',
        ]);
        foreach ($donnees['prevus'] ?? [] as $rubriqueId => $montant) {
            if (RubriqueDepense::whereKey($rubriqueId)->exists()) {
                Budget::updateOrCreate(['annee' => $donnees['annee'], 'rubrique_id' => $rubriqueId], ['montant_prevu_cdf' => (float) ($montant ?? 0)]);
            }
        }

        return back()->with('success', "Budget {$donnees['annee']} enregistré.");
    }

    public function enregistrerRubrique(Request $request): RedirectResponse
    {
        $this->autoriser('finance.valider');
        $donnees = $request->validate([
            'code' => 'required|string|max:20|unique:rubriques_depense,code',
            'libelle' => 'required|string|max:150',
            'nature' => ['required', Rule::in(array_keys(RubriqueDepense::NATURES))],
        ]);
        RubriqueDepense::create($donnees + ['est_actif' => true]);

        return back()->with('success', 'Rubrique ajoutée.');
    }

    public function resultat(Request $request): View
    {
        $this->autoriser('finance.view', 'finance.valider');
        $du = $request->query('du', now()->startOfMonth()->toDateString());
        $au = $request->query('au', now()->toDateString());

        return view('finance.resultat', [
            'resultat' => $this->finance->resultat($du, $au),
            'du' => $du, 'au' => $au,
            'clotures' => ClotureFinanciere::with('auteur')->orderByDesc('mois')->limit(24)->get(),
        ]);
    }

    public function cloturer(Request $request): RedirectResponse
    {
        $this->autoriser('finance.valider');
        $donnees = $request->validate(['mois' => ['required', 'regex:/^\d{4}-\d{2}$/'], 'observations' => 'nullable|string|max:1000']);

        return $this->tenter(fn () => $this->finance->cloturer($donnees['mois'], $donnees['observations'] ?? null),
            'Mois '.Carbon::createFromFormat('Y-m', $donnees['mois'])->translatedFormat('F Y').' clôturé.');
    }

    // ─────────────────────────────────────────────────────────────────────

    private function tenter(callable $action, string $succes): RedirectResponse
    {
        try {
            $action();
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $succes);
    }

    private function csvDepenses($depenses, string $du, string $au): Response
    {
        return response()->streamDownload(function () use ($depenses) {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['N°', 'Date', 'Rubrique', 'Libellé', 'Bénéficiaire', 'Montant', 'Devise', 'Équivalent CDF', 'Statut', 'Compte'], ';');
            foreach ($depenses as $d) {
                fputcsv($f, [$d->numero, $d->date_depense->format('d/m/Y'), $d->rubrique?->libelle, $d->libelle, $d->beneficiaire,
                    $d->montant, $d->devise, $d->montant_cdf, Depense::STATUTS[$d->statut] ?? $d->statut, $d->compte?->nom], ';');
            }
            fclose($f);
        }, "depenses_{$du}_{$au}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function autoriser(string ...$droits): void
    {
        $u = auth()->user();
        abort_unless(
            $u?->hasAnyRole(['super_admin', 'directeur']) || collect($droits)->contains(fn ($d) => $u?->can($d)),
            403,
            'Gestion financière réservée.'
        );
    }
}
