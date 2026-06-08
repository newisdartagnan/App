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
$date_debut = $_GET['date_debut'] ?? date('Y-m-d');
$date_fin = $_GET['date_fin'] ?? date('Y-m-d');
$statut = $_GET['statut'] ?? 'all';

// RÃ©cupÃ©rer les consultations du mÃ©decin connectÃ©
$query = "SELECT c.*, 
                 p.nom, p.prenom, p.numero_dossier, p.date_naissance, p.sexe,
                 s.numero_sejour, s.type_sejour,
                 COUNT(DISTINCT pp.idpharma_presc) as nb_prescriptions_med,
                 COUNT(DISTINCT ap.idactes_presc) as nb_prescriptions_exam
          FROM consultations c
          JOIN sous_sejour ss ON c.idsous_sejour = ss.idsous_sejour
          JOIN sejour s ON ss.idsejour = s.idsejour
          JOIN patient p ON s.idpatient = p.idpatient
          LEFT JOIN pharma_presc pp ON ss.idsous_sejour = pp.idsous_sejour
          LEFT JOIN actes_presc ap ON ss.idsous_sejour = ap.idsous_sejour
          WHERE c.idutilisateur = :idmedecin
          AND DATE(c.date_consultation) BETWEEN :date_debut AND :date_fin
          " . ($statut != 'all' ? "AND c.statut = :statut" : "") . "
          GROUP BY c.idconsultation
          ORDER BY c.date_consultation DESC, c.date_consultation DESC";

$stmt = $db->prepare($query);
$params = [
    ':idmedecin' => $_SESSION['user_id'],
    ':date_debut' => $date_debut,
    ':date_fin' => $date_fin
];
if ($statut != 'all') {
    $params[':statut'] = $statut;
}
$stmt->execute($params);
$consultations = $stmt->fetchAll();

// Statistiques
$stats_query = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN statut = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN statut = 'terminee' THEN 1 ELSE 0 END) as terminees
                FROM consultations
                WHERE idutilisateur = :idmedecin
                AND DATE(date_consultation) = CURDATE()";
$stmt_stats = $db->prepare($stats_query);
$stmt_stats->execute([':idmedecin' => $_SESSION['user_id']]);
$stats = $stmt_stats->fetch();

$pageTitle = "Mes Consultations - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-stethoscope"></i> Mes Consultations</h1>
    <div class="header-actions">
        <a href="index.php" class="btn btn-outline">
            <i class="fas fa-arrow-left"></i> Retour
        </a>
        <a href="nouvelle-consultation.php" class="btn btn-primary">
            <i class="fas fa-plus"></i> Nouvelle Consultation
        </a>
    </div>
</div>

