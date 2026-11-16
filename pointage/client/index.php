<?php
/** Portail client : en direct, heures, main courante, rapport signé. */
require_once __DIR__ . '/_init.php';
$p = ptPortail();
$db = getDB();
$t = $p['token'];
$vue = in_array($_GET['v'] ?? '', ['direct', 'heures', 'evenements', 'rapport'], true) ? $_GET['v'] : 'direct';
if ($vue === 'heures' && !$p['voir_heures']) $vue = 'direct';
if ($vue === 'evenements' && !$p['voir_evenements']) $vue = 'direct';
$in = ptInMissions($p);
$sites = $db->query("SELECT id, nom, pt_adresse, lieu FROM missions WHERE id IN ($in) ORDER BY nom")->fetchAll();

$du = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : date('Y-m-01');
$au = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : date('Y-m-d');
if ($au < $du) $au = $du;

function ptClPeriode(string $t, string $vue, string $du, string $au): void { ?>
<form class="cl-card no-print d-flex flex-wrap gap-2 align-items-end" method="get">
    <input type="hidden" name="t" value="<?= h($t) ?>"><input type="hidden" name="v" value="<?= h($vue) ?>">
    <div><label class="form-label small mb-1">Du</label><input class="form-control form-control-sm" type="date" name="du" value="<?= h($du) ?>"></div>
    <div><label class="form-label small mb-1">Au</label><input class="form-control form-control-sm" type="date" name="au" value="<?= h($au) ?>"></div>
    <button class="btn btn-sm btn-dark">Afficher</button>
    <span class="small ms-2">
        <a href="?t=<?= h($t) ?>&v=<?= $vue ?>&du=<?= date('Y-m-d', strtotime('monday last week')) ?>&au=<?= date('Y-m-d', strtotime('sunday last week')) ?>">Semaine dernière</a> ·
        <a href="?t=<?= h($t) ?>&v=<?= $vue ?>&du=<?= date('Y-m-01') ?>&au=<?= date('Y-m-d') ?>">Ce mois</a> ·
        <a href="?t=<?= h($t) ?>&v=<?= $vue ?>&du=<?= date('Y-m-01', strtotime('first day of last month')) ?>&au=<?= date('Y-m-t', strtotime('last day of last month')) ?>">Mois dernier</a>
    </span>
</form>
<?php }

ptClientHead($p, ['direct' => 'En direct', 'heures' => 'Heures', 'evenements' => 'Main courante', 'rapport' => 'Rapport'][$vue], $vue);

