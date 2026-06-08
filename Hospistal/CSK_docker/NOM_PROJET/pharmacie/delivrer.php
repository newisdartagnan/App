<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../models/PrescriptionMedicale.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();
$prescription = new PrescriptionMedicale($db);

$idpharma_presc = $_GET['id'] ?? null;
$idofficine = $_GET['idofficine'] ?? null;

if (!$idpharma_presc) {
    redirect('pharmacie/officine.php');
}

// RÃ©cupÃ©rer la prescription
$query = "SELECT pp.*, pr.libelle as produit_libelle, pr.code as produit_code,
                 p.nom as patient_nom, p.prenom as patient_prenom, p.numero_dossier,
                 u.nom as prescripteur_nom, u.prenom as prescripteur_prenom,
                 sp.quantite as stock_disponible,
                 o.nom as officine_nom,
                 s.idsejour
          FROM pharma_presc pp
          JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
          JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
          JOIN sejour s ON ss.idsejour = s.idsejour
          JOIN patient p ON s.idpatient = p.idpatient
          LEFT JOIN utilisateur u ON pp.prescripteur = u.idutilisateur
          LEFT JOIN stockpharma sp ON pr.idprodpharma = sp.idprodpharma 
                                   AND sp.idofficine = :idofficine
          LEFT JOIN officine o ON sp.idofficine = o.idofficine
          WHERE pp.idpharma_presc = :id";

$stmt = $db->prepare($query);
$stmt->execute([
    ':id' => $idpharma_presc,
    ':idofficine' => $idofficine
]);
$presc = $stmt->fetch();

