<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();

// Statistiques rapides - RETIREZ LA CLAUSE DE TEMPS
$stats_query = "SELECT 
    COUNT(DISTINCT CASE WHEN ap.statut_execution = 'en_attente' THEN ap.idactes_presc END) as en_attente,
    COUNT(DISTINCT CASE WHEN ap.statut_execution = 'en_cours' THEN ap.idactes_presc END) as en_cours,
    COUNT(DISTINCT CASE WHEN ap.statut_execution = 'termine' THEN ap.idactes_presc END) as termines,
    COUNT(DISTINCT CASE WHEN ap.urgent = 1 AND ap.statut_execution != 'termine' THEN ap.idactes_presc END) as urgents
    FROM actes_presc ap
    JOIN acte a ON ap.idacte = a.idacte
    WHERE a.idcategorie_acte = 6";

$stmt_stats = $db->prepare($stats_query);
$stmt_stats->execute();
$stats = $stmt_stats->fetch();

$pageTitle = "Laboratoire - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="laboratoire-module">
    <div class="module-header">
        <div>
            <h1><i class="fas fa-flask"></i> Module Laboratoire</h1>
            <p>Gestion des analyses biologiques et rÃ©sultats</p>
        </div>
        <div class="header-stats-mini">
            <div class="stat-mini stat-warning">
                <i class="fas fa-hourglass-half"></i>
                <div>
                    <strong><?php echo $stats['en_attente'] ?? 0; ?></strong>
                    <span>En attente</span>
                </div>
            </div>
            <div class="stat-mini stat-info">
                <i class="fas fa-spinner"></i>
                <div>
                    <strong><?php echo $stats['en_cours'] ?? 0; ?></strong>
                    <span>En cours</span>
                </div>
            </div>
            <div class="stat-mini stat-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong><?php echo $stats['urgents'] ?? 0; ?></strong>
                    <span>Urgent(s)</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistiques principales -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #fbbf24, #f59e0b);">
                <i class="fas fa-hourglass-half"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $stats['en_attente'] ?? 0; ?></h3>
                <p>Analyses en Attente</p>
                <a href="<?php echo BASE_URL; ?>laboratoire/analyses-attente.php" class="stat-link">Voir tout <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                <i class="fas fa-vial"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $stats['en_cours'] ?? 0; ?></h3>
                <p>En Cours d'Analyse</p>
                <a href="<?php echo BASE_URL; ?>laboratoire/analyses-encours.php" class="stat-link">Voir tout <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $stats['termines'] ?? 0; ?></h3>
                <p>RÃ©sultats Disponibles</p>
                <a href="<?php echo BASE_URL; ?>laboratoire/analyses-terminees.php" class="stat-link">Voir tout <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $stats['urgents'] ?? 0; ?></h3>
                <p>Analyses Urgentes</p>
                <a href="<?php echo BASE_URL; ?>laboratoire/analyses-urgentes.php" class="stat-link">Traiter <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <!-- Modules d'action -->
    <div class="action-cards">
        <a href="<?php echo BASE_URL; ?>laboratoire/analyses-attente.php" class="action-card">
            <div class="action-icon">
                <i class="fas fa-syringe"></i>
            </div>
            <h3>PrÃ©lÃ¨vements</h3>
            <p>Enregistrer et gÃ©rer les prÃ©lÃ¨vements biologiques</p>
            <span class="action-badge">Nouveau</span>
        </a>

        <a href="<?php echo BASE_URL; ?>laboratoire/analyses-encours.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                <i class="fas fa-keyboard"></i>
            </div>
            <h3>Saisie RÃ©sultats</h3>
            <p>Saisir et valider les rÃ©sultats d'analyses</p>
        </a>

        <a href="<?php echo BASE_URL; ?>laboratoire/analyses-terminees.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <i class="fas fa-check-double"></i>
            </div>
            <h3>Validation Biologique</h3>
            <p>Validation par le biologiste responsable</p>
        </a>

        <a href="<?php echo BASE_URL; ?>laboratoire/machines.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                <i class="fas fa-microscope"></i>
            </div>
            <h3>Machines & Ã‰quipements</h3>
            <p>Gestion des automates et Ã©quipements</p>
        </a>

        <a href="<?php echo BASE_URL; ?>laboratoire/controle-qualite.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <i class="fas fa-clipboard-check"></i>
            </div>
            <h3>ContrÃ´le QualitÃ©</h3>
            <p>Suivi qualitÃ© et calibrations</p>
        </a>

        <a href="<?php echo BASE_URL; ?>laboratoire/rapports-labo.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                <i class="fas fa-chart-bar"></i>
            </div>
            <h3>Rapports & Statistiques</h3>
            <p>Analyses statistiques et rapports d'activitÃ©</p>
        </a>
    </div>
</div>

<style>
.laboratoire-module {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.module-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 40px;
}

.module-header h1 {
    font-size: 36px;
    color: var(--primary);
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 15px;
}

.module-header p {
    font-size: 18px;
    color: #64748b;
    margin: 0;
}

.header-stats-mini {
    display: flex;
    gap: 20px;
}

.stat-mini {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 15px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stat-mini i {
    font-size: 24px;
}

.stat-mini.stat-warning i { color: #f59e0b; }
.stat-mini.stat-info i { color: #3b82f6; }
.stat-mini.stat-danger i { color: #ef4444; }

.stat-mini strong {
    display: block;
    font-size: 24px;
    font-weight: 700;
    color: var(--dark);
}

.stat-mini span {
    display: block;
    font-size: 12px;
    color: #64748b;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
    gap: 25px;
    margin-bottom: 40px;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    display: flex;
    align-items: center;
    gap: 20px;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 15px rgba(0, 0, 0, 0.15);
}

.stat-icon {
    width: 70px;
    height: 70px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    color: white;
    flex-shrink: 0;
}

.stat-content {
    flex: 1;
}

.stat-content h3 {
    font-size: 36px;
    font-weight: bold;
    margin: 0 0 5px 0;
    color: var(--dark);
}

.stat-content p {
    margin: 0 0 10px 0;
    color: #64748b;
    font-size: 14px;
}

.stat-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: var(--primary);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    transition: gap 0.2s;
}

.stat-link:hover {
    gap: 8px;
}

.action-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 25px;
}

.action-card {
    background: white;
    padding: 35px;
    border-radius: 12px;
    text-align: center;
    text-decoration: none;
    color: var(--dark);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
}

.action-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), var(--primary-dark));
    transform: scaleX(0);
    transition: transform 0.3s;
}

.action-card:hover::before {
    transform: scaleX(1);
}

.action-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
    border-color: var(--primary);
}

.action-icon {
    width: 80px;
    height: 80px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    font-size: 36px;
    color: white;
    transition: transform 0.3s ease;
}

.action-card:hover .action-icon {
    transform: scale(1.1) rotate(5deg);
}

.action-card h3 {
    font-size: 22px;
    margin-bottom: 12px;
    color: var(--primary);
    font-weight: 600;
}

.action-card p {
    color: #64748b;
    line-height: 1.6;
    font-size: 15px;
    margin: 0;
}

.action-badge {
    position: absolute;
    top: 15px;
    right: 15px;
    background: linear-gradient(135deg, #ef4444, #dc2626);
    color: white;
    font-size: 11px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 12px;
    text-transform: uppercase;
}

@media (max-width: 768px) {
    .module-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 20px;
    }
    
    .header-stats-mini {
        width: 100%;
        flex-direction: column;
    }
    
    .stat-mini {
        width: 100%;
    }
}
</style>

<?php include '../views/includes/footer.php'; ?>