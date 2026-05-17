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

$params = getAllParams();

// ── Dimensions (mm) ──────────────────────────────────────────────
$wMm = max(54, min(210, (int)($_GET['w'] ?? 86)));
$hMm = max(34, min(150, (int)($_GET['h'] ?? 54)));

// ── Constante mm→pt ─────────────────────────────────────────────
$pt = 2.83465; // 1mm = 2.83465pt

// ── Zones (mm) — légèrement sous la page pour éviter la 2e page DomPDF ──
$safeH  = $hMm - 0.6;               // buffer 0.6mm
$barTop = round($safeH * 0.014, 2);
$barBot = round($safeH * 0.022, 2);
$hdrH   = round($safeH * 0.355, 2);
$ftrH   = round($safeH * 0.165, 2);
$bodyH  = round($safeH - $barTop - $barBot - $hdrH - 0.3 - $ftrH, 2);

// ── Colonnes header (mm) ─────────────────────────────────────────
$logoCol  = round($wMm * 0.22, 2);
$photoCol = round($wMm * 0.19, 2);
$centerW  = round($wMm - $logoCol - $photoCol, 2);

// ── Photo frame (mm) ─────────────────────────────────────────────
$phH = round($hdrH * 0.88, 2);
$phW = round($phH  * 0.68, 2);

// ── Logo (mm) ────────────────────────────────────────────────────
$logoH    = round($hdrH * 0.52, 2);
$logoMaxW = round($logoCol - 3, 2);

// ── Typographie (pt) ─────────────────────────────────────────────
$fCie    = round($wMm * 0.110, 1);  // "Oeil Vigilant" italic serif
$fAddr   = round($wMm * 0.040, 1);  // adresse
$fSlogan = round($wMm * 0.027, 1);  // slogan
$fLname  = round($wMm * 0.030, 1);  // sous logo
$fField  = round($wMm * 0.048, 1);  // champs body
$fMat    = round($wMm * 0.038, 1);  // matricule
$fCnaps  = round($wMm * 0.036, 1);  // CNAPS footer
$fLegal  = round($wMm * 0.030, 1);  // mention légale

// ── Images base64 ────────────────────────────────────────────────
function b64img(string $path): string {
    if (!file_exists($path) || !is_readable($path)) return '';
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $mimeMap = ['png' => 'image/png', 'svg' => 'image/svg+xml'];
    $mime = $mimeMap[$ext] ?? 'image/jpeg';
    return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
}

$logoB64  = b64img(APP_ROOT.'/assets/img/'.($params['logo_principal'] ?? 'logo.png'));
$photoB64 = $a['photo'] ? b64img(UPLOAD_PATH.'/'.$a['photo']) : '';

// ── Données ──────────────────────────────────────────────────────
$companyName = $params['entreprise_nom']       ?? 'Oeil Vigilant';
$companyAddr = strtoupper(trim(($params['entreprise_adresse']??'').' '.($params['entreprise_cp']??'').' '.($params['entreprise_ville']??'')));
$slogan      = $params['entreprise_slogan']    ?? 'VOTRE SÉCURITÉ, NOTRE PRIORITÉ';
$cnapsEnt    = $params['entreprise_cnaps']     ?? '';
$legalText   = $params['carte_mention_legale'] ?? "L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient";
$mat         = $a['matricule'] ?? '';
$initiales   = strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1));

$bodyFields = [
    ['label'=>'Nom',                             'value'=>strtoupper($a['nom']    ?? '')],
    ['label'=>'Prénom',                          'value'=>$a['prenom']            ?? ''],
    ['label'=>'Né(e) le',                        'value'=>!empty($a['date_naissance'])   ? date('d/m/Y', strtotime($a['date_naissance']))       : ''],
    ['label'=>'Numéro de carte professionnelle', 'value'=>$a['num_autorisation_cnaps']   ?? ''],
    ['label'=>'Validité',                        'value'=>$a['date_expiration_cnaps']     ? date('d/m/Y', strtotime($a['date_expiration_cnaps'])) : ''],
];
// Tous les champs sont affichés même vides (espace réservé)

