<?php

namespace App\Services\Laboratoire;

use RuntimeException;

/**
 * Action du circuit laboratoire refusée : message court, lisible par la
 * personne qui agit, et une raison stable pour l'API des laboratoires.
 */
final class LaboratoireRefuse extends RuntimeException
{
    public const BON_INTROUVABLE = 'bon_introuvable';

    public const CODE_RETRAIT = 'code_retrait_incorrect';

    public const TROP_D_ESSAIS = 'trop_d_essais';

    public const REFUSE = 'refuse';

    public function __construct(string $message, public readonly string $raison = self::REFUSE)
    {
        parent::__construct($message);
    }
}
