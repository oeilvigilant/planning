<?php
// Script de diagnostic — signatures perdues
// Accès restreint : admin uniquement
// À SUPPRIMER après utilisation
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (($_SESSION['user']['role_slug'] ?? '') !== 'admin') { http_response_code(403); exit('Accès refusé'); }

$db = getDB();
header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Diagnostic signatures perdues</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light p-4">
<div class="container" style="max-width:900px">
<h2 class="mb-1">Diagnostic — Signatures perdues</h2>
<p class="text-muted mb-4">Contrats supprimés par le nettoyage automatique malgré une signature enregistrée.</p>

<?php
// ── 1. Tokens signés dont le contrat n'existe plus ────────────────────────
$q1 = $db->query("
    SELECT st.agent_id, a.nom, a.prenom, st.contrat_id,
           st.email, st.signed_at, st.ip_signed
    FROM signature_tokens st
    JOIN agents a ON a.id = st.agent_id
    WHERE st.signed_at IS NOT NULL
      AND st.contrat_id IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM contrats c WHERE c.id = st.contrat_id)
    ORDER BY st.signed_at DESC
");
$orphanTokens = $q1->fetchAll();

// ── 2. Log signatures dont le contrat n'existe plus ───────────────────────
$orphanLogs = [];
try {
    $q2 = $db->query("
        SELECT sl.agent_id, a.nom, a.prenom, sl.contrat_id, sl.signed_at, sl.ip_address
        FROM signatures_log sl
        JOIN agents a ON a.id = sl.agent_id
        WHERE sl.contrat_id IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM contrats c WHERE c.id = sl.contrat_id)
        ORDER BY sl.signed_at DESC
    ");
    $orphanLogs = $q2->fetchAll();
} catch (Exception $e) {}

