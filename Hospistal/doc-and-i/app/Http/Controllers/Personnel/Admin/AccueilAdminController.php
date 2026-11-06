<?php

namespace App\Http\Controllers\Personnel\Admin;

use App\Http\Controllers\Controller;
use App\Services\Administration\PeriodeTableau;
use App\Services\Administration\TableauDeBordService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Tableau de bord de l'administration (ADM-02) : agrégats seulement, sur une période au choix. */
class AccueilAdminController extends Controller
{
    public function __invoke(Request $request, TableauDeBordService $tableau): Response
    {
        $periode = PeriodeTableau::depuis($request->query('periode'));

        return Inertia::render('Personnel/Admin/Accueil', [
            ...$tableau->calculer($periode),
            'periodes' => collect(PeriodeTableau::CHOIX)
                ->map(fn (string $libelle, string $cle) => ['cle' => $cle, 'libelle' => $libelle])
                ->values(),
        ]);
    }
}