// ── HTML → DomPDF ────────────────────────────────────────────────
ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

@page {
    size: <?= $wMm ?>mm <?= $hMm ?>mm;
    margin: 0;
}

html, body {
    margin: 0; padding: 0;
    font-family: Arial, Helvetica, sans-serif;
    width: <?= $wMm ?>mm;
    height: <?= $hMm ?>mm;
    overflow: hidden;
    background: white;
}

.badge {
    width: <?= $wMm ?>mm;
    height: <?= $safeH ?>mm;
    background: white;
    border: 0.2mm solid #bbb;
    overflow: hidden;
    position: relative;
    page-break-inside: avoid;
}

/* Barres */
.bar { width: 100%; background: #111; font-size:0; line-height:0; display:block; }
.bar-top { height: <?= $barTop ?>mm; }
.bar-bot { height: <?= $barBot ?>mm; }

/* ── En-tête (table 3 colonnes) ── */
.hdr-table {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
    height: <?= $hdrH ?>mm;
}
.td-logo {
    width: <?= $logoCol ?>mm;
    vertical-align: middle;
    text-align: center;
    padding: 1.5mm 1mm 1mm 2.5mm;
    border-right: 0.2mm solid #eee;
}
.td-center {
    vertical-align: middle;
    text-align: center;
    padding: 1.5mm 2mm;
    border-right: 0.2mm solid #eee;
}
.td-photo {
    width: <?= $photoCol ?>mm;
    vertical-align: middle;
    text-align: center;
    padding: 1mm 2.5mm 1mm 1mm;
}

.logo-img    { height: <?= $logoH ?>mm; max-width: <?= $logoMaxW ?>mm; }
.logo-name   { font-size: <?= $fLname ?>pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.4pt; color: #111; margin-top: 0.8mm; }
.logo-slogan { font-size: <?= $fSlogan ?>pt; color: #666; font-style: italic; line-height: 1.3; margin-top: 0.5mm; }

.cie-name { font-family: Georgia, serif; font-size: <?= $fCie ?>pt; font-weight: 400; font-style: italic; color: #0a1628; letter-spacing: 0.3pt; line-height: 1.1; }
.cie-addr { font-size: <?= $fAddr ?>pt; color: #444; font-weight: 600; margin-top: 1mm; letter-spacing: 0.2pt; }

/* Photo frame : table cell overflow:hidden pour simuler le cadre fixe */
.photo-outer { width: <?= $phW ?>mm; height: <?= $phH ?>mm; border: 0.2mm solid #bbb; overflow: hidden; margin: 0 auto; background: #ebebeb; }
.photo-img   { width: <?= $phW ?>mm; height: <?= $phH ?>mm; }
.photo-init  { width: <?= $phW ?>mm; height: <?= $phH ?>mm; font-size: <?= round($phW * 0.85, 1) ?>pt; font-weight: 700; color: #bbb; text-align: center; line-height: <?= $phH ?>mm; font-family: Georgia, serif; }
.mat-lbl     { font-size: <?= $fMat ?>pt; font-weight: 700; color: #111; margin-top: 0.8mm; text-align: center; }
.mat-lbl span { font-weight: 400; color: #777; }

/* Séparateur */
.sep { height: 0.3mm; background: #ddd; display: block; font-size:0; }

/* ── Corps ── */
.body {
    height: <?= $bodyH ?>mm;
    padding: <?= round($bodyH * 0.09, 1) ?>mm 5mm <?= round($bodyH * 0.05, 1) ?>mm 5mm;
    overflow: hidden;
    position: relative;
}

/* Filigrane */
.watermark {
    position: absolute;
    top: <?= round($bodyH * 0.05, 1) ?>mm;
    left: <?= round(($wMm - $wMm*0.22) / 2, 1) ?>mm;
    text-align: center;
    opacity: 0.055;
}
.watermark img { width: <?= round($wMm * 0.20, 1) ?>mm; display: block; margin: 0 auto; }
.wm-txt {
    font-size: <?= round($wMm * 0.130, 1) ?>pt;
    font-weight: 700;
    color: #000;
    letter-spacing: 1.5pt;
    text-transform: uppercase;
    text-align: center;
    margin-top: -0.5mm;
}

/* Champs */
.field-row {
    margin-bottom: <?= round($bodyH * 0.095, 1) ?>mm;
    font-size: <?= $fField ?>pt;
    line-height: 1.25;
}
.field-lbl { font-weight: 700; color: #111; }
.field-val { font-weight: 700; color: #111; }

/* ── Pied ── */
.footer {
    height: <?= $ftrH ?>mm;
    border-top: 0.2mm solid #ddd;
    background: #fafafa;
    padding: 1mm 3mm 0.5mm;
    text-align: center;
    overflow: hidden;
}
.footer-cnaps { font-size: <?= $fCnaps ?>pt; font-weight: 700; color: #333; margin-bottom: 0.7mm; }
.footer-legal { font-size: <?= $fLegal ?>pt; color: #777; line-height: 1.4; }
</style>
</head>
<body>
<div class="badge">

  <div class="bar bar-top"></div>

  <!-- En-tête -->
  <table class="hdr-table">
  <tr>
    <!-- Logo -->
    <td class="td-logo">
      <?php if ($logoB64): ?>
      <img src="<?= $logoB64 ?>" class="logo-img" alt="">
      <?php endif; ?>
      <div class="logo-name"><?= htmlspecialchars(strtoupper($companyName)) ?></div>
      <?php if ($slogan): ?>
      <div class="logo-slogan"><?= htmlspecialchars($slogan) ?></div>
      <?php endif; ?>
    </td>

    <!-- Centre -->
    <td class="td-center">
      <div class="cie-name"><?= htmlspecialchars($companyName) ?></div>
      <?php if ($companyAddr): ?>
      <div class="cie-addr"><?= htmlspecialchars($companyAddr) ?></div>
      <?php endif; ?>
    </td>

    <!-- Photo -->
    <td class="td-photo">
      <div class="photo-outer">
        <?php if ($photoB64): ?>
          <img src="<?= $photoB64 ?>" class="photo-img" alt="">
        <?php else: ?>
          <div class="photo-init"><?= htmlspecialchars($initiales) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($mat): ?>
      <div class="mat-lbl"><span>MAT:</span> <?= htmlspecialchars($mat) ?></div>
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
      <div class="wm-txt"><?= htmlspecialchars(strtoupper($companyName)) ?></div>
    </div>
    <?php endif; ?>

    <?php foreach ($bodyFields as $f): ?>
    <div class="field-row">
      <span class="field-lbl"><?= htmlspecialchars($f['label']) ?> : </span>
      <span class="field-val"><?= htmlspecialchars($f['value']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pied -->
  <div class="footer">
    <?php if ($cnapsEnt): ?>
    <div class="footer-cnaps">CNAPS : <?= htmlspecialchars($cnapsEnt) ?></div>
    <?php endif; ?>
    <?php if ($legalText): ?>
    <div class="footer-legal"><?= htmlspecialchars($legalText) ?></div>
    <?php endif; ?>
  </div>

  <div class="bar bar-bot"></div>

</div>
</body>
</html>
<?php
$html = ob_get_clean();

if (file_exists(DOMPDF_AUTOLOAD)) {
    require_once DOMPDF_AUTOLOAD;
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Arial');
    $options->set('chroot', APP_ROOT);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper([0, 0, $wMm * $pt, $hMm * $pt]);
    $dompdf->render();
    $filename = 'badge_'.strtolower($a['nom']).'_'.strtolower($a['prenom']).'.pdf';
    $dompdf->stream($filename, ['Attachment' => true]);
    exit;
}
// Fallback navigateur
header('Content-Type: text/html; charset=utf-8');
echo $html;
echo '<script>window.onload=function(){window.print();}</script>';
exit;
