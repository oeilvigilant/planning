<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
requirePerm('planning', 'export');

$db       = getDB();
$format   = $_GET['format']    ?? 'pdf';
$type     = $_GET['type']      ?? 'month';
$agentIds = trim($_GET['agent_ids'] ?? '');

// ── Filtre agents ─────────────────────────────────────────────────────────────
if ($agentIds !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', $agentIds))));
    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT id, nom, prenom, matricule, poste FROM agents WHERE actif=1 AND id IN ($placeholders) ORDER BY nom, prenom");
        $stmt->execute($ids);
        $agents = $stmt->fetchAll();
    } else {
        $agents = [];
    }
} else {
    $agents = $db->query("SELECT id, nom, prenom, matricule, poste FROM agents WHERE actif=1 ORDER BY nom, prenom")->fetchAll();
}

$nomsJs = ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
$params = getAllParams();

$shifts = [
    'J'  => ['label'=>'Journée', 'debut'=>'07:00', 'fin'=>'19:00', 'color'=>'#16a34a'],
    'N'  => ['label'=>'Nuit',    'debut'=>'19:00', 'fin'=>'07:00', 'color'=>'#4f46e5'],
    'M'  => ['label'=>'Matin',   'debut'=>'06:00', 'fin'=>'14:00', 'color'=>'#ea580c'],
    'S'  => ['label'=>'Soir',    'debut'=>'14:00', 'fin'=>'22:00', 'color'=>'#7c3aed'],
    'NC' => ['label'=>'Nuit C.', 'debut'=>'22:00', 'fin'=>'06:00', 'color'=>'#1d4ed8'],
];

function detectShiftExport($hDeb, $hFin, $shifts) {
    foreach ($shifts as $code => $s) {
        if (substr($s['debut'],0,5) === $hDeb && substr($s['fin'],0,5) === $hFin) {
            return $code;
        }
    }
    return null;
}

$planningData    = [];
$feries          = [];
$dateDebutFilter = $_GET['date_debut'] ?? '';
$dateFinFilter   = $_GET['date_fin']   ?? '';
$jourDebut       = 1;
$jourFin         = 31;

