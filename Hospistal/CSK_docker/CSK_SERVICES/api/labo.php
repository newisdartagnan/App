<?php
/**
 * API Laboratoire - Endpoints AJAX
 * 
 * Toutes les reponses sont en JSON.
 * Necessite une session active + profil autorise pour le service labo.
 * 
 * Endpoints :
 *   GET  api/labo.php?action=stats           -> Stats temps reel du labo
 *   GET  api/labo.php?action=search&q=xxx    -> Recherche rapide d'echantillons
 *   GET  api/labo.php?action=detail&code=xxx -> Detail complet d'un echantillon
 *   POST api/labo.php?action=transition       -> Transition de statut (AJAX)
 */

// Headers JSON + CORS pour AJAX same-origin
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Charger la configuration
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/labo_helpers.php';

// =============================================
// VERIFICATION SESSION + ACCES LABO
// =============================================

session_start();

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non authentifie. Veuillez vous reconnecter.']);
    exit();
}

// Verifier timeout de session
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Session expiree.']);
    exit();
}
$_SESSION['last_activity'] = time();

$user_id          = $_SESSION['user_id'];
$user_profil_code = $_SESSION['user_profil_code'] ?? '';
$user_services    = $_SESSION['services_autorises'] ?? [];

// Verifier acces au service labo
if (!in_array('labo', $user_services) && $user_profil_code !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acces refuse au service laboratoire.']);
    exit();
}

// =============================================
// CONNEXION BDD
// =============================================

try {
    $db = new Database();
    $conn_services = $db->getServicesConnection();
    $conn_base     = $db->getBaseConnection();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur de connexion a la base de donnees.']);
    error_log("[CSK Services][API Labo] Erreur BDD: " . $e->getMessage());
    exit();
}

// =============================================
// ROUTING
// =============================================

$action = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';

switch ($action) {
    case 'stats':
        handleStats($conn_services);
        break;
    
    case 'search':
        handleSearch($conn_services);
        break;
    
    case 'detail':
        handleDetail($conn_services, $conn_base);
        break;
    
    case 'transition':
        handleTransition($conn_services, $user_id, $user_profil_code);
        break;
    
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Action non reconnue: ' . $action]);
        break;
}

exit();

// =============================================
// HANDLER : STATS TEMPS REEL
// =============================================

function handleStats(PDO $conn) {
    try {
        // Stats du jour
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total_jour,
                SUM(CASE WHEN statut IN ('attente_prelevement') THEN 1 ELSE 0 END) as en_attente,
                SUM(CASE WHEN statut IN ('preleve','transit','receptionne','controle_qualite','en_analyse') THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN statut IN ('resultat_transmis') THEN 1 ELSE 0 END) as termines,
                SUM(CASE WHEN statut IN ('rejete','perdu') THEN 1 ELSE 0 END) as rejetes,
                SUM(CASE WHEN urgence = 1 AND statut NOT IN ('resultat_transmis','annule','rejete','perdu') THEN 1 ELSE 0 END) as urgents,
                SUM(CASE WHEN TIMESTAMPDIFF(MINUTE, created_at, NOW()) > delai_theorique_min 
                    AND statut NOT IN ('resultat_transmis','annule','rejete','perdu') THEN 1 ELSE 0 END) as en_retard
            FROM labo_echantillons
            WHERE deleted_at IS NULL AND DATE(created_at) = CURDATE()
        ");
        $jour = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Stats globaux (actifs)
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total_actifs,
                SUM(CASE WHEN statut IN ('attente_prelevement') THEN 1 ELSE 0 END) as en_attente,
                SUM(CASE WHEN statut NOT IN ('attente_prelevement','resultat_transmis','annule','rejete','perdu') THEN 1 ELSE 0 END) as en_cours,
                SUM(CASE WHEN urgence = 1 AND statut NOT IN ('resultat_transmis','annule','rejete','perdu') THEN 1 ELSE 0 END) as urgents
            FROM labo_echantillons
            WHERE deleted_at IS NULL
            AND statut NOT IN ('resultat_transmis','annule','rejete','perdu')
        ");
        $global = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Repartition par statut
        $stmt = $conn->query("
            SELECT statut, COUNT(*) as nombre
            FROM labo_echantillons
            WHERE deleted_at IS NULL AND statut NOT IN ('annule')
            GROUP BY statut
            ORDER BY FIELD(statut,
                'attente_prelevement','preleve','transit','receptionne',
                'controle_qualite','en_analyse','analyse_terminee',
                'validation_technique','validation_biologiste','resultat_transmis',
                'rejete','perdu')
        ");
        $repartition = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => [
                'jour'        => $jour,
                'global'      => $global,
                'repartition' => $repartition,
                'timestamp'   => date('Y-m-d H:i:s'),
            ]
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la recuperation des stats.']);
        error_log("[CSK Services][API Labo] Erreur stats: " . $e->getMessage());
    }
}

// =============================================
// HANDLER : RECHERCHE RAPIDE
// =============================================

function handleSearch(PDO $conn) {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'data' => [], 'total' => 0]);
        return;
    }
    
    try {
        $search = "%$q%";
        $stmt = $conn->prepare("
            SELECT 
                le.code_echantillon,
                le.statut,
                le.urgence,
                le.couleur_tube,
                le.created_at,
                p.Noms as patient_nom,
                p.Prenom as patient_prenom,
                a.Nom as examen_libelle
            FROM labo_echantillons le
            LEFT JOIN {$DB}.Patient p ON le.idpatient = p.IDPatient
            LEFT JOIN {$DB}.Acte_presc ap ON le.idactes_presc = ap.IDActe_presc
            LEFT JOIN {$DB}.Acte a ON ap.IDActe = a.IDActe
            WHERE le.deleted_at IS NULL
            AND (le.code_echantillon LIKE :q1 OR p.Noms LIKE :q2 OR p.Prenom LIKE :q3 OR a.Nom LIKE :q4)
            ORDER BY le.urgence DESC, le.created_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':q1', $search);
        $stmt->bindValue(':q2', $search);
        $stmt->bindValue(':q3', $search);
        $stmt->bindValue(':q4', $search);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Enrichir avec les labels de statut
        $labels = $GLOBALS['labo_statut_labels'];
        foreach ($results as &$r) {
            $r['statut_label'] = $labels[$r['statut']]['label'] ?? $r['statut'];
            $r['statut_color'] = $labels[$r['statut']]['color'] ?? '#6c757d';
            $r['patient'] = trim(($r['patient_nom'] ?? '') . ' ' . ($r['patient_prenom'] ?? ''));
            unset($r['patient_nom'], $r['patient_prenom']);
        }
        
        echo json_encode(['success' => true, 'data' => $results, 'total' => count($results)]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la recherche.']);
        error_log("[CSK Services][API Labo] Erreur search: " . $e->getMessage());
    }
}

