<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { flash('danger','Agent introuvable'); header('Location: index.php'); exit; }

$pageTitle     = 'Badge agent';
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';

$params = getAllParams();

// ── Dimensions PDF (mm) ──────────────────────────────────────────
$wMm = max(54, min(210, (int)($_GET['w'] ?? 86)));
$hMm = max(34, min(150, (int)($_GET['h'] ?? 54)));

// ── Canvas écran : 852×552 px (fixe, demandé par utilisateur) ───
$bW = 852;
$bH = 552;

// ── Zones (px) ──────────────────────────────────────────────────
$barTop = 7;
$barBot = 11;
$hdrH   = (int)round($bH * 0.355);   // ~196px
$ftrH   = (int)round($bH * 0.165);   // ~91px
$bodyH  = $bH - $barTop - $barBot - $hdrH - 1 - $ftrH;

// ── Colonnes header ─────────────────────────────────────────────
$logoCol  = (int)round($bW * 0.22);  // ~187px
$photoCol = (int)round($bW * 0.19);  // ~162px

// ── Photo frame (portrait 2:3) ──────────────────────────────────
$phH = (int)round($hdrH * 0.88);     // ~172px
$phW = (int)round($phH  * 0.68);     // ~117px

// ── Logo ────────────────────────────────────────────────────────
$logoH = (int)round($hdrH * 0.52);   // ~102px
$logoMaxW = $logoCol - 22;           // ~165px

// ── Typographie (px) ────────────────────────────────────────────
$fCie    = (int)round($bW * 0.041);  // ~35px — Oeil Vigilant (italic serif)
$fAddr   = (int)round($bW * 0.015);  // ~13px — adresse
$fSlogan = (int)round($bW * 0.010);  // ~9px  — slogan
$fLname  = (int)round($bW * 0.011);  // ~9px  — sous logo
$fField  = (int)round($bW * 0.018);  // ~15px — champs body
$fMat    = (int)round($bW * 0.015);  // ~13px — matricule
$fCnaps  = (int)round($bW * 0.014);  // ~12px — CNAPS footer
$fLegal  = (int)round($bW * 0.012);  // ~10px — mention légale

// ── Échelle impression CSS ──────────────────────────────────────
$printScale = round($wMm / ($bW * 0.2646), 4);

// ── Données ─────────────────────────────────────────────────────
$companyName = $params['entreprise_nom']    ?? 'Oeil Vigilant';
$companyAddr = strtoupper(trim(($params['entreprise_adresse']??'').' '.($params['entreprise_cp']??'').' '.($params['entreprise_ville']??'')));
$slogan      = $params['entreprise_slogan'] ?? 'VOTRE SÉCURITÉ, NOTRE PRIORITÉ';
$cnapsEnt    = $params['entreprise_cnaps']  ?? '';
$legalText   = $params['carte_mention_legale'] ?? "L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient";
$mat         = $a['matricule'] ?? '';
$initiales   = strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1));
$photoUrl    = $a['photo'] ? UPLOAD_URL.'/'.$a['photo'] : null;
$logoUrl     = APP_URL.'/assets/img/'.($params['logo_principal'] ?? 'logo.png');

$bodyFields = [
    ['label'=>'Nom',                             'value'=>strtoupper($a['nom']    ?? '')],
    ['label'=>'Prénom',                          'value'=>$a['prenom']            ?? ''],
    ['label'=>'Né(e) le',                        'value'=>!empty($a['date_naissance'])      ? date('d/m/Y', strtotime($a['date_naissance']))      : ''],
    ['label'=>'Numéro de carte professionnelle', 'value'=>$a['num_autorisation_cnaps']      ?? ''],
    ['label'=>'Validité',                        'value'=>$a['date_expiration_cnaps']        ? date('d/m/Y', strtotime($a['date_expiration_cnaps'])) : ''],
];
$bodyFields = array_values(array_filter($bodyFields, fn($f) => $f['value'] !== ''));
?>

