<?php

namespace App\Http\Controllers\Laboratoire;

use App\Http\Controllers\Controller;
use App\Services\Laboratoire\AlertesSoignants;
use App\Services\Laboratoire\LaboratoireRefuse;
use App\Services\Laboratoire\ValidationResultats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRE-07, PRE-08, RG-10 : résultats déposés, côté soignant. Le même écran
 * sert au médecin prescripteur (/personnel/medecin/…) et au coordinateur
 * pour un bon escaladé (/personnel/coordination/…). Le service revérifie
 * à chaque requête qui peut lire et qui peut valider ; un refus répond 403.
 */
class ResultatsSoignantController extends Controller
{
    public function __construct(private readonly ValidationResultats $validation, private readonly AlertesSoignants $alertes) {}

    /** Médecin : bons qui attendent sa validation. */
    public function aValider(Request $request): Response
    {
        return $this->ou403(fn () => Inertia::render('Laboratoire/ResultatsAValider', [
            'bons' => $this->validation->aValider($request->user()),
        ])->toResponse($request));
    }

    public function detail(Request $request, string $bon): Response
    {
        return $this->ou403(fn () => Inertia::render('Laboratoire/Resultats', $this->validation->detail($request->user(), $bon) + [
            'espace' => $this->espace($request),
        ])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store, max-age=0']));
    }

    public function valider(Request $request, string $resultat): RedirectResponse
    {
        $donnees = $request->validate(['commentaire' => ['nullable', 'string', 'max:2000']], ['commentaire.max' => 'Commentaire trop long (2 000 caractères au plus).']);

        return $this->ou403(function () use ($request, $resultat, $donnees) {
            $this->validation->valider($request->user(), $resultat, $donnees['commentaire'] ?? null);

            return back()->with('succes', 'Résultat validé : le patient est prévenu et le voit dans son espace.');
        });
    }

    public function pdf(Request $request, string $resultat): Response
    {
        return $this->ou403(fn () => $this->validation->pdfPourSoignant($request->user(), $resultat));
    }

    /** Médecin : portail d'alertes. */
    public function alertes(Request $request): Response
    {
        return $this->ou403(fn () => Inertia::render('Laboratoire/AlertesMedecin', [
            'alertes' => $this->alertes->duMedecin($request->user()),
        ])->toResponse($request));
    }

    public function marquerAlerte(Request $request, string $alerte, string $etat): RedirectResponse
    {
        abort_unless(in_array($etat, ['lue', 'traitee'], true), 404);

        return $this->ou403(function () use ($request, $alerte, $etat) {
            $this->alertes->marquer($request->user(), $alerte, $etat);

            return back()->with('succes', $etat === 'lue' ? 'Alerte marquée lue.' : 'Alerte traitée.');
        });
    }

    private function espace(Request $request): string
    {
        return $request->routeIs('personnel.coordination.*') ? 'coordination' : 'medecin';
    }

    /** @template T  @param  callable(): T  $action  @return T */
    private function ou403(callable $action): mixed
    {
        try {
            return $action();
        } catch (LaboratoireRefuse $refus) {
            abort(403, $refus->getMessage());
        }
    }
}
