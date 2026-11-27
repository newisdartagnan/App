<?php

namespace App\Http\Controllers;

use App\Models\Assurance;
use App\Models\MethodePaiement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Les méthodes de paiement de l'établissement, partagées entre patients. */
class MethodePaiementController extends Controller
{
    public function index(): View
    {
        $this->autoriser();

        return view('catalogue.methodes-paiement', [
            'methodes' => MethodePaiement::with('assurance')->withCount('liens')->orderBy('libelle')->get(),
            'assurances' => Assurance::where('est_actif', true)->orderBy('nom')->get(['id', 'nom']),
        ]);
    }

    public function enregistrer(Request $request, ?MethodePaiement $methode = null): RedirectResponse
    {
        $this->autoriser();
        $methode ??= new MethodePaiement;
        $donnees = $request->validate([
            'libelle' => ['required', 'string', 'max:150', Rule::unique('methodes_paiement', 'libelle')->ignore($methode->id)],
            'type' => ['required', Rule::in(array_keys(MethodePaiement::TYPES))],
            'assurance_id' => 'nullable|uuid|exists:assurances,id',
        ], ['libelle.unique' => 'Cette méthode existe déjà.']);

        $methode->fill($donnees + ['est_actif' => $methode->exists ? $methode->est_actif : true])->save();

        return back()->with('success', "Méthode « {$methode->libelle} » enregistrée.");
    }

    public function basculer(MethodePaiement $methode): RedirectResponse
    {
        $this->autoriser();
        $methode->update(['est_actif' => ! $methode->est_actif]);

        return back()->with('success', "Méthode « {$methode->libelle} » ".($methode->est_actif ? 'réactivée' : 'désactivée').'.');
    }

    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->can('settings.manage') || auth()->user()?->can('facture.create')
                || auth()->user()?->hasAnyRole(['super_admin', 'directeur']),
            403
        );
    }
}
