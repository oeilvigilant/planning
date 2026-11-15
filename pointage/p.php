<?php
/**
 * Ouvert quand l'agent scanne une étiquette avec l'appareil photo du téléphone
 * (le QR code contient cette adresse). Enregistre le passage puis propose la suite.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$code = strtoupper((string)($_GET['c'] ?? ''));
if (!preg_match('/^[A-Z0-9]{16}$/', $code)) ptRedirect('app.php');
if (!ptCurrentAgent()) ptRedirect('login.php?r=' . rawurlencode('p.php?c=' . $code));
$agent = ptRequireAgent();

ptHead('Point de ronde');
?>
<div class="pt-access" style="text-align:center">
    <?php ptBrand('Ronde'); ?>
    <div class="pt-card" data-pt-passage data-code="<?= h($code) ?>" data-api="<?= h(ptUrl('api/passage.php')) ?>" data-agent="<?= (int)$agent['id'] ?>">
        <i class="fa fa-qrcode" style="font-size:3rem;color:var(--pt-gold)"></i>
        <div class="pt-small" data-scan-status style="margin-top:14px;font-size:1rem"></div>
    </div>
    <a class="pt-btn pt-btn-gold" href="<?= h(ptUrl('ronde.php')) ?>"><i class="fa fa-list-check"></i> Voir ma ronde</a>
    <a class="pt-btn pt-btn-ghost" href="<?= h(ptUrl('app.php')) ?>" style="margin-top:10px">Accueil</a>
</div>
<?php ptFoot(['assets/ronde.js']);
