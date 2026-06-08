<?php
/**
 * API Prescriptions - Endpoints AJAX pour la creation de prescriptions.
 * 
 * Endpoints :
 *   ?action=search_patients&q=...   : Recherche patients (csk_base)
 *   ?action=get_sejours&idpatient=  : Sejours actifs d'un patient
 *   ?action=search_produits&q=...   : Recherche produits pharma
 *   ?action=search_actes&type=...   : Liste des actes par categorie
 */

session_start();

// Verification de session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorise']);
    exit();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/prescription_helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$db = new Database();
$conn_base = $db->getBaseConnection();

$action = isset($_GET['action']) ? sanitizeInput($_GET['action']) : '';

try {
    switch ($action) {
        
        // =============================================
        // RECHERCHE PATIENTS
        // =============================================
        case 'search_patients':
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode([]); // Retourne un tableau vide, pas {patients: []}
                exit();
            }
            
            $patients = searchPatients($conn_base, $q);
            echo json_encode($patients); // Retourne directement le tableau de patients
            break;
        
        // =============================================
        // SEJOURS ACTIFS D'UN PATIENT
        // =============================================
        case 'get_sejours':
            $idpatient = (int)($_GET['idpatient'] ?? 0);
            if ($idpatient <= 0) {
                echo json_encode(['sejours' => []]);
                exit();
            }
            
            $sejours = getPatientSejours($conn_base, $idpatient);
            echo json_encode(['sejours' => $sejours]);
            break;
        
        // =============================================
        // RECHERCHE PRODUITS PHARMA
        // =============================================
        case 'search_produits':
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode([]); // Retourne un tableau vide
                exit();
            }
            
            $produits = searchProdPharma($conn_base, $q);
            echo json_encode($produits); // Retourne directement le tableau de produits
            break;
        
        // =============================================
        // ACTES PAR CATEGORIE
        // =============================================
        case 'search_actes':
            $type = sanitizeInput($_GET['type'] ?? 'labo');
            $actes = getActesByCategorie($conn_base, $type);
            echo json_encode($actes); // Retourne directement le tableau d'actes
            break;
        
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Action inconnue: ' . $action]);
            break;
    }
    
} catch (Exception $e) {
    error_log("[CSK Services][API Prescriptions] Erreur: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur.']);
}