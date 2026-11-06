<?php

namespace App\Services\Administration;

use App\Models\EntreeJournalAdministration;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Journal des actions d'administration : création ou désactivation d'un
 * compte, vérification à l'Ordre, changement de tarif, partenaire… Chaque
 * action de l'espace administrateur y écrit une ligne, dans la même
 * transaction que l'action.
 *
 * Les détails sont des valeurs simples (texte court, nombre, booléen) :
 * jamais de donnée de santé, de mot de passe, de lien d'activation ni de
 * secret. Les clés interdites sont refusées.
 */
final class JournalAdministration
{
    private const CLES_INTERDITES = ['password', 'mot_de_passe', 'lien', 'jeton', 'secret', 'totp', 'code', 'motif_consultation', 'symptomes'];

    /** @param  array<string, scalar|null>  $details */
    public function consigner(User $auteur, string $action, ?Model $cible = null, array $details = []): EntreeJournalAdministration
    {
        foreach ($details as $cle => $valeur) {
            if (in_array(mb_strtolower((string) $cle), self::CLES_INTERDITES, true)) {
                throw new InvalidArgumentException("Le journal d'administration ne garde pas « {$cle} ».");
            }
            if (! is_scalar($valeur) && $valeur !== null) {
                throw new InvalidArgumentException("Détail « {$cle} » : valeur simple attendue.");
            }
            if (is_string($valeur)) {
                $details[$cle] = mb_substr($valeur, 0, 255);
            }
        }

        $entree = new EntreeJournalAdministration;
        $entree->forceFill([
            'auteur_id' => $auteur->id,
            'action' => mb_substr($action, 0, 60),
            'cible_type' => $cible ? class_basename($cible) : null,
            'cible_id' => $cible?->getKey(),
            'details' => $details,
            'ip' => request()?->ip(),
        ])->save();

        return $entree;
    }
}
