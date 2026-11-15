<?php
/** Événements : SOS, incidents, main courante — suivi et traitement. */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/pointer.php';
require_once __DIR__ . '/../includes/evenements.php';
$user = ptRequireAdmin('view');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ptRequireAdmin('edit');
    $retour = 'admin/evenements.php' . (!empty($_POST['retour']) && strpos($_POST['retour'], '?') === 0 ? $_POST['retour'] : '');
    if (!ptCsrfCheck()) { ptFlash('danger', 'Session expirée.'); ptRedirect($retour); }
    $id = (int)($_POST['id'] ?? 0);
    $statut = in_array($_POST['statut'] ?? '', ['ouvert', 'en_cours', 'clos'], true) ? $_POST['statut'] : 'ouvert';
    $db->prepare("UPDATE pt_evenements SET statut = ?, commentaire = ?, visible_client = ?, traite_par = ?, traite_le = NOW() WHERE id = ?")
       ->execute([$statut, trim((string)($_POST['commentaire'] ?? '')) ?: null, isset($_POST['visible_client']) ? 1 : 0, $user['id'], $id]);
    ptFlash('success', 'Événement mis à jour.');
    ptRedirect($retour);
}

$du = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : date('Y-m-d', strtotime('-13 days'));
$au = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : date('Y-m-d');
$fType = in_array($_GET['type'] ?? '', ['incident', 'sos', 'main_courante'], true) ? $_GET['type'] : '';
$fStatut = in_array($_GET['statut'] ?? '', ['ouvert', 'en_cours', 'clos'], true) ? $_GET['statut'] : '';
$fSite = (int)($_GET['site'] ?? 0);
$focus = (int)($_GET['id'] ?? 0);
$qs = '?' . http_build_query(array_filter(['du' => $du, 'au' => $au, 'type' => $fType, 'statut' => $fStatut, 'site' => $fSite ?: null]));

