<?php

namespace App\Http\Controllers;

use App\Models\BilanHydrique;
use App\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bilan hydrique du dossier infirmier : entrées et sorties par tranche
 * horaire, avec la balance du jour et le cumul du séjour.
 */
class BilanHydriqueController extends Controller
{
    public function index(Request $request, Visit $visit): View
    {
        $jour = $request->query('jour', now()->toDateString());

        $visit->load(['patient', 'service', 'lit']);

        $bilans = BilanHydrique::with('auteur')
            ->where('visit_id', $visit->id)
            ->whereDate('jour', $jour)
            ->get()
            ->keyBy('tranche');

        $historique = BilanHydrique::where('visit_id', $visit->id)
            ->orderByDesc('jour')
            ->get()
            ->groupBy(fn ($b) => $b->jour->toDateString());

        return view('mar.bilan-hydrique', compact('visit', 'jour', 'bilans', 'historique'));
    }

    public function store(Request $request, Visit $visit): RedirectResponse
    {
        $champs = array_merge(array_keys(BilanHydrique::ENTREES), array_keys(BilanHydrique::SORTIES));

        $request->validate(array_merge(
            [
                'jour' => 'required|date',
                'tranche' => 'required|in:matin,apres_midi,nuit',
                'observation' => 'nullable|string|max:500',
            ],
            array_fill_keys(array_map(fn ($c) => $c, $champs), 'nullable|integer|min:0|max:20000')
        ));

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le dossier est clos.');
        }

        $valeurs = collect($champs)->mapWithKeys(
            fn ($c) => [$c => (int) $request->input($c, 0)]
        )->all();

        BilanHydrique::updateOrCreate(
            ['visit_id' => $visit->id, 'jour' => $request->jour, 'tranche' => $request->tranche],
            $valeurs + ['user_id' => auth()->id(), 'observation' => $request->observation]
        );

        return back()->with('success', 'Bilan hydrique enregistré.');
    }
}
