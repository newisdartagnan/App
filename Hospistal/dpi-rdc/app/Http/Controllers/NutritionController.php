<?php

namespace App\Http\Controllers;

use App\Models\Establishment;
use App\Models\MesureAnthropometrique;
use App\Models\Patient;
use App\Models\PriseEnChargeNutritionnelle;
use App\Models\Visit;
use App\Services\NutritionService;
use App\Services\SystemeSanteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Le registre nutritionnel.
 *
 * Il tenait sur des fiches cartonnées rangées dans une boîte, et se
 * recomptait à la main le 30 du mois. Un enfant admis pour malnutrition
 * sévère revient toutes les semaines pendant deux mois : c'est un suivi,
 * pas une visite, et il n'avait nulle part où vivre dans l'application.
 */
class NutritionController extends Controller
{
    public function __construct(
        private readonly NutritionService $nutrition,
        private readonly SystemeSanteService $systemes,
    ) {}

    /** Qui pèse, mesure et admet : ceux qui soignent. */
    private function autoriser(): void
    {
        abort_unless(
            auth()->user()?->can('soin.execute'),
            403,
            'Le registre nutritionnel est tenu par les soignants.'
        );
    }

    private function etablissementId(): ?string
    {
        return auth()->user()?->establishment_id
            ?? Establishment::orderBy('created_at')->value('id');
    }

    /** Les enfants actuellement suivis, unité par unité. */
    public function index(): View
    {
        $this->autoriser();

        $suivis = PriseEnChargeNutritionnelle::query()
            ->with(['patient', 'mesure'])
            ->where('establishment_id', $this->etablissementId())
            ->whereNull('date_sortie')
            ->orderBy('date_admission')
            ->get()
            ->groupBy('unite');

        return view('nutrition.index', [
            'suivis' => $suivis,
            'unitesDeLetablissement' => $this->systemes->unitesNutritionnelles(),
            'systeme' => $this->systemes->definition(),
            // Les sorties du mois, pour que la relève voie ce qui vient de
            // se clore sans aller chercher dans le rapport.
            'sortiesRecentes' => PriseEnChargeNutritionnelle::query()
                ->with('patient')
                ->where('establishment_id', $this->etablissementId())
                ->whereNotNull('date_sortie')
                ->where('date_sortie', '>=', now()->subDays(30)->toDateString())
                ->orderByDesc('date_sortie')
                ->limit(25)
                ->get(),
        ]);
    }

    /** Le dossier nutritionnel d'un patient : ses mesures et son suivi. */
    public function patient(Patient $patient): View
    {
        $this->autoriser();

        $mesures = MesureAnthropometrique::where('patient_id', $patient->id)
            ->with('auteur')
            ->orderByDesc('mesure_a')
            ->limit(30)
            ->get();

        $derniere = $mesures->first();

        return view('nutrition.patient', [
            'patient' => $patient,
            'mesures' => $mesures,
            'derniere' => $derniere,
            'orientation' => $derniere ? $this->nutrition->orientation($derniere) : null,
            'suivi' => $this->nutrition->suiviEnCours($patient),
            'historique' => PriseEnChargeNutritionnelle::where('patient_id', $patient->id)
                ->whereNotNull('date_sortie')
                ->orderByDesc('date_admission')
                ->get(),
            'unitesDeLetablissement' => $this->systemes->unitesNutritionnelles(),
            'visiteEnCours' => Visit::where('patient_id', $patient->id)
                ->whereIn('statut', ['en_attente', 'en_cours'])
                ->orderByDesc('date_entree')
                ->first(),
        ]);
    }

