<?php

return [
    'establishment_code' => env('ESTABLISHMENT_CODE', 'HGR_KINSHASA_01'),
    'establishment_name' => env('ESTABLISHMENT_NAME', 'Établissement hospitalier'),
    'central_api_url' => env('CENTRAL_API_URL'),
    'central_sync_token' => env('CENTRAL_SYNC_TOKEN'),
    'offline_token_ttl_hours' => 48,
    'sync_batch_size' => 500,
    'sync_interval_minutes' => 15,
    'backup_retention_days' => 7,

    // Taux appliqué aux tarifs exprimés en dollars (consultations 20 $/24 $)
    'taux_usd_cdf' => env('DPI_TAUX_USD_CDF', 2300),

    /*
     * Devises acceptées au guichet.
     *
     * Le franc congolais est la monnaie de compte : tout est ramené en CDF
     * pour additionner et comparer. Chaque opération fige cependant le taux
     * qu'elle a appliqué, de sorte qu'une révision du taux ne réécrive pas
     * les acomptes et les encaissements déjà passés.
     */
    'devise_pivot' => 'CDF',

    'devises' => [
        'CDF' => [
            'libelle' => 'Franc congolais',
            'symbole' => 'CDF',
            'taux_cdf' => 1,
            'decimales' => 0,
            // Le billet le plus petit réellement en circulation.
            'coupures' => [20000, 10000, 5000, 1000, 500, 200, 100, 50],
        ],
        'USD' => [
            'libelle' => 'Dollar américain',
            'symbole' => '$',
            'taux_cdf' => env('DPI_TAUX_USD_CDF', 2300),
            'decimales' => 2,
            'coupures' => [100, 50, 20, 10, 5, 2, 1],
        ],
        'EUR' => [
            'libelle' => 'Euro',
            'symbole' => '€',
            'taux_cdf' => env('DPI_TAUX_EUR_CDF', 2681.50),
            'decimales' => 2,
            'coupures' => [500, 200, 100, 50, 20, 10, 5],
        ],
    ],

    'tarifs_cdf' => [
        'consultation_externe' => 15000,
        'urgence' => 25000,
        'hospitalisation_jour' => 35000,
        'chirurgie_minor' => 150000,
        'accouchement' => 200000,
        'dialyse_seance' => 120000,
        'dialyse_seance_epo' => 165000,
        'dialyse_peritoneale' => 60000,
        'dialyse_catheter' => 180000,
        'dialyse_fistule' => 400000,
    ],

    /**
     * Catalogue des actes cliniques, par domaine.
     *
     * C'est d'ici que se remplit la liste proposée au prescripteur. Ajouter
     * une intervention ne demande pas de toucher au code : une ligne suffit,
     * avec sa durée habituelle en salle, qui pré-remplit la réservation.
     */
    'actes' => [
        'chirurgie' => [
            ['libelle' => 'Cure de hernie inguinale', 'prix' => 350000, 'duree' => 90],
            ['libelle' => 'Cure de hernie ombilicale', 'prix' => 300000, 'duree' => 75],
            ['libelle' => 'Appendicectomie', 'prix' => 400000, 'duree' => 60],
            ['libelle' => 'Laparotomie exploratrice', 'prix' => 600000, 'duree' => 120],
            ['libelle' => 'Cholécystectomie', 'prix' => 750000, 'duree' => 120],
            ['libelle' => 'Herniorraphie étranglée (urgence)', 'prix' => 500000, 'duree' => 120],
            ['libelle' => 'Cure d\'hydrocèle', 'prix' => 250000, 'duree' => 60],
            ['libelle' => 'Thyroïdectomie', 'prix' => 900000, 'duree' => 180],
            ['libelle' => 'Splénectomie', 'prix' => 850000, 'duree' => 150],
            ['libelle' => 'Colostomie', 'prix' => 700000, 'duree' => 120],
            ['libelle' => 'Prostatectomie', 'prix' => 950000, 'duree' => 150],
            ['libelle' => 'Ostéosynthèse de fracture', 'prix' => 800000, 'duree' => 150],
            ['libelle' => 'Amputation de membre', 'prix' => 600000, 'duree' => 120],
            ['libelle' => 'Parage et suture de plaie profonde', 'prix' => 150000, 'duree' => 45],
            ['libelle' => 'Drainage d\'abcès profond', 'prix' => 200000, 'duree' => 45],
            ['libelle' => 'Circoncision', 'prix' => 120000, 'duree' => 30],
            ['libelle' => 'Biopsie chirurgicale', 'prix' => 180000, 'duree' => 45],
            ['libelle' => 'Petite chirurgie sous anesthésie locale', 'prix' => 150000, 'duree' => 30],
        ],
        'maternite' => [
            ['libelle' => 'Césarienne', 'prix' => 450000, 'duree' => 90],
            ['libelle' => 'Césarienne en urgence', 'prix' => 550000, 'duree' => 90],
            ['libelle' => 'Accouchement voie basse', 'prix' => 200000, 'duree' => 60],
            ['libelle' => 'Accouchement assisté (ventouse ou forceps)', 'prix' => 280000, 'duree' => 60],
            ['libelle' => 'Révision utérine', 'prix' => 200000, 'duree' => 45],
            ['libelle' => 'Délivrance artificielle', 'prix' => 180000, 'duree' => 45],
            ['libelle' => 'Réparation de déchirure périnéale', 'prix' => 150000, 'duree' => 45],
            ['libelle' => 'Cerclage du col', 'prix' => 300000, 'duree' => 45],
            ['libelle' => 'Curetage évacuateur', 'prix' => 220000, 'duree' => 45],
            ['libelle' => 'Grossesse extra-utérine — laparotomie', 'prix' => 650000, 'duree' => 120],
            ['libelle' => 'Hystérectomie d\'hémostase', 'prix' => 900000, 'duree' => 150],
            ['libelle' => 'Myomectomie', 'prix' => 700000, 'duree' => 120],
            ['libelle' => 'Ligature des trompes', 'prix' => 350000, 'duree' => 60],
        ],
        'dialyse' => [
            ['libelle' => 'Séance d\'hémodialyse (4 h)', 'prix' => 120000, 'duree' => 240],
            ['libelle' => 'Séance d\'hémodialyse avec érythropoïétine', 'prix' => 165000, 'duree' => 240],
            ['libelle' => 'Dialyse péritonéale — échange', 'prix' => 60000, 'duree' => 60],
            ['libelle' => 'Pose de cathéter de dialyse', 'prix' => 180000, 'duree' => 60],
            ['libelle' => 'Confection de fistule artério-veineuse', 'prix' => 400000, 'duree' => 120],
            ['libelle' => 'Réfection de fistule', 'prix' => 350000, 'duree' => 90],
        ],
    ],

    /*
     * Le sang n'est pas gratuit même quand le donneur est bénévole : le
     * prélèvement, les cinq dépistages, la poche et la chaîne du froid se
     * paient. Le tarif porte sur l'unité délivrée, pas sur la demande.
     */
    'sang' => [
        'tarifs' => [
            'sang_total' => 45000,
            'concentre_globulaire' => 55000,
            'plasma_frais' => 40000,
            'plaquettes' => 65000,
            'cryoprecipite' => 50000,
        ],
    ],
];
