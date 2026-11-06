<?php

namespace App\Services\Administration;

use Carbon\CarbonImmutable;

/**
 * Période du tableau de bord (ADM-02), en heure de Kinshasa, aujourd'hui
 * compris : [debut, fin[ où fin est demain à 00:00.
 */
final readonly class PeriodeTableau
{
    public const CHOIX = [
        '7j' => '7 derniers jours',
        '30j' => '30 derniers jours',
        'mois' => 'Mois en cours',
    ];

    public const PAR_DEFAUT = '30j';

    private function __construct(
        public string $cle,
        public CarbonImmutable $debut,
        public CarbonImmutable $fin,
    ) {}

    public static function depuis(?string $cle): self
    {
        $cle = array_key_exists((string) $cle, self::CHOIX) ? $cle : self::PAR_DEFAUT;
        $aujourdhui = CarbonImmutable::now('Africa/Kinshasa')->startOfDay();
        $fin = $aujourdhui->addDay();

        $debut = match ($cle) {
            '7j' => $fin->subDays(7),
            '30j' => $fin->subDays(30),
            'mois' => $aujourdhui->startOfMonth(),
        };

        return new self($cle, $debut, $fin);
    }

    public function libelle(): string
    {
        return self::CHOIX[$this->cle].' (du '.$this->debut->format('d/m/Y').' au '.$this->fin->subDay()->format('d/m/Y').')';
    }
}
