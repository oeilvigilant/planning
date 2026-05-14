<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
requirePerm('agents', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Agent invalide.');

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) die('Agent introuvable.');

$params    = getAllParams();
$pdfChamps = $db->query("SELECT * FROM pdf_champs WHERE actif=1 ORDER BY ordre")->fetchAll();

$agentData = [
    'nom'                    => $a['nom'],
    'prenom'                 => $a['prenom'],
    'date_naissance'         => $a['date_naissance'] ? date('d/m/Y', strtotime($a['date_naissance'])) : '',
    'lieu_naissance'         => $a['lieu_naissance'] ?? '',
    'nationalite'            => $a['nationalite'] ?? '',
    'num_secu'               => $a['num_secu'] ?? '',
    'adresse'                => $a['adresse'] ?? '',
    'cp'                     => $a['cp'] ?? '',
    'ville'                  => $a['ville'] ?? '',
    'situation_familiale'    => $a['situation_familiale'] ?? '',
    'nb_enfants'             => (string)($a['nb_enfants'] ?? 0),
    'type_contrat'           => $a['type_contrat'] ?? '',
    'poste'                  => $a['poste'] ?? '',
    'statut'                 => $a['statut'] ?? '',
    'date_debut_contrat'     => $a['date_debut_contrat'] ? date('d/m/Y', strtotime($a['date_debut_contrat'])) : '',
    'date_fin_contrat'       => $a['date_fin_contrat'] ? date('d/m/Y', strtotime($a['date_fin_contrat'])) : '',
    'lieu_travail'           => $a['lieu_travail'] ?? '',
    'remuneration'           => $a['remuneration'] ? number_format((float)$a['remuneration'], 2) . ' €' : '',
    'type_remuneration'      => $a['type_remuneration'] ?? '',
    'num_autorisation_cnaps' => $a['num_autorisation_cnaps'] ?? '',
    'date_expiration_cnaps'  => $a['date_expiration_cnaps'] ? date('d/m/Y', strtotime($a['date_expiration_cnaps'])) : '',
    'dpae'                   => $a['dpae'] ? 'Oui' : 'Non',
    'contrat_realise'        => $a['contrat_realise'] ? 'Oui' : 'Non',
];

// Encoder logo en base64 pour PDF
$logoB64 = '';
$logoFile = APP_ROOT . '/assets/img/' . ($params['logo_principal'] ?? 'logo.png');
if (file_exists($logoFile)) {
    $ext  = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
    $mime = $ext === 'svg' ? 'image/svg+xml' : (($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png');
    $logoB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
}

$photoB64 = '';
if (!empty($a['photo'])) {
    $photoFile = UPLOAD_PATH . '/' . $a['photo'];
    if (file_exists($photoFile)) {
        $ext  = strtolower(pathinfo($photoFile, PATHINFO_EXTENSION));
        $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
        $photoB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($photoFile));
    }
}

// Grouper les champs en sections
$sections = [
    'Identité'          => ['nom','prenom','date_naissance','lieu_naissance','nationalite','num_secu','situation_familiale','nb_enfants'],
    'Coordonnées'       => ['adresse','cp','ville'],
    'Contrat'           => ['type_contrat','poste','statut','date_debut_contrat','date_fin_contrat','lieu_travail','remuneration','type_remuneration'],
    'Autorisation CNAPS'=> ['num_autorisation_cnaps','date_expiration_cnaps'],
    'Pôle Social'       => ['dpae','contrat_realise'],
];

$champsActifs = array_column($pdfChamps, 'cle');

ob_start();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<?= pdfBaseStyle() ?>
</head>
<body>
<div class="page">

  <!-- En-tête -->
  <div class="pdf-header">
    <div>
      <?php if ($logoB64): ?>
      <img src="<?= $logoB64 ?>" style="height:38px;margin-bottom:6px"><br>
      <?php endif; ?>
      <h1><?= htmlspecialchars($params['entreprise_nom'] ?? 'Oeil Vigilant') ?></h1>
      <p><?= htmlspecialchars($params['entreprise_adresse'] ?? '') ?>, <?= htmlspecialchars($params['entreprise_cp'] ?? '') ?> <?= htmlspecialchars($params['entreprise_ville'] ?? '') ?></p>
      <p>SIRET : <?= htmlspecialchars($params['entreprise_siret'] ?? '') ?></p>
      <?php if (!empty($params['entreprise_tel'])): ?>
      <p>Tél : <?= htmlspecialchars($params['entreprise_tel']) ?></p>
      <?php endif; ?>
    </div>
    <div style="text-align:center">
      <?php if ($photoB64): ?>
      <img src="<?= $photoB64 ?>" style="width:70px;height:80px;object-fit:cover;border:2px solid #c9a84c;border-radius:4px">
      <?php else: ?>
      <div style="width:70px;height:80px;background:#f0f2f5;border:2px solid #c9a84c;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:22pt;color:#c9a84c;font-weight:700">
        <?= strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1)) ?>
      </div>
      <?php endif; ?>
      <div style="margin-top:5px;font-size:7pt;color:#999">Matricule</div>
      <div style="font-weight:700;font-size:9pt"><?= htmlspecialchars($a['matricule'] ?? '—') ?></div>
    </div>
  </div>

  <div class="pdf-title">FICHE DE RENSEIGNEMENTS SALARIÉ</div>

  <!-- Sections de champs -->
  <?php foreach ($sections as $sectionNom => $cles): ?>
  <?php
  $champsSection = array_filter($pdfChamps, fn($c) => in_array($c['cle'], $cles) && in_array($c['cle'], $champsActifs));
  if (empty($champsSection)) continue;
  ?>
  <div class="section-title"><?= htmlspecialchars($sectionNom) ?></div>
  <div class="grid-2">
    <?php foreach ($champsSection as $champ): ?>
    <?php $val = $agentData[$champ['cle']] ?? ''; if ($val === '') continue; ?>
    <div class="field">
      <div class="field-label"><?= htmlspecialchars($champ['label']) ?></div>
      <div class="field-value"><?= htmlspecialchars($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>

  <!-- Pied de page -->
  <div class="pdf-footer">
    <span>Généré le <?= date('d/m/Y à H:i') ?></span>
    <span>Document confidentiel — <?= htmlspecialchars($params['entreprise_nom'] ?? '') ?></span>
  </div>

</div>
</body>
</html>
<?php
$html = ob_get_clean();
renderPdf($html, 'fiche_agent_' . ($a['matricule'] ?? $a['id']) . '.pdf');
