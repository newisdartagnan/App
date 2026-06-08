<?php
// ============================================
// debug.php — Script de diagnostic DB + Patient
// Accès : http://localhost:8001/debug.php
// ============================================
require_once 'config/config.php';
require_once 'config/database.php';

echo "<h2>GPS — Debug</h2>";

// 1. Test connexion
try {
    $database = new Database();
    $db = $database->getConnection();
    echo "<p style='color:green'>✅ Connexion MySQL OK</p>";
} catch (Exception $e) {
    die("<p style='color:red'>❌ Connexion échouée : " . $e->getMessage() . "</p>");
}

// 2. Lister les tables
echo "<h3>Tables disponibles :</h3><ul>";
$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    echo "<li>$t</li>";
}
echo "</ul>";

// 3. Test recherche patient
$search = $_GET['q'] ?? '';
if ($search) {
    require_once 'models/Patient.php';
    $patient = new Patient($db);
    $results = $patient->searchByName($search);

    echo "<h3>Résultats pour \"" . htmlspecialchars($search) . "\" :</h3>";
    echo "<pre>" . print_r($results, true) . "</pre>";
} else {
    echo "<p>Ajouter <code>?q=NomPatient</code> à l'URL pour tester la recherche.</p>";
}

// 4. Compter patients
$count = $db->query("SELECT COUNT(*) FROM patient")->fetchColumn();
echo "<p>Nombre de patients dans la base : <strong>$count</strong></p>";
