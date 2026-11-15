<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

// Retour après connexion (ex. étiquette de ronde scannée) : chemins internes connus uniquement
$retour = (string)($_GET['r'] ?? $_POST['r'] ?? '');
if (!preg_match('#^(p\.php\?c=[A-Z0-9]{16}|ronde\.php|evenement\.php)$#', $retour)) $retour = 'app.php';
if (ptCurrentAgent()) ptRedirect($retour);

$error = '';
$ident = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ident = trim((string)($_POST['identifiant'] ?? ''));
    if (!ptCsrfCheck()) {
        $error = 'La page a expiré, réessayez.';
    } else {
        $res = ptAgentLoginPin($ident, (string)($_POST['pin'] ?? ''));
        if ($res['ok']) {
            ptAgentLoginSession($res['agent_id']);
            ptRememberDevice($res['agent_id']);
            ptRedirect($retour);
        }
        $error = $res['error'];
    }
}

ptHead('Connexion');
?>
<div class="pt-access">
    <?php ptBrand(); ?>
    <h1>Connexion</h1>
    <p class="pt-muted">Pointez, signalez, contactez votre responsable.</p>

    <?php if ($error): ?><div class="pt-alert pt-alert-danger"><?= h($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="off" class="pt-card">
        <?= ptCsrfField() ?>
        <input type="hidden" name="r" value="<?= h($retour) ?>">
        <label class="pt-label" for="identifiant" style="margin-top:0">Matricule ou n° de téléphone</label>
        <input class="pt-input" id="identifiant" name="identifiant" value="<?= h($ident) ?>" required autocapitalize="characters" placeholder="Ex. 25F01 ou 06 12 34 56 78">
        <label class="pt-label" for="pin">Code PIN</label>
        <input class="pt-input pt-pin" id="pin" name="pin" type="tel" inputmode="numeric" maxlength="4" data-pin required>
        <button class="pt-btn pt-btn-gold" type="submit" style="margin-top:18px"><i class="fa fa-right-to-bracket"></i> Se connecter</button>
    </form>

    <p class="pt-muted pt-small" style="text-align:center">
        Première connexion ? Utilisez le lien envoyé par votre responsable.<br>
        PIN oublié ? Demandez un nouveau lien à votre responsable.
    </p>
    <div class="pt-footer-links"><a href="<?= h(ptUrl('rgpd.php')) ?>">Vos données</a></div>
</div>
<script>if (window.caches) caches.delete('ovpt-pages');</script>
<?php ptFoot();