<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary"><i class="fa fa-arrow-left me-1"></i>Retour</a>
    <a href="export_carte_pdf.php?id=<?= $id ?>&w=<?= $wMm ?>&h=<?= $hMm ?>" class="btn btn-ov-primary"><i class="fa fa-file-pdf me-1"></i>Télécharger PDF</a>
    <button onclick="window.print()" class="btn" style="background:rgba(201,168,76,0.1);color:#92400e;border:1px solid rgba(201,168,76,0.3);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem">
        <i class="fa fa-print me-1"></i>Imprimer
    </button>
    <form method="GET" class="d-flex gap-2 align-items-center ms-2" style="font-size:0.875rem">
        <input type="hidden" name="id" value="<?= $id ?>">
        <span class="text-muted">Dimensions PDF :</span>
        <input type="number" name="w" value="<?= $wMm ?>" min="54" max="210" class="form-control form-control-sm" style="width:65px" title="Largeur mm">
        <span class="text-muted">×</span>
        <input type="number" name="h" value="<?= $hMm ?>" min="34" max="150" class="form-control form-control-sm" style="width:65px" title="Hauteur mm">
        <span class="text-muted" style="font-size:0.78rem">mm</span>
        <button type="submit" class="btn btn-sm btn-ov-secondary"><i class="fa fa-arrows-rotate"></i></button>
    </form>
    <span class="text-muted ms-auto" style="font-size:0.78rem">Aperçu 852×552 px — PDF : <?= $wMm ?>×<?= $hMm ?> mm</span>
</div>

<style>
/* ═══ Badge styles ════════════════════════════════════════════════════ */
.badge-wrap * { box-sizing: border-box; margin: 0; padding: 0; }

.badge-outer {
    display: inline-block;
    border: 1.5px dashed #bbb;
    padding: 8px;
    background: #f4f4f4;
    border-radius: 4px;
}

.badge-wrap {
    width: <?= $bW ?>px;
    height: <?= $bH ?>px;
    font-family: Arial, Helvetica, sans-serif;
    background: #fff;
    border: 0.5px solid #ccc;
    box-shadow: 0 6px 28px rgba(0,0,0,0.14);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    position: relative;
}

