<?php
/** Agent : ronde — selon le site : scan QR, photo à chaque point, ou validation simple. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$agent = ptRequireAgent();
$agentId = (int)$agent['id'];
$ouvert = ptServiceOuvert($agentId);
$sites = array_values(array_filter(ptSitesAgent($agentId), function ($s) { return ($s['pt_ronde_mode'] ?? 'aucune') !== 'aucune'; }));
$siteId = $ouvert ? (int)$ouvert['mission_id'] : (int)($_GET['site'] ?? (count($sites) === 1 ? $sites[0]['id'] : 0));
if ($siteId && !$ouvert && !in_array($siteId, array_map('intval', array_column($sites, 'id')), true)) $siteId = 0;
$site = $siteId ? ptSite($siteId) : null;
$mode = $site['pt_ronde_mode'] ?? 'aucune';
$points = ($site && $mode !== 'aucune') ? ptPointsSite($siteId) : [];
$etat = $points ? ptEtatRonde($siteId, $agentId) : null;

ptHead('Ronde');
?>
<div class="pt-top">
    <a class="pt-icon-btn" href="<?= h(ptUrl('app.php')) ?>" aria-label="Retour"><i class="fa fa-arrow-left"></i></a>
    <div class="pt-hello" style="flex:1;text-align:center">Ronde</div>
    <span style="width:44px"></span>
</div>

<?php if (!$site): ?>
    <div class="pt-card">
        <div class="pt-card-label">Sur quel site ?</div>
        <?php foreach ($sites as $s): ?>
            <a class="pt-site-choice" href="?site=<?= (int)$s['id'] ?>" style="text-decoration:none;color:inherit"><span><strong><?= h($s['nom']) ?></strong></span></a>
        <?php endforeach; ?>
        <?php if (!$sites): ?><div class="pt-muted">Aucune ronde n'est prévue sur vos sites.</div><?php endif; ?>
    </div>
<?php elseif (!$points): ?>
    <div class="pt-card"><h2><?= h($site['nom']) ?></h2><div class="pt-muted">Aucune ronde n'est prévue sur ce site.</div></div>
<?php else: ?>
    <div class="pt-card" style="text-align:center" data-pt-ronde data-mode="<?= h($mode) ?>" data-api="<?= h(ptUrl('api/passage.php')) ?>" data-agent="<?= $agentId ?>">
        <div class="pt-card-label"><?= h($site['nom']) ?></div>
        <div class="pt-total"><?= (int)$etat['faits'] ?> / <?= (int)$etat['total'] ?></div>
        <div class="pt-muted pt-small"><?= $etat['total'] > 1 ? 'points faits pour cette ronde' : 'ronde en cours' ?>
            <?= $etat['derniere_complete'] ? '· dernière ronde complète à ' . date('H\hi', strtotime($etat['derniere_complete'])) : '' ?>
            <?= (int)$site['pt_ronde_freq_min'] ? '<br>Une ronde toutes les ' . (int)$site['pt_ronde_freq_min'] . ' min' : '' ?></div>
        <?php if ($mode === 'qr'): ?>
            <button type="button" class="pt-btn pt-btn-gold" data-scan style="margin-top:16px"><i class="fa fa-qrcode"></i> Scanner un point</button>
            <div class="pt-scan" data-scan-box hidden>
                <video playsinline muted data-video></video>
                <button type="button" class="pt-btn pt-btn-ghost" data-scan-stop style="margin-top:10px">Fermer la caméra</button>
            </div>
            <details style="margin-top:12px;text-align:left">
                <summary class="pt-muted pt-small">QR code abîmé ? Saisir le code inscrit sous l'étiquette</summary>
                <div style="display:flex;gap:8px;margin-top:8px">
                    <input class="pt-input" data-code-manuel maxlength="16" autocapitalize="characters" placeholder="Code à 16 caractères" style="font-size:1rem">
                    <button type="button" class="pt-btn pt-btn-ghost" data-code-ok style="width:auto">OK</button>
                </div>
            </details>
        <?php else: ?>
            <div class="pt-muted pt-small" style="margin-top:10px"><?= $mode === 'photo' ? 'Touchez un point puis prenez-le en photo.' : 'Touchez chaque point une fois contrôlé.' ?></div>
            <input type="file" accept="image/*" capture="environment" data-photo-point hidden>
        <?php endif; ?>
        <div class="pt-small" data-scan-status style="margin-top:10px;min-height:1.2em"></div>
    </div>

    <div class="pt-card">
        <h2><i class="fa fa-list-check me-1"></i> Points de contrôle</h2>
        <?php foreach ($points as $pt): $fait = isset($etat['vus'][(int)$pt['id']]); ?>
            <div class="pt-info-row" style="align-items:center">
                <i class="fa <?= $fait ? 'fa-circle-check' : 'fa-circle' ?>" style="color:<?= $fait ? 'var(--pt-green)' : 'var(--pt-muted)' ?>"></i>
                <div style="flex:1"><strong><?= h($pt['nom']) ?></strong>
                    <?php if ($pt['consigne']): ?><div class="pt-muted pt-small"><?= h($pt['consigne']) ?></div><?php endif; ?>
                    <?php if ($fait): ?><div class="pt-small" style="color:var(--pt-green)">fait à <?= date('H\hi', strtotime($etat['vus'][(int)$pt['id']])) ?></div><?php endif; ?></div>
                <?php if ($mode !== 'qr' && !$fait): ?>
                    <button type="button" class="pt-btn <?= $mode === 'photo' ? 'pt-btn-gold' : 'pt-btn-green' ?>" style="width:auto;padding:10px 14px" data-point="<?= (int)$pt['id'] ?>">
                        <i class="fa <?= $mode === 'photo' ? 'fa-camera' : 'fa-check' ?>"></i> <?= $mode === 'photo' ? 'Photo' : 'Fait' ?></button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($mode === 'qr'): ?><script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script><?php endif; ?>
<?php ptFoot(['assets/ronde.js']);
