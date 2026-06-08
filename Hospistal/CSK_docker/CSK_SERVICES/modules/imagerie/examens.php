<?php
/**
 * Module Imagerie - Liste des examens
 * 
 * Liste filtrable (statut, priorite, type_examen, date, recherche)
 * avec pagination et lien vers le workflow de chaque examen.
 */

require_once __DIR__ . '/../../includes/imagerie_helpers.php';
$statut_labels   = $GLOBALS['imagerie_statut_labels'];
$priorite_colors = $GLOBALS['imagerie_priorite_colors'];

$db = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

// =============================================
// PARAMETRES DE FILTRAGE
// =============================================

$filter_statut     = isset($_GET['statut'])     ? sanitizeInput($_GET['statut'])     : '';
$filter_priorite   = isset($_GET['priorite'])   ? sanitizeInput($_GET['priorite'])   : '';
$filter_type       = isset($_GET['type_examen'])? sanitizeInput($_GET['type_examen']): '';
$filter_date       = isset($_GET['date'])       ? sanitizeInput($_GET['date'])       : '';
$filter_search     = isset($_GET['q'])          ? sanitizeInput($_GET['q'])          : '';
$current_page      = max(1, (int)($_GET['pg'] ?? 1));
$per_page          = 20;

// =============================================
// CONSTRUCTION DYNAMIQUE DES FILTRES
// =============================================

$where_clauses = [];
$params = [];

// Filtre statut
if (!empty($filter_statut)) {
    $where_clauses[] = "e.statut = :statut";
    $params[':statut'] = $filter_statut;
}

// Filtre priorité
if (!empty($filter_priorite)) {
    $where_clauses[] = "e.priorite = :priorite";
    $params[':priorite'] = $filter_priorite;
}

// Filtre type examen
if (!empty($filter_type)) {
    $where_clauses[] = "e.type_examen LIKE :type_examen";
    $params[':type_examen'] = '%' . $filter_type . '%';
}

// Filtre date RDV
if (!empty($filter_date)) {
    $where_clauses[] = "DATE(e.date_rdv) = :date_rdv";
    $params[':date_rdv'] = $filter_date;
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// =============================================
// COMPTAGE TOTAL
// =============================================

$count_sql = "
    SELECT COUNT(*) 
    FROM imagerie_examens e
    $where_sql
";

$stmt_count = $conn_services->prepare($count_sql);
$stmt_count->execute($params);

$total_records = (int)$stmt_count->fetchColumn();
$total_pages = max(1, ceil($total_records / $per_page));
$current_page = min($current_page, $total_pages);
$offset = ($current_page - 1) * $per_page;

// =============================================
// RECUPERATION DES EXAMENS
// =============================================

$sql = "
    SELECT e.*,
           TIMESTAMPDIFF(MINUTE, e.date_rdv, NOW()) as delai_minutes
    FROM imagerie_examens e
    $where_sql
    ORDER BY 
        CASE e.priorite 
            WHEN 'extreme_urgence' THEN 1 
            WHEN 'urgence' THEN 2 
            ELSE 3 
        END,
        e.date_rdv DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn_services->prepare($sql);

// Bind des filtres dynamiques
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

// Bind pagination (toujours en INT)
$stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);

$stmt->execute();

$examens = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// RECUPERATION DES PATIENTS
// =============================================

$patients = [];
if (!empty($examens)) {
    $ids = array_values(array_unique(array_column($examens, 'idpatient')));
    
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        
        $stmt_p = $conn_base->prepare("
            SELECT idpatient, nom, prenom, sexe, date_naissance 
            FROM patient 
            WHERE idpatient IN ($placeholders)
        ");
        
        $stmt_p->execute($ids);
        
        foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $patients[$p['idpatient']] = $p;
        }
    }
}

// Filtrage texte cote PHP (recherche dans code_examen + nom/prenom patient)
if ($filter_search) {
    $search_lower = strtolower($filter_search);
    $examens = array_filter($examens, function($ech) use ($search_lower, $patients) {
        if (stripos($ech['code_examen'], $search_lower) !== false) return true;
        if (stripos($ech['type_examen'] ?? '', $search_lower) !== false) return true;
        if (stripos($ech['salle'] ?? '', $search_lower) !== false) return true;
        $pat = $patients[$ech['idpatient']] ?? null;
        if ($pat) {
            $full = strtolower($pat['nom'] . ' ' . $pat['prenom']);
            if (strpos($full, $search_lower) !== false) return true;
        }
        return false;
    });
}

// =============================================
// LISTE DES TYPES D'EXAMENS (pour le filtre select)
// =============================================

$stmt_types = $conn_services->query("SELECT DISTINCT type_examen FROM imagerie_examens WHERE type_examen IS NOT NULL ORDER BY type_examen");
$types_disponibles = $stmt_types->fetchAll(PDO::FETCH_COLUMN);

// Noms des radiologues
$radiologues = [];
$radiologue_ids = array_values(array_filter(array_unique(array_column($examens, 'radiologue'))));

