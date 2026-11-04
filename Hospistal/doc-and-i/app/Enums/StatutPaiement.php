<?php

namespace App\Enums;

/**
 * Cycle de vie d'un paiement. Seul le rappel signé de l'opérateur fait
 * passer un paiement à « réussi » (PAY-04, RG-01), jamais le navigateur.
 */
enum StatutPaiement: string
{
    /** Créé chez nous, pas encore accepté par l'opérateur. */
    case Initie = 'initie';
    /** Demande acceptée par l'opérateur : le patient confirme sur son téléphone (code PIN) ou sur la page 3-D Secure. */
    case EnAttente = 'en_attente';
    /** Rappel signé reçu : argent encaissé. */
    case Reussi = 'reussi';
    case Echoue = 'echoue';
    /** Sans réponse de l'opérateur dans le délai : la tentative est close chez nous. */
    case Expire = 'expire';

    /** Statuts d'une tentative en cours (une seule par rendez-vous). */
    public static function enCours(): array
    {
        return [self::Initie->value, self::EnAttente->value];
    }

    public function estEnCours(): bool
    {
        return in_array($this->value, self::enCours(), true);
    }

    /** @return list<self> */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Initie => [self::EnAttente, self::Reussi, self::Echoue, self::Expire],
            self::EnAttente => [self::Reussi, self::Echoue, self::Expire],
            // L'opérateur fait foi : un débit confirmé après notre délai reste
            // un débit, il est encaissé puis traité (RG-03).
            self::Expire => [self::Reussi],
            self::Reussi, self::Echoue => [],
        };
    }

    public function peutPasserA(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Initie => 'En préparation',
            self::EnAttente => 'En attente de votre confirmation',
            self::Reussi => 'Payé',
            self::Echoue => 'Échoué',
            self::Expire => 'Expiré',
        };
    }
}
