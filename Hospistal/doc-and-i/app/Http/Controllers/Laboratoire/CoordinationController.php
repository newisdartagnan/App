<?php

namespace App\Http\Controllers\Laboratoire;

use App\Http\Controllers\Controller;
use App\Services\Laboratoire\AlertesSoignants;
use App\Services\Laboratoire\LaboratoireRefuse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PRE-08, RG-10 : écran du coordinateur, escalades ouvertes (rôle coordinateur). */
class CoordinationController extends Controller
{
    public function __construct(private readonly AlertesSoignants $alertes) {}

    public function index(): Response
    {
        return Inertia::render('Laboratoire/Coordination', ['escalades' => $this->alertes->escaladesOuvertes()]);
    }

    public function traiter(Request $request, string $escalade): RedirectResponse
    {
        try {
            $this->alertes->traiterEscalade($request->user(), $escalade);
        } catch (LaboratoireRefuse $refus) {
            abort(404, $refus->getMessage());
        }

        return back()->with('succes', 'Escalade traitée.');
    }
}
