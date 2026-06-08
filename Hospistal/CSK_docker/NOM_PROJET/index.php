<?php
require_once 'config/config.php';
requireLogin();

include 'views/includes/header.php';
?>

<div class="dashboard">
    <div class="dashboard-header">
        <h1>Tableau de bord</h1>
        <p>Bienvenue, <?php echo htmlspecialchars($_SESSION['nom_complet']); ?> &mdash;
           <?php echo htmlspecialchars($_SESSION['site_nom'] ?? ''); ?></p>
    </div>

    <div class="module-grid">
        <?php if (hasPermission('reception')): ?>
        <a href="reception/index.php" class="module-card">
            <div class="module-icon">👥</div>
            <h3>Réception</h3>
            <p>Enregistrement et recherche des patients</p>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('consultation')): ?>
        <a href="consultation/index.php" class="module-card">
            <div class="module-icon">🩺</div>
            <h3>Consultation</h3>
            <p>Dossiers médicaux et consultations</p>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('hospitalisation')): ?>
        <a href="hospitalisation/index.php" class="module-card">
            <div class="module-icon">🏥</div>
            <h3>Hospitalisation</h3>
            <p>Gestion des hospitalisations</p>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('pharmacie')): ?>
        <a href="pharmacie/index.php" class="module-card">
            <div class="module-icon">💊</div>
            <h3>Pharmacie</h3>
            <p>Gestion du stock et prescriptions</p>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('laboratoire')): ?>
        <a href="laboratoire/index.php" class="module-card">
            <div class="module-icon">🔬</div>
            <h3>Laboratoire</h3>
            <p>Résultats d'analyses</p>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('imagerie')): ?>
        <a href="imagerie/index.php" class="module-card">
            <div class="module-icon">📷</div>
            <h3>Imagerie</h3>
            <p>Radiologie et échographie</p>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('facturation')): ?>
        <a href="facturation/index.php" class="module-card">
            <div class="module-icon">💰</div>
            <h3>Facturation</h3>
            <p>Gestion des factures et paiements</p>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('bloc')): ?>
        <a href="bloc/index.php" class="module-card">
            <div class="module-icon">🔪</div>
            <h3>Bloc Opératoire</h3>
            <p>Programme et interventions</p>
        </a>
        <?php endif; ?>

        <?php if (hasPermission('admin')): ?>
        <a href="admin/index.php" class="module-card">
            <div class="module-icon">⚙️</div>
            <h3>Administration</h3>
            <p>Configuration du système</p>
        </a>
        <?php endif; ?>
    </div>
</div>

<?php include 'views/includes/footer.php'; ?>
