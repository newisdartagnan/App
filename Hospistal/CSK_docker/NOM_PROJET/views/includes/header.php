<?php
// Sécurité : login requis (déjà vérifié avant, mais on peut doubler)
$pageTitle = $pageTitle ?? APP_NAME;
$flash = getFlashMessage();

// Initiales utilisateur pour l'avatar
$initiales = strtoupper(
    substr($_SESSION['nom_complet'] ?? 'U', 0, 1) .
    (strpos($_SESSION['nom_complet'] ?? '', ' ') !== false
        ? substr(strrchr($_SESSION['nom_complet'] ?? '', ' '), 1, 1)
        : '')
);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<div class="wrapper">

    <!-- ===================== SIDEBAR ===================== -->
    <aside class="sidebar">
        <div class="sidebar-brand">
            <h2><i class="fas fa-hospital-user"></i> GPS</h2>
            <small>Centre Hospitalier Monkole</small>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">Modules</div>

            <?php if (hasPermission('reception')): ?>
            <a href="<?php echo BASE_URL; ?>reception/index.php" class="nav-link">
                <i class="fas fa-user-plus"></i>
                <span>Réception</span>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('consultation')): ?>
            <a href="<?php echo BASE_URL; ?>consultation/index.php" class="nav-link">
                <i class="fas fa-stethoscope"></i>
                <span>Consultation</span>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('hospitalisation')): ?>
            <a href="<?php echo BASE_URL; ?>hospitalisation/index.php" class="nav-link">
                <i class="fas fa-hospital"></i>
                <span>Hospitalisation</span>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('pharmacie')): ?>
            <a href="<?php echo BASE_URL; ?>pharmacie/index.php" class="nav-link">
                <i class="fas fa-pills"></i>
                <span>Pharmacie</span>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('laboratoire')): ?>
            <a href="<?php echo BASE_URL; ?>laboratoire/index.php" class="nav-link">
                <i class="fas fa-flask"></i>
                <span>Laboratoire</span>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('imagerie')): ?>
            <a href="<?php echo BASE_URL; ?>imagerie/index.php" class="nav-link">
                <i class="fas fa-x-ray"></i>
                <span>Imagerie</span>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('facturation')): ?>
            <a href="<?php echo BASE_URL; ?>facturation/index.php" class="nav-link">
                <i class="fas fa-file-invoice-dollar"></i>
                <span>Facturation</span>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('bloc')): ?>
            <a href="<?php echo BASE_URL; ?>bloc/index.php" class="nav-link">
                <i class="fas fa-procedures"></i>
                <span>Bloc Opératoire</span>
            </a>
            <?php endif; ?>

            <?php if (hasPermission('admin')): ?>
            <div class="nav-section">Administration</div>
            <a href="<?php echo BASE_URL; ?>admin/index.php" class="nav-link">
                <i class="fas fa-cog"></i>
                <span>Administration</span>
            </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div><?php echo htmlspecialchars($_SESSION['nom_complet'] ?? ''); ?></div>
            <div><?php echo htmlspecialchars($_SESSION['profil'] ?? ''); ?></div>
        </div>
    </aside>

    <!-- ===================== TOPBAR ===================== -->
    <header class="topbar">
        <div class="topbar-left">
            <span class="topbar-title"><?php echo APP_NAME; ?></span>
        </div>
        <div class="topbar-right">
            <div class="user-info">
                <div class="user-avatar"><?php echo htmlspecialchars($initiales); ?></div>
                <div>
                    <div style="font-weight:600;font-size:13px"><?php echo htmlspecialchars($_SESSION['nom_complet'] ?? ''); ?></div>
                    <div style="font-size:11px;color:#64748b"><?php echo htmlspecialchars($_SESSION['site_nom'] ?? ''); ?></div>
                </div>
            </div>
            <a href="<?php echo BASE_URL; ?>logout.php" class="btn btn-outline btn-sm" title="Déconnexion">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </header>

    <!-- ===================== CONTENU ===================== -->
    <main class="main-content">

    <?php if ($flash): ?>
        <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'error' : $flash['type']; ?>" data-autohide>
            <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'error' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
            <?php echo htmlspecialchars($flash['message']); ?>
        </div>
    <?php endif; ?>
