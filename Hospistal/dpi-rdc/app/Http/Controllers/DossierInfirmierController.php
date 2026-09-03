<?php

namespace App\Http\Controllers;

use App\Models\ActeInfirmier;
use App\Models\EvaluationNeuro;
use App\Models\Prescription;
use App\Models\SoinGavage;
use App\Models\SoinPansement;
use App\Models\Transfusion;
use App\Models\Visit;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Dossier infirmier d'un patient hospitalisé, présenté en onglets :
 * pansement, gavage, évaluation neurologique et transfusion. Les autres
 * volets (signes vitaux, bilan hydrique, plan de traitement 24 h) ont leur
 * propre écran et sont accessibles depuis l'en-tête.
 */
class DossierInfirmierController extends Controller
{
    public const ONGLETS = [
        // Le registre d'abord : c'est ce qu'on ouvre le plus souvent, et ce
        // qui manquait entièrement.
        'actes' => 'Actes infirmiers',
        'pansement' => 'Pansement',
        'gavage' => 'Gavage',
        'neuro' => 'Évaluation neuro',
        'transfusion' => 'Transfusion',
    ];

    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Qui entre au dossier de soins.
     *
     * Il n'était gardé par rien : n'importe quel compte connecté — la caisse,
     * l'accueil — pouvait y inscrire un pansement. Une trace que tout le
     * monde peut écrire ne vaut pas comme trace.
     */
    private function autoriserLeSoin(): void
    {
        abort_unless(auth()->user()->can('soin.execute'), 403,
            'Le dossier de soins est tenu par l\'équipe soignante.');
    }

    public function index(Request $request, Visit $visit): View
    {
        abort_unless(auth()->user()->can('patient.view'), 403,
            'Réservé au personnel qui suit les patients.');

        $onglet = $request->query('onglet', 'pansement');
        if (! array_key_exists($onglet, self::ONGLETS)) {
            $onglet = 'pansement';
        }

        $visit->load(['patient', 'service', 'lit']);

        $pansements = $visit->soinsPansement()->with('auteur')->orderByDesc('realise_a')->get();
        $gavages = $visit->soinsGavage()->with('auteur')->orderByDesc('realise_a')->get();
        $neuros = $visit->evaluationsNeuro()->with('auteur')->orderByDesc('evalue_a')->get();
        $transfusions = $visit->transfusions()->with('auteur')->orderByDesc('jour')->orderByDesc('heure_debut')->get();

        $actes = ActeInfirmier::with(['auteur', 'prescription'])
            ->where('visit_id', $visit->id)
            ->orderByDesc('realise_a')
            ->get();

        // Les ordonnances du séjour, pour rattacher l'acte à l'ordre qui
        // l'a motivé quand il y en a un.
        $ordonnances = Prescription::with('lignes.medicament')
            ->whereIn('consultation_id', $visit->consultations()->pluck('id'))
            ->orderByDesc('date_prescription')
            ->get();

        return view('infirmier.dossier', compact(
            'visit', 'onglet', 'pansements', 'gavages', 'neuros', 'transfusions',
            'actes', 'ordonnances'
        ));
    }

    // ══════════════════════════════════════════════════════════════
    // Pansement
    // ══════════════════════════════════════════════════════════════
    public function storePansement(Request $request, Visit $visit): RedirectResponse
    {
        $this->autoriserLeSoin();

        $donnees = $request->validate([
            'realise_a' => 'required|date',
            'localisation' => 'required|string|max:150',
            'etat_plaie' => 'required|in:'.implode(',', array_keys(SoinPansement::ETATS)),
            'protocole' => 'required|string|max:1000',
            'date_refaire' => 'nullable|date|after_or_equal:today',
            'observation' => 'nullable|string|max:500',
        ]);

        if ($refus = $this->refusSiSejourClos($visit)) {
            return $refus;
        }

        $soin = SoinPansement::create($donnees + ['visit_id' => $visit->id, 'user_id' => auth()->id()]);

        if ($soin->estPreoccupant()) {
            $this->alerterMedecin(
                $visit,
                'Plaie '.mb_strtolower($soin->libelleEtat()),
                'Plaie '.$soin->localisation.' constatée '.mb_strtolower($soin->libelleEtat())
                    .' chez '.$visit->patient->nom_complet.' — avis médical requis.',
                'pansement',
                $soin->id
            );

            return back()->with('success', 'Pansement enregistré. Le médecin a été alerté sur l\'état de la plaie.');
        }

        return back()->with('success', 'Pansement enregistré.');
    }

