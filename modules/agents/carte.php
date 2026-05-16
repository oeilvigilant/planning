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

$params      = getAllParams();
$champs      = $db->query("SELECT * FROM carte_champs WHERE actif=1 ORDER BY face, ordre")->fetchAll();
$rectoChamps = array_values(array_filter($champs, fn($c) => $c['face'] === 'recto'));

// ── Dimensions ──────────────────────────────────────────────────────
$wMm = max(85, min(210, (int)($_GET['w'] ?? 105)));
$hMm = max(50, min(150, (int)($_GET['h'] ?? 74)));

// Aperçu écran — largeur fixe, hauteur proportionnelle
$bW = 800;
$bH = (int)round($bW * $hMm / $wMm);   // ex. ~548 pour 105×74

// Zones (px)
$barTop    = 5;
$barBot    = 8;
$hdrH      = (int)round($bH * 0.37);
$ftrH      = (int)round($bH * 0.16);
$bodyH     = $bH - $barTop - $barBot - $hdrH - 1 - $ftrH; // 1 = séparateur

// Typographie (px) proportionnelle à la largeur
$fCie      = (int)round($bW * 0.036);   // Nom société (serif, grand)
$fAddr     = (int)round($bW * 0.014);   // Adresse société
$fLogoName = (int)round($bW * 0.013);   // Sous-logo
$fSlogan   = (int)round($bW * 0.011);   // Slogan
$fField    = (int)round($bW * 0.017);   // Champs agent
$fMat      = (int)round($bW * 0.013);   // Matricule
$fCnaps    = (int)round($bW * 0.012);   // CNAPS
$fLegal    = (int)round($bW * 0.011);   // Mention légale

// Photo (px)
$phW       = (int)round($bH * 0.52);
$phH       = (int)round($bH * 0.65);

// Logo height dans l'en-tête
$logoH     = (int)round($hdrH * 0.46);

// Filigrane
$wmW       = (int)round($bW * 0.29);
$wmFtxt    = (int)round($bW * 0.032);

// Échelle impression
$printScale = round($wMm / ($bW * 0.2646), 4);

// ── Helpers ─────────────────────────────────────────────────────────
function getChampValBadge(array $a, array $params, string $cle): string {
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

// ── Données ──────────────────────────────────────────────────────────
$companyName = $params['entreprise_nom'] ?? 'Oeil Vigilant';
$companyAddr = strtoupper(trim(($params['entreprise_adresse']??'').' '.($params['entreprise_cp']??'').' '.($params['entreprise_ville']??'')));
$slogan      = $params['entreprise_slogan'] ?? 'Votre sécurité, notre priorité';
$cnapsNum    = $a['num_autorisation_cnaps'] ?? '';
$legalText   = $params['carte_mention_legale']
    ?? "L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient";
$photoUrl    = $a['photo'] ? UPLOAD_URL.'/'.$a['photo'] : '';
$logoUrl     = APP_URL.'/assets/img/'.($params['logo_principal'] ?? 'logo.png');
$mat         = $a['matricule'] ?? '';
$initiales   = strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1));
?>

<!-- ── Barre de contrôle ─────────────────────────────────────────── -->
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
     class="btn btn-sm" style="background:rgba(220,38,38,.08);color:#dc2626;border:1px solid rgba(220,38,38,.2);border-radius:8px;padding:.35rem .8rem;font-size:.8rem">
    <i class="fa fa-file-pdf me-1"></i>Télécharger PDF
  </a>
</div>

