<?php

namespace App\Http\Controllers\Laboratoire;

use App\Http\Controllers\Controller;
use App\Services\Laboratoire\AccesLaboratoire;
use App\Services\Laboratoire\ActeurLaboratoire;
use App\Services\Laboratoire\DepotResultats;
use App\Services\Laboratoire\LaboratoireRefuse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRE-05, PRE-06 : portail du laboratoire (rôle laboratoire, compte rattaché
 * à un laboratoire partenaire ACTIF). Il ne voit que les bons payés de SON
 * laboratoire, et rien d'autre du dossier.
 */
class PortailLaboratoireController extends Controller
{
    public function __construct(private readonly AccesLaboratoire $acces, private readonly DepotResultats $depots) {}

    public function index(Request $request): Response
    {
        $acteur = $this->acteur($request);

        return Inertia::render('Laboratoire/Portail', [
            'laboratoire' => $acteur->laboratoire->nom,
            'bons' => $this->depots->bonsDuLaboratoire($acteur),
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']);
    }

    public function bon(Request $request, string $bon): Response
    {
        $acteur = $this->acteur($request);
        try {
            $detail = $this->depots->bon($acteur, $bon);
        } catch (LaboratoireRefuse) {
            abort(404);
        }

        return Inertia::render('Laboratoire/BonLaboratoire', [
            'laboratoire' => $acteur->laboratoire->nom,
            'bon' => $detail,
            'cle' => (string) Str::uuid(),
            'pdfMoMax' => intdiv((int) config('doc-and-i.laboratoire.pdf_octets_max'), 1024 * 1024),
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']);
    }

    public function recevoir(Request $request, string $bon): RedirectResponse
    {
        $acteur = $this->acteur($request);
        $donnees = $request->validate(['code' => ['required', 'string', 'max:20']], ['code.required' => 'Saisissez le code de retrait du patient.']);

        try {
            $this->depots->recevoir($acteur, $this->depots->bonDuLaboratoire($acteur, $bon), $donnees['code']);
        } catch (LaboratoireRefuse $refus) {
            return back()->withErrors(['code' => $refus->getMessage()]);
        }

        return back()->with('succes', 'Patient reçu : vous pourrez déposer les résultats de ce bon.');
    }

    public function deposer(Request $request, string $bon): RedirectResponse
    {
        $acteur = $this->acteur($request);
        $request->validate(['pdf' => ['nullable', 'file']], ['pdf.file' => 'Le compte rendu n\'a pas été reçu en entier. Réessayez.']);
        $fichier = $request->file('pdf');
        if ($fichier !== null && ! $fichier->isValid()) {
            return back()->withErrors(['pdf' => 'Le compte rendu n\'a pas été reçu en entier. Réessayez.']);
        }

        try {
            $this->depots->deposer(
                $acteur,
                $this->depots->bonDuLaboratoire($acteur, $bon),
                $request->only(['valeurs', 'critique', 'cle', 'ligne_id']),
                $fichier ? (string) file_get_contents($fichier->getRealPath()) : null,
            );
        } catch (LaboratoireRefuse $refus) {
            return back()->withErrors(['depot' => $refus->getMessage()]);
        }

        return back()->with('succes', 'Résultats déposés : le médecin prescripteur est prévenu pour les valider.');
    }

    private function acteur(Request $request): ActeurLaboratoire
    {
        // Compte désactivé, non rattaché, ou laboratoire désactivé : plus rien.
        $laboratoire = $this->acces->laboratoireDe($request->user()) ?? abort(403, 'Votre compte n\'est rattaché à aucun laboratoire actif.');

        return ActeurLaboratoire::compte($request->user(), $laboratoire);
    }
}