if (!$presc) {
    redirect('pharmacie/officine.php');
}

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delivrer'])) {
    try {
        $db->beginTransaction();
        
        // 1. VÃ©rifier le stock
        $stock_disponible = $presc['stock_disponible'] ?? 0;
        if ($stock_disponible < $presc['quantite']) {
            throw new Exception("Stock insuffisant ! Disponible: {$stock_disponible}, DemandÃ©: {$presc['quantite']}");
        }
        
        // 2. DÃ©duire du stock
        $query_stock = "UPDATE stockpharma 
                       SET quantite = quantite - :quantite
                       WHERE idprodpharma = :idprodpharma 
                       AND idofficine = :idofficine";
        
        $stmt_stock = $db->prepare($query_stock);
        $stmt_stock->execute([
            ':quantite' => $presc['quantite'],
            ':idprodpharma' => $presc['idprodpharma'],
            ':idofficine' => $idofficine
        ]);
        
        // 3. Mettre Ã  jour la prescription
        $query_update = "UPDATE pharma_presc 
                        SET statut_execution = 'acheve',
                            date_execution = NOW(),
                            date_delivrance = NOW(),
                            executeur = :executeur,
                            delivre_par = :executeur
                        WHERE idpharma_presc = :id";
        
        $stmt_update = $db->prepare($query_update);
        $stmt_update->execute([
            ':executeur' => $_SESSION['user_id'],
            ':id' => $idpharma_presc
        ]);
        
        // 4. Notifier le prescripteur (IMPORTANT pour le workflow)
        $prescription->notifierPrescripteurLivraison($idpharma_presc);
        
        $db->commit();
        
        // Rediriger vers la liste des prescriptions
        header("Location: traiter-prescription.php?sejour_id={$presc['idsejour']}&idofficine={$idofficine}&success=1");
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
    <h1><i class="fas fa-hand-holding-medical"></i> DÃ©livrance de MÃ©dicament</h1>
    <a href="traiter-prescription.php?sejour_id=<?php echo $presc['idsejour']; ?>&idofficine=<?php echo $idofficine; ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<?php if ($presc['urgent']): ?>
<div class="alert alert-warning urgent-alert">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>PRESCRIPTION URGENTE</strong> - Traiter en prioritÃ©
</div>
<?php endif; ?>

<div class="delivrance-card">
    <div class="delivrance-section">
        <h3><i class="fas fa-user"></i> Patient</h3>
        <p class="section-value"><?php echo htmlspecialchars($presc['patient_prenom'] . ' ' . $presc['patient_nom']); ?></p>
        <p class="section-meta">NÂ° Dossier: <?php echo htmlspecialchars($presc['numero_dossier']); ?></p>
    </div>
    
    <div class="delivrance-section">
        <h3><i class="fas fa-pills"></i> MÃ©dicament</h3>
        <p class="section-value"><?php echo htmlspecialchars($presc['produit_libelle']); ?></p>
        <p class="section-meta">Code: <?php echo htmlspecialchars($presc['produit_code']); ?></p>
    </div>
    
    <div class="delivrance-section">
        <h3><i class="fas fa-hashtag"></i> QuantitÃ© Prescrite</h3>
        <p class="section-value big-number"><?php echo $presc['quantite']; ?></p>
    </div>
    
    <div class="delivrance-section">
        <h3><i class="fas fa-box"></i> Stock Disponible</h3>
        <p class="section-value big-number <?php echo ($presc['stock_disponible'] ?? 0) < $presc['quantite'] ? 'text-danger' : 'text-success'; ?>">
            <?php echo $presc['stock_disponible'] ?? 0; ?>
        </p>
        <p class="section-meta"><?php echo htmlspecialchars($presc['officine_nom'] ?? 'Officine'); ?></p>
    </div>
    
    <div class="delivrance-section">
        <h3><i class="fas fa-user-md"></i> Prescripteur</h3>
        <p class="section-value">Dr. <?php echo htmlspecialchars($presc['prescripteur_prenom'] . ' ' . $presc['prescripteur_nom']); ?></p>
        <p class="section-meta"><?php echo formatDateTime($presc['date_prescription']); ?></p>
    </div>
    
    <div class="delivrance-section">
        <h3><i class="fas fa-dollar-sign"></i> Montant</h3>
        <p class="section-value"><?php echo formatMoney($presc['montant_total']); ?></p>
        <p class="section-meta"><?php echo formatMoney($presc['prix_unitaire']); ?> / unitÃ©</p>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title"><i class="fas fa-prescription"></i> Posologie</h3>
    </div>
    <div class="card-body">
        <div class="posologie-box">
            <?php echo nl2br(htmlspecialchars($presc['posologie'])); ?>
        </div>
    </div>
</div>

<?php if ($error): ?>
<div class="alert alert-error">
    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
</div>
<?php endif; ?>

<?php if (($presc['stock_disponible'] ?? 0) >= $presc['quantite']): ?>
<form method="POST" onsubmit="return confirm('Confirmer la dÃ©livrance de ce mÃ©dicament ?\n\nLe mÃ©decin prescripteur sera automatiquement notifiÃ©.')">
    <div class="form-actions">
        <button type="submit" name="delivrer" class="btn btn-success btn-lg">
            <i class="fas fa-check-circle"></i> DÃ©livrer et Notifier le MÃ©decin
        </button>
    </div>
</form>
<?php else: ?>
<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i>
    <strong>Stock insuffisant !</strong> Veuillez faire une rÃ©quisition au dÃ©pÃ´t central.
    <div style="margin-top: 15px;">
        <a href="requisition.php?idofficine=<?php echo $idofficine; ?>&produit_id=<?php echo $presc['idprodpharma']; ?>&quantite=<?php echo $presc['quantite']; ?>" 
           class="btn btn-primary">
            <i class="fas fa-file-import"></i> CrÃ©er une RÃ©quisition
        </a>
    </div>
</div>
<?php endif; ?>

<style>
.urgent-alert {
    animation: pulse 2s infinite;
    font-size: 16px;
    font-weight: bold;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.8; }
}

.delivrance-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    padding: 30px;
    margin-bottom: 25px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 25px;
}

.delivrance-section {
    padding: 20px;
    border-radius: 8px;
    background: var(--light);
    border-left: 4px solid var(--primary);
}

.delivrance-section h3 {
    color: var(--primary);
    font-size: 14px;
    margin-bottom: 12px;
    text-transform: uppercase;
    font-weight: 600;
}

.section-value {
    font-size: 18px;
    font-weight: 600;
    color: var(--dark);
    margin: 0 0 8px 0;
}

.section-meta {
    font-size: 13px;
    color: #64748b;
    margin: 0;
}

.big-number {
    font-size: 36px;
    font-weight: bold;
    color: var(--primary);
}

.text-danger {
    color: #ef4444 !important;
}

.text-success {
    color: #10b981 !important;
}

.posologie-box {
    font-size: 16px;
    line-height: 1.8;
    color: var(--dark);
    background: #f8fafc;
    padding: 25px;
    border-radius: 8px;
    border-left: 4px solid var(--primary);
    white-space: pre-wrap;
}
</style>

<?php include '../views/includes/footer.php'; ?>