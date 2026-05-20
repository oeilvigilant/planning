<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
requirePerm('planning', 'export');

if (!class_exists('ZipArchive')) {
    die('L\'extension PHP ZipArchive est requise.');
}
if (!file_exists(DOMPDF_AUTOLOAD)) {
    die('DomPDF introuvable.');
}
require_once DOMPDF_AUTOLOAD;

$db    = getDB();
$type  = $_GET['type'] ?? 'month';
$params = getAllParams();

$dateDebutFilter = $_GET['date_debut'] ?? '';
$dateFinFilter   = $_GET['date_fin']   ?? '';

$nomsJs = ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
$shifts = [
    'J'  => ['label'=>'Journée', 'debut'=>'07:00', 'fin'=>'19:00', 'color'=>'#16a34a'],
    'N'  => ['label'=>'Nuit',    'debut'=>'19:00', 'fin'=>'07:00', 'color'=>'#4f46e5'],
    'M'  => ['label'=>'Matin',   'debut'=>'06:00', 'fin'=>'14:00', 'color'=>'#ea580c'],
    'S'  => ['label'=>'Soir',    'debut'=>'14:00', 'fin'=>'22:00', 'color'=>'#7c3aed'],
    'NC' => ['label'=>'Nuit C.', 'debut'=>'22:00', 'fin'=>'06:00', 'color'=>'#1d4ed8'],
];

function detectShift($hDeb, $hFin, $shifts) {
    foreach ($shifts as $code => $s) {
        if (substr($s['debut'],0,5) === $hDeb && substr($s['fin'],0,5) === $hFin) return $code;
    }
    return null;
}

// ── Données période ───────────────────────────────────────────────────────────
$planningData = [];
$feries       = [];
$jourDebut    = 1;
$jourFin      = 31;
$dates        = [];
$mois         = 0;
$version      = null;

if ($type === 'week') {
    $semaine = (int)($_GET['semaine'] ?? date('W'));
    $annee   = (int)($_GET['annee']   ?? date('Y'));
    if ($semaine < 1)  $semaine = 1;
    if ($semaine > 53) $semaine = 52;

    $lundi    = new DateTime();
    $lundi->setISODate($annee, $semaine, 1);
    $dimanche = clone $lundi;
    $dimanche->modify('+6 days');

    $stmt = $db->prepare("
        SELECT pl.* FROM planning_lignes pl
        JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
        WHERE pl.date_travail BETWEEN ? AND ?");
    $stmt->execute([$lundi->format('Y-m-d'), $dimanche->format('Y-m-d')]);
    foreach ($stmt->fetchAll() as $l) {
        $planningData[$l['agent_id']][$l['date_travail']] = $l;
    }

    $feries = getJoursFeries($annee);
    if ((int)$dimanche->format('Y') !== $annee) {
        $feries = array_merge($feries, getJoursFeries((int)$dimanche->format('Y')));
    }

    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $d = clone $lundi;
        $d->modify("+$i days");
        $ds = $d->format('Y-m-d');
        if ($dateDebutFilter && $ds < $dateDebutFilter) continue;
        if ($dateFinFilter   && $ds > $dateFinFilter)   continue;
        $dates[] = $d;
    }

    $moisFrArr = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    $periodeLabel = 'Semaine '.$semaine.' — '
        .$lundi->format('d').' '.$moisFrArr[(int)$lundi->format('n')]
        .' au '.$dimanche->format('d').' '.$moisFrArr[(int)$dimanche->format('n')].' '.$dimanche->format('Y');
    $fileLabel = 'sem'.$semaine.'_'.$annee;

} else {
    $versionId = (int)($_GET['version_id'] ?? 0);
    if (!$versionId) die('Version invalide.');

    $stmtV = $db->prepare("SELECT * FROM planning_versions WHERE id=?");
    $stmtV->execute([$versionId]);
    $version = $stmtV->fetch();
    if (!$version) die('Version introuvable.');

    $mois  = $version['mois'];
    $annee = $version['annee'];

    $stmtL = $db->prepare("SELECT * FROM planning_lignes WHERE version_id=?");
    $stmtL->execute([$versionId]);
    foreach ($stmtL->fetchAll() as $l) {
        $planningData[$l['agent_id']][$l['date_travail']] = $l;
    }

    $feries  = getJoursFeries($annee);
    $nbJours = (int)date('t', mktime(0,0,0,$mois,1,$annee));
    $jourDebut = 1;
    $jourFin   = $nbJours;
    if ($dateDebutFilter && $dateDebutFilter > date('Y-m-d', mktime(0,0,0,$mois,1,$annee))) {
        $jourDebut = (int)date('j', strtotime($dateDebutFilter));
    }
    if ($dateFinFilter && $dateFinFilter < date('Y-m-d', mktime(0,0,0,$mois,$nbJours,$annee))) {
        $jourFin = (int)date('j', strtotime($dateFinFilter));
    }

    $periodeLabel = formatMois($mois, $annee) . ' — V' . $version['version'];
    if ($dateDebutFilter || $dateFinFilter) {
        $periodeLabel .= ' (du '.($dateDebutFilter ? date('d/m', strtotime($dateDebutFilter)) : '01')
                       .' au '.($dateFinFilter ? date('d/m', strtotime($dateFinFilter)) : date('d/m', mktime(0,0,0,$mois,$nbJours,$annee))).')';
    }
    $fileLabel = sprintf('%02d', $mois) . '_' . $annee . '_v' . $version['version'];
}

