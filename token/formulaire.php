<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$token = trim($_GET['t'] ?? '');
if (!$token) { die('Lien invalide.'); }

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM agents WHERE token_acces = ? AND token_used = 0 AND token_expires_at > NOW()");
$stmt->execute([$token]);
$agent = $stmt->fetch();

if (!$agent) {
    $expired = $db->prepare("SELECT token_used FROM agents WHERE token_acces = ?")->execute([$token]);
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Lien expiré</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-dark text-white d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="text-center p-4">
    <i class="fa fa-triangle-exclamation fa-3x text-warning mb-3"></i>
    <h4>Lien invalide ou expiré</h4>
    <p class="text-muted">Ce lien a déjà été utilisé ou a expiré. Contactez votre responsable pour en obtenir un nouveau.</p>
</div>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</body></html>
<?php exit; }

$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;

    // Mettre à jour les infos de base
    $db->prepare("
        UPDATE agents SET
        date_naissance=?,lieu_naissance=?,nationalite=?,num_secu=?,
        situation_familiale=?,nb_enfants=?,adresse=?,cp=?,ville=?,telephone=?,email=?,
        num_autorisation_cnaps=?,date_autorisation_cnaps=?,date_expiration_cnaps=?,
        token_used=1
        WHERE id=?
    ")->execute([
        $data['date_naissance']?:null,$data['lieu_naissance']?:null,
        $data['nationalite']?:null,$data['num_secu']?:null,
        $data['situation_familiale']?:null,(int)($data['nb_enfants']??0),
        $data['adresse']?:null,$data['cp']?:null,$data['ville']?:null,
        $data['telephone']?:null,$data['email']?:null,
        $data['num_autorisation_cnaps']?:null,
        $data['date_autorisation_cnaps']?:null,$data['date_expiration_cnaps']?:null,
        $agent['id']
    ]);

    // Photo
    if (!empty($_FILES['photo']['name'])) {
        $photo = uploadFichier($_FILES['photo'], 'photos', ['jpg','jpeg','png']);
        if ($photo) $db->prepare("UPDATE agents SET photo=? WHERE id=?")->execute([$photo, $agent['id']]);
    }

    // Documents
    $typesDoc = ['piece_identite','carte_vitale','attestation_domicile','titre_sejour','attestation_cnaps','contrat'];
    foreach ($typesDoc as $typeDoc) {
        if (!empty($_FILES[$typeDoc]['name'])) {
            $chemin = uploadFichier($_FILES[$typeDoc], 'documents', ['pdf','jpg','jpeg','png']);
            if ($chemin) {
                $db->prepare("INSERT INTO agent_documents (agent_id,type_document,nom_fichier,chemin,taille) VALUES (?,?,?,?,?)")
                   ->execute([$agent['id'],$typeDoc,$_FILES[$typeDoc]['name'],$chemin,$_FILES[$typeDoc]['size']]);
            }
        }
    }
    $success = true;
}

