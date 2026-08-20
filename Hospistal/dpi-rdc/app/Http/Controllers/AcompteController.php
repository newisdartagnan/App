<?php

namespace App\Http\Controllers;

use App\Models\Caution;
use App\Models\Visit;
use App\Services\AcompteService;
use App\Services\DeviseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Acomptes de soins des urgences et des hospitalisations : encaissement à
 * l'admission, imputation sur les factures du séjour, remboursement du
 * reliquat à la sortie.
 */
class AcompteController extends Controller
{
    public function __construct(private readonly AcompteService $acomptes) {}

    /** Registre des acomptes : ce qui a été avancé, imputé, remboursé. */
    public function index(Request $request): View
    {
        // Un registre montre tout par défaut : filtrer sur « versé »
        // masquait les acomptes déjà imputés, c'est-à-dire la majorité.
        $statut = $request->query('statut', 'tous');

        $acomptes = Caution::with(['patient', 'visit.service', 'caissier'])
            ->when($statut !== 'tous', fn ($q) => $q->where('statut', $statut))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        // Les totaux du registre sont en monnaie de compte : additionner des
        // francs et des dollars n'a de sens qu'après conversion.
        $totaux = [
            'verse' => (float) Caution::sum('montant_cdf'),
            'impute' => (float) Caution::sum('montant_impute_cdf'),
            'rembourse' => (float) Caution::sum('montant_rembourse_cdf'),
        ];
        $totaux['disponible'] = $totaux['verse'] - $totaux['impute'] - $totaux['rembourse'];

        // Détail par devise réellement détenue au guichet.
        $parDevise = Caution::selectRaw(
            'devise, SUM(montant) AS verse, SUM(montant - montant_impute - montant_rembourse) AS disponible'
        )->groupBy('devise')->get();

        return view('acomptes.index', compact('acomptes', 'statut', 'totaux', 'parDevise'));
    }

    /** Acomptes d'un séjour, avec le détail de chaque imputation. */
    public function show(Visit $visit): View
    {
        $visit->load(['patient', 'service', 'lit', 'factures']);

        $acomptes = Caution::with(['caissier', 'imputations.facture', 'imputations.auteur'])
            ->where('visit_id', $visit->id)
            ->orderBy('created_at')
            ->get();

        return view('acomptes.show', [
            'visit' => $visit,
            'acomptes' => $acomptes,
            'disponible' => $this->acomptes->soldeDisponible($visit->id),
            'totalVerse' => $this->acomptes->totalVerse($visit->id),
            'parDevise' => $this->acomptes->soldeParDevise($visit->patient_id),
        ]);
    }

    public function store(Request $request, Visit $visit): RedirectResponse
    {
        $donnees = $request->validate([
            'montant' => 'required|numeric|min:1|max:100000000',
            'devise' => 'required|'.app(DeviseService::class)->regleValidation(),
            'mode_paiement' => 'required|in:'.implode(',', array_keys(Caution::MODES_PAIEMENT)),
            'type' => 'required|in:'.implode(',', array_keys(Caution::TYPES)),
            'motif' => 'nullable|string|max:500',
            'reference' => 'nullable|string|max:200',
        ]);

        if (! in_array($visit->type, AcompteService::TYPES_VISITE, true)) {
            return back()->with('error', 'Un acompte ne se prend qu\'aux urgences ou en hospitalisation.');
        }

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le dossier est clos.');
        }

        $acompte = $this->acomptes->encaisser(
            $visit,
            (float) $donnees['montant'],
            $donnees['devise'],
            $donnees['mode_paiement'],
            $donnees['type'],
            $donnees['motif'] ?? null,
            $donnees['reference'] ?? null,
        );

        $acompte->refresh();
        $devises = app(DeviseService::class);
        $impute = (float) $acompte->montant_impute;

        return back()->with('success', $impute > 0
            ? 'Acompte de '.$acompte->montantFormate().' encaissé, dont '
                .$devises->formater($impute, $acompte->devise)
                .' imputés immédiatement sur les factures ouvertes.'
            : 'Acompte de '.$acompte->montantFormate().' encaissé.');
    }

    /** Rend au patient ce qui reste de ses avances, après imputation. */
    public function rembourser(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate(['reference' => 'nullable|string|max:200']);

        // Le reliquat est rendu dans la devise de chaque versement : une
        // avance en dollars se rembourse en dollars, pas en francs.
        $rendus = $this->acomptes->rembourser($visit, $request->input('reference'));

        if ($rendus === []) {
            return back()->with('error', 'Aucun reliquat à rembourser : les acomptes ont tous été imputés.');
        }

        $devises = app(DeviseService::class);
        $detail = collect($rendus)
            ->map(fn ($montant, $devise) => $devises->formater((float) $montant, $devise))
            ->implode(' et ');

        return back()->with('success', 'Reliquat de '.$detail.' remboursé au patient.');
    }
}
