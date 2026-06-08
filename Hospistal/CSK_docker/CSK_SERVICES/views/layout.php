<?php
/**
 * CSK Services - Layout principal
 * Vue principale de l'application avec sidebar et header
 */

// ============================================
// DÉFINITION DES VARIABLES POUR LE DROPDOWN
// ============================================

// 1. Déterminer les services autorisés
$services_autorises = [];

if (isset($is_admin) && $is_admin) {
    // Admin : tous les services
    $services_autorises = ['LABORATOIRE', 'IMAGERIE', 'PHARMACIE'];
} else {
    // Utilisateur simple : uniquement ses services
    if (isset($has_labo) && $has_labo) $services_autorises[] = 'LABORATOIRE';
    if (isset($has_imagerie) && $has_imagerie) $services_autorises[] = 'IMAGERIE';
    if (isset($has_pharmacie) && $has_pharmacie) $services_autorises[] = 'PHARMACIE';
}

// 2. Déterminer le service actif
$service_actif = strtoupper(trim($_GET['service'] ?? 'ALL'));

// Sécurité : si le service demandé n'est pas autorisé, on force ALL
if ($service_actif !== 'ALL' && !in_array($service_actif, $services_autorises)) {
    $service_actif = 'ALL';
}

// Pour un non-admin avec un seul service, on force l'affichage de ce service
if (!isset($is_admin) || !$is_admin) {
    if (count($services_autorises) === 1) {
        $service_actif = $services_autorises[0];
    }
}

// 3. Libellé et icône pour le dropdown
$service_actif_libelle = 'Tous les services';
$service_actif_icon = 'bi-grid-3x3-gap-fill';

if ($service_actif !== 'ALL') {
    $service_actif_libelle = ucfirst(strtolower($service_actif));
    $service_actif_icon = match($service_actif) {
        'LABORATOIRE' => 'bi-droplet',
        'IMAGERIE'    => 'bi-image',
        'PHARMACIE'   => 'bi-capsule',
        default       => 'bi-building'
    };
}

