<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
requirePerm('salaires', 'view');
ensureMissionsSchema();

$db      = getDB();
$agentId = (int)($_GET['agent_id'] ?? 0);
$mois    = (int)($_GET['mois']     ?? 0);
$annee   = (int)($_GET['annee']    ?? 0);
if (!$agentId || !$mois || !$annee) { header('Location: index.php'); exit; }

$stmtA = $db->prepare("SELECT * FROM agents WHERE id=?");
$stmtA->execute([$agentId]);
$agent = $stmtA->fetch();
if (!$agent) { flash('danger','Données introuvables.'); header('Location: index.php'); exit; }

$taux    = getTauxHoraires();
$params  = getAllParams();
$feries  = getJoursFeries($annee);

// Agrégé sur toutes les missions du mois pour cet agent (un agent peut travailler plusieurs missions en parallèle)
$stmtL = $db->prepare("
    SELECT pl.*, m.nom AS mission_nom
    FROM planning_lignes pl
    JOIN planning_versions pv ON pv.id = pl.version_id
    LEFT JOIN missions m ON m.id = pv.mission_id
    WHERE pv.mois=? AND pv.annee=? AND pv.is_current=1 AND pl.agent_id=?
    ORDER BY pl.date_travail
");
$stmtL->execute([$mois, $annee, $agentId]);
$lignes = $stmtL->fetchAll();
$missionsCouvertes = array_values(array_unique(array_filter(array_column($lignes, 'mission_nom'))));

$totaux = ['normal'=>0,'nuit'=>0,'dimanche'=>0,'nuit_dimanche'=>0,'ferie_normal'=>0,'ferie_nuit'=>0];
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

// Calcul paie nette
$primesCfg = getPrimesConfig();
$minPanier = (int)round($primesCfg['panier_min_heures'] * 60);
$nbVacationsPanier = 0;
foreach ($lignes as $l) {
    $totL = $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_nuit_dimanche']+$l['min_ferie_normal']+$l['min_ferie_nuit'];
    if ($totL >= $minPanier) $nbVacationsPanier++;
}
$paie = calculerPaie(round($salaireTotal, 2), $nbVacationsPanier);

$typeLabels = [
    'normal'        => ['Normal',      '#374151'],
    'nuit'          => ['Nuit',        '#4f46e5'],
    'dimanche'      => ['Dimanche',    '#dc2626'],
    'nuit_dimanche' => ['Nuit Dim.',   '#7c3aed'],
    'ferie_normal'  => ['Férié',       '#92400e'],
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
            <p>Matricule : <?= htmlspecialchars($agent['matricule'] ?? '—') ?> · <?= htmlspecialchars(formatMois($mois, $annee)) ?><?= $missionsCouvertes ? ' · ' . htmlspecialchars(implode(', ', $missionsCouvertes)) : '' ?></p>
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
        <thead><tr><th>Date</th><th>Jour</th><th>Mission</th><th>Horaires</th><th>Normal</th><th>Nuit</th><th>Dim.</th><th>Fér.</th><th>Total</th></tr></thead>
        <tbody>
        <?php foreach ($lignes as $l):
            $dt   = strtotime($l['date_travail']);
            $totL = $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_nuit_dimanche']+$l['min_ferie_normal']+$l['min_ferie_nuit'];
        ?>
        <tr>
            <td><?= date('d/m', $dt) ?></td>
            <td><?= $nomsJours[date('N', $dt)] ?></td>
            <td><?= htmlspecialchars($l['mission_nom'] ?? '—') ?></td>
            <td><?= substr($l['heure_debut'],0,5) ?>→<?= substr($l['heure_fin'],0,5) ?><?= $l['depasse_minuit']?'+1':'' ?></td>
            <td><?= $l['min_normal']   >0 ? number_format($l['min_normal']  /60,2).'h' : '—' ?></td>
            <td><?= ($l['min_nuit']+$l['min_nuit_dimanche']+$l['min_ferie_nuit'])>0 ? number_format(($l['min_nuit']+$l['min_nuit_dimanche']+$l['min_ferie_nuit'])/60,2).'h' : '—' ?></td>
            <td><?= $l['min_dimanche']>0 ? number_format($l['min_dimanche']/60,2).'h' : '—' ?></td>
            <td><?= ($l['min_ferie_normal']+$l['min_ferie_nuit'])>0?'Oui':'—' ?></td>
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
    renderPdf($html, 'detail_salaire_' . ($agent['matricule'] ?? $agentId) . '_' . sprintf('%02d', $mois) . '_' . $annee . '.pdf');
}

$pageTitle    = 'Détail salaire agent';
$currentModule = 'salaires';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Actions -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="index.php?mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour</a>
    <a href="?agent_id=<?= $agentId ?>&mois=<?= $mois ?>&annee=<?= $annee ?>&export=pdf" class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.35rem 0.75rem;font-size:0.8rem"><i class="fa fa-file-pdf me-1"></i>Exporter PDF</a>
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
                <div style="font-size:1.1rem;font-weight:700;margin:4px 0"><?= formatMois($mois, $annee) ?></div>
                <div style="font-size:0.82rem;color:#6b7280"><?= count($lignes) ?> jours travaillés<?= $missionsCouvertes ? ' · ' . h(implode(', ', $missionsCouvertes)) : '' ?></div>
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
                        <th>Mission</th>
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
                    $totL    = $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_nuit_dimanche']+$l['min_ferie_normal']+$l['min_ferie_nuit'];
                    $bgRow   = $isFer ? '#fffbeb' : ($isDim ? '#fef2f2' : 'white');
                ?>
                <tr style="background:<?= $bgRow ?>">
                    <td>
                        <strong><?= date('d/m', $dt) ?></strong>
                        <span style="font-size:0.72rem;color:#9ca3af;margin-left:3px"><?= $nomsJours[$jourSem] ?></span>
                        <?php if ($isFer): ?><span style="font-size:0.6rem;background:#fde68a;color:#92400e;padding:1px 4px;border-radius:3px;margin-left:2px">F</span><?php endif; ?>
                    </td>
                    <td style="font-size:0.78rem;color:#6b7280"><?= h($l['mission_nom'] ?? '—') ?></td>
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
                        <?php $md=$l['min_dimanche']; echo $md>0?number_format($md/60,2).'h':'<span style="color:#e5e7eb">—</span>'; ?>
                    </td>
                    <td class="text-center" style="font-size:0.8rem;color:#92400e">
                        <?php $mf=$l['min_ferie_normal']+$l['min_ferie_nuit']; echo $mf>0?number_format($mf/60,2).'h':'<span style="color:#e5e7eb">—</span>'; ?>
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

<!-- ── Fiche de paie estimée ─────────────────────────────────────────────── -->
<div class="ov-card mt-3">
  <div class="ov-card-header">
    <h2 class="ov-card-title"><i class="fa fa-file-invoice-dollar me-2" style="color:var(--ov-gold)"></i>Fiche de paie estimée</h2>
    <small class="text-muted">Estimation basée sur les taux IDCC 1351 configurés</small>
  </div>
  <div class="ov-card-body">
    <div class="row g-3">

      <!-- Colonne gauche : brut + cotisations -->
      <div class="col-md-5">
        <div style="background:#f8f9fa;border-radius:8px;padding:16px;border:1px solid #e5e7eb">
          <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:8px">Salaire brut</div>

          <?php foreach ($salaireDetail as $k => $d): if ($d['heures'] <= 0) continue; ?>
          <?php $typeLabels2 = ['normal'=>'Normal','nuit'=>'Nuit','dimanche'=>'Dimanche','nuit_dimanche'=>'Nuit Dim.','ferie_normal'=>'Férié','ferie_nuit'=>'Nuit Fér.']; ?>
          <div class="d-flex justify-content-between" style="font-size:0.82rem;padding:3px 0;border-bottom:1px solid #f3f4f6">
            <span style="color:#6b7280"><?= $typeLabels2[$k] ?> — <?= number_format($d['heures'],2) ?>h × <?= number_format($d['taux'],2) ?>€</span>
            <span style="font-weight:600"><?= number_format($d['montant'],2) ?> €</span>
          </div>
          <?php endforeach; ?>

          <div class="d-flex justify-content-between mt-2" style="font-size:1rem;font-weight:700;color:#1a2332">
            <span>Total brut</span>
            <span><?= number_format($paie['brut'],2) ?> €</span>
          </div>

          <div style="margin-top:12px;font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;margin-bottom:6px">Cotisations salariales</div>
          <?php foreach ($paie['cotis_detail'] as $c): if ($c['taux_sal'] <= 0) continue; ?>
          <div class="d-flex justify-content-between" style="font-size:0.78rem;padding:2px 0;border-bottom:1px solid #f3f4f6">
            <span style="color:#6b7280"><?= h($c['libelle']) ?> (<?= number_format($c['taux_sal'],3) ?>%)</span>
            <span style="color:#dc2626">−<?= number_format($c['montant_sal'],2) ?> €</span>
          </div>
          <?php endforeach; ?>

          <div class="d-flex justify-content-between mt-2" style="font-size:0.9rem;font-weight:700;color:#dc2626">
            <span>Total cotisations</span>
            <span>−<?= number_format($paie['cotisations'],2) ?> €</span>
          </div>

          <div class="d-flex justify-content-between mt-2 pt-2" style="border-top:2px solid #e5e7eb;font-size:1rem;font-weight:700;color:#374151">
            <span>Net avant primes</span>
            <span><?= number_format($paie['net_base'],2) ?> €</span>
          </div>
        </div>
      </div>

      <!-- Colonne milieu : primes exonérées -->
      <div class="col-md-3">
        <div style="background:#f0fdf4;border-radius:8px;padding:16px;border:1px solid #bbf7d0;height:100%">
          <div style="font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#16a34a;margin-bottom:8px">Primes exonérées</div>

          <div class="d-flex justify-content-between" style="font-size:0.82rem;padding:4px 0;border-bottom:1px solid #dcfce7">
            <span style="color:#374151">Panier repas<br><small style="color:#6b7280"><?= $nbVacationsPanier ?> vacation<?= $nbVacationsPanier>1?'s':'' ?> × <?= number_format($paie['panier']/$nbVacationsPanier ?: 0,2) ?> €</small></span>
            <span style="font-weight:600;color:#16a34a">+<?= number_format($paie['panier'],2) ?> €</span>
          </div>
          <div class="d-flex justify-content-between" style="font-size:0.82rem;padding:4px 0;border-bottom:1px solid #dcfce7">
            <span style="color:#374151">Habillage</span>
            <span style="font-weight:600;color:#16a34a">+<?= number_format($paie['habillage'],2) ?> €</span>
          </div>
          <div class="d-flex justify-content-between" style="font-size:0.82rem;padding:4px 0">
            <span style="color:#374151">Entretien tenue</span>
            <span style="font-weight:600;color:#16a34a">+<?= number_format($paie['entretien'],2) ?> €</span>
          </div>

          <div class="d-flex justify-content-between mt-3 pt-2" style="border-top:2px solid #86efac;font-size:0.9rem;font-weight:700;color:#16a34a">
            <span>Total primes</span>
            <span>+<?= number_format($paie['total_primes'],2) ?> €</span>
          </div>
        </div>
      </div>

      <!-- Colonne droite : net total -->
      <div class="col-md-4">
        <div style="background:linear-gradient(135deg,#1a2332,#2d3f5c);border-radius:8px;padding:20px;color:white;height:100%;display:flex;flex-direction:column;justify-content:center;align-items:center;text-align:center">
          <div style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#c9a84c;margin-bottom:8px">Net à payer estimé</div>
          <div style="font-size:2.2rem;font-weight:800;color:#fff"><?= number_format($paie['net_total'],2) ?> €</div>
          <div style="font-size:0.75rem;color:rgba(255,255,255,0.6);margin-top:4px"><?= number_format($totalMin/60,2) ?>h travaillées</div>
          <div style="margin-top:16px;padding-top:12px;border-top:1px solid rgba(255,255,255,0.15);width:100%">
            <div style="font-size:0.72rem;color:rgba(255,255,255,0.5);margin-bottom:4px">Coût employeur estimé</div>
            <div style="font-size:1.1rem;font-weight:700;color:#f97316"><?= number_format($paie['cout_employeur'] + $paie['total_primes'],2) ?> €</div>
          </div>
          <div style="margin-top:8px;font-size:0.65rem;color:rgba(255,255,255,0.35)">Estimation — hors mutuelle, 13e mois, ancienneté</div>
        </div>
      </div>

    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
