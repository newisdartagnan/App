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

        if ($request->user()->hasRole('admin_fonctionnel')) {
            return redirect()->route('personnel.admin.accueil');
        }

        if ($request->user()->hasRole('caisse')) {
            return redirect()->route('personnel.caisse.accueil');
        }

        // PRE-05, PRE-06 : le compte d'un laboratoire arrive directement sur ses bons.
        if ($request->user()->hasRole('laboratoire')) {
            return redirect()->route('personnel.laboratoire.accueil');
        }

        // PRE-08, RG-10 : le coordinateur arrive sur les escalades.
        if ($request->user()->hasRole('coordinateur')) {
            return redirect()->route('personnel.coordination.accueil');
        }

        return Inertia::render('Personnel/Accueil', [
            'roles' => $request->user()->getRoleNames()->values(),
        ]);
    }
}
