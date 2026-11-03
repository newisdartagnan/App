<?php

return [

    /*
    | Connexion par téléphone : un code à usage unique envoyé par SMS.
    |
    | Les limites portent surtout sur le NUMÉRO : à Kinshasa, des milliers
    | d'abonnés mobiles sortent par la même adresse IP (NAT des opérateurs),
    | une limite serrée par IP bloquerait des patients légitimes.
    */
    'code_connexion' => [
        'chiffres' => 6,
        'validite_minutes' => 10,
        'essais_max' => 5,
        // Demandes de code par numéro : en rafale, puis sur 24 h.
        'demandes_par_numero' => 3,
        'fenetre_numero_minutes' => 10,
        'demandes_par_numero_par_jour' => 10,
        // Codes faux cumulés sur tous les codes d'un numéro : au-delà, le
        // numéro est bloqué 24 h (une nouvelle demande ne remet pas à zéro).
        'echecs_par_numero_par_jour' => 10,
        // Par adresse IP (IPv6 regroupée par /64) : large, à cause du NAT.
        'demandes_par_ip' => 100,
        'fenetre_ip_minutes' => 60,
        // Budget global de SMS de connexion par heure : protège le crédit
        // d'envoi contre une attaque répartie sur de nombreux numéros.
        'sms_par_heure' => 2000,
    ],

    'sms' => [
        // Pas de valeur par défaut : un oubli en production doit se voir.
        // « log » (développement, tests) est refusé en production.
        'driver' => env('SMS_DRIVER'),
        'expediteur' => 'DocAndI',
    ],

    /*
    | Conditions de téléconsultation et de traitement des données (CPT-04).
    | Changer la version oblige chaque patient à les accepter de nouveau.
    */
    'consentement' => [
        'version' => env('DOC_AND_I_CONSENTEMENT_VERSION', '2026-11'),
    ],

    /*
    | Mandataires de confiance (répartiteur de charge, proxy TLS), séparés par
    | des virgules. Sans eux, derrière un proxy, toutes les requêtes auraient
    | l'adresse du proxy et la limite par IP s'appliquerait à toute la
    | plateforme.
    */
    'proxys_de_confiance' => env('TRUSTED_PROXIES'),

    'roles' => [
        'patient', 'medecin', 'coordinateur', 'laboratoire', 'caisse',
        'support', 'admin_fonctionnel', 'admin_technique', 'auditeur',
    ],

];
