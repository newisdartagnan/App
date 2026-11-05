<?php

namespace App\Services\Paiement\Fournisseur;

/** Rappel serveur authentifié, traduit dans nos termes. */
final readonly class RappelFournisseur
{
    public const PAIEMENT = 'paiement';

    public const REMBOURSEMENT = 'remboursement';

    public function __construct(
        /** Identifiant unique de l'événement chez le prestataire : un rappel rejoué porte le même. */
        public string $identifiantEvenement,
        /** self::PAIEMENT ou self::REMBOURSEMENT. */
        public string $type,
        /** Notre clé d'idempotence, transmise à l'initiation. */
        public string $referenceMarchand,
        public string $referenceFournisseur,
        public bool $reussi,
        /** Montant confirmé par le prestataire, chaîne décimale (« 10.00 »). */
        public string $montant,
        public string $devise,
        public ?string $motifEchec,
        /**
         * Charge utile décodée, gardée dans evenements_paiement. Le pilote en
         * RETIRE ou masque le numéro du payeur et toute donnée personnelle
         * (Telephone::masquer) : la table n'est pas un registre de numéros.
         */
        public array $chargeUtile,
    ) {}
}
