<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

$username = 'admin';
$new_password = 'admin'; // Changez ici

$hash = password_hash($new_password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE utilisateur SET password = :password WHERE username = :username");
$stmt->execute([
    ':password' => $hash,
    ':username' => $username
]);

echo "✅ Mot de passe réinitialisé pour '$username' : '$new_password'\n";
echo "Nouveau hash : " . $hash . "\n";