<!-- ── Badge ─────────────────────────────────────────────────────── -->
<div id="badgeWrap" style="display:inline-block">
<div id="badge" style="
    width:<?= $bW ?>px;
    height:<?= $bH ?>px;
    background-color:#fff;
    background-image:linear-gradient(rgba(0,0,0,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(0,0,0,0.04) 1px,transparent 1px);
    background-size:16px 16px;
    border:1.5px solid #bbb;
    border-radius:5px;
    box-shadow:0 8px 32px rgba(0,0,0,.2);
    font-family:Arial,Helvetica,sans-serif;
    overflow:hidden;
    position:relative;
    box-sizing:border-box;
">

  <!-- ▌Barre supérieure ▐ -->
  <div style="height:<?= $barTop ?>px;background:#111"></div>

  <!-- ▌EN-TÊTE ▐ -->
  <table style="width:100%;height:<?= $hdrH ?>px;border-collapse:collapse;table-layout:fixed">
  <tr>

    <!-- Logo (gauche) -->
    <td style="width:21%;vertical-align:top;padding:10px 6px 6px 16px">
      <img src="<?= h($logoUrl) ?>"
           style="height:<?= $logoH ?>px;max-width:100%;object-fit:contain;display:block"
           onerror="this.style.display='none'" alt="">
      <div style="font-size:<?= $fLogoName ?>px;font-weight:700;letter-spacing:.7px;color:#111;margin-top:5px;text-transform:uppercase">
        <?= h(strtoupper($companyName)) ?>
      </div>
      <?php if ($slogan): ?>
      <div style="font-size:<?= $fSlogan ?>px;color:#666;margin-top:2px;font-style:italic;line-height:1.3">
        <?= h($slogan) ?>
      </div>
      <?php endif; ?>
    </td>

    <!-- Société (centre) -->
    <td style="vertical-align:top;text-align:center;padding:14px 8px 6px 8px">
      <div style="font-family:Georgia,'Times New Roman',serif;font-size:<?= $fCie ?>px;font-weight:400;color:#111;letter-spacing:1px;line-height:1.05">
        <?= h($companyName) ?>
      </div>
      <?php if ($companyAddr): ?>
      <div style="font-size:<?= $fAddr ?>px;color:#333;margin-top:8px;letter-spacing:.3px">
        <?= h($companyAddr) ?>
      </div>
      <?php endif; ?>
    </td>

    <!-- Photo (droite) -->
    <td style="width:19%;vertical-align:top;text-align:center;padding:8px 16px 4px 6px">
      <?php if ($photoUrl): ?>
      <img src="<?= h($photoUrl) ?>"
           style="width:<?= $phW ?>px;height:<?= $phH ?>px;object-fit:cover;border:1px solid #bbb;filter:grayscale(35%);display:block;margin:0 auto"
           alt="">
      <?php else: ?>
      <div style="width:<?= $phW ?>px;height:<?= $phH ?>px;border:1px solid #bbb;background:#ebebeb;display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700;color:#888;margin:0 auto">
        <?= h($initiales) ?>
      </div>
      <?php endif; ?>
      <?php if ($mat): ?>
      <div style="font-size:<?= $fMat ?>px;font-weight:700;color:#111;margin-top:4px;text-align:center">
        <b>MAT:</b> <?= h($mat) ?>
      </div>
      <?php endif; ?>
    </td>

  </tr>
  </table>

  <!-- ▌Séparateur ▐ -->
  <div style="height:1px;background:#ccc;margin:0 14px"></div>

  <!-- ▌CORPS — champs + filigrane ▐ -->
  <div style="height:<?= $bodyH ?>px;position:relative;overflow:hidden;padding:<?= max(6,(int)round($bodyH*.05)) ?>px 18px 4px 18px">

    <!-- Filigrane -->
    <div style="position:absolute;top:50%;left:50%;margin-top:-<?= (int)round($bodyH*.38) ?>px;margin-left:-<?= (int)round($wmW*.55) ?>px;text-align:center;pointer-events:none">
      <img src="<?= h($logoUrl) ?>" style="width:<?= $wmW ?>px;opacity:.055;filter:grayscale(1)" onerror="this.style.display='none'" alt="">
      <div style="font-size:<?= $wmFtxt ?>px;font-weight:700;color:rgba(0,0,0,.055);letter-spacing:3px;text-transform:uppercase;margin-top:-8px;white-space:nowrap">
        <?= h(strtoupper($companyName)) ?>
      </div>
      <?php if ($slogan): ?>
      <div style="font-size:<?= $fSlogan ?>px;color:rgba(0,0,0,.045);letter-spacing:1.5px;text-transform:uppercase">
        <?= h(strtoupper($slogan)) ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Champs agent -->
    <?php foreach ($rectoChamps as $c):
      if (in_array($c['cle'], ['photo','logo'])) continue;
      $val = getChampValBadge($a, $params, $c['cle']);
      if ($val === '') continue;
    ?>
    <div style="font-size:<?= $fField ?>px;color:#111;margin-bottom:<?= max(4,(int)round($bodyH*.045)) ?>px;position:relative;z-index:1;line-height:1.2">
      <b><?= h($c['label']) ?> :</b>&nbsp;<?= h($val) ?>
    </div>
    <?php endforeach; ?>

  </div>

  <!-- ▌PIED — CNAPS + mention légale ▐ -->
  <?php if ($cnapsNum || $legalText): ?>
  <div style="height:<?= $ftrH ?>px;border-top:1px solid #ddd;padding:5px 16px 4px;text-align:center;background:rgba(255,255,255,.85);overflow:hidden">
    <?php if ($cnapsNum): ?>
    <div style="font-size:<?= $fCnaps ?>px;color:#333">
      CNAPS : <?= h($cnapsNum) ?>
    </div>
    <?php endif; ?>
    <?php if ($legalText): ?>
    <div style="font-size:<?= $fLegal ?>px;color:#666;margin-top:3px;line-height:1.35">
      <?= h($legalText) ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <!-- ▌Barre inférieure ▐ -->
  <div style="height:<?= $barBot ?>px;background:#111"></div>

</div><!-- /badge -->
</div><!-- /badgeWrap -->

<style>
@media print {
  .no-print, #sidebar, #topbar { display: none !important; }
  #main-content { margin: 0 !important; padding: 6mm !important; background: white !important; }
  body { background: white !important; }
  #badgeWrap {
    display: block !important;
    transform: scale(<?= $printScale ?>);
    transform-origin: top left;
  }
  #badge { box-shadow: none !important; border: 1px solid #aaa !important; }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