    // ══════════════════════════════════════════════════════════════
    // Gavage
    // ══════════════════════════════════════════════════════════════
    public function storeGavage(Request $request, Visit $visit): RedirectResponse
    {
        $this->autoriserLeSoin();

        $donnees = $request->validate([
            'realise_a' => 'required|date',
            'sonde' => 'required|in:'.implode(',', array_keys(SoinGavage::SONDES)),
            'residu_gastrique' => 'nullable|integer|min:0|max:5000',
            'type_aliment' => 'required|string|max:150',
            'quantite_aliment' => 'required|integer|min:0|max:5000',
            'quantite_eliminee' => 'nullable|integer|min:0|max:5000',
            'tolerance' => 'required|in:'.implode(',', array_keys(SoinGavage::TOLERANCES)),
            'observation' => 'nullable|string|max:500',
        ]);

        if ($refus = $this->refusSiSejourClos($visit)) {
            return $refus;
        }

        $gavage = SoinGavage::create($donnees + [
            'visit_id' => $visit->id,
            'user_id' => auth()->id(),
            'residu_gastrique' => (int) $request->input('residu_gastrique', 0),
            'quantite_eliminee' => (int) $request->input('quantite_eliminee', 0),
        ]);

        if ($alerte = $gavage->alerte()) {
            $this->alerterMedecin(
                $visit,
                'Gavage mal toléré',
                $visit->patient->nom_complet.' — '.$alerte,
                'gavage',
                $gavage->id
            );

            return back()->with('success', 'Gavage enregistré. '.$alerte);
        }

        return back()->with('success', 'Gavage enregistré.');
    }

    // ══════════════════════════════════════════════════════════════
    // Évaluation neurologique
    // ══════════════════════════════════════════════════════════════
    public function storeNeuro(Request $request, Visit $visit): RedirectResponse
    {
        $this->autoriserLeSoin();

        $donnees = $request->validate([
            'evalue_a' => 'required|date',
            'ouverture_yeux' => 'required|integer|min:1|max:4',
            'reponse_verbale' => 'required|integer|min:1|max:5',
            'reponse_motrice' => 'required|integer|min:1|max:6',
            'pupille_droite' => 'nullable|in:'.implode(',', array_keys(EvaluationNeuro::PUPILLES)),
            'pupille_gauche' => 'nullable|in:'.implode(',', array_keys(EvaluationNeuro::PUPILLES)),
            'observation' => 'nullable|string|max:500',
        ]);

        if ($refus = $this->refusSiSejourClos($visit)) {
            return $refus;
        }

        $score = EvaluationNeuro::calculerScore(
            (int) $donnees['ouverture_yeux'],
            (int) $donnees['reponse_verbale'],
            (int) $donnees['reponse_motrice'],
        );

        $evaluation = EvaluationNeuro::create($donnees + [
            'visit_id' => $visit->id,
            'user_id' => auth()->id(),
            'score' => $score,
        ]);

        // Le séjour porte le dernier Glasgow connu : le triage d'urgence et
        // les écrans de service s'appuient dessus sans requête supplémentaire.
        $visit->update(['glasgow' => $score]);

        if ($alerte = $evaluation->alerte()) {
            $this->alerterMedecin(
                $visit,
                'Glasgow '.$score.'/15',
                $visit->patient->nom_complet.' — '.$alerte,
                'evaluation_neuro',
                $evaluation->id,
                $score <= 8 ? 'urgente' : 'haute'
            );

            return back()->with('success', 'Évaluation enregistrée — Glasgow '.$score.'/15. '.$alerte);
        }

        return back()->with('success', 'Évaluation enregistrée — Glasgow '.$score.'/15.');
    }

    // ══════════════════════════════════════════════════════════════
    // Transfusion
    // ══════════════════════════════════════════════════════════════
    public function storeTransfusion(Request $request, Visit $visit): RedirectResponse
    {
        $this->autoriserLeSoin();

        $groupes = implode(',', Transfusion::GROUPES);

        $donnees = $request->validate([
            'produit' => 'required|in:'.implode(',', array_keys(Transfusion::PRODUITS)),
            'groupe_donneur' => 'required|in:'.$groupes,
            'groupe_receveur' => 'required|in:'.$groupes,
            'numero_poche' => 'required|string|max:50',
            'quantite' => 'required|integer|min:10|max:1000',
            'jour' => 'required|date',
            'heure_debut' => 'required|date_format:H:i',
            'heure_fin' => 'nullable|date_format:H:i',
            'incident' => 'required|in:'.implode(',', array_keys(Transfusion::INCIDENTS)),
            'observation' => 'nullable|string|max:500',
        ]);

        if ($refus = $this->refusSiSejourClos($visit)) {
            return $refus;
        }

        // Sécurité transfusionnelle : une poche incompatible n'est jamais posée.
        if (! Transfusion::estCompatible($donnees['produit'], $donnees['groupe_donneur'], $donnees['groupe_receveur'])) {
            return back()->withInput()->with(
                'error',
                'Transfusion refusée : une poche '.$donnees['groupe_donneur']
                    .' est incompatible avec un receveur '.$donnees['groupe_receveur']
                    .' pour ce produit. Vérifiez le groupage avant de poser.'
            );
        }

        if (Transfusion::where('visit_id', $visit->id)->where('numero_poche', $donnees['numero_poche'])->exists()) {
            return back()->withInput()->with('error', 'La poche '.$donnees['numero_poche'].' est déjà enregistrée pour ce séjour.');
        }

        $transfusion = Transfusion::create($donnees + ['visit_id' => $visit->id, 'user_id' => auth()->id()]);

        if ($transfusion->avecIncident()) {
            $this->alerterMedecin(
                $visit,
                'Incident transfusionnel',
                $visit->patient->nom_complet.' — '.$transfusion->libelleIncident()
                    .' sur la poche '.$transfusion->numero_poche.'.',
                'transfusion',
                $transfusion->id,
                'urgente'
            );

            return back()->with('success', 'Transfusion enregistrée. Incident signalé au médecin.');
        }

        return back()->with('success', 'Transfusion enregistrée — poche '.$transfusion->numero_poche.'.');
    }

