<?php

namespace App\Enums;

/**
 * Salle de téléconsultation d'un rendez-vous confirmé. Toute transition
 * passe par peutPasserA(), sous verrou ; le rendez-vous suit (Termine,
 * AbsencePatient, AbsenceMedecin) dans la même transaction.
 */
enum StatutConsultation: string
{
    /** Salle ouverte, au moins un participant attend l'autre. */
    case EnAttente = 'en_attente';
    /** Les deux participants se sont retrouvés au moins une fois. */
    case EnCours = 'en_cours';
    /** Clôturée par le médecin. */
    case Terminee = 'terminee';
    /** RG-06 : patient absent 10 minutes après l'heure ; consultation perdue (RG-04). */
    case PatientAbsent = 'patient_absent';
    /** RG-06 : médecin absent ; remboursement intégral. */
    case MedecinAbsent = 'medecin_absent';

    /** @return list<self> */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::EnAttente => [self::EnCours, self::PatientAbsent, self::MedecinAbsent],
            // Une coupure ne ferme pas la consultation (RG-07, TLC-04) : seul le médecin la termine.
            self::EnCours => [self::Terminee],
            self::Terminee, self::PatientAbsent, self::MedecinAbsent => [],
        };
    }

    public function peutPasserA(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    public function estOuverte(): bool
    {
        return in_array($this, [self::EnAttente, self::EnCours], true);
    }

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::EnCours => 'En cours',
            self::Terminee => 'Terminée',
            self::PatientAbsent => 'Patient absent',
            self::MedecinAbsent => 'Médecin absent',
        };
    }
}
