<?php
/**
 * Génération PDF (Dompdf embarqué dans pointage/vendor) : rapport client et
 * rapport d'heures interne.
 */

function ptDompdf(): \Dompdf\Dompdf {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $cache = ptUploadsDir() . '/pdf-cache';
    if (!is_dir($cache)) @mkdir($cache, 0755, true);
    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');
    $opt->set('isRemoteEnabled', false);   // aucune ressource externe : images en data: uniquement
    $opt->set('fontCache', $cache);
    $opt->set('tempDir', $cache);
    $opt->set('chroot', dirname(__DIR__));
    return new \Dompdf\Dompdf($opt);
}

function ptPdfDepuisHtml(string $html, string $orientation = 'portrait'): string {
    $d = ptDompdf();
    $d->loadHtml($html, 'UTF-8');
    $d->setPaper('A4', $orientation);
    $d->render();
    $canvas = $d->getCanvas();
    $canvas->page_text(520, 815, 'Page {PAGE_NUM}/{PAGE_COUNT}', null, 7, [0.45, 0.45, 0.45]);
    return $d->output();
}

function ptPdfEnvoyer(string $pdf, string $nomFichier, bool $telecharger = true): void {
    header('Content-Type: application/pdf');
    header('Content-Disposition: ' . ($telecharger ? 'attachment' : 'inline') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $nomFichier) . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

function ptPdfStyle(): string {
    return '<style>
        @page { margin: 18mm 14mm 16mm 14mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9pt; color: #1a2332; }
        h1 { font-size: 16pt; margin: 2mm 0 1mm; }
        h2 { font-size: 11pt; margin: 6mm 0 2mm; color: #1a2332; border-bottom: 1.5px solid #c9a84c; padding-bottom: 1mm; }
        .marque { color: #a8883c; font-weight: bold; letter-spacing: 1.5px; font-size: 10pt; }
        .muted { color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 7.5pt; text-transform: uppercase; color: #6b7280; border-bottom: 1px solid #d1d5db; padding: 1.5mm 1mm; }
        td { padding: 1.4mm 1mm; border-bottom: 0.5px solid #eceef2; vertical-align: top; }
        .r { text-align: right; }
        .kpis td { border: 0; text-align: center; padding: 3mm; background: #f5f6f8; }
        .kpis .v { font-size: 15pt; font-weight: bold; }
        .sig { border: 1px solid #d1d5db; padding: 3mm; margin-top: 2mm; }
        .ent td { border: 0; padding: 0; }
    </style>';
}

/** Rapport client d'une période (mêmes données que le portail). */
function ptRapportClientPdf(array $p, string $du, string $au): string {
    $db = getDB();
    $in = implode(',', array_map('intval', $p['mission_ids']));
    $sites = $db->query("SELECT id, nom FROM missions WHERE id IN ($in) ORDER BY nom")->fetchAll();
    $st = $db->prepare("SELECT s.*, a.prenom, a.nom, m.nom AS site_nom FROM pt_services s JOIN agents a ON a.id = s.agent_id JOIN missions m ON m.id = s.mission_id
        WHERE s.mission_id IN ($in) AND s.statut IN ('ok','valide','a_verifier') AND s.fin_retenu IS NOT NULL AND s.debut_retenu BETWEEN ? AND ? ORDER BY s.debut_retenu");
    $st->execute([$du . ' 00:00:00', $au . ' 23:59:59']);
    $services = $st->fetchAll();
    $total = 0; $parAgent = [];
    foreach ($services as $s) { $m = ptDureeMin($s); $total += $m; $k = ptNomCourt($s['prenom'], $s['nom']); $parAgent[$k] = ($parAgent[$k] ?? 0) + $m; }
    arsort($parAgent);

    $st = $db->prepare("SELECT pt.nom AS point_nom, m.nom AS site_nom, COUNT(pa.id) n, MIN(pa.scanne_le) premier, MAX(pa.scanne_le) dernier
        FROM pt_points pt JOIN missions m ON m.id = pt.mission_id
        LEFT JOIN pt_passages pa ON pa.point_id = pt.id AND pa.scanne_le BETWEEN ? AND ?
        WHERE pt.mission_id IN ($in) AND pt.actif = 1 GROUP BY pt.id ORDER BY m.nom, pt.ordre, pt.nom");
    $st->execute([$du . ' 00:00:00', $au . ' 23:59:59']);
    $rondes = $st->fetchAll();

    $evs = [];
    if ($p['voir_evenements']) {
        $st = $db->prepare("SELECT e.*, a.prenom, a.nom, m.nom AS site_nom FROM pt_evenements e JOIN agents a ON a.id = e.agent_id LEFT JOIN missions m ON m.id = e.mission_id
            WHERE e.mission_id IN ($in) AND e.visible_client = 1 AND e.type IN ('incident','sos') AND e.survenu_le BETWEEN ? AND ? ORDER BY e.survenu_le");
        $st->execute([$du . ' 00:00:00', $au . ' 23:59:59']);
        $evs = $st->fetchAll();
    }
    $st = $db->prepare("SELECT * FROM pt_signatures WHERE portail_id = ? AND periode_du = ? AND periode_au = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$p['id'], $du, $au]);
    $sig = $st->fetch();

    ob_start(); ?>
<html><head><meta charset="UTF-8"><?= ptPdfStyle() ?></head><body>
<table class="ent"><tr>
    <td><div class="marque"><?= h(mb_strtoupper(ptMarque())) ?></div><h1>Rapport de prestation</h1>
        <div><?= h($p['libelle']) ?><?= !empty($p['client_nom']) ? ' · ' . h($p['client_nom']) : '' ?></div></td>
    <td class="r"><strong>Du <?= date('d/m/Y', strtotime($du)) ?> au <?= date('d/m/Y', strtotime($au)) ?></strong><br>
        <span class="muted">Édité le <?= date('d/m/Y à H:i') ?></span></td>
</tr></table>
<div class="muted" style="margin-top:2mm">Sites : <?= h(implode(', ', array_column($sites, 'nom'))) ?></div>

<table class="kpis" style="margin-top:4mm"><tr>
    <td><div class="v"><?= ptFormatDuree($total) ?></div>heures réalisées</td>
    <td><div class="v"><?= count($services) ?></div>vacations</td>
    <td><div class="v"><?= array_sum(array_column($rondes, 'n')) ?></div>passages de ronde</td>
    <td><div class="v"><?= count($evs) ?></div>incident(s)</td>
</tr></table>

<?php if ($p['voir_heures']): ?>
<h2>Heures par agent</h2>
<table><tr><th>Agent</th><th class="r">Heures</th></tr>
<?php foreach ($parAgent as $n => $m): ?><tr><td><?= h($n) ?></td><td class="r"><?= ptFormatDuree($m) ?></td></tr><?php endforeach; ?>
</table>
<h2>Détail des vacations</h2>
<table><tr><th>Date</th><th>Agent</th><th>Site</th><th>Horaires</th><th class="r">Durée</th></tr>
<?php foreach ($services as $s): ?><tr><td><?= date('d/m', strtotime($s['debut_retenu'])) ?></td><td><?= h(ptNomCourt($s['prenom'], $s['nom'])) ?></td><td><?= h($s['site_nom']) ?></td>
    <td><?= date('H\hi', strtotime($s['debut_retenu'])) ?> → <?= date('H\hi', strtotime($s['fin_retenu'])) ?></td><td class="r"><?= ptFormatDuree(ptDureeMin($s)) ?></td></tr><?php endforeach; ?>
<?php if (!$services): ?><tr><td colspan="5" class="muted">Aucune vacation sur la période.</td></tr><?php endif; ?>
</table>
<?php endif; ?>

<?php if ($rondes): ?>
<h2>Rondes — passages aux points de contrôle</h2>
<table><tr><th>Site</th><th>Point</th><th class="r">Passages</th><th>Premier</th><th>Dernier</th></tr>
<?php foreach ($rondes as $r): ?><tr><td><?= h($r['site_nom']) ?></td><td><?= h($r['point_nom']) ?></td><td class="r"><?= (int)$r['n'] ?></td>
    <td><?= $r['premier'] ? date('d/m H\hi', strtotime($r['premier'])) : '—' ?></td><td><?= $r['dernier'] ? date('d/m H\hi', strtotime($r['dernier'])) : '—' ?></td></tr><?php endforeach; ?>
</table>
<?php endif; ?>

<?php if ($evs): ?>
<h2>Incidents signalés</h2>
<table><tr><th>Date</th><th>Site</th><th>Nature</th><th>Description et suite donnée</th></tr>
<?php foreach ($evs as $e): ?><tr><td><?= date('d/m H\hi', strtotime($e['survenu_le'])) ?></td><td><?= h((string)$e['site_nom']) ?></td>
    <td><?= h($e['type'] === 'sos' ? 'Alerte agent' : (ptCategories('incident')[$e['categorie']] ?? $e['categorie'])) ?></td>
    <td><?= nl2br(h((string)$e['description'])) ?><?= $e['commentaire'] ? '<br><em>Suite : ' . h($e['commentaire']) . '</em>' : '' ?></td></tr><?php endforeach; ?>
</table>
<?php endif; ?>

<h2>Validation du client</h2>
<?php if ($sig): ?>
    <div class="sig"><img src="<?= h($sig['signature']) ?>" style="height:22mm"><br>
        Validé par <strong><?= h($sig['nom']) ?></strong><?= $sig['fonction'] ? ', ' . h($sig['fonction']) : '' ?>, le <?= date('d/m/Y à H:i', strtotime($sig['created_at'])) ?>
        — <?= ptFormatDuree((int)$sig['total_min']) ?> attestées.</div>
<?php else: ?>
    <div class="sig muted" style="height:22mm">Non signé à la date d'édition. Signature en ligne possible depuis votre portail.</div>
<?php endif; ?>
</body></html>
<?php
    return ptPdfDepuisHtml((string)ob_get_clean());
}

/** Rapport d'heures interne (ventilé nuit / dimanche / férié). */
function ptRapportHeuresPdf(array $agents, array $tot, string $du, string $au, string $titreSite): string {
    ob_start(); ?>
<html><head><meta charset="UTF-8"><?= ptPdfStyle() ?></head><body>
<div class="marque"><?= h(mb_strtoupper(ptMarque())) ?></div>
<h1>Rapport d'heures</h1>
<div>Du <?= date('d/m/Y', strtotime($du)) ?> au <?= date('d/m/Y', strtotime($au)) ?> · <?= h($titreSite) ?> · <span class="muted">édité le <?= date('d/m/Y H:i') ?></span></div>
<table class="kpis" style="margin-top:4mm"><tr>
    <td><div class="v"><?= ptFormatDuree($tot['total']) ?></div>total</td><td><div class="v"><?= ptFormatDuree($tot['nuit']) ?></div>dont nuit</td>
    <td><div class="v"><?= ptFormatDuree($tot['dimanche']) ?></div>dont dimanche</td><td><div class="v"><?= ptFormatDuree($tot['ferie']) ?></div>dont férié</td>
</tr></table>
<h2>Par agent</h2>
<table><tr><th>Matricule</th><th>Agent</th><th class="r">Services</th><th class="r">Total</th><th class="r">Nuit</th><th class="r">Dimanche</th><th class="r">Férié</th><th>Sites</th></tr>
<?php foreach ($agents as $a): ?><tr><td><?= h((string)$a['matricule']) ?></td><td><?= h($a['nom']) ?><?= $a['verif'] ? ' <span class="muted">(' . $a['verif'] . ' à vérifier)</span>' : '' ?></td>
    <td class="r"><?= $a['n'] ?></td><td class="r"><strong><?= ptFormatDuree($a['total']) ?></strong></td><td class="r"><?= ptFormatDuree($a['nuit']) ?></td>
    <td class="r"><?= ptFormatDuree($a['dimanche']) ?></td><td class="r"><?= ptFormatDuree($a['ferie']) ?></td>
    <td><?php foreach ($a['sites'] as $sn => $m) echo h($sn) . ' : ' . ptFormatDuree($m) . '<br>'; ?></td></tr><?php endforeach; ?>
</table>
</body></html>
<?php
    return ptPdfDepuisHtml((string)ob_get_clean(), 'landscape');
}