if (!empty($radiologue_ids)) {
    $placeholders = implode(',', array_fill(0, count($radiologue_ids), '?'));
    
    $stmt_r = $conn_base->prepare("
        SELECT idutilisateur, nom, prenom 
        FROM utilisateur 
        WHERE idutilisateur IN ($placeholders)
    ");
    
    $stmt_r->execute($radiologue_ids);
    
    foreach ($stmt_r->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $radiologues[$r['idutilisateur']] = $r;
    }
}
?>

<!-- =============================================
     LISTE DES EXAMENS IMAGERIE
     ============================================= -->

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="mb-1" style="font-weight:700; color:#0d6efd;">
            <i class="bi bi-list-ul me-2"></i>Examens d'imagerie
        </h2>
        <p class="text-muted mb-0"><?= $total_records ?> examen(s) au total</p>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php?page=imagerie&action=dashboard" class="btn btn-outline-secondary">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <a href="index.php?page=imagerie&action=workflow" class="btn btn-primary">
            <i class="bi bi-kanban me-1"></i>Workflow
        </a>
    </div>
</div>

<!-- FILTRES -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="imagerie">
            <input type="hidden" name="action" value="examens">
            
            <!-- Recherche texte -->
            <div class="col-md-3">
                <label class="form-label" style="font-size:0.8rem; font-weight:600;">Recherche</label>
                <input type="text" name="q" class="form-control form-control-sm" 
                       placeholder="Code, patient, type..." value="<?= htmlspecialchars($filter_search) ?>">
            </div>
            
            <!-- Filtre statut -->
            <div class="col-md-2">
                <label class="form-label" style="font-size:0.8rem; font-weight:600;">Statut</label>
                <select name="statut" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <?php foreach ($statut_labels as $key => $info): ?>
                        <option value="<?= $key ?>" <?= $filter_statut === $key ? 'selected' : '' ?>>
                            <?= $info['label'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Filtre priorite -->
            <div class="col-md-2">
                <label class="form-label" style="font-size:0.8rem; font-weight:600;">Priorite</label>
                <select name="priorite" class="form-select form-select-sm">
                    <option value="">Toutes</option>
                    <?php foreach ($priorite_colors as $key => $info): ?>
                        <option value="<?= $key ?>" <?= $filter_priorite === $key ? 'selected' : '' ?>>
                            <?= $info['label'] ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Filtre type examen -->
            <div class="col-md-2">
                <label class="form-label" style="font-size:0.8rem; font-weight:600;">Type examen</label>
                <select name="type_examen" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <?php foreach ($types_disponibles as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= $filter_type === $t ? 'selected' : '' ?>>
                            <?= htmlspecialchars($t) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Filtre date -->
            <div class="col-md-2">
                <label class="form-label" style="font-size:0.8rem; font-weight:600;">Date RDV</label>
                <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filter_date) ?>">
            </div>
            
            <!-- Boutons -->
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary" title="Filtrer">
                    <i class="bi bi-funnel"></i>
                </button>
                <a href="index.php?page=imagerie&action=examens" class="btn btn-sm btn-outline-secondary" title="Reset">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- TABLEAU DES EXAMENS -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($examens)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-camera" style="font-size:3rem;"></i>
                <p class="mt-2 mb-0">Aucun examen trouve avec ces filtres.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:0.85rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Code</th>
                            <th>Patient</th>
                            <th>Type examen</th>
                            <th>Salle</th>
                            <th>RDV</th>
                            <th>Priorite</th>
                            <th>Statut</th>
                            <th>Radiologue</th>
                            <th>Qualite</th>
                            <th>Delai</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($examens as $ex): 
                            $pat = $patients[$ex['idpatient']] ?? null;
                            $rad = $radiologues[$ex['radiologue'] ?? 0] ?? null;
                            $en_retard = isImagerieEnRetard($ex);
                        ?>
                        <tr style="<?= $en_retard ? 'background:#fff3cd;' : '' ?>">
                            <td>
                                <code><?= htmlspecialchars($ex['code_examen']) ?></code>
                                <?php if ($ex['urgence']): ?>
                                    <i class="bi bi-exclamation-triangle-fill text-danger ms-1" title="Urgent"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($pat): ?>
                                    <strong><?= htmlspecialchars($pat['nom']) ?></strong>
                                    <span class="text-muted"><?= htmlspecialchars($pat['prenom']) ?></span>
                                <?php else: ?>
                                    <span class="text-muted">#<?= $ex['idpatient'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($ex['type_examen'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($ex['salle'] ?? '-') ?></td>
                            <td>
                                <?php if ($ex['date_rdv']): ?>
                                    <span title="<?= date('d/m/Y H:i', strtotime($ex['date_rdv'])) ?>">
                                        <?= date('d/m H:i', strtotime($ex['date_rdv'])) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= getImageriePrioriteBadge($ex['priorite'] ?? 'programme') ?></td>
                            <td><?= getImagerieStatutBadge($ex['statut']) ?></td>
                            <td>
                                <?php if ($rad): ?>
                                    Dr. <?= htmlspecialchars($rad['nom']) ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= getImagerieQualiteBadge($ex['qualite_images']) ?></td>
                            <td><?= getImagerieDelaiProgressHtml($ex) ?></td>
                            <td>
                                <a href="index.php?page=imagerie&action=workflow&code=<?= urlencode($ex['code_examen']) ?>" 
                                   class="btn btn-sm btn-outline-primary" title="Voir workflow">
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
    
    <!-- PAGINATION -->
    <?php if ($total_pages > 1): ?>
    <div class="card-footer bg-white border-0 d-flex justify-content-between align-items-center">
        <small class="text-muted">
            Page <?= $current_page ?> / <?= $total_pages ?> (<?= $total_records ?> examens)
        </small>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <?php if ($current_page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= buildImagerieFilterUrl('examens', ['pg' => $current_page - 1]) ?>">
                            <i class="bi bi-chevron-left"></i>
                        </a>
                    </li>
                <?php endif; ?>
                
                <?php 
                $start = max(1, $current_page - 2);
                $end = min($total_pages, $current_page + 2);
                for ($i = $start; $i <= $end; $i++): 
                ?>
                    <li class="page-item <?= $i === $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= buildImagerieFilterUrl('examens', ['pg' => $i]) ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>
                
                <?php if ($current_page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="<?= buildImagerieFilterUrl('examens', ['pg' => $current_page + 1]) ?>">
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
