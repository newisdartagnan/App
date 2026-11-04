<?php

namespace App\Services\RendezVous;

use RuntimeException;

/**
 * Refus métier du parcours de rendez-vous (créneau pris, délai dépassé,
 * statut qui ne le permet pas…). Le message s'adresse au patient : phrase
 * courte, sans jargon. Les contrôleurs le montrent tel quel.
 */
class ReservationRefusee extends RuntimeException {}
