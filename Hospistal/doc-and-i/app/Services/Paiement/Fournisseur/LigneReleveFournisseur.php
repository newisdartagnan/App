<?php

namespace App\Services\Paiement\Fournisseur;

final readonly class LigneReleveFournisseur
{
    public function __construct(
        /** RappelFournisseur::PAIEMENT ou ::REMBOURSEMENT. */
        public string $type,
        public string $referenceFournisseur,
        public ?string $referenceMarchand,
        public string $montant,
        public string $devise,
        /** « reussi » ou « echoue ». */
        public string $statut,
    ) {}
}
