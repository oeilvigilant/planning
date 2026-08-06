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

// Documents déjà uploadés pour cet agent
$existingDocs = [];
$docRows = $db->prepare("SELECT type_document, nom_fichier, chemin FROM agent_documents WHERE agent_id = ?");
$docRows->execute([$agent['id']]);
foreach ($docRows->fetchAll() as $d) {
    $existingDocs[$d['type_document']] = $d;
}

// contrat et titre_sejour gérés séparément dans la boucle ci-dessous
$typesDoc = ['piece_identite','carte_vitale','attestation_domicile','attestation_cnaps','rib'];

$errors  = [];
$success = false;
$data    = $agent;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Les valeurs de l'agent servent de fallback pour les champs désactivés (selects)
    $data = array_merge($agent, $_POST);

    $champsTexte = [
        'date_naissance' => 'La date de naissance est obligatoire.',
        'lieu_naissance' => 'Le lieu de naissance est obligatoire.',
        'nationalite'    => 'La nationalité est obligatoire.',
        'num_secu'       => 'Le numéro de sécurité sociale est obligatoire.',
        'adresse'        => 'L\'adresse est obligatoire.',
        'cp'             => 'Le code postal est obligatoire.',
        'ville'          => 'La ville est obligatoire.',
        'num_autorisation_cnaps'  => 'Le numéro d\'autorisation CNAPS est obligatoire.',
        'date_autorisation_cnaps' => 'La date d\'autorisation CNAPS est obligatoire.',
        'date_expiration_cnaps'   => 'La date d\'expiration CNAPS est obligatoire.',
    ];
    foreach ($champsTexte as $champ => $msg) {
        if (empty(trim($data[$champ] ?? ''))) $errors[] = $msg;
    }

    $documentsObligatoires = [
        'attestation_cnaps'     => 'l\'attestation CNAPS',
        'attestation_domicile'  => 'le justificatif de domicile',
        'carte_vitale'          => 'la carte vitale',
        'rib'                   => 'le RIB',
    ];
    $extDocsAutorisees = ['pdf','jpg','jpeg','png'];
    foreach ($documentsObligatoires as $champ => $label) {
        $dejaFourni = isset($existingDocs[$champ]);
        if (!$dejaFourni && empty($_FILES[$champ]['name'])) {
            $errors[] = ucfirst($label) . ' est obligatoire.';
        } elseif (!$dejaFourni) {
            $ext = strtolower(pathinfo($_FILES[$champ]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $extDocsAutorisees)) {
                $errors[] = 'Format invalide pour ' . $label . ' (pdf, jpg, png acceptés).';
            }
        }
    }
    $hasIdentite = isset($existingDocs['piece_identite']) || isset($existingDocs['titre_sejour']);
    if (!$hasIdentite) {
        if (empty($_FILES['piece_identite']['name'])) {
            $errors[] = 'La pièce d\'identité ou le titre de séjour est obligatoire.';
        } else {
            $ext = strtolower(pathinfo($_FILES['piece_identite']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $extDocsAutorisees)) {
                $errors[] = 'Format invalide pour la pièce d\'identité ou le titre de séjour (pdf, jpg, png acceptés).';
            }
        }
    }

    if (!$errors) {
    $db->prepare("
        UPDATE agents SET
        date_naissance=?,lieu_naissance=?,nationalite=?,num_secu=?,
        situation_familiale=?,nb_enfants=?,adresse=?,cp=?,ville=?,telephone=?,email=?,
        num_autorisation_cnaps=?,date_autorisation_cnaps=?,date_expiration_cnaps=?,
        token_used=1
        WHERE id=?
    ")->execute([
        $data['date_naissance']    ?: null, $data['lieu_naissance'] ?: null,
        $data['nationalite']       ?: null, $data['num_secu']       ?: null,
        $data['situation_familiale']?: null, (int)($data['nb_enfants'] ?? 0),
        $data['adresse']  ?: null, $data['cp']    ?: null, $data['ville'] ?: null,
        $data['telephone']?: null, $data['email'] ?: null,
        $data['num_autorisation_cnaps']  ?: null,
        $data['date_autorisation_cnaps'] ?: null,
        $data['date_expiration_cnaps']   ?: null,
        $agent['id'],
    ]);

    // Photo
    if (!empty($_FILES['photo']['name'])) {
        $photo = uploadFichier($_FILES['photo'], 'photos', ['jpg','jpeg','png']);
        if ($photo) $db->prepare("UPDATE agents SET photo=? WHERE id=?")->execute([$photo, $agent['id']]);
    }

    // Documents standards — uniquement les types non encore fournis
    foreach ($typesDoc as $typeDoc) {
        if (!isset($existingDocs[$typeDoc]) && !empty($_FILES[$typeDoc]['name'])) {
            $chemin = uploadFichier($_FILES[$typeDoc], 'documents', ['pdf','jpg','jpeg','png']);
            if ($chemin) {
                $db->prepare("INSERT INTO agent_documents (agent_id,type_document,nom_fichier,chemin,taille) VALUES (?,?,?,?,?)")
                   ->execute([$agent['id'], $typeDoc, $_FILES[$typeDoc]['name'], $chemin, $_FILES[$typeDoc]['size']]);
            }
        }
    }

    // Pièce d'identité OU titre de séjour (champ fusionné)
    if (!$hasIdentite && !empty($_FILES['piece_identite']['name'])) {
        $chemin = uploadFichier($_FILES['piece_identite'], 'documents', ['pdf','jpg','jpeg','png']);
        if ($chemin) {
            $db->prepare("INSERT INTO agent_documents (agent_id,type_document,nom_fichier,chemin,taille) VALUES (?,?,?,?,?)")
               ->execute([$agent['id'], 'piece_identite', $_FILES['piece_identite']['name'], $chemin, $_FILES['piece_identite']['size']]);
        }
    }

    // Autres documents libres (slots 1 et 2)
    for ($i = 1; $i <= 2; $i++) {
        if (!empty($_FILES['autre_' . $i]['name'])) {
            $chemin = uploadFichier($_FILES['autre_' . $i], 'documents', ['pdf','jpg','jpeg','png']);
            if ($chemin) {
                $label = trim($_POST['autre_label_' . $i] ?? '') ?: 'Document supplémentaire';
                $db->prepare("INSERT INTO agent_documents (agent_id,type_document,nom_fichier,chemin,taille) VALUES (?,?,?,?,?)")
                   ->execute([$agent['id'], 'autre', $label . ' — ' . $_FILES['autre_' . $i]['name'], $chemin, $_FILES['autre_' . $i]['size']]);
            }
        }
    }

    $success = true;
    }
}

