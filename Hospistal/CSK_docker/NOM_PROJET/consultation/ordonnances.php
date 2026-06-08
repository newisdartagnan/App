<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

if (!hasPermission('consultation')) {
    redirect('../index.php');
}

$database = new Database();
$db = $database->getConnection();

// Filtres
$date_debut = $_GET['date_debut'] ?? date('Y-m-d', strtotime('-7 days'));
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$recherche = $_GET['recherche'] ?? '';

// RÃ©cupÃ©rer les ordonnances (prescriptions mÃ©dicamenteuses)
$query = "SELECT 
                c.idconsultation,
                c.date_consultation,
                p.nom, p.prenom, p.numero_dossier, p.date_naissance,
                s.numero_sejour,
                COUNT(DISTINCT pp.idpharma_presc) as nb_medicaments,
                GROUP_CONCAT(DISTINCT pr.libelle SEPARATOR ', ') as medicaments_list,
                MAX(pp.date_prescription) as date_prescription
          FROM consultations c
          JOIN sous_sejour ss ON c.idsous_sejour = ss.idsous_sejour
          JOIN sejour s ON ss.idsejour = s.idsejour
          JOIN patient p ON s.idpatient = p.idpatient
          LEFT JOIN pharma_presc pp ON ss.idsous_sejour = pp.idsous_sejour 
                AND pp.prescripteur = c.idutilisateur
          LEFT JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
          WHERE c.idutilisateur = :idutilisateur
          AND DATE(c.date_consultation) BETWEEN :date_debut AND :date_fin
          " . (!empty($recherche) ? "AND (p.nom LIKE :recherche OR p.prenom LIKE :recherche OR p.numero_dossier LIKE :recherche)" : "") . "
          GROUP BY c.idconsultation
          HAVING nb_medicaments > 0
          ORDER BY c.date_consultation DESC";

$stmt = $db->prepare($query);
$params = [
    ':idutilisateur' => $_SESSION['user_id'],
    ':date_debut' => $date_debut,
    ':date_fin' => $date_fin
];
if (!empty($recherche)) {
    $params[':recherche'] = '%' . $recherche . '%';
}
$stmt->execute($params);
$ordonnances = $stmt->fetchAll();

$pageTitle = "Mes Ordonnances - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-prescription"></i> Mes Ordonnances</h1>
    <a href="mes-consultations.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
</div>

<!-- Filtres et recherche -->
<div class="card">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="recherche">Rechercher un patient</label>
                    <input type="text" id="recherche" name="recherche" class="form-control" 
                           value="<?php echo htmlspecialchars($recherche); ?>"
                           placeholder="Nom, prÃ©nom ou nÂ° dossier">
                </div>
                
                <div class="form-group">
                    <label for="date_debut">Date dÃ©but</label>
                    <input type="date" id="date_debut" name="date_debut" class="form-control" 
                           value="<?php echo $date_debut; ?>">
                </div>
                
                <div class="form-group">
                    <label for="date_fin">Date fin</label>
                    <input type="date" id="date_fin" name="date_fin" class="form-control" 
                           value="<?php echo $date_fin; ?>">
                </div>
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Rechercher
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Liste des ordonnances -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-list"></i> Ordonnances prescrites
            <span class="badge badge-primary"><?php echo count($ordonnances); ?></span>
        </h3>
    </div>
    <div class="card-body">
        <?php if (empty($ordonnances)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Aucune ordonnance trouvÃ©e pour cette pÃ©riode.
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Patient</th>
                            <th>NÂ° Dossier</th>
                            <th>Ã‚ge</th>
                            <th>Nb MÃ©dicaments</th>
                            <th>MÃ©dicaments prescrits</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ordonnances as $ord): ?>
                        <tr>
                            <td><?php echo formatDate($ord['date_consultation']); ?></td>
                            <td><strong><?php echo htmlspecialchars($ord['nom'] . ' ' . $ord['prenom']); ?></strong></td>
                            <td><?php echo htmlspecialchars($ord['numero_dossier']); ?></td>
                            <td><?php echo calculateAge($ord['date_naissance']); ?> ans</td>
                            <td class="text-center">
                                <span class="badge badge-primary"><?php echo $ord['nb_medicaments']; ?></span>
                            </td>
                            <td>
                                <div class="medicaments-preview">
                                    <?php 
                                    $meds = explode(', ', $ord['medicaments_list']);
                                    echo htmlspecialchars(implode(', ', array_slice($meds, 0, 3)));
                                    if (count($meds) > 3) echo '...';
                                    ?>
                                </div>
                            </td>
                            <td>
                                <a href="voir-ordonnance.php?idconsultation=<?php echo $ord['idconsultation']; ?>" 
                                   class="btn btn-sm btn-info">
                                    <i class="fas fa-eye"></i> Voir
                                </a>
                                <a href="imprimer-ordonnance.php?idconsultation=<?php echo $ord['idconsultation']; ?>" 
                                   class="btn btn-sm btn-secondary" target="_blank">
                                    <i class="fas fa-print"></i> Imprimer
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

<style>
.medicaments-preview {
    max-width: 300px;
    font-size: 13px;
    color: #64748b;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>

<?php include '../views/includes/footer.php'; ?>