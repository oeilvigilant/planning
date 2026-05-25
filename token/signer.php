<?php
/**
 * Page publique de signature électronique par lien tokenisé.
 * Accessible sans authentification.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/contrat_builder.php';
require_once __DIR__ . '/../includes/mailer.php';

$token = trim($_GET['t'] ?? '');
if (!$token) { die('Lien invalide.'); }

$db   = getDB();
$stmt = $db->prepare("SELECT st.*, a.* FROM signature_tokens st JOIN agents a ON a.id = st.agent_id WHERE st.token = ? AND st.expires_at > NOW()");
$stmt->execute([$token]);
$row = $stmt->fetch();

// ─── Token invalide ou expiré ─────────────────────────────────────────────────
if (!$row) {
    $alreadySigned = $db->prepare("SELECT signed_at FROM signature_tokens WHERE token=?")->execute([$token]) ? null : null;
    $st2 = $db->prepare("SELECT signed_at FROM signature_tokens WHERE token=?"); $st2->execute([$token]);
    $old = $st2->fetch();
?><!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Lien invalide</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</head><body class="bg-dark text-white d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="text-center p-4">
  <?php if ($old && $old['signed_at']): ?>
    <i class="fa fa-check-circle fa-3x text-success mb-3"></i>
    <h4>Contrat déjà signé</h4>
    <p class="text-muted">Ce contrat a été signé le <?= date('d/m/Y à H:i', strtotime($old['signed_at'])) ?>. Contactez votre employeur si vous avez des questions.</p>
  <?php else: ?>
    <i class="fa fa-triangle-exclamation fa-3x text-warning mb-3"></i>
    <h4>Lien expiré ou invalide</h4>
    <p class="text-muted">Ce lien a expiré ou n'est plus valide. Contactez votre responsable pour en obtenir un nouveau.</p>
  <?php endif; ?>
</div></body></html><?php exit; }

$params       = getAllParams();
$agentId      = (int)$row['agent_id'];
$tokenId      = (int)$row['id'];
$contractData = json_decode($row['contrat_data'] ?? '{}', true) ?: [];
$alreadySigned = !empty($row['signed_at']);

// ─── POST : enregistrer la signature ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['sign_submit'])) {
    $sigData = $_POST['signature_data'] ?? '';
    if (!preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $sigData)) {
        $errorMsg = 'Données de signature invalides. Veuillez réessayer.';
    } elseif ($alreadySigned) {
        $errorMsg = 'Ce contrat a déjà été signé.';
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        // Sauvegarder la signature dans la fiche agent
        $db->prepare("UPDATE agents SET signature=?, signature_date=NOW(), signature_ip=? WHERE id=?")
           ->execute([$sigData, $ip, $agentId]);
        // Marquer le token comme utilisé
        $db->prepare("UPDATE signature_tokens SET signed_at=NOW(), ip_signed=?, ua_signed=? WHERE id=?")
           ->execute([$ip, $ua, $tokenId]);
        // Notifier l'admin par email
        $adminEmail = $params['smtp_from'] ?? $params['entreprise_email'] ?? '';
        if ($adminEmail) {
            $nom = trim(($row['prenom'] ?? '') . ' ' . strtoupper($row['nom'] ?? ''));
            sendMail($adminEmail, $params['entreprise_nom'] ?? 'Admin',
                'Contrat signé — ' . $nom,
                '<p>Le contrat de <strong>' . htmlspecialchars($nom) . '</strong> a été signé électroniquement le ' . date('d/m/Y à H:i') . '.</p><p>IP : ' . htmlspecialchars($ip) . '</p>'
            );
        }
        $signed = true;
    }
}

// ─── Générer l'aperçu HTML du contrat ────────────────────────────────────────
$contractHtml = '';
if (!empty($contractData)) {
    // Recharger $a depuis DB pour avoir les données fraîches
    $aFresh = $db->prepare("SELECT * FROM agents WHERE id=?")->execute([$agentId]) ? null : null;
    $stA = $db->prepare("SELECT * FROM agents WHERE id=?"); $stA->execute([$agentId]); $aFresh = $stA->fetch();
    $contractHtml = buildContratHtml($contractData, $params, $aFresh ?: []);
    // Supprimer les balises html/head/body pour l'intégration inline
    $contractHtml = preg_replace('/<\/?(?:html|head|body)[^>]*>/i', '', $contractHtml);
}
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Signature de contrat — <?= htmlspecialchars($params['entreprise_nom'] ?? 'Oeil Vigilant') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
body { background:#f3f4f6; }
.brand-bar { background:#1a2332; color:#c9a84c; padding:14px 24px; font-weight:700; font-size:17px; letter-spacing:1px; }
.contract-box { background:#fff; border-radius:8px; box-shadow:0 1px 6px rgba(0,0,0,.08); overflow:hidden; }
.sig-canvas-wrap { border:2px dashed #d1d5db; border-radius:6px; background:#fafafa; position:relative; }
#sigCanvas { width:100%; height:140px; display:block; touch-action:none; cursor:crosshair; }
#sigPlaceholder { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); color:#d1d5db; font-size:14px; pointer-events:none; white-space:nowrap; }
.btn-sign { background:#c9a84c; border-color:#c9a84c; color:#1a2332; font-weight:600; }
.btn-sign:hover { background:#b8943e; border-color:#b8943e; color:#1a2332; }
</style>
</head>
<body>
<div class="brand-bar">
  <i class="fa fa-shield-halved me-2"></i><?= htmlspecialchars($params['entreprise_nom'] ?? 'Oeil Vigilant') ?>
  <span class="float-end" style="font-size:13px;color:#9ca3af;font-weight:400">Signature électronique sécurisée</span>
</div>

<div class="container py-4" style="max-width:820px">

<?php if (!empty($signed)): ?>
<!-- ── Confirmation de signature ──────────────────────────────────────────── -->
<div class="text-center py-5">
  <div class="mb-4" style="font-size:64px;color:#10b981">✓</div>
  <h3 class="fw-bold">Contrat signé avec succès !</h3>
  <p class="text-muted">Votre signature a été enregistrée le <strong><?= date('d/m/Y à H:i') ?></strong>.</p>
  <p class="text-muted small">Un exemplaire signé vous sera transmis par votre employeur. Conservez cet email comme preuve de signature.</p>
  <div class="alert alert-light border mt-4 d-inline-block text-start" style="font-size:12px;max-width:400px">
    <i class="fa fa-shield-halved text-success me-2"></i>
    Signature électronique simple — Règlement eIDAS (UE 910/2014)<br>
    Horodatage et adresse IP conservés à des fins probatoires.
  </div>
</div>

<?php elseif ($alreadySigned): ?>
<!-- ── Déjà signé ─────────────────────────────────────────────────────────── -->
<div class="text-center py-5">
  <i class="fa fa-check-circle fa-4x text-success mb-3"></i>
  <h4>Ce contrat a déjà été signé</h4>
  <p class="text-muted">Signé le <?= date('d/m/Y à H:i', strtotime($row['signed_at'])) ?>. Contactez votre employeur si vous souhaitez un exemplaire.</p>
</div>

<?php else: ?>
<!-- ── Formulaire de signature ────────────────────────────────────────────── -->
<?php if (!empty($errorMsg)): ?>
<div class="alert alert-danger mb-3"><i class="fa fa-exclamation-circle me-2"></i><?= htmlspecialchars($errorMsg) ?></div>
<?php endif; ?>

<div class="contract-box mb-4">
  <div class="p-3 border-bottom" style="background:#f8f9fa">
    <h5 class="mb-0"><i class="fa fa-file-contract me-2 text-warning"></i>Votre contrat de travail</h5>
    <div class="text-muted small">Lisez attentivement le document avant de signer.</div>
  </div>
  <div style="max-height:55vh;overflow-y:auto;padding:0 8px">
    <?= $contractHtml ?>
  </div>
</div>

<div class="contract-box p-4">
  <h5 class="mb-1"><i class="fa fa-pen-nib me-2 text-warning"></i>Votre signature</h5>
  <p class="text-muted small mb-3">Signez dans le cadre ci-dessous (souris ou doigt sur écran tactile).</p>

  <div class="sig-canvas-wrap mb-2">
    <canvas id="sigCanvas" width="700" height="140"></canvas>
    <span id="sigPlaceholder">✍ Signez ici</span>
  </div>
  <div class="d-flex gap-2 mb-3">
    <button type="button" onclick="clearSig()" class="btn btn-sm btn-outline-secondary">
      <i class="fa fa-eraser me-1"></i>Effacer
    </button>
  </div>

  <div class="form-check mb-3">
    <input class="form-check-input" type="checkbox" id="chkLu" required>
    <label class="form-check-label" for="chkLu">
      J'ai lu et j'accepte l'intégralité du contrat de travail ci-dessus.
    </label>
  </div>

  <form method="post" id="sigForm">
    <input type="hidden" name="sign_submit" value="1">
    <input type="hidden" name="signature_data" id="sigData">
    <button type="button" onclick="submitSig()" class="btn btn-sign w-100 py-3" style="font-size:17px">
      <i class="fa fa-check me-2"></i>Signer et valider mon contrat
    </button>
  </form>

  <p class="text-muted mt-3 mb-0" style="font-size:11px">
    <i class="fa fa-shield-halved me-1 text-success"></i>
    Signature électronique simple — Règlement eIDAS (UE 910/2014). Votre adresse IP et l'horodatage sont conservés à des fins probatoires. Ce lien est à usage unique.
  </p>
</div>
<?php endif; ?>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.2.0/dist/signature_pad.umd.min.js"></script>
<script>
var _pad = null;
var canvas = document.getElementById('sigCanvas');
if (canvas) {
    function resizePad() {
        var r = window.devicePixelRatio || 1;
        canvas.width  = canvas.offsetWidth  * r;
        canvas.height = canvas.offsetHeight * r;
        canvas.getContext('2d').scale(r, r);
        if (_pad) _pad.clear();
    }
    window.addEventListener('resize', resizePad);
    resizePad();
    _pad = new SignaturePad(canvas, { backgroundColor:'rgba(255,255,255,0)', penColor:'#1a2332', minWidth:1, maxWidth:2.5 });
    canvas.addEventListener('mousedown', function(){ var p=document.getElementById('sigPlaceholder'); if(p) p.style.display='none'; });
    canvas.addEventListener('touchstart', function(){ var p=document.getElementById('sigPlaceholder'); if(p) p.style.display='none'; });
}
function clearSig() {
    if (_pad) _pad.clear();
    var p=document.getElementById('sigPlaceholder'); if(p) p.style.display='';
}
function submitSig() {
    if (!document.getElementById('chkLu').checked) { alert("Veuillez cocher la case d'acceptation du contrat."); return; }
    if (!_pad || _pad.isEmpty()) { alert('Veuillez tracer votre signature avant de valider.'); return; }
    document.getElementById('sigData').value = _pad.toDataURL('image/png');
    document.getElementById('sigForm').submit();
}
</script>
</body>
</html>
