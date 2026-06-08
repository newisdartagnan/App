
<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// ParamÃƒÂ¨tres de pÃ©riode
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');

// Statistiques gÃ©nÃ©rales
$query_stats = "SELECT 
                COUNT(DISTINCT pp.idpharma_presc) as nb_prescriptions,
                COUNT(DISTINCT p.idpatient) as nb_patients,
                SUM(pp.montant_total) as chiffre_affaire,
                AVG(pp.montant_total) as montant_moyen
                FROM pharma_presc pp
                JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
                JOIN sejour s ON ss.idsejour = s.idsejour
                JOIN patient p ON s.idpatient = p.idpatient
                WHERE pp.statut_execution = 'acheve'
                AND DATE(pp.date_execution) BETWEEN :date_debut AND :date_fin
                AND s.idsite = :idsite";

$stmt_stats = $db->prepare($query_stats);
$stmt_stats->execute([
    ':date_debut' => $date_debut,
    ':date_fin' => $date_fin,
    ':idsite' => $_SESSION['site_id']
]);
$stats = $stmt_stats->fetch();

// Produits les plus vendus
$query_produits = "SELECT pr.libelle, pr.code,
                          COUNT(pp.idpharma_presc) as nb_ventes,
                          SUM(pp.quantite) as quantite_totale,
                          SUM(pp.montant_total) as chiffre_affaire
                   FROM pharma_presc pp
                   JOIN prodpharma pr ON pp.idprodpharma = pr.idprodpharma
                   JOIN sous_sejour ss ON pp.idsous_sejour = ss.idsous_sejour
                   JOIN sejour s ON ss.idsejour = s.idsejour
                   WHERE pp.statut_execution = 'acheve'
                   AND DATE(pp.date_execution) BETWEEN :date_debut AND :date_fin
                   AND s.idsite = :idsite
                   GROUP BY pr.idprodpharma
                   ORDER BY quantite_totale DESC
                   LIMIT 10";

$stmt_produits = $db->prepare($query_produits);
$stmt_produits->execute([
    ':date_debut' => $date_debut,
    ':date_fin' => $date_fin,
    ':idsite' => $_SESSION['site_id']
]);
$top_produits = $stmt_produits->fetchAll();

$pageTitle = "Rapports Pharmacie - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> Rapports et Statistiques</h1>
    <a href="index.php" class="btn btn-outline">
        <i class="fas fa-arrow-left"></i> Retour
    </a>
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
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Appliquer
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Statistiques gÃ©nÃ©rales -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-prescription"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['nb_prescriptions'] ?? 0; ?></h3>
            <p>Prescriptions traitÃ©es</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['nb_patients'] ?? 0; ?></h3>
            <p>Patients servis</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
            <i class="fas fa-dollar-sign"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatMoney($stats['chiffre_affaire'] ?? 0); ?></h3>
            <p>Chiffre d'affaire</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
            <i class="fas fa-chart-line"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo formatMoney($stats['montant_moyen'] ?? 0); ?></h3>
            <p>Moyenne par prescription</p>
        </div>
    </div>
</div>

<!-- Top produits -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Top 10 des Produits les Plus Vendus</h3>
    </div>
    <div class="card-body">
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Produit</th>
                        <th>Code</th>
                        <th>Nb Ventes</th>
                        <th>QuantitÃ© Totale</th>
                        <th>Chiffre d'Affaire</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_produits as $produit): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($produit['libelle']); ?></strong></td>
                        <td><small><?php echo htmlspecialchars($produit['code']); ?></small></td>
                        <td class="text-center">
                            <span class="badge badge-primary"><?php echo $produit['nb_ventes']; ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge badge-success"><?php echo $produit['quantite_totale']; ?></span>
                        </td>
                        <td><strong><?php echo formatMoney($produit['chiffre_affaire']); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Graphique simple (placeholder) -->
<div class="card mt-2">
    <div class="card-header">
        <h3 class="card-title">Evolution des Ventes</h3>
    </div>
    <div class="card-body">
        <div style="height: 300px; background: #f8fafc; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #64748b;">
            <div style="text-align: center;">
                <i class="fas fa-chart-bar" style="font-size: 48px; margin-bottom: 10px;"></i>
                <p>Graphique des ventes (intÃ©gration future)</p>
            </div>
        </div>
    </div>
</div>

<style>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin: 20px 0;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 20px;
}

.stat-icon {
    width: 60px;
    height: 60px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
    color: white;
}

.stat-content h3 {
    font-size: 32px;
    font-weight: bold;
    margin: 0;
    color: var(--dark);
}

.stat-content p {
    margin: 5px 0 0 0;
    color: #64748b;
    font-size: 14px;
}
</style>

<?php include '../views/includes/footer.php'; ?>