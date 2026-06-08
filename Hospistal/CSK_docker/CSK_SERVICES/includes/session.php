<?php
/**
 * CSK Services - Gestion de la session
 * 
 * Ce fichier est inclus en haut de chaque page protégée.
 * Il vérifie la session, le timeout et prépare les variables globales.
 */

// config.php et auth.php sont déjà chargés par index.php
// On ne les re-inclut PAS ici pour éviter les redéclarations.
// Ce fichier est toujours appelé APRÈS index.php qui fait :
//   require_once config/config.php
//   require_once includes/auth.php

// =============================================
// VÉRIFIER LA SESSION
// =============================================

// 1. Vérifier si l'utilisateur est connecté
if (!isLoggedIn()) {
    redirect('index.php?page=login');
    exit();
}

// 2. Vérifier le timeout de session (30 min d'inactivité)
if (checkSessionTimeout(30)) {
    redirect('index.php?page=login&reason=timeout');
    exit();
}

// 3. Récupérer les infos utilisateur pour les vues
$currentUser = getCurrentUser();

// =============================================
// VARIABLES GLOBALES POUR LES VUES
// =============================================

// Infos utilisateur
$user_id          = $currentUser['id'];
$user_nom         = $currentUser['nom'];
$user_prenom      = $currentUser['prenom'];
$user_profil      = $currentUser['profil'];
$user_profil_code = $currentUser['profil_code'];
$profil_couleur   = $currentUser['profil_couleur'];
$site_nom         = $currentUser['site_nom'];
$service_actif    = $currentUser['service_actif'];

// Services auxquels l'utilisateur a accès
$services_autorises = $currentUser['services_autorises'];
$has_labo           = in_array('labo', $services_autorises);
$has_imagerie       = in_array('imagerie', $services_autorises);
$has_pharmacie      = in_array('pharmacie', $services_autorises);
$is_admin           = isAdmin();

// =============================================
// COMPTEUR DE NOTIFICATIONS NON LUES
// =============================================

require_once __DIR__ . '/notifications_helpers.php';

$notifications_count = 0;
try {
    $notifications_count = countUnread($user_id, $user_profil_code);
} catch (Exception $e) {
    error_log("[CSK Services] Erreur comptage notifications: " . $e->getMessage());
    $notifications_count = 0;
}

// =============================================
// FLASH MESSAGES (récupérer pour affichage)
// =============================================

$flash_success = getFlash('success');
$flash_error   = getFlash('error');
$flash_warning = getFlash('warning');
$flash_info    = getFlash('info');