<?php

return [

    /*
    | Connexion par téléphone : un code à usage unique envoyé par SMS.
    */
    'code_connexion' => [
        'chiffres' => 6,
        'validite_minutes' => 10,
        'essais_max' => 5,
        // Demandes de code autorisées par numéro et par adresse IP.
        'demandes_par_numero' => 3,
        'fenetre_numero_minutes' => 10,
        'demandes_par_ip' => 20,
        'fenetre_ip_minutes' => 60,
    ],

    'sms' => [
        // « log » : le message est écrit dans le journal (développement, tests).
        'driver' => env('SMS_DRIVER', 'log'),
        'expediteur' => 'DocAndI',
    ],

    /*
    | Conditions de téléconsultation et de traitement des données (CPT-04).
    | Changer la version oblige chaque patient à les accepter de nouveau.
    */
    'consentement' => [
        'version' => env('DOC_AND_I_CONSENTEMENT_VERSION', '2026-11'),
    ],

    'roles' => [
        'patient', 'medecin', 'coordinateur', 'laboratoire', 'caisse',
        'support', 'admin_fonctionnel', 'admin_technique', 'auditeur',
    ],

];
