<?php

namespace App\Http\Controllers;

use App\Models\DemandeSang;
use App\Models\DonneurSang;
use App\Models\Establishment;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\Transfusion;
use App\Models\Visit;
use App\Services\BanqueSangService;
use App\Services\NotificationService;
use App\Services\ParametreService;
use App\Services\ReseauSangService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Banque de sang : stock, donneurs, demandes des services, délivrance.
 */
class BanqueSangController extends Controller
{
    public function __construct(
        private readonly BanqueSangService $banque,
        private readonly ParametreService $parametres,
        private readonly ReseauSangService $reseau,
    ) {}

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

        $demande->load(['patient.assurances.assurance', 'demandeur', 'transfusions.poche', 'transfusions.facture']);

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

    /**
     * Clôture une feuille de transfusion.
     *
     * C'est l'acte qui manquait : sans lui, la poche n'était qu'une sortie
     * de stock. Ici on dit à quelle heure elle a fini de couler, ce que
     * l'hémoglobine est devenue, et si le malade a mal réagi.
     */
    public function cloturerTransfusion(Request $request, Transfusion $transfusion): RedirectResponse
    {
        $this->autoriser();

        if ($transfusion->estCloturee()) {
            return back()->with('info', 'Cette transfusion est déjà clôturée.');
        }

        $donnees = $request->validate([
            'heure_fin' => 'required|date_format:H:i',
            'hemoglobine_apres' => 'nullable|numeric|min:1|max:25',
            'incident' => ['required', Rule::in(array_keys(Transfusion::INCIDENTS))],
            'observation' => 'nullable|string|max:1000',
        ], [
            'heure_fin.required' => 'L\'heure de fin est ce qui distingue une transfusion posée d\'une poche sortie du stock.',
            'incident.required' => 'Dites si la transfusion s\'est passée sans incident : « aucun » est une réponse, le silence n\'en est pas une.',
        ]);

        $transfusion = $this->banque->cloturerTransfusion($transfusion, $donnees);

        if ($transfusion->avecIncident()) {
            return back()->with('error', sprintf(
                'Transfusion %s clôturée avec incident : %s. Le prescripteur et le laboratoire sont prévenus.%s',
                $transfusion->numero_poche,
                $transfusion->libelleIncident(),
                $transfusion->incidentEstGrave() ? ' Arrêtez toute autre poche du même donneur.' : ''
            ));
        }

        $rendement = $transfusion->rendement();

        return back()->with('success', sprintf(
            'Transfusion %s clôturée — %s.%s',
            $transfusion->numero_poche,
            $transfusion->dureeMinutes() !== null ? $transfusion->dureeMinutes().' min de pose' : 'sans incident',
            $rendement !== null
                ? ' Hémoglobine '.($rendement >= 0 ? '+' : '').$rendement.' g/dL.'
                    .($transfusion->rendementInsuffisant() ? ' Gain faible : le saignement se poursuit peut-être.' : '')
                : ''
        ));
    }

    /**
     * Registre transfusionnel : ce qui a été posé, par qui, avec quelle suite.
     */
    public function registre(Request $request): View
    {
        $this->autoriser();

        $filtres = [
            'debut' => $request->query('debut', now()->startOfMonth()->toDateString()),
            'fin' => $request->query('fin', now()->toDateString()),
            'groupe' => $request->query('groupe'),
            'etat' => $request->query('etat'),
        ];

        $transfusions = $this->banque->registre($this->etablissementId(), $filtres);

        return view('banque-sang.registre', [
            'transfusions' => $transfusions,
            'filtres' => $filtres,
            'groupes' => PocheSang::GROUPES,
            'incidents' => Transfusion::INCIDENTS,
            'enCours' => $transfusions->reject->estCloturee()->count(),
            'avecIncident' => $transfusions->filter->avecIncident()->count(),
        ]);
    }

