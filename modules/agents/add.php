<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'create');

$db     = getDB();
ensureAgentsSchema();
$errors = [];
$data   = [];
$postesGrille = $db->query("SELECT * FROM postes WHERE actif=1 ORDER BY ordre, label")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;

    // Validation champs obligatoires
    if (empty($data['nom']))    $errors[] = 'Le nom est obligatoire.';
    if (empty($data['prenom'])) $errors[] = 'Le prénom est obligatoire.';

    if (empty($errors)) {
        $photo = null;
        if (!empty($_FILES['photo']['name'])) {
            $photo = uploadFichier($_FILES['photo'], 'photos', ['jpg','jpeg','png','webp']);
            if (!$photo) $errors[] = 'Format photo invalide (jpg, png acceptés).';
        }

        if (empty($errors)) {
            $sexe      = in_array($data['sexe'] ?? '', ['M','F']) ? $data['sexe'] : 'M';
            $matricule = !empty($data['matricule']) ? $data['matricule'] : generateMatricule($sexe);

            $stmt = $db->prepare("
                INSERT INTO agents
                (matricule,nom,prenom,sexe,date_naissance,lieu_naissance,nationalite,num_secu,
                 situation_familiale,nb_enfants,photo,adresse,cp,ville,telephone,email,
                 type_contrat,poste,statut,temps_travail_hebdo,date_debut_contrat,date_fin_contrat,
                 lieu_travail,periode_essai,motif_embauche,remuneration,type_remuneration,
                 num_autorisation_cnaps,date_autorisation_cnaps,date_expiration_cnaps,
                 h_lundi,h_mardi,h_mercredi,h_jeudi,h_vendredi,h_samedi,h_dimanche,
                 dpae,contrat_realise,bulletins_depuis,prelevement_auto,actif,created_by)
                VALUES
                (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $matricule,
                $data['nom'], $data['prenom'], $sexe,
                ($data['date_naissance'] ?? '') ?: null, ($data['lieu_naissance'] ?? '') ?: null,
                ($data['nationalite'] ?? '') ?: null, ($data['num_secu'] ?? '') ?: null,
                ($data['situation_familiale'] ?? '') ?: null, (int)($data['nb_enfants'] ?? 0),
                $photo,
                ($data['adresse'] ?? '') ?: null, ($data['cp'] ?? '') ?: null, ($data['ville'] ?? '') ?: null,
                ($data['telephone'] ?? '') ?: null, ($data['email'] ?? '') ?: null,
                ($data['type_contrat'] ?? '') ?: null, ($data['poste'] ?? '') ?: null, ($data['statut'] ?? '') ?: null,
                ($data['temps_travail_hebdo'] ?? '') ?: null,
                ($data['date_debut_contrat'] ?? '') ?: null, ($data['date_fin_contrat'] ?? '') ?: null,
                ($data['lieu_travail'] ?? '') ?: null, ($data['periode_essai'] ?? '') ?: null,
                ($data['motif_embauche'] ?? '') ?: null,
                ($data['remuneration'] ?? '') ?: null, ($data['type_remuneration'] ?? '') ?: null,
                ($data['num_autorisation_cnaps'] ?? '') ?: null,
                ($data['date_autorisation_cnaps'] ?? '') ?: null, ($data['date_expiration_cnaps'] ?? '') ?: null,
                (float)($data['h_lundi']??0),(float)($data['h_mardi']??0),(float)($data['h_mercredi']??0),
                (float)($data['h_jeudi']??0),(float)($data['h_vendredi']??0),(float)($data['h_samedi']??0),
                (float)($data['h_dimanche']??0),
                isset($data['dpae'])?1:0, isset($data['contrat_realise'])?1:0,
                ($data['bulletins_depuis'] ?? '') ?: null, isset($data['prelevement_auto'])?1:0,
                isset($data['actif'])?1:0,
                getCurrentUser()['id']
            ]);
            $agentId = $db->lastInsertId();

            // Upload documents
            $typesDoc = ['piece_identite','carte_vitale','attestation_domicile','titre_sejour','attestation_cnaps','rib','contrat'];
            foreach ($typesDoc as $typeDoc) {
                if (!empty($_FILES[$typeDoc]['name'])) {
                    $chemin = uploadFichier($_FILES[$typeDoc], 'documents', ['pdf','jpg','jpeg','png']);
                    if ($chemin) {
                        $db->prepare("INSERT INTO agent_documents (agent_id,type_document,nom_fichier,chemin,taille) VALUES (?,?,?,?,?)")
                           ->execute([$agentId, $typeDoc, $_FILES[$typeDoc]['name'], $chemin, $_FILES[$typeDoc]['size']]);
                    }
                }
            }

            // Champs personnalisés
            try {
                $stmtCP = $db->query("SELECT * FROM agent_champs_perso WHERE actif=1 ORDER BY ordre");
                foreach ($stmtCP->fetchAll() as $cp) {
                    if ($cp['type'] === 'file') {
                        if (!empty($_FILES['cp_'.$cp['id']]['name'])) {
                            $fic = uploadFichier($_FILES['cp_'.$cp['id']], 'documents', ['pdf','jpg','jpeg','png']);
                            if ($fic) $db->prepare("INSERT INTO agent_valeurs_perso (agent_id,champ_id,fichier) VALUES (?,?,?)")->execute([$agentId, $cp['id'], $fic]);
                        }
                    } else {
                        $val = trim($_POST['cp_'.$cp['id']] ?? '');
                        if ($val !== '') $db->prepare("INSERT INTO agent_valeurs_perso (agent_id,champ_id,valeur) VALUES (?,?,?)")->execute([$agentId, $cp['id'], $val]);
                    }
                }
            } catch(Exception $e) {}

            flash('success', 'Agent ' . $data['prenom'] . ' ' . $data['nom'] . ' créé avec le matricule <strong>' . $matricule . '</strong>');
            header('Location: view.php?id=' . $agentId);
            exit;
        }
    }
}

