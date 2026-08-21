<?php

namespace App\Http\Controllers;

use App\Models\ActeClinique;
use App\Models\TypeConsultation;
use App\Models\User;
use App\Models\Visit;
use App\Services\FacturationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Actes cliniques : chirurgie, maternité, dialyse, examens spécialisés.
 *
 * Un acte naît comme une **demande** : le médecin dit ce qu'il faut faire, pas
 * quand ni par qui. C'est le plateau technique qui le programme ensuite —
 * salle, créneau, opérateur — puis qui le clôture avec son compte rendu.
 * Facturer sans cette trace reviendrait à faire payer un acte dont rien ne
 * dit qu'il a eu lieu.
 */
class ActeCliniqueController extends Controller
{
    /** Domaines dont la programmation se fait au bloc opératoire. */
    private const DOMAINES_BLOC = ['chirurgie', 'maternite'];

    public function index(Request $request): View
    {
        $domaine = $request->get('domaine', 'chirurgie');

        $actes = ActeClinique::with(['patient', 'visit.service', 'prescripteur', 'operateur', 'salle'])
            ->where('domaine', $domaine)
            ->orderByDesc('created_at')
            ->get();

        $programme = [
            // Demandes reçues, en attente de créneau.
            'demandes' => $actes->where('statut', 'prescrit'),
            // Programme daté, salle attribuée.
            'planifies' => $actes->where('statut', 'planifie')->sortBy('date_prevue'),
            // Actes réalisés (registre).
            'realises' => $actes->whereIn('statut', ['realise', 'facture'])->take(50),
        ];

        $operateurs = User::role(['medecin', 'infirmier_chef'])
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom']);

        return view('actes.index', [
            'actes' => $actes,
            'domaine' => $domaine,
            'programme' => $programme,
            'operateurs' => $operateurs,
            'catalogue' => $this->catalogue($domaine),
            'sejoursOuverts' => $this->sejoursOuverts(),
        ]);
    }

    /**
     * Formulaire de demande d'acte.
     *
     * Le séjour peut être passé en paramètre (depuis le dossier du patient) ou
     * choisi ici : demander une intervention depuis le bloc lui-même est le
     * cas courant, et l'écran ne doit pas renvoyer l'agent aux admissions.
     */
    public function create(Request $request): View
    {
        $domaine = $request->get('domaine', 'chirurgie');

        return view('actes.create', [
            'visit' => $request->visit_id
                ? Visit::with('patient', 'service')->find($request->visit_id)
                : null,
            'domaine' => $domaine,
            'catalogue' => $this->catalogue($domaine),
            'sejoursOuverts' => $this->sejoursOuverts(),
        ]);
    }

