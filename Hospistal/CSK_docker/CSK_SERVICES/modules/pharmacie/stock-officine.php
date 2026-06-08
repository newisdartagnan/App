<?php
/**
 * Module Pharmacie - Stock d'une officine
 * Gestion détaillée du stock par officine
 */

require_once __DIR__ . '/../../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

$idofficine = $_GET['idofficine'] ?? null;

if (!$idofficine) {
    redirect('index.php?page=pharmacie&action=officine');
}

// =============================================
// INFOS OFFICINE
// =============================================
$query_off = "SELECT o.*, s.nom as site_nom FROM officine o JOIN site s ON o.idsite = s.idsite WHERE o.idofficine = :id";
$stmt_off = $conn_base->prepare($query_off);
$stmt_off->bindParam(':id', $idofficine);
$stmt_off->execute();
$officine = $stmt_off->fetch();

// =============================================
// RÉCUPÉRATION DU STOCK
// =============================================
$query = "SELECT p.*, 
                 sp.quantite,
                 f.nom as famille,
                 u.nom as unite
          FROM stockpharma sp
          JOIN prodpharma p ON sp.idprodpharma = p.idprodpharma
          LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
          LEFT JOIN unite u ON p.idunite = u.idunite
          WHERE sp.idofficine = :idofficine
          AND p.actif = 1
          ORDER BY p.libelle";

$stmt = $conn_base->prepare($query);
$stmt->bindParam(':idofficine', $idofficine);
$stmt->execute();
$stock = $stmt->fetchAll();

// =============================================
// STATISTIQUES
// =============================================
$total_produits = count($stock);
$total_unites = array_sum(array_column($stock, 'quantite'));
$valeur_totale = 0;
$produits_rupture = 0;
$produits_alerte = 0;
$produits_reappro = 0;

foreach ($stock as $p) {
    $valeur_totale += $p['quantite'] * $p['prix_achat'];
    if ($p['quantite'] == 0) $produits_rupture++;
    elseif ($p['quantite'] <= $p['seuil_alerte']) $produits_alerte++;
    elseif ($p['quantite'] <= $p['seuil_reappro']) $produits_reappro++;
}
?>

<!-- ========================================= -->
<!-- EN-TÊTE -->
<!-- ========================================= -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-1"><i class="bi bi-boxes me-2" style="color: #198754;"></i>Stock - <?= htmlspecialchars($officine['nom']) ?></h4>
        <small class="text-muted"><?= htmlspecialchars($officine['site_nom']) ?></small>
    </div>
    <div class="btn-group">
        <a href="index.php?page=pharmacie&action=officine&idofficine=<?= $idofficine ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i> Retour</a>
        <a href="index.php?page=pharmacie&action=requisition&idofficine=<?= $idofficine ?>" class="btn btn-primary btn-sm"><i class="bi bi-file-import"></i> Réquisition</a>
        <a href="index.php?page=pharmacie&action=sortie-directe&idofficine=<?= $idofficine ?>" class="btn btn-warning btn-sm"><i class="bi bi-sign-out-alt"></i> Sortie directe</a>
        <button onclick="exportTableToExcel()" class="btn btn-success btn-sm"><i class="bi bi-file-earmark-excel"></i> Exporter</button>
    </div>
</div>

<!-- ========================================= -->
<!-- STATISTIQUES RAPIDES -->
<!-- ========================================= -->
<div class="row g-3 mb-4">
    <div class="col-md-2 col-6"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #d1fae5;"><i class="bi bi-capsule" style="color: #198754;"></i></div><div class="stat-value"><?= $total_produits ?></div><div class="stat-small">Produits</div></div></div></div>
    <div class="col-md-2 col-6"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #dbeafe;"><i class="bi bi-box" style="color: #2563eb;"></i></div><div class="stat-value"><?= number_format($total_unites) ?></div><div class="stat-small">Unités</div></div></div></div>
    <div class="col-md-3 col-6"><div class="card border-0 shadow-sm stat-card"><div class="card-body text-center"><div class="stat-icon-wrapper mx-auto mb-2" style="background: #fef3c7;"><i class="bi bi-currency-dollar" style="color: #f59e0b;"></i></div><div class="stat-value"><?= formatMoney($valeur_totale) ?></div><div class="stat-small">Valeur stock</div></div></div></div>
    <div class="col-md-5 col-12"><div class="card border-0 shadow-sm"><div class="card-body"><div class="d-flex justify-content-between align-items-center"><div class="text-center"><span class="badge bg-success" style="font-size: 1rem;"><?= $total_produits - $produits_rupture - $produits_alerte - $produits_reappro ?></span><div class="small">Normal</div></div><div class="text-center"><span class="badge bg-info" style="font-size: 1rem;"><?= $produits_reappro ?></span><div class="small">Réappro</div></div><div class="text-center"><span class="badge bg-warning" style="font-size: 1rem;"><?= $produits_alerte ?></span><div class="small">Alerte</div></div><div class="text-center"><span class="badge bg-danger" style="font-size: 1rem;"><?= $produits_rupture ?></span><div class="small">Rupture</div></div></div></div></div></div>
