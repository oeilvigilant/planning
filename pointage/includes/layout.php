<?php
/** Mise en page mobile de l'application agent. */

/** Version des CSS/JS : à incrémenter à chaque modification (cache LWS et navigateurs). */
const PT_ASSETS_V = '5';

function ptHead(string $title, array $opts = []): void {
    $bodyClass = $opts['bodyClass'] ?? '';
    $v = PT_ASSETS_V;
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0d1520">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="OV Pointage">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · OV Pointage</title>
<link rel="manifest" href="<?= h(ptUrl('manifest.webmanifest')) ?>">
<link rel="icon" type="image/png" href="<?= h(ptUrl('assets/icons/icon-192.png')) ?>">
<link rel="apple-touch-icon" href="<?= h(ptUrl('assets/icons/apple-touch-icon.png')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= h(ptUrl('assets/pointage.css')) ?>?v=<?= $v ?>" rel="stylesheet">
</head>
<body class="pt-body <?= h($bodyClass) ?>" data-base="<?= h(ptBaseUrl()) ?>" data-csrf="<?= h(ptCsrfToken()) ?>">
<?php
    foreach (ptGetFlash() as $f) {
        echo '<div class="pt-toast pt-toast-' . h($f['type']) . '" role="status">' . h($f['message']) . '</div>';
    }
}

function ptFoot(array $scripts = []): void {
    ?>
<script src="<?= h(ptUrl('assets/pointage.js')) ?>?v=<?= PT_ASSETS_V ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= h(ptUrl($s)) ?>?v=<?= PT_ASSETS_V ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Logo + titre de marque pour les écrans d'accès. */
function ptBrand(string $sous = 'Pointage terrain'): void {
    ?>
<div class="pt-brand">
    <img src="<?= h(ptUrl('assets/icons/icon-192.png')) ?>" alt="" class="pt-brand-logo">
    <div>
        <div class="pt-brand-name"><?= h(mb_strtoupper(ptMarque())) ?></div>
        <div class="pt-brand-sub"><?= h($sous) ?></div>
    </div>
</div>
<?php
}
