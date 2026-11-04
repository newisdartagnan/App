<?php

namespace App\Enums;

/**
 * Cycle de vie d'un rendez-vous. Toute transition passe par peutPasserA() :
 * une transition interdite est refusée côté serveur, quel que soit le
 * formulaire renvoyé (leçon de l'audit de dpi-rdc).
 */
enum StatutRendezVous: string
{
    /** Créneau bloqué pendant le paiement (RDV-05, 15 minutes). */
    case EnAttentePaiement = 'en_attente_paiement';
    /** Paiement confirmé par l'opérateur (RG-01). */
    case Confirme = 'confirme';
    /** Délai de paiement dépassé : le créneau est libéré. */
    case Expire = 'expire';
    case AnnuleParPatient = 'annule_patient';
    case AnnuleParMedecin = 'annule_medecin';
    case Termine = 'termine';
    case AbsencePatient = 'absent_patient';
    case AbsenceMedecin = 'absent_medecin';

    /** Statuts qui occupent le créneau du médecin. */
    public static function occupants(): array
    {
        return [self::EnAttentePaiement->value, self::Confirme->value];
    }

    public function occupeLeCreneau(): bool
    {
        return in_array($this->value, self::occupants(), true);
    }

    public function estFinal(): bool
    {
        return ! $this->occupeLeCreneau();
    }

    /** @return list<self> */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::EnAttentePaiement => [self::Confirme, self::Expire, self::AnnuleParPatient],
            self::Confirme => [self::AnnuleParPatient, self::AnnuleParMedecin, self::Termine, self::AbsencePatient, self::AbsenceMedecin],
            default => [],
        };
    }

    public function peutPasserA(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    public function libelle(): string
    {
        return match ($this) {
            self::EnAttentePaiement => 'En attente de paiement',
            self::Confirme => 'Confirmé',
            self::Expire => 'Expiré (non payé à temps)',
            self::AnnuleParPatient => 'Annulé par vous',
            self::AnnuleParMedecin => 'Annulé par le médecin',
            self::Termine => 'Terminé',
            self::AbsencePatient => 'Absence du patient',
            self::AbsenceMedecin => 'Absence du médecin',
        };
    }
}
