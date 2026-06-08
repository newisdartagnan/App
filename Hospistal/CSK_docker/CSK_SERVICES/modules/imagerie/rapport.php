<?php
/**
 * Module Imagerie - Rapport statistique
 * Affiche les statistiques et graphiques de l'imagerie
 */

require_once __DIR__ . '/../../includes/imagerie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();
$conn_services = $db->getServicesConnection();

// =============================================
// GESTION DE L'EXPORT EXCEL
// =============================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Fonction d'export Excel
    function exportExcel($filename, $headers, $rows) {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        fputcsv($output, $headers, "\t");
        foreach ($rows as $row) {
            fputcsv($output, $row, "\t");
        }
        fclose($output);
        exit();
    }
    
    // Données pour l'export
    $type_stats_query = "SELECT 
        a.libelle,
        COUNT(*) as nombre,
        COUNT(CASE WHEN ap.statut_execution = 'termine' THEN 1 END) as termines,
        ROUND(COUNT(CASE WHEN ap.statut_execution = 'termine' THEN 1 END) * 100.0 / COUNT(*), 1) as taux_completion
        FROM actes_presc ap
        JOIN acte a ON ap.idacte = a.idacte
        WHERE a.idcategorie_acte IN (5, 10, 22)
        AND DATE(ap.date_prescription) BETWEEN :start_date AND :end_date
        GROUP BY a.idacte, a.libelle
        ORDER BY nombre DESC";

    $start_date = $_GET['start_date'] ?? date('Y-m-01');
    $end_date = $_GET['end_date'] ?? date('Y-m-t');
    
    $stmt = $conn_base->prepare($type_stats_query);
    $stmt->bindParam(':start_date', $start_date);
    $stmt->bindParam(':end_date', $end_date);
    $stmt->execute();
    $type_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Statistiques générales
    $stats_query = "SELECT 
        COUNT(DISTINCT ap.idactes_presc) as total,
        COUNT(DISTINCT CASE WHEN ap.statut_execution = 'termine' THEN ap.idactes_presc END) as termines,
        COUNT(DISTINCT CASE WHEN ap.urgent = 1 THEN ap.idactes_presc END) as urgents,
        COUNT(DISTINCT CASE WHEN ap.type_externe = 'externe' THEN ap.idactes_presc END) as externes,
        AVG(TIMESTAMPDIFF(HOUR, ap.date_prescription, ap.date_execution)) as delai_moyen
        FROM actes_presc ap
        JOIN acte a ON ap.idacte = a.idacte
        WHERE a.idcategorie_acte IN (5, 10, 22)
        AND DATE(ap.date_prescription) BETWEEN :start_date AND :end_date";
    
    $stmt_stats = $conn_base->prepare($stats_query);
    $stmt_stats->bindParam(':start_date', $start_date);
    $stmt_stats->bindParam(':end_date', $end_date);
    $stmt_stats->execute();
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    $headers = ['Type d\'Examen', 'Nombre Total', 'Terminés', 'Taux de Complétion (%)'];
    $rows = [];
    
    foreach ($type_stats as $stat) {
        $rows[] = [
            $stat['libelle'],
            $stat['nombre'],
            $stat['termines'],
            $stat['taux_completion'] . '%'
        ];
    }
    
    // Ajouter les statistiques globales
    $rows[] = ['', '', '', ''];
    $rows[] = ['STATISTIQUES GLOBALES', '', '', ''];
    $rows[] = ['Examens prescrits', $stats['total'] ?? 0, '', ''];
    $rows[] = ['Examens terminés', $stats['termines'] ?? 0, '', ''];
    $rows[] = ['Examens urgents', $stats['urgents'] ?? 0, '', ''];
    $rows[] = ['Examens externes', $stats['externes'] ?? 0, '', ''];
    $rows[] = ['Délai moyen (heures)', round($stats['delai_moyen'] ?? 0, 1), '', ''];
    
    exportExcel('rapport_imagerie_' . date('Ymd_His') . '.xls', $headers, $rows);
}

