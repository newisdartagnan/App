<?php

namespace App\Http\Controllers;

use App\Models\TriageUrgence;
use App\Models\Visit;
use App\Services\TriageUrgenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Urgences : le triage structuré précède la prise en charge clinique.
 * Deux files distinctes, comme dans le système de référence.
 */
class UrgenceController extends Controller
{
    public function __construct(protected TriageUrgenceService $triage) {}

    public function index(Request $request): View
    {
        $onglet = $request->query('onglet', 'a_trier');

        $visites = Visit::with(['patient', 'triagesUrgence' => fn ($q) => $q->latest('triage_at')])
            ->where('type', 'urgence')
            ->where('statut', '!=', 'annule')
            ->orderByDesc('date_entree')
            ->get();

        $aTrier = $visites->filter(fn ($v) => $v->triagesUrgence->isEmpty() && $v->statut !== 'termine');
        $priseEnCharge = $visites->filter(fn ($v) => $v->triagesUrgence->isNotEmpty() && $v->statut !== 'termine')
            // Les plus graves d'abord, puis les plus anciens
            ->sortBy([
                fn ($a, $b) => $a->triagesUrgence->first()->niveau <=> $b->triagesUrgence->first()->niveau,
                fn ($a, $b) => $a->date_entree <=> $b->date_entree,
            ]);
        $terminees = $visites->where('statut', 'termine')->take(20);

        return view('urgences.index', compact('onglet', 'aTrier', 'priseEnCharge', 'terminees'));
    }

    /**
     * Formulaire de triage. Un triage déjà fait est proposé en révision
     * plutôt que refait à zéro.
     */
    public function triage(Visit $visit): View|RedirectResponse
    {
        if ($visit->type !== 'urgence') {
            return redirect()->route('visites.triage', $visit);
        }

        $visit->load('patient');
        $precedent = TriageUrgence::where('visit_id', $visit->id)
            ->latest('triage_at')->first();

        return view('urgences.triage', [
            'visit' => $visit,
            'grille' => $this->triage->grille(),
            'precedent' => $precedent,
            'niveaux' => TriageUrgence::NIVEAUX,
        ]);
    }

    public function storeTriage(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate([
            'criteres' => 'nullable|array',
            'criteres.*' => 'string|max:60',
            'observation' => 'nullable|string|max:1000',
        ]);

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le triage n\'est plus modifiable.');
        }

        $triage = $this->triage->enregistrer(
            $visit,
            $request->input('criteres', []),
            $request->boolean('atr'),
            $request->input('observation')
        );

        return redirect()->route('urgences.index', ['onglet' => 'prise_en_charge'])
            ->with('success', sprintf(
                'Triage enregistré — niveau %d (%s) : %s.',
                $triage->niveau,
                $triage->libelleNiveau(),
                $triage->descriptionNiveau()
            ));
    }

    /**
     * Registre des triages : distribution des niveaux sur une période.
     */
    public function registre(Request $request): View
    {
        $debut = $request->query('debut', now()->startOfMonth()->toDateString());
        $fin = $request->query('fin', now()->toDateString());

        $triages = TriageUrgence::with(['visit.patient', 'auteur'])
            ->whereBetween('triage_at', [$debut . ' 00:00:00', $fin . ' 23:59:59'])
            ->orderByDesc('triage_at')
            ->get();

        $parNiveau = collect(TriageUrgence::NIVEAUX)
            ->map(fn ($info, $niveau) => [
                'info' => $info,
                'total' => $triages->where('niveau', $niveau)->count(),
            ]);

        return view('urgences.registre', compact('triages', 'parNiveau', 'debut', 'fin'));
    }
}
