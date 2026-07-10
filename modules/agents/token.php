<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'create');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { flash('danger','Agent introuvable'); header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token   = generateToken();
    $expires = date('Y-m-d H:i:s', strtotime('+' . getParam('token_expiration_jours','7') . ' days'));
    $db->prepare("UPDATE agents SET token_acces=?, token_used=0, token_expires_at=? WHERE id=?")
       ->execute([$token, $expires, $id]);
    $a['token_acces']     = $token;
    $a['token_used']      = 0;
    $a['token_expires_at']= $expires;
    flash('success', 'Nouveau lien généré. Valable ' . getParam('token_expiration_jours','7') . ' jours.');
}

$pageTitle    = 'Lien auto-remplissage agent';
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';

$tokenUrl = $a['token_acces'] ? APP_URL . '/token/formulaire.php?t=' . $a['token_acces'] : null;

// Calcul des éléments manquants pour le corps de l'email
$docsStmt = $db->prepare("SELECT type_document, date_expiration FROM agent_documents WHERE agent_id=?");
$docsStmt->execute([$id]);
$docRows     = $docsStmt->fetchAll();
$docTypes    = array_column($docRows, 'type_document');
$ignoredKeys = json_decode($a['alertes_ignorees'] ?? '[]', true) ?: [];
$completion  = agentCompletion($a, $docTypes, $docRows, $ignoredKeys);

// Construire le corps de l'email en texte brut
function buildEmailBody(array $a, string $tokenUrl, array $completion): string {
    $prenom = $a['prenom'] ?? '';
    $lines  = [];
    $lines[] = "Bonjour " . $prenom . ",";
    $lines[] = "";
    $lines[] = "Afin de compléter votre dossier, nous vous invitons à mettre à jour vos informations en cliquant sur le lien ci-dessous :";
    $lines[] = $tokenUrl;
    $lines[] = "";

    $allAlerts = array_merge($completion['errors'], $completion['warnings']);
    if ($allAlerts) {
        $lines[] = "Voici les éléments manquants ou à mettre à jour dans votre dossier :";
        $lines[] = "";
        $bycat = [];
        foreach ($allAlerts as $alert) { $bycat[$alert['cat']][] = $alert['label']; }
        foreach ($bycat as $cat => $items) {
            $lines[] = "▸ " . $cat . " :";
            foreach ($items as $item) { $lines[] = "   - " . $item; }
        }
        $lines[] = "";
    }

    $lines[] = "Ce lien est à usage unique et expire dans " . getParam('token_expiration_jours','7') . " jours.";
    $lines[] = "";
    $lines[] = "Merci de votre collaboration.";
    $lines[] = "L'équipe Oeil Vigilant";
    return implode("\r\n", $lines);
}
?>

<div class="row justify-content-center">
<div class="col-lg-7">
  <div class="ov-card">
    <div class="ov-card-header">
      <h2 class="ov-card-title"><i class="fa fa-link me-2" style="color:var(--ov-gold)"></i>Lien d'auto-remplissage</h2>
    </div>
    <div class="ov-card-body">
      <p class="text-muted mb-4" style="font-size:0.875rem">
        Générez un lien unique à envoyer à l'agent <strong><?= h($a['prenom'].' '.$a['nom']) ?></strong> pour qu'il remplisse lui-même ses informations et upload ses documents. Chaque lien est à usage unique et expire automatiquement.
      </p>

      <?php if ($tokenUrl): ?>
      <div class="p-3 rounded mb-4" style="background:#f0f7ff;border:1px solid #bee3ff">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="fw-600" style="font-size:0.85rem"><i class="fa fa-link me-1 text-primary"></i>Lien actuel</span>
          <span class="badge" style="background:<?= $a['token_used'] ? 'rgba(107,114,128,0.1)' : 'rgba(34,197,94,0.1)' ?>;color:<?= $a['token_used'] ? '#6b7280' : '#16a34a' ?>;border-radius:20px;font-size:0.72rem;padding:3px 10px">
            <?= $a['token_used'] ? 'Utilisé' : 'Actif' ?>
          </span>
        </div>
        <div class="input-group">
          <input type="text" class="form-control form-control-sm" id="tokenUrl" value="<?= h($tokenUrl) ?>" readonly>
          <button class="btn btn-sm btn-ov-primary" onclick="navigator.clipboard.writeText(document.getElementById('tokenUrl').value);this.innerHTML='<i class=\'fa fa-check\'></i> Copié!'">
            <i class="fa fa-copy"></i> Copier
          </button>
        </div>
        <?php if ($a['token_expires_at']): ?>
        <div class="mt-2" style="font-size:0.75rem;color:#6b7280">
          <i class="fa fa-clock me-1"></i>Expire le <?= date('d/m/Y à H:i', strtotime($a['token_expires_at'])) ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- QR Code optionnel (simple lien) -->
      <div class="text-center mb-4">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($tokenUrl) ?>" alt="QR Code" style="border:8px solid white;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)">
        <div class="text-muted mt-2" style="font-size:0.75rem">QR Code pour l'agent</div>
      </div>
      <?php endif; ?>

      <form method="POST" class="d-grid">
        <button type="submit" class="btn btn-ov-primary">
          <i class="fa fa-rotate me-2"></i><?= $tokenUrl ? 'Régénérer un nouveau lien' : 'Générer le lien' ?>
        </button>
      </form>

      <?php if ($a['email'] && $tokenUrl): ?>
      <?php
        $emailSubject = 'Mise à jour de votre dossier — Oeil Vigilant';
        $emailBody    = buildEmailBody($a, $tokenUrl, $completion);
        $mailtoUrl    = 'mailto:'.rawurlencode($a['email']).'?subject='.rawurlencode($emailSubject).'&body='.rawurlencode($emailBody);
      ?>
      <div class="mt-3">
        <a href="<?= h($mailtoUrl) ?>" class="btn btn-ov-secondary btn-sm w-100">
          <i class="fa fa-envelope me-1"></i>Envoyer par email à <?= h($a['email']) ?>
        </a>
        <!-- Prévisualisation du message -->
        <div class="mt-3 p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;font-size:0.78rem;white-space:pre-wrap;font-family:monospace;color:#374151;max-height:260px;overflow-y:auto">
<?= h($emailBody) ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="mt-4 pt-3" style="border-top:1px solid #f0f2f5">
        <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour à la fiche</a>
      </div>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
