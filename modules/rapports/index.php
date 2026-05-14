<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('rapports', 'view');

$pageTitle    = 'Exports & Rapports';
$currentModule = 'rapports';
require_once __DIR__ . '/../../includes/header.php';

$db    = getDB();
$mois  = (int)($_GET['mois']  ?? date('n'));
$annee = (int)($_GET['annee'] ?? date('Y'));

$versions = $db->query("
    SELECT v.*, u.nom as user_nom
    FROM planning_versions v
    LEFT JOIN utilisateurs u ON u.id = v.created_by
    ORDER BY v.annee DESC, v.mois DESC, v.version DESC
    LIMIT 20
")->fetchAll();

$agents = $db->query("SELECT id, nom, prenom, matricule FROM agents WHERE actif=1 ORDER BY nom")->fetchAll();
?>

<div class="row g-3">

<div class="col-lg-6">
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-calendar me-2" style="color:var(--ov-gold)"></i>Export planning</h2></div>
    <div class="ov-card-body">
      <p class="text-muted mb-3" style="font-size:0.85rem">Exporter un planning mensuel en PDF ou Excel (CSV)</p>
      <?php if (empty($versions)): ?>
      <p class="text-muted">Aucun planning disponible.</p>
      <?php else: ?>
      <table class="ov-table">
        <thead><tr><th>Période</th><th>Version</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($versions as $v): ?>
        <tr>
          <td><?= formatMois($v['mois'], $v['annee']) ?></td>
          <td>V<?= $v['version'] ?> <?= $v['is_current']?'<span style="color:#16a34a;font-size:0.72rem">(active)</span>':'' ?></td>
          <td>
            <div class="d-flex gap-1">
              <a href="../planning/export.php?version_id=<?= $v['id'] ?>&format=pdf" class="btn-sm-icon" style="background:rgba(239,68,68,0.1);color:#dc2626" title="PDF"><i class="fa fa-file-pdf"></i></a>
              <a href="../planning/export.php?version_id=<?= $v['id'] ?>&format=excel" class="btn-sm-icon" style="background:rgba(34,197,94,0.1);color:#16a34a" title="Excel"><i class="fa fa-file-excel"></i></a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="col-lg-6">
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-euro-sign me-2" style="color:var(--ov-gold)"></i>Export salaires</h2></div>
    <div class="ov-card-body">
      <p class="text-muted mb-3" style="font-size:0.85rem">Exporter le récapitulatif des salaires d'un mois</p>
      <?php foreach ($versions as $v):
        if (!$v['is_current']) continue;
      ?>
      <div class="d-flex justify-content-between align-items-center p-3 rounded mb-2" style="background:#f8f9fa">
        <div>
          <div class="fw-600"><?= formatMois($v['mois'], $v['annee']) ?> — V<?= $v['version'] ?></div>
          <div style="font-size:0.75rem;color:#9ca3af">Planning actif</div>
        </div>
        <div class="d-flex gap-2">
          <a href="export_salaires.php?version_id=<?= $v['id'] ?>&format=pdf" class="btn btn-sm" style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.3rem 0.7rem;font-size:0.8rem"><i class="fa fa-file-pdf me-1"></i>PDF</a>
          <a href="export_salaires.php?version_id=<?= $v['id'] ?>&format=csv" class="btn btn-sm" style="background:rgba(34,197,94,0.1);color:#16a34a;border:1px solid rgba(34,197,94,0.2);border-radius:8px;padding:0.3rem 0.7rem;font-size:0.8rem"><i class="fa fa-file-excel me-1"></i>CSV</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="ov-card">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-file-pdf me-2" style="color:var(--ov-gold)"></i>Fiches agents PDF</h2></div>
    <div class="ov-card-body">
      <p class="text-muted mb-3" style="font-size:0.85rem">Exporter la fiche d'un agent pour le comptable</p>
      <table class="ov-table">
        <thead><tr><th>Agent</th><th>Matricule</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($agents as $a): ?>
        <tr>
          <td><?= h($a['prenom'].' '.$a['nom']) ?></td>
          <td><code><?= h($a['matricule']??'—') ?></code></td>
          <td>
            <a href="../agents/export_pdf.php?id=<?= $a['id'] ?>" class="btn-sm-icon" style="background:rgba(239,68,68,0.1);color:#dc2626" title="PDF comptable"><i class="fa fa-file-pdf"></i></a>
            <a href="../agents/carte.php?id=<?= $a['id'] ?>" class="btn-sm-icon print" title="Carte agent"><i class="fa fa-id-card"></i></a>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
