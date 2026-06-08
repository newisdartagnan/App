<?php
/**
 * Module Imagerie - Dashboard
 * 
 * Vue d'ensemble : statistiques temps reel, planning du jour,
 * examens urgents/en retard, repartition par statut.
 */

require_once __DIR__ . '/../../includes/imagerie_helpers.php';
$statut_labels   = $GLOBALS['imagerie_statut_labels'];
$priorite_colors = $GLOBALS['imagerie_priorite_colors'];

$db = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

// =============================================
// STATISTIQUES GLOBALES
// =============================================

// Total examens aujourd'hui
$today = date('Y-m-d');

$stmt = $conn_services->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN statut NOT IN ('transmis','annule') THEN 1 ELSE 0 END) as en_cours,
        SUM(CASE WHEN statut = 'transmis' THEN 1 ELSE 0 END) as termines,
        SUM(CASE WHEN urgence = 1 AND statut NOT IN ('transmis','annule') THEN 1 ELSE 0 END) as urgences,
        SUM(CASE WHEN statut = 'annule' THEN 1 ELSE 0 END) as annules,
        SUM(CASE WHEN statut = 'programme' THEN 1 ELSE 0 END) as programmes,
        SUM(CASE WHEN statut IN ('en_acquisition','acquisition_terminee','en_reconstruction') THEN 1 ELSE 0 END) as en_acquisition,
        SUM(CASE WHEN statut IN ('en_interpretation','compte_rendu_fait') THEN 1 ELSE 0 END) as en_interpretation,
        SUM(CASE WHEN statut IN ('validation_radiologue','validation_chef') THEN 1 ELSE 0 END) as en_validation
    FROM imagerie_examens
    WHERE DATE(date_rdv) = ? OR DATE(created_at) = ?
");
$stmt->execute([$today, $today]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Repartition par statut (tous examens actifs)
$stmt = $conn_services->query("
    SELECT statut, COUNT(*) as nb
    FROM imagerie_examens
    WHERE statut NOT IN ('transmis','annule')
    GROUP BY statut
    ORDER BY FIELD(statut, 'programme','accueil','en_preparation','en_acquisition','acquisition_terminee','en_reconstruction','en_interpretation','compte_rendu_fait','validation_radiologue','validation_chef')
");
$repartition = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// EXAMENS URGENTS / EN RETARD
// =============================================

$stmt = $conn_services->prepare("
    SELECT e.*, 
           TIMESTAMPDIFF(MINUTE, e.date_rdv, NOW()) as delai_minutes
    FROM imagerie_examens e
    WHERE e.statut NOT IN ('transmis','annule')
      AND (e.urgence = 1 OR (e.date_rdv IS NOT NULL AND TIMESTAMPDIFF(MINUTE, e.date_rdv, NOW()) > IFNULL(e.duree_estimee_min, 60)))
    ORDER BY e.priorite DESC, e.date_rdv ASC
    LIMIT 10
");
$stmt->execute();
$urgents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recuperer les noms patients pour les urgents
$patients_urgents = [];
if (!empty($urgents)) {
    $ids = array_unique(array_column($urgents, 'idpatient'));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom, sexe FROM patient WHERE idpatient IN ($placeholders)");
        $stmt_p->execute($ids);
        foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $patients_urgents[$p['idpatient']] = $p;
        }
    }
}

// =============================================
// PLANNING DU JOUR (prochains examens)
// =============================================

$stmt = $conn_services->prepare("
    SELECT e.*
    FROM imagerie_examens e
    WHERE DATE(e.date_rdv) = ?
      AND e.statut NOT IN ('transmis','annule')
    ORDER BY e.date_rdv ASC
    LIMIT 15
");
$stmt->execute([$today]);
$planning = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Patients du planning
$patients_planning = [];
if (!empty($planning)) {
    $ids = array_unique(array_column($planning, 'idpatient'));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom, sexe FROM patient WHERE idpatient IN ($placeholders)");
        $stmt_p->execute($ids);
        foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $patients_planning[$p['idpatient']] = $p;
        }
    }
}

// =============================================
// DERNIERE ACTIVITE
// =============================================

$stmt = $conn_services->query("
    SELECT h.*, e.code_examen, e.type_examen, e.idpatient
    FROM imagerie_workflow_history h
    JOIN imagerie_examens e ON e.idexamen = h.idexamen
    ORDER BY h.created_at DESC
    LIMIT 8
");
$activite = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Patients de l'activite
$patients_activite = [];
if (!empty($activite)) {
    $ids = array_unique(array_column($activite, 'idpatient'));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom FROM patient WHERE idpatient IN ($placeholders)");
        $stmt_p->execute($ids);
        foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $patients_activite[$p['idpatient']] = $p;
        }
    }
}

// Noms utilisateurs activite
$users_activite = [];
if (!empty($activite)) {
    $uids = array_unique(array_column($activite, 'idutilisateur'));
    if (!empty($uids)) {
        $placeholders = implode(',', array_fill(0, count($uids), '?'));
        $stmt_u = $conn_base->prepare("SELECT idutilisateur, nom, prenom FROM utilisateur WHERE idutilisateur IN ($placeholders)");
        $stmt_u->execute($uids);
        foreach ($stmt_u->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $users_activite[$u['idutilisateur']] = $u;
        }
    }
}
?>

