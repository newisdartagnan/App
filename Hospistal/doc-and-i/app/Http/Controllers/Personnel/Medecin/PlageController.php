<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Services\Personnel\DisponibilitesMedecinService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** RDV-04 : plages hebdomadaires du médecin connecté, heure de Kinshasa. */
class PlageController extends ControleurMedecin
{
    public function __construct(private readonly DisponibilitesMedecinService $disponibilites) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Personnel/Medecin/Plages', [
            'plages' => $this->disponibilites->plages($this->medecin($request)),
            'jours' => DisponibilitesMedecinService::JOURS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $medecin = $this->medecin($request);

        $valides = $request->validate([
            'jour_semaine' => ['required', 'integer', 'between:1,7'],
            'heure_debut' => ['required', 'date_format:H:i'],
            'heure_fin' => ['required', 'date_format:H:i'],
        ]);

        $this->disponibilites->ajouterPlage($medecin, (int) $valides['jour_semaine'], $valides['heure_debut'], $valides['heure_fin']);

        return redirect()->route('personnel.medecin.plages')->with('succes', 'Plage ajoutée.');
    }

    public function destroy(Request $request, string $plage): RedirectResponse
    {
        $this->disponibilites->supprimerPlage($this->medecin($request), $plage);

        return redirect()->route('personnel.medecin.plages')->with('succes', 'Plage supprimée.');
    }
}
