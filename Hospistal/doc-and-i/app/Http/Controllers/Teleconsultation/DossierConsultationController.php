<?php

namespace App\Http\Controllers\Teleconsultation;

use App\Services\Teleconsultation\DossierConsultation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** TLC-05 : dossier du patient pendant l'appel, au médecin de ce rendez-vous seulement. */
class DossierConsultationController extends ConsultationJsonController
{
    public function __construct(private readonly DossierConsultation $dossier) {}

    public function voir(Request $request, string $rendezVous): Response
    {
        $reponse = $this->repondre(fn () => $this->dossier->pour($request->user(), $this->rendezVous($rendezVous)));
        $reponse->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $reponse;
    }

    public function pieceJointe(Request $request, string $rendezVous, string $piece): Response
    {
        return $this->repondre(fn () => $this->dossier->pieceJointe($request->user(), $this->rendezVous($rendezVous), $piece));
    }
}
