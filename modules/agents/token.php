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

      <?php if ($a['email']): ?>
      <div class="mt-3 text-center">
        <?php if ($tokenUrl): ?>
        <a href="mailto:<?= h($a['email']) ?>?subject=Votre lien de dossier Oeil Vigilant&body=Bonjour <?= urlencode($a['prenom']) ?>,%0D%0A%0D%0AVeuillez remplir votre dossier en cliquant sur le lien suivant :%0D%0A<?= urlencode($tokenUrl) ?>" class="btn btn-ov-secondary btn-sm">
          <i class="fa fa-envelope me-1"></i>Envoyer par email à <?= h($a['email']) ?>
        </a>
        <?php endif; ?>
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
