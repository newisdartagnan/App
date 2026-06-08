<?php
/**
 * API : Vérifier si un séjour est complet (tous résultats prêts)
 * 
 * Usage : check_sejour_complet.php?id=123
 *         (id peut être idsejour ou idsous_sejour)
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit();
}

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    echo json_encode(['error' => 'ID manquant']);
    exit();
}

try {
    $db = new Database();
    $conn_base = $db->getBaseConnection();
    
    // ── 1. Déterminer si c'est un sejour ou sous_sejour ────────────────────────
    $stmt_type = $conn_base->prepare("
        SELECT 
            'sejour' as type, idsejour as id
        FROM sejour 
        WHERE idsejour = :id
        
        UNION
        
        SELECT 
            'sous_sejour' as type, idsejour as id
        FROM sous_sejour 
        WHERE idsous_sejour = :id
    ");
    $stmt_type->execute([':id' => $id]);
    $type_info = $stmt_type->fetch();
    
    if (!$type_info) {
        echo json_encode(['error' => 'Séjour introuvable']);
        exit();
    }
    
    $idsejour = $type_info['id'];
    
    // ── 2. Charger les stats du séjour ─────────────────────────────────────────
    $stmt = $conn_base->prepare("
        SELECT
            s.idsejour,
            s.numero_sejour,
            s.statut as sejour_statut,
            s.pdf_resultats_genere,
            CONCAT(p.prenom, ' ', p.nom) AS patient_nom,
            
            -- Compter les prescriptions et résultats labo
            COUNT(DISTINCT ap_labo.idactes_presc) AS nb_prescriptions_labo,
            COUNT(DISTINCT r.idresultat) AS nb_resultats_labo,
            
            -- Compter les prescriptions et résultats imagerie
            COUNT(DISTINCT ap_img.idactes_presc) AS nb_prescriptions_imagerie,
            COUNT(DISTINCT ri.idresultat_imagerie) AS nb_resultats_imagerie,
            
            -- Compter les médicaments
            COUNT(DISTINCT pp.idpharma_presc) AS nb_medicaments
            
        FROM sejour s
        JOIN patient p ON s.idpatient = p.idpatient
        LEFT JOIN sous_sejour ss ON s.idsejour = ss.idsejour
        
        -- Actes labo
        LEFT JOIN actes_presc ap_labo ON ss.idsous_sejour = ap_labo.idsous_sejour
        LEFT JOIN acte a_labo ON ap_labo.idacte = a_labo.idacte AND a_labo.idcategorie_acte = 6
        LEFT JOIN resultatslabo r ON ap_labo.idactes_presc = r.idactes_presc
        
        -- Actes imagerie
        LEFT JOIN actes_presc ap_img ON ss.idsous_sejour = ap_img.idsous_sejour
        LEFT JOIN acte a_img ON ap_img.idacte = a_img.idacte AND a_img.idcategorie_acte IN (2, 5, 10, 22)
        LEFT JOIN resultats_imagerie ri ON ap_img.idactes_presc = ri.idactes_presc
        
        -- Médicaments
        LEFT JOIN pharma_presc pp ON ss.idsous_sejour = pp.idsous_sejour
        
        WHERE s.idsejour = :idsejour
        GROUP BY s.idsejour
    ");
    
    $stmt->execute([':idsejour' => $idsejour]);
    $stats = $stmt->fetch();
    
    if (!$stats) {
        echo json_encode(['error' => 'Données introuvables']);
        exit();
    }
    
    // ── 3. Déterminer si le séjour est "complet" ───────────────────────────────
    // Complet = toutes les prescriptions labo ont un résultat
    //           ET toutes les prescriptions imagerie ont un résultat
    $labo_complet = ($stats['nb_prescriptions_labo'] > 0 && 
                     $stats['nb_prescriptions_labo'] == $stats['nb_resultats_labo']);
    
    $imagerie_complet = ($stats['nb_prescriptions_imagerie'] == 0 || 
                         $stats['nb_prescriptions_imagerie'] == $stats['nb_resultats_imagerie']);
    
    $sejour_complet = $labo_complet && $imagerie_complet && 
                      ($stats['nb_resultats_labo'] > 0 || $stats['nb_resultats_imagerie'] > 0);
    
    // ── 4. Retourner le résultat ───────────────────────────────────────────────
    echo json_encode([
        'idsejour' => $stats['idsejour'],
        'numero_sejour' => $stats['numero_sejour'],
        'patient_nom' => $stats['patient_nom'],
        'complet' => $sejour_complet,
        'pdf_genere' => (bool)$stats['pdf_resultats_genere'],
        'nb_prescriptions_labo' => (int)$stats['nb_prescriptions_labo'],
        'nb_resultats_labo' => (int)$stats['nb_resultats_labo'],
        'nb_prescriptions_imagerie' => (int)$stats['nb_prescriptions_imagerie'],
        'nb_resultats_imagerie' => (int)$stats['nb_resultats_imagerie'],
        'nb_medicaments' => (int)$stats['nb_medicaments'],
        'manquants_labo' => max(0, (int)$stats['nb_prescriptions_labo'] - (int)$stats['nb_resultats_labo']),
        'manquants_imagerie' => max(0, (int)$stats['nb_prescriptions_imagerie'] - (int)$stats['nb_resultats_imagerie']),
    ]);
    
} catch (Exception $e) {
    error_log("[CSK][API] Erreur check_sejour_complet: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Erreur serveur']);
}