// =============================================
// PARAMÈTRES DE PÉRIODE
// =============================================
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-t');

// =============================================
// STATISTIQUES GÉNÉRALES
// =============================================
$query_stats = "SELECT 
    COUNT(DISTINCT ap.idactes_presc) as total,
    COUNT(DISTINCT CASE WHEN ap.statut_execution = 'termine' THEN ap.idactes_presc END) as termines,
    COUNT(DISTINCT CASE WHEN ap.urgent = 1 THEN ap.idactes_presc END) as urgents,
    COUNT(DISTINCT CASE WHEN ap.type_externe = 'externe' THEN ap.idactes_presc END) as externes,
    AVG(TIMESTAMPDIFF(HOUR, ap.date_prescription, ap.date_execution)) as delai_moyen
    FROM actes_presc ap
    JOIN acte a ON ap.idacte = a.idacte
    WHERE a.idcategorie_acte IN (5, 10, 22)
    AND DATE(ap.date_prescription) BETWEEN :date_debut AND :date_fin";

$stmt_stats = $conn_base->prepare($query_stats);
$stmt_stats->bindParam(':date_debut', $date_debut);
$stmt_stats->bindParam(':date_fin', $date_fin);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

// Valeurs par défaut si NULL
$stats = array_map(function($val) { return $val ?? 0; }, $stats);

// =============================================
// STATISTIQUES PAR TYPE D'EXAMEN
// =============================================
$type_stats_query = "SELECT 
    a.libelle,
    COUNT(*) as nombre,
    COUNT(CASE WHEN ap.statut_execution = 'termine' THEN 1 END) as termines,
    ROUND(COUNT(CASE WHEN ap.statut_execution = 'termine' THEN 1 END) * 100.0 / COUNT(*), 1) as taux_completion
    FROM actes_presc ap
    JOIN acte a ON ap.idacte = a.idacte
    WHERE a.idcategorie_acte IN (5, 10, 22)
    AND DATE(ap.date_prescription) BETWEEN :date_debut AND :date_fin
    GROUP BY a.idacte, a.libelle
    ORDER BY nombre DESC";

$stmt_type = $conn_base->prepare($type_stats_query);
$stmt_type->bindParam(':date_debut', $date_debut);
$stmt_type->bindParam(':date_fin', $date_fin);
$stmt_type->execute();
$type_stats = $stmt_type->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// ACTIVITÉ PAR JOUR
// =============================================
$daily_stats_query = "SELECT 
    DATE(ap.date_prescription) as jour,
    COUNT(*) as examens,
    COUNT(CASE WHEN ap.statut_execution = 'termine' THEN 1 END) as termines
    FROM actes_presc ap
    JOIN acte a ON ap.idacte = a.idacte
    WHERE a.idcategorie_acte IN (5, 10, 22)
    AND DATE(ap.date_prescription) BETWEEN :date_debut AND :date_fin
    GROUP BY DATE(ap.date_prescription)
    ORDER BY jour";

$stmt_daily = $conn_base->prepare($daily_stats_query);
$stmt_daily->bindParam(':date_debut', $date_debut);
$stmt_daily->bindParam(':date_fin', $date_fin);
$stmt_daily->execute();
$daily_stats = $stmt_daily->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// STATISTIQUES PAR MODALITÉ
// =============================================
$modalite_stats_query = "SELECT 
    CASE a.idcategorie_acte
        WHEN 5 THEN 'Radiologie'
        WHEN 10 THEN 'Échographie'
        WHEN 22 THEN 'Scanner/IRM'
        ELSE 'Autre'
    END as modalite,
    COUNT(*) as nombre,
    COUNT(CASE WHEN ap.statut_execution = 'termine' THEN 1 END) as termines
    FROM actes_presc ap
    JOIN acte a ON ap.idacte = a.idacte
    WHERE a.idcategorie_acte IN (5, 10, 22)
    AND DATE(ap.date_prescription) BETWEEN :date_debut AND :date_fin
    GROUP BY modalite
    ORDER BY nombre DESC";

