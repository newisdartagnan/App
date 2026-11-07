<?php

namespace App\Http\Controllers\Teleconsultation;

use App\Http\Controllers\Controller;
use App\Models\Consultation;
use App\Models\RendezVous;
use App\Services\Teleconsultation\AccesRefuse;
use App\Services\Teleconsultation\Presence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Battement de présence (revue de sécurité ; RG-06, RG-07) : envoyé par le
 * navigateur seulement tant que sa connexion WebRTC est établie. Commun au
 * patient et au médecin ; le rôle et la fenêtre sont revérifiés par
 * Presence::battement. La salle passe « en cours » quand les deux battent
 * ensemble.
 */
class PresenceController extends Controller
{
    public function __invoke(Request $request, RendezVous $rendezVous, Presence $presence): JsonResponse
    {
        try {
            $consultation = Consultation::where('rendez_vous_id', $rendezVous->id)->first()
                ?? throw new AccesRefuse('La salle de consultation n\'est pas encore ouverte.');

            $reponse = response()->json($presence->battement($consultation, $request->user()));
        } catch (AccesRefuse $refus) {
            $reponse = response()->json(['message' => $refus->getMessage()], 403);
        }

        return SignauxController::sansCache($reponse);
    }
}
