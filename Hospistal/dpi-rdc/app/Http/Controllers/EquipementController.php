<?php
namespace App\Http\Controllers;

use App\Models\Equipement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EquipementController extends Controller
{
    public function index(): View
    {
        $equipements = Equipement::orderBy('type')->orderBy('nom')->get()->groupBy('type');

        return view('equipements.index', compact('equipements'));
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => 'required|string|max:150',
            'type' => 'required|in:labo,imagerie,autre',
            'marque' => 'nullable|string|max:100',
            'modele' => 'nullable|string|max:100',
            'numero_serie' => 'nullable|string|max:100',
            'localisation' => 'nullable|string|max:150',
            'prochaine_maintenance' => 'nullable|date',
        ]);

        Equipement::create($donnees + ['statut' => 'operationnel']);

        return back()->with('success', 'Équipement ajouté.');
    }
}
