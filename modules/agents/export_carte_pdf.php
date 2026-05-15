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

// Dimensions (mm) — passées depuis carte.php
$wMm = max(85, min(210, (int)($_GET['w'] ?? 105)));
$hMm = max(50, min(150, (int)($_GET['h'] ?? 74)));

// Calcul des zones (mm)
$headerH  = round($hMm * 0.42, 1);   // ~42% pour l'en-tête
$bodyH    = round($hMm * 0.38, 1);   // ~38% pour le corps
$footerH  = round($hMm * 0.16, 1);   // ~16% pour le pied
$barH     = 1.5;                      // mm — barres noires
$photoW   = round($wMm * 0.155, 1);  // largeur photo
$photoH   = round($headerH - 4, 1);  // hauteur photo
$logoColW = round($wMm * 0.22, 1);   // largeur colonne logo

// Font sizes (pt) proportionnels à la largeur
$fBase    = round($wMm * 0.063, 1);  // ~6.6pt pour 105mm
$fCompany = round($wMm * 0.17,  1);  // ~17.8pt
$fAddr    = round($wMm * 0.064, 1);
$fSub     = round($wMm * 0.046, 1);
$fField   = round($wMm * 0.066, 1);
$fCnaps   = round($wMm * 0.049, 1);
$fLegal   = round($wMm * 0.041, 1);

function getChampValPdf2(array $a, array $params, string $cle): string {
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

// Images en base64
$photoB64 = '';
if ($a['photo'] && file_exists(UPLOAD_PATH.'/'.$a['photo'])) {
    $ext  = strtolower(pathinfo($a['photo'], PATHINFO_EXTENSION));
    $mime = ($ext === 'png') ? 'image/png' : (($ext === 'svg') ? 'image/svg+xml' : 'image/jpeg');
    $photoB64 = 'data:'.$mime.';base64,'.base64_encode(file_get_contents(UPLOAD_PATH.'/'.$a['photo']));
}
$logoPath = APP_ROOT.'/assets/img/'.($params['logo_principal'] ?? 'logo.png');
$logoB64  = '';
if (file_exists($logoPath)) {
    $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
    $mime = ($ext === 'png') ? 'image/png' : (($ext === 'svg') ? 'image/svg+xml' : 'image/jpeg');
    $logoB64 = 'data:'.$mime.';base64,'.base64_encode(file_get_contents($logoPath));
}

$companyName = $params['entreprise_nom'] ?? 'Oeil Vigilant';
$companyAddr = strtoupper(trim(($params['entreprise_adresse']??'').' '.($params['entreprise_cp']??'').' '.($params['entreprise_ville']??'')));
$slogan      = $params['entreprise_slogan'] ?? 'VOTRE SÉCURITÉ, NOTRE PRIORITÉ';
$cnapsNum    = $a['num_autorisation_cnaps'] ?? '';
$legalText   = $params['carte_mention_legale'] ?? "L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient";
$mat         = $a['matricule'] ?? '';
$initiales   = strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1));

// Champs du corps (hors photo/logo)
$bodyFields = [];
foreach ($rectoChamps as $c) {
    if (in_array($c['cle'], ['photo','logo'])) continue;
    $val = getChampValPdf2($a, $params, $c['cle']);
    if ($val !== '') $bodyFields[] = ['label' => $c['label'], 'value' => $val];
}

ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

@page {
    size: <?= $wMm ?>mm <?= $hMm ?>mm;
    margin: 0;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    background: white;
    width: <?= $wMm ?>mm;
    height: <?= $hMm ?>mm;
    overflow: hidden;
}

/* ── Carte ── */
.badge {
    width: <?= $wMm ?>mm;
    height: <?= $hMm ?>mm;
    background: #ffffff;
    position: relative;
    overflow: hidden;
    border: 0.3mm solid #999;
}

/* Barres noires */
.bar-top, .bar-bot {
    width: 100%;
    height: <?= $barH ?>mm;
    background: #111111;
    font-size: 0;
    line-height: 0;
}

/* ── En-tête (table 3 colonnes) ── */
.bc-header {
    width: 100%;
    border-collapse: collapse;
}
.bc-header td {
    vertical-align: top;
    padding: 1.5mm 2mm 1mm 2mm;
}
.td-logo {
    width: <?= $logoColW ?>mm;
    text-align: left;
}
.td-center {
    text-align: center;
}
.td-photo {
    width: <?= ($photoW + 4) ?>mm;
    text-align: center;
}

/* Logo */
.logo-img {
    height: <?= round($headerH * 0.45, 1) ?>mm;
    max-width: <?= ($logoColW - 2) ?>mm;
}
.logo-name {
    font-size: <?= $fSub ?>pt;
    font-weight: 700;
    letter-spacing: 0.3pt;
    color: #111;
    margin-top: 0.8mm;
    text-transform: uppercase;
}
.logo-slogan {
    font-size: <?= round($fSub * 0.85, 1) ?>pt;
    color: #555;
    text-transform: uppercase;
    letter-spacing: 0.2pt;
    line-height: 1.2;
}

/* Société centre */
.company-name {
    font-family: Georgia, serif;
    font-size: <?= $fCompany ?>pt;
    font-weight: 400;
    letter-spacing: 0.5pt;
    color: #111;
    line-height: 1.1;
}
.company-addr {
    font-size: <?= $fAddr ?>pt;
    color: #333;
    margin-top: 0.8mm;
    letter-spacing: 0.2pt;
}