<!-- =============================================
     DASHBOARD IMAGERIE
     ============================================= -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1" style="font-weight:700; color:#0d6efd;">
            <i class="bi bi-camera me-2"></i>Imagerie Medicale
        </h2>
        <p class="text-muted mb-0">Tableau de bord — <?= date('d/m/Y') ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php?page=imagerie&action=examens" class="btn btn-outline-primary">
            <i class="bi bi-list-ul me-1"></i>Liste examens
        </a>
        <a href="index.php?page=imagerie&action=workflow" class="btn btn-primary">
            <i class="bi bi-kanban me-1"></i>Workflow
        </a>
    </div>
</div>

<!-- CARTES STATISTIQUES -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div style="font-size:2rem; font-weight:700; color:#0d6efd;"><?= (int)$stats['total'] ?></div>
                <div class="text-muted" style="font-size:0.85rem;">Total aujourd'hui</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div style="font-size:2rem; font-weight:700; color:#fd7e14;"><?= (int)$stats['en_cours'] ?></div>
                <div class="text-muted" style="font-size:0.85rem;">En cours</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div style="font-size:2rem; font-weight:700; color:#198754;"><?= (int)$stats['termines'] ?></div>
                <div class="text-muted" style="font-size:0.85rem;">Termines</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div style="font-size:2rem; font-weight:700; color:#dc3545;"><?= (int)$stats['urgences'] ?></div>
                <div class="text-muted" style="font-size:0.85rem;">Urgences actives</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center">
                <div style="font-size:2rem; font-weight:700; color:#6c757d;"><?= (int)$stats['programmes'] ?></div>
                <div class="text-muted" style="font-size:0.85rem;">Programmes</div>
            </div>
        </div>
    </div>
</div>