$params = getAllParams();

// Helper : attributs readonly si la valeur est déjà renseignée
$ro = fn($v) => !empty(trim((string)$v))
    ? ' readonly style="background:#f3f4f6;color:#6b7280;cursor:not-allowed"'
    : '';
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
.field-done { font-size:0.7rem; color:#16a34a; font-weight:600; margin-left:6px; }
.doc-done { display:flex; align-items:center; gap:8px; padding:8px 10px; background:#f0fdf4; border:1px solid #bbf7d0; border-radius:6px; font-size:0.82rem; }
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
        Bonjour <strong><?= h($agent['prenom'].' '.$agent['nom']) ?></strong>, veuillez compléter les informations manquantes ci-dessous.
        Les champs <span style="background:#f3f4f6;color:#6b7280;padding:1px 6px;border-radius:4px;font-size:0.8rem">grisés</span> sont déjà renseignés. Ce lien est à usage unique.
      </div>

      <div class="alert alert-danger py-2" id="formErrorSummary" style="font-size:0.85rem<?= $errors ? '' : ';display:none' ?>">
        <ul class="mb-0" id="formErrorList"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
      </div>

      <form method="POST" enctype="multipart/form-data" id="agentForm" novalidate>

        <div class="section-title">Informations personnelles</div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Date de naissance <span class="text-danger">*</span><?= !empty($agent['date_naissance']) ? '<span class="field-done"><i class="fa fa-check"></i> Déjà renseigné</span>' : '' ?></label>
            <input type="date" name="date_naissance" class="form-control" value="<?= h($data['date_naissance']??'') ?>"<?= $ro($agent['date_naissance']??'') ?> required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Lieu de naissance <span class="text-danger">*</span><?= !empty($agent['lieu_naissance']) ? '<span class="field-done"><i class="fa fa-check"></i> Déjà renseigné</span>' : '' ?></label>
            <input type="text" name="lieu_naissance" class="form-control" value="<?= h($data['lieu_naissance']??'') ?>"<?= $ro($agent['lieu_naissance']??'') ?> required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nationalité <span class="text-danger">*</span><?= !empty($agent['nationalite']) ? '<span class="field-done"><i class="fa fa-check"></i> Déjà renseigné</span>' : '' ?></label>
            <input type="text" name="nationalite" class="form-control" value="<?= h($data['nationalite']??'') ?>"<?= $ro($agent['nationalite']??'') ?> required>
          </div>
          <div class="col-md-6">
            <label class="form-label">N° Sécurité Sociale <span class="text-danger">*</span><?= !empty($agent['num_secu']) ? '<span class="field-done"><i class="fa fa-check"></i> Déjà renseigné</span>' : '' ?></label>
            <input type="text" name="num_secu" class="form-control" data-format="secu" value="<?= h($data['num_secu']??'') ?>"<?= $ro($agent['num_secu']??'') ?> required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Situation familiale<?= !empty($agent['situation_familiale']) ? '<span class="field-done"><i class="fa fa-check"></i> Déjà renseigné</span>' : '' ?></label>
            <?php $sfFilled = !empty($agent['situation_familiale']); ?>
            <select name="situation_familiale" class="form-select"<?= $sfFilled ? ' disabled style="background:#f3f4f6;color:#6b7280"' : '' ?>>
              <option value="">—</option>
              <?php foreach (['Célibataire','Marié(e)','Divorcé(e)','Veuf(ve)','PACS'] as $sf): ?>
              <option value="<?= h($sf) ?>" <?= $agent['situation_familiale']===$sf?'selected':'' ?>><?= h($sf) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nombre d'enfants</label>
            <input type="number" name="nb_enfants" class="form-control" min="0" value="<?= h($agent['nb_enfants']??0) ?>">
          </div>
        </div>

        <div class="section-title">Coordonnées</div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Adresse <span class="text-danger">*</span><?= !empty($agent['adresse']) ? '<span class="field-done"><i class="fa fa-check"></i> Déjà renseigné</span>' : '' ?></label>
            <input type="text" name="adresse" class="form-control" value="<?= h($data['adresse']??'') ?>"<?= $ro($agent['adresse']??'') ?> required>
          </div>
          <div class="col-md-3">
            <label class="form-label">Code postal <span class="text-danger">*</span><?= !empty($agent['cp']) ? '<span class="field-done"><i class="fa fa-check"></i></span>' : '' ?></label>
            <input type="text" name="cp" class="form-control" value="<?= h($data['cp']??'') ?>"<?= $ro($agent['cp']??'') ?> required>
          </div>
          <div class="col-md-5">
            <label class="form-label">Ville <span class="text-danger">*</span><?= !empty($agent['ville']) ? '<span class="field-done"><i class="fa fa-check"></i></span>' : '' ?></label>
            <input type="text" name="ville" class="form-control" value="<?= h($data['ville']??'') ?>"<?= $ro($agent['ville']??'') ?> required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Téléphone<?= !empty($agent['telephone']) ? '<span class="field-done"><i class="fa fa-check"></i></span>' : '' ?></label>
            <input type="tel" name="telephone" class="form-control" value="<?= h($agent['telephone']??'') ?>"<?= $ro($agent['telephone']??'') ?>>
          </div>
          <div class="col-md-6">
            <label class="form-label">Email<?= !empty($agent['email']) ? '<span class="field-done"><i class="fa fa-check"></i></span>' : '' ?></label>
            <input type="email" name="email" class="form-control" value="<?= h($agent['email']??'') ?>"<?= $ro($agent['email']??'') ?>>
          </div>
        </div>

        <div class="section-title">Autorisation CNAPS</div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">N° Autorisation CNAPS <span class="text-danger">*</span><?= !empty($agent['num_autorisation_cnaps']) ? '<span class="field-done"><i class="fa fa-check"></i></span>' : '' ?></label>
            <input type="text" name="num_autorisation_cnaps" class="form-control" value="<?= h($data['num_autorisation_cnaps']??'') ?>"<?= $ro($agent['num_autorisation_cnaps']??'') ?> required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date d'autorisation <span class="text-danger">*</span><?= !empty($agent['date_autorisation_cnaps']) ? '<span class="field-done"><i class="fa fa-check"></i></span>' : '' ?></label>
            <input type="date" name="date_autorisation_cnaps" class="form-control" value="<?= h($data['date_autorisation_cnaps']??'') ?>"<?= $ro($agent['date_autorisation_cnaps']??'') ?> required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date d'expiration <span class="text-danger">*</span><?= !empty($agent['date_expiration_cnaps']) ? '<span class="field-done"><i class="fa fa-check"></i></span>' : '' ?></label>
            <input type="date" name="date_expiration_cnaps" class="form-control" value="<?= h($data['date_expiration_cnaps']??'') ?>"<?= $ro($agent['date_expiration_cnaps']??'') ?> required>
          </div>
        </div>

        <div class="section-title">Photo & Documents</div>
        <div class="row g-3">

          <!-- Photo -->
          <div class="col-12">
            <label class="form-label">Photo</label>
            <?php if (!empty($agent['photo'])): ?>
            <div class="doc-done mb-1">
              <img src="<?= UPLOAD_URL ?>/<?= h($agent['photo']) ?>" style="height:36px;width:36px;object-fit:cover;border-radius:50%;border:2px solid #bbf7d0">
              <span style="color:#15803d"><i class="fa fa-check-circle me-1"></i>Photo déjà enregistrée</span>
              <span style="color:#6b7280;font-size:0.75rem">— vous pouvez en envoyer une nouvelle ci-dessous</span>
            </div>
            <?php endif; ?>
            <input type="file" name="photo" class="form-control" accept="image/*">
          </div>

          <?php
          // ── Pièce d'identité OU titre de séjour (champ fusionné) ──────────────
          $existIdentite = $existingDocs['piece_identite'] ?? $existingDocs['titre_sejour'] ?? null;
          ?>
          <div class="col-md-6">
            <label class="form-label"><i class="fa fa-id-card me-1 text-muted"></i>Pièce d'identité <span style="color:#6b7280;font-weight:400">ou</span> titre de séjour <?= $existIdentite ? '' : '<span class="text-danger">*</span>' ?></label>
            <?php if ($existIdentite): ?>
              <div class="doc-done">
                <i class="fa fa-check-circle text-success"></i>
                <span style="color:#15803d;font-weight:500">Déjà fourni</span>
                <a href="<?= UPLOAD_URL ?>/<?= h($existIdentite['chemin']) ?>" target="_blank" class="ms-auto" style="font-size:0.75rem;color:#2563eb"><i class="fa fa-eye me-1"></i><?= h($existIdentite['nom_fichier']) ?></a>
              </div>
            <?php else: ?>
              <input type="file" name="piece_identite" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
            <?php endif; ?>
          </div>

          <?php
          // ── Documents standards (sans pièce d'identité/titre séjour/contrat) ──
          $docsLabels = [
            'carte_vitale'         => ['Carte vitale',                    'fa-heart-pulse'],
            'attestation_domicile' => ['Attestation de domicile',         'fa-house'],
            'attestation_cnaps'    => ['Attestation CNAPS',               'fa-shield-halved'],
            'rib'                  => ['RIB (relevé d\'identité bancaire)','fa-building-columns'],
          ];
          foreach ($docsLabels as $k => [$label, $icon]):
            $existing = $existingDocs[$k] ?? null;
          ?>
          <div class="col-md-6">
            <label class="form-label"><i class="fa <?= $icon ?> me-1 text-muted"></i><?= h($label) ?> <?= $existing ? '' : '<span class="text-danger">*</span>' ?></label>
            <?php if ($existing): ?>
              <div class="doc-done">
                <i class="fa fa-check-circle text-success"></i>
                <span style="color:#15803d;font-weight:500">Déjà fourni</span>
                <a href="<?= UPLOAD_URL ?>/<?= h($existing['chemin']) ?>" target="_blank" class="ms-auto" style="font-size:0.75rem;color:#2563eb"><i class="fa fa-eye me-1"></i><?= h($existing['nom_fichier']) ?></a>
              </div>
            <?php else: ?>
              <input type="file" name="<?= $k ?>" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>

          <?php
          // ── Autres documents ─────────────────────────────────────────────────
          $autresDocs = array_filter($existingDocs, fn($d) => true, ARRAY_FILTER_USE_KEY);
          $nbAutres = count(array_filter($existingDocs, fn($k) => $k === 'autre', ARRAY_FILTER_USE_KEY));
          ?>
          <div class="col-12 mt-2">
            <div style="border-top:1px dashed #d1d5db;padding-top:1rem;margin-top:0.25rem">
              <div class="form-label mb-2" style="color:#374151;font-weight:600"><i class="fa fa-paperclip me-1 text-muted"></i>Autres documents <span style="color:#6b7280;font-weight:400;font-size:0.8rem">(optionnel — diplôme, justificatif, etc.)</span></div>
              <?php for ($i = 1; $i <= 2; $i++): ?>
              <div class="row g-2 mb-2">
                <div class="col-md-5">
                  <input type="text" name="autre_label_<?= $i ?>" class="form-control form-control-sm" placeholder="Nom du document (ex : Diplôme)" maxlength="80">
                </div>
                <div class="col-md-7">
                  <input type="file" name="autre_<?= $i ?>" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png">
                </div>
              </div>
              <?php endfor; ?>
            </div>
          </div>

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
<?php if (!$success): ?>
<script>
(function() {
    var form    = document.getElementById('agentForm');
    var summary = document.getElementById('formErrorSummary');
    var listEl  = document.getElementById('formErrorList');

    function fieldLabel(field) {
        var wrapper = field.closest('[class*="col-"]');
        var label = wrapper ? wrapper.querySelector('.form-label') : null;
        return label ? label.textContent.replace('*', '').replace('Déjà renseigné', '').trim() : (field.name || 'Champ');
    }

    form.addEventListener('submit', function(e) {
        var missing = [];
        var firstInvalid = null;
        form.querySelectorAll('[required]').forEach(function(f) {
            f.classList.remove('is-invalid');
            if (!f.value || !f.value.trim()) {
                missing.push(fieldLabel(f));
                f.classList.add('is-invalid');
                if (!firstInvalid) firstInvalid = f;
            }
        });

        if (missing.length > 0) {
            e.preventDefault();
            listEl.innerHTML = missing.map(function(m) { return '<li>Le champ « ' + m + ' » est obligatoire.</li>'; }).join('');
            summary.style.display = 'block';
            summary.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (firstInvalid) firstInvalid.focus();
        }
    });
})();
</script>
<?php endif; ?>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
