<?php
/** Rondes : historique des passages par site, agent et jour. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');
$db = getDB();

$du = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : date('Y-m-d', strtotime('-1 day'));
$au = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : date('Y-m-d');
$fSite = (int)($_GET['site'] ?? 0);
$fAgent = (int)($_GET['agent'] ?? 0);

$where = ['pa.scanne_le BETWEEN ? AND ?'];
$params = [$du . ' 00:00:00', $au . ' 23:59:59'];
if ($fSite) { $where[] = 'pa.mission_id = ?'; $params[] = $fSite; }
if ($fAgent) { $where[] = 'pa.agent_id = ?'; $params[] = $fAgent; }
$st = $db->prepare("SELECT pa.*, pt.nom AS point_nom, a.prenom, a.nom, m.nom AS site_nom
    FROM pt_passages pa JOIN pt_points pt ON pt.id = pa.point_id JOIN agents a ON a.id = pa.agent_id JOIN missions m ON m.id = pa.mission_id
    WHERE " . implode(' AND ', $where) . " ORDER BY pa.scanne_le DESC LIMIT 1000");
$st->execute($params);
$passages = $st->fetchAll();

$parPoint = [];
foreach ($passages as $p) {
    $k = $p['site_nom'] . ' — ' . $p['point_nom'];
    $parPoint[$k] = ($parPoint[$k] ?? 0) + 1;
}
ksort($parPoint);
$sites = $db->query("SELECT m.id, m.nom FROM missions m WHERE EXISTS (SELECT 1 FROM pt_points p WHERE p.mission_id = m.id) ORDER BY m.nom")->fetchAll();
$agents = $db->query("SELECT id, nom, prenom FROM agents WHERE actif = 1 ORDER BY nom")->fetchAll();

ptAdminHead('Rondes', 'rondes');
?>
<div class="pta-title"><div><h1>Rondes</h1><div class="text-muted small"><?= count($passages) ?> passage(s) sur la période</div></div></div>
<form class="pta-card py-3 row g-2 align-items-end" method="get">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Du</label><input class="form-control form-control-sm" type="date" name="du" value="<?= h($du) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Au</label><input class="form-control form-control-sm" type="date" name="au" value="<?= h($au) ?>"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Site</label><select class="form-select form-select-sm" name="site"><option value="">Tous</option>
        <?php foreach ($sites as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $fSite === (int)$x['id'] ? 'selected' : '' ?>><?= h($x['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-1">Agent</label><select class="form-select form-select-sm" name="agent"><option value="">Tous</option>
        <?php foreach ($agents as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $fAgent === (int)$x['id'] ? 'selected' : '' ?>><?= h($x['nom'] . ' ' . $x['prenom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-pta w-100">Filtrer</button></div>
</form>
<?php if (!$sites): ?><div class="alert alert-info small">Aucun point de ronde configuré. Ouvrez un site dans <a href="missions.php">Sites</a> → « Points de ronde ».</div><?php endif; ?>
<div class="row g-3">
    <div class="col-lg-4"><div class="pta-card">
        <h2>Passages par point</h2>
        <?php foreach ($parPoint as $k => $n): ?><div class="d-flex justify-content-between small py-1 border-top"><span><?= h($k) ?></span><strong><?= $n ?></strong></div><?php endforeach; ?>
        <?php if (!$parPoint): ?><div class="small text-muted">Aucun passage.</div><?php endif; ?>
    </div></div>
    <div class="col-lg-8"><div class="pta-card p-0"><div class="table-responsive">
        <table class="pta-table">
            <thead><tr><th class="ps-3">Heure</th><th>Point</th><th>Site</th><th>Agent</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($passages as $p): ?>
                <tr class="small"><td class="ps-3 text-nowrap"><?= date('d/m H:i', strtotime($p['scanne_le'])) ?></td><td class="fw-semibold"><?= h($p['point_nom']) ?></td>
                    <td><?= h($p['site_nom']) ?></td><td><?= h($p['prenom'] . ' ' . $p['nom']) ?></td>
                    <td class="text-nowrap"><?php if ($p['lat']): ?><a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= h($p['lat'] . ',' . $p['lng']) ?>"><i class="fa fa-map-pin"></i></a><?php endif; ?>
                        <?= $p['hors_ligne'] ? '<span class="pta-badge off">hors connexion</span>' : '' ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$passages): ?><tr><td colspan="5" class="text-center text-muted py-4">Aucun passage sur cette période.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div></div></div>
</div>
<?php ptAdminFoot();