// ── Données planning ──────────────────────────────────────────────────────────
if ($type === 'week') {
    $semaine = (int)($_GET['semaine'] ?? date('W'));
    $annee   = (int)($_GET['annee']   ?? date('Y'));
    if ($semaine < 1)  $semaine = 1;
    if ($semaine > 53) $semaine = 52;

    $lundi    = new DateTime();
    $lundi->setISODate($annee, $semaine, 1);
    $dimanche = clone $lundi;
    $dimanche->modify('+6 days');

    $dateDebut = $lundi->format('Y-m-d');
    $dateFin   = $dimanche->format('Y-m-d');

    $stmt = $db->prepare("
        SELECT pl.* FROM planning_lignes pl
        JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
        WHERE pl.date_travail BETWEEN ? AND ?");
    $stmt->execute([$dateDebut, $dateFin]);
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

    $feries       = getJoursFeries($annee);
    $nbJours      = (int)date('t', mktime(0,0,0,$mois,1,$annee));
    // Plage filtrée
    $jourDebut = 1;
    $jourFin   = $nbJours;
    if ($dateDebutFilter) {
        $df = date('Y-m-d', mktime(0,0,0,$mois,$jourDebut,$annee));
        if ($dateDebutFilter > $df) $jourDebut = (int)date('j', strtotime($dateDebutFilter));
    }
    if ($dateFinFilter) {
        $df = date('Y-m-d', mktime(0,0,0,$mois,$jourFin,$annee));
        if ($dateFinFilter < $df) $jourFin = (int)date('j', strtotime($dateFinFilter));
    }
    $periodeLabel = formatMois($mois, $annee) . ' — V' . $version['version'];
    if ($dateDebutFilter || $dateFinFilter) {
        $periodeLabel .= ' (du '.($dateDebutFilter ? date('d/m', strtotime($dateDebutFilter)) : '01')
                       .' au '.($dateFinFilter ? date('d/m', strtotime($dateFinFilter)) : date('d/m', mktime(0,0,0,$mois,$nbJours,$annee))).')';
    }
    $fileLabel    = sprintf('%02d', $mois) . '_' . $annee . '_v' . $version['version'];
}

// ── Export CSV/Excel ──────────────────────────────────────────────────────────
if ($format === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="planning_' . $fileLabel . '.csv"');
    $f = fopen('php://output', 'w');
    fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));

    $headers = ['Agent', 'Poste'];
    if ($type === 'week') {
        foreach ($dates as $dt) {
            $headers[] = $nomsJs[(int)$dt->format('N')] . ' ' . $dt->format('d/m');
        }
    } else {
        for ($d = 1; $d <= $nbJours; $d++) {
            $date = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
            $headers[] = $nomsJs[date('N', strtotime($date))] . ' ' . $d;
        }
    }
    $headers = array_merge($headers, ['Total h', 'Normal', 'Nuit', 'Dimanche', 'Férié', 'Fér.Dim', 'Nuit Fér.']);
    fputcsv($f, $headers, ';');

    foreach ($agents as $ag) {
        $row    = [$ag['prenom'].' '.$ag['nom'], $ag['poste']??''];
        $totMin = ['normal'=>0,'nuit'=>0,'dimanche'=>0,'ferie_normal'=>0,'ferie_dimanche'=>0,'ferie_nuit'=>0];

        if ($type === 'week') {
            foreach ($dates as $dt) {
                $dateStr = $dt->format('Y-m-d');
                $ligne   = $planningData[$ag['id']][$dateStr] ?? null;
                if ($ligne) {
                    $hD = substr($ligne['heure_debut'],0,5);
                    $hF = substr($ligne['heure_fin'],0,5);
                    $minT = $ligne['min_normal']+$ligne['min_nuit']+$ligne['min_dimanche']+$ligne['min_ferie_normal']+$ligne['min_ferie_dimanche']+$ligne['min_ferie_nuit'];
                    $dur  = round($minT/60).'h';
                    $code = detectShiftExport($hD, $hF, $shifts);
                    $row[] = ($code ? $code.' ' : '').$hD.'→'.$hF.($ligne['depasse_minuit']?'+1':'').' '.$dur;
                    foreach (['normal','nuit','dimanche','ferie_normal','ferie_dimanche','ferie_nuit'] as $t) {
                        $totMin[$t] += (int)$ligne['min_'.$t];
                    }
                } else {
                    $row[] = '';
                }
            }
        } else {
            for ($d = 1; $d <= $nbJours; $d++) {
                $date  = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
                $ligne = $planningData[$ag['id']][$date] ?? null;
                if ($ligne) {
                    $hD = substr($ligne['heure_debut'],0,5);
                    $hF = substr($ligne['heure_fin'],0,5);
                    $minT = $ligne['min_normal']+$ligne['min_nuit']+$ligne['min_dimanche']+$ligne['min_ferie_normal']+$ligne['min_ferie_dimanche']+$ligne['min_ferie_nuit'];
                    $dur  = round($minT/60).'h';
                    $code = detectShiftExport($hD, $hF, $shifts);
                    $row[] = ($code ? $code.' ' : '').$hD.'→'.$hF.($ligne['depasse_minuit']?'+1':'').' '.$dur;
                    foreach (['normal','nuit','dimanche','ferie_normal','ferie_dimanche','ferie_nuit'] as $t) {
                        $totMin[$t] += (int)$ligne['min_'.$t];
                    }
                } else {
                    $row[] = '';
                }
            }
        }

        $totalH = array_sum($totMin) / 60;
        $row[]  = number_format($totalH, 2);
        foreach ($totMin as $m) $row[] = number_format($m/60, 2);
        fputcsv($f, $row, ';');
    }
    fclose($f);
    exit;
}

