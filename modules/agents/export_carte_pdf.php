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

// ── Dimensions ───────────────────────────────────────────────────
$wMm = max(54, min(210, (int)($_GET['w'] ?? 86)));
$hMm = max(34, min(150, (int)($_GET['h'] ?? 54)));
$pt  = 2.83465; // 1mm = 2.83465pt

$wPt = round($wMm * $pt, 2);
$hPt = round($hMm * $pt, 2);

// ── Hauteurs des lignes (en pt) — total = hPt exactement ────────
$barTopPt = round($hPt * 0.013, 2);
$barBotPt = round($hPt * 0.020, 2);
$hdrPt    = round($hPt * 0.370, 2);
$ftrPt    = round($hPt * 0.195, 2);
$bodyPt   = round($hPt - $barTopPt - $barBotPt - $hdrPt - $ftrPt, 2);

// ── Colonnes header (pt) ─────────────────────────────────────────
$logoColPt  = round($wPt * 0.215, 2);
$photoColPt = round($wPt * 0.195, 2);

// ── Photo (pt) ───────────────────────────────────────────────────
$phHpt = round($hdrPt * 0.68, 2);
$phWpt = round($phHpt * 0.75, 2);

// ── Logo (pt) ────────────────────────────────────────────────────
$logoHpt    = round($hdrPt * 0.80, 2);
$logoMaxWpt = round($logoColPt - 5, 2);

// ── Typographie (pt) ─────────────────────────────────────────────
$fCie    = round($wMm * 0.145, 1);  // nom société — agrandi
$fAddr   = round($wMm * 0.042, 1);  // adresse
$fSlogan = round($wMm * 0.032, 1);  // slogan
$fField  = round($wMm * 0.053, 1);  // champs body
$fMat    = round($wMm * 0.038, 1);  // matricule
$fCnaps  = round($wMm * 0.040, 1);  // CNAPS footer
$fLegal  = round($wMm * 0.030, 1);  // mention légale

// ── Espacements body ─────────────────────────────────────────────
$bodyPadT  = round($bodyPt * 0.06, 1);  // petit pad-top pour remonter le bloc
$bodyPadS  = round($wPt * 0.018, 1);
$fieldGap  = round($bodyPt * 0.075, 1);

// ── Espacements footer ───────────────────────────────────────────
$ftrPadT = round($ftrPt * 0.08, 1);
$ftrPadS = round($wPt * 0.018, 1);

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
$companyName = $params['entreprise_nom']    ?? 'Oeil Vigilant';
$companyAddr = strtoupper(trim(
    ($params['entreprise_adresse'] ?? '') . ' ' .
    ($params['entreprise_cp']      ?? '') . ' ' .
    ($params['entreprise_ville']   ?? '')
));
$slogan   = $params['entreprise_slogan']    ?? 'VOTRE SÉCURITÉ, NOTRE PRIORITÉ';
$cnapsEnt = $params['entreprise_cnaps']     ?? '';
$legalText= $params['carte_mention_legale'] ?? "L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient";
$mat      = $a['matricule'] ?? '';
$initiales= strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1));

$bodyFields = [
    ['label' => 'Nom',                             'value' => strtoupper($a['nom']   ?? '')],
    ['label' => 'Prénom',                          'value' => $a['prenom']           ?? ''],
    ['label' => 'Né(e) le',                        'value' => !empty($a['date_naissance'])  ? date('d/m/Y', strtotime($a['date_naissance']))       : ''],
    ['label' => 'N° carte professionnelle',        'value' => $a['num_autorisation_cnaps']  ?? ''],
    ['label' => 'Validité',                        'value' => !empty($a['date_expiration_cnaps']) ? date('d/m/Y', strtotime($a['date_expiration_cnaps'])) : ''],
];
// Tous les champs affichés même vides (espace réservé)

// ── HTML ─────────────────────────────────────────────────────────
ob_start(); ?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

@page { size: <?= $wMm ?>mm <?= $hMm ?>mm; margin: 0; }

html, body {
    margin: 0; padding: 0;
    width: <?= $wPt ?>pt;
    height: <?= $hPt ?>pt;
    overflow: hidden;
    background: #fff;
    font-family: Arial, Helvetica, sans-serif;
}

/* ── Table maîtresse : remplit la page exactement ── */
.card {
    width: <?= $wPt ?>pt;
    height: <?= $hPt ?>pt;
    border-collapse: collapse;
    table-layout: fixed;
    border: none;
    page-break-inside: avoid;
}

