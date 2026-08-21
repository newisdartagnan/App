<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\GenerateurDialyse;
use App\Models\Patient;
use App\Models\SeanceDialyse;
use App\Models\User;
use App\Models\Visit;
use App\Services\DialyseService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Unité de dialyse : calendrier des générateurs, séances, registre.
 */
class DialyseController extends Controller
{
    public function __construct(private readonly DialyseService $dialyse) {}

    /**
     * Le calendrier de la semaine, générateur par générateur.
     */
    public function calendrier(Request $request): View
    {
        $lundi = Carbon::parse($request->query('semaine', now()->toDateString()))->startOfWeek();

        $generateurs = $this->generateurs();

        $seances = SeanceDialyse::with(['patient', 'generateur', 'nephrologue'])
            ->whereBetween('date_seance', [$lundi, $lundi->copy()->endOfWeek()])
            ->orderBy('date_seance')
            ->get();

        return view('dialyse.calendrier', [
            'lundi' => $lundi,
            'generateurs' => $generateurs,
            // Indexé par générateur puis par jour : la grille se lit sans
            // refaire de tri dans la vue.
            'grille' => $seances->groupBy('generateur_id')
                ->map(fn ($duGenerateur) => $duGenerateur->groupBy(
                    fn (SeanceDialyse $s) => $s->date_seance->toDateString()
                )),
            'seancesDuJour' => $seances->filter(fn ($s) => $s->date_seance->isToday()),
            'patients' => $this->patientsDialyses(),
            'nephrologues' => $this->nephrologues(),
            'types' => SeanceDialyse::TYPES,
            'abords' => SeanceDialyse::ABORDS,
        ]);
    }

