<?php

namespace App\Http\Controllers\Personnel;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Accueil du personnel : le médecin va dans son espace ; les autres rôles ont le leur à venir. */
class AccueilPersonnelController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user()->hasRole('medecin')) {
            return redirect()->route('personnel.medecin.accueil');
        }

        if ($request->user()->hasRole('caisse')) {
            return redirect()->route('personnel.caisse.accueil');
        }

        return Inertia::render('Personnel/Accueil', [
            'roles' => $request->user()->getRoleNames()->values(),
        ]);
    }
}
