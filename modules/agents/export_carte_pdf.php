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
$versoChamps = array_values(array_filter($champs, fn($c) => $c['face'] === 'verso'));

function getChampValPdf(array $a, array $params, string $cle): string {
    $agentMap = [
        'prenom_nom'             => trim($a['prenom'].' '.$a['nom']),
        'nom'                    => $a['nom'] ?? '',
        'prenom'                 => $a['prenom'] ?? '',
        'matricule'              => $a['matricule'] ?? '',
        'poste'                  => $a['poste'] ?? '',
        'num_autorisation_cnaps' => $a['num_autorisation_cnaps'] ?? '',
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

// Photo en base64 pour DomPDF
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

$initiales = strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1));

ob_start();
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, Helvetica, sans-serif; background: white; }
.page { padding: 15mm; }
.cards-row { display: table; width: 180mm; border-spacing: 8mm; }
.card-cell { display: table-cell; vertical-align: top; }
.carte {
    width: 85.6mm; height: 53.98mm; border-radius: 5mm;
    background: linear-gradient(135deg, #0f172a 0%, #1a2332 60%, #0f172a 100%);
    position: relative; overflow: hidden; page-break-inside: avoid;
}
.gold-bar { height: 3mm; background: linear-gradient(90deg, #c9a84c, #f0d080, #c9a84c); }
.card-inner { padding: 3mm 3mm 3mm 3mm; display: flex; height: 50.98mm; gap: 2.5mm; }
.photo-box { flex-shrink: 0; }
.photo-box img { width: 16mm; height: 19mm; object-fit: cover; border-radius: 1mm; border: 0.5mm solid rgba(201,168,76,0.5); }
.initiales { width: 16mm; height: 19mm; border-radius: 1mm; background: rgba(201,168,76,0.15); border: 0.5mm solid rgba(201,168,76,0.4); display: flex; align-items: center; justify-content: center; color: #c9a84c; font-weight: 700; font-size: 9pt; }
.info-box { flex: 1; color: white; overflow: hidden; }
.logo-img { height: 5mm; margin-bottom: 1mm; filter: brightness(0) invert(1); }
.field-name { font-size: 5.5pt; font-weight: 700; color: #c9a84c; margin-bottom: 0.8mm; }
.field-label { font-size: 4.5pt; color: rgba(255,255,255,0.45); display: block; }
.field-value { font-size: 5pt; color: rgba(255,255,255,0.85); margin-bottom: 1mm; }
.section-title { font-size: 5.5pt; font-weight: 700; color: #c9a84c; text-transform: uppercase; letter-spacing: 0.5pt; margin-bottom: 2mm; }
.verso-inner { padding: 3mm; color: white; height: 50.98mm; position: relative; }
.verso-logo { position: absolute; bottom: 3mm; right: 3mm; opacity: 0.25; }
.label-title { font-size: 5pt; color: rgba(255,255,255,0.4); margin-bottom: 3mm; font-style: italic; }
</style>
</head><body>
<div class="page">
<div style="display:table;width:100%;margin-bottom:5mm">
  <div style="display:table-cell;vertical-align:middle">
    <div style="font-size:11pt;font-weight:700;color:#1a2332">Carte agent — <?= htmlspecialchars($a['prenom'].' '.$a['nom']) ?></div>
    <div style="font-size:8pt;color:#6b7280">Généré le <?= date('d/m/Y') ?></div>
  </div>
</div>

<table style="border-spacing:8mm;border-collapse:separate">
<tr>
<!-- RECTO -->
<td>
  <div style="font-size:8pt;font-weight:600;color:#374151;margin-bottom:2mm">Recto</div>
  <div class="carte">
    <div class="gold-bar"></div>
    <div class="card-inner">
      <?php if (in_array('photo', array_column($rectoChamps, 'cle'))): ?>
      <div class="photo-box">
        <?php if ($photoB64): ?>
        <img src="<?= $photoB64 ?>">
        <?php else: ?>
        <div class="initiales"><?= $initiales ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="info-box">
        <?php if (in_array('logo', array_column($rectoChamps, 'cle')) && $logoB64): ?>
        <img src="<?= $logoB64 ?>" class="logo-img">
        <?php endif; ?>
        <?php foreach ($rectoChamps as $c):
          if (in_array($c['cle'], ['photo','logo'])) continue;
          $val = getChampValPdf($a, $params, $c['cle']);
          if (!$val) continue;
          $isName = $c['cle'] === 'prenom_nom';
        ?>
        <div class="<?= $isName ? 'field-name' : 'field-value' ?>">
          <?php if (!$isName): ?><span class="field-label"><?= htmlspecialchars($c['label']) ?></span><?php endif; ?>
          <?= htmlspecialchars($val) ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</td>

<!-- VERSO -->
<td>
  <div style="font-size:8pt;font-weight:600;color:#374151;margin-bottom:2mm">Verso</div>
  <div class="carte">
    <div class="gold-bar"></div>
    <div class="verso-inner">
      <div class="section-title"><?= htmlspecialchars($params['entreprise_nom'] ?? 'Oeil Vigilant') ?></div>
      <?php foreach ($versoChamps as $c):
        $val = getChampValPdf($a, $params, $c['cle']);
        if (!$val) continue;
      ?>
      <div class="field-value">
        <span class="field-label"><?= htmlspecialchars($c['label']) ?></span>
        <?= htmlspecialchars($val) ?>
      </div>
      <?php endforeach; ?>
      <?php if ($logoB64): ?>
      <div class="verso-logo"><img src="<?= $logoB64 ?>" style="height:8mm"></div>
      <?php endif; ?>
    </div>
  </div>
</td>
</tr>
</table>
</div>
</body></html>
<?php
$html = ob_get_clean();
renderPdf($html, 'carte_'.strtolower($a['nom']).'_'.strtolower($a['prenom']).'.pdf');
