<?php

namespace App\Http\Controllers\Laboratoire;

use App\Http\Controllers\Teleconsultation\ConsultationJsonController;
use App\Services\Laboratoire\LaboratoireRefuse;
use App\Services\Laboratoire\PrescriptionExamens;
use App\Services\Teleconsultation\AccesRefuse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** PRE-04 : bons d'examen de CE rendez-vous, côté médecin (composant BonExamen de la salle et de la page « Note »). */
class BonExamenMedecinController extends ConsultationJsonController
{
    public function __construct(private readonly PrescriptionExamens $prescriptions) {}

    public function lister(Request $request, string $rendezVous): Response
    {
        return $this->repondre(fn () => $this->prescriptions->pourLeRendezVous($request->user(), $this->rendezVous($rendezVous)));
    }

    public function prescrire(Request $request, string $rendezVous): Response
    {
        return $this->repondre(function () use ($request, $rendezVous) {
            $rdv = $this->rendezVous($rendezVous);
            try {
                $bon = $this->prescriptions->prescrire($request->user(), $rdv, $request->only(['cle', 'examens', 'renseignements', 'date_souhaitee', 'laboratoire_id']));
            } catch (LaboratoireRefuse $refus) {
                throw new AccesRefuse($refus->getMessage());
            }

            return ['message' => 'Bon '.$bon->numero.' prescrit. Le patient est prévenu par SMS.', 'bon' => PrescriptionExamens::presenter($bon)]
                + $this->prescriptions->pourLeRendezVous($request->user(), $rdv);
        }, 201);
    }
}
