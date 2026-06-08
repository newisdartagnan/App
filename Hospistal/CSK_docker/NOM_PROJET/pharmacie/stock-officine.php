<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$idofficine = $_GET['idofficine'] ?? null;

if (!$idofficine) {
    redirect('officine.php');
}

// RÃ©cupÃ©rer les informations de l'officine
$query_off = "SELECT o.*, s.nom as site_nom 
              FROM officine o 
              JOIN site s ON o.idsite = s.idsite 
              WHERE o.idofficine = :id";
$stmt_off = $db->prepare($query_off);
$stmt_off->bindParam(':id', $idofficine);
$stmt_off->execute();
$officine = $stmt_off->fetch();

// RÃ©cupÃ©rer le stock
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

$stmt = $db->prepare($query);
$stmt->bindParam(':idofficine', $idofficine);
$stmt->execute();
$stock = $stmt->fetchAll();

$pageTitle = "Stock Officine - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-boxes"></i> Stock de l'Officine</h1>
    <div class="officine-badge">
        <strong><?php echo htmlspecialchars($officine['nom']); ?></strong>
        <span><?php echo htmlspecialchars($officine['site_nom']); ?></span>
    </div>
</div>

<div class="action-buttons">
    <a href="officine.php?idofficine=<?php echo $idofficine; ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
    <a href="sortie-directe.php?idofficine=<?php echo $idofficine; ?>" class="btn btn-secondary">
        <i class="fas fa-sign-out-alt"></i> Sortie Directe
    </a>
    <a href="requisition.php?idofficine=<?php echo $idofficine; ?>" class="btn btn-primary">
        <i class="fas fa-file-import"></i> RÃ©quisition
    </a>
    <button onclick="exportStock()" class="btn btn-info">
        <i class="fas fa-file-excel"></i> Exporter
    </button>
</div>

<div class="card mt-2">
    <div class="card-header flex-between">
        <h3 class="card-title">Ã‰tat du Stock</h3>
        <div class="stock-stats">
            <span class="stat-item">
                <i class="fas fa-pills"></i> 
                <?php echo count($stock); ?> produits
            </span>
        </div>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table id="stockTable">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Produit</th>
                        <th>Type</th>
                        <th>Famille</th>
                        <th>UnitÃ©</th>
                        <th>QuantitÃ©</th>
                        <th>Prix Achat</th>
                        <th>Prix Vente</th>
                        <th>Valeur Stock</th>
                        <th>Statut</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $valeur_totale = 0;
                    foreach ($stock as $item): 
                        $valeur_stock = $item['quantite'] * $item['prix_achat'];
                        $valeur_totale += $valeur_stock;
                        
                        $statut_class = '';
                        $statut_text = 'Normal';
                        
                        if ($item['quantite'] == 0) {
                            $statut_class = 'stock-rupture';
                            $statut_text = 'Rupture';
                        } elseif ($item['quantite'] <= $item['seuil_alerte']) {
                            $statut_class = 'stock-alerte';
                            $statut_text = 'Alerte';
                        } elseif ($item['quantite'] <= $item['seuil_reappro']) {
                            $statut_class = 'stock-reappro';
                            $statut_text = 'RÃ©appro';
                        }
                    ?>
                    <tr class="<?php echo $statut_class; ?>">
                        <td><small><?php echo htmlspecialchars($item['code']); ?></small></td>
                        <td><strong><?php echo htmlspecialchars($item['libelle']); ?></strong></td>
                        <td>
                            <span class="badge <?php echo $item['type_produit'] === 'medicament' ? 'badge-primary' : 'badge-info'; ?>">
                                <?php echo ucfirst($item['type_produit']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($item['famille'] ?? '-'); ?></td>
                        <td><?php echo htmlspecialchars($item['unite'] ?? '-'); ?></td>
                        <td class="text-center">
                            <strong><?php echo $item['quantite']; ?></strong>
                        </td>
                        <td><?php echo formatMoney($item['prix_achat']); ?></td>
                        <td><?php echo formatMoney($item['prix_vente_externe']); ?></td>
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
                        <td colspan="8" style="text-align: right;">VALEUR TOTALE DU STOCK:</td>
                        <td colspan="2"><?php echo formatMoney($valeur_totale); ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<style>
.officine-badge {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    background: var(--primary);
    color: white;
    padding: 10px 20px;
    border-radius: 8px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    margin: 20px 0;
}

.stock-stats {
    display: flex;
    gap: 20px;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 16px;
    background: var(--light);
    border-radius: 6px;
    font-weight: 500;
}

.stock-rupture {
    background: #fee2e2 !important;
}

.stock-alerte {
    background: #fef3c7 !important;
}

.stock-reappro {
    background: #dbeafe !important;
}
</style>

<script>
function exportStock() {
    exportToCSV('stockTable', 'stock-officine-<?php echo date("Y-m-d"); ?>.csv');
}
</script>

<?php include '../views/includes/footer.php'; ?>