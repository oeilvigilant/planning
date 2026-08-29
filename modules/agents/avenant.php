<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
require_once __DIR__ . '/../../includes/contrat_builder.php';
requireLogin();
requirePerm('agents', 'view');
ensureAvenantSchema();

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { flash('danger','Agent introuvable.'); header('Location: index.php'); exit; }

$params = getAllParams();

// ── Routing ────────────────────────────────────────────────────────────────────
$avenantId = (int)($_GET['avenant_id'] ?? 0);
$action    = $_GET['action'] ?? '';
$isNew     = ($action === 'new');

$stCount = $db->prepare("SELECT COUNT(*) FROM avenants WHERE agent_id=?");
$stCount->execute([$id]);
$hasAvenants = (int)$stCount->fetchColumn() > 0;

// Dupliquer
if ($action === 'dupliquer' && $avenantId) {
    requirePerm('agents','edit');
    $stSrc = $db->prepare("SELECT * FROM avenants WHERE id=? AND agent_id=?");
    $stSrc->execute([$avenantId, $id]);
    $src = $stSrc->fetch();
    if ($src) {
        $nextNumero = $src['numero'];
        if ($src['type_document'] === 'avenant') {
            $stMax = $db->prepare("SELECT COUNT(*) FROM avenants WHERE agent_id=? AND type_document='avenant'");
            $stMax->execute([$id]);
            $nextNumero = (string)((int)$stMax->fetchColumn() + 1);
        }
        $db->prepare("INSERT INTO avenants (
            agent_id, type_document, titre_document, numero,
            civilite, nom_prenom, adresse, poste, type_contrat,
            date_contrat_reference, date_effet, corps_html,
            lieu_signature, date_signature
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $id, $src['type_document'], null, $nextNumero,
            $src['civilite'], $src['nom_prenom'], $src['adresse'], $src['poste'], $src['type_contrat'],
            $src['date_contrat_reference'], $src['date_effet'], $src['corps_html'],
            $src['lieu_signature'], date('d/m/Y'),
        ]);
        $newId = (int)$db->lastInsertId();
        flash('success', 'Document dupliqué.');
        header("Location: avenant.php?id=$id&avenant_id=$newId"); exit;
    }
    header("Location: avenant.php?id=$id"); exit;
}

// Supprimer
if ($action === 'supprimer' && $avenantId) {
    requirePerm('agents','edit');
    $stV = $db->prepare("SELECT signature FROM avenants WHERE id=? AND agent_id=?");
    $stV->execute([$avenantId, $id]);
    $vRow = $stV->fetch();
    if ($vRow && !empty($vRow['signature'])) {
        flash('danger', 'Impossible de supprimer un document déjà signé.');
    } else {
        $db->prepare("DELETE FROM avenants WHERE id=? AND agent_id=?")->execute([$avenantId, $id]);
        flash('success', 'Document supprimé.');
    }
    header("Location: avenant.php?id=$id"); exit;
}

// Si pas d'avenant_id, charger le plus récent, sinon basculer en mode "nouveau"
if (!$avenantId && !$isNew) {
    if (!$hasAvenants) {
        $isNew = true;
    } else {
        $stLast = $db->prepare("SELECT id FROM avenants WHERE agent_id=? ORDER BY created_at DESC, id DESC LIMIT 1");
        $stLast->execute([$id]);
        $avenantId = (int)($stLast->fetchColumn() ?: 0);
    }
}

// Charger le document courant
$v = [];
if ($avenantId) {
    $stV = $db->prepare("SELECT * FROM avenants WHERE id=? AND agent_id=?");
    $stV->execute([$avenantId, $id]);
    $v = $stV->fetch() ?: [];
    if (!$v && !$isNew) {
        flash('danger','Document introuvable.');
        header("Location: avenant.php?id=$id"); exit;
    }
}

// Tous les documents pour le sélecteur
$stAll = $db->prepare("SELECT id, type_document, titre_document, numero, signature, created_at FROM avenants WHERE agent_id=? ORDER BY created_at DESC, id DESC");
$stAll->execute([$id]);
$allAvenants = $stAll->fetchAll();

