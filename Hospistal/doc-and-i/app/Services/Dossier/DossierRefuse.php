<?php

namespace App\Services\Dossier;

use RuntimeException;

/** Refus métier sur le dossier médical : message court, lisible par le patient ou le médecin. */
final class DossierRefuse extends RuntimeException {}
