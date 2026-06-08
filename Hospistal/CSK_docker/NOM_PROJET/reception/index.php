<?php
require_once '../config/config.php';
requireLogin();

$pageTitle = "RÃ©ception - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="reception-module">
    <div class="module-header">
        <h1><i class="fas fa-user-plus"></i> Module RÃ©ception</h1>
        <p>Gestion des patients et des sÃ©jours</p>
    </div>

    <div class="action-cards">
        <a href="recherche-patient.php" class="action-card">
            <div class="action-icon"><i class="fas fa-search"></i></div>
            <h3>Recherche Patient</h3>
            <p>Rechercher un patient existant</p>
        </a>

        <a href="nouveau-patient.php" class="action-card">
            <div class="action-icon"><i class="fas fa-user-plus"></i></div>
            <h3>Nouveau Patient</h3>
            <p>Enregistrer un nouveau patient</p>
        </a>

        <a href="rapports.php" class="action-card">
            <div class="action-icon"><i class="fas fa-chart-bar"></i></div>
            <h3>Rapports</h3>
            <p>Statistiques d'enregistrement</p>
        </a>
    </div>
</div>

<style>
.reception-module {
    max-width: 1200px;
    margin: 0 auto;
}

.module-header {
    text-align: center;
    margin-bottom: 50px;
}

.module-header h1 {
    font-size: 36px;
    color: var(--primary);
    margin-bottom: 10px;
}

.action-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 30px;
}

.action-card {
    background: white;
    padding: 40px;
    border-radius: 12px;
    text-align: center;
    text-decoration: none;
    color: var(--dark);
    box-shadow: var(--shadow);
    transition: all 0.3s;
}

.action-card:hover {
    transform: translateY(-5px);
    box-shadow: var(--shadow-lg);
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
}

.action-card h3 {
    font-size: 24px;
    margin-bottom: 10px;
    color: var(--primary);
}

.action-card p {
    color: #64748b;
}
</style>

<?php include '../views/includes/footer.php'; ?>