$pageTitle    = 'Ajouter un agent';
$currentModule = 'agents-add';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<div class="row g-3">

<!-- Colonne gauche -->
<div class="col-lg-8">

  <!-- Identité -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-user me-2" style="color:var(--ov-gold)"></i>Identité</h2></div>
    <div class="ov-card-body">
      <div class="form-section-title">Informations personnelles</div>
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Sexe <span class="text-danger">*</span></label>
          <select name="sexe" id="sexeSelect" class="form-select" required>
            <option value="M" <?= ($data['sexe']??'M')==='M'?'selected':'' ?>>Homme</option>
            <option value="F" <?= ($data['sexe']??'')==='F'?'selected':'' ?>>Femme</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Matricule</label>
          <input type="text" name="matricule" id="matriculeInput" class="form-control" value="<?= h($data['matricule']??'') ?>" placeholder="Auto-généré">
          <div class="form-text" id="matPreview" style="color:var(--ov-gold);font-weight:600;"></div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Nom <span class="text-danger">*</span></label>
          <input type="text" name="nom" class="form-control" value="<?= h($data['nom']??'') ?>" required>
        </div>
        <div class="col-md-3">
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
          <input type="text" name="num_secu" class="form-control" data-format="secu" value="<?= h($data['num_secu']??'') ?>" placeholder="1 77 11 99 380 141 84" maxlength="21">
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
      </div>

      <div class="form-section-title mt-4">Coordonnées</div>
      <div class="row g-3">
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
          <?php if ($postesGrille): ?>
          <select id="posteGrilleSelect" class="form-select form-select-sm mb-1" style="border-color:var(--ov-gold);background:rgba(201,168,76,0.04)">
            <option value="">— Sélectionner depuis la grille —</option>
            <?php foreach ($postesGrille as $pg): ?>
            <option value="<?= h($pg['id']) ?>"
                    data-label="<?= h($pg['label']) ?>"
                    data-taux="<?= h($pg['taux_horaire']) ?>"
            ><?= h($pg['label']) ?><?= $pg['coefficient'] ? ' (coef.'.$pg['coefficient'].')' : '' ?> — <?= number_format($pg['taux_horaire'],4) ?> €/h</option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <input type="text" name="poste" id="posteTexte" class="form-control" value="<?= h($data['poste']??'') ?>" placeholder="Ou saisir librement">
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
          <label class="form-label">Date fin contrat <small class="text-muted">(CDD)</small></label>
          <input type="date" name="date_fin_contrat" class="form-control" value="<?= h($data['date_fin_contrat']??'') ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Période d'essai</label>
          <input type="text" name="periode_essai" class="form-control" value="<?= h($data['periode_essai']??'') ?>" placeholder="Ex: 2 semaines">
        </div>
        <div class="col-md-6">
          <label class="form-label">Lieu de travail</label>
          <input type="text" name="lieu_travail" class="form-control" value="<?= h($data['lieu_travail']??'') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Motif d'embauche <small class="text-muted">(CDD)</small></label>
          <select name="motif_embauche" class="form-select">
            <option value="">—</option>
            <?php foreach (['Accroissement activité','Remplacement','Autres'] as $m): ?>
            <option value="<?= h($m) ?>" <?= ($data['motif_embauche']??'')===$m?'selected':'' ?>><?= h($m) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Rémunération</label>
          <div class="input-group">
            <input type="number" name="remuneration" class="form-control" step="0.01" value="<?= h($data['remuneration']??'') ?>">
            <span class="input-group-text">€</span>
          </div>
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
          <input type="text" name="temps_travail_hebdo" class="form-control" value="<?= h($data['temps_travail_hebdo']??'') ?>" placeholder="Ex: 35h / planning">
        </div>
      </div>

      <div class="form-section-title mt-4">Répartition horaire <small class="text-muted fw-normal">(si temps partiel)</small></div>
      <div class="row g-2">
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
          <label class="form-label">N° Autorisation CNAPS</label>
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
            <input class="form-check-input" type="checkbox" name="dpae" id="dpae" <?= isset($data['dpae'])?'checked':'' ?>>
            <label class="form-check-label" for="dpae">DPAE réalisée</label>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="contrat_realise" id="contrat_realise" <?= isset($data['contrat_realise'])?'checked':'' ?>>
            <label class="form-check-label" for="contrat_realise">Contrat réalisé</label>
          </div>
        </div>
        <div class="col-md-3">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="prelevement_auto" id="prelevement_auto" <?= isset($data['prelevement_auto'])?'checked':'' ?>>
            <label class="form-check-label" for="prelevement_auto">Prélèvement automatique</label>
          </div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Bulletins depuis</label>
          <input type="text" name="bulletins_depuis" class="form-control" value="<?= h($data['bulletins_depuis']??'') ?>" placeholder="Ex: juillet 2025">
        </div>
      </div>
    </div>
  </div>