</div>

<!-- ========================================= -->
<!-- TABLEAU DU STOCK -->
<!-- ========================================= -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0"><i class="bi bi-table me-2"></i>État du stock</h6>
            <div class="d-flex gap-2">
                <input type="text" id="searchStock" class="form-control form-control-sm" placeholder="Rechercher..." style="width: 200px;">
                <select id="filterStatut" class="form-select form-select-sm" style="width: 150px;">
                    <option value="">Tous les statuts</option>
                    <option value="rupture">Rupture</option>
                    <option value="alerte">Alerte</option>
                    <option value="reappro">Réappro</option>
                    <option value="normal">Normal</option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="stockTable">
                <thead class="table-light"><tr>
                    <th>Produit</th><th>Code</th><th>Type</th><th>Famille</th><th class="text-center">Stock</th>
                    <th class="text-center">Seuil alerte</th><th class="text-center">Prix achat</th><th class="text-center">Prix vente</th>
                    <th class="text-center">Valeur</th><th>Statut</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($stock as $p): 
                        $valeur = $p['quantite'] * $p['prix_achat'];
                        $statut = '';
                        $badge = '';
                        $row_class = '';
                        if ($p['quantite'] == 0) { $statut = 'Rupture'; $badge = 'bg-danger'; $row_class = 'table-danger'; }
                        elseif ($p['quantite'] <= $p['seuil_alerte']) { $statut = 'Alerte'; $badge = 'bg-warning text-dark'; $row_class = 'table-warning'; }
                        elseif ($p['quantite'] <= $p['seuil_reappro']) { $statut = 'Réappro'; $badge = 'bg-info'; $row_class = 'table-info'; }
                        else { $statut = 'Normal'; $badge = 'bg-success'; $row_class = ''; }
                    ?>
                    <tr class="<?= $row_class ?>">
                        <td><strong><?= htmlspecialchars($p['libelle']) ?></strong></td>
                        <td><code><?= htmlspecialchars($p['code']) ?></code></td>
                        <td><?= getPharmaTypeProduitBadge($p['type_produit']) ?></td>
                        <td><?= htmlspecialchars($p['famille'] ?? '-') ?></td>
                        <td class="text-center fw-bold"><?= $p['quantite'] ?> <?= $p['unite'] ?></td>
                        <td class="text-center"><?= $p['seuil_alerte'] ?></td>
                        <td class="text-center"><?= formatMoney($p['prix_achat']) ?></td>
                        <td class="text-center"><?= formatMoney($p['prix_vente_externe']) ?></td>
                        <td class="text-center fw-bold"><?= formatMoney($valeur) ?></td>
                        <td><span class="badge <?= $badge ?>"><?= $statut ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light">
                    <tr><td colspan="8" class="text-end fw-bold">VALEUR TOTALE DU STOCK:</td><td class="text-center fw-bold"><?= formatMoney($valeur_totale) ?></td><td></td></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<script>
document.getElementById('searchStock').addEventListener('keyup', filterTable);
document.getElementById('filterStatut').addEventListener('change', filterTable);

function filterTable() {
    const searchText = document.getElementById('searchStock')?.value.toLowerCase() || '';
    const filterStatut = document.getElementById('filterStatut')?.value || '';
    const rows = document.querySelectorAll('#stockTable tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        const statutCell = row.querySelector('td:last-child .badge')?.textContent.trim().toLowerCase() || '';
        let show = true;
        if (searchText && !text.includes(searchText)) show = false;
        if (show && filterStatut) {
            const filtreLower = filterStatut.toLowerCase();
            if (filtreLower === 'rupture' && !statutCell.includes('rupture')) show = false;
            else if (filtreLower === 'alerte' && !statutCell.includes('alerte')) show = false;
            else if (filtreLower === 'reappro' && !statutCell.includes('réappro') && !statutCell.includes('reappro')) show = false;
            else if (filtreLower === 'normal' && !statutCell.includes('normal')) show = false;
        }
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
    link.download = 'stock_officine_<?= $idofficine ?>_' + new Date().toISOString().slice(0,10) + '.xls';
    link.click();
}
</script>