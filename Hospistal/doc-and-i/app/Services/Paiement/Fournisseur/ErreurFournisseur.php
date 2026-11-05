<?php

namespace App\Services\Paiement\Fournisseur;

use RuntimeException;
use Throwable;

/**
 * Le prestataire n'a pas accepté la demande. Message lisible par le patient
 * ou la caisse.
 *
 * Deux cas à ne jamais confondre (revue de sécurité) :
 * - refus() : le prestataire a répondu NON (numéro invalide, solde, carte
 *   refusée). Rien n'est parti : l'opération peut être close.
 * - injoignable() (et le constructeur par défaut) : délai dépassé, coupure,
 *   réponse illisible. La demande est peut-être partie : l'opération reste
 *   ouverte et se reprend avec la MÊME clé d'idempotence ; seul le rappel
 *   signé ou le délai d'expiration la clôt.
 */
final class ErreurFournisseur extends RuntimeException
{
    public function __construct(string $message = '', public readonly bool $refusDefinitif = false, ?Throwable $precedente = null)
    {
        parent::__construct($message, 0, $precedente);
    }

    public static function refus(string $message): self
    {
        return new self($message, true);
    }

    public static function injoignable(string $message, ?Throwable $precedente = null): self
    {
        return new self($message, false, $precedente);
    }
}