</div>

<!-- Colonne droite -->
<div class="col-lg-4">

  <!-- Photo -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-camera me-2" style="color:var(--ov-gold)"></i>Photo</h2></div>
    <div class="ov-card-body text-center">
      <div class="mb-3">
        <img id="photoPreview" src="<?= APP_URL ?>/assets/img/avatar-default.png" class="rounded-circle" style="width:100px;height:100px;object-fit:cover;border:3px solid var(--ov-gold)" onerror="this.src='https://ui-avatars.com/api/?name=?&background=1a2332&color=c9a84c&size=100'">
      </div>
      <input type="file" name="photo" class="form-control form-control-sm" accept="image/*" onchange="previewPhoto(this,'photoPreview')">
    </div>
  </div>

  <!-- Documents -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-folder-open me-2" style="color:var(--ov-gold)"></i>Documents</h2></div>
    <div class="ov-card-body">
      <?php
      $docsLabels = [
        'piece_identite'      => 'Carte d\'identité',
        'titre_sejour'        => 'Carte de séjour',
        'carte_vitale'        => 'Carte vitale',
        'attestation_domicile'=> 'Attestation domicile',
        'attestation_cnaps'   => 'Attestation CNAPS',
        'rib'                 => 'RIB',
        'contrat'             => 'Contrat de travail',
      ];
      foreach ($docsLabels as $k => $label):
        $isIdentite = in_array($k, ['piece_identite','titre_sejour']);
      ?>
      <div class="mb-3" <?= $isIdentite ? 'id="doc-'.$k.'"' : '' ?>>
        <label class="form-label"><?= h($label) ?></label>
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
        <input class="form-check-input" type="checkbox" name="actif" id="actif" checked>
        <label class="form-check-label" for="actif">Agent actif</label>
      </div>
    </div>
  </div>

  <?php
  try {
      $champsPerso = $db->query("SELECT * FROM agent_champs_perso WHERE actif=1 ORDER BY ordre")->fetchAll();
  } catch(Exception $e) { $champsPerso = []; }
  if ($champsPerso):
  ?>
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-sliders me-2" style="color:var(--ov-gold)"></i>Champs personnalisés</h2></div>
    <div class="ov-card-body">
      <?php foreach ($champsPerso as $cp): ?>
      <div class="mb-3">
        <label class="form-label"><?= h($cp['label']) ?><?= $cp['obligatoire'] ? ' <span class="text-danger">*</span>' : '' ?></label>
        <?php if ($cp['type'] === 'textarea'): ?>
        <textarea name="cp_<?= $cp['id'] ?>" class="form-control form-control-sm" rows="3" <?= $cp['obligatoire']?'required':'' ?>><?= h($data['cp_'.$cp['id']]??'') ?></textarea>
        <?php elseif ($cp['type'] === 'date'): ?>
        <input type="date" name="cp_<?= $cp['id'] ?>" class="form-control form-control-sm" value="<?= h($data['cp_'.$cp['id']]??'') ?>" <?= $cp['obligatoire']?'required':'' ?>>
        <?php elseif ($cp['type'] === 'select'): ?>
        <?php $opts = $cp['options'] ? (json_decode($cp['options'], true) ?: []) : []; ?>
        <select name="cp_<?= $cp['id'] ?>" class="form-select form-select-sm" <?= $cp['obligatoire']?'required':'' ?>>
          <option value="">—</option>
          <?php foreach ($opts as $opt): ?><option value="<?= h($opt) ?>" <?= ($data['cp_'.$cp['id']]??'')===$opt?'selected':'' ?>><?= h($opt) ?></option><?php endforeach; ?>
        </select>
        <?php elseif ($cp['type'] === 'file'): ?>
        <input type="file" name="cp_<?= $cp['id'] ?>" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
        <?php else: ?>
        <input type="text" name="cp_<?= $cp['id'] ?>" class="form-control form-control-sm" value="<?= h($data['cp_'.$cp['id']]??'') ?>" <?= $cp['obligatoire']?'required':'' ?>>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="d-grid gap-2">
    <button type="submit" class="btn btn-ov-primary">
      <i class="fa fa-save me-2"></i>Enregistrer l'agent
    </button>
    <a href="index.php" class="btn btn-ov-secondary text-center">Annuler</a>
  </div>

