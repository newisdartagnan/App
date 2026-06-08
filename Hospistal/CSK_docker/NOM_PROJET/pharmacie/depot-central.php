<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// RÃ©cupÃ©rer les entrÃ©es rÃ©centes
$query_entrees = "SELECT e.*, p.libelle as produit, f.nom as fournisseur, u.nom as utilisateur
                  FROM pharma_entrees e
                  JOIN prodpharma p ON e.idprodpharma = p.idprodpharma
                  LEFT JOIN fournisseur f ON e.idfournisseur = f.idfournisseur
                  LEFT JOIN utilisateur u ON e.idutilisateur = u.idutilisateur
                  ORDER BY e.date_entree DESC
                  LIMIT 50";

$stmt_entrees = $db->prepare($query_entrees);
$stmt_entrees->execute();
$entrees = $stmt_entrees->fetchAll();

// RÃ©cupÃ©rer les fournisseurs
$fournisseurs = $db->query("SELECT * FROM fournisseur WHERE actif = 1 ORDER BY nom")->fetchAll();

// RÃ©cupÃ©rer les produits
$produits = $db->query("SELECT * FROM prodpharma WHERE actif = 1 ORDER BY libelle")->fetchAll();

$success = '';
$error = '';

// Traitement nouvelle entrÃ©e
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_entree'])) {
    try {
        $db->beginTransaction();
        
        // VÃ©rifier que tous les paramÃ¨tres sont prÃ©sents
        $required_params = ['idprodpharma', 'idfournisseur', 'quantite', 'prix_achat'];
        foreach ($required_params as $param) {
            if (!isset($_POST[$param]) || empty($_POST[$param])) {
                throw new Exception("Le paramÃ¨tre '$param' est manquant ou vide.");
            }
        }
        
        $query = "INSERT INTO pharma_entrees 
                  (idprodpharma, idfournisseur, quantite, prix_achat, date_entree, idutilisateur, observation)
                  VALUES 
                  (:idprodpharma, :idfournisseur, :quantite, :prix_achat, NOW(), :idutilisateur, :observation)";
        
        $stmt = $db->prepare($query);
        
        // PrÃ©parer les paramÃ¨tres
        $params = [
            ':idprodpharma' => (int)$_POST['idprodpharma'],
            ':idfournisseur' => (int)$_POST['idfournisseur'],
            ':quantite' => (int)$_POST['quantite'],
            ':prix_achat' => (float)$_POST['prix_achat'],
            ':idutilisateur' => (int)$_SESSION['user_id'],
            ':observation' => trim($_POST['observation']) ?: null
        ];
        
        $stmt->execute($params);
        
        $db->commit();
        $success = "EntrÃ©e en stock enregistrÃ©e avec succÃ¨s !";
        
        // Recharger la page pour afficher la nouvelle entrÃ©e
        header("Location: depot-central.php?success=1");
        exit();
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Erreur : " . $e->getMessage();
    }
}

// VÃ©rifier si succÃ¨s dans l'URL
if (isset($_GET['success'])) {
    $success = "EntrÃ©e en stock enregistrÃ©e avec succÃ¨s !";
}

$pageTitle = "DÃ©pÃ´t Central - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-warehouse"></i> DÃ©pÃ´t Central</h1>
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
        <h3 class="card-title">Nouvelle EntrÃ©e en Stock</h3>
    </div>
    <div class="card-body">
        <form method="POST" id="formEntree">
            <div class="form-grid">
                <div class="form-group">
                    <label for="idprodpharma">Produit *</label>
                    <select id="idprodpharma" name="idprodpharma" class="form-control" required>
                        <option value="">-- SÃ©lectionner un produit --</option>
                        <?php foreach ($produits as $produit): ?>
                            <option value="<?php echo $produit['idprodpharma']; ?>">
                                <?php echo htmlspecialchars($produit['libelle'] . ' - ' . $produit['code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="idfournisseur">Fournisseur *</label>
                    <select id="idfournisseur" name="idfournisseur" class="form-control" required>
                        <option value="">-- SÃ©lectionner un fournisseur --</option>
                        <?php foreach ($fournisseurs as $fournisseur): ?>
                            <option value="<?php echo $fournisseur['idfournisseur']; ?>">
                                <?php echo htmlspecialchars($fournisseur['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="quantite">QuantitÃ© *</label>
                    <input type="number" id="quantite" name="quantite" class="form-control" min="1" value="1" required>
                </div>
                
                <div class="form-group">
                    <label for="prix_achat">Prix d'Achat (FC) *</label>
                    <input type="number" id="prix_achat" name="prix_achat" class="form-control" step="0.01" min="0" value="0" required>
                </div>
            </div>
            
            <div class="form-group">
                <label for="observation">Observation</label>
                <textarea id="observation" name="observation" class="form-control" rows="3" placeholder="Observation facultative..."></textarea>
            </div>
            
            <div class="form-actions">
                <button type="submit" name="ajouter_entree" class="btn btn-primary btn-lg">
                    <i class="fas fa-plus-circle"></i> Ajouter l'EntrÃ©e
                </button>
                <button type="reset" class="btn btn-outline btn-lg">
                    <i class="fas fa-undo"></i> RÃ©initialiser
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">Historique des EntrÃ©es</h3>
    </div>
    <div class="card-body">
        <?php if (empty($entrees)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Aucune entrÃ©e enregistrÃ©e pour le moment.
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Produit</th>
                            <th>Fournisseur</th>
                            <th>QuantitÃ©</th>
                            <th>Prix Achat</th>
                            <th>Montant Total</th>
                            <th>Saisi par</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entrees as $entree): ?>
                        <tr>
                            <td><?php echo formatDate($entree['date_entree']); ?></td>
                            <td><strong><?php echo htmlspecialchars($entree['produit']); ?></strong></td>
                            <td><?php echo htmlspecialchars($entree['fournisseur'] ?? '-'); ?></td>
                            <td class="text-center">
                                <span class="badge badge-primary"><?php echo $entree['quantite']; ?></span>
                            </td>
                            <td><?php echo formatMoney($entree['prix_achat']); ?></td>
                            <td><strong><?php echo formatMoney($entree['quantite'] * $entree['prix_achat']); ?></strong></td>
                            <td><?php echo htmlspecialchars($entree['utilisateur'] ?? '-'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.form-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-start;
    margin-top: 20px;
}
</style>

<script>
// Validation du formulaire
document.getElementById('formEntree').addEventListener('submit', function(e) {
    const quantite = document.getElementById('quantite').value;
    const prix = document.getElementById('prix_achat').value;
    
    if (quantite <= 0) {
        alert('La quantitÃ© doit Ãªtre supÃ©rieure Ã   0.');
        e.preventDefault();
        return false;
    }
    
    if (prix < 0) {
        alert('Le prix ne peut pas Ãªtre nÃ©gatif.');
        e.preventDefault();
        return false;
    }
    
    return true;
});
</script>

<?php include '../views/includes/footer.php'; ?>