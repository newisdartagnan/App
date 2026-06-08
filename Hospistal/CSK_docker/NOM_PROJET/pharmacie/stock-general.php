<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// RÃ©cupÃ©rer le stock global par produit
$query = "SELECT p.*, 
                 f.nom as famille,
                 u.nom as unite,
                 SUM(sp.quantite) as stock_total,
                 COUNT(DISTINCT sp.idofficine) as nb_officines,
                 MIN(sp.quantite) as stock_min,
                 MAX(sp.quantite) as stock_max
          FROM prodpharma p
          LEFT JOIN stockpharma sp ON p.idprodpharma = sp.idprodpharma
          LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
          LEFT JOIN unite u ON p.idunite = u.idunite
          WHERE p.actif = 1
          GROUP BY p.idprodpharma
          ORDER BY p.libelle";

$stmt = $db->prepare($query);
$stmt->execute();
$stock_global = $stmt->fetchAll();

$pageTitle = "Stock GÃ©nÃ©ral - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-boxes"></i> Stock GÃ©nÃ©ral Pharmacie</h1>
    <div class="header-actions">
        <a href="index.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <button onclick="exportStock()" class="btn btn-info">
            <i class="fas fa-file-excel"></i> Exporter
        </button>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-pills"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo count($stock_global); ?></h3>
            <p>Produits diffÃ©rents</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
            <i class="fas fa-box"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo array_sum(array_column($stock_global, 'stock_total')); ?></h3>
            <p>UnitÃ©s totales</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
            <i class="fas fa-store"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo count(array_unique(array_column($stock_global, 'nb_officines'))); ?></h3>
            <p>Officines actives</p>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Etat du Stock Global</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table id="stockTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Produit</th>
                        <th>Famille</th>
                        <th>Type</th>
                        <th>UnitÃ©</th>
                        <th>Stock Total</th>
                        <th>Officines</th>
                        <th>Prix Achat</th>
                        <th>Prix Vente</th>
                        <th>Valeur Stock</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $valeur_totale = 0;
                    foreach ($stock_global as $produit): 
                        $valeur_stock = $produit['stock_total'] * $produit['prix_achat'];
                        $valeur_totale += $valeur_stock;
                        
                        $statut_class = '';
                        $statut_text = 'Normal';
                        
                        if ($produit['stock_total'] == 0) {
                            $statut_class = 'stock-rupture';
                            $statut_text = 'Rupture';
                        } elseif ($produit['stock_total'] <= $produit['seuil_alerte']) {
                            $statut_class = 'stock-alerte';
                            $statut_text = 'Alerte';
                        }
                    ?>
                    <tr class="<?php echo $statut_class; ?>">
                        <td><small><?php echo htmlspecialchars($produit['code']); ?></small></td>
                        <td><strong><?php echo htmlspecialchars($produit['libelle']); ?></strong></td>
                        <td><?php echo htmlspecialchars($produit['famille'] ?? '-'); ?></td>
                        <td>
                            <span class="badge <?php echo $produit['type_produit'] === 'medicament' ? 'badge-primary' : 'badge-info'; ?>">
                                <?php echo ucfirst($produit['type_produit']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($produit['unite'] ?? '-'); ?></td>
                        <td class="text-center">
                            <strong><?php echo $produit['stock_total'] ?? 0; ?></strong>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-secondary"><?php echo $produit['nb_officines']; ?></span>
                        </td>
                        <td><?php echo formatMoney($produit['prix_achat']); ?></td>
                        <td><?php echo formatMoney($produit['prix_vente_externe']); ?></td>
                        <td><?php echo formatMoney($valeur_stock); ?></td>
                        <td>
                            <span class="badge badge-<?php echo $statut_class ? 'danger' : 'success'; ?>">
                                <?php echo $statut_text; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: var(--light); font-weight: bold;">
                        <td colspan="9" style="text-align: right;">VALEUR TOTALE DU STOCK:</td>
                        <td colspan="2"><?php echo formatMoney($valeur_totale); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 20px;
}

.stat-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}

.stat-content h3 {
    font-size: 32px;
    font-weight: bold;
    margin: 0;
    color: var(--dark);
}

.stat-content p {
    margin: 5px 0 0 0;
    color: #64748b;
    font-size: 14px;
}

.stock-rupture {
    background: #fee2e2 !important;
}

.stock-alerte {
    background: #fef3c7 !important;
}
</style>

<script>
function exportStock() {
    exportToCSV('stockTable', 'stock-general-<?php echo date("Y-m-d"); ?>.csv');
}
</script>

<?php include '../views/includes/footer.php'; ?>