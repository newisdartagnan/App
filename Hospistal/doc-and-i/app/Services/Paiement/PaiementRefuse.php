<?php

namespace App\Services\Paiement;

use RuntimeException;

/** Refus métier du lot paiement : message court, lisible par le patient ou la caisse. */
final class PaiementRefuse extends RuntimeException {}
