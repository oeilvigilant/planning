<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'create');
ensureInscriptionSchema();

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';
    if ($id && $action === 'valider') {
        $db->prepare("UPDATE agents SET actif=1, statut_inscription='validee' WHERE id=? AND statut_inscription IN ('en_attente','refusee')")
           ->execute([$id]);
        flash('success', 'Candidat validé — il apparaît maintenant dans la liste des agents actifs.');
    } elseif ($id && $action === 'refuser') {
        $motif = trim($_POST['motif'] ?? '');
        $db->prepare("UPDATE agents SET statut_inscription='refusee', motif_refus_inscription=? WHERE id=? AND statut_inscription != 'validee'")
           ->execute([$motif ?: null, $id]);
        flash('success', 'Candidature refusée.');
    }
    header('Location: inscriptions.php');
    exit;
}

$pageTitle    = 'Inscriptions en attente';
$currentModule = 'agents-inscriptions';
require_once __DIR__ . '/../../includes/header.php';

$candidats = $db->query("
    SELECT * FROM agents
    WHERE actif=0 AND statut_inscription IN ('en_attente','refusee')
    ORDER BY FIELD(statut_inscription,'en_attente','refusee'), created_at DESC
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 style="font-size:1.1rem;font-weight:700;margin:0"><i class="fa fa-user-clock me-2" style="color:var(--ov-gold)"></i>Inscriptions en attente</h1>
  <a href="invitation_recrutement.php" class="btn btn-ov-primary btn-sm"><i class="fa fa-user-plus me-1"></i>Inviter un candidat</a>
</div>

<?php if (!$candidats): ?>
<div class="alert alert-info"><i class="fa fa-info-circle me-2"></i>Aucune candidature en attente pour le moment.</div>
<?php else: ?>
<div class="ov-card">
  <div class="ov-card-body p-0">
    <div class="table-responsive">
    <table class="ov-table">
      <thead>
        <tr>
          <th>Candidat</th>
          <th>Email</th>
          <th>Téléphone</th>
          <th>Matricule</th>
          <th>Soumis le</th>
          <th>Statut</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($candidats as $c): ?>
      <tr>
        <td>
          <div class="fw-600" style="font-size:0.875rem"><?= h($c['prenom'].' '.$c['nom']) ?></div>
          <?php if ($c['statut_inscription'] === 'refusee' && $c['motif_refus_inscription']): ?>
          <div style="font-size:0.72rem;color:#9ca3af">Motif : <?= h($c['motif_refus_inscription']) ?></div>
          <?php endif; ?>
        </td>
        <td style="font-size:0.85rem"><?= h($c['email'] ?? '—') ?></td>
        <td style="font-size:0.85rem"><?= h($c['telephone'] ?? '—') ?></td>
        <td style="font-size:0.85rem"><?= h($c['matricule']) ?></td>
        <td style="font-size:0.8rem;color:#6b7280"><?= date('d/m/Y à H:i', strtotime($c['created_at'])) ?></td>
        <td>
          <?php if ($c['statut_inscription'] === 'en_attente'): ?>
          <span class="badge" style="background:rgba(234,179,8,0.12);color:#92400e;border-radius:20px;font-size:0.72rem;padding:3px 10px">En attente</span>
          <?php else: ?>
          <span class="badge" style="background:rgba(239,68,68,0.1);color:#dc2626;border-radius:20px;font-size:0.72rem;padding:3px 10px">Refusée</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="d-flex gap-1 flex-wrap">
            <a href="view.php?id=<?= $c['id'] ?>" class="btn-sm-icon view" title="Voir la fiche"><i class="fa fa-eye"></i></a>
            <form method="POST" onsubmit="return confirm('Valider ce candidat ? Il deviendra un agent actif.')">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <input type="hidden" name="action" value="valider">
              <button type="submit" class="btn-sm-icon" style="background:rgba(34,197,94,0.1);color:#16a34a" title="Valider"><i class="fa fa-check"></i></button>
            </form>
            <?php if ($c['statut_inscription'] !== 'refusee'): ?>
            <button type="button" class="btn-sm-icon" style="background:rgba(239,68,68,0.08);color:#dc2626" title="Refuser"
                    onclick="document.getElementById('refuseForm<?= $c['id'] ?>').style.display='flex'">
              <i class="fa fa-xmark"></i>
            </button>
            <?php endif; ?>
          </div>
          <?php if ($c['statut_inscription'] !== 'refusee'): ?>
          <form method="POST" id="refuseForm<?= $c['id'] ?>" style="display:none;gap:4px;margin-top:6px" onsubmit="return confirm('Refuser cette candidature ?')">
            <input type="hidden" name="id" value="<?= $c['id'] ?>">
            <input type="hidden" name="action" value="refuser">
            <input type="text" name="motif" class="form-control form-control-sm" placeholder="Motif (optionnel)" style="font-size:0.78rem">
            <button type="submit" class="btn btn-sm btn-outline-danger">OK</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
