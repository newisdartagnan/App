<?php

namespace App\Services\Paiement;

use App\Models\Paiement;
use App\Services\Paiement\Fournisseur\InitiationPaiement;

/** Résultat de PaiementService::initier() : la tentative, et la réponse du prestataire s'il vient d'être appelé. */
final readonly class TentativePaiement
{
    public function __construct(
        public Paiement $paiement,
        public ?InitiationPaiement $initiation = null,
    ) {}
}
