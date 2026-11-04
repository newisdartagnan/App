<?php

namespace App\Http\Controllers\RendezVous;

use App\Models\DemandeConsultation;
use App\Models\RendezVous;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Relation de soin côté serveur : un patient n'agit que sur ses propres
 * demandes et rendez-vous. Refus en 404 : on ne révèle pas qu'un
 * identifiant existe.
 */
trait ControleDuPatient
{
    protected function assurerProprietaire(Request $request, DemandeConsultation|RendezVous $objet): void
    {
        abort_unless($objet->patient_id === $request->user()->id, 404);
    }

    /**
     * Où envoyer le patient si sa demande ne peut plus mener à une
     * réservation (urgence, déjà réservée, close) ; null si elle est ouverte.
     */
    protected function redirectionSiNonReservable(DemandeConsultation $demande): ?RedirectResponse
    {
        return match ($demande->statut) {
            'ouverte' => null,
            'orientee_urgence' => redirect()->route('rdv.demande.urgence', $demande),
            'reservee' => ($rdv = $demande->rendezVous()->latest()->first())
                ? redirect()->route('rdv.voir', $rdv)
                : redirect()->route('rdv.index'),
            default => redirect()->route('rdv.index'),
        };
    }
}
