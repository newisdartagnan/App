<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Http\Controllers\Dossier\SaisieDossier;
use App\Models\Antecedent;
use App\Models\DemandeRectification;
use App\Models\DossierPatient;
use App\Models\User;
use App\Services\Dossier\AccesDossier;
use App\Services\Dossier\DossierRefuse;
use App\Services\Dossier\DossierService;
use App\Services\Dossier\JournalAccesDossier;
use App\Services\Dossier\LectureDossier;
use App\Services\RendezVous\Affichage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dossier d'un patient, lu et complété par un médecin traitant (DOS-01,
 * DOS-03, DOS-04) : seulement s'il a un rendez-vous confirmé ou terminé
 * avec ce patient ; sinon 404, comme un patient inconnu. Chaque lecture va
 * au journal des accès, que le patient consulte.
 */
class DossierMedecinController extends ControleurMedecin
{
    use SaisieDossier;

    public function __construct(
        private readonly DossierService $dossier,
        private readonly LectureDossier $lecture,
        private readonly AccesDossier $acces,
        private readonly JournalAccesDossier $journal,
    ) {}

    protected function dossierService(): DossierService
    {
        return $this->dossier;
    }

    /** Depuis l'agenda : le dossier du patient de CE rendez-vous du médecin connecté. */
    public function depuisRendezVous(Request $request, string $rendezVous): RedirectResponse
    {
        $rdv = $this->medecin($request)->rendezVous()->whereKey($rendezVous)->firstOrFail();

        return redirect()->route('personnel.medecin.dossier', $rdv->patient_id);
    }

    public function voir(Request $request, string $patient): Response
    {
        $patient = $this->patientSuivi($request, $patient);
        $this->journal->noter($request->user(), $patient->id, 'dossier');

        return Inertia::render('Personnel/Medecin/Dossier', [
            'patient' => [
                'id' => $patient->id,
                'nom' => trim($patient->prenom.' '.mb_strtoupper((string) $patient->nom)),
                'age' => $patient->date_naissance ? (int) $patient->date_naissance->diffInYears(now()) : null,
                'sexe' => ['F' => 'Femme', 'M' => 'Homme'][$patient->sexe] ?? null,
            ],
            ...$this->lecture->etat($patient),
            'historique' => $this->lecture->historique($patient, false),
            'rectifications' => $this->lecture->rectifications($patient),
            'notes' => $this->lecture->notesSignees($patient),
            // Lire n'est pas modifier (revue M3) : les formulaires ne s'affichent qu'après avoir vu le patient.
            'modifiable' => $this->acces->peutModifier($request->user(), $patient),
            'categories' => collect(DossierService::CATEGORIES)->map(fn ($l, $c) => ['valeur' => $c, 'libelle' => $l])->values(),
            'mesures' => collect(DossierService::CONSTANTES)->map(fn ($r, $t) => ['valeur' => $t, 'libelle' => $r['libelle'], 'unite' => $r['unite']])->values(),
            'groupes' => DossierPatient::GROUPES_SANGUINS,
            'consulteLe' => Affichage::dateHeure(now()),
        ]);
    }

    public function ajouterAntecedent(Request $request, string $patient): RedirectResponse
    {
        return $this->saisirAntecedent($request, $this->patientSuivi($request, $patient));
    }

    public function retirerAntecedent(Request $request, string $patient, string $antecedent): RedirectResponse
    {
        $patient = $this->patientSuivi($request, $patient);

        return $this->retirerElement($request, Antecedent::where('patient_id', $patient->id)->findOrFail($antecedent));
    }

    public function ajouterConstante(Request $request, string $patient): RedirectResponse
    {
        return $this->saisirConstante($request, $this->patientSuivi($request, $patient));
    }

    public function groupeSanguin(Request $request, string $patient): RedirectResponse
    {
        return $this->saisirGroupeSanguin($request, $this->patientSuivi($request, $patient));
    }

    public function repondreRectification(Request $request, string $patient, string $demande): RedirectResponse
    {
        $patient = $this->patientSuivi($request, $patient);
        $demande = DemandeRectification::where('patient_id', $patient->id)->findOrFail($demande);
        $donnees = $this->valider($request, [
            'decision' => ['required', 'in:acceptee,refusee'],
            'motif' => ['required', 'string', 'max:1000'],
            'note_id' => ['nullable', 'uuid'],
        ], [
            'decision.required' => 'Acceptez ou refusez la demande.',
            'motif.required' => 'Donnez le motif de votre réponse.',
        ]);

        try {
            $this->dossier->repondreRectification($request->user(), $demande, $donnees['decision'] === 'acceptee', $donnees['motif'], $donnees['note_id'] ?? null);
        } catch (DossierRefuse $refus) {
            return back()->withErrors(['rectification' => $refus->getMessage()]);
        }

        return back()->with('succes', 'Réponse envoyée au patient.');
    }

    /** Patient suivi par le médecin connecté (relation de soin), sinon 404. */
    private function patientSuivi(Request $request, string $id): User
    {
        $patient = str($id)->isUuid() ? User::find($id) : null;
        abort_unless($patient && $this->acces->medecinTraitant($request->user(), $patient), 404);

        return $patient;
    }
}
