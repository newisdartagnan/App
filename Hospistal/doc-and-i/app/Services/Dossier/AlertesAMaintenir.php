<?php

namespace App\Services\Dossier;

use RuntimeException;

/**
 * PRE-02 : l'ordonnance déclenche des alertes que le médecin n'a pas encore
 * maintenues. Rien n'est émis ; l'écran les montre, chacune avec sa case
 * « je maintiens cette prescription ».
 */
final class AlertesAMaintenir extends RuntimeException
{
    /** @param  list<array{cle: string, type: string, rang: int, message: string, maintenue: bool}>  $alertes */
    public function __construct(public readonly array $alertes)
    {
        parent::__construct('Alertes à la prescription : confirmez chacune pour émettre l\'ordonnance.');
    }
}