/* Barres */
.b-bar { width: 100%; background: #111; flex-shrink: 0; }
.b-bar-top { height: <?= $barTop ?>px; }
.b-bar-bot { height: <?= $barBot ?>px; }

/* ── En-tête ── */
.b-hdr {
    height: <?= $hdrH ?>px;
    display: flex;
    align-items: stretch;
    flex-shrink: 0;
    overflow: hidden;
}

/* Logo bloc */
.b-logo {
    width: <?= $logoCol ?>px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 10px 6px 8px 14px;
    gap: 4px;
}
.b-logo img {
    max-height: <?= $logoH ?>px;
    max-width: <?= $logoMaxW ?>px;
    object-fit: contain;
    display: block;
}
.b-logo-name {
    font-size: <?= $fLname ?>px;
    font-weight: 900;
    color: #111;
    text-transform: uppercase;
    letter-spacing: 1px;
    text-align: center;
    line-height: 1.1;
}
.b-logo-slogan {
    font-size: <?= $fSlogan ?>px;
    color: #666;
    font-style: italic;
    text-align: center;
    line-height: 1.3;
}

/* Centre */
.b-center {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 10px 12px;
    border-left: 1px solid #eee;
    border-right: 1px solid #eee;
    overflow: hidden;
}
.b-cie-name {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: <?= $fCie ?>px;
    font-weight: 400;
    font-style: italic;
    color: #0a1628;
    letter-spacing: 0.5px;
    line-height: 1.1;
}
.b-cie-addr {
    font-size: <?= $fAddr ?>px;
    color: #444;
    margin-top: 8px;
    letter-spacing: 0.3px;
    font-weight: 600;
}

/* Photo */
.b-photo-col {
    width: <?= $photoCol ?>px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 8px 14px 6px 6px;
    gap: 5px;
}
.b-photo-frame {
    width: <?= $phW ?>px;
    height: <?= $phH ?>px;
    border: 1px solid #bbb;
    overflow: hidden;
    background: #ebebeb;
    flex-shrink: 0;
    position: relative;
}
.b-photo-frame img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.b-photo-init {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: <?= (int)round($phW * 0.32) ?>px;
    font-weight: 900;
    color: #ccc;
    letter-spacing: -2px;
    font-family: Georgia, serif;
}
.b-mat {
    font-size: <?= $fMat ?>px;
    font-weight: 700;
    color: #111;
    text-align: center;
    white-space: nowrap;
}
.b-mat span { font-weight: 400; color: #666; }

/* Séparateur */
.b-sep { height: 1px; background: #ddd; flex-shrink: 0; }

/* ── Corps ── */
.b-body {
    flex: 1;
    position: relative;
    overflow: hidden;
    padding: <?= (int)round($bodyH * 0.07) ?>px 30px <?= (int)round($bodyH * 0.04) ?>px 30px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: <?= (int)round($bodyH * 0.055) ?>px;
}
.b-watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    opacity: 0.055;
    text-align: center;
    pointer-events: none;
    user-select: none;
}
.b-watermark img { width: <?= (int)round($bW * 0.20) ?>px; display: block; margin: 0 auto; }
.b-wm-txt {
    font-size: <?= (int)round($bW * 0.048) ?>px;
    font-weight: 900;
    color: #000;
    text-transform: uppercase;
    letter-spacing: 3px;
    font-family: Arial, sans-serif;
    margin-top: -4px;
    white-space: nowrap;
}

.b-field {
    display: flex;
    align-items: baseline;
    font-size: <?= $fField ?>px;
    line-height: 1.2;
    position: relative;
}
.b-field-lbl {
    font-weight: 700;
    color: #111;
    white-space: nowrap;
    min-width: <?= (int)round($bW * 0.325) ?>px;
}
.b-field-val {
    font-weight: 700;
    color: #111;
    font-size: <?= ($fField + 1) ?>px;
}

/* ── Pied ── */
.b-footer {
    height: <?= $ftrH ?>px;
    border-top: 1px solid #ddd;
    background: #fafafa;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 6px 20px 4px;
    flex-shrink: 0;
    overflow: hidden;
    gap: 4px;
}
.b-footer-cnaps {
    font-size: <?= $fCnaps ?>px;
    font-weight: 700;
    color: #333;
}
.b-footer-legal {
    font-size: <?= $fLegal ?>px;
    color: #777;
    line-height: 1.45;
    max-width: 90%;
}

/* ── Impression ── */
@media print {
    body > * { display: none !important; }
    .badge-print-zone { display: block !important; position: fixed; top: 0; left: 0; }
    .badge-outer { border: none; padding: 0; background: white; }
    .badge-wrap {
        transform-origin: top left;
        transform: scale(<?= $printScale ?>);
        box-shadow: none;
        border: none;
    }
}
</style>

<div class="badge-print-zone">
<div class="badge-outer">
<div class="badge-wrap">

  <div class="b-bar b-bar-top"></div>

  <!-- En-tête -->
  <div class="b-hdr">

    <!-- Logo gauche -->
    <div class="b-logo">
      <img src="<?= h($logoUrl) ?>" alt="" onerror="this.style.display='none'">
      <div class="b-logo-name"><?= h(strtoupper($companyName)) ?></div>
      <?php if ($slogan): ?>
      <div class="b-logo-slogan"><?= h($slogan) ?></div>
      <?php endif; ?>
    </div>

    <!-- Centre -->
    <div class="b-center">
      <div class="b-cie-name"><?= h($companyName) ?></div>
      <?php if ($companyAddr): ?>
      <div class="b-cie-addr"><?= h($companyAddr) ?></div>
      <?php endif; ?>
    </div>

    <!-- Photo droite -->
    <div class="b-photo-col">
      <div class="b-photo-frame">
        <?php if ($photoUrl): ?>
          <img src="<?= h($photoUrl) ?>" alt="">
        <?php else: ?>
          <div class="b-photo-init"><?= h($initiales) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($mat): ?>
      <div class="b-mat"><span>MAT:</span> <?= h($mat) ?></div>
      <?php endif; ?>
    </div>

  </div>

  <div class="b-sep"></div>

  <!-- Corps -->
  <div class="b-body">
    <div class="b-watermark">
      <img src="<?= h($logoUrl) ?>" alt="" onerror="this.style.display='none'">
      <div class="b-wm-txt"><?= h(strtoupper($companyName)) ?></div>
    </div>

    <?php foreach ($bodyFields as $f): ?>
    <div class="b-field">
      <span class="b-field-lbl"><?= h($f['label']) ?> :</span>
      <span class="b-field-val"><?= h($f['value']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Pied -->
  <div class="b-footer">
    <?php if ($cnapsEnt): ?>
    <div class="b-footer-cnaps">CNAPS : <?= h($cnapsEnt) ?></div>
    <?php endif; ?>
    <?php if ($legalText): ?>
    <div class="b-footer-legal"><?= h($legalText) ?></div>
    <?php endif; ?>
  </div>

  <div class="b-bar b-bar-bot"></div>

</div><!-- /.badge-wrap -->
</div><!-- /.badge-outer -->
</div><!-- /.badge-print-zone -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>
