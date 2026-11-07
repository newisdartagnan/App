<?php

namespace App\Http\Controllers\Teleconsultation;

use App\Services\Teleconsultation\FinConsultation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** RG-06, RG-07 : fin de consultation et absences, selon le rôle dans CE rendez-vous. */
class FinConsultationController extends ConsultationJsonController
{
    public function __construct(private readonly FinConsultation $fin) {}

    public function etat(Request $request, string $rendezVous): Response
    {
        return $this->repondre(fn () => $this->fin->etat($request->user(), $this->rendezVous($rendezVous)));
    }

    public function terminer(Request $request, string $rendezVous): Response
    {
        return $this->repondre(function () use ($request, $rendezVous) {
            $rdv = $this->rendezVous($rendezVous);
            $this->fin->terminer($request->user(), $rdv);

            return $this->fin->etat($request->user(), $rdv->fresh()) + ['message' => 'Consultation terminée.'];
        });
    }

    public function interrompre(Request $request, string $rendezVous): Response
    {
        return $this->repondre(function () use ($request, $rendezVous) {
            $rdv = $this->rendezVous($rendezVous);
            $this->fin->interrompre($request->user(), $rdv);

            return $this->fin->etat($request->user(), $rdv->fresh()) + ['message' => 'Consultation interrompue : le patient est remboursé et pourra reprendre un créneau.'];
        });
    }

    public function patientAbsent(Request $request, string $rendezVous): Response
    {
        return $this->repondre(function () use ($request, $rendezVous) {
            $rdv = $this->rendezVous($rendezVous);
            $this->fin->constaterPatientAbsent($request->user(), $rdv);

            return $this->fin->etat($request->user(), $rdv->fresh()) + ['message' => 'Absence du patient constatée.'];
        });
    }

    public function medecinAbsent(Request $request, string $rendezVous): Response
    {
        return $this->repondre(function () use ($request, $rendezVous) {
            $rdv = $this->rendezVous($rendezVous);
            $this->fin->constaterMedecinAbsent($request->user(), $rdv);

            return $this->fin->etat($request->user(), $rdv->fresh()) + ['message' => 'Absence du médecin constatée : vous serez remboursé intégralement.'];
        });
    }
}
