<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'edit');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$agent = $stmt->fetch();
if (!$agent) { flash('danger','Agent introuvable'); header('Location: index.php'); exit; }

$docs = $db->prepare("SELECT * FROM agent_documents WHERE agent_id = ? ORDER BY type_document");
$docs->execute([$id]);
$documents = $docs->fetchAll();

$errors = [];
$data   = $agent;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;

    if (empty($data['nom']))    $errors[] = 'Le nom est obligatoire.';
    if (empty($data['prenom'])) $errors[] = 'Le prénom est obligatoire.';

    if (empty($errors)) {
        $photo = $agent['photo'];
        if (!empty($_FILES['photo']['name'])) {
            $newPhoto = uploadFichier($_FILES['photo'], 'photos', ['jpg','jpeg','png','webp']);
            if ($newPhoto) $photo = $newPhoto;
            else $errors[] = 'Format photo invalide.';
        }

        if (empty($errors)) {
            $db->prepare("
                UPDATE agents SET
                matricule=?,nom=?,prenom=?,date_naissance=?,lieu_naissance=?,nationalite=?,num_secu=?,
                situation_familiale=?,nb_enfants=?,photo=?,adresse=?,cp=?,ville=?,telephone=?,email=?,
                type_contrat=?,poste=?,statut=?,temps_travail_hebdo=?,date_debut_contrat=?,date_fin_contrat=?,
                lieu_travail=?,periode_essai=?,motif_embauche=?,remuneration=?,type_remuneration=?,
                num_autorisation_cnaps=?,date_autorisation_cnaps=?,date_expiration_cnaps=?,
                h_lundi=?,h_mardi=?,h_mercredi=?,h_jeudi=?,h_vendredi=?,h_samedi=?,h_dimanche=?,
                dpae=?,contrat_realise=?,bulletins_depuis=?,prelevement_auto=?,actif=?
                WHERE id=?
            ")->execute([
                $data['matricule'],$data['nom'],$data['prenom'],
                $data['date_naissance']?:null,$data['lieu_naissance']?:null,
                $data['nationalite']?:null,$data['num_secu']?:null,
                $data['situation_familiale']?:null,(int)($data['nb_enfants']??0),
                $photo,
                $data['adresse']?:null,$data['cp']?:null,$data['ville']?:null,
                $data['telephone']?:null,$data['email']?:null,
                $data['type_contrat']?:null,$data['poste']?:null,$data['statut']?:null,
                $data['temps_travail_hebdo']?:null,
                $data['date_debut_contrat']?:null,$data['date_fin_contrat']?:null,
                $data['lieu_travail']?:null,$data['periode_essai']?:null,
                $data['motif_embauche']?:null,
                $data['remuneration']?:null,$data['type_remuneration']?:null,
                $data['num_autorisation_cnaps']?:null,
                $data['date_autorisation_cnaps']?:null,$data['date_expiration_cnaps']?:null,
                (float)($data['h_lundi']??0),(float)($data['h_mardi']??0),(float)($data['h_mercredi']??0),
                (float)($data['h_jeudi']??0),(float)($data['h_vendredi']??0),(float)($data['h_samedi']??0),
                (float)($data['h_dimanche']??0),
                isset($data['dpae'])?1:0,isset($data['contrat_realise'])?1:0,
                $data['bulletins_depuis']?:null,isset($data['prelevement_auto'])?1:0,
                isset($data['actif'])?1:0,
                $id
            ]);

            // Nouveaux documents
            $typesDoc = ['piece_identite','carte_vitale','attestation_domicile','titre_sejour','attestation_cnaps','contrat'];
            foreach ($typesDoc as $typeDoc) {
                if (!empty($_FILES[$typeDoc]['name'])) {
                    $chemin = uploadFichier($_FILES[$typeDoc], 'documents', ['pdf','jpg','jpeg','png']);
                    if ($chemin) {
                        $db->prepare("INSERT INTO agent_documents (agent_id,type_document,nom_fichier,chemin,taille) VALUES (?,?,?,?,?)")
                           ->execute([$id,$typeDoc,$_FILES[$typeDoc]['name'],$chemin,$_FILES[$typeDoc]['size']]);
                    }
                }
            }

            flash('success', 'Agent mis à jour avec succès.');
            header('Location: view.php?id=' . $id);
            exit;
        }
    }
}

$pageTitle    = 'Modifier l\'agent ' . h($agent['prenom'] . ' ' . $agent['nom']);
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';

$isEdit = true;
$docs   = $db->prepare("SELECT * FROM agent_documents WHERE agent_id=? ORDER BY type_document");
$docs->execute([$id]);
$documents = $docs->fetchAll();
?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="d-flex gap-2 mb-3">
    <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour fiche</a>
