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

// ── Canvas écran 852×552 px ──────────────────────────────────────
$bW = 852;
$bH = 552;

// ── Zones (px) ──────────────────────────────────────────────────
$barTop = 8;
$barBot = 12;
$hdrH   = (int)round($bH * 0.36);    // ~199px
$ftrH   = (int)round($bH * 0.155);   // ~85px
$bodyH  = $bH - $barTop - $barBot - $hdrH - $ftrH;

// ── Colonnes header ─────────────────────────────────────────────
$logoCol  = (int)round($bW * 0.21);   // ~179px  logo seul (pas de texte dessous)
$photoCol = (int)round($bW * 0.185);  // ~158px

// ── Photo frame ─────────────────────────────────────────────────
$phH = (int)round($hdrH * 0.90);      // ~179px
$phW = (int)round($phH  * 0.68);      // ~122px

// ── Logo (agrandi, plus de texte dessous) ───────────────────────
$logoH    = (int)round($hdrH * 0.80); // ~159px — beaucoup plus grand
$logoMaxW = $logoCol - 10;            // ~169px

// ── Typographie (px) ────────────────────────────────────────────
$fCie    = (int)round($bW * 0.052);   // ~44px — Oeil Vigilant (plus grand)
$fSlogan = (int)round($bW * 0.013);   // ~11px — slogan (centre)
$fAddr   = (int)round($bW * 0.013);   // ~11px — adresse
$fField  = (int)round($bW * 0.018);   // ~15px — champs body
$fMat    = (int)round($bW * 0.015);   // ~13px — matricule
$fCnaps  = (int)round($bW * 0.014);   // ~12px — CNAPS footer
$fLegal  = (int)round($bW * 0.011);   // ~9px  — mention légale

// ── Échelle impression ───────────────────────────────────────────
$printScale = round($wMm / ($bW * 0.2646), 4);

// ── Données ─────────────────────────────────────────────────────
$companyName = $params['entreprise_nom']       ?? 'Oeil Vigilant';
$companyAddr = strtoupper(trim(($params['entreprise_adresse']??'').' '.($params['entreprise_cp']??'').' '.($params['entreprise_ville']??'')));
$slogan      = $params['entreprise_slogan']    ?? 'VOTRE SÉCURITÉ, NOTRE PRIORITÉ';
$cnapsEnt    = $params['entreprise_cnaps']     ?? '';
$legalText   = $params['carte_mention_legale'] ?? "L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient";
$mat         = $a['matricule'] ?? '';
$initiales   = strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1));
$photoUrl    = $a['photo'] ? UPLOAD_URL.'/'.$a['photo'] : null;
$logoUrl     = APP_URL.'/assets/img/'.($params['logo_principal'] ?? 'logo.png');

$bodyFields = [
    ['label'=>'Nom',                             'value'=>strtoupper($a['nom']  ?? '')],
    ['label'=>'Prénom',                          'value'=>$a['prenom']          ?? ''],
    ['label'=>'Né(e) le',                        'value'=>!empty($a['date_naissance'])    ? date('d/m/Y', strtotime($a['date_naissance']))       : ''],
    ['label'=>'Numéro de carte professionnelle', 'value'=>$a['num_autorisation_cnaps']    ?? ''],
    ['label'=>'Validité',                        'value'=>$a['date_expiration_cnaps']      ? date('d/m/Y', strtotime($a['date_expiration_cnaps'])) : ''],
];
$bodyFields = array_values(array_filter($bodyFields, fn($f) => $f['value'] !== ''));

$editUrl = APP_URL.'/modules/parametres/index.php?tab=carte';
?>

<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
    <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary"><i class="fa fa-arrow-left me-1"></i>Retour</a>
    <a href="export_carte_pdf.php?id=<?= $id ?>&w=<?= $wMm ?>&h=<?= $hMm ?>" class="btn btn-ov-primary"><i class="fa fa-file-pdf me-1"></i>Télécharger PDF</a>
    <button onclick="window.print()" class="btn" style="background:rgba(201,168,76,0.1);color:#92400e;border:1px solid rgba(201,168,76,0.3);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem">
        <i class="fa fa-print me-1"></i>Imprimer
    </button>
    <a href="<?= $editUrl ?>" class="btn" style="background:rgba(99,102,241,0.08);color:#4f46e5;border:1px solid rgba(99,102,241,0.2);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem">
        <i class="fa fa-sliders me-1"></i>Paramètres badge
    </a>
    <form method="GET" class="d-flex gap-2 align-items-center ms-1" style="font-size:0.875rem">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="number" name="w" value="<?= $wMm ?>" min="54" max="210" class="form-control form-control-sm" style="width:60px" title="Largeur mm">
        <span class="text-muted">×</span>
        <input type="number" name="h" value="<?= $hMm ?>" min="34" max="150" class="form-control form-control-sm" style="width:60px" title="Hauteur mm">
        <span class="text-muted" style="font-size:0.78rem">mm</span>
        <button type="submit" class="btn btn-sm btn-ov-secondary"><i class="fa fa-arrows-rotate"></i></button>
    </form>
</div>

<style>
/* ═══ Impression ════════════════════════════════════════════════════ */
@media print {
    /* Cacher tout sauf le badge */
    body * { visibility: hidden; }
    .badge-wrap, .badge-wrap * { visibility: visible; }
    .badge-wrap {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        transform-origin: top left !important;
        transform: scale(<?= $printScale ?>) !important;
        box-shadow: none !important;
        border: none !important;
    }
}

/* ═══ Badge ═════════════════════════════════════════════════════════ */
.badge-wrap * { box-sizing: border-box; margin: 0; padding: 0; }

.badge-outer {
    display: inline-block;
    border: 1.5px dashed #c8c8c8;
    padding: 10px;
    background: #ebebeb;
    border-radius: 4px;
}

