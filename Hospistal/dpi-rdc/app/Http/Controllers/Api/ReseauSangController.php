<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Services\ReseauSangService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le point de rendez-vous du réseau des banques de sang.
 *
 * Une maison y dépose son bulletin et repart avec ceux des autres, en un
 * seul aller-retour : sur une liaison qui coupe, chaque appel économisé
 * compte. N'importe quelle installation de DPI-RDC peut tenir ce rôle — il
 * n'y a pas de logiciel supplémentaire à installer quelque part.
 *
 * L'authentification se fait par le jeton de l'établissement, celui-là même
 * qui sert déjà à la synchronisation. Une maison ne peut publier que sous
 * son propre code : sans cela, n'importe qui annoncerait n'importe quoi au
 * nom de l'hôpital d'à côté, et on enverrait une ambulance pour rien.
 */
class ReseauSangController extends Controller
{
    public function __construct(private readonly ReseauSangService $reseau) {}

    public function echanger(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'etablissement_code' => 'required|string|max:100',
            'bulletin' => 'nullable|array',
            'bulletin.etablissement_code' => 'nullable|string|max:100',
            'bulletin.nom' => 'nullable|string|max:255',
            'bulletin.ville' => 'nullable|string|max:255',
            'bulletin.province' => 'nullable|string|max:255',
            'bulletin.telephone' => 'nullable|string|max:100',
            'bulletin.stock' => 'nullable|array',
            'bulletin.donneurs' => 'nullable|array',
            'bulletin.publie_le' => 'nullable|string|max:64',
        ]);

        $maison = $this->authentifier($request, $donnees['etablissement_code']);

        if (! $maison) {
            return response()->json(['message' => 'Jeton de réseau inconnu ou ne correspondant pas à cet établissement.'], 401);
        }

        // Une maison retirée du réseau n'envoie pas de bulletin : elle
        // continue pourtant de recevoir ceux des autres.
        if ($bulletin = $donnees['bulletin'] ?? null) {
            // Le code du bulletin est celui du porteur du jeton, quoi qu'il
            // ait écrit dedans.
            $bulletin['etablissement_code'] = $maison->code;
            $this->reseau->enregistrer([$bulletin]);
        }

        return response()->json([
            'recu' => $bulletin !== null,
            'bulletins' => $this->reseau->bulletinsPourAutrui($maison->code),
            'heure_serveur' => now()->toIso8601String(),
        ]);
    }

    /**
     * Le porteur du jeton est-il bien la maison qu'il prétend être ?
     */
    private function authentifier(Request $request, string $code): ?Establishment
    {
        $jeton = (string) $request->bearerToken();

        if ($jeton === '') {
            return null;
        }

        $maison = Establishment::where('code', $code)->where('is_active', true)->first();

        if (! $maison || blank($maison->central_sync_token)) {
            return null;
        }

        // Comparaison à temps constant : un jeton ne se devine pas à la
        // vitesse des réponses.
        return hash_equals((string) $maison->central_sync_token, $jeton) ? $maison : null;
    }
}
