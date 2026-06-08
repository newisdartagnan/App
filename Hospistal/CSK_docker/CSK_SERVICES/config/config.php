<?php
/**
 * CSK Services - Configuration générale
 * 
 * Application dédiée aux services techniques :
 *  - Laboratoire
 *  - Imagerie médicale
 *  - Pharmacie
 * 
 * Seuls les profils ci-dessous ont accès à cette application.
 */

// =============================================
// OUTPUT BUFFERING (pour éviter les erreurs headers already sent)
// =============================================
if (!ob_get_level()) {
    ob_start();
}

// =============================================
// DÉMARRAGE DE LA SESSION
// =============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =============================================
// CONFIGURATION GÉNÉRALE
// =============================================
define('APP_NAME', 'CSK Services');
define('APP_VERSION', '1.0');
define('BASE_URL', getenv('BASE_URL') ?: 'http://localhost:8002/');
define('ASSETS_URL', BASE_URL . 'assets/');

// =============================================
// CHEMINS
// =============================================
define('ROOT_PATH', dirname(__DIR__));
define('CONFIG_PATH', ROOT_PATH . '/config');
define('INCLUDES_PATH', ROOT_PATH . '/includes');
define('MODULES_PATH', ROOT_PATH . '/modules');
define('ASSETS_PATH', ROOT_PATH . '/assets');
define('API_PATH', ROOT_PATH . '/api');
define('LOGS_PATH', ROOT_PATH . '/logs');

// Uploads spécifiques aux services
define('UPLOADS_PATH', ROOT_PATH . '/uploads');
define('LABO_UPLOADS_PATH', UPLOADS_PATH . '/labo');
define('IMAGERIE_UPLOADS_PATH', UPLOADS_PATH . '/imagerie');
define('PHARMACIE_UPLOADS_PATH', UPLOADS_PATH . '/pharmacie');

// =============================================
// FUSEAU HORAIRE
// =============================================
date_default_timezone_set('Africa/Kinshasa');

// =============================================
// GESTION DES ERREURS (désactiver en production)
// =============================================
error_reporting(E_ALL);
ini_set('display_errors', 1);

// =============================================
// PROFILS AUTORISÉS POUR CSK SERVICES
// =============================================
// Seuls ces profils (par idprofiluser dans csk_base.profiluser) 
// ont le droit de se connecter à cette application.

define('PROFILS_AUTORISES', [
    // Administration (accès total)
    1  => ['code' => 'admin',               'nom' => 'Administrateur',       'services' => ['labo', 'imagerie', 'pharmacie']],
    
    // Laboratoire
    15 => ['code' => 'technicien_labo',      'nom' => 'Technicien Labo',      'services' => ['labo']],
    34 => ['code' => 'biologiste',           'nom' => 'Biologiste',           'services' => ['labo']],
    
    // Imagerie
    35 => ['code' => 'technicien_imagerie',  'nom' => 'Technicien Imagerie',  'services' => ['imagerie']],
    16 => ['code' => 'radiologue',           'nom' => 'Radiologue',           'services' => ['imagerie']],
    
    // Pharmacie
    4  => ['code' => 'pharmacien',           'nom' => 'Pharmacien',           'services' => ['pharmacie']],
    5  => ['code' => 'pharmacien_chef',      'nom' => 'Pharmacien Chef',      'services' => ['pharmacie']],
]);

// Tableau simple des IDs autorisés (pour requêtes rapides)
define('PROFILS_IDS_AUTORISES', array_keys(PROFILS_AUTORISES));

// =============================================
// MAPPING SERVICE → GROUPE NOTIFICATION
// =============================================
// Correspondance entre le profil et le groupe_destinataire 
// utilisé dans services_notifications
define('PROFIL_GROUPE_NOTIFICATION', [
    'technicien_labo'     => 'techniciens_labo',
    'biologiste'          => 'biologistes',
    'technicien_imagerie' => 'manipulateurs_imagerie',
    'radiologue'          => 'radiologues',
    'pharmacien'          => 'pharmaciens',
    'pharmacien_chef'     => 'pharmaciens',
    'admin'               => 'admin',
]);

