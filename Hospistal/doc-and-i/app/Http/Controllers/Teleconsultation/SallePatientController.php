<?php

namespace App\Http\Controllers\Teleconsultation;

use App\Http\Controllers\Controller;
use App\Models\RendezVous;
use App\Services\RendezVous\Affichage;
use App\Services\Teleconsultation\AccesConsultation;
use App\Services\Teleconsultation\AccesRefuse;
use App\Services\Teleconsultation\SalleAppel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** TLC-01 : salle de consultation, côté patient. Seul le patient du rendez-vous y entre (404 sinon). */
class SallePatientController extends Controller
{
    public function __invoke(Request $request, RendezVous $rendezVous, AccesConsultation $acces, SalleAppel $salle): Response|RedirectResponse
    {
        abort_unless($rendezVous->patient_id === $request->user()->id, 404);

        try {
            [$consultation, $role] = $acces->entrer($request->user(), $rendezVous);
        } catch (AccesRefuse $refus) {
            return redirect()->route('rdv.voir', $rendezVous)->withErrors(['consultation' => $refus->getMessage()]);
        }

        $rdv = $rendezVous->fresh(['medecin.user', 'typeConsultation']);

        return Inertia::render('Teleconsultation/Salle', [
            'rendezVous' => Affichage::resume($rdv),
            'appel' => $salle->proprietes($consultation, $request->user(), $role, route('consultation.signaux', $rdv), route('consultation.presence', $rdv)),
        ]);
    }
}