/* ── Barres ── */
.r-bar-t td { height: <?= $barTopPt ?>pt; background: #0d1118; padding: 0; font-size: 0; overflow: hidden; }
.r-bar-b td { height: <?= $barBotPt ?>pt; background: #0d1118; padding: 0; font-size: 0; overflow: hidden; }

/* ── En-tête ── */
.r-hdr td { height: <?= $hdrPt ?>pt; padding: 0; vertical-align: top; overflow: hidden; }

.hdr-inner {
    width: <?= $wPt ?>pt;
    height: <?= $hdrPt ?>pt;
    border-collapse: collapse;
    table-layout: fixed;
}

.td-logo {
    width: <?= $logoColPt ?>pt;
    vertical-align: middle;
    text-align: center;
    padding: 3pt 2pt 3pt 4pt;
    background: #f4f7fc;
    overflow: hidden;
}
.td-center {
    vertical-align: middle;
    text-align: center;
    padding: 3pt 6pt;
    background: #f4f7fc;
    overflow: hidden;
}
.td-photo {
    width: <?= $photoColPt ?>pt;
    vertical-align: middle;
    text-align: center;
    padding: 2pt 4pt 2pt 2pt;
    background: #f4f7fc;
    overflow: hidden;
}

.logo-img    { height: <?= $logoHpt ?>pt; max-width: <?= $logoMaxWpt ?>pt; }
.cie-name    { font-family: Georgia, serif; font-size: <?= $fCie ?>pt; font-weight: 700; font-style: italic; color: #0a1628; line-height: 1.05; letter-spacing: 0.2pt; }
.cie-slogan  { font-size: <?= $fSlogan ?>pt; color: #888; font-style: italic; margin-top: 1.5pt; line-height: 1.1; text-transform: uppercase; }
.cie-addr    { font-size: <?= $fAddr ?>pt; color: #333; font-weight: 600; margin-top: 1.5pt; letter-spacing: 0.2pt; }

.photo-frame { width: <?= $phWpt ?>pt; height: <?= $phHpt ?>pt; border: 0.3pt solid #ccc; overflow: hidden; margin: 0 auto; background: #e4e8ef; }
.photo-img   { width: <?= $phWpt ?>pt; height: <?= $phHpt ?>pt; }
.photo-init  { width: <?= $phWpt ?>pt; height: <?= $phHpt ?>pt; font-size: <?= round($phWpt*0.28, 1) ?>pt; font-weight: 700; color: #b0b8c8; text-align: center; line-height: <?= $phHpt ?>pt; font-family: Georgia, serif; }
.mat-lbl     { font-size: <?= $fMat ?>pt; font-weight: 700; color: #111; margin-top: 1.5pt; text-align: center; }
.mat-lbl span{ font-weight: 400; color: #888; }

/* ── Corps ── */
.r-body td {
    height: <?= $bodyPt ?>pt;
    padding: <?= $bodyPadT ?>pt <?= $bodyPadS ?>pt 0 <?= $bodyPadS ?>pt;
    vertical-align: top;
    overflow: hidden;
    background: #ffffff;
}

.field-row   { margin-bottom: <?= $fieldGap ?>pt; font-size: <?= $fField ?>pt; line-height: 1.25; }
.field-lbl   { font-weight: 700; color: #0a1628; }
.field-val   { font-weight: 600; color: #111; }
.field-empty { font-weight: 400; color: #bbb; font-style: italic; }

/* ── Pied ── */
.r-ftr td {
    height: <?= $ftrPt ?>pt;
    padding: <?= $ftrPadT ?>pt <?= $ftrPadS ?>pt;
    vertical-align: top;
    text-align: center;
    overflow: hidden;
    background: #f4f7fc;
    border-top: 0.5pt solid #d8dfe8;
}
.ftr-cnaps { font-size: <?= $fCnaps ?>pt; font-weight: 700; color: #0a1628; margin-bottom: 1.2pt; letter-spacing: 0.2pt; }
.ftr-legal { font-size: <?= $fLegal ?>pt; color: #555; line-height: 1.35; }
</style>
</head>
<body>
<table class="card">

  <!-- Barre supérieure -->
  <tr class="r-bar-t"><td></td></tr>

  <!-- En-tête -->
  <tr class="r-hdr">
    <td>
      <table class="hdr-inner">
      <tr>
        <td class="td-logo">
          <?php if ($logoB64): ?>
          <img src="<?= $logoB64 ?>" class="logo-img" alt="">
          <?php endif; ?>
        </td>
        <td class="td-center">
          <div class="cie-name"><?= htmlspecialchars($companyName) ?></div>
          <?php if ($slogan): ?>
          <div class="cie-slogan"><?= htmlspecialchars($slogan) ?></div>
          <?php endif; ?>
          <?php if ($companyAddr): ?>
          <div class="cie-addr"><?= htmlspecialchars($companyAddr) ?></div>
          <?php endif; ?>
        </td>
        <td class="td-photo">
          <div class="photo-frame">
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
    </td>
  </tr>

  <!-- Corps -->
  <tr class="r-body">
    <td>
      <?php foreach ($bodyFields as $f): ?>
      <div class="field-row">
        <span class="field-lbl"><?= htmlspecialchars($f['label']) ?> : </span>
        <?php if ($f['value'] !== ''): ?>
          <span class="field-val"><?= htmlspecialchars($f['value']) ?></span>
        <?php else: ?>
          <span class="field-empty">—</span>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </td>
  </tr>

  <!-- Pied -->
  <tr class="r-ftr">
    <td>
      <?php if ($cnapsEnt): ?>
      <div class="ftr-cnaps">CNAPS : <?= htmlspecialchars($cnapsEnt) ?></div>
      <?php endif; ?>
      <?php if ($legalText): ?>
      <div class="ftr-legal"><?= htmlspecialchars($legalText) ?></div>
      <?php endif; ?>
    </td>
  </tr>

  <!-- Barre inférieure -->
  <tr class="r-bar-b"><td></td></tr>

</table>
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
    $dompdf->setPaper([0, 0, $wPt, $hPt]);
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