// =============================================
// AUTOLOADER
// =============================================
spl_autoload_register(function ($class) {
    $paths = [
        CONFIG_PATH   . '/' . $class . '.php',
        INCLUDES_PATH . '/' . $class . '.php',
    ];
    
    foreach ($paths as $path) {
        if (file_exists($path)) {
            require_once $path;
            return;
        }
    }
});

// =============================================
// FONCTIONS UTILITAIRES (reprises de l'app GPS)
// =============================================

/**
 * Redirection vers une URL relative à BASE_URL
 */
function redirect($url) {
    // Vider le buffer de sortie si actif
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Empêcher toute sortie après la redirection
    header("Location: " . BASE_URL . $url);
    exit();
}

/**
 * Vérifier si l'utilisateur est connecté
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Exiger une connexion (sinon redirect login)
 */
function requireLogin() {
    if (!isLoggedIn()) {
        redirect('index.php?page=login');
    }
}

/**
 * Vérifier si le profil connecté est un admin
 */
function isAdmin() {
    return isset($_SESSION['profil_code']) && $_SESSION['profil_code'] === 'admin';
}

/**
 * Vérifier si l'utilisateur a accès à un service donné
 * 
 * @param string $service  'labo', 'imagerie' ou 'pharmacie'
 * @return bool
 */
function hasServiceAccess($service) {
    if (!isLoggedIn()) return false;
    if (isAdmin()) return true;
    
    return isset($_SESSION['services_autorises']) 
        && in_array($service, $_SESSION['services_autorises']);
}

/**
 * Exiger l'accès à un service (sinon redirect vers accueil)
 * 
 * @param string $service  'labo', 'imagerie' ou 'pharmacie'
 */
function requireServiceAccess($service) {
    requireLogin();
    if (!hasServiceAccess($service)) {
        $_SESSION['flash_error'] = "Vous n'avez pas accès au service " . ucfirst($service) . ".";
        redirect('index.php?page=dashboard');
    }
}

/**
 * Vérifier une permission détaillée (reprend le pattern GPS)
 * 
 * @param string $permission  Nom de la fonctionnalité (ex: 'gestion_echantillons')
 * @param string $action      'consulter', 'creer', 'modifier', 'supprimer', 'imprimer', 'valider'
 * @return bool
 */
function hasPermission($permission, $action = 'consulter') {
    if (!isLoggedIn()) return false;
    
    // Admins = tous les droits
    if (isAdmin()) return true;
    
    // Vérifier dans les permissions chargées en session
    if (isset($_SESSION['permissions'])) {
        if (in_array($permission, $_SESSION['permissions'])) {
            if ($action === 'consulter' || $action === 'access') {
                return true;
            }
            
            if (isset($_SESSION['permissions_details'][$permission])) {
                $details = $_SESSION['permissions_details'][$permission];
                
                switch ($action) {
                    case 'creer':
                    case 'create':
                        return isset($details['peut_creer']) && $details['peut_creer'] == 1;
                    case 'modifier':
                    case 'update':
                        return isset($details['peut_modifier']) && $details['peut_modifier'] == 1;
                    case 'supprimer':
                    case 'delete':
                        return isset($details['peut_supprimer']) && $details['peut_supprimer'] == 1;
                    case 'consulter':
                    case 'read':
                        return isset($details['peut_consulter']) && $details['peut_consulter'] == 1;
                    case 'imprimer':
                    case 'print':
                        return isset($details['peut_imprimer']) && $details['peut_imprimer'] == 1;
                    case 'valider':
                    case 'validate':
                        return isset($details['peut_valider']) && $details['peut_valider'] == 1;
                    default:
                        return true;
                }
            }
            
            return true;
        }
    }
    
    return false;
}

/**
 * Charger les permissions depuis csk_base (appelé au login)
 */