.badge-wrap {
    width: <?= $bW ?>px;
    height: <?= $bH ?>px;
    font-family: Arial, Helvetica, sans-serif;
    background: #fff;
    border: 1px solid #d0d0d0;
    box-shadow: 0 8px 32px rgba(0,0,0,0.16), 0 2px 8px rgba(0,0,0,0.08);
    overflow: hidden;
    display: flex;
    flex-direction: column;
}

/* ── Barres noires ── */
.b-bar { width: 100%; flex-shrink: 0; }
.b-bar-top { height: <?= $barTop ?>px; background: #0d0d0d; }
.b-bar-bot { height: <?= $barBot ?>px; background: #0d0d0d; }

/* ── En-tête ── */
.b-hdr {
    height: <?= $hdrH ?>px;
    display: flex;
    align-items: stretch;
    flex-shrink: 0;
    overflow: hidden;
    background: linear-gradient(160deg, #f6f9ff 0%, #ffffff 55%);
}

/* Logo (image seule, pas de texte) */
.b-logo {
    width: <?= $logoCol ?>px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 8px 6px 8px 12px;
}
.b-logo img {
    max-height: <?= $logoH ?>px;
    max-width: <?= $logoMaxW ?>px;
    object-fit: contain;
    display: block;
}

/* Centre — nom société + slogan + adresse */
.b-center {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 8px 14px;
    overflow: hidden;
    gap: 5px;
}
.b-cie-name {
    font-family: Georgia, 'Times New Roman', serif;
    font-size: <?= $fCie ?>px;
    font-weight: 400;
    font-style: italic;
    color: #0a1628;
    letter-spacing: 0.5px;
    line-height: 1.05;
}
.b-cie-slogan {
    font-size: <?= $fSlogan ?>px;
    color: #888;
    font-style: italic;
    letter-spacing: 0.5px;
    line-height: 1.2;
    text-transform: uppercase;
}
.b-cie-addr {
    font-size: <?= $fAddr ?>px;
    color: #333;
    font-weight: 600;
    letter-spacing: 0.3px;
    line-height: 1.2;
}

/* Photo */
.b-photo-col {
    width: <?= $photoCol ?>px;
    flex-shrink: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 6px 12px 6px 4px;
    gap: 4px;
}
.b-photo-frame {
    width: <?= $phW ?>px;
    height: <?= $phH ?>px;
    border: 1px solid #ccc;
    overflow: hidden;
    background: #e8e8e8;
    flex-shrink: 0;
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
    font-size: <?= (int)round($phW * 0.30) ?>px;
    font-weight: 900;
    color: #c0c0c0;
    font-family: Georgia, serif;
}
.b-mat {
    font-size: <?= $fMat ?>px;
    font-weight: 700;
    color: #111;
    text-align: center;
    white-space: nowrap;
}
.b-mat span { font-weight: 400; color: #777; }

/* ── Corps — pas de séparateur, juste la continuité ── */
.b-body {
    flex: 1;
    position: relative;
    overflow: hidden;
    padding: <?= max(10, (int)round($bodyH * 0.10)) ?>px 36px <?= max(6, (int)round($bodyH * 0.06)) ?>px 36px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: <?= max(8, (int)round($bodyH * 0.050)) ?>px;
    background: #fff;
}

/* Filigrane */
.b-watermark {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    opacity: 0.05;
    text-align: center;
    pointer-events: none;
    user-select: none;
}
.b-watermark img { width: <?= (int)round($bW * 0.19) ?>px; display: block; margin: 0 auto; }
.b-wm-txt {
    font-size: <?= (int)round($bW * 0.044) ?>px;
    font-weight: 900;
    color: #000;
    text-transform: uppercase;
    letter-spacing: 4px;
    font-family: Arial, sans-serif;
    margin-top: -4px;
    white-space: nowrap;
}

/* Champs */
.b-field {
    display: flex;
    align-items: baseline;
    font-size: <?= $fField ?>px;
    line-height: 1.15;
}
.b-field-lbl {
    font-weight: 700;
    color: #222;
    white-space: nowrap;
    min-width: <?= (int)round($bW * 0.315) ?>px;
}
.b-field-val {
    font-weight: 700;
    color: #111;
    font-size: <?= ($fField + 1) ?>px;
}

/* ── Pied ── */
.b-footer {
    height: <?= $ftrH ?>px;
    border-top: 1.5px solid #e8e8e8;
    background: linear-gradient(180deg, #f6f8fc 0%, #f0f3f8 100%);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
    padding: 5px 20px 4px;
    flex-shrink: 0;
    overflow: hidden;
    gap: 3px;
}
.b-footer-cnaps {
    font-size: <?= $fCnaps ?>px;
    font-weight: 700;
    color: #222;
    letter-spacing: 0.2px;
}
.b-footer-legal {
    font-size: <?= $fLegal ?>px;
    color: #666;
    line-height: 1.4;
    max-width: 88%;
}
</style>

<div class="badge-outer">
<div class="badge-wrap">

  <div class="b-bar b-bar-top"></div>

  <!-- En-tête : logo | société | photo -->
  <div class="b-hdr">

    <div class="b-logo">
      <img src="<?= h($logoUrl) ?>" alt="" onerror="this.style.display='none'">
    </div>

    <div class="b-center">
      <div class="b-cie-name"><?= h($companyName) ?></div>
      <?php if ($slogan): ?>
      <div class="b-cie-slogan"><?= h($slogan) ?></div>
      <?php endif; ?>
      <?php if ($companyAddr): ?>
      <div class="b-cie-addr"><?= h($companyAddr) ?></div>
      <?php endif; ?>
    </div>

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

  <!-- Corps (pas de séparateur) -->
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>
