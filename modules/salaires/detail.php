<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
requirePerm('salaires', 'view');

$db        = getDB();
$agentId   = (int)($_GET['agent_id']   ?? 0);
$versionId = (int)($_GET['version_id'] ?? 0);
if (!$agentId || !$versionId) { header('Location: index.php'); exit; }

$stmtA = $db->prepare("SELECT * FROM agents WHERE id=?");
$stmtA->execute([$agentId]);
$agent = $stmtA->fetch();

$stmtV = $db->prepare("SELECT * FROM planning_versions WHERE id=?");
$stmtV->execute([$versionId]);
$version = $stmtV->fetch();
if (!$agent || !$version) { flash('danger','Données introuvables.'); header('Location: index.php'); exit; }

$taux    = getTauxHoraires();
$params  = getAllParams();
$feries  = getJoursFeries($version['annee']);

$stmtL = $db->prepare("SELECT * FROM planning_lignes WHERE version_id=? AND agent_id=? ORDER BY date_travail");
$stmtL->execute([$versionId, $agentId]);
$lignes = $stmtL->fetchAll();

$totaux = ['normal'=>0,'nuit'=>0,'dimanche'=>0,'nuit_dimanche'=>0,'ferie_normal'=>0,'ferie_dimanche'=>0,'ferie_nuit'=>0];
foreach ($lignes as $l) {
    foreach ($totaux as $k => $_) $totaux[$k] += (int)$l['min_'.$k];
}
$totalMin     = array_sum($totaux);
$salaireTotal = 0;
$salaireDetail = [];
foreach ($totaux as $k => $min) {
    $h       = $min / 60;
    $montant = round($h * ($taux[$k] ?? 0), 2);
    $salaireTotal += $montant;
    $salaireDetail[$k] = ['heures' => $h, 'taux' => $taux[$k] ?? 0, 'montant' => $montant];
}

$typeLabels = [
    'normal'        => ['Normal',      '#374151'],
    'nuit'          => ['Nuit',        '#4f46e5'],
    'dimanche'      => ['Dimanche',    '#dc2626'],
    'nuit_dimanche' => ['Nuit Dim.',   '#7c3aed'],
    'ferie_normal'  => ['Férié',       '#92400e'],
    'ferie_dimanche'=> ['Férié Dim.',  '#be185d'],
    'ferie_nuit'    => ['Nuit Férié',  '#1d4ed8'],
];
$nomsJours = ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];

