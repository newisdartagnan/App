<?php

namespace App\Enums;

/**
 * Cycle de vie d'un remboursement (PAY-07). Une demande humaine attend la
 * validation d'un agent de caisse (autre que le demandeur) ; un
 * remboursement de règle (paiement tardif, en double) naît validé.
 */
enum StatutRemboursement: string
{
    case AValider = 'a_valider';
    case Valide = 'valide';
    /** Envoyé à l'opérateur, en attente de son rappel signé. */
    case EnCours = 'en_cours';
    case Reussi = 'reussi';
    case Echoue = 'echoue';
    case Refuse = 'refuse';

    /** Statuts qui engagent le montant sur le paiement (paiements.montant_rembourse). */
    public static function engages(): array
    {
        return [self::AValider->value, self::Valide->value, self::EnCours->value, self::Reussi->value];
    }

    public function engageLeMontant(): bool
    {
        return in_array($this->value, self::engages(), true);
    }

    /** @return list<self> */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::AValider => [self::Valide, self::Refuse],
            self::Valide => [self::EnCours, self::Echoue],
            self::EnCours => [self::Reussi, self::Echoue],
            // Rappel signé réussi reçu après un échec de notre côté : l'argent
            // est parti, le remboursement est constaté (revue de sécurité).
            self::Echoue => [self::Reussi],
            self::Reussi, self::Refuse => [],
        };
    }

    public function peutPasserA(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    public function libelle(): string
    {
        return match ($this) {
            self::AValider => 'À valider par la caisse',
            self::Valide => 'Validé',
            self::EnCours => 'En cours',
            self::Reussi => 'Remboursé',
            self::Echoue => 'Échoué',
            self::Refuse => 'Refusé',
        };
    }
}
