<?php
/**
 * Imagerie - Fonctions et constantes communes
 * 
 * Ce fichier centralise les labels, couleurs, transitions et fonctions utilitaires
 * utilisees par tous les modules imagerie (dashboard, examens, workflow).
 * 
 * Inclusion : require_once __DIR__ . '/../includes/imagerie_helpers.php';
 */

// Protection contre les inclusions multiples
if (defined('IMAGERIE_HELPERS_LOADED')) return;
define('IMAGERIE_HELPERS_LOADED', true);

// =============================================
// MAP STATUT -> LABELS ET COULEURS
// =============================================

$GLOBALS['imagerie_statut_labels'] = [
    'programme'              => ['label' => 'Programme',          'color' => '#6c757d', 'bg' => '#e9ecef',  'icon' => 'bi-calendar-event'],
    'accueil'                => ['label' => 'Accueil',            'color' => '#0d6efd', 'bg' => '#cfe2ff',  'icon' => 'bi-person-check'],
    'en_acquisition'         => ['label' => 'En acquisition',     'color' => '#0dcaf0', 'bg' => '#cff4fc',  'icon' => 'bi-camera'],
    'acquisition_terminee'   => ['label' => 'Acq. terminee',      'color' => '#fd7e14', 'bg' => '#ffe5d0',  'icon' => 'bi-check-circle'],
    'en_interpretation'      => ['label' => 'Interpretation',     'color' => '#d63384', 'bg' => '#f7d6e6',  'icon' => 'bi-search'],
    'compte_rendu_fait'      => ['label' => 'Compte-rendu fait',  'color' => '#20c997', 'bg' => '#d2f4ea',  'icon' => 'bi-file-text'],
    'validation_radiologue'  => ['label' => 'Valid. radiologue',  'color' => '#198754', 'bg' => '#d1e7dd',  'icon' => 'bi-patch-check'],
    'validation_chef'        => ['label' => 'Valid. chef',        'color' => '#0f5132', 'bg' => '#badbcc',  'icon' => 'bi-patch-check-fill'],
    'transmis'               => ['label' => 'Transmis',           'color' => '#198754', 'bg' => '#d1e7dd',  'icon' => 'bi-send'],
    'annule'                 => ['label' => 'Annule',             'color' => '#dc3545', 'bg' => '#f8d7da',  'icon' => 'bi-x-circle'],
];

// =============================================
// COULEURS PRIORITE
// =============================================

$GLOBALS['imagerie_priorite_colors'] = [
    'programme'         => ['color' => '#198754', 'bg' => '#d1e7dd', 'label' => 'Programme'],
    'urgence'           => ['color' => '#fd7e14', 'bg' => '#ffe5d0', 'label' => 'Urgence'],
    'extreme_urgence'   => ['color' => '#dc3545', 'bg' => '#f8d7da', 'label' => 'Extreme urgence'],
];

// =============================================
// COULEURS QUALITE IMAGES
// =============================================

$GLOBALS['imagerie_qualite_colors'] = [
    'excellente' => ['color' => '#0f5132', 'bg' => '#badbcc'],
    'bonne'      => ['color' => '#198754', 'bg' => '#d1e7dd'],
    'moyenne'    => ['color' => '#664d03', 'bg' => '#fff3cd'],
    'mauvaise'   => ['color' => '#dc3545', 'bg' => '#f8d7da'],
];

// =============================================
// MAP DES TRANSITIONS AUTORISEES (workflow)
// =============================================
// statut_actuel => [statut_suivant => ['label', 'icon', 'btn_class', 'profils_autorises']]

