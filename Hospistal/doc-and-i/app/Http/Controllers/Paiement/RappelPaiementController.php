<?php

namespace App\Http\Controllers\Paiement;

use App\Http\Controllers\Controller;
use App\Services\Paiement\Fournisseur\FournisseurPaiement;
use App\Services\Paiement\PaiementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PAY-04 : rappel serveur de l'agrégateur. Hors connexion et hors jeton
 * CSRF (bootstrap/app.php) : c'est la signature du rappel qui l'authentifie.
 * Un nom de prestataire autre que le pilote configuré répond 404.
 */
class RappelPaiementController extends Controller
{
    public function __invoke(Request $request, string $fournisseur, FournisseurPaiement $pilote, PaiementService $paiements): JsonResponse
    {
        abort_unless(hash_equals($pilote->nom(), $fournisseur), 404);

        $code = $paiements->recevoirRappel($request);

        return response()->json(['statut' => $code === 200 ? 'recu' : 'refuse'], $code);
    }
}