// Export PDF — doit se faire avant tout output HTML
if (($_GET['export'] ?? '') === 'pdf') {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="fr"><head><meta charset="UTF-8"><?= pdfBaseStyle() ?></head>
    <body><div class="page">
    <div class="pdf-header">
        <div>
            <h1>Détail salaire — <?= htmlspecialchars($agent['prenom'].' '.$agent['nom']) ?></h1>
            <p>Matricule : <?= htmlspecialchars($agent['matricule'] ?? '—') ?> · <?= htmlspecialchars(formatMois($version['mois'], $version['annee'])) ?> · V<?= $version['version'] ?></p>
            <p><?= htmlspecialchars($params['entreprise_nom'] ?? '') ?> · Généré le <?= date('d/m/Y à H:i') ?></p>
        </div>
        <div style="background:#1a2332;color:#c9a84c;padding:10px 14px;border-radius:6px;text-align:center">
            <div style="font-size:8pt">Total mois</div>
            <div style="font-size:14pt;font-weight:700"><?= number_format($salaireTotal, 2) ?> €</div>
            <div style="font-size:8pt"><?= number_format($totalMin/60, 2) ?>h</div>
        </div>
    </div>
    <div class="section-title">Récapitulatif par type d'heures</div>
    <table>
        <thead><tr><th>Type</th><th>Heures</th><th>Taux (€/h)</th><th>Montant (€)</th></tr></thead>
        <tbody>
        <?php foreach ($typeLabels as $k => [$label, $col]):
            if ($salaireDetail[$k]['heures'] == 0) continue; ?>
        <tr>
            <td style="color:<?= $col ?>;font-weight:700"><?= $label ?></td>
            <td><?= number_format($salaireDetail[$k]['heures'], 2) ?>h</td>
            <td><?= number_format($salaireDetail[$k]['taux'], 2) ?></td>
            <td class="green"><?= number_format($salaireDetail[$k]['montant'], 2) ?> €</td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2"><strong>TOTAL : <?= number_format($totalMin/60, 2) ?>h</strong></td>
                <td></td>
                <td class="green" style="font-size:11pt"><?= number_format($salaireTotal, 2) ?> €</td>
            </tr>
        </tfoot>
    </table>
    <div class="section-title" style="margin-top:14px">Détail journalier</div>
    <table>
        <thead><tr><th>Date</th><th>Jour</th><th>Horaires</th><th>Normal</th><th>Nuit</th><th>Dim.</th><th>Fér.</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($lignes as $l):
            $dt   = strtotime($l['date_travail']);
            $totL = $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_nuit_dimanche']+$l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit'];
        ?>
        <tr>
            <td><?= date('d/m', $dt) ?></td>
            <td><?= $nomsJours[date('N', $dt)] ?></td>
            <td><?= substr($l['heure_debut'],0,5) ?>→<?= substr($l['heure_fin'],0,5) ?><?= $l['depasse_minuit']?'+1':'' ?></td>
            <td><?= $l['min_normal']   >0 ? number_format($l['min_normal']  /60,2).'h' : '—' ?></td>
            <td><?= ($l['min_nuit']+$l['min_nuit_dimanche']+$l['min_ferie_nuit'])>0 ? number_format(($l['min_nuit']+$l['min_nuit_dimanche']+$l['min_ferie_nuit'])/60,2).'h' : '—' ?></td>
            <td><?= ($l['min_dimanche']+$l['min_ferie_dimanche'])>0 ? number_format(($l['min_dimanche']+$l['min_ferie_dimanche'])/60,2).'h' : '—' ?></td>
            <td><?= ($l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit'])>0?'Oui':'—' ?></td>
            <td><strong><?= number_format($totL/60,2) ?>h</strong></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <div class="pdf-footer">
        <span>Document confidentiel</span>
        <span><?= htmlspecialchars($params['entreprise_nom'] ?? '') ?></span>
    </div>
    </div></body></html>
    <?php
    $html = ob_get_clean();
    renderPdf($html, 'detail_salaire_' . ($agent['matricule'] ?? $agentId) . '_' . sprintf('%02d', $version['mois']) . '_' . $version['annee'] . '.pdf');
}

$pageTitle    = 'Détail salaire agent';
$currentModule = 'salaires';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Actions -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="index.php?mois=<?= $version['mois'] ?>&annee=<?= $version['annee'] ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour</a>
    <a href="?agent_id=<?= $agentId ?>&version_id=<?= $versionId ?>&export=pdf" class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.35rem 0.75rem;font-size:0.8rem"><i class="fa fa-file-pdf me-1"></i>Exporter PDF</a>
    <a href="../agents/view.php?id=<?= $agentId ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-user me-1"></i>Fiche agent</a>
</div>

<!-- Résumé agent -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="ov-card">
            <div class="ov-card-body text-center">
                <div style="font-size:0.75rem;color:#9ca3af;text-transform:uppercase;letter-spacing:1px">Agent</div>
                <div style="font-size:1.1rem;font-weight:700;margin:4px 0"><?= h($agent['prenom'].' '.$agent['nom']) ?></div>
                <div style="font-size:0.82rem;color:#6b7280"><?= h($agent['poste']??'') ?></div>
                <code style="font-size:0.85rem"><?= h($agent['matricule']??'—') ?></code>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="ov-card">
            <div class="ov-card-body text-center">
                <div style="font-size:0.75rem;color:#9ca3af;text-transform:uppercase;letter-spacing:1px">Période</div>
                <div style="font-size:1.1rem;font-weight:700;margin:4px 0"><?= formatMois($version['mois'], $version['annee']) ?></div>
                <div style="font-size:0.82rem;color:#6b7280"><?= count($lignes) ?> jours travaillés · V<?= $version['version'] ?></div>
                <div style="font-size:1.1rem;font-weight:700;color:var(--ov-navy)"><?= number_format($totalMin/60, 2) ?>h</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="ov-card" style="border:2px solid rgba(22,163,74,0.2)">
            <div class="ov-card-body text-center">
                <div style="font-size:0.75rem;color:#9ca3af;text-transform:uppercase;letter-spacing:1px">Salaire calculé</div>
                <div style="font-size:2rem;font-weight:800;color:#16a34a;margin:4px 0"><?= number_format($salaireTotal, 2) ?> €</div>
                <div style="font-size:0.75rem;color:#9ca3af">Basé sur les taux configurés</div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
