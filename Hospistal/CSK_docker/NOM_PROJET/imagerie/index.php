<?php
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

$database = new Database();
$db = $database->getConnection();
$imagerie = new Imagerie($db);

// Statistiques avec la classe Imagerie
$stats = $imagerie->getStatistics($_SESSION['site_id']);

$pageTitle = "Imagerie MÃ©dicale - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="imagerie-module">
    <div class="module-header">
        <div>
            <h1><i class="fas fa-x-ray"></i> Module Imagerie MÃ©dicale</h1>
            <p>Gestion des examens d'imagerie et comptes rendus radiologiques</p>
        </div>
        <div class="header-stats-mini">
            <div class="stat-mini stat-warning">
                <i class="fas fa-clock"></i>
                <div>
                    <strong><?php echo $stats['en_attente']; ?></strong>
                    <span>En attente</span>
                </div>
            </div>
            <div class="stat-mini stat-danger">
                <i class="fas fa-exclamation-triangle"></i>
                <div>
                    <strong><?php echo $stats['urgents']; ?></strong>
                    <span>Urgent(s)</span>
                </div>
            </div>
            <div class="stat-mini stat-info">
                <i class="fas fa-hospital"></i>
                <div>
                    <strong><?php echo $stats['externes']; ?></strong>
                    <span>Externe(s)</span>
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
                <h3><?php echo $stats['en_attente']; ?></h3>
                <p>Examens en Attente</p>
                <a href="examens-attente.php" class="stat-link">Voir tout <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                <i class="fas fa-camera"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $stats['en_cours']; ?></h3>
                <p>En Cours de RÃ©alisation</p>
                <a href="examens-encours.php" class="stat-link">Voir tout <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                <i class="fas fa-file-medical"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $stats['termines']; ?></h3>
                <p>Comptes Rendus Disponibles</p>
                <a href="examens-termines.php" class="stat-link">Voir tout <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                <i class="fas fa-exclamation-circle"></i>
            </div>
            <div class="stat-content">
                <h3><?php echo $stats['urgents']; ?></h3>
                <p>Examens Urgents</p>
                <a href="examens-urgents.php" class="stat-link">Traiter <i class="fas fa-arrow-right"></i></a>
            </div>
        </div>
    </div>

    <!-- Modules d'action -->
    <div class="action-cards">
        <a href="examens-attente.php" class="action-card">
            <div class="action-icon">
                <i class="fas fa-camera-retro"></i>
            </div>
            <h3>RÃ©alisation Examens</h3>
            <p>RÃ©aliser les examens d'imagerie et capturer les images</p>
            <span class="action-badge">Prioritaire</span>
        </a>

        <a href="compte-rendu.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                <i class="fas fa-file-medical-alt"></i>
            </div>
            <h3>Comptes Rendus</h3>
            <p>RÃ©diger et valider les comptes rendus radiologiques</p>
        </a>

        <a href="pacs-viewer.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <i class="fas fa-images"></i>
            </div>
            <h3>Visualiseur PACS</h3>
            <p>Consulter et analyser les images mÃ©dicales</p>
        </a>

        <a href="planning-examens.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                <i class="fas fa-calendar-alt"></i>
            </div>
            <h3>Planning Examens</h3>
            <p>Planification et organisation des examens</p>
        </a>

        <a href="equipements-imagerie.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <i class="fas fa-cogs"></i>
            </div>
            <h3>Ã‰quipements</h3>
            <p>Gestion des appareils et maintenance</p>
        </a>

        <a href="rapports-imagerie.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                <i class="fas fa-chart-line"></i>
            </div>
            <h3>Rapports & Statistiques</h3>
            <p>Statistiques d'activitÃ© et analyses</p>
        </a>
    </div>

    <!-- Section modalitÃ©s -->
    <div class="modalites-section">
        <h2><i class="fas fa-layer-group"></i> ModalitÃ©s Disponibles</h2>
        <div class="modalites-grid">
            <div class="modalite-card">
                <div class="modalite-icon" style="background: #dbeafe;">
                    <i class="fas fa-x-ray" style="color: #1e40af;"></i>
                </div>
                <h4>Radiologie Conventionnelle</h4>
                <p>Radio standard numÃ©rique</p>
            </div>

            <div class="modalite-card">
                <div class="modalite-icon" style="background: #d1fae5;">
                    <i class="fas fa-brain" style="color: #065f46;"></i>
                </div>
                <h4>Scanner (TDM)</h4>
                <p>TomodensitomÃ©trie</p>
            </div>

            <div class="modalite-card">
                <div class="modalite-icon" style="background: #fef3c7;">
                    <i class="fas fa-magnet" style="color: #92400e;"></i>
                </div>
                <h4>IRM</h4>
                <p>Imagerie par RÃ©sonance MagnÃ©tique</p>
            </div>

            <div class="modalite-card">
                <div class="modalite-icon" style="background: #fee2e2;">
                    <i class="fas fa-heartbeat" style="color: #991b1b;"></i>
                </div>
                <h4>Ã‰chographie</h4>
                <p>Doppler & Ã‰chographie</p>
            </div>

            <div class="modalite-card">
                <div class="modalite-icon" style="background: #e0e7ff;">
                    <i class="fas fa-female" style="color: #4338ca;"></i>
                </div>
                <h4>Mammographie</h4>
                <p>Imagerie mammaire</p>
            </div>

            <div class="modalite-card">
                <div class="modalite-icon" style="background: #fce7f3;">
                    <i class="fas fa-bone" style="color: #9f1239;"></i>
                </div>
                <h4>OstÃ©odensitomÃ©trie</h4>
                <p>DensitÃ© osseuse</p>
            </div>
        </div>
    </div>
</div>

<style>
.imagerie-module {
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
    margin-bottom: 50px;
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

.modalites-section {
    background: white;
    padding: 40px;
    border-radius: 12px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.modalites-section h2 {
    color: var(--primary);
    font-size: 28px;
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    gap: 12px;
}

.modalites-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.modalite-card {
    padding: 25px;
    background: #f8fafc;
    border-radius: 12px;
    text-align: center;
    transition: all 0.3s;
    border: 2px solid transparent;
}

.modalite-card:hover {
    background: white;
    border-color: var(--primary);
    transform: translateY(-3px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.modalite-icon {
    width: 60px;
    height: 60px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
}

.modalite-icon i {
    font-size: 28px;
}

.modalite-card h4 {
    font-size: 16px;
    color: var(--dark);
    margin-bottom: 5px;
    font-weight: 600;
}

.modalite-card p {
    font-size: 13px;
    color: #64748b;
    margin: 0;
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