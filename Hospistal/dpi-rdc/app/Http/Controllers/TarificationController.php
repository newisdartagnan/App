<?php

namespace App\Http\Controllers;

use App\Models\ActeCatalogue;
use App\Models\Assurance;
use App\Models\CategorieTarifaire;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Catégories de convention × fonctions tarifaires, et catalogue des actes.
 *
 * La grille se tient comme au registre : une ligne par catégorie (A, B, C,
 * D, CD, DD…), une colonne par fonction tarifaire (la première est 1). La
 * fiche d'un acte dit sa fonction tarifaire, ses prix normal / urgent /
 * externe et sa disponibilité ; l'onglet « Prise en charge selon tarif »
 * montre le prix qui en résulte dans chaque catégorie.
 */
class TarificationController extends Controller
{
    // ── Catégories de convention ─────────────────────────────────────────

    public function categories(): View
    {
        $this->autoriser();

        return view('tarification.categories', [
            'categories' => CategorieTarifaire::withCount('assurances')->orderBy('numero')->get(),
            'conventions' => Assurance::with('categorieTarifaire')->orderBy('nom')->get(),
            'nbFonctions' => CategorieTarifaire::NB_FONCTIONS,
        ]);
    }

    public function enregistrerCategorie(Request $request, ?CategorieTarifaire $categorie = null): RedirectResponse
    {
        $this->autoriser();
        $categorie ??= new CategorieTarifaire;

        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:30', Rule::unique('categories_tarifaires', 'nom')->ignore($categorie->id)],
            'commentaire' => 'nullable|string|max:1000',
            'coefficients' => 'required|array|size:'.CategorieTarifaire::NB_FONCTIONS,
            'coefficients.*' => 'nullable|numeric|min:0|max:100',
        ], [
            'nom.unique' => 'Une catégorie porte déjà ce nom.',
            'coefficients.*.numeric' => 'Chaque coefficient est un nombre (ex. : 0,8088).',
        ]);

        $coefficients = array_map(
            fn ($c) => $c === null || $c === '' ? 1.0 : round((float) str_replace(',', '.', (string) $c), 4),
            array_values($donnees['coefficients'])
        );

        $categorie->fill([
            'nom' => mb_strtoupper(trim($donnees['nom'])),
            'commentaire' => $donnees['commentaire'] ?? null,
            'coefficients' => $coefficients,
            'numero' => $categorie->numero ?? ((int) CategorieTarifaire::max('numero') + 1),
        ])->save();

        return back()->with('success', "Catégorie {$categorie->nom} enregistrée.");
    }

    public function supprimerCategorie(CategorieTarifaire $categorie): RedirectResponse
    {
        $this->autoriser();
        if ($categorie->assurances()->exists()) {
            return back()->with('error', 'Des conventions sont classées dans cette catégorie : reclassez-les d\'abord.');
        }
        $categorie->delete();

        return back()->with('success', 'Catégorie supprimée.');
    }

    /** Classe une convention dans une catégorie. */
    public function classerConvention(Request $request, Assurance $assurance): RedirectResponse
    {
        $this->autoriser();
        $donnees = $request->validate(['categorie_tarifaire_id' => 'nullable|uuid|exists:categories_tarifaires,id']);
        $assurance->update(['categorie_tarifaire_id' => $donnees['categorie_tarifaire_id'] ?? null]);

        return back()->with('success', "« {$assurance->nom} » : ".($assurance->categorieTarifaire?->nom ? 'catégorie '.$assurance->categorieTarifaire->nom : 'sans catégorie').'.');
    }

    // ── Catalogue des actes ──────────────────────────────────────────────

    public function actes(Request $request): View
    {
        $this->autoriser();
        $recherche = trim((string) $request->query('recherche', ''));
        $specialite = (string) $request->query('specialite', '');

        return view('tarification.actes', [
            'actes' => ActeCatalogue::query()
                ->when($recherche !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('LOWER(libelle) LIKE ?', ['%'.mb_strtolower($recherche).'%'])
                    ->orWhere('numero', (int) $recherche)))
                ->when($specialite !== '', fn ($q) => $q->where('specialite', $specialite))
                ->orderBy('specialite')->orderBy('libelle')
                ->paginate(40)->withQueryString(),
            'specialites' => ActeCatalogue::whereNotNull('specialite')->distinct()->orderBy('specialite')->pluck('specialite'),
            'recherche' => $recherche,
            'specialite' => $specialite,
        ]);
    }

    public function creerActe(): View
    {
        $this->autoriser();

        return $this->fiche(new ActeCatalogue([
            'numero' => (int) ActeCatalogue::max('numero') + 1,
            'fonction_tarifaire' => 1, 'disponibilite' => 'oui', 'devise' => 'USD', 'domaine' => 'chirurgie',
        ]));
    }

    public function modifierActe(ActeCatalogue $acte): View
    {
        $this->autoriser();

        return $this->fiche($acte);
    }

    public function enregistrerActe(Request $request, ?ActeCatalogue $acte = null): RedirectResponse
    {
        $this->autoriser();
        $acte ??= new ActeCatalogue;

        $donnees = $request->validate([
            'numero' => ['required', 'integer', 'min:1', Rule::unique('actes_catalogue', 'numero')->ignore($acte->id)],
            'libelle' => 'required|string|max:255',
            'fonction_tarifaire' => 'required|integer|min:1|max:'.CategorieTarifaire::NB_FONCTIONS,
            'prix_normal' => 'required|numeric|min:0',
            'prix_urgent' => 'nullable|numeric|min:0',
            'prix_externe' => 'nullable|numeric|min:0',
            'devise' => 'required|in:USD,CDF',
            'specialite' => 'nullable|string|max:150',
            'disponibilite' => ['required', Rule::in(array_keys(ActeCatalogue::DISPONIBILITES))],
            'categorie_acte' => 'nullable|string|max:100',
            'domaine' => ['required', Rule::in(array_keys(ActeCatalogue::DOMAINES))],
            'duree_minutes' => 'nullable|integer|min:5|max:1440',
            'observation' => 'nullable|string|max:2000',
        ], [
            'numero.unique' => 'Cet identifiant d\'acte est déjà utilisé.',
            'libelle.required' => 'Le libellé de l\'acte est obligatoire.',
        ]);

        $acte->fill($donnees)->save();

        return redirect()->route('tarification.actes.edit', $acte)->with('success', "Acte « {$acte->libelle} » enregistré.");
    }

    private function fiche(ActeCatalogue $acte): View
    {
        return view('tarification.acte-fiche', [
            'acte' => $acte,
            'categories' => CategorieTarifaire::orderBy('numero')->get(),
            'specialites' => ActeCatalogue::whereNotNull('specialite')->distinct()->orderBy('specialite')->pluck('specialite'),
            'nbFonctions' => CategorieTarifaire::NB_FONCTIONS,
        ]);
    }

    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->can('settings.manage') || auth()->user()?->can('facture.annuler')
                || auth()->user()?->hasAnyRole(['super_admin', 'directeur']),
            403
        );
    }
}
