<?php

namespace App\Http\Controllers;

use App\Models\Consultation;
use App\Models\Facture;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Visit;
use App\Services\DiagnosticService;
use App\Services\FacturationService;
use App\Services\NotificationService;
use App\Services\VisiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ConsultationController extends Controller
{
    public function __construct(private readonly DiagnosticService $diagnostics) {}

    /**
     * File d'attente et historique des consultations.
     *
     * Les filtres passaient par Livewire : sans le script, ils ne faisaient
     * rien du tout — on tapait un nom et la liste ne bougeait pas. Ils sont
     * désormais un formulaire GET ordinaire, comme partout ailleurs, et
     * l'adresse porte le filtre : elle se met en favori et se partage.
     */
    public function index(Request $request): View
    {
        $recherche = trim((string) $request->query('recherche', ''));
        $statut = (string) $request->query('statut', '');
        $date = (string) $request->query('date', '');
        $specialite = (string) $request->query('specialite', '');

        $base = fn () => Visit::with(['patient', 'user', 'consultations', 'typeConsultation'])
            ->when($recherche, function ($q) use ($recherche) {
                $q->whereHas('patient', function ($q) use ($recherche) {
                    $q->whereRaw('LOWER(nom) LIKE ?', ['%'.strtolower($recherche).'%'])
                        ->orWhereRaw('LOWER(prenom) LIKE ?', ['%'.strtolower($recherche).'%'])
                        ->orWhere('dossier_number', 'like', '%'.$recherche.'%');
                });
            })
            // Un patient reçu aux urgences suit la file des urgences, pas
            // celle des consultations : il n'a rien à faire dans les deux.
            ->where('type', 'consultation_externe');

        // File d'attente : visites payées (ou contrôles gratuits), pas encore
        // consultées — groupée par spécialité, celle du médecin connecté en tête.
        $utilisateur = auth()->user();
        $maSpecialite = $utilisateur->specialite;
        // Un médecin (non admin/directeur) ne voit que sa spécialité, ou la
        // médecine générale s'il est généraliste. Infirmiers et admin voient tout.
        $estMedecin = $utilisateur->hasRole('medecin')
            && ! $utilisateur->hasAnyRole(['super_admin', 'directeur']);

        $fileAttente = $base()
            ->where('statut', 'en_cours')
            ->whereDoesntHave('consultations')
            // Patient déjà entré au cabinet : il est avec un médecin, il ne
            // doit plus apparaître dans la file que les autres consultent.
            ->whereNull('consultation_debutee_at')
            ->orderBy('date_entree')
            ->get();

        // Ce que la file contient réellement, avant tout filtre : c'est là
        // que se lisent les spécialités qu'on peut demander.
        $toutesLesAttentes = $fileAttente;

        // La spécialité oriente la file, elle ne la ferme pas : un médecin
        // qui choisit explicitement une autre spécialité — ou « toutes » —
        // doit pouvoir prendre le patient d'un confrère absent. Sans quoi
        // un dossier attend qu'un seul homme revienne.
        if ($estMedecin && $specialite === '') {
            $fileAttente = $fileAttente->filter(function ($v) use ($maSpecialite) {
                $specialite = $v->typeConsultation?->specialite ?: 'Médecine générale';

                return $maSpecialite ? $specialite === $maSpecialite : $specialite === 'Médecine générale';
            });
        }

        // Filtre explicite de spécialité, pour qu'un médecin puisse suivre
        // une file précise même quand il en voit plusieurs.
        if ($specialite !== '') {
            $fileAttente = $fileAttente->filter(
                fn ($v) => ($v->typeConsultation?->specialite ?: 'Médecine générale') === $specialite
            );
        }

        $specialitesEnFile = $toutesLesAttentes
            ->map(fn ($v) => $v->typeConsultation?->specialite ?: 'Médecine générale')
            ->unique()->sort()->values();

        $fileParSpecialite = $fileAttente
            ->groupBy(fn ($v) => $v->typeConsultation?->specialite ?: 'Médecine générale')
            ->sortBy(fn ($groupe, $cle) => $maSpecialite && $cle === $maSpecialite ? 0 : 1);

        // Patients actuellement au cabinet : visibles, mais hors file.
        $auCabinet = $base()
            ->where('statut', 'en_cours')
            ->whereDoesntHave('consultations')
            ->whereNotNull('consultation_debutee_at')
            ->with('medecinConsultant')
            ->orderBy('consultation_debutee_at')
            ->get();

        // Envoyés à la caisse, paiement non encore validé
        $enAttentePaiement = $base()
            ->where('statut', 'en_attente')
            ->with('factures')
            ->orderBy('date_entree')
            ->get();

        // Historique des consultations réalisées
        $visits = $base()
            ->whereHas('consultations')
            ->when($statut, fn ($q) => $q->where('statut', $statut))
            ->when($date, fn ($q) => $q->whereDate('date_entree', $date))
            ->orderByDesc('date_entree')
            ->paginate(20)
            ->withQueryString();

        return view('consultations.index', compact(
            'visits', 'fileAttente', 'fileParSpecialite', 'enAttentePaiement',
            'maSpecialite', 'auCabinet', 'specialitesEnFile', 'toutesLesAttentes',
            'recherche', 'statut', 'date', 'specialite'
        ));
    }

    /**
     * Ancien point d'entrée « nouvelle consultation depuis le patient ».
     * Workflow caisse-first : on redirige vers la visite active du patient
     * (payée → wizard) ou vers sa fiche pour l'envoyer à la caisse.
     */
    public function create(Patient $patient): RedirectResponse
    {
        $active = app(VisiteService::class)->visiteActive($patient);

        if ($active && ($active->consultationPayee() || $active->serviACredit())) {
            return redirect()->route('visites.consulter', $active);
        }

        return redirect()->route('patients.show', $patient)
            ->with('info', 'Le patient doit d\'abord régler la consultation à la caisse.');
    }

    /**
     * Le médecin rend la main sans avoir consulté : le patient retourne
     * dans la file, faute de quoi il y resterait invisible pour tous.
     */
    public function liberer(Visit $visit): RedirectResponse
    {
        if ($visit->consultations()->exists()) {
            return back()->with('error', 'La consultation est déjà enregistrée.');
        }

        $visit->update(['consultation_debutee_at' => null, 'consultation_par' => null]);

        return redirect()->route('consultations.index')
            ->with('success', $visit->patient->nom_complet.' est remis dans la file d\'attente.');
    }

    /**
     * Le médecin démarre la consultation d'une visite payée au guichet.
     */
    public function consulter(Visit $visit): View|RedirectResponse
    {
        abort_unless(auth()->user()->can('consultation.create'), 403,
            'Réservé aux médecins — les infirmiers font le triage.');

        $visit->load('patient');

        if ($consultation = $visit->consultations()->first()) {
            return redirect()->route('consultations.show', $consultation)
                ->with('info', 'La consultation de cette visite est déjà enregistrée.');
        }

        if (! $visit->consultationPayee() && ! $visit->serviACredit()) {
            return redirect()->route('consultations.index')
                ->with('error', 'Consultation non réglée — le patient doit passer à la caisse avant de voir le médecin.');
        }

        // Le patient entre au cabinet : il sort de la file d'attente, pour
        // que deux médecins n'appellent pas la même personne. Un confrère
        // déjà installé garde la main.
        if ($visit->consultation_debutee_at === null) {
            $visit->update([
                'consultation_debutee_at' => now(),
                'consultation_par' => auth()->id(),
            ]);
        } elseif ($visit->consultation_par && $visit->consultation_par !== auth()->id()) {
            $confrere = $visit->medecinConsultant?->nom_complet ?? 'un confrère';

            return redirect()->route('consultations.index')
                ->with('error', "Ce patient est déjà au cabinet avec {$confrere}.");
        }

        return view('consultations.create', [
            'visit' => $visit,
            'patient' => $visit->patient,
            'servicesHospitalisation' => Service::where('is_active', true)
                ->orderBy('nom')->get(),
            'referentielDiagnostics' => $this->diagnostics->referentiel()->map(fn ($e) => [
                'valeur' => $this->diagnostics->proposition($e),
                'aide' => trim($e->categorie.($e->synonymes ? ' — '.$e->synonymes : '')),
            ]),
        ]);
    }

    /**
     * Enregistrement de la consultation du médecin (formulaire classique,
     * aucune dépendance JavaScript — remplace le wizard Livewire).
     */
    public function store(Request $request, Visit $visit): RedirectResponse
    {
        if (! $visit->consultationPayee() && ! $visit->serviACredit()) {
            return redirect()->route('consultations.index')
                ->with('error', 'Consultation non réglée — le patient doit passer à la caisse.');
        }

        if ($existante = $visit->consultations()->first()) {
            return redirect()->route('consultations.show', $existante)
                ->with('info', 'La consultation de cette visite est déjà enregistrée.');
        }

        $donnees = $request->validate([
            'histoire_maladie' => 'nullable|string',
            'antecedents_personnels' => 'nullable|string',
            'antecedents_familiaux' => 'nullable|string',
            'allergies' => 'nullable|string',
            'traitements_en_cours' => 'nullable|string',
            'examen_general' => 'nullable|string',
            'conclusion' => 'nullable|string',
            'conduite_a_tenir' => 'nullable|string',
            'diagnostics' => 'nullable|array',
            'diagnostics.*.libelle' => 'nullable|string|max:255',
            'diagnostics.*.code_cim10' => 'nullable|string|max:20',
            'diagnostics.*.code_cim11' => 'nullable|string|max:20',
            'orientation' => ['nullable', Rule::in(array_keys(Consultation::ORIENTATIONS))],
            'service_oriente_id' => 'nullable|uuid|exists:services,id',
        ]);

        $diagnostics = [];
        foreach ($request->input('diagnostics', []) as $i => $diag) {
            if (blank($diag['libelle'] ?? null)) {
                continue;
            }
            // Le médecin n'a qu'un champ : « Paludisme grave (1F45) ». Le
            // code s'en extrait ici, et la saisie libre reste intacte quand
            // il n'y en a pas.
            $pose = $this->diagnostics->decomposer($diag['libelle']);

            $diagnostics[] = [
                'libelle' => $pose['libelle'],
                'code_cim11' => $pose['code_cim11']
                    ?: (strtoupper(trim($diag['code_cim11'] ?? '')) ?: null),
                // L'ancien champ reste renseigné quand il est fourni : un
                // dossier ouvert avant le passage à la CIM-11 ne se réécrit
                // pas, et les rapports lisent encore les deux.
                'code_cim10' => strtoupper(trim($diag['code_cim10'] ?? '')) ?: null,
                'type' => $i === 0 ? 'principal' : 'associe',
            ];
        }

        $consultation = Consultation::create([
            'visit_id' => $visit->id,
            'user_id' => auth()->id(),
            'date_consultation' => now(),
            'type' => $visit->type === 'urgence' ? 'urgence' : ($visit->gratuite ? 'suivi' : 'initiale'),
            'histoire_maladie' => $donnees['histoire_maladie'] ?? null,
            'antecedents_personnels' => $donnees['antecedents_personnels'] ?? null,
            'antecedents_familiaux' => $donnees['antecedents_familiaux'] ?? null,
            'allergies' => $donnees['allergies'] ?? null,
            'traitements_en_cours' => array_filter([trim((string) ($donnees['traitements_en_cours'] ?? ''))]),
            'examen_general' => $donnees['examen_general'] ?? null,
            'diagnostics' => $diagnostics,
            'conclusion' => $donnees['conclusion'] ?? null,
            'conduite_a_tenir' => $donnees['conduite_a_tenir'] ?? null,
            'orientation' => $donnees['orientation'] ?? null,
            'service_oriente_id' => $donnees['service_oriente_id'] ?? null,
            'statut' => 'finalise',
        ]);

        // Le patient qu'on interne doit apparaître dans le service avant d'y
        // arriver : c'est ainsi que l'équipe prépare le lit au lieu de le
        // découvrir à sa porte.
        if ($consultation->demandeUnLit() && $consultation->service_oriente_id) {
            $this->demanderLadmission($visit, $consultation);
        }

        $visit->update(['user_id' => auth()->id()]);

        return redirect()->route('consultations.show', $consultation)
            ->with('success', 'Consultation enregistrée — vous pouvez prescrire bilans et médicaments.');
    }

    public function show(Consultation $consultation): View
    {
        $consultation->load(['visit.patient', 'visit.factures', 'user']);
        $factureConsult = $consultation->visit->factures()
            ->whereHas('lignes', fn ($q) => $q->where('type', 'consultation'))
            ->first();

        return view('consultations.show', compact('consultation', 'factureConsult'));
    }

    public function facturer(Consultation $consultation): RedirectResponse
    {
        $visit = $consultation->visit;

        $existante = Facture::where('visit_id', $visit->id)
            ->whereHas('lignes', fn ($q) => $q->where('type', 'consultation'))
            ->whereIn('statut', ['emise', 'partiellement_payee', 'payee'])
            ->first();

        if ($existante) {
            return redirect()->route('caisse.show', $existante)
                ->with('info', 'Facture consultation déjà émise.');
        }

        $facture = app(FacturationService::class)->creerFactureConsultation($visit);

        return redirect()->route('caisse.show', $facture)
            ->with('success', 'Facture consultation émise — patient au guichet.');
    }

    /**
     * Pose la demande d'admission et prévient le service.
     *
     * Le lit n'est pas attribué ici : le médecin décide du service, le
     * service place le patient. Un médecin en consultation ne sait pas quel
     * lit vient de se libérer en pédiatrie, et ce n'est pas son travail de
     * le savoir.
     */
    private function demanderLadmission(Visit $visit, Consultation $consultation): void
    {
        $visit->update([
            'admission_demandee_le' => now(),
            'admission_service_id' => $consultation->service_oriente_id,
            'admission_par' => auth()->id(),
        ]);

        $consultation->loadMissing('serviceOriente');

        app(NotificationService::class)->envoyer(
            service: 'hospitalisation',
            type: 'admission_demandee',
            titre: 'Admission demandée en '.($consultation->serviceOriente?->nom ?? 'hospitalisation'),
            message: $visit->patient->nom_complet.' attend un lit — '
                .($consultation->libelleOrientation() ?? 'hospitalisation')
                .'. Décidé par '.(auth()->user()?->nom_complet ?? 'le médecin').'.',
            referenceType: 'visit',
            referenceId: $visit->id,
            codeReference: $visit->patient->dossier_number,
            groupeDestinataire: 'infirmier_chef',
            priorite: 'haute',
            patientId: $visit->patient_id,
        );
    }
}
