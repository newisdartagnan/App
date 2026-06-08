<?php
/**
 * CSK Services - Gestion de l'authentification
 * 
 * Authentifie les utilisateurs via csk_base.utilisateur
 * et vÃ©rifie qu'ils font partie des profils autorisÃ©s.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/**
 * Tenter de connecter un utilisateur
 * 
 * @param string $login     Nom d'utilisateur (csk_base.utilisateur.login)
 * @param string $password  Mot de passe en clair
 * @return array ['success' => bool, 'message' => string, 'user' => array|null]
 */
function attemptLogin($login, $password) {
    try {
        $db = new Database();
        $conn = $db->getBaseConnection();
        
        // 1. Rechercher l'utilisateur dans csk_base
        $query = "SELECT 
                    u.idutilisateur,
                    u.nom,
                    u.prenom,
                    u.username,
                    u.password,
                    u.email,
                    u.telephone,
                    u.statut,
                    u.idprofiluser,
                    u.idsite,
                    p.nom AS profil_nom,
                    p.code AS profil_code,
                    p.categorie AS profil_categorie,
                    p.couleur AS profil_couleur,
                    s.nom AS site_nom
                FROM utilisateur u
                JOIN profiluser p ON u.idprofiluser = p.idprofiluser
                LEFT JOIN site s ON u.idsite = s.idsite
                WHERE u.username = :login
                LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->execute([':login' => $login]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 2. Utilisateur introuvable
        if (!$user) {
            logAction('LOGIN_FAILED', "Login inconnu: $login");
            return [
                'success' => false,
                'message' => 'Identifiants incorrects.'
            ];
        }
        
        // 3. VÃ©rifier le mot de passe
        // Support des mots de passe hashÃ©s (password_hash) et MD5 (legacy)
        $password_valid = false;
        
        if (password_verify($password, $user['password'])) {
            $password_valid = true;
        } elseif (md5($password) === $user['password']) {
            // Legacy MD5 - accepter mais mettre Ã  jour vers bcrypt
            $password_valid = true;
            $update_pwd = $conn->prepare("UPDATE utilisateur SET password = :pwd WHERE idutilisateur = :id");
            $update_pwd->execute([
                ':pwd' => password_hash($password, PASSWORD_DEFAULT),
                ':id'  => $user['idutilisateur']
            ]);
        }
        
        if (!$password_valid) {
            logAction('LOGIN_FAILED', "Mot de passe incorrect pour: $login");
            return [
                'success' => false,
                'message' => 'Identifiants incorrects.'
            ];
        }
        
        // 4. VÃ©rifier que le compte est actif
        if ($user['statut'] !== 'actif') {
            logAction('LOGIN_FAILED', "Compte inactif: $login");
            return [
                'success' => false,
                'message' => 'Votre compte est dÃ©sactivÃ©. Contactez l\'administrateur.'
            ];
        }
        
        // 5. VÃ©rifier que le profil est autorisÃ© pour CSK Services
        $idprofil = (int) $user['idprofiluser'];
        
        if (!in_array($idprofil, PROFILS_IDS_AUTORISES)) {
            logAction('LOGIN_DENIED', "Profil non autorisÃ© (id=$idprofil) pour: $login");
            return [
                'success' => false,
                'message' => 'Votre profil n\'a pas accÃ¨s Ã  cette application. '
                           . 'Seuls les techniciens de laboratoire, d\'imagerie, '
                           . 'les pharmaciens et les administrateurs y ont accÃ¨s.'
            ];
        }
        
        // 6. Tout est OK : crÃ©er la session
        $profil_config = PROFILS_AUTORISES[$idprofil];
        
        $_SESSION['user_id']            = $user['idutilisateur'];
        $_SESSION['user_nom']           = $user['nom'];
        $_SESSION['user_prenom']        = $user['prenom'];
        $_SESSION['user_login']         = $user['username'];
        $_SESSION['user_email']         = $user['email'];
        $_SESSION['user_telephone']     = $user['telephone'];
        $_SESSION['profil']             = $user['profil_nom'];
        $_SESSION['profil_code']        = $profil_config['code'];
        $_SESSION['profil_id']          = $idprofil;
        $_SESSION['profil_couleur']     = $user['profil_couleur'];
        $_SESSION['profil_categorie']   = $user['profil_categorie'];
        $_SESSION['site_id']            = $user['idsite'];
        $_SESSION['site_nom']           = $user['site_nom'];
        $_SESSION['services_autorises'] = $profil_config['services'];
        $_SESSION['login_time']         = time();
        $_SESSION['last_activity']      = time();
        
        // DÃ©terminer le service par dÃ©faut (premier de la liste)
        $_SESSION['service_actif'] = $profil_config['services'][0];
        
        // Charger les permissions depuis csk_base
        loadUserPermissions($user['idutilisateur'], $conn);
        
        // DÃ©terminer le groupe notification
        $_SESSION['groupe_notification'] = PROFIL_GROUPE_NOTIFICATION[$profil_config['code']] ?? null;
        
        // Logger la connexion rÃ©ussie
        logAction('LOGIN_SUCCESS', "Connexion rÃ©ussie: {$user['nom']} {$user['prenom']} ({$profil_config['code']})");
        
        // Mettre Ã  jour la derniÃ¨re connexion dans csk_base
        try {
            $update = $conn->prepare("UPDATE utilisateur SET derniere_connexion = NOW() WHERE idutilisateur = :id");
            $update->execute([':id' => $user['idutilisateur']]);
        } catch (Exception $e) {
            // Ne pas bloquer le login si cette mise Ã  jour Ã©choue
            error_log("[CSK Services] Impossible de mettre Ã  jour derniere_connexion: " . $e->getMessage());
        }
        
        return [
            'success' => true,
            'message' => 'Connexion rÃ©ussie.',
            'user'    => [
                'id'       => $user['idutilisateur'],
                'nom'      => $user['nom'],
                'prenom'   => $user['prenom'],
                'profil'   => $profil_config['nom'],
                'services' => $profil_config['services'],
            ]
        ];
        
    } catch (Exception $e) {
        error_log("[CSK Services] Erreur login: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Erreur interne. Veuillez rÃ©essayer.'
        ];
    }
}

/**
 * DÃ©connecter l'utilisateur
 */
function logout() {
    $user_login = $_SESSION['user_login'] ?? 'inconnu';
    logAction('LOGOUT', "DÃ©connexion: $user_login");
    
    // DÃ©truire la session
    $_SESSION = [];
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    session_destroy();
}

/**
 * VÃ©rifier le timeout de session (inactivitÃ©)
 * Timeout par dÃ©faut : 30 minutes
 */
function checkSessionTimeout($timeout_minutes = 30) {
    if (!isLoggedIn()) return false;
    
    $timeout = $timeout_minutes * 60;
    
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        logout();
        return true; // Session expirÃ©e
    }
    
    // RafraÃ®chir le timestamp d'activitÃ©
    $_SESSION['last_activity'] = time();
    return false;
}

