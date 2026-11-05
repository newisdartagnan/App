<?php

/*
| Caisse (PAY-01, PAY-07, PAY-09) : garde-fous réglables. La commission de la
| plateforme reste dans config/doc-and-i.php (décision du client).
*/

return [

    // PAY-01 : francs congolais pour un dollar. Hors de ces bornes, le taux
    // est refusé (une faute de frappe ferait payer 1 CDF ou 1 000 000 CDF).
    'taux' => [
        'minimum' => 500,
        'maximum' => 10000,
        // Écart avec le dernier taux connu au-delà duquel un SECOND agent confirme.
        'ecart_confirmation_pourcent' => 10,
    ],

    // PAY-07 : relances après une erreur technique du prestataire (la
    // demande est peut-être partie : même clé, idempotent chez lui).
    'remboursements' => [
        'tentatives_max' => 12,
        'intervalle_minutes' => 5,
    ],

];
