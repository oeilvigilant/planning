<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
requirePerm('agents', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { header('Location: index.php'); exit; }

$params      = getAllParams();
$champs      = $db->query("SELECT * FROM carte_champs WHERE actif=1 ORDER BY face, ordre")->fetchAll();
$rectoChamps = array_values(array_filter($champs, fn($c) => $c['face'] === 'recto'));

// ── Dimensions (mm) ──────────────────────────────────────────────────
$wMm = max(85, min(210, (int)($_GET['w'] ?? 105)));
$hMm = max(50, min(150, (int)($_GET['h'] ?? 74)));

// ── Zones (mm) ───────────────────────────────────────────────────────
$barTop  = 1.5;
$barBot  = 2.5;
$hdrH    = round($hMm * 0.37, 1);
$ftrH    = round($hMm * 0.17, 1);
$bodyH   = round($hMm - $barTop - $barBot - $hdrH - 0.3 - $ftrH, 1);

// ── Typographie (pt) ─────────────────────────────────────────────────
$fCie      = round($wMm * 0.096, 1);  // Nom société (pt)
$fAddr     = round($wMm * 0.038, 1);
$fLogoName = round($wMm * 0.033, 1);
$fSlogan   = round($wMm * 0.028, 1);
$fField    = round($wMm * 0.043, 1);
$fMat      = round($wMm * 0.033, 1);
$fCnaps    = round($wMm * 0.031, 1);
$fLegal    = round($wMm * 0.026, 1);

// ── Photo / Logo (mm) ────────────────────────────────────────────────
$phH     = round($hdrH * 0.82, 1);
$phW     = round($phH  * 0.68, 1);  // ratio portrait 2:3
$logoH   = round($hdrH * 0.44, 1);
$logoCol = round($wMm * 0.21, 1);
$photoCol= round($wMm * 0.19, 1);

// ── Images base64 ────────────────────────────────────────────────────
function b64img(string $path): string {
    if (!file_exists($path)) return '';
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mime = $ext === 'png' ? 'image/png' : ($ext === 'svg' ? 'image/svg+xml' : 'image/jpeg');
    return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
}
$photoB64 = $a['photo'] ? b64img(UPLOAD_PATH.'/'.$a['photo']) : '';
$logoB64  = b64img(APP_ROOT.'/assets/img/'.($params['logo_principal'] ?? 'logo.png'));

// ── Données ──────────────────────────────────────────────────────────
function getChampValPdfBadge(array $a, array $params, string $cle): string {
    $agentMap = [
        'prenom_nom'             => trim($a['prenom'].' '.$a['nom']),
        'nom'                    => $a['nom'] ?? '',
        'prenom'                 => $a['prenom'] ?? '',
        'matricule'              => $a['matricule'] ?? '',
        'poste'                  => $a['poste'] ?? '',
        'num_autorisation_cnaps' => $a['num_autorisation_cnaps'] ?? '',
        'date_naissance'         => !empty($a['date_naissance']) ? date('d/m/Y', strtotime($a['date_naissance'])) : '',
        'date_debut_contrat'     => $a['date_debut_contrat'] ? date('d/m/Y', strtotime($a['date_debut_contrat'])) : '',
        'date_expiration_cnaps'  => $a['date_expiration_cnaps'] ? date('d/m/Y', strtotime($a['date_expiration_cnaps'])) : '',
    ];
    $entMap = [
        'entreprise_nom'     => $params['entreprise_nom']     ?? '',
        'entreprise_adresse' => trim(($params['entreprise_adresse']??'').' '.($params['entreprise_cp']??'').' '.($params['entreprise_ville']??'')),
        'entreprise_tel'     => $params['entreprise_tel']     ?? '',
        'entreprise_siret'   => 'SIRET: '.($params['entreprise_siret'] ?? ''),
    ];
    return $agentMap[$cle] ?? $entMap[$cle] ?? '';
}

$companyName = $params['entreprise_nom'] ?? 'Oeil Vigilant';
$companyAddr = strtoupper(trim(($params['entreprise_adresse']??'').' '.($params['entreprise_cp']??'').' '.($params['entreprise_ville']??'')));
$slogan      = $params['entreprise_slogan'] ?? 'Votre sécurité, notre priorité';
$cnapsNum    = $a['num_autorisation_cnaps'] ?? '';
$legalText   = $params['carte_mention_legale']
    ?? "L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient";
$mat         = $a['matricule'] ?? '';
$initiales   = strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1));

