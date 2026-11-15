<?php
/** Mot de passe oublié (responsables) : envoi d'un lien valable 1 heure. */
require_once __DIR__ . '/_init.php';
$db = getDB();
$envoye = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ptCsrfCheck()) {
    $email = trim((string)($_POST['email'] ?? ''));
    $st = $db->prepare("SELECT id, prenom FROM utilisateurs WHERE email = ? AND actif = 1");
    $st->execute([$email]);
    // Limite : 3 demandes par heure et par compte
    if (($u = $st->fetch())) {
        $n = $db->prepare("SELECT COUNT(*) FROM pt_resets WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $n->execute([$u['id']]);
        if ((int)$n->fetchColumn() < 3) {
            $token = bin2hex(random_bytes(32));
            $db->prepare("INSERT INTO pt_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))")
               ->execute([$u['id'], hash('sha256', $token)]);
            ptMail($email, 'Réinitialisation de votre mot de passe — ' . ptMarque(),
                "Bonjour " . $u['prenom'] . ",\n\nPour choisir un nouveau mot de passe, ouvrez ce lien (valable 1 heure) :\n"
                . ptUrl('admin/reinit.php?t=' . $token) . "\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez cet email.\n\n" . ptMarque());
        }
    }
    $envoye = true;   // même réponse que le compte existe ou non (pas de fuite d'information)
}
ptAdminHead('Mot de passe oublié');
?>
</main>
<div class="pta-login">
    <form method="post" class="pta-login-card">
        <?= ptCsrfField() ?>
        <h1 class="h5 fw-bold mb-3">Mot de passe oublié</h1>
        <?php if ($envoye): ?>
            <div class="alert alert-success small">Si un compte existe pour cette adresse, un email vient d'être envoyé avec un lien valable 1 heure.</div>
        <?php else: ?>
            <label class="form-label small fw-semibold" for="email">Votre email</label>
            <input class="form-control mb-3" type="email" id="email" name="email" required autofocus>
            <button class="btn btn-pta w-100">Recevoir un lien</button>
        <?php endif; ?>
        <a class="d-block text-center small mt-3" href="login.php">Retour à la connexion</a>
    </form>
</div>
<main>
<?php ptAdminFoot();
