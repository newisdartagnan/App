<?php

namespace App\Http\Controllers\Dossier;

use App\Http\Controllers\Controller;
use App\Services\Dossier\DocumentOrdonnance;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * PRE-03 : page publique de vérification d'une ordonnance (QR code du
 * pharmacien). Hors connexion, débit limité, jamais en cache. Un code
 * inconnu reçoit la même réponse qu'il ait existé ou non.
 */
class VerificationOrdonnanceController extends Controller
{
    public function __invoke(Request $request, string $code, DocumentOrdonnance $document): Response
    {
        return Inertia::render('Dossier/VerificationOrdonnance', ['verification' => $document->verifier($code)])
            ->toResponse($request)
            ->withHeaders([
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
    }
}
