<?php
/**
 * Activation du compte agent depuis son lien personnel :
 * lecture de l'information RGPD + choix du code PIN, en un seul écran.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$token = (string)($_GET['t'] ?? $_POST['t'] ?? '');
$db = getDB();
$agent = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $st = $db->prepare("SELECT a.id, a.nom, a.prenom, a.matricule, a.telephone, pa.activation_expires
        FROM pt_acces pa JOIN agents a ON a.id = pa.agent_id
        WHERE pa.activation_token = ? AND a.actif = 1");
    $st->execute([$token]);
    $agent = $st->fetch() ?: null;
}
$expire = $agent && strtotime($agent['activation_expires']) < time();

$errors = [];
if ($agent && !$expire && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pin  = (string)($_POST['pin'] ?? '');
    $pin2 = (string)($_POST['pin2'] ?? '');
    if (!ptCsrfCheck())                  $errors[] = 'La page a expiré, rechargez-la.';
    if (empty($_POST['rgpd']))           $errors[] = 'Merci de confirmer avoir lu l\'information sur vos données.';
    if (!preg_match('/^\d{4}$/', $pin))  $errors[] = 'Le code PIN doit contenir 4 chiffres.';
    elseif (ptPinTropSimple($pin))       $errors[] = 'Ce code est trop simple. Choisissez-en un autre.';
    elseif ($pin !== $pin2)              $errors[] = 'Les deux codes ne correspondent pas.';

    if (!$errors) {
        $version = ptRgpdVersion();
        $db->beginTransaction();
        $db->prepare("UPDATE pt_acces SET pin_hash = ?, activation_token = NULL, activation_expires = NULL,
                active_le = NOW(), rgpd_version = ?, rgpd_accepte_le = NOW(), pin_essais = 0, bloque_jusqua = NULL
            WHERE agent_id = ?")
           ->execute([password_hash($pin, PASSWORD_DEFAULT), $version, $agent['id']]);
        $db->prepare("INSERT INTO pt_rgpd_log (agent_id, version, accepte_le, ip, user_agent) VALUES (?,?,NOW(),?,?)")
           ->execute([$agent['id'], $version, ptClientIp(), ptUserAgent()]);
        $db->commit();

        ptAgentLoginSession((int)$agent['id']);
        ptRememberDevice((int)$agent['id']);
        $ident = $agent['matricule'] ?: $agent['telephone'];
        ptFlash('success', 'Compte activé ! Votre identifiant : ' . $ident);
        ptRedirect('app.php?bienvenue=1');
    }
}

ptHead('Activation');
?>
<div class="pt-access">
    <?php ptBrand(); ?>

    <?php if (!$agent): ?>
        <h1>Lien invalide</h1>
        <p class="pt-muted">Ce lien d'activation n'est pas valide ou a déjà été utilisé.</p>
        <a class="pt-btn pt-btn-gold" href="<?= h(ptUrl('login.php')) ?>">J'ai déjà un compte</a>
        <p class="pt-muted pt-small" style="margin-top:14px">Besoin d'un nouveau lien ? Demandez-le à votre responsable.</p>

    <?php elseif ($expire): ?>
        <h1>Lien expiré</h1>
        <p class="pt-muted">Ce lien a expiré. Demandez un nouveau lien à votre responsable.</p>

    <?php else: ?>
        <h1>Bienvenue <?= h($agent['prenom']) ?> 👋</h1>
        <p class="pt-muted">Deux étapes et votre application de pointage est prête.</p>

        <?php foreach ($errors as $e): ?><div class="pt-alert pt-alert-danger"><?= h($e) ?></div><?php endforeach; ?>

        <form method="post" autocomplete="off">
            <?= ptCsrfField() ?>
            <input type="hidden" name="t" value="<?= h($token) ?>">

            <div class="pt-card">
                <div class="pt-card-label">Étape 1</div>
                <h2><i class="fa fa-shield-halved me-1"></i> Vos données personnelles</h2>
                <div class="pt-rgpd"><?= ptTexteRiche(getParam('pointage_rgpd_texte', ptRgpdTexteDefaut())) ?></div>
                <label class="pt-check">
                    <input type="checkbox" name="rgpd" value="1" required <?= !empty($_POST['rgpd']) ? 'checked' : '' ?>>
                    <span>J'ai lu et compris comment mes données sont utilisées.</span>
                </label>
            </div>

            <div class="pt-card">
                <div class="pt-card-label">Étape 2</div>
                <h2><i class="fa fa-key me-1"></i> Choisissez votre code PIN</h2>
                <p class="pt-muted pt-small">4 chiffres, à garder pour vous. Il vous sera demandé si vous changez de téléphone.</p>
                <label class="pt-label" for="pin">Code PIN</label>
                <input class="pt-input pt-pin" id="pin" name="pin" type="tel" inputmode="numeric" maxlength="4" data-pin required autocomplete="new-password">
                <label class="pt-label" for="pin2">Confirmez le code</label>
                <input class="pt-input pt-pin" id="pin2" name="pin2" type="tel" inputmode="numeric" maxlength="4" data-pin required autocomplete="new-password">
            </div>

            <button class="pt-btn pt-btn-gold" type="submit"><i class="fa fa-check"></i> Activer mon compte</button>
        </form>
    <?php endif; ?>
</div>
<?php ptFoot();