// 4. Passer ces variables à la page incluse
// (elles seront accessibles dans dashboard.php)
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title ?? 'Accueil') ?> - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --sidebar-width: 260px;
            --header-height: 60px;
            --color-labo: #6f42c1;
            --color-imagerie: #0dcaf0;
            --color-pharmacie: #198754;
        }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #f0f2f5;
            margin: 0;
        }

         /* ============ SIDEBAR ============ */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: var(--sidebar-width);
            height: 100vh;
            background: #1a1a2e;
            color: #fff;
            overflow-y: auto;
            z-index: 1000;
            transition: transform 0.3s;
        }
        .sidebar-header {
            padding: 1.25rem 1rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .sidebar-header h5 {
            font-size: 1.1rem;
            font-weight: 700;
            margin: 0;
        }
        .sidebar-header small {
            color: rgba(255,255,255,0.5);
            font-size: 0.75rem;
        }
        .sidebar-section {
            padding: 0.75rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .sidebar-section-title {
            padding: 0.25rem 1rem;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.35);
            font-weight: 600;
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 1rem;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.15s;
            border-left: 3px solid transparent;
        }
        .sidebar-link:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }
        .sidebar-link.active {
            background: rgba(255,255,255,0.1);
            color: #fff;
            border-left-color: #0d6efd;
            font-weight: 600;
        }
        .sidebar-link i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
        }

        /* ============ TOP HEADER ============ */
        .top-header {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: var(--header-height);
            background: #fff;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.5rem;
            z-index: 999;
        }
        .top-header .page-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
            margin: 0;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .notification-badge {
            position: relative;
        }
        .notification-badge .badge {
            position: absolute;
            top: -5px;
            right: -5px;
            font-size: 0.65rem;
            padding: 0.2rem 0.4rem;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .user-info .profil-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }

        /* ============ MAIN CONTENT ============ */
        .main-content {
            margin-left: var(--sidebar-width);
            margin-top: var(--header-height);
            padding: 1.5rem;
            min-height: calc(100vh - var(--header-height));
        }

        /* ============ FLASH MESSAGES ============ */
        .flash-container {
            margin-bottom: 1rem;
        }
        .flash-container .alert {
            border-radius: 10px;
            font-size: 0.9rem;
        }

        /* ============ RESPONSIVE ============ */
        @media (max-width: 992px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .top-header {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
        }

        /* ============ COULEUR HEADER PAR SERVICE ============ */
        body.service-laboratoire .top-header { border-top: 3px solid var(--color-labo); }
        body.service-imagerie    .top-header { border-top: 3px solid var(--color-imagerie); }
        body.service-pharmacie   .top-header { border-top: 3px solid var(--color-pharmacie); }
        body.service-all         .top-header { border-top: 3px solid #0d6efd; }

        /* ============ SIDEBAR ACCORDÉON ============ */
        .sidebar-submenu {
            max-height: 600px;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .sidebar-submenu.collapsed {
            max-height: 0;
        }
        .sidebar-toggle {
            cursor: pointer;
            display: flex;
            align-items: center;
            user-select: none;
        }
        .sidebar-toggle .toggle-icon {
            margin-left: auto;
            font-size: 0.75rem;
            transition: transform 0.3s;
            opacity: 0.6;
        }
        .sidebar-toggle.collapsed-state .toggle-icon {
            transform: rotate(-90deg);
        }

        /* ============ LOADER GLOBAL ============ */
        #global-loader {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(240, 242, 245, 0.85);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s;
        }
        #global-loader.active {
            opacity: 1;
            pointer-events: all;
        }
    </style>
</head>
<body class="service-<?= strtolower($service_actif === 'ALL' ? 'all' : strtolower($service_actif)) ?>">
    <!-- SIDEBAR -->
    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h5><i class="bi bi-hospital"></i> <?= APP_NAME ?></h5>
            <small>v<?= APP_VERSION ?> | <?= htmlspecialchars($site_nom) ?></small>
        </div>

        <!-- Navigation principale -->
        <div class="sidebar-section">
            <div class="sidebar-section-title">Principal</div>
            <a href="index.php?page=dashboard" 
               class="sidebar-link <?= $page === 'dashboard' ? 'active' : '' ?>">
                <i class="bi bi-grid-1x2"></i> Tableau de bord
            </a>
            <a href="index.php?page=notifications" 
               class="sidebar-link <?= $page === 'notifications' ? 'active' : '' ?>">
                <i class="bi bi-bell"></i> Notifications
                <?php if ($notifications_count > 0): ?>
                    <span class="badge bg-danger ms-auto"><?= $notifications_count ?></span>
                <?php endif; ?>
            </a>
            <a href="index.php?page=prescriptions" 
               class="sidebar-link <?= $page === 'prescriptions' ? 'active' : '' ?>">
                <i class="bi bi-prescription2"></i> Prescriptions
            </a>
        </div>

        <!-- Laboratoire -->
        <?php if ($has_labo || $is_admin): ?>
        <div class="sidebar-section">
            <div class="sidebar-section-title sidebar-toggle" data-target="menu-labo" style="color: var(--color-labo);">
                <i class="bi bi-droplet-fill"></i> Laboratoire
                <i class="bi bi-chevron-down toggle-icon"></i>
            </div>
            <div class="sidebar-submenu" id="menu-labo">
            <a href="index.php?page=labo&action=dashboard" 
               class="sidebar-link <?= ($page === 'labo' && $action === 'dashboard') ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard Labo
            </a>
            <a href="index.php?page=labo&action=echantillons" 
               class="sidebar-link <?= ($page === 'labo' && $action === 'echantillons') ? 'active' : '' ?>">
                <i class="bi bi-list-ul"></i> Echantillons
            </a>
            <a href="index.php?page=labo&action=workflow" 
               class="sidebar-link <?= ($page === 'labo' && $action === 'workflow') ? 'active' : '' ?>">
                <i class="bi bi-diagram-3"></i> Workflow
            </a>
            <a href="index.php?page=labo&action=resultats" 
               class="sidebar-link <?= ($page === 'labo' && $action === 'resultats') ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-medical"></i> Resultats
            </a>
            <a href="index.php?page=labo&action=rapport" 
                class="sidebar-link <?= ($page === 'labo' && $action === 'rapport') ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-line"></i> Rapport statistique
            </a>
            </div><!-- /menu-labo -->
        </div>
        <?php endif; ?>

        <!-- Imagerie -->
        <?php if ($has_imagerie || $is_admin): ?>
        <div class="sidebar-section">
            <div class="sidebar-section-title sidebar-toggle" data-target="menu-imagerie" style="color: var(--color-imagerie);">
                <i class="bi bi-image-fill"></i> Imagerie
                <i class="bi bi-chevron-down toggle-icon"></i>
            </div>
            <div class="sidebar-submenu" id="menu-imagerie">
            <a href="index.php?page=imagerie&action=dashboard" 
                class="sidebar-link <?= ($page === 'imagerie' && $action === 'dashboard') ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard Imagerie
            </a>
            <!-- <a href="index.php?page=imagerie&action=planning" 
                class="sidebar-link <?= ($page === 'imagerie' && $action === 'planning') ? 'active' : '' ?>">
                <i class="bi bi-calendar-week"></i> Planning
            </a> -->
            <a href="index.php?page=imagerie&action=examens" 
                class="sidebar-link <?= ($page === 'imagerie' && $action === 'examens') ? 'active' : '' ?>">
                <i class="bi bi-list-ul"></i> Examens
            </a>
            <a href="index.php?page=imagerie&action=workflow" 
                class="sidebar-link <?= ($page === 'imagerie' && $action === 'workflow') ? 'active' : '' ?>">
                <i class="bi bi-diagram-3"></i> Workflow
            </a>
            <!-- <a href="index.php?page=imagerie&action=pacs" 
                class="sidebar-link <?= ($page === 'imagerie' && $action === 'pacs') ? 'active' : '' ?>">
                <i class="bi bi-images"></i> Visualiseur PACS
            </a> -->
            <a href="index.php?page=imagerie&action=resultats" 
               class="sidebar-link <?= ($page === 'imagerie' && $action === 'resultats') ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-richtext"></i> Resultats / CR
            </a>
            <a href="index.php?page=imagerie&action=rapport" 
                class="sidebar-link <?= ($page === 'imagerie' && $action === 'rapport') ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-line"></i> Rapport statistique
            </a>
            </div><!-- /menu-imagerie -->
        </div>
        <?php endif; ?>

        <!-- Pharmacie -->
        <?php if ($has_pharmacie || $is_admin): ?>
        <div class="sidebar-section">
            <div class="sidebar-section-title sidebar-toggle" data-target="menu-pharmacie" style="color: var(--color-pharmacie);">
                <i class="bi bi-capsule"></i> Pharmacie
                <i class="bi bi-chevron-down toggle-icon"></i>
            </div>
            <div class="sidebar-submenu" id="menu-pharmacie">
            <a href="index.php?page=pharmacie&action=dashboard" 
                class="sidebar-link <?= ($page === 'pharmacie' && $action === 'dashboard') ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard Pharmacie
            </a>
            <a href="index.php?page=pharmacie&action=preparations" 
                class="sidebar-link <?= ($page === 'pharmacie' && $action === 'preparations') ? 'active' : '' ?>">
                <i class="bi bi-list-ul"></i> Préparations
            </a>
            <a href="index.php?page=pharmacie&action=workflow" 
                class="sidebar-link <?= ($page === 'pharmacie' && $action === 'workflow') ? 'active' : '' ?>">
                <i class="bi bi-diagram-3"></i> Workflow
            </a>
            
            <!-- Sous-menu Gestion des stocks -->
            <div class="sidebar-section-title mt-2" style="color: #adb5bd;">Gestion des stocks</div>
            <a href="index.php?page=pharmacie&action=stock-general" 
                class="sidebar-link <?= ($page === 'pharmacie' && $action === 'stock-general') ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i> Stock général
            </a>
            <a href="index.php?page=pharmacie&action=officine" 
                class="sidebar-link <?= ($page === 'pharmacie' && $action === 'officine') ? 'active' : '' ?>">
                <i class="bi bi-store"></i> Officines
            </a>
            <a href="index.php?page=pharmacie&action=inventaire" 
                class="sidebar-link <?= ($page === 'pharmacie' && $action === 'inventaire') ? 'active' : '' ?>">
                <i class="bi bi-clipboard-data"></i> Inventaire
            </a>
            <a href="index.php?page=pharmacie&action=depot-central" 
                class="sidebar-link <?= ($page === 'pharmacie' && $action === 'depot-central') ? 'active' : '' ?>">
                <i class="bi bi-building"></i> Dépôt central
            </a>
            
            <!-- Sous-menu Catalogue -->
            <div class="sidebar-section-title mt-2" style="color: #adb5bd;">Catalogue</div>
            <a href="index.php?page=pharmacie&action=produits" 
                class="sidebar-link <?= ($page === 'pharmacie' && $action === 'produits') ? 'active' : '' ?>">
                <i class="bi bi-capsule-pill"></i> Produits
            </a>
            
            <!-- Sous-menu Statistiques -->
            <div class="sidebar-section-title mt-2" style="color: #adb5bd;">Statistiques</div>
            <a href="index.php?page=pharmacie&action=rapport" 
                class="sidebar-link <?= ($page === 'pharmacie' && $action === 'rapport') ? 'active' : '' ?>">
                <i class="bi bi-bar-chart-line"></i> Rapport statistique
            </a>
            </div><!-- /menu-pharmacie -->
        </div>
        <?php endif; ?>

        <!-- Déconnexion -->
        <div class="sidebar-section" style="margin-top: auto;">
            <a href="index.php?page=logout" class="sidebar-link text-danger">
                <i class="bi bi-box-arrow-left"></i> Deconnexion
            </a>
        </div>
    </nav>

    <!-- TOP HEADER -->
    <header class="top-header">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-outline-secondary d-lg-none" onclick="document.getElementById('sidebar').classList.toggle('show')">
                <i class="bi bi-list"></i>
            </button>
            <h1 class="page-title"><?= htmlspecialchars($page_title ?? '') ?></h1>
        </div>

        <div class="header-right">
            <!-- Notifications -->
            <a href="index.php?page=notifications" class="btn btn-sm btn-light notification-badge">
                <i class="bi bi-bell"></i>
                <?php if ($notifications_count > 0): ?>
                    <span class="badge bg-danger rounded-pill"><?= $notifications_count ?></span>
                <?php endif; ?>
            </a>

            <!-- Switch service (admin) - FILTRE DASHBOARD -->
            <?php if ($is_admin): ?>
            <div class="dropdown">
                <button class="btn btn-sm btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown">
                    <i class="bi <?= $service_actif_icon ?>"></i> 
                    <?= $service_actif_libelle ?>
                </button>
                <ul class="dropdown-menu">
                    <li>
                        <a class="dropdown-item <?= $service_actif === 'ALL' ? 'active' : '' ?>" 
                        href="?<?= http_build_query(array_merge($_GET, ['page' => 'dashboard', 'service' => 'ALL'])) ?>">
                            <i class="bi bi-grid-3x3-gap-fill"></i> Tous les services
                        </a>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <?php foreach ($services_autorises as $service): 
                        $icon = match($service) {
                            'LABORATOIRE' => 'bi-droplet',
                            'IMAGERIE'    => 'bi-image',
                            'PHARMACIE'   => 'bi-capsule',
                            default       => 'bi-building'
                        };
                        $color = match($service) {
                            'LABORATOIRE' => 'var(--color-labo, #6f42c1)',
                            'IMAGERIE'    => 'var(--color-imagerie, #0dcaf0)',
                            'PHARMACIE'   => 'var(--color-pharmacie, #198754)',
                            default       => '#6c757d'
                        };
                    ?>
                    <li>
                        <a class="dropdown-item <?= $service_actif === $service ? 'active' : '' ?>" 
                        href="?<?= http_build_query(array_merge($_GET, ['page' => 'dashboard', 'service' => $service])) ?>">
                            <i class="bi <?= $icon ?>" style="color: <?= $color ?>;"></i> 
                            <?= ucfirst(strtolower($service)) ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <!-- User info -->
            <div class="user-info">
                <span class="profil-dot" style="background: <?= htmlspecialchars($profil_couleur) ?>;"></span>
                <div class="d-none d-md-block">
                    <div style="font-size: 0.85rem; font-weight: 600; line-height: 1.2;">
                        <?= htmlspecialchars($user_prenom . ' ' . $user_nom) ?>
                    </div>
                    <div style="font-size: 0.7rem; color: #6c757d;">
                        <?= htmlspecialchars($user_profil) ?>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- MAIN CONTENT -->
    <main class="main-content">
        <!-- Flash messages -->
        <?php if ($flash_success || $flash_error || $flash_warning || $flash_info): ?>
        <div class="flash-container">
            <?php if ($flash_success): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($flash_success) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($flash_error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($flash_error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($flash_warning): ?>
                <div class="alert alert-warning alert-dismissible fade show">
                    <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($flash_warning) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($flash_info): ?>
                <div class="alert alert-info alert-dismissible fade show">
                    <i class="bi bi-info-circle me-2"></i><?= htmlspecialchars($flash_info) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Module content -->
        <?php 
        if ($page_content === '404') {
            echo '<div class="text-center py-5">';
            echo '<h2 class="text-muted">Page non trouvee</h2>';
            echo '<p>La page demandee n\'existe pas.</p>';
            echo '<a href="index.php?page=dashboard" class="btn btn-primary">Retour au tableau de bord</a>';
            echo '</div>';
        } elseif (file_exists($module_file)) {
            include $module_file;
        }
        ?>
    </main>

    <!-- LOADER GLOBAL -->
    <div id="global-loader">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Chargement...</span>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-dismiss flash messages après 5 secondes
        document.querySelectorAll('.flash-container .alert').forEach(function(alert) {
            setTimeout(function() {
                var bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                bsAlert.close();
            }, 5000);
        });

        // ── Sidebar accordéon ─────────────────────────────────────────────
        (function() {
            var toggles = document.querySelectorAll('.sidebar-toggle');

            // Restaurer l'état sauvegardé
            toggles.forEach(function(toggle) {
                var targetId = toggle.dataset.target;
                var menu = document.getElementById(targetId);
                if (!menu) return;
                var saved = localStorage.getItem('sidebar_' + targetId);
                // Par défaut : section active reste ouverte, les autres se ferment
                var currentSection = toggle.querySelector('[class*="active"]');
                if (saved === 'collapsed') {
                    menu.classList.add('collapsed');
                    toggle.classList.add('collapsed-state');
                }
            });

            toggles.forEach(function(toggle) {
                toggle.addEventListener('click', function() {
                    var targetId = this.dataset.target;
                    var menu = document.getElementById(targetId);
                    if (!menu) return;
                    var isCollapsed = menu.classList.toggle('collapsed');
                    this.classList.toggle('collapsed-state', isCollapsed);
                    try {
                        localStorage.setItem('sidebar_' + targetId, isCollapsed ? 'collapsed' : 'open');
                    } catch(e) {}
                });
            });
        })();

        // ── Loader global ────────────────────────────────────────────────
        (function() {
            var loader = document.getElementById('global-loader');
            if (!loader) return;

            // Masquer quand la page est chargée
            window.addEventListener('load', function() {
                loader.classList.remove('active');
            });

            // Afficher sur les clics de liens (navigation entre pages)
            document.addEventListener('click', function(e) {
                var link = e.target.closest('a[href]');
                if (!link) return;
                var href = link.getAttribute('href');
                // Ne pas afficher pour ancres, logout, ou liens externes
                if (!href || href.startsWith('#') || href.startsWith('javascript') || href.includes('logout')) return;
                // Ne pas afficher pour les actions AJAX (pas de navigation)
                if (link.dataset.noLoader) return;
                loader.classList.add('active');
            });
        })();
    </script>
    <!-- ============================================ -->
    <!-- NOTIFICATION POLLING + BIP SONORE           -->
    <!-- Web Audio API — async/await + unlock correct -->
    <!-- ============================================ -->
    <script>
    (function () {
        'use strict';

        // ── Son de notification via Web Audio API ────────────────────────────
        const notifSound = (function () {

            let ctx = null;

            // Déverrouillage : crée le contexte ET attend resume() à la 1re interaction
            function unlock() {
                if (ctx) return;
                ctx = new (window.AudioContext || window.webkitAudioContext)();
                ctx.resume();   // asynchrone, mais on ne bloque pas unlock()
            }

            ['click', 'keydown', 'touchstart', 'mousedown'].forEach(function (evt) {
                document.addEventListener(evt, unlock, { passive: true, capture: true });
            });

            // getCtx() async : attend que le contexte soit vraiment running
            async function getCtx() {
                if (!ctx) {
                    ctx = new (window.AudioContext || window.webkitAudioContext)();
                }
                if (ctx.state === 'suspended') {
                    await ctx.resume();
                }
                return ctx;
            }

            return {
                // play() async : attend getCtx() avant de lancer les oscillateurs
                play: async function () {
                    try {
                        var c = await getCtx();
                        if (!c || c.state !== 'running') return;

                        var now = c.currentTime;

                        // Ton 1 : 800 Hz, 70 ms
                        var o1 = c.createOscillator();
                        var g1 = c.createGain();
                        o1.connect(g1);
                        g1.connect(c.destination);
                        o1.type = 'sine';
                        o1.frequency.value = 800;
                        g1.gain.setValueAtTime(0.35, now);
                        g1.gain.exponentialRampToValueAtTime(0.001, now + 0.07);
                        o1.start(now);
                        o1.stop(now + 0.07);

                        // Ton 2 : 1050 Hz, 90 ms (après 30 ms de silence)
                        var o2 = c.createOscillator();
                        var g2 = c.createGain();
                        o2.connect(g2);
                        g2.connect(c.destination);
                        o2.type = 'sine';
                        o2.frequency.value = 1050;
                        g2.gain.setValueAtTime(0.35, now + 0.10);
                        g2.gain.exponentialRampToValueAtTime(0.001, now + 0.19);
                        o2.start(now + 0.10);
                        o2.stop(now + 0.19);

                    } catch (e) {
                        // Silencieux — AudioContext non supporté
                    }
                }
            };
        })();

        // Expose pour test console : notifSound.play()
        window.notifSound = notifSound;

        // ── Compteur précédent (initialisé depuis le badge PHP rendu) ────────
        var _badge = document.querySelector('.notification-badge .badge');
        var prevCount = _badge ? (parseInt(_badge.textContent.trim(), 10) || 0) : 0;

        // ── Met à jour le badge dans le header ───────────────────────────────
        function updateBadge(count) {
            var btn = document.querySelector('.notification-badge');
            if (!btn) return;
            var b = btn.querySelector('.badge');
            if (count > 0) {
                if (!b) {
                    b = document.createElement('span');
                    b.className = 'badge bg-danger rounded-pill';
                    btn.appendChild(b);
                }
                b.textContent = count;
                b.style.display = '';
            } else {
                if (b) b.style.display = 'none';
            }
        }

        // ── Polling ──────────────────────────────────────────────────────────
        function checkNotifications() {
            fetch('api/notifications.php?action=count', {
                method:  'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                cache:   'no-store',
            })
            .then(function (resp) { return resp.ok ? resp.json() : null; })
            .then(function (data) {
                if (!data) return;
                var newCount = parseInt(data.count || data.total || 0, 10);
                if (newCount > prevCount) {
                    notifSound.play();   // 🔔
                }
                updateBadge(newCount);
                prevCount = newCount;
            })
            .catch(function () {});
        }

        setTimeout(checkNotifications, 10000);
        setInterval(checkNotifications, 30000);

    })();
    </script>
</body>
</html>