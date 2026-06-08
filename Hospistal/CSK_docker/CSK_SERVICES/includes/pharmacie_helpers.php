<?php
/**
 * Pharmacie - Fonctions et constantes communes
 * 
 * Ce fichier centralise les labels, couleurs, transitions et fonctions utilitaires
 * utilisees par tous les modules pharmacie (dashboard, preparations, workflow).
 * 
 * Inclusion : require_once __DIR__ . '/../includes/pharmacie_helpers.php';
 */

// Protection contre les inclusions multiples
if (defined('PHARMACIE_HELPERS_LOADED')) return;
define('PHARMACIE_HELPERS_LOADED', true);

// =============================================
// MAP STATUT -> LABELS ET COULEURS
// =============================================

$GLOBALS['pharma_statut_labels'] = [
    'attente'               => ['label' => 'En attente',         'color' => '#6c757d', 'bg' => '#e9ecef',  'icon' => 'bi-hourglass-split'],
    'verification_stock'    => ['label' => 'Verif. stock',       'color' => '#0d6efd', 'bg' => '#cfe2ff',  'icon' => 'bi-search'],
    'en_preparation'        => ['label' => 'En preparation',     'color' => '#6610f2', 'bg' => '#e0cffc',  'icon' => 'bi-capsule'],
    'preparation_terminee'  => ['label' => 'Prep. terminee',     'color' => '#0dcaf0', 'bg' => '#cff4fc',  'icon' => 'bi-check-circle'],
    'controle_qualite'      => ['label' => 'Ctrl qualite',       'color' => '#fd7e14', 'bg' => '#ffe5d0',  'icon' => 'bi-shield-check'],
    'prete'                 => ['label' => 'Prete',              'color' => '#20c997', 'bg' => '#d2f4ea',  'icon' => 'bi-box-seam'],
    'delivree'              => ['label' => 'Delivree',           'color' => '#198754', 'bg' => '#d1e7dd',  'icon' => 'bi-check2-all'],
    'retournee'             => ['label' => 'Retournee',          'color' => '#dc3545', 'bg' => '#f8d7da',  'icon' => 'bi-arrow-return-left'],
    'annulee'               => ['label' => 'Annulee',            'color' => '#842029', 'bg' => '#f5c2c7',  'icon' => 'bi-x-circle'],
];

// =============================================
// MAP DES TRANSITIONS AUTORISEES (workflow)
// =============================================

$GLOBALS['pharma_transitions'] = [
    'attente' => [
        'verification_stock' => ['Verifier le stock', 'bi-search', 'btn-primary', ['admin','pharmacien','pharmacien_chef']],
        'annulee' => ['Annuler la prescription', 'bi-x-circle', 'btn-outline-danger', ['admin','pharmacien_chef']],
    ],
    'verification_stock' => [
        'en_preparation' => ['Demarrer la preparation', 'bi-play-circle', 'btn-primary', ['admin','pharmacien','pharmacien_chef']],
        'retournee' => ['Stock insuffisant', 'bi-arrow-return-left', 'btn-outline-warning', ['admin','pharmacien','pharmacien_chef']],
        'annulee' => ['Annuler', 'bi-x-circle', 'btn-outline-danger', ['admin','pharmacien_chef']],
    ],
    'en_preparation' => [
        'preparation_terminee' => ['Terminer la preparation', 'bi-check-circle', 'btn-success', ['admin','pharmacien','pharmacien_chef']],
    ],
    'preparation_terminee' => [
        'controle_qualite' => ['Controler la qualite', 'bi-shield-check', 'btn-warning', ['admin','pharmacien','pharmacien_chef']],
    ],
    'controle_qualite' => [
        'prete' => ['Valider et mettre a disposition', 'bi-box-seam', 'btn-success', ['admin','pharmacien','pharmacien_chef']],
        'en_preparation' => ['Reprendre la preparation', 'bi-arrow-counterclockwise', 'btn-outline-warning', ['admin','pharmacien_chef']],
        'retournee' => ['Non conforme - Retour', 'bi-x-octagon', 'btn-outline-danger', ['admin','pharmacien_chef']],
    ],
    'prete' => [
        'delivree' => ['Delivrer au patient', 'bi-hand-thumbs-up', 'btn-success', ['admin','pharmacien','pharmacien_chef']],
        'retournee' => ['Retourner au stock', 'bi-arrow-return-left', 'btn-outline-secondary', ['admin','pharmacien','pharmacien_chef']],
    ],
];

// =============================================
// STATUTS FINAUX ET GROUPEMENTS
// =============================================

define('PHARMA_STATUTS_FINAUX', ['delivree', 'retournee', 'annulee']);

define('PHARMA_STATUTS_EN_COURS', [
    'attente', 'verification_stock', 'en_preparation',
    'preparation_terminee', 'controle_qualite', 'prete'
]);

// =============================================
// COLONNES KANBAN (AJOUTER CE BLOC)
// =============================================

