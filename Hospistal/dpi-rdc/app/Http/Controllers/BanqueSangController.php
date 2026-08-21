<?php

namespace App\Http\Controllers;

use App\Models\DemandeSang;
use App\Models\DonneurSang;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\Visit;
use App\Services\BanqueSangService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Banque de sang : stock, donneurs, demandes des services, délivrance.
 */
class BanqueSangController extends Controller
{
    public function __construct(private readonly BanqueSangService $banque) {}

    /**
     * Tableau du stock : ce qu'il y a au réfrigérateur, et qui peut donner.
     */
    public function index(Request $request): View
    {
        $this->autoriser();

        // Le ménage d'abord : une poche périmée qui reste marquée disponible
        // finit par être posée.
        $this->banque->retirerPochesPerimees();

        $etablissement = $this->etablissementId();
        $groupeRecherche = $request->query('groupe');

        return view('banque-sang.index', [
            'stock' => $this->banque->etatDuStock($etablissement),
            'poches' => PocheSang::with('donneur')
                ->where('establishment_id', $etablissement)
                ->when($groupeRecherche, fn ($q) => $q->where('groupe_sanguin', $groupeRecherche))
                ->whereIn('statut', ['quarantaine', 'disponible', 'reservee'])
                ->orderBy('date_peremption')
                ->get(),
            'demandesOuvertes' => DemandeSang::with(['patient', 'demandeur'])
                ->where('establishment_id', $etablissement)
                ->whereIn('statut', ['en_attente', 'partiellement_servie'])
                ->orderByDesc('urgence')
                ->orderBy('created_at')
                ->get(),
            'groupeRecherche' => $groupeRecherche,
            'groupes' => PocheSang::GROUPES,
            'produits' => PocheSang::PRODUITS,
        ]);
    }

    /**
     * Fichier des donneurs : le vrai stock de l'hôpital.
     */
    public function donneurs(Request $request): View
    {
        $this->autoriser();

        $groupe = $request->query('groupe');
        $recherche = trim((string) $request->query('recherche', ''));
        // « Pour un receveur de ce groupe » : la question qu'on se pose la nuit.
        $pourReceveur = $request->query('pour_receveur');

        $donneurs = DonneurSang::query()
            ->where('establishment_id', $this->etablissementId())
            ->when($groupe, fn ($q) => $q->where('groupe_sanguin', $groupe))
            ->when($pourReceveur, fn ($q) => $q->compatiblesAvec($pourReceveur))
            ->when($recherche !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('nom', 'ilike', "%{$recherche}%")
                ->orWhere('prenom', 'ilike', "%{$recherche}%")
                ->orWhere('telephone', 'ilike', "%{$recherche}%")
                ->orWhere('code', 'ilike', "%{$recherche}%")))
            ->orderBy('nom')
            ->get();

