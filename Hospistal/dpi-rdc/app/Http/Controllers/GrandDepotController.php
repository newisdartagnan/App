<?php

namespace App\Http\Controllers;

use App\Models\CommandeFournisseur;
use App\Models\DestructionStock;
use App\Models\Fournisseur;
use App\Models\InventaireStock;
use App\Models\Medicament;
use App\Models\Officine;
use App\Models\ReceptionStock;
use App\Services\GrandDepotService;
use App\Services\OfficineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Gestion du grand dépôt pharmaceutique : tableau de bord (valeur, CMM,
 * ruptures, péremptions, propositions de commande), fournisseurs, bons de
 * commande, réceptions, inventaires, destructions et fiche de stock.
 */
class GrandDepotController extends Controller
{
    public function __construct(private readonly GrandDepotService $depot) {}

    public function tableau(Request $request): View|RedirectResponse
    {
        $this->autoriser();
        $depot = $this->depotOuRetour();
        if ($depot instanceof RedirectResponse) {
            return $depot;
        }

        $filtre = (string) $request->query('statut', '');
        $situation = $this->depot->situation($depot, $request->query('q'));
        $peremptions = $this->depot->peremptions($depot);

        return view('grand-depot.tableau', [
            'depot' => $depot,
            'situation' => $filtre ? $situation->where('statut', $filtre)->values() : $situation->filter(fn ($s) => $s->stock > 0 || $s->cmm > 0 || $s->en_commande > 0)->values(),
            'kpi' => [
                'valeur' => $situation->sum('valeur'),
                'references' => $situation->where('stock', '>', 0)->count(),
                'ruptures' => $situation->where('statut', 'rupture')->filter(fn ($s) => $s->cmm > 0 || $s->en_commande > 0)->count(),
                'alertes' => $situation->where('statut', 'alerte')->count(),
                'perimes' => $peremptions->filter(fn ($l) => $l->date_peremption->isPast())->count(),
                'bientot' => $peremptions->filter(fn ($l) => ! $l->date_peremption->isPast())->count(),
                'a_commander' => $situation->where('a_commander', '>', 0)->count(),
            ],
            'peremptions' => $peremptions,
            'filtre' => $filtre,
            'q' => $request->query('q'),
            'commandesOuvertes' => CommandeFournisseur::with('fournisseur')->where('officine_id', $depot->id)
                ->whereIn('statut', ['brouillon', 'envoyee', 'partiellement_recue'])->orderBy('date_commande')->get(),
        ]);
    }

    // ── Fournisseurs ─────────────────────────────────────────────────────

    public function fournisseurs(): View
    {
        $this->autoriser();

        return view('grand-depot.fournisseurs', [
            'fournisseurs' => Fournisseur::withCount(['commandes', 'receptions'])->orderBy('nom')->get(),
        ]);
    }

    public function enregistrerFournisseur(Request $request, ?Fournisseur $fournisseur = null): RedirectResponse
    {
        $this->autoriser();
        $donnees = $request->validate([
            'nom' => 'required|string|max:200',
            'contact' => 'nullable|string|max:150',
            'telephone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:150',
            'adresse' => 'nullable|string|max:1000',
            'identifiant_fiscal' => 'nullable|string|max:100',
            'delai_livraison_jours' => 'nullable|integer|min:0|max:365',
        ], ['nom.required' => 'Le nom du fournisseur est obligatoire.']);

        $fournisseur ??= new Fournisseur(['est_actif' => true]);
        $fournisseur->fill($donnees)->save();

        return back()->with('success', "Fournisseur « {$fournisseur->nom} » enregistré.");
    }

    public function basculerFournisseur(Fournisseur $fournisseur): RedirectResponse
    {
        $this->autoriser();
        $fournisseur->update(['est_actif' => ! $fournisseur->est_actif]);

        return back()->with('success', "Fournisseur « {$fournisseur->nom} » ".($fournisseur->est_actif ? 'réactivé' : 'désactivé').'.');
    }

    // ── Commandes ────────────────────────────────────────────────────────

    public function commandes(): View
    {
        $this->autoriser();

        return view('grand-depot.commandes', [
            'commandes' => CommandeFournisseur::with(['fournisseur', 'lignes'])->orderByDesc('date_commande')->orderByDesc('created_at')->paginate(30),
        ]);
    }

