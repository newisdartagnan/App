<?php

namespace App\Http\Middleware;

use App\Services\Laboratoire\JetonsLaboratoire;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * API des laboratoires (PRE-06) : « Authorization: Bearer <jeton> ». Jeton
 * inconnu, révoqué, ou laboratoire désactivé : 401, même réponse dans tous
 * les cas. Débit borné par jeton (et par adresse avant authentification).
 * Réponses jamais mises en cache.
 */
class JetonLaboratoireRequis
{
    public const ATTRIBUT = 'jeton_laboratoire';

    public function __construct(private readonly JetonsLaboratoire $jetons) {}

    public function handle(Request $request, Closure $next): Response
    {
        $parMinute = (int) config('doc-and-i.laboratoire.api_requetes_par_minute');

        // Avant l'authentification : on borne les essais de jetons par adresse.
        $cleIp = 'api-laboratoire-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($cleIp, $parMinute)) {
            return self::json(['erreur' => 'trop_de_requetes', 'message' => 'Trop de requêtes : réessayez dans une minute.'], 429);
        }

        $jeton = $this->jetons->authentifier($request->bearerToken());
        if ($jeton === null) {
            RateLimiter::hit($cleIp, 60);

            return self::json(['erreur' => 'jeton_invalide', 'message' => 'Jeton absent, inconnu ou révoqué.'], 401)
                ->header('WWW-Authenticate', 'Bearer');
        }

        $cleJeton = 'api-laboratoire-jeton:'.$jeton->id;
        if (RateLimiter::tooManyAttempts($cleJeton, $parMinute)) {
            return self::json(['erreur' => 'trop_de_requetes', 'message' => 'Trop de requêtes : réessayez dans une minute.'], 429);
        }
        RateLimiter::hit($cleJeton, 60);

        $request->attributes->set(self::ATTRIBUT, $jeton);
        $reponse = $next($request);
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }

    public static function json(array $donnees, int $statut): JsonResponse
    {
        return (new JsonResponse($donnees, $statut))->header('Cache-Control', 'no-store');
    }
}
