<?php

namespace App\Services\Paiement\Fournisseur;

use RuntimeException;

/** Signature absente, fausse ou périmée, ou corps illisible : le rappel est rejeté sans être stocké. */
final class RappelInvalide extends RuntimeException {}