/**
 * Obtenir les infos de l'utilisateur connectÃ© (rÃ©sumÃ©)
 */
function getCurrentUser() {
    if (!isLoggedIn()) return null;
    
    return [
        'id'                 => $_SESSION['user_id'],
        'nom'                => $_SESSION['user_nom'],
        'prenom'             => $_SESSION['user_prenom'],
        'login'              => $_SESSION['user_login'],
        'profil'             => $_SESSION['profil'],
        'profil_code'        => $_SESSION['profil_code'],
        'profil_couleur'     => $_SESSION['profil_couleur'] ?? '#6c757d',
        'site_nom'           => $_SESSION['site_nom'] ?? '',
        'services_autorises' => $_SESSION['services_autorises'] ?? [],
        'service_actif'      => $_SESSION['service_actif'] ?? '',
        'groupe_notification'=> $_SESSION['groupe_notification'] ?? '',
    ];
}

/**
 * Changer le service actif en cours de session
 * (utile pour l'admin qui navigue entre labo/imagerie/pharmacie)
 */
function switchService($service) {
    $services_valides = ['labo', 'imagerie', 'pharmacie'];
    
    if (!in_array($service, $services_valides)) {
        return false;
    }
    
    if (!hasServiceAccess($service)) {
        return false;
    }
    
    $_SESSION['service_actif'] = $service;
    logAction('SWITCH_SERVICE', "Service actif changÃ© vers: $service");
    return true;
}