$GLOBALS['pharma_kanban_colonnes'] = [
    'attente' => [
        'label' => 'En attente',
        'color' => '#6c757d',
        'statuts' => ['attente', 'verification_stock'],
    ],
    'preparation' => [
        'label' => 'Preparation',
        'color' => '#6610f2',
        'statuts' => ['en_preparation', 'preparation_terminee'],
    ],
    'controle' => [
        'label' => 'Controle qualite',
        'color' => '#fd7e14',
        'statuts' => ['controle_qualite'],
    ],
    'prete' => [
        'label' => 'Prete / Delivrance',
        'color' => '#20c997',
        'statuts' => ['prete'],
    ],
    'termine' => [
        'label' => 'Termine',
        'color' => '#198754',
        'statuts' => ['delivree'],
    ],
];

// =============================================
// FONCTIONS UTILITAIRES
// =============================================

function getPharmaStatutBadge(string $statut): string {
    $labels = $GLOBALS['pharma_statut_labels'];
    $info = $labels[$statut] ?? ['label' => $statut, 'color' => '#6c757d', 'bg' => '#e9ecef', 'icon' => 'bi-circle'];
    return '<span class="badge" style="background:' . $info['bg'] . '; color:' . $info['color'] . '; font-weight:600;">'
         . '<i class="bi ' . $info['icon'] . ' me-1"></i>'
         . htmlspecialchars($info['label']) . '</span>';
}

function getPharmaUrgenceBadge(bool $urgent): string {
    if ($urgent) {
        return '<span class="badge" style="background:#f8d7da; color:#dc3545; font-weight:600;">'
             . '<i class="bi bi-exclamation-triangle-fill me-1"></i>Urgent</span>';
    }
    return '<span class="badge" style="background:#d1e7dd; color:#198754; font-weight:600;">'
         . '<i class="bi bi-clock me-1"></i>Normal</span>';
}

function formatPharmaDelai($minutes): string {
    if ($minutes === null) return '-';
    $minutes = (int)$minutes;
    if ($minutes < 60) return $minutes . ' min';
    $h = floor($minutes / 60);
    $m = $minutes % 60;
    return $h . 'h' . ($m > 0 ? str_pad($m, 2, '0', STR_PAD_LEFT) : '');
}

function getPharmaTransitionsPossibles(string $statut_actuel, string $profil_code): array {
    $transitions = $GLOBALS['pharma_transitions'];
    $actions = $transitions[$statut_actuel] ?? [];
    
    $filtrees = [];
    foreach ($actions as $new_st => $info) {
        if (in_array($profil_code, $info[3])) {
            $filtrees[$new_st] = $info;
        }
    }
    return $filtrees;
}

function buildPharmaFilterUrl(string $base_action = 'preparations', array $extra = []): string {
    $params = $_GET;
    unset($params['page'], $params['action']);
    $params = array_merge($params, $extra);
    $qs = http_build_query($params);
    return 'index.php?page=pharmacie&action=' . $base_action . ($qs ? '&' . $qs : '');
}

function isPharmaEnRetard(array $preparation): bool {
    if (in_array($preparation['statut'], PHARMA_STATUTS_FINAUX)) return false;
    
    $created = strtotime($preparation['created_at'] ?? 'now');
    $urgent = !empty($preparation['urgence']);
    $seuil_minutes = $urgent ? 60 : 120;
    $limite = $created + ($seuil_minutes * 60);
    
    return time() > $limite;
}

function getPharmaDelaiMinutes(array $preparation): ?int {
    if (empty($preparation['created_at'])) return null;
    $created = strtotime($preparation['created_at']);
    $diff = time() - $created;
    return $diff > 0 ? (int)floor($diff / 60) : 0;
}

function getPharmaDelaiProgressHtml(array $preparation): string {
    $delai = getPharmaDelaiMinutes($preparation);
    if ($delai === null) return '<span class="text-muted">-</span>';
    
    $urgent = !empty($preparation['urgence']);
    $seuil = $urgent ? 60 : 120;
    $pct = $seuil > 0 ? round(($delai / $seuil) * 100) : 0;
    $color = $pct > 100 ? '#dc3545' : ($pct > 75 ? '#fd7e14' : '#0d6efd');
    
    return '<div style="min-width:80px;">'
         . '<div style="font-size:0.75rem; color:' . $color . '; font-weight:600;">'
         . formatPharmaDelai($delai) . ' / ' . formatPharmaDelai($seuil)
         . '</div>'
         . '<div class="progress" style="height:4px;">'
         . '<div class="progress-bar" style="width:' . min($pct, 100) . '%; background:' . $color . ';"></div>'
         . '</div>'
         . '</div>';
}

function getPharmaTypeProduitBadge(?string $type): string {
    if (!$type) return '<span class="text-muted">-</span>';
    $colors = [
        'medicament'        => ['color' => '#0d6efd', 'bg' => '#cfe2ff'],
        'consommable'       => ['color' => '#198754', 'bg' => '#d1e7dd'],
        'dispositif_medical'=> ['color' => '#6610f2', 'bg' => '#e0cffc'],
        'reactif'           => ['color' => '#fd7e14', 'bg' => '#ffe5d0'],
        'solution'          => ['color' => '#0dcaf0', 'bg' => '#cff4fc'],
    ];
    $info = $colors[$type] ?? ['color' => '#6c757d', 'bg' => '#e9ecef'];
    return '<span class="badge" style="background:' . $info['bg'] . '; color:' . $info['color'] . '; font-weight:600;">'
         . htmlspecialchars(ucfirst(str_replace('_', ' ', $type))) . '</span>';
}