$stmt_modalite = $conn_base->prepare($modalite_stats_query);
$stmt_modalite->bindParam(':date_debut', $date_debut);
$stmt_modalite->bindParam(':date_fin', $date_fin);
$stmt_modalite->execute();
$modalite_stats = $stmt_modalite->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// TOP RADIOLOGUES VALIDATEURS
// =============================================
$top_radiologues_query = "SELECT 
    u.nom,
    u.prenom,
    COUNT(DISTINCT ie.idexamen) as nb_examens,
    AVG(TIMESTAMPDIFF(HOUR, ie.date_rdv, COALESCE(ie.updated_at, NOW()))) as delai_moyen
    FROM imagerie_examens ie
    LEFT JOIN csk_base.utilisateur u ON ie.radiologue_validateur = u.idutilisateur
    WHERE DATE(ie.date_rdv) BETWEEN :date_debut AND :date_fin
    AND ie.radiologue_validateur IS NOT NULL
    GROUP BY ie.radiologue_validateur, u.nom, u.prenom
    ORDER BY nb_examens DESC
    LIMIT 10";

$stmt_radio = $conn_services->prepare($top_radiologues_query);
$stmt_radio->bindParam(':date_debut', $date_debut);
$stmt_radio->bindParam(':date_fin', $date_fin);
$stmt_radio->execute();
$top_radiologues = $stmt_radio->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// CALCULS
// =============================================
$taux_completion = $stats['total'] > 0 
    ? round(($stats['termines'] / $stats['total']) * 100, 1) 
    : 0;
?>

<!-- ========================================= -->
<!-- EN-TÊTE DU RAPPORT -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-bar-chart-line me-2" style="color: #0dcaf0;"></i>
        Rapport statistique - Imagerie
    </h4>
    <div class="btn-group">
        <a href="index.php?page=imagerie&action=dashboard" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Retour
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer"></i> Imprimer
        </button>
        <a href="index.php?page=imagerie&action=rapport&export=excel&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>" 
           class="btn btn-success btn-sm">
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
            <input type="hidden" name="page" value="imagerie">
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
                <button type="submit" class="btn btn-primary w-100" style="background: #0dcaf0; border-color: #0dcaf0;">
                    <i class="bi bi-search"></i> Générer
                </button>
            </div>
        </form>
        
        <div class="mt-3 d-flex gap-2 flex-wrap">
            <button onclick="setPeriod('today')" class="btn btn-sm btn-outline-secondary">Aujourd'hui</button>
            <button onclick="setPeriod('week')" class="btn btn-sm btn-outline-secondary">Cette semaine</button>
            <button onclick="setPeriod('month')" class="btn btn-sm btn-outline-secondary">Ce mois</button>
            <button onclick="setPeriod('quarter')" class="btn btn-sm btn-outline-secondary">Ce trimestre</button>
            <button onclick="setPeriod('year')" class="btn btn-sm btn-outline-secondary">Cette année</button>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- PÉRIODE AFFICHÉE -->
<!-- ========================================= -->
<div class="alert alert-primary mb-4" style="background: #e9ecef; border: none;">
    <i class="bi bi-calendar-range me-2"></i>
    Rapport du <strong><?= formatDate($date_debut) ?></strong> au <strong><?= formatDate($date_fin) ?></strong>
</div>