<!-- MINI STATS PIPELINE -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:48px; height:48px; background:#cff4fc;">
                    <i class="bi bi-camera" style="font-size:1.4rem; color:#0dcaf0;"></i>
                </div>
                <div>
                    <div style="font-size:1.5rem; font-weight:700;"><?= (int)$stats['en_acquisition'] ?></div>
                    <div class="text-muted" style="font-size:0.8rem;">En acquisition</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:48px; height:48px; background:#f7d6e6;">
                    <i class="bi bi-search" style="font-size:1.4rem; color:#d63384;"></i>
                </div>
                <div>
                    <div style="font-size:1.5rem; font-weight:700;"><?= (int)$stats['en_interpretation'] ?></div>
                    <div class="text-muted" style="font-size:0.8rem;">En interpretation / CR</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:48px; height:48px; background:#d1e7dd;">
                    <i class="bi bi-patch-check" style="font-size:1.4rem; color:#198754;"></i>
                </div>
                <div>
                    <div style="font-size:1.5rem; font-weight:700;"><?= (int)$stats['en_validation'] ?></div>
                    <div class="text-muted" style="font-size:0.8rem;">En validation</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- COLONNE GAUCHE : Urgents + Planning -->
    <div class="col-lg-8">
        
        <!-- EXAMENS URGENTS / EN RETARD -->
        <?php if (!empty($urgents)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 d-flex align-items-center gap-2" style="padding:1rem 1.25rem;">
                <i class="bi bi-exclamation-triangle-fill text-danger"></i>
                <strong>Examens urgents / en retard</strong>
                <span class="badge bg-danger ms-auto"><?= count($urgents) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.85rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Patient</th>
                                <th>Type examen</th>
                                <th>Priorite</th>
                                <th>Statut</th>
                                <th>Delai</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($urgents as $u): 
                                $pat = $patients_urgents[$u['idpatient']] ?? null;
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($u['code_examen']) ?></code></td>
                                <td>
                                    <?php if ($pat): ?>
                                        <strong><?= htmlspecialchars($pat['nom'] . ' ' . $pat['prenom']) ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">Patient #<?= $u['idpatient'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($u['type_examen'] ?? '-') ?></td>
                                <td><?= getImageriePrioriteBadge($u['priorite'] ?? 'programme') ?></td>
                                <td><?= getImagerieStatutBadge($u['statut']) ?></td>
                                <td>
                                    <?php 
                                    $delai = (int)$u['delai_minutes'];
                                    $color = $delai > 120 ? '#dc3545' : '#fd7e14';
                                    ?>
                                    <span style="color:<?= $color ?>; font-weight:600;">
                                        <?= formatImagerieDelai($delai) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="index.php?page=imagerie&action=workflow&code=<?= urlencode($u['code_examen']) ?>" 
                                       class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- PLANNING DU JOUR -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 d-flex align-items-center gap-2" style="padding:1rem 1.25rem;">
                <i class="bi bi-calendar-day" style="color:#0d6efd;"></i>
                <strong>Planning du jour</strong>
                <span class="badge bg-primary ms-auto"><?= count($planning) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($planning)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-calendar-x" style="font-size:2rem;"></i>
                        <p class="mt-2 mb-0">Aucun examen programme aujourd'hui</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="font-size:0.85rem;">
                            <thead class="table-light">
                                <tr>
                                    <th>Heure</th>
                                    <th>Code</th>
                                    <th>Patient</th>
                                    <th>Type examen</th>
                                    <th>Salle</th>
                                    <th>Statut</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($planning as $pl): 
                                    $pat = $patients_planning[$pl['idpatient']] ?? null;
                                    $heure = $pl['date_rdv'] ? date('H:i', strtotime($pl['date_rdv'])) : '-';
                                    $past = $pl['date_rdv'] && strtotime($pl['date_rdv']) < time();
                                ?>
                                <tr style="<?= $past && $pl['statut'] === 'programme' ? 'background:#fff3cd;' : '' ?>">
                                    <td>
                                        <strong style="<?= $past && $pl['statut'] === 'programme' ? 'color:#dc3545;' : '' ?>">
                                            <?= $heure ?>
                                        </strong>
                                    </td>
                                    <td><code><?= htmlspecialchars($pl['code_examen']) ?></code></td>
                                    <td>
                                        <?php if ($pat): ?>
                                            <?= htmlspecialchars($pat['nom'] . ' ' . $pat['prenom']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Patient #<?= $pl['idpatient'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($pl['type_examen'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($pl['salle'] ?? '-') ?></td>
                                    <td><?= getImagerieStatutBadge($pl['statut']) ?></td>
                                    <td>
                                        <a href="index.php?page=imagerie&action=workflow&code=<?= urlencode($pl['code_examen']) ?>" 
                                           class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-arrow-right"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- COLONNE DROITE : Repartition + Activite -->
    <div class="col-lg-4">

        <!-- REPARTITION PAR STATUT -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0" style="padding:1rem 1.25rem;">
                <strong><i class="bi bi-bar-chart me-2"></i>Repartition par statut</strong>
            </div>
            <div class="card-body">
                <?php if (empty($repartition)): ?>
                    <p class="text-muted text-center mb-0">Aucun examen actif</p>
                <?php else: ?>
                    <?php 
                    $total_actifs = array_sum(array_column($repartition, 'nb'));
                    foreach ($repartition as $r): 
                        $info = $statut_labels[$r['statut']] ?? ['label' => $r['statut'], 'color' => '#6c757d', 'bg' => '#e9ecef'];
                        $pct = $total_actifs > 0 ? round(($r['nb'] / $total_actifs) * 100) : 0;
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1" style="font-size:0.8rem;">
                            <span><?= htmlspecialchars($info['label']) ?></span>
                            <span style="font-weight:600; color:<?= $info['color'] ?>;"><?= $r['nb'] ?></span>
                        </div>
                        <div class="progress" style="height:6px;">
                            <div class="progress-bar" style="width:<?= $pct ?>%; background:<?= $info['color'] ?>;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- DERNIERE ACTIVITE -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0" style="padding:1rem 1.25rem;">
                <strong><i class="bi bi-clock-history me-2"></i>Derniere activite</strong>
            </div>
            <div class="card-body p-0">
                <?php if (empty($activite)): ?>
                    <div class="text-center text-muted py-4">Aucune activite recente</div>
                <?php else: ?>
                    <div class="list-group list-group-flush" style="font-size:0.82rem;">
                        <?php foreach ($activite as $act):
                            $user = $users_activite[$act['idutilisateur']] ?? null;
                            $pat = $patients_activite[$act['idpatient']] ?? null;
                            $time_ago = '';
                            $diff = time() - strtotime($act['created_at']);
                            if ($diff < 3600) $time_ago = floor($diff / 60) . ' min';
                            elseif ($diff < 86400) $time_ago = floor($diff / 3600) . 'h';
                            else $time_ago = floor($diff / 86400) . 'j';
                        ?>
                        <div class="list-group-item px-3 py-2">
                            <div class="d-flex justify-content-between">
                                <strong><?= htmlspecialchars($act['action']) ?></strong>
                                <small class="text-muted"><?= $time_ago ?></small>
                            </div>
                            <div class="text-muted mt-1">
                                <code style="font-size:0.75rem;"><?= htmlspecialchars($act['code_examen']) ?></code>
                                <?php if ($pat): ?>
                                    — <?= htmlspecialchars($pat['nom'] . ' ' . $pat['prenom']) ?>
                                <?php endif; ?>
                            </div>
                            <div class="mt-1">
                                <?php if ($act['ancien_statut']): ?>
                                    <?= getImagerieStatutBadge($act['ancien_statut']) ?>
                                    <i class="bi bi-arrow-right mx-1" style="font-size:0.7rem;"></i>
                                <?php endif; ?>
                                <?= getImagerieStatutBadge($act['nouveau_statut']) ?>
                            </div>
                            <?php if ($user): ?>
                                <small class="text-muted">par <?= htmlspecialchars($user['prenom'] . ' ' . $user['nom']) ?></small>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
