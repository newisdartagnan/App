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

    /*
    | Rendez-vous (RDV-04 à RDV-07, RG-03 à RG-06).
    */
    'rendez_vous' => [
        // RDV-05 / RG-03 : créneau bloqué le temps du paiement.
        'delai_paiement_minutes' => 15,
        // Créneaux proposés sur cette période.
        'horizon_jours' => 14,
        // On ne réserve pas un créneau qui commence dans moins de N minutes.
        'delai_minimum_minutes' => 30,
        // RG-06 : absence constatée N minutes après l'heure.
        'retard_absence_minutes' => 10,
        // RDV-06 : rappels avant la consultation, en minutes.
        'rappels_minutes' => [1440, 60],
        // RG-04 : annulation par le patient, seuils en heures avant le début.
        'annulation' => [
            'remboursement_total_heures' => 24,
            'remboursement_partiel_heures' => 2,
            'taux_partiel' => 50,
        ],
        // RG-04 : à moins de 2 h, un seul report possible.
        'reports_max_tardifs' => 1,
        // Reports au total pour un même rendez-vous (tardifs compris) : borne
        // l'agenda du médecin et les SMS de rappel (revue de sécurité).
        'reports_max' => 3,
    ],

    /*
    | Pièces jointes des demandes (RDV-01) : quota par patient sur 24 heures
    | glissantes, en plus des 5 fichiers de 5 Mo par demande. Une demande sans
    | fichier n'est jamais bloquée par ce quota.
    */
    'pieces_jointes' => [
        'demandes_par_jour' => 5,
        'octets_par_jour' => 50 * 1024 * 1024,
    ],

    /*
    | Urgences (RDV-02, RG-08) : affichées quand le tri trouve un signe d'alerte.
    | Le client fournit l'établissement et le numéro ; tant qu'ils manquent,
    | le message renvoie vers l'hôpital le plus proche.
    */
    'urgences' => [
        'etablissement' => env('DOC_AND_I_URGENCES_ETABLISSEMENT'),
        'telephone' => env('DOC_AND_I_URGENCES_TELEPHONE'),
    ],

    'roles' => [
        'patient', 'medecin', 'coordinateur', 'laboratoire', 'caisse',
        'support', 'admin_fonctionnel', 'admin_technique', 'auditeur',
    ],

];
