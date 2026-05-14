<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('planning', 'view');

$db    = getDB();
$mois  = (int)($_GET['mois']  ?? date('n'));
$annee = (int)($_GET['annee'] ?? date('Y'));

// Restaurer une version — redirect AVANT header.php
if ($_GET['restore'] ?? false) {
    requirePerm('planning','edit');
    $vid = (int)$_GET['restore'];
    $db->prepare("UPDATE planning_versions SET is_current=0 WHERE mois=? AND annee=?")->execute([$mois,$annee]);
    $db->prepare("UPDATE planning_versions SET is_current=1 WHERE id=?")->execute([$vid]);
    flash('success','Version restaurée avec succès.');
    header("Location: index.php?mois=$mois&annee=$annee");
    exit;
}

$pageTitle    = 'Historique des versions';
$currentModule = 'planning-versions';
require_once __DIR__ . '/../../includes/header.php';

$versions = $db->prepare("
    SELECT v.*, u.nom as user_nom, u.prenom as user_prenom,
           (SELECT COUNT(*) FROM planning_lignes l WHERE l.version_id = v.id) as nb_lignes
    FROM planning_versions v
    LEFT JOIN utilisateurs u ON u.id = v.created_by
    WHERE v.mois=? AND v.annee=?
    ORDER BY v.version DESC
");
$versions->execute([$mois, $annee]);
$versions = $versions->fetchAll();
?>

<div class="d-flex align-items-center gap-2 mb-3">
    <a href="index.php?mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour au planning</a>
    <h2 style="font-size:1rem;margin:0;font-weight:600"><?= formatMois($mois,$annee) ?> — Versions</h2>
</div>

<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-clock-rotate-left me-2" style="color:var(--ov-gold)"></i>Historique des versions</h2></div>
  <div class="ov-card-body p-0">
    <?php if (empty($versions)): ?>
    <p class="text-center text-muted py-4">Aucun planning pour ce mois.</p>
    <?php else: ?>
    <table class="ov-table">
      <thead>
        <tr>
          <th>Version</th>
          <th>Date de création</th>
          <th>Créé par</th>
          <th>Entrées</th>
          <th>Commentaire</th>
          <th>Statut</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($versions as $v): ?>
      <tr>
        <td><strong>V<?= $v['version'] ?></strong></td>
        <td><?= date('d/m/Y H:i', strtotime($v['created_at'])) ?></td>
        <td><?= h(($v['user_prenom']??'').' '.($v['user_nom']??'')) ?></td>
        <td><span class="badge-ov" style="background:rgba(99,102,241,0.1);color:#4f46e5;padding:2px 8px;border-radius:20px;font-size:0.72rem"><?= $v['nb_lignes'] ?> entrées</span></td>
        <td class="text-muted" style="font-size:0.85rem"><?= h($v['note'] ?: '—') ?></td>
        <td>
          <?php if ($v['is_current']): ?>
          <span class="badge-ov" style="background:rgba(34,197,94,0.1);color:#16a34a;padding:3px 10px;border-radius:20px;font-size:0.72rem"><i class="fa fa-check me-1"></i>Active</span>
          <?php else: ?>
          <span class="badge-ov" style="background:rgba(107,114,128,0.1);color:#6b7280;padding:3px 10px;border-radius:20px;font-size:0.72rem">Archivée</span>
          <?php endif; ?>
        </td>
        <td>
          <div class="d-flex gap-1">
            <a href="view_version.php?id=<?= $v['id'] ?>" class="btn-sm-icon view" title="Voir"><i class="fa fa-eye"></i></a>
            <?php if (!$v['is_current'] && canDo('planning','edit')): ?>
            <a href="versions.php?mois=<?= $mois ?>&annee=<?= $annee ?>&restore=<?= $v['id'] ?>"
               class="btn-sm-icon edit" title="Restaurer cette version"
               data-confirm="Restaurer la version V<?= $v['version'] ?> ? La version active sera archivée.">
               <i class="fa fa-rotate-left"></i>
            </a>
            <?php endif; ?>
            <a href="export.php?version_id=<?= $v['id'] ?>&format=pdf" class="btn-sm-icon" style="background:rgba(239,68,68,0.1);color:#dc2626" title="PDF"><i class="fa fa-file-pdf"></i></a>
            <a href="export.php?version_id=<?= $v['id'] ?>&format=excel" class="btn-sm-icon" style="background:rgba(34,197,94,0.1);color:#16a34a" title="Excel"><i class="fa fa-file-excel"></i></a>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
