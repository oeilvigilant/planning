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

$pageTitle    = 'Carte agent';
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';

$params     = getAllParams();
$champs     = $db->query("SELECT * FROM carte_champs WHERE actif=1 ORDER BY face, ordre")->fetchAll();
$rectoChamps = array_filter($champs, fn($c) => $c['face'] === 'recto');
$versoChamps = array_filter($champs, fn($c) => $c['face'] === 'verso');

function getChampVal(array $a, array $params, string $cle): string {
    $agentMap = [
        'prenom_nom'              => trim($a['prenom'].' '.$a['nom']),
        'nom'                     => $a['nom'],
        'prenom'                  => $a['prenom'],
        'matricule'               => $a['matricule'],
        'poste'                   => $a['poste'],
        'num_autorisation_cnaps'  => $a['num_autorisation_cnaps'],
        'date_debut_contrat'      => $a['date_debut_contrat'] ? date('d/m/Y', strtotime($a['date_debut_contrat'])) : '',
        'date_expiration_cnaps'   => $a['date_expiration_cnaps'] ? date('d/m/Y', strtotime($a['date_expiration_cnaps'])) : '',
    ];
    $entMap = [
        'entreprise_nom'     => $params['entreprise_nom']     ?? '',
        'entreprise_adresse' => ($params['entreprise_adresse']??'').' '.(($params['entreprise_cp']??'')).' '.(($params['entreprise_ville']??'')),
        'entreprise_tel'     => $params['entreprise_tel']     ?? '',
        'entreprise_siret'   => 'SIRET: '.($params['entreprise_siret'] ?? ''),
    ];
    return $agentMap[$cle] ?? $entMap[$cle] ?? '';
}
?>

<div class="d-flex gap-2 mb-3">
  <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour</a>
  <button onclick="window.print()" class="btn btn-ov-primary btn-sm"><i class="fa fa-print me-1"></i>Imprimer</button>
  <a href="export_carte_pdf.php?id=<?= $id ?>" class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.35rem 0.75rem;font-size:0.8rem"><i class="fa fa-file-pdf me-1"></i>Télécharger PDF</a>
</div>

<div class="row g-4">
  <!-- Recto -->
  <div class="col-md-6">
    <div class="ov-card-header mb-2"><h2 class="ov-card-title">Recto</h2></div>
    <div class="carte-preview carte-recto" id="carteRecto">
      <div class="carte-gold-bar"></div>
      <div class="d-flex h-100" style="padding:10px;gap:10px;align-items:flex-start">
        <!-- Photo -->
        <?php if (in_array('photo', array_column(iterator_to_array($rectoChamps), 'cle'))): ?>
        <div style="flex-shrink:0">
          <?php if ($a['photo']): ?>
          <img src="<?= UPLOAD_URL ?>/<?= h($a['photo']) ?>" style="width:60px;height:70px;object-fit:cover;border-radius:4px;border:2px solid rgba(201,168,76,0.4)">
          <?php else: ?>
          <div style="width:60px;height:70px;border-radius:4px;background:rgba(201,168,76,0.15);border:2px solid rgba(201,168,76,0.4);display:flex;align-items:center;justify-content:center;color:var(--ov-gold);font-weight:700;font-size:1rem">
            <?= strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1)) ?>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
        <!-- Infos -->
        <div style="flex:1;color:white;overflow:hidden">
          <!-- Logo -->
          <?php if (in_array('logo', array_column(iterator_to_array($rectoChamps), 'cle'))): ?>
          <img src="<?= APP_URL ?>/assets/img/<?= h($params['logo_principal']??'logo.png') ?>" style="height:18px;filter:brightness(0) invert(1);margin-bottom:4px" onerror="this.style.display='none'">
          <?php endif; ?>

          <?php foreach ($rectoChamps as $c):
            if (in_array($c['cle'], ['photo','logo'])) continue;
            $val = getChampVal($a, $params, $c['cle']);
            if (!$val) continue;
            $isName = $c['cle'] === 'prenom_nom';
          ?>
          <div style="<?= $isName ? 'font-size:0.75rem;font-weight:700;color:var(--ov-gold-light);margin-bottom:3px' : 'font-size:0.62rem;color:rgba(255,255,255,0.8);margin-bottom:2px' ?>">
            <?php if (!$isName): ?><span style="color:rgba(255,255,255,0.45);font-size:0.55rem;display:block"><?= h($c['label']) ?></span><?php endif; ?>
            <?= h($val) ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Verso -->
  <div class="col-md-6">
    <div class="ov-card-header mb-2"><h2 class="ov-card-title">Verso</h2></div>
    <div class="carte-preview carte-verso" id="carteVerso">
      <div class="carte-gold-bar"></div>
      <div style="padding:10px;color:white">
        <div style="font-size:0.65rem;font-weight:700;color:var(--ov-gold);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">
          <?= h($params['entreprise_nom'] ?? 'Oeil Vigilant') ?>
        </div>
        <?php foreach ($versoChamps as $c):
          $val = getChampVal($a, $params, $c['cle']);
          if (!$val) continue;
        ?>
        <div style="font-size:0.62rem;margin-bottom:4px;color:rgba(255,255,255,0.85)">
          <span style="color:rgba(255,255,255,0.45);display:block;font-size:0.55rem"><?= h($c['label']) ?></span>
          <?= h($val) ?>
        </div>
        <?php endforeach; ?>
        <div style="position:absolute;bottom:10px;right:10px">
          <img src="<?= APP_URL ?>/assets/img/logo-blanc.jpg" style="height:25px;opacity:0.3" onerror="this.style.display='none'">
        </div>
      </div>
    </div>
  </div>
</div>

<style>
@media print {
    #sidebar, #topbar, .d-flex.gap-2.mb-3, .ov-card-header { display:none !important; }
    #main-content { margin:0; padding:0; }
    .row { display:flex; gap:5mm; }
    .carte-preview { width:85.6mm; height:53.98mm; page-break-inside:avoid; }
    body { background:white; }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
