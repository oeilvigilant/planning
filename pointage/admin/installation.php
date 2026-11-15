<?php
/**
 * Première installation (outil vendu seul, base vide) : création du premier
 * compte administrateur et du nom de la société. Inaccessible dès qu'un
 * compte existe.
 */
require_once __DIR__ . '/_init.php';
$db = getDB();
if ((int)$db->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn() > 0) ptRedirect('admin/login.php');

$errors = [];
$v = ['societe' => '', 'prenom' => '', 'nom' => '', 'email' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = trim((string)($_POST[$k] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    if (!ptCsrfCheck()) $errors[] = 'Session expirée, réessayez.';
    if (in_array('', $v, true)) $errors[] = 'Tous les champs sont obligatoires.';
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Adresse email invalide.';
    if (strlen($pass) < 10) $errors[] = 'Le mot de passe doit contenir au moins 10 caractères.';
    if ($pass !== (string)($_POST['password2'] ?? '')) $errors[] = 'Les mots de passe ne correspondent pas.';
    if (!$errors) {
        $roleId = (int)$db->query("SELECT id FROM roles WHERE slug = 'admin'")->fetchColumn();
        $db->prepare("INSERT INTO utilisateurs (nom, prenom, email, password, role_id, actif) VALUES (?,?,?,?,?,1)")
           ->execute([mb_strtoupper($v['nom']), $v['prenom'], $v['email'], password_hash($pass, PASSWORD_DEFAULT), $roleId]);
        setParam('pointage_marque', $v['societe']);
        ptAdminLogin($v['email'], $pass);
        ptFlash('success', 'Bienvenue ! Créez maintenant votre premier site, puis vos agents.');
        ptRedirect('admin/index.php');
    }
}

ptAdminHead('Installation');
?>
</main>
<div class="pta-login">
    <form method="post" class="pta-login-card" style="max-width:460px">
        <?= ptCsrfField() ?>
        <div class="text-center mb-3">
            <img src="<?= h(ptUrl('assets/icons/icon-192.png')) ?>" alt="" style="width:64px;height:64px;border-radius:14px">
            <h1 class="h5 mt-3 mb-0 fw-bold">Bienvenue</h1>
            <div class="text-muted small">Créez le compte administrateur de votre outil de pointage.</div>
        </div>
        <?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?= h($e) ?></div><?php endforeach; ?>
        <label class="form-label small fw-semibold">Nom de votre société</label>
        <input class="form-control mb-2" name="societe" value="<?= h($v['societe']) ?>" required>
        <div class="row g-2">
            <div class="col-6"><label class="form-label small fw-semibold">Prénom</label><input class="form-control" name="prenom" value="<?= h($v['prenom']) ?>" required></div>
            <div class="col-6"><label class="form-label small fw-semibold">Nom</label><input class="form-control" name="nom" value="<?= h($v['nom']) ?>" required></div>
        </div>
        <label class="form-label small fw-semibold mt-2">Email</label>
        <input class="form-control mb-2" type="email" name="email" value="<?= h($v['email']) ?>" required>
        <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label small fw-semibold">Mot de passe</label><input class="form-control" type="password" name="password" minlength="10" required></div>
            <div class="col-6"><label class="form-label small fw-semibold">Confirmation</label><input class="form-control" type="password" name="password2" minlength="10" required></div>
        </div>
        <button class="btn btn-pta w-100 py-2">Créer mon compte</button>
    </form>
</div>
<main>
<?php ptAdminFoot();
