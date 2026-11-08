<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Teleconsultation\ConsultationJsonController;
use App\Models\Ordonnance;
use App\Services\Dossier\AccesDossier;
use App\Services\Dossier\AlertesAMaintenir;
use App\Services\Dossier\DocumentOrdonnance;
use App\Services\Dossier\JournalAccesDossier;
use App\Services\Dossier\OrdonnanceService;
use App\Services\Dossier\RedactionConsultation;
use App\Services\Teleconsultation\AccesRefuse;
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

    /**
     * Le PDF d'une de SES ordonnances, tant que la relation de soin dure
     * (revue de sécurité) : rendez-vous de l'ordonnance encore confirmé ou
     * terminé. Celle d'un confrère, ou d'un rendez-vous passé depuis à
     * l'absence ou à l'annulation, répond 404. Chaque lecture est notée au
     * journal des accès du patient.
     */
    public function pdf(Request $request, string $ordonnance, AccesDossier $acces, RedactionConsultation $redaction, JournalAccesDossier $journal, DocumentOrdonnance $document): Response
    {
        $medecin = $acces->medecinDe($request->user()) ?? abort(404);
        $trouvee = Ordonnance::with('rendezVous')->where('medecin_id', $medecin->id)->findOrFail($ordonnance);
        try {
            $redaction->exigerLecteur($request->user(), $trouvee->rendezVous);
        } catch (AccesRefuse) {
            abort(404);
        }

        $journal->noter($request->user(), $trouvee->patient_id, 'ordonnance_pdf', $trouvee->id);

        return $document->pdf($trouvee);
    }
}
