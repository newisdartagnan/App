<?php

namespace App\Services\Dossier;

use App\Enums\StatutRendezVous;
use App\Models\RendezVous;
use App\Models\User;
use App\Services\Teleconsultation\AccesConsultation;
use App\Services\Teleconsultation\AccesRefuse;

/**
 * Qui rédige la note et les ordonnances d'un rendez-vous (DOS-02, PRE-01) :
 * le médecin de CE rendez-vous (AccesDossier::medecinDuRendezVous), et
 * seulement
 * - pendant la consultation : rendez-vous confirmé, dans la fenêtre de la salle ;
 * - ou après : rendez-vous terminé (une note non signée se signe après coup).
 * Annulé, expiré, absences, en attente de paiement, ou confirmé hors de la
 * fenêtre : rien.
 */
final class RedactionConsultation
{
    public function __construct(private readonly AccesDossier $acces, private readonly AccesConsultation $salle) {}

    /** Lecture de la note et des ordonnances du rendez-vous, par son médecin. @throws AccesRefuse */
    public function exigerLecteur(User $user, RendezVous $rdv): void
    {
        if (! $this->acces->medecinDuRendezVous($user, $rdv)) {
            throw new AccesRefuse('Ce rendez-vous ne vous est pas ouvert.');
        }
    }

    /** @throws AccesRefuse */
    public function exigerRedacteur(User $user, RendezVous $rdv): void
    {
        $this->exigerLecteur($user, $rdv);

        if (! $this->peutRediger($rdv)) {
            throw new AccesRefuse('La note et l\'ordonnance se rédigent pendant la consultation, ou après quand elle est terminée.');
        }
    }

    public function peutRediger(RendezVous $rdv): bool
    {
        return $rdv->statut === StatutRendezVous::Termine
            || ($rdv->statut === StatutRendezVous::Confirme && $this->salle->dansLaFenetre($rdv));
    }
}
