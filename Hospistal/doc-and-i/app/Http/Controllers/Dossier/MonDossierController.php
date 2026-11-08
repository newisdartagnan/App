<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Controller;
use App\Models\Antecedent;
use App\Models\DossierPatient;
use App\Services\Dossier\DossierRefuse;
use App\Services\Dossier\DossierService;
use App\Services\Dossier\LectureDossier;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as ReponseHttp;

/**
 * « Mon dossier » (DOS-01, DOS-03, DOS-04) : le patient lit son dossier,
 * déclare ses antécédents, allergies, traitements, constantes et groupe
 * sanguin, demande une rectification, télécharge son dossier en PDF et voit
 * qui l'a consulté. Tout porte sur le patient connecté : aucun identifiant
 * de patient ne vient du navigateur.
 */
class MonDossierController extends Controller
{
    use SaisieDossier;

    public function __construct(private readonly DossierService $dossier, private readonly LectureDossier $lecture) {}

    protected function dossierService(): DossierService
    {
        return $this->dossier;
    }

    public function index(Request $request): Response
    {
        $patient = $request->user();

        return Inertia::render('Dossier/MonDossier', [
            ...$this->lecture->etat($patient),
            'historique' => $this->lecture->historique($patient, true),
            'rectifications' => $this->lecture->rectifications($patient),
            'notes' => $this->lecture->notesSignees($patient),
            'modifiable' => true,
            'acces' => $this->lecture->accesAuDossier($patient),
            'categories' => collect(DossierService::CATEGORIES)->map(fn ($l, $c) => ['valeur' => $c, 'libelle' => $l])->values(),
            'mesures' => collect(DossierService::CONSTANTES)->map(fn ($r, $t) => ['valeur' => $t, 'libelle' => $r['libelle'], 'unite' => $r['unite']])->values(),
            'groupes' => DossierPatient::GROUPES_SANGUINS,
        ]);
    }

    public function ajouterAntecedent(Request $request): RedirectResponse
    {
        return $this->saisirAntecedent($request, $request->user());
    }

    public function retirerAntecedent(Request $request, Antecedent $antecedent): RedirectResponse
    {
        abort_unless($antecedent->patient_id === $request->user()->id, 404);

        return $this->retirerElement($request, $antecedent);
    }

    public function ajouterConstante(Request $request): RedirectResponse
    {
        return $this->saisirConstante($request, $request->user());
    }

    public function groupeSanguin(Request $request): RedirectResponse
    {
        return $this->saisirGroupeSanguin($request, $request->user());
    }

    public function demanderRectification(Request $request): RedirectResponse
    {
        $donnees = $this->valider($request, [
            'demande' => ['required', 'string', 'max:2000'],
            'note_id' => ['nullable', 'uuid'],
        ], [
            'demande.required' => 'Expliquez ce qu\'il faut corriger.',
        ]);

        try {
            $this->dossier->demanderRectification($request->user(), $donnees['demande'], $donnees['note_id'] ?? null);
        } catch (DossierRefuse $refus) {
            return back()->withErrors(['demande' => $refus->getMessage()]);
        }

        return back()->with('succes', 'Demande de rectification envoyée. Un médecin qui vous suit vous répondra.');
    }

    /** DOS-04 : le dossier en PDF, au patient seulement, sans cache ni ressource externe. */
    public function pdf(Request $request): ReponseHttp
    {
        $patient = $request->user();
        $etat = $this->lecture->etat($patient);

        $pdf = Pdf::setOption(['isRemoteEnabled' => false, 'isPhpEnabled' => false])->loadView('pdf.dossier', [
            'patient' => $patient,
            'etat' => $etat,
            'historique' => $this->lecture->historique($patient, true),
            'emis' => now()->setTimezone('Africa/Kinshasa')->format('d/m/Y à H:i'),
        ]);

        return $pdf->download('dossier-medical.pdf')->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']);
    }
}
