<?php
/** Rapports d'heures : par agent et par site, ventilées (nuit, dimanche, férié), export CSV. */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/pointer.php';
ptRequireAdmin('view');
$db = getDB();

$du = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : date('Y-m-01');
$au = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : date('Y-m-t');
$fSite = (int)($_GET['site'] ?? 0);
$seulValides = !empty($_GET['valides']);

$where = ["s.debut_retenu BETWEEN ? AND ?", "s.fin_retenu IS NOT NULL"];
$params = [$du . ' 00:00:00', $au . ' 23:59:59'];
$where[] = $seulValides ? "s.statut = 'valide'" : "s.statut IN ('ok','valide','a_verifier')";
if ($fSite) { $where[] = 's.mission_id = ?'; $params[] = $fSite; }
$st = $db->prepare("SELECT s.*, a.nom, a.prenom, a.matricule, m.nom AS site_nom, c.nom AS client_nom
    FROM pt_services s JOIN agents a ON a.id = s.agent_id JOIN missions m ON m.id = s.mission_id LEFT JOIN clients c ON c.id = m.client_id
    WHERE " . implode(' AND ', $where) . " ORDER BY a.nom, a.prenom, s.debut_retenu");
$st->execute($params);

$agents = []; $sitesT = []; $tot = ['total' => 0, 'nuit' => 0, 'dimanche' => 0, 'ferie' => 0, 'n' => 0, 'verif' => 0];
foreach ($st->fetchAll() as $s) {
    $v = ptVentiler($s['debut_retenu'], $s['fin_retenu']);
    $k = (int)$s['agent_id'];
    $agents[$k] = $agents[$k] ?? ['nom' => $s['nom'] . ' ' . $s['prenom'], 'matricule' => $s['matricule'], 'total' => 0, 'nuit' => 0, 'dimanche' => 0, 'ferie' => 0, 'n' => 0, 'verif' => 0, 'sites' => []];
    $sk = (int)$s['mission_id'];
    $sitesT[$sk] = $sitesT[$sk] ?? ['nom' => $s['site_nom'], 'client' => $s['client_nom'], 'total' => 0, 'n' => 0, 'agents' => []];
    foreach (['total', 'nuit', 'dimanche', 'ferie'] as $c) { $agents[$k][$c] += $v[$c]; $tot[$c] += $v[$c]; }
    $agents[$k]['n']++; $tot['n']++;
    $agents[$k]['sites'][$s['site_nom']] = ($agents[$k]['sites'][$s['site_nom']] ?? 0) + $v['total'];
    if ($s['statut'] === 'a_verifier') { $agents[$k]['verif']++; $tot['verif']++; }
    $sitesT[$sk]['total'] += $v['total']; $sitesT[$sk]['n']++; $sitesT[$sk]['agents'][$k] = true;
}
uasort($sitesT, function ($a, $b) { return $b['total'] <=> $a['total']; });

if (($_GET['export'] ?? '') === 'pdf') {
    ptRequireAdmin('export');
    require_once __DIR__ . '/../includes/pdf.php';
    $titreSite = 'Tous les sites';
    if ($fSite) { $s = ptSite($fSite); $titreSite = $s ? $s['nom'] : $titreSite; }
    ptPdfEnvoyer(ptRapportHeuresPdf($agents, $tot, $du, $au, $titreSite), 'heures_' . $du . '_' . $au . '.pdf');
}
if (($_GET['export'] ?? '') === 'csv') {
    ptRequireAdmin('export');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="heures_' . $du . '_' . $au . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Matricule', 'Agent', 'Services', 'Heures totales', 'dont nuit', 'dont dimanche', 'dont férié', 'À vérifier', 'Détail par site'], ';');
    $f = function ($m) { return number_format($m / 60, 2, ',', ''); };
    foreach ($agents as $a) {
        $det = [];
        foreach ($a['sites'] as $sn => $m) $det[] = $sn . ' : ' . $f($m) . ' h';
        fputcsv($out, [$a['matricule'], $a['nom'], $a['n'], $f($a['total']), $f($a['nuit']), $f($a['dimanche']), $f($a['ferie']), $a['verif'], implode(' | ', $det)], ';');
    }
    exit;
}

$sites = $db->query("SELECT id, nom FROM missions ORDER BY actif DESC, nom")->fetchAll();
$qs = http_build_query(array_filter(['du' => $du, 'au' => $au, 'site' => $fSite ?: null, 'valides' => $seulValides ? 1 : null]));
ptAdminHead('Rapports', 'rapports');
?>
<div class="pta-title">
    <div><h1>Rapports d'heures</h1><div class="text-muted small">Heures retenues (après tolérance et corrections), du <?= date('d/m/Y', strtotime($du)) ?> au <?= date('d/m/Y', strtotime($au)) ?></div></div>
    <div class="d-flex gap-2">
        <?php if (ptAdminCan('export')): ?>
            <a class="btn btn-sm btn-pta" href="?<?= h($qs) ?>&export=pdf"><i class="fa fa-file-pdf me-1"></i>PDF</a>
            <a class="btn btn-sm btn-outline-secondary" href="?<?= h($qs) ?>&export=csv"><i class="fa fa-file-csv me-1"></i>Excel (CSV)</a>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print me-1"></i>Imprimer</button>
    </div>
</div>

<form class="pta-card py-3 row g-2 align-items-end d-print-none" method="get">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Du</label><input class="form-control form-control-sm" type="date" name="du" value="<?= h($du) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Au</label><input class="form-control form-control-sm" type="date" name="au" value="<?= h($au) ?>"></div>
    <div class="col-md-3"><label class="form-label small mb-1">Site</label><select class="form-select form-select-sm" name="site"><option value="">Tous</option>
        <?php foreach ($sites as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $fSite === (int)$x['id'] ? 'selected' : '' ?>><?= h($x['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-check small"><input class="form-check-input" type="checkbox" name="valides" value="1" <?= $seulValides ? 'checked' : '' ?>>
        <span class="form-check-label">Uniquement les services validés</span></label></div>
    <div class="col-md-2"><button class="btn btn-sm btn-pta w-100">Afficher</button></div>
    <div class="col-12 small">
        <a href="?du=<?= date('Y-m-01') ?>&au=<?= date('Y-m-t') ?>">Ce mois</a> ·
        <a href="?du=<?= date('Y-m-01', strtotime('first day of last month')) ?>&au=<?= date('Y-m-t', strtotime('last day of last month')) ?>">Mois dernier</a> ·
        <a href="?du=<?= date('Y-m-d', strtotime('monday this week')) ?>&au=<?= date('Y-m-d', strtotime('sunday this week')) ?>">Cette semaine</a>
    </div>
</form>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="pta-kpi"><div class="v"><?= ptFormatDuree($tot['total']) ?></div><div class="l">Heures totales · <?= $tot['n'] ?> service(s)</div></div></div>
    <div class="col-6 col-lg-3"><div class="pta-kpi"><div class="v"><?= ptFormatDuree($tot['nuit']) ?></div><div class="l">dont nuit (<?= h(getParam('nuit_debut', '21:00')) ?>–<?= h(getParam('nuit_fin', '06:00')) ?>)</div></div></div>
    <div class="col-6 col-lg-3"><div class="pta-kpi"><div class="v"><?= ptFormatDuree($tot['dimanche']) ?></div><div class="l">dont dimanche</div></div></div>
    <div class="col-6 col-lg-3"><div class="pta-kpi"><div class="v"><?= ptFormatDuree($tot['ferie']) ?></div><div class="l">dont jours fériés</div></div></div>
</div>
<?php if ($tot['verif']): ?><div class="alert alert-warning small d-print-none"><?= $tot['verif'] ?> service(s) encore « à vérifier » inclus. <a href="pointages.php?statut=a_verifier&du=<?= h($du) ?>&au=<?= h($au) ?>">Les contrôler</a></div><?php endif; ?>

<div class="pta-card p-0">
<div class="table-responsive">
<table class="pta-table">
    <thead><tr><th class="ps-3">Agent</th><th class="text-end">Services</th><th class="text-end">Total</th><th class="text-end">Nuit</th><th class="text-end">Dimanche</th><th class="text-end">Férié</th><th>Sites</th></tr></thead>
    <tbody>
    <?php foreach ($agents as $a): ?>
        <tr>
            <td class="ps-3"><div class="fw-semibold"><?= h($a['nom']) ?></div><div class="small text-muted"><?= h((string)$a['matricule']) ?><?= $a['verif'] ? ' · <span class="text-danger">' . $a['verif'] . ' à vérifier</span>' : '' ?></div></td>
            <td class="text-end"><?= $a['n'] ?></td>
            <td class="text-end fw-bold"><?= ptFormatDuree($a['total']) ?></td>
            <td class="text-end"><?= ptFormatDuree($a['nuit']) ?></td>
            <td class="text-end"><?= ptFormatDuree($a['dimanche']) ?></td>
            <td class="text-end"><?= ptFormatDuree($a['ferie']) ?></td>
            <td class="small text-muted"><?php foreach ($a['sites'] as $sn => $m) echo h($sn) . ' : ' . ptFormatDuree($m) . '<br>'; ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$agents): ?><tr><td colspan="7" class="text-center text-muted py-4">Aucune heure sur cette période.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>

<?php if ($sitesT): ?>
<div class="pta-card">
    <h2>Par site</h2>
    <?php foreach ($sitesT as $s): ?>
        <div class="d-flex justify-content-between py-1 border-top small">
            <span><span class="fw-semibold"><?= h($s['nom']) ?></span><?= $s['client'] ? ' · ' . h($s['client']) : '' ?> <span class="text-muted">(<?= count($s['agents']) ?> agent(s), <?= $s['n'] ?> service(s))</span></span>
            <span class="fw-bold"><?= ptFormatDuree($s['total']) ?></span>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php ptAdminFoot();
