<?php
/**
 * Dashboard principal CSK Services
 * Affiche un résumé des 3 services selon les droits de l'utilisateur.
 *
 * LABO : stats calculées directement depuis labo_groupes_echantillons + labo_echantillons
 *        (uniquement échantillons dans un groupe, statuts actifs : attente_prelevement / preleve / validation_technique)
 *
 * ACTIVITÉ RÉCENTE :
 *   - LABORATOIRE : 1 ligne = 1 GROUPE (code_groupe, liste examens, statut calculé)
 *                   Statuts affichés : attente_prelevement / preleve / validation_technique
 *   - IMAGERIE / PHARMACIE : v_workflow_complet (statut 'resultat_transmis' exclu)
 */
$currentUser = getCurrentUser();

// ============================================
// 1. CONNEXIONS
// ============================================
try {
    $DB = defined('DB_MAIN') ? DB_MAIN : 'csk_base';
$db            = new Database();
    $conn_services = $db->getServicesConnection();
    $conn_base     = $db->getBaseConnection();
} catch (Exception $e) {
    error_log("[CSK Services] Erreur connexion dashboard: " . $e->getMessage());
}

// ============================================
// 2. STATS LABORATOIRE — depuis les groupes
//    Seuls les échantillons appartenant à un groupe sont comptés.
//    Statuts actifs : attente_prelevement / preleve / validation_technique
// ============================================
$labo = ['total' => 0, 'en_attente' => 0, 'en_cours' => 0, 'termines' => 0, 'urgents' => 0];
try {
    $stmt = $conn_services->query("
        SELECT
            COUNT(le.idechantillon)                                                AS total,
            SUM(le.statut = 'attente_prelevement')                                 AS en_attente,
            SUM(le.statut = 'preleve')                                             AS en_cours,
            SUM(le.statut = 'validation_technique')                                AS termines,
            SUM(le.urgence = 1 AND le.statut IN ('attente_prelevement','preleve')) AS urgents
        FROM labo_echantillons le
        INNER JOIN labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
        WHERE le.deleted_at IS NULL
          AND le.statut IN ('attente_prelevement','preleve','validation_technique')
    ");
    $row = $stmt->fetch();
    if ($row) {
        $labo = [
            'total'      => (int)$row['total'],
            'en_attente' => (int)$row['en_attente'],
            'en_cours'   => (int)$row['en_cours'],
            'termines'   => (int)$row['termines'],
            'urgents'    => (int)$row['urgents'],
        ];
    }
} catch (Exception $e) {
    error_log("[CSK Services] Erreur stats labo dashboard: " . $e->getMessage());
}

// ============================================
// 3. STATS IMAGERIE + PHARMACIE — depuis v_dashboard_services
// ============================================
$imagerie  = ['total' => 0, 'en_attente' => 0, 'en_cours' => 0, 'termines' => 0, 'urgents' => 0];
$pharmacie = ['total' => 0, 'en_attente' => 0, 'en_cours' => 0, 'termines' => 0, 'urgents' => 0];
try {
    $stmt = $conn_services->query("SELECT * FROM v_dashboard_services");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if ($row['service'] === 'imagerie')  $imagerie  = $row;
        if ($row['service'] === 'pharmacie') $pharmacie = $row;
    }
} catch (Exception $e) {
    error_log("[CSK Services] Erreur stats imagerie/pharmacie dashboard: " . $e->getMessage());
}

// ============================================
// 4. NOTIFICATIONS RÉCENTES
// ============================================
$notifs_recentes = [];
try {
    if ($is_admin) {
        $stmt_n = $conn_services->query(
            "SELECT * FROM services_notifications WHERE lu = 0 AND archive = 0 ORDER BY created_at DESC LIMIT 5"
        );
    } else {
        $groupe = $currentUser['groupe_notification'] ?? null;
        $stmt_n = $conn_services->prepare(
            "SELECT * FROM services_notifications WHERE lu = 0 AND archive = 0
             AND (groupe_destinataire = :g OR id_destinataire = :uid)
             ORDER BY created_at DESC LIMIT 5"
        );
        $stmt_n->execute([':g' => $groupe, ':uid' => $user_id]);
    }
    $notifs_recentes = $stmt_n->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("[CSK Services] Erreur notifs dashboard: " . $e->getMessage());
}

