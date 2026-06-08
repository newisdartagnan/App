<?php
// API : liste des produits pharmaceutiques disponibles pour une officine
require_once '../config/config.php';
require_once '../config/database.php';
requireLogin();

header('Content-Type: application/json');

$database = new Database();
$db = $database->getConnection();

$idofficine = (int)($_GET['idofficine'] ?? 0);
if (!$idofficine) {
    echo json_encode([]);
    exit;
}

$query = "SELECT p.idprodpharma, p.libelle, p.code, p.type_produit,
                 sp.quantite AS stock_disponible
          FROM prodpharma p
          JOIN stockpharma sp ON p.idprodpharma = sp.idprodpharma
          WHERE sp.idofficine = :idofficine
          AND   p.actif = 1
          AND   sp.quantite > 0
          ORDER BY p.libelle";

$stmt = $db->prepare($query);
$stmt->execute([':idofficine' => $idofficine]);
$produits = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($produits);
