<?php

/*
| Comptes du personnel (CPT-05, CPT-06) : activation, connexion forte,
| session. Le patient a ses propres règles (config/doc-and-i.php).
*/

return [

    // CPT-05 : lien d'activation à usage unique envoyé par l'administrateur.
    'activation' => [
        'validite_heures' => 72,
        // Codes faux à l'enrôlement, par lien, avant de devoir patienter.
        'essais_code_max' => 10,
        'fenetre_essais_minutes' => 15,
    ],

    'mot_de_passe' => [
        'longueur_min' => 12,
        // bcrypt ignore tout ce qui dépasse 72 octets.
        'longueur_max' => 64,
    ],

    // CPT-06 : connexion par courriel, mot de passe, puis second facteur.
    'connexion' => [
        // Échecs consécutifs (mot de passe ou code) avant verrouillage du compte.
        'echecs_avant_verrouillage' => 5,
        'verrouillage_minutes' => 15,
        // Par adresse IP et par minute, pour chaque étape. Le personnel est
        // peu nombreux : la limite peut être plus serrée que pour le patient.
        'tentatives_par_ip_par_minute' => 20,
        // Délai pour saisir le second facteur après le mot de passe.
        'etape_code_minutes' => 5,
    ],

    'totp' => [
        // Nom affiché dans l'application d'authentification.
        'emetteur' => 'MediHome',
    ],

    'codes_recuperation' => [
        'nombre' => 8,
    ],

    // Session du personnel fermée après cette inactivité (plus courte que
    // celle du patient : un poste partagé dans un hôpital reste souvent ouvert).
    'session' => [
        'inactivite_minutes' => 30,
    ],

];