</div>
</div>
</form>

<script>
// Poste depuis la grille → auto-remplit le libellé et la rémunération
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('posteGrilleSelect');
    if (!sel) return;
    sel.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        if (!opt.value) return;
        var posteField = document.getElementById('posteTexte');
        var remuField  = document.querySelector('[name="remuneration"]');
        if (posteField) posteField.value = opt.dataset.label || '';
        if (remuField && opt.dataset.taux) {
            remuField.value = parseFloat(opt.dataset.taux).toFixed(2);
            remuField.style.borderColor = 'var(--ov-gold)';
            remuField.style.background  = 'rgba(201,168,76,0.06)';
        }
    });
});

// Toggle carte d'identité / carte de séjour selon nationalité
function toggleIdentiteDoc() {
    var nat = (document.querySelector('[name="nationalite"]') || {value:''}).value.toLowerCase();
    var isFr = nat === '' || nat.includes('fran');
    var cni = document.getElementById('doc-piece_identite');
    var cs  = document.getElementById('doc-titre_sejour');
    if (cni) cni.style.display = isFr ? '' : 'none';
    if (cs)  cs.style.display  = isFr ? 'none' : '';
}
document.addEventListener('DOMContentLoaded', function() {
    toggleIdentiteDoc();
    var natInput = document.querySelector('[name="nationalite"]');
    if (natInput) natInput.addEventListener('input', toggleIdentiteDoc);
});

(function() {
    var sel = document.getElementById('sexeSelect');
    var inp = document.getElementById('matriculeInput');
    var prv = document.getElementById('matPreview');
    if (!sel || !prv) return;

    function fetchMatricule() {
        if (inp.value.trim() !== '') { prv.textContent = ''; return; }
        fetch('ajax_matricule.php?sexe=' + sel.value)
            .then(function(r){ return r.json(); })
            .then(function(d){ prv.textContent = 'Prochain : ' + d.matricule; })
            .catch(function(){});
    }

    sel.addEventListener('change', fetchMatricule);
    inp.addEventListener('input', fetchMatricule);
    fetchMatricule();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
