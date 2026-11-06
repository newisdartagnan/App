<?php

namespace App\Enums;

enum MotifRemboursement: string
{
    /** RG-04 : taux selon le délai avant le rendez-vous. */
    case AnnulationPatient = 'annulation_patient';
    /** RG-05 : remboursement total. */
    case AnnulationMedecin = 'annulation_medecin';
    /** RG-06 : médecin absent à la consultation, remboursement intégral. */
    case AbsenceMedecin = 'absence_medecin';
    /** RG-03 : paiement confirmé après la libération du créneau. */
    case PaiementTardif = 'paiement_tardif';
    /** Deux débits pour un même rendez-vous : le second est rendu. */
    case PaiementEnDouble = 'paiement_en_double';
    /** Décision de la caisse, motivée, dans la limite du montant payé. */
    case GesteCommercial = 'geste_commercial';

    /** Remboursements de règle, exécutés sans validation humaine. */
    public function estAutomatique(): bool
    {
        return in_array($this, [self::PaiementTardif, self::PaiementEnDouble, self::AbsenceMedecin], true);
    }

    public function libelle(): string
    {
        return match ($this) {
            self::AnnulationPatient => 'Annulation par le patient',
            self::AnnulationMedecin => 'Annulation par le médecin',
            self::AbsenceMedecin => 'Médecin absent à la consultation',
            self::PaiementTardif => 'Paiement reçu après la libération du créneau',
            self::PaiementEnDouble => 'Paiement en double',
            self::GesteCommercial => 'Geste commercial',
        };
    }
}
