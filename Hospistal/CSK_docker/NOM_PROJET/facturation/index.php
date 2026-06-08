<?php
require_once '../config/config.php';
requireLogin();

$pageTitle = "Facturation - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h1><i class="fas fa-file-invoice-dollar"></i> Module Facturation</h1>
        <p>Gestion des factures et paiements</p>
    </div>

    <div class="module-grid">
        <a href="facturation-prive.php" class="module-card">
            <div class="module-icon"><i class="fas fa-user"></i></div>
            <h3>Facturation Privée</h3>
            <p>Validation et paiement des patients privés</p>
            <div class="card-arrow">→</div>
        </a>

        <a href="facturation-conventionnee.php" class="module-card">
            <div class="module-icon"><i class="fas fa-briefcase"></i></div>
            <h3>Facturation Conventionnée</h3>
            <p>Gestion des factures société</p>
            <div class="card-arrow">→</div>
        </a>

        <a href="caisse.php" class="module-card">
            <div class="module-icon"><i class="fas fa-cash-register"></i></div>
            <h3>Ma Caisse</h3>
            <p>État de caisse et billetage</p>
            <div class="card-arrow">→</div>
        </a>

        <a href="rapports.php" class="module-card">
            <div class="module-icon"><i class="fas fa-chart-line"></i></div>
            <h3>Rapports</h3>
            <p>Statistiques de facturation</p>
            <div class="card-arrow">→</div>
        </a>
    </div>
</div>

<style>
.dashboard {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.dashboard-header {
    text-align: center;
    margin-bottom: 40px;
    padding: 30px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 15px;
    color: white;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.dashboard-header h1 {
    margin: 0 0 10px 0;
    font-size: 2.5rem;
    font-weight: 700;
}

.dashboard-header p {
    margin: 0;
    font-size: 1.2rem;
    opacity: 0.9;
}

.module-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 25px;
    margin-top: 30px;
}

.module-card {
    display: block;
    background: white;
    padding: 30px 25px;
    border-radius: 15px;
    text-decoration: none;
    color: inherit;
    box-shadow: 0 5px 20px rgba(0,0,0,0.08);
    transition: all 0.3s ease;
    border: 1px solid #f0f0f0;
    position: relative;
    overflow: hidden;
}

.module-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.module-card:hover {
    transform: translateY(-8px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.15);
    text-decoration: none;
    color: inherit;
}

.module-icon {
    font-size: 3rem;
    margin-bottom: 20px;
    color: #667eea;
    text-align: center;
}

.module-card h3 {
    margin: 0 0 12px 0;
    font-size: 1.4rem;
    font-weight: 600;
    color: #2d3748;
    text-align: center;
}

.module-card p {
    margin: 0;
    color: #718096;
    text-align: center;
    line-height: 1.5;
}

.card-arrow {
    position: absolute;
    bottom: 20px;
    right: 20px;
    font-size: 1.5rem;
    color: #cbd5e0;
    transition: all 0.3s ease;
}

.module-card:hover .card-arrow {
    color: #667eea;
    transform: translateX(5px);
}
</style>

<?php include '../views/includes/footer.php'; ?>