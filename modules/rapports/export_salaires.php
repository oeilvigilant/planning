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

$mois      = $version['mois'];
$annee     = $version['annee'];
$taux      = getTauxHoraires();
$agents    = $db->query("SELECT * FROM agents WHERE actif=1 ORDER BY nom,prenom")->fetchAll();
$params    = getAllParams();
$primesCfg = getPrimesConfig();
$minPanier = (int)round($primesCfg['panier_min_heures'] * 60);

// Charger le contrat actif de chaque agent (pour "Contrat (h)" et le détail mensuel complet)
$contratsMap = [];
$agentIdsAll = array_column($agents, 'id');
if ($agentIdsAll) {
    $ph = implode(',', array_fill(0, count($agentIdsAll), '?'));
    $stCtr = $db->prepare("SELECT agent_id, total_heures_contrat, heures_unite, date_debut, date_fin FROM contrats WHERE agent_id IN ($ph) AND statut='actif' ORDER BY created_at DESC");
    $stCtr->execute($agentIdsAll);
    foreach ($stCtr->fetchAll() as $cr) {
        if (!isset($contratsMap[$cr['agent_id']])) $contratsMap[$cr['agent_id']] = $cr;
    }
}

// Colonnes disponibles (ordre d'affichage)
$allCols = [
    'h_normal'             => 'Normal (h)',
    'h_nuit'               => 'Nuit (h)',
    'h_dimanche'           => 'Dimanche (h)',
    'h_nuit_dimanche'      => 'Nuit Dim. (h)',
    'h_ferie_normal'       => 'Férié (h)',
    'h_ferie_nuit'         => 'Nuit Fér. (h)',
    'total_h'              => 'Total (h)',
    'h_contrat'            => 'Contrat (h)',
    'detail_contrat_mois'  => 'Détail contrat complet (h/mois prévu)',
    'brut'                 => 'Brut (€)',
    'cotisations'          => 'Cotis. sal. (€)',
    'prime_panier'         => 'Panier (€)',
    'prime_habillage'      => 'Habillage (€)',
    'prime_entretien'      => 'Entretien (€)',
    'net_estime'           => 'Net estimé (€)',
];

// Colonnes actives (intersection ordonnée avec la définition)
$requestedCols = (isset($_GET['cols']) && is_array($_GET['cols']))
    ? array_values(array_intersect(array_keys($allCols), $_GET['cols']))
    : array_keys($allCols);

// ─── Helpers ────────────────────────────────────────────────────────────────
function salExportColVal(array $r, string $col): float {
    switch ($col) {
        case 'h_normal':        return $r['heures']['normal'];
        case 'h_nuit':          return $r['heures']['nuit'];
        case 'h_dimanche':      return $r['heures']['dimanche'];
        case 'h_nuit_dimanche': return $r['heures']['nuit_dimanche'];
        case 'h_ferie_normal':  return $r['heures']['ferie_normal'];
        case 'h_ferie_nuit':    return $r['heures']['ferie_nuit'];
        case 'total_h':         return $r['total'];
        case 'brut':            return $r['paie']['brut'];
        case 'cotisations':     return $r['paie']['cotisations'];
        case 'prime_panier':    return $r['paie']['panier'];
        case 'prime_habillage': return $r['paie']['habillage'];
        case 'prime_entretien': return $r['paie']['entretien'];
        case 'net_estime':      return $r['paie']['net_total'];
        case 'h_contrat':       return (float)($r['agent']['total_heures_contrat'] ?? 0);
        default:                return 0.0;
    }
}
function salExportIsHeure(string $col): bool {
    return in_array($col, ['h_normal','h_nuit','h_dimanche','h_nuit_dimanche','h_ferie_normal','h_ferie_nuit','total_h','h_contrat']);
}
function salExportIsTexte(string $col): bool {
    return $col === 'detail_contrat_mois';
}
// Détail complet du contrat : heures prévues, mois par mois, sur toute la durée du contrat
function salExportDetailContratMois(?array $contrat): string {
    if (!$contrat || empty($contrat['date_debut']) || empty($contrat['date_fin'])) return '—';
    $moisNoms = ['','Janv.','Févr.','Mars','Avr.','Mai','Juin','Juil.','Août','Sept.','Oct.','Nov.','Déc.'];
    $cur = new DateTime(substr($contrat['date_debut'], 0, 7) . '-01');
    $end = new DateTime(substr($contrat['date_fin'],   0, 7) . '-01');
    $parts = [];
    while ($cur <= $end) {
        $m = (int)$cur->format('n');
        $y = (int)$cur->format('Y');
        $v = calculerHeuresContratMois($contrat, $m, $y);
        $parts[] = $moisNoms[$m] . ' ' . $y . ' : ' . number_format($v['mois_prorata'], 1) . 'h';
        $cur->modify('+1 month');
    }
    return implode(' · ', $parts);
}
function salExportColCss(string $col): string {
    switch ($col) {
        case 'net_estime':      return 'col-net';
        case 'cotisations':     return 'col-cotis';
        case 'brut':            return 'col-brut';
        case 'prime_panier':
        case 'prime_habillage':
        case 'prime_entretien': return 'col-prime';
        default:                return '';
    }
}