/* Photo + matricule */
.photo-img {
    width: <?= $photoW ?>mm;
    height: <?= $photoH ?>mm;
    border: 0.3mm solid #ccc;
}
.photo-placeholder {
    width: <?= $photoW ?>mm;
    height: <?= $photoH ?>mm;
    border: 0.3mm solid #ccc;
    background: #f0f0f0;
    font-size: <?= round($fBase * 1.5, 1) ?>pt;
    font-weight: 700;
    color: #777;
    text-align: center;
    line-height: <?= $photoH ?>mm;
}
.mat-label {
    font-size: <?= $fSub ?>pt;
    font-weight: 700;
    color: #111;
    margin-top: 0.5mm;
    text-align: center;
}

/* Séparateur */
.sep {
    height: 0.3mm;
    background: #ccc;
    margin: 0 2mm;
}

/* ── Corps ── */
.bc-body {
    padding: 1.5mm 3.5mm 1mm 3.5mm;
    position: relative;
    height: <?= $bodyH ?>mm;
    overflow: hidden;
}

/* Filigrane (position absolute) */
.watermark {
    position: absolute;
    top: 1mm;
    left: 50%;
    margin-left: -<?= round($wMm * 0.24, 0) ?>mm;
    width: <?= round($wMm * 0.48, 0) ?>mm;
    text-align: center;
    opacity: 0.06;
}
.watermark img {
    width: 100%;
}
.wm-text {
    font-size: <?= round($fBase * 1.3, 1) ?>pt;
    font-weight: 700;
    color: #000;
    letter-spacing: 1pt;
    text-transform: uppercase;
    opacity: 0.06;
    text-align: center;
    margin-top: -2mm;
}

/* Champs */
.bc-field {
    font-size: <?= $fField ?>pt;
    color: #111;
    margin-bottom: 0.9mm;
    line-height: 1.2;
}
.bc-field b { font-weight: 700; }

/* ── Pied de page ── */
.bc-footer {
    padding: 1mm 3mm 0.5mm;
    border-top: 0.2mm solid #e0e0e0;
    text-align: center;
    height: <?= $footerH ?>mm;
    overflow: hidden;
}
.cnaps-txt {
    font-size: <?= $fCnaps ?>pt;
    color: #333;
}
.legal-txt {
    font-size: <?= $fLegal ?>pt;
    color: #666;
    margin-top: 0.5mm;
    line-height: 1.3;
}
</style>
</head>
<body>
<div class="badge">

  <!-- Barre supérieure -->
  <div class="bar-top"></div>

  <!-- En-tête -->
  <table class="bc-header">
  <tr>

    <!-- Logo gauche -->
    <td class="td-logo">
      <?php if ($logoB64): ?>
      <img src="<?= $logoB64 ?>" class="logo-img" alt="">
      <?php endif; ?>
      <div class="logo-name"><?= htmlspecialchars(strtoupper($companyName)) ?></div>
      <?php if ($slogan): ?>
      <div class="logo-slogan"><?= htmlspecialchars(strtoupper($slogan)) ?></div>
      <?php endif; ?>
    </td>

    <!-- Société centre -->
    <td class="td-center">
      <div class="company-name"><?= htmlspecialchars($companyName) ?></div>
      <?php if ($companyAddr): ?>
      <div class="company-addr"><?= htmlspecialchars($companyAddr) ?></div>
      <?php endif; ?>
    </td>

    <!-- Photo droite -->
    <td class="td-photo">
      <?php if ($photoB64): ?>
      <img src="<?= $photoB64 ?>" class="photo-img" alt="">
      <?php else: ?>
      <div class="photo-placeholder"><?= htmlspecialchars($initiales) ?></div>
      <?php endif; ?>
      <?php if ($mat): ?>
      <div class="mat-label">MAT: <?= htmlspecialchars($mat) ?></div>
      <?php endif; ?>
    </td>

  </tr>
  </table>

  <!-- Séparateur -->
  <div class="sep"></div>

  <!-- Corps -->
  <div class="bc-body">

    <!-- Filigrane -->
    <?php if ($logoB64): ?>
    <div class="watermark">
      <img src="<?= $logoB64 ?>" alt="">
    </div>
    <?php endif; ?>
    <div class="wm-text"><?= htmlspecialchars(strtoupper($companyName)) ?></div>

    <?php foreach ($bodyFields as $f): ?>
    <div class="bc-field">
      <b><?= htmlspecialchars($f['label']) ?> :</b>
      <?= htmlspecialchars($f['value']) ?>
    </div>
    <?php endforeach; ?>

  </div>

  <!-- Pied de page -->
  <?php if ($cnapsNum || $legalText): ?>
  <div class="bc-footer">
    <?php if ($cnapsNum): ?>
    <div class="cnaps-txt">CNAPS : <?= htmlspecialchars($cnapsNum) ?></div>
    <?php endif; ?>
    <?php if ($legalText): ?>
    <div class="legal-txt"><?= htmlspecialchars($legalText) ?></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Barre inférieure -->
  <div class="bar-bot"></div>

</div>
</body>
</html>
<?php
$html = ob_get_clean();

// Rendu DomPDF avec taille personnalisée
if (file_exists(DOMPDF_AUTOLOAD)) {
    require_once DOMPDF_AUTOLOAD;
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);   // images base64 uniquement
    $options->set('defaultFont', 'Arial');
    $options->set('chroot', APP_ROOT);
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    // Taille personnalisée en points (1mm = 2.83465 pt)
    $mmToPt = 2.83465;
    $dompdf->setPaper([0, 0, $wMm * $mmToPt, $hMm * $mmToPt]);
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
