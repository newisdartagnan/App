<?php
// pharmacie/sortie-directe.php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$idofficine = $_GET['idofficine'] ?? null;

if (!$idofficine) {
    redirect('stock-officine.php');
}

// RÃ©cupÃ©rer l'officine
$query_off = "SELECT * FROM officine WHERE idofficine = :id";
$stmt_off = $db->prepare($query_off);
$stmt_off->bindParam(':id', $idofficine);
$stmt_off->execute();
$officine = $stmt_off->fetch();

$success = '';
$error = '';

// Traitement de la sortie directe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['effectuer_sortie'])) {
    $idprodpharma = $_POST['produit'] ?? null;
    $quantite = $_POST['quantite'] ?? 0;
    $motif = $_POST['motif'] ?? '';
    
    if (!$idprodpharma || $quantite <= 0) {
        $error = "Veuillez sÃ©lectionner un produit et une quantitÃ© valide.";
    } else {
        try {
            $db->beginTransaction();
            
            // 1. VÃ©rifier le stock
            $query_stock = "SELECT quantite FROM stockpharma 
                           WHERE idprodpharma = :idprodpharma 
                           AND idofficine = :idofficine";
            $stmt_stock = $db->prepare($query_stock);
            $stmt_stock->execute([
                ':idprodpharma' => $idprodpharma,
                ':idofficine' => $idofficine
            ]);
            $stock = $stmt_stock->fetch();
            
            if (!$stock || $stock['quantite'] < $quantite) {
                throw new Exception("Stock insuffisant ! Disponible: " . ($stock['quantite'] ?? 0));
            }
            
            // 2. DÃ©duire du stock
            $query_update = "UPDATE stockpharma 
                            SET quantite = quantite - :quantite
                            WHERE idprodpharma = :idprodpharma 
                            AND idofficine = :idofficine";
            $stmt_update = $db->prepare($query_update);
            $stmt_update->execute([
                ':quantite' => $quantite,
                ':idprodpharma' => $idprodpharma,
                ':idofficine' => $idofficine
            ]);
            
            // 3. Enregistrer la sortie directe
            $query_sortie = "INSERT INTO sortie_directe 
                            (idofficine, idprodpharma, quantite, motif, idutilisateur)
                            VALUES 
                            (:idofficine, :idprodpharma, :quantite, :motif, :idutilisateur)";
            $stmt_sortie = $db->prepare($query_sortie);
            $stmt_sortie->execute([
                ':idofficine' => $idofficine,
                ':idprodpharma' => $idprodpharma,
                ':quantite' => $quantite,
                ':motif' => $motif,
                ':idutilisateur' => $_SESSION['user_id']
            ]);
            
            $db->commit();
            $success = "Sortie directe effectuÃ©e avec succÃ¨s.";
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Erreur : " . $e->getMessage();
        }
    }
}

// RÃ©cupÃ©rer les produits disponibles
$query_produits = "SELECT p.*, sp.quantite as stock_disponible
                   FROM prodpharma p
                   JOIN stockpharma sp ON p.idprodpharma = sp.idprodpharma
                   WHERE sp.idofficine = :idofficine
                   AND p.actif = 1
                   ORDER BY p.libelle";
$stmt_produits = $db->prepare($query_produits);
$stmt_produits->bindParam(':idofficine', $idofficine);
$stmt_produits->execute();
$produits = $stmt_produits->fetchAll();

$pageTitle = "Sortie Directe - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-sign-out-alt"></i> Sortie Directe</h1>
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
        <h3 class="card-title">Nouvelle Sortie Directe</h3>
    </div>
    <div class="card-body">
        <form method="POST">
            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label for="produit">Produit</label>
                    <select id="produit" name="produit" class="form-control" required>
                        <option value="">-- SÃ©lectionner un produit --</option>
                        <?php foreach ($produits as $produit): ?>
                            <option value="<?php echo $produit['idprodpharma']; ?>"
                                    data-stock="<?php echo $produit['stock_disponible']; ?>">
                                <?php echo htmlspecialchars($produit['libelle'] . ' - ' . $produit['code']); ?>
                                (Stock: <?php echo $produit['stock_disponible']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="quantite">QuantitÃ©</label>
                    <input type="number" id="quantite" name="quantite" 
                           class="form-control" min="1" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="motif">Motif de la sortie</label>
                <textarea id="motif" name="motif" class="form-control" 
                          rows="3" placeholder="Ex: Don Ã  un patient, Transfert, etc."></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="effectuer_sortie" class="btn btn-primary"
                        onclick="return confirm('Confirmer la sortie directe ? Cette action est irrÃ©versible.')">
                    <i class="fas fa-check"></i> Effectuer la Sortie
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('produit').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const stock = selectedOption.getAttribute('data-stock');
    if (stock) {
        document.getElementById('quantite').max = stock;
        document.getElementById('quantite').placeholder = `Max: ${stock}`;
    }
});
</script>

<?php include '../views/includes/footer.php'; ?>