    /** Planifie une séance isolée. */
    public function planifier(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'patient_id' => 'required|uuid|exists:patients,id',
            'generateur_id' => 'required|uuid|exists:generateurs_dialyse,id',
            'date_seance' => 'required|date',
            'duree_minutes' => 'required|integer|min:60|max:600',
            'type' => ['required', Rule::in(array_keys(SeanceDialyse::TYPES))],
            'abord' => ['nullable', Rule::in(array_keys(SeanceDialyse::ABORDS))],
            'poids_sec_kg' => 'nullable|numeric|min:10|max:250',
            'nephrologue_id' => 'nullable|uuid|exists:users,id',
        ], [
            'generateur_id.required' => 'Choisissez le générateur qui recevra le patient.',
            'duree_minutes.required' => 'La durée réserve le poste : indiquez-la.',
        ]);

        $patient = Patient::findOrFail($donnees['patient_id']);
        $donnees['visit_id'] = $this->visiteOuverte($patient)?->id;

        $resultat = $this->dialyse->planifier($patient, $donnees);

        if ($resultat['erreur']) {
            return back()->withInput()->with('error', $resultat['erreur']);
        }

        $seance = $resultat['seance'];

        return back()->with('success', sprintf(
            'Séance programmée le %s à %s sur %s.',
            $seance->date_seance->format('d/m/Y'),
            $seance->date_seance->format('H:i'),
            $seance->generateur->nom
        ));
    }

    /**
     * Programme récurrent : les mêmes jours, pendant plusieurs semaines.
     */
    public function recurrence(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'patient_id' => 'required|uuid|exists:patients,id',
            'generateur_id' => 'required|uuid|exists:generateurs_dialyse,id',
            'jours' => 'required|array|min:1',
            'jours.*' => 'integer|min:1|max:7',
            'heure' => 'required|date_format:H:i',
            'date_debut' => 'required|date',
            'semaines' => 'required|integer|min:1|max:52',
            'duree_minutes' => 'required|integer|min:60|max:600',
            'type' => ['required', Rule::in(array_keys(SeanceDialyse::TYPES))],
            'abord' => ['nullable', Rule::in(array_keys(SeanceDialyse::ABORDS))],
            'poids_sec_kg' => 'nullable|numeric|min:10|max:250',
            'nephrologue_id' => 'nullable|uuid|exists:users,id',
        ], [
            'jours.required' => 'Cochez les jours de dialyse — souvent lundi, mercredi et vendredi.',
        ]);

        $patient = Patient::findOrFail($donnees['patient_id']);

        $resultat = $this->dialyse->programmerRecurrence(
            $patient,
            $donnees['jours'],
            $donnees['heure'],
            Carbon::parse($donnees['date_debut']),
            (int) $donnees['semaines'],
            [
                'generateur_id' => $donnees['generateur_id'],
                'duree_minutes' => $donnees['duree_minutes'],
                'type' => $donnees['type'],
                'abord' => $donnees['abord'] ?? null,
                'poids_sec_kg' => $donnees['poids_sec_kg'] ?? null,
                'nephrologue_id' => $donnees['nephrologue_id'] ?? null,
                'visit_id' => $this->visiteOuverte($patient)?->id,
            ]
        );

        $message = $resultat['creees'].' séance(s) programmée(s) pour '.$patient->nom_complet.'.';

        if ($resultat['conflits'] !== []) {
            return back()->with('error', $message.' '.count($resultat['conflits'])
                .' créneau(x) déjà pris : '.implode(' — ', array_slice($resultat['conflits'], 0, 3)));
        }

        return back()->with('success', $message);
    }

    /** Les séances du jour, à réaliser. */
    public function seances(Request $request): View
    {
        $date = $request->query('date', now()->toDateString());

        return view('dialyse.seances', [
            'seances' => SeanceDialyse::with(['patient', 'generateur', 'nephrologue', 'infirmier'])
                ->whereDate('date_seance', $date)
                ->orderBy('date_seance')
                ->get(),
            'date' => $date,
            'abords' => SeanceDialyse::ABORDS,
            'nephrologues' => $this->nephrologues(),
        ]);
    }

    /** Clôt une séance avec ses mesures de fin. */
    public function realiser(Request $request, SeanceDialyse $seance): RedirectResponse
    {
        if ($seance->estRealisee()) {
            return back()->with('info', 'Cette séance est déjà clôturée.');
        }

        $donnees = $request->validate([
            'poids_avant_kg' => 'required|numeric|min:10|max:250',
            'poids_apres_kg' => 'required|numeric|min:10|max:250',
            'poids_sec_kg' => 'nullable|numeric|min:10|max:250',
            'ultrafiltration_ml' => 'nullable|integer|min:0|max:10000',
            'ta_avant_systolique' => 'nullable|integer|min:40|max:300',
            'ta_avant_diastolique' => 'nullable|integer|min:20|max:200',
            'ta_apres_systolique' => 'nullable|integer|min:40|max:300',
            'ta_apres_diastolique' => 'nullable|integer|min:20|max:200',
            'abord' => ['nullable', Rule::in(array_keys(SeanceDialyse::ABORDS))],
            'anticoagulation' => 'nullable|string|max:50',
            'incidents' => 'nullable|string|max:1000',
            'observations' => 'nullable|string|max:2000',
            'nephrologue_id' => 'nullable|uuid|exists:users,id',
        ], [
            'poids_avant_kg.required' => 'Le poids d\'entrée commande l\'ultrafiltration : il est obligatoire.',
            'poids_apres_kg.required' => 'Le poids de sortie dit ce qui a été retiré : il est obligatoire.',
        ]);

        // Un patient ne peut pas sortir plus lourd qu'il n'est entré.
        if ((float) $donnees['poids_apres_kg'] > (float) $donnees['poids_avant_kg']) {
            return back()->with('error',
                'Le poids de sortie dépasse le poids d\'entrée : vérifiez la pesée.');
        }

        $seance = $this->dialyse->realiser($seance, [
            ...$donnees,
            'erythropoietine' => $request->boolean('erythropoietine'),
        ]);

        $alertes = $seance->alertes();

        return back()->with(
            $alertes === [] ? 'success' : 'error',
            'Séance clôturée — '.($seance->ultrafiltration_ml ?? 0).' ml retirés.'
                .($alertes === [] ? '' : ' À signaler : '.implode(' · ', $alertes))
        );
    }

    /** Marque une absence — le créneau reste tracé. */
    public function absence(SeanceDialyse $seance): RedirectResponse
    {
        if ($seance->estRealisee()) {
            return back()->with('error', 'Séance déjà réalisée.');
        }

        $seance->update(['statut' => 'absente']);

        return back()->with('success', 'Absence notée pour '.$seance->patient->nom_complet.'.');
    }

    /** Registre de l'unité. */
    public function registre(Request $request): View
    {
        $debut = $request->query('debut', now()->startOfMonth()->toDateString());
        $fin = $request->query('fin', now()->toDateString());

        return view('dialyse.registre', [
            'seances' => SeanceDialyse::with(['patient.assurances.assurance', 'generateur', 'nephrologue', 'infirmier'])
                ->whereBetween('date_seance', [$debut.' 00:00:00', $fin.' 23:59:59'])
                ->orderByDesc('date_seance')
                ->get(),
            'indicateurs' => $this->dialyse->indicateurs($debut, $fin),
            'debut' => $debut,
            'fin' => $fin,
        ]);
    }

    /** Séjour ouvert du patient, pour rattacher la séance et la facturer. */
    private function visiteOuverte(Patient $patient): ?Visit
    {
        return Visit::where('patient_id', $patient->id)
            ->where('statut', 'en_cours')
            ->latest('date_entree')
            ->first();
    }

    private function generateurs()
    {
        return GenerateurDialyse::where('est_actif', true)
            ->when($this->etablissementId(), fn ($q, $id) => $q->where('establishment_id', $id))
            ->orderBy('code')
            ->get();
    }

    /**
     * Patients dialysés : ceux qui ont déjà une séance, plus tout patient
     * recherché — un nouveau venu doit pouvoir être programmé.
     */
    private function patientsDialyses()
    {
        return Patient::whereIn('id', SeanceDialyse::select('patient_id')->distinct())
            ->orderBy('nom')
            ->get();
    }

    private function nephrologues()
    {
        return User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['medecin', 'super_admin']))
            ->orderBy('nom')
            ->get();
    }

    private function etablissementId(): ?string
    {
        return auth()->user()?->establishment_id
            ?? Establishment::orderBy('created_at')->value('id');
    }
}
