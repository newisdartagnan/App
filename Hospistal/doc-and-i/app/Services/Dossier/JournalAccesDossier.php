<?php

namespace App\Services\Dossier;

use App\Models\EntreeAccesDossier;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Journal des accès au dossier médical (revue de sécurité, cahier des
 * charges section 6). Une ligne à chaque lecture du dossier (ou d'une de
 * ses pièces) par quelqu'un d'autre que le patient lui-même ; le patient
 * voit qui a consulté son dossier. Aucune donnée de santé : la nature de
 * ce qui a été lu, l'identifiant de l'objet, l'heure et l'adresse.
 */
final class JournalAccesDossier
{
    public function noter(User $lecteur, string $patientId, string $objet, ?string $objetId = null): ?EntreeAccesDossier
    {
        if ($lecteur->id === $patientId) {
            return null; // Le patient qui lit son propre dossier n'est pas un accès à tracer.
        }

        $entree = new EntreeAccesDossier;
        $entree->forceFill([
            'lecteur_id' => $lecteur->id,
            'patient_id' => $patientId,
            'objet' => $objet,
            'objet_id' => $objetId,
            'ip' => request()?->ip(),
        ])->save();

        return $entree;
    }

    /**
     * Ce que voit le patient : qui, quoi, quand (sans l'adresse IP).
     *
     * @return Collection<int, array{qui: string, quoi: string, quand: CarbonImmutable}>
     */
    public function pourLePatient(User $patient, int $limite = 100): Collection
    {
        return EntreeAccesDossier::with(['lecteur:id,prenom,nom', 'lecteur.roles'])
            ->where('patient_id', $patient->id)
            ->orderByDesc('cree_le')->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn (EntreeAccesDossier $e) => [
                'qui' => trim(($e->lecteur?->hasRole('medecin') ? 'Dr ' : '').$e->lecteur?->prenom.' '.mb_strtoupper((string) $e->lecteur?->nom)),
                'quoi' => EntreeAccesDossier::OBJETS[$e->objet] ?? $e->objet,
                'quand' => $e->cree_le,
            ]);
    }
}