// ── POST : Signature électronique — enregistrement ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_signature'])) {
    $sigData = $_POST['signature_data'] ?? '';
    if ($avenantId && preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $sigData)) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $db->prepare("UPDATE avenants SET signature=?, signature_date=NOW(), signature_ip=? WHERE id=? AND agent_id=?")
           ->execute([$sigData, $ip, $avenantId, $id]);
        $db->prepare("INSERT INTO signatures_log (agent_id, avenant_id, contrat_hash, ip_address, user_agent) VALUES (?,?,?,?,?)")
           ->execute([$id, $avenantId, hash('sha256', $sigData . $id . date('Y-m-d')), $ip, $ua]);
        flash('success', 'Signature enregistrée — horodatage conservé.');
    } else {
        flash('danger', 'Données de signature invalides.');
    }
    header('Location: avenant.php?id=' . $id . '&avenant_id=' . $avenantId); exit;
}

// ── POST : Signature électronique — suppression ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_signature'])) {
    if ($avenantId) {
        $db->prepare("UPDATE avenants SET signature=NULL, signature_date=NULL, signature_ip=NULL WHERE id=? AND agent_id=?")->execute([$avenantId, $id]);
    }
    flash('success', 'Signature supprimée.');
    header('Location: avenant.php?id=' . $id . '&avenant_id=' . $avenantId); exit;
}

// ── POST : Envoyer un lien de signature par email ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['send_for_signature'])) {
    require_once __DIR__ . '/../../includes/mailer.php';
    $emailDest = trim($_POST['sig_email'] ?? $a['email'] ?? '');
    if (!$avenantId) {
        flash('danger', 'Sauvegardez le document avant de l’envoyer pour signature.');
    } elseif (!filter_var($emailDest, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'Adresse email invalide.');
    } else {
        $token     = bin2hex(random_bytes(32));
        $nbJours   = max(1, min(30, (int)($_POST['sig_expiry_jours'] ?? 7)));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $nbJours . ' days'));
        $snap      = json_decode($_POST['avenant_snapshot'] ?? '{}', true);
        $snapData  = is_array($snap) && !empty($snap) ? $snap : [];
        $dataSnap  = json_encode($snapData, JSON_UNESCAPED_UNICODE);
        $docType   = $snapData['type_document'] ?? ($v['type_document'] ?? 'avenant');
        $db->prepare("INSERT INTO signature_tokens (agent_id, avenant_id, token, email, contrat_data, expires_at) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $avenantId, $token, $emailDest, $dataSnap, $expiresAt]);
        $sigLink   = rtrim(APP_URL, '/') . '/token/signer.php?t=' . $token;
        $expiryFmt = date('d/m/Y à H:i', strtotime($expiresAt));
        $linkHtml  = '<div class="mt-2 p-2" style="background:#f8f9fa;border-radius:4px;word-break:break-all;font-size:12px"><strong>Lien à copier :</strong><br><a href="' . htmlspecialchars($sigLink) . '" target="_blank">' . htmlspecialchars($sigLink) . '</a></div>';
        $docLabel  = strtolower(avenantTypeLabel($docType, $snapData['numero'] ?? '1'));

        if (empty($params['smtp_host'])) {
            flash('warning', '<i class="fa fa-exclamation-triangle me-1"></i>SMTP non configuré — copiez ce lien et transmettez-le à l\'agent. <a href="' . APP_URL . '/modules/parametres/index.php?tab=email" class="alert-link">Configurer le SMTP</a>' . $linkHtml);
        } else {
            $result = sendMail($emailDest, trim($a['prenom'] . ' ' . strtoupper($a['nom'])),
                'Signature — ' . ($params['entreprise_nom'] ?? 'Oeil Vigilant'),
                buildSignatureEmailHtml($a, $params, $sigLink, $expiryFmt, $docLabel));
            if ($result['ok']) {
                flash('success', '<i class="fa fa-check-circle me-1"></i>Email envoyé à <strong>' . htmlspecialchars($emailDest) . '</strong>. Lien valide jusqu\'au ' . $expiryFmt . '.' . $linkHtml);
            } else {
                flash('danger', '<i class="fa fa-times-circle me-1"></i>Envoi impossible : ' . htmlspecialchars($result['error'] ?? 'erreur inconnue') . $linkHtml);
            }
        }
    }
    header('Location: avenant.php?id=' . $id . '&avenant_id=' . $avenantId); exit;
}

