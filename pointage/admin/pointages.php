<?php
/**
 * Pointages : contrôle, correction (tracée), ajout manuel, validation, export.
 * Les heures « réelles » de l'agent ne sont jamais modifiées : seules les heures
 * « retenues » changent, et chaque changement est journalisé avec son motif.
 */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/pointer.php';
$user = ptRequireAdmin('view');
$db = getDB();

function ptDtLocal(?string $dt): string { return $dt ? date('Y-m-d\TH:i', strtotime($dt)) : ''; }
function ptParseDtLocal(string $v): ?string {
    $v = trim($v);
    if ($v === '') return null;
    $t = strtotime($v);
    return $t ? date('Y-m-d H:i:00', $t) : null;
}

// ---------- Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $retour = 'admin/pointages.php' . (!empty($_POST['retour']) && strpos($_POST['retour'], '?') === 0 ? $_POST['retour'] : '');
    if (!ptCsrfCheck()) { ptFlash('danger', 'Session expirée, réessayez.'); ptRedirect($retour); }
    $action = (string)($_POST['action'] ?? '');
    $motif = trim((string)($_POST['motif'] ?? ''));

    if ($action === 'ajouter') {
        ptRequireAdmin('create');
        $agentId = (int)($_POST['agent_id'] ?? 0);
        $missionId = (int)($_POST['mission_id'] ?? 0);
        $debut = ptParseDtLocal((string)($_POST['debut'] ?? ''));
        $fin = ptParseDtLocal((string)($_POST['fin'] ?? ''));
        if (!$agentId || !$missionId || !$debut || ($fin && strtotime($fin) <= strtotime($debut)) || $motif === '') {
            ptFlash('danger', 'Ajout impossible : agent, site, début, fin postérieure au début et motif sont obligatoires.');
            ptRedirect($retour);
        }
        $db->prepare("INSERT INTO pt_services (agent_id, mission_id, debut_reel, debut_retenu, fin_reel, fin_retenu, statut, alertes, source, note, created_by_user)
            VALUES (?,?,?,?,NULL,?,?, 'manuel', 'manuel', ?, ?)")
           ->execute([$agentId, $missionId, $debut, $debut, $fin, $fin ? 'ok' : 'en_cours', mb_substr($motif, 0, 255), $user['id']]);
        $sid = (int)$db->lastInsertId();
        ptLogCorrection($sid, 'ajout_manuel', null, ['debut' => $debut, 'fin' => $fin, 'mission_id' => $missionId], $motif, $user);
        ptFlash('success', 'Service ajouté.');
        ptRedirect($retour);
    }

    $sid = (int)($_POST['service_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM pt_services WHERE id = ?");
    $st->execute([$sid]);
    $s = $st->fetch();
    if (!$s && $action !== 'valider_selection') { ptFlash('danger', 'Service introuvable.'); ptRedirect($retour); }

    if ($action === 'corriger') {
        ptRequireAdmin('edit');
        $debut = ptParseDtLocal((string)($_POST['debut'] ?? ''));
        $fin = ptParseDtLocal((string)($_POST['fin'] ?? ''));
        $missionId = (int)($_POST['mission_id'] ?? $s['mission_id']);
        if (!$debut || ($fin && strtotime($fin) <= strtotime($debut))) { ptFlash('danger', 'Heures invalides : la fin doit être après le début.'); ptRedirect($retour); }
        if ($motif === '') { ptFlash('danger', 'Le motif de la correction est obligatoire.'); ptRedirect($retour); }
        $avant = ['debut' => $s['debut_retenu'], 'fin' => $s['fin_retenu'], 'mission_id' => (int)$s['mission_id']];
        $apres = ['debut' => $debut, 'fin' => $fin, 'mission_id' => $missionId];
        if ($avant == $apres) { ptFlash('info', 'Aucun changement.'); ptRedirect($retour); }
        // fin_reel n'est jamais inventé : un service clôturé par le responsable (oubli de sortie)
        // garde fin_reel vide, preuve que l'agent n'a pas pointé sa sortie.
        $statut = $fin ? 'ok' : 'en_cours';
        $db->prepare("UPDATE pt_services SET debut_retenu = ?, fin_retenu = ?, mission_id = ?, statut = ?,
                valide_par = NULL, valide_le = NULL WHERE id = ?")
           ->execute([$debut, $fin, $missionId, $statut, $sid]);
        ptLogCorrection($sid, 'correction', $avant, $apres, $motif, $user);
        ptFlash('success', 'Correction enregistrée (l\'heure pointée par l\'agent reste visible dans l\'historique).');
        ptRedirect($retour);
    }
    if ($action === 'valider') {
        ptRequireAdmin('edit');
        if (!$s['fin_retenu']) { ptFlash('danger', 'Un service en cours ne peut pas être validé.'); ptRedirect($retour); }
        $db->prepare("UPDATE pt_services SET statut = 'valide', valide_par = ?, valide_le = NOW() WHERE id = ?")->execute([$user['id'], $sid]);
        ptLogCorrection($sid, 'validation', ['statut' => $s['statut']], ['statut' => 'valide'], $motif, $user);
        ptFlash('success', 'Service validé.');
        ptRedirect($retour);
    }
    if ($action === 'valider_selection') {
        ptRequireAdmin('edit');
        $ids = array_filter(array_map('intval', (array)($_POST['ids'] ?? [])));
        $n = 0;
        foreach ($ids as $i) {
            $up = $db->prepare("UPDATE pt_services SET statut = 'valide', valide_par = ?, valide_le = NOW()
                WHERE id = ? AND fin_retenu IS NOT NULL AND statut IN ('ok','a_verifier')");
            $up->execute([$user['id'], $i]);
            if ($up->rowCount()) { $n++; ptLogCorrection($i, 'validation', null, ['statut' => 'valide'], 'Validation groupée', $user); }
        }
        ptFlash('success', "$n service(s) validé(s).");
        ptRedirect($retour);
    }
    if ($action === 'annuler') {
        ptRequireAdmin('delete');
        if ($motif === '') { ptFlash('danger', 'Le motif de l\'annulation est obligatoire.'); ptRedirect($retour); }
        $db->prepare("UPDATE pt_services SET statut = 'annule' WHERE id = ?")->execute([$sid]);
        ptLogCorrection($sid, 'annulation', ['statut' => $s['statut']], ['statut' => 'annule'], $motif, $user);
        ptFlash('success', 'Service annulé (il reste consultable dans l\'historique).');
        ptRedirect($retour);
    }
    ptRedirect($retour);
}

// ---------- Filtres ----------
$du = $_GET['du'] ?? date('Y-m-d', strtotime('-6 days'));
$au = $_GET['au'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $du)) $du = date('Y-m-d', strtotime('-6 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $au)) $au = date('Y-m-d');
$fSite = (int)($_GET['site'] ?? 0);
$fAgent = (int)($_GET['agent'] ?? 0);
$fStatut = (string)($_GET['statut'] ?? '');
$qs = '?' . http_build_query(['du' => $du, 'au' => $au, 'site' => $fSite ?: null, 'agent' => $fAgent ?: null, 'statut' => $fStatut ?: null]);

$where = ["s.debut_retenu BETWEEN ? AND ?"];
$params = [$du . ' 00:00:00', $au . ' 23:59:59'];
if ($fSite) { $where[] = 's.mission_id = ?'; $params[] = $fSite; }
if ($fAgent) { $where[] = 's.agent_id = ?'; $params[] = $fAgent; }
if ($fStatut === 'annule') $where[] = "s.statut = 'annule'";
elseif ($fStatut !== '') { $where[] = 's.statut = ?'; $params[] = $fStatut; }
else $where[] = "s.statut <> 'annule'";

$st = $db->prepare("SELECT s.*, a.nom AS agent_nom, a.prenom AS agent_prenom, a.matricule, m.nom AS site_nom
    FROM pt_services s JOIN agents a ON a.id = s.agent_id JOIN missions m ON m.id = s.mission_id
    WHERE " . implode(' AND ', $where) . " ORDER BY s.debut_retenu DESC LIMIT 1000");
$st->execute($params);
$services = $st->fetchAll();

// Historique des corrections pour les services affichés
$histo = [];
if ($services) {
    $ids = implode(',', array_map('intval', array_column($services, 'id')));
    foreach ($db->query("SELECT * FROM pt_corrections WHERE service_id IN ($ids) ORDER BY created_at") as $c) $histo[(int)$c['service_id']][] = $c;
}

// Export CSV (heures retenues)
if (($_GET['export'] ?? '') === 'csv') {
    ptRequireAdmin('export');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pointages_' . $du . '_' . $au . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Matricule', 'Agent', 'Site', 'Date', 'Début retenu', 'Fin retenue', 'Durée (h)', 'Début pointé', 'Fin pointée', 'Statut', 'Alertes'], ';');
    foreach (array_reverse($services) as $s) {
        fputcsv($out, [$s['matricule'], $s['agent_nom'] . ' ' . $s['agent_prenom'], $s['site_nom'], date('d/m/Y', strtotime($s['debut_retenu'])),
            date('H:i', strtotime($s['debut_retenu'])), $s['fin_retenu'] ? date('d/m H:i', strtotime($s['fin_retenu'])) : '',
            number_format(ptDureeMin($s) / 60, 2, ',', ''), date('H:i', strtotime($s['debut_reel'])),
            $s['fin_reel'] ? date('d/m H:i', strtotime($s['fin_reel'])) : '', $s['statut'],
            implode(', ', array_map('ptLibelleAlerte', array_filter(explode(',', (string)$s['alertes']))))], ';');
    }
    exit;
}

// Synthèse par agent
$parAgent = [];
$nbVerif = 0;
foreach ($services as $s) {
    if ($s['statut'] === 'annule') continue;
    $k = (int)$s['agent_id'];
    $parAgent[$k] = $parAgent[$k] ?? ['nom' => $s['agent_nom'] . ' ' . $s['agent_prenom'], 'min' => 0, 'n' => 0, 'verif' => 0];
    $parAgent[$k]['min'] += ptDureeMin($s);
    $parAgent[$k]['n']++;
    if ($s['statut'] === 'a_verifier') { $parAgent[$k]['verif']++; $nbVerif++; }
}
uasort($parAgent, function ($x, $y) { return $y['min'] <=> $x['min']; });

$sites = $db->query("SELECT id, nom FROM missions ORDER BY actif DESC, nom")->fetchAll();
$agents = $db->query("SELECT id, nom, prenom FROM agents WHERE actif = 1 ORDER BY nom, prenom")->fetchAll();
$statuts = ['en_cours' => ['En cours', 'wait'], 'a_verifier' => ['À vérifier', 'ko'], 'ok' => ['Conforme', 'ok'], 'valide' => ['Validé', 'ok'], 'annule' => ['Annulé', 'off']];

ptAdminHead('Pointages', 'pointages');
?>
<div class="pta-title">
    <div>
        <h1>Pointages</h1>
        <div class="text-muted small"><?= count($services) ?> service(s) · <?= $nbVerif ?> à vérifier</div>
    </div>
    <div class="d-flex gap-2">
        <?php if (ptAdminCan('create')): ?>
        <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#mAjout"><i class="fa fa-plus me-1"></i>Ajouter un service</button>
        <?php endif; ?>
        <?php if (ptAdminCan('export')): ?>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h($qs . '&export=csv') ?>"><i class="fa fa-file-csv me-1"></i>Export</a>
        <?php endif; ?>
    </div>
</div>

<form class="pta-card py-3 row g-2 align-items-end" method="get">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Du</label><input class="form-control form-control-sm" type="date" name="du" value="<?= h($du) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Au</label><input class="form-control form-control-sm" type="date" name="au" value="<?= h($au) ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">Site</label>
        <select class="form-select form-select-sm" name="site"><option value="">Tous</option>
        <?php foreach ($sites as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $fSite === (int)$x['id'] ? 'selected' : '' ?>><?= h($x['nom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Agent</label>
        <select class="form-select form-select-sm" name="agent"><option value="">Tous</option>
        <?php foreach ($agents as $x): ?><option value="<?= (int)$x['id'] ?>" <?= $fAgent === (int)$x['id'] ? 'selected' : '' ?>><?= h($x['nom'] . ' ' . $x['prenom']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">Statut</label>
        <select class="form-select form-select-sm" name="statut"><option value="">Tous (hors annulés)</option>
        <?php foreach ($statuts as $k => [$l]): ?><option value="<?= $k ?>" <?= $fStatut === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-1"><button class="btn btn-sm btn-pta w-100">Filtrer</button></div>
</form>

<?php if ($parAgent): ?>
<div class="row g-3 mb-3">
    <?php foreach (array_slice($parAgent, 0, 8, true) as $aid => $p): ?>
    <div class="col-6 col-md-3">
        <a class="pta-kpi d-block text-decoration-none text-reset" href="<?= h('?' . http_build_query(['du' => $du, 'au' => $au, 'agent' => $aid])) ?>">
            <div class="v" style="font-size:1.35rem"><?= ptFormatDuree($p['min']) ?></div>
            <div class="l"><?= h($p['nom']) ?> · <?= $p['n'] ?> service(s)<?= $p['verif'] ? ' · <span class="text-danger">' . $p['verif'] . ' à vérifier</span>' : '' ?></div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" id="fSel">
<?= ptCsrfField() ?>
<input type="hidden" name="action" value="valider_selection">
<input type="hidden" name="retour" value="<?= h($qs) ?>">
<div class="pta-card p-0">
<div class="table-responsive">
<table class="pta-table">
    <thead><tr>
        <th class="ps-3" style="width:28px"><input type="checkbox" class="form-check-input" onclick="document.querySelectorAll('.sel').forEach(c=>c.checked=this.checked)"></th>
        <th>Agent</th><th>Site</th><th>Prise</th><th>Fin</th><th>Durée</th><th>Contrôles</th><th>Statut</th><th class="text-end pe-3"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($services as $s):
        $al = array_filter(explode(',', (string)$s['alertes']));
        [$stLib, $stCls] = $statuts[$s['statut']];
        $corrige = !empty($histo[(int)$s['id']]);
        $diffDebut = date('H:i', strtotime($s['debut_reel'])) !== date('H:i', strtotime($s['debut_retenu']));
        $diffFin = $s['fin_reel'] && $s['fin_retenu'] && date('Y-m-d H:i', strtotime($s['fin_reel'])) !== date('Y-m-d H:i', strtotime($s['fin_retenu']));
    ?>
        <tr class="<?= $s['statut'] === 'annule' ? 'opacity-50' : '' ?>">
            <td class="ps-3"><?php if (in_array($s['statut'], ['ok', 'a_verifier'], true)): ?><input type="checkbox" class="form-check-input sel" name="ids[]" value="<?= (int)$s['id'] ?>"><?php endif; ?></td>
            <td><div class="fw-semibold"><?= h($s['agent_nom'] . ' ' . $s['agent_prenom']) ?></div><div class="text-muted small"><?= h((string)$s['matricule']) ?></div></td>
            <td class="small"><?= h($s['site_nom']) ?></td>
            <td class="small text-nowrap">
                <strong><?= date('d/m H:i', strtotime($s['debut_retenu'])) ?></strong>
                <?php if ($diffDebut && $s['source'] === 'agent'): ?><div class="text-muted">pointé <?= date('H:i', strtotime($s['debut_reel'])) ?></div><?php endif; ?>
                <?php if ($s['prevu_debut']): ?><div class="text-muted">prévu <?= date('H:i', strtotime($s['prevu_debut'])) ?></div><?php endif; ?>
            </td>
            <td class="small text-nowrap">
                <?php if ($s['fin_retenu']): ?>
                    <strong><?= date('d/m H:i', strtotime($s['fin_retenu'])) ?></strong>
                    <?php if ($diffFin && $s['source'] === 'agent'): ?><div class="text-muted">pointé <?= date('H:i', strtotime($s['fin_reel'])) ?></div><?php endif; ?>
                <?php else: ?><span class="pta-badge wait">En cours</span><?php endif; ?>
            </td>
            <td class="fw-bold text-nowrap"><?= $s['fin_retenu'] ? ptFormatDuree(ptDureeMin($s)) : '—' ?></td>
            <td class="small">
                <?php foreach ($al as $x): ?><span class="pta-badge <?= in_array($x, ['hors_ligne', 'manuel', 'avance'], true) ? 'off' : 'ko' ?> me-1 mb-1"><?= h(ptLibelleAlerte($x)) ?></span><?php endforeach; ?>
                <?php if ($s['debut_distance_m'] !== null): ?><span class="text-muted d-block">à <?= ptFormatDistance((int)$s['debut_distance_m']) ?> du site</span><?php endif; ?>
                <?php if ($s['debut_lat']): ?><a target="_blank" rel="noopener" href="https://www.google.com/maps?q=<?= h($s['debut_lat'] . ',' . $s['debut_lng']) ?>"><i class="fa fa-map-pin"></i> position</a><?php endif; ?>
                <?php if ($s['debut_photo']): ?> <a target="_blank" href="photo.php?f=<?= h(rawurlencode($s['debut_photo'])) ?>"><i class="fa fa-camera"></i> prise</a><?php endif; ?>
                <?php if ($s['fin_photo']): ?> <a target="_blank" href="photo.php?f=<?= h(rawurlencode($s['fin_photo'])) ?>"><i class="fa fa-camera"></i> fin</a><?php endif; ?>
                <?php if ($corrige): ?> <a href="#" data-bs-toggle="collapse" data-bs-target="#h<?= (int)$s['id'] ?>"><i class="fa fa-clock-rotate-left"></i> historique</a><?php endif; ?>
            </td>
            <td><span class="pta-badge <?= $stCls ?>"><?= $stLib ?></span></td>
            <td class="text-end pe-3 text-nowrap">
                <?php if ($s['statut'] !== 'annule' && ptAdminCan('edit')): ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary" title="Corriger" data-bs-toggle="modal" data-bs-target="#mCorr"
                        data-id="<?= (int)$s['id'] ?>" data-debut="<?= h(ptDtLocal($s['debut_retenu'])) ?>" data-fin="<?= h(ptDtLocal($s['fin_retenu'])) ?>"
                        data-mission="<?= (int)$s['mission_id'] ?>" data-nom="<?= h($s['agent_prenom'] . ' ' . $s['agent_nom']) ?>"><i class="fa fa-pen"></i></button>
                    <?php if ($s['fin_retenu'] && $s['statut'] !== 'valide'): ?>
                    <button type="submit" form="fVal<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-success" title="Valider"><i class="fa fa-check"></i></button>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($corrige): ?>
        <tr class="collapse" id="h<?= (int)$s['id'] ?>"><td colspan="9" class="ps-5 small bg-light">
            <div class="text-muted mb-1">Pointé par l'agent : <?= date('d/m H:i', strtotime($s['debut_reel'])) ?> → <?= $s['fin_reel'] ? date('d/m H:i', strtotime($s['fin_reel'])) : '—' ?></div>
            <?php foreach ($histo[(int)$s['id']] as $c):
                $av = json_decode((string)$c['avant'], true) ?: []; $ap = json_decode((string)$c['apres'], true) ?: []; ?>
                <div><strong><?= date('d/m/Y H:i', strtotime($c['created_at'])) ?></strong> · <?= h((string)$c['user_nom']) ?> ·
                    <?= h(str_replace('_', ' ', $c['action'])) ?>
                    <?php if (isset($ap['debut'])): ?> : <?= isset($av['debut']) ? h(date('d/m H:i', strtotime($av['debut']))) . ' → ' : '' ?><?= h(date('d/m H:i', strtotime($ap['debut']))) ?>
                        / <?= isset($av['fin']) && $av['fin'] ? h(date('d/m H:i', strtotime($av['fin']))) . ' → ' : '' ?><?= !empty($ap['fin']) ? h(date('d/m H:i', strtotime($ap['fin']))) : 'en cours' ?><?php endif; ?>
                    <?php if ($c['motif']): ?> — « <?= h($c['motif']) ?> »<?php endif; ?></div>
            <?php endforeach; ?>
        </td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$services): ?><tr><td colspan="9" class="text-center text-muted py-4">Aucun pointage sur cette période.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>
<?php if ($services && ptAdminCan('edit')): ?>
<button class="btn btn-sm btn-outline-success"><i class="fa fa-check-double me-1"></i>Valider la sélection</button>
<?php endif; ?>
</form>

<?php foreach ($services as $s): if ($s['fin_retenu'] && $s['statut'] !== 'valide' && $s['statut'] !== 'annule'): ?>
<form method="post" id="fVal<?= (int)$s['id'] ?>" class="d-none"><?= ptCsrfField() ?>
    <input type="hidden" name="action" value="valider"><input type="hidden" name="service_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="retour" value="<?= h($qs) ?>"></form>
<?php endif; endforeach; ?>

<!-- Correction -->
<div class="modal fade" id="mCorr" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= ptCsrfField() ?>
    <input type="hidden" name="service_id" id="cId"><input type="hidden" name="retour" value="<?= h($qs) ?>">
    <div class="modal-header"><h5 class="modal-title">Corriger — <span id="cNom"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <label class="form-label small fw-semibold">Site</label>
        <select class="form-select mb-2" name="mission_id" id="cMission"><?php foreach ($sites as $x): ?><option value="<?= (int)$x['id'] ?>"><?= h($x['nom']) ?></option><?php endforeach; ?></select>
        <div class="row g-2">
            <div class="col-6"><label class="form-label small fw-semibold">Début retenu</label><input class="form-control" type="datetime-local" name="debut" id="cDebut" required></div>
            <div class="col-6"><label class="form-label small fw-semibold">Fin retenue</label><input class="form-control" type="datetime-local" name="fin" id="cFin"></div>
        </div>
        <div class="small text-muted mt-1">Laisser la fin vide pour garder le service « en cours ». Pour un oubli de sortie, saisissez l'heure de fin réelle.</div>
        <label class="form-label small fw-semibold mt-3">Motif *</label>
        <input class="form-control" name="motif" required maxlength="255" placeholder="Ex. oubli de pointage confirmé par le client">
    </div>
    <div class="modal-footer justify-content-between">
        <?php if (ptAdminCan('delete')): ?>
        <button class="btn btn-outline-danger btn-sm" name="action" value="annuler" onclick="return confirm('Annuler ce service ? Il restera visible dans l\'historique.')"><i class="fa fa-ban me-1"></i>Annuler le service</button>
        <?php endif; ?>
        <button class="btn btn-pta" name="action" value="corriger"><i class="fa fa-check me-1"></i>Enregistrer</button>
    </div>
</form></div></div>

<!-- Ajout manuel -->
<div class="modal fade" id="mAjout" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content">
    <?= ptCsrfField() ?>
    <input type="hidden" name="action" value="ajouter"><input type="hidden" name="retour" value="<?= h($qs) ?>">
    <div class="modal-header"><h5 class="modal-title">Ajouter un service</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <label class="form-label small fw-semibold">Agent</label>
        <select class="form-select mb-2" name="agent_id" required><option value="">—</option><?php foreach ($agents as $x): ?><option value="<?= (int)$x['id'] ?>"><?= h($x['nom'] . ' ' . $x['prenom']) ?></option><?php endforeach; ?></select>
        <label class="form-label small fw-semibold">Site</label>
        <select class="form-select mb-2" name="mission_id" required><option value="">—</option><?php foreach ($sites as $x): ?><option value="<?= (int)$x['id'] ?>"><?= h($x['nom']) ?></option><?php endforeach; ?></select>
        <div class="row g-2">
            <div class="col-6"><label class="form-label small fw-semibold">Début</label><input class="form-control" type="datetime-local" name="debut" required></div>
            <div class="col-6"><label class="form-label small fw-semibold">Fin</label><input class="form-control" type="datetime-local" name="fin"></div>
        </div>
        <label class="form-label small fw-semibold mt-3">Motif *</label>
        <input class="form-control" name="motif" required maxlength="255" placeholder="Ex. téléphone de l'agent en panne">
    </div>
    <div class="modal-footer"><button class="btn btn-pta"><i class="fa fa-plus me-1"></i>Ajouter</button></div>
</form></div></div>

<?php
$scripts = <<<'HTML'
<script>
document.getElementById('mCorr').addEventListener('show.bs.modal', function (e) {
    var b = e.relatedTarget;
    document.getElementById('cId').value = b.dataset.id;
    document.getElementById('cNom').textContent = b.dataset.nom;
    document.getElementById('cDebut').value = b.dataset.debut;
    document.getElementById('cFin').value = b.dataset.fin;
    document.getElementById('cMission').value = b.dataset.mission;
});
</script>
HTML;
ptAdminFoot($scripts);
