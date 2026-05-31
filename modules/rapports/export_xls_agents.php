<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('rapports', 'export');

$db = getDB();

$dateDebut = $_POST['date_debut'] ?? '';
$dateFin   = $_POST['date_fin']   ?? '';
$agentIds  = array_values(array_filter(array_map('intval', (array)($_POST['agents'] ?? [])), fn($id) => $id > 0));

if (!$dateDebut || !$dateFin || empty($agentIds)) {
    die('Paramètres manquants. <a href="index.php">Retour</a>');
}

$dateDebut = date('Y-m-d', strtotime($dateDebut));
$dateFin   = date('Y-m-d', strtotime($dateFin));

if ($dateDebut > $dateFin) {
    die('La date de début doit être antérieure à la date de fin. <a href="index.php">Retour</a>');
}

$taux = getTauxHoraires();

$types = [
    'normal'         => 'Normal',
    'nuit'           => 'Nuit',
    'dimanche'       => 'Dimanche',
    'ferie_normal'   => 'Férié',
    'ferie_dimanche' => 'Fér.Dim.',
    'ferie_nuit'     => 'Nuit Fér.',
];

// Query — only current versions to avoid duplicates
$placeholders = implode(',', array_fill(0, count($agentIds), '?'));
$stmt = $db->prepare("
    SELECT
        a.id, a.nom, a.prenom, a.matricule,
        SUM(pl.min_normal)         AS min_normal,
        SUM(pl.min_nuit)           AS min_nuit,
        SUM(pl.min_dimanche)       AS min_dimanche,
        SUM(pl.min_ferie_normal)   AS min_ferie_normal,
        SUM(pl.min_ferie_dimanche) AS min_ferie_dimanche,
        SUM(pl.min_ferie_nuit)     AS min_ferie_nuit
    FROM agents a
    JOIN planning_lignes pl   ON pl.agent_id  = a.id
    JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
    WHERE pl.agent_id IN ($placeholders)
      AND pl.date_travail BETWEEN ? AND ?
    GROUP BY a.id, a.nom, a.prenom, a.matricule
    ORDER BY a.nom, a.prenom
");
$stmt->execute(array_merge($agentIds, [$dateDebut, $dateFin]));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Détail journalier — pour feuille "Formulaire de calcul"
$stmtLines = $db->prepare("
    SELECT
        a.id AS agent_id, a.nom, a.prenom, a.matricule,
        pl.date_travail, pl.heure_debut, pl.heure_fin, pl.depasse_minuit, pl.note,
        pl.min_normal, pl.min_nuit, pl.min_dimanche,
        pl.min_ferie_normal, pl.min_ferie_dimanche, pl.min_ferie_nuit
    FROM agents a
    JOIN planning_lignes pl ON pl.agent_id = a.id
    JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
    WHERE pl.agent_id IN ($placeholders)
      AND pl.date_travail BETWEEN ? AND ?
    ORDER BY a.nom, a.prenom, pl.date_travail
");
$stmtLines->execute(array_merge($agentIds, [$dateDebut, $dateFin]));

$detailParAgent = [];
foreach ($stmtLines->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $aid = $l['agent_id'];
    if (!isset($detailParAgent[$aid])) {
        $detailParAgent[$aid] = ['nom'=>$l['nom'],'prenom'=>$l['prenom'],'matricule'=>$l['matricule']??'','lignes'=>[]];
    }
    $montantJ = 0;
    foreach (['normal','nuit','dimanche','ferie_normal','ferie_dimanche','ferie_nuit'] as $t) {
        $montantJ += round(((int)$l["min_$t"] / 60) * ($taux[$t] ?? 0), 2);
    }
    $detailParAgent[$aid]['lignes'][] = array_merge($l, ['montant' => $montantJ]);
}

$anneeDebut   = (int)substr($dateDebut, 0, 4);
$anneeFin     = (int)substr($dateFin, 0, 4);
$feriesDetail = getJoursFeries($anneeDebut);
if ($anneeFin !== $anneeDebut) $feriesDetail = array_merge($feriesDetail, getJoursFeries($anneeFin));
$nomsJours = ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];

// Build results
$resultats = [];
$totaux    = array_fill_keys(array_keys($types), ['h' => 0.0, 'm' => 0.0]);
$totaux['total_h']          = 0.0;
$totaux['total_m']          = 0.0;
$totaux['cotis_salariale']  = 0.0;
$totaux['net']              = 0.0;
$totaux['cotis_patronale']  = 0.0;
$totaux['cout_employeur']   = 0.0;

foreach ($rows as $row) {
    $r = [
        'nom'      => $row['nom'],
        'prenom'   => $row['prenom'],
        'matricule'=> $row['matricule'] ?? '',
        'types'    => [],
        'total_h'  => 0.0,
        'total_m'  => 0.0,
    ];
    foreach (array_keys($types) as $t) {
        $h = minutesToHeures((int)($row["min_$t"] ?? 0));
        $m = round($h * ($taux[$t] ?? 0), 2);
        $r['types'][$t] = ['h' => $h, 'm' => $m];
        $r['total_h'] += $h;
        $r['total_m'] += $m;
        $totaux[$t]['h'] += $h;
        $totaux[$t]['m'] += $m;
    }
    $r['total_h'] = round($r['total_h'], 2);
    $r['total_m'] = round($r['total_m'], 2);

    // Social calculations
    $social = calculerCotisations($r['total_m']);
    $r['cotis_salariale'] = $social['salarial'];
    $r['net']             = $social['net'];
    $r['cotis_patronale'] = $social['patronal'];
    $r['cout_employeur']  = $social['cout_employeur'];
    $r['cotis_detail']    = $social['detail'];

    $totaux['total_h']         += $r['total_h'];
    $totaux['total_m']         += $r['total_m'];
    $totaux['cotis_salariale'] += $r['cotis_salariale'];
    $totaux['net']             += $r['net'];
    $totaux['cotis_patronale'] += $r['cotis_patronale'];
    $totaux['cout_employeur']  += $r['cout_employeur'];

    $resultats[] = $r;
}
foreach (['total_m','cotis_salariale','net','cotis_patronale','cout_employeur'] as $k) {
    $totaux[$k] = round($totaux[$k], 2);
}

$dfDebut  = date('d/m/Y', strtotime($dateDebut));
$dfFin    = date('d/m/Y', strtotime($dateFin));
$tauxInfo = implode(' | ', array_map(
    fn($k, $l) => "$l : " . number_format($taux[$k] ?? 0, 2, ',', '') . ' €/h',
    array_keys($types), array_values($types)
));

// Cotisations taux info for display
$cotisLignes = getCotisationsTaux();
$tSalPct = array_sum(array_column($cotisLignes, 'taux_salarial'));
$tPatPct = array_sum(array_column($cotisLignes, 'taux_patronal'));

$filename = 'heures_agents_' . str_replace('-', '', $dateDebut) . '_' . str_replace('-', '', $dateFin) . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

function xe(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
function strCell(string $val, string $style = ''): string {
    $s = $style ? " ss:StyleID=\"$style\"" : '';
    return "<Cell$s><Data ss:Type=\"String\">" . xe($val) . "</Data></Cell>\n";
}
function numCell(float $val, string $style): string {
    return "<Cell ss:StyleID=\"$style\"><Data ss:Type=\"Number\">" . number_format($val, 2, '.', '') . "</Data></Cell>\n";
}
function pctCell(float $val, string $style): string {
    return "<Cell ss:StyleID=\"$style\"><Data ss:Type=\"Number\">" . number_format($val, 3, '.', '') . "</Data></Cell>\n";
}
function emptyCell(string $style = ''): string {
    $s = $style ? " ss:StyleID=\"$style\"" : '';
    return "<Cell$s/>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
          xmlns:x="urn:schemas-microsoft-com:office:excel">

  <Styles>
    <!-- Base -->
    <Style ss:ID="s_title">
      <Font ss:Bold="1" ss:Size="13" ss:Color="#1A2332"/>
    </Style>
    <Style ss:ID="s_info">
      <Font ss:Italic="1" ss:Size="8" ss:Color="#6B7280"/>
    </Style>
    <!-- Column headers by category -->
    <Style ss:ID="s_hdr">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#1A2332" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_hdr_nuit">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#4338CA" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_hdr_dim">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#DC2626" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_hdr_ferie">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#92400E" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_hdr_total">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#C9A84C" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_hdr_sal">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#DC2626" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_hdr_net">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#16A34A" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_hdr_pat">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#EA580C" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_hdr_cout">
      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#7C3AED" ss:Pattern="Solid"/>
    </Style>
    <!-- Data cells -->
    <Style ss:ID="s_agent">
      <Font ss:Bold="1"/>
    </Style>
    <Style ss:ID="s_mat">
      <Font ss:Color="#6B7280"/>
      <Alignment ss:Horizontal="Center"/>
    </Style>
    <Style ss:ID="s_h">
      <Alignment ss:Horizontal="Right"/>
      <NumberFormat ss:Format="0.00&quot; h&quot;"/>
    </Style>
    <Style ss:ID="s_eur">
      <Alignment ss:Horizontal="Right"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#F0FDF4" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_total_h">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1"/>
      <NumberFormat ss:Format="0.00&quot; h&quot;"/>
      <Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_total_eur">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#FEF9C3" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_sal_eur">
      <Alignment ss:Horizontal="Right"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#FEF2F2" ss:Pattern="Solid"/>
      <Font ss:Color="#DC2626"/>
    </Style>
    <Style ss:ID="s_net_eur">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#15803D"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#F0FDF4" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_pat_eur">
      <Alignment ss:Horizontal="Right"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#FFF7ED" ss:Pattern="Solid"/>
      <Font ss:Color="#EA580C"/>
    </Style>
    <Style ss:ID="s_cout_eur">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#7C3AED"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#F5F3FF" ss:Pattern="Solid"/>
    </Style>
    <!-- Footer row -->
    <Style ss:ID="s_foot">
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#1A2332" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_foot_h">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <NumberFormat ss:Format="0.00&quot; h&quot;"/>
      <Interior ss:Color="#1A2332" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_foot_eur">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#C9A84C" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_foot_sal">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#DC2626" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_foot_net">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#16A34A" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_foot_pat">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#EA580C" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s_foot_cout">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#7C3AED" ss:Pattern="Solid"/>
    </Style>
    <!-- Sheet 2 specific -->
    <Style ss:ID="s2_agent_hdr">
      <Font ss:Bold="1" ss:Color="#FFFFFF"/>
      <Interior ss:Color="#1A2332" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_total">
      <Font ss:Bold="1"/>
      <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_total_sal">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#DC2626"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#FEF2F2" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_total_pat">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#EA580C"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#FFF7ED" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_net">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#15803D"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#F0FDF4" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_cout">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#7C3AED"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#F5F3FF" ss:Pattern="Solid"/>
    </Style>
    <!-- Feuille 3 spécifique -->
    <Style ss:ID="s_zero">
      <Alignment ss:Horizontal="Center"/>
      <Font ss:Color="#D1D5DB"/>
      <NumberFormat ss:Format="0.00&quot; h&quot;"/>
    </Style>
    <Style ss:ID="s2_ferie_date">
      <Alignment ss:Horizontal="Center"/>
      <Font ss:Bold="1" ss:Color="#92400E"/>
      <Interior ss:Color="#FEF9C3" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_dim_date">
      <Alignment ss:Horizontal="Center"/>
      <Font ss:Bold="1" ss:Color="#DC2626"/>
      <Interior ss:Color="#FEF2F2" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_subtotal_lbl">
      <Font ss:Bold="1" ss:Italic="1" ss:Color="#374151"/>
      <Interior ss:Color="#F1F5F9" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_subtotal_h">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1"/>
      <NumberFormat ss:Format="0.00&quot; h&quot;"/>
      <Interior ss:Color="#EFF6FF" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_subtotal_eur">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Bold="1" ss:Color="#15803D"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Interior ss:Color="#F0FDF4" ss:Pattern="Solid"/>
    </Style>
    <Style ss:ID="s2_pct">
      <Alignment ss:Horizontal="Right"/>
      <Font ss:Color="#6B7280"/>
      <NumberFormat ss:Format="0.000&quot; %&quot;"/>
    </Style>
    <Style ss:ID="s2_eur_sal">
      <Alignment ss:Horizontal="Right"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Font ss:Color="#DC2626"/>
    </Style>
    <Style ss:ID="s2_eur_pat">
      <Alignment ss:Horizontal="Right"/>
      <NumberFormat ss:Format="#,##0.00 &quot;€&quot;"/>
      <Font ss:Color="#EA580C"/>
    </Style>
    <Style ss:ID="s2_cat">
      <Alignment ss:Horizontal="Center"/>
      <Font ss:Size="8" ss:Color="#6B7280"/>
    </Style>
  </Styles>

  <!-- ═══════════════════════════════════════════════════════════
       FEUILLE 1 — Résumé heures & salaires
  ════════════════════════════════════════════════════════════════ -->
  <Worksheet ss:Name="Heures &amp; Salaires">
    <Table>
      <Column ss:Width="155"/>
      <Column ss:Width="85"/>
      <Column ss:Width="62" ss:Span="11"/>
      <Column ss:Width="78"/>
      <Column ss:Width="88"/>
      <!-- Social columns -->
      <Column ss:Width="95"/>
      <Column ss:Width="95"/>
      <Column ss:Width="95"/>
      <Column ss:Width="100"/>

      <!-- Row 1 : Titre -->
      <Row ss:Height="30">
        <Cell ss:MergeAcross="19" ss:StyleID="s_title">
          <Data ss:Type="String">Rapport Heures &amp; Salaires Agents — du <?= xe($dfDebut) ?> au <?= xe($dfFin) ?></Data>
        </Cell>
      </Row>

      <!-- Row 2 : Taux horaires -->
      <Row ss:Height="16">
        <Cell ss:MergeAcross="19" ss:StyleID="s_info">
          <Data ss:Type="String">Taux horaires : <?= xe($tauxInfo) ?></Data>
        </Cell>
      </Row>

      <!-- Row 3 : Taux cotisations -->
      <Row ss:Height="16">
        <Cell ss:MergeAcross="19" ss:StyleID="s_info">
          <Data ss:Type="String">Cotisations (IDCC 1351) : Salariales <?= number_format($tSalPct, 2) ?>% | Patronales <?= number_format($tPatPct, 2) ?>% — Voir onglet "Cotisations détail" pour le détail</Data>
        </Cell>
      </Row>

      <!-- Row 4 : espacement -->
      <Row ss:Height="6"/>

      <!-- Row 5 : En-têtes colonnes -->
      <Row ss:Height="42">
        <?= strCell('Agent', 's_hdr') ?>
        <?= strCell('Matricule', 's_hdr') ?>
        <?= strCell('Normal (h)', 's_hdr') ?>
        <?= strCell('Normal (€)', 's_hdr') ?>
        <?= strCell('Nuit (h)', 's_hdr_nuit') ?>
        <?= strCell('Nuit (€)', 's_hdr_nuit') ?>
        <?= strCell('Dimanche (h)', 's_hdr_dim') ?>
        <?= strCell('Dimanche (€)', 's_hdr_dim') ?>
        <?= strCell('Férié (h)', 's_hdr_ferie') ?>
        <?= strCell('Férié (€)', 's_hdr_ferie') ?>
        <?= strCell('Fér.Dim. (h)', 's_hdr_ferie') ?>
        <?= strCell('Fér.Dim. (€)', 's_hdr_ferie') ?>
        <?= strCell('Nuit Fér. (h)', 's_hdr_ferie') ?>
        <?= strCell('Nuit Fér. (€)', 's_hdr_ferie') ?>
        <?= strCell('TOTAL (h)', 's_hdr_total') ?>
        <?= strCell('BRUT (€)', 's_hdr_total') ?>
        <?= strCell('Cotis. sal. (€)', 's_hdr_sal') ?>
        <?= strCell('NET (€)', 's_hdr_net') ?>
        <?= strCell('Cotis. pat. (€)', 's_hdr_pat') ?>
        <?= strCell('Coût employeur (€)', 's_hdr_cout') ?>
      </Row>

      <?php if (empty($resultats)): ?>
      <Row ss:Height="24">
        <Cell ss:MergeAcross="19"><Data ss:Type="String">Aucune donnée pour la période et les agents sélectionnés.</Data></Cell>
      </Row>
      <?php else: ?>

      <!-- Lignes agents -->
      <?php foreach ($resultats as $r): ?>
      <Row ss:Height="20">
        <?= strCell($r['prenom'] . ' ' . $r['nom'], 's_agent') ?>
        <?= strCell($r['matricule'], 's_mat') ?>
        <?= numCell($r['types']['normal']['h'],         's_h') ?>
        <?= numCell($r['types']['normal']['m'],         's_eur') ?>
        <?= numCell($r['types']['nuit']['h'],           's_h') ?>
        <?= numCell($r['types']['nuit']['m'],           's_eur') ?>
        <?= numCell($r['types']['dimanche']['h'],       's_h') ?>
        <?= numCell($r['types']['dimanche']['m'],       's_eur') ?>
        <?= numCell($r['types']['ferie_normal']['h'],   's_h') ?>
        <?= numCell($r['types']['ferie_normal']['m'],   's_eur') ?>
        <?= numCell($r['types']['ferie_dimanche']['h'], 's_h') ?>
        <?= numCell($r['types']['ferie_dimanche']['m'], 's_eur') ?>
        <?= numCell($r['types']['ferie_nuit']['h'],     's_h') ?>
        <?= numCell($r['types']['ferie_nuit']['m'],     's_eur') ?>
        <?= numCell($r['total_h'],                      's_total_h') ?>
        <?= numCell($r['total_m'],                      's_total_eur') ?>
        <?= numCell($r['cotis_salariale'],              's_sal_eur') ?>
        <?= numCell($r['net'],                          's_net_eur') ?>
        <?= numCell($r['cotis_patronale'],              's_pat_eur') ?>
        <?= numCell($r['cout_employeur'],               's_cout_eur') ?>
      </Row>
      <?php endforeach; ?>

      <!-- Ligne TOTAL -->
      <Row ss:Height="24">
        <?= strCell('TOTAL', 's_foot') ?>
        <?= emptyCell('s_foot') ?>
        <?= numCell($totaux['normal']['h'],         's_foot_h') ?>
        <?= numCell($totaux['normal']['m'],         's_foot_eur') ?>
        <?= numCell($totaux['nuit']['h'],           's_foot_h') ?>
        <?= numCell($totaux['nuit']['m'],           's_foot_eur') ?>
        <?= numCell($totaux['dimanche']['h'],       's_foot_h') ?>
        <?= numCell($totaux['dimanche']['m'],       's_foot_eur') ?>
        <?= numCell($totaux['ferie_normal']['h'],   's_foot_h') ?>
        <?= numCell($totaux['ferie_normal']['m'],   's_foot_eur') ?>
        <?= numCell($totaux['ferie_dimanche']['h'], 's_foot_h') ?>
        <?= numCell($totaux['ferie_dimanche']['m'], 's_foot_eur') ?>
        <?= numCell($totaux['ferie_nuit']['h'],     's_foot_h') ?>
        <?= numCell($totaux['ferie_nuit']['m'],     's_foot_eur') ?>
        <?= numCell($totaux['total_h'],             's_foot_h') ?>
        <?= numCell($totaux['total_m'],             's_foot_eur') ?>
        <?= numCell($totaux['cotis_salariale'],     's_foot_sal') ?>
        <?= numCell($totaux['net'],                 's_foot_net') ?>
        <?= numCell($totaux['cotis_patronale'],     's_foot_pat') ?>
        <?= numCell($totaux['cout_employeur'],      's_foot_cout') ?>
      </Row>

      <?php endif; ?>
    </Table>
    <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
      <FreezePanes/>
      <FrozenNoSplit/>
      <SplitHorizontal>5</SplitHorizontal>
      <TopRowBottomPane>5</TopRowBottomPane>
      <ActivePane>2</ActivePane>
    </WorksheetOptions>
  </Worksheet>

  <!-- ═══════════════════════════════════════════════════════════
       FEUILLE 2 — Détail des cotisations sociales par agent
  ════════════════════════════════════════════════════════════════ -->
  <Worksheet ss:Name="Cotisations détail">
    <Table>
      <Column ss:Width="155"/>
      <Column ss:Width="85"/>
      <Column ss:Width="90"/>
      <Column ss:Width="185"/>
      <Column ss:Width="95"/>
      <Column ss:Width="80"/>
      <Column ss:Width="100"/>
      <Column ss:Width="80"/>
      <Column ss:Width="100"/>

      <!-- Row 1 : Titre -->
      <Row ss:Height="30">
        <Cell ss:MergeAcross="8" ss:StyleID="s_title">
          <Data ss:Type="String">Détail cotisations sociales — du <?= xe($dfDebut) ?> au <?= xe($dfFin) ?></Data>
        </Cell>
      </Row>

      <!-- Row 2 : Info -->
      <Row ss:Height="16">
        <Cell ss:MergeAcross="8" ss:StyleID="s_info">
          <Data ss:Type="String">IDCC 1351 — Salariales : <?= number_format($tSalPct, 2) ?>% du brut | Patronales : <?= number_format($tPatPct, 2) ?>% du brut — modifiable dans Paramètres → Cotisations</Data>
        </Cell>
      </Row>

      <!-- Row 3 : espacement -->
      <Row ss:Height="6"/>

      <!-- Row 4 : En-têtes -->
      <Row ss:Height="35">
        <?= strCell('Agent', 's_hdr') ?>
        <?= strCell('Matricule', 's_hdr') ?>
        <?= strCell('Brut (€)', 's_hdr_total') ?>
        <?= strCell('Cotisation', 's_hdr') ?>
        <?= strCell('Catégorie', 's_hdr') ?>
        <?= strCell('Taux sal. %', 's_hdr_sal') ?>
        <?= strCell('Montant sal. €', 's_hdr_sal') ?>
        <?= strCell('Taux pat. %', 's_hdr_pat') ?>
        <?= strCell('Montant pat. €', 's_hdr_pat') ?>
      </Row>

      <?php
      $catLabels2 = ['secu_sociale'=>'Séc. sociale','retraite'=>'Retraite','chomage'=>'Chômage','prevoyance'=>'Prévoyance','csg_crds'=>'CSG/CRDS','autres'=>'Autres'];
      foreach ($resultats as $r):
          $agentLabel  = $r['prenom'] . ' ' . $r['nom'];
          $firstRow    = true;
          foreach ($r['cotis_detail'] as $ligne):
      ?>
      <Row ss:Height="18">
        <?= strCell($firstRow ? $agentLabel : '', $firstRow ? 's_agent' : '') ?>
        <?= strCell($firstRow ? $r['matricule'] : '', 's_mat') ?>
        <?php if ($firstRow): ?>
        <?= numCell($r['total_m'], 's_total_eur') ?>
        <?php else: ?>
        <?= emptyCell() ?>
        <?php endif; ?>
        <?= strCell($ligne['libelle']) ?>
        <?= strCell($catLabels2[$ligne['categorie']] ?? $ligne['categorie'], 's2_cat') ?>
        <?= pctCell($ligne['taux_sal'], 's2_pct') ?>
        <?= numCell($ligne['montant_sal'], 's2_eur_sal') ?>
        <?= pctCell($ligne['taux_pat'], 's2_pct') ?>
        <?= numCell($ligne['montant_pat'], 's2_eur_pat') ?>
      </Row>
      <?php $firstRow = false; endforeach; ?>

      <!-- Ligne récap agent : NET / COÛT -->
      <Row ss:Height="20">
        <?= strCell('', 's2_total') ?>
        <?= strCell('', 's2_total') ?>
        <?= strCell('', 's2_total') ?>
        <Cell ss:MergeAcross="1" ss:StyleID="s2_total">
          <Data ss:Type="String">SOUS-TOTAL <?= xe($agentLabel) ?></Data>
        </Cell>
        <?= strCell('', 's2_total') ?>
        <?= numCell($r['cotis_salariale'], 's2_total_sal') ?>
        <?= numCell($r['net'], 's2_net') ?>
        <?= numCell($r['cotis_patronale'], 's2_total_pat') ?>
        <?= numCell($r['cout_employeur'], 's2_cout') ?>
      </Row>

      <!-- Ligne vide entre agents -->
      <Row ss:Height="6"/>

      <?php endforeach; ?>

      <?php if (!empty($resultats)): ?>
      <!-- GRAND TOTAL -->
      <Row ss:Height="24">
        <Cell ss:MergeAcross="2" ss:StyleID="s_foot">
          <Data ss:Type="String">TOTAL GÉNÉRAL</Data>
        </Cell>
        <?= emptyCell('s_foot') ?>
        <?= emptyCell('s_foot') ?>
        <?= numCell($totaux['cotis_salariale'], 's_foot_sal') ?>
        <?= numCell($totaux['net'],             's_foot_net') ?>
        <?= numCell($totaux['cotis_patronale'], 's_foot_pat') ?>
        <?= numCell($totaux['cout_employeur'],  's_foot_cout') ?>
      </Row>
      <?php endif; ?>

    </Table>
    <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
      <FreezePanes/>
      <FrozenNoSplit/>
      <SplitHorizontal>4</SplitHorizontal>
      <TopRowBottomPane>4</TopRowBottomPane>
      <ActivePane>2</ActivePane>
    </WorksheetOptions>
  </Worksheet>

  <!-- ═══════════════════════════════════════════════════════════
       FEUILLE 3 — Formulaire de calcul (détail journalier)
  ════════════════════════════════════════════════════════════════ -->
  <Worksheet ss:Name="Formulaire de calcul">
    <Table>
      <Column ss:Width="155"/>
      <Column ss:Width="85"/>
      <Column ss:Width="72"/>
      <Column ss:Width="42"/>
      <Column ss:Width="90"/>
      <Column ss:Width="65"/>
      <Column ss:Width="65"/>
      <Column ss:Width="65"/>
      <Column ss:Width="65"/>
      <Column ss:Width="65"/>
      <Column ss:Width="65"/>
      <Column ss:Width="72"/>
      <Column ss:Width="90"/>

      <!-- Titre -->
      <Row ss:Height="30">
        <Cell ss:MergeAcross="12" ss:StyleID="s_title">
          <Data ss:Type="String">Formulaire de calcul — du <?= xe($dfDebut) ?> au <?= xe($dfFin) ?></Data>
        </Cell>
      </Row>

      <!-- Taux -->
      <Row ss:Height="16">
        <Cell ss:MergeAcross="12" ss:StyleID="s_info">
          <Data ss:Type="String">Taux horaires : <?= xe($tauxInfo) ?></Data>
        </Cell>
      </Row>

      <!-- Espacement -->
      <Row ss:Height="6"/>

      <!-- En-têtes -->
      <Row ss:Height="38">
        <?= strCell('Agent',        's_hdr') ?>
        <?= strCell('Matricule',    's_hdr') ?>
        <?= strCell('Date',         's_hdr') ?>
        <?= strCell('Jour',         's_hdr') ?>
        <?= strCell('Horaires',     's_hdr') ?>
        <?= strCell('Normal (h)',   's_hdr') ?>
        <?= strCell('Nuit (h)',     's_hdr_nuit') ?>
        <?= strCell('Dim. (h)',     's_hdr_dim') ?>
        <?= strCell('Fér. (h)',     's_hdr_ferie') ?>
        <?= strCell('Fér.D. (h)',   's_hdr_ferie') ?>
        <?= strCell('N.Fér. (h)',   's_hdr_ferie') ?>
        <?= strCell('Total (h)',    's_hdr_total') ?>
        <?= strCell('Montant (€)',  's_hdr_total') ?>
      </Row>

      <?php
      $gTotalMin = 0;
      $gTotalMnt = 0.0;
      foreach ($detailParAgent as $aid => $agData):
          $agLabel    = $agData['prenom'] . ' ' . $agData['nom'];
          $agMat      = $agData['matricule'];
          $subTotalMin = 0;
          $subTotalMnt = 0.0;
          $firstRow    = true;

          foreach ($agData['lignes'] as $l):
              $dt      = strtotime($l['date_travail']);
              $jourSem = (int)date('N', $dt);
              $isFer   = in_array($l['date_travail'], $feriesDetail);
              $isDim   = $jourSem === 7;
              $totMin  = $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit'];
              $subTotalMin += $totMin;
              $subTotalMnt += $l['montant'];

              // Style de la ligne selon le type de jour
              $rowStyle = $isFer ? ' ss:StyleID="s2_ferie_row"' : ($isDim ? ' ss:StyleID="s2_dim_row"' : '');
              $dateFmt  = date('d/m/Y', $dt);
              $jourNom  = $nomsJours[$jourSem] . ($isFer ? ' F' : '');
              $horaires = substr($l['heure_debut'],0,5) . '→' . substr($l['heure_fin'],0,5) . ($l['depasse_minuit'] ? '+1' : '');
              if ($l['note']) $horaires .= ' (' . $l['note'] . ')';
          ?>
          <Row ss:Height="17">
            <?= strCell($firstRow ? $agLabel : '', $firstRow ? 's_agent' : '') ?>
            <?= strCell($firstRow ? $agMat   : '', 's_mat') ?>
            <?= strCell($dateFmt, $isFer ? 's2_ferie_date' : ($isDim ? 's2_dim_date' : '')) ?>
            <?= strCell($jourNom, $isFer ? 's2_ferie_date' : ($isDim ? 's2_dim_date' : 's_mat')) ?>
            <?= strCell($horaires, '') ?>
            <?= numCell(minutesToHeures((int)$l['min_normal']),         $l['min_normal']         > 0 ? 's_h' : 's_zero') ?>
            <?= numCell(minutesToHeures((int)$l['min_nuit']),           $l['min_nuit']           > 0 ? 's_h' : 's_zero') ?>
            <?= numCell(minutesToHeures((int)$l['min_dimanche']),       $l['min_dimanche']       > 0 ? 's_h' : 's_zero') ?>
            <?= numCell(minutesToHeures((int)$l['min_ferie_normal']),   $l['min_ferie_normal']   > 0 ? 's_h' : 's_zero') ?>
            <?= numCell(minutesToHeures((int)$l['min_ferie_dimanche']), $l['min_ferie_dimanche'] > 0 ? 's_h' : 's_zero') ?>
            <?= numCell(minutesToHeures((int)$l['min_ferie_nuit']),     $l['min_ferie_nuit']     > 0 ? 's_h' : 's_zero') ?>
            <?= numCell(minutesToHeures($totMin),  's_total_h') ?>
            <?= numCell($l['montant'],             's_total_eur') ?>
          </Row>
          <?php $firstRow = false; endforeach; ?>

          <!-- Sous-total agent -->
          <Row ss:Height="20">
            <Cell ss:MergeAcross="3" ss:StyleID="s2_subtotal_lbl">
              <Data ss:Type="String">Sous-total <?= xe($agLabel) ?></Data>
            </Cell>
            <?= emptyCell('s2_subtotal_lbl') ?>
            <?= emptyCell('s2_subtotal_lbl') ?>
            <?= emptyCell('s2_subtotal_lbl') ?>
            <?= emptyCell('s2_subtotal_lbl') ?>
            <?= emptyCell('s2_subtotal_lbl') ?>
            <?= emptyCell('s2_subtotal_lbl') ?>
            <?= numCell(minutesToHeures($subTotalMin), 's2_subtotal_h') ?>
            <?= numCell(round($subTotalMnt, 2),        's2_subtotal_eur') ?>
          </Row>
          <!-- Ligne vide entre agents -->
          <Row ss:Height="5"/>

          <?php
          $gTotalMin += $subTotalMin;
          $gTotalMnt += $subTotalMnt;
      endforeach;
      ?>

      <?php if (!empty($detailParAgent)): ?>
      <!-- GRAND TOTAL -->
      <Row ss:Height="24">
        <Cell ss:MergeAcross="10" ss:StyleID="s_foot">
          <Data ss:Type="String">TOTAL GÉNÉRAL</Data>
        </Cell>
        <?= numCell(minutesToHeures($gTotalMin), 's_foot_h') ?>
        <?= numCell(round($gTotalMnt, 2),        's_foot_eur') ?>
      </Row>
      <?php endif; ?>

    </Table>
    <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">
      <FreezePanes/>
      <FrozenNoSplit/>
      <SplitHorizontal>4</SplitHorizontal>
      <TopRowBottomPane>4</TopRowBottomPane>
      <ActivePane>2</ActivePane>
    </WorksheetOptions>
  </Worksheet>

</Workbook>