// ── POST : Regénérer un lien de signature ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['regenerate_token'])) {
    if ($avenantId) {
        $nbJours   = max(1, min(30, (int)($_POST['regen_expiry_jours'] ?? 7)));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $nbJours . ' days'));
        $token     = bin2hex(random_bytes(32));
        $snap      = json_decode($_POST['avenant_snapshot'] ?? '{}', true);
        $snapData  = is_array($snap) && !empty($snap) ? $snap : [];
        $dataSnap  = json_encode($snapData, JSON_UNESCAPED_UNICODE);
        $db->prepare("INSERT INTO signature_tokens (agent_id, avenant_id, token, email, contrat_data, expires_at) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $avenantId, $token, $a['email'] ?? '', $dataSnap, $expiresAt]);
        $sigLink   = rtrim(APP_URL, '/') . '/token/signer.php?t=' . $token;
        $expiryFmt = date('d/m/Y à H:i', strtotime($expiresAt));
        $linkHtml  = '<div class="mt-2 p-2" style="background:#f8f9fa;border-radius:4px;word-break:break-all;font-size:12px"><strong>Lien à copier :</strong><br><a href="' . htmlspecialchars($sigLink) . '" target="_blank">' . htmlspecialchars($sigLink) . '</a></div>';
        flash('success', '<i class="fa fa-link me-1"></i>Nouveau lien généré. Valide jusqu\'au ' . $expiryFmt . '.' . $linkHtml);
    }
    header('Location: avenant.php?id=' . $id . '&avenant_id=' . $avenantId); exit;
}

