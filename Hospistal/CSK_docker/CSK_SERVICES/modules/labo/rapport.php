<?php
/**
 * Module Laboratoire — Rapport statistique
 *
 * Unité principale : GROUPE (cohérence avec le résumé du tableau de bord).
 *
 * Stats groupes  → labo_groupes_echantillons + labo_echantillons (csk_services)
 * Délai moyen    → groupes ENTIÈREMENT terminés uniquement :
 *                  date_creation → MAX(resultat_labo.date_analyse)
 *                  (évite de gonfler avec les groupes encore en cours)
 * Urgents        → échantillons urgents non-terminés (stat opérationnelle)
 * Critiques      → résultats critiques (via resultat_labo, groupés uniquement)
 * Techniciens    → date_prescription → date_analyse (délai propre au technicien)
 *
 * Toutes les requêtes sont restreintes :
 *   - idgroupe IS NOT NULL (groupés uniquement)
 *   - statut IN ('attente_prelevement','preleve','validation_technique')
 *   - deleted_at IS NULL
 */

require_once __DIR__ . '/../../includes/labo_helpers.php';

$db            = new Database();
$conn_services = $db->getServicesConnection();
$conn_base     = $db->getBaseConnection();

// =============================================
// EXPORT EXCEL — examens groupés uniquement
// =============================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    function exportExcel(string $filename, array $headers, array $rows): void {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, $headers, "\t");
        foreach ($rows as $row) { fputcsv($out, $row, "\t"); }
        fclose($out);
        exit();
    }
    $stmt = $conn_base->query("
        SELECT
            CONCAT(p.nom, ' ', p.prenom) AS patient,
            a.libelle                          AS analyse,
            r.resultat,
            r.interpretation               AS interpretation,
            r.date_analyse                     AS date_analyse
        FROM resultatslabo r
        JOIN labo_echantillons le
            ON le.idactes_presc = r.idactes_presc AND le.deleted_at IS NULL
        JOIN csk_services.labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
        JOIN actes_presc ap ON ap.idactes_presc = le.idactes_presc
        JOIN acte a         ON a.idacte = ap.idacte
        JOIN sous_sejour ss  ON ss.idsous_sejour = ap.idsous_sejour
        JOIN sejour s        ON s.idsejour = ss.idsejour
        JOIN patient p       ON p.idpatient = s.idpatient
        ORDER BY r.date_analyse DESC
    ");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $rows = [];
    foreach ($data as $row) {
        $rows[] = [
            $row['patient'], $row['analyse'],
            $row['resultat'], strtoupper($row['interpretation'] ?? ''), $row['date_analyse']
        ];
    }
    exportExcel(
        'rapport_laboratoire_' . date('Ymd_His') . '.xls',
        ['Patient','Analyse','Résultat','Interprétation','Date Analyse'],
        $rows
    );
}

// =============================================
// PARAMÈTRES DE PÉRIODE
// =============================================
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin   = $_GET['date_fin']   ?? date('Y-m-t');

// =============================================
// 1. STATS GROUPES — même logique que dashboard_general
//    total_groupes  = groupes ayant au moins 1 échantillon actif dans la période
//    groupes_termines = groupes où TOUS les échantillons sont validation_technique
//    taux_completion = groupes_termines / total_groupes  (identique au dashboard)
// =============================================
$stats = [
    'total_groupes'      => 0,
    'groupes_termines'   => 0,
    'analyses_urgentes'  => 0,
    'resultats_critiques'=> 0,
    'delai_moyen_min'    => 0,   // terminés uniquement : date_creation → last date_analyse
];

