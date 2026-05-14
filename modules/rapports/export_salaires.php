<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
requirePerm('rapports', 'export');

$db        = getDB();
$versionId = (int)($_GET['version_id'] ?? 0);
$format    = $_GET['format'] ?? 'pdf';
if (!$versionId) die('Version invalide.');

$stmt = $db->prepare("SELECT * FROM planning_versions WHERE id=?");
$stmt->execute([$versionId]);
$version = $stmt->fetch();
if (!$version) die('Version introuvable.');

$mois   = $version['mois'];
$annee  = $version['annee'];
$taux   = getTauxHoraires();
$agents = $db->query("SELECT * FROM agents WHERE actif=1 ORDER BY nom,prenom")->fetchAll();
$params = getAllParams();

$resultats = [];
foreach ($agents as $ag) {
    $stmtL = $db->prepare("
        SELECT SUM(min_normal) n, SUM(min_nuit) nu, SUM(min_dimanche) d,
               SUM(min_ferie_normal) fn, SUM(min_ferie_dimanche) fd, SUM(min_ferie_nuit) fnu
        FROM planning_lignes WHERE version_id=? AND agent_id=?
    ");
    $stmtL->execute([$versionId, $ag['id']]);
    $mins = $stmtL->fetch();
    $heures = [
        'normal'        => minutesToHeures((int)$mins['n']),
        'nuit'          => minutesToHeures((int)$mins['nu']),
        'dimanche'      => minutesToHeures((int)$mins['d']),
        'ferie_normal'  => minutesToHeures((int)$mins['fn']),
        'ferie_dimanche'=> minutesToHeures((int)$mins['fd']),
        'ferie_nuit'    => minutesToHeures((int)$mins['fnu']),
    ];
    $tot = array_sum($heures);
    if ($tot > 0) {
        $sal = 0;
        foreach ($heures as $t => $h) $sal += $h * ($taux[$t] ?? 0);
        $resultats[] = ['agent' => $ag, 'heures' => $heures, 'total' => $tot, 'salaire' => round($sal, 2)];
    }
}

// CSV
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="salaires_' . sprintf('%02d', $mois) . '_' . $annee . '_v' . $version['version'] . '.csv"');
    $f = fopen('php://output', 'w');
    fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($f, ['Agent','Matricule','Normal (h)','Nuit (h)','Dimanche (h)','Férié (h)','Fér.Dim. (h)','Nuit Fér. (h)','Total (h)','Salaire (€)'], ';');
    foreach ($resultats as $r) {
        $ag = $r['agent'];
        fputcsv($f, [
            $ag['prenom'] . ' ' . $ag['nom'],
            $ag['matricule'] ?? '',
            number_format($r['heures']['normal'],        2, '.', ''),
            number_format($r['heures']['nuit'],          2, '.', ''),
            number_format($r['heures']['dimanche'],      2, '.', ''),
            number_format($r['heures']['ferie_normal'],  2, '.', ''),
            number_format($r['heures']['ferie_dimanche'],2, '.', ''),
            number_format($r['heures']['ferie_nuit'],    2, '.', ''),
            number_format($r['total'],                   2, '.', ''),
            number_format($r['salaire'],                 2, '.', ''),
        ], ';');
    }
    fclose($f);
    exit;
}

// Logo
$logoB64 = '';
$logoFile = APP_ROOT . '/assets/img/' . ($params['logo_principal'] ?? 'logo.png');
if (file_exists($logoFile)) {
    $ext  = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
    $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
    $logoB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
}

