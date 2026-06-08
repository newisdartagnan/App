<?php
/**
 * CSK Services - Point d'entrée unique (routeur)
 * 
 * Toutes les URLs passent par ce fichier :
 *   index.php?page=login
 *   index.php?page=dashboard
 *   index.php?page=labo&action=echantillons
 *   index.php?page=imagerie&action=...
 *   index.php?page=pharmacie&action=...
 */

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

// =============================================
// GESTION DU ROUTING
// =============================================

$page   = isset($_GET['page'])   ? sanitizeInput($_GET['page'])   : 'dashboard';
$action = isset($_GET['action']) ? sanitizeInput($_GET['action']) : 'index';

// =============================================
// PAGES PUBLIQUES (pas besoin d'auth)
// =============================================

if ($page === 'login') {
    handleLogin();
    exit();
}

if ($page === 'logout') {
    logout();
    redirect('index.php?page=login');
    exit();
}

// =============================================
// VÉRIFICATION DE SESSION (pages protégées)
// =============================================

// Inclure le guard de session
require_once __DIR__ . '/includes/session.php';

// Récupérer les informations utilisateur pour toutes les pages protégées
$currentUser = getCurrentUser();
$user_id = $currentUser['id'];
$user_nom = $currentUser['nom'];
$user_prenom = $currentUser['prenom'];
$user_profil = $currentUser['profil'];
$user_profil_code = $currentUser['profil_code'];
$profil_couleur = $currentUser['profil_couleur'];
$site_nom = $currentUser['site_nom'];
$user_services = $currentUser['services_autorises'];
$service_actif = $currentUser['service_actif'];
$is_admin = ($user_profil_code === 'admin');

// Vérifier les accès aux services
$has_labo = in_array('labo', $user_services) || $is_admin;
$has_imagerie = in_array('imagerie', $user_services) || $is_admin;
$has_pharmacie = in_array('pharmacie', $user_services) || $is_admin;

// =============================================
// ROUTING DES PAGES PROTÉGÉES
// =============================================

switch ($page) {
    case 'dashboard':
        // Dashboard principal - accessible à tous les profils autorisés
        $page_title = 'Tableau de bord';
        $page_content = 'dashboard';
        break;
    
    case 'labo':
        if (!$has_labo) {
            setFlash('error', 'Accès non autorisé au module laboratoire.');
            redirect('index.php?page=dashboard');
            exit();
        }
        $page_title = 'Laboratoire';
        $page_content = 'labo/' . $action;
        break;
    
    case 'imagerie':
        if (!$has_imagerie) {
            setFlash('error', 'Accès non autorisé au module imagerie.');
            redirect('index.php?page=dashboard');
            exit();
        }
        $page_title = 'Imagerie Médicale';
        $page_content = 'imagerie/' . $action;
        break;
    
    case 'pharmacie':
        if (!$has_pharmacie) {
            setFlash('error', 'Accès non autorisé au module pharmacie.');
            redirect('index.php?page=dashboard');
            exit();
        }
        $page_title = 'Pharmacie';
        $page_content = 'pharmacie/' . $action;
        break;
    
    case 'notifications':
        $page_title = 'Notifications';
        $page_content = 'notifications';
        break;
    
    case 'prescriptions':
        $page_title = 'Prescriptions';
        $page_content = 'prescriptions';
        break;
    
    case 'switch_service':
        // Changer de service actif (admin uniquement)
        if (!$is_admin) {
            setFlash('error', 'Action non autorisée.');
            redirect('index.php?page=dashboard');
            exit();
        }
        $service = isset($_GET['service']) ? sanitizeInput($_GET['service']) : '';
        switchService($service);
        redirect('index.php?page=dashboard');
        exit();
    
    default:
        $page_title = 'Page non trouvée';
        $page_content = '404';
        break;
}

// =============================================
// RENDU DE LA PAGE (layout principal)
// =============================================

// Vérifier si le fichier du module existe
$module_file = MODULES_PATH . '/' . $page_content . '.php';
if (!file_exists($module_file) && $page_content !== '404') {
    $page_title = 'Module en construction';
    $page_content = 'coming_soon';
    $module_file = MODULES_PATH . '/coming_soon.php';
}

// Compter les notifications non lues
$notifications_count = 0;
try {
    $db = new Database();
    $conn_services = $db->getServicesConnection();
    
    if ($is_admin) {
        $stmt = $conn_services->query("SELECT COUNT(*) FROM services_notifications WHERE lu = 0 AND archive = 0");
        $notifications_count = (int)$stmt->fetchColumn();
    } else {
        $stmt = $conn_services->prepare(
            "SELECT COUNT(*) FROM services_notifications 
             WHERE lu = 0 AND archive = 0 
             AND (groupe_destinataire = :g OR id_destinataire = :uid)"
        );
        $stmt->execute([':g' => $currentUser['groupe_notification'], ':uid' => $user_id]);
        $notifications_count = (int)$stmt->fetchColumn();
    }
} catch (Exception $e) {
    error_log("[CSK Services] Erreur comptage notifications: " . $e->getMessage());
}

// Récupérer les messages flash
$flash_success = getFlash('success');
$flash_error = getFlash('error');
$flash_warning = getFlash('warning');
$flash_info = getFlash('info');

// Inclure le layout principal
include __DIR__ . '/views/layout.php';

// Envoyer le contenu du buffer
if (ob_get_level()) {
    ob_end_flush();
}

exit();

// =============================================
// FONCTION LOGIN (page publique)
// =============================================

function handleLogin() {
    $error = '';
    $login_value = '';
    
    // Message de timeout
    if (isset($_GET['reason']) && $_GET['reason'] === 'timeout') {
        $error = 'Votre session a expiré. Veuillez vous reconnecter.';
    }
    
    // Traitement du formulaire POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $login_value = sanitizeInput($_POST['login'] ?? '');
        $password    = $_POST['password'] ?? '';
        
        if (empty($login_value) || empty($password)) {
            $error = 'Veuillez remplir tous les champs.';
        } else {
            $result = attemptLogin($login_value, $password);
            
            if ($result['success']) {
                // Rediriger vers le dashboard
                redirect('index.php?page=dashboard');
                exit();
            } else {
                $error = $result['message'];
            }
        }
    }
    
    // Afficher la page de login
    include __DIR__ . '/views/login.php';
}