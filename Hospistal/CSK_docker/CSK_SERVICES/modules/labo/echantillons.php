<?php
/**
 * Module Laboratoire — Liste des groupes d'échantillons
 *
 * VERSION csk_base (CSK.sql) :
 *   - csk_base.patient  : idpatient, Noms, Prenom, Date_de_naissance, SEXE
 *   - csk_base.actes_presc : idactes_presc, IDActe
 *   - csk_base.acte     : IDActe, Nom (libelle)
 */

require_once __DIR__ . '/../../includes/labo_helpers.php';

$DB = defined('DB_MAIN') ? DB_MAIN : 'csk_base';

$statut_labels = $GLOBALS['labo_statut_labels'];
$tube_colors   = $GLOBALS['labo_tube_colors'];

$db            = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();  // csk_base

// ─── FILTRES ────────────────────────────────────────────
$f_statut    = sanitizeInput($_GET['statut']   ?? '');
$f_urgence   = sanitizeInput($_GET['urgence']  ?? '');
$f_q         = sanitizeInput($_GET['q']        ?? '');
$f_date_du   = sanitizeInput($_GET['date_du']  ?? '');
$f_date_au   = sanitizeInput($_GET['date_au']  ?? '');
$f_affichage = sanitizeInput($_GET['affichage'] ?? 'groupes');
$page_num    = max(1, (int)($_GET['p'] ?? 1));
$par_page    = 20;
$offset      = ($page_num - 1) * $par_page;

// =============================================
// 1. COMPTAGE TOTAL (groupes uniquement)
// =============================================

$total_groupes = 0;
try {
    $stmt = $conn_services->query("
        SELECT COUNT(DISTINCT lg.idgroupe)
        FROM labo_groupes_echantillons lg
        JOIN labo_echantillons le ON le.idgroupe = lg.idgroupe AND le.deleted_at IS NULL
    ");
    $total_groupes = (int)$stmt->fetchColumn();
} catch (Exception $e) {
    error_log("[Echantillons] Erreur comptage: " . $e->getMessage());
}

$total       = $total_groupes;
$total_pages = max(1, ceil($total / $par_page));

// =============================================
// 2. REQUÊTE GROUPES
// =============================================

$groupes = [];
{
    $ordre_statuts = "FIELD(le.statut,
        'attente_prelevement','preleve','validation_technique','annule','rejete','perdu')";

    // Patient.Noms + Patient.Prenom remplacent patient.nom + patient.prenom
    $sql_base = "
        FROM labo_groupes_echantillons lg
        JOIN labo_echantillons le ON le.idgroupe = lg.idgroupe AND le.deleted_at IS NULL
        JOIN {$DB}.patient p ON p.idpatient = lg.idpatient
        LEFT JOIN {$DB}.actes_presc ap ON ap.idactes_presc = le.idactes_presc
        LEFT JOIN {$DB}.acte a ON a.idacte = ap.idacte
    ";

    $where  = ['1=1'];
    $params = [];

    if ($f_date_du !== '') { $where[] = "DATE(lg.date_creation) >= :date_du"; $params[':date_du'] = $f_date_du; }
    if ($f_date_au !== '') { $where[] = "DATE(lg.date_creation) <= :date_au"; $params[':date_au'] = $f_date_au; }
    if ($f_q !== '') {
        $where[] = "(lg.code_groupe LIKE :q OR p.nom LIKE :q2 OR p.prenom LIKE :q3)";
        $params[':q']  = "%$f_q%";
        $params[':q2'] = "%$f_q%";
        $params[':q3'] = "%$f_q%";
    }

    $where_sql = implode(' AND ', $where);

    $having = [];
    if ($f_urgence === '1') { $having[] = "MAX(le.urgence) = 1"; }
    if ($f_urgence === '0') { $having[] = "MAX(le.urgence) = 0"; }
    if ($f_statut !== '')   { $having[] = "statut_global = :statut"; $params[':statut'] = $f_statut; }
    $having_sql = !empty($having) ? 'HAVING ' . implode(' AND ', $having) : '';

    $sql_select = "
        SELECT
            lg.idgroupe,
            lg.code_groupe,
            lg.date_creation,
            lg.idpatient,
            p.nom              AS patient_nom,
            p.prenom            AS patient_prenom,
            p.sexe              AS patient_sexe,
            p.date_naissance AS patient_dob,
            COUNT(le.idechantillon)              AS nb_examens,
            SUM(le.idresultat IS NOT NULL)       AS nb_resultats,
            MAX(le.urgence)                      AS urgence,
            TIMESTAMPDIFF(MINUTE, lg.date_creation, NOW()) AS delai_min,
            ELT(MIN($ordre_statuts),
                'attente_prelevement','preleve','validation_technique','annule','rejete','perdu'
            ) AS statut_global,
            GROUP_CONCAT(a.libelle ORDER BY le.sous_numero SEPARATOR ' | ') AS examens_liste,
            'groupe' AS type_affichage
    ";

    try {
        $stmt = $conn_services->prepare(
            "$sql_select $sql_base WHERE $where_sql
             GROUP BY lg.idgroupe $having_sql
             ORDER BY MAX(le.urgence) DESC, lg.date_creation DESC
             LIMIT :lim OFFSET :off"
        );
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':lim', $par_page, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
        $stmt->execute();
        $groupes = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log("[Echantillons] Erreur groupes: " . $e->getMessage());
    }
}

