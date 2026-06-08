<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - <?= APP_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #0d6efd;
            --primary-dark: #0b5ed7;
        }
        body {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 2rem 1rem;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 16px;
            padding: 2.5rem;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        .app-logo {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .app-logo h1 {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 0.25rem;
        }
        .app-logo p {
            color: #6c757d;
            font-size: 0.9rem;
            margin: 0;
        }
        .service-badges {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        .service-badges .badge {
            font-size: 0.75rem;
            padding: 0.35rem 0.65rem;
            border-radius: 20px;
            font-weight: 500;
        }
        .badge-labo { background: #6f42c1; color: white; }
        .badge-imagerie { background: #0dcaf0; color: #000; }
        .badge-pharmacie { background: #198754; color: white; }
        .form-floating label {
            color: #6c757d;
        }
        .form-floating .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
        }
        .btn-login {
            background: var(--primary);
            border: none;
            padding: 0.75rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 10px;
            transition: background 0.2s;
        }
        .btn-login:hover {
            background: var(--primary-dark);
        }
        .alert {
            border-radius: 10px;
            font-size: 0.9rem;
        }
        .access-info {
            text-align: center;
            color: #6c757d;
            font-size: 0.8rem;
            margin-top: 1.5rem;
            line-height: 1.4;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="app-logo">
                <h1><i class="bi bi-hospital"></i> <?= APP_NAME ?></h1>
                <p>Gestion des services techniques</p>
            </div>
            
            <div class="service-badges">
                <span class="badge badge-labo"><i class="bi bi-droplet"></i> Laboratoire</span>
                <span class="badge badge-imagerie"><i class="bi bi-image"></i> Imagerie</span>
                <span class="badge badge-pharmacie"><i class="bi bi-capsule"></i> Pharmacie</span>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="index.php?page=login" autocomplete="off">
                <div class="form-floating mb-3">
                    <input type="text" 
                           class="form-control" 
                           id="username" 
                           name="login"  // ← Garder "login" pour correspondre à $_POST['login'] dans index.php
                           placeholder="Nom d'utilisateur"
                           value="<?= htmlspecialchars($login_value ?? '') ?>"
                           required
                           autofocus>
                    <label for="username"><i class="bi bi-person"></i> Nom d'utilisateur</label>
                </div>
                
                <div class="form-floating mb-4">
                    <input type="password" 
                           class="form-control" 
                           id="password" 
                           name="password" 
                           placeholder="Mot de passe"
                           required>
                    <label for="password"><i class="bi bi-lock"></i> Mot de passe</label>
                </div>
                
                <button type="submit" class="btn btn-primary btn-login w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Se connecter
                </button>
            </form>
            
            <div class="access-info">
                <i class="bi bi-info-circle"></i> 
                Accès réservé aux techniciens de laboratoire,<br>
                d'imagerie, pharmaciens et administrateurs.
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>