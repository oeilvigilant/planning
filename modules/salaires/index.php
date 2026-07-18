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
                   SUM(min_ferie_normal) as fn, SUM(min_ferie_nuit) as fnu,
                   SUM(CASE WHEN (min_normal+min_nuit+min_dimanche+min_nuit_dimanche+min_ferie_normal+min_ferie_nuit) >= ? THEN 1 ELSE 0 END) AS nb_vacations_panier
            FROM planning_lignes WHERE version_id=? AND agent_id=?
        ");
        $primesCfg = getPrimesConfig();
        $minPanier = (int)round($primesCfg['panier_min_heures'] * 60);
        $stmtL->execute([$minPanier, $version['id'], $ag['id']]);
        $mins = $stmtL->fetch();

        $heures = [
            'normal'        => minutesToHeures((int)$mins['n']),
            'nuit'          => minutesToHeures((int)$mins['nu']),
            'dimanche'      => minutesToHeures((int)$mins['d']),
            'nuit_dimanche' => minutesToHeures((int)$mins['nd']),
            'ferie_normal'  => minutesToHeures((int)$mins['fn']),
            'ferie_nuit'    => minutesToHeures((int)$mins['fnu']),
        ];

        $totalHeures  = array_sum($heures);
        $salaireCalc  = 0;
        foreach ($heures as $type => $h) {
            $salaireCalc += $h * ($taux[$type] ?? 0);
        }
        $brut = round($salaireCalc, 2);

        if ($totalHeures > 0) {
            $paie = calculerPaie($brut, (int)$mins['nb_vacations_panier']);
            $resultats[$ag['id']] = [
                'agent'       => $ag,
                'heures'      => $heures,
                'total_heures'=> $totalHeures,
                'salaire'     => $brut,
                'paie'        => $paie,
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
    'ferie_nuit'    => 'Nuit Fér.',
];
$typeCols = [
    'normal'        => '#374151',
    'nuit'          => '#4f46e5',
    'dimanche'      => '#dc2626',
    'nuit_dimanche' => '#7c3aed',
    'ferie_normal'  => '#92400e',
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
    <div class="ms-auto">
        <button type="button" class="btn btn-sm" data-bs-toggle="modal" data-bs-target="#modalExport"
                style="background:rgba(99,102,241,0.1);color:#6366f1;border:1px solid rgba(99,102,241,0.2);border-radius:8px;padding:0.35rem 0.9rem;font-size:0.8rem">
            <i class="fa fa-download me-1"></i>Exporter
        </button>
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
          <th class="text-center" style="color:#374151">Brut</th>
          <th class="text-center" style="color:#dc2626">Cotis.</th>
          <th class="text-center" style="color:#8b5cf6">Primes</th>
          <th class="text-center" style="color:#16a34a;font-size:0.95rem">Net estimé</th>
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
        <td class="text-center fw-600" style="color:#374151"><?= number_format($r['paie']['brut'],2) ?> €</td>
        <td class="text-center" style="color:#dc2626;font-size:0.82rem">
          <span title="Cotisations salariales">−<?= number_format($r['paie']['cotisations'],2) ?> €</span>
        </td>
        <td class="text-center" style="color:#8b5cf6;font-size:0.82rem">
          <span title="Panier : <?= number_format($r['paie']['panier'],2) ?> € · Habillage : <?= number_format($r['paie']['habillage'],2) ?> € · Entretien : <?= number_format($r['paie']['entretien'],2) ?> €">
            +<?= number_format($r['paie']['total_primes'],2) ?> €
          </span>
        </td>
        <td class="text-center fw-700" style="color:#16a34a;font-size:1rem"><?= number_format($r['paie']['net_total'],2) ?> €</td>
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
          <td class="text-center fw-700"><?= number_format($totalSalaires,2) ?> €</td>
          <td class="text-center fw-700" style="color:#dc2626">−<?= number_format(array_sum(array_map(fn($r)=>$r['paie']['cotisations'],$resultats)),2) ?> €</td>
          <td class="text-center fw-700" style="color:#8b5cf6">+<?= number_format(array_sum(array_map(fn($r)=>$r['paie']['total_primes'],$resultats)),2) ?> €</td>
          <td class="text-center fw-700" style="color:#16a34a;font-size:1rem"><?= number_format(array_sum(array_map(fn($r)=>$r['paie']['net_total'],$resultats)),2) ?> €</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($version): ?>
<!-- Modal export colonnes -->
<div class="modal fade" id="modalExport" tabindex="-1" aria-labelledby="modalExportLabel">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form id="formExport" method="GET" action="../rapports/export_salaires.php" target="_blank">
        <input type="hidden" name="version_id" value="<?= $version['id'] ?>">
        <input type="hidden" name="format" id="exportFormat" value="pdf">

        <div class="modal-header" style="background:var(--ov-dark);color:white">
          <h5 class="modal-title" id="modalExportLabel">
            <i class="fa fa-sliders me-2" style="color:var(--ov-gold)"></i>Configurer l'export — <?= formatMois($mois,$annee) ?>
          </h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <!-- Presets -->
          <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
            <small class="text-muted me-1">Sélection rapide :</small>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="presetPolesSociale()">
              <i class="fa fa-building me-1"></i>Pôle sociale
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="presetCompta()">
              <i class="fa fa-calculator me-1"></i>Comptabilité
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(true)">Tout cocher</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(false)">Tout décocher</button>
          </div>

          <div class="row g-3">
            <!-- Colonne gauche : heures -->
            <div class="col-md-6">
              <div class="card h-100">
                <div class="card-header py-2" style="font-size:0.8rem;font-weight:700;background:#eff6ff;color:#1e40af">
                  <i class="fa fa-clock me-1"></i>Heures par type
                </div>
                <div class="card-body py-2">
                  <?php
                  $colsH = ['h_normal'=>'Normal','h_nuit'=>'Nuit','h_dimanche'=>'Dimanche',
                             'h_nuit_dimanche'=>'Nuit Dim.','h_ferie_normal'=>'Férié','h_ferie_nuit'=>'Nuit Fér.','total_h'=>'Total heures'];
                  foreach ($colsH as $val => $lab): ?>
                  <div class="form-check mb-1">
                    <input class="form-check-input export-col" type="checkbox" name="cols[]"
                           value="<?= $val ?>" id="col_<?= $val ?>" checked>
                    <label class="form-check-label" for="col_<?= $val ?>" style="font-size:0.85rem"><?= $lab ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <!-- Colonne droite : salaire + primes + net -->
            <div class="col-md-6">
              <div class="card mb-2">
                <div class="card-header py-2" style="font-size:0.8rem;font-weight:700;background:#f0fdf4;color:#166534">
                  <i class="fa fa-euro-sign me-1"></i>Salaire &amp; Cotisations
                </div>
                <div class="card-body py-2">
                  <?php foreach (['brut'=>'Salaire brut','cotisations'=>'Cotisations salariales'] as $val=>$lab): ?>
                  <div class="form-check mb-1">
                    <input class="form-check-input export-col" type="checkbox" name="cols[]"
                           value="<?= $val ?>" id="col_<?= $val ?>" checked>
                    <label class="form-check-label" for="col_<?= $val ?>" style="font-size:0.85rem"><?= $lab ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="card">
                <div class="card-header py-2" style="font-size:0.8rem;font-weight:700;background:#faf5ff;color:#7e22ce">
                  <i class="fa fa-gift me-1"></i>Primes exonérées &amp; Net
                </div>
                <div class="card-body py-2">
                  <?php
                  $colsP = ['prime_panier'=>'Panier repas','prime_habillage'=>'Prime habillage',
                             'prime_entretien'=>'Prime entretien','net_estime'=>'Net estimé ★'];
                  foreach ($colsP as $val=>$lab): ?>
                  <div class="form-check mb-1">
                    <input class="form-check-input export-col" type="checkbox" name="cols[]"
                           value="<?= $val ?>" id="col_<?= $val ?>" checked>
                    <label class="form-check-label" for="col_<?= $val ?>"
                           style="font-size:0.85rem<?= $val==='net_estime'?';color:#16a34a;font-weight:700':'' ?>"><?= $lab ?></label>
                  </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>

          <div class="mt-3 p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:0.78rem;color:#166534">
            <i class="fa fa-info-circle me-1"></i>
            Les colonnes <strong>Agent</strong> et <strong>Matricule</strong> sont toujours incluses.
            Le PDF passe automatiquement en paysage si plus de 7 colonnes sont sélectionnées.
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
          <button type="submit" class="btn btn-sm"
                  onclick="document.getElementById('exportFormat').value='excel'"
                  style="background:rgba(34,197,94,0.1);color:#16a34a;border:1px solid rgba(34,197,94,0.2)">
            <i class="fa fa-file-excel me-1"></i>Excel (CSV)
          </button>
          <button type="submit" class="btn btn-sm"
                  onclick="document.getElementById('exportFormat').value='pdf'"
                  style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2)">
            <i class="fa fa-file-pdf me-1"></i>PDF
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function toggleAll(state) {
    document.querySelectorAll('.export-col').forEach(c => c.checked = state);
}
function presetPolesSociale() {
    toggleAll(false);
    ['col_total_h','col_brut','col_cotisations','col_prime_panier','col_prime_habillage','col_prime_entretien','col_net_estime']
        .forEach(id => { const el = document.getElementById(id); if(el) el.checked = true; });
}
function presetCompta() {
    toggleAll(false);
    ['col_h_normal','col_h_nuit','col_h_dimanche','col_h_nuit_dimanche','col_h_ferie_normal','col_h_ferie_nuit',
     'col_total_h','col_brut','col_cotisations','col_net_estime']
        .forEach(id => { const el = document.getElementById(id); if(el) el.checked = true; });
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
