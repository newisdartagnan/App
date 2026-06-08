<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// SÃ©lection de l'officine
$idofficine = $_GET['idofficine'] ?? null;

// RÃ©cupÃ©rer les officines du site
$query_officines = "SELECT * FROM officine 
                    WHERE idsite = :idsite AND actif = 1 
                    ORDER BY nom";
$stmt_officines = $db->prepare($query_officines);
$stmt_officines->bindParam(':idsite', $_SESSION['site_id']);
$stmt_officines->execute();
$officines = $stmt_officines->fetchAll();

if (!$idofficine && !empty($officines)) {
    $idofficine = $officines[0]['idofficine'];
}

// ParamÃ¨tres de recherche des prescriptions
$date_debut = $_GET['date_debut'] ?? date('Y-m-d');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

// RÃ©cupÃ©rer les prescriptions en attente
$prescriptions = [];
if ($idofficine) {
    $query = "SELECT DISTINCT
                     p.idpatient,
                     p.numero_dossier,
                     p.nom,
                     p.prenom,
                     p.date_naissance,
                     p.type_patient,
                     s.idsejour,
                     COUNT(DISTINCT pp.idpharma_presc) as nb_prescriptions,
                     SUM(pp.montant_total) as montant_total,
                     MIN(pp.date_prescription) as date_prescription
              FROM patient p
              JOIN sejour s ON p.idpatient = s.idpatient
              JOIN sous_sejour ss ON s.idsejour = ss.idsejour
              JOIN pharma_presc pp ON ss.idsous_sejour = pp.idsous_sejour
              WHERE pp.statut_execution = 'en_attente'
              AND s.idsite = :idsite
              AND DATE(pp.date_prescription) BETWEEN :date_debut AND :date_fin
              GROUP BY p.idpatient, s.idsejour
              ORDER BY MIN(pp.date_prescription) ASC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':idsite', $_SESSION['site_id']);
    $stmt->bindParam(':date_debut', $date_debut);
    $stmt->bindParam(':date_fin', $date_fin);
    $stmt->execute();
    $prescriptions = $stmt->fetchAll();
}

$pageTitle = "Officine - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-store"></i> Gestion de l'Officine</h1>
    <div class="header-actions">
        <a href="index.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <a href="stock-officine.php?idofficine=<?php echo $idofficine; ?>" class="btn btn-info">
            <i class="fas fa-boxes"></i> Stock Officine
        </a>
        <a href="requisition.php?idofficine=<?php echo $idofficine; ?>" class="btn btn-primary">
            <i class="fas fa-file-import"></i> RÃ©quisition
        </a>
    </div>
</div>

<!-- SÃ©lection de l'officine -->
<div class="card">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="idofficine"><i class="fas fa-store"></i> Officine</label>
                    <select id="idofficine" name="idofficine" class="form-control" onchange="this.form.submit()">
                        <?php foreach ($officines as $off): ?>
                            <option value="<?php echo $off['idofficine']; ?>" 
                                    <?php echo $off['idofficine'] == $idofficine ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($off['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="date_debut"><i class="fas fa-calendar"></i> Date dÃ©but</label>
                    <input type="date" id="date_debut" name="date_debut" class="form-control" 
                           value="<?php echo $date_debut; ?>">
                </div>
                
                <div class="form-group">
                    <label for="date_fin"><i class="fas fa-calendar"></i> Date fin</label>
                    <input type="date" id="date_fin" name="date_fin" class="form-control" 
                           value="<?php echo $date_fin; ?>">
                </div>
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filtrer
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="location.reload()">
                        <i class="fas fa-sync"></i> Actualiser
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Liste des prescriptions -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-prescription"></i> Prescriptions en Attente 
            <span class="badge badge-warning"><?php echo count($prescriptions); ?></span>
        </h3>
    </div>
    <div class="card-body">
        <?php if (empty($prescriptions)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Aucune prescription en attente pour cette pÃ©riode.
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>NÂ° Dossier</th>
                            <th>Nom Patient</th>
                            <th>Type</th>
                            <th>Date Prescription</th>
                            <th>Nb Produits</th>
                            <th>Montant</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($prescriptions as $presc): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($presc['numero_dossier']); ?></strong></td>
                            <td><?php echo htmlspecialchars($presc['nom'] . ' ' . $presc['prenom']); ?></td>
                            <td>
                                <span class="badge <?php echo $presc['type_patient'] === 'prive' ? 'badge-warning' : 'badge-success'; ?>">
                                    <?php echo $presc['type_patient'] === 'prive' ? 'PrivÃ©' : 'ConventionnÃ©'; ?>
                                </span>
                            </td>
                            <td><?php echo formatDateTime($presc['date_prescription']); ?></td>
                            <td class="text-center">
                                <span class="badge badge-primary"><?php echo $presc['nb_prescriptions']; ?></span>
                            </td>
                            <td><strong><?php echo formatMoney($presc['montant_total']); ?></strong></td>
                            <td>
                                <a href="traiter-prescription.php?sejour_id=<?php echo $presc['idsejour']; ?>&idofficine=<?php echo $idofficine; ?>" 
                                   class="btn btn-sm btn-success">
                                    <i class="fas fa-check-circle"></i> Traiter
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../views/includes/footer.php'; ?>