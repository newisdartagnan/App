<?php

namespace App\Services\Paiement\Fournisseur;

final readonly class InitiationRemboursement
{
    public function __construct(public string $referenceFournisseur) {}
}