$GLOBALS['imagerie_transitions'] = [
    'programme' => [
        'accueil' => ['Accueillir patient', 'bi-person-check', 'btn-primary', ['admin','technicien_imagerie']],
        'annule'  => ['Annuler examen', 'bi-x-circle', 'btn-outline-danger', ['admin','radiologue']],
    ],
    'accueil' => [
        'en_preparation' => ['Preparer salle', 'bi-gear', 'btn-info', ['admin','technicien_imagerie']],
        'annule'         => ['Annuler', 'bi-x-circle', 'btn-outline-danger', ['admin','radiologue']],
    ],
    'en_preparation' => [
        'en_acquisition' => ['Demarrer acquisition', 'bi-camera', 'btn-primary', ['admin','technicien_imagerie']],
        'annule'         => ['Annuler', 'bi-x-circle', 'btn-outline-danger', ['admin','radiologue']],
    ],
    'en_acquisition' => [
        'acquisition_terminee' => ['Terminer acquisition', 'bi-check-circle', 'btn-success', ['admin','technicien_imagerie']],
        'annule'               => ['Annuler', 'bi-x-circle', 'btn-outline-danger', ['admin','radiologue']],
    ],
    'acquisition_terminee' => [
        'en_reconstruction'  => ['Envoyer en reconstruction', 'bi-layers', 'btn-info', ['admin','technicien_imagerie','radiologue']],
        'en_interpretation'  => ['Envoyer en interpretation', 'bi-search', 'btn-primary', ['admin','technicien_imagerie','radiologue']],
    ],
    'en_reconstruction' => [
        'en_interpretation' => ['Envoyer en interpretation', 'bi-search', 'btn-primary', ['admin','technicien_imagerie','radiologue']],
    ],
    'en_interpretation' => [
        'compte_rendu_fait' => ['Finaliser compte-rendu', 'bi-file-text', 'btn-success', ['admin','radiologue']],
    ],
    'compte_rendu_fait' => [
        'validation_radiologue' => ['Valider (radiologue)', 'bi-patch-check', 'btn-primary', ['admin','radiologue']],
    ],
    'validation_radiologue' => [
        'validation_chef' => ['Valider (chef service)', 'bi-patch-check-fill', 'btn-warning', ['admin','radiologue']],
        'transmis'        => ['Transmettre directement', 'bi-send', 'btn-success', ['admin','radiologue']],
    ],
    'validation_chef' => [
        'transmis' => ['Transmettre resultats', 'bi-send', 'btn-success', ['admin','radiologue']],
    ],
];

// =============================================
// STATUTS FINAUX ET GROUPEMENTS
// =============================================

define('IMAGERIE_STATUTS_FINAUX', ['transmis', 'annule']);

// Statuts ou le compte-rendu est consultable/saisissable
define('IMAGERIE_STATUTS_COMPTE_RENDU', [
    'en_interpretation', 'compte_rendu_fait', 'validation_radiologue', 'validation_chef', 'transmis'
]);

// Statuts "en cours" (pour les stats)
define('IMAGERIE_STATUTS_EN_COURS', [
    'accueil', 'en_preparation', 'en_acquisition', 'acquisition_terminee',
    'en_reconstruction', 'en_interpretation', 'compte_rendu_fait',
    'validation_radiologue', 'validation_chef'
]);

// Colonnes Kanban (groupement pour le tableau workflow)
$GLOBALS['imagerie_kanban_colonnes'] = [
    'a_faire'       => [
        'label' => 'A faire',
        'color' => '#6c757d',
        'statuts' => ['programme'],
    ],
    'accueil_prep'  => [
        'label' => 'Accueil / Preparation',
        'color' => '#0d6efd',
        'statuts' => ['accueil', 'en_preparation'],
    ],
    'acquisition'   => [
        'label' => 'Acquisition',
        'color' => '#0dcaf0',
        'statuts' => ['en_acquisition', 'acquisition_terminee', 'en_reconstruction'],
    ],
    'interpretation' => [
        'label' => 'Interpretation / CR',
        'color' => '#d63384',
        'statuts' => ['en_interpretation', 'compte_rendu_fait'],
    ],
    'validation'    => [
        'label' => 'Validation',
        'color' => '#198754',
        'statuts' => ['validation_radiologue', 'validation_chef'],
    ],
    'termine'       => [
        'label' => 'Termine',
        'color' => '#198754',
        'statuts' => ['transmis'],
    ],
];

// =============================================
// FONCTIONS UTILITAIRES
// =============================================

/**
 * Genere un badge HTML colore pour un statut imagerie
 */
function getImagerieStatutBadge(string $statut): string {
    $labels = $GLOBALS['imagerie_statut_labels'];
    $info = $labels[$statut] ?? ['label' => $statut, 'color' => '#6c757d', 'bg' => '#e9ecef', 'icon' => 'bi-circle'];
    return '<span class="badge" style="background:' . $info['bg'] . '; color:' . $info['color'] . '; font-weight:600;">'
         . '<i class="bi ' . $info['icon'] . ' me-1"></i>'
         . htmlspecialchars($info['label']) . '</span>';
}

/**
 * Genere un badge HTML pour la priorite
 */
