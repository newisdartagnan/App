<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// RÃ©cupÃ©rer les produits avec leur stock thÃ©orique
$query = "SELECT p.*, 
                 f.nom as famille,
                 u.nom as unite,
                 sp.quantite as stock_theorique,
                 sp.idofficine
          FROM prodpharma p
          LEFT JOIN stockpharma sp ON p.idprodpharma = sp.idprodpharma
          LEFT JOIN famiprod f ON p.idfamiprod = f.idfamiprod
          LEFT JOIN unite u ON p.idunite = u.idunite
          WHERE p.actif = 1
          ORDER BY p.libelle, sp.idofficine";

$stmt = $db->prepare($query);
$stmt->execute();
$produits = $stmt->fetchAll();

// RÃ©cupÃ©rer les officines
$officines = $db->query("SELECT * FROM officine WHERE actif = 1 ORDER BY nom")->fetchAll();

$success = '';
$error = '';

// Traitement de l'ajustement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajuster_stock'])) {
    try {
        $db->beginTransaction();
        
        $idprodpharma = $_POST['idprodpharma'];
        $idofficine = $_POST['idofficine'];
        $quantite_reelle = $_POST['quantite_reelle'];
        $observation = $_POST['observation'] ?? '';
        
        // VÃ©rifier si le stock existe
        $query_check = "SELECT * FROM stockpharma 
                       WHERE idprodpharma = :idprodpharma 
                       AND idofficine = :idofficine";
        $stmt_check = $db->prepare($query_check);
        $stmt_check->execute([
            ':idprodpharma' => $idprodpharma,
            ':idofficine' => $idofficine
        ]);
        $stock_existant = $stmt_check->fetch();
        
        if ($stock_existant) {
            // Mettre Ãƒ  jour le stock
            $query_update = "UPDATE stockpharma 
                           SET quantite = :quantite,
                               date_derniere_maj = NOW()
                           WHERE idprodpharma = :idprodpharma 
                           AND idofficine = :idofficine";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->execute([
                ':quantite' => $quantite_reelle,
                ':idprodpharma' => $idprodpharma,
                ':idofficine' => $idofficine
            ]);
        } else {
            // CrÃ©er le stock
            $query_insert = "INSERT INTO stockpharma 
                           (idprodpharma, idofficine, quantite, date_derniere_maj)
                           VALUES 
                           (:idprodpharma, :idofficine, :quantite, NOW())";
            $stmt_insert = $db->prepare($query_insert);
            $stmt_insert->execute([
                ':idprodpharma' => $idprodpharma,
                ':idofficine' => $idofficine,
                ':quantite' => $quantite_reelle
            ]);
        }
        
        // Enregistrer l'ajustement dans l'historique
        $query_histo = "INSERT INTO inventaire_ajustements 
                       (idprodpharma, idofficine, quantite_theorique, quantite_reelle, 
                        ecart, observation, idutilisateur, date_ajustement)
                       VALUES 
                       (:idprodpharma, :idofficine, :quantite_theorique, :quantite_reelle,
                        :ecart, :observation, :idutilisateur, NOW())";
        
        $stmt_histo = $db->prepare($query_histo);
        $stmt_histo->execute([
            ':idprodpharma' => $idprodpharma,
            ':idofficine' => $idofficine,
            ':quantite_theorique' => $_POST['quantite_theorique'],
            ':quantite_reelle' => $quantite_reelle,
            ':ecart' => $quantite_reelle - $_POST['quantite_theorique'],
            ':observation' => $observation,
            ':idutilisateur' => $_SESSION['user_id']
        ]);
        
        $db->commit();
        $success = "Stock ajustÃ© avec succÃƒÂ¨s !";
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Erreur : " . $e->getMessage();
    }
}

