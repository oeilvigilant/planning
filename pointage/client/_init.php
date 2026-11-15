<?php
/** Portail client : accès par lien sécurisé (jeton), lecture seule. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/pointer.php';
require_once __DIR__ . '/../includes/evenements.php';

header('X-Robots-Tag: noindex, nofollow');

function ptPortail(): array {
    $t = (string)($_GET['t'] ?? $_POST['t'] ?? '');
    $p = null;
    if (preg_match('/^[a-f0-9]{64}$/', $t)) {
        $st = getDB()->prepare("SELECT p.*, c.nom AS client_nom FROM pt_portails p LEFT JOIN clients c ON c.id = p.client_id WHERE p.token = ?");
        $st->execute([$t]);
        $p = $st->fetch() ?: null;
    }
    if (!$p || $p['revoque'] || ($p['expire_le'] && $p['expire_le'] < date('Y-m-d'))) {
        http_response_code(403);
        echo '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Accès indisponible</title>'
           . '<body style="font-family:system-ui;padding:40px;text-align:center;color:#334"><h1 style="font-size:1.3rem">Accès indisponible</h1>'
           . '<p>Ce lien n\'est pas valide ou a expiré. Contactez votre prestataire pour en obtenir un nouveau.</p></body>';
        exit;
    }
    if (!$p['derniere_visite'] || strtotime($p['derniere_visite']) < time() - 300) {
        getDB()->prepare("UPDATE pt_portails SET derniere_visite = NOW() WHERE id = ?")->execute([$p['id']]);
    }
    $p['mission_ids'] = array_values(array_filter(array_map('intval', explode(',', $p['missions']))));
    if (!$p['mission_ids']) $p['mission_ids'] = [0];
    return $p;
}

function ptInMissions(array $p): string {
    return implode(',', array_map('intval', $p['mission_ids']));
}

/** Services retenus d'une période sur les sites du portail. */
function ptServicesPortail(array $p, string $du, string $au): array {
    $st = getDB()->prepare("SELECT s.*, a.prenom, a.nom, m.nom AS site_nom FROM pt_services s
        JOIN agents a ON a.id = s.agent_id JOIN missions m ON m.id = s.mission_id
        WHERE s.mission_id IN (" . ptInMissions($p) . ") AND s.statut IN ('ok','valide','a_verifier') AND s.fin_retenu IS NOT NULL
          AND s.debut_retenu BETWEEN ? AND ? ORDER BY s.debut_retenu");
    $st->execute([$du . ' 00:00:00', $au . ' 23:59:59']);
    return $st->fetchAll();
}

function ptClientHead(array $p, string $titre, string $vue): void {
    $t = $p['token'];
    $tabs = ['direct' => ['fa-signal', 'En direct']];
    if ($p['voir_heures']) $tabs['heures'] = ['fa-clock', 'Heures'];
    if ($p['voir_evenements']) $tabs['evenements'] = ['fa-book', 'Main courante'];
    $tabs['rapport'] = ['fa-file-signature', 'Rapport'];
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($titre) ?> · <?= h(ptMarque()) ?></title>
<link rel="icon" type="image/png" href="<?= h(ptUrl('assets/icons/icon-192.png')) ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
body { background: #f3f4f7; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
.cl-top { background: #0d1520; color: #fff; border-bottom: 3px solid #c9a84c; }
.cl-top img { width: 40px; height: 40px; border-radius: 10px; }
.cl-brand { color: #c9a84c; font-weight: 800; letter-spacing: 1.5px; }
.cl-tabs a { color: rgba(255,255,255,.7); text-decoration: none; padding: 10px 14px; display: inline-block; border-bottom: 3px solid transparent; margin-bottom: -3px; white-space: nowrap; }
.cl-tabs a.active { color: #fff; border-color: #c9a84c; font-weight: 600; }
.cl-card { background: #fff; border-radius: 14px; border: 1px solid #e6e8ec; padding: 18px; margin-bottom: 16px; }
.cl-kpi { font-size: 1.8rem; font-weight: 800; color: #1a2332; }
.cl-dot { width: 10px; height: 10px; border-radius: 50%; background: #16a34a; display: inline-block; box-shadow: 0 0 0 4px rgba(22,163,74,.15); }
@media print { .cl-top, .no-print { display: none !important; } body { background: #fff; } .cl-card { border: 0; padding: 0; } }
</style>
</head>
<body>
<header class="cl-top no-print">
    <div class="container py-2 d-flex align-items-center gap-3">
        <img src="<?= h(ptUrl('assets/icons/icon-192.png')) ?>" alt="">
        <div><div class="cl-brand"><?= h(mb_strtoupper(ptMarque())) ?></div><div class="small text-white-50"><?= h($p['libelle']) ?></div></div>
    </div>
    <nav class="container cl-tabs d-flex overflow-auto">
        <?php foreach ($tabs as $k => [$i, $l]): ?>
            <a class="<?= $vue === $k ? 'active' : '' ?>" href="?t=<?= h($t) ?>&v=<?= $k ?>"><i class="fa <?= $i ?> me-1"></i><?= $l ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<main class="container py-4">
<?php
}

function ptClientFoot(string $scripts = ''): void {
    echo '</main><footer class="container pb-4 small text-muted no-print">Accès en lecture seule · ' . h(ptMarque()) . '</footer>' . $scripts . '</body></html>';
}