$where = ['e.survenu_le BETWEEN ? AND ?'];
$params = [$du . ' 00:00:00', $au . ' 23:59:59'];
if ($focus) { $where = ['e.id = ?']; $params = [$focus]; }
if ($fType) { $where[] = 'e.type = ?'; $params[] = $fType; }
if ($fStatut) { $where[] = 'e.statut = ?'; $params[] = $fStatut; }
if ($fSite) { $where[] = 'e.mission_id = ?'; $params[] = $fSite; }
$st = $db->prepare("SELECT e.*, a.prenom, a.nom, a.telephone, m.nom AS site_nom
    FROM pt_evenements e JOIN agents a ON a.id = e.agent_id LEFT JOIN missions m ON m.id = e.mission_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY (e.type = 'sos' AND e.statut <> 'clos') DESC, e.survenu_le DESC LIMIT 500");
$st->execute($params);
$evs = $st->fetchAll();
$sites = $db->query("SELECT id, nom FROM missions ORDER BY actif DESC, nom")->fetchAll();
$types = ['incident' => 'Incident', 'sos' => 'SOS', 'main_courante' => 'Main courante'];
$statuts = ['ouvert' => ['Ouvert', 'ko'], 'en_cours' => ['En cours', 'wait'], 'clos' => ['Clos', 'ok']];

ptAdminHead('Événements', 'evenements');
?>
<div class="pta-title">
    <div><h1>Événements</h1><div class="text-muted small">SOS, incidents et main courante remontés par les agents</div></div>
    <?php if ($focus): ?><a class="btn btn-sm btn-outline-secondary" href="evenements.php">Tous les événements</a><?php endif; ?>
</div>

<?php if (!$focus): ?>
<form class="pta-card py-3 row g-2 align-items-end" method="get">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Du</label><input class="form-control form-control-sm" type="date" name="du" value="<?= h($du) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Au</label><input class="form-control form-control-sm" type="date" name="au" value="<?= h($au) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Type</label><select class="form-select form-select-sm" name="type"><option value="">Tous</option>
        <?php foreach ($types as $k => $l): ?><option value="<?= $k ?>" <?= $fType === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Statut</label><select class="form-select form-select-sm" name="statut"><option value="">Tous</option>
        <?php foreach ($statuts as $k => [$l]): ?><option value="<?= $k ?>" <?= $fStatut === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-8 col-md-3"><label class="form-label small mb-1">Site</label><select class="form-select form-select-sm" name="site"><option value="">Tous</option>
        <?php foreach ($sites as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $fSite === (int)$x['id'] ? 'selected' : '' ?>><?= h($x['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-4 col-md-1"><button class="btn btn-sm btn-pta w-100">Filtrer</button></div>
</form>
<?php endif; ?>

<?php if (!$evs): ?><div class="pta-card text-center text-muted">Aucun événement sur cette période.</div><?php endif; ?>

<?php foreach ($evs as $e):
    [$gLib, $gCls] = ptLibelleGravite($e['gravite']);
    [$sLib, $sCls] = $statuts[$e['statut']];
    $cat = $e['type'] === 'sos' ? 'SOS' : (ptCategories($e['type'])[$e['categorie']] ?? $e['categorie']);
    $photos = json_decode((string)$e['photos'], true) ?: [];
    $sosActif = $e['type'] === 'sos' && $e['statut'] !== 'clos';
?>
<div class="pta-card <?= $sosActif ? 'border-danger border-2' : '' ?>" id="ev<?= (int)$e['id'] ?>">
    <div class="d-flex flex-wrap gap-2 align-items-start justify-content-between">
        <div>
            <div class="mb-1">
                <?php if ($e['type'] === 'sos'): ?><span class="pta-badge ko"><i class="fa fa-bell"></i> SOS</span>
                <?php elseif ($e['type'] === 'main_courante'): ?><span class="pta-badge off"><i class="fa fa-book"></i> Main courante</span>
                <?php else: ?><span class="pta-badge <?= $gCls ?>"><i class="fa fa-triangle-exclamation"></i> <?= h($gLib) ?></span><?php endif; ?>
                <span class="pta-badge <?= $sCls ?>"><?= $sLib ?></span>
                <?php if (!$e['visible_client']): ?><span class="pta-badge off" title="Masqué au client"><i class="fa fa-eye-slash"></i></span><?php endif; ?>
            </div>
            <div class="fw-bold"><?= h($cat) ?> — <?= h($e['site_nom'] ?: 'Site non précisé') ?></div>
            <div class="small text-muted"><?= date('d/m/Y H:i', strtotime($e['survenu_le'])) ?> · <?= h($e['prenom'] . ' ' . $e['nom']) ?>
                <?php if ($e['telephone']): ?> · <a href="tel:<?= h(preg_replace('/[^\d+]/', '', $e['telephone'])) ?>"><i class="fa fa-phone"></i> <?= h($e['telephone']) ?></a><?php endif; ?>
                <?php if ($e['lat']): ?> · <a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= h($e['lat'] . ',' . $e['lng']) ?>"><i class="fa fa-map-pin"></i> position<?= $e['precision_m'] ? ' (±' . (int)$e['precision_m'] . ' m)' : '' ?></a><?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($e['description']): ?><div class="mt-2" style="white-space:pre-line"><?= h($e['description']) ?></div><?php endif; ?>
    <?php if ($photos): ?>
        <div class="d-flex gap-2 mt-2 flex-wrap">
        <?php foreach ($photos as $ph): ?>
            <a target="_blank" href="photo.php?f=<?= h(rawurlencode($ph)) ?>"><img src="photo.php?f=<?= h(rawurlencode($ph)) ?>" alt="" style="width:110px;height:110px;object-fit:cover;border-radius:10px"></a>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if (ptAdminCan('edit')): ?>
    <form method="post" class="row g-2 align-items-end mt-2 pt-2 border-top">
        <?= ptCsrfField() ?>
        <input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="retour" value="<?= h($focus ? '?id=' . $focus : $qs) ?>">
        <div class="col-md-6"><input class="form-control form-control-sm" name="commentaire" value="<?= h((string)$e['commentaire']) ?>" placeholder="Suite donnée (visible par le client si coché)"></div>
        <div class="col-6 col-md-2"><select class="form-select form-select-sm" name="statut">
            <?php foreach ($statuts as $k => [$l]): ?><option value="<?= $k ?>" <?= $e['statut'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-check small mb-0"><input class="form-check-input" type="checkbox" name="visible_client" value="1" <?= $e['visible_client'] ? 'checked' : '' ?>>
            <span class="form-check-label">Visible client</span></label></div>
        <div class="col-md-2"><button class="btn btn-sm btn-pta w-100">Enregistrer</button></div>
    </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php ptAdminFoot();
