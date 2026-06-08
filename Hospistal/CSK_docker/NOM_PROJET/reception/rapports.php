<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// ParamÃ¨tres
$date_debut = $_GET['date_debut'] ?? date('Y-m-01');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$type_rapport = $_GET['type_rapport'] ?? 'nouveaux_patients';

// Statistiques gÃ©nÃ©rales
$stats_query = "SELECT 
                COUNT(DISTINCT p.idpatient) as total_patients,
                COUNT(DISTINCT CASE WHEN DATE(p.date_enregistrement) BETWEEN :date_debut AND :date_fin THEN p.idpatient END) as nouveaux_patients,
                COUNT(DISTINCT s.idsejour) as total_sejours,
                COUNT(DISTINCT CASE WHEN s.type_sejour = 'ambulatoire' THEN s.idsejour END) as sejours_ambulatoires,
                COUNT(DISTINCT CASE WHEN s.type_sejour = 'hospitalise' THEN s.idsejour END) as sejours_hospitalises,
                COUNT(DISTINCT CASE WHEN p.sexe = 'M' THEN p.idpatient END) as hommes,
                COUNT(DISTINCT CASE WHEN p.sexe = 'F' THEN p.idpatient END) as femmes
                FROM patient p
                LEFT JOIN sejour s ON p.idpatient = s.idpatient 
                    AND DATE(s.date_entree) BETWEEN :date_debut2 AND :date_fin2
                WHERE p.idsite = :idsite";

$stmt_stats = $db->prepare($stats_query);
$stmt_stats->execute([
    ':date_debut' => $date_debut,
    ':date_fin' => $date_fin,
    ':date_debut2' => $date_debut,
    ':date_fin2' => $date_fin,
    ':idsite' => $_SESSION['site_id']
]);
$stats = $stmt_stats->fetch();

// Rapports dÃ©taillÃ©s selon le type
$details = [];
switch($type_rapport) {
    case 'nouveaux_patients':
        $query = "SELECT p.*, c.nom as categorie, 
                  TIMESTAMPDIFF(YEAR, p.date_naissance, CURDATE()) as age
                  FROM patient p
                  LEFT JOIN categorie c ON p.idcategorie = c.idcategorie
                  WHERE p.idsite = :idsite
                  AND DATE(p.date_enregistrement) BETWEEN :date_debut AND :date_fin
                  ORDER BY p.date_enregistrement DESC";
        break;
        
    case 'sejours':
        $query = "SELECT s.*, p.nom, p.prenom, p.numero_dossier, p.sexe,
                  m.nom as medecin_nom, um.nom as unite
                  FROM sejour s
                  JOIN patient p ON s.idpatient = p.idpatient
                  LEFT JOIN utilisateur m ON s.idutilisateur = m.idutilisateur
                  LEFT JOIN sous_sejour ss ON s.idsejour = ss.idsejour
                  LEFT JOIN unite_med um ON ss.idunite_med = um.idunite_med
                  WHERE s.idsite = :idsite
                  AND DATE(s.date_entree) BETWEEN :date_debut AND :date_fin
                  ORDER BY s.date_entree DESC";
        break;
        
    case 'par_age':
        $query = "SELECT 
                  CASE 
                    WHEN TIMESTAMPDIFF(YEAR, p.date_naissance, CURDATE()) < 5 THEN '0-4 ans'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_naissance, CURDATE()) < 15 THEN '5-14 ans'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_naissance, CURDATE()) < 25 THEN '15-24 ans'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_naissance, CURDATE()) < 45 THEN '25-44 ans'
                    WHEN TIMESTAMPDIFF(YEAR, p.date_naissance, CURDATE()) < 65 THEN '45-64 ans'
                    ELSE '65+ ans'
                  END as tranche_age,
                  COUNT(*) as nombre,
                  SUM(CASE WHEN p.sexe = 'M' THEN 1 ELSE 0 END) as hommes,
                  SUM(CASE WHEN p.sexe = 'F' THEN 1 ELSE 0 END) as femmes
                  FROM patient p
                  JOIN sejour s ON p.idpatient = s.idpatient
                  WHERE s.idsite = :idsite
                  AND DATE(s.date_entree) BETWEEN :date_debut AND :date_fin
                  GROUP BY tranche_age
                  ORDER BY tranche_age";
        break;
}

if (!empty($query)) {
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':idsite' => $_SESSION['site_id'],
        ':date_debut' => $date_debut,
        ':date_fin' => $date_fin
    ]);
    $details = $stmt->fetchAll();
}

