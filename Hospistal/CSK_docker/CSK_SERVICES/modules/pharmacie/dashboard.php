<?php
/**
 * Module Pharmacie - Dashboard
 * Vue d'ensemble : statistiques temps reel, alertes stock bas,
 * preparations urgentes, repartition par statut, activite recente.
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';
$statut_labels = $GLOBALS['pharma_statut_labels'];

$db = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

// =============================================
// STATS PRINCIPALES
// =============================================

$today = date('Y-m-d');

$stmt = $conn_services->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN statut = 'attente' THEN 1 ELSE 0 END) as en_attente,
        SUM(CASE WHEN statut = 'verification_stock' THEN 1 ELSE 0 END) as verif_stock,
        SUM(CASE WHEN statut IN ('en_preparation','preparation_terminee') THEN 1 ELSE 0 END) as en_preparation,
        SUM(CASE WHEN statut = 'controle_qualite' THEN 1 ELSE 0 END) as controle,
        SUM(CASE WHEN statut = 'prete' THEN 1 ELSE 0 END) as pretes,
        SUM(CASE WHEN statut = 'delivree' THEN 1 ELSE 0 END) as delivrees,
        SUM(CASE WHEN statut NOT IN ('delivree','retournee','annulee') AND urgence = 1 THEN 1 ELSE 0 END) as urgentes,
        SUM(CASE WHEN statut IN ('retournee','annulee') THEN 1 ELSE 0 END) as annulees_retournees
    FROM pharmacie_preparations
    WHERE DATE(created_at) = ? OR statut NOT IN ('delivree','retournee','annulee')
");
$stmt->execute([$today]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Compteur en retard
$stmt_retard = $conn_services->query("
    SELECT * FROM pharmacie_preparations 
    WHERE statut NOT IN ('delivree','retournee','annulee')
");
$all_actives = $stmt_retard->fetchAll(PDO::FETCH_ASSOC);
$nb_retard = 0;
foreach ($all_actives as $p) {
    if (isPharmaEnRetard($p)) $nb_retard++;
}

// =============================================
// ALERTES STOCK BAS (depuis csk_base.prodpharma)
// =============================================

$stmt_stock = $conn_base->query("
    SELECT 
        pp.idprodpharma,
        pp.libelle,
        pp.code,
        pp.type_produit,
        pp.seuil_alerte,
        pp.seuil_reappro,
        COALESCE(SUM(sp.quantite),0) as stock_reel
    FROM prodpharma pp
    LEFT JOIN stockpharma sp 
        ON sp.idprodpharma = pp.idprodpharma
    WHERE pp.actif = 1
    GROUP BY 
        pp.idprodpharma,
        pp.libelle,
        pp.code,
        pp.type_produit,
        pp.seuil_alerte,
        pp.seuil_reappro
    HAVING 
        stock_reel = 0
        OR stock_reel <= pp.seuil_alerte
        OR stock_reel <= COALESCE(pp.seuil_reappro, pp.seuil_alerte * 2)
    ORDER BY stock_reel ASC
    LIMIT 10
");
$alertes_stock = $stmt_stock->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// PREPARATIONS URGENTES (non terminees)
// =============================================

$stmt_urg = $conn_services->query("
    SELECT p.*, p.idpatient
    FROM pharmacie_preparations p
    WHERE p.urgence = 1 
      AND p.statut NOT IN ('delivree','retournee','annulee')
    ORDER BY p.created_at ASC
    LIMIT 8
");
$urgentes = $stmt_urg->fetchAll(PDO::FETCH_ASSOC);

// Recup noms patients urgents
$pat_ids = array_unique(array_column($urgentes, 'idpatient'));
$patients = [];
if (!empty($pat_ids)) {
    $ph = implode(',', array_fill(0, count($pat_ids), '?'));
    $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom FROM patient WHERE idpatient IN ($ph)");
    $stmt_p->execute(array_values($pat_ids));
    foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $patients[$p['idpatient']] = trim($p['nom'] . ' ' . $p['prenom']);
    }
}

// Recup noms produits urgents
$presc_ids = array_unique(array_filter(array_column($urgentes, 'idpharma_presc')));
$produits_map = [];
if (!empty($presc_ids)) {
    $ph = implode(',', array_fill(0, count($presc_ids), '?'));
    $stmt_pr = $conn_base->prepare("
        SELECT pp.idpharma_presc, pr.libelle as produit_libelle
        FROM pharma_presc pp
        LEFT JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
        WHERE pp.idpharma_presc IN ($ph)
    ");
    $stmt_pr->execute(array_values($presc_ids));
    foreach ($stmt_pr->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $produits_map[$r['idpharma_presc']] = $r['produit_libelle'];
    }
}

// =============================================
// REPARTITION PAR STATUT (pour chart)
// =============================================

$stmt_rep = $conn_services->query("
    SELECT statut, COUNT(*) as nombre
    FROM pharmacie_preparations
    WHERE statut NOT IN ('annulee','retournee')
    GROUP BY statut
    ORDER BY FIELD(statut,
        'attente','verification_stock','en_preparation','preparation_terminee',
        'controle_qualite','prete','delivree')
");
$repartition = $stmt_rep->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// ACTIVITE RECENTE (dernieres transitions)
// =============================================

$stmt_act = $conn_services->query("
    SELECT h.*, p.code_preparation
    FROM pharmacie_workflow_history h
    JOIN pharmacie_preparations p ON p.idpreparation = h.idpreparation
    ORDER BY h.created_at DESC
    LIMIT 10
");
$activites = $stmt_act->fetchAll(PDO::FETCH_ASSOC);

// Noms utilisateurs activite
$act_uids = array_unique(array_column($activites, 'idutilisateur'));
$act_users = [];
if (!empty($act_uids)) {
    $ph = implode(',', array_fill(0, count($act_uids), '?'));
    $stmt_u = $conn_base->prepare("SELECT idutilisateur, nom, prenom FROM utilisateur WHERE idutilisateur IN ($ph)");
    $stmt_u->execute(array_values($act_uids));
    foreach ($stmt_u->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $act_users[$u['idutilisateur']] = trim($u['prenom'] . ' ' . $u['nom']);
    }
}

?>

<!-- =============================================
     DASHBOARD PHARMACIE - HTML
     ============================================= -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1"><i class="bi bi-capsule-pill me-2"></i>Dashboard Pharmacie</h3>
        <small class="text-muted">Vue d'ensemble du <?= date('d/m/Y') ?></small>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php?page=pharmacie&action=preparations" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-list-ul me-1"></i>Toutes les preparations
        </a>
        <a href="index.php?page=pharmacie&action=workflow" class="btn btn-primary btn-sm">
            <i class="bi bi-kanban me-1"></i>Workflow
        </a>
    </div>
</div>

<!-- Stats principales -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-primary"><?= (int)$stats['total'] ?></div>
                <small class="text-muted">Total du jour</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold" style="color:#6c757d;"><?= (int)$stats['en_attente'] + (int)$stats['verif_stock'] ?></div>
                <small class="text-muted">En attente</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold" style="color:#6610f2;"><?= (int)$stats['en_preparation'] ?></div>
                <small class="text-muted">En preparation</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold" style="color:#20c997;"><?= (int)$stats['pretes'] ?></div>
                <small class="text-muted">Pretes</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-success"><?= (int)$stats['delivrees'] ?></div>
                <small class="text-muted">Delivrees</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-danger"><?= (int)$stats['urgentes'] ?></div>
                <small class="text-muted">Urgentes</small>
            </div>
        </div>
    </div>
    <?php if ($nb_retard > 0): ?>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100 border-danger">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-danger"><?= $nb_retard ?></div>
                <small class="text-danger fw-semibold">En retard</small>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <!-- Colonne gauche : alertes stock + urgences -->
    <div class="col-lg-7">
        
        <!-- Alertes stock bas -->
        <?php if (!empty($alertes_stock)): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle text-warning me-2"></i>Alertes stock bas</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Produit</th>
                                <th>Code</th>
                                <th>Type</th>
                                <th class="text-center">Stock</th>
                                <th class="text-center">Seuil alerte</th>
                                <th>Etat</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alertes_stock as $prod): ?>
                            <?php
                                $stock = (int)$prod['stock_reel'];
                                $seuil = max(1, (int)$prod['seuil_alerte']);
                                $ratio = $stock / $seuil;

                                if ($stock === 0) {
                                    $etat = "Rupture";
                                    $badge = "bg-danger";
                                    $textColor = "text-danger";
                                } elseif ($ratio <= 1) {
                                    $etat = "Stock critique";
                                    $badge = "bg-danger";
                                    $textColor = "text-danger";
                                } elseif ($ratio <= 2) {
                                    $etat = "Stock bas";
                                    $badge = "bg-warning text-dark";
                                    $textColor = "text-warning";
                                } else {
                                    $etat = "Stock normal";
                                    $badge = "bg-success";
                                    $textColor = "text-success";
                                }
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($prod['libelle']) ?></td>
                                <td><code><?= htmlspecialchars($prod['code']) ?></code></td>
                                <td><?= getPharmaTypeProduitBadge($prod['type_produit']) ?></td>
                                <td class="text-center">
                                    <span class="fw-bold <?= $textColor ?>">
                                        <?= $stock ?>
                                    </span>
                                </td>
                                <td class="text-center text-muted"><?= $seuil ?></td>
                                <td>
                                    <span class="badge <?= $badge ?>">
                                        <?= $etat ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Preparations urgentes -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-exclamation-circle text-danger me-2"></i>Preparations urgentes en cours</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($urgentes)): ?>
                    <div class="text-center text-muted py-4">Aucune preparation urgente en cours</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Patient</th>
                                <th>Produit</th>
                                <th>Statut</th>
                                <th>Delai</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($urgentes as $u): ?>
                            <tr class="<?= isPharmaEnRetard($u) ? 'table-danger' : '' ?>">
                                <td><code><?= htmlspecialchars($u['code_preparation']) ?></code></td>
                                <td><?= htmlspecialchars($patients[$u['idpatient']] ?? 'Patient #'.$u['idpatient']) ?></td>
                                <td class="text-truncate" style="max-width:150px;">
                                    <?= htmlspecialchars($produits_map[$u['idpharma_presc']] ?? '-') ?>
                                </td>
                                <td><?= getPharmaStatutBadge($u['statut']) ?></td>
                                <td><?= getPharmaDelaiProgressHtml($u) ?></td>
                                <td>
                                    <a href="index.php?page=pharmacie&action=workflow&code=<?= urlencode($u['code_preparation']) ?>" 
                                       class="btn btn-sm btn-outline-primary" title="Voir">
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

    <!-- Colonne droite : repartition + activite recente -->
    <div class="col-lg-5">

        <!-- Repartition par statut -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Repartition par statut</h5>
            </div>
            <div class="card-body">
                <?php 
                $total_rep = array_sum(array_column($repartition, 'nombre'));
                foreach ($repartition as $r): 
                    $info = $statut_labels[$r['statut']] ?? ['label' => $r['statut'], 'color' => '#6c757d', 'bg' => '#e9ecef', 'icon' => 'bi-circle'];
                    $pct = $total_rep > 0 ? round(($r['nombre'] / $total_rep) * 100) : 0;
                ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <span style="color:<?= $info['color'] ?>; font-weight:600; font-size:0.85rem;">
                            <i class="bi <?= $info['icon'] ?> me-1"></i><?= htmlspecialchars($info['label']) ?>
                        </span>
                        <span class="badge" style="background:<?= $info['bg'] ?>; color:<?= $info['color'] ?>;">
                            <?= $r['nombre'] ?> (<?= $pct ?>%)
                        </span>
                    </div>
                    <div class="progress" style="height:6px;">
                        <div class="progress-bar" style="width:<?= $pct ?>%; background:<?= $info['color'] ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($repartition)): ?>
                    <div class="text-center text-muted py-3">Aucune donnee</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Activite recente -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 py-3">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Activite recente</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($activites)): ?>
                    <div class="text-center text-muted py-4">Aucune activite</div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($activites as $act): ?>
                    <div class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold" style="font-size:0.85rem;">
                                    <?= htmlspecialchars($act['action']) ?>
                                </div>
                                <div style="font-size:0.8rem;">
                                    <code><?= htmlspecialchars($act['code_preparation']) ?></code>
                                    <span class="mx-1">-</span>
                                    <?= getPharmaStatutBadge($act['nouveau_statut']) ?>
                                </div>
                                <?php if (!empty($act['observation'])): ?>
                                <div class="text-muted" style="font-size:0.75rem;"><?= htmlspecialchars($act['observation']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end" style="font-size:0.75rem; min-width:80px;">
                                <div class="text-muted"><?= date('H:i', strtotime($act['created_at'])) ?></div>
                                <div class="text-muted"><?= date('d/m', strtotime($act['created_at'])) ?></div>
                                <div class="text-muted"><?= htmlspecialchars($act_users[$act['idutilisateur']] ?? '-') ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>