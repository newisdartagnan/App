<?php

namespace App\Services\Teleconsultation;

use App\Models\User;

/**
 * Serveurs de mise en relation pour WebRTC (TLC-01, TLC-02) : STUN pour
 * trouver l'adresse publique, TURN pour relayer quand deux réseaux mobiles
 * ne se joignent pas directement (fréquent derrière le NAT des opérateurs).
 *
 * Identifiants TURN éphémères (méthode « REST API » de coturn,
 * use-auth-secret) : utilisateur = « expiration:identifiant », mot de passe
 * = HMAC-SHA1 du secret partagé. Rien de permanent n'est donné au
 * navigateur ; le secret reste sur le serveur.
 */
final class ServeursIce
{
    /** @return list<array{urls: list<string>, username?: string, credential?: string}> */
    public function pour(User $user): array
    {
        $config = config('doc-and-i.teleconsultation');
        $serveurs = [];

        $stun = array_values(array_filter(array_map('trim', explode(',', (string) $config['stun']))));
        if ($stun !== []) {
            $serveurs[] = ['urls' => $stun];
        }

        $turn = array_values(array_filter(array_map('trim', explode(',', (string) $config['turn']))));
        $secret = (string) $config['turn_secret'];
        if ($turn !== [] && strlen($secret) >= 32) {
            $utilisateur = (time() + (int) $config['turn_validite_secondes']).':'.$user->id;
            $serveurs[] = [
                'urls' => $turn,
                'username' => $utilisateur,
                'credential' => base64_encode(hash_hmac('sha1', $utilisateur, $secret, true)),
            ];
        }

        return $serveurs;
    }
}