function getImageriePrioriteBadge(string $priorite): string {
    $info = $GLOBALS['imagerie_priorite_colors'][$priorite] ?? ['color' => '#6c757d', 'bg' => '#e9ecef', 'label' => $priorite];
    $icon = match($priorite) {
        'extreme_urgence' => 'bi-exclamation-triangle-fill',
        'urgence'         => 'bi-exclamation-circle',
        default           => 'bi-clock',
    };
    return '<span class="badge" style="background:' . $info['bg'] . '; color:' . $info['color'] . '; font-weight:600;">'
         . '<i class="bi ' . $icon . ' me-1"></i>'
         . htmlspecialchars($info['label']) . '</span>';
}

/**
 * Genere un badge HTML pour la qualite des images
 */
function getImagerieQualiteBadge(?string $qualite): string {
    if (!$qualite) return '<span class="text-muted">-</span>';
    $info = $GLOBALS['imagerie_qualite_colors'][$qualite] ?? ['color' => '#6c757d', 'bg' => '#e9ecef'];
    return '<span class="badge" style="background:' . $info['bg'] . '; color:' . $info['color'] . '; font-weight:600;">'
         . htmlspecialchars(ucfirst($qualite)) . '</span>';
}

/**
 * Formate un delai en minutes vers un affichage lisible (ex: "2h15", "45 min")
 */
function formatImagerieDelai($minutes): string {
    if ($minutes === null) return '-';
    $minutes = (int)$minutes;
    if ($minutes < 60) return $minutes . ' min';
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return $h . 'h' . ($m > 0 ? str_pad($m, 2, '0', STR_PAD_LEFT) : '');
}

/**
 * Retourne les transitions possibles pour un statut et un profil donnes
 */
function getImagerieTransitionsPossibles(string $statut_actuel, string $profil_code): array {
    $transitions = $GLOBALS['imagerie_transitions'];
    $actions = $transitions[$statut_actuel] ?? [];
    
    $filtrees = [];
    foreach ($actions as $new_st => $info) {
        if (in_array($profil_code, $info[3])) {
            $filtrees[$new_st] = $info;
        }
    }
    return $filtrees;
}

/**
 * URL helper pour garder les filtres dans la pagination
 */
function buildImagerieFilterUrl(string $base_action = 'examens', array $extra = []): string {
    $params = $_GET;
    unset($params['page'], $params['action']);
    $params = array_merge($params, $extra);
    $qs = http_build_query($params);
    return 'index.php?page=imagerie&action=' . $base_action . ($qs ? '&' . $qs : '');
}

/**
 * Verifie si un examen est en retard (depasse le RDV + duree estimee)
 */
function isImagerieEnRetard(array $examen): bool {
    if (in_array($examen['statut'], IMAGERIE_STATUTS_FINAUX)) return false;
    if (empty($examen['date_rdv'])) return false;
    
    $rdv = strtotime($examen['date_rdv']);
    $duree = (int)($examen['duree_estimee_min'] ?? 60);
    $limite = $rdv + ($duree * 60);
    
    return time() > $limite && !in_array($examen['statut'], ['transmis', 'annule']);
}

/**
 * Calcule le delai ecoule depuis le RDV en minutes
 */
function getImagerieDelaiMinutes(array $examen): ?int {
    if (empty($examen['date_rdv'])) return null;
    $rdv = strtotime($examen['date_rdv']);
    $diff = time() - $rdv;
    return $diff > 0 ? (int)floor($diff / 60) : 0;
}

/**
 * Genere le HTML de la barre de progression du delai
 */
function getImagerieDelaiProgressHtml(array $examen): string {
    $delai = getImagerieDelaiMinutes($examen);
    if ($delai === null) return '<span class="text-muted">-</span>';
    
    $duree_estimee = (int)($examen['duree_estimee_min'] ?? 60);
    $pct = $duree_estimee > 0 ? round(($delai / $duree_estimee) * 100) : 0;
    $color = $pct > 100 ? '#dc3545' : ($pct > 75 ? '#fd7e14' : '#0d6efd');
    
    return '<div style="min-width:80px;">'
         . '<div style="font-size:0.75rem; color:' . $color . '; font-weight:600;">'
         . formatImagerieDelai($delai) . ' / ' . formatImagerieDelai($duree_estimee)
         . '</div>'
         . '<div class="progress" style="height:4px;">'
         . '<div class="progress-bar" style="width:' . min($pct, 100) . '%; background:' . $color . ';"></div>'
         . '</div>'
         . '</div>';
}