<!-- Statistiques du jour -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-calendar-day"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['total']; ?></h3>
            <p>Consultations Aujourd'hui</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
            <i class="fas fa-clock"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['en_cours']; ?></h3>
            <p>En Cours</p>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-content">
            <h3><?php echo $stats['terminees']; ?></h3>
            <p>TerminÃ©es</p>
        </div>
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
                    <label for="statut">Statut</label>
                    <select id="statut" name="statut" class="form-control">
                        <option value="all" <?php echo $statut == 'all' ? 'selected' : ''; ?>>Tous</option>
                        <option value="en_attente" <?php echo $statut == 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                        <option value="en_cours" <?php echo $statut == 'en_cours' ? 'selected' : ''; ?>>En cours</option>
                        <option value="terminee" <?php echo $statut == 'terminee' ? 'selected' : ''; ?>>TerminÃ©es</option>
                    </select>
                </div>
                
                <div class="form-group" style="align-self: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filtrer
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Liste des consultations -->
<div class="consultations-grid">
    <?php if (empty($consultations)): ?>
        <div class="empty-state">
            <i class="fas fa-calendar-times"></i>
            <h3>Aucune consultation</h3>
            <p>Aucune consultation trouvÃ©e pour cette pÃ©riode</p>
        </div>
    <?php else: ?>
        <?php foreach ($consultations as $consult): ?>
        <div class="consultation-card">
            <div class="card-header-custom <?php echo $consult['statut'] == 'terminee' ? 'terminee' : ''; ?>">
                <div class="patient-info">
                    <div class="patient-avatar">
                        <?php echo strtoupper(substr($consult['nom'], 0, 1) . substr($consult['prenom'], 0, 1)); ?>
                    </div>
                    <div>
                        <h3><?php echo htmlspecialchars($consult['nom'] . ' ' . $consult['prenom']); ?></h3>
                        <span class="numero-dossier"><?php echo htmlspecialchars($consult['numero_dossier']); ?></span>
                    </div>
                </div>
                <div class="consultation-badges">
                    <?php
                    $statut_badge = [
                        'en_attente' => 'secondary',
                        'en_cours' => 'warning',
                        'terminee' => 'success'
                    ];
                    $badge_class = $statut_badge[$consult['statut']] ?? 'secondary';
                    ?>
                    <span class="badge badge-<?php echo $badge_class; ?>">
                        <?php 
                        $statut_text = [
                            'en_attente' => 'En attente',
                            'en_cours' => 'En cours',
                            'terminee' => 'TerminÃ©e'
                        ];
                        echo $statut_text[$consult['statut']] ?? ucfirst($consult['statut']);
                        ?>
                    </span>
                    <?php if ($consult['type_sejour'] == 'ambulatoire'): ?>
                        <span class="badge badge-info">Ambulatoire</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card-body-custom">
                <div class="consultation-details">
                    <div class="detail-item">
                        <i class="fas fa-calendar"></i>
                        <span><?php echo formatDate($consult['date_consultation']); ?> Ã  <?php echo formatTime($consult['date_consultation']); ?></span>
                    </div>
                    
                    <?php if ($consult['motif_consultation']): ?>
                    <div class="detail-item">
                        <i class="fas fa-notes-medical"></i>
                        <span><?php echo htmlspecialchars($consult['motif_consultation']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <div class="detail-item">
                        <i class="fas fa-user-md"></i>
                        <span>
                            <?php 
                            $age = calculateAge($consult['date_naissance']);
                            echo $age . ' ans - ' . ($consult['sexe'] == 'M' ? 'Masculin' : 'FÃ©minin'); 
                            ?>
                        </span>
                    </div>
                </div>
                
                <?php if ($consult['nb_prescriptions_med'] > 0 || $consult['nb_prescriptions_exam'] > 0): ?>
                <div class="prescriptions-summary">
                    <?php if ($consult['nb_prescriptions_med'] > 0): ?>
                        <span class="prescription-badge">
                            <i class="fas fa-pills"></i> <?php echo $consult['nb_prescriptions_med']; ?> mÃ©dicament(s)
                        </span>
                    <?php endif; ?>
                    <?php if ($consult['nb_prescriptions_exam'] > 0): ?>
                        <span class="prescription-badge">
                            <i class="fas fa-flask"></i> <?php echo $consult['nb_prescriptions_exam']; ?> examen(s)
                        </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="card-actions">
                <a href="voir-consultation.php?id=<?php echo $consult['idconsultation']; ?>" 
                   class="btn btn-sm btn-info">
                    <i class="fas fa-eye"></i> Voir
                </a>
                <?php if ($consult['statut'] != 'terminee'): ?>
                    <a href="modifier-consultation.php?id=<?php echo $consult['idconsultation']; ?>" 
                       class="btn btn-sm btn-warning">
                        <i class="fas fa-edit"></i> Modifier
                    </a>
                    <a href="prescrire.php?idconsultation=<?php echo $consult['idconsultation']; ?>" 
                       class="btn btn-sm btn-primary">
                        <i class="fas fa-prescription"></i> Prescrire
                    </a>
                <?php endif; ?>
                <a href="imprimer-consultation.php?id=<?php echo $consult['idconsultation']; ?>" 
                   class="btn btn-sm btn-secondary" target="_blank">
                    <i class="fas fa-print"></i> Imprimer
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
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

.consultations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 20px;
}

.consultation-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    transition: transform 0.2s;
}

.consultation-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
}

.card-header-custom {
    padding: 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.card-header-custom.terminee {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.patient-info {
    display: flex;
    align-items: center;
    gap: 15px;
}

.patient-avatar {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    font-weight: bold;
}

.patient-info h3 {
    margin: 0;
    font-size: 18px;
}

.numero-dossier {
    font-size: 12px;
    opacity: 0.9;
}

.consultation-badges {
    display: flex;
    gap: 8px;
}

.card-body-custom {
    padding: 20px;
}

.consultation-details {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin-bottom: 15px;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #64748b;
    font-size: 14px;
}

.detail-item i {
    color: var(--primary);
    width: 20px;
}

.prescriptions-summary {
    display: flex;
    gap: 10px;
    padding: 12px;
    background: #f8fafc;
    border-radius: 8px;
}

.prescription-badge {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    background: white;
    border-radius: 6px;
    font-size: 12px;
    color: #64748b;
}

.prescription-badge i {
    color: var(--primary);
}

.card-actions {
    padding: 15px 20px;
    background: #f8fafc;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.empty-state i {
    font-size: 64px;
    color: #cbd5e1;
    margin-bottom: 20px;
}

.empty-state h3 {
    color: #64748b;
    margin-bottom: 10px;
}

@media (max-width: 768px) {
    .consultations-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include '../views/includes/footer.php'; ?>