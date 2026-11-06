<?php

namespace App\Services\Teleconsultation;

use RuntimeException;

/** Salle de consultation fermée ou inaccessible : message court, lisible par le patient ou le médecin. */
final class AccesRefuse extends RuntimeException {}