    public function creerCommande(Request $request): View|RedirectResponse
    {
        $this->autoriser();
        $depot = $this->depotOuRetour();
        if ($depot instanceof RedirectResponse) {
            return $depot;
        }

        // « Commander les propositions » : les quantités calculées pré-remplissent.
        $propositions = $request->boolean('propositions')
            ? $this->depot->situation($depot)->where('a_commander', '>', 0)->values()
            : collect();

        return view('grand-depot.commande-form', [
            'fournisseurs' => Fournisseur::where('est_actif', true)->orderBy('nom')->get(),
            'medicaments' => Medicament::where('est_actif', true)->orderBy('denomination_commune')->get(),
            'propositions' => $propositions,
        ]);
    }

    public function enregistrerCommande(Request $request): RedirectResponse
    {
        $this->autoriser();
        $depot = $this->depotOuRetour();
        if ($depot instanceof RedirectResponse) {
            return $depot;
        }

        $donnees = $request->validate([
            'fournisseur_id' => 'required|uuid|exists:fournisseurs,id',
            'date_livraison_prevue' => 'nullable|date',
            'devise' => 'required|in:CDF,USD',
            'observations' => 'nullable|string|max:2000',
            'lignes' => 'required|array|min:1',
            'lignes.*.medicament_id' => 'nullable|uuid|exists:medicaments,id',
            'lignes.*.quantite' => 'nullable|numeric|min:0',
            'lignes.*.prix_unitaire' => 'nullable|numeric|min:0',
        ], ['fournisseur_id.required' => 'Choisissez le fournisseur.']);

        $lignes = collect($donnees['lignes'])->filter(fn ($l) => ! empty($l['medicament_id']) && (float) ($l['quantite'] ?? 0) > 0);
        if ($lignes->isEmpty()) {
            return back()->withInput()->withErrors(['lignes' => 'Ajoutez au moins un produit avec une quantité.']);
        }

        $commande = DB::transaction(function () use ($donnees, $lignes, $depot) {
            $commande = CommandeFournisseur::create([
                'numero' => $this->depot->numero('BC', 'commandes_fournisseur'),
                'fournisseur_id' => $donnees['fournisseur_id'],
                'officine_id' => $depot->id,
                'statut' => 'brouillon',
                'date_commande' => today(),
                'date_livraison_prevue' => $donnees['date_livraison_prevue'] ?? null,
                'devise' => $donnees['devise'],
                'observations' => $donnees['observations'] ?? null,
                'cree_par' => auth()->id(),
            ]);
            foreach ($lignes as $l) {
                $commande->lignes()->create([
                    'medicament_id' => $l['medicament_id'],
                    'quantite_commandee' => (float) $l['quantite'],
                    'prix_unitaire' => isset($l['prix_unitaire']) && $l['prix_unitaire'] !== '' ? (float) $l['prix_unitaire'] : null,
                ]);
            }

            return $commande;
        });

        return redirect()->route('grand-depot.commandes.show', $commande)->with('success', "Bon de commande {$commande->numero} créé (brouillon).");
    }

    public function voirCommande(CommandeFournisseur $commande): View
    {
        $this->autoriser();

        return view('grand-depot.commande', ['commande' => $commande->load(['fournisseur', 'lignes.medicament', 'receptions', 'auteur', 'officine'])]);
    }

    public function envoyerCommande(CommandeFournisseur $commande): RedirectResponse
    {
        $this->autoriser();
        abort_unless($commande->statut === 'brouillon', 422, 'Seul un brouillon s\'envoie.');
        $commande->update(['statut' => 'envoyee', 'envoyee_par' => auth()->id(), 'envoyee_at' => now()]);

        return back()->with('success', "Commande {$commande->numero} envoyée au fournisseur.");
    }

    public function annulerCommande(CommandeFournisseur $commande): RedirectResponse
    {
        $this->autoriser();
        if ($commande->receptions()->exists()) {
            return back()->with('error', 'Des produits ont déjà été reçus sur cette commande : elle ne s\'annule plus.');
        }
        $commande->update(['statut' => 'annulee']);

        return back()->with('success', "Commande {$commande->numero} annulée.");
    }

