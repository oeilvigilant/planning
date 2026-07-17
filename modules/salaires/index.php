<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('salaires', 'view');

$pageTitle    = 'Calcul des salaires';
$currentModule = 'salaires';
require_once __DIR__ . '/../../includes/header.php';

$db    = getDB();
$mois  = (int)($_GET['mois']  ?? date('n'));
$annee = (int)($_GET['annee'] ?? date('Y'));

// Version active du planning
$stmtV = $db->prepare("SELECT * FROM planning_versions WHERE mois=? AND annee=? AND is_current=1 LIMIT 1");
$stmtV->execute([$mois, $annee]);
$version = $stmtV->fetch();

$taux   = getTauxHoraires();
$agents = $db->query("SELECT id,nom,prenom,matricule,poste,remuneration,type_remuneration FROM agents WHERE actif=1 ORDER BY nom,prenom")->fetchAll();

// Calculer les totaux par agent
$resultats = [];
if ($version) {
    foreach ($agents as $ag) {
        $stmtL = $db->prepare("
            SELECT SUM(min_normal) as n, SUM(min_nuit) as nu, SUM(min_dimanche) as d,
                   SUM(min_nuit_dimanche) as nd,
                   SUM(min_ferie_normal) as fn, SUM(min_ferie_dimanche) as fd, SUM(min_ferie_nuit) as fnu
            FROM planning_lignes WHERE version_id=? AND agent_id=?
        ");
        $stmtL->execute([$version['id'], $ag['id']]);
        $mins = $stmtL->fetch();

        $heures = [
            'normal'        => minutesToHeures((int)$mins['n']),
            'nuit'          => minutesToHeures((int)$mins['nu']),
            'dimanche'      => minutesToHeures((int)$mins['d']),
            'nuit_dimanche' => minutesToHeures((int)$mins['nd']),
            'ferie_normal'  => minutesToHeures((int)$mins['fn']),
            'ferie_dimanche'=> minutesToHeures((int)$mins['fd']),
            'ferie_nuit'    => minutesToHeures((int)$mins['fnu']),
        ];

        $totalHeures  = array_sum($heures);
        $salaireCalc  = 0;
        foreach ($heures as $type => $h) {
            $salaireCalc += $h * ($taux[$type] ?? 0);
        }

        if ($totalHeures > 0) {
            $resultats[$ag['id']] = [
                'agent'       => $ag,
                'heures'      => $heures,
                'total_heures'=> $totalHeures,
                'salaire'     => round($salaireCalc, 2),
            ];
        }
    }
}

$typeLabels = [
    'normal'        => 'Normal',
    'nuit'          => 'Nuit',
    'dimanche'      => 'Dimanche',
    'nuit_dimanche' => 'Nuit Dim.',
    'ferie_normal'  => 'Férié',
    'ferie_dimanche'=> 'Fér. Dim.',
    'ferie_nuit'    => 'Nuit Fér.',
];
$typeCols = [
    'normal'        => '#374151',
    'nuit'          => '#4f46e5',
    'dimanche'      => '#dc2626',
    'nuit_dimanche' => '#7c3aed',
    'ferie_normal'  => '#92400e',
    'ferie_dimanche'=> '#be185d',
    'ferie_nuit'    => '#1d4ed8',
];

$prevMois  = $mois==1?12:$mois-1; $prevAnnee = $mois==1?$annee-1:$annee;
$nextMois  = $mois==12?1:$mois+1; $nextAnnee = $mois==12?$annee+1:$annee;
$totalSalaires = array_sum(array_column($resultats,'salaire'));
?>

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
    <a href="?mois=<?= $prevMois ?>&annee=<?= $prevAnnee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-left"></i></a>
    <h2 style="font-size:1.1rem;font-weight:700;margin:0"><?= formatMois($mois,$annee) ?></h2>
    <a href="?mois=<?= $nextMois ?>&annee=<?= $nextAnnee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-right"></i></a>

    <?php if ($version): ?>
    <div class="ms-auto d-flex gap-2">
        <a href="../rapports/export_salaires.php?version_id=<?= $version['id'] ?>&format=pdf" class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.35rem 0.75rem;font-size:0.8rem"><i class="fa fa-file-pdf me-1"></i>Export PDF</a>
        <a href="../rapports/export_salaires.php?version_id=<?= $version['id'] ?>&format=excel" class="btn btn-sm" style="background:rgba(34,197,94,0.1);color:#16a34a;border:1px solid rgba(34,197,94,0.2);border-radius:8px;padding:0.35rem 0.75rem;font-size:0.8rem"><i class="fa fa-file-excel me-1"></i>Export Excel</a>
    </div>
    <?php endif; ?>
</div>

<?php if (!$version || empty($resultats)): ?>
<div class="alert alert-info"><i class="fa fa-info-circle me-2"></i>Aucun planning trouvé pour <?= formatMois($mois,$annee) ?>. <a href="../planning/index.php?mois=<?= $mois ?>&annee=<?= $annee ?>">Créer le planning →</a></div>
<?php else: ?>

<!-- Résumé global -->
<div class="row g-3 mb-3">
  <div class="col-md-3">
    <div class="stat-card"><div class="stat-icon gold"><i class="fa fa-users"></i></div>
    <div><div class="stat-value"><?= count($resultats) ?></div><div class="stat-label">Agents planifiés</div></div></div>
  </div>
  <div class="col-md-3">
    <div class="stat-card"><div class="stat-icon navy"><i class="fa fa-clock"></i></div>
    <div><div class="stat-value"><?= number_format(array_sum(array_column($resultats,'total_heures')),1) ?></div><div class="stat-label">Heures totales</div></div></div>
  </div>
  <div class="col-md-3">
    <div class="stat-card"><div class="stat-icon green"><i class="fa fa-euro-sign"></i></div>
    <div><div class="stat-value"><?= number_format($totalSalaires,2,'.',' ') ?></div><div class="stat-label">Total salaires (€)</div></div></div>
  </div>
  <div class="col-md-3">
    <div class="stat-card"><div class="stat-icon gold"><i class="fa fa-code-branch"></i></div>
    <div><div class="stat-value">V<?= $version['version'] ?></div><div class="stat-label">Version planning</div></div></div>
  </div>
</div>

<div class="ov-card">
  <div class="ov-card-header">
    <h2 class="ov-card-title"><i class="fa fa-euro-sign me-2" style="color:var(--ov-gold)"></i>Détail par agent — <?= formatMois($mois,$annee) ?></h2>
    <div class="d-flex gap-2 flex-wrap" style="font-size:0.72rem">
      <?php foreach ($typeLabels as $k=>$l): ?>
      <span style="color:<?= $typeCols[$k] ?>"><i class="fa fa-circle" style="font-size:0.5rem"></i> <?= $l ?> (<?= number_format($taux[$k]??0,2) ?>€/h)</span>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="ov-card-body p-0">
    <div class="table-responsive">
    <table class="ov-table">
      <thead>
        <tr>
          <th>Agent</th>
          <?php foreach ($typeLabels as $k=>$l): ?>
          <th class="text-center" style="color:<?= $typeCols[$k] ?>"><?= $l ?><br><small style="font-weight:normal;font-size:0.68rem"><?= number_format($taux[$k]??0,2) ?>€</small></th>
          <?php endforeach; ?>
          <th class="text-center">Total h.</th>
          <th class="text-center" style="color:#16a34a">Salaire</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($resultats as $rid => $r): ?>
      <tr>
        <td>
          <div class="fw-600" style="font-size:0.875rem"><?= h($r['agent']['prenom'].' '.$r['agent']['nom']) ?></div>
          <div style="font-size:0.72rem;color:#9ca3af"><?= h($r['agent']['poste']??'') ?></div>
        </td>
        <?php foreach ($typeLabels as $k=>$l): ?>
        <td class="text-center" style="font-size:0.83rem;color:<?= $r['heures'][$k]>0?$typeCols[$k]:'#d1d5db' ?>">
          <?= $r['heures'][$k] > 0 ? number_format($r['heures'][$k],2).'h' : '—' ?>
        </td>
        <?php endforeach; ?>
        <td class="text-center fw-700"><?= number_format($r['total_heures'],2) ?>h</td>
        <td class="text-center fw-700" style="color:#16a34a;font-size:1rem"><?= number_format($r['salaire'],2) ?> €</td>
        <td>
          <div class="d-flex gap-1">
            <a href="detail.php?agent_id=<?= $rid ?>&version_id=<?= $version['id'] ?>" class="btn-sm-icon view" title="Détail"><i class="fa fa-eye"></i></a>
            <a href="../agents/export_pdf.php?id=<?= $rid ?>&version_id=<?= $version['id'] ?>" class="btn-sm-icon" style="background:rgba(239,68,68,0.1);color:#dc2626" title="PDF comptable"><i class="fa fa-file-pdf"></i></a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background:#f8f9fa;border-top:2px solid #e5e7eb">
          <td class="fw-700">TOTAL</td>
          <?php foreach ($typeLabels as $k=>$l): ?>
          <td class="text-center fw-700" style="font-size:0.83rem">
            <?php $tot = array_sum(array_column(array_column($resultats,'heures'),$k)); ?>
            <?= $tot > 0 ? number_format($tot,2).'h' : '—' ?>
          </td>
          <?php endforeach; ?>
          <td class="text-center fw-700"><?= number_format(array_sum(array_column($resultats,'total_heures')),2) ?>h</td>
          <td class="text-center fw-700" style="color:#16a34a;font-size:1rem"><?= number_format($totalSalaires,2) ?> €</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