function loadUserPermissions($idutilisateur, $conn) {
    try {
        $query = "SELECT DISTINCT 
                    f.nom,
                    fp.peut_creer,
                    fp.peut_modifier,
                    fp.peut_supprimer,
                    fp.peut_consulter,
                    fp.peut_imprimer,
                    fp.peut_valider
                  FROM utilisateur u
                  JOIN profiluser p ON u.idprofiluser = p.idprofiluser
                  JOIN fct_profiluser fp ON p.idprofiluser = fp.idprofiluser
                  JOIN fct f ON fp.idfct = f.idfct
                  WHERE u.idutilisateur = :idutilisateur
                  AND u.statut = 'actif'
                  AND p.statut = 'actif'
                  AND f.statut = 'actif'";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([':idutilisateur' => $idutilisateur]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Liste simple des noms de fonctionnalités
        $_SESSION['permissions'] = array_column($permissions, 'nom');
        
        // Détails par fonctionnalité
        $_SESSION['permissions_details'] = [];
        foreach ($permissions as $perm) {
            $_SESSION['permissions_details'][$perm['nom']] = [
                'peut_creer'     => $perm['peut_creer'] ?? 0,
                'peut_modifier'  => $perm['peut_modifier'] ?? 0,
                'peut_supprimer' => $perm['peut_supprimer'] ?? 0,
                'peut_consulter' => $perm['peut_consulter'] ?? 1,
                'peut_imprimer'  => $perm['peut_imprimer'] ?? 0,
                'peut_valider'   => $perm['peut_valider'] ?? 0,
            ];
        }
        
        return true;
    } catch (Exception $e) {
        error_log("[CSK Services] Erreur chargement permissions: " . $e->getMessage());
        return false;
    }
}

// =============================================
// FONCTIONS DE FORMATAGE
// =============================================

function formatDate($date, $format = 'd/m/Y') {
    if (empty($date) || $date === '0000-00-00') return '-';
    return date($format, strtotime($date));
}

function formatDateTime($datetime, $format = 'd/m/Y H:i') {
    if (empty($datetime)) return '-';
    return date($format, strtotime($datetime));
}

function formatTime($datetime, $format = 'H:i') {
    if (empty($datetime)) return '-';
    try {
        $date = new DateTime($datetime);
        return $date->format($format);
    } catch (Exception $e) {
        return '-';
    }
}

function formatMoney($amount, $currency = 'FC') {
    return number_format($amount, 2, ',', ' ') . ' ' . $currency;
}

function calculateAge($date_naissance) {
    if (!$date_naissance || $date_naissance === '0000-00-00') {
        return 'Inconnu';
    }
    $naissance = new DateTime($date_naissance);
    $aujourdhui = new DateTime();
    return $aujourdhui->diff($naissance)->y;
}

// =============================================
// FONCTIONS DE SÉCURITÉ
// =============================================

function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function generateNumero($prefix, $lastNumber) {
    $newNumber = $lastNumber + 1;
    return $prefix . str_pad($newNumber, 6, '0', STR_PAD_LEFT);
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// =============================================
// FONCTIONS DE LOG
// =============================================

function logAction($action, $details = '', $user_id = null) {
    $user_id = $user_id ?? $_SESSION['user_id'] ?? 0;
    $date = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    $log_entry = "[$date] [USER:$user_id] [IP:$ip] $action";
    if ($details) {
        $log_entry .= " - $details";
    }
    $log_entry .= "\n";
    
    if (!file_exists(LOGS_PATH)) {
        mkdir(LOGS_PATH, 0777, true);
    }
    
    $log_file = LOGS_PATH . '/services_' . date('Y-m-d') . '.log';
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// =============================================
// FLASH MESSAGES (pour feedback utilisateur)
// =============================================

function setFlash($type, $message) {
    $_SESSION['flash_' . $type] = $message;
}

function getFlash($type) {
    $key = 'flash_' . $type;
    if (isset($_SESSION[$key])) {
        $message = $_SESSION[$key];
        unset($_SESSION[$key]);
        return $message;
    }
    return null;
}

function hasFlash($type) {
    return isset($_SESSION['flash_' . $type]);
}
