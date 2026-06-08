<?php
/**
 * Module Laboratoire - Dashboard
 *
 * ACTIVITÉ RÉCENTE : 1 ligne = 1 GROUPE (identique au tableau de bord général)
 *   Statut calculé dynamiquement depuis les échantillons du groupe :
 *     - Tous validation_technique → Validé
 *     - Au moins 1 preleve        → Prélevé
 *     - Sinon                     → Att. prélèv.
 *   15 groupes les plus récents.
 */

require_once __DIR__ . '/../../includes/labo_helpers.php';
$statut_labels = $GLOBALS['labo_statut_labels'];
$tube_colors   = $GLOBALS['labo_tube_colors'];

$DB = defined('DB_MAIN') ? DB_MAIN : 'csk_base';
$db            = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

// =============================================
// 1. STATISTIQUES PRINCIPALES (aujourd'hui)
// =============================================
$stats = [
    'total_jour' => 0, 'en_attente' => 0, 'en_cours'  => 0,
    'termines'   => 0, 'rejetes'    => 0, 'urgents'   => 0, 'en_retard' => 0,
];
try {
    $stmt = $conn_services->query("
        SELECT
            COUNT(*)                                                                         AS total,
            SUM(statut = 'attente_prelevement')                                              AS en_attente,
            SUM(statut = 'preleve')                                                          AS en_cours,
            SUM(statut = 'validation_technique')                                             AS termines,
            SUM(statut IN ('rejete','perdu'))                                                AS rejetes,
            SUM(urgence = 1 AND statut IN ('attente_prelevement','preleve'))                 AS urgents,
            SUM(TIMESTAMPDIFF(MINUTE, created_at, NOW()) > delai_theorique_min
                AND statut IN ('attente_prelevement','preleve'))                             AS en_retard
        FROM {$DB}.labo_echantillons
        WHERE deleted_at IS NULL AND idgroupe IS NOT NULL
          AND statut IN ('attente_prelevement','preleve','validation_technique')
          AND DATE(created_at) = CURDATE()
    ");
    $row = $stmt->fetch();
    if ($row) {
        foreach (['total_jour'=>'total','en_attente','en_cours','termines','rejetes','urgents','en_retard'] as $k => $v) {
            $key = is_string($k) ? $k : $v;
            $col = is_string($k) ? $v : $v;
            $stats[$key] = (int)($row[$col] ?? 0);
        }
    }
} catch (Exception $e) {
    error_log("[Labo Dashboard] Erreur stats jour: " . $e->getMessage());
}

// =============================================
// 2. TOTAUX GLOBAUX
// =============================================
$stats_global = ['total_actifs' => 0, 'en_attente' => 0, 'en_cours' => 0, 'urgents' => 0];
try {
    $stmt = $conn_services->query("
        SELECT COUNT(*) AS total,
               SUM(statut = 'attente_prelevement') AS en_attente,
               SUM(statut = 'preleve')             AS en_cours,
               SUM(urgence = 1 AND statut IN ('attente_prelevement','preleve')) AS urgents
        FROM {$DB}.labo_echantillons
        WHERE deleted_at IS NULL AND idgroupe IS NOT NULL
          AND statut IN ('attente_prelevement','preleve','validation_technique')
    ");
    $row = $stmt->fetch();
    if ($row) {
        $stats_global['total_actifs'] = (int)$row['total'];
        $stats_global['en_attente']   = (int)$row['en_attente'];
        $stats_global['en_cours']     = (int)$row['en_cours'];
        $stats_global['urgents']      = (int)$row['urgents'];
    }
} catch (Exception $e) {
    error_log("[Labo Dashboard] Erreur stats globales: " . $e->getMessage());
}

// =============================================
// 3. GROUPES URGENTS EN COURS
// =============================================
$urgents = [];
try {
    $ordre = "FIELD(le.statut,'attente_prelevement','preleve','validation_technique','annule','rejete','perdu')";
    $stmt  = $conn_services->query("
        SELECT lg.idgroupe, lg.code_groupe, lg.date_creation,
               p.nom AS patient_nom, p.prenom AS patient_prenom,
               COUNT(le.idechantillon) AS nb_examens,
               TIMESTAMPDIFF(MINUTE, lg.date_creation, NOW()) AS delai_min,
               ELT(MIN($ordre),'attente_prelevement','preleve','validation_technique','annule','rejete','perdu') AS statut_global,
               GROUP_CONCAT(a.libelle ORDER BY le.sous_numero SEPARATOR ' | ') AS examens_liste
        FROM labo_groupes_echantillons lg
        JOIN {$DB}.labo_echantillons le ON le.idgroupe = lg.idgroupe AND le.deleted_at IS NULL
        JOIN {$DB}.patient p ON p.idpatient = lg.idpatient
        LEFT JOIN {$DB}.actes_presc ap ON ap.idactes_presc = le.idactes_presc
        LEFT JOIN {$DB}.acte a         ON a.idacte = ap.idacte
        WHERE le.statut IN ('attente_prelevement','preleve','validation_technique')
        GROUP BY lg.idgroupe
        HAVING MAX(le.urgence) = 1 AND statut_global IN ('attente_prelevement','preleve')
        ORDER BY lg.date_creation ASC LIMIT 10
    ");
    $urgents = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("[Labo Dashboard] Erreur urgents: " . $e->getMessage());
}

// =============================================
// 4. GROUPES EN RETARD
// =============================================
$retards = [];
try {
    $ordre = "FIELD(le.statut,'attente_prelevement','preleve','validation_technique','annule','rejete','perdu')";
    $stmt  = $conn_services->query("
        SELECT lg.idgroupe, lg.code_groupe, lg.date_creation,
               p.nom AS patient_nom, p.prenom AS patient_prenom,
               COUNT(le.idechantillon) AS nb_examens,
               TIMESTAMPDIFF(MINUTE, lg.date_creation, NOW()) AS delai_min,
               MIN(le.delai_theorique_min) AS delai_theorique_min,
               (TIMESTAMPDIFF(MINUTE, lg.date_creation, NOW()) - MIN(le.delai_theorique_min)) AS retard_min,
               ELT(MIN($ordre),'attente_prelevement','preleve','validation_technique','annule','rejete','perdu') AS statut_global,
               GROUP_CONCAT(a.libelle ORDER BY le.sous_numero SEPARATOR ' | ') AS examens_liste
        FROM labo_groupes_echantillons lg
        JOIN {$DB}.labo_echantillons le ON le.idgroupe = lg.idgroupe AND le.deleted_at IS NULL
        JOIN {$DB}.patient p ON p.idpatient = lg.idpatient
        LEFT JOIN {$DB}.actes_presc ap ON ap.idactes_presc = le.idactes_presc
        LEFT JOIN {$DB}.acte a         ON a.idacte = ap.idacte
        WHERE le.statut IN ('attente_prelevement','preleve','validation_technique')
        GROUP BY lg.idgroupe
        HAVING statut_global IN ('attente_prelevement','preleve') AND retard_min > 0
        ORDER BY retard_min DESC LIMIT 10
    ");
    $retards = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("[Labo Dashboard] Erreur retards: " . $e->getMessage());
}

// =============================================
// 5. RÉPARTITION PAR STATUT
// =============================================
$repartition = [];
try {
    $stmt = $conn_services->query("
        SELECT statut, COUNT(*) AS nombre
        FROM {$DB}.labo_echantillons
        WHERE deleted_at IS NULL AND idgroupe IS NOT NULL
          AND statut IN ('attente_prelevement','preleve','validation_technique')
        GROUP BY statut ORDER BY nombre DESC
    ");
    $repartition = $stmt->fetchAll();
} catch (Exception $e) {
    error_log("[Labo Dashboard] Erreur répartition: " . $e->getMessage());
}

// =============================================
// 6. ACTIVITÉ RÉCENTE — 1 ligne = 1 GROUPE
//    Statut calculé depuis les échantillons du groupe.
//    15 groupes, triés par dernière mise à jour décroissante.
// =============================================
$activite_recente = [];
try {
    $stmt = $conn_services->query("
        SELECT
            lg.idgroupe,
            lg.code_groupe,
            CONCAT(p.nom, ' ', p.prenom)                                           AS patient_nom,
            GROUP_CONCAT(DISTINCT a.libelle ORDER BY a.libelle SEPARATOR ' · ')   AS acte_libelle,
            COUNT(le.idechantillon)                                                AS nb_examens,
            CASE
                WHEN SUM(le.statut != 'validation_technique') = 0 THEN 'validation_technique'
                WHEN SUM(le.statut = 'preleve')               > 0 THEN 'preleve'
                ELSE                                                    'attente_prelevement'
            END                                                                    AS statut_groupe,
            MAX(le.urgence)                                                        AS urgence,
            GREATEST(MAX(le.updated_at), lg.date_creation)                        AS derniere_action_date
        FROM labo_groupes_echantillons lg
        INNER JOIN {$DB}.labo_echantillons le
            ON  le.idgroupe   = lg.idgroupe
            AND le.deleted_at IS NULL
            AND le.statut     IN ('attente_prelevement','preleve','validation_technique')
        LEFT JOIN {$DB}.actes_presc ap ON ap.idactes_presc = le.idactes_presc
        LEFT JOIN {$DB}.acte        a  ON a.idacte         = ap.idacte
        LEFT JOIN {$DB}.patient     p  ON p.idpatient      = lg.idpatient
        GROUP BY lg.idgroupe
        ORDER BY derniere_action_date DESC
        LIMIT 15
    ");
    $activite_recente = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("[Labo Dashboard] Erreur activité groupes: " . $e->getMessage());
}
?>

<!-- ============================================ -->
<!-- DASHBOARD LABORATOIRE                       -->
<!-- ============================================ -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3 class="mb-0">
        <i class="bi bi-speedometer2 me-2" style="color:#0d6efd;"></i>
        Dashboard Laboratoire
    </h3>
    <div class="d-flex gap-2">
        <a href="index.php?page=labo&action=echantillons" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-list-ul me-1"></i>Liste
        </a>
        <a href="index.php?page=labo&action=rapport" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-bar-chart me-1"></i>Rapport
        </a>
    </div>
</div>

<!-- STATS AUJOURD'HUI -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div style="width:40px;height:40px;border-radius:10px;background:#e7f1ff;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-clipboard-data" style="font-size:1.2rem;color:#0d6efd;"></i>
                    </div>
                    <span class="text-muted" style="font-size:.75rem;">Aujourd'hui</span>
                </div>
                <div class="h2 mb-0 fw-bold"><?= number_format($stats['total_jour']) ?></div>
                <div class="text-muted" style="font-size:.85rem;">Échantillons</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div style="width:40px;height:40px;border-radius:10px;background:#fff3cd;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-hourglass-split" style="font-size:1.2rem;color:#f59e0b;"></i>
                    </div>
                    <a href="index.php?page=labo&action=echantillons&statut=attente_prelevement"
                       class="btn btn-sm btn-outline-warning" style="font-size:.7rem;">Voir</a>
                </div>
                <div class="h2 mb-0 fw-bold" style="color:#f59e0b;"><?= number_format($stats['en_attente']) ?></div>
                <div class="text-muted" style="font-size:.85rem;">En attente</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div style="width:40px;height:40px;border-radius:10px;background:#cff4fc;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-gear" style="font-size:1.2rem;color:#0dcaf0;"></i>
                    </div>
                    <a href="index.php?page=labo&action=echantillons"
                       class="btn btn-sm btn-outline-info" style="font-size:.7rem;">Voir</a>
                </div>
                <div class="h2 mb-0 fw-bold" style="color:#0dcaf0;"><?= number_format($stats['en_cours']) ?></div>
                <div class="text-muted" style="font-size:.85rem;">En cours</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div style="width:40px;height:40px;border-radius:10px;background:#d1e7dd;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-check-circle" style="font-size:1.2rem;color:#198754;"></i>
                    </div>
                    <a href="index.php?page=labo&action=echantillons&statut=validation_technique"
                       class="btn btn-sm btn-outline-success" style="font-size:.7rem;">Voir</a>
                </div>
                <div class="h2 mb-0 fw-bold" style="color:#198754;"><?= number_format($stats['termines']) ?></div>
                <div class="text-muted" style="font-size:.85rem;">Validés</div>
            </div>
        </div>
    </div>
</div>

<!-- ALERTES + RÉPARTITION -->
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <?php if (!empty($urgents)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex align-items-center justify-content-between">
                <strong class="text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Groupes urgents (<?= count($urgents) ?>)</strong>
                <a href="index.php?page=labo&action=echantillons&urgence=1" class="btn btn-sm btn-outline-danger">Voir tous</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                        <thead class="table-light">
                            <tr><th>Groupe</th><th>Patient</th><th>Examen(s)</th><th>Statut</th><th>Délai</th><th class="text-center">Action</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($urgents as $u):
                            $si = $statut_labels[$u['statut_global'] ?? 'attente_prelevement'] ?? ['label'=>$u['statut_global'],'color'=>'#6c757d','bg'=>'#e9ecef'];
                            $ex = explode(' | ', $u['examens_liste'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <i class="bi bi-exclamation-triangle-fill text-danger me-1" style="font-size:.75rem;"></i>
                                <code style="font-weight:700;color:#0d6efd;"><?= htmlspecialchars($u['code_groupe']) ?></code>
                                <span class="badge ms-1" style="background:#ede8f5;color:#5a3e9e;font-size:.65rem;"><?= $u['nb_examens'] ?></span>
                            </td>
                            <td><?= htmlspecialchars(trim($u['patient_nom'].' '.$u['patient_prenom'])) ?></td>
                            <td style="max-width:200px;"><small class="text-muted">
                                <?= htmlspecialchars(implode(', ', array_slice($ex, 0, 2))) ?>
                                <?= count($ex)>2 ? ' <span class="text-muted">+'.(count($ex)-2).'</span>' : '' ?>
                            </small></td>
                            <td><span class="badge" style="background:<?= $si['bg'] ?>;color:<?= $si['color'] ?>;"><?= htmlspecialchars($si['label']) ?></span></td>
                            <td style="font-size:.82rem;color:#6c757d;"><?= formatLaboDelai((int)$u['delai_min']) ?></td>
                            <td class="text-center">
                                <a href="index.php?page=labo&action=workflow&code=<?= urlencode($u['code_groupe']) ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-right"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($retards)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <strong class="text-warning"><i class="bi bi-clock-history me-2"></i>Groupes en retard (<?= count($retards) ?>)</strong>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem;">
                        <thead class="table-light">
                            <tr><th>Groupe</th><th>Patient</th><th>Examen(s)</th><th>Statut</th><th>Retard</th><th class="text-center">Action</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($retards as $r):
                            $si = $statut_labels[$r['statut_global'] ?? 'attente_prelevement'] ?? ['label'=>$r['statut_global'],'color'=>'#6c757d','bg'=>'#e9ecef'];
                            $ex = explode(' | ', $r['examens_liste'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <code style="font-weight:700;color:#0d6efd;"><?= htmlspecialchars($r['code_groupe']) ?></code>
                                <span class="badge ms-1" style="background:#ede8f5;color:#5a3e9e;font-size:.65rem;"><?= $r['nb_examens'] ?></span>
                            </td>
                            <td><?= htmlspecialchars(trim($r['patient_nom'].' '.$r['patient_prenom'])) ?></td>
                            <td style="max-width:180px;"><small class="text-muted">
                                <?= htmlspecialchars(implode(', ', array_slice($ex, 0, 2))) ?>
                                <?= count($ex)>2 ? ' <span class="text-muted">+'.(count($ex)-2).'</span>' : '' ?>
                            </small></td>
                            <td><span class="badge" style="background:<?= $si['bg'] ?>;color:<?= $si['color'] ?>;"><?= htmlspecialchars($si['label']) ?></span></td>
                            <td><span class="text-danger fw-bold" style="font-size:.85rem;">+<?= formatLaboDelai((int)$r['retard_min']) ?></span></td>
                            <td class="text-center">
                                <a href="index.php?page=labo&action=workflow&code=<?= urlencode($r['code_groupe']) ?>" class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-right"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($urgents) && empty($retards)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-check-circle" style="font-size:3rem;color:#198754;"></i>
                <p class="mt-2 mb-0">Aucun groupe urgent ou en retard. Tout est sous contrôle.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- RÉPARTITION -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white">
                <strong><i class="bi bi-pie-chart me-2"></i>Répartition par statut</strong>
            </div>
            <div class="card-body">
                <?php if (!empty($repartition)):
                    $total_rep = array_sum(array_column($repartition, 'nombre'));
                    foreach ($repartition as $rep):
                        $info = $statut_labels[$rep['statut']] ?? ['label'=>$rep['statut'],'color'=>'#6c757d','bg'=>'#e9ecef'];
                        $pct  = $total_rep > 0 ? round(($rep['nombre']/$total_rep)*100,1) : 0;
                ?>
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <div class="d-flex align-items-center gap-2" style="min-width:0;">
                        <span style="display:inline-block;width:12px;height:12px;border-radius:3px;background:<?= $info['color'] ?>;flex-shrink:0;"></span>
                        <span style="font-size:.85rem;"><?= htmlspecialchars($info['label']) ?></span>
                    </div>
                    <div class="text-end" style="flex-shrink:0;min-width:80px;">
                        <strong style="font-size:.85rem;"><?= $rep['nombre'] ?></strong>
                        <span class="text-muted" style="font-size:.75rem;">(<?= $pct ?>%)</span>
                    </div>
                </div>
                <div class="progress mb-3" style="height:6px;">
                    <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $info['color'] ?>;"></div>
                </div>
                <?php endforeach; else: ?>
                    <p class="text-muted text-center mb-0">Aucune donnée</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ============================================ -->
<!-- ACTIVITÉ RÉCENTE — par GROUPE                -->
<!-- ============================================ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <strong>
            <i class="bi bi-activity me-2"></i>Activité récente
            <small class="text-muted fw-normal ms-2" style="font-size:.75rem;">
                <i class="bi bi-collection me-1"></i>par groupe · 15 derniers
            </small>
        </strong>
        <a href="index.php?page=labo&action=echantillons" class="btn btn-sm btn-outline-secondary">Tout voir</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.85rem;">
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
                <?php if (empty($activite_recente)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Aucune activité récente</td></tr>
                <?php else: ?>
                <?php foreach ($activite_recente as $wf):
                    $statut = $wf['statut_groupe'] ?? 'attente_prelevement';
                    $si     = $statut_labels[$statut] ?? ['label'=>$statut,'color'=>'#6c757d','bg'=>'#e9ecef'];
                    $actes  = $wf['acte_libelle'] ?? '—';
                    $nb     = (int)($wf['nb_examens'] ?? 0);
                ?>
                <tr>
                    <td>
                        <code style="font-weight:700;color:#0d6efd;"><?= htmlspecialchars($wf['code_groupe'] ?? '—') ?></code>
                        <?php if ($nb > 0): ?>
                            <span class="badge ms-1"
                                  style="background:#ede8f5;color:#5a3e9e;font-size:.65rem;"
                                  title="<?= $nb ?> examen(s)">
                                <?= $nb ?> ex.
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($wf['patient_nom'] ?? '—') ?></td>
                    <td style="max-width:260px;">
                        <?php if (mb_strlen($actes) > 60): ?>
                            <span class="text-muted" title="<?= htmlspecialchars($actes) ?>" style="font-size:.82rem;">
                                <?= htmlspecialchars(mb_substr($actes, 0, 57)) ?>…
                            </span>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:.82rem;"><?= htmlspecialchars($actes) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge" style="background:<?= $si['bg'] ?>;color:<?= $si['color'] ?>;">
                            <?= htmlspecialchars($si['label']) ?>
                        </span>
                    </td>
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

<!-- Liens rapides -->
<div class="row g-3 mt-3">
    <div class="col-md-4">
        <a href="index.php?page=labo&action=echantillons" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:45px;height:45px;border-radius:10px;background:#e2d9f3;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-list-ul" style="font-size:1.2rem;color:#6f42c1;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="color:#333;">Liste des échantillons</div>
                    <small class="text-muted">Voir tous les échantillons</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="index.php?page=labo&action=workflow" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:45px;height:45px;border-radius:10px;background:#cff4fc;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-diagram-3" style="font-size:1.2rem;color:#0dcaf0;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="color:#333;">Workflow</div>
                    <small class="text-muted">Gérer les transitions de statut</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="index.php?page=labo&action=resultats" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:45px;height:45px;border-radius:10px;background:#d1e7dd;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-file-earmark-medical" style="font-size:1.2rem;color:#198754;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="color:#333;">Résultats</div>
                    <small class="text-muted">Saisie et validation</small>
                </div>
            </div>
        </a>
    </div>
</div>