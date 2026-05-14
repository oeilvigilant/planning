<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
requirePerm('planning', 'export');

$db        = getDB();
$versionId = (int)($_GET['version_id'] ?? 0);
$format    = $_GET['format'] ?? 'pdf';

if (!$versionId) die('Version invalide.');

$version = $db->prepare("SELECT * FROM planning_versions WHERE id=?");
$version->execute([$versionId]);
$version = $version->fetch();
if (!$version) die('Version introuvable.');

$mois  = $version['mois'];
$annee = $version['annee'];

$agents = $db->query("SELECT id, nom, prenom, matricule, poste FROM agents WHERE actif=1 ORDER BY nom, prenom")->fetchAll();
$nbJours = (int)date('t', mktime(0,0,0,$mois,1,$annee));
$feries  = getJoursFeries($annee);
$nomsJs  = ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];

// Charger toutes les lignes
$planningData = [];
$stmtL = $db->prepare("SELECT * FROM planning_lignes WHERE version_id=?");
$stmtL->execute([$versionId]);
foreach ($stmtL->fetchAll() as $l) {
    $planningData[$l['agent_id']][$l['date_travail']] = $l;
}

$moisNom = formatMois($mois, $annee);
$params  = getAllParams();

if ($format === 'excel') {
    // Export CSV (compatible Excel) si PhpSpreadsheet absent
    $useSpreadsheet = file_exists(APP_ROOT . '/libs/phpspreadsheet/autoload.php');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="planning_' . $mois . '_' . $annee . '_v' . $version['version'] . '.csv"');
    $f = fopen('php://output', 'w');
    fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8

    // Ligne titre
    $headers = ['Agent', 'Poste'];
    for ($d = 1; $d <= $nbJours; $d++) {
        $date = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
        $headers[] = $nomsJs[date('N', strtotime($date))] . ' ' . $d;
    }
    $headers = array_merge($headers, ['Total h', 'Normal', 'Nuit', 'Dimanche', 'Férié', 'Fér.Dim', 'Nuit Fér.']);
    fputcsv($f, $headers, ';');

    foreach ($agents as $ag) {
        $row = [$ag['prenom'].' '.$ag['nom'], $ag['poste']??''];
        $totMin = ['normal'=>0,'nuit'=>0,'dimanche'=>0,'ferie_normal'=>0,'ferie_dimanche'=>0,'ferie_nuit'=>0];
        for ($d = 1; $d <= $nbJours; $d++) {
            $date  = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
            $ligne = $planningData[$ag['id']][$date] ?? null;
            if ($ligne) {
                $row[] = substr($ligne['heure_debut'],0,5).'→'.substr($ligne['heure_fin'],0,5).($ligne['depasse_minuit']?'+1':'');
                foreach (['normal','nuit','dimanche','ferie_normal','ferie_dimanche','ferie_nuit'] as $t) {
                    $totMin[$t] += (int)$ligne['min_'.$t];
                }
            } else {
                $row[] = '';
            }
        }
        $totalH = array_sum($totMin) / 60;
        $row[] = number_format($totalH, 2);
        foreach ($totMin as $m) $row[] = number_format($m/60, 2);
        fputcsv($f, $row, ';');
    }
    fclose($f);
    exit;
}

// PDF (HTML print)
$html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<style>
body { font-family: Arial, sans-serif; font-size: 7pt; margin: 0; padding: 10mm; color: #1a2332; }
h1 { font-size: 12pt; color: #1a2332; margin-bottom: 3px; }
.version-badge { background: #c9a84c; color: white; padding: 2px 8px; border-radius: 10px; font-size: 7pt; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th { background: #1a2332; color: white; padding: 4px 3px; text-align: center; font-size: 6.5pt; }
th.agent-col { text-align: left; padding-left: 6px; }
td { padding: 3px 2px; text-align: center; border-bottom: 1px solid #f0f2f5; font-size: 6.5pt; }
td.agent-name { text-align: left; padding-left: 6px; font-weight: 600; }
.ferie { background: rgba(234,179,8,0.1); }
.dimanche { background: rgba(239,68,68,0.06); }
.total-col { font-weight: 700; border-left: 2px solid #e5e7eb; }
tr:nth-child(even) td { background: rgba(0,0,0,0.02); }
</style>
</head><body>';

$html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;border-bottom:3px solid #c9a84c;padding-bottom:8px">';
$html .= '<div><h1>Planning — ' . htmlspecialchars($moisNom) . '</h1>';
$html .= '<div>' . htmlspecialchars($params['entreprise_nom'] ?? 'Oeil Vigilant') . ' &nbsp;|&nbsp; Généré le ' . date('d/m/Y') . '</div></div>';
$html .= '<span class="version-badge">V' . $version['version'] . '</span></div>';

$html .= '<table><thead><tr>';
$html .= '<th class="agent-col" style="min-width:80px">Agent</th>';
for ($d = 1; $d <= $nbJours; $d++) {
    $date    = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
    $jourSem = date('N', strtotime($date));
    $isFer   = in_array($date, $feries);
    $bg      = $isFer ? 'background:#fde68a;color:#92400e;' : ($jourSem==7 ? 'background:#fca5a5;color:#7f1d1d;' : '');
    $html   .= '<th style="' . $bg . '">' . $nomsJs[$jourSem] . '<br>' . $d . '</th>';
}
$html .= '<th class="total-col">Total</th></tr></thead><tbody>';

foreach ($agents as $ag) {
    $totalMin = 0;
    $row      = '';
    for ($d = 1; $d <= $nbJours; $d++) {
        $date    = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
        $ligne   = $planningData[$ag['id']][$date] ?? null;
        $jourSem = date('N', strtotime($date));
        $isFer   = in_array($date, $feries);
        $cls     = $isFer ? 'ferie' : ($jourSem==7 ? 'dimanche' : '');
        if ($ligne) {
            $hDeb  = substr($ligne['heure_debut'],0,5);
            $hFin  = substr($ligne['heure_fin'],0,5);
            $minT  = $ligne['min_normal']+$ligne['min_nuit']+$ligne['min_dimanche']+$ligne['min_ferie_normal']+$ligne['min_ferie_dimanche']+$ligne['min_ferie_nuit'];
            $totalMin += $minT;
            $row  .= '<td class="'.$cls.'">' . $hDeb . '<br>' . $hFin . ($ligne['depasse_minuit']?'<sup>+1</sup>':'') . '</td>';
        } else {
            $row .= '<td class="'.$cls.'">—</td>';
        }
    }
    if ($totalMin == 0) continue; // Ne pas afficher agents sans heures
    $html .= '<tr><td class="agent-name">' . htmlspecialchars($ag['prenom'].' '.$ag['nom']) . '</td>';
    $html .= $row;
    $html .= '<td class="total-col">' . number_format($totalMin/60,1) . 'h</td></tr>';
}
$html .= '</tbody></table></body></html>';

renderPdf($html, 'planning_' . sprintf('%02d', $mois) . '_' . $annee . '_v' . $version['version'] . '.pdf', 'landscape');