// ── 3. Contrats actuels sans signature mais avec un token signé ───────────
$q3 = $db->query("
    SELECT a.id AS agent_id, a.nom, a.prenom,
           c.id AS contrat_id, c.date_debut, c.date_fin,
           c.signature, c.poste,
           st.signed_at AS token_signed_at, st.email
    FROM contrats c
    JOIN agents a ON a.id = c.agent_id
    LEFT JOIN signature_tokens st ON st.contrat_id = c.id AND st.signed_at IS NOT NULL
    WHERE (c.signature IS NULL OR c.signature = '')
      AND st.id IS NOT NULL
    ORDER BY a.nom
");
$contratsSansSig = $q3->fetchAll();

// ── 4. Tous les contrats signés actuellement en base ──────────────────────
$q4 = $db->query("
    SELECT c.id, c.agent_id, a.nom, a.prenom, c.date_debut, c.date_fin,
           c.signature_date, c.poste, c.statut
    FROM contrats c
    JOIN agents a ON a.id = c.agent_id
    WHERE c.signature IS NOT NULL AND c.signature != ''
    ORDER BY a.nom, c.signature_date DESC
");
$contratsSignes = $q4->fetchAll();
?>

<!-- Résumé -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card text-center border-danger">
      <div class="card-body py-3">
        <div class="fs-2 fw-bold text-danger"><?= count($orphanTokens) ?></div>
        <div class="small text-muted">Tokens signés sans contrat</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card text-center border-warning">
      <div class="card-body py-3">
        <div class="fs-2 fw-bold text-warning"><?= count($orphanLogs) ?></div>
        <div class="small text-muted">Logs signés sans contrat</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card text-center border-info">
      <div class="card-body py-3">
        <div class="fs-2 fw-bold text-info"><?= count($contratsSansSig) ?></div>
        <div class="small text-muted">Contrats actifs sans signature</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="card text-center border-success">
      <div class="card-body py-3">
        <div class="fs-2 fw-bold text-success"><?= count($contratsSignes) ?></div>
        <div class="small text-muted">Contrats encore signés</div>
      </div>
    </div>
  </div>
</div>

<!-- 1. Tokens orphelins -->
<div class="card mb-4">
  <div class="card-header bg-danger text-white fw-bold">
    1. Agents dont le contrat signé a été supprimé (tokens orphelins)
  </div>
  <div class="card-body p-0">
    <?php if (empty($orphanTokens)): ?>
    <p class="p-3 mb-0 text-muted">Aucun</p>
    <?php else: ?>
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr>
        <th>Agent</th><th>ID agent</th><th>ID contrat (supprimé)</th>
        <th>Email signature</th><th>Signé le</th><th>IP</th>
      </tr></thead>
      <tbody>
        <?php foreach ($orphanTokens as $r): ?>
        <tr class="table-danger">
          <td><a href="modules/agents/contrat.php?id=<?= $r['agent_id'] ?>" target="_blank"><?= htmlspecialchars($r['prenom'].' '.$r['nom']) ?></a></td>
          <td><?= $r['agent_id'] ?></td>
          <td class="fw-bold text-danger"><?= $r['contrat_id'] ?></td>
          <td><?= htmlspecialchars($r['email']) ?></td>
          <td><?= $r['signed_at'] ?></td>
          <td><?= htmlspecialchars($r['ip_signed'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<!-- 2. Logs orphelins -->
<?php if ($orphanLogs): ?>
<div class="card mb-4">
  <div class="card-header bg-warning fw-bold">
    2. Logs de signatures sans contrat associé
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>Agent</th><th>ID contrat supprimé</th><th>Signé le</th><th>IP</th></tr></thead>
      <tbody>
        <?php foreach ($orphanLogs as $r): ?>
        <tr class="table-warning">
          <td><?= htmlspecialchars($r['prenom'].' '.$r['nom']) ?></td>
          <td class="fw-bold"><?= $r['contrat_id'] ?></td>
          <td><?= $r['signed_at'] ?></td>
          <td><?= htmlspecialchars($r['ip_address'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- 3. Contrats signés en base -->
<div class="card mb-4">
  <div class="card-header bg-success text-white fw-bold">
    3. Contrats encore signés en base (<?= count($contratsSignes) ?>)
  </div>
  <div class="card-body p-0">
    <?php if (empty($contratsSignes)): ?>
    <p class="p-3 mb-0 text-muted">Aucun contrat signé en base.</p>
    <?php else: ?>
    <table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>Agent</th><th>Poste</th><th>Dates</th><th>Signé le</th><th>Statut</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($contratsSignes as $r): ?>
        <tr>
          <td><?= htmlspecialchars($r['prenom'].' '.$r['nom']) ?></td>
          <td style="font-size:0.8rem"><?= htmlspecialchars($r['poste'] ?? '—') ?></td>
          <td style="font-size:0.8rem"><?= $r['date_debut'] ? date('d/m/Y', strtotime($r['date_debut'])) : '—' ?> → <?= $r['date_fin'] ? date('d/m/Y', strtotime($r['date_fin'])) : '—' ?></td>
          <td style="font-size:0.8rem"><?= $r['signature_date'] ? date('d/m/Y H:i', strtotime($r['signature_date'])) : '—' ?></td>
          <td><span class="badge bg-<?= $r['statut']==='actif'?'success':'secondary' ?>"><?= $r['statut'] ?></span></td>
          <td><a href="modules/agents/contrat.php?id=<?= $r['agent_id'] ?>&contrat_id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary">Voir</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<div class="alert alert-warning">
  <strong>Récupération depuis LWS :</strong> connectez-vous à votre espace client LWS → <strong>Hébergement → Sauvegardes</strong>.
  LWS conserve des snapshots quotidiens (généralement 7 à 14 jours). Téléchargez la sauvegarde MySQL antérieure au 12 juillet 2026
  (date du commit problématique) et récupérez les lignes manquantes dans la table <code>contrats</code>.
  <br><br>
  <strong>Colonnes à récupérer :</strong> <code>id, agent_id, signature, signature_date, signature_ip</code> — le reste peut rester tel quel.
</div>

<p class="text-muted small">⚠️ Supprimer ce fichier après diagnostic : <code>diagnostic_signatures.php</code></p>
</div>
</body>
</html>