try {
    // Groupes + taux (identique query dashboard_general)
    $stmt = $conn_services->prepare("
        SELECT
            COUNT(DISTINCT lg.idgroupe) AS total_groupes,
            COUNT(DISTINCT CASE
                WHEN NOT EXISTS (
                    SELECT 1 FROM labo_echantillons le2
                    WHERE le2.idgroupe  = lg.idgroupe
                      AND le2.deleted_at IS NULL
                      AND le2.statut   != 'validation_technique'
                ) THEN lg.idgroupe END) AS groupes_termines
        FROM labo_groupes_echantillons lg
        INNER JOIN labo_echantillons le
            ON le.idgroupe = lg.idgroupe AND le.deleted_at IS NULL
        WHERE le.statut IN ('attente_prelevement','preleve','validation_technique')
          AND DATE(lg.date_creation) BETWEEN :deb AND :fin
    ");
    $stmt->execute([':deb' => $date_debut, ':fin' => $date_fin]);
    $row = $stmt->fetch();
    if ($row) {
        $stats['total_groupes']    = (int)$row['total_groupes'];
        $stats['groupes_termines'] = (int)$row['groupes_termines'];
    }
} catch (Exception $e) {
    error_log("[Rapport Labo] Erreur stats groupes: " . $e->getMessage());
}

try {
    // Urgents : échantillons urgents non-terminés (stat opérationnelle)
    $stmt = $conn_services->prepare("
        SELECT SUM(le.urgence = 1 AND le.statut IN ('attente_prelevement','preleve')) AS urgents
        FROM labo_echantillons le
        INNER JOIN labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
        WHERE le.deleted_at IS NULL
          AND le.statut IN ('attente_prelevement','preleve','validation_technique')
          AND DATE(lg.date_creation) BETWEEN :deb AND :fin
    ");
    $stmt->execute([':deb' => $date_debut, ':fin' => $date_fin]);
    $row = $stmt->fetch();
    $stats['analyses_urgentes'] = (int)($row['urgents'] ?? 0);
} catch (Exception $e) {
    error_log("[Rapport Labo] Erreur urgents: " . $e->getMessage());
}

try {
    // Délai moyen — groupes ENTIÈREMENT terminés uniquement
    // date_creation → MAX(date_analyse) = délai réel de traitement
    // Exclut les groupes encore en cours (évite le biais de NOW())
    $stmt = $conn_services->prepare("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, lg.date_creation, dr.max_date)) AS delai_moyen_min
        FROM labo_groupes_echantillons lg
        INNER JOIN (
            SELECT le.idgroupe AS idgroupe, MAX(r.date_analyse) AS max_date
            FROM labo_echantillons le
            JOIN csk_base.resultatslabo r ON r.idactes_presc = le.idactes_presc
            WHERE le.deleted_at IS NULL
            GROUP BY le.idgroupe
        ) dr ON dr.idgroupe = lg.idgroupe
        WHERE DATE(lg.date_creation) BETWEEN :deb AND :fin
          AND NOT EXISTS (
              SELECT 1 FROM labo_echantillons le2
              WHERE le2.idgroupe  = lg.idgroupe
                AND le2.deleted_at IS NULL
                AND le2.statut   != 'validation_technique'
          )
    ");
    $stmt->execute([':deb' => $date_debut, ':fin' => $date_fin]);
    $row = $stmt->fetch();
    $stats['delai_moyen_min'] = round((float)($row['delai_moyen_min'] ?? 0));
} catch (Exception $e) {
    error_log("[Rapport Labo] Erreur délai moyen: " . $e->getMessage());
}

try {
    // Critiques : résultats critiques dans des examens groupés
    $stmt = $conn_base->prepare("
        SELECT COUNT(DISTINCT r.idresultat) AS critiques
        FROM resultatslabo r
        JOIN labo_echantillons le
            ON le.idactes_presc = r.idactes_presc AND le.deleted_at IS NULL
        JOIN csk_services.labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
        WHERE r.interpretation = 'critique'
          AND DATE(r.date_analyse) BETWEEN :deb AND :fin
    ");
    $stmt->execute([':deb' => $date_debut, ':fin' => $date_fin]);
    $row = $stmt->fetch();
    $stats['resultats_critiques'] = (int)($row['critiques'] ?? 0);
} catch (Exception $e) {
    error_log("[Rapport Labo] Erreur critiques: " . $e->getMessage());
}

// Calculs dérivés
$taux_completion = $stats['total_groupes'] > 0
    ? round(($stats['groupes_termines'] / $stats['total_groupes']) * 100, 1)
    : 0;

$delai_min       = (int)$stats['delai_moyen_min'];
$delai_affichage = $delai_min > 0
    ? ($delai_min >= 60
        ? floor($delai_min / 60) . 'h' . ($delai_min % 60 > 0 ? str_pad($delai_min % 60, 2, '0', STR_PAD_LEFT) : '')
        : $delai_min . ' min')
    : '—';

// =============================================
// 2. TOP EXAMENS — par nombre d'échantillons groupés
// =============================================
$top_analyses = [];
try {
    $stmt = $conn_services->prepare("
        SELECT a.libelle AS libelle, COUNT(le.idechantillon) AS nombre
        FROM labo_echantillons le
        INNER JOIN labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
        LEFT JOIN csk_base.actes_presc ap ON ap.idactes_presc = le.idactes_presc
        LEFT JOIN csk_base.acte a         ON a.idacte = ap.idacte
        WHERE le.deleted_at IS NULL
          AND le.statut IN ('attente_prelevement','preleve','validation_technique')
          AND DATE(lg.date_creation) BETWEEN :deb AND :fin
        GROUP BY ap.idacte
        ORDER BY nombre DESC
        LIMIT 10
    ");
    $stmt->execute([':deb' => $date_debut, ':fin' => $date_fin]);
    $top_analyses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("[Rapport Labo] Erreur top analyses: " . $e->getMessage());
}

// =============================================
// 3. GROUPES PAR JOUR
// =============================================
$analyses_par_jour = [];
try {
    $stmt = $conn_services->prepare("
        SELECT
            DATE(lg.date_creation)      AS date,
            COUNT(DISTINCT lg.idgroupe) AS nombre
        FROM labo_groupes_echantillons lg
        INNER JOIN labo_echantillons le
            ON le.idgroupe = lg.idgroupe AND le.deleted_at IS NULL
        WHERE le.statut IN ('attente_prelevement','preleve','validation_technique')
          AND DATE(lg.date_creation) BETWEEN :deb AND :fin
        GROUP BY DATE(lg.date_creation)
        ORDER BY date
    ");
    $stmt->execute([':deb' => $date_debut, ':fin' => $date_fin]);
    $analyses_par_jour = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("[Rapport Labo] Erreur par jour: " . $e->getMessage());
}

// =============================================
// 4. PERFORMANCE TECHNICIENS
//    Délai = date_prescription → date_analyse (délai propre au technicien)
//    Différent du délai du rapport (date_creation groupe → résultat final)
//    Les deux métriques sont complémentaires et intentionnellement distinctes.
// =============================================
$performance_techniciens = [];
try {
    $stmt = $conn_base->prepare("
        SELECT
            u.nom AS nom, NULL AS prenom,
            COUNT(DISTINCT r.idresultat)                                     AS nb_analyses,
            AVG(TIMESTAMPDIFF(HOUR, ap.date_prescription, r.date_analyse))              AS delai_moyen
        FROM resultatslabo r
        JOIN labo_echantillons le
            ON le.idactes_presc = r.idactes_presc AND le.deleted_at IS NULL
        JOIN csk_services.labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
        JOIN actes_presc ap ON ap.idactes_presc = r.idactes_presc
        JOIN utilisateur u  ON u.idutilisateur  = r.analyse_par
        WHERE DATE(r.date_analyse) BETWEEN :deb AND :fin
        GROUP BY r.analyse_par
        ORDER BY nb_analyses DESC
        LIMIT 10
    ");
    $stmt->execute([':deb' => $date_debut, ':fin' => $date_fin]);
    $performance_techniciens = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("[Rapport Labo] Erreur techniciens: " . $e->getMessage());
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE DU RAPPORT -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-bar-chart-line me-2" style="color:#6f42c1;"></i>
        Rapport statistique — Laboratoire
    </h4>
    <div class="btn-group">
        <a href="index.php?page=labo&action=dashboard" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer"></i> Imprimer
        </button>
        <a href="index.php?page=labo&action=rapport&export=excel" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel"></i> Exporter
        </a>
    </div>
</div>

<!-- ========================================= -->
<!-- FILTRES PÉRIODE -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page"   value="labo">
            <input type="hidden" name="action" value="rapport">
            <div class="col-md-4">
                <label class="form-label">Date début</label>
                <input type="date" name="date_debut" class="form-control"
                       value="<?= htmlspecialchars($date_debut) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Date fin</label>
                <input type="date" name="date_fin" class="form-control"
                       value="<?= htmlspecialchars($date_fin) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i> Générer
                </button>
            </div>
        </form>
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <button onclick="setPeriod('today')"   class="btn btn-sm btn-outline-secondary">Aujourd'hui</button>
            <button onclick="setPeriod('week')"    class="btn btn-sm btn-outline-secondary">Cette semaine</button>
            <button onclick="setPeriod('month')"   class="btn btn-sm btn-outline-secondary">Ce mois</button>
            <button onclick="setPeriod('quarter')" class="btn btn-sm btn-outline-secondary">Ce trimestre</button>
            <button onclick="setPeriod('year')"    class="btn btn-sm btn-outline-secondary">Cette année</button>
        </div>
    </div>
</div>

<!-- Bandeau période -->
<div class="alert mb-4" style="background:#e9ecef;border:none;">
    <i class="bi bi-calendar-range me-2"></i>
    Rapport du <strong><?= formatDate($date_debut) ?></strong>
    au <strong><?= formatDate($date_fin) ?></strong>
    <span class="text-muted ms-2" style="font-size:.88rem;">
        · Examens groupés uniquement
        · Statuts actifs : Att. prélèvement / Prélevé / Validé
        · Délai = groupes terminés uniquement (création → dernier résultat)
    </span>
</div>

<!-- ========================================= -->
<!-- STATISTIQUES PRINCIPALES                  -->
<!-- Unité : GROUPE — identique au tableau de bord
     Urgents et Critiques : par échantillon/résultat (alertes)
<!-- ========================================= -->
<div class="row g-4 mb-4">

    <!-- Total Groupes -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background:#e9d5ff;">
                    <i class="bi bi-collection fs-4" style="color:#6f42c1;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Total Groupes</div>
                    <div class="stat-value"><?= number_format($stats['total_groupes']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Groupes Terminés + taux — IDENTIQUE au tableau de bord -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon-wrapper me-3" style="background:#d1fae5;">
                        <i class="bi bi-check-circle fs-4" style="color:#10b981;"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Groupes Terminés</div>
                        <div class="stat-value"><?= number_format($stats['groupes_termines']) ?></div>
                        <div class="stat-small"><?= $taux_completion ?>% complétion</div>
                        <div class="progress mt-2" style="height:4px;">
                            <div class="progress-bar bg-success"
                                 style="width:<?= $taux_completion ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Urgents (échantillons non-terminés) -->
    <div class="col-xl-2 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background:#fee2e2;">
                    <i class="bi bi-exclamation-triangle fs-4" style="color:#dc2626;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Urgents</div>
                    <div class="stat-value"><?= number_format($stats['analyses_urgentes']) ?></div>
                    <div class="stat-small">échantillons en cours</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Délai moyen (groupes terminés uniquement) -->
    <div class="col-xl-2 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background:#dbeafe;">
                    <i class="bi bi-clock fs-4" style="color:#2563eb;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Délai moyen</div>
                    <div class="stat-value" style="font-size:1.5rem;"><?= $delai_affichage ?></div>
                    <div class="stat-small">groupes terminés</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Résultats critiques -->
    <div class="col-xl-2 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background:#fee2e2;">
                    <i class="bi bi-exclamation-circle fs-4" style="color:#dc2626;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Critiques</div>
                    <div class="stat-value"><?= number_format($stats['resultats_critiques']) ?></div>
                    <div class="stat-small">résultats critiques</div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ========================================= -->
<!-- GRAPHIQUES -->
<!-- ========================================= -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Groupes créés par jour</h6>
            </div>
            <div class="card-body">
                <canvas id="chartEvolution" style="height:300px;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Top 10 examens</h6>
            </div>
            <div class="card-body">
                <canvas id="chartTopAnalyses" style="height:300px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- PERFORMANCE TECHNICIENS                   -->
<!-- Délai = date_prescription → date_analyse  -->
<!-- (différent du délai rapport = création groupe → dernier résultat) -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="bi bi-people me-2"></i>Performance des techniciens</h6>
        <small class="text-muted">Délai : prescription → résultat saisi</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Technicien</th>
                        <th class="text-center">Résultats saisis</th>
                        <th class="text-center">Délai moyen (prescription→résultat)</th>
                        <th class="text-center">Performance</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($performance_techniciens)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Aucune donnée pour cette période</td></tr>
                <?php else: ?>
                    <?php foreach ($performance_techniciens as $tech):
                        $dm   = (float)($tech['delai_moyen'] ?? 999);
                        $perf = $dm < 24 ? 'bg-success' : ($dm < 48 ? 'bg-info' : 'bg-warning');
                        $ptxt = $dm < 24 ? 'Excellent'  : ($dm < 48 ? 'Bon'     : 'À améliorer');
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($tech['prenom'] . ' ' . $tech['nom']) ?></strong></td>
                        <td class="text-center"><?= number_format($tech['nb_analyses']) ?></td>
                        <td class="text-center"><?= round($dm, 1) ?>h</td>
                        <td class="text-center">
                            <span class="badge <?= $perf ?> text-white"><?= $ptxt ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
const evolutionData   = <?= json_encode($analyses_par_jour) ?>;
const topAnalysesData = <?= json_encode($top_analyses) ?>;

if (document.getElementById('chartEvolution') && evolutionData.length > 0) {
    new Chart(document.getElementById('chartEvolution'), {
        type: 'line',
        data: {
            labels: evolutionData.map(d => d.date),
            datasets: [{
                label: 'Groupes créés',
                data: evolutionData.map(d => d.nombre),
                borderColor: '#6f42c1',
                backgroundColor: 'rgba(111,66,193,.1)',
                tension: .4, fill: true
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
}

if (document.getElementById('chartTopAnalyses') && topAnalysesData.length > 0) {
    new Chart(document.getElementById('chartTopAnalyses'), {
        type: 'bar',
        data: {
            labels: topAnalysesData.map(d =>
                d.libelle ? (d.libelle.length > 25 ? d.libelle.substring(0,25)+'…' : d.libelle) : '—'
            ),
            datasets: [{
                label: 'Nombre',
                data: topAnalysesData.map(d => d.nombre),
                backgroundColor: [
                    '#6f42c1','#20c997','#fd7e14','#dc3545','#0d6efd',
                    '#198754','#d63384','#0dcaf0','#ffc107','#6610f2'
                ]
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
}

function setPeriod(period) {
    const today = new Date();
    let start, end;
    switch (period) {
        case 'today':   start = end = today; break;
        case 'week': {
            const fd = new Date(today);
            fd.setDate(today.getDate() - today.getDay() + (today.getDay() === 0 ? -6 : 1));
            start = fd; end = today; break;
        }
        case 'month':
            start = new Date(today.getFullYear(), today.getMonth(), 1);
            end   = new Date(today.getFullYear(), today.getMonth()+1, 0); break;
        case 'quarter': {
            const q = Math.floor(today.getMonth()/3);
            start = new Date(today.getFullYear(), q*3, 1);
            end   = new Date(today.getFullYear(), q*3+3, 0); break;
        }
        case 'year':
            start = new Date(today.getFullYear(), 0, 1);
            end   = new Date(today.getFullYear(), 11, 31); break;
    }
    document.querySelector('input[name="date_debut"]').value = start.toISOString().split('T')[0];
    document.querySelector('input[name="date_fin"]').value   = end.toISOString().split('T')[0];
    document.querySelector('form').submit();
}
</script>

<style>
@media print {
    .btn-group,.btn,form,.sidebar,.top-header { display:none !important; }
    .main-content { margin:0 !important; padding:0 !important; }
}
.stat-card { transition:transform .2s; }
.stat-card:hover { transform:translateY(-2px); }
.stat-icon-wrapper {
    width:44px; height:44px; min-width:44px;
    border-radius:50%; display:flex; align-items:center; justify-content:center;
}
.stat-content { flex:1; min-width:0; }
.stat-title { font-size:.8rem; color:#6c757d; margin-bottom:4px; }
.stat-value { font-size:1.8rem; font-weight:700; line-height:1.2; margin:0; }
.stat-small { font-size:.75rem; color:#6c757d; }
</style>

<?php
// ══════════════════════════════════════════════════════════════════════════════
// HELPER DATE FR
// ══════════════════════════════════════════════════════════════════════════════
function date_fr(string $date_ymd): string {
    $jours = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
    $mois  = ['','Janvier','Février','Mars','Avril','Mai','Juin',
              'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    $ts    = strtotime($date_ymd);
    return $jours[date('w', $ts)] . ' ' . date('d', $ts) . ' '
         . $mois[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

// ══════════════════════════════════════════════════════════════════════════════
// REGISTRE JOURNALIER — examens groupés uniquement
// ══════════════════════════════════════════════════════════════════════════════
$date_registre = $_GET['date_registre'] ?? date('Y-m-d');

$stmt_unites = $conn_services->prepare("
    SELECT DISTINCT
        COALESCE(ss.idsous_specialite, 0)    AS idsous_specialite,
        COALESCE(ss.nom, 'Autres analyses')  AS unite_libelle,
        COALESCE(ss.nom, 'AUTRES')           AS unite_code
    FROM csk_base.resultatslabo r
    JOIN labo_echantillons le
        ON le.idactes_presc = r.idactes_presc AND le.deleted_at IS NULL
    JOIN labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
    JOIN csk_base.actes_presc ap ON ap.idactes_presc = le.idactes_presc
    JOIN csk_base.acte a ON a.idacte = ap.idacte
    LEFT JOIN csk_base.sous_specialite ss ON ss.idsous_specialite = a.idsous_specialite
    LEFT JOIN csk_base.specialite sp ON sp.idspecialite = ss.idspecialite
    WHERE sp.idspecialite = 24
      AND DATE(r.date_analyse) = :dr
    ORDER BY unite_libelle ASC
");
$stmt_unites->execute([':dr' => $date_registre]);
$unites = $stmt_unites->fetchAll(PDO::FETCH_ASSOC);

$registre = [];
foreach ($unites as $unite) {
    $stmt_l = $conn_services->prepare("
        SELECT
            CONCAT(p.nom, ' ', p.prenom)                    AS patient_nom,
            p.sexe         AS sexe,
            TIMESTAMPDIFF(YEAR, p.date_naissance, CURDATE()) AS age,
            a.libelle                                             AS examen_libelle,
            NULL                                              AS examen_code,
            r.resultat,
            r.valeur_normale                                  AS valeur_normale,
            r.interpretation                                  AS interpretation,
            r.observations                                    AS observations,
            r.date_analyse                                        AS date_analyse,
            ap.montant_total                                   AS montant_total,
            u.nom                                            AS analyste_nom,
            upr.nom                                          AS prescripteur_nom,
            NULL                                              AS numero_dossier
        FROM csk_base.resultatslabo r
        JOIN labo_echantillons le
            ON le.idactes_presc = r.idactes_presc AND le.deleted_at IS NULL
        JOIN labo_groupes_echantillons lg ON lg.idgroupe = le.idgroupe
        JOIN csk_base.actes_presc ap ON ap.idactes_presc = le.idactes_presc
        JOIN csk_base.acte a ON a.idacte = ap.idacte
        LEFT JOIN csk_base.sous_specialite ss2 ON ss2.idsous_specialite = a.idsous_specialite
        LEFT JOIN csk_base.specialite sp2 ON sp2.idspecialite = ss2.idspecialite
        JOIN csk_base.sous_sejour ss ON ss.idsous_sejour = ap.idsous_sejour
        JOIN csk_base.sejour sj ON sj.idsejour = ss.idsejour
        JOIN csk_base.patient p ON p.idpatient = sj.idpatient
        LEFT JOIN csk_base.utilisateur u ON u.idutilisateur = r.analyse_par
        LEFT JOIN csk_base.utilisateur upr ON upr.idutilisateur = ap.prescripteur
        WHERE sp2.idspecialite = 24
          AND COALESCE(ss2.idsous_specialite, 0) = :id
          AND DATE(r.date_analyse) = :dr
        ORDER BY a.libelle ASC, r.date_analyse ASC
    ");
    $stmt_l->execute([':id' => $unite['idsous_specialite'], ':dr' => $date_registre]);
    $lignes = $stmt_l->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($lignes)) {
        $registre[$unite['idsous_specialite']] = ['unite' => $unite, 'lignes' => $lignes];
    }
}

$date_fr_affichage = strtoupper(date_fr($date_registre));
$date_impression   = date('d/m/Y H:i');
?>

<!-- ══════════════════════════════════════════════════════════════════════════ -->
<!-- REGISTRE JOURNALIER                                                        -->
<!-- ══════════════════════════════════════════════════════════════════════════ -->
<hr class="my-5">

<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">
        <i class="bi bi-journal-text me-2" style="color:#0056b3;"></i>
        Registre journalier par unité d'analyse
    </h4>
    <button onclick="printRegistre(null)" class="btn btn-primary btn-sm">
        <i class="bi bi-printer me-1"></i> Imprimer tout le registre
    </button>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page"        value="labo">
            <input type="hidden" name="action"      value="rapport">
            <input type="hidden" name="date_debut"  value="<?= htmlspecialchars($date_debut) ?>">
            <input type="hidden" name="date_fin"    value="<?= htmlspecialchars($date_fin) ?>">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Date du registre</label>
                <input type="date" name="date_registre" class="form-control"
                       value="<?= htmlspecialchars($date_registre) ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search me-1"></i> Afficher
                </button>
            </div>
            <div class="col-md-6 text-muted small pt-3">
                <?= count($registre) ?> unité(s) —
                <?= array_sum(array_map(fn($u) => count($u['lignes']), $registre)) ?> patient(s)
            </div>
        </form>
    </div>
</div>

<div id="zone-registre">
<?php if (empty($registre)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>
        Aucun résultat enregistré le <?= date_fr($date_registre) ?>.
    </div>
<?php else: ?>
    <?php foreach ($registre as $idspecialite => $bloc):
        $unite  = $bloc['unite'];
        $lignes = $bloc['lignes'];
        $nb     = count($lignes);
    ?>
    <div class="registre-bloc mb-4" id="bloc-unite-<?= $idspecialite ?>">
        <div class="d-flex justify-content-between align-items-end mb-1">
            <div>
                <h5 class="mb-0 fw-bold" style="color:#0056b3;">
                    <?= htmlspecialchars(strtoupper($unite['unite_libelle'])) ?>
                    <?php if ($unite['unite_code']): ?>
                        <span class="badge bg-secondary ms-2" style="font-size:.75rem;">
                            <?= htmlspecialchars($unite['unite_code']) ?>
                        </span>
                    <?php endif; ?>
                </h5>
                <div class="text-muted small">
                    Cliniques Spécialisées de Kinshasa — Laboratoire &nbsp;|&nbsp;
                    <?= strtoupper(date_fr($date_registre)) ?>
                    &nbsp;|&nbsp; <?= $nb ?> patient(s)
                </div>
            </div>
            <button onclick="printRegistre('bloc-unite-<?= $idspecialite ?>')"
                    class="btn btn-outline-secondary btn-sm no-print">
                <i class="bi bi-printer me-1"></i> Cette unité seule
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm registre-table mb-0">
                <thead>
                    <tr class="registre-thead">
                        <th style="width:40px;"  class="text-center">N°</th>
                        <th style="width:180px;">Nom &amp; Prénom</th>
                        <th style="width:35px;"  class="text-center">Sexe</th>
                        <th style="width:45px;"  class="text-center">Âge</th>
                        <th style="width:130px;">Examen</th>
                        <th>Résultats</th>
                        <th style="width:110px;" class="text-center">Dr Prescripteur</th>
                        <th style="width:85px;"  class="text-center">Interp.</th>
                        <th style="width:75px;"  class="text-center">Montant</th>
                        <th style="width:60px;"  class="text-center">Heure</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lignes as $i => $ligne):
                    $interp    = $ligne['interpretation'] ?? '';
                    $row_class = match($interp) { 'critique'=>'table-danger', 'anormal'=>'table-warning', default=>'' };
                    $badge     = match($interp) {
                        'critique' => '<span class="badge bg-danger">Critique</span>',
                        'anormal'  => '<span class="badge bg-warning text-dark">Anormal</span>',
                        'normal'   => '<span class="badge bg-success">Normal</span>',
                        default    => '<span class="text-muted">—</span>',
                    };
                ?>
                <tr class="<?= $row_class ?>">
                    <td class="text-center fw-bold"><?= str_pad($i+1, 3, '0', STR_PAD_LEFT) ?></td>
                    <td class="fw-semibold">
                        <?= htmlspecialchars(strtoupper($ligne['patient_nom'])) ?>
                        <?php if ($ligne['numero_dossier']): ?>
                            <div class="text-muted" style="font-size:.7rem;">Doss. <?= htmlspecialchars($ligne['numero_dossier']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><?= htmlspecialchars($ligne['sexe'] ?? '—') ?></td>
                    <td class="text-center"><?= $ligne['age'] ?? '—' ?><span style="font-size:.7rem;"> ans</span></td>
                    <td style="font-size:.78rem;">
                        <span class="fw-semibold"><?= htmlspecialchars($ligne['examen_libelle'] ?? '—') ?></span>
                        <?php if (!empty($ligne['examen_code'])): ?>
                            <div class="text-muted" style="font-size:.68rem;"><?= htmlspecialchars($ligne['examen_code']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:.8rem;white-space:pre-wrap;">
                        <?= htmlspecialchars($ligne['resultat'] ?? '—') ?>
                        <?php if (!empty($ligne['valeur_normale'])): ?>
                            <div class="text-muted" style="font-size:.7rem;">Réf : <?= htmlspecialchars($ligne['valeur_normale']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($ligne['observations'])): ?>
                            <div class="fst-italic text-muted" style="font-size:.7rem;"><?= htmlspecialchars($ligne['observations']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-center" style="font-size:.78rem;">
                        <?= !empty($ligne['prescripteur_nom']) ? htmlspecialchars($ligne['prescripteur_nom']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-center"><?= $badge ?></td>
                    <td class="text-center" style="font-size:.78rem;white-space:nowrap;">
                        <?= isset($ligne['montant_total']) ? number_format((float)$ligne['montant_total'],0,',',' ').' FC' : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-center text-muted" style="font-size:.75rem;">
                        <?= $ligne['date_analyse'] ? date('H:i', strtotime($ligne['date_analyse'])) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="10" class="text-end"
                            style="font-size:.75rem;color:#444;border-top:2px solid #333;">
                            Technicien responsable : ___________________________________ &nbsp;&nbsp;
                            Signature : _______________
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<script>
function printRegistre(blocId) {
    let contenu;
    if (blocId) {
        const el = document.getElementById(blocId);
        if (!el) { alert('Bloc introuvable.'); return; }
        contenu = el.outerHTML;
    } else {
        contenu = document.getElementById('zone-registre').innerHTML;
    }
    const dateFr  = '<?= $date_fr_affichage ?>';
    const imprime = '<?= $date_impression ?>';
    const win = window.open('', '_blank', 'width=1000,height=750');
    win.document.write(`<!DOCTYPE html>
<html lang="fr"><head>
<meta charset="UTF-8">
<title>Registre — ${dateFr}</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Arial,sans-serif;font-size:9pt;color:#000;background:#fff}
.print-header{text-align:center;border-bottom:2px solid #003580;padding-bottom:6px;margin-bottom:14px}
.print-header .etablissement{font-size:13pt;font-weight:bold}
.print-header .service{font-size:9pt;color:#444}
.print-header .titre-registre{font-size:11pt;font-weight:bold;margin-top:4px}
.registre-bloc{margin-bottom:24px}
.registre-bloc h5{font-size:10.5pt;color:#003580;font-weight:bold;margin-bottom:2px}
table{width:100%;border-collapse:collapse}
th,td{border:1px solid #000;padding:3px 5px;vertical-align:top}
th{background:#d0d8e8!important;font-weight:bold;text-align:center;font-size:8.5pt;-webkit-print-color-adjust:exact;print-color-adjust:exact}
td{font-size:8.5pt}
.text-center{text-align:center}.text-end{text-align:right}
.fw-bold,.fw-semibold{font-weight:bold}.fst-italic{font-style:italic}
.table-danger{background:#ffe0e0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.table-warning{background:#fff5cc!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
.badge{display:inline-block;font-size:7pt;padding:1px 5px;border-radius:3px}
.bg-danger{background:#dc3545!important;color:#fff}.bg-warning{background:#ffc107!important;color:#000}
.bg-success{background:#198754!important;color:#fff}.bg-secondary{background:#6c757d!important;color:#fff}
tfoot td{font-size:8pt;color:#444;border-top:2px solid #000!important}
.registre-bloc{page-break-inside:avoid}
.no-print,button,.btn{display:none!important}
.print-footer{margin-top:16px;font-size:8pt;color:#777;text-align:right;border-top:1px solid #ccc;padding-top:4px}
.text-muted{color:#555}
</style></head><body>
<div class="print-header">
    <div class="etablissement">CLINIQUES SPÉCIALISÉES DE KINSHASA</div>
    <div class="service">Laboratoire d'analyses médicales</div>
    <div class="titre-registre">REGISTRE JOURNALIER DES ANALYSES — ${dateFr}</div>
</div>
${contenu}
<div class="print-footer">Imprimé le ${imprime} — Laboratoire CSK</div>
</body></html>`);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); win.close(); }, 500);
}
</script>

<style>
.registre-bloc { border:1px solid #dee2e6; border-radius:6px; padding:12px; }
.registre-thead th { background:#d0d8e8 !important; color:#003580; font-weight:bold; font-size:.8rem; white-space:nowrap; }
.registre-table td { vertical-align:middle; font-size:.82rem; }
.registre-table tfoot td { background:#f8f9fa; font-size:.75rem; color:#555; }
@media print { .no-print, button { display:none !important; } }
</style>