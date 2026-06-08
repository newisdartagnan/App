<?php
require_once '../config/config.php';
requireLogin();

$pageTitle = "Consultation MÃ©dicale - " . APP_NAME;
include '../views/includes/header.php';
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h1><i class="fas fa-stethoscope"></i> Module Consultation MÃ©dicale</h1>
        <p>Dossiers mÃ©dicaux et consultations</p>
    </div>

    <div class="module-grid">
        <a href="salle-attente.php" class="module-card">
            <div class="module-icon"><i class="fas fa-user-clock"></i></div>
            <h3>Salle d'Attente</h3>
            <p>Patients en attente de consultation</p>
            <div class="card-arrow">â†’</div>
        </a>

        <a href="mes-consultations.php" class="module-card">
            <div class="module-icon"><i class="fas fa-notes-medical"></i></div>
            <h3>Mes Consultations</h3>
            <p>Consultations du jour</p>
            <div class="card-arrow">â†’</div>
        </a>

        <a href="recherche-dossier.php" class="module-card">
            <div class="module-icon"><i class="fas fa-folder-open"></i></div>
            <h3>Recherche Dossier</h3>
            <p>AccÃ©der au dossier d'un patient</p>
            <div class="card-arrow">â†’</div>
        </a>

        <a href="ordonnances.php" class="module-card">
            <div class="module-icon"><i class="fas fa-prescription"></i></div>
            <h3>Ordonnances</h3>
            <p>GÃ©rer les ordonnances</p>
            <div class="card-arrow">â†’</div>
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
    background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
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
    background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
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
    color: #48bb78;
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
    color: #48bb78;
    transform: translateX(5px);
}
</style>

<?php include '../views/includes/footer.php'; ?>