</div>

<form method="POST" enctype="multipart/form-data">
<div class="row g-3">
<div class="col-lg-8">

  <!-- Identité -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-user me-2" style="color:var(--ov-gold)"></i>Identité</h2></div>
    <div class="ov-card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Matricule</label>
          <input type="text" name="matricule" class="form-control" value="<?= h($data['matricule']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Nom <span class="text-danger">*</span></label>
          <input type="text" name="nom" class="form-control" value="<?= h($data['nom']??'') ?>" required>
        </div>
        <div class="col-md-5">
          <label class="form-label">Prénom <span class="text-danger">*</span></label>
          <input type="text" name="prenom" class="form-control" value="<?= h($data['prenom']??'') ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Date de naissance</label>
          <input type="date" name="date_naissance" class="form-control" value="<?= h($data['date_naissance']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Lieu de naissance</label>
          <input type="text" name="lieu_naissance" class="form-control" value="<?= h($data['lieu_naissance']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Nationalité</label>
          <input type="text" name="nationalite" class="form-control" value="<?= h($data['nationalite']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">N° Sécurité Sociale</label>
          <input type="text" name="num_secu" class="form-control" value="<?= h($data['num_secu']??'') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Situation familiale</label>
          <select name="situation_familiale" class="form-select">
            <option value="">—</option>
            <?php foreach (['Célibataire','Marié(e)','Divorcé(e)','Veuf(ve)','PACS'] as $sf): ?>
            <option value="<?= h($sf) ?>" <?= ($data['situation_familiale']??'')===$sf?'selected':'' ?>><?= h($sf) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Nb. enfants</label>
          <input type="number" name="nb_enfants" class="form-control" min="0" value="<?= h($data['nb_enfants']??0) ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Adresse</label>
          <input type="text" name="adresse" class="form-control" value="<?= h($data['adresse']??'') ?>">
        </div>
        <div class="col-md-3">
          <label class="form-label">Code postal</label>
          <input type="text" name="cp" class="form-control" value="<?= h($data['cp']??'') ?>">
        </div>
        <div class="col-md-5">
          <label class="form-label">Ville</label>
          <input type="text" name="ville" class="form-control" value="<?= h($data['ville']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Téléphone</label>
          <input type="tel" name="telephone" class="form-control" value="<?= h($data['telephone']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" value="<?= h($data['email']??'') ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- Contrat -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-file-contract me-2" style="color:var(--ov-gold)"></i>Contrat</h2></div>
    <div class="ov-card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Type de contrat</label>
          <select name="type_contrat" class="form-select">
            <option value="">—</option>
            <?php foreach (['CDI','CDD','CDD Usage','Saisonnier'] as $tc): ?>
            <option value="<?= h($tc) ?>" <?= ($data['type_contrat']??'')===$tc?'selected':'' ?>><?= h($tc) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Poste occupé</label>
          <input type="text" name="poste" class="form-control" value="<?= h($data['poste']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Statut</label>
          <select name="statut" class="form-select">
            <option value="">—</option>
            <option value="Cadre" <?= ($data['statut']??'')==='Cadre'?'selected':'' ?>>Cadre</option>
            <option value="Non cadre" <?= ($data['statut']??'')==='Non cadre'?'selected':'' ?>>Non cadre</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Date début contrat</label>
          <input type="date" name="date_debut_contrat" class="form-control" value="<?= h($data['date_debut_contrat']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Date fin contrat</label>
          <input type="date" name="date_fin_contrat" class="form-control" value="<?= h($data['date_fin_contrat']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Période d'essai</label>
          <input type="text" name="periode_essai" class="form-control" value="<?= h($data['periode_essai']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Lieu de travail</label>
          <input type="text" name="lieu_travail" class="form-control" value="<?= h($data['lieu_travail']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Motif d'embauche</label>
          <select name="motif_embauche" class="form-select">
            <option value="">—</option>
            <?php foreach (['Accroissement activité','Remplacement','Autres'] as $m): ?>
            <option value="<?= h($m) ?>" <?= ($data['motif_embauche']??'')===$m?'selected':'' ?>><?= h($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Rémunération €</label>
          <input type="number" name="remuneration" class="form-control" step="0.01" value="<?= h($data['remuneration']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Type</label>
          <select name="type_remuneration" class="form-select">
            <option value="Nette" <?= ($data['type_remuneration']??'')==='Nette'?'selected':'' ?>>Nette</option>
            <option value="Brute" <?= ($data['type_remuneration']??'')==='Brute'?'selected':'' ?>>Brute</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Temps hebdomadaire</label>
          <input type="text" name="temps_travail_hebdo" class="form-control" value="<?= h($data['temps_travail_hebdo']??'') ?>">
        </div>
      </div>
      <div class="row g-2 mt-2">
        <?php foreach (['lundi'=>'Lun','mardi'=>'Mar','mercredi'=>'Mer','jeudi'=>'Jeu','vendredi'=>'Ven','samedi'=>'Sam','dimanche'=>'Dim'] as $k=>$v): ?>
        <div class="col">
          <label class="form-label text-center d-block"><?= $v ?></label>
          <input type="number" name="h_<?= $k ?>" class="form-control text-center" step="0.5" min="0" max="24" value="<?= h($data["h_$k"]??0) ?>">
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- CNAPS -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-shield-halved me-2" style="color:var(--ov-gold)"></i>Autorisation CNAPS</h2></div>
    <div class="ov-card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">N° Autorisation</label>
          <input type="text" name="num_autorisation_cnaps" class="form-control" value="<?= h($data['num_autorisation_cnaps']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Date d'autorisation</label>
          <input type="date" name="date_autorisation_cnaps" class="form-control" value="<?= h($data['date_autorisation_cnaps']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Date d'expiration</label>
          <input type="date" name="date_expiration_cnaps" class="form-control" value="<?= h($data['date_expiration_cnaps']??'') ?>">
        </div>
      </div>
    </div>
  </div>

  <!-- Pôle Social -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-building-columns me-2" style="color:var(--ov-gold)"></i>Pôle Social</h2></div>
    <div class="ov-card-body">
      <div class="row g-3">
        <div class="col-md-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="dpae" id="dpae" <?= ($data['dpae']??0)?'checked':'' ?>>
            <label class="form-check-label" for="dpae">DPAE réalisée</label>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="contrat_realise" id="contrat_realise" <?= ($data['contrat_realise']??0)?'checked':'' ?>>
            <label class="form-check-label" for="contrat_realise">Contrat réalisé</label>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="prelevement_auto" id="prelevement_auto" <?= ($data['prelevement_auto']??0)?'checked':'' ?>>
            <label class="form-check-label" for="prelevement_auto">Prélèvement auto.</label>
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Bulletins depuis</label>
          <input type="text" name="bulletins_depuis" class="form-control" value="<?= h($data['bulletins_depuis']??'') ?>">
        </div>
      </div>
    </div>
  </div>

</div>

<div class="col-lg-4">
  <!-- Photo -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-camera me-2" style="color:var(--ov-gold)"></i>Photo</h2></div>
    <div class="ov-card-body text-center">
      <img id="photoPreview" src="<?= $data['photo'] ? UPLOAD_URL.'/'.$data['photo'] : APP_URL.'/assets/img/avatar-default.png' ?>"
           class="rounded-circle mb-3" style="width:100px;height:100px;object-fit:cover;border:3px solid var(--ov-gold)"
           onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($data['prenom'].' '.$data['nom']) ?>&background=1a2332&color=c9a84c&size=100'">
      <input type="file" name="photo" class="form-control form-control-sm" accept="image/*" onchange="previewPhoto(this,'photoPreview')">
    </div>
  </div>

  <!-- Documents existants + upload -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-folder-open me-2" style="color:var(--ov-gold)"></i>Documents</h2></div>
    <div class="ov-card-body">
      <?php
      $docsLabels = ['piece_identite'=>'Pièce d\'identité','carte_vitale'=>'Carte vitale','attestation_domicile'=>'Attestation domicile','titre_sejour'=>'Titre de séjour','attestation_cnaps'=>'Attestation CNAPS','contrat'=>'Contrat de travail'];
      foreach ($docsLabels as $k => $label): ?>
      <div class="mb-2">
        <label class="form-label"><?= h($label) ?></label>
        <?php $doc = array_filter($documents, fn($d) => $d['type_document'] === $k); $doc = reset($doc); ?>
        <?php if ($doc): ?>
        <div class="d-flex align-items-center gap-2 mb-1">
          <a href="<?= UPLOAD_URL.'/'.$doc['chemin'] ?>" target="_blank" class="btn btn-sm btn-ov-secondary"><i class="fa fa-eye me-1"></i><?= h($doc['nom_fichier']) ?></a>
        </div>
        <?php endif; ?>
        <input type="file" name="<?= $k ?>" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Statut -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-toggle-on me-2" style="color:var(--ov-gold)"></i>Statut</h2></div>
    <div class="ov-card-body">
      <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" name="actif" id="actif" <?= ($data['actif']??1)?'checked':'' ?>>
        <label class="form-check-label" for="actif">Agent actif</label>
      </div>
    </div>
  </div>

  <div class="d-grid gap-2">
    <button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Enregistrer les modifications</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary text-center">Annuler</a>
  </div>
</div>
</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
