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

$pageTitle     = 'Carte agent';
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';

$params      = getAllParams();
$champs      = $db->query("SELECT * FROM carte_champs WHERE actif=1 ORDER BY face, ordre")->fetchAll();
$rectoChamps = array_values(array_filter($champs, fn($c) => $c['face'] === 'recto'));

// Dimensions en mm (pour PDF/impression)
$wMm = max(85, min(210, (int)($_GET['w'] ?? 105)));
$hMm = max(50, min(150, (int)($_GET['h'] ?? 74)));

// Taille écran (px) pour l'aperçu confortable
$screenW = 620;
$screenH = (int)round($screenW * $hMm / $wMm);

// Échelle pour l'impression : 600px ≈ 158.75mm à 96dpi
$printScale = round($wMm / 158.75, 4);

function getChampValC(array $a, array $params, string $cle): string {
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

$companyName = $params['entreprise_nom']     ?? 'Oeil Vigilant';
$companyAddr = strtoupper(trim(($params['entreprise_adresse']??'').' '.($params['entreprise_cp']??'').' '.($params['entreprise_ville']??'')));
$slogan      = $params['entreprise_slogan']  ?? 'VOTRE SÉCURITÉ, NOTRE PRIORITÉ';
$cnapsNum    = $a['num_autorisation_cnaps']  ?? '';
$legalText   = $params['carte_mention_legale'] ?? "L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient";
$photoUrl    = $a['photo'] ? UPLOAD_URL.'/'.$a['photo'] : '';
$logoUrl     = APP_URL.'/assets/img/'.($params['logo_principal'] ?? 'logo.png');
$mat         = $a['matricule'] ?? '';
?>

<!-- Contrôles -->
<div class="d-flex flex-wrap gap-2 align-items-end mb-4 no-print">
  <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm">
    <i class="fa fa-arrow-left me-1"></i>Retour
  </a>

  <form method="get" class="d-flex align-items-end gap-2">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div>
      <label class="d-block text-muted mb-0" style="font-size:.7rem">Largeur (mm)</label>
      <input type="number" name="w" value="<?= $wMm ?>" min="85" max="210" step="5"
             class="form-control form-control-sm" style="width:82px">
    </div>
    <div>
      <label class="d-block text-muted mb-0" style="font-size:.7rem">Hauteur (mm)</label>
      <input type="number" name="h" value="<?= $hMm ?>" min="50" max="150" step="5"
             class="form-control form-control-sm" style="width:82px">
    </div>
    <button type="submit" class="btn btn-ov-secondary btn-sm">Appliquer</button>
  </form>

  <button onclick="window.print()" class="btn btn-ov-primary btn-sm">
    <i class="fa fa-print me-1"></i>Imprimer
  </button>
  <a href="export_carte_pdf.php?id=<?= $id ?>&w=<?= $wMm ?>&h=<?= $hMm ?>"
     class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:.35rem .75rem;font-size:.8rem">
    <i class="fa fa-file-pdf me-1"></i>Télécharger PDF
  </a>
</div>

<!-- Aperçu badge -->
<div id="badgeWrap" style="display:inline-block;margin-bottom:2rem">
<div id="badge" class="badge-card" style="width:<?= $screenW ?>px;height:<?= $screenH ?>px">

  <!-- Barre supérieure -->
  <div class="bc-topbar"></div>

  <!-- En-tête : Logo | Société | Photo -->
  <div class="bc-header">

    <!-- Zone logo gauche -->
    <div class="bc-logo-zone">
      <img src="<?= h($logoUrl) ?>" class="bc-logo-img" alt="" onerror="this.style.display='none'">
      <div class="bc-logo-name"><?= h(strtoupper($companyName)) ?></div>
      <?php if ($slogan): ?>
      <div class="bc-logo-slogan"><?= h(strtoupper($slogan)) ?></div>
      <?php endif; ?>
    </div>

    <!-- Centre : nom société + adresse -->
    <div class="bc-center">
      <div class="bc-company-name"><?= h($companyName) ?></div>
      <?php if ($companyAddr): ?>
      <div class="bc-company-addr"><?= h($companyAddr) ?></div>
      <?php endif; ?>
    </div>

    <!-- Zone photo droite -->
    <div class="bc-photo-zone">
      <?php if ($photoUrl): ?>
      <img src="<?= h($photoUrl) ?>" class="bc-photo" alt="">
      <?php else: ?>
      <div class="bc-photo-placeholder"><?= h(strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1))) ?></div>
      <?php endif; ?>
      <?php if ($mat): ?>
      <div class="bc-mat">MAT: <?= h($mat) ?></div>
      <?php endif; ?>
    </div>

  </div><!-- /bc-header -->

  <!-- Séparateur -->
  <div class="bc-sep"></div>

  <!-- Corps : champs agent -->
  <div class="bc-body">

    <!-- Filigrane -->
    <div class="bc-watermark">
      <img src="<?= h($logoUrl) ?>" style="width:48%;opacity:.06;filter:grayscale(1)" alt="" onerror="this.style.display='none'">
      <div class="bc-wm-text"><?= h(strtoupper($companyName)) ?></div>
      <?php if ($slogan): ?><div class="bc-wm-sub"><?= h(strtoupper($slogan)) ?></div><?php endif; ?>
    </div>

    <?php foreach ($rectoChamps as $c):
      if (in_array($c['cle'], ['photo','logo'])) continue;
      $val = getChampValC($a, $params, $c['cle']);
      if (!$val) continue;
    ?>
    <div class="bc-field">
      <span class="bc-field-label"><?= h($c['label']) ?> :</span>
      <span class="bc-field-value"><?= h($val) ?></span>
    </div>
    <?php endforeach; ?>

  </div><!-- /bc-body -->

  <!-- Pied de page CNAPS + mention légale -->
  <?php if ($cnapsNum || $legalText): ?>
  <div class="bc-footer">
    <?php if ($cnapsNum): ?><div class="bc-cnaps">CNAPS : <?= h($cnapsNum) ?></div><?php endif; ?>
    <?php if ($legalText): ?><div class="bc-legal"><?= h($legalText) ?></div><?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- Barre inférieure -->
  <div class="bc-botbar"></div>

