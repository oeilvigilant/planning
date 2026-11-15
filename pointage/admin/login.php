<?php
require_once __DIR__ . '/_init.php';

if (ptAdminUser()) ptRedirect('admin/index.php');

$error = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    if (!ptCsrfCheck()) {
        $error = 'La page a expiré, réessayez.';
    } else {
        $error = ptAdminLogin($email, (string)($_POST['password'] ?? ''));
        if ($error === '') ptRedirect('admin/index.php');
    }
}

ptAdminHead('Connexion');
?>
</main>
<div class="pta-login">
    <form method="post" class="pta-login-card">
        <?= ptCsrfField() ?>
        <div class="text-center mb-4">
            <img src="<?= h(ptUrl('assets/icons/icon-192.png')) ?>" alt="" style="width:64px;height:64px;border-radius:14px">
            <h1 class="h5 mt-3 mb-0 fw-bold">OV Pointage</h1>
            <div class="text-muted small">Espace responsable</div>
        </div>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
        <label class="form-label small fw-semibold" for="email">Email</label>
        <input class="form-control mb-3" type="email" id="email" name="email" value="<?= h($email) ?>" required autofocus>
        <label class="form-label small fw-semibold" for="password">Mot de passe</label>
        <input class="form-control mb-4" type="password" id="password" name="password" required>
        <button class="btn btn-pta w-100 py-2" type="submit">Se connecter</button>
        <p class="text-muted small text-center mt-3 mb-0">Mêmes identifiants que OV-Gestion.</p>
    </form>
</div>
<main>
<?php ptAdminFoot();
