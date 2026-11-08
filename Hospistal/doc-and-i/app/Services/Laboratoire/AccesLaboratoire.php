<?php

namespace App\Services\Laboratoire;

use App\Models\BonExamen;
use App\Models\Partenaire;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Qui agit au nom d'un laboratoire partenaire (PRE-05, PRE-06) : un compte
 * du personnel de rôle « laboratoire », actif, rattaché à un partenaire de
 * type laboratoire actif (comptes_laboratoire). Il ne voit que les bons
 * PAYÉS transmis à SON laboratoire, et seulement ce qu'il faut pour faire
 * l'examen (identité, examens, renseignements cliniques), jamais le reste
 * du dossier.
 */
final class AccesLaboratoire
{
    public function laboratoireDe(User $user): ?Partenaire
    {
        if (! $user->hasRole('laboratoire') || $user->estDesactive()) {
            return null;
        }

        $partenaireId = DB::table('comptes_laboratoire')->where('user_id', $user->id)->value('partenaire_id');

        return $partenaireId === null ? null
            : Partenaire::whereKey($partenaireId)->where('type', 'laboratoire')->where('actif', true)->first();
    }

    /** Le bon est-il pour le laboratoire de $user, et payé (RG-01 : rien avant paiement) ? */
    public function peutTraiter(User $user, BonExamen $bon): bool
    {
        $laboratoire = $this->laboratoireDe($user);

        return $laboratoire !== null && $bon->laboratoire_id === $laboratoire->id && $bon->paye_le !== null;
    }
}