// ── Agents à exporter ─────────────────────────────────────────────────────────
$agentIds = trim($_GET['agent_ids'] ?? '');
if ($agentIds !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', $agentIds))));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtA = $db->prepare("SELECT id, nom, prenom, matricule, poste FROM agents WHERE actif=1 AND id IN ($placeholders) ORDER BY nom, prenom");
        $stmtA->execute($ids);
        $agents = $stmtA->fetchAll();
    } else {
        $agents = [];
    }
} else {
    $agents = $db->query("SELECT id, nom, prenom, matricule, poste FROM agents WHERE actif=1 ORDER BY nom, prenom")->fetchAll();
}

$showFooter   = !empty($_GET['footer']);
$marginBottom = $showFooter ? '22mm' : '5mm';
$entreprise   = htmlspecialchars($params['entreprise_nom'] ?? 'Oeil Vigilant');

// ── CSS partagé ───────────────────────────────────────────────────────────────
function buildPdfCss(string $marginBottom): string {
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<style>
@page { size: A4 landscape; margin: 5mm 5mm ' . $marginBottom . ' 5mm; }
body { font-family: Arial, sans-serif; font-size: 7pt; margin: 0; padding: 0; color: #1a2332; }
h1 { font-size: 11pt; color: #1a2332; margin-bottom: 3px; }
.version-badge { background: #c9a84c; color: white; padding: 2px 8px; border-radius: 10px; font-size: 7pt; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th { background: #eef0f4; color: #1a2332; padding: 4px 3px; text-align: center; font-size: 6.5pt; font-weight: 600; border-bottom: 1.5px solid #c9a84c; }
th.agent-col { text-align: left; padding-left: 6px; }
td { padding: 3px 2px; text-align: center; border-bottom: 1px solid #f0f2f5; font-size: 6.5pt; }
td.agent-name { text-align: left; padding-left: 6px; font-weight: 600; font-size: 8pt; }
.ferie { background: rgba(234,179,8,0.1); }
.dimanche { background: rgba(239,68,68,0.06); }
.total-col { font-weight: 700; border-left: 2px solid #e5e7eb; }
.shift-code { font-weight: 900; font-size: 7pt; line-height: 1.1; }
.shift-times { font-size: 5.5pt; line-height: 1.2; }
.shift-dur { font-size: 5.5pt; color: #6b7280; }
tfoot td { background: #f4f6fa; font-weight: 700; border-top: 2px solid #c9a84c; font-size: 6.5pt; color: #1a2332; }
.footer-pdf { position: fixed; bottom: 0; left: 0; right: 0; font-size: 6pt; color: #555; border-top: 1.5px solid #c9a84c; padding: 3px 10mm 2px 10mm; background: #fafaf8; }
.footer-pdf-l1 { font-weight: 700; color: #1a2332; text-align: center; }
.footer-pdf-l2 { color: #555; }
.footer-pdf-legal { color: #888; font-style: italic; }
</style></head><body>';
}

function buildAgentPdf(array $ag, array $planningData, array $dates, array $feries, array $shifts, string $nomsJs, string $type, int $jourDebut, int $jourFin, int $mois, int $annee, string $periodeLabel, string $entreprise, bool $showFooter, string $marginBottom): string {
    $nomsJsArr = explode(',', $nomsJs);
    $html = buildPdfCss($marginBottom);

    $agNom = htmlspecialchars($ag['prenom'] . ' ' . strtoupper($ag['nom']));
    $html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;border-bottom:3px solid #c9a84c;padding-bottom:6px">';
    $html .= '<div><h1>' . $agNom . '</h1>';
    $html .= '<div style="font-size:7pt;color:#666">' . $entreprise . ' &nbsp;·&nbsp; ' . htmlspecialchars($periodeLabel) . ' &nbsp;·&nbsp; Généré le ' . date('d/m/Y') . '</div></div>';
    if ($type !== 'week') {
        global $version;
        $html .= '<span class="version-badge">V' . $version['version'] . '</span>';
    }
    $html .= '</div>';

    $html .= '<table><thead><tr>';
    $html .= '<th class="agent-col" style="min-width:30px">Poste</th>';

    $totauxJour = [];
    $nomsJsMap  = ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];

    if ($type === 'week') {
        foreach ($dates as $dt) {
            $dateStr = $dt->format('Y-m-d');
            $jourSem = (int)$dt->format('N');
            $isFer   = in_array($dateStr, $feries);
            $bg      = $isFer ? 'background:#fde68a;color:#92400e;' : ($jourSem==7 ? 'background:#fca5a5;color:#7f1d1d;' : '');
            $html   .= '<th style="'.$bg.'">'.$nomsJsMap[$jourSem].'<br>'.$dt->format('d').'</th>';
            $totauxJour[$dateStr] = 0;
        }
    } else {
        for ($d = $jourDebut; $d <= $jourFin; $d++) {
            $date    = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
            $jourSem = (int)date('N', strtotime($date));
            $isFer   = in_array($date, $feries);
            $bg      = $isFer ? 'background:#fde68a;color:#92400e;' : ($jourSem==7 ? 'background:#fca5a5;color:#7f1d1d;' : '');
            $html   .= '<th style="'.$bg.'">'.$nomsJsMap[$jourSem].'<br>'.$d.'</th>';
            $totauxJour[$date] = 0;
        }
    }
    $html .= '<th class="total-col">Total</th></tr></thead><tbody>';

    $totalMin = 0;
    $row      = '';

    if ($type === 'week') {
        foreach ($dates as $dt) {
            $dateStr = $dt->format('Y-m-d');
            $ligne   = $planningData[$ag['id']][$dateStr] ?? null;
            $jourSem = (int)$dt->format('N');
            $isFer   = in_array($dateStr, $feries);
            $cls     = $isFer ? 'ferie' : ($jourSem==7 ? 'dimanche' : '');
            if ($ligne) {
                $hDeb = substr($ligne['heure_debut'],0,5);
                $hFin = substr($ligne['heure_fin'],0,5);
                $minT = $ligne['min_normal']+$ligne['min_nuit']+$ligne['min_dimanche']+$ligne['min_ferie_normal']+$ligne['min_ferie_dimanche']+$ligne['min_ferie_nuit'];
                $totalMin += $minT;
                $totauxJour[$dateStr] += $minT;
                $code    = detectShift($hDeb, $hFin, $shifts);
                $color   = $code ? $shifts[$code]['color'] : '#374151';
                $dur     = round($minT/60).'h';
                $hDebFmt = formatHeureCourte($hDeb);
                $hFinFmt = formatHeureCourte($hFin);
                $row .= '<td class="'.$cls.'"><span class="shift-code" style="color:'.$color.'">'.($code ? $code : $hDebFmt.' - '.$hFinFmt).'</span>';
                if ($code) $row .= '<br><span class="shift-times" style="color:'.$color.'">'.$hDebFmt.' - '.$hFinFmt.'</span>';
                $row .= '<br><span class="shift-dur">'.$dur.'</span></td>';
            } else {
                $row .= '<td class="'.$cls.'">—</td>';
            }
        }
    } else {
        for ($d = $jourDebut; $d <= $jourFin; $d++) {
            $date    = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
            $ligne   = $planningData[$ag['id']][$date] ?? null;
            $jourSem = (int)date('N', strtotime($date));
            $isFer   = in_array($date, $feries);
            $cls     = $isFer ? 'ferie' : ($jourSem==7 ? 'dimanche' : '');
            if ($ligne) {
                $hDeb = substr($ligne['heure_debut'],0,5);
                $hFin = substr($ligne['heure_fin'],0,5);
                $minT = $ligne['min_normal']+$ligne['min_nuit']+$ligne['min_dimanche']+$ligne['min_ferie_normal']+$ligne['min_ferie_dimanche']+$ligne['min_ferie_nuit'];
                $totalMin += $minT;
                $totauxJour[$date] += $minT;
                $code    = detectShift($hDeb, $hFin, $shifts);
                $color   = $code ? $shifts[$code]['color'] : '#374151';
                $dur     = round($minT/60).'h';
                $hDebFmt = formatHeureCourte($hDeb);
                $hFinFmt = formatHeureCourte($hFin);
                $row .= '<td class="'.$cls.'"><span class="shift-code" style="color:'.$color.'">'.($code ? $code : $hDebFmt.' - '.$hFinFmt).'</span>';
                if ($code) $row .= '<br><span class="shift-times" style="color:'.$color.'">'.$hDebFmt.' - '.$hFinFmt.'</span>';
                $row .= '<br><span class="shift-dur">'.$dur.'</span></td>';
            } else {
                $row .= '<td class="'.$cls.'">—</td>';
            }
        }
    }

    $html .= '<tr><td class="agent-name">'.htmlspecialchars($ag['poste'] ?? '').'</td>';
    $html .= $row;
    $html .= '<td class="total-col">'.number_format($totalMin/60,1).'h</td></tr>';

    // Ligne récapitulatif heures par type
    $lignesAg = $planningData[$ag['id']] ?? [];
    $recapMins = ['normal'=>0,'nuit'=>0,'dimanche'=>0,'ferie_normal'=>0,'ferie_dimanche'=>0,'ferie_nuit'=>0];
    foreach ($lignesAg as $dateK => $l) {
        if ($type === 'week') {
            $inRange = false;
            foreach ($dates as $dt) { if ($dt->format('Y-m-d') === $dateK) { $inRange = true; break; } }
            if (!$inRange) continue;
        } else {
            $j = (int)date('j', strtotime($dateK));
            if ($j < $jourDebut || $j > $jourFin) continue;
        }
        foreach ($recapMins as $k => $_) $recapMins[$k] += (int)$l['min_'.$k];
    }
    $recapParts = [];
    $labMap = ['normal'=>'Normal','nuit'=>'Nuit','dimanche'=>'Dim.','ferie_normal'=>'Fér.','ferie_dimanche'=>'Fér.Dim','ferie_nuit'=>'Nuit Fér.'];
    foreach ($recapMins as $k => $m) {
        if ($m > 0) $recapParts[] = $labMap[$k] . ' : ' . number_format($m/60,1) . 'h';
    }

    $html .= '</tbody><tfoot><tr>';
    $html .= '<td class="agent-name" style="font-size:6pt;color:#666">Récap.</td>';
    $colCount = $type === 'week' ? count($dates) : ($jourFin - $jourDebut + 1);
    $recapStr = implode(' &nbsp;·&nbsp; ', $recapParts);
    $html .= '<td colspan="'.$colCount.'" style="text-align:left;padding-left:6px;font-size:6pt;color:#666">'.$recapStr.'</td>';
    $html .= '<td class="total-col">'.number_format($totalMin/60,1).'h</td></tr></tfoot>';
    $html .= '</table>';

    if ($showFooter) {
        $html .= '<div class="footer-pdf">'
            . '<div class="footer-pdf-l1">Oeil Vigilant (SAS) &nbsp;·&nbsp; 58 RUE DE MONCEAU 75008 PARIS &nbsp;·&nbsp; contact@oeilvigilant.com</div>'
            . '<div class="footer-pdf-l2">SIREN : 928 552 702 &nbsp;·&nbsp; TVA : FR90928552702 &nbsp;·&nbsp; Tél : +33 (0)7 78 54 24 35 / +33 (0)7 84 90 19 93 &nbsp;·&nbsp; N° autorisation : AUT-075-2123-06-21-20240934026</div>'
            . '<div class="footer-pdf-legal">L\'autorisation d\'exercice ne confère aucune prérogative de puissance publique à l\'entreprise ou aux personnes qui en bénéficient.</div>'
            . '</div>';
    }
    $html .= '</body></html>';
    return $html;
}

// ── Génération ZIP ────────────────────────────────────────────────────────────
$tmpZip = tempnam(sys_get_temp_dir(), 'ov_zip_');
$zip    = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('Impossible de créer le fichier ZIP.');
}

$options = new \Dompdf\Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Arial');
$options->set('chroot', APP_ROOT);

foreach ($agents as $ag) {
    // Skip agents with no planning data in range
    if (empty($planningData[$ag['id']])) continue;

    $html = buildAgentPdf(
        $ag, $planningData,
        $dates ?? [], $feries, $shifts,
        '', $type, $jourDebut, $jourFin,
        $mois ?? 0, $annee,
        $periodeLabel, $entreprise, $showFooter, $marginBottom
    );

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $pdfContent = $dompdf->output();

    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower($ag['nom'].'_'.$ag['prenom']));
    $zip->addFromString('planning_' . $safeName . '_' . $fileLabel . '.pdf', $pdfContent);
}

$numAdded = $zip->numFiles;
$zip->close();

if ($numAdded === 0 || !file_exists($tmpZip) || filesize($tmpZip) === 0) {
    @unlink($tmpZip);
    die('Aucun agent avec du planning à exporter.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="planning_individuel_' . $fileLabel . '.zip"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: no-cache');
readfile($tmpZip);
unlink($tmpZip);
exit;
