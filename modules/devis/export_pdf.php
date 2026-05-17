<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$devis = $db->prepare("SELECT * FROM devis WHERE id = ?");
$devis->execute([$id]);
$devis = $devis->fetch();
if (!$devis) { header('Location: index.php'); exit; }

$profils = $db->prepare("SELECT * FROM devis_profils WHERE devis_id = ? ORDER BY ordre, id");
$profils->execute([$id]);
$profils = $profils->fetchAll();

$lignesParProfil = [];
if ($profils) {
    $profilIds    = array_column($profils, 'id');
    $placeholders = implode(',', array_fill(0, count($profilIds), '?'));
    $stmtL = $db->prepare("SELECT * FROM devis_lignes WHERE profil_id IN ($placeholders) ORDER BY date");
    $stmtL->execute($profilIds);
    foreach ($stmtL->fetchAll() as $l) {
        $lignesParProfil[$l['profil_id']][$l['date']] = $l;
    }
}

$jours = [];
$cur   = strtotime($devis['periode_debut']);
$end   = strtotime($devis['periode_fin']);
while ($cur <= $end) {
    $jours[] = date('Y-m-d', $cur);
    $cur      = strtotime('+1 day', $cur);
}

$joursAbrev = ['1'=>'lun','2'=>'mar','3'=>'mer','4'=>'jeu','5'=>'ven','6'=>'sam','7'=>'dim'];

// Récupérer logo et infos société
$logoPath   = APP_ROOT . '/assets/img/logo.png';
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoData   = base64_encode(file_get_contents($logoPath));
    $logoBase64 = 'data:image/png;base64,' . $logoData;
}

// Calcul des totaux
$grandTotalH  = 0;
$grandTotalHT = 0;
$totauxProfils = [];

foreach ($profils as $profil) {
    $pid    = $profil['id'];
    $lignes = $lignesParProfil[$pid] ?? [];
    $tauxJn = (float)$profil['taux_jn'];
    $tauxNn = (float)$profil['taux_nn'];
    $tauxJd = (float)$profil['taux_jd'];
    $tauxNd = (float)$profil['taux_nd'];
    $tauxJf = (float)$profil['taux_jf'];
    $tauxNf = (float)$profil['taux_nf'];
    $stH = $stHT = 0;
    $rows = [];
    foreach ($jours as $jour) {
        $lg  = $lignes[$jour] ?? ['h_jn'=>0,'h_nn'=>0,'h_jd'=>0,'h_nd'=>0,'h_jf'=>0,'h_nf'=>0];
        $hJn = (float)$lg['h_jn']; $hNn = (float)$lg['h_nn'];
        $hJd = (float)$lg['h_jd']; $hNd = (float)$lg['h_nd'];
        $hJf = (float)$lg['h_jf']; $hNf = (float)$lg['h_nf'];
        $tH  = $hJn + $hNn + $hJd + $hNd + $hJf + $hNf;
        $tHT = $hJn*$tauxJn + $hNn*$tauxNn + $hJd*$tauxJd + $hNd*$tauxNd + $hJf*$tauxJf + $hNf*$tauxNf;
        $stH  += $tH;
        $stHT += $tHT;
        $rows[] = compact('jour','hJn','hNn','hJd','hNd','hJf','hNf','tH','tHT');
    }
    $totauxProfils[$pid] = ['h' => $stH, 'ht' => $stHT, 'rows' => $rows];
    $grandTotalH  += $stH;
    $grandTotalHT += $stHT;
}
$tvaMontant    = $grandTotalHT * ($devis['tva_taux'] / 100);
$grandTotalTTC = $grandTotalHT + $tvaMontant;