$pageTitle = "Rapports RÃ©ception - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-chart-bar"></i> Rapports de RÃ©ception</h1>
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
                
                <div class="form-group">
                    <label for="type_rapport">Type de rapport</label>
                    <select id="type_rapport" name="type_rapport" class="form-control">
                        <option value="nouveaux_patients" <?php echo $type_rapport == 'nouveaux_patients' ? 'selected' : ''; ?>>
                            Nouveaux Patients
                        </option>
                        <option value="sejours" <?php echo $type_rapport == 'sejours' ? 'selected' : ''; ?>>
                            SÃ©jours
                        </option>
                        <option value="par_age" <?php echo $type_rapport == 'par_age' ? 'selected' : ''; ?>>
                            RÃ©partition par Ã¢ge
                        </option>
                    </select>
                </div>
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> GÃ©nÃ©rer
                    </button>
                    <button type="button" onclick="window.print()" class="btn btn-secondary">
                        <i class="fas fa-print"></i> Imprimer
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Statistiques -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['total_patients']; ?></h3>
            <p>Total Patients</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
            <i class="fas fa-user-plus"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['nouveaux_patients']; ?></h3>
            <p>Nouveaux Patients</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
            <i class="fas fa-bed"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['total_sejours']; ?></h3>
            <p>Total SÃ©jours</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
            <i class="fas fa-venus-mars"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['hommes']; ?> / <?php echo $stats['femmes']; ?></h3>
            <p>Hommes / Femmes</p>
        </div>
    </div>
</div>

<!-- DÃ©tails -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <?php 
            $titles = [
                'nouveaux_patients' => 'Liste des Nouveaux Patients',
                'sejours' => 'Liste des SÃ©jours',
                'par_age' => 'RÃ©partition par Tranche d\'Ã‚ge'
            ];
            echo $titles[$type_rapport] ?? 'DÃ©tails';
            ?>
        </h3>
    </div>
    <div class="card-body">
        <?php if (empty($details)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i> Aucune donnÃ©e disponible pour cette pÃ©riode.
            </div>
        <?php else: ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <?php if ($type_rapport == 'nouveaux_patients'): ?>
                                <th>NÂ° Dossier</th>
                                <th>Nom Complet</th>
                                <th>Sexe</th>
                                <th>Ã‚ge</th>
                                <th>TÃ©lÃ©phone</th>
                                <th>CatÃ©gorie</th>
                                <th>Date CrÃ©ation</th>
                            <?php elseif ($type_rapport == 'sejours'): ?>
                                <th>NÂ° SÃ©jour</th>
                                <th>Patient</th>
                                <th>Type</th>
                                <th>Date EntrÃ©e</th>
                                <th>MÃ©decin</th>
                                <th>UnitÃ©</th>
                                <th>Statut</th>
                            <?php elseif ($type_rapport == 'par_age'): ?>
                                <th>Tranche d'Ã‚ge</th>
                                <th>Total</th>
                                <th>Hommes</th>
                                <th>Femmes</th>
                                <th>Pourcentage</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_age = array_sum(array_column($details, 'nombre'));
                        foreach ($details as $row): 
                        ?>
                        <tr>
                            <?php if ($type_rapport == 'nouveaux_patients'): ?>
                                <td><strong><?php echo htmlspecialchars($row['numero_dossier']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['nom'] . ' ' . $row['prenom']); ?></td>
                                <td><?php echo $row['sexe'] == 'M' ? 'Masculin' : 'FÃ©minin'; ?></td>
                                <td><?php echo $row['age']; ?> ans</td>
                                <td><?php echo htmlspecialchars($row['telephone1'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['categorie'] ?? '-'); ?></td>
                                <td><?php echo formatDate($row['date_enregistrement']); ?></td>
                            <?php elseif ($type_rapport == 'sejours'): ?>
                                <td><strong><?php echo htmlspecialchars($row['numero_sejour']); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['nom'] . ' ' . $row['prenom']); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $row['type_sejour'] == 'ambulatoire' ? 'info' : 'primary'; ?>">
                                        <?php echo ucfirst($row['type_sejour']); ?>
                                    </span>
                                </td>
                                <td><?php echo formatDate($row['date_entree']); ?></td>
                                <td><?php echo htmlspecialchars($row['medecin_nom'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($row['unite'] ?? '-'); ?></td>
                                <td>
                                    <span class="badge badge-<?php echo $row['statut'] == 'en_cours' ? 'warning' : 'success'; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $row['statut'])); ?>
                                    </span>
                                </td>
                            <?php elseif ($type_rapport == 'par_age'): ?>
                                <td><strong><?php echo $row['tranche_age']; ?></strong></td>
                                <td class="text-center"><strong><?php echo $row['nombre']; ?></strong></td>
                                <td class="text-center"><?php echo $row['hommes']; ?></td>
                                <td class="text-center"><?php echo $row['femmes']; ?></td>
                                <td class="text-center">
                                    <?php echo $total_age > 0 ? round(($row['nombre'] / $total_age) * 100, 1) : 0; ?>%
                                </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
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

@media print {
    .page-header .btn, .filter-form { display: none; }
}
</style>

<?php include '../views/includes/footer.php'; ?>