// ── POST : Sauvegarder le document ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_avenant'])) {
    requirePerm('agents','edit');
    $fields = [
        $_POST['type_document']          ?? 'avenant',
        trim($_POST['titre_document']    ?? '') ?: null,
        trim($_POST['numero']            ?? '1'),
        $_POST['civilite']               ?? 'M.',
        trim($_POST['nom_prenom']        ?? ''),
        trim($_POST['adresse']           ?? ''),
        trim($_POST['poste']             ?? ''),
        $_POST['type_contrat']           ?? 'CDD',
        trim($_POST['date_contrat_reference'] ?? ''),
        trim($_POST['date_effet']        ?? ''),
        $_POST['corps_html']             ?? '',
        trim($_POST['lieu_signature']    ?? ''),
        trim($_POST['date_signature']    ?? ''),
    ];
    if ($avenantId) {
        $fields[] = $avenantId;
        $fields[] = $id;
        $db->prepare("UPDATE avenants SET
            type_document=?, titre_document=?, numero=?,
            civilite=?, nom_prenom=?, adresse=?, poste=?, type_contrat=?,
            date_contrat_reference=?, date_effet=?, corps_html=?,
            lieu_signature=?, date_signature=?
            WHERE id=? AND agent_id=?")
        ->execute($fields);
    } else {
        $fields[] = $id;
        $db->prepare("INSERT INTO avenants (
            type_document, titre_document, numero,
            civilite, nom_prenom, adresse, poste, type_contrat,
            date_contrat_reference, date_effet, corps_html,
            lieu_signature, date_signature, agent_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute($fields);
        $avenantId = (int)$db->lastInsertId();
    }
    flash('success', 'Document sauvegardé.');
    header('Location: avenant.php?id=' . $id . '&avenant_id=' . $avenantId);
    exit;
}

// ── Construire $data pour le rendu ─────────────────────────────────────────────
$defaults  = buildAvenantDefaults($a, $v, $params);
$data      = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_merge($defaults, $_POST) : $defaults;
$exportPdf = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_pdf']) && $_POST['export_pdf'] === '1';

// La signature affichée dans le PDF est celle du document courant (pas la
// signature legacy de la fiche agent, qui appartient au contrat principal).
$aForPdf = $a;
$aForPdf['signature'] = $v['signature'] ?? null;

if ($exportPdf) {
    $html  = buildAvenantHtml($data, $params, $aForPdf);
    $fname = strtolower(str_replace(' ', '_', $data['type_document'])) . '_' . $data['numero'] . '_' . strtolower(str_replace(' ','_',$a['nom'])) . '.pdf';
    renderPdf($html, $fname);
}

$pageTitle    = ($v['titre_document'] ?? avenantTypeLabel($defaults['type_document'], $defaults['numero'])) . ' — ' . $a['prenom'] . ' ' . $a['nom'];
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';

function avenantLabel(array $v): string {
    $titre = $v['titre_document'] ?: avenantTypeLabel($v['type_document'] ?? 'avenant', $v['numero'] ?? '1');
    return $titre;
}
?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour fiche</a>
  <a href="contrat.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-file-contract me-1"></i>Contrat principal</a>

  <?php if (!$isNew && count($allAvenants) > 0): ?>
  <div class="dropdown">
    <button class="btn btn-sm dropdown-toggle" style="background:#f0f2f5;border:1px solid #e5e7eb;font-size:0.82rem" type="button" data-bs-toggle="dropdown">
      <i class="fa fa-file-pen me-1 text-warning"></i>
      <?= $v ? h(avenantLabel($v)) : 'Sélectionner' ?>
    </button>
    <ul class="dropdown-menu" style="min-width:280px">
      <?php foreach ($allAvenants as $av): ?>
      <li>
        <a class="dropdown-item d-flex align-items-center gap-2 <?= $av['id'] == $avenantId ? 'active' : '' ?>" href="avenant.php?id=<?= $id ?>&avenant_id=<?= $av['id'] ?>">
          <i class="fa fa-file-pen fa-fw" style="font-size:0.8rem"></i>
          <span style="font-size:0.82rem"><?= h(avenantLabel($av)) ?></span>
          <?php if (!empty($av['signature'])): ?><span class="badge bg-success ms-auto" style="font-size:0.6rem">Signé</span><?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-primary" href="avenant.php?id=<?= $id ?>&action=new"><i class="fa fa-plus me-2"></i>Nouveau document</a></li>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (!$isNew && $v): ?>
  <a href="avenant.php?id=<?= $id ?>&avenant_id=<?= $avenantId ?>&action=dupliquer"
     class="btn btn-sm" style="background:rgba(99,102,241,0.1);color:#3730a3;border:1px solid rgba(99,102,241,0.3)"
     title="Créer un nouveau document avec les mêmes paramètres">
    <i class="fa fa-copy me-1"></i>Dupliquer
  </a>
  <?php if (empty($v['signature'])): ?>
  <a href="avenant.php?id=<?= $id ?>&avenant_id=<?= $avenantId ?>&action=supprimer"
     class="btn btn-sm" style="background:rgba(239,68,68,0.08);color:#dc2626;border:1px solid rgba(239,68,68,0.2)"
     onclick="return confirm('Supprimer définitivement ce document ?')">
    <i class="fa fa-trash me-1"></i>
  </a>
  <?php endif; ?>
  <?php endif; ?>

  <a href="avenant.php?id=<?= $id ?>&action=new" class="btn btn-sm btn-ov-primary ms-auto">
    <i class="fa fa-plus me-1"></i>Nouveau document
  </a>
</div>

<?php if ($isNew): ?>
<div class="alert alert-info py-2 mb-3" style="font-size:0.85rem">
  <i class="fa fa-circle-info me-1"></i>
  <strong>Nouveau document</strong> — choisissez un type (avenant, convention de rupture, ou document libre), un modèle de départ est proposé mais tout le contenu reste modifiable.
</div>
<?php endif; ?>

<div class="row g-3">

<!-- Formulaire -->
<div class="col-lg-5">
  <form method="POST" id="avenantForm">
    <input type="hidden" name="export_pdf"    value="0" id="exportFlag">
    <input type="hidden" name="save_avenant"  value="0" id="saveFlag">
    <input type="hidden" name="corps_html"    id="corpsHtmlField" value="<?= h($data['corps_html']) ?>">

    <!-- Type de document -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-file-pen me-2" style="color:var(--ov-gold)"></i>Document</h2></div>
      <div class="ov-card-body">
        <div class="row g-2">
          <div class="col-7">
            <label class="form-label">Type de document</label>
            <select name="type_document" id="typeDocument" class="form-select form-select-sm" onchange="updatePreview()">
              <option value="avenant" <?= $data['type_document']==='avenant'?'selected':'' ?>>Avenant au contrat</option>
              <option value="rupture" <?= $data['type_document']==='rupture'?'selected':'' ?>>Convention de rupture conventionnelle</option>
              <option value="libre"   <?= $data['type_document']==='libre'  ?'selected':'' ?>>Document libre</option>
            </select>
          </div>
          <div class="col-5">
            <label class="form-label">N° / Référence</label>
            <input type="text" name="numero" class="form-control form-control-sm" value="<?= h($data['numero']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-12">
            <label class="form-label">Titre affiché <small class="text-muted">(en-tête du document)</small></label>
            <input type="text" name="titre_document" class="form-control form-control-sm" value="<?= h($data['titre_document']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Date de référence</label>
            <input type="text" name="date_contrat_reference" class="form-control form-control-sm" value="<?= h($data['date_contrat_reference']) ?>" placeholder="dd/mm/yyyy" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Date d'effet</label>
            <input type="text" name="date_effet" class="form-control form-control-sm" value="<?= h($data['date_effet']) ?>" placeholder="dd/mm/yyyy" oninput="updatePreview()">
          </div>
          <div class="col-12">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="loadTemplate()">
              <i class="fa fa-wand-magic-sparkles me-1"></i>Charger le modèle du type sélectionné
            </button>
            <div class="form-text">Remplace le contenu ci-dessous par un modèle de départ pour le type choisi. Vous pouvez ensuite tout modifier librement.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Parties -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-user me-2" style="color:var(--ov-gold)"></i>Parties</h2></div>
      <div class="ov-card-body">
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label">Civilité</label>
            <select name="civilite" class="form-select form-select-sm" onchange="updatePreview()">
              <option value="M." <?= $data['civilite']==='M.'?'selected':'' ?>>M.</option>
              <option value="Mme" <?= $data['civilite']==='Mme'?'selected':'' ?>>Mme</option>
            </select>
          </div>
          <div class="col-8">
            <label class="form-label">Nom Prénom</label>
            <input type="text" name="nom_prenom" class="form-control form-control-sm" value="<?= h($data['nom_prenom']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-12">
            <label class="form-label">Adresse</label>
            <input type="text" name="adresse" class="form-control form-control-sm" value="<?= h($data['adresse']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Type contrat</label>
            <select name="type_contrat" class="form-select form-select-sm" onchange="updatePreview()">
              <option value="CDD" <?= $data['type_contrat']==='CDD'?'selected':'' ?>>CDD</option>
              <option value="CDD Usage" <?= $data['type_contrat']==='CDD Usage'?'selected':'' ?>>CDD d'Usage</option>
              <option value="CDI" <?= $data['type_contrat']==='CDI'?'selected':'' ?>>CDI</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Poste actuel</label>
            <input type="text" name="poste" class="form-control form-control-sm" value="<?= h($data['poste']) ?>" oninput="updatePreview()">
          </div>
        </div>
      </div>
    </div>

    <!-- Contenu éditable -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-pen me-2" style="color:var(--ov-gold)"></i>Contenu du document</h2></div>
      <div class="ov-card-body">
        <div class="btn-toolbar mb-2 gap-1">
          <button type="button" class="btn btn-sm btn-outline-secondary" onmousedown="event.preventDefault()" onclick="fmt('bold')"><i class="fa fa-bold"></i></button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onmousedown="event.preventDefault()" onclick="fmt('italic')"><i class="fa fa-italic"></i></button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onmousedown="event.preventDefault()" onclick="fmt('underline')"><i class="fa fa-underline"></i></button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onmousedown="event.preventDefault()" onclick="fmt('insertUnorderedList')"><i class="fa fa-list-ul"></i></button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onmousedown="event.preventDefault()" onclick="fmt('insertOrderedList')"><i class="fa fa-list-ol"></i></button>
        </div>
        <div id="corpsEditable" contenteditable="true"
             style="min-height:280px;max-height:420px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:6px;padding:12px;font-size:0.87rem;background:#fff"
             oninput="syncCorps(); updatePreview()"><?= $data['corps_html'] ?></div>
        <div class="form-text">Ce contenu est entièrement libre — articles, clauses, texte de convention de rupture, etc.</div>
      </div>
    </div>

    <!-- Signature -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-pen-to-square me-2" style="color:var(--ov-gold)"></i>Lieu et date</h2></div>
      <div class="ov-card-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Lieu</label>
            <input type="text" name="lieu_signature" class="form-control form-control-sm" value="<?= h($data['lieu_signature']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Date</label>
            <input type="text" name="date_signature" class="form-control form-control-sm" value="<?= h($data['date_signature']) ?>" oninput="updatePreview()">
          </div>
        </div>
      </div>
    </div>

    <div class="d-grid gap-2">
      <button type="button" onclick="saveAvenant()" class="btn" style="background:rgba(16,185,129,0.1);color:#065f46;border:1px solid rgba(16,185,129,0.4);font-weight:500">
        <i class="fa fa-floppy-disk me-2"></i>Sauvegarder
      </button>
      <button type="button" onclick="exportPdf()" class="btn btn-ov-primary">
        <i class="fa fa-file-pdf me-2"></i>Générer & Télécharger le PDF
      </button>
      <?php if (!empty($v['signature'])): ?>
      <button type="button" onclick="exportPdf()" class="btn btn-success">
        <i class="fa fa-file-pdf me-2"></i>Télécharger le document <strong>signé</strong>
        <span class="badge bg-white text-success ms-1" style="font-size:0.7rem">✓ Signé le <?= date('d/m/Y', strtotime($v['signature_date'])) ?></span>
      </button>
      <?php endif; ?>
      <button type="button" onclick="document.getElementById('avenantPreview').contentWindow.print()" class="btn btn-ov-secondary">
        <i class="fa fa-print me-2"></i>Imprimer l'aperçu
      </button>
    </div>
  </form>

  <!-- ── Signature électronique ──────────────────────────────────────────────── -->
  <?php if (!$isNew && $avenantId): ?>
  <div class="ov-card mt-3">
    <div class="ov-card-header">
      <h2 class="ov-card-title"><i class="fa fa-pen-nib me-2" style="color:var(--ov-gold)"></i>Signature électronique du salarié</h2>
    </div>
    <div class="ov-card-body">

      <?php if (!empty($v['signature'])): ?>
      <div class="d-flex align-items-center gap-3 p-2 mb-3" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px">
        <img src="<?= h($v['signature']) ?>" style="height:54px;max-width:140px;background:#fff;border:1px solid #d1fae5;border-radius:4px;padding:3px;object-fit:contain">
        <div class="small">
          <div class="fw-semibold text-success"><i class="fa fa-check-circle me-1"></i>Signature enregistrée</div>
          <?php if ($v['signature_date']): ?>
          <div class="text-muted"><?= date('d/m/Y à H:i', strtotime($v['signature_date'])) ?></div>
          <div class="text-muted">IP : <?= h($v['signature_ip'] ?? '—') ?></div>
          <?php endif; ?>
        </div>
        <form method="post" class="ms-auto" onsubmit="return confirm('Supprimer définitivement la signature ?')">
          <input type="hidden" name="delete_signature" value="1">
          <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="fa fa-trash"></i></button>
        </form>
      </div>
      <p class="text-muted small mb-2">Pour re-signer, effacez la signature ci-dessus puis tracez et enregistrez.</p>
      <?php else: ?>
      <p class="text-muted small mb-2">Le salarié signe ici (souris ou écran tactile). La signature est horodatée.</p>
      <?php endif; ?>

      <div style="border:2px dashed #d1d5db;border-radius:6px;background:#fafafa;position:relative;user-select:none">
        <canvas id="sigCanvas" width="600" height="150" style="width:100%;height:150px;display:block;touch-action:none;cursor:crosshair;border-radius:4px"></canvas>
        <span id="sigPlaceholder" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#d1d5db;font-size:14px;pointer-events:none;white-space:nowrap">✍ Signez ici</span>
      </div>

      <div class="d-flex gap-2 mt-2 align-items-center">
        <button type="button" onclick="clearSig()" class="btn btn-sm btn-outline-secondary"><i class="fa fa-eraser me-1"></i>Effacer</button>
        <button type="button" onclick="saveSig()" class="btn btn-sm btn-ov-primary ms-auto"><i class="fa fa-check me-1"></i>Enregistrer la signature</button>
      </div>

      <form method="post" id="sigForm">
        <input type="hidden" name="save_signature" value="1">
        <input type="hidden" name="signature_data" id="sigData">
      </form>

      <p class="text-muted mt-2 mb-0" style="font-size:10px">
        <i class="fa fa-shield-halved me-1 text-success"></i>Signature électronique simple — règlement eIDAS (UE 910/2014).
      </p>

      <hr class="my-3">

      <h6 class="mb-2"><i class="fa fa-envelope me-2 text-warning"></i>Envoyer pour signature par email</h6>
      <?php
        $lastTok = false;
        if ($avenantId > 0) {
            $lastToken = $db->prepare("SELECT * FROM signature_tokens WHERE agent_id=? AND avenant_id=? ORDER BY sent_at DESC LIMIT 1");
            $lastToken->execute([$id, $avenantId]);
            $lastTok = $lastToken->fetch();
        }
      ?>
      <?php if ($lastTok): ?>
      <div class="mb-2 p-2 rounded small <?= $lastTok['signed_at'] ? 'bg-success bg-opacity-10 border border-success border-opacity-25' : (strtotime($lastTok['expires_at']) < time() ? 'bg-secondary bg-opacity-10' : 'bg-warning bg-opacity-10 border border-warning border-opacity-25') ?>">
        <?php if ($lastTok['signed_at']): ?>
          <i class="fa fa-check-circle text-success me-1"></i>
          <strong>Signé</strong> le <?= date('d/m/Y à H:i', strtotime($lastTok['signed_at'])) ?> — envoyé à <?= h($lastTok['email'] ?: '(sans email)') ?>
        <?php elseif (strtotime($lastTok['expires_at']) < time()): ?>
          <i class="fa fa-clock text-muted me-1"></i>
          <strong>Lien expiré</strong> — envoyé à <?= h($lastTok['email']) ?> le <?= date('d/m/Y', strtotime($lastTok['sent_at'])) ?>
        <?php else: ?>
          <i class="fa fa-hourglass-half text-warning me-1"></i>
          <strong>En attente</strong> — envoyé à <?= h($lastTok['email']) ?> le <?= date('d/m/Y à H:i', strtotime($lastTok['sent_at'])) ?>,
          expire le <?= date('d/m/Y à H:i', strtotime($lastTok['expires_at'])) ?>
          <button type="button" class="btn btn-xs btn-outline-secondary ms-2 py-0 px-1" style="font-size:10px"
            onclick="navigator.clipboard.writeText('<?= rtrim(APP_URL,'/') ?>/token/signer.php?t=<?= h($lastTok['token']) ?>').then(()=>this.textContent='✓ Copié!')">
            <i class="fa fa-copy"></i> Copier lien
          </button>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <form method="post" class="mb-2" id="regenForm">
        <input type="hidden" name="regenerate_token" value="1">
        <input type="hidden" name="avenant_snapshot" id="regenSnapshot">
        <div class="row g-2 align-items-end">
          <div class="col-auto">
            <label class="form-label mb-1 small">Validité</label>
            <div class="input-group input-group-sm" style="width:90px">
              <input type="number" name="regen_expiry_jours" class="form-control" value="7" min="1" max="30">
              <span class="input-group-text">j</span>
            </div>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-secondary" style="white-space:nowrap">
              <i class="fa fa-rotate me-1"></i>Regénérer le lien
            </button>
          </div>
        </div>
      </form>

      <form method="post" id="sendForm">
        <input type="hidden" name="send_for_signature" value="1">
        <input type="hidden" name="avenant_snapshot" id="sendSnapshot">
        <div class="row g-2 align-items-end">
          <div class="col">
            <label class="form-label mb-1 small">Email du salarié</label>
            <input type="email" name="sig_email" class="form-control form-control-sm" value="<?= h($a['email'] ?? '') ?>" placeholder="prenom.nom@email.com" required>
          </div>
          <div class="col-auto">
            <label class="form-label mb-1 small">Validité</label>
            <div class="input-group input-group-sm" style="width:100px">
              <input type="number" name="sig_expiry_jours" class="form-control" value="7" min="1" max="30">
              <span class="input-group-text">j</span>
            </div>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-sm" style="background:#1a2332;color:#c9a84c;border-color:#1a2332;white-space:nowrap">
              <i class="fa fa-paper-plane me-1"></i>Envoyer par email
            </button>
          </div>
        </div>
      </form>

    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Aperçu -->
<div class="col-lg-7">
  <div class="ov-card" style="position:sticky;top:70px">
    <div class="ov-card-header">
      <h2 class="ov-card-title"><i class="fa fa-eye me-2" style="color:var(--ov-gold)"></i>Aperçu</h2>
      <span style="font-size:0.75rem;color:#9ca3af">Mis à jour en temps réel</span>
    </div>
    <div class="ov-card-body p-0" style="max-height:calc(100vh - 180px);overflow-y:auto">
      <iframe id="avenantPreview" style="width:100%;height:calc(100vh - 200px);border:none" srcdoc=""></iframe>
    </div>
  </div>
</div>

</div><!-- /row -->

<script>
function fmt(cmd) {
    document.getElementById('corpsEditable').focus();
    document.execCommand(cmd, false, null);
    syncCorps();
    updatePreview();
}

function syncCorps() {
    document.getElementById('corpsHtmlField').value = document.getElementById('corpsEditable').innerHTML;
}

function loadTemplate() {
    var type = document.getElementById('typeDocument').value;
    if (!confirm('Remplacer le contenu actuel par le modèle de ce type de document ?')) return;
    fetch('avenant_template.php?type=' + encodeURIComponent(type))
        .then(function(r) { return r.text(); })
        .then(function(html) {
            document.getElementById('corpsEditable').innerHTML = html;
            syncCorps();
            updatePreview();
        });
}

function getFormData() {
    syncCorps();
    const form = document.getElementById('avenantForm');
    const fd   = new FormData(form);
    const obj  = {};
    for (let [k,v] of fd.entries()) { obj[k] = v; }
    return obj;
}

function updatePreview() {
    const d = getFormData();
    fetch('avenant_preview.php?id=<?= $id ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(d)
    }).then(r => r.text()).then(html => {
        document.getElementById('avenantPreview').srcdoc = html;
    });
}

