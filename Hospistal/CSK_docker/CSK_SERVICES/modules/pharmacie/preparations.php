<?php
/**
 * Module Pharmacie - Liste des preparations
 * 
 * Liste filtrable (statut, urgence, produit, date, recherche texte)
 * avec pagination et liens vers le workflow.
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';
$statut_labels = $GLOBALS['pharma_statut_labels'];

$db = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

// =============================================
// PARAMETRES FILTRES + PAGINATION
// =============================================

$filter_statut   = isset($_GET['statut']) ? sanitizeInput($_GET['statut']) : '';
$filter_urgence  = isset($_GET['urgence']) ? sanitizeInput($_GET['urgence']) : '';
$filter_date     = isset($_GET['date']) ? sanitizeInput($_GET['date']) : '';
$filter_search   = isset($_GET['q']) ? trim($_GET['q']) : '';
$page_num        = max(1, (int)($_GET['p'] ?? 1));
$per_page        = 20;
$offset          = ($page_num - 1) * $per_page;

// =============================================
// CONSTRUCTION REQUETE FILTREE
// =============================================

$where = [];
$params = [];

if ($filter_statut) {
    $where[] = "p.statut = :statut";
    $params[':statut'] = $filter_statut;
}

if ($filter_urgence === '1') {
    $where[] = "p.urgence = 1";
} elseif ($filter_urgence === '0') {
    $where[] = "(p.urgence = 0 OR p.urgence IS NULL)";
}

if ($filter_date) {
    $where[] = "DATE(p.created_at) = :date_filter";
    $params[':date_filter'] = $filter_date;
}

// Recherche texte (code preparation)
$search_patient_ids = [];
if ($filter_search) {
    $search_like = '%' . $filter_search . '%';
    $stmt_ps = $conn_base->prepare("SELECT idpatient FROM patient WHERE nom LIKE ? OR prenom LIKE ? LIMIT 50");
    $stmt_ps->execute([$search_like, $search_like]);
    $search_patient_ids = $stmt_ps->fetchAll(PDO::FETCH_COLUMN);
    
    $code_clause = "p.code_preparation LIKE :q_code";
    $params[':q_code'] = $search_like;
    
    if (!empty($search_patient_ids)) {
        $ph_pids = implode(',', array_map('intval', $search_patient_ids));
        $where[] = "($code_clause OR p.idpatient IN ($ph_pids))";
    } else {
        $where[] = $code_clause;
    }
}

$where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Compteur total
$stmt_count = $conn_services->prepare("SELECT COUNT(*) FROM pharmacie_preparations p $where_sql");
$stmt_count->execute($params);
$total = (int)$stmt_count->fetchColumn();
$total_pages = max(1, ceil($total / $per_page));

// Requete paginee
$stmt = $conn_services->prepare("
    SELECT p.*
    FROM pharmacie_preparations p
    $where_sql
    ORDER BY p.urgence DESC, p.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$preparations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// ENRICHIR : patients + produits
// =============================================

$patient_ids = array_unique(array_column($preparations, 'idpatient'));
$patients = [];
if (!empty($patient_ids)) {
    $ph = implode(',', array_fill(0, count($patient_ids), '?'));
    $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom FROM patient WHERE idpatient IN ($ph)");
    $stmt_p->execute(array_values($patient_ids));
    foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $patients[$p['idpatient']] = trim($p['nom'] . ' ' . $p['prenom']);
    }
}

$presc_ids = array_unique(array_filter(array_column($preparations, 'idpharma_presc')));
$produits_map = [];
if (!empty($presc_ids)) {
    $ph = implode(',', array_fill(0, count($presc_ids), '?'));
    $stmt_pr = $conn_base->prepare("
        SELECT pp.idpharma_presc, pr.libelle as produit_libelle, pr.code as produit_code, pr.forme
        FROM pharma_presc pp
        LEFT JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
        WHERE pp.idpharma_presc IN ($ph)
    ");
    $stmt_pr->execute(array_values($presc_ids));
    foreach ($stmt_pr->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $produits_map[$r['idpharma_presc']] = $r;
    }
}

?>

<!-- =============================================
     LISTE PREPARATIONS - HTML
     ============================================= -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-1"><i class="bi bi-list-ul me-2"></i>Preparations</h3>
        <small class="text-muted"><?= $total ?> preparation(s) trouvee(s)</small>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php?page=pharmacie" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a href="index.php?page=pharmacie&action=workflow" class="btn btn-primary btn-sm">
            <i class="bi bi-kanban me-1"></i>Workflow
        </a>
    </div>
</div>

<!-- Filtres -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-3">
        <form method="GET" action="index.php" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="pharmacie">
            <input type="hidden" name="action" value="preparations">
            
            <div class="col-md-2">
                <label class="form-label form-label-sm">Statut</label>
                <select name="statut" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <?php foreach ($statut_labels as $key => $info): ?>
                    <option value="<?= $key ?>" <?= $filter_statut === $key ? 'selected' : '' ?>>
                        <?= htmlspecialchars($info['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label form-label-sm">Urgence</label>
                <select name="urgence" class="form-select form-select-sm">
                    <option value="">Toutes</option>
                    <option value="1" <?= $filter_urgence === '1' ? 'selected' : '' ?>>Urgent</option>
                    <option value="0" <?= $filter_urgence === '0' ? 'selected' : '' ?>>Normal</option>
                </select>
            </div>
            
            <div class="col-md-2">
                <label class="form-label form-label-sm">Date</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
            </div>
            
            <div class="col-md-3">
                <label class="form-label form-label-sm">Recherche</label>
                <input type="text" name="q" class="form-control form-control-sm" 
                       placeholder="Code, patient..." value="<?= htmlspecialchars($filter_search) ?>">
            </div>
            
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-search me-1"></i>Filtrer
                </button>
                <a href="index.php?page=pharmacie&action=preparations" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-circle me-1"></i>Reset
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Tableau -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($preparations)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                Aucune preparation trouvee
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Patient</th>
                        <th>Produit</th>
                        <th>Forme</th>
                        <th>Statut</th>
                        <th>Urgence</th>
                        <th>Delai</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($preparations as $prep): 
                        $produit = $produits_map[$prep['idpharma_presc']] ?? null;
                        $en_retard = isPharmaEnRetard($prep);
                    ?>
                    <tr class="<?= $en_retard ? 'table-danger' : '' ?>">
                        <td>
                            <code class="fw-semibold"><?= htmlspecialchars($prep['code_preparation']) ?></code>
                        </td>
                        <td><?= htmlspecialchars($patients[$prep['idpatient']] ?? 'Patient #'.$prep['idpatient']) ?></td>
                        <td class="text-truncate" style="max-width:180px;" title="<?= htmlspecialchars($produit['produit_libelle'] ?? '-') ?>">
                            <?= htmlspecialchars($produit['produit_libelle'] ?? '-') ?>
                        </td>
                        <td>
                            <small class="text-muted"><?= htmlspecialchars($produit['forme'] ?? '-') ?></small>
                        </td>
                        <td><?= getPharmaStatutBadge($prep['statut']) ?></td>
                        <td><?= getPharmaUrgenceBadge(!empty($prep['urgence'])) ?></td>
                        <td><?= getPharmaDelaiProgressHtml($prep) ?></td>
                        <td>
                            <small class="text-muted"><?= date('d/m H:i', strtotime($prep['created_at'])) ?></small>
                        </td>
                        <td>
                            <a href="index.php?page=pharmacie&action=workflow&code=<?= urlencode($prep['code_preparation']) ?>" 
                               class="btn btn-sm btn-outline-primary" title="Gerer">
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

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white border-0 py-3">
        <nav>
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildPharmaFilterUrl('preparations', ['p' => $page_num - 1]) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php 
                $start = max(1, $page_num - 2);
                $end = min($total_pages, $page_num + 2);
                for ($i = $start; $i <= $end; $i++): 
                ?>
                <li class="page-item <?= $i === $page_num ? 'active' : '' ?>">
                    <a class="page-link" href="<?= buildPharmaFilterUrl('preparations', ['p' => $i]) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="<?= buildPharmaFilterUrl('preparations', ['p' => $page_num + 1]) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>