// Générer HTML pour DomPDF
ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
    * { box-sizing: border-box; }
    body {
        font-family: DejaVu Sans, Arial, sans-serif;
        font-size: 9pt;
        color: #1a2332;
        margin: 0;
        padding: 0;
    }
    .page { padding: 15mm 12mm 28mm 12mm; }

    /* En-tête */
    .entete { width: 100%; margin-bottom: 12px; border-bottom: 2px solid #c9a84c; padding-bottom: 8px; }
    .entete td { vertical-align: top; }
    .logo-cell { width: 210px; }
    .logo-cell img { height: 85px; max-width: 200px; }
    .societe-cell { padding-left: 10px; }
    .societe-name { font-size: 13pt; font-weight: 600; color: #1a2332; white-space: nowrap; }
    .societe-sub  { font-size: 8pt; color: #888; font-style: italic; }
    .devis-info-cell { text-align: right; }
    .devis-numero { font-size: 13pt; font-weight: 600; color: #c9a84c; }

    /* Bloc client / période */
    .info-block { width: 100%; margin-bottom: 10px; }
    .info-block td { padding: 4px 8px; vertical-align: top; font-size: 8.5pt; }
    .info-label { font-weight: 600; color: #888; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5px; }
    .info-value { color: #1a2332; font-weight: normal; }

    /* Tableau devis */
    .tbl { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 7.5pt; }
    .tbl th, .tbl td { border: 1px solid #e0e0e0; padding: 3px 4px; text-align: center; }
    .tbl thead tr:first-child th { background: #eef0f4; color: #1a2332; font-size: 7.5pt; font-weight: 600; padding: 4px; border-bottom: 1.5px solid #c9a84c; }
    .tbl thead tr:nth-child(2) th { background: #f4f4f4; font-weight: 600; color: #444; }
    .tbl thead tr:nth-child(3) td { background: #faf8f2; color: #a8883c; font-size: 7pt; }
    .tbl tbody tr td:first-child { text-align: left; white-space: nowrap; }
    .tbl tbody tr.zero td { color: #ccc; }
    .tbl tfoot tr td { background: #f8f6e8; font-weight: 600; border-top: 1.5px solid #c9a84c; }
    .tbl tfoot tr td:first-child { text-align: left; }

    /* Couleurs de type */
    .col-jn { background: rgba(99,102,241,0.06); }
    .col-jd { background: rgba(59,130,246,0.06); }
    .col-jf { background: rgba(239,68,68,0.06); }
    .td-date-dim  { color: #2563eb; font-weight: bold; }
    .td-date-ferie { color: #dc2626; font-weight: bold; }
    .td-disabled  { color: #ccc; background: #f9f9f9; }

    /* Récap totaux */
    .tbl-recap { width: 260px; margin-left: auto; border-collapse: collapse; margin-top: 10px; }
    .tbl-recap td { padding: 4px 8px; border-bottom: 1px solid #eee; font-size: 9pt; }
    .tbl-recap .lbl { color: #666; }
    .tbl-recap .val { text-align: right; font-weight: bold; }
    .tbl-recap tr.ttc td { border-top: 2px solid #c9a84c; font-size: 11pt; color: #c9a84c; font-weight: bold; }

    /* Titre profil */
    .profil-title { background: #eef0f4; color: #1a2332; padding: 5px 8px; font-weight: 600; font-size: 9pt; margin-bottom: 0; border-left: 3px solid #c9a84c; }
    .profil-sub   { background: #f8f8f8; padding: 3px 8px; font-size: 7.5pt; color: #777; margin-bottom: 4px; }

    /* Pied de page */
    .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 6.5pt; color: #666; border-top: 1.5px solid #c9a84c; padding: 4px 12mm 3px 12mm; background: #fafaf8; }
    .footer-line1 { font-weight: bold; color: #1a2332; margin-bottom: 1px; }
    .footer-line2 { color: #555; margin-bottom: 1px; }
    .footer-legal { color: #888; font-style: italic; }
    .footer-right  { text-align: right; color: #aaa; }

    .page-break { page-break-after: always; }
    .text-right { text-align: right; }
    .text-center { text-align: center; }
</style>
</head>
<body>
<div class="page">

    <!-- En-tête -->
    <table class="entete">
        <tr>
            <td class="logo-cell">
                <?php if ($logoBase64): ?>
                <img src="<?= $logoBase64 ?>" alt="Logo">
                <?php endif; ?>
                <div class="societe-name">OEIL VIGILANT</div>
                <div class="societe-sub">Votre sécurité, Notre priorité</div>
            </td>
            <td style="text-align:right;vertical-align:middle">
                <div class="devis-numero">DEVIS <?= h($devis['numero']) ?></div>
            </td>
        </tr>
    </table>

    <!-- Infos client / période -->
    <table class="info-block">
        <tr>
            <td style="width:40%">
                <div class="info-label">Client</div>
                <div class="info-value" style="font-size:10pt;font-weight:bold"><?= h($devis['client_nom'] ?: '—') ?></div>
                <?php if ($devis['client_adresse']): ?>
                <div class="info-value" style="color:#666;font-size:8pt"><?= nl2br(h($devis['client_adresse'])) ?></div>
                <?php endif; ?>
            </td>
            <td style="width:30%">
                <div class="info-label">Période</div>
                <div class="info-value"><?= h(formatDate($devis['periode_debut'])) ?> → <?= h(formatDate($devis['periode_fin'])) ?></div>
                <div style="color:#666;font-size:8pt"><?= count($jours) ?> jour(s)</div>
            </td>
            <td style="width:30%">
                <div class="info-label">Description</div>
                <div class="info-value" style="font-size:8pt"><?= nl2br(h($devis['description'] ?: '—')) ?></div>
            </td>
        </tr>
    </table>

    <!-- Tableau par profil -->
    <?php
    function showH($v, $active) {
        if (!$active) return '<td class="td-disabled">—</td>';
        return '<td>' . ($v > 0 ? number_format($v, 1) : '—') . '</td>';
    }
    foreach ($profils as $profil):
        $pid      = $profil['id'];
        $donnees  = $totauxProfils[$pid];
        $tauxJn   = (float)$profil['taux_jn'];
        $tauxNn   = (float)$profil['taux_nn'];
        $tauxJd   = (float)$profil['taux_jd'];
        $tauxNd   = (float)$profil['taux_nd'];
        $tauxJf   = (float)$profil['taux_jf'];
        $tauxNf   = (float)$profil['taux_nf'];
    ?>
    <div class="profil-title"><?= h($profil['label']) ?></div>
    <div class="profil-sub"><?= h($profil['activite']) ?> &nbsp;|&nbsp; <?= h($profil['plage']) ?></div>

    <table class="tbl">
        <thead>
            <tr>
                <th rowspan="3" style="width:80px;text-align:left">Date</th>
                <th colspan="2">Jour Normal</th>
                <th colspan="2">Dimanche</th>
                <th colspan="2">Jour Férié</th>
                <th rowspan="3" style="width:48px">Total H</th>
                <th rowspan="3" style="width:60px">Total HT</th>
            </tr>
            <tr>
                <th class="col-jn">JOUR</th>
                <th class="col-jn">NUIT</th>
                <th class="col-jd">JOUR</th>
                <th class="col-jd">NUIT</th>
                <th class="col-jf">JOUR</th>
                <th class="col-jf">NUIT</th>
            </tr>
            <tr>
                <td class="col-jn"><?= number_format($tauxJn, 2) ?></td>
                <td class="col-jn"><?= number_format($tauxNn, 2) ?></td>
                <td class="col-jd"><?= number_format($tauxJd, 2) ?></td>
                <td class="col-jd"><?= number_format($tauxNd, 2) ?></td>
                <td class="col-jf"><?= number_format($tauxJf, 2) ?></td>
                <td class="col-jf"><?= number_format($tauxNf, 2) ?></td>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($donnees['rows'] as $row):
            $ferie    = isFerie($row['jour']);
            $dimanche = isDimanche($row['jour']);
            $isZero   = $row['tH'] == 0;
            $jourAbrev = $joursAbrev[date('N', strtotime($row['jour']))] ?? '';
            $jourFmt   = $jourAbrev . ' ' . date('d/m', strtotime($row['jour']));

            $trCls = $isZero ? 'zero' : '';
            if ($ferie) {
                $dateCls = 'td-date-ferie';
            } elseif ($dimanche) {
                $dateCls = 'td-date-dim';
            } else {
                $dateCls = '';
            }

            // Colonnes actives
            $activeJn = !$ferie && !$dimanche;
            $activeNn = !$ferie && !$dimanche;
            $activeJd = $dimanche && !$ferie;
            $activeNd = $dimanche && !$ferie;
            $activeJf = $ferie;
            $activeNf = $ferie;

        ?>
        <tr class="<?= $trCls ?>">
            <td class="<?= $dateCls ?>"><?= h($jourFmt) ?></td>
            <?= showH($row['hJn'], $activeJn) ?>
            <?= showH($row['hNn'], $activeNn) ?>
            <?= showH($row['hJd'], $activeJd) ?>
            <?= showH($row['hNd'], $activeNd) ?>
            <?= showH($row['hJf'], $activeJf) ?>
            <?= showH($row['hNf'], $activeNf) ?>
            <td><?= $row['tH'] > 0 ? number_format($row['tH'], 1) : '—' ?></td>
            <td class="text-right"><?= $row['tHT'] > 0 ? number_format($row['tHT'], 2, ',', ' ') : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>Sous-total</td>
                <td><?= $donnees['h'] > 0 ? number_format(array_sum(array_column($donnees['rows'], 'hJn')), 1) : '—' ?></td>
                <td><?= number_format(array_sum(array_column($donnees['rows'], 'hNn')), 1) ?: '—' ?></td>
                <td><?= number_format(array_sum(array_column($donnees['rows'], 'hJd')), 1) ?: '—' ?></td>
                <td><?= number_format(array_sum(array_column($donnees['rows'], 'hNd')), 1) ?: '—' ?></td>
                <td><?= number_format(array_sum(array_column($donnees['rows'], 'hJf')), 1) ?: '—' ?></td>
                <td><?= number_format(array_sum(array_column($donnees['rows'], 'hNf')), 1) ?: '—' ?></td>
                <td><?= number_format($donnees['h'], 1) ?></td>
                <td class="text-right"><?= number_format($donnees['ht'], 2, ',', ' ') ?> €</td>
            </tr>
        </tfoot>
    </table>
    <?php endforeach; ?>

    <!-- Récapitulatif final -->
    <table class="tbl-recap">
        <tr>
            <td class="lbl">Total général heures</td>
            <td class="val"><?= number_format($grandTotalH, 1) ?> h</td>
        </tr>
        <tr>
            <td class="lbl">Total HT</td>
            <td class="val"><?= number_format($grandTotalHT, 2, ',', ' ') ?> €</td>
        </tr>
        <tr>
            <td class="lbl">TVA <?= h($devis['tva_taux']) ?>%</td>
            <td class="val"><?= number_format($tvaMontant, 2, ',', ' ') ?> €</td>
        </tr>
        <tr class="ttc">
            <td class="lbl">Total NET TTC</td>
            <td class="val"><?= number_format($grandTotalTTC, 2, ',', ' ') ?> €</td>
        </tr>
    </table>

    <!-- Référence document -->
    <p style="font-size:6.5pt;color:#aaa;text-align:right;margin:8px 0 2px 0">
        Devis <?= h($devis['numero']) ?> &nbsp;·&nbsp; Généré le <?= date('d/m/Y') ?>
    </p>

    <!-- Pied de page fixe -->
    <div class="footer">
        <div class="footer-line1" style="text-align:center">Oeil Vigilant (SAS) &nbsp;·&nbsp; 58 RUE DE MONCEAU 75008 PARIS &nbsp;·&nbsp; contact@oeilvigilant.com</div>
        <div class="footer-line2">SIREN : 928 552 702 &nbsp;·&nbsp; TVA : FR90928552702 &nbsp;·&nbsp; Tél : +33 (0)7 78 54 24 35 / +33 (0)7 84 90 19 93 &nbsp;·&nbsp; N° autorisation : AUT-075-2123-06-21-20240934026</div>
        <div class="footer-legal">L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient.</div>
    </div>

</div>
</body>
</html>
<?php
$html = ob_get_clean();

// DomPDF
if (!file_exists(DOMPDF_AUTOLOAD)) {
    die('DomPDF introuvable. Vérifiez que Composer a été exécuté et que vendor/autoload.php existe.');
}
require_once DOMPDF_AUTOLOAD;

use Dompdf\Dompdf;
use Dompdf\Options;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'devis-' . preg_replace('/[^A-Za-z0-9\-]/', '', $devis['numero']) . '.pdf';
$dompdf->stream($filename, ['Attachment' => false]);