$pageTitle = "Inventaire - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-clipboard-list"></i> Inventaire des Stocks</h1>
    <a href="index.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Liste des Produits Ãƒ  Inventorier</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Code</th>
                        <th>Famille</th>
                        <th>Officine</th>
                        <th>Stock ThÃ©orique</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($produits as $produit): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($produit['libelle']); ?></strong></td>
                        <td><small><?php echo htmlspecialchars($produit['code']); ?></small></td>
                        <td><?php echo htmlspecialchars($produit['famille'] ?? '-'); ?></td>
                        <td>
                            <?php 
                            $officine_nom = '';
                            foreach ($officines as $off) {
                                if ($off['idofficine'] == $produit['idofficine']) {
                                    $officine_nom = $off['nom'];
                                    break;
                                }
                            }
                            echo htmlspecialchars($officine_nom);
                            ?>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-<?php echo $produit['stock_theorique'] > 0 ? 'primary' : 'danger'; ?>">
                                <?php echo $produit['stock_theorique'] ?? 0; ?>
                            </span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-warning" 
                                    onclick="ajusterStock(
                                        <?php echo $produit['idprodpharma']; ?>,
                                        '<?php echo htmlspecialchars($produit['libelle']); ?>',
                                        <?php echo $produit['idofficine']; ?>,
                                        '<?php echo htmlspecialchars($officine_nom); ?>',
                                        <?php echo $produit['stock_theorique'] ?? 0; ?>
                                    )">
                                <i class="fas fa-edit"></i> Ajuster
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal Ajustement Stock -->
<div id="ajusterStockModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-edit"></i> Ajustement de Stock</h3>
            <span class="close" onclick="closeModal('ajusterStockModal')">&times;</span>
        </div>
        <div class="modal-body">
            <form method="POST">
                <input type="hidden" id="idprodpharma" name="idprodpharma">
                <input type="hidden" id="idofficine" name="idofficine">
                <input type="hidden" id="quantite_theorique" name="quantite_theorique">
                
                <div class="form-group">
                    <label>Produit</label>
                    <p class="form-control-static" id="produit_libelle"></p>
                </div>
                
                <div class="form-group">
                    <label>Officine</label>
                    <p class="form-control-static" id="officine_nom"></p>
                </div>
                
                <div class="form-group">
                    <label>Stock ThÃ©orique</label>
                    <p class="form-control-static" id="stock_theorique_display"></p>
                </div>
                
                <div class="form-group">
                    <label for="quantite_reelle">Stock RÃ©el *</label>
                    <input type="number" id="quantite_reelle" name="quantite_reelle" class="form-control" min="0" required>
                </div>
                
                <div class="form-group">
                    <label for="observation">Observation</label>
                    <textarea id="observation" name="observation" class="form-control" rows="3" 
                              placeholder="Raison de l'ajustement..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="ajuster_stock" class="btn btn-primary">Enregistrer</button>
                    <button type="button" class="btn btn-outline" onclick="closeModal('ajusterStockModal')">Annuler</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 10% auto;
    padding: 0;
    border-radius: 12px;
    width: 90%;
    max-width: 500px;
}

.modal-header {
    padding: 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    margin: 0;
    color: var(--primary);
}

.close {
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    color: #64748b;
}

.close:hover {
    color: var(--dark);
}

.modal-body {
    padding: 20px;
}

.form-control-static {
    padding: 8px 12px;
    background: var(--light);
    border-radius: 6px;
    font-weight: 500;
}
</style>

<script>
function ajusterStock(idprodpharma, libelle, idofficine, officine_nom, stock_theorique) {
    document.getElementById('idprodpharma').value = idprodpharma;
    document.getElementById('idofficine').value = idofficine;
    document.getElementById('quantite_theorique').value = stock_theorique;
    
    document.getElementById('produit_libelle').textContent = libelle;
    document.getElementById('officine_nom').textContent = officine_nom;
    document.getElementById('stock_theorique_display').textContent = stock_theorique;
    document.getElementById('quantite_reelle').value = stock_theorique;
    
    document.getElementById('ajusterStockModal').style.display = 'block';
}

function closeModal(modalId) {
    document.getElementById(modalId).style.display = 'none';
}

// Fermer la modal en cliquant en dehors
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}
</script>

<?php include '../views/includes/footer.php'; ?>