<?php

namespace App\Http\Controllers;

use App\Models\Ambulance;
use App\Models\Establishment;
use App\Models\SortieAmbulance;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Registre des ambulances : chaque sortie avec sa date, ses heures de départ
 * et d'arrivée, et la distance parcourue (compteur au départ et au retour).
 */
class AmbulanceController extends Controller
{
    public function index(Request $request): View
    {
        $this->autoriser();
        $du = $request->query('du', now()->startOfMonth()->toDateString());
        $au = $request->query('au', now()->toDateString());

        $sorties = SortieAmbulance::with(['ambulance', 'patient', 'auteur'])
            ->whereBetween('depart_at', [$du.' 00:00:00', $au.' 23:59:59'])
            ->orderByDesc('depart_at')
            ->paginate(30)->withQueryString();

        return view('ambulances.index', [
            'sorties' => $sorties,
            'ambulances' => Ambulance::orderBy('libelle')->get(),
            'enCours' => SortieAmbulance::with(['ambulance', 'patient'])->whereNull('arrivee_at')->orderBy('depart_at')->get(),
            'du' => $du,
            'au' => $au,
        ]);
    }

    public function enregistrerAmbulance(Request $request): RedirectResponse
    {
        $this->autoriser();
        $donnees = $request->validate([
            'libelle' => 'required|string|max:100',
            'immatriculation' => 'nullable|string|max:30',
        ], ['libelle.required' => 'Donnez un nom à l\'ambulance.']);

        Ambulance::create($donnees + [
            'establishment_id' => auth()->user()->establishment_id ?? Establishment::orderBy('created_at')->value('id'),
            'is_active' => true,
        ]);

        return back()->with('success', 'Ambulance « '.$donnees['libelle'].' » ajoutée.');
    }

    public function basculerAmbulance(Ambulance $ambulance): RedirectResponse
    {
        $this->autoriser();
        $ambulance->update(['is_active' => ! $ambulance->is_active]);

        return back()->with('success', 'Ambulance '.($ambulance->is_active ? 'réactivée' : 'retirée du service').'.');
    }

    /** Départ (et retour, si on l'enregistre après coup). */
    public function enregistrerSortie(Request $request): RedirectResponse
    {
        $this->autoriser();
        $donnees = $request->validate([
            'ambulance_id' => 'required|uuid|exists:ambulances,id',
            'patient_id' => 'nullable|uuid|exists:patients,id',
            'motif' => 'required|string|max:255',
            'destination' => 'nullable|string|max:255',
            'chauffeur' => 'nullable|string|max:150',
            'depart_at' => 'required|date',
            'arrivee_at' => 'nullable|date|after_or_equal:depart_at',
            'km_depart' => 'nullable|numeric|min:0',
            'km_arrivee' => 'nullable|numeric|gte:km_depart',
            'observations' => 'nullable|string|max:1000',
        ], [
            'motif.required' => 'Indiquez le motif de la sortie.',
            'arrivee_at.after_or_equal' => 'L\'arrivée ne peut pas précéder le départ.',
            'km_arrivee.gte' => 'Le compteur au retour est inférieur à celui du départ.',
        ]);

        SortieAmbulance::create($donnees + ['user_id' => auth()->id()]);

        return back()->with('success', 'Sortie d\'ambulance enregistrée.');
    }

    public function retour(Request $request, SortieAmbulance $sortie): RedirectResponse
    {
        $this->autoriser();
        $donnees = $request->validate([
            'arrivee_at' => 'required|date|after_or_equal:'.$sortie->depart_at->toDateTimeString(),
            'km_arrivee' => 'nullable|numeric|min:'.((float) $sortie->km_depart),
            'observations' => 'nullable|string|max:1000',
        ], [
            'arrivee_at.after_or_equal' => 'L\'arrivée ne peut pas précéder le départ.',
            'km_arrivee.min' => 'Le compteur au retour est inférieur à celui du départ.',
        ]);

        $sortie->update(array_filter($donnees, fn ($v) => $v !== null));

        return back()->with('success', 'Retour de l\'ambulance enregistré.');
    }

    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->can('ambulance.manage') || auth()->user()?->hasAnyRole(['super_admin', 'directeur']),
            403
        );
    }
}
