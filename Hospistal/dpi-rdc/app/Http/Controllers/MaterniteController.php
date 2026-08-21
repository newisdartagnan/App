<?php

namespace App\Http\Controllers;

use App\Models\Accouchement;
use App\Models\Grossesse;
use App\Models\NouveauNe;
use App\Models\Patient;
use App\Models\PocheSang;
use App\Models\User;
use App\Services\MaterniteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Maternité : fiches obstétricales, consultations prénatales, accouchements.
 */
class MaterniteController extends Controller
{
    public function __construct(private readonly MaterniteService $maternite) {}

    /** Les grossesses suivies, celles qui approchent du terme en tête. */
    public function index(Request $request): View
    {
        $statut = $request->query('statut', 'en_cours');
        $recherche = trim((string) $request->query('recherche', ''));

        $grossesses = Grossesse::with(['patient', 'consultations', 'accouchement'])
            ->when($statut !== 'toutes', fn ($q) => $q->where('statut', $statut))
            ->when($recherche !== '', fn ($q) => $q->whereHas('patient', fn ($p) => $p
                ->where('nom', 'ilike', "%{$recherche}%")
                ->orWhere('postnom', 'ilike', "%{$recherche}%")
                ->orWhere('prenom', 'ilike', "%{$recherche}%")
                ->orWhere('dossier_number', 'ilike', "%{$recherche}%")))
            // Le terme le plus avancé d'abord : ce sont elles qu'on verra
            // arriver en salle de travail.
            ->orderBy('date_prevue_accouchement')
            ->paginate(20)
            ->withQueryString();

        return view('maternite.index', compact('grossesses', 'statut', 'recherche'));
    }

