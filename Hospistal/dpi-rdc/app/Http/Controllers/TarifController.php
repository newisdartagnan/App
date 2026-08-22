<?php

namespace App\Http\Controllers;

use App\Models\Medicament;
use App\Models\StockMedicament;
use App\Models\TypeConsultation;
use App\Models\TypeDiete;
use App\Models\TypeExamen;
use App\Services\DeviseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tarifs de l'établissement : consultations, examens, produits, diètes.
 *
 * Les prix étaient posés une fois par le peuplement initial et plus rien ne
 * permettait d'y revenir. Un hôpital qui ne peut pas relever le prix d'une
 * consultation sans appeler un informaticien n'a pas de tarification : il a
 * une photographie.
 *
 * Un produit ne se supprime pas, il se retire du catalogue : les factures
 * déjà émises le nomment, et une ligne d'historique qui pointe dans le vide
 * est pire qu'un produit inactif.
 */
class TarifController extends Controller
{
    public function __construct(private readonly DeviseService $devises) {}

    public function index(Request $request): View
    {
        $this->autoriser();

        $onglet = $request->query('onglet', 'consultations');
        $recherche = trim((string) $request->query('recherche', ''));

        return view('parametres.tarifs', [
            'onglet' => $onglet,
            'recherche' => $recherche,
            'tauxUsd' => $this->devises->taux('USD'),
            'consultations' => TypeConsultation::orderBy('categorie')->orderBy('libelle')->get(),
            'examens' => TypeExamen::query()
                ->when($recherche !== '', fn ($q) => $q->where('libelle', 'ilike', "%{$recherche}%"))
                ->orderBy('domaine')->orderBy('categorie')->orderBy('libelle')
                ->get(),
            'medicaments' => Medicament::query()
                ->with('stock')
                ->when($recherche !== '', fn ($q) => $q->where(fn ($sub) => $sub
                    ->where('denomination_commune', 'ilike', "%{$recherche}%")
                    ->orWhere('nom_commercial', 'ilike', "%{$recherche}%")))
                ->orderBy('denomination_commune')
                ->get(),
            'dietes' => TypeDiete::orderBy('libelle')->get(),
        ]);
    }

    /** Révise le tarif d'une consultation. */
    public function consultation(Request $request, TypeConsultation $type): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'prix_usd' => 'required|numeric|min:0|max:100000',
        ], [
            'prix_usd.required' => 'Indiquez le tarif de la consultation.',
        ]);

        $type->update(['prix_usd' => $donnees['prix_usd']]);

        return back()->with('success', sprintf(
            '%s : %s $, soit %s CDF au taux du jour. Les factures déjà émises gardent leur montant.',
            $type->libelle,
            $donnees['prix_usd'] + 0,
            number_format($type->fresh()->prixCdf(), 0, ',', ' ')
        ));
    }

    /** Révise le tarif et le délai de rendu d'un examen. */
    public function examen(Request $request, TypeExamen $type): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'prix' => 'required|numeric|min:0|max:100000000',
            'delai_heures' => 'nullable|integer|min:0|max:720',
        ], [
            'prix.required' => 'Indiquez le tarif de l\'examen.',
        ]);

        $type->update([
            'prix' => $donnees['prix'],
            'delai_heures' => $donnees['delai_heures'] ?? $type->delai_heures,
        ]);

        return back()->with('success', sprintf(
            '%s : %s CDF, rendu annoncé sous %s h.',
            $type->libelle,
            number_format((float) $donnees['prix'], 0, ',', ' '),
            $type->fresh()->delai_heures
        ));
    }

    /** Révise le prix de vente et le seuil d'alerte d'un produit. */
    public function medicament(Request $request, Medicament $medicament): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'prix_unitaire_vente' => 'required|numeric|min:0|max:10000000',
            'quantite_alerte' => 'nullable|integer|min:0|max:100000',
        ], [
            'prix_unitaire_vente.required' => 'Indiquez le prix de l\'unité.',
        ]);

        // Le prix se porte sur chaque stock du produit : le dépôt et les
        // officines vendent au même tarif, sinon le patient paie selon le
        // guichet où il passe.
        StockMedicament::where('medicament_id', $medicament->id)->update([
            'prix_unitaire_vente' => $donnees['prix_unitaire_vente'],
            ...($request->filled('quantite_alerte')
                ? ['quantite_alerte' => (int) $donnees['quantite_alerte']]
                : []),
        ]);

        return back()->with('success', sprintf(
            '%s : %s CDF l\'unité, dans toutes les officines.',
            $medicament->designation(),
            number_format((float) $donnees['prix_unitaire_vente'], 0, ',', ' ')
        ));
    }

    /** Révise le coût journalier d'une diète. */
    public function diete(Request $request, TypeDiete $type): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'prix_journalier' => 'required|numeric|min:0|max:10000000',
        ]);

        $type->update(['prix_journalier' => $donnees['prix_journalier']]);

        return back()->with('success', sprintf(
            '%s : %s CDF par jour servi.',
            $type->libelle,
            number_format((float) $donnees['prix_journalier'], 0, ',', ' ')
        ));
    }

    /**
     * Retire un élément du catalogue, ou l'y remet.
     *
     * Retirer n'est pas supprimer : le produit disparaît des écrans de
     * prescription mais reste lisible sur les factures qui le portent.
     */
    public function basculer(Request $request, string $famille, string $id): RedirectResponse
    {
        $this->autoriser();

        $modele = match ($famille) {
            'consultation' => TypeConsultation::findOrFail($id),
            'examen' => TypeExamen::findOrFail($id),
            'medicament' => Medicament::findOrFail($id),
            'diete' => TypeDiete::findOrFail($id),
            default => abort(404),
        };

        $colonne = $modele instanceof TypeDiete ? 'is_active' : 'est_actif';
        $actif = ! $modele->{$colonne};
        $modele->update([$colonne => $actif]);

        return back()->with('success', sprintf(
            '%s %s du catalogue.%s',
            $modele->libelle ?? $modele->designation(),
            $actif ? 'remis' : 'retiré',
            $actif ? '' : ' Les factures qui le portent restent inchangées.'
        ));
    }

    /** Les tarifs engagent les recettes de la maison : ils restent à la direction. */
    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'directeur']),
            403,
            'Tarification réservée à la direction.'
        );
    }
}