    /** Le poids, la taille, le bras et les œdèmes du jour. */
    public function storeMesure(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'poids_kg' => 'nullable|numeric|min:0.5|max:300',
            'taille_cm' => 'nullable|numeric|min:20|max:250',
            'position_taille' => 'nullable|in:'.implode(',', array_keys(MesureAnthropometrique::POSITIONS)),
            // En millimètres : c'est l'unité du ruban et du protocole.
            'perimetre_brachial_mm' => 'nullable|integer|min:50|max:500',
            'oedemes' => 'required|in:'.implode(',', array_keys(MesureAnthropometrique::OEDEMES)),
            // Lu sur l'abaque par le soignant : l'application ne le calcule
            // pas, elle n'embarque pas les tables de l'OMS.
            'z_score_pt' => 'nullable|numeric|min:-6|max:6',
            'observation' => 'nullable|string|max:1000',
            'visit_id' => 'nullable|uuid|exists:visits,id',
        ], [
            'perimetre_brachial_mm.integer' => 'Le périmètre brachial se note en millimètres entiers (ex. 112).',
        ]);

        // Une mesure sans aucune mesure n'est pas une mesure.
        if (blank($donnees['poids_kg'] ?? null)
            && blank($donnees['taille_cm'] ?? null)
            && blank($donnees['perimetre_brachial_mm'] ?? null)
            && ($donnees['oedemes'] ?? 'aucun') === 'aucun'
            && blank($donnees['z_score_pt'] ?? null)) {
            return back()->withInput()->withErrors([
                'poids_kg' => 'Renseignez au moins une mesure : poids, taille, périmètre brachial, œdèmes ou score P/T.',
            ]);
        }

        $visite = isset($donnees['visit_id']) ? Visit::find($donnees['visit_id']) : null;
        $mesure = $this->nutrition->mesurer($patient, $donnees, $visite);

        // L'orientation n'est pas répétée en bandeau : l'écran la porte déjà
        // sur l'encadré de la mesure, et deux fois la même phrase se lit
        // comme deux messages différents.
        return redirect()
            ->route('nutrition.patient', $patient)
            ->with('success', 'Mesure enregistrée — '.$mesure->libelleEtat().'.');
    }

    /** L'admission dans une unité : la décision reste au soignant. */
    public function storeAdmission(Request $request, Patient $patient): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'unite' => 'required|in:'.implode(',', array_keys(PriseEnChargeNutritionnelle::UNITES)),
            'critere_admission' => 'required|in:'.implode(',', array_keys(PriseEnChargeNutritionnelle::CRITERES)),
            'avec_complication' => 'nullable|boolean',
            'groupe_specifique' => 'nullable|in:'.implode(',', array_keys(PriseEnChargeNutritionnelle::GROUPES_SPECIFIQUES)),
            'date_admission' => 'nullable|date|before_or_equal:today',
            'mesure_id' => 'nullable|uuid|exists:mesures_anthropometriques,id',
            'observation' => 'nullable|string|max:1000',
        ]);

        try {
            $this->nutrition->admettre($patient, $donnees['unite'], $donnees['critere_admission'], [
                'avec_complication' => $request->boolean('avec_complication'),
                'groupe_specifique' => $donnees['groupe_specifique'] ?? null,
                'date_admission' => $donnees['date_admission'] ?? null,
                'mesure_id' => $donnees['mesure_id'] ?? null,
                'observation' => $donnees['observation'] ?? null,
            ]);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['unite' => $e->getMessage()]);
        }

        $sigle = PriseEnChargeNutritionnelle::UNITES[$donnees['unite']]['sigle'];

        return redirect()
            ->route('nutrition.patient', $patient)
            ->with('success', $patient->nom_complet.' est admis à l\''.$sigle.'.');
    }

    /** La décharge : c'est elle qui alimente la moitié du canevas. */
    public function decharger(Request $request, PriseEnChargeNutritionnelle $suivi): RedirectResponse
    {
        $this->autoriser();

        $donnees = $request->validate([
            'issue' => 'required|in:'.implode(',', array_keys(PriseEnChargeNutritionnelle::ISSUES[$suivi->unite] ?? [])),
            'date_sortie' => 'nullable|date|before_or_equal:today|after_or_equal:'.$suivi->date_admission->toDateString(),
            'observation' => 'nullable|string|max:1000',
        ], [
            'issue.in' => 'Cette issue n\'existe pas pour l\''.$suivi->sigle().'.',
            'date_sortie.after_or_equal' => 'La sortie ne peut pas précéder l\'admission du '
                .$suivi->date_admission->format('d/m/Y').'.',
        ]);

        try {
            $this->nutrition->decharger(
                $suivi,
                $donnees['issue'],
                $donnees['date_sortie'] ?? null,
                $donnees['observation'] ?? null,
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['issue' => $e->getMessage()]);
        }

        return redirect()
            ->route('nutrition.patient', $suivi->patient)
            ->with('success', 'Suivi clos — '.$suivi->fresh()->libelleIssue().'.');
    }
}
