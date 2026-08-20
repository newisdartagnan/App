<?php

namespace App\Http\Controllers;

use App\Models\Assurance;
use App\Models\Forfait;
use App\Models\Visit;
use App\Services\ForfaitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Règles de forfait : le référentiel des forfaits de l'établissement et
 * leur application à un séjour.
 */
class ForfaitController extends Controller
{
    public function __construct(private readonly ForfaitService $forfaits) {}

    public function index(): View
    {
        $forfaits = Forfait::with('assurance')
            ->where('establishment_id', auth()->user()->establishment_id)
            ->orderBy('portee')
            ->orderBy('libelle')
            ->get();

        $assurances = Assurance::where('est_actif', true)->orderBy('nom')->get();

        return view('forfaits.index', compact('forfaits', 'assurances'));
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'code' => 'required|string|max:30',
            'libelle' => 'required|string|max:150',
            'description' => 'nullable|string|max:1000',
            'portee' => 'required|in:global,partiel',
            'montant' => 'required|numeric|min:0|max:100000000',
            'devise' => 'required|in:CDF,USD',
            'categories_couvertes' => 'nullable|array',
            'categories_couvertes.*' => 'in:'.implode(',', array_keys(Forfait::CATEGORIES)),
            'jours_inclus' => 'nullable|integer|min:1|max:365',
            'assurance_id' => 'nullable|uuid|exists:assurances,id',
        ]);

        $etablissement = auth()->user()->establishment_id;
        $code = strtoupper($donnees['code']);

        if (Forfait::where('establishment_id', $etablissement)->where('code', $code)->exists()) {
            return back()->withInput()->with('error', 'Un forfait porte déjà le code '.$code.'.');
        }

        // Un forfait partiel qui ne couvre rien ne sert à rien : autant le
        // refuser tout de suite plutôt que d'émettre des factures muettes.
        if ($donnees['portee'] === 'partiel' && empty($donnees['categories_couvertes'])) {
            return back()->withInput()->with('error', 'Un forfait partiel doit couvrir au moins une catégorie.');
        }

        Forfait::create([
            ...$donnees,
            'code' => $code,
            'establishment_id' => $etablissement,
            'categories_couvertes' => $donnees['portee'] === 'global'
                ? array_keys(Forfait::CATEGORIES)
                : ($donnees['categories_couvertes'] ?? []),
            'is_active' => true,
        ]);

        return back()->with('success', 'Forfait '.$donnees['libelle'].' enregistré.');
    }

    public function basculer(Forfait $forfait): RedirectResponse
    {
        $forfait->update(['is_active' => ! $forfait->is_active]);

        return back()->with('success', $forfait->is_active
            ? 'Forfait '.$forfait->libelle.' réactivé.'
            : 'Forfait '.$forfait->libelle.' désactivé — il ne sera plus proposé.');
    }

    /** Applique un forfait à un séjour et émet sa facture. */
    public function appliquer(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate(['forfait_id' => 'required|uuid|exists:forfaits,id']);

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le dossier est clos.');
        }

        if ($visit->forfait_id) {
            return back()->with('error', 'Ce séjour porte déjà le forfait « '.$visit->forfait->libelle.' ».');
        }

        $forfait = Forfait::findOrFail($request->forfait_id);

        if (! $forfait->is_active) {
            return back()->with('error', 'Ce forfait est désactivé.');
        }

        $facture = $this->forfaits->appliquer($visit, $forfait);

        return redirect()->route('caisse.show', $facture)
            ->with('success', 'Forfait « '.$forfait->libelle.' » appliqué — facture émise au guichet.');
    }

    public function retirer(Visit $visit): RedirectResponse
    {
        if (! $visit->forfait_id) {
            return back()->with('error', 'Aucun forfait n\'est appliqué à ce séjour.');
        }

        $libelle = $visit->forfait->libelle;
        $this->forfaits->retirer($visit);

        return back()->with('success', 'Forfait « '.$libelle.' » retiré. '
            .'La facture déjà émise reste à annuler au guichet si elle n\'a pas été réglée.');
    }
}