// =============================================
// HANDLER : DETAIL D'UN ECHANTILLON
// =============================================

function handleDetail(PDO $conn_services, PDO $conn_base) {
    $code = isset($_GET['code']) ? trim($_GET['code']) : '';
    
    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Code echantillon requis.']);
        return;
    }
    
    try {
        $stmt = $conn_services->prepare("
            SELECT 
                le.*,
                TIMESTAMPDIFF(MINUTE, le.created_at, NOW()) as delai_actuel_min,
                p.Noms as patient_nom, p.Prenom as patient_prenom,
                p.SEXE as patient_sexe, p.Date_de_naissance as patient_dob,
                a.Nom as examen_libelle, a.IDActe as examen_code,
                ap.date_prescription,
                u_pre.nom as preleveur_nom, u_pre.prenom as preleveur_prenom,
                u_rec.nom as receveur_nom, u_rec.prenom as receveur_prenom,
                u_tech.nom as technicien_nom, u_tech.prenom as technicien_prenom,
                u_bio.nom as biologiste_nom, u_bio.prenom as biologiste_prenom
            FROM labo_echantillons le
            LEFT JOIN {$DB}.Patient p ON le.idpatient = p.IDPatient
            LEFT JOIN {$DB}.Acte_presc ap ON le.idactes_presc = ap.IDActe_presc
            LEFT JOIN {$DB}.Acte a ON ap.IDActe = a.IDActe
            LEFT JOIN {$DB}.Utilisateur u_pre ON le.preleveur = u_pre.IDUtilisateur
            LEFT JOIN {$DB}.Utilisateur u_rec ON le.receveur_labo = u_rec.IDUtilisateur
            LEFT JOIN {$DB}.Utilisateur u_tech ON le.technicien_analyse = u_tech.IDUtilisateur
            LEFT JOIN {$DB}.Utilisateur u_bio ON le.biologiste_validateur = u_bio.IDUtilisateur
            WHERE le.code_echantillon = :code AND le.deleted_at IS NULL
        ");
        $stmt->execute([':code' => $code]);
        $ech = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ech) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Echantillon introuvable.']);
            return;
        }
        
        // Resultat si disponible
        $resultat = null;
        if ($ech['idresultat']) {
            $stmt = $conn_base->prepare("
                SELECT r.*, m.Nom as machine_nom,
                    u.Noms as analyste_nom
                FROM resultat_labo r
                LEFT JOIN machineslabo m ON r.IDMachineLabo = m.IDMachineLabo
                LEFT JOIN {$DB}.Utilisateur u ON r.Analyse_par = u.IDUtilisateur
                WHERE r.idresultat = :id
            ");
            $stmt->execute([':id' => $ech['idresultat']]);
            $resultat = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Historique
        $stmt = $conn_services->prepare("
            SELECT lwh.action, lwh.ancien_statut, lwh.nouveau_statut, lwh.observation, lwh.created_at,
                   u.Noms as user_nom
            FROM labo_workflow_history lwh
            LEFT JOIN {$DB}.Utilisateur u ON lwh.idutilisateur = u.IDUtilisateur
            WHERE lwh.idechantillon = :id
            ORDER BY lwh.created_at ASC
        ");
        $stmt->execute([':id' => $ech['idechantillon']]);
        $historique = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Labels de statut
        $labels = $GLOBALS['labo_statut_labels'];
        $ech['statut_label'] = $labels[$ech['statut']]['label'] ?? $ech['statut'];
        $ech['en_retard'] = isLaboEnRetard(
            (int)$ech['delai_actuel_min'], 
            (int)$ech['delai_theorique_min'], 
            $ech['statut']
        );
        
        echo json_encode([
            'success'    => true,
            'echantillon' => $ech,
            'resultat'   => $resultat,
            'historique'  => $historique,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la recuperation du detail.']);
        error_log("[CSK Services][API Labo] Erreur detail: " . $e->getMessage());
    }
}

// =============================================
// HANDLER : TRANSITION DE STATUT (POST)
// =============================================

function handleTransition(PDO $conn, int $user_id, string $profil_code) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Methode POST requise.']);
        return;
    }
    
    // Lire le body JSON ou form-data
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $code_ech   = sanitizeInput($input['code_echantillon'] ?? '');
    $new_statut = sanitizeInput($input['nouveau_statut'] ?? '');
    $observation = trim($input['observation'] ?? '');
    $action_label = sanitizeInput($input['action_label'] ?? 'Changement de statut');
    
    if (empty($code_ech) || empty($new_statut)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'code_echantillon et nouveau_statut sont requis.']);
        return;
    }
    
    $transitions = $GLOBALS['labo_transitions'];
    
    try {
        // Verifier l'echantillon
        $stmt = $conn->prepare("SELECT idechantillon, statut FROM labo_echantillons WHERE code_echantillon = :code AND deleted_at IS NULL");
        $stmt->execute([':code' => $code_ech]);
        $ech = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ech) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Echantillon introuvable.']);
            return;
        }
        
        $statut_actuel = $ech['statut'];
        
        // Verifier transition autorisee
        if (!isset($transitions[$statut_actuel][$new_statut])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => "Transition non autorisee: $statut_actuel -> $new_statut"]);
            return;
        }
        
        // Verifier profil
        $profils_ok = $transitions[$statut_actuel][$new_statut][3];
        if (!in_array($profil_code, $profils_ok)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => "Votre profil n'est pas autorise pour cette action."]);
            return;
        }
        
        // Executer la transition via la procedure stockee
        $stmt = $conn->prepare("CALL changer_statut_echantillon(:code, :statut, :user, :obs, :action)");
        $stmt->execute([
            ':code'   => $code_ech,
            ':statut' => $new_statut,
            ':user'   => $user_id,
            ':obs'    => $observation ?: null,
            ':action' => $action_label,
        ]);
        
        // Mettre a jour les champs supplementaires
        $updates = [];
        $update_params = [':code' => $code_ech];
        
        switch ($new_statut) {
            case 'receptionne':
                $updates[] = "date_reception = NOW()";
                $updates[] = "receveur_labo = :receveur";
                $update_params[':receveur'] = $user_id;
                break;
            case 'en_analyse':
                $updates[] = "date_debut_analyse = NOW()";
                $updates[] = "technicien_analyse = :tech";
                $update_params[':tech'] = $user_id;
                break;
            case 'analyse_terminee':
                $updates[] = "date_fin_analyse = NOW()";
                break;
            case 'validation_biologiste':
                $updates[] = "biologiste_validateur = :bio";
                $updates[] = "date_validation = NOW()";
                $update_params[':bio'] = $user_id;
                break;
        }
        
        if (!empty($updates)) {
            $update_sql = "UPDATE labo_echantillons SET " . implode(', ', $updates) 
                        . " WHERE code_echantillon = :code";
            $stmt_up = $conn->prepare($update_sql);
            $stmt_up->execute($update_params);
        }
        
        logAction('WORKFLOW_LABO_API', "Transition $statut_actuel -> $new_statut pour $code_ech");
        
        echo json_encode([
            'success'    => true,
            'message'    => "Transition effectuee: $statut_actuel -> $new_statut",
            'code'       => $code_ech,
            'ancien'     => $statut_actuel,
            'nouveau'    => $new_statut,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la transition.']);
        error_log("[CSK Services][API Labo] Erreur transition: " . $e->getMessage());
    }
}
