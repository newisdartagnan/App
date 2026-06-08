<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

$sejour_id = $_GET['sejour_id'] ?? null;
$idofficine = $_GET['idofficine'] ?? null;

if (!$sejour_id) {
    redirect('officine.php');
}

// RÃ©cupÃ©rer les prescriptions du sÃ©jour
$query = "SELECT pp.*, pr.libelle as produit, pr.code as code_produit, 
                 p.nom as patient_nom, p.prenom as patient_prenom, p.numero_dossier,
                 u.nom as prescripteur_nom, u.prenom as prescripteur_prenom,
                 sp.quantite as stock_disponible
          FROM pharma_presc pp
          JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
          JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
          JOIN sejour s ON ss.idsejour = s.idsejour
          JOIN patient p ON s.idpatient = p.idpatient
          LEFT JOIN utilisateur u ON pp.prescripteur = u.idutilisateur
          LEFT JOIN stockpharma sp ON pr.idprodpharma = sp.idprodpharma 
                AND sp.idofficine = :idofficine
          WHERE s.idsejour = :sejour_id 
          AND pp.statut_execution = 'en_attente'
          ORDER BY pp.urgent DESC, pp.date_prescription ASC";

$stmt = $db->prepare($query);
$stmt->execute([
    ':sejour_id' => $sejour_id,
    ':idofficine' => $idofficine
]);
$prescriptions = $stmt->fetchAll();

$pageTitle = "Traiter Prescription - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-prescription"></i> Traiter les Prescriptions</h1>
    <a href="officine.php?idofficine=<?php echo $idofficine; ?>" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<?php if (!empty($prescriptions)): ?>
    <?php $patient = $prescriptions[0]; ?>
    <div class="patient-info-card">
        <div class="patient-avatar">
            <i class="fas fa-user"></i>
        </div>
        <div class="patient-details">
            <h2><?php echo htmlspecialchars($patient['patient_prenom'] . ' ' . $patient['patient_nom']); ?></h2>
            <p><strong>NÂ° Dossier:</strong> <?php echo htmlspecialchars($patient['numero_dossier']); ?></p>
        </div>
        <div class="patient-stats">
            <div class="stat-badge">
                <i class="fas fa-pills"></i>
                <span><?php echo count($prescriptions); ?> produit(s)</span>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($prescriptions)): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> Aucune prescription en attente pour ce sÃ©jour.
    </div>
<?php else: ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">
                <i class="fas fa-list"></i> Liste des MÃ©dicaments Ã  DÃ©livrer
            </h3>
        </div>
        <div class="card-body">
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Produit</th>
                            <th>Code</th>
                            <th>Posologie</th>
                            <th>QuantitÃ©</th>
                            <th>Stock</th>
                            <th>Prix Unit.</th>
                            <th>Montant</th>
                            <th>Prescripteur</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prescriptions as $presc): ?>
                        <tr class="<?php echo $presc['urgent'] ? 'urgent-row' : ''; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($presc['produit']); ?></strong>
                                <?php if ($presc['urgent']): ?>
                                    <span class="badge badge-danger">URGENT</span>
                                <?php endif; ?>
                            </td>
                            <td><small><?php echo htmlspecialchars($presc['code_produit']); ?></small></td>
                            <td>
                                <div class="posologie-preview">
                                    <?php echo nl2br(htmlspecialchars(substr($presc['posologie'], 0, 80))); ?>
                                    <?php if (strlen($presc['posologie']) > 80): ?>...<?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge badge-primary"><?php echo $presc['quantite']; ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge <?php echo ($presc['stock_disponible'] ?? 0) >= $presc['quantite'] ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $presc['stock_disponible'] ?? 0; ?>
                                </span>
                            </td>
                            <td><?php echo formatMoney($presc['prix_unitaire']); ?></td>
                            <td><strong><?php echo formatMoney($presc['montant_total']); ?></strong></td>
                            <td>
                                <small>
                                    <i class="fas fa-user-md"></i>
                                    Dr. <?php echo htmlspecialchars($presc['prescripteur_prenom'] . ' ' . $presc['prescripteur_nom']); ?>
                                </small>
                            </td>
                            <td>
                                <a href="delivrer.php?id=<?php echo $presc['idpharma_presc']; ?>&idofficine=<?php echo $idofficine; ?>" 
                                   class="btn btn-sm btn-success">
                                    <i class="fas fa-hand-holding-medical"></i> DÃ©livrer
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="6" style="text-align: right;"><strong>TOTAL:</strong></td>
                            <td colspan="3">
                                <strong><?php echo formatMoney(array_sum(array_column($prescriptions, 'montant_total'))); ?></strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<style>
.patient-info-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    padding: 25px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 25px;
}

.patient-avatar {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 36px;
    color: white;
}

.patient-details {
    flex: 1;
}

.patient-details h2 {
    margin: 0 0 10px 0;
    color: var(--primary);
    font-size: 24px;
}

.patient-stats {
    display: flex;
    gap: 15px;
}

.stat-badge {
    background: var(--light);
    padding: 15px 25px;
    border-radius: 8px;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}

.stat-badge i {
    font-size: 24px;
    color: var(--primary);
}

.posologie-preview {
    max-width: 300px;
    font-size: 13px;
    line-height: 1.5;
    color: #64748b;
}

.urgent-row {
    background: #fef3c7;
}

.urgent-row:hover {
    background: #fde68a;
}
</style>

<?php include '../views/includes/footer.php'; ?>