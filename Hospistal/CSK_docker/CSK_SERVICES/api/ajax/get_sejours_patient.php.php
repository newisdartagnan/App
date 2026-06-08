<?php
/**
 * AJAX : Récupérer les séjours d'un patient
 * Usage : ajax/get_sejours_patient.php?idpatient=123
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

$idpatient = (int)($_GET['idpatient'] ?? 0);

if ($idpatient <= 0) {
    echo json_encode(['error' => 'ID patient invalide']);
    exit();
}

try {
    $db = new Database();
    $conn_base = $db->getBaseConnection();
    
    $stmt = $conn_base->prepare("
        SELECT 
            ss.idsous_sejour,
            s.numero_sejour,
            s.date_entree,
            s.date_sortie,
            CASE 
                WHEN s.type_sejour = 'hospitalisation' THEN 'Hospitalisation'
                WHEN s.type_sejour = 'consultation' THEN 'Consultation'
                WHEN s.type_sejour = 'urgence' THEN 'Urgence'
                ELSE s.type_sejour
            END AS type_sejour,
            ss.type_sous_sejour
        FROM sejour s
        JOIN sous_sejour ss ON s.idsejour = ss.idsejour
        WHERE s.idpatient = :idpatient
          AND s.actif = 1
        ORDER BY s.date_entree DESC, ss.created_at DESC
        LIMIT 50
    ");
    
    $stmt->execute([':idpatient' => $idpatient]);
    $sejours = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($sejours);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur : ' . $e->getMessage()]);
}