$typeLabels = ['normal'=>'Normal','nuit'=>'Nuit','dimanche'=>'Dimanche','ferie_normal'=>'Férié','ferie_dimanche'=>'Fér.Dim.','ferie_nuit'=>'Nuit Fér.'];
$totaux = array_fill_keys(array_keys($typeLabels), 0);
$totaux['total'] = 0; $totaux['salaire'] = 0;
foreach ($resultats as $r) {
    foreach ($typeLabels as $k => $_) $totaux[$k] += $r['heures'][$k];
    $totaux['total']   += $r['total'];
    $totaux['salaire'] += $r['salaire'];
}

ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<?= pdfBaseStyle() ?>
<style>
.type-nuit    { color: #4f46e5; }
.type-dim     { color: #dc2626; }
.type-ferie   { color: #92400e; }
.salaire-col  { color: #16a34a; font-weight: 700; font-size: 9.5pt; }
</style>
</head>
<body>
<div class="page">

  <div class="pdf-header">
    <div>
      <?php if ($logoB64): ?>
      <img src="<?= $logoB64 ?>" style="height:35px;margin-bottom:5px"><br>
      <?php endif; ?>
      <h1>Récapitulatif salaires — <?= htmlspecialchars(formatMois($mois, $annee)) ?></h1>
      <p><?= htmlspecialchars($params['entreprise_nom'] ?? '') ?> · SIRET <?= htmlspecialchars($params['entreprise_siret'] ?? '') ?></p>
      <p>Version planning : V<?= $version['version'] ?> · Généré le <?= date('d/m/Y à H:i') ?></p>
    </div>
    <div style="text-align:right">
      <div style="background:#1a2332;color:#c9a84c;padding:8px 14px;border-radius:6px;font-size:8pt;font-weight:700">
        <?= count($resultats) ?> agents<br>
        <?= number_format($totaux['total'], 2) ?> h total<br>
        <span style="font-size:11pt"><?= number_format($totaux['salaire'], 2) ?> €</span>
      </div>
    </div>
  </div>

  <!-- Taux appliqués -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;font-size:7pt">
    <?php foreach ($typeLabels as $k => $l): ?>
    <span style="background:#f0f2f5;padding:2px 7px;border-radius:10px">
      <strong><?= $l ?></strong> : <?= number_format($taux[$k] ?? 0, 2) ?> €/h
    </span>
    <?php endforeach; ?>
  </div>

  <table>
    <thead>
      <tr>
        <th>Agent</th>
        <?php foreach ($typeLabels as $l): ?><th><?= $l ?></th><?php endforeach; ?>
        <th>Total h.</th>
        <th>Salaire €</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($resultats as $r):
      $ag = $r['agent'];
    ?>
    <tr>
      <td>
        <?= htmlspecialchars($ag['prenom'] . ' ' . $ag['nom']) ?>
        <?php if ($ag['matricule']): ?>
        <br><span style="font-size:6.5pt;color:#999"><?= htmlspecialchars($ag['matricule']) ?></span>
        <?php endif; ?>
      </td>
      <?php foreach ($typeLabels as $k => $_): ?>
      <td><?= $r['heures'][$k] > 0 ? number_format($r['heures'][$k], 2) . 'h' : '—' ?></td>
      <?php endforeach; ?>
      <td><strong><?= number_format($r['total'], 2) ?>h</strong></td>
      <td class="salaire-col"><?= number_format($r['salaire'], 2) ?> €</td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td><strong>TOTAL</strong></td>
        <?php foreach ($typeLabels as $k => $_): ?>
        <td><strong><?= $totaux[$k] > 0 ? number_format($totaux[$k], 2) . 'h' : '—' ?></strong></td>
        <?php endforeach; ?>
        <td><strong><?= number_format($totaux['total'], 2) ?>h</strong></td>
        <td class="salaire-col" style="font-size:10pt"><?= number_format($totaux['salaire'], 2) ?> €</td>
      </tr>
    </tfoot>
  </table>

  <div class="pdf-footer">
    <span>Document confidentiel — usage interne</span>
    <span><?= htmlspecialchars($params['entreprise_nom'] ?? '') ?> · <?= date('d/m/Y') ?></span>
  </div>

</div>
</body>
</html>
<?php
$html = ob_get_clean();
renderPdf($html, 'salaires_' . sprintf('%02d', $mois) . '_' . $annee . '.pdf');