function saveAvenant() {
    syncCorps();
    document.getElementById('saveFlag').value = '1';
    document.getElementById('avenantForm').submit();
}

function exportPdf() {
    syncCorps();
    document.getElementById('exportFlag').value = '1';
    document.getElementById('avenantForm').submit();
}

document.addEventListener('DOMContentLoaded', function() {
    updatePreview();
    var regenForm = document.getElementById('regenForm');
    var sendForm  = document.getElementById('sendForm');
    if (regenForm) regenForm.addEventListener('submit', function() {
        document.getElementById('regenSnapshot').value = JSON.stringify(getFormData());
    });
    if (sendForm) sendForm.addEventListener('submit', function() {
        document.getElementById('sendSnapshot').value = JSON.stringify(getFormData());
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.2.0/dist/signature_pad.umd.min.js"></script>
<script>
var _sigPad = null;

function initSigPad() {
    var canvas = document.getElementById('sigCanvas');
    if (!canvas) return;
    function setCanvasSize() {
        var ratio = window.devicePixelRatio || 1;
        var w = canvas.offsetWidth, h = canvas.offsetHeight;
        canvas.width  = w * ratio;
        canvas.height = h * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        if (_sigPad) _sigPad.clear();
    }
    setCanvasSize();
    window.addEventListener('resize', setCanvasSize);
    _sigPad = new SignaturePad(canvas, { backgroundColor:'rgba(255,255,255,0)', penColor:'#1a2332', minWidth:1, maxWidth:2.5 });
    canvas.addEventListener('mousedown', hidePlaceholder);
    canvas.addEventListener('touchstart', hidePlaceholder);
}
function hidePlaceholder() {
    var p = document.getElementById('sigPlaceholder');
    if (p) p.style.display = 'none';
}
function clearSig() {
    if (_sigPad) _sigPad.clear();
    var p = document.getElementById('sigPlaceholder');
    if (p) p.style.display = '';
}
function saveSig() {
    if (!_sigPad || _sigPad.isEmpty()) { alert('Veuillez tracer votre signature avant d\'enregistrer.'); return; }
    document.getElementById('sigData').value = _sigPad.toDataURL('image/png');
    document.getElementById('sigForm').submit();
}
document.addEventListener('DOMContentLoaded', initSigPad);
</script>

<style>
@media print {
    #sidebar, #topbar, .col-lg-5, .ov-card-header { display:none!important; }
    #main-content { margin:0; padding:0; }
    iframe { width:100%; height:auto; border:none; }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