</div><!-- /badge-card -->
</div><!-- /badgeWrap -->

<style>
/* ── Badge card ── */
.badge-card {
  background: #fff;
  border: 1px solid #bbb;
  border-radius: 6px;
  box-shadow: 0 6px 24px rgba(0,0,0,.18);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  font-family: Arial, Helvetica, sans-serif;
  position: relative;
  box-sizing: border-box;
}
.bc-topbar, .bc-botbar {
  height: 5px;
  background: #111;
  flex-shrink: 0;
}

/* En-tête */
.bc-header {
  display: flex;
  align-items: flex-start;
  padding: 10px 10px 6px 10px;
  gap: 8px;
  flex-shrink: 0;
}
.bc-logo-zone {
  flex: 0 0 22%;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}
.bc-logo-img {
  height: 38px;
  max-width: 80px;
  object-fit: contain;
}
.bc-logo-name {
  font-size: .48rem;
  font-weight: 700;
  letter-spacing: .5px;
  color: #111;
  margin-top: 3px;
}
.bc-logo-slogan {
  font-size: .38rem;
  color: #555;
  letter-spacing: .3px;
}
.bc-center {
  flex: 1;
  text-align: center;
  padding: 0 6px;
}
.bc-company-name {
  font-family: Georgia, 'Times New Roman', serif;
  font-size: 1.35rem;
  font-weight: 400;
  letter-spacing: 1px;
  color: #111;
  line-height: 1.1;
}
.bc-company-addr {
  font-size: .52rem;
  color: #333;
  margin-top: 4px;
  letter-spacing: .4px;
}
.bc-photo-zone {
  flex: 0 0 auto;
  display: flex;
  flex-direction: column;
  align-items: center;
}
.bc-photo {
  width: 60px;
  height: 75px;
  object-fit: cover;
  border: 1px solid #ccc;
  filter: grayscale(30%);
}
.bc-photo-placeholder {
  width: 60px;
  height: 75px;
  border: 1px solid #ccc;
  background: #f0f0f0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.1rem;
  font-weight: 700;
  color: #777;
}
.bc-mat {
  font-size: .48rem;
  font-weight: 700;
  color: #111;
  margin-top: 3px;
}

/* Séparateur */
.bc-sep {
  height: 1px;
  background: #ccc;
  margin: 0 10px;
  flex-shrink: 0;
}

/* Corps */
.bc-body {
  flex: 1;
  padding: 7px 14px 4px 14px;
  position: relative;
  overflow: hidden;
}
.bc-watermark {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  pointer-events: none;
  width: 80%;
}
.bc-wm-text {
  font-size: .8rem;
  font-weight: 700;
  color: rgba(0,0,0,.06);
  letter-spacing: 2px;
  margin-top: -4px;
}
.bc-wm-sub {
  font-size: .35rem;
  color: rgba(0,0,0,.05);
  letter-spacing: 1px;
}
.bc-field {
  font-size: .56rem;
  margin-bottom: 3px;
  color: #111;
  position: relative;
  z-index: 1;
}
.bc-field-label { font-weight: 700; }
.bc-field-value  { margin-left: 2px; }

/* Pied de page */
.bc-footer {
  padding: 4px 10px 3px;
  text-align: center;
  border-top: 1px solid #eee;
  flex-shrink: 0;
}
.bc-cnaps { font-size: .44rem; color: #333; }
.bc-legal { font-size: .37rem; color: #666; margin-top: 2px; line-height: 1.35; }

/* ── Impression ── */
@media print {
  .no-print { display: none !important; }
  #sidebar, #topbar { display: none !important; }
  #main-content { margin: 0 !important; padding: 8mm !important; background: white !important; }
  body { background: white !important; }
  #badgeWrap {
    display: block !important;
    transform: scale(<?= $printScale ?>);
    transform-origin: top left;
    width: <?= $screenW ?>px;
    height: <?= $screenH ?>px;
  }
  #badge {
    width: <?= $screenW ?>px !important;
    height: <?= $screenH ?>px !important;
    box-shadow: none !important;
    border: 1px solid #999 !important;
  }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