<!-- ========================================= -->
<!-- STATISTIQUES PRINCIPALES -->
<!-- ========================================= -->
<!-- Version avec classes CSS (identique au laboratoire) -->
<div class="row g-4 mb-4">
    <!-- Total examens -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background: #cff4fc;">
                    <i class="bi bi-image fs-4" style="color: #0dcaf0;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Total Examens</div>
                    <div class="stat-value"><?= number_format($stats['total']) ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Examens terminés -->
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon-wrapper me-3" style="background: #d1fae5;">
                        <i class="bi bi-check-circle" style="color: #10b981;"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Examens Terminés</div>
                        <div class="stat-value"><?= number_format($stats['termines']) ?></div>
                        <div class="stat-small"><?= $taux_completion ?>% complétion</div>
                        <div class="progress mt-2" style="height: 4px;">
                            <div class="progress-bar bg-success" style="width: <?= $taux_completion ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Examens urgents -->
    <div class="col-xl-2 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background: #fee2e2;">
                    <i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Urgents</div>
                    <div class="stat-value"><?= number_format($stats['urgents']) ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Délai moyen -->
    <div class="col-xl-2 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background: #dbeafe;">
                    <i class="bi bi-clock" style="color: #2563eb;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Délai moyen</div>
                    <div class="stat-value"><?= round($stats['delai_moyen'] ?? 0, 1) ?>h</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Examens externes -->
    <div class="col-xl-2 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background: #fff3cd;">
                    <i class="bi bi-building" style="color: #f59e0b;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Externes</div>
                    <div class="stat-value"><?= number_format($stats['externes']) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- GRAPHIQUES -->
<!-- ========================================= -->
<div class="row g-4 mb-4">
    <!-- Évolution quotidienne -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Évolution quotidienne</h6>
            </div>
            <div class="card-body">
                <canvas id="chartEvolution" style="height: 300px;"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Répartition par modalité -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Répartition par modalité</h6>
            </div>
            <div class="card-body">
                <canvas id="chartModalite" style="height: 300px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- TABLEAUX DES STATISTIQUES -->
<!-- ========================================= -->
<div class="row g-4 mb-4">
    <!-- Top 10 types d'examens -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Top 10 types d'examens</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Type d'examen</th>
                                <th class="text-center">Nombre</th>
                                <th class="text-center">Terminés</th>
                                <th class="text-center">Taux</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($type_stats)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        Aucune donnée pour cette période
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach (array_slice($type_stats, 0, 10) as $stat): ?>
                                <tr>
                                    <td><?= htmlspecialchars($stat['libelle']) ?></td>
                                    <td class="text-center"><?= $stat['nombre'] ?></td>
                                    <td class="text-center"><?= $stat['termines'] ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-info"><?= $stat['taux_completion'] ?>%</span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Performance des radiologues -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-people me-2"></i>Performance des radiologues</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Radiologue</th>
                                <th class="text-center">Examens</th>
                                <th class="text-center">Délai moyen</th>
                                <th class="text-center">Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_radiologues)): ?>
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        Aucune donnée pour cette période
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($top_radiologues as $rad): ?>
                                <tr>
                                    <td>
                                        <strong>Dr. <?= htmlspecialchars($rad['prenom'] . ' ' . $rad['nom']) ?></strong>
                                    </td>
                                    <td class="text-center"><?= $rad['nb_examens'] ?></td>
                                    <td class="text-center"><?= round($rad['delai_moyen'] ?? 0, 1) ?>h</td>
                                    <td class="text-center">
                                        <?php 
                                        $perf = ($rad['delai_moyen'] ?? 999) < 24 ? 'bg-success' : 
                                               (($rad['delai_moyen'] ?? 999) < 48 ? 'bg-info' : 'bg-warning');
                                        $perf_text = ($rad['delai_moyen'] ?? 999) < 24 ? 'Excellent' : 
                                                    (($rad['delai_moyen'] ?? 999) < 48 ? 'Bon' : 'À améliorer');
                                        ?>
                                        <span class="badge <?= $perf ?> text-white"><?= $perf_text ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- RÉPARTITION PAR MODALITÉ (TABLEAU) -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white">
        <h6 class="mb-0"><i class="bi bi-bar-chart-steps me-2"></i>Répartition par modalité</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Modalité</th>
                        <th class="text-center">Nombre</th>
                        <th class="text-center">Terminés</th>
                        <th class="text-center">Taux</th>
                        <th class="text-center">Progression</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($modalite_stats)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Aucune donnée pour cette période
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($modalite_stats as $modal): 
                            $taux = $modal['nombre'] > 0 ? round(($modal['termines'] / $modal['nombre']) * 100, 1) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($modal['modalite']) ?></strong>
                            </td>
                            <td class="text-center"><?= $modal['nombre'] ?></td>
                            <td class="text-center"><?= $modal['termines'] ?></td>
                            <td class="text-center">
                                <span class="badge bg-info"><?= $taux ?>%</span>
                            </td>
                            <td class="text-center" style="width: 200px;">
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar bg-success" style="width: <?= $taux ?>%"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Scripts pour les graphiques et périodes -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Données pour les graphiques
const dailyData = <?= json_encode($daily_stats) ?>;
const modaliteData = <?= json_encode($modalite_stats) ?>;

