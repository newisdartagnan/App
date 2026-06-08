<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();
$prescription = new PrescriptionMedicale($db);

$idpharma_presc = $_GET['id'] ?? null;

if (!$idpharma_presc) {
    redirect('officine.php');
}

// RÃ©cupÃ©rer la prescription
$query = "SELECT pp.*, pr.libelle as produit_libelle, pr.code as produit_code,
                 p.nom as patient_nom, p.prenom as patient_prenom,
                 u.nom as prescripteur_nom,
                 sp.quantite as stock_disponible
          FROM pharma_presc pp
          JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
          JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
          JOIN sejour s ON ss.idsejour = s.idsejour
          JOIN patient p ON s.idpatient = p.idpatient
          LEFT JOIN utilisateur u ON pp.prescripteur = u.idutilisateur
          LEFT JOIN stockpharma sp ON pr.idprodpharma = sp.idprodpharma 
                                   AND sp.idofficine = (SELECT idofficine FROM officine WHERE idsite = s.idsite LIMIT 1)
          WHERE pp.idpharma_presc = :id";

$stmt = $db->prepare($query);
$stmt->bindParam(':id', $idpharma_presc);
$stmt->execute();
$presc = $stmt->fetch();

$success = '';
$error = '';

// AchÃ¨vement de la prescription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['achever'])) {
    try {
        $db->beginTransaction();
        
        // 1. VÃ©rifier le stock
        if ($presc['stock_disponible'] < $presc['quantite']) {
            throw new Exception("Stock insuffisant ! Disponible: {$presc['stock_disponible']}, DemandÃ©: {$presc['quantite']}");
        }
        
        // 2. DÃ©duire du stock
        $query_stock = "UPDATE stockpharma 
                       SET quantite = quantite - :quantite
                       WHERE idprodpharma = :idprodpharma 
                       AND idofficine = (SELECT idofficine FROM officine WHERE idsite = :idsite LIMIT 1)";
        
        $stmt_stock = $db->prepare($query_stock);
        $stmt_stock->execute([
            ':quantite' => $presc['quantite'],
            ':idprodpharma' => $presc['idprodpharma'],
            ':idsite' => $_SESSION['site_id']
        ]);
        
        // 3. Mettre Ã  jour la prescription
        $query_update = "UPDATE pharma_presc 
                        SET statut_execution = 'acheve',
                            date_execution = NOW(),
                            executeur = :executeur
                        WHERE idpharma_presc = :id";
        
        $stmt_update = $db->prepare($query_update);
        $stmt_update->execute([
            ':executeur' => $_SESSION['user_id'],
            ':id' => $idpharma_presc
        ]);
        
        // 4. Notifier le prescripteur
        $prescription->notifierPrescripteurLivraison($idpharma_presc);
        
        $db->commit();
        $success = "MÃ©dicament dÃ©livrÃ© et mÃ©decin notifiÃ© !";
        
        // Rediriger
        header("Location: officine.php?success=1");
        exit();
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Erreur : " . $e->getMessage();
    }
}

$pageTitle = "DÃ©livrance MÃ©dicament - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-pills"></i> DÃ©livrance de MÃ©dicament</h1>
    <a href="officine.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<div class="prescription-pharma-card">
    <div class="section">
        <h3>Patient</h3>
        <p><strong><?php echo htmlspecialchars($presc['patient_nom'] . ' ' . $presc['patient_prenom']); ?></strong></p>
    </div>
    
    <div class="section">
        <h3>MÃ©dicament</h3>
        <p><strong><?php echo htmlspecialchars($presc['produit_libelle']); ?></strong></p>
        <p>Code: <?php echo htmlspecialchars($presc['produit_code']); ?></p>
    </div>
    
    <div class="section">
        <h3>QuantitÃ©</h3>
        <p class="big-number"><?php echo $presc['quantite']; ?></p>
    </div>
    
    <div class="section">
        <h3>Stock Disponible</h3>
        <p class="big-number <?php echo $presc['stock_disponible'] < $presc['quantite'] ? 'text-danger' : 'text-success'; ?>">
            <?php echo $presc['stock_disponible'] ?? 0; ?>
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Posologie</h3>
    </div>
    <div class="card-body">
        <p class="posologie-text"><?php echo nl2br(htmlspecialchars($presc['posologie'])); ?></p>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
    </div>
<?php endif; ?>

<?php if ($presc['stock_disponible'] >= $presc['quantite']): ?>
    <form method="POST">
        <div class="form-actions">
            <button type="submit" name="achever" class="btn btn-success btn-lg" 
                    onclick="return confirm('Confirmer la dÃ©livrance de ce mÃ©dicament ?')">
                <i class="fas fa-check"></i> DÃ©livrer et Notifier le MÃ©decin
            </button>
        </div>
    </form>
<?php else: ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i> 
        <strong>Stock insuffisant !</strong> Veuillez faire une rÃ©quisition au dÃ©pÃ´t central.
        <a href="requisition.php" class="btn btn-primary btn-sm">CrÃ©er une rÃ©quisition</a>
    </div>
<?php endif; ?>

<style>
.prescription-pharma-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    padding: 25px;
    margin-bottom: 25px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.prescription-pharma-card .section h3 {
    color: var(--primary);
    font-size: 14px;
    margin-bottom: 10px;
}

.big-number {
    font-size: 32px;
    font-weight: bold;
    color: var(--primary);
}

.text-danger {
    color: var(--danger) !important;
}

.text-success {
    color: var(--secondary) !important;
}

.posologie-text {
    font-size: 16px;
    line-height: 1.8;
    color: var(--dark);
    background: var(--light);
    padding: 20px;
    border-radius: 8px;
    border-left: 4px solid var(--primary);
}
</style>

<?php include '../views/includes/footer.php'; ?>