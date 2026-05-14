<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (login($email, $password)) {
        header('Location: ' . APP_URL . '/modules/dashboard/index.php');
        exit;
    } else {
        $error = 'Email ou mot de passe incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion — OV-Gestion</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="assets/css/theme.css" rel="stylesheet">
<style>
body {
    background: linear-gradient(135deg, #0d1520 0%, #1a2332 50%, #0d1520 100%);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI', sans-serif;
}
.login-card {
    background: rgba(255,255,255,0.04);
    border: 1px solid rgba(201,168,76,0.2);
    border-radius: 16px;
    padding: 2.5rem;
    width: 100%;
    max-width: 420px;
    backdrop-filter: blur(10px);
    box-shadow: 0 25px 50px rgba(0,0,0,0.5);
}
.login-logo { max-height: 80px; filter: brightness(0) invert(1); }
.login-title { color: #c9a84c; font-size: 1.1rem; letter-spacing: 2px; text-transform: uppercase; }
.form-control {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(201,168,76,0.3);
    color: #fff;
    border-radius: 8px;
}
.form-control:focus {
    background: rgba(255,255,255,0.12);
    border-color: #c9a84c;
    color: #fff;
    box-shadow: 0 0 0 0.2rem rgba(201,168,76,0.25);
}
.form-control::placeholder { color: rgba(255,255,255,0.4); }
.form-label { color: rgba(255,255,255,0.7); font-size: 0.85rem; }
.btn-login {
    background: linear-gradient(135deg, #c9a84c, #a8883c);
    border: none;
    color: #1a2332;
    font-weight: 700;
    letter-spacing: 1px;
    border-radius: 8px;
    padding: 0.65rem;
    width: 100%;
    transition: all 0.3s;
}
.btn-login:hover { transform: translateY(-1px); box-shadow: 0 8px 20px rgba(201,168,76,0.4); }
.divider { border-top: 1px solid rgba(255,255,255,0.1); }
.tagline { color: rgba(255,255,255,0.35); font-size: 0.75rem; letter-spacing: 1px; }
</style>
</head>
<body>
<div class="login-card text-center">
    <img src="assets/img/logo.png" alt="Oeil Vigilant" class="login-logo mb-3">
    <p class="login-title mb-1">OV-Gestion</p>
    <p class="tagline mb-4">VOTRE SÉCURITÉ, NOTRE PRIORITÉ</p>

    <div class="divider mb-4"></div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 text-start" style="font-size:0.875rem">
        <i class="fa fa-exclamation-circle me-2"></i><?= h($error) ?>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3 text-start">
            <label class="form-label">Email</label>
            <div class="input-group">
                <span class="input-group-text bg-transparent border-end-0" style="border-color:rgba(201,168,76,0.3);color:#c9a84c">
                    <i class="fa fa-envelope"></i>
                </span>
                <input type="email" name="email" class="form-control border-start-0"
                       placeholder="admin@ov.fr" required autocomplete="email"
                       value="<?= h($_POST['email'] ?? '') ?>">
            </div>
        </div>
        <div class="mb-4 text-start">
            <label class="form-label">Mot de passe</label>
            <div class="input-group">
                <span class="input-group-text bg-transparent border-end-0" style="border-color:rgba(201,168,76,0.3);color:#c9a84c">
                    <i class="fa fa-lock"></i>
                </span>
                <input type="password" name="password" class="form-control border-start-0"
                       placeholder="••••••••" required autocomplete="current-password">
            </div>
        </div>
        <button type="submit" class="btn btn-login">
            <i class="fa fa-shield-halved me-2"></i>SE CONNECTER
        </button>
    </form>

    <p class="tagline mt-4">© <?= date('Y') ?> Oeil Vigilant — Gestion interne</p>
</div>
</body>
</html>
