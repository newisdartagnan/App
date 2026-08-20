<?php

namespace App\Http\Controllers;

use App\Models\Caution;
use App\Models\Visit;
use App\Services\AcompteService;
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

        $totaux = [
            'verse' => (float) Caution::sum('montant'),
            'impute' => (float) Caution::sum('montant_impute'),
            'rembourse' => (float) Caution::sum('montant_rembourse'),
        ];
        $totaux['disponible'] = $totaux['verse'] - $totaux['impute'] - $totaux['rembourse'];

        return view('acomptes.index', compact('acomptes', 'statut', 'totaux'));
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
        ]);
    }

    public function store(Request $request, Visit $visit): RedirectResponse
    {
        $donnees = $request->validate([
            'montant' => 'required|numeric|min:1|max:100000000',
            'devise' => 'required|in:CDF,USD',
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

        $impute = (float) $acompte->fresh()->montant_impute;

        return back()->with('success', $impute > 0
            ? 'Acompte de '.number_format((float) $acompte->montant, 0, ',', ' ').' CDF encaissé, dont '
                .number_format($impute, 0, ',', ' ').' CDF imputés immédiatement sur les factures ouvertes.'
            : 'Acompte de '.number_format((float) $acompte->montant, 0, ',', ' ').' CDF encaissé.');
    }

    /** Rend au patient ce qui reste de ses avances, après imputation. */
    public function rembourser(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate(['reference' => 'nullable|string|max:200']);

        $montant = $this->acomptes->rembourser($visit, $request->input('reference'));

        return back()->with($montant > 0 ? 'success' : 'error', $montant > 0
            ? 'Reliquat de '.number_format($montant, 0, ',', ' ').' CDF remboursé au patient.'
            : 'Aucun reliquat à rembourser : les acomptes ont tous été imputés.');
    }
}