    /**
     * Enregistre la demande d'acte.
     *
     * Elle part au programme du plateau technique, jamais directement en
     * « planifié » : sans salle ni créneau, un acte n'est pas programmé.
     */
    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'visit_id' => 'required|uuid|exists:visits,id',
            'domaine' => 'required|in:chirurgie,maternite,examen_specialise,dialyse',
            'libelle' => 'required|string|max:255',
            'prix' => 'required|numeric|min:0',
            'duree_minutes' => 'nullable|integer|min:5|max:1440',
            'indication' => 'nullable|string|max:1000',
            'diagnostic_preop' => 'nullable|string|max:1000',
        ], [
            'visit_id.required' => 'Choisissez le patient et son séjour.',
            'libelle.required' => 'Choisissez l\'acte à réaliser.',
        ]);

        $visit = Visit::findOrFail($donnees['visit_id']);

        if (! $visit->peutRecevoirServices()) {
            return back()->withInput()->with('error', 'Séjour terminé — aucun nouvel acte possible.');
        }

        $acte = ActeClinique::create([
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'prescripteur_id' => auth()->id(),
            'demandeur_id' => auth()->id(),
            'domaine' => $donnees['domaine'],
            'libelle' => $donnees['libelle'],
            'prix' => $donnees['prix'],
            'duree_minutes' => $donnees['duree_minutes'] ?? null,
            'indication' => $donnees['indication'] ?? null,
            'diagnostic_preop' => $donnees['diagnostic_preop'] ?? null,
            'urgence' => $request->boolean('urgence'),
            'consentement' => $request->boolean('consentement'),
            // Une demande, pas encore un programme.
            'statut' => 'prescrit',
        ]);

        // Facturer tout de suite reste possible — le guichet encaisse souvent
        // avant l'acte — mais cela ne dispense jamais de la programmation.
        if ($request->boolean('facturer')) {
            app(FacturationService::class)->creerFactureActeClinique($acte);
        }

        return redirect($this->retourApresDemande($acte))->with('success', sprintf(
            'Demande enregistrée : « %s » pour %s.%s Programmez-la maintenant : salle, créneau et opérateur.',
            $acte->libelle,
            $visit->patient->nom_complet,
            $request->boolean('facturer') ? ' Facture émise au guichet.' : ''
        ));
    }

    /**
     * Programmation simple, hors bloc opératoire (examens spécialisés).
     *
     * La chirurgie et la maternité passent par le bloc, qui vérifie en plus
     * la disponibilité de la salle.
     */
    public function planifier(Request $request, ActeClinique $acte): RedirectResponse
    {
        $request->validate([
            'date_prevue' => 'required|date',
            'operateur_id' => 'nullable|uuid|exists:users,id',
            'duree_minutes' => 'nullable|integer|min:5|max:1440',
            'indication' => 'nullable|string|max:255',
        ]);

        if (! $acte->visit?->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — programmation impossible.');
        }

        $acte->update([
            'date_prevue' => $request->date_prevue,
            'operateur_id' => $request->operateur_id,
            'duree_minutes' => $request->duree_minutes,
            'indication' => $request->indication,
            'consentement' => $request->boolean('consentement'),
            'urgence' => $request->boolean('urgence'),
            'statut' => 'planifie',
        ]);

        return back()->with('success', 'Acte inscrit au programme.');
    }

    public function realiser(Request $request, ActeClinique $acte): RedirectResponse
    {
        $request->validate(['compte_rendu' => 'required|string|min:10'], [
            'compte_rendu.required' => 'Le compte rendu atteste que l\'acte a été réalisé : il est obligatoire.',
        ]);

        $acte->update([
            'statut' => 'realise',
            'compte_rendu' => $request->compte_rendu,
            'date_realisation' => now(),
        ]);

        return back()->with('success', 'Compte rendu enregistré — l\'acte est réalisé.');
    }

    /**
     * Facture l'acte.
     *
     * Un acte qui n'a jamais été programmé peut être facturé — le guichet
     * encaisse d'avance — mais on le dit, pour que personne ne croie l'acte
     * fait sous prétexte qu'il est payé.
     */
    public function facturer(ActeClinique $acte): RedirectResponse
    {
        if ($acte->facture_id) {
            return redirect()->route('caisse.show', $acte->facture_id)
                ->with('info', 'Facture déjà existante.');
        }

        $facture = app(FacturationService::class)->creerFactureActeClinique($acte);

        return redirect()->route('caisse.show', $facture)->with(
            $acte->statut === 'prescrit' ? 'info' : 'success',
            $acte->statut === 'prescrit'
                ? 'Facture émise — l\'acte reste à programmer au plateau technique.'
                : 'Facture de l\'acte émise.'
        );
    }

    /**
     * Catalogue du domaine, tiré de la configuration de l'établissement.
     *
     * @return array<int, array{libelle: string, prix: float, duree: int}>
     */
    private function catalogue(string $domaine): array
    {
        if ($domaine === 'examen_specialise') {
            return TypeConsultation::where('categorie', 'specialisee')
                ->where('est_actif', true)
                ->orderBy('libelle')
                ->get()
                ->map(fn ($tc) => [
                    'libelle' => 'Examen spécialisé '.$tc->libelle,
                    'prix' => (float) $tc->prixCdf(),
                    'duree' => 30,
                ])->all();
        }

        return config('dpi.actes.'.$domaine, config('dpi.actes.chirurgie', []));
    }

    /**
     * Séjours ouverts, pour choisir le patient sans quitter l'écran.
     */
    private function sejoursOuverts()
    {
        return Visit::with('patient', 'service')
            ->where('statut', 'en_cours')
            ->orderByDesc('date_entree')
            ->limit(300)
            ->get();
    }

    /** Où renvoyer après une demande, selon le plateau qui la traitera. */
    private function retourApresDemande(ActeClinique $acte): string
    {
        return match (true) {
            in_array($acte->domaine, self::DOMAINES_BLOC, true) => route('bloc.programme'),
            $acte->domaine === 'dialyse' => route('dialyse.index'),
            default => route('examens-specialises.index'),
        };
    }
}
