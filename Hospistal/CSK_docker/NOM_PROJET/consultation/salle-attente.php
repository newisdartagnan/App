<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../models/Consultation.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();
$consultation = new Consultation($db);

// RÃ©cupÃ©rer les patients en attente
$patients = $consultation->getPatientsEnAttente($_SESSION['site_id']);

$pageTitle = "Salle d'Attente - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user-clock"></i> Salle d'Attente</h1>
    <div class="header-stats">
        <div class="stat-badge stat-urgent">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo count(array_filter($patients, fn($p) => $p['urgent'])); ?> Urgent(s)
        </div>
        <div class="stat-badge stat-total">
            <i class="fas fa-users"></i>
            <?php echo count($patients); ?> Total
        </div>
    </div>
</div>

<div class="action-bar">
    <button onclick="refreshList()" class="btn btn-secondary">
        <i class="fas fa-sync"></i> Actualiser
    </button>
    <div class="filter-group">
        <select id="filterType" class="form-control" onchange="filterPatients()">
            <option value="all">Tous les patients</option>
            <option value="urgent">Urgents seulement</option>
            <option value="normal">Non urgents</option>
        </select>
    </div>
</div>

<?php if (empty($patients)): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> Aucun patient en attente de consultation.
    </div>
<?php else: ?>
    <div class="patients-grid">
        <?php foreach ($patients as $patient): ?>
        <div class="patient-card <?php echo $patient['urgent'] ? 'urgent' : ''; ?>" 
             data-type="<?php echo $patient['urgent'] ? 'urgent' : 'normal'; ?>">
            <div class="patient-card-header">
                <div class="patient-info">
                    <h3><?php echo htmlspecialchars($patient['nom'] . ' ' . $patient['prenom']); ?></h3>
                    <span class="numero-dossier"><?php echo htmlspecialchars($patient['numero_dossier']); ?></span>
                </div>
                <?php if ($patient['urgent']): ?>
                    <span class="badge badge-danger badge-pulse">
                        <i class="fas fa-exclamation-triangle"></i> URGENT
                    </span>
                <?php endif; ?>
            </div>
            
            <div class="patient-card-body">
                <div class="info-row">
                    <span class="label"><i class="fas fa-birthday-cake"></i> Ã‚ge:</span>
                    <span class="value">
                        <?php 
                        $birthDate = new DateTime($patient['date_naissance']);
                        $today = new DateTime();
                        $age = $today->diff($birthDate);
                        echo $age->y . ' ans';
                        ?>
                    </span>
                </div>
                
                <div class="info-row">
                    <span class="label"><i class="fas fa-hospital"></i> UnitÃ©:</span>
                    <span class="value"><?php echo htmlspecialchars($patient['unite_medicale']); ?></span>
                </div>
                
                <div class="info-row">
                    <span class="label"><i class="fas fa-clock"></i> ArrivÃ©e:</span>
                    <span class="value"><?php echo formatDateTime($patient['date_entree']); ?></span>
                </div>
                
                <div class="info-row">
                    <span class="label"><i class="fas fa-check-circle"></i> Actes validÃ©s:</span>
                    <span class="value badge badge-success"><?php echo $patient['nb_actes_valides']; ?></span>
                </div>
            </div>
            
            <div class="patient-card-footer">
                <a href="consulter.php?sous_sejour_id=<?php echo $patient['idsous_sejour']; ?>" 
                   class="btn btn-primary btn-block">
                    <i class="fas fa-stethoscope"></i> Consulter
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<style>
.header-stats {
    display: flex;
    gap: 15px;
}

.stat-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 20px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 14px;
}

.stat-urgent {
    background: #fee2e2;
    color: #991b1b;
}

.stat-total {
    background: #dbeafe;
    color: #1e40af;
}

.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 20px 0;
    padding: 15px;
    background: white;
    border-radius: 8px;
    box-shadow: var(--shadow);
}

.filter-group {
    display: flex;
    gap: 10px;
    align-items: center;
}

.patients-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.patient-card {
    background: white;
    border-radius: 12px;
    box-shadow: var(--shadow);
    overflow: hidden;
    transition: all 0.3s;
    border-left: 4px solid var(--primary);
}

.patient-card.urgent {
    border-left-color: var(--danger);
    box-shadow: 0 0 20px rgba(239, 68, 68, 0.2);
}

.patient-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--shadow-lg);
}

.patient-card-header {
    padding: 20px;
    background: var(--light);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
}

.patient-info h3 {
    margin: 0;
    font-size: 18px;
    color: var(--dark);
}

.numero-dossier {
    font-size: 13px;
    color: #64748b;
    font-weight: 500;
}

.badge-pulse {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.patient-card-body {
    padding: 20px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
}

.info-row:last-child {
    border-bottom: none;
}

.info-row .label {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #64748b;
    font-size: 14px;
}

.info-row .value {
    font-weight: 600;
    color: var(--dark);
}

.patient-card-footer {
    padding: 20px;
    background: var(--light);
}
</style>

<script>
function refreshList() {
    location.reload();
}

function filterPatients() {
    const filterValue = document.getElementById('filterType').value;
    const cards = document.querySelectorAll('.patient-card');
    
    cards.forEach(card => {
        const type = card.dataset.type;
        
        if (filterValue === 'all') {
            card.style.display = 'block';
        } else if (filterValue === type) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });
}

// Auto-refresh toutes les 30 secondes
setInterval(refreshList, 30000);
</script>

<?php include '../views/includes/footer.php'; ?>