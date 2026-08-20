<?php

namespace App\Http\Controllers;

use App\Models\Lit;
use App\Models\NoteEvolution;
use App\Models\Service;
use App\Models\SigneVital;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Services d'hospitalisation (réanimation, médecine interne, néonatologie,
 * maternité, chirurgie…) sur le modèle du tableau de service GPS :
 * la salle se lit lit par lit, et chaque patient ouvre son dossier de séjour
 * (évolution, transmissions, constantes, produits, prescriptions, examens).
 */
class ServiceHospitalierController extends Controller
{
    /** Services d'hospitalisation (hors plateaux techniques). */
    protected const TYPES_HOSPITALISATION = [
        'medecine', 'chirurgie', 'maternite', 'pediatrie', 'reanimation', 'neonatologie', 'urgence',
    ];

    public function index(): View
    {
        $services = Service::where('is_active', true)
            ->whereIn('type', self::TYPES_HOSPITALISATION)
            ->withCount('lits')
            ->orderBy('nom')
            ->get();

        $occupations = Visit::where('type', 'hospitalisation')
            ->where('statut', 'en_cours')
            ->selectRaw('service_id, count(*) as total')
            ->groupBy('service_id')
            ->pluck('total', 'service_id');

        foreach ($services as $service) {
            $service->setAttribute('patients_actuels', (int) ($occupations[$service->id] ?? 0));
        }

        return view('services.index', compact('services'));
    }

    /**
     * Tableau du service : un lit par ligne, occupé ou non (modèle GPS).
     */
    public function show(Service $service): View
    {
        $lits = $service->lits()->orderBy('numero')->get();

        $visites = Visit::with(['patient', 'lit'])
            ->where('service_id', $service->id)
            ->where('type', 'hospitalisation')
            ->where('statut', 'en_cours')
            ->get()
            ->keyBy('lit_id');

        // Patients du service sans lit attribué (admissions en attente de place)
        $sansLit = Visit::with('patient')
            ->where('service_id', $service->id)
            ->where('type', 'hospitalisation')
            ->where('statut', 'en_cours')
            ->whereNull('lit_id')
            ->get();

        return view('services.show', compact('service', 'lits', 'visites', 'sansLit'));
    }

    /**
     * Dossier de séjour d'un patient hospitalisé : toutes les informations
     * réunies sur une page (le « Dossier Infirmier » + « Évolution » de GPS).
     */
    public function dossier(Service $service, Visit $visit): View
    {
        $visit->load([
            'patient', 'lit', 'service',
            'consultations.user',
            'consultations.prescriptions.lignes.medicament',
            'examensLaboratoire.resultats.typeExamen',
            'actesCliniques.prescripteur',
            'notesEvolution.auteur',
            'signesVitaux.auteur',
            'factures.lignes',
            'transferts.serviceSource', 'transferts.serviceDestination',
            'transferts.litDestination', 'transferts.auteur',
        ]);

        $prescriptions = $visit->consultations->flatMap->prescriptions
            ->sortByDesc('date_prescription');

        $impayees = $visit->factures->whereNotIn('statut', ['payee', 'annulee']);

        // Services d'accueil possibles pour un transfert interne, avec leurs
        // lits libres : on ne propose pas d'envoyer un patient là où il n'y
        // a pas de place.
        $servicesAccueil = Service::where('establishment_id', $visit->establishment_id)
            ->where('is_active', true)
            ->whereNotIn('type', ['labo', 'pharmacie'])
            ->where('id', '!=', $visit->service_id)
            ->with(['lits' => fn ($q) => $q->where('statut', 'libre')->where('is_active', true)->orderBy('numero')])
            ->orderBy('nom')
            ->get();

        $medecins = User::role(['medecin', 'infirmier_chef'])
            ->where('is_active', true)
            ->orderBy('nom')
            ->get(['id', 'nom', 'prenom']);

        return view('services.dossier', compact(
            'service', 'visit', 'prescriptions', 'impayees', 'servicesAccueil', 'medecins'
        ));
    }

    /**
     * Note d'évolution médicale ou transmission infirmière.
     */
    public function storeNote(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate([
            'note' => 'required|string|min:3',
            'type' => 'nullable|in:evolution,transmission',
            'etat_general' => 'nullable|in:bonne,stationnaire,degradee,critique',
        ], [
            'note.required' => "Écrivez la note avant d'enregistrer.",
        ]);

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le dossier est clos.');
        }

        NoteEvolution::create([
            'visit_id' => $visit->id,
            'user_id' => auth()->id(),
            'type' => $request->input('type', 'evolution'),
            'etat_general' => $request->input('etat_general'),
            'note' => $request->note,
        ]);

        return back()->with('success', 'Note enregistrée au dossier.');
    }

    /**
     * Relevé de constantes durant le séjour (surveillance infirmière).
     */
    public function storeSignesVitaux(Request $request, Visit $visit): RedirectResponse
    {
        $request->validate([
            'temperature' => 'nullable|numeric|between:30,45',
            'tension_systolique' => 'nullable|integer|between:50,300',
            'tension_diastolique' => 'nullable|integer|between:30,200',
            'frequence_cardiaque' => 'nullable|integer|between:20,250',
            'frequence_respiratoire' => 'nullable|integer|between:5,80',
            'saturation_o2' => 'nullable|integer|between:50,100',
            'poids_kg' => 'nullable|numeric|between:0.5,400',
            'glycemie' => 'nullable|numeric|between:0,50',
            'observation' => 'nullable|string|max:500',
        ]);

        if (! $visit->peutRecevoirServices()) {
            return back()->with('error', 'Séjour terminé — le dossier est clos.');
        }

        $mesures = $request->only([
            'temperature', 'tension_systolique', 'tension_diastolique',
            'frequence_cardiaque', 'frequence_respiratoire', 'saturation_o2',
            'poids_kg', 'glycemie',
        ]);

        if (collect($mesures)->filter(fn ($v) => $v !== null && $v !== '')->isEmpty()) {
            return back()->with('error', 'Saisissez au moins une constante.');
        }

        SigneVital::create($mesures + [
            'visit_id' => $visit->id,
            'user_id' => auth()->id(),
            'mesure_at' => now(),
            'observation' => $request->input('observation'),
        ]);

        return back()->with('success', 'Constantes enregistrées.');
    }

    /**
     * Cycle de vie du lit après une sortie : à nettoyer, à réparer, libre
     * (statuts du tableau de service GPS).
     */
    public function statutLit(Request $request, Lit $lit): RedirectResponse
    {
        $request->validate([
            'statut' => 'required|in:libre,a_nettoyer,a_reparer,maintenance,reserve',
        ]);

        if ($lit->statut === 'occupe') {
            return back()->with('error', 'Lit occupé — enregistrez d\'abord la sortie du patient.');
        }

        $lit->update(['statut' => $request->statut]);

        return back()->with('success', 'Lit '.$lit->numero.' : '.str_replace('_', ' ', $request->statut).'.');
    }
}
