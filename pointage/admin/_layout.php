<?php
/** Mise en page de l'espace responsable (bureau + mobile). */

function ptAdminHead(string $title, string $active = '', string $extraHead = ''): void {
    $u = ptAdminUser();
    $nav = [
        'dashboard'  => ['index.php', 'fa-gauge', 'Tableau de bord'],
        'agents'     => ['agents.php', 'fa-users', 'Agents & accès'],
        'sites'      => ['missions.php', 'fa-location-dot', 'Sites'],
        'parametres' => ['parametres.php', 'fa-sliders', 'Paramètres'],
    ];
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · OV Pointage — Responsable</title>
<link rel="icon" type="image/png" href="<?= h(ptUrl('assets/icons/icon-192.png')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="<?= h(ptUrl('admin/admin.css')) ?>?v=1" rel="stylesheet">
<?= $extraHead ?>
</head>
<body class="pta-body" data-base="<?= h(ptBaseUrl()) ?>" data-csrf="<?= h(ptCsrfToken()) ?>">
<?php if ($u): ?>
<header class="pta-top">
    <a class="pta-brand" href="<?= h(ptUrl('admin/index.php')) ?>">
        <img src="<?= h(ptUrl('assets/icons/icon-192.png')) ?>" alt="">
        <span>OV <strong>Pointage</strong></span>
    </a>
    <nav class="pta-nav">
        <?php foreach ($nav as $key => [$href, $icon, $label]): ?>
            <a href="<?= h(ptUrl('admin/' . $href)) ?>" class="<?= $active === $key ? 'active' : '' ?>"><i class="fa <?= $icon ?>"></i><span><?= h($label) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="pta-user">
        <span class="d-none d-md-inline"><?= h($u['prenom'] . ' ' . $u['nom']) ?></span>
        <form method="post" action="<?= h(ptUrl('admin/logout.php')) ?>" class="d-inline">
            <?= ptCsrfField() ?>
            <button class="btn btn-sm btn-outline-light" title="Déconnexion"><i class="fa fa-right-from-bracket"></i></button>
        </form>
    </div>
</header>
<?php endif; ?>
<main class="pta-main container-xl">
<?php foreach (ptGetFlash() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?> alert-dismissible fade show mt-3"><?= h($f['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach;
}

function ptAdminFoot(string $extraScripts = ''): void {
    ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?= $extraScripts ?>
</body>
</html>
<?php
}