// ─── Résultats avec paie ────────────────────────────────────────────────────
$resultats = [];
foreach ($agents as $ag) {
    $stmtL = $db->prepare("
        SELECT SUM(min_normal) n, SUM(min_nuit) nu, SUM(min_dimanche) d,
               SUM(min_nuit_dimanche) nd,
               SUM(min_ferie_normal) fn, SUM(min_ferie_nuit) fnu,
               SUM(CASE WHEN (min_normal+min_nuit+min_dimanche+min_nuit_dimanche+min_ferie_normal+min_ferie_nuit) >= ? THEN 1 ELSE 0 END) AS nb_vacations_panier
        FROM planning_lignes WHERE version_id=? AND agent_id=?
    ");
    $stmtL->execute([$minPanier, $versionId, $ag['id']]);
    $mins = $stmtL->fetch();
    $heures = [
        'normal'        => minutesToHeures((int)$mins['n']),
        'nuit'          => minutesToHeures((int)$mins['nu']),
        'dimanche'      => minutesToHeures((int)$mins['d']),
        'nuit_dimanche' => minutesToHeures((int)$mins['nd']),
        'ferie_normal'  => minutesToHeures((int)$mins['fn']),
        'ferie_nuit'    => minutesToHeures((int)$mins['fnu']),
    ];
    $tot = array_sum($heures);
    if ($tot > 0) {
        $sal = 0;
        foreach ($heures as $t => $h) $sal += $h * ($taux[$t] ?? 0);
        $brut = round($sal, 2);
        $paie = calculerPaie($brut, (int)$mins['nb_vacations_panier']);
        $resultats[] = ['agent' => $ag, 'heures' => $heures, 'total' => $tot, 'salaire' => $brut, 'paie' => $paie, 'contrat' => $contratsMap[$ag['id']] ?? null];
    }
}

// ─── CSV / Excel ────────────────────────────────────────────────────────────
if ($format === 'csv' || $format === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="salaires_' . sprintf('%02d', $mois) . '_' . $annee . '_v' . $version['version'] . '.csv"');
    $f = fopen('php://output', 'w');
    fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));
    $headers = ['Agent', 'Matricule'];
    foreach ($requestedCols as $col) $headers[] = $allCols[$col];
    fputcsv($f, $headers, ';');
    foreach ($resultats as $r) {
        $ag  = $r['agent'];
        $row = [$ag['prenom'] . ' ' . $ag['nom'], $ag['matricule'] ?? ''];
        foreach ($requestedCols as $col) {
            $row[] = salExportIsTexte($col)
                ? salExportDetailContratMois($r['contrat'])
                : number_format(salExportColVal($r, $col), 2, '.', '');
        }
        fputcsv($f, $row, ';');
    }
    fclose($f);
    exit;
}

// ─── PDF ────────────────────────────────────────────────────────────────────
$orientation = (count($requestedCols) > 7 || in_array('detail_contrat_mois', $requestedCols, true)) ? 'landscape' : 'portrait';

$logoB64  = '';
$logoFile = APP_ROOT . '/assets/img/' . ($params['logo_principal'] ?? 'logo.png');
if (file_exists($logoFile)) {
    $ext     = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
    $mime    = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
    $logoB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
}

// Totaux par colonne active (les colonnes texte n'ont pas de total numérique)
$totaux = array_fill_keys($requestedCols, 0.0);
foreach ($resultats as $r) {
    foreach ($requestedCols as $col) {
        if (salExportIsTexte($col)) continue;
        $totaux[$col] += salExportColVal($r, $col);
    }
}

