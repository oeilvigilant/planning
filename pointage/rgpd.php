<?php
/**
 * Information RGPD : consultable à tout moment, et à ré-accepter quand le
 * texte change (nouvelle version) avant de pouvoir utiliser l'application.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$agent   = ptCurrentAgent();
$version = ptRgpdVersion();
$aValider = $agent && (int)$agent['rgpd_version'] < $version;

if ($agent && $aValider && $_SERVER['REQUEST_METHOD'] === 'POST' && ptCsrfCheck() && !empty($_POST['rgpd'])) {
    $db = getDB();
    $db->prepare("UPDATE pt_acces SET rgpd_version = ?, rgpd_accepte_le = NOW() WHERE agent_id = ?")
       ->execute([$version, $agent['id']]);
    $db->prepare("INSERT INTO pt_rgpd_log (agent_id, version, accepte_le, ip, user_agent) VALUES (?,?,NOW(),?,?)")
       ->execute([$agent['id'], $version, ptClientIp(), ptUserAgent()]);
    ptRedirect('app.php');
}

ptHead('Vos données');
?>
<div class="pt-access">
    <?php ptBrand('Vos données personnelles'); ?>
    <?php if ($aValider): ?>
        <div class="pt-alert pt-alert-info">L'information sur vos données a été mise à jour. Merci de la lire avant de continuer.</div>
    <?php endif; ?>

    <div class="pt-card">
        <div class="pt-rgpd full"><?= ptTexteRiche(getParam('pointage_rgpd_texte', ptRgpdTexteDefaut())) ?></div>
    </div>

    <?php if ($aValider): ?>
        <form method="post">
            <?= ptCsrfField() ?>
            <label class="pt-check">
                <input type="checkbox" name="rgpd" value="1" required>
                <span>J'ai lu et compris comment mes données sont utilisées.</span>
            </label>
            <button class="pt-btn pt-btn-gold" type="submit"><i class="fa fa-check"></i> Continuer</button>
        </form>
    <?php else: ?>
        <a class="pt-btn pt-btn-ghost" href="<?= h(ptUrl($agent ? 'app.php' : 'login.php')) ?>"><i class="fa fa-arrow-left"></i> Retour</a>
    <?php endif; ?>
</div>
<?php ptFoot();
