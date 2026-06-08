<?php
/**
 * Module Imagerie - Dashboard
 * 
 * Vue d'ensemble : statistiques temps reel, planning du jour,
 * examens urgents/en retard, repartition par statut.
 * 
 * VERSION CORRIGÉE avec les nouveaux statuts simplifiés
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

// ✅ CORRIGÉ : Utilisation des nouveaux statuts simplifiés
$stmt = $conn_services->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN statut NOT IN ('cr_valide','annule') THEN 1 ELSE 0 END) as en_cours,
        SUM(CASE WHEN statut = 'cr_valide' THEN 1 ELSE 0 END) as termines,
        SUM(CASE WHEN urgence = 1 AND statut NOT IN ('cr_valide','annule') THEN 1 ELSE 0 END) as urgences,
        SUM(CASE WHEN statut = 'annule' THEN 1 ELSE 0 END) as annules,
        SUM(CASE WHEN statut = 'a_faire' THEN 1 ELSE 0 END) as a_faire,
        SUM(CASE WHEN statut IN ('en_cours_examen','sortie_examen') THEN 1 ELSE 0 END) as en_cours_examen,
        SUM(CASE WHEN statut = 'cr_en_cours' THEN 1 ELSE 0 END) as cr_en_cours,
        SUM(CASE WHEN statut = 'cr_a_valider' THEN 1 ELSE 0 END) as cr_a_valider
    FROM imagerie_examens
    WHERE DATE(date_rdv) = ? OR DATE(created_at) = ?
");
$stmt->execute([$today, $today]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Valeurs par défaut si null
$stats = array_map(function($val) { return $val ?? 0; }, $stats);

// Repartition par statut (tous examens actifs)
$stmt = $conn_services->query("
    SELECT statut, COUNT(*) as nb
    FROM imagerie_examens
    WHERE statut NOT IN ('annule')
    GROUP BY statut
    ORDER BY FIELD(statut, 'a_faire','en_cours_examen','sortie_examen','cr_en_cours','cr_a_valider','cr_valide')
");
$repartition = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// EXAMENS URGENTS / EN RETARD
// =============================================

// ✅ CORRIGÉ : Utilisation des nouveaux statuts
$stmt = $conn_services->prepare("
    SELECT e.*, 
           TIMESTAMPDIFF(MINUTE, e.date_rdv, NOW()) as delai_minutes,
           p.nom as patient_nom,
           p.prenom as patient_prenom
    FROM imagerie_examens e
    LEFT JOIN csk_base.patient p ON e.idpatient = p.idpatient
    WHERE e.statut NOT IN ('cr_valide','annule')
      AND (e.urgence = 1 OR (e.date_rdv IS NOT NULL AND TIMESTAMPDIFF(MINUTE, e.date_rdv, NOW()) > IFNULL(e.duree_estimee_min, 60)))
    ORDER BY e.priorite DESC, e.date_rdv ASC
    LIMIT 10
");
$stmt->execute();
$urgents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ✅ On n'a plus besoin de requête séparée pour les patients car on a fait un LEFT JOIN
$patients_urgents = [];
foreach ($urgents as $u) {
    if (!empty($u['patient_nom'])) {
        $patients_urgents[$u['idpatient']] = [
            'nom' => $u['patient_nom'],
            'prenom' => $u['patient_prenom']
        ];
    }
}

// =============================================
// PLANNING DU JOUR (prochains examens)
// =============================================

$stmt = $conn_services->prepare("
    SELECT e.*, p.nom as patient_nom, p.prenom as patient_prenom
    FROM imagerie_examens e
    LEFT JOIN csk_base.patient p ON e.idpatient = p.idpatient
    WHERE DATE(e.date_rdv) = ?
      AND e.statut NOT IN ('cr_valide','annule')
    ORDER BY e.date_rdv ASC
    LIMIT 15
");
$stmt->execute([$today]);
$planning = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Patients du planning - déjà inclus dans la requête
$patients_planning = [];
foreach ($planning as $pl) {
    if (!empty($pl['patient_nom'])) {
        $patients_planning[$pl['idpatient']] = [
            'nom' => $pl['patient_nom'],
            'prenom' => $pl['patient_prenom']
        ];
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
        <a href="index.php?page=imagerie&action=planning" class="btn btn-outline-info">
            <i class="bi bi-calendar-week me-1"></i>Planning
        </a>
    </div>
</div>

<!-- CARTES STATISTIQUES PRINCIPALES -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #0d6efd !important;">
            <div class="card-body text-center">
                <div style="font-size:2rem; font-weight:700; color:#0d6efd;"><?= (int)$stats['total'] ?></div>
                <div class="text-muted" style="font-size:0.85rem;">Total aujourd'hui</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #fd7e14 !important;">
            <div class="card-body text-center">
                <div style="font-size:2rem; font-weight:700; color:#fd7e14;"><?= (int)$stats['en_cours'] ?></div>
                <div class="text-muted" style="font-size:0.85rem;">En cours</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #198754 !important;">
            <div class="card-body text-center">
                <div style="font-size:2rem; font-weight:700; color:#198754;"><?= (int)$stats['termines'] ?></div>
                <div class="text-muted" style="font-size:0.85rem;">Terminés</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left: 4px solid #dc3545 !important;">
            <div class="card-body text-center">
                <div style="font-size:2rem; font-weight:700; color:#dc3545;"><?= (int)$stats['urgences'] ?></div>
                <div class="text-muted" style="font-size:0.85rem;">Urgences actives</div>
            </div>
        </div>
    </div>
</div>

<!-- STATS DÉTAILLÉES DU PIPELINE -->
<div class="row g-3 mb-4">
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-calendar-event fs-3" style="color: #6c757d;"></i>
                    <div>
                        <div class="small text-muted">À faire</div>
                        <div class="h5 mb-0 fw-bold"><?= (int)$stats['a_faire'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-camera fs-3" style="color: #0d6efd;"></i>
                    <div>
                        <div class="small text-muted">En cours examen</div>
                        <div class="h5 mb-0 fw-bold"><?= (int)$stats['en_cours_examen'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-pencil-square fs-3" style="color: #fd7e14;"></i>
                    <div>
                        <div class="small text-muted">CR en cours</div>
                        <div class="h5 mb-0 fw-bold"><?= (int)$stats['cr_en_cours'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-patch-check fs-3" style="color: #6f42c1;"></i>
                    <div>
                        <div class="small text-muted">CR à valider</div>
                        <div class="h5 mb-0 fw-bold"><?= (int)$stats['cr_a_valider'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-send fs-3" style="color: #198754;"></i>
                    <div>
                        <div class="small text-muted">Terminés</div>
                        <div class="h5 mb-0 fw-bold"><?= (int)$stats['termines'] ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-x-circle fs-3" style="color: #dc3545;"></i>
                    <div>
                        <div class="small text-muted">Annulés</div>
                        <div class="h5 mb-0 fw-bold"><?= (int)$stats['annules'] ?></div>
                    </div>
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
            <div class="card-header bg-white d-flex align-items-center justify-content-between py-3">
                <div>
                    <i class="bi bi-exclamation-triangle-fill text-danger me-2"></i>
                    <strong>Examens urgents / en retard</strong>
                </div>
                <span class="badge bg-danger"><?= count($urgents) ?></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:0.85rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Patient</th>
                                <th>Type examen</th>
                                <th>Priorité</th>
                                <th>Statut</th>
                                <th>Délai</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($urgents as $u): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($u['code_examen']) ?></code></td>
                                <td>
                                    <?php if (!empty($u['patient_nom'])): ?>
                                        <strong><?= htmlspecialchars($u['patient_nom'] . ' ' . $u['patient_prenom']) ?></strong>
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
                                       class="btn btn-sm btn-outline-primary" title="Workflow">
                                        <i class="bi bi-diagram-3"></i>
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
            <div class="card-header bg-white d-flex align-items-center justify-content-between py-3">
                <div>
                    <i class="bi bi-calendar-day text-primary me-2"></i>
                    <strong>Planning du jour</strong>
                </div>
                <span class="badge bg-primary"><?= count($planning) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($planning)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="bi bi-calendar-x fs-1"></i>
                        <p class="mt-2 mb-0">Aucun examen programmé aujourd'hui</p>
                        <a href="index.php?page=imagerie&action=planning" class="btn btn-sm btn-outline-primary mt-3">
                            Voir le planning
                        </a>
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
                                    $heure = $pl['date_rdv'] ? date('H:i', strtotime($pl['date_rdv'])) : '-';
                                    $past = $pl['date_rdv'] && strtotime($pl['date_rdv']) < time();
                                ?>
                                <tr style="<?= $past && $pl['statut'] === 'a_faire' ? 'background:#fff3cd;' : '' ?>">
                                    <td>
                                        <strong style="<?= $past && $pl['statut'] === 'a_faire' ? 'color:#dc3545;' : '' ?>">
                                            <?= $heure ?>
                                        </strong>
                                    </td>
                                    <td><code><?= htmlspecialchars($pl['code_examen']) ?></code></td>
                                    <td>
                                        <?php if (!empty($pl['patient_nom'])): ?>
                                            <?= htmlspecialchars($pl['patient_nom'] . ' ' . $pl['patient_prenom']) ?>
                                        <?php else: ?>
                                            <span class="text-muted">Patient #<?= $pl['idpatient'] ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($pl['type_examen'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($pl['salle'] ?? '-') ?></td>
                                    <td><?= getImagerieStatutBadge($pl['statut']) ?></td>
                                    <td>
                                        <a href="index.php?page=imagerie&action=workflow&code=<?= urlencode($pl['code_examen']) ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Workflow">
                                            <i class="bi bi-diagram-3"></i>
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

    <!-- COLONNE DROITE : Répartition + Activité -->
    <div class="col-lg-4">
        <!-- RÉPARTITION PAR STATUT -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <strong><i class="bi bi-pie-chart me-2"></i>Répartition par statut</strong>
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

        <!-- DERNIÈRE ACTIVITÉ -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <strong><i class="bi bi-clock-history me-2"></i>Dernière activité</strong>
            </div>
            <div class="card-body p-0">
                <?php if (empty($activite)): ?>
                    <div class="text-center text-muted py-4">Aucune activité récente</div>
                <?php else: ?>
                    <div class="list-group list-group-flush" style="font-size:0.82rem; max-height:400px; overflow-y:auto;">
                        <?php foreach ($activite as $act):
                            $user = $users_activite[$act['idutilisateur']] ?? null;
                            $pat = $patients_activite[$act['idpatient']] ?? null;
                            $time_ago = '';
                            $diff = time() - strtotime($act['created_at']);
                            if ($diff < 60) $time_ago = 'à l\'instant';
                            elseif ($diff < 3600) $time_ago = floor($diff / 60) . ' min';
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

<!-- Liens rapides -->
<div class="row g-3 mt-4">
    <div class="col-md-4">
        <a href="index.php?page=imagerie&action=planning" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:45px;height:45px;border-radius:10px;background:#cff4fc;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-calendar-week" style="font-size:1.2rem;color:#0dcaf0;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="color:#333;">Planning des examens</div>
                    <small class="text-muted">Voir et gérer le planning</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="index.php?page=imagerie&action=pacs" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:45px;height:45px;border-radius:10px;background:#e2d9f3;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-images" style="font-size:1.2rem;color:#6f42c1;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="color:#333;">Visualiseur PACS</div>
                    <small class="text-muted">Images et vidéos DICOM</small>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="index.php?page=imagerie&action=resultats" class="card border-0 shadow-sm text-decoration-none h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:45px;height:45px;border-radius:10px;background:#d1e7dd;display:flex;align-items:center;justify-content:center;">
                    <i class="bi bi-file-earmark-richtext" style="font-size:1.2rem;color:#198754;"></i>
                </div>
                <div>
                    <div class="fw-semibold" style="color:#333;">Résultats / CR</div>
                    <small class="text-muted">Saisie et validation</small>
                </div>
            </div>
        </a>
    </div>
</div>