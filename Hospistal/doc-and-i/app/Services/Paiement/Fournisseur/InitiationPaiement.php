<?php

namespace App\Services\Paiement\Fournisseur;

/** Réponse du prestataire à une demande de débit : la demande est acceptée, pas payée. */
final readonly class InitiationPaiement
{
    public function __construct(
        public string $referenceFournisseur,
        /** Carte (3-D Secure) : page du prestataire où le patient s'authentifie. */
        public ?string $urlRedirection = null,
        /** Mobile Money : ce que le patient doit faire (« confirmez sur votre téléphone »). */
        public ?string $instructions = null,
    ) {}
}
