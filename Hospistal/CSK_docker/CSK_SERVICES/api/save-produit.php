<?php
/**
 * API - Sauvegarde d'un produit pharmaceutique
 */

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorisé']);
    exit();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/pharmacie_helpers.php';

$db = new Database();
$conn_base = $db->getBaseConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit();
}

try {
    $idprodpharma = isset($_POST['idprodpharma']) && !empty($_POST['idprodpharma']) ? (int)$_POST['idprodpharma'] : null;
    
    $data = [
        'libelle' => trim($_POST['libelle'] ?? ''),
        'code' => trim($_POST['code'] ?? ''),
        'type_produit' => $_POST['type_produit'] ?? 'medicament',
        'idfamiprod' => !empty($_POST['idfamiprod']) ? (int)$_POST['idfamiprod'] : null,
        'idvoie_prod' => !empty($_POST['idvoie_prod']) ? (int)$_POST['idvoie_prod'] : null,
        'idunite' => !empty($_POST['idunite']) ? (int)$_POST['idunite'] : null,
        'dosage' => !empty($_POST['dosage']) ? trim($_POST['dosage']) : null,
        'prix_achat' => (float)($_POST['prix_achat'] ?? 0),
        'prix_vente_externe' => (float)($_POST['prix_vente_externe'] ?? 0),
        'seuil_alerte' => (int)($_POST['seuil_alerte'] ?? 10),
        'seuil_reappro' => (int)($_POST['seuil_reappro'] ?? 50),
        'description' => !empty($_POST['description']) ? trim($_POST['description']) : null,
        'actif' => isset($_POST['actif']) ? 1 : 0
    ];
    
    if (empty($data['libelle']) || empty($data['code'])) {
        throw new Exception("Le libellé et le code sont obligatoires.");
    }
    
    $conn_base->beginTransaction();
    
    if ($idprodpharma) {
        $query = "UPDATE prodpharma SET 
                  libelle = :libelle, code = :code, type_produit = :type_produit,
                  idfamiprod = :idfamiprod, idvoie_prod = :idvoie_prod,
                  idunite = :idunite, dosage = :dosage, prix_achat = :prix_achat,
                  prix_vente_externe = :prix_vente_externe, seuil_alerte = :seuil_alerte,
                  seuil_reappro = :seuil_reappro, description = :description, actif = :actif
                  WHERE idprodpharma = :idprodpharma";
        $stmt = $conn_base->prepare($query);
        $data['idprodpharma'] = $idprodpharma;
        $stmt->execute($data);
    } else {
        $query = "INSERT INTO prodpharma 
                  (libelle, code, type_produit, idfamiprod, idvoie_prod,
                   idunite, dosage, prix_achat, prix_vente_externe, seuil_alerte,
                   seuil_reappro, description, actif)
                  VALUES 
                  (:libelle, :code, :type_produit, :idfamiprod, :idvoie_prod,
                   :idunite, :dosage, :prix_achat, :prix_vente_externe, :seuil_alerte,
                   :seuil_reappro, :description, :actif)";
        $stmt = $conn_base->prepare($query);
        $stmt->execute($data);
    }
    
    $conn_base->commit();
    
    $_SESSION['flash_success'] = "Produit enregistré avec succès !";
    header("Location: ../index.php?page=pharmacie&action=produits");
    exit();
    
} catch (Exception $e) {
    $conn_base->rollBack();
    error_log("[Pharmacie] Erreur sauvegarde produit: " . $e->getMessage());
    $_SESSION['flash_error'] = "Erreur : " . $e->getMessage();
    header("Location: ../index.php?page=pharmacie&action=produits");
    exit();
}