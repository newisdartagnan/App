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
        'expediteur' => 'MEDIHOME',
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
    | Dossier médical et ordonnance (DOS-01 à DOS-04, PRE-01 à PRE-03).
    */
    'dossier' => [
        // Clé du sceau HMAC des notes, avenants et ordonnances signés (32
        // caractères au moins), hors base et hors dépôt. La changer rend les
        // documents déjà signés « altérés » : rotation par version seulement.
        'secret_signature' => env('DOSSIER_SECRET_SIGNATURE'),
    ],

    /*
    | Téléconsultation (TLC-01 à TLC-06, RG-06, RG-07).
    */
    'teleconsultation' => [
        // La salle ouvre avant l'heure et reste ouverte après la fin prévue
        // (retard du médecin, reprise après coupure, RG-07).
        'ouverture_minutes_avant' => 10,
        'fermeture_minutes_apres' => 60,
        // STUN et TURN, séparés par des virgules (ex. « stun:turn.medihome.cd:3478 »,
        // « turn:turn.medihome.cd:3478?transport=udp,turns:turn.medihome.cd:5349 »).
        // Vides en local : deux navigateurs du même poste se joignent sans aide.
        'stun' => env('TLC_STUN_URLS', ''),
        'turn' => env('TLC_TURN_URLS', ''),
        // Secret partagé avec coturn (use-auth-secret), 32 caractères au moins.
        'turn_secret' => env('TLC_TURN_SECRET'),
        'turn_validite_secondes' => 4 * 3600,
        // Présence réelle dans l'appel (revue de sécurité) : battement des
        // navigateurs connectés ; deux battements à moins de « tolérance »
        // d'écart = ensemble. Sous « durée minimale » passée ensemble, la
        // consultation ne peut être que « interrompue » (RG-07, remboursée).
        'presence_intervalle_secondes' => 15,
        'presence_tolerance_secondes' => 45,
        'duree_minimale_secondes' => 120,
        // Signaux WebRTC : taille et débit bornés (une offre SDP fait quelques Ko).
        'signal_octets_max' => 16 * 1024,
        'signaux_par_minute' => 240,
        // Bornes par type et par consultation (revue de sécurité) : une offre
        // ou une réponse par émetteur toutes les quelques secondes ; un
        // candidat réseau tient en 1 Ko ; plafonds de candidats et de signaux
        // par consultation ; une lecture rend au plus N signaux et N octets.
        'description_intervalle_secondes' => 3,
        'candidat_octets_max' => 1024,
        'candidats_max_par_consultation' => 300,
        'signaux_max_par_consultation' => 600,
        'lecture_signaux_max' => 100,
        'lecture_octets_max' => 64 * 1024,
        // Avec un serveur TURN, tout passe par le relais (iceTransportPolicy
        // « relay ») : l'adresse IP de l'un n'est jamais donnée à l'autre. Sans
        // TURN (poste de développement), connexion directe.
        'relais_seulement' => (bool) env('TLC_RELAIS_SEULEMENT', true),
        // Messagerie (TLC-03) : photos seulement, 5 Mo.
        'photo_octets_max' => 5 * 1024 * 1024,
        // Quota de photos par participant et par consultation (revue de sécurité).
        'photos_par_participant' => 20,
        'photos_octets_par_participant' => 40 * 1024 * 1024,
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

    /*
    | Paiement (PAY-01 à PAY-09, RG-01 à RG-03).
    */
    'paiement' => [
        // Pas de valeur par défaut : un oubli en production doit se voir.
        // « simulation » (développement, tests) est refusé en production.
        'fournisseur' => env('PAIEMENT_FOURNISSEUR'),
        'secret_rappel' => env('PAIEMENT_SECRET_RAPPEL'),
        // Écart toléré entre l'horodatage signé d'un rappel et notre horloge.
        'tolerance_signature_secondes' => 300,
        'devises' => ['USD', 'CDF'],
        // Tentative sans réponse de l'opérateur : close après ce délai, le
        // patient peut réessayer tant que son créneau est bloqué (RG-03).
        'delai_confirmation_minutes' => 10,
        // Tentatives par rendez-vous : borne les demandes de débit (et les
        // messages de confirmation envoyés au téléphone du payeur).
        'tentatives_par_rendez_vous' => 5,
        // PAY-09 : part de la plateforme sur les honoraires, en pourcentage.
        // Pas de valeur par défaut : décision du client.
        'commission_pourcent' => env('DOC_AND_I_COMMISSION_POURCENT'),
    ],

    'roles' => [
        'patient', 'medecin', 'coordinateur', 'laboratoire', 'caisse',
        'support', 'admin_fonctionnel', 'admin_technique', 'auditeur',
    ],

];