$params = getAllParams();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Formulaire — <?= h($params['entreprise_nom'] ?? 'Oeil Vigilant') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
body { background:#f0f2f5; font-family:'Segoe UI',sans-serif; }
.form-header { background:linear-gradient(135deg,#0d1520,#1a2332); color:white; padding:2rem; border-radius:12px 12px 0 0; }
.form-header h1 { font-size:1.3rem; }
.section-title { color:#c9a84c; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; border-bottom:1px solid rgba(201,168,76,0.2); padding-bottom:0.5rem; margin:1.5rem 0 1rem; }
.form-label { font-weight:500; font-size:0.85rem; color:#374151; }
</style>
</head>
<body>
<div class="container py-4" style="max-width:700px">
<?php if ($success): ?>
  <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden">
    <div class="form-header text-center">
      <i class="fa fa-circle-check fa-3x text-success mb-3"></i>
      <h1>Formulaire envoyé !</h1>
      <p class="opacity-75 mb-0">Vos informations ont bien été reçues. Merci !</p>
    </div>
  </div>
<?php else: ?>
  <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden">
    <div class="form-header">
      <div class="d-flex align-items-center gap-3">
        <img src="<?= APP_URL ?>/assets/img/logo.png" style="height:40px;filter:brightness(0) invert(1)" alt="Logo">
        <div>
          <h1 class="mb-0"><?= h($params['entreprise_nom'] ?? 'Oeil Vigilant') ?></h1>
          <p class="opacity-60 mb-0" style="font-size:0.8rem">Fiche de renseignements — <?= h($agent['prenom'].' '.$agent['nom']) ?></p>
        </div>
      </div>
    </div>
    <div class="card-body p-4">
      <div class="alert alert-info py-2" style="font-size:0.85rem">
        <i class="fa fa-info-circle me-2"></i>
        Bonjour <strong><?= h($agent['prenom'].' '.$agent['nom']) ?></strong>, veuillez compléter vos informations ci-dessous. Ce lien est à usage unique.
      </div>

      <form method="POST" enctype="multipart/form-data">

        <div class="section-title">Informations personnelles</div>
        <div class="row g-3">
          <div class="col-md-6"><label class="form-label">Date de naissance</label>
            <input type="date" name="date_naissance" class="form-control" value="<?= h($agent['date_naissance']??'') ?>"></div>
          <div class="col-md-6"><label class="form-label">Lieu de naissance</label>
            <input type="text" name="lieu_naissance" class="form-control" value="<?= h($agent['lieu_naissance']??'') ?>"></div>
          <div class="col-md-6"><label class="form-label">Nationalité</label>
            <input type="text" name="nationalite" class="form-control" value="<?= h($agent['nationalite']??'') ?>"></div>
          <div class="col-md-6"><label class="form-label">N° Sécurité Sociale</label>
            <input type="text" name="num_secu" class="form-control" data-format="secu" value="<?= h($agent['num_secu']??'') ?>"></div>
          <div class="col-md-6"><label class="form-label">Situation familiale</label>
            <select name="situation_familiale" class="form-select">
              <option value="">—</option>
              <?php foreach (['Célibataire','Marié(e)','Divorcé(e)','Veuf(ve)','PACS'] as $sf): ?>
              <option value="<?= h($sf) ?>" <?= $agent['situation_familiale']===$sf?'selected':'' ?>><?= h($sf) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label">Nombre d'enfants</label>
            <input type="number" name="nb_enfants" class="form-control" min="0" value="<?= h($agent['nb_enfants']??0) ?>"></div>
        </div>

        <div class="section-title">Coordonnées</div>
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Adresse</label>
            <input type="text" name="adresse" class="form-control" value="<?= h($agent['adresse']??'') ?>"></div>
          <div class="col-md-3"><label class="form-label">Code postal</label>
            <input type="text" name="cp" class="form-control" value="<?= h($agent['cp']??'') ?>"></div>
          <div class="col-md-5"><label class="form-label">Ville</label>
            <input type="text" name="ville" class="form-control" value="<?= h($agent['ville']??'') ?>"></div>
          <div class="col-md-4"><label class="form-label">Téléphone</label>
            <input type="tel" name="telephone" class="form-control" value="<?= h($agent['telephone']??'') ?>"></div>
          <div class="col-md-6"><label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" value="<?= h($agent['email']??'') ?>"></div>
        </div>

        <div class="section-title">Autorisation CNAPS</div>
        <div class="row g-3">
          <div class="col-md-4"><label class="form-label">N° Autorisation CNAPS</label>
            <input type="text" name="num_autorisation_cnaps" class="form-control" value="<?= h($agent['num_autorisation_cnaps']??'') ?>"></div>
          <div class="col-md-4"><label class="form-label">Date d'autorisation</label>
            <input type="date" name="date_autorisation_cnaps" class="form-control" value="<?= h($agent['date_autorisation_cnaps']??'') ?>"></div>
          <div class="col-md-4"><label class="form-label">Date d'expiration</label>
            <input type="date" name="date_expiration_cnaps" class="form-control" value="<?= h($agent['date_expiration_cnaps']??'') ?>"></div>
        </div>

        <div class="section-title">Photo & Documents</div>
        <div class="row g-3">
          <div class="col-12"><label class="form-label">Photo</label>
            <input type="file" name="photo" class="form-control" accept="image/*"></div>
          <?php $docsLabels = ['piece_identite'=>'Pièce d\'identité','carte_vitale'=>'Carte vitale','attestation_domicile'=>'Attestation domicile','titre_sejour'=>'Titre de séjour (si étranger)','attestation_cnaps'=>'Attestation CNAPS','contrat'=>'Contrat de travail'];
          foreach ($docsLabels as $k=>$l): ?>
          <div class="col-md-6"><label class="form-label"><?= h($l) ?></label>
            <input type="file" name="<?= $k ?>" class="form-control" accept=".pdf,.jpg,.jpeg,.png"></div>
          <?php endforeach; ?>
        </div>

        <div class="mt-4 d-grid">
          <button type="submit" class="btn btn-lg" style="background:linear-gradient(135deg,#c9a84c,#a8883c);color:#1a2332;font-weight:700">
            <i class="fa fa-paper-plane me-2"></i>Envoyer mon dossier
          </button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
</div>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