$typeLabelsMap = [
    'h_normal'        => ['label' => 'Normal',    'taux_key' => 'normal'],
    'h_nuit'          => ['label' => 'Nuit',      'taux_key' => 'nuit'],
    'h_dimanche'      => ['label' => 'Dimanche',  'taux_key' => 'dimanche'],
    'h_nuit_dimanche' => ['label' => 'Nuit Dim.', 'taux_key' => 'nuit_dimanche'],
    'h_ferie_normal'  => ['label' => 'Férié',     'taux_key' => 'ferie_normal'],
    'h_ferie_nuit'    => ['label' => 'Nuit Fér.', 'taux_key' => 'ferie_nuit'],
];

$nCols   = count($requestedCols);
$fntData = $nCols > 9 ? '7' : '8';
$fntHead = $nCols > 9 ? '6.5' : '7.5';

ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<?= pdfBaseStyle() ?>
<style>
.col-net   { color: #16a34a; font-weight: 700; }
.col-cotis { color: #dc2626; }
.col-prime { color: #8b5cf6; }
.col-brut  { color: #374151; font-weight: 600; }
th.col-net   { color: #a7f3d0; }
th.col-cotis { color: #fca5a5; }
th.col-prime { color: #ddd6fe; }
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
        <?= count($resultats) ?> agents
        <?php if (in_array('total_h', $requestedCols)): ?><br><?= number_format($totaux['total_h'], 2) ?> h<?php endif; ?>
        <br>
        <?php if (in_array('net_estime', $requestedCols)): ?>
        <span style="font-size:11pt"><?= number_format($totaux['net_estime'], 2) ?> € net</span>
        <?php elseif (in_array('brut', $requestedCols)): ?>
        <span style="font-size:11pt"><?= number_format($totaux['brut'], 2) ?> € brut</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php $hColsActive = array_intersect($requestedCols, array_keys($typeLabelsMap));
  if (!empty($hColsActive)): ?>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px;font-size:7pt">
    <?php foreach ($hColsActive as $col): $info = $typeLabelsMap[$col]; ?>
    <span style="background:#f0f2f5;padding:2px 7px;border-radius:10px">
      <strong><?= $info['label'] ?></strong> : <?= number_format($taux[$info['taux_key']] ?? 0, 2) ?> €/h
    </span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <table>
    <thead>
      <tr>
        <th style="text-align:left">Agent</th>
        <?php foreach ($requestedCols as $col): ?>
        <th class="<?= salExportColCss($col) ?>" style="font-size:<?= $fntHead ?>pt"><?= $allCols[$col] ?></th>
        <?php endforeach; ?>
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
      <?php foreach ($requestedCols as $col):
        if (salExportIsTexte($col)):
      ?>
      <td style="font-size:<?= $fntData ?>pt;text-align:left;white-space:normal">
        <?= htmlspecialchars(salExportDetailContratMois($r['contrat'])) ?>
      </td>
      <?php
          continue;
        endif;
        $v   = salExportColVal($r, $col);
        $isH = salExportIsHeure($col);
        $css = salExportColCss($col);
        $pfx = $col === 'cotisations' ? '−' : '';
        $sfx = $isH ? 'h' : ' €';
      ?>
      <td class="<?= $css ?>" style="font-size:<?= $fntData ?>pt">
        <?= $v > 0 ? $pfx . number_format($v, 2) . $sfx : '—' ?>
      </td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td><strong>TOTAL</strong></td>
        <?php foreach ($requestedCols as $col):
          if (salExportIsTexte($col)):
        ?>
        <td>—</td>
        <?php
            continue;
          endif;
          $v   = $totaux[$col];
          $isH = salExportIsHeure($col);
          $pfx = $col === 'cotisations' ? '−' : '';
          $sfx = $isH ? 'h' : ' €';
          $css = salExportColCss($col);
        ?>
        <td class="<?= $css ?>"><strong><?= $v > 0 ? $pfx . number_format($v, 2) . $sfx : '—' ?></strong></td>
        <?php endforeach; ?>
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
renderPdf($html, 'salaires_' . sprintf('%02d', $mois) . '_' . $annee . '.pdf', $orientation);