    /** Ouvre une fiche de grossesse pour une patiente. */
    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'patient_id' => 'required|uuid|exists:patients,id',
            'date_dernieres_regles' => 'nullable|date|before_or_equal:today',
            'date_prevue_accouchement' => 'nullable|date',
            'gestite' => 'required|integer|min:1|max:20',
            'parite' => 'required|integer|min:0|max:20',
            'avortements' => 'nullable|integer|min:0|max:20',
            'groupe_sanguin' => ['nullable', Rule::in(PocheSang::GROUPES)],
            'antecedents' => 'nullable|string|max:2000',
            'motif_risque' => 'nullable|string|max:500',
            'serologies' => 'nullable|array',
        ], [
            'date_dernieres_regles.before_or_equal' => 'La date des dernières règles ne peut être dans l\'avenir.',
        ]);

        $patiente = Patient::findOrFail($donnees['patient_id']);

        if ($patiente->sexe !== 'F') {
            return back()->with('error', 'Une fiche obstétricale ne s\'ouvre que pour une patiente.');
        }

        $existante = Grossesse::where('patient_id', $patiente->id)->where('statut', 'en_cours')->first();

        $grossesse = $this->maternite->ouvrirGrossesse($patiente, [
            ...$donnees,
            'grossesse_a_risque' => $request->boolean('grossesse_a_risque'),
        ]);

        return redirect()->route('maternite.show', $grossesse)->with(
            $existante ? 'info' : 'success',
            $existante
                ? 'Cette patiente a déjà une grossesse en cours : voici sa fiche.'
                : 'Fiche obstétricale ouverte'
                    .($grossesse->date_prevue_accouchement
                        ? ' — terme prévu le '.$grossesse->date_prevue_accouchement->format('d/m/Y').'.'
                        : ' — date des dernières règles à renseigner pour calculer le terme.')
        );
    }

    /** La fiche : suivi prénatal, accouchement, enfants. */
    public function show(Grossesse $grossesse): View
    {
        $grossesse->load([
            'patient', 'consultations.soignant',
            'accouchement.nouveauNes.patient', 'accouchement.accoucheur',
        ]);

        return view('maternite.show', [
            'grossesse' => $grossesse,
            'accoucheurs' => $this->accoucheurs(),
            'modes' => Accouchement::MODES,
            'presentations' => Accouchement::PRESENTATIONS,
            'delivrances' => Accouchement::DELIVRANCES,
            'dechirures' => Accouchement::DECHIRURES,
            'etatsMere' => Accouchement::ETATS_MERE,
            'statutsEnfant' => NouveauNe::STATUTS,
            'serologies' => Grossesse::SEROLOGIES,
        ]);
    }

    /** Enregistre une consultation prénatale. */
    public function consultation(Request $request, Grossesse $grossesse): RedirectResponse
    {
        if (! $grossesse->estEnCours()) {
            return back()->with('error', 'Cette grossesse est close : plus de consultation prénatale à y ajouter.');
        }

        $donnees = $request->validate([
            'date_consultation' => 'nullable|date',
            'poids_kg' => 'nullable|numeric|min:20|max:200',
            'tension_systolique' => 'nullable|integer|min:50|max:300',
            'tension_diastolique' => 'nullable|integer|min:30|max:200',
            'hauteur_uterine_cm' => 'nullable|numeric|min:5|max:60',
            'bruits_coeur_foetal' => 'nullable|integer|min:40|max:220',
            'presentation' => 'nullable|string|max:30',
            'oedemes' => 'nullable|string|max:20',
            'albuminurie' => 'nullable|string|max:20',
            'glycosurie' => 'nullable|string|max:20',
            'hemoglobine' => 'nullable|numeric|min:2|max:25',
            'vat_dose' => 'nullable|integer|min:1|max:5',
            'observations' => 'nullable|string|max:2000',
            'conduite_a_tenir' => 'nullable|string|max:2000',
            'prochain_rendez_vous' => 'nullable|date|after:today',
        ]);

        $consultation = $this->maternite->enregistrerConsultation($grossesse, [
            ...$donnees,
            'fer_folates' => $request->boolean('fer_folates'),
            'sulfadoxine_pyrimethamine' => $request->boolean('sulfadoxine_pyrimethamine'),
            'moustiquaire_remise' => $request->boolean('moustiquaire_remise'),
        ]);

        $alertes = $consultation->alertes();

        return back()->with(
            $alertes === [] ? 'success' : 'error',
            $alertes === []
                ? 'Consultation prénatale n° '.$consultation->numero.' enregistrée — terme '
                    .$consultation->terme_semaines.' SA.'
                : 'Consultation enregistrée, mais à surveiller : '.implode(' · ', $alertes)
        );
    }

    /** Enregistre l'accouchement et clôt la grossesse. */
    public function accouchement(Request $request, Grossesse $grossesse): RedirectResponse
    {
        if (! $grossesse->estEnCours()) {
            return back()->with('error', 'Cette grossesse est déjà close.');
        }

        $donnees = $request->validate([
            'date_accouchement' => 'required|date',
            'debut_travail' => 'nullable|date|before_or_equal:date_accouchement',
            'visit_id' => 'nullable|uuid|exists:visits,id',
            'mode' => ['required', Rule::in(array_keys(Accouchement::MODES))],
            'presentation' => ['nullable', Rule::in(array_keys(Accouchement::PRESENTATIONS))],
            'delivrance' => ['nullable', Rule::in(array_keys(Accouchement::DELIVRANCES))],
            'dechirure' => ['nullable', Rule::in(array_keys(Accouchement::DECHIRURES))],
            'saignement_ml' => 'nullable|integer|min:0|max:10000',
            'accoucheur_id' => 'nullable|uuid|exists:users,id',
            'sage_femme' => 'nullable|string|max:150',
            'etat_mere' => ['required', Rule::in(array_keys(Accouchement::ETATS_MERE))],
            'complications' => 'nullable|string|max:2000',
            'observations' => 'nullable|string|max:2000',
            'enfants' => 'required|array|min:1',
            'enfants.*.sexe' => 'nullable|in:M,F',
            'enfants.*.prenom' => 'nullable|string|max:100',
            'enfants.*.poids_g' => 'nullable|integer|min:200|max:7000',
            'enfants.*.taille_cm' => 'nullable|numeric|min:15|max:70',
            'enfants.*.perimetre_cranien_cm' => 'nullable|numeric|min:15|max:60',
            'enfants.*.apgar_1' => 'nullable|integer|min:0|max:10',
            'enfants.*.apgar_5' => 'nullable|integer|min:0|max:10',
            'enfants.*.apgar_10' => 'nullable|integer|min:0|max:10',
            'enfants.*.statut' => ['nullable', Rule::in(array_keys(NouveauNe::STATUTS))],
            'enfants.*.malformations' => 'nullable|string|max:500',
        ], [
            'enfants.required' => 'Un accouchement compte au moins un enfant, fût-il mort-né.',
            'debut_travail.before_or_equal' => 'Le début du travail ne peut suivre la naissance.',
        ]);

        // Une ligne d'enfant sans le moindre renseignement n'est pas un enfant.
        $enfants = collect($donnees['enfants'])
            ->filter(fn ($e) => filled($e['sexe'] ?? null) || filled($e['poids_g'] ?? null))
            ->map(fn ($e) => [
                ...$e,
                'reanimation' => filled($e['reanimation'] ?? null),
                'mise_au_sein_precoce' => filled($e['mise_au_sein_precoce'] ?? null),
            ])
            ->values()
            ->all();

        if ($enfants === []) {
            return back()->withInput()->with('error',
                'Renseignez au moins le sexe ou le poids d\'un enfant.');
        }

        unset($donnees['enfants']);

        $accouchement = $this->maternite->enregistrerAccouchement($grossesse, [
            ...$donnees,
            'episiotomie' => $request->boolean('episiotomie'),
            'transfusion' => $request->boolean('transfusion'),
        ], $enfants);

        $messages = [];

        if ($accouchement->estHemorragique()) {
            $messages[] = 'hémorragie de la délivrance ('.$accouchement->saignement_ml.' ml)';
        }
        if ($accouchement->estPremature()) {
            $messages[] = 'prématurité ('.$accouchement->terme_semaines.' SA)';
        }
        foreach ($accouchement->nouveauNes as $enfant) {
            if ($enfant->souffranceNeonatale()) {
                $messages[] = 'souffrance néonatale (Apgar '.$enfant->apgar_5.'/10 à 5 min) — enfant '.$enfant->rang;
            }
        }

        return back()->with(
            $messages === [] ? 'success' : 'error',
            'Accouchement enregistré — '.$accouchement->nouveauNes->count().' enfant(s), '
                .$accouchement->nouveauNes->where('statut', 'vivant')->count().' vivant(s).'
                .($messages === [] ? '' : ' À surveiller : '.implode(' · ', $messages).'.')
        );
    }

    /** Registre des accouchements. */
    public function registre(Request $request): View
    {
        $debut = $request->query('debut', now()->startOfMonth()->toDateString());
        $fin = $request->query('fin', now()->toDateString());

        return view('maternite.registre', [
            'accouchements' => Accouchement::with([
                'patient.assurances.assurance', 'nouveauNes', 'accoucheur', 'grossesse',
            ])
                ->whereBetween('date_accouchement', [$debut.' 00:00:00', $fin.' 23:59:59'])
                ->orderByDesc('date_accouchement')
                ->get(),
            'indicateurs' => $this->maternite->indicateurs($debut, $fin),
            'debut' => $debut,
            'fin' => $fin,
        ]);
    }

    /** Fiche obstétricale imprimable. */
    public function fiche(Grossesse $grossesse): View
    {
        $grossesse->load([
            'patient.assurances.assurance', 'consultations.soignant',
            'accouchement.nouveauNes', 'accouchement.accoucheur',
        ]);

        return view('maternite.fiche', compact('grossesse'));
    }

    private function accoucheurs()
    {
        return User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name',
                ['medecin', 'infirmier_chef', 'infirmier', 'super_admin']))
            ->orderBy('nom')
            ->get();
    }
}
