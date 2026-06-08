<?php
/**
 * Module Pharmacie - Stock Général
 * Vue d'ensemble du stock de tous les produits
 * CORRIGÉ - Suppression des colonnes inexistantes
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

// =============================================
// RÉCUPÉRATION DU STOCK GLOBAL - CORRIGÉ
// =============================================
$query = "SELECT 
    p.idprodpharma,
    p.libelle,
    p.code,
    p.type_produit,
    p.forme,
    p.prix_achat,
    p.prix_vente_externe as prix_vente,
    p.seuil_alerte,
    p.seuil_reappro,
    p.actif,
    f.nom as famille,
    u.nom as unite_nom,
    COALESCE(SUM(sp.quantite), 0) as stock_total,
    COUNT(DISTINCT sp.idofficine) as nb_officines,
    MIN(sp.quantite) as stock_min,
    MAX(sp.quantite) as stock_max
    FROM prodpharma p
    LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
    LEFT JOIN unite u ON p.idunite = u.idunite
    LEFT JOIN stockpharma sp ON p.idprodpharma = sp.idprodpharma
    WHERE p.actif = 1
    GROUP BY p.idprodpharma, p.libelle, p.code, p.type_produit, p.forme,
             p.prix_achat, p.prix_vente_externe, p.seuil_alerte, p.seuil_reappro, 
             p.actif, f.nom, u.nom
    ORDER BY p.libelle";

$stmt = $conn_base->prepare($query);
$stmt->execute();
$stock_global = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =============================================
// STATISTIQUES
// =============================================
$total_produits = count($stock_global);
$total_unites = array_sum(array_column($stock_global, 'stock_total'));
$valeur_totale = 0;
$produits_rupture = 0;
$produits_alerte = 0;

foreach ($stock_global as $p) {
    $valeur_totale += $p['stock_total'] * $p['prix_achat'];
    if ($p['stock_total'] == 0) $produits_rupture++;
    elseif ($p['stock_total'] <= $p['seuil_alerte']) $produits_alerte++;
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bi bi-boxes me-2" style="color: #198754;"></i>
        Stock Général Pharmacie
    </h4>
    <div class="btn-group">
        <a href="index.php?page=pharmacie&action=dashboard" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>
        <button onclick="exportTableToExcel()" class="btn btn-success btn-sm">
            <i class="bi bi-file-earmark-excel"></i> Exporter
        </button>
    </div>
</div>

<!-- ========================================= -->
<!-- STATISTIQUES RAPIDES -->
<!-- ========================================= -->
<div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body text-center">
                <div class="stat-icon-wrapper mx-auto mb-2" style="background: #d1fae5;">
                    <i class="bi bi-capsule" style="color: #198754;"></i>
                </div>
                <div class="stat-value"><?= $total_produits ?></div>
                <div class="stat-small">Produits différents</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body text-center">
                <div class="stat-icon-wrapper mx-auto mb-2" style="background: #dbeafe;">
                    <i class="bi bi-box" style="color: #2563eb;"></i>
                </div>
                <div class="stat-value"><?= number_format($total_unites) ?></div>
                <div class="stat-small">Unités totales</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body text-center">
                <div class="stat-icon-wrapper mx-auto mb-2" style="background: #fef3c7;">
                    <i class="bi bi-currency-dollar" style="color: #f59e0b;"></i>
                </div>
                <div class="stat-value"><?= formatMoney($valeur_totale) ?></div>
                <div class="stat-small">Valeur totale</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-6">
        <div class="card border-0 shadow-sm stat-card">
            <div class="card-body text-center">
                <div class="stat-icon-wrapper mx-auto mb-2" style="background: #fee2e2;">
                    <i class="bi bi-exclamation-triangle" style="color: #dc2626;"></i>
                </div>
                <div class="stat-value"><?= $produits_alerte + $produits_rupture ?></div>
                <div class="stat-small">Alertes (<?= $produits_rupture ?> rupture)</div>
            </div>
        </div>
    </div>
</div>

<!-- ========================================= -->
<!-- TABLEAU DU STOCK -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-2"></i>État du stock global</h6>
            <div class="d-flex gap-2">
                <input type="text" id="searchStock" class="form-control form-control-sm" placeholder="Rechercher..." style="width: 200px;">
                <select id="filterType" class="form-select form-select-sm" style="width: 150px;">
                    <option value="">Tous les types</option>
                    <option value="medicament">Médicaments</option>
                    <option value="consommable">Consommables</option>
                    <option value="dispositif_medical">DM</option>
                    <option value="reactif">Réactifs</option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="stockTable">
                <thead class="table-light">
                    <tr>
                        <th>Produit</th>
                        <th>Code</th>
                        <th>Type</th>
                        <th>Famille</th>
                        <th>Forme</th>
                        <th class="text-center">Stock total</th>
                        <th class="text-center">Officines</th>
                        <th class="text-center">Prix achat</th>
                        <th class="text-center">Prix vente</th>
                        <th class="text-center">Valeur stock</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stock_global as $p): 
                        $valeur = $p['stock_total'] * $p['prix_achat'];
                        $statut = '';
                        $badge = '';
                        $row_class = '';
                        
                        if ($p['stock_total'] == 0) {
                            $statut = 'Rupture';
                            $badge = 'bg-danger';
                            $row_class = 'table-danger';
                        } elseif ($p['stock_total'] <= $p['seuil_alerte']) {
                            $statut = 'Alerte';
                            $badge = 'bg-warning text-dark';
                            $row_class = 'table-warning';
                        } elseif ($p['stock_total'] <= $p['seuil_reappro']) {
                            $statut = 'Réappro';
                            $badge = 'bg-info';
                            $row_class = 'table-info';
                        } else {
                            $statut = 'Normal';
                            $badge = 'bg-success';
                            $row_class = '';
                        }
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td><strong><?= htmlspecialchars($p['libelle']) ?></strong></td>
                        <td><code><?= htmlspecialchars($p['code']) ?></code></td>
                        <td><?= getPharmaTypeProduitBadge($p['type_produit']) ?></td>
                        <td><?= htmlspecialchars($p['famille'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($p['forme'] ?? '-') ?></td>
                        <td class="text-center fw-bold"><?= $p['stock_total'] ?> <?= $p['unite_nom'] ?? '' ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $p['nb_officines'] ?></span></td>
                        <td class="text-center"><?= formatMoney($p['prix_achat']) ?></td>
                        <td class="text-center"><?= formatMoney($p['prix_vente']) ?></td>
                        <td class="text-center fw-bold"><?= formatMoney($valeur) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= $statut ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="9" class="text-end fw-bold">VALEUR TOTALE DU STOCK:</td>
                        <td class="text-center fw-bold"><?= formatMoney($valeur_totale) ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('searchStock').addEventListener('keyup', filterTable);
document.getElementById('filterType').addEventListener('change', filterTable);

function filterTable() {
    const searchText = document.getElementById('searchStock').value.toLowerCase();
    const filterType = document.getElementById('filterType').value.toLowerCase();
    const rows = document.querySelectorAll('#stockTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const typeCell = row.cells[2].textContent.toLowerCase();
        let show = true;
        if (searchText && !text.includes(searchText)) show = false;
        if (filterType && !typeCell.includes(filterType)) show = false;
        row.style.display = show ? '' : 'none';
    });
}

function exportTableToExcel() {
    const table = document.getElementById('stockTable');
    const rows = [];
    const headers = [];
    table.querySelectorAll('thead th').forEach(th => headers.push(th.textContent.trim()));
    rows.push(headers);
    table.querySelectorAll('tbody tr').forEach(tr => {
        if (tr.style.display !== 'none') {
            const row = [];
            tr.querySelectorAll('td').forEach(td => row.push(td.textContent.trim()));
            rows.push(row);
        }
    });
    let csv = rows.map(row => row.join('\t')).join('\n');
    const blob = new Blob(["\uFEFF" + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'stock-general_' + new Date().toISOString().slice(0,10) + '.xls';
    link.click();
}
</script>

<style>
.stat-card { transition: transform 0.2s; }
.stat-card:hover { transform: translateY(-2px); }
.stat-icon-wrapper { width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto; }
.stat-icon-wrapper i { font-size: 1.2rem; }
.stat-value { font-size: 1.5rem; font-weight: 700; line-height: 1.2; }
.stat-small { font-size: 0.75rem; color: #6c757d; }
</style>