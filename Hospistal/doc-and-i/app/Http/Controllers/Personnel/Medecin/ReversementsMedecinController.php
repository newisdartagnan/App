<?php

namespace App\Http\Controllers\Personnel\Medecin;

use App\Services\Caisse\ReversementService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** PAY-09 : le médecin lit SES reversements, jamais ceux d'un confrère. */
class ReversementsMedecinController extends ControleurMedecin
{
    public function __invoke(Request $request, ReversementService $reversements): Response
    {
        return Inertia::render('Personnel/Medecin/Reversements', [
            'reversements' => array_map(
                fn (array $r) => array_diff_key($r, ['preparePar' => true, 'medecin' => true]),
                $reversements->lister($this->medecin($request)),
            ),
        ]);
    }
}
