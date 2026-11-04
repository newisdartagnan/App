<?php

namespace App\Enums;

/** PAY-02, PAY-03 : moyens acceptés, tous passant par l'agrégateur. */
enum MoyenPaiement: string
{
    case MPesa = 'mpesa';
    case OrangeMoney = 'orange_money';
    case AirtelMoney = 'airtel_money';
    case Afrimoney = 'afrimoney';
    case Carte = 'carte';

    public function estMobileMoney(): bool
    {
        return $this !== self::Carte;
    }

    public function libelle(): string
    {
        return match ($this) {
            self::MPesa => 'M-Pesa',
            self::OrangeMoney => 'Orange Money',
            self::AirtelMoney => 'Airtel Money',
            self::Afrimoney => 'Afrimoney',
            self::Carte => 'Carte Visa ou Mastercard',
        };
    }
}