    /** Clôture d'une poche en cours : on note l'heure de fin et l'incident. */
    public function terminerTransfusion(Request $request, Transfusion $transfusion): RedirectResponse
    {
        $this->autoriserLeSoin();

        $donnees = $request->validate([
            'heure_fin' => 'required|date_format:H:i',
            'incident' => 'required|in:'.implode(',', array_keys(Transfusion::INCIDENTS)),
            'observation' => 'nullable|string|max:500',
        ]);

        $transfusion->update($donnees);

        if ($transfusion->avecIncident()) {
            $this->alerterMedecin(
                $transfusion->visit,
                'Incident transfusionnel',
                $transfusion->visit->patient->nom_complet.' — '.$transfusion->libelleIncident()
                    .' sur la poche '.$transfusion->numero_poche.'.',
                'transfusion',
                $transfusion->id,
                'urgente'
            );
        }

        return back()->with('success', 'Poche '.$transfusion->numero_poche.' terminée.');
    }

    // ══════════════════════════════════════════════════════════════

    private function refusSiSejourClos(Visit $visit): ?RedirectResponse
    {
        return $visit->peutRecevoirServices()
            ? null
            : back()->with('error', 'Séjour terminé — le dossier est clos.');
    }

    /** Alerte le médecin en charge du séjour, à défaut toute l'équipe médicale. */
    private function alerterMedecin(
        Visit $visit,
        string $titre,
        string $message,
        string $referenceType,
        string $referenceId,
        string $priorite = 'haute'
    ): void {
        $this->notifications->envoyer(
            service: 'hospitalisation',
            type: 'alerte_soins',
            titre: $titre,
            message: $message,
            referenceType: $referenceType,
            referenceId: $referenceId,
            codeReference: $visit->patient->dossier_number ?? null,
            // Une alerte de soins concerne le patient, pas seulement le
            // médecin qui l'a admis : c'est celui qui est là qui doit agir.
            destinataireId: $visit->user_id,
            groupeDestinataire: $visit->user_id ? null : 'medecin',
            priorite: $priorite,
            patientId: $visit->patient_id,
        );
    }

    // ══════════════════════════════════════════════════════════════
    // Actes infirmiers
    // ══════════════════════════════════════════════════════════════

    /**
     * Inscrit un acte réalisé.
     *
     * L'auteur et l'heure ne se choisissent pas : c'est l'agent connecté et
     * l'horloge. Une trace qu'on peut antidater ou signer au nom d'un autre
     * ne vaut pas comme trace — et à la relève, c'est elle qu'on lit pour
     * savoir si la deuxième injection a été faite.
     */
    public function storeActe(Request $request, Visit $visit): RedirectResponse
    {
        $this->autoriserLeSoin();

        $donnees = $request->validate([
            'type' => ['required', Rule::in(array_keys(ActeInfirmier::TYPES))],
            'precisions' => 'nullable|string|max:1000',
            'observation' => 'nullable|string|max:1000',
            'prescription_id' => 'nullable|uuid|exists:prescriptions,id',
        ], [
            'type.required' => 'Dites quel acte a été réalisé.',
        ]);

        $acte = ActeInfirmier::create([
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'user_id' => auth()->id(),
            'type' => $donnees['type'],
            'libelle' => ActeInfirmier::TYPES[$donnees['type']]['libelle'],
            'precisions' => $donnees['precisions'] ?? null,
            'observation' => $donnees['observation'] ?? null,
            'prescription_id' => $donnees['prescription_id'] ?? null,
            'realise_a' => now(),
        ]);

        return back()->with('success',
            $acte->libelleType().' inscrit à '.$acte->realise_a->format('H:i').'.');
    }
}