    public function recevoirCommande(Request $request, CommandeFournisseur $commande): RedirectResponse
    {
        $this->autoriser();
        if (! $commande->peutRecevoir()) {
            return back()->with('error', 'Cette commande n\'attend plus de livraison.');
        }
        $donnees = $this->validerReception($request);

        try {
            $reception = $this->depot->recevoir($commande->officine, $donnees, $donnees['lignes'], $commande);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('grand-depot.receptions.show', $reception)->with('success', "Réception {$reception->numero} enregistrée : le stock du dépôt est à jour.");
    }

    // ── Réceptions ───────────────────────────────────────────────────────

    public function receptions(): View
    {
        $this->autoriser();

        return view('grand-depot.receptions', [
            'receptions' => ReceptionStock::with(['fournisseur', 'commande'])->orderByDesc('date_reception')->orderByDesc('created_at')->paginate(30),
        ]);
    }

    public function creerReception(): View
    {
        $this->autoriser();

        return view('grand-depot.reception-form', [
            'fournisseurs' => Fournisseur::where('est_actif', true)->orderBy('nom')->get(),
            'medicaments' => Medicament::where('est_actif', true)->orderBy('denomination_commune')->get(),
        ]);
    }

    public function enregistrerReception(Request $request): RedirectResponse
    {
        $this->autoriser();
        $depot = $this->depotOuRetour();
        if ($depot instanceof RedirectResponse) {
            return $depot;
        }
        $donnees = $this->validerReception($request, true);

        try {
            $reception = $this->depot->recevoir($depot, $donnees, $donnees['lignes']);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('grand-depot.receptions.show', $reception)->with('success', "Réception {$reception->numero} enregistrée.");
    }

    public function voirReception(ReceptionStock $reception): View
    {
        $this->autoriser();

        return view('grand-depot.reception', ['reception' => $reception->load(['lignes.medicament', 'fournisseur', 'commande', 'auteur'])]);
    }

    // ── Inventaires ──────────────────────────────────────────────────────

    public function inventaires(): View
    {
        $this->autoriser();

        return view('grand-depot.inventaires', [
            'inventaires' => InventaireStock::withCount('lignes')->with('auteur')->orderByDesc('date_inventaire')->orderByDesc('created_at')->paginate(20),
        ]);
    }

    public function demarrerInventaire(Request $request): RedirectResponse
    {
        $this->autoriser();
        $depot = $this->depotOuRetour();
        if ($depot instanceof RedirectResponse) {
            return $depot;
        }
        try {
            $inventaire = $this->depot->demarrerInventaire($depot, $request->input('observations'));
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('grand-depot.inventaires.show', $inventaire)->with('success', "Inventaire {$inventaire->numero} ouvert : saisissez les quantités comptées.");
    }

    public function voirInventaire(InventaireStock $inventaire): View
    {
        $this->autoriser();

        return view('grand-depot.inventaire', [
            'inventaire' => $inventaire->load(['lignes.medicament', 'officine', 'auteur']),
        ]);
    }

    public function saisirInventaire(Request $request, InventaireStock $inventaire): RedirectResponse
    {
        $this->autoriser();
        abort_unless($inventaire->statut === 'en_cours', 422);
        $donnees = $request->validate([
            'comptes' => 'array',
            'comptes.*' => 'nullable|numeric|min:0',
            'motifs' => 'array',
            'motifs.*' => 'nullable|string|max:255',
        ]);

        foreach ($inventaire->lignes as $ligne) {
            if (array_key_exists($ligne->id, $donnees['comptes'] ?? [])) {
                $compte = $donnees['comptes'][$ligne->id];
                $ligne->update([
                    'quantite_comptee' => $compte === null || $compte === '' ? null : (float) $compte,
                    'motif_ecart' => $donnees['motifs'][$ligne->id] ?? $ligne->motif_ecart,
                ]);
            }
        }

        if ($request->input('action') === 'valider') {
            $n = $this->depot->validerInventaire($inventaire->fresh());

            return redirect()->route('grand-depot.inventaires.show', $inventaire)->with('success', "Inventaire validé : {$n} ajustement(s) de stock enregistré(s).");
        }

        return back()->with('success', 'Comptage enregistré.');
    }

    // ── Destructions ─────────────────────────────────────────────────────

    public function destructions(): View|RedirectResponse
    {
        $this->autoriser();
        $depot = $this->depotOuRetour();
        if ($depot instanceof RedirectResponse) {
            return $depot;
        }

        return view('grand-depot.destructions', [
            'destructions' => DestructionStock::withCount('lignes')->orderByDesc('date_destruction')->paginate(20),
            'peremptions' => $this->depot->peremptions($depot),
        ]);
    }

    public function enregistrerDestruction(Request $request): RedirectResponse
    {
        $this->autoriser();
        $depot = $this->depotOuRetour();
        if ($depot instanceof RedirectResponse) {
            return $depot;
        }
        $donnees = $request->validate([
            'motif' => 'required|string|max:255',
            'temoins' => 'nullable|string|max:1000',
            'quantites' => 'required|array',
            'quantites.*' => 'nullable|numeric|min:0',
        ], ['motif.required' => 'Indiquez le motif de la destruction.']);

        try {
            $pv = $this->depot->detruire($depot, $donnees['quantites'], $donnees['motif'], $donnees['temoins'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('grand-depot.destructions.show', $pv)->with('success', "Procès-verbal {$pv->numero} établi.");
    }

    public function voirDestruction(DestructionStock $destruction): View
    {
        $this->autoriser();

        return view('grand-depot.destruction', ['pv' => $destruction->load(['lignes.medicament', 'officine', 'auteur'])]);
    }

    // ── Fiche de stock ───────────────────────────────────────────────────

    public function ficheStock(Request $request, Medicament $medicament): View|RedirectResponse
    {
        $this->autoriser();
        $depot = $this->depotOuRetour();
        if ($depot instanceof RedirectResponse) {
            return $depot;
        }
        $du = $request->query('du', now()->subMonths(3)->toDateString());
        $au = $request->query('au', now()->toDateString());

        return view('grand-depot.fiche-stock', [
            'medicament' => $medicament,
            'depot' => $depot,
            'mouvements' => $this->depot->ficheStock($depot, $medicament, $du, $au),
            'lots' => $medicament->stocks()->where('officine_id', $depot->id)->orderBy('date_peremption')->get(),
            'du' => $du,
            'au' => $au,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function validerReception(Request $request, bool $libre = false): array
    {
        return $request->validate([
            'fournisseur_id' => ($libre ? 'nullable' : 'nullable').'|uuid|exists:fournisseurs,id',
            'origine' => 'nullable|in:achat,don,transfert_externe,retour',
            'date_reception' => 'required|date|before_or_equal:today',
            'numero_bon_livraison' => 'nullable|string|max:100',
            'numero_facture_fournisseur' => 'nullable|string|max:100',
            'devise' => 'nullable|in:CDF,USD',
            'observations' => 'nullable|string|max:2000',
            'lignes' => 'required|array|min:1',
            'lignes.*.medicament_id' => 'nullable|uuid|exists:medicaments,id',
            'lignes.*.ligne_commande_id' => 'nullable|uuid',
            'lignes.*.quantite' => 'nullable|numeric|min:0',
            'lignes.*.lot' => 'nullable|string|max:100',
            'lignes.*.date_peremption' => 'nullable|date',
            'lignes.*.prix_unitaire_achat' => 'nullable|numeric|min:0',
            'lignes.*.prix_unitaire_vente' => 'nullable|numeric|min:0',
        ], ['date_reception.required' => 'Indiquez la date de réception.']) + ['lignes' => []];
    }

    private function depotOuRetour(): Officine|RedirectResponse
    {
        $depot = app(OfficineService::class)->depotCentral();

        return $depot ?: redirect()->route('officines.index')->with('error', 'Aucun dépôt central n\'est configuré.');
    }

    private function autoriser(): void
    {
        $u = auth()->user();
        abort_unless(
            $u?->hasAnyRole(['super_admin', 'directeur']) || $u?->can('depot.manage') || $u?->can('stock.receive'),
            403,
            'Le grand dépôt est réservé au pharmacien gestionnaire.'
        );
    }
}
