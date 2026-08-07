<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('rapports', 'view');
ensureMissionsSchema();

$pageTitle    = 'Exports & Rapports';
$currentModule = 'rapports';
require_once __DIR__ . '/../../includes/header.php';

$db    = getDB();
$mois  = (int)($_GET['mois']  ?? date('n'));
$annee = (int)($_GET['annee'] ?? date('Y'));

$versions = $db->query("
    SELECT v.*, u.nom as user_nom, m.nom as mission_nom
    FROM planning_versions v
    LEFT JOIN utilisateurs u ON u.id = v.created_by
    LEFT JOIN missions m ON m.id = v.mission_id
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
        <thead><tr><th>Période</th><th>Mission</th><th>Version</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($versions as $v): ?>
        <tr>
          <td><?= formatMois($v['mois'], $v['annee']) ?></td>
          <td><?= h($v['mission_nom'] ?? '—') ?></td>
          <td>V<?= $v['version'] ?> <?= $v['is_current']?'<span style="color:#16a34a;font-size:0.72rem">(active)</span>':'' ?></td>
          <td>
            <div class="d-flex gap-1">
              <a href="../planning/export.php?version_id=<?= $v['id'] ?>&mission=<?= $v['mission_id'] ?>&format=pdf" class="btn-sm-icon" style="background:rgba(239,68,68,0.1);color:#dc2626" title="PDF"><i class="fa fa-file-pdf"></i></a>
              <a href="../planning/export.php?version_id=<?= $v['id'] ?>&mission=<?= $v['mission_id'] ?>&format=excel" class="btn-sm-icon" style="background:rgba(34,197,94,0.1);color:#16a34a" title="Excel"><i class="fa fa-file-excel"></i></a>
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
          <div style="font-size:0.75rem;color:#9ca3af"><?= h($v['mission_nom'] ?? 'Mission') ?></div>
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

<!-- Export XLS agents par période -->
<div class="row g-3 mt-1">
<div class="col-12">
  <div class="ov-card">
    <div class="ov-card-header">
      <h2 class="ov-card-title">
        <i class="fa fa-file-excel me-2" style="color:var(--ov-gold)"></i>
        Export XLS — Heures par agents et période
      </h2>
    </div>
    <div class="ov-card-body">
      <p class="text-muted mb-3" style="font-size:0.85rem">
        Sélectionnez les agents et une plage de dates pour exporter un fichier Excel détaillant
        les heures par type (normal, nuit, dimanche, férié…), le montant par type et le total.
      </p>
      <form action="export_xls_agents.php" method="post" target="_blank" id="form-xls-agents">
        <div class="row g-3 mb-4">
          <div class="col-md-3">
            <label class="form-label fw-600" style="font-size:0.85rem">Date début</label>
            <input type="date" name="date_debut" class="form-control form-control-sm" required
                   value="<?= date('Y-m-01') ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-600" style="font-size:0.85rem">Date fin</label>
            <input type="date" name="date_fin" class="form-control form-control-sm" required
                   value="<?= date('Y-m-t') ?>">
          </div>
        </div>

        <div class="mb-4">
          <div class="d-flex align-items-center gap-3 mb-2">
            <span class="fw-600" style="font-size:0.85rem">Agents</span>
            <button type="button" class="btn btn-sm" style="font-size:0.75rem;padding:2px 10px;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb"
                    onclick="document.querySelectorAll('.ag-cb').forEach(c=>c.checked=true)">
              Tout sélectionner
            </button>
            <button type="button" class="btn btn-sm" style="font-size:0.75rem;padding:2px 10px;border:1px solid #d1d5db;border-radius:6px;background:#f9fafb"
                    onclick="document.querySelectorAll('.ag-cb').forEach(c=>c.checked=false)">
              Tout désélectionner
            </button>
          </div>
          <?php if (empty($agents)): ?>
          <p class="text-muted" style="font-size:0.85rem">Aucun agent actif.</p>
          <?php else: ?>
          <div class="row g-2">
            <?php foreach ($agents as $a): ?>
            <div class="col-xl-2 col-lg-3 col-md-4 col-sm-6">
              <label class="d-flex align-items-center gap-2 p-2 rounded"
                     style="background:#f8f9fa;border:1px solid #e5e7eb;cursor:pointer;font-size:0.82rem;transition:background .15s"
                     onmouseover="this.style.background='#eef2ff'" onmouseout="this.style.background='#f8f9fa'">
                <input class="form-check-input ag-cb" type="checkbox" name="agents[]"
                       value="<?= $a['id'] ?>" style="margin-top:0;flex-shrink:0" checked>
                <span>
                  <?= h($a['prenom'] . ' ' . $a['nom']) ?>
                  <?php if ($a['matricule']): ?>
                  <br><span style="color:#9ca3af;font-size:0.73rem"><?= h($a['matricule']) ?></span>
                  <?php endif; ?>
                </span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <button type="submit" class="btn btn-ov-primary" onclick="return validerFormXls()">
          <i class="fa fa-file-excel me-1"></i>Télécharger XLS
        </button>
      </form>
    </div>
  </div>
</div>
</div>

<script>
function validerFormXls() {
  var cbs = document.querySelectorAll('.ag-cb:checked');
  if (!cbs.length) {
    alert('Veuillez sélectionner au moins un agent.');
    return false;
  }
  return true;
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