<!-- Récapitulatif par type -->
<div class="col-md-5">
    <div class="ov-card">
        <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-chart-pie me-2" style="color:var(--ov-gold)"></i>Par type d'heures</h2></div>
        <div class="ov-card-body p-0">
            <table class="ov-table">
                <thead><tr><th>Type</th><th>Heures</th><th>Taux</th><th>Montant</th></tr></thead>
                <tbody>
                <?php foreach ($typeLabels as $k => [$label, $col]):
                    if ($salaireDetail[$k]['heures'] == 0) continue; ?>
                <tr>
                    <td><span style="color:<?= $col ?>;font-weight:600"><?= $label ?></span></td>
                    <td class="text-center fw-600"><?= number_format($salaireDetail[$k]['heures'], 2) ?>h</td>
                    <td class="text-center" style="color:#6b7280"><?= number_format($salaireDetail[$k]['taux'], 2) ?> €</td>
                    <td class="text-center fw-700" style="color:#16a34a"><?= number_format($salaireDetail[$k]['montant'], 2) ?> €</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2"><strong><?= number_format($totalMin/60, 2) ?>h</strong></td>
                        <td></td>
                        <td class="text-center fw-700" style="color:#16a34a;font-size:1rem"><?= number_format($salaireTotal, 2) ?> €</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Détail journalier -->
<div class="col-md-7">
    <div class="ov-card">
        <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-calendar-days me-2" style="color:var(--ov-gold)"></i>Détail journalier</h2></div>
        <div class="ov-card-body p-0">
            <?php if (empty($lignes)): ?>
            <p class="text-center text-muted py-4">Aucune heure enregistrée</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="ov-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Horaires</th>
                        <th>Normal</th>
                        <th><span style="color:#4f46e5">Nuit</span></th>
                        <th><span style="color:#dc2626">Dim.</span></th>
                        <th><span style="color:#92400e">Fér.</span></th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lignes as $l):
                    $dt      = strtotime($l['date_travail']);
                    $jourSem = date('N', $dt);
                    $isFer   = in_array($l['date_travail'], $feries);
                    $isDim   = $jourSem == 7;
                    $totL    = $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_nuit_dimanche']+$l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit'];
                    $bgRow   = $isFer ? '#fffbeb' : ($isDim ? '#fef2f2' : 'white');
                ?>
                <tr style="background:<?= $bgRow ?>">
                    <td>
                        <strong><?= date('d/m', $dt) ?></strong>
                        <span style="font-size:0.72rem;color:#9ca3af;margin-left:3px"><?= $nomsJours[$jourSem] ?></span>
                        <?php if ($isFer): ?><span style="font-size:0.6rem;background:#fde68a;color:#92400e;padding:1px 4px;border-radius:3px;margin-left:2px">F</span><?php endif; ?>
                    </td>
                    <td style="font-size:0.82rem;font-weight:600">
                        <?= substr($l['heure_debut'],0,5) ?>→<?= substr($l['heure_fin'],0,5) ?><?= $l['depasse_minuit']?'<sup>+1</sup>':'' ?>
                        <?php if (!empty($l['note'])): ?><br><span style="font-size:0.7rem;color:#9ca3af"><?= h($l['note']) ?></span><?php endif; ?>
                    </td>
                    <td class="text-center" style="font-size:0.8rem">
                        <?= $l['min_normal']>0 ? number_format($l['min_normal']/60,2).'h' : '<span style="color:#e5e7eb">—</span>' ?>
                    </td>
                    <td class="text-center" style="font-size:0.8rem;color:#4f46e5">
                        <?php $mn=$l['min_nuit']+$l['min_nuit_dimanche']+$l['min_ferie_nuit']; echo $mn>0?number_format($mn/60,2).'h':'<span style="color:#e5e7eb">—</span>'; ?>
                    </td>
                    <td class="text-center" style="font-size:0.8rem;color:#dc2626">
                        <?php $md=$l['min_dimanche']+$l['min_ferie_dimanche']; echo $md>0?number_format($md/60,2).'h':'<span style="color:#e5e7eb">—</span>'; ?>
                    </td>
                    <td class="text-center" style="font-size:0.8rem;color:#92400e">
                        <?php $mf=$l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit']; echo $mf>0?number_format($mf/60,2).'h':'<span style="color:#e5e7eb">—</span>'; ?>
                    </td>
                    <td class="text-center fw-700" style="font-size:0.87rem"><?= number_format($totL/60,2) ?>h</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
