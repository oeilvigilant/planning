<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'create');
ensureInscriptionSchema();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token   = generateToken();
    $email   = trim($_POST['email'] ?? '');
    $nomAff  = trim($_POST['nom_affichage'] ?? '');
    $expires = date('Y-m-d H:i:s', strtotime('+' . getParam('token_expiration_jours','7') . ' days'));
    $stmt = $db->prepare("INSERT INTO invitations_recrutement (token, email, expires_at, created_by) VALUES (?,?,?,?)");
    $stmt->execute([$token, $email ?: null, $expires, getCurrentUser()['id']]);
    $newId = (int)$db->lastInsertId();
    flash('success', 'Lien d\'invitation généré' . ($nomAff ? ' pour ' . h($nomAff) : '') . '.');
    header('Location: invitation_recrutement.php?id=' . $newId . ($nomAff ? '&nom=' . urlencode($nomAff) : ''));
    exit;
}

$current = null;
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $stC = $db->prepare("SELECT * FROM invitations_recrutement WHERE id=?");
    $stC->execute([$id]);
    $current = $stC->fetch() ?: null;
}
$nomAffichage = trim($_GET['nom'] ?? '');

$pageTitle    = 'Inviter un candidat';
$currentModule = 'agents-inscriptions';
require_once __DIR__ . '/../../includes/header.php';

$tokenUrl = $current ? APP_URL . '/token/inscription.php?t=' . $current['token'] : null;

function buildInvitationEmailBody(string $tokenUrl, string $nomAffichage, string $joursValidite): string {
    $lines = [];
    $lines[] = $nomAffichage !== '' ? "Bonjour " . $nomAffichage . "," : "Bonjour,";
    $lines[] = "";
    $lines[] = "Vous êtes invité(e) à déposer votre candidature chez Oeil Vigilant en cliquant sur le lien ci-dessous :";
    $lines[] = $tokenUrl;
    $lines[] = "";
    $lines[] = "Ce lien est à usage unique et expire dans " . $joursValidite . " jours.";
    $lines[] = "";
    $lines[] = "Merci de votre intérêt.";
    $lines[] = "L'équipe Oeil Vigilant";
    return implode("\r\n", $lines);
}
?>

<div class="row justify-content-center">
<div class="col-lg-7">
  <div class="ov-card">
    <div class="ov-card-header">
      <h2 class="ov-card-title"><i class="fa fa-user-plus me-2" style="color:var(--ov-gold)"></i>Inviter un candidat</h2>
    </div>
    <div class="ov-card-body">
      <p class="text-muted mb-4" style="font-size:0.875rem">
        Générez un lien unique à envoyer à un candidat pour qu'il dépose lui-même sa candidature. Elle apparaîtra dans
        <a href="inscriptions.php">Inscriptions en attente</a> pour validation — rien n'est créé automatiquement dans vos agents actifs.
      </p>

      <form method="POST" class="row g-2 mb-4">
        <div class="col-md-6">
          <label class="form-label">Nom du candidat <small class="text-muted">(affichage uniquement)</small></label>
          <input type="text" name="nom_affichage" class="form-control form-control-sm" placeholder="Ex : Jean Dupont">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email du candidat <small class="text-muted">(optionnel)</small></label>
          <input type="email" name="email" class="form-control form-control-sm" placeholder="candidat@exemple.fr">
        </div>
        <div class="col-12 mt-2">
          <button type="submit" class="btn btn-ov-primary btn-sm"><i class="fa fa-link me-1"></i>Générer le lien</button>
        </div>
      </form>

      <?php if ($tokenUrl): ?>
      <div class="p-3 rounded mb-4" style="background:#f0f7ff;border:1px solid #bee3ff">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <span class="fw-600" style="font-size:0.85rem"><i class="fa fa-link me-1 text-primary"></i>Lien généré<?= $nomAffichage ? ' pour ' . h($nomAffichage) : '' ?></span>
          <span class="badge" style="background:<?= $current['used'] ? 'rgba(107,114,128,0.1)' : 'rgba(34,197,94,0.1)' ?>;color:<?= $current['used'] ? '#6b7280' : '#16a34a' ?>;border-radius:20px;font-size:0.72rem;padding:3px 10px">
            <?= $current['used'] ? 'Utilisé' : 'Actif' ?>
          </span>
        </div>
        <div class="input-group">
          <input type="text" class="form-control form-control-sm" id="tokenUrl" value="<?= h($tokenUrl) ?>" readonly>
          <button class="btn btn-sm btn-ov-primary" onclick="navigator.clipboard.writeText(document.getElementById('tokenUrl').value);this.innerHTML='<i class=\'fa fa-check\'></i> Copié!'">
            <i class="fa fa-copy"></i> Copier
          </button>
        </div>
        <div class="mt-2" style="font-size:0.75rem;color:#6b7280">
          <i class="fa fa-clock me-1"></i>Expire le <?= date('d/m/Y à H:i', strtotime($current['expires_at'])) ?>
        </div>
      </div>

      <div class="text-center mb-4">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=<?= urlencode($tokenUrl) ?>" alt="QR Code" style="border:8px solid white;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1)">
        <div class="text-muted mt-2" style="font-size:0.75rem">QR Code pour le candidat</div>
      </div>

      <?php
        $emailSubject = 'Invitation à candidater — Oeil Vigilant';
        $emailBody    = buildInvitationEmailBody($tokenUrl, $nomAffichage, getParam('token_expiration_jours','7'));
      ?>
      <?php if (!empty($current['email'])): ?>
      <?php $mailtoUrl = 'mailto:'.rawurlencode($current['email']).'?subject='.rawurlencode($emailSubject).'&body='.rawurlencode($emailBody); ?>
      <a href="<?= h($mailtoUrl) ?>" class="btn btn-ov-secondary btn-sm w-100 mb-2">
        <i class="fa fa-envelope me-1"></i>Envoyer par email à <?= h($current['email']) ?>
      </a>
      <?php endif; ?>
      <div style="font-size:0.75rem;font-weight:600;color:#6b7280;margin-bottom:4px;text-transform:uppercase;letter-spacing:0.5px">
        Message à envoyer
        <button type="button" onclick="navigator.clipboard.writeText(document.getElementById('emailBodyTxt').innerText);this.innerHTML='<i class=\'fa fa-check\'></i> Copié!';setTimeout(()=>this.innerHTML='<i class=\'fa fa-copy\'></i> Copier',2000)"
                class="btn btn-sm ms-2" style="font-size:0.7rem;padding:1px 8px;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc;color:#6b7280">
          <i class="fa fa-copy"></i> Copier
        </button>
      </div>
      <div id="emailBodyTxt" class="p-3 rounded" style="background:#f8fafc;border:1px solid #e2e8f0;font-size:0.78rem;white-space:pre-wrap;font-family:monospace;color:#374151;max-height:280px;overflow-y:auto"><?= h($emailBody) ?></div>
      <?php endif; ?>

      <div class="mt-4 pt-3" style="border-top:1px solid #f0f2f5">
        <a href="inscriptions.php" class="btn btn-ov-secondary btn-sm"><i class="fa fa-list me-1"></i>Voir les inscriptions en attente</a>
      </div>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
