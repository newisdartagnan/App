<?php

namespace App\Http\Controllers\Teleconsultation;

use App\Http\Controllers\Controller;
use App\Models\RendezVous;
use App\Services\Teleconsultation\AccesRefuse;
use Closure;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base des points d'accès JSON de la consultation (messagerie, dossier,
 * fin), appelés par les composants de la salle, côté patient comme côté
 * médecin. Le rôle et la relation de soin sont revérifiés par les services
 * à chaque requête ; un refus répond 403 avec un message lisible.
 */
abstract class ConsultationJsonController extends Controller
{
    protected function rendezVous(string $id): RendezVous
    {
        return RendezVous::findOrFail($id);
    }

    /** @param  Closure(): (Response|array)  $action */
    protected function repondre(Closure $action, int $statut = 200): Response
    {
        try {
            $resultat = $action();
        } catch (AccesRefuse $refus) {
            return new JsonResponse(['message' => $refus->getMessage()], 403);
        }

        return $resultat instanceof Response ? $resultat : new JsonResponse($resultat, $statut);
    }
}