    /**
     * Le réseau : ce que les banques voisines ont en rayon.
     *
     * Chercher du sang à trois heures du matin ne devrait pas consister à
     * appeler les hôpitaux un par un.
     */
    public function reseau(Request $request): View
    {
        $this->autoriser();

        $groupe = $request->query('groupe');
        $produit = $request->query('produit', 'sang_total');
        $etablissement = $this->etablissementId();

        // Les maisons de la même base répondent en direct ; les hôpitaux
        // distants, eux, par le dernier bulletin qu'ils ont publié. Là où un
        // bulletin existe, c'est lui qui fait foi : la fiche locale d'un
        // hôpital distant n'a aucune poche et annoncerait « 0 » pour une
        // banque qui en a quinze.
        $annonces = $this->reseau->codesAnnonces();

        $maisons = $this->banque->reseau($etablissement, $groupe, $produit)
            ->reject(fn (array $m) => in_array($m['code'] ?? null, $annonces, true))
            ->concat($this->reseau->maisonsDistantes($groupe, $produit))
            ->sortByDesc('compatibles')
            ->values();

        return view('banque-sang.reseau', [
            'maisons' => $maisons,
            'groupe' => $groupe,
            'produit' => $produit,
            'groupes' => PocheSang::GROUPES,
            'produits' => PocheSang::PRODUITS,
            'groupesAcceptes' => $groupe ? PocheSang::groupesCompatiblesPour($groupe, $produit) : [],
            'nousPartageons' => $this->banque->partageSonStock($etablissement),
            'peutRegler' => auth()->user()?->hasAnyRole(['super_admin', 'directeur']) ?? false,
            'reseauConfigure' => $this->reseau->configure(),
            'pointDeRendezVous' => $this->reseau->pointDeRendezVous(),
        ]);
    }

    /**
     * Publier notre stock et rapporter celui des autres, à la demande.
     *
     * L'échange se fait aussi tout seul, par la tâche planifiée ; ce bouton
     * est là pour l'urgence, quand on ne veut pas attendre le quart d'heure
     * suivant.
     */
    public function rafraichirReseau(): RedirectResponse
    {
        $this->autoriser();

        $maison = Establishment::find($this->etablissementId());

        if (! $maison) {
            return back()->with('error', 'Établissement introuvable.');
        }

        $resultat = $this->reseau->echanger($maison);

        $abouti = $resultat['publie'] || $resultat['connus'] > 0;

        return back()->with($abouti ? 'success' : 'error', $resultat['message']);
    }

    /** Ouvre ou ferme le partage du stock avec les autres établissements. */
    public function reglerPartage(Request $request): RedirectResponse
    {
        abort_unless(
            auth()->user()?->hasAnyRole(['super_admin', 'directeur']),
            403,
            'Le partage engage l\'établissement : il relève de la direction.'
        );

        $partage = $request->boolean('partage');
        $this->parametres->ecrire(BanqueSangService::CLE_PARTAGE, ['actif' => $partage]);

        return back()->with('success', $partage
            ? 'Votre stock est de nouveau visible des autres établissements du réseau.'
            : 'Votre stock est retiré du réseau : les autres établissements ne le voient plus. Vous continuez de voir le leur.');
    }

    /** Écarte un donneur du fichier, ou l'y remet. */
    public function reglerEligibilite(Request $request, DonneurSang $donneur): RedirectResponse
    {
        $this->autoriser();

        $request->validate([
            'motif_exclusion' => 'required_if:eligible,0|nullable|string|max:255',
        ], [
            'motif_exclusion.required_if' => 'Dites pourquoi ce donneur est écarté : sans motif, personne ne saura le réhabiliter.',
        ]);

        $eligible = $request->boolean('eligible');
        $donneur = $this->banque->reglerEligibilite($donneur, $eligible, $request->input('motif_exclusion'));

        return back()->with('success', $eligible
            ? $donneur->nomComplet().' est de nouveau appelable.'
            : $donneur->nomComplet().' est écarté du fichier — '.$donneur->motif_exclusion.'.');
    }

    /** Refuse une demande, avec son motif. */
    public function refuser(Request $request, DemandeSang $demande): RedirectResponse
    {
        $this->autoriser();

        $request->validate(['motif_refus' => 'required|string|max:500']);

        $demande->update(['statut' => 'refusee', 'motif_refus' => $request->motif_refus]);

        // « Le service est informé » ne doit pas être une figure de style :
        // le demandeur reçoit le motif dans ses notifications.
        if ($demande->demandeur_id) {
            app(NotificationService::class)->envoyer(
                service: 'banque_sang',
                type: 'demande_refusee',
                titre: 'Demande de sang '.$demande->numero.' refusée',
                message: $request->motif_refus,
                referenceType: 'demande_sang',
                referenceId: $demande->id,
                codeReference: $demande->numero,
                destinataireId: $demande->demandeur_id,
                priorite: $demande->urgence ? 'urgente' : 'haute',
                patientId: $demande->patient_id,
            );
        }

        return back()->with('success', 'Demande '.$demande->numero.' refusée — le demandeur reçoit le motif.');
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
