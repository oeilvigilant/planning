<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('planning', 'view');
ensurePlanningAuditSchema();

$db    = getDB();
$mois  = (int)($_GET['mois']  ?? date('n'));
$annee = (int)($_GET['annee'] ?? date('Y'));

$pageTitle    = 'Journal des modifications';
$currentModule = 'planning-audit';
require_once __DIR__ . '/../../includes/header.php';

$entries = $db->prepare("
    SELECT pa.*, u.nom AS user_nom, u.prenom AS user_prenom,
           a.nom AS agent_nom, a.prenom AS agent_prenom,
           v.version AS version_num
    FROM planning_audit pa
    JOIN planning_versions v ON v.id = pa.version_id
    LEFT JOIN utilisateurs u ON u.id = pa.user_id
    LEFT JOIN agents a ON a.id = pa.agent_id
    WHERE v.mois=? AND v.annee=?
    ORDER BY pa.created_at DESC
    LIMIT 500
");
$entries->execute([$mois, $annee]);
$entries = $entries->fetchAll();

$actionBadges = [
    'creation'     => ['label' => 'Création',    'bg' => 'rgba(34,197,94,0.1)',  'color' => '#16a34a'],
    'modification' => ['label' => 'Modification', 'bg' => 'rgba(59,130,246,0.1)', 'color' => '#2563eb'],
    'suppression'  => ['label' => 'Suppression',  'bg' => 'rgba(239,68,68,0.1)',  'color' => '#dc2626'],
];
?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="index.php?mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour au planning</a>
    <h2 style="font-size:1rem;margin:0;font-weight:600"><?= formatMois($mois,$annee) ?> — Journal des modifications</h2>
</div>

<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-magnifying-glass me-2" style="color:var(--ov-gold)"></i>Dernières modifications <span class="text-muted" style="font-size:0.75rem;font-weight:400">(500 max)</span></h2></div>
  <div class="ov-card-body p-0">
    <?php if (empty($entries)): ?>
    <p class="text-center text-muted py-4">Aucune modification enregistrée pour ce mois.</p>
    <?php else: ?>
    <div class="table-responsive">
    <table class="ov-table">
      <thead>
        <tr>
          <th>Quand</th>
          <th>Agent</th>
          <th>Date planifiée</th>
          <th>Action</th>
          <th>Avant</th>
          <th>Après</th>
          <th>Par</th>
          <th>Version</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($entries as $e):
        $badge = $actionBadges[$e['action']] ?? ['label' => $e['action'], 'bg' => '#f0f2f5', 'color' => '#6b7280'];
        $qui   = (!empty($e['user_nom']) || !empty($e['user_prenom'])) ? trim($e['user_prenom'].' '.$e['user_nom']) : 'Automatique';
        $agentNom = trim(($e['agent_prenom'] ?? '').' '.($e['agent_nom'] ?? '')) ?: 'Agent supprimé';
      ?>
      <tr>
        <td style="font-size:0.82rem;color:#6b7280"><?= date('d/m/Y H:i', strtotime($e['created_at'])) ?></td>
        <td style="font-size:0.85rem;font-weight:600"><?= h($agentNom) ?></td>
        <td style="font-size:0.85rem"><?= date('d/m/Y', strtotime($e['date_travail'])) ?></td>
        <td>
          <span class="badge-ov" style="background:<?= $badge['bg'] ?>;color:<?= $badge['color'] ?>;padding:3px 10px;border-radius:20px;font-size:0.72rem"><?= h($badge['label']) ?></span>
        </td>
        <td style="font-size:0.82rem;color:#6b7280">
          <?= $e['heure_debut_avant'] ? h($e['heure_debut_avant']).' - '.h($e['heure_fin_avant']) : '—' ?>
        </td>
        <td style="font-size:0.82rem;font-weight:600">
          <?= $e['heure_debut_apres'] ? h($e['heure_debut_apres']).' - '.h($e['heure_fin_apres']) : '—' ?>
        </td>
        <td style="font-size:0.85rem"><?= h($qui) ?></td>
        <td style="font-size:0.8rem;color:#9ca3af">V<?= $e['version_num'] ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
