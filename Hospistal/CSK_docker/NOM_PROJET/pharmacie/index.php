<?php
require_once '../config/config.php';
requireLogin();

$pageTitle = "Pharmacie - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="pharmacie-module">
    <div class="module-header">
        <h1><i class="fas fa-pills"></i> Module Pharmacie</h1>
        <p>Gestion du stock et des prescriptions mÃ©dicales</p>
    </div>

    <div class="action-cards">
        <a href="officine.php" class="action-card">
            <div class="action-icon">
                <i class="fas fa-store"></i>
            </div>
            <h3>Officine</h3>
            <p>Traitement des prescriptions et dÃ©livrance</p>
        </a>

        <a href="stock-general.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                <i class="fas fa-boxes"></i>
            </div>
            <h3>Stock GÃ©nÃ©ral</h3>
            <p>Gestion globale du stock pharmaceutique</p>
        </a>

        <a href="depot-central.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                <i class="fas fa-warehouse"></i>
            </div>
            <h3>DÃ©pÃ´t Central</h3>
            <p>RÃ©ception et distribution des produits</p>
        </a>

        <a href="produits.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                <i class="fas fa-capsules"></i>
            </div>
            <h3>Produits</h3>
            <p>Catalogue des produits pharmaceutiques</p>
        </a>

        <a href="rapports.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                <i class="fas fa-chart-bar"></i>
            </div>
            <h3>Rapports</h3>
            <p>Statistiques et analyses de consommation</p>
        </a>

        <a href="inventaire.php" class="action-card">
            <div class="action-icon" style="background: linear-gradient(135deg, #06b6d4, #0891b2);">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <h3>Inventaire</h3>
            <p>ContrÃ´le et ajustement des stocks</p>
        </a>
    </div>
</div>

<style>
.pharmacie-module {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
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

.module-header p {
    font-size: 18px;
    color: #64748b;
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
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.action-card:hover {
    transform: translateY(-5px);
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
    font-size: 24px;
    margin-bottom: 10px;
    color: var(--primary);
    font-weight: 600;
}

.action-card p {
    color: #64748b;
    line-height: 1.6;
    font-size: 15px;
}
</style>

<?php include '../views/includes/footer.php'; ?>