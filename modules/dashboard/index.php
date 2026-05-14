<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('dashboard', 'view');

$pageTitle    = 'Tableau de bord';
$currentModule = 'dashboard';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();

$nbAgents       = $db->query("SELECT COUNT(*) FROM agents WHERE actif=1")->fetchColumn();
$nbAgentsTotal  = $db->query("SELECT COUNT(*) FROM agents")->fetchColumn();
$moisActuel     = (int)date('n');
$anneeActuelle  = (int)date('Y');

// Planning du mois
$version = $db->prepare("SELECT * FROM planning_versions WHERE mois=? AND annee=? AND is_current=1 LIMIT 1");
$version->execute([$moisActuel, $anneeActuelle]);
$version = $version->fetch();

$nbPlanifies = 0;
if ($version) {
    $nbPlanifies = $db->prepare("SELECT COUNT(DISTINCT agent_id) FROM planning_lignes WHERE version_id=?")->execute([$version['id']]) ?
        $db->query("SELECT COUNT(DISTINCT agent_id) FROM planning_lignes WHERE version_id={$version['id']}")->fetchColumn() : 0;
}

// Agents avec CNAPS expirant dans 30 jours
$cnapsAlerte = $db->query("
    SELECT nom, prenom, num_autorisation_cnaps, date_expiration_cnaps
    FROM agents WHERE actif=1 AND date_expiration_cnaps IS NOT NULL
    AND date_expiration_cnaps <= DATE_ADD(NOW(), INTERVAL 30 DAY)
    ORDER BY date_expiration_cnaps ASC LIMIT 10
")->fetchAll();

// Derniers agents ajoutés
$derniersAgents = $db->query("
    SELECT id, nom, prenom, poste, type_contrat, created_at
    FROM agents ORDER BY created_at DESC LIMIT 5
")->fetchAll();

// Agents du jour
$aujourdhui = date('Y-m-d');
$agentsAujourdhui = [];
if ($version) {
    $stmtJ = $db->prepare("
        SELECT a.nom, a.prenom, l.heure_debut, l.heure_fin, l.depasse_minuit
        FROM planning_lignes l
        JOIN agents a ON a.id = l.agent_id
        WHERE l.version_id=? AND l.date_travail=?
        ORDER BY l.heure_debut
    ");
    $stmtJ->execute([$version['id'], $aujourdhui]);
    $agentsAujourdhui = $stmtJ->fetchAll();
}
?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <div class="stat-card">
      <div class="stat-icon gold"><i class="fa fa-user-shield"></i></div>
      <div><div class="stat-value"><?= $nbAgents ?></div><div class="stat-label">Agents actifs</div></div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card">
      <div class="stat-icon navy"><i class="fa fa-calendar-check"></i></div>
      <div><div class="stat-value"><?= $nbPlanifies ?></div><div class="stat-label">Planifiés ce mois</div></div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card">
      <div class="stat-icon <?= count($cnapsAlerte)>0 ? 'red' : 'green' ?>"><i class="fa fa-shield-halved"></i></div>
      <div><div class="stat-value"><?= count($cnapsAlerte) ?></div><div class="stat-label">Alertes CNAPS</div></div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fa fa-calendar-day"></i></div>
      <div><div class="stat-value"><?= count($agentsAujourdhui) ?></div><div class="stat-label">En service aujourd'hui</div></div>
    </div>
  </div>
</div>

<div class="row g-3">

  <!-- Agents aujourd'hui -->
  <div class="col-lg-4">
    <div class="ov-card">
      <div class="ov-card-header">
        <h2 class="ov-card-title"><i class="fa fa-calendar-day me-2" style="color:var(--ov-gold)"></i>Aujourd'hui</h2>
        <span style="font-size:0.78rem;color:#9ca3af"><?= date('d/m/Y') ?></span>
      </div>
      <div class="ov-card-body p-0">
        <?php if (empty($agentsAujourdhui)): ?>
        <p class="text-center text-muted py-3 mb-0" style="font-size:0.85rem">Aucun agent planifié aujourd'hui</p>
        <?php else: ?>
        <?php foreach ($agentsAujourdhui as $a): ?>
        <?php
          $hDeb = substr($a['heure_debut'],0,5);
          $hFin = substr($a['heure_fin'],0,5);
          $isNuit = $hDeb >= '21:00' || $hFin <= '06:00';
        ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2" style="border-bottom:1px solid #f0f2f5">
          <div style="width:8px;height:8px;border-radius:50%;background:<?= $isNuit?'#4f46e5':'#f59e0b' ?>;flex-shrink:0"></div>
          <div class="flex-grow-1">
            <div style="font-size:0.875rem;font-weight:600"><?= h($a['prenom'].' '.$a['nom']) ?></div>
          </div>
          <div style="font-size:0.78rem;color:#6b7280"><?= $hDeb ?>→<?= $hFin ?><?= $a['depasse_minuit']?'+1':'' ?></div>
          <span class="shift-badge <?= $isNuit?'shift-nuit':'shift-jour' ?>"><?= $isNuit?'Nuit':'Jour' ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Alertes CNAPS -->
  <div class="col-lg-4">
    <div class="ov-card">
      <div class="ov-card-header">
        <h2 class="ov-card-title"><i class="fa fa-triangle-exclamation me-2 text-warning"></i>Alertes CNAPS</h2>
        <?php if (count($cnapsAlerte)): ?><span class="badge bg-danger rounded-pill"><?= count($cnapsAlerte) ?></span><?php endif; ?>
      </div>
      <div class="ov-card-body p-0">
        <?php if (empty($cnapsAlerte)): ?>
        <p class="text-center text-muted py-3 mb-0" style="font-size:0.85rem"><i class="fa fa-circle-check text-success me-1"></i>Toutes les autorisations sont valides</p>
        <?php else: ?>
        <?php foreach ($cnapsAlerte as $c):
          $jours = (int)ceil((strtotime($c['date_expiration_cnaps']) - time()) / 86400);
        ?>
        <div class="d-flex align-items-center gap-2 px-3 py-2" style="border-bottom:1px solid #f0f2f5">
          <i class="fa fa-shield-halved text-danger"></i>
          <div class="flex-grow-1">
            <div style="font-size:0.875rem;font-weight:600"><?= h($c['prenom'].' '.$c['nom']) ?></div>
            <div style="font-size:0.72rem;color:#9ca3af"><?= h($c['num_autorisation_cnaps'] ?? '—') ?></div>
          </div>
          <span class="badge <?= $jours <= 0 ? 'bg-danger' : 'bg-warning text-dark' ?>" style="font-size:0.72rem">
            <?= $jours <= 0 ? 'Expiré' : "J-$jours" ?>
          </span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Derniers agents -->
  <div class="col-lg-4">
    <div class="ov-card">
      <div class="ov-card-header">
        <h2 class="ov-card-title"><i class="fa fa-user-plus me-2" style="color:var(--ov-gold)"></i>Derniers agents</h2>
        <a href="../agents/index.php" style="font-size:0.78rem;color:var(--ov-gold)">Voir tous →</a>
      </div>
      <div class="ov-card-body p-0">
        <?php foreach ($derniersAgents as $a): ?>
        <div class="d-flex align-items-center gap-2 px-3 py-2" style="border-bottom:1px solid #f0f2f5">
          <div style="width:34px;height:34px;border-radius:50%;background:rgba(201,168,76,0.12);display:flex;align-items:center;justify-content:center;color:var(--ov-gold);font-weight:700;font-size:0.78rem;flex-shrink:0">
            <?= strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1)) ?>
          </div>
          <div class="flex-grow-1">
            <a href="../agents/view.php?id=<?= $a['id'] ?>" style="font-size:0.875rem;font-weight:600;color:var(--ov-navy);text-decoration:none"><?= h($a['prenom'].' '.$a['nom']) ?></a>
            <div style="font-size:0.72rem;color:#9ca3af"><?= h($a['poste']??'') ?></div>
          </div>
          <div style="font-size:0.72rem;color:#9ca3af"><?= date('d/m', strtotime($a['created_at'])) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

</div>

<!-- Accès rapide -->
<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="ov-card">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-bolt me-2" style="color:var(--ov-gold)"></i>Accès rapide</h2></div>
      <div class="ov-card-body d-flex gap-3 flex-wrap">
        <a href="../agents/add.php" class="btn btn-ov-secondary"><i class="fa fa-user-plus me-2"></i>Nouvel agent</a>
        <a href="../planning/index.php" class="btn btn-ov-secondary"><i class="fa fa-calendar me-2"></i>Planning <?= formatMois($moisActuel,$anneeActuelle) ?></a>
        <a href="../salaires/index.php" class="btn btn-ov-secondary"><i class="fa fa-euro-sign me-2"></i>Salaires du mois</a>
        <a href="../parametres/index.php" class="btn btn-ov-secondary"><i class="fa fa-sliders me-2"></i>Paramètres</a>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
