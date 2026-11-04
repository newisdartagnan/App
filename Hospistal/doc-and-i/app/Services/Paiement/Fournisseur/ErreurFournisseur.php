<?php

namespace App\Services\Paiement\Fournisseur;

use RuntimeException;

/** Le prestataire est injoignable ou refuse la demande. Message lisible par le patient. */
final class ErreurFournisseur extends RuntimeException {}
