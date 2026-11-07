<?php

namespace App\Services\Dossier;

use App\Enums\StatutRendezVous;
use App\Models\Medecin;
use App\Models\RendezVous;
use App\Models\User;

/**
 * Qui lit et écrit le dossier médical d'un patient (DOS-01 à DOS-04).
 *
 * - Le patient : tout son dossier, à vie. Il déclare ses antécédents,
 *   allergies, traitements et constantes ; il ne modifie ni note ni
 *   ordonnance, il en demande la rectification.
 * - Un médecin : seulement s'il a une relation de soin avec CE patient,
 *   c'est-à-dire un rendez-vous confirmé (à venir ou en cours) ou terminé
 *   avec lui. Un rendez-vous annulé, expiré ou en attente de paiement
 *   n'ouvre rien. L'accès « bris de glace » hors relation de soin est au
 *   lot V2 (DOS-05).
 * - Personne d'autre : ni caisse, ni administration, ni support.
 */
final class AccesDossier
{
    public const STATUTS_RELATION_DE_SOIN = [StatutRendezVous::Confirme, StatutRendezVous::Termine];

    public function estLePatient(User $user, User $patient): bool
    {
        return $user->is($patient) && $user->hasRole('patient');
    }

    public function medecinDe(User $user): ?Medecin
    {
        if (! $user->hasRole('medecin') || $user->estDesactive()) {
            return null;
        }

        return Medecin::where('user_id', $user->id)->first();
    }

    /** Le médecin $user suit-il ce patient (relation de soin) ? */
    public function medecinTraitant(User $user, User $patient): bool
    {
        $medecin = $this->medecinDe($user);

        return $medecin !== null && RendezVous::where('medecin_id', $medecin->id)
            ->where('patient_id', $patient->id)
            ->whereIn('statut', array_map(fn ($s) => $s->value, self::STATUTS_RELATION_DE_SOIN))
            ->exists();
    }

    public function peutLire(User $user, User $patient): bool
    {
        return $this->estLePatient($user, $patient) || $this->medecinTraitant($user, $patient);
    }

    /**
     * Écrire dans le dossier d'un rendez-vous (note, ordonnance) : le médecin
     * de CE rendez-vous, rendez-vous confirmé ou terminé.
     */
    public function medecinDuRendezVous(User $user, RendezVous $rdv): bool
    {
        $medecin = $this->medecinDe($user);

        return $medecin !== null && $rdv->medecin_id === $medecin->id
            && in_array($rdv->statut, self::STATUTS_RELATION_DE_SOIN, true);
    }
}
