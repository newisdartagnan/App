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

// RÃ©cupÃ©rer l'officine
$query_off = "SELECT * FROM officine WHERE idofficine = :id";
$stmt_off = $db->prepare($query_off);
$stmt_off->bindParam(':id', $idofficine);
$stmt_off->execute();
$officine = $stmt_off->fetch();

$success = '';
$error = '';

// Traitement de la crÃ©ation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['creer_requisition'])) {
    $produits = $_POST['produits'] ?? [];
    $quantites = $_POST['quantites'] ?? [];
    
    if (empty($produits)) {
        $error = "Veuillez sÃ©lectionner au moins un produit.";
    } else {
        try {
            $db->beginTransaction();
            
            // GÃ©nÃ©rer le numÃ©ro de rÃ©quisition
            $query_num = "SELECT MAX(CAST(SUBSTRING(numero_requisition, 4) AS UNSIGNED)) as last_num 
                          FROM requisition 
                          WHERE numero_requisition LIKE 'REQ%'";
            $stmt_num = $db->prepare($query_num);
            $stmt_num->execute();
            $row_num = $stmt_num->fetch();
            $lastNumber = $row_num['last_num'] ?? 0;
            $numero_requisition = generateNumero('REQ', $lastNumber);
            
            // CrÃ©er la rÃ©quisition
            $query = "INSERT INTO requisition 
                      (idofficine, numero_requisition, idutilisateur)
                      VALUES 
                      (:idofficine, :numero_requisition, :idutilisateur)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':idofficine', $idofficine);
            $stmt->bindParam(':numero_requisition', $numero_requisition);
            $stmt->bindParam(':idutilisateur', $_SESSION['user_id']);
            $stmt->execute();
            
            $idrequisition = $db->lastInsertId();
            
            // Ajouter les lignes
            $query_ligne = "INSERT INTO lignesrecquisition 
                           (idrequisition, idprodpharma, quantite_demandee)
                           VALUES 
                           (:idrequisition, :idprodpharma, :quantite)";
            
            $stmt_ligne = $db->prepare($query_ligne);
            
            foreach ($produits as $index => $idprodpharma) {
                if (!empty($idprodpharma) && !empty($quantites[$index]) && $quantites[$index] > 0) {
                    $stmt_ligne->bindParam(':idrequisition', $idrequisition);
                    $stmt_ligne->bindParam(':idprodpharma', $idprodpharma);
                    $stmt_ligne->bindParam(':quantite', $quantites[$index]);
                    $stmt_ligne->execute();
                }
            }
            
            $db->commit();
            $success = "RÃ©quisition crÃ©Ã©e avec succÃ¨s ! NÂ°: " . $numero_requisition;
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur : " . $e->getMessage();
        }
    }
}

// RÃ©cupÃ©rer l'historique des rÃ©quisitions
$query_hist = "SELECT r.*, u.nom as user_nom, u.prenom as user_prenom
               FROM requisition r
               JOIN utilisateur u ON r.idutilisateur = u.idutilisateur
               WHERE r.idofficine = :idofficine
               ORDER BY r.date_requisition DESC
               LIMIT 20";
$stmt_hist = $db->prepare($query_hist);
$stmt_hist->bindParam(':idofficine', $idofficine);
$stmt_hist->execute();
$historique = $stmt_hist->fetchAll();

$pageTitle = "RÃ©quisition - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-file-import"></i> Nouvelle RÃ©quisition</h1>
    <a href="stock-officine.php?idofficine=<?php echo $idofficine; ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour au stock
    </a>
</div>

<div class="officine-info">
    <strong>Officine:</strong> <?php echo htmlspecialchars($officine['nom']); ?>
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
        <h3 class="card-title">Produits Ã  RÃ©quisitionner</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <div id="produitsContainer">
                <div class="produit-ligne">
                    <div class="form-row">
                        <div class="form-group" style="flex: 2;">
                            <label>Produit</label>
                            <select name="produits[]" class="form-control produit-select" required>
                                <option value="">-- SÃ©lectionner un produit --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>QuantitÃ©</label>
                            <input type="number" name="quantites[]" class="form-control" min="1" required>
                        </div>
                        <div class="form-group" style="align-self: flex-end;">
                            <button type="button" class="btn btn-danger btn-sm" onclick="removeLigne(this)">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-actions mt-2">
                <button type="button" class="btn btn-secondary" onclick="addLigne()">
                    <i class="fas fa-plus"></i> Ajouter un produit
                </button>
                <button type="submit" name="creer_requisition" class="btn btn-primary btn-lg">
                    <i class="fas fa-paper-plane"></i> Envoyer la RÃ©quisition
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Historique -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">Historique des RÃ©quisitions</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>NÂ° RÃ©quisition</th>
                        <th>Date</th>
                        <th>DemandÃ© par</th>
                        <th>Statut</th>
                        <th>Date Traitement</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($historique as $req): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($req['numero_requisition']); ?></strong></td>
                        <td><?php echo formatDateTime($req['date_requisition']); ?></td>
                        <td><?php echo htmlspecialchars($req['user_prenom'] . ' ' . $req['user_nom']); ?></td>
                        <td>
                            <?php
                            $badge_class = [
                                'en_attente' => 'badge-warning',
                                'servi' => 'badge-success',
                                'refuse' => 'badge-danger'
                            ][$req['statut']];
                            ?>
                            <span class="badge <?php echo $badge_class; ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $req['statut'])); ?>
                            </span>
                        </td>
                        <td><?php echo $req['date_traitement'] ? formatDateTime($req['date_traitement']) : '-'; ?></td>
                        <td>
                            <a href="detail-requisition.php?id=<?php echo $req['idrequisition']; ?>" 
                               class="btn btn-sm btn-info">
                                <i class="fas fa-eye"></i> Voir
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
.produit-ligne {
    margin-bottom: 15px;
    padding: 15px;
    background: var(--light);
    border-radius: 8px;
}

.officine-info {
    background: white;
    padding: 15px 20px;
    border-radius: 8px;
    box-shadow: var(--shadow);
    margin: 20px 0;
    font-size: 16px;
}

.officine-info strong {
    color: var(--primary);
}
</style>

<script>
// Charger les produits disponibles
let produits = [];

fetch('../api/get-produits-pharma.php?idofficine=<?php echo $idofficine; ?>')
    .then(response => response.json())
    .then(data => {
        produits = data;
        updateAllSelects();
    });

function updateAllSelects() {
    document.querySelectorAll('.produit-select').forEach(select => {
        updateSelect(select);
    });
}

function updateSelect(select) {
    const currentValue = select.value;
    let html = '<option value="">-- SÃ©lectionner un produit --</option>';
    
    produits.forEach(p => {
        html += `<option value="${p.idprodpharma}">${p.libelle} - ${p.code} (Stock: ${p.stock_disponible})</option>`;
    });
    
    select.innerHTML = html;
    if (currentValue) {
        select.value = currentValue;
    }
}

function addLigne() {
    const container = document.getElementById('produitsContainer');
    const newLigne = container.firstElementChild.cloneNode(true);
    
    // RÃ©initialiser les valeurs
    newLigne.querySelectorAll('input, select').forEach(input => {
        input.value = '';
    });
    
    container.appendChild(newLigne);
    updateAllSelects();
}

function removeLigne(button) {
    const container = document.getElementById('produitsContainer');
    if (container.children.length > 1) {
        button.closest('.produit-ligne').remove();
    } else {
        alert('Au moins un produit doit Ãªtre conservÃ©.');
    }
}
</script>

<?php include '../views/includes/footer.php'; ?>