// ============================================
// 5. RAPPORT LABO DU MOIS — depuis labo_echantillons (groupes)
// ============================================
$mois_fr = [
    1 => 'janvier', 2 => 'février',  3 => 'mars',      4 => 'avril',
    5 => 'mai',     6 => 'juin',     7 => 'juillet',   8 => 'août',
    9 => 'septembre',10=> 'octobre', 11 => 'novembre', 12 => 'décembre'
];
$mois_num   = (int)date('n');
$annee      = date('Y');
$mois_texte = $mois_fr[$mois_num] . ' ' . $annee;

$total_groupes_mois   = 0;
$groupes_valides_mois = 0;
$taux_completion_dash = 0;
$delai_moyen_dash     = 0;  // en minutes

if ($has_labo || $is_admin) {
    try {
        $stmt = $conn_services->query("
            SELECT
                COUNT(DISTINCT lg.idgroupe) AS total_groupes,
                COUNT(DISTINCT CASE
                    WHEN NOT EXISTS (
                        SELECT 1 FROM labo_echantillons le2
                        WHERE le2.idgroupe  = lg.idgroupe
                          AND le2.deleted_at IS NULL
                          AND le2.statut   != 'validation_technique'
                    ) THEN lg.idgroupe END) AS groupes_valides,
                AVG(TIMESTAMPDIFF(MINUTE, lg.date_creation, NOW())) AS delai_moyen_min
            FROM labo_groupes_echantillons lg
            INNER JOIN labo_echantillons le ON le.idgroupe = lg.idgroupe AND le.deleted_at IS NULL
            WHERE DATE(lg.date_creation) BETWEEN DATE_FORMAT(NOW(),'%Y-%m-01') AND LAST_DAY(NOW())
              AND le.statut IN ('attente_prelevement','preleve','validation_technique')
        ");
        $row = $stmt->fetch();
        if ($row) {
            $total_groupes_mois   = (int)$row['total_groupes'];
            $groupes_valides_mois = (int)$row['groupes_valides'];
            $taux_completion_dash = $total_groupes_mois > 0
                ? round(($groupes_valides_mois / $total_groupes_mois) * 100, 1) : 0;
            $delai_moyen_dash = round((float)$row['delai_moyen_min']);
        }
    } catch (Exception $e) {
        error_log("[Dashboard] Erreur rapport labo mois: " . $e->getMessage());
    }
}

// ============================================
// 6. RAPPORT IMAGERIE DU MOIS
// ============================================
$total_imagerie = 0;
$taux_imagerie  = 0;
$delai_imagerie = 0;

if ($has_imagerie || $is_admin) {
    try {
        $stmt = $conn_base->prepare("
            SELECT
                COUNT(DISTINCT ap.IDActe_presc) AS total,
                COUNT(DISTINCT CASE WHEN ap.Statut = 3 THEN ap.IDActe_presc END) AS termines,
                AVG(TIMESTAMPDIFF(HOUR, ap.Date_presc, ap.Date_acheve)) AS delai_moyen
            FROM {$DB}.Acte_presc ap
            JOIN {$DB}.Acte a ON ap.IDActe = a.IDActe
            WHERE a.IDSous_specialite IN (SELECT IDSous_specialite FROM {$DB}.Sous_specialite WHERE IDSpecialite = 23)
              AND DATE(ap.Date_presc) BETWEEN :deb AND :fin
        ");
        $stmt->execute([':deb' => date('Y-m-01'), ':fin' => date('Y-m-t')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_imagerie = (int)($row['total'] ?? 0);
        $termines_img   = (int)($row['termines'] ?? 0);
        $taux_imagerie  = $total_imagerie > 0 ? round(($termines_img / $total_imagerie) * 100, 1) : 0;
        $delai_imagerie = round((float)($row['delai_moyen'] ?? 0), 1);
    } catch (Exception $e) {
        error_log("[Dashboard] Erreur rapport imagerie mois: " . $e->getMessage());
    }
}

// ============================================
// 7. RAPPORT PHARMACIE DU MOIS
// ============================================
$total_pharma = 0;
$taux_pharma  = 0;
$ca_pharma    = 0;
$delai_pharma = 0;

if ($has_pharmacie || $is_admin) {
    try {
        $stmt = $conn_base->prepare("
            SELECT
                COUNT(DISTINCT pp.IDProduit_presc) AS total,
                COUNT(DISTINCT CASE WHEN pp.Statut = 3 THEN pp.IDProduit_presc END) AS termines,
                SUM(pp.Prix_total) AS chiffre_affaire,
                AVG(TIMESTAMPDIFF(HOUR, pp.Date_presc, pp.Date_acheve)) AS delai_moyen
            FROM {$DB}.Produit_presc pp
            WHERE DATE(pp.Date_presc) BETWEEN :deb AND :fin
        ");
        $stmt->execute([':deb' => date('Y-m-01'), ':fin' => date('Y-m-t')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_pharma    = (int)($row['total'] ?? 0);
        $termines_pharma = (int)($row['termines'] ?? 0);
        $taux_pharma     = $total_pharma > 0 ? round(($termines_pharma / $total_pharma) * 100, 1) : 0;
        $ca_pharma       = (float)($row['chiffre_affaire'] ?? 0);
        $delai_pharma    = round((float)($row['delai_moyen'] ?? 0), 1);
    } catch (Exception $e) {
        error_log("[Dashboard] Erreur rapport pharmacie mois: " . $e->getMessage());
    }
}

// ============================================
// 8. ACTIVITÉ RÉCENTE — LABORATOIRE (par GROUPE)
//
//    1 ligne = 1 groupe labo.
//    Statut du groupe calculé dynamiquement :
//      - Tous validation_technique → 'validation_technique'
//      - Au moins 1 preleve        → 'preleve'
//      - Sinon                     → 'attente_prelevement'
//    La colonne Acte(s) liste tous les examens du groupe.
//    Le badge « N ex. » indique le nombre d'échantillons.
// ============================================
$activite_labo = [];
try {
    $needs_labo = $is_admin || in_array('LABORATOIRE', $services_autorises ?? []);
    if ($needs_labo && ($service_actif === 'ALL' || $service_actif === 'LABORATOIRE')) {
        $stmt = $conn_services->prepare("
            SELECT
                'LABORATOIRE'                                                          AS service_type,
                lg.idgroupe,
                lg.code_groupe                                                         AS code_reference,
                CONCAT(p.Noms, ' ', p.Prenom)                                           AS patient_nom,
                ''                                                                     AS patient_prenom,
                GROUP_CONCAT(DISTINCT a.Nom ORDER BY a.Nom SEPARATOR ' · ')   AS acte_libelle,
                COUNT(le.idechantillon)                                                AS nb_examens,
                CASE
                    WHEN SUM(le.statut != 'validation_technique') = 0 THEN 'validation_technique'
                    WHEN SUM(le.statut = 'preleve')               > 0 THEN 'preleve'
                    ELSE                                                   'attente_prelevement'
                END                                                                    AS statut_courant,
                MAX(le.urgence)                                                        AS urgence,
                GREATEST(MAX(le.updated_at), lg.date_creation)                        AS derniere_action_date
            FROM labo_groupes_echantillons lg
            INNER JOIN labo_echantillons le
                ON  le.idgroupe   = lg.idgroupe
                AND le.deleted_at IS NULL
                AND le.statut     IN ('attente_prelevement','preleve','validation_technique')
            LEFT JOIN {$DB}.Acte_presc ap ON ap.IDActe_presc = le.idactes_presc
            LEFT JOIN {$DB}.acte        a  ON a.IDActe         = ap.IDActe
            LEFT JOIN {$DB}.patient     p  ON p.IDPatient      = lg.idpatient
            GROUP BY lg.idgroupe
            ORDER BY derniere_action_date DESC
            LIMIT 5
        ");
        $stmt->execute();
        $activite_labo = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("[CSK Services] Erreur activité labo groupes: " . $e->getMessage());
}

// ============================================
// 9. ACTIVITÉ RÉCENTE — IMAGERIE (5 max) + PHARMACIE (5 max)
//    Chaque service interrogé séparément pour garantir
//    exactement 5 lignes par service.
//    Le statut 'resultat_transmis' est supprimé → exclu explicitement.
// ============================================
$activite_imagerie  = [];
$activite_pharmacie = [];

try {
    $base_sql = "SELECT * FROM v_workflow_complet
                 WHERE statut_courant != 'resultat_transmis'
                   AND service_type = ?
                 ORDER BY derniere_action_date DESC
                 LIMIT 5";

    $show_imagerie  = $is_admin || in_array('IMAGERIE',  $services_autorises ?? []);
    $show_pharmacie = $is_admin || in_array('PHARMACIE', $services_autorises ?? []);

    if ($show_imagerie && ($service_actif === 'ALL' || $service_actif === 'IMAGERIE')) {
        $stmt = $conn_services->prepare($base_sql);
        $stmt->execute(['IMAGERIE']);
        $activite_imagerie = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    if ($show_pharmacie && ($service_actif === 'ALL' || $service_actif === 'PHARMACIE')) {
        $stmt = $conn_services->prepare($base_sql);
        $stmt->execute(['PHARMACIE']);
        $activite_pharmacie = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    error_log("[CSK Services] Erreur activité imagerie/pharmacie: " . $e->getMessage());
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE BIENVENUE                         -->
<!-- ========================================= -->
<div class="mb-4">
    <h4 class="mb-1">Bienvenue, <?= htmlspecialchars($user_prenom . ' ' . $user_nom) ?></h4>
    <p class="text-muted mb-0">
        <?= htmlspecialchars($user_profil) ?> | <?= htmlspecialchars($site_nom) ?> |
        <?= date('d/m/Y H:i') ?>
    </p>
</div>

<!-- ========================================= -->
<!-- CARTES SERVICES                           -->
<!-- ========================================= -->
<div class="row g-4">

<!-- ─── CARTE LABORATOIRE ─── -->
<?php if (($has_labo ?? false) || ($is_admin ?? false)):
    $show_carte = ($service_actif === 'ALL' || $service_actif === 'LABORATOIRE');
    if ($show_carte): ?>
<div class="col-lg-4 col-md-6">
    <div class="card h-100 border-0 shadow-sm">
        <div class="card-header text-white" style="background:#6f42c1; border:none;">
            <div class="d-flex align-items-center justify-content-between">
                <div><i class="bi bi-droplet-fill me-2"></i><strong>Laboratoire</strong></div>
                <span class="badge bg-light text-dark"><?= $labo['total'] ?> exam.</span>
            </div>
        </div>
        <div class="card-body">
            <div class="row text-center g-2 mb-3">
                <div class="col-4">
                    <div class="p-2 rounded" style="background:#fff3cd;">
                        <div style="font-size:1.5rem;font-weight:700;color:#856404;"><?= $labo['en_attente'] ?></div>
                        <small class="text-muted">Att. prélèv.</small>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:#cfe2ff;">
                        <div style="font-size:1.5rem;font-weight:700;color:#084298;"><?= $labo['en_cours'] ?></div>
                        <small class="text-muted">Prélevés</small>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:#d1e7dd;">
                        <div style="font-size:1.5rem;font-weight:700;color:#0f5132;"><?= $labo['termines'] ?></div>
                        <small class="text-muted">Validés</small>
                    </div>
                </div>
            </div>
            <?php if ($labo['urgents'] > 0): ?>
            <div class="alert alert-danger py-2 mb-3" style="font-size:.85rem;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong><?= $labo['urgents'] ?></strong> examen(s) urgent(s)
            </div>
            <?php endif; ?>
            <a href="index.php?page=labo&action=dashboard" class="btn btn-sm w-100"
               style="background:#6f42c1;color:#fff;">
                <i class="bi bi-arrow-right me-1"></i> Accéder au laboratoire
            </a>
        </div>
    </div>
</div>
<?php endif; endif; ?>

<!-- ─── CARTE IMAGERIE ─── -->
<?php if (($has_imagerie ?? false) || ($is_admin ?? false)):
    $show_carte = ($service_actif === 'ALL' || $service_actif === 'IMAGERIE');
    if ($show_carte): ?>
<div class="col-lg-4 col-md-6">
    <div class="card h-100 border-0 shadow-sm">
        <div class="card-header text-dark" style="background:#0dcaf0; border:none;">
            <div class="d-flex align-items-center justify-content-between">
                <div><i class="bi bi-image-fill me-2"></i><strong>Imagerie</strong></div>
                <span class="badge bg-light text-dark"><?= (int)$imagerie['total'] ?> total</span>
            </div>
        </div>
        <div class="card-body">
            <div class="row text-center g-2 mb-3">
                <div class="col-4">
                    <div class="p-2 rounded" style="background:#fff3cd;">
                        <div style="font-size:1.5rem;font-weight:700;color:#856404;"><?= (int)$imagerie['en_attente'] ?></div>
                        <small class="text-muted">Programmés</small>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:#cff4fc;">
                        <div style="font-size:1.5rem;font-weight:700;color:#055160;"><?= (int)$imagerie['en_cours'] ?></div>
                        <small class="text-muted">En cours</small>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:#d1e7dd;">
                        <div style="font-size:1.5rem;font-weight:700;color:#0f5132;"><?= (int)$imagerie['termines'] ?></div>
                        <small class="text-muted">Transmis</small>
                    </div>
                </div>
            </div>
            <?php if ((int)$imagerie['urgents'] > 0): ?>
            <div class="alert alert-danger py-2 mb-3" style="font-size:.85rem;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong><?= (int)$imagerie['urgents'] ?></strong> examen(s) urgent(s)
            </div>
            <?php endif; ?>
            <a href="index.php?page=imagerie&action=dashboard" class="btn btn-sm btn-info w-100">
                <i class="bi bi-arrow-right me-1"></i> Accéder à l'imagerie
            </a>
        </div>
    </div>
</div>
<?php endif; endif; ?>

<!-- ─── CARTE PHARMACIE ─── -->
<?php if (($has_pharmacie ?? false) || ($is_admin ?? false)):
    $show_carte = ($service_actif === 'ALL' || $service_actif === 'PHARMACIE');
    if ($show_carte): ?>
<div class="col-lg-4 col-md-6">
    <div class="card h-100 border-0 shadow-sm">
        <div class="card-header text-white" style="background:#198754; border:none;">
            <div class="d-flex align-items-center justify-content-between">
                <div><i class="bi bi-capsule me-2"></i><strong>Pharmacie</strong></div>
                <span class="badge bg-light text-dark"><?= (int)$pharmacie['total'] ?> total</span>
            </div>
        </div>
        <div class="card-body">
            <div class="row text-center g-2 mb-3">
                <div class="col-4">
                    <div class="p-2 rounded" style="background:#fff3cd;">
                        <div style="font-size:1.5rem;font-weight:700;color:#856404;"><?= (int)$pharmacie['en_attente'] ?></div>
                        <small class="text-muted">En attente</small>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:#cff4fc;">
                        <div style="font-size:1.5rem;font-weight:700;color:#055160;"><?= (int)$pharmacie['en_cours'] ?></div>
                        <small class="text-muted">En cours</small>
                    </div>
                </div>
                <div class="col-4">
                    <div class="p-2 rounded" style="background:#d1e7dd;">
                        <div style="font-size:1.5rem;font-weight:700;color:#0f5132;"><?= (int)$pharmacie['termines'] ?></div>
                        <small class="text-muted">Délivrées</small>
                    </div>
                </div>
            </div>
            <?php if ((int)$pharmacie['urgents'] > 0): ?>
            <div class="alert alert-danger py-2 mb-3" style="font-size:.85rem;">
                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                <strong><?= (int)$pharmacie['urgents'] ?></strong> préparation(s) urgente(s)
            </div>
            <?php endif; ?>
            <a href="index.php?page=pharmacie&action=dashboard" class="btn btn-sm btn-success w-100">
                <i class="bi bi-arrow-right me-1"></i> Accéder à la pharmacie
            </a>
        </div>
    </div>
</div>
<?php endif; endif; ?>
</div><!-- /row cartes -->

<!-- ========================================= -->
<!-- RAPPORT LABORATOIRE DU MOIS               -->
<!-- ========================================= -->
<?php if ($has_labo || $is_admin): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>
                    <i class="bi bi-bar-chart-line me-2" style="color:#6f42c1;"></i>
                    Rapport laboratoire — <?= $mois_texte ?>
                </strong>
                <a href="index.php?page=labo&action=rapport" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-arrow-right"></i> Statistiques détaillées
                </a>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Groupes (mois)</div>
                            <div class="h3 mb-0 fw-bold" style="color:#6f42c1;">
                                <?= number_format($total_groupes_mois) ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Délai moyen</div>
                            <div class="h3 mb-0 fw-bold" style="color:#0d6efd;">
                                <?php
                                if ($delai_moyen_dash >= 60) {
                                    $h = floor($delai_moyen_dash / 60);
                                    $m = $delai_moyen_dash % 60;
                                    echo $h . 'h' . ($m > 0 ? str_pad($m, 2, '0', STR_PAD_LEFT) : '');
                                } else {
                                    echo $delai_moyen_dash . ' min';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Taux complétion</div>
                            <div class="h3 mb-0 fw-bold" style="color:#198754;">
                                <?= $taux_completion_dash ?>%
                            </div>
                            <div class="progress mt-2" style="height:4px;">
                                <div class="progress-bar bg-success"
                                     style="width:<?= $taux_completion_dash ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================================= -->
<!-- RAPPORT IMAGERIE DU MOIS                  -->
<!-- ========================================= -->
<?php if ($has_imagerie || $is_admin): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>
                    <i class="bi bi-bar-chart-line me-2" style="color:#0dcaf0;"></i>
                    Rapport imagerie — <?= $mois_texte ?>
                </strong>
                <a href="index.php?page=imagerie&action=rapport"
                   class="btn btn-sm btn-outline-primary"
                   style="color:#0dcaf0;border-color:#0dcaf0;">
                    <i class="bi bi-arrow-right"></i> Statistiques détaillées
                </a>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Examens (mois)</div>
                            <div class="h3 mb-0 fw-bold" style="color:#0dcaf0;"><?= number_format($total_imagerie) ?></div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Délai moyen</div>
                            <div class="h3 mb-0 fw-bold" style="color:#0d6efd;"><?= $delai_imagerie ?>h</div>
                        </div>
                    </div>
                    <div class="col-md-4 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Taux complétion</div>
                            <div class="h3 mb-0 fw-bold" style="color:#198754;"><?= $taux_imagerie ?>%</div>
                            <div class="progress mt-2" style="height:4px;">
                                <div class="progress-bar bg-success" style="width:<?= $taux_imagerie ?>%"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ========================================= -->
<!-- RAPPORT PHARMACIE DU MOIS                 -->
<!-- ========================================= -->
<?php if ($has_pharmacie || $is_admin): ?>
<div class="row mt-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>
                    <i class="bi bi-bar-chart-line me-2" style="color:#198754;"></i>
                    Rapport pharmacie — <?= $mois_texte ?>
                </strong>
                <a href="index.php?page=pharmacie&action=rapport"
                   class="btn btn-sm btn-outline-primary"
                   style="color:#198754;border-color:#198754;">
                    <i class="bi bi-arrow-right"></i> Statistiques détaillées
                </a>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Prescriptions (mois)</div>
                            <div class="h3 mb-0 fw-bold" style="color:#198754;"><?= number_format($total_pharma) ?></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Taux complétion</div>
                            <div class="h3 mb-0 fw-bold" style="color:#10b981;"><?= $taux_pharma ?>%</div>
                            <div class="progress mt-2" style="height:4px;">
                                <div class="progress-bar bg-success" style="width:<?= $taux_pharma ?>%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Chiffre d'affaire</div>
                            <div class="h3 mb-0 fw-bold" style="color:#f59e0b;"><?= formatMoney($ca_pharma) ?></div>
                        </div>
                    </div>
                    <div class="col-md-3 col-6">
                        <div class="text-center p-3">
                            <div class="text-muted small">Délai moyen</div>
                            <div class="h3 mb-0 fw-bold" style="color:#0d6efd;"><?= $delai_pharma ?>h</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// ── Helper : badge statut partagé ─────────────────────────────────────────
function dashStatutBadge(string $statut): string {
    [$label, $cls] = match($statut) {
        'validation_technique' => ['Validé',       'bg-success'],
        'preleve'              => ['Prélevé',       'bg-info text-dark'],
        'attente_prelevement'  => ['Att. prélèv.',  'bg-warning text-dark'],
        'en_cours'             => ['En cours',      'bg-primary'],
        'termine', 'acheve'    => ['Terminé',       'bg-success'],
        'rejete'               => ['Rejeté',        'bg-danger'],
        default                => [htmlspecialchars($statut), 'bg-secondary'],
    };
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
}
?>

<!-- ========================================= -->
<!-- ACTIVITÉ RÉCENTE — 3 blocs (5 lignes/svc) -->
<!-- ========================================= -->
<div class="mt-4">
    <h6 class="text-muted mb-3" style="font-size:.8rem;letter-spacing:.05em;text-transform:uppercase;">
        <i class="bi bi-activity me-1"></i> Activité récente
        <span class="fw-normal ms-1">— 5 dernières actions par service</span>
    </h6>

    <div class="row g-4">

    <?php /* ─── BLOC LABORATOIRE ─────────────────────────────────── */ ?>
    <?php if (($has_labo ?? false) || ($is_admin ?? false)):
          if ($service_actif === 'ALL' || $service_actif === 'LABORATOIRE'): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2 d-flex align-items-center justify-content-between"
                 style="border-left:4px solid #6f42c1;">
                <span style="color:#6f42c1;font-weight:600;">
                    <i class="bi bi-droplet-fill me-2"></i>Laboratoire
                    <small class="text-muted fw-normal ms-1" style="font-size:.75rem;">
                        par groupe · <i class="bi bi-collection"></i>
                    </small>
                </span>
                <a href="index.php?page=labo&action=dashboard"
                   class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;">Voir tout</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:.84rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Groupe / Code</th>
                                <th>Patient</th>
                                <th>Examen(s)</th>
                                <th>Statut</th>
                                <th>Urgence</th>
                                <th>Dernière action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($activite_labo)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Aucune activité</td></tr>
                        <?php else: ?>
                        <?php foreach ($activite_labo as $wf):
                            $actes_raw  = $wf['acte_libelle'] ?? '—';
                            $nb_examens = (int)($wf['nb_examens'] ?? 0);
                        ?>
                        <tr>
                            <td>
                                <code style="font-weight:700;color:#0d6efd;">
                                    <?= htmlspecialchars($wf['code_reference']) ?>
                                </code>
                                <?php if ($nb_examens > 0): ?>
                                    <span class="badge bg-light text-muted border ms-1"
                                          title="<?= $nb_examens ?> examen(s) dans ce groupe"
                                          style="font-size:.68rem;">
                                        <?= $nb_examens ?> ex.
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(trim(($wf['patient_nom'] ?? '') . ' ' . ($wf['patient_prenom'] ?? ''))) ?></td>
                            <td style="max-width:240px;">
                                <?php if (mb_strlen($actes_raw) > 55): ?>
                                    <span class="text-muted" title="<?= htmlspecialchars($actes_raw) ?>"
                                          style="font-size:.82rem;">
                                        <?= htmlspecialchars(mb_substr($actes_raw, 0, 52)) ?>…
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size:.82rem;"><?= htmlspecialchars($actes_raw) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= dashStatutBadge($wf['statut_courant'] ?? '') ?></td>
                            <td>
                                <?php if (!empty($wf['urgence']) && $wf['urgence']): ?>
                                    <span class="badge bg-danger">URGENT</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Normal</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= formatDateTime($wf['derniere_action_date'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; endif; ?>

    <?php /* ─── BLOC IMAGERIE ──────────────────────────────────────── */ ?>
    <?php if (($has_imagerie ?? false) || ($is_admin ?? false)):
          if ($service_actif === 'ALL' || $service_actif === 'IMAGERIE'): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2 d-flex align-items-center justify-content-between"
                 style="border-left:4px solid #0dcaf0;">
                <span style="color:#0a8fa5;font-weight:600;">
                    <i class="bi bi-image-fill me-2"></i>Imagerie
                </span>
                <a href="index.php?page=imagerie&action=dashboard"
                   class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;">Voir tout</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:.84rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Patient</th>
                                <th>Acte</th>
                                <th>Statut</th>
                                <th>Urgence</th>
                                <th>Dernière action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($activite_imagerie)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Aucune activité</td></tr>
                        <?php else: ?>
                        <?php foreach ($activite_imagerie as $wf): ?>
                        <tr>
                            <td><code style="font-weight:700;color:#0d6efd;"><?= htmlspecialchars($wf['code_reference'] ?? '—') ?></code></td>
                            <td><?= htmlspecialchars(trim(($wf['patient_nom'] ?? '') . ' ' . ($wf['patient_prenom'] ?? ''))) ?></td>
                            <td style="max-width:220px;">
                                <?php $act = $wf['acte_libelle'] ?? '—'; ?>
                                <?php if (mb_strlen($act) > 45): ?>
                                    <span title="<?= htmlspecialchars($act) ?>"><?= htmlspecialchars(mb_substr($act,0,42)) ?>…</span>
                                <?php else: ?>
                                    <?= htmlspecialchars($act) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= dashStatutBadge($wf['statut_courant'] ?? '') ?></td>
                            <td>
                                <?php if (!empty($wf['urgence']) && $wf['urgence']): ?>
                                    <span class="badge bg-danger">URGENT</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Normal</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= formatDateTime($wf['derniere_action_date'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; endif; ?>

    <?php /* ─── BLOC PHARMACIE ──────────────────────────────────────── */ ?>
    <?php if (($has_pharmacie ?? false) || ($is_admin ?? false)):
          if ($service_actif === 'ALL' || $service_actif === 'PHARMACIE'): ?>
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-2 d-flex align-items-center justify-content-between"
                 style="border-left:4px solid #198754;">
                <span style="color:#198754;font-weight:600;">
                    <i class="bi bi-capsule me-2"></i>Pharmacie
                </span>
                <a href="index.php?page=pharmacie&action=dashboard"
                   class="btn btn-sm btn-outline-secondary" style="font-size:.75rem;">Voir tout</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:.84rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Patient</th>
                                <th>Acte</th>
                                <th>Statut</th>
                                <th>Urgence</th>
                                <th>Dernière action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($activite_pharmacie)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-3">Aucune activité</td></tr>
                        <?php else: ?>
                        <?php foreach ($activite_pharmacie as $wf): ?>
                        <tr>
                            <td><code style="font-weight:700;color:#0d6efd;"><?= htmlspecialchars($wf['code_reference'] ?? '—') ?></code></td>
                            <td><?= htmlspecialchars(trim(($wf['patient_nom'] ?? '') . ' ' . ($wf['patient_prenom'] ?? ''))) ?></td>
                            <td style="max-width:220px;">
                                <?php $act = $wf['acte_libelle'] ?? '—'; ?>
                                <?php if (mb_strlen($act) > 45): ?>
                                    <span title="<?= htmlspecialchars($act) ?>"><?= htmlspecialchars(mb_substr($act,0,42)) ?>…</span>
                                <?php else: ?>
                                    <?= htmlspecialchars($act) ?>
                                <?php endif; ?>
                            </td>
                            <td><?= dashStatutBadge($wf['statut_courant'] ?? '') ?></td>
                            <td>
                                <?php if (!empty($wf['urgence']) && $wf['urgence']): ?>
                                    <span class="badge bg-danger">URGENT</span>
                                <?php else: ?>
                                    <span class="badge bg-light text-muted border">Normal</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted"><?= formatDateTime($wf['derniere_action_date'] ?? '') ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; endif; ?>

    </div><!-- /row activités -->
</div><!-- /section activité récente -->