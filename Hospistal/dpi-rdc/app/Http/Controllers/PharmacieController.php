<?php
namespace App\Http\Controllers;

use App\Models\Medicament;
use App\Models\Prescription;
use App\Models\StockMedicament;
use App\Services\PharmacieService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PharmacieController extends Controller
{
    public function dashboard(Request $request): View
    {
        return view('pharmacie.dashboard', $this->donneesStock($request));
    }

    public function stock(Request $request): View
    {
        return view('pharmacie.stock', $this->donneesStock($request));
    }

    public function prescriptions(): View
    {
        return view('pharmacie.prescriptions');
    }

    public function showPrescription(Prescription $prescription): View
    {
        $prescription->load(['patient', 'prescripteur', 'lignes.medicament.stock', 'consultation.visit']);

        return view('pharmacie.prescription-show', compact('prescription'));
    }

    public function medicaments(Request $request): View
    {
        return view('pharmacie.medicaments', $this->donneesStock($request));
    }

    /**
     * Dispensation par formulaire classique (aucune dépendance JavaScript).
     */
    public function dispenser(Request $request, Prescription $prescription): RedirectResponse
    {
        $request->validate(['quantites' => 'required|array']);

        $erreurs = app(PharmacieService::class)->dispenser($prescription, $request->input('quantites', []));

        if ($erreurs !== []) {
            return back()->with('error', implode(' — ', $erreurs));
        }

        return back()->with('success', 'Médicaments dispensés avec succès.');
    }

    /**
     * Ajout d'un médicament au catalogue + stock initial (formulaire classique).
     */
    public function storeMedicament(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'denomination_commune' => 'required|string|max:255',
            'nom_commercial' => 'nullable|string|max:255',
            'forme' => 'required|in:comprime,gelule,sirop,injectable,pommade,creme,suppositoire,collyre,sachet,autre',
            'dosage' => 'required|string|max:100',
            'unite_dispensation' => 'required|string|max:50',
            'classe_therapeutique' => 'nullable|string|max:150',
            'prix_unitaire_vente' => 'required|numeric|min:0',
            'prix_unitaire_achat' => 'nullable|numeric|min:0',
            'quantite_alerte' => 'nullable|integer|min:0',
            'quantite_initiale' => 'nullable|numeric|min:0',
            'date_peremption' => 'nullable|date',
            'lot' => 'nullable|string|max:100',
        ], [
            'denomination_commune.required' => 'La DCI est obligatoire.',
            'dosage.required' => 'Le dosage est obligatoire.',
            'prix_unitaire_vente.required' => 'Le prix de vente est obligatoire.',
        ]);

        $medicament = app(PharmacieService::class)->creerMedicament($donnees + [
            'necessite_ordonnance' => $request->boolean('necessite_ordonnance', true),
        ]);

        return back()->with('success', "Médicament « {$medicament->denomination_commune} » ajouté au stock.");
    }

    /**
     * Entrée / ajustement / sortie péremption (formulaire classique).
     */
    public function mouvementStock(Request $request, Medicament $medicament): RedirectResponse
    {
        $request->validate([
            'type' => 'required|in:entree,ajustement_inventaire,sortie_peremption',
            'quantite' => 'required|numeric|not_in:0',
            'lot' => 'nullable|string|max:100',
            'reference' => 'nullable|string|max:200',
        ], [
            'quantite.required' => 'Indiquez une quantité.',
            'quantite.not_in' => 'La quantité ne peut pas être nulle.',
        ]);

        $erreur = app(PharmacieService::class)->mouvementStock(
            $medicament,
            $request->input('type'),
            (float) $request->input('quantite'),
            $request->input('lot'),
            $request->input('reference'),
        );

        if ($erreur) {
            return back()->with('error', $erreur);
        }

        return back()->with('success', "Stock de {$medicament->denomination_commune} mis à jour.");
    }

    /**
     * Données stock partagées par les pages pharmacie (rendu serveur).
     *
     * @return array<string, mixed>
     */
    protected function donneesStock(Request $request): array
    {
        $search = trim((string) $request->query('q', ''));
        $filtre = (string) $request->query('filtre', 'tous');

        $query = Medicament::with('stock')
            ->where('est_actif', true)
            ->when($search !== '', function ($q) use ($search) {
                $like = '%' . strtolower($search) . '%';
                $q->where(fn ($q) => $q
                    ->whereRaw('LOWER(denomination_commune) LIKE ?', [$like])
                    ->orWhereRaw("LOWER(COALESCE(nom_commercial, '')) LIKE ?", [$like]));
            });

        if ($filtre === 'alerte') {
            $query->whereHas('stock', fn ($q) => $q->whereColumn('quantite_disponible', '<=', 'quantite_alerte'));
        } elseif ($filtre === 'peremption') {
            $query->whereHas('stock', fn ($q) => $q->whereNotNull('date_peremption')
                ->whereBetween('date_peremption', [now(), now()->addDays(30)]));
        } elseif ($filtre === 'rupture') {
            $query->whereHas('stock', fn ($q) => $q->where('quantite_disponible', '<=', 0));
        }

        return [
            'medicaments' => $query->orderBy('denomination_commune')->paginate(25)->withQueryString(),
            'stats' => [
                'total' => Medicament::where('est_actif', true)->count(),
                'alerte' => StockMedicament::whereColumn('quantite_disponible', '<=', 'quantite_alerte')->count(),
                'rupture' => StockMedicament::where('quantite_disponible', '<=', 0)->count(),
                'peremption' => StockMedicament::whereNotNull('date_peremption')
                    ->whereBetween('date_peremption', [now(), now()->addDays(30)])->count(),
            ],
            'search' => $search,
            'filtre' => $filtre,
        ];
    }
}
