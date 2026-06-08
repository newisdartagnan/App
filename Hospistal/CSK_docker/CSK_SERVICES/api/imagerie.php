<?php
/**
 * API Imagerie - Endpoints AJAX
 * 
 * Toutes les reponses sont en JSON.
 * Necessite une session active + profil autorise pour le service imagerie.
 * 
 * Endpoints :
 *   GET  api/imagerie.php?action=stats            -> Stats temps reel imagerie
 *   GET  api/imagerie.php?action=search&q=xxx     -> Recherche rapide d'examens
 *   GET  api/imagerie.php?action=detail&code=xxx  -> Detail complet d'un examen
 *   POST api/imagerie.php?action=transition        -> Transition de statut (AJAX)
 */

// Headers JSON + CORS pour AJAX same-origin
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Charger la configuration
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/imagerie_helpers.php';

// =============================================
// VERIFICATION SESSION + ACCES IMAGERIE
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

// Verifier acces au service imagerie
if (!in_array('imagerie', $user_services) && $user_profil_code !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acces refuse au service imagerie.']);
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
    error_log("[CSK Services][API Imagerie] Erreur BDD: " . $e->getMessage());
    exit();
}

// =============================================
// ROUTING
// =============================================

$action = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';

switch ($action) {
    case 'stats':
        handleImagerieStats($conn_services);
        break;
    
    case 'search':
        handleImagerieSearch($conn_services, $conn_base);
        break;
    
    case 'detail':
        handleImagerieDetail($conn_services, $conn_base);
        break;
    
    case 'transition':
        handleImagerieTransition($conn_services, $user_id, $user_profil_code);
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

function handleImagerieStats(PDO $conn) {
    try {
        $today = date('Y-m-d');
        
        // Stats du jour
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_jour,
                SUM(CASE WHEN statut = 'programme' THEN 1 ELSE 0 END) as programmes,
                SUM(CASE WHEN statut IN ('accueil','en_preparation') THEN 1 ELSE 0 END) as accueil_prep,
                SUM(CASE WHEN statut IN ('en_acquisition','acquisition_terminee','en_reconstruction') THEN 1 ELSE 0 END) as en_acquisition,
                SUM(CASE WHEN statut IN ('en_interpretation','compte_rendu_fait') THEN 1 ELSE 0 END) as en_interpretation,
                SUM(CASE WHEN statut IN ('validation_radiologue','validation_chef') THEN 1 ELSE 0 END) as en_validation,
                SUM(CASE WHEN statut = 'transmis' THEN 1 ELSE 0 END) as transmis,
                SUM(CASE WHEN statut = 'annule' THEN 1 ELSE 0 END) as annules,
                SUM(CASE WHEN urgence = 1 AND statut NOT IN ('transmis','annule') THEN 1 ELSE 0 END) as urgences
            FROM imagerie_examens
            WHERE DATE(date_rdv) = ? OR DATE(created_at) = ?
        ");
        $stmt->execute([$today, $today]);
        $jour = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Stats globaux (actifs)
        $stmt = $conn->query("
            SELECT 
                COUNT(*) as total_actifs,
                SUM(CASE WHEN urgence = 1 THEN 1 ELSE 0 END) as urgences
            FROM imagerie_examens
            WHERE statut NOT IN ('transmis','annule')
        ");
        $global = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Repartition par statut
        $stmt = $conn->query("
            SELECT statut, COUNT(*) as nombre
            FROM imagerie_examens
            WHERE statut NOT IN ('annule')
            GROUP BY statut
            ORDER BY FIELD(statut,
                'programme','accueil','en_preparation','en_acquisition',
                'acquisition_terminee','en_reconstruction','en_interpretation',
                'compte_rendu_fait','validation_radiologue','validation_chef','transmis')
        ");
        $repartition = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Enrichir avec labels
        $labels = $GLOBALS['imagerie_statut_labels'];
        foreach ($repartition as &$r) {
            $r['statut_label'] = $labels[$r['statut']]['label'] ?? $r['statut'];
            $r['statut_color'] = $labels[$r['statut']]['color'] ?? '#6c757d';
        }
        
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
        error_log("[CSK Services][API Imagerie] Erreur stats: " . $e->getMessage());
    }
}

// =============================================
// HANDLER : RECHERCHE RAPIDE
// =============================================

function handleImagerieSearch(PDO $conn_services, PDO $conn_base) {
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
    
    if (strlen($q) < 2) {
        echo json_encode(['success' => true, 'data' => [], 'total' => 0]);
        return;
    }
    
    try {
        $search = "%$q%";
        
        // Recherche dans imagerie_examens
        $stmt = $conn_services->prepare("
            SELECT 
                e.code_examen,
                e.statut,
                e.urgence,
                e.priorite,
                e.type_examen,
                e.salle,
                e.date_rdv,
                e.idpatient
            FROM imagerie_examens e
            WHERE e.code_examen LIKE :q1 
               OR e.type_examen LIKE :q2
               OR e.salle LIKE :q3
            ORDER BY e.urgence DESC, e.date_rdv DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':q1', $search);
        $stmt->bindValue(':q2', $search);
        $stmt->bindValue(':q3', $search);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recuperer les noms patients
        $patient_ids = array_unique(array_column($results, 'idpatient'));
        $patients = [];
        if (!empty($patient_ids)) {
            $placeholders = implode(',', array_fill(0, count($patient_ids), '?'));
            $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom FROM patient WHERE idpatient IN ($placeholders)");
            $stmt_p->execute(array_values($patient_ids));
            foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $patients[$p['idpatient']] = $p;
            }
        }
        
        // Aussi chercher par nom patient si pas deja assez de resultats
        if (count($results) < $limit) {
            $stmt_p2 = $conn_base->prepare("
                SELECT idpatient FROM patient 
                WHERE nom LIKE :q1 OR prenom LIKE :q2 
                LIMIT :lim
            ");
            $stmt_p2->bindValue(':q1', $search);
            $stmt_p2->bindValue(':q2', $search);
            $stmt_p2->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt_p2->execute();
            $pat_ids = $stmt_p2->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($pat_ids)) {
                // Exclure les examens deja trouves
                $existing_codes = array_column($results, 'code_examen');
                $placeholders = implode(',', array_fill(0, count($pat_ids), '?'));
                $exclude = '';
                $params = $pat_ids;
                if (!empty($existing_codes)) {
                    $exclude_ph = implode(',', array_fill(0, count($existing_codes), '?'));
                    $exclude = "AND e.code_examen NOT IN ($exclude_ph)";
                    $params = array_merge($pat_ids, $existing_codes);
                }
                
                $remaining = $limit - count($results);
                $stmt_e2 = $conn_services->prepare("
                    SELECT e.code_examen, e.statut, e.urgence, e.priorite, e.type_examen, e.salle, e.date_rdv, e.idpatient
                    FROM imagerie_examens e
                    WHERE e.idpatient IN ($placeholders) $exclude
                    ORDER BY e.urgence DESC, e.date_rdv DESC
                    LIMIT $remaining
                ");
                $stmt_e2->execute($params);
                $extra = $stmt_e2->fetchAll(PDO::FETCH_ASSOC);
                $results = array_merge($results, $extra);
                
                // Mettre a jour les patients
                foreach ($extra as $ex) {
                    if (!isset($patients[$ex['idpatient']])) {
                        $stmt_pp = $conn_base->prepare("SELECT idpatient, nom, prenom FROM patient WHERE idpatient = ?");
                        $stmt_pp->execute([$ex['idpatient']]);
                        $pp = $stmt_pp->fetch(PDO::FETCH_ASSOC);
                        if ($pp) $patients[$pp['idpatient']] = $pp;
                    }
                }
            }
        }
        
        // Enrichir les resultats
        $labels = $GLOBALS['imagerie_statut_labels'];
        foreach ($results as &$r) {
            $r['statut_label'] = $labels[$r['statut']]['label'] ?? $r['statut'];
            $r['statut_color'] = $labels[$r['statut']]['color'] ?? '#6c757d';
            $pat = $patients[$r['idpatient']] ?? null;
            $r['patient'] = $pat ? trim($pat['nom'] . ' ' . $pat['prenom']) : 'Patient #' . $r['idpatient'];
            unset($r['idpatient']);
        }
        
        echo json_encode(['success' => true, 'data' => $results, 'total' => count($results)]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la recherche.']);
        error_log("[CSK Services][API Imagerie] Erreur search: " . $e->getMessage());
    }
}

// =============================================
// HANDLER : DETAIL D'UN EXAMEN
// =============================================

function handleImagerieDetail(PDO $conn_services, PDO $conn_base) {
    $code = isset($_GET['code']) ? trim($_GET['code']) : '';
    
    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Code examen requis.']);
        return;
    }
    
    try {
        // Examen
        $stmt = $conn_services->prepare("SELECT * FROM imagerie_examens WHERE code_examen = ?");
        $stmt->execute([$code]);
        $examen = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$examen) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Examen introuvable.']);
            return;
        }
        
        // Patient
        $stmt_p = $conn_base->prepare("SELECT idpatient, nom, prenom, sexe, datenais FROM patient WHERE idpatient = ?");
        $stmt_p->execute([$examen['idpatient']]);
        $patient = $stmt_p->fetch(PDO::FETCH_ASSOC);
        
        // Prescription
        $prescription = null;
        if ($examen['idactes_presc']) {
            $stmt_pr = $conn_base->prepare("SELECT * FROM actes_presc WHERE idactes_presc = ?");
            $stmt_pr->execute([$examen['idactes_presc']]);
            $prescription = $stmt_pr->fetch(PDO::FETCH_ASSOC);
        }
        
        // Resultat imagerie
        $resultat = null;
        if ($examen['idresultat_imagerie']) {
            $stmt_r = $conn_base->prepare("SELECT * FROM resultats_imagerie WHERE idresultat_imagerie = ?");
            $stmt_r->execute([$examen['idresultat_imagerie']]);
            $resultat = $stmt_r->fetch(PDO::FETCH_ASSOC);
        }
        
        // Personnel assigne (noms)
        $personnel = [];
        $user_ids = array_filter([
            $examen['secretaire_accueil'],
            $examen['manipulateur'],
            $examen['radiologue'],
            $examen['radiologue_validateur'],
        ]);
        if (!empty($user_ids)) {
            $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
            $stmt_u = $conn_base->prepare("SELECT idutilisateur, nom, prenom FROM utilisateur WHERE idutilisateur IN ($placeholders)");
            $stmt_u->execute(array_values($user_ids));
            foreach ($stmt_u->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $personnel[$u['idutilisateur']] = trim($u['prenom'] . ' ' . $u['nom']);
            }
        }
        
        // Historique
        $stmt_h = $conn_services->prepare("
            SELECT h.action, h.ancien_statut, h.nouveau_statut, h.observation, h.created_at, h.idutilisateur
            FROM imagerie_workflow_history h
            WHERE h.idexamen = ?
            ORDER BY h.created_at ASC
        ");
        $stmt_h->execute([$examen['idexamen']]);
        $historique = $stmt_h->fetchAll(PDO::FETCH_ASSOC);
        
        // Noms utilisateurs historique
        $hist_uids = array_unique(array_column($historique, 'idutilisateur'));
        $hist_users = [];
        if (!empty($hist_uids)) {
            $placeholders = implode(',', array_fill(0, count($hist_uids), '?'));
            $stmt_hu = $conn_base->prepare("SELECT idutilisateur, nom, prenom FROM utilisateur WHERE idutilisateur IN ($placeholders)");
            $stmt_hu->execute(array_values($hist_uids));
            foreach ($stmt_hu->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $hist_users[$u['idutilisateur']] = trim($u['prenom'] . ' ' . $u['nom']);
            }
        }
        foreach ($historique as &$h) {
            $h['utilisateur_nom'] = $hist_users[$h['idutilisateur']] ?? null;
            unset($h['idutilisateur']);
        }
        
        // Enrichir avec labels
        $labels = $GLOBALS['imagerie_statut_labels'];
        $examen['statut_label'] = $labels[$examen['statut']]['label'] ?? $examen['statut'];
        $examen['en_retard'] = isImagerieEnRetard($examen);
        
        echo json_encode([
            'success'      => true,
            'examen'       => $examen,
            'patient'      => $patient,
            'prescription' => $prescription,
            'resultat'     => $resultat,
            'personnel'    => $personnel,
            'historique'   => $historique,
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la recuperation du detail.']);
        error_log("[CSK Services][API Imagerie] Erreur detail: " . $e->getMessage());
    }
}

// =============================================
// HANDLER : TRANSITION DE STATUT (POST)
// =============================================

function handleImagerieTransition(PDO $conn, int $user_id, string $profil_code) {
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
    
    $code_examen = sanitizeInput($input['code_examen'] ?? '');
    $new_statut  = sanitizeInput($input['nouveau_statut'] ?? '');
    $observation = trim($input['observation'] ?? '');
    
    if (empty($code_examen) || empty($new_statut)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'code_examen et nouveau_statut sont requis.']);
        return;
    }
    
    $transitions = $GLOBALS['imagerie_transitions'];
    
    try {
        // Verifier l'examen
        $stmt = $conn->prepare("SELECT idexamen, statut FROM imagerie_examens WHERE code_examen = ?");
        $stmt->execute([$code_examen]);
        $examen = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$examen) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Examen introuvable.']);
            return;
        }
        
        $statut_actuel = $examen['statut'];
        
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
        
        $action_label = $transitions[$statut_actuel][$new_statut][0];
        
        // Transaction atomique
        $conn->beginTransaction();
        
        // Mettre a jour le statut
        $stmt_up = $conn->prepare("UPDATE imagerie_examens SET statut = ? WHERE idexamen = ?");
        $stmt_up->execute([$new_statut, $examen['idexamen']]);
        
        // Logger dans l'historique
        $stmt_log = $conn->prepare("
            INSERT INTO imagerie_workflow_history 
            (idexamen, ancien_statut, nouveau_statut, action, idutilisateur, observation, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_log->execute([
            $examen['idexamen'],
            $statut_actuel,
            $new_statut,
            $action_label,
            $user_id,
            $observation ?: null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
        
        // Mettre a jour les champs supplementaires selon la transition
        $updates = [];
        $update_params = [':id' => $examen['idexamen']];
        
        switch ($new_statut) {
            case 'accueil':
                $updates[] = "secretaire_accueil = :user_id";
                $update_params[':user_id'] = $user_id;
                break;
            case 'en_preparation':
            case 'en_acquisition':
                $updates[] = "manipulateur = :user_id";
                $update_params[':user_id'] = $user_id;
                break;
            case 'en_interpretation':
                $updates[] = "radiologue = :user_id";
                $update_params[':user_id'] = $user_id;
                break;
            case 'validation_radiologue':
            case 'validation_chef':
                $updates[] = "radiologue_validateur = :user_id";
                $update_params[':user_id'] = $user_id;
                break;
        }
        
        if (!empty($updates)) {
            $update_sql = "UPDATE imagerie_examens SET " . implode(', ', $updates) . " WHERE idexamen = :id";
            $stmt_upd = $conn->prepare($update_sql);
            $stmt_upd->execute($update_params);
        }
        
        $conn->commit();
        
        logAction('WORKFLOW_IMAGERIE_API', "Transition $statut_actuel -> $new_statut pour $code_examen");
        
        echo json_encode([
            'success' => true,
            'message' => "Transition effectuee: $action_label",
            'code'    => $code_examen,
            'ancien'  => $statut_actuel,
            'nouveau' => $new_statut,
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erreur lors de la transition.']);
        error_log("[CSK Services][API Imagerie] Erreur transition: " . $e->getMessage());
    }
}