// =============================================
// 3. TRIER LES GROUPES
// =============================================

$items = $groupes;
usort($items, function($a, $b) {
    if ($a['urgence'] != $b['urgence']) return $b['urgence'] - $a['urgence'];
    return strtotime($b['date_creation']) - strtotime($a['date_creation']);
});

// =============================================
// 4. CHARGER SOUS-EXAMENS POUR GROUPES
// =============================================

$sous_par_groupe = [];
$ids_groupes = array_filter(array_column($groupes, 'idgroupe'));
if (!empty($ids_groupes)) {
    $ph = implode(',', array_fill(0, count($ids_groupes), '?'));
    try {
        $stmt = $conn_services->prepare("
            SELECT
                le.idechantillon, le.idgroupe, le.code_echantillon,
                le.sous_numero, le.statut, le.type_prelevement,
                le.tube_type, le.couleur_tube, le.date_prelevement, le.idresultat,
                a.libelle AS examen_libelle
            FROM labo_echantillons le
            LEFT JOIN {$DB}.actes_presc ap ON ap.idactes_presc = le.idactes_presc
            LEFT JOIN {$DB}.acte a ON a.idacte = ap.idacte
            WHERE le.idgroupe IN ($ph) AND le.deleted_at IS NULL
            ORDER BY le.idgroupe, le.sous_numero
        ");
        $stmt->execute($ids_groupes);
        foreach ($stmt->fetchAll() as $row) {
            $sous_par_groupe[$row['idgroupe']][] = $row;
        }
    } catch (Exception $e) {
        error_log("[Echantillons] Sous-examens: " . $e->getMessage());
    }
}
?>

<!-- BANDEAU GROUPES -->
<div class="d-flex align-items-center gap-3 mb-3">
    <span class="fw-semibold text-muted" style="font-size:.85rem;">
        <i class="bi bi-collection me-1"></i><?= $total_groupes ?> groupe<?= $total_groupes > 1 ? 's' : '' ?>
    </span>
</div>

<!-- FILTRES -->
<form method="GET" class="card border-0 shadow-sm mb-4">
    <input type="hidden" name="page" value="labo">
    <input type="hidden" name="action" value="echantillons">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label" style="font-size:.8rem;">Recherche</label>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Code groupe, patient..." value="<?= htmlspecialchars($f_q) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;">Statut</label>
                <select name="statut" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <?php foreach ($statut_labels as $k => $i): ?>
                        <option value="<?= $k ?>" <?= $f_statut === $k ? 'selected' : '' ?>><?= htmlspecialchars($i['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label" style="font-size:.8rem;">Urgence</label>
                <select name="urgence" class="form-select form-select-sm">
                    <option value="">Tous</option>
                    <option value="1" <?= $f_urgence === '1' ? 'selected' : '' ?>>Oui</option>
                    <option value="0" <?= $f_urgence === '0' ? 'selected' : '' ?>>Non</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;">Du</label>
                <input type="date" name="date_du" class="form-control form-control-sm" value="<?= htmlspecialchars($f_date_du) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" style="font-size:.8rem;">Au</label>
                <input type="date" name="date_au" class="form-control form-control-sm" value="<?= htmlspecialchars($f_date_au) ?>">
            </div>
            <div class="col-md-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100"><i class="bi bi-search"></i> Filtrer</button>
                <a href="index.php?page=labo&action=echantillons" class="btn btn-sm btn-outline-secondary" title="Reset"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </div>
</form>

<div class="d-flex align-items-center justify-content-between mb-3">
    <div style="font-size:.9rem;">
        <strong><?= count($items) ?></strong> élément(s) affiché(s) sur <strong><?= $total ?></strong>
        <?= $total_pages > 1 ? " — Page $page_num/$total_pages" : '' ?>
    </div>
</div>

<!-- TABLEAU -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.83rem;">
                <thead class="table-light">
                    <tr>
                        <th style="width:24px;"></th>
                        <th>Code</th>
                        <th>Patient</th>
                        <th>Examen(s)</th>
                        <th>Statut</th>
                        <th>Progression</th>
                        <th>Délai</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($items)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-5">
                        <i class="bi bi-collection fs-2 d-block mb-2"></i>Aucun groupe trouvé.
                    </td></tr>
                <?php endif; ?>

                <?php foreach ($items as $item):
                    $statut  = $item['statut_global'] ?? 'attente_prelevement';
                    $urgence = (bool)$item['urgence'];
                    $nb_ex   = (int)$item['nb_examens'];
                    $nb_res  = (int)$item['nb_resultats'];
                    $delai   = (int)$item['delai_min'];
                    $retard  = $delai > 120 && !in_array($statut, ['validation_technique','annule','rejete','perdu']);
                    $pct     = $nb_ex > 0 ? round($nb_res / $nb_ex * 100) : 0;
                    $si      = $statut_labels[$statut] ?? ['label' => $statut, 'color' => '#6c757d', 'bg' => '#e9ecef'];
                    $sous    = $sous_par_groupe[$item['idgroupe']] ?? [];
                    $age     = !empty($item['patient_dob']) ? calculateAge($item['patient_dob']) : '';
                ?>
                <!-- Ligne GROUPE -->
                <tr class="<?= $retard ? 'table-warning' : '' ?>"
                    style="cursor:pointer;<?= $urgence ? 'border-left:3px solid #dc3545;' : '' ?>"
                    onclick="toggleGrp(<?= $item['idgroupe'] ?>)">
                    <td class="text-center py-3">
                        <i class="bi bi-chevron-right text-muted toggle-icon" id="icon-<?= $item['idgroupe'] ?>"
                           style="font-size:.75rem;transition:.2s;"></i>
                    </td>
                    <td class="py-3">
                        <?php if ($urgence): ?>
                            <i class="bi bi-exclamation-triangle-fill text-danger me-1" style="font-size:.75rem;"></i>
                        <?php endif; ?>
                        <code style="font-weight:700;color:#0d6efd;"><?= htmlspecialchars($item['code_groupe']) ?></code>
                        <span class="badge ms-1" style="background:#ede8f5;color:#5a3e9e;font-size:.65rem;"><?= $nb_ex ?> exam.</span>
                    </td>
                    <td class="py-3">
                        <!-- csk_base : Noms = nom de famille, Prenom = prénom -->
                        <div class="fw-semibold"><?= htmlspecialchars(trim($item['patient_nom'] . ' ' . $item['patient_prenom'])) ?></div>
                        <div class="text-muted" style="font-size:.75rem;"><?= $age ? $age . ' ans' : '' ?></div>
                    </td>
                    <td class="py-3" style="max-width:220px;">
                        <?php
                        $ex = explode(' | ', $item['examens_liste'] ?? '');
                        echo '<div style="font-size:.78rem;">' . htmlspecialchars(implode(', ', array_slice($ex, 0, 2)));
                        if (count($ex) > 2) echo ' <span class="text-muted">+' . (count($ex) - 2) . '</span>';
                        echo '</div>';
                        ?>
                    </td>
                    <td class="py-3">
                        <span class="badge" style="background:<?= $si['bg'] ?>;color:<?= $si['color'] ?>;"><?= htmlspecialchars($si['label']) ?></span>
                    </td>
                    <td class="py-3" style="min-width:90px;">
                        <div style="font-size:.72rem;color:#6c757d;margin-bottom:3px;"><?= $nb_res ?>/<?= $nb_ex ?> résultats</div>
                        <div class="progress" style="height:5px;">
                            <div class="progress-bar <?= $pct >= 100 ? 'bg-success' : 'bg-primary' ?>" style="width:<?= $pct ?>%;"></div>
                        </div>
                    </td>
                    <td class="py-3" style="color:<?= $retard ? '#dc3545' : '#6c757d' ?>;font-weight:<?= $retard ? 700 : 400 ?>;">
                        <?= formatLaboDelai($delai) ?>
                    </td>
                    <td class="py-3 text-muted" style="font-size:.78rem;"><?= formatDateTime($item['date_creation'], 'd/m/Y H:i') ?></td>
                    <td class="py-3" onclick="event.stopPropagation();">
                        <a href="index.php?page=labo&action=workflow&code=<?= urlencode($item['code_groupe']) ?>"
                           class="btn btn-sm btn-outline-primary" title="Workflow" style="font-size:.75rem;">
                            <i class="bi bi-diagram-3"></i>
                        </a>
                    </td>
                </tr>

                <!-- Sous-examens expandables -->
                <tr id="detail-<?= $item['idgroupe'] ?>" class="d-none" style="background:#f8f9ff;">
                    <td colspan="9" class="p-0">
                        <div class="px-4 py-3">
                            <div class="fw-semibold mb-2" style="font-size:.82rem;color:#5a3e9e;">
                                <i class="bi bi-list-ul me-1"></i>
                                Sous-examens — <strong><?= htmlspecialchars($item['code_groupe']) ?></strong>
                            </div>
                            <?php if (empty($sous)): ?>
                                <p class="text-muted mb-0" style="font-size:.8rem;">Aucun sous-examen trouvé.</p>
                            <?php else: ?>
                            <table class="table table-sm mb-0" style="font-size:.8rem;">
                                <thead class="table-secondary">
                                    <tr>
                                        <th>#</th><th>Code</th><th>Examen</th>
                                        <th>Type / Tube</th><th>Statut</th><th>Prélèvement</th><th>Résultat</th><th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($sous as $se):
                                    $tc  = $tube_colors[$se['couleur_tube'] ?? ''] ?? null;
                                    $si2 = $statut_labels[$se['statut'] ?? 'attente_prelevement'] ?? ['label' => '-', 'color' => '#6c757d', 'bg' => '#e9ecef'];
                                    $can_result = in_array($se['statut'], ['preleve','validation_technique']);
                                ?>
                                <tr>
                                    <td class="text-muted"><?= $se['sous_numero'] ?></td>
                                    <td>
                                        <?php if ($tc): ?><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:<?= $tc ?>;margin-right:3px;"></span><?php endif; ?>
                                        <code><?= htmlspecialchars($se['code_echantillon']) ?></code>
                                    </td>
                                    <td><?= htmlspecialchars($se['examen_libelle'] ?? '-') ?></td>
                                    <td class="text-muted"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($se['type_prelevement'] ?? '-'))) ?><?= $se['tube_type'] ? ' / ' . htmlspecialchars($se['tube_type']) : '' ?></td>
                                    <td><span class="badge" style="background:<?= $si2['bg'] ?>;color:<?= $si2['color'] ?>;font-size:.7rem;"><?= htmlspecialchars($si2['label']) ?></span></td>
                                    <td class="text-muted"><?= $se['date_prelevement'] ? formatDateTime($se['date_prelevement'], 'd/m H:i') : '-' ?></td>
                                    <td>
                                        <?php if ($se['idresultat']): ?>
                                            <span class="badge bg-success" style="font-size:.7rem;"><i class="bi bi-check2 me-1"></i>Saisi</span>
                                        <?php elseif ($can_result): ?>
                                            <span class="badge bg-warning text-dark" style="font-size:.7rem;">À saisir</span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="index.php?page=labo&action=workflow&code=<?= urlencode($item['code_groupe']) ?>"
                                           class="btn btn-sm btn-outline-primary me-1" style="font-size:.68rem;padding:1px 6px;" title="Workflow">
                                            <i class="bi bi-diagram-3"></i>
                                        </a>
                                        <?php if ($can_result || $se['idresultat']): ?>
                                        <a href="index.php?page=labo&action=resultats&code=<?= urlencode($item['code_groupe']) ?>"
                                           class="btn btn-sm <?= $se['idresultat'] ? 'btn-success' : 'btn-outline-success' ?>"
                                           style="font-size:.68rem;padding:1px 6px;"
                                           title="<?= $se['idresultat'] ? 'Voir/Modifier résultat' : 'Saisir résultat' ?>">
                                            <i class="bi bi-clipboard2-<?= $se['idresultat'] ? 'check' : 'plus' ?>"></i>
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($total_pages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <li class="page-item <?= $page_num <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= buildLaboFilterUrl('echantillons', ['p' => $page_num - 1]) ?>">Préc.</a>
        </li>
        <?php for ($i = max(1, $page_num - 3); $i <= min($total_pages, $page_num + 3); $i++): ?>
            <li class="page-item <?= $i === $page_num ? 'active' : '' ?>">
                <a class="page-link" href="<?= buildLaboFilterUrl('echantillons', ['p' => $i]) ?>"><?= $i ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page_num >= $total_pages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= buildLaboFilterUrl('echantillons', ['p' => $page_num + 1]) ?>">Suiv.</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<script>
function toggleGrp(id) {
    var row  = document.getElementById('detail-' + id);
    var icon = document.getElementById('icon-' + id);
    if (!row) return;
    var closed = row.classList.toggle('d-none');
    if (icon) icon.style.transform = closed ? '' : 'rotate(90deg)';
}
</script>