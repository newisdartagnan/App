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
];
