<?php
/**
 * Module Pharmacie - Rapport statistique
 * Affiche les statistiques et graphiques de la pharmacie
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();
$conn_services = $db->getServicesConnection();

// =============================================
// GESTION DE L'EXPORT EXCEL
// =============================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    function exportExcel($filename, $headers, $rows) {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($output, $headers, "\t");
        foreach ($rows as $row) {
            fputcsv($output, $row, "\t");
        }
        fclose($output);
        exit();
    }
    
    $date_debut = $_GET['date_debut'] ?? date('Y-m-01');
    $date_fin = $_GET['date_fin'] ?? date('Y-m-t');
    
    // Top produits
    $query_top = "SELECT 
        pr.libelle,
        pr.code,
        COUNT(pp.idpharma_presc) as nb_prescriptions,
        SUM(pp.quantite) as quantite_totale,
        SUM(pp.montant_total) as chiffre_affaire
        FROM pharma_presc pp
        JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
        JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
        JOIN sejour s ON ss.idsejour = s.idsejour
        WHERE pp.source_prescription = 'csk_services'
        AND DATE(pp.date_prescription) BETWEEN :date_debut AND :date_fin
        GROUP BY pr.idprodpharma
        ORDER BY quantite_totale DESC
        LIMIT 20";
    
    $stmt_top = $conn_base->prepare($query_top);
    $stmt_top->bindParam(':date_debut', $date_debut);
    $stmt_top->bindParam(':date_fin', $date_fin);
    $stmt_top->execute();
    $top_produits = $stmt_top->fetchAll(PDO::FETCH_ASSOC);
    
    // Statistiques générales
    $query_stats = "SELECT 
        COUNT(DISTINCT pp.idpharma_presc) as total,
        COUNT(DISTINCT CASE WHEN pp.statut_execution = 'acheve' THEN pp.idpharma_presc END) as termines,
        COUNT(DISTINCT CASE WHEN pp.urgent = 1 THEN pp.idpharma_presc END) as urgents,
        SUM(pp.montant_total) as chiffre_affaire
        FROM pharma_presc pp
        JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
        JOIN sejour s ON ss.idsejour = s.idsejour
        WHERE pp.source_prescription = 'csk_services'
        AND DATE(pp.date_prescription) BETWEEN :date_debut AND :date_fin";
    
    $stmt_stats = $conn_base->prepare($query_stats);
    $stmt_stats->bindParam(':date_debut', $date_debut);
    $stmt_stats->bindParam(':date_fin', $date_fin);
    $stmt_stats->execute();
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    $headers = ['Produit', 'Code', 'Nb Prescriptions', 'Quantité Totale', 'Chiffre d\'Affaire'];
    $rows = [];
    
    foreach ($top_produits as $prod) {
        $rows[] = [
            $prod['libelle'],
            $prod['code'],
            $prod['nb_prescriptions'],
            $prod['quantite_totale'],
            formatMoney($prod['chiffre_affaire'])
        ];
    }
    
    $rows[] = ['', '', '', '', ''];
    $rows[] = ['STATISTIQUES GLOBALES', '', '', '', ''];
    $rows[] = ['Total prescriptions', $stats['total'] ?? 0, '', '', ''];
    $rows[] = ['Prescriptions terminées', $stats['termines'] ?? 0, '', '', ''];
    $rows[] = ['Prescriptions urgentes', $stats['urgents'] ?? 0, '', '', ''];
    $rows[] = ['Chiffre d\'affaire', '', '', '', formatMoney($stats['chiffre_affaire'] ?? 0)];
    
    exportExcel('rapport_pharmacie_' . date('Ymd_His') . '.xls', $headers, $rows);
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
    COUNT(DISTINCT pp.idpharma_presc) as total,
    COUNT(DISTINCT CASE WHEN pp.statut_execution = 'acheve' THEN pp.idpharma_presc END) as termines,
    COUNT(DISTINCT CASE WHEN pp.urgent = 1 THEN pp.idpharma_presc END) as urgents,
    COUNT(DISTINCT p.idpatient) as patients_servis,
    SUM(pp.montant_total) as chiffre_affaire,
    AVG(pp.montant_total) as montant_moyen,
    AVG(TIMESTAMPDIFF(HOUR, pp.date_prescription, pp.date_execution)) as delai_moyen_heures
    FROM pharma_presc pp
    JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
    JOIN sejour s ON ss.idsejour = s.idsejour
    JOIN patient p ON s.idpatient = p.idpatient
    WHERE pp.source_prescription = 'csk_services'
    AND DATE(pp.date_prescription) BETWEEN :date_debut AND :date_fin";

$stmt_stats = $conn_base->prepare($query_stats);
$stmt_stats->bindParam(':date_debut', $date_debut);
$stmt_stats->bindParam(':date_fin', $date_fin);
$stmt_stats->execute();
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$stats = array_map(function($val) { return $val ?? 0; }, $stats);

// =============================================
// TOP PRODUITS
// =============================================
$query_top_produits = "SELECT 
    pr.libelle,
    pr.code,
    pr.forme,
    COUNT(pp.idpharma_presc) as nb_prescriptions,
    SUM(pp.quantite) as quantite_totale,
    SUM(pp.montant_total) as chiffre_affaire
    FROM pharma_presc pp
    JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
    JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
    JOIN sejour s ON ss.idsejour = s.idsejour
    WHERE pp.source_prescription = 'csk_services'
    AND DATE(pp.date_prescription) BETWEEN :date_debut AND :date_fin
    GROUP BY pr.idprodpharma
    ORDER BY quantite_totale DESC
    LIMIT 10";

$stmt_top = $conn_base->prepare($query_top_produits);
$stmt_top->bindParam(':date_debut', $date_debut);
$stmt_top->bindParam(':date_fin', $date_fin);
$stmt_top->execute();
$top_produits = $stmt_top->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// ÉVOLUTION JOURNALIÈRE
// =============================================
$query_evolution = "SELECT 
    DATE(pp.date_prescription) as jour,
    COUNT(*) as nombre,
    SUM(CASE WHEN pp.statut_execution = 'acheve' THEN 1 ELSE 0 END) as termines,
    SUM(pp.montant_total) as chiffre_affaire
    FROM pharma_presc pp
    JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
    JOIN sejour s ON ss.idsejour = s.idsejour
    WHERE pp.source_prescription = 'csk_services'
    AND DATE(pp.date_prescription) BETWEEN :date_debut AND :date_fin
    GROUP BY DATE(pp.date_prescription)
    ORDER BY jour";

$stmt_evo = $conn_base->prepare($query_evolution);
$stmt_evo->bindParam(':date_debut', $date_debut);
$stmt_evo->bindParam(':date_fin', $date_fin);
$stmt_evo->execute();
$evolution = $stmt_evo->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// STATISTIQUES PAR TYPE DE PRODUIT
// =============================================
$query_types = "SELECT 
    pr.type_produit,
    COUNT(DISTINCT pp.idpharma_presc) as nb_prescriptions,
    SUM(pp.quantite) as quantite,
    SUM(pp.montant_total) as montant
    FROM pharma_presc pp
    JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
    JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
    JOIN sejour s ON ss.idsejour = s.idsejour
    WHERE pp.source_prescription = 'csk_services'
    AND DATE(pp.date_prescription) BETWEEN :date_debut AND :date_fin
    GROUP BY pr.type_produit
    ORDER BY montant DESC";

$stmt_types = $conn_base->prepare($query_types);
$stmt_types->bindParam(':date_debut', $date_debut);
$stmt_types->bindParam(':date_fin', $date_fin);
$stmt_types->execute();
$type_stats = $stmt_types->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// PERFORMANCE DES PHARMACIENS
// =============================================
$query_pharmaciens = "SELECT 
    u.nom,
    u.prenom,
    COUNT(DISTINCT h.idpreparation) as nb_preparations,
    COUNT(DISTINCT CASE WHEN h.action LIKE '%delivrance%' THEN h.idpreparation END) as nb_delivrances,
    COUNT(DISTINCT CASE WHEN h.action LIKE '%controle%' THEN h.idpreparation END) as nb_controles
    FROM pharmacie_workflow_history h
    JOIN csk_base.utilisateur u ON h.idutilisateur = u.idutilisateur
    WHERE DATE(h.created_at) BETWEEN :date_debut AND :date_fin
    GROUP BY h.idutilisateur
    ORDER BY nb_preparations DESC
    LIMIT 10";

$stmt_pharm = $conn_services->prepare($query_pharmaciens);
$stmt_pharm->bindParam(':date_debut', $date_debut);
$stmt_pharm->bindParam(':date_fin', $date_fin);
$stmt_pharm->execute();
$performance_pharmaciens = $stmt_pharm->fetchAll(PDO::FETCH_ASSOC);

$taux_completion = $stats['total'] > 0 
    ? round(($stats['termines'] / $stats['total']) * 100, 1) 
    : 0;
?>

<!-- ========================================= -->
<!-- EN-TÊTE DU RAPPORT -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-bar-chart-line me-2" style="color: #198754;"></i>
        Rapport statistique - Pharmacie
    </h4>
    <div class="btn-group">
        <a href="index.php?page=pharmacie&action=dashboard" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-printer"></i> Imprimer
        </button>
        <a href="index.php?page=pharmacie&action=rapport&export=excel&date_debut=<?= $date_debut ?>&date_fin=<?= $date_fin ?>" 
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
            <input type="hidden" name="page" value="pharmacie">
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
                <button type="submit" class="btn btn-primary w-100" style="background: #198754; border-color: #198754;">
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
<div class="row g-4 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background: #d1fae5;">
                    <i class="bi bi-prescription2 fs-4" style="color: #198754;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Total Prescriptions</div>
                    <div class="stat-value"><?= number_format($stats['total']) ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body">
                <div class="d-flex align-items-center">
                    <div class="stat-icon-wrapper me-3" style="background: #d1fae5;">
                        <i class="bi bi-check-circle fs-4" style="color: #10b981;"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-title">Prescriptions Terminées</div>
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
    
    <div class="col-xl-2 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background: #fee2e2;">
                    <i class="bi bi-exclamation-triangle fs-4" style="color: #dc2626;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Urgentes</div>
                    <div class="stat-value"><?= number_format($stats['urgents']) ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-2 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background: #dbeafe;">
                    <i class="bi bi-people fs-4" style="color: #2563eb;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Patients servis</div>
                    <div class="stat-value"><?= number_format($stats['patients_servis']) ?></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-2 col-md-6">
        <div class="card border-0 shadow-sm h-100 stat-card">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon-wrapper me-3" style="background: #fef3c7;">
                    <i class="bi bi-currency-dollar fs-4" style="color: #f59e0b;"></i>
                </div>
                <div class="stat-content">
                    <div class="stat-title">Chiffre d'affaire</div>
                    <div class="stat-value"><?= formatMoney($stats['chiffre_affaire']) ?></div>
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
                <h6 class="mb-0"><i class="bi bi-graph-up me-2"></i>Évolution quotidienne</h6>
            </div>
            <div class="card-body">
                <canvas id="chartEvolution" style="height: 300px;"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-pie-chart me-2"></i>Répartition par type</h6>
            </div>
            <div class="card-body">
                <canvas id="chartTypes" style="height: 300px;"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- TABLEAUX DES STATISTIQUES -->
<!-- ========================================= -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Top 10 produits les plus prescrits</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Produit</th>
                                <th>Code</th>
                                <th class="text-center">Prescriptions</th>
                                <th class="text-center">Quantité</th>
                                <th class="text-center">Chiffre d'affaire</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($top_produits)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        Aucune donnée pour cette période
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($top_produits as $prod): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($prod['libelle']) ?></strong>
                                        <?php if (!empty($prod['forme'])): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars($prod['forme']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?= htmlspecialchars($prod['code']) ?></code></td>
                                    <td class="text-center"><?= $prod['nb_prescriptions'] ?></td>
                                    <td class="text-center"><?= $prod['quantite_totale'] ?></td>
                                    <td class="text-center"><strong><?= formatMoney($prod['chiffre_affaire']) ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <h6 class="mb-0"><i class="bi bi-people me-2"></i>Performance des pharmaciens</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Pharmacien</th>
                                <th class="text-center">Préparations</th>
                                <th class="text-center">Délivrances</th>
                                <th class="text-center">Contrôles</th>
                                <th class="text-center">Performance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($performance_pharmaciens)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">
                                        Aucune donnée pour cette période
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($performance_pharmaciens as $ph): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($ph['prenom'] . ' ' . $ph['nom']) ?></strong>
                                    </td>
                                    <td class="text-center"><?= $ph['nb_preparations'] ?></td>
                                    <td class="text-center"><?= $ph['nb_delivrances'] ?></td>
                                    <td class="text-center"><?= $ph['nb_controles'] ?></td>
                                    <td class="text-center">
                                        <?php 
                                        $total = $ph['nb_preparations'] + $ph['nb_delivrances'] + $ph['nb_controles'];
                                        $perf = $total > 50 ? 'bg-success' : ($total > 20 ? 'bg-info' : 'bg-warning');
                                        $perf_text = $total > 50 ? 'Excellent' : ($total > 20 ? 'Bon' : 'Normal');
                                        ?>
                                        <span class="badge <?= $perf ?> text-white"><?= $perf_text ?></span>
                                    </td>
                                </table>
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
<!-- RÉPARTITION PAR TYPE (TABLEAU) -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white">
        <h6 class="mb-0"><i class="bi bi-bar-chart-steps me-2"></i>Répartition par type de produit</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Type de produit</th>
                        <th class="text-center">Prescriptions</th>
                        <th class="text-center">Quantité</th>
                        <th class="text-center">Montant</th>
                        <th class="text-center">Part (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($type_stats)): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Aucune donnée pour cette période
                            </td>
                        </tr>
                    <?php else: 
                        $total_montant = array_sum(array_column($type_stats, 'montant'));
                        foreach ($type_stats as $type):
                            $part = $total_montant > 0 ? round(($type['montant'] / $total_montant) * 100, 1) : 0;
                    ?>
                        <tr>
                            <td>
                                <strong><?= ucfirst(htmlspecialchars($type['type_produit'])) ?></strong>
                            </td>
                            <td class="text-center"><?= $type['nb_prescriptions'] ?></td>
                            <td class="text-center"><?= $type['quantite'] ?></td>
                            <td class="text-center"><strong><?= formatMoney($type['montant']) ?></strong></td>
                            <td class="text-center">
                                <span class="badge bg-info"><?= $part ?>%</span>
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
const evolutionData = <?= json_encode($evolution) ?>;
const typeData = <?= json_encode($type_stats) ?>;

if (document.getElementById('chartEvolution') && evolutionData.length > 0) {
    const ctxEvolution = document.getElementById('chartEvolution').getContext('2d');
    new Chart(ctxEvolution, {
        type: 'line',
        data: {
            labels: evolutionData.map(d => {
                const date = new Date(d.jour);
                return date.toLocaleDateString('fr-FR', { day: '2-digit', month: '2-digit' });
            }),
            datasets: [
                {
                    label: 'Prescriptions',
                    data: evolutionData.map(d => d.nombre),
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y'
                },
                {
                    label: 'Chiffre d\'affaire (milliers)',
                    data: evolutionData.map(d => d.chiffre_affaire / 1000),
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    tension: 0.4,
                    fill: true,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } },
            scales: {
                y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Nombre de prescriptions' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Chiffre d\'affaire (x1000 FC)' } }
            }
        }
    });
}

if (document.getElementById('chartTypes') && typeData.length > 0) {
    const ctxTypes = document.getElementById('chartTypes').getContext('2d');
    new Chart(ctxTypes, {
        type: 'doughnut',
        data: {
            labels: typeData.map(d => d.type_produit.charAt(0).toUpperCase() + d.type_produit.slice(1)),
            datasets: [{ data: typeData.map(d => d.montant), backgroundColor: ['#198754', '#0dcaf0', '#f59e0b', '#6f42c1', '#dc3545'] }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.label || '';
                            let value = context.raw || 0;
                            let total = context.dataset.data.reduce((a, b) => a + b, 0);
                            let percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                            return `${label}: ${formatMoney(value)} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

function formatMoney(value) {
    return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'FC' }).format(value);
}

function setPeriod(period) {
    const today = new Date();
    let start, end;
    
    switch(period) {
        case 'today': start = end = today; break;
        case 'week':
            const firstDay = new Date(today);
            firstDay.setDate(today.getDate() - today.getDay() + (today.getDay() === 0 ? -6 : 1));
            start = firstDay; end = today; break;
        case 'month':
            start = new Date(today.getFullYear(), today.getMonth(), 1);
            end = new Date(today.getFullYear(), today.getMonth() + 1, 0); break;
        case 'quarter':
            const quarter = Math.floor(today.getMonth() / 3);
            start = new Date(today.getFullYear(), quarter * 3, 1);
            end = new Date(today.getFullYear(), quarter * 3 + 3, 0); break;
        case 'year':
            start = new Date(today.getFullYear(), 0, 1);
            end = new Date(today.getFullYear(), 11, 31); break;
    }
    
    document.querySelector('input[name="date_debut"]').value = start.toISOString().split('T')[0];
    document.querySelector('input[name="date_fin"]').value = end.toISOString().split('T')[0];
    document.querySelector('form').submit();
}
</script>

<style>
@media print {
    .btn-group, .btn, form, .sidebar, .top-header { display: none !important; }
    .main-content { margin: 0 !important; padding: 0 !important; }
}
.stat-card { transition: transform 0.2s; }
.stat-card:hover { transform: translateY(-2px); }
.stat-icon-wrapper { width: 40px; height: 40px; min-width: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
.stat-icon-wrapper i { font-size: 1.5rem; }
.stat-content { flex: 1; min-width: 0; }
.stat-title { font-size: 0.8rem; color: #6c757d; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stat-value { font-size: 1.8rem; font-weight: 700; line-height: 1.2; margin: 0; }
.stat-small { font-size: 0.75rem; color: #6c757d; }
</style>