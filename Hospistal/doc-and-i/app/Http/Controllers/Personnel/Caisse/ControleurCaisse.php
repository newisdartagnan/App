<?php

namespace App\Http\Controllers\Personnel\Caisse;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Paiement\PaiementRefuse;
use App\Services\RendezVous\Affichage;
use Closure;
use DateTimeInterface;
use Illuminate\Http\RedirectResponse;

/**
 * Base des écrans de la caisse. Les routes exigent la session complète du
 * personnel ET le rôle caisse ; les règles (quatre yeux, plafonds, états)
 * sont dans les services, un refus revient en message sur le formulaire.
 */
abstract class ControleurCaisse extends Controller
{
    /**
     * Exécute l'action ; un refus métier revient sur le formulaire, champ
     * $champ. Si l'action rend une chaîne, c'est le message de succès.
     */
    protected function action(Closure $action, string $champ, string $succes, ?string $route = null): RedirectResponse
    {
        try {
            $resultat = $action();
        } catch (PaiementRefuse $e) {
            return back()->withErrors([$champ => $e->getMessage()]);
        }

        return ($route ? redirect()->route($route) : back())->with('succes', is_string($resultat) ? $resultat : $succes);
    }

    protected static function nom(?User $user): ?string
    {
        return $user ? trim($user->prenom.' '.mb_strtoupper((string) $user->nom)) : null;
    }

    protected static function quand(?DateTimeInterface $instant): ?string
    {
        return $instant ? Affichage::dateHeure($instant) : null;
    }
}
