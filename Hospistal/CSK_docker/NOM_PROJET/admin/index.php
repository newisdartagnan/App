<?php
require_once '../config/config.php';
requireLogin();
if (!hasPermission('admin')) redirect('../index.php');
$pageTitle = "Administration - " . APP_NAME;
include '../views/includes/header.php';
?>
<div class="module-header">
    <h1><i class="fas fa-cog"></i> Administration</h1>
    <p>Configuration du système</p>
</div>
<div class="action-cards">
    <a href="utilisateurs.php" class="action-card">
        <div class="action-icon"><i class="fas fa-users"></i></div>
        <h3>Utilisateurs</h3>
        <p>Gérer les comptes</p>
    </a>
    <a href="profils.php" class="action-card">
        <div class="action-icon"><i class="fas fa-user-shield"></i></div>
        <h3>Profils &amp; Droits</h3>
        <p>Gérer les permissions</p>
    </a>
    <a href="referentiels.php" class="action-card">
        <div class="action-icon"><i class="fas fa-database"></i></div>
        <h3>Référentiels</h3>
        <p>Motifs, services, origines...</p>
    </a>
</div>
<?php include '../views/includes/footer.php'; ?>
