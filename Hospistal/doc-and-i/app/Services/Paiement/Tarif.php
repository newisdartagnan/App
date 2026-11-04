<?php

namespace App\Services\Paiement;

use App\Models\TauxChange;

/** Ce que le patient paie, dans la devise choisie, et d'où vient ce montant. */
final readonly class Tarif
{
    public function __construct(
        public string $montant,
        public string $devise,
        public string $montantReference,
        public string $deviseReference,
        public ?TauxChange $taux,
    ) {}
}