// Graphique évolution
if (document.getElementById('chartEvolution') && dailyData.length > 0) {
    const ctxEvolution = document.getElementById('chartEvolution').getContext('2d');
    new Chart(ctxEvolution, {
        type: 'line',
        data: {
            labels: dailyData.map(d => {
                const date = new Date(d.jour);
                return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
            }),
            datasets: [
                {
                    label: 'Examens',
                    data: dailyData.map(d => d.examens),
                    borderColor: '#0dcaf0',
                    backgroundColor: 'rgba(13, 202, 240, 0.1)',
                    tension: 0.4,
                    fill: true
                },
                {
                    label: 'Terminés',
                    data: dailyData.map(d => d.termines),
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    tension: 0.4,
                    fill: true
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            },
            scales: {
                y: { 
                    beginAtZero: true, 
                    ticks: { precision: 0 } 
                }
            }
        }
    });
}

// Graphique répartition par modalité
if (document.getElementById('chartModalite') && modaliteData.length > 0) {
    const ctxModalite = document.getElementById('chartModalite').getContext('2d');
    new Chart(ctxModalite, {
        type: 'doughnut',
        data: {
            labels: modaliteData.map(d => d.modalite),
            datasets: [{
                data: modaliteData.map(d => d.nombre),
                backgroundColor: ['#0dcaf0', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

// Fonctions période rapide
function setPeriod(period) {
    const today = new Date();
    let start, end;
    
    switch(period) {
        case 'today':
            start = end = today;
            break;
        case 'week':
            const firstDay = new Date(today);
            firstDay.setDate(today.getDate() - today.getDay() + (today.getDay() === 0 ? -6 : 1));
            start = firstDay;
            end = today;
            break;
        case 'month':
            start = new Date(today.getFullYear(), today.getMonth(), 1);
            end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            break;
        case 'quarter':
            const quarter = Math.floor(today.getMonth() / 3);
            start = new Date(today.getFullYear(), quarter * 3, 1);
            end = new Date(today.getFullYear(), quarter * 3 + 3, 0);
            break;
        case 'year':
            start = new Date(today.getFullYear(), 0, 1);
            end = new Date(today.getFullYear(), 11, 31);
            break;
    }
    
    document.querySelector('input[name="date_debut"]').value = start.toISOString().split('T')[0];
    document.querySelector('input[name="date_fin"]').value = end.toISOString().split('T')[0];
    document.querySelector('form').submit();
}
</script>

<style>
@media print {
    .btn-group, .btn, form, .sidebar, .top-header {
        display: none !important;
    }
    .main-content {
        margin: 0 !important;
        padding: 0 !important;
    }
}

.stat-card {
    transition: transform 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
}

.stat-icon-wrapper {
    width: 40px;
    height: 40px;
    min-width: 40px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}

.stat-icon-wrapper i {
    font-size: 1.5rem;
}

.stat-content {
    flex: 1;
    min-width: 0; /* Évite le débordement */
}

.stat-title {
    font-size: 0.8rem;
    color: #6c757d;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    line-height: 1.2;
    margin: 0;
}

.stat-small {
    font-size: 0.75rem;
    color: #6c757d;
}
</style>