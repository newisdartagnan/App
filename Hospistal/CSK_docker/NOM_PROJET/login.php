<?php
require_once 'config/config.php';
require_once 'config/database.php';

if (isLoggedIn()) {
    redirect('index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $database = new Database();
        $db = $database->getConnection();
        $user = new User($db);

        $userData = $user->login($username, $password);

        if ($userData) {
            $permissions = $user->getPermissions($userData['idprofiluser']);

            $_SESSION['user_id']     = $userData['idutilisateur'];
            $_SESSION['username']    = $userData['username'];
            $_SESSION['nom_complet'] = $userData['prenom'] . ' ' . $userData['nom'];
            $_SESSION['profil']      = $userData['profil_nom'];
            $_SESSION['profil_id']   = $userData['idprofiluser'];
            $_SESSION['site_id']     = $userData['idsite'];
            $_SESSION['site_nom']    = $userData['site_nom'];
            $_SESSION['permissions'] = $permissions;

            redirect('index.php');
        } else {
            $error = 'Identifiant ou mot de passe incorrect';
        }
    } else {
        $error = 'Veuillez remplir tous les champs';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?php echo APP_NAME; ?></title>
    <link rel="stylesheet" href="<?php echo ASSETS_URL; ?>css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <h1><?php echo APP_NAME; ?></h1>
                <p>Centre Hospitalier Monkole</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="login-form">
                <div class="form-group">
                    <label for="username">Nom d'utilisateur</label>
                    <input type="text" id="username" name="username" class="form-control"
                           required autofocus
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="password">Mot de passe</label>
                    <input type="password" id="password" name="password" class="form-control" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">
                    <i class="fas fa-sign-in-alt"></i> Se connecter
                </button>
            </form>

            <div class="login-footer">
                <p>Version <?php echo APP_VERSION; ?></p>
            </div>
        </div>
    </div>
</body>
</html>
