<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Teleconsultation\ConsultationJsonController;
use App\Models\Ordonnance;
use App\Services\Dossier\AccesDossier;
use App\Services\Dossier\AlertesAMaintenir;
use App\Services\Dossier\DocumentOrdonnance;
use App\Services\Dossier\OrdonnanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** PRE-01 à PRE-03, côté médecin : ordonnances de CE rendez-vous, émission, PDF des siennes. */
class OrdonnanceMedecinController extends ConsultationJsonController
{
    public function __construct(private readonly OrdonnanceService $ordonnances) {}

    public function lister(Request $request, string $rendezVous): Response
    {
        return $this->repondre(fn () => $this->ordonnances->duRendezVous($request->user(), $this->rendezVous($rendezVous)));
    }

    public function emettre(Request $request, string $rendezVous): Response
    {
        return $this->repondre(function () use ($request, $rendezVous) {
            $rdv = $this->rendezVous($rendezVous);
            try {
                $ordonnance = $this->ordonnances->emettre($request->user(), $rdv, $request->only(['cle', 'consignes', 'lignes', 'maintiens']));
            } catch (AlertesAMaintenir $alertes) {
                // PRE-02 : alerte bloquante ; rien n'est émis tant que chaque alerte n'est pas maintenue.
                return new JsonResponse(['message' => $alertes->getMessage(), 'alertes' => $alertes->alertes], 422);
            }

            return ['message' => 'Ordonnance '.$ordonnance->numero.' émise. Le patient est prévenu par SMS.', 'ordonnance' => OrdonnanceService::presenter($ordonnance)]
                + $this->ordonnances->duRendezVous($request->user(), $rdv);
        }, 201);
    }

    /** Le PDF d'une de SES ordonnances (celle d'un confrère répond 404). */
    public function pdf(Request $request, string $ordonnance, AccesDossier $acces, DocumentOrdonnance $document): Response
    {
        $medecin = $acces->medecinDe($request->user()) ?? abort(404);
        $trouvee = Ordonnance::where('medecin_id', $medecin->id)->findOrFail($ordonnance);

        return $document->pdf($trouvee);
    }
}
