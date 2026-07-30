<?php
if (!defined('APP_ROOT')) exit;

/**
 * Formulaire commun d'auto-inscription candidat (canal nominatif ET libre) —
 * partagé pour ne jamais avoir deux copies du même champ/upload qui divergent.
 */

function inscriptionHoneypotField(): string {
    // Nom anodin, hors-écran (pas display:none — certains bots l'ignorent), un humain ne le voit ni ne le tabule jamais.
    return '<div style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true">
        <label for="site_web">Site web</label>
        <input type="text" id="site_web" name="site_web" tabindex="-1" autocomplete="off">
    </div>';
}

function renderInscriptionForm(array $data, array $errors, array $params): void {
    $ro = fn($v) => (string)($data[$v] ?? '');
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Candidature — <?= h($params['entreprise_nom'] ?? 'Oeil Vigilant') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
body { background:#f0f2f5; font-family:'Segoe UI',sans-serif; }
.form-header { background:linear-gradient(135deg,#0d1520,#1a2332); color:white; padding:2rem; border-radius:12px 12px 0 0; }
.form-header h1 { font-size:1.3rem; }
.section-title { color:#c9a84c; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:1px; border-bottom:1px solid rgba(201,168,76,0.2); padding-bottom:0.5rem; margin:1.5rem 0 1rem; }
.form-label { font-weight:500; font-size:0.85rem; color:#374151; }
.email-mismatch { color:#dc2626; font-size:0.78rem; margin-top:4px; display:none; }
</style>
</head>
<body>
<div class="container py-4" style="max-width:700px">
  <div class="card border-0 shadow-sm" style="border-radius:12px;overflow:hidden">
    <div class="form-header">
      <div class="d-flex align-items-center gap-3">
        <img src="<?= APP_URL ?>/assets/img/logo.png" style="height:40px;filter:brightness(0) invert(1)" alt="Logo">
        <div>
          <h1 class="mb-0"><?= h($params['entreprise_nom'] ?? 'Oeil Vigilant') ?></h1>
          <p class="opacity-60 mb-0" style="font-size:0.8rem">Formulaire de candidature</p>
        </div>
      </div>
    </div>
    <div class="card-body p-4">
      <div class="alert alert-info py-2" style="font-size:0.85rem">
        <i class="fa fa-info-circle me-2"></i>
        Merci de votre intérêt ! Complétez ce formulaire pour candidater — votre dossier sera examiné par notre équipe dans les meilleurs délais.
      </div>

      <div class="alert alert-danger py-2" id="formErrorSummary" style="font-size:0.85rem<?= $errors ? '' : ';display:none' ?>">
        <ul class="mb-0" id="formErrorList"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
      </div>

      <form method="POST" enctype="multipart/form-data" id="inscriptionForm" novalidate>
        <?= inscriptionHoneypotField() ?>

        <div class="section-title">Identité</div>
        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label">Civilité</label>
            <select name="sexe" class="form-select">
              <option value="M" <?= $ro('sexe')!=='F'?'selected':'' ?>>M.</option>
              <option value="F" <?= $ro('sexe')==='F'?'selected':'' ?>>Mme</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Nom <span class="text-danger">*</span></label>
            <input type="text" name="nom" class="form-control" value="<?= h($ro('nom')) ?>" required>
          </div>
          <div class="col-md-5">
            <label class="form-label">Prénom <span class="text-danger">*</span></label>
            <input type="text" name="prenom" class="form-control" value="<?= h($ro('prenom')) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date de naissance</label>
            <input type="date" name="date_naissance" class="form-control" value="<?= h($ro('date_naissance')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Lieu de naissance</label>
            <input type="text" name="lieu_naissance" class="form-control" value="<?= h($ro('lieu_naissance')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Nationalité</label>
            <input type="text" name="nationalite" class="form-control" value="<?= h($ro('nationalite')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">N° Sécurité Sociale</label>
            <input type="text" name="num_secu" class="form-control" data-format="secu" value="<?= h($ro('num_secu')) ?>" maxlength="21">
          </div>
          <div class="col-md-3">
            <label class="form-label">Situation familiale</label>
            <select name="situation_familiale" class="form-select">
              <option value="">—</option>
              <?php foreach (['Célibataire','Marié(e)','Divorcé(e)','Veuf(ve)','PACS'] as $sf): ?>
              <option value="<?= h($sf) ?>" <?= $ro('situation_familiale')===$sf?'selected':'' ?>><?= h($sf) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label">Nb. enfants</label>
            <input type="number" name="nb_enfants" class="form-control" min="0" value="<?= h($ro('nb_enfants') ?: 0) ?>">
          </div>
        </div>

        <div class="section-title">Coordonnées</div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Adresse</label>
            <input type="text" name="adresse" class="form-control" value="<?= h($ro('adresse')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label">Code postal</label>
            <input type="text" name="cp" class="form-control" value="<?= h($ro('cp')) ?>">
          </div>
          <div class="col-md-5">
            <label class="form-label">Ville</label>
            <input type="text" name="ville" class="form-control" value="<?= h($ro('ville')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">Téléphone</label>
            <input type="tel" name="telephone" class="form-control" value="<?= h($ro('telephone')) ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Email <span class="text-danger">*</span></label>
            <input type="email" id="emailInput" name="email" class="form-control" value="<?= h($ro('email')) ?>" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Confirmez votre email <span class="text-danger">*</span></label>
            <input type="email" id="emailConfirmInput" name="email_confirmation" class="form-control" value="<?= h($ro('email_confirmation')) ?>" required>
            <div class="email-mismatch" id="emailMismatch">Les deux adresses email ne correspondent pas.</div>
          </div>
        </div>

        <div class="section-title">Autorisation CNAPS</div>
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">N° Autorisation CNAPS <span class="text-danger">*</span></label>
            <input type="text" name="num_autorisation_cnaps" class="form-control" value="<?= h($ro('num_autorisation_cnaps')) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date d'autorisation <span class="text-danger">*</span></label>
            <input type="date" name="date_autorisation_cnaps" class="form-control" value="<?= h($ro('date_autorisation_cnaps')) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Date d'expiration <span class="text-danger">*</span></label>
            <input type="date" name="date_expiration_cnaps" class="form-control" value="<?= h($ro('date_expiration_cnaps')) ?>" required>
          </div>
        </div>

        <div class="section-title">Photo & Documents <span style="color:#9ca3af;font-weight:400;text-transform:none;letter-spacing:0">(optionnel à ce stade)</span></div>
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">Photo</label>
            <input type="file" name="photo" class="form-control" accept="image/*">
          </div>
          <div class="col-md-6">
            <label class="form-label"><i class="fa fa-id-card me-1 text-muted"></i>Pièce d'identité <span style="color:#6b7280;font-weight:400">ou</span> titre de séjour</label>
            <input type="file" name="piece_identite" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
          </div>
          <?php
          $docsLabels = [
            'carte_vitale'         => ['Carte vitale',                    'fa-heart-pulse'],
            'attestation_domicile' => ['Attestation de domicile',         'fa-house'],
            'attestation_cnaps'    => ['Attestation CNAPS',               'fa-shield-halved'],
            'rib'                  => ['RIB (relevé d\'identité bancaire)','fa-building-columns'],
          ];
          foreach ($docsLabels as $k => [$label, $icon]): ?>
          <div class="col-md-6">
            <label class="form-label"><i class="fa <?= $icon ?> me-1 text-muted"></i><?= h($label) ?></label>
            <input type="file" name="<?= $k ?>" class="form-control" accept=".pdf,.jpg,.jpeg,.png">
          </div>
          <?php endforeach; ?>
        </div>

        <div class="mt-4 d-grid">
          <button type="submit" class="btn btn-lg" style="background:linear-gradient(135deg,#c9a84c,#a8883c);color:#1a2332;font-weight:700">
            <i class="fa fa-paper-plane me-2"></i>Envoyer ma candidature
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function() {
    var email    = document.getElementById('emailInput');
    var confirm  = document.getElementById('emailConfirmInput');
    var warn     = document.getElementById('emailMismatch');
    var form     = document.getElementById('inscriptionForm');
    var summary  = document.getElementById('formErrorSummary');
    var listEl   = document.getElementById('formErrorList');

    function emailsMatch() {
        var mismatch = confirm.value !== '' && email.value !== confirm.value;
        warn.style.display = mismatch ? 'block' : 'none';
        return !mismatch;
    }

    function fieldLabel(field) {
        var wrapper = field.closest('[class*="col-"]');
        var label = wrapper ? wrapper.querySelector('.form-label') : null;
        return label ? label.textContent.replace('*', '').trim() : (field.name || 'Champ');
    }

    function validateRequiredFields() {
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
        return { missing: missing, firstInvalid: firstInvalid };
    }

    email.addEventListener('input', emailsMatch);
    confirm.addEventListener('input', emailsMatch);

    form.addEventListener('submit', function(e) {
        var result = validateRequiredFields();
        var emailOk = emailsMatch();
        var messages = result.missing.map(function(m) { return 'Le champ « ' + m + ' » est obligatoire.'; });
        if (!emailOk) messages.push('Les deux adresses email ne correspondent pas.');

        if (messages.length > 0) {
            e.preventDefault();
            listEl.innerHTML = messages.map(function(m) { return '<li>' + m + '</li>'; }).join('');
            summary.style.display = 'block';
            summary.scrollIntoView({ behavior: 'smooth', block: 'center' });
            var focusTarget = result.firstInvalid || (!emailOk ? confirm : null);
            if (focusTarget) focusTarget.focus();
        }
    });
})();
</script>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
<?php
}

function renderInscriptionSuccessPage(array $params): void {
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Candidature envoyée — <?= h($params['entreprise_nom'] ?? 'Oeil Vigilant') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>body { background:#f0f2f5; font-family:'Segoe UI',sans-serif; }</style>
</head>
<body>
<div class="container py-5" style="max-width:600px">
  <div class="card border-0 shadow-sm text-center" style="border-radius:12px">
    <div class="card-body p-5">
      <i class="fa fa-circle-check fa-4x text-success mb-3"></i>
      <h1 style="font-size:1.3rem">Candidature envoyée !</h1>
      <p class="text-muted">Votre dossier a bien été reçu et sera examiné par notre équipe dans les meilleurs délais. Merci de votre intérêt pour <?= h($params['entreprise_nom'] ?? 'Oeil Vigilant') ?>.</p>
    </div>
  </div>
</div>
</body>
</html>
<?php
}

function renderInscriptionInvalidPage(): void {
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Lien expiré</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head><body class="bg-dark text-white d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="text-center p-4">
    <i class="fa fa-triangle-exclamation fa-3x text-warning mb-3"></i>
    <h4>Lien invalide ou expiré</h4>
    <p class="text-muted">Ce lien a déjà été utilisé ou a expiré. Contactez-nous pour en obtenir un nouveau.</p>
</div>
</body></html>
<?php
}

/**
 * Traite la soumission du formulaire d'auto-inscription (canal nominatif ou libre).
 * Retourne ['ok'=>bool, 'silent'=>bool, 'errors'=>array, 'agent_id'=>?int]
 * 'silent' = honeypot rempli ou rate-limit dépassé : on affiche quand même la
 * page de succès générique côté candidat, sans rien écrire ni notifier.
 */
function handleInscriptionSubmit(PDO $db, array $post, array $files, ?array $invitation, string $ip, bool $rateLimitExceeded): array {
    // 1. Honeypot — un bot remplit ce champ, un humain ne le voit jamais
    if (trim($post['site_web'] ?? '') !== '') {
        return ['ok' => true, 'silent' => true, 'errors' => [], 'agent_id' => null];
    }

    // 3. Rate-limit (lien libre uniquement, calculé par l'appelant)
    if ($rateLimitExceeded) {
        return ['ok' => true, 'silent' => true, 'errors' => [], 'agent_id' => null];
    }

    $errors = [];
    if (empty(trim($post['nom'] ?? '')))    $errors[] = 'Le nom est obligatoire.';
    if (empty(trim($post['prenom'] ?? ''))) $errors[] = 'Le prénom est obligatoire.';
    $email       = trim($post['email'] ?? '');
    $emailConfirm = trim($post['email_confirmation'] ?? '');
    if (empty($email)) {
        $errors[] = 'L\'email est obligatoire.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'L\'adresse email n\'est pas valide.';
    } elseif ($email !== $emailConfirm) {
        $errors[] = 'Les deux adresses email ne correspondent pas.';
    }
    if (empty(trim($post['num_autorisation_cnaps'] ?? '')))   $errors[] = 'Le numéro d\'autorisation CNAPS est obligatoire.';
    if (empty(trim($post['date_autorisation_cnaps'] ?? '')))  $errors[] = 'La date d\'autorisation CNAPS est obligatoire.';
    if (empty(trim($post['date_expiration_cnaps'] ?? '')))    $errors[] = 'La date d\'expiration CNAPS est obligatoire.';

    $photo = null;
    if (!empty($files['photo']['name'])) {
        $photo = uploadFichier($files['photo'], 'photos', ['jpg','jpeg','png','webp']);
        if (!$photo) $errors[] = 'Format de photo invalide (jpg, png acceptés).';
    }

    if ($errors) {
        return ['ok' => false, 'silent' => false, 'errors' => $errors, 'agent_id' => null];
    }

    $sexe      = in_array($post['sexe'] ?? '', ['M','F']) ? $post['sexe'] : 'M';
    $matricule = generateMatricule($sexe);

    $stmt = $db->prepare("
        INSERT INTO agents
        (matricule,nom,prenom,sexe,date_naissance,lieu_naissance,nationalite,num_secu,
         situation_familiale,nb_enfants,photo,adresse,cp,ville,telephone,email,
         num_autorisation_cnaps,date_autorisation_cnaps,date_expiration_cnaps,
         actif,statut_inscription,ip_inscription,created_by)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,'en_attente',?,NULL)
    ");
    $stmt->execute([
        $matricule, trim($post['nom']), trim($post['prenom']), $sexe,
        ($post['date_naissance'] ?? '') ?: null, ($post['lieu_naissance'] ?? '') ?: null,
        ($post['nationalite'] ?? '') ?: null, ($post['num_secu'] ?? '') ?: null,
        ($post['situation_familiale'] ?? '') ?: null, (int)($post['nb_enfants'] ?? 0),
        $photo,
        ($post['adresse'] ?? '') ?: null, ($post['cp'] ?? '') ?: null, ($post['ville'] ?? '') ?: null,
        ($post['telephone'] ?? '') ?: null, $email,
        ($post['num_autorisation_cnaps'] ?? '') ?: null,
        ($post['date_autorisation_cnaps'] ?? '') ?: null, ($post['date_expiration_cnaps'] ?? '') ?: null,
        $ip,
    ]);
    $agentId = (int)$db->lastInsertId();

    // Documents standards
    $typesDoc = ['carte_vitale','attestation_domicile','attestation_cnaps','rib'];
    foreach ($typesDoc as $typeDoc) {
        if (!empty($files[$typeDoc]['name'])) {
            $chemin = uploadFichier($files[$typeDoc], 'documents', ['pdf','jpg','jpeg','png']);
            if ($chemin) {
                $db->prepare("INSERT INTO agent_documents (agent_id,type_document,nom_fichier,chemin,taille) VALUES (?,?,?,?,?)")
                   ->execute([$agentId, $typeDoc, $files[$typeDoc]['name'], $chemin, $files[$typeDoc]['size']]);
            }
        }
    }
    // Pièce d'identité (uploadée comme "piece_identite" par défaut ; distinction avec titre de séjour faite plus tard par l'admin si besoin)
    if (!empty($files['piece_identite']['name'])) {
        $chemin = uploadFichier($files['piece_identite'], 'documents', ['pdf','jpg','jpeg','png']);
        if ($chemin) {
            $db->prepare("INSERT INTO agent_documents (agent_id,type_document,nom_fichier,chemin,taille) VALUES (?,?,?,?,?)")
               ->execute([$agentId, 'piece_identite', $files['piece_identite']['name'], $chemin, $files['piece_identite']['size']]);
        }
    }

    // Marquer l'invitation nominative utilisée le cas échéant
    if ($invitation) {
        $db->prepare("UPDATE invitations_recrutement SET used=1, agent_id_cree=? WHERE id=?")
           ->execute([$agentId, $invitation['id']]);
    }

    // Notification admin immédiate
    try {
        require_once APP_ROOT . '/includes/mailer.php';
        $params = getAllParams();
        $notifEmail = $params['notification_signature_email'] ?? $params['smtp_from'] ?? $params['entreprise_email'] ?? '';
        if ($notifEmail && !empty($params['smtp_host'])) {
            $nom = trim($post['prenom'] . ' ' . strtoupper($post['nom']));
            // Doublon éventuel — purement informatif pour l'admin, aucun impact côté candidat
            $dup = $db->prepare("SELECT matricule, actif FROM agents WHERE email = ? AND id != ? LIMIT 1");
            $dup->execute([$email, $agentId]);
            $existing = $dup->fetch();
            $dupWarning = $existing
                ? '<p style="margin:8px 0 0;color:#92400e;font-size:0.85rem"><i class="fa fa-triangle-exclamation"></i> Un agent avec cet email existe déjà (matricule ' . htmlspecialchars($existing['matricule'] ?? '?') . ', ' . ($existing['actif'] ? 'actif' : 'inactif') . ').</p>'
                : '';
            sendMail($notifEmail, $params['entreprise_nom'] ?? 'OV-Gestion',
                '📋 Nouvelle candidature — ' . $nom,
                '<div style="font-family:Arial,sans-serif;max-width:500px">
                  <div style="background:#1a2332;padding:16px 24px;border-radius:8px 8px 0 0">
                    <span style="color:#c9a84c;font-weight:700;font-size:1rem">📋 Nouvelle candidature reçue</span>
                  </div>
                  <div style="border:1px solid #e5e7eb;border-top:none;padding:20px 24px;border-radius:0 0 8px 8px">
                    <p style="margin:0 0 12px;font-size:1.1rem;font-weight:700;color:#1a2332">' . htmlspecialchars($nom) . '</p>
                    <p style="margin:0 0 12px;color:#6b7280;font-size:0.85rem">Email : ' . htmlspecialchars($email) . '</p>
                    ' . $dupWarning . '
                    <a href="' . APP_URL . '/modules/agents/inscriptions.php" style="display:inline-block;margin-top:12px;background:#c9a84c;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:600;font-size:0.875rem">Voir les inscriptions en attente →</a>
                  </div>
                </div>');
        }
    } catch (Exception $e) {}

    return ['ok' => true, 'silent' => false, 'errors' => [], 'agent_id' => $agentId];
}
