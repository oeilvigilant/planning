<?php
/** Mise en page de l'espace responsable (bureau + mobile). */

function ptAdminHead(string $title, string $active = '', string $extraHead = ''): void {
    $u = ptAdminUser();
    $nav = [
        'dashboard'  => ['index.php', 'fa-gauge', 'Tableau de bord'],
        'carte'      => ['carte.php', 'fa-map-location-dot', 'Carte'],
        'pointages'  => ['pointages.php', 'fa-clock', 'Pointages'],
        'evenements' => ['evenements.php', 'fa-triangle-exclamation', 'Événements'],
        'rapports'   => ['rapports.php', 'fa-chart-column', 'Rapports'],
        'agents'     => ['agents.php', 'fa-users', 'Agents'],
        'sites'      => ['missions.php', 'fa-location-dot', 'Sites'],
        'portails'   => ['portails.php', 'fa-handshake', 'Clients'],
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
<link href="<?= h(ptUrl('admin/admin.css')) ?>?v=<?= PT_ASSETS_V ?>" rel="stylesheet">
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
        <button type="button" class="btn btn-sm btn-outline-warning d-none" id="ptaPushBtn" title="Recevoir les alertes (SOS, poste non pris) sur cet appareil">
            <i class="fa fa-bell"></i><span class="d-none d-lg-inline ms-1">Activer les alertes</span></button>
        <span class="d-none d-xl-inline"><?= h($u['prenom'] . ' ' . $u['nom']) ?></span>
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
<?php if (ptAdminUser()): ?>
<script>
/* Alertes push pour le responsable (SOS, poste non pris, incidents graves) */
(function () {
    var btn = document.getElementById('ptaPushBtn');
    if (!btn || !('serviceWorker' in navigator) || !('PushManager' in window)) return;
    var base = document.body.getAttribute('data-base'), api = base + '/api/push.php';
    function u8(b) { var p = '='.repeat((4 - b.length % 4) % 4), r = atob((b + p).replace(/-/g, '+').replace(/_/g, '/')), a = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) a[i] = r.charCodeAt(i); return a; }
    function post(sub, action) {
        return fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-OV-Pointage': '1' },
            body: JSON.stringify({ qui: 'responsable', action: action, subscription: sub.toJSON() }) }).then(function (r) { return r.json(); });
    }
    navigator.serviceWorker.register(base + '/sw.js').then(function () { return navigator.serviceWorker.ready; }).then(function (reg) {
        return reg.pushManager.getSubscription().then(function (sub) {
            if (sub && Notification.permission === 'granted') { post(sub, 'enregistrer'); return; }
            btn.classList.remove('d-none');
            btn.onclick = function () {
                btn.disabled = true;
                Notification.requestPermission().then(function (perm) {
                    if (perm !== 'granted') throw new Error('Notifications refusées par le navigateur.');
                    return fetch(api, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
                }).then(function (k) {
                    return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: u8(k.publicKey) });
                }).then(function (s) { return post(s, 'abonner'); }).then(function (res) {
                    alert(res.message); if (res.ok) btn.classList.add('d-none');
                }).catch(function (e) { alert(e.message || 'Impossible d\'activer les alertes.'); }).then(function () { btn.disabled = false; });
            };
        });
    }).catch(function () {});
})();
</script>
<?php endif; ?>
<?= $extraScripts ?>
</body>
</html>
<?php
}