// Champs corps
$bodyFields = [];
foreach ($rectoChamps as $c) {
    if (in_array($c['cle'], ['photo','logo'])) continue;
    $val = getChampValPdfBadge($a, $params, $c['cle']);
    if ($val !== '') $bodyFields[] = ['label' => $c['label'], 'value' => $val];
}

ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
@page { size: <?= $wMm ?>mm <?= $hMm ?>mm; margin: 0; }
body  { font-family: Arial, Helvetica, sans-serif; width: <?= $wMm ?>mm; height: <?= $hMm ?>mm; overflow: hidden; background: white; }

.badge {
    width: <?= $wMm ?>mm;
    height: <?= $hMm ?>mm;
    background-color: #fff;
    border: 0.3mm solid #bbb;
    overflow: hidden;
    position: relative;
}

/* Barres */
.bar { width: 100%; background: #111; font-size: 0; line-height: 0; display: block; }
.bar-top { height: <?= $barTop ?>mm; }
.bar-bot { height: <?= $barBot ?>mm; }

/* ── En-tête ── */
.hdr { width: 100%; border-collapse: collapse; table-layout: fixed; }
.hdr td { vertical-align: top; }

.td-logo  { width: <?= $logoCol ?>mm;  padding: 1.5mm 1mm 1mm 3mm; }
.td-ctr   { text-align: center; padding: 2mm 2mm 1mm 2mm; }
.td-photo { width: <?= ($photoCol + 2) ?>mm; padding: 1mm 3mm 1mm 1mm; text-align: center; }

.logo-img  { height: <?= $logoH ?>mm; max-width: <?= ($logoCol - 2) ?>mm; }
.logo-name { font-size: <?= $fLogoName ?>pt; font-weight: 700; letter-spacing: 0.3pt; color: #111; text-transform: uppercase; margin-top: 1mm; }
.logo-slogan { font-size: <?= $fSlogan ?>pt; color: #666; font-style: italic; line-height: 1.3; margin-top: 0.5mm; }

.cie-name { font-family: Georgia, serif; font-size: <?= $fCie ?>pt; font-weight: 400; color: #111; letter-spacing: 0.5pt; line-height: 1.1; }
.cie-addr { font-size: <?= $fAddr ?>pt; color: #333; margin-top: 1.5mm; letter-spacing: 0.2pt; }

.photo-img  { width: <?= $phW ?>mm; height: <?= $phH ?>mm; border: 0.3mm solid #bbb; }
.photo-init { width: <?= $phW ?>mm; height: <?= $phH ?>mm; border: 0.3mm solid #bbb; background: #ebebeb; font-size: <?= round($fField*1.3,1) ?>pt; font-weight: 700; color: #888; text-align: center; line-height: <?= $phH ?>mm; }
.mat-lbl { font-size: <?= $fMat ?>pt; font-weight: 700; color: #111; margin-top: 0.8mm; text-align: center; }

/* Séparateur */
.sep { height: 0.3mm; background: #ccc; margin: 0 3mm; }

/* ── Corps ── */
.body { height: <?= $bodyH ?>mm; padding: 1.5mm 4mm 1mm 4mm; overflow: hidden; position: relative; }

.watermark {
    position: absolute;
    top: <?= round($bodyH * 0.08, 1) ?>mm;
    left: <?= round($wMm * 0.27, 1) ?>mm;
    text-align: center;
    opacity: 0.055;
}
.watermark img { width: <?= round($wMm * 0.28, 1) ?>mm; }
.wm-txt {
    font-size: <?= round($wMm * 0.09, 1) ?>pt;
    font-weight: 700;
    color: #000;
    letter-spacing: 1pt;
    text-transform: uppercase;
    text-align: center;
    margin-top: -1mm;
    opacity: 0.055;
}

.field { font-size: <?= $fField ?>pt; color: #111; margin-bottom: <?= round($bodyH * 0.06, 1) ?>mm; line-height: 1.25; }

/* ── Pied ── */
.footer { height: <?= $ftrH ?>mm; border-top: 0.2mm solid #ddd; padding: 1mm 3mm 0.5mm; text-align: center; overflow: hidden; }
.cnaps-txt { font-size: <?= $fCnaps ?>pt; color: #333; }
.legal-txt { font-size: <?= $fLegal ?>pt; color: #666; margin-top: 0.8mm; line-height: 1.35; }
</style>
</head>
<body>
<div class="badge">

  <div class="bar bar-top"></div>

  <!-- En-tête -->
  <table class="hdr">
  <tr>
    <td class="td-logo">
      <?php if ($logoB64): ?>
      <img src="<?= $logoB64 ?>" class="logo-img" alt="">
      <?php endif; ?>
      <div class="logo-name"><?= htmlspecialchars(strtoupper($companyName)) ?></div>
      <?php if ($slogan): ?>
      <div class="logo-slogan"><?= htmlspecialchars($slogan) ?></div>
      <?php endif; ?>
    </td>

    <td class="td-ctr">
      <div class="cie-name"><?= htmlspecialchars($companyName) ?></div>
      <?php if ($companyAddr): ?>
      <div class="cie-addr"><?= htmlspecialchars($companyAddr) ?></div>
      <?php endif; ?>
    </td>

    <td class="td-photo">
      <?php if ($photoB64): ?>
      <img src="<?= $photoB64 ?>" class="photo-img" alt="">
      <?php else: ?>
      <div class="photo-init"><?= htmlspecialchars($initiales) ?></div>
      <?php endif; ?>
      <?php if ($mat): ?>
      <div class="mat-lbl"><b>MAT:</b> <?= htmlspecialchars($mat) ?></div>
      <?php endif; ?>
    </td>
  </tr>
  </table>

  <div class="sep"></div>

  <!-- Corps -->
  <div class="body">
    <?php if ($logoB64): ?>
    <div class="watermark">
      <img src="<?= $logoB64 ?>" alt="">
    </div>
    <?php endif; ?>
    <div class="wm-txt"><?= htmlspecialchars(strtoupper($companyName)) ?></div>

    <?php foreach ($bodyFields as $f): ?>
    <div class="field">
      <b><?= htmlspecialchars($f['label']) ?> :</b>
      <?= htmlspecialchars($f['value']) ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pied -->
  <?php if ($cnapsNum || $legalText): ?>
  <div class="footer">
    <?php if ($cnapsNum): ?>
    <div class="cnaps-txt">CNAPS : <?= htmlspecialchars($cnapsNum) ?></div>
    <?php endif; ?>
    <?php if ($legalText): ?>
    <div class="legal-txt"><?= htmlspecialchars($legalText) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="bar bar-bot"></div>

</div>
</body>
</html>
<?php
$html = ob_get_clean();

// ── Rendu DomPDF à la taille exacte ─────────────────────────────────
if (file_exists(DOMPDF_AUTOLOAD)) {
    require_once DOMPDF_AUTOLOAD;
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Arial');
    $options->set('chroot', APP_ROOT);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $pt = 2.83465; // mm → pt
    $dompdf->setPaper([0, 0, $wMm * $pt, $hMm * $pt]);
    $dompdf->render();
    $filename = 'badge_'.strtolower($a['nom']).'_'.strtolower($a['prenom']).'.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}
// Fallback
header('Content-Type: text/html; charset=utf-8');
echo $html;
echo '<script>window.onload=function(){window.print();}</script>';
exit;
