<?php
/** Choix d'un nouveau mot de passe depuis le lien reçu par email. */
require_once __DIR__ . '/_init.php';
$db = getDB();
$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$reset = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = $db->prepare("SELECT * FROM pt_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()");
    $st->execute([hash('sha256', $token)]);
    $reset = $st->fetch() ?: null;
}
$erreur = '';
if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string)($_POST['password'] ?? '');
    if (!ptCsrfCheck()) $erreur = 'Session expirée, réessayez.';
    elseif (strlen($p1) < 10) $erreur = 'Au moins 10 caractères.';
    elseif ($p1 !== (string)($_POST['password2'] ?? '')) $erreur = 'Les deux mots de passe ne correspondent pas.';
    else {
        $db->prepare("UPDATE utilisateurs SET password = ? WHERE id = ?")->execute([password_hash($p1, PASSWORD_DEFAULT), $reset['user_id']]);
        $db->prepare("UPDATE pt_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")->execute([$reset['user_id']]);
        ptFlash('success', 'Mot de passe modifié. Connectez-vous avec le nouveau (il vaut aussi pour OV-Gestion).');
        ptRedirect('admin/login.php');
    }
}
ptAdminHead('Nouveau mot de passe');
?>
</main>
<div class="pta-login">
    <form method="post" class="pta-login-card">
        <?= ptCsrfField() ?><input type="hidden" name="t" value="<?= h($token) ?>">
        <h1 class="h5 fw-bold mb-3">Nouveau mot de passe</h1>
        <?php if (!$reset): ?>
            <div class="alert alert-warning small">Ce lien n'est pas valide ou a expiré.</div>
            <a class="d-block text-center small" href="oubli.php">Faire une nouvelle demande</a>
        <?php else: ?>
            <?php if ($erreur): ?><div class="alert alert-danger py-2 small"><?= h($erreur) ?></div><?php endif; ?>
            <input class="form-control mb-2" type="password" name="password" minlength="10" required placeholder="Nouveau mot de passe (10 caractères min.)">
            <input class="form-control mb-3" type="password" name="password2" minlength="10" required placeholder="Confirmation">
            <button class="btn btn-pta w-100">Enregistrer</button>
        <?php endif; ?>
    </form>
</div>
<main>
<?php ptAdminFoot();