// ── Export PDF ────────────────────────────────────────────────────────────────
$showFooter  = !empty($_GET['footer']);
$padBottom   = $showFooter ? '20mm' : '5mm';
$html = '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<style>
body { font-family: Arial, sans-serif; font-size: 7pt; margin: 0; padding: 5mm 5mm ' . $padBottom . ' 5mm; color: #1a2332; }
h1 { font-size: 12pt; color: #1a2332; margin-bottom: 3px; }
.version-badge { background: #c9a84c; color: white; padding: 2px 8px; border-radius: 10px; font-size: 7pt; }
table { width: 100%; border-collapse: collapse; margin-top: 8px; }
th { background: #eef0f4; color: #1a2332; padding: 4px 3px; text-align: center; font-size: 6.5pt; font-weight: 600; border-bottom: 1.5px solid #c9a84c; }
th.agent-col { text-align: left; padding-left: 6px; }
td { padding: 3px 2px; text-align: center; border-bottom: 1px solid #f0f2f5; font-size: 6.5pt; }
td.agent-name { text-align: left; padding-left: 6px; font-weight: 600; }
.ferie { background: rgba(234,179,8,0.1); }
.dimanche { background: rgba(239,68,68,0.06); }
.total-col { font-weight: 700; border-left: 2px solid #e5e7eb; }
tr:nth-child(even) td { background: rgba(0,0,0,0.02); }
.shift-code { font-weight: 900; font-size: 7pt; line-height: 1.1; }
.shift-times { font-size: 5.5pt; line-height: 1.2; }
.shift-dur { font-size: 5.5pt; color: #6b7280; }
tfoot td { background: #f4f6fa; font-weight: 700; border-top: 2px solid #c9a84c; font-size: 6.5pt; color: #1a2332; }
tfoot td.agent-name { text-align: left; padding-left: 6px; color: #666; font-size: 6pt; }
.footer-pdf { position: fixed; bottom: 0; left: 0; right: 0; font-size: 6pt; color: #555; border-top: 1.5px solid #c9a84c; padding: 3px 10mm 2px 10mm; background: #fafaf8; }
.footer-pdf-l1 { font-weight: 700; color: #1a2332; text-align: center; }
.footer-pdf-l2 { color: #555; }
.footer-pdf-legal { color: #888; font-style: italic; }
</style>
</head><body>';

$html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;border-bottom:3px solid #c9a84c;padding-bottom:8px">';
$html .= '<div><h1>Planning — ' . htmlspecialchars($periodeLabel) . '</h1>';
$html .= '<div>' . htmlspecialchars($params['entreprise_nom'] ?? 'Oeil Vigilant') . ' &nbsp;|&nbsp; Généré le ' . date('d/m/Y') . '</div></div>';
if ($type !== 'week') {
    $html .= '<span class="version-badge">V' . $version['version'] . '</span>';
}
$html .= '</div>';

$html .= '<table><thead><tr>';
$html .= '<th class="agent-col" style="min-width:80px">Agent</th>';

// Accumulateurs par colonne (date => minutes)
$totauxJour = [];

if ($type === 'week') {
    foreach ($dates as $dt) {
        $dateStr = $dt->format('Y-m-d');
        $jourSem = (int)$dt->format('N');
        $isFer   = in_array($dateStr, $feries);
        $bg      = $isFer ? 'background:#fde68a;color:#92400e;' : ($jourSem==7 ? 'background:#fca5a5;color:#7f1d1d;' : '');
        $html   .= '<th style="'.$bg.'">'.$nomsJs[$jourSem].'<br>'.$dt->format('d').'</th>';
        $totauxJour[$dateStr] = 0;
    }
} else {
    for ($d = $jourDebut; $d <= $jourFin; $d++) {
        $date    = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
        $jourSem = date('N', strtotime($date));
        $isFer   = in_array($date, $feries);
        $bg      = $isFer ? 'background:#fde68a;color:#92400e;' : ($jourSem==7 ? 'background:#fca5a5;color:#7f1d1d;' : '');
        $html   .= '<th style="'.$bg.'">'.$nomsJs[$jourSem].'<br>'.$d.'</th>';
        $totauxJour[$date] = 0;
    }
}
$html .= '<th class="total-col">Total</th></tr></thead><tbody>';

foreach ($agents as $ag) {
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
                $code  = detectShiftExport($hDeb, $hFin, $shifts);
                $color = $code ? $shifts[$code]['color'] : '#374151';
                $dur   = round($minT/60).'h';
                $hFin2 = $hFin.($ligne['depasse_minuit']?'<sup>+1</sup>':'');
                $row .= '<td class="'.$cls.'">'
                    .'<span class="shift-code" style="color:'.$color.'">'
                    .($code ? $code : $hDeb.'→'.$hFin2).'</span>';
                if ($code) $row .= '<br><span class="shift-times" style="color:'.$color.'">'.$hDeb.'→'.$hFin2.'</span>';
                $row .= '<br><span class="shift-dur">'.$dur.'</span></td>';
            } else {
                $row .= '<td class="'.$cls.'">—</td>';
            }
        }
    } else {
        for ($d = $jourDebut; $d <= $jourFin; $d++) {
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
                $totauxJour[$date] += $minT;
                $code  = detectShiftExport($hDeb, $hFin, $shifts);
                $color = $code ? $shifts[$code]['color'] : '#374151';
                $dur   = round($minT/60).'h';
                $hFin2 = $hFin.($ligne['depasse_minuit']?'<sup>+1</sup>':'');
                $row .= '<td class="'.$cls.'">'
                    .'<span class="shift-code" style="color:'.$color.'">'
                    .($code ? $code : $hDeb.'→'.$hFin2).'</span>';
                if ($code) $row .= '<br><span class="shift-times" style="color:'.$color.'">'.$hDeb.'→'.$hFin2.'</span>';
                $row .= '<br><span class="shift-dur">'.$dur.'</span></td>';
            } else {
                $row .= '<td class="'.$cls.'">—</td>';
            }
        }
    }

    if ($totalMin == 0) continue;
    $html .= '<tr><td class="agent-name">'.htmlspecialchars($ag['prenom'].' '.$ag['nom']).'</td>';
    $html .= $row;
    $html .= '<td class="total-col">'.number_format($totalMin/60,1).'h</td></tr>';
}

// Ligne total h/jour
$grandTotal = array_sum($totauxJour);
$html .= '</tbody><tfoot><tr><td class="agent-name">Total h/jour</td>';
foreach ($totauxJour as $min) {
    $html .= '<td>' . ($min > 0 ? number_format($min/60,1).'h' : '—') . '</td>';
}
$html .= '<td class="total-col">' . number_format($grandTotal/60,1) . 'h</td></tr></tfoot>';
$html .= '</table>';

// Footer légal (optionnel)
if ($showFooter) {
    $html .= '<div class="footer-pdf">'
        . '<div class="footer-pdf-l1">Oeil Vigilant (SAS) &nbsp;·&nbsp; 58 RUE DE MONCEAU 75008 PARIS &nbsp;·&nbsp; contact@oeilvigilant.com</div>'
        . '<div class="footer-pdf-l2">SIREN : 928 552 702 &nbsp;·&nbsp; TVA : FR90928552702 &nbsp;·&nbsp; Tél : +33 (0)7 78 54 24 35 / +33 (0)7 84 90 19 93 &nbsp;·&nbsp; N° autorisation : AUT-075-2123-06-21-20240934026</div>'
        . '<div class="footer-pdf-legal">L\'autorisation d\'exercice ne confère aucune prérogative de puissance publique à l\'entreprise ou aux personnes qui en bénéficient.</div>'
        . '</div>';
}
$html .= '</body></html>';

renderPdf($html, 'planning_' . $fileLabel . '.pdf', 'landscape');
