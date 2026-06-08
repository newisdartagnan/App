<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// ParamÃ¨tres de recherche
$date_debut = $_GET['date_debut'] ?? date('Y-m-d');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$site_id = $_GET['site_id'] ?? $_SESSION['site_id'];

// RÃ©cupÃ©rer la liste des patients avec prescriptions non validÃ©es
$query = "SELECT DISTINCT
            p.idpatient,
            p.numero_dossier,
            p.nom,
            p.prenom,
            p.date_naissance,
            p.type_patient,
            c.nom as categorie_nom,
            s.idsejour,
            s.type_sejour,
            s.date_entree,
            COUNT(DISTINCT ap.idactes_presc) as nb_actes_non_valides,
            SUM(ap.montant_total) as montant_total,
            MAX(ap.date_prescription) as derniere_prescription
        FROM patient p
        JOIN sejour s ON p.idpatient = s.idpatient
        JOIN sous_sejour ss ON s.idsejour = ss.idsejour
        JOIN actes_presc ap ON ss.idsous_sejour = ap.idsous_sejour
        LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
        WHERE p.type_patient = 'prive'
        AND ap.statut_validation = 'rien'
        AND s.idsite = :site_id
        AND DATE(ap.date_prescription) BETWEEN :date_debut AND :date_fin
        GROUP BY p.idpatient, s.idsejour
        ORDER BY derniere_prescription DESC";

$stmt = $db->prepare($query);
$stmt->bindParam(':site_id', $site_id);
$stmt->bindParam(':date_debut', $date_debut);
$stmt->bindParam(':date_fin', $date_fin);
$stmt->execute();
$patients = $stmt->fetchAll();

$sites = $db->query("SELECT * FROM site WHERE actif = 1 ORDER BY nom")->fetchAll();

$pageTitle = "Facturation PrivÃ©e - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user"></i> Facturation des Patients PrivÃ©s</h1>
    <div class="header-info">
        <span class="badge badge-primary">
            <i class="fas fa-user-clock"></i> <?php echo count($patients); ?> patient(s) en attente
        </span>
    </div>
</div>

<!-- Filtres -->
<div class="card">
    <div class="card-body">
        <form method="GET" class="filter-form">
            <div class="form-row">
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
                
                <div class="form-group">
                    <label for="site_id">Site</label>
                    <select id="site_id" name="site_id" class="form-control">
                        <?php foreach ($sites as $site): ?>
                            <option value="<?php echo $site['idsite']; ?>" 
                                    <?php echo $site['idsite'] == $site_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($site['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filtrer
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="refreshList()">
                        <i class="fas fa-sync"></i> Actualiser
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Liste des patients -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">Patients avec prescriptions en attente</h3>
    </div>
    <div class="card-body">
        <?php if (empty($patients)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Aucun patient trouvÃ© avec des prescriptions non validÃ©es pour cette pÃ©riode.
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>NÂ° Dossier</th>
                            <th>Nom Patient</th>
                            <th>Date Naissance</th>
                            <th>Type SÃ©jour</th>
                            <th>CatÃ©gorie</th>
                            <th>Actes Non ValidÃ©s</th>
                            <th>Montant Total</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $pat): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($pat['numero_dossier']); ?></strong></td>
                            <td>
                                <?php echo htmlspecialchars($pat['nom'] . ' ' . $pat['prenom']); ?>
                            </td>
                            <td><?php echo formatDate($pat['date_naissance']); ?></td>
                            <td>
                                <span class="badge badge-info">
                                    <?php echo ucfirst($pat['type_sejour']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($pat['categorie_nom'] ?? '-'); ?></td>
                            <td class="text-center">
                                <span class="badge badge-warning">
                                    <?php echo $pat['nb_actes_non_valides']; ?>
                                </span>
                            </td>
                            <td><strong><?php echo formatMoney($pat['montant_total']); ?></strong></td>
                            <td>
                                <a href="validation.php?sejour_id=<?php echo $pat['idsejour']; ?>" 
                                   class="btn btn-sm btn-primary">
                                    <i class="fas fa-check"></i> Valider
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

<script>
function refreshList() {
    location.reload();
}

// Auto-refresh toutes les 30 secondes
setInterval(refreshList, 30000);
</script>

<?php include '../views/includes/footer.php'; ?>