        return view('banque-sang.donneurs', [
            'donneurs' => $donneurs,
            'groupe' => $groupe,
            'recherche' => $recherche,
            'pourReceveur' => $pourReceveur,
            'groupes' => PocheSang::GROUPES,
            'types' => DonneurSang::TYPES,
            'produits' => PocheSang::PRODUITS,
        ]);
    }

    /** Inscrit un donneur au fichier. */
    public function enregistrerDonneur(Request $request): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'nom' => 'required|string|max:100',
            'postnom' => 'nullable|string|max:100',
            'prenom' => 'nullable|string|max:100',
            'sexe' => 'nullable|in:M,F',
            'date_naissance' => 'nullable|date|before:today',
            'groupe_sanguin' => ['required', Rule::in(PocheSang::GROUPES)],
            'telephone' => 'nullable|string|max:50',
            'adresse' => 'nullable|string|max:255',
            'type_donneur' => ['required', Rule::in(array_keys(DonneurSang::TYPES))],
            'patient_id' => 'nullable|uuid|exists:patients,id',
            'notes' => 'nullable|string|max:1000',
        ], [
            'groupe_sanguin.required' => 'Le groupe est ce qui rend le donneur joignable : il est obligatoire.',
        ]);

        $etablissement = $this->etablissementId();

        $donneur = DonneurSang::create([
            ...$donnees,
            'establishment_id' => $etablissement,
            'code' => $this->banque->genererCodeDonneur($etablissement),
            'est_eligible' => true,
        ]);

        return back()->with('success', 'Donneur '.$donneur->code.' — '.$donneur->nomComplet()
            .' ('.$donneur->groupe_sanguin.') inscrit au fichier.');
    }

    /** Enregistre un don : la poche part en quarantaine. */
    public function enregistrerDon(Request $request, DonneurSang $donneur): RedirectResponse
    {
        $this->autoriser();

        if (! $donneur->peutDonnerMaintenant()) {
            return back()->with('error', $donneur->motifIndisponibilite());
        }

        $donnees = $request->validate([
            'type_produit' => ['required', Rule::in(array_keys(PocheSang::PRODUITS))],
            'volume_ml' => 'nullable|integer|min:50|max:1000',
            'date_prelevement' => 'nullable|date|before_or_equal:today',
            'emplacement' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        $poche = $this->banque->enregistrerDon($donneur, $donnees);

        return back()->with('success', 'Poche '.$poche->numero.' ('.$poche->groupe_sanguin
            .') enregistrée en quarantaine — à dépister avant toute délivrance.');
    }

    /** Enregistre le dépistage d'une poche. */
    public function depister(Request $request, PocheSang $poche): RedirectResponse
    {
        $this->autoriser();

        $request->validate([
            'depistage_vih' => 'nullable|boolean',
            'depistage_hepatite_b' => 'nullable|boolean',
            'depistage_hepatite_c' => 'nullable|boolean',
            'depistage_syphilis' => 'nullable|boolean',
            'depistage_paludisme' => 'nullable|boolean',
        ]);

        $poche = $this->banque->enregistrerDepistage($poche, [
            'depistage_vih' => $request->boolean('depistage_vih'),
            'depistage_hepatite_b' => $request->boolean('depistage_hepatite_b'),
            'depistage_hepatite_c' => $request->boolean('depistage_hepatite_c'),
            'depistage_syphilis' => $request->boolean('depistage_syphilis'),
            'depistage_paludisme' => $request->boolean('depistage_paludisme'),
        ]);

        $positifs = $poche->marqueursPositifs();

        return back()->with(
            $positifs === [] ? 'success' : 'error',
            $positifs === []
                ? 'Poche '.$poche->numero.' : dépistage négatif — elle passe en rayon.'
                : 'Poche '.$poche->numero.' détruite : dépistage positif ('.implode(', ', $positifs)
                    .'). Le donneur est écarté et doit être orienté vers un soignant.'
        );
    }

    /** Demande de sang adressée par un service. */
    public function demander(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'patient_id' => 'required|uuid|exists:patients,id',
            'groupe_demande' => ['nullable', Rule::in(PocheSang::GROUPES)],
            'type_produit' => ['required', Rule::in(array_keys(PocheSang::PRODUITS))],
            'nombre_poches' => 'required|integer|min:1|max:20',
            'indication' => 'nullable|string|max:1000',
            'hemoglobine' => 'nullable|numeric|min:1|max:25',
        ]);

        $patient = Patient::findOrFail($donnees['patient_id']);

        $demande = $this->banque->creerDemande($patient, [
            ...$donnees,
            'urgence' => $request->boolean('urgence'),
            'visit_id' => Visit::where('patient_id', $patient->id)
                ->where('statut', 'en_cours')->latest('date_entree')->value('id'),
        ]);

        if (! $demande->groupeReceveur()) {
            return redirect()->route('banque-sang.demande', $demande)->with('error',
                'Demande '.$demande->numero.' ouverte, mais le groupe du receveur est inconnu : '
                .'seul du O négatif pourra être proposé. Faites déterminer le groupe.');
        }

        return redirect()->route('banque-sang.demande', $demande)
            ->with('success', 'Demande '.$demande->numero.' ouverte pour '.$patient->nom_complet.'.');
    }

    /** La demande et les poches compatibles qu'on peut lui servir. */
    public function demande(DemandeSang $demande): View
    {
        $this->autoriser();

        $demande->load(['patient.assurances.assurance', 'demandeur', 'transfusions.poche']);

        return view('banque-sang.demande', [
            'demande' => $demande,
            'pochesCompatibles' => $this->banque->pochesPour($demande),
            'donneursAAppeler' => $this->banque->donneursAAppeler(
                $demande->groupeReceveur(),
                $demande->establishment_id
            ),
            'groupesAcceptes' => PocheSang::groupesCompatiblesPour(
                $demande->groupeReceveur(),
                $demande->type_produit
            ),
        ]);
    }

    /** Délivre une poche pour cette demande. */
    public function delivrer(Request $request, DemandeSang $demande): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'poche_id' => 'required|uuid|exists:poches_sang,id',
            'hemoglobine_avant' => 'nullable|numeric|min:1|max:25',
            'heure_debut' => 'nullable|date_format:H:i',
        ]);

        // Le contrôle ultime au lit du malade n'est pas une case à cocher pour
        // la forme : sans lui, la poche ne part pas.
        if (! $request->boolean('controle_ultime')) {
            return back()->with('error',
                'Le contrôle ultime au lit du malade n\'a pas été confirmé : la poche ne peut être délivrée.');
        }

        $poche = PocheSang::findOrFail($donnees['poche_id']);

        $resultat = $this->banque->delivrer($demande, $poche, [
            ...$donnees,
            'controle_ultime' => true,
        ]);

        if ($resultat['erreur']) {
            return back()->with('error', $resultat['erreur']);
        }

        return back()->with('success', 'Poche '.$poche->numero.' ('.$poche->groupe_sanguin
            .') délivrée pour '.$demande->patient->nom_complet.'.');
    }

    /** Refuse une demande, avec son motif. */
    public function refuser(Request $request, DemandeSang $demande): RedirectResponse
    {
        $this->autoriser();

        $request->validate(['motif_refus' => 'required|string|max:500']);

        $demande->update(['statut' => 'refusee', 'motif_refus' => $request->motif_refus]);

        return back()->with('success', 'Demande '.$demande->numero.' refusée — le service est informé du motif.');
    }

    /**
     * La banque engage la sécurité transfusionnelle : elle reste au
     * laboratoire, aux médecins et à la direction.
     */
    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->hasAnyRole([
                'super_admin', 'directeur', 'laborantin', 'medecin', 'infirmier_chef',
            ]),
            403,
            'Banque de sang réservée au laboratoire et à l\'équipe soignante.'
        );
    }

    private function etablissementId(): ?string
    {
        return auth()->user()?->establishment_id
            ?? Establishment::orderBy('created_at')->value('id');
    }
}
