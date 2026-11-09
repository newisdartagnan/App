<?php

namespace App\Enums;

/**
 * Cycle d'un bon d'examen (PRE-04 à PRE-07, RG-01, RG-10). Toute transition
 * passe par peutPasserA(), sous verrou.
 */
enum StatutBonExamen: string
{
    /** Prescrit par le médecin, en attente de paiement : rien n'est transmis. */
    case Prescrit = 'prescrit';
    /** Payé (rappel signé) : transmis au laboratoire choisi, code de retrait remis au patient. */
    case Paye = 'paye';
    /** Le laboratoire a reçu le patient (code de retrait présenté). */
    case EnCours = 'en_cours';
    /** Résultats déposés, en attente de la validation du médecin ; le patient ne les voit pas. */
    case ResultatsDeposes = 'resultats_deposes';
    /** Validés par le médecin : le patient les voit. */
    case Valide = 'valide';
    case Annule = 'annule';

    /** @return list<self> */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Prescrit => [self::Paye, self::Annule],
            self::Paye => [self::EnCours, self::Annule],
            self::EnCours => [self::ResultatsDeposes],
            self::ResultatsDeposes => [self::Valide],
            self::Valide, self::Annule => [],
        };
    }

    public function peutPasserA(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Prescrit => 'À payer',
            self::Paye => 'Payé, à faire au laboratoire',
            self::EnCours => 'Au laboratoire',
            self::ResultatsDeposes => 'Résultats en cours de validation',
            self::Valide => 'Résultats disponibles',
            self::Annule => 'Annulé',
        };
    }
}