/* ===================== EN DIRECT ===================== */
if ($vue === 'direct'):
    $enPoste = $db->query("SELECT s.mission_id, s.debut_reel, a.prenom, a.nom FROM pt_services s JOIN agents a ON a.id = s.agent_id
        WHERE s.mission_id IN ($in) AND s.fin_retenu IS NULL AND s.statut <> 'annule' ORDER BY s.debut_reel")->fetchAll();
    $parSite = [];
    foreach ($enPoste as $e) $parSite[(int)$e['mission_id']][] = $e;
    $jour = $db->query("SELECT COUNT(*) n, COALESCE(SUM(TIMESTAMPDIFF(MINUTE, debut_retenu, fin_retenu)),0) m FROM pt_services
        WHERE mission_id IN ($in) AND statut IN ('ok','valide','a_verifier') AND fin_retenu IS NOT NULL AND debut_retenu >= CURDATE()")->fetch();
    $inc = $p['voir_evenements'] ? (int)$db->query("SELECT COUNT(*) FROM pt_evenements WHERE mission_id IN ($in) AND visible_client = 1
        AND type = 'incident' AND survenu_le >= CURDATE()")->fetchColumn() : 0;
?>
    <div class="row g-3 mb-2">
        <div class="col-6 col-md-4"><div class="cl-card"><div class="cl-kpi"><?= count($enPoste) ?></div><div class="small text-muted"><span class="cl-dot me-1"></span>agent(s) en poste maintenant</div></div></div>
        <div class="col-6 col-md-4"><div class="cl-card"><div class="cl-kpi"><?= ptFormatDuree((int)$jour['m']) ?></div><div class="small text-muted">heures réalisées aujourd'hui (services terminés)</div></div></div>
        <?php if ($p['voir_evenements']): ?>
        <div class="col-12 col-md-4"><div class="cl-card"><div class="cl-kpi"><?= $inc ?></div><div class="small text-muted">incident(s) signalé(s) aujourd'hui</div></div></div>
        <?php endif; ?>
    </div>
    <?php foreach ($sites as $s): $liste = $parSite[(int)$s['id']] ?? []; ?>
    <div class="cl-card">
        <div class="d-flex justify-content-between">
            <div><div class="fw-bold"><?= h($s['nom']) ?></div><div class="small text-muted"><?= h((string)($s['pt_adresse'] ?: $s['lieu'])) ?></div></div>
            <span class="badge <?= $liste ? 'bg-success' : 'bg-secondary' ?> align-self-start"><?= $liste ? count($liste) . ' en poste' : 'Aucun agent en poste' ?></span>
        </div>
        <?php foreach ($liste as $e): ?>
            <div class="border-top mt-2 pt-2 small"><span class="cl-dot me-2"></span><strong><?= h(ptNomCourt($e['prenom'], $e['nom'])) ?></strong>
                — prise de poste à <?= date('H\hi', strtotime($e['debut_reel'])) ?></div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="small text-muted">Mise à jour automatique toutes les minutes · <?= date('d/m/Y H:i') ?></div>
    <script>setTimeout(function () { location.reload(); }, 60000);</script>

<?php
/* ===================== HEURES ===================== */
elseif ($vue === 'heures'):
    ptClPeriode($t, $vue, $du, $au);
    $services = ptServicesPortail($p, $du, $au);
    $parAgent = []; $total = 0;
    foreach ($services as $s) {
        $m = ptDureeMin($s); $total += $m;
        $k = ptNomCourt($s['prenom'], $s['nom']);
        $parAgent[$k] = ($parAgent[$k] ?? 0) + $m;
    }
    arsort($parAgent);
?>
    <div class="row g-3">
        <div class="col-md-4"><div class="cl-card"><div class="cl-kpi"><?= ptFormatDuree($total) ?></div><div class="small text-muted"><?= count($services) ?> service(s) du <?= date('d/m', strtotime($du)) ?> au <?= date('d/m/Y', strtotime($au)) ?></div>
            <hr><?php foreach ($parAgent as $n => $m): ?><div class="d-flex justify-content-between small py-1"><span><?= h($n) ?></span><strong><?= ptFormatDuree($m) ?></strong></div><?php endforeach; ?>
        </div></div>
        <div class="col-md-8"><div class="cl-card p-0"><div class="table-responsive">
            <table class="table table-sm mb-0 align-middle">
                <thead class="small text-muted"><tr><th class="ps-3">Date</th><th>Agent</th><th>Site</th><th>Horaires</th><th class="text-end pe-3">Durée</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($services) as $s): ?>
                    <tr class="small"><td class="ps-3"><?= date('d/m', strtotime($s['debut_retenu'])) ?></td><td><?= h(ptNomCourt($s['prenom'], $s['nom'])) ?></td><td><?= h($s['site_nom']) ?></td>
                        <td><?= date('H\hi', strtotime($s['debut_retenu'])) ?> → <?= date('H\hi', strtotime($s['fin_retenu'])) ?></td>
                        <td class="text-end pe-3 fw-semibold"><?= ptFormatDuree(ptDureeMin($s)) ?> <?= $s['statut'] === 'valide' ? '<i class="fa fa-circle-check text-success" title="Validé"></i>' : '' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$services): ?><tr><td colspan="5" class="text-center text-muted py-4">Aucun service sur cette période.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div></div>
    </div>

<?php
/* ===================== MAIN COURANTE ===================== */
elseif ($vue === 'evenements'):
    ptClPeriode($t, $vue, $du, $au);
    $st = $db->prepare("SELECT e.*, a.prenom, a.nom, m.nom AS site_nom FROM pt_evenements e JOIN agents a ON a.id = e.agent_id
        LEFT JOIN missions m ON m.id = e.mission_id
        WHERE e.mission_id IN ($in) AND e.visible_client = 1 AND e.survenu_le BETWEEN ? AND ? ORDER BY e.survenu_le DESC LIMIT 300");
    $st->execute([$du . ' 00:00:00', $au . ' 23:59:59']);
    $evs = $st->fetchAll();
    if (!$evs): ?><div class="cl-card text-muted">Aucune entrée sur cette période.</div><?php endif;
    foreach ($evs as $e):
        $cat = $e['type'] === 'sos' ? 'Alerte agent' : (ptCategories($e['type'])[$e['categorie']] ?? $e['categorie']);
        $photos = $p['voir_photos'] ? (json_decode((string)$e['photos'], true) ?: []) : [];
        [$gLib] = ptLibelleGravite($e['gravite']);
?>
    <div class="cl-card">
        <div class="small text-muted"><?= date('d/m/Y H:i', strtotime($e['survenu_le'])) ?> · <?= h($e['site_nom'] ?: '') ?> · <?= h(ptNomCourt($e['prenom'], $e['nom'])) ?></div>
        <div class="fw-bold"><?= $e['type'] === 'main_courante' ? '<i class="fa fa-book text-secondary me-1"></i>' : '<i class="fa fa-triangle-exclamation text-warning me-1"></i>' ?><?= h($cat) ?>
            <?= $e['type'] === 'incident' ? '<span class="badge bg-light text-dark border ms-1">' . h($gLib) . '</span>' : '' ?></div>
        <?php if ($e['description']): ?><div class="mt-1" style="white-space:pre-line"><?= h($e['description']) ?></div><?php endif; ?>
        <?php if ($photos): ?><div class="d-flex gap-2 mt-2 flex-wrap"><?php foreach ($photos as $ph): ?>
            <a target="_blank" href="photo.php?t=<?= h($t) ?>&f=<?= h(rawurlencode($ph)) ?>"><img src="photo.php?t=<?= h($t) ?>&f=<?= h(rawurlencode($ph)) ?>" style="width:96px;height:96px;object-fit:cover;border-radius:8px" alt=""></a>
        <?php endforeach; ?></div><?php endif; ?>
        <?php if ($e['commentaire']): ?><div class="mt-2 small p-2 bg-light rounded"><strong>Suite donnée :</strong> <?= h($e['commentaire']) ?></div><?php endif; ?>
    </div>
<?php endforeach;

/* ===================== RAPPORT ===================== */
else:
    $services = ptServicesPortail($p, $du, $au);
    $total = 0; $parAgent = [];
    foreach ($services as $s) { $m = ptDureeMin($s); $total += $m; $k = ptNomCourt($s['prenom'], $s['nom']); $parAgent[$k] = ($parAgent[$k] ?? 0) + $m; }
    $nbInc = (int)$db->query("SELECT COUNT(*) FROM pt_evenements WHERE mission_id IN ($in) AND visible_client = 1 AND type IN ('incident','sos')
        AND survenu_le BETWEEN " . $db->quote($du . ' 00:00:00') . " AND " . $db->quote($au . ' 23:59:59'))->fetchColumn();
    $st = $db->prepare("SELECT * FROM pt_signatures WHERE portail_id = ? AND periode_du = ? AND periode_au = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$p['id'], $du, $au]);
    $sig = $st->fetch();
    ptClPeriode($t, $vue, $du, $au);
?>
    <div class="cl-card">
        <div class="d-flex justify-content-between flex-wrap gap-2">
            <div><div class="cl-brand" style="color:#a8883c"><?= h(mb_strtoupper(ptMarque())) ?></div>
                <h1 class="h4 mt-1 mb-0">Rapport de prestation</h1>
                <div class="text-muted"><?= h($p['libelle']) ?><?= $p['client_nom'] ? ' · ' . h($p['client_nom']) : '' ?></div></div>
            <div class="text-end"><div class="fw-semibold">Du <?= date('d/m/Y', strtotime($du)) ?> au <?= date('d/m/Y', strtotime($au)) ?></div>
                <div class="small text-muted">Édité le <?= date('d/m/Y à H:i') ?></div>
                <button class="btn btn-sm btn-dark mt-2 no-print" type="button" data-bs-toggle="collapse" data-bs-target="#pdfOpts" onclick="document.getElementById('pdfOpts').classList.toggle('show')"><i class="fa fa-file-pdf me-1"></i>Télécharger le PDF</button>
                <button class="btn btn-sm btn-outline-dark mt-2 no-print" onclick="window.print()"><i class="fa fa-print me-1"></i>Imprimer</button></div>
        </div>
        <form class="collapse no-print border rounded p-3 mt-3" id="pdfOpts" method="get" action="rapport-pdf.php">
            <input type="hidden" name="t" value="<?= h($t) ?>"><input type="hidden" name="du" value="<?= h($du) ?>"><input type="hidden" name="au" value="<?= h($au) ?>">
            <div class="small fw-semibold mb-2">Que voulez-vous dans le PDF ?</div>
            <div class="d-flex flex-wrap gap-3">
            <?php require_once __DIR__ . '/../includes/pdf.php';
            $dispo = ptSectionsDemandees($p, null);
            foreach (ptSectionsRapport() as $k => $l): if (!in_array($k, $dispo, true)) continue; ?>
                <label class="form-check small"><input class="form-check-input" type="checkbox" name="s[]" value="<?= $k ?>" checked><span class="form-check-label"><?= h($l) ?></span></label>
            <?php endforeach; ?>
            </div>
            <button class="btn btn-sm btn-dark mt-2"><i class="fa fa-download me-1"></i>Générer le PDF</button>
        </form>
        <hr>
        <div class="row text-center g-2 mb-3">
            <div class="col-4"><div class="cl-kpi"><?= ptFormatDuree($total) ?></div><div class="small text-muted">heures réalisées</div></div>
            <div class="col-4"><div class="cl-kpi"><?= count($services) ?></div><div class="small text-muted">vacations</div></div>
            <div class="col-4"><div class="cl-kpi"><?= $nbInc ?></div><div class="small text-muted">incident(s)</div></div>
        </div>
        <div class="small text-muted mb-1">Sites : <?= h(implode(', ', array_column($sites, 'nom'))) ?></div>
        <table class="table table-sm small">
            <thead><tr><th>Agent</th><th class="text-end">Heures</th></tr></thead>
            <tbody><?php foreach ($parAgent as $n => $m): ?><tr><td><?= h($n) ?></td><td class="text-end"><?= ptFormatDuree($m) ?></td></tr><?php endforeach; ?></tbody>
        </table>
        <table class="table table-sm small">
            <thead><tr><th>Date</th><th>Agent</th><th>Site</th><th>Horaires</th><th class="text-end">Durée</th></tr></thead>
            <tbody><?php foreach ($services as $s): ?>
                <tr><td><?= date('d/m', strtotime($s['debut_retenu'])) ?></td><td><?= h(ptNomCourt($s['prenom'], $s['nom'])) ?></td><td><?= h($s['site_nom']) ?></td>
                    <td><?= date('H\hi', strtotime($s['debut_retenu'])) ?> → <?= date('H\hi', strtotime($s['fin_retenu'])) ?></td><td class="text-end"><?= ptFormatDuree(ptDureeMin($s)) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$services): ?><tr><td colspan="5" class="text-center text-muted">Aucune vacation sur cette période.</td></tr><?php endif; ?></tbody>
        </table>

        <h2 class="h6 mt-4">Validation du client</h2>
        <?php if ($sig): ?>
            <div class="border rounded p-3 d-flex gap-3 align-items-center flex-wrap">
                <img src="<?= h($sig['signature']) ?>" alt="Signature" style="max-height:90px;background:#fff">
                <div class="small">Validé par <strong><?= h($sig['nom']) ?></strong><?= $sig['fonction'] ? ', ' . h($sig['fonction']) : '' ?><br>
                    le <?= date('d/m/Y à H:i', strtotime($sig['created_at'])) ?> — <?= ptFormatDuree((int)$sig['total_min']) ?> attestées</div>
            </div>
        <?php else: ?>
            <form method="post" action="signer.php" class="no-print" id="fSign">
                <input type="hidden" name="t" value="<?= h($t) ?>"><input type="hidden" name="du" value="<?= h($du) ?>"><input type="hidden" name="au" value="<?= h($au) ?>">
                <input type="hidden" name="signature" id="sigData">
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><input class="form-control" name="nom" required placeholder="Nom et prénom du signataire"></div>
                    <div class="col-md-6"><input class="form-control" name="fonction" placeholder="Fonction (ex. responsable sécurité)"></div>
                </div>
                <div class="small text-muted mb-1">Signez dans le cadre ci-dessous (doigt ou souris) :</div>
                <canvas id="sigPad" width="600" height="180" style="width:100%;max-width:600px;height:180px;border:1px dashed #aab;border-radius:10px;background:#fff;touch-action:none"></canvas>
                <div class="d-flex gap-2 mt-2 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="sigClear">Effacer</button>
                    <label class="form-check small align-self-center"><input class="form-check-input" type="checkbox" required>
                        <span class="form-check-label">J'atteste l'exactitude des <?= ptFormatDuree($total) ?> de prestation de cette période.</span></label>
                    <button class="btn btn-dark ms-auto"><i class="fa fa-signature me-1"></i>Signer le rapport</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
    <script>
    (function () {
        var c = document.getElementById('sigPad'); if (!c) return;
        var ctx = c.getContext('2d'), drawing = false, vide = true;
        ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.strokeStyle = '#111';
        function pos(e) { var r = c.getBoundingClientRect(); return [(e.clientX - r.left) * c.width / r.width, (e.clientY - r.top) * c.height / r.height]; }
        c.addEventListener('pointerdown', function (e) { drawing = true; vide = false; var p = pos(e); ctx.beginPath(); ctx.moveTo(p[0], p[1]); c.setPointerCapture(e.pointerId); });
        c.addEventListener('pointermove', function (e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p[0], p[1]); ctx.stroke(); });
        c.addEventListener('pointerup', function () { drawing = false; });
        document.getElementById('sigClear').onclick = function () { ctx.clearRect(0, 0, c.width, c.height); vide = true; };
        document.getElementById('fSign').addEventListener('submit', function (e) {
            if (vide) { e.preventDefault(); alert('Merci de signer dans le cadre.'); return; }
            document.getElementById('sigData').value = c.toDataURL('image/png');
        });
    })();
    </script>
<?php endif;
ptClientFoot();
