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
$nbInscriptionsAttente = countInscriptionsEnAttente();
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

// Notes internes en attente (toutes agents)
$notesEnAttente = [];
try {
    $stN = $db->query("
        SELECT n.id, n.contenu, n.created_at, n.created_by,
               a.id AS agent_id, a.nom, a.prenom
        FROM agent_notes n
        JOIN agents a ON a.id = n.agent_id
        WHERE n.statut = 'ouvert'
        ORDER BY n.created_at DESC
        LIMIT 20
    ");
    $notesEnAttente = $stN->fetchAll();
} catch(Exception $e){}

// Alertes documents expirés ou expirant dans 60 jours
$alertesDocs = [];
try {
    // Documents avec date_expiration
    $stD = $db->query("
        SELECT a.id AS agent_id, a.nom, a.prenom,
               d.type_document, d.date_expiration, d.nom_fichier
        FROM agent_documents d
        JOIN agents a ON a.id = d.agent_id AND a.actif=1
        WHERE d.date_expiration IS NOT NULL
          AND d.date_expiration <= DATE_ADD(NOW(), INTERVAL 60 DAY)
        ORDER BY d.date_expiration ASC
        LIMIT 20
    ");
    $alertesDocs = $stD->fetchAll();
    // Ajouter CNAPS dans la même liste
    $stC = $db->query("
        SELECT id AS agent_id, nom, prenom,
               'cnaps' AS type_document, date_expiration_cnaps AS date_expiration, num_autorisation_cnaps AS nom_fichier
        FROM agents WHERE actif=1 AND date_expiration_cnaps IS NOT NULL
        AND date_expiration_cnaps <= DATE_ADD(NOW(), INTERVAL 60 DAY)
    ");
    $alertesDocs = array_merge($alertesDocs, $stC->fetchAll());
    usort($alertesDocs, fn($a,$b) => strcmp($a['date_expiration'], $b['date_expiration']));
} catch(Exception $e){}

$labelsDoc = [
    'cnaps'             => 'CNAPS',
    'piece_identite'    => 'CNI',
    'titre_sejour'      => 'Titre de séjour',
    'carte_vitale'      => 'Carte vitale',
    'attestation_cnaps' => 'Attestation CNAPS',
];

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
      <div class="stat-icon <?= count($alertesDocs)>0 ? 'red' : 'green' ?>"><i class="fa fa-shield-halved"></i></div>
      <div><div class="stat-value"><?= count($alertesDocs) ?></div><div class="stat-label">Alertes documents</div></div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card">
      <div class="stat-icon green"><i class="fa fa-calendar-day"></i></div>
      <div><div class="stat-value"><?= count($agentsAujourdhui) ?></div><div class="stat-label">En service aujourd'hui</div></div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="stat-card" onclick="document.getElementById('bloc-notes-dashboard').scrollIntoView({behavior:'smooth'})" style="cursor:pointer">
      <div class="stat-icon <?= count($notesEnAttente)>0 ? 'red' : 'green' ?>"><i class="fa fa-clipboard-list"></i></div>
      <div><div class="stat-value"><?= count($notesEnAttente) ?></div><div class="stat-label">Notes en attente</div></div>
    </div>
  </div>
  <?php if (canDo('agents','create')): ?>
  <div class="col-md-3 col-6">
    <a href="<?= APP_URL ?>/modules/agents/inscriptions.php" class="stat-card" style="cursor:pointer;text-decoration:none">
      <div class="stat-icon <?= $nbInscriptionsAttente>0 ? 'red' : 'green' ?>"><i class="fa fa-user-clock"></i></div>
      <div><div class="stat-value"><?= $nbInscriptionsAttente ?></div><div class="stat-label">Inscriptions en attente</div></div>
    </a>
  </div>
  <?php endif; ?>
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

<!-- Notes en attente + Alertes documents -->
<div class="row g-3 mt-1" id="bloc-notes-dashboard">

  <!-- Notes internes en attente -->
  <div class="col-lg-6">
    <div class="ov-card h-100">
      <div class="ov-card-header d-flex align-items-center justify-content-between">
        <h2 class="ov-card-title mb-0"><i class="fa fa-clipboard-list me-2" style="color:var(--ov-gold)"></i>Notes en attente</h2>
        <?php if ($notesEnAttente): ?>
        <span class="badge rounded-pill" style="background:#f59e0b;color:#fff"><?= count($notesEnAttente) ?></span>
        <?php endif; ?>
      </div>
      <div class="ov-card-body p-0">
        <?php if (empty($notesEnAttente)): ?>
        <p class="text-center text-muted py-3 mb-0" style="font-size:0.85rem"><i class="fa fa-circle-check text-success me-1"></i>Aucune note en attente</p>
        <?php else: ?>
        <?php foreach ($notesEnAttente as $n): ?>
        <a href="../agents/view.php?id=<?= $n['agent_id'] ?>#notes" style="text-decoration:none;color:inherit">
          <div class="d-flex align-items-start gap-3 px-3 py-2" style="border-bottom:1px solid #f0f2f5;transition:background .15s" onmouseover="this.style.background='#fffbeb'" onmouseout="this.style.background=''">
            <i class="fa fa-circle text-warning mt-1" style="font-size:0.6rem;flex-shrink:0;margin-top:6px"></i>
            <div style="flex:1;min-width:0">
              <div style="font-size:0.78rem;font-weight:600;color:var(--ov-navy)"><?= h($n['prenom'].' '.$n['nom']) ?></div>
              <div style="font-size:0.82rem;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($n['contenu']) ?></div>
            </div>
            <div style="font-size:0.72rem;color:#9ca3af;white-space:nowrap;flex-shrink:0"><?= date('d/m/Y', strtotime($n['created_at'])) ?></div>
          </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Alertes documents -->
  <div class="col-lg-6">
    <div class="ov-card h-100">
      <div class="ov-card-header d-flex align-items-center justify-content-between">
        <h2 class="ov-card-title mb-0"><i class="fa fa-triangle-exclamation me-2 text-warning"></i>Alertes documents</h2>
        <?php if ($alertesDocs): ?>
        <span class="badge rounded-pill bg-danger"><?= count($alertesDocs) ?></span>
        <?php endif; ?>
      </div>
      <div class="ov-card-body p-0">
        <?php if (empty($alertesDocs)): ?>
        <p class="text-center text-muted py-3 mb-0" style="font-size:0.85rem"><i class="fa fa-circle-check text-success me-1"></i>Tous les documents sont valides (60 jours)</p>
        <?php else: ?>
        <?php foreach ($alertesDocs as $d):
          $jours = (int)ceil((strtotime($d['date_expiration']) - time()) / 86400);
          $typeLabel = $labelsDoc[$d['type_document']] ?? ucfirst(str_replace('_',' ',$d['type_document']));
        ?>
        <a href="../agents/view.php?id=<?= $d['agent_id'] ?>" style="text-decoration:none;color:inherit">
          <div class="d-flex align-items-center gap-3 px-3 py-2" style="border-bottom:1px solid #f0f2f5;transition:background .15s" onmouseover="this.style.background='#fff5f5'" onmouseout="this.style.background=''">
            <i class="fa fa-id-card" style="color:<?= $jours<=0?'#dc2626':($jours<=14?'#dc2626':'#f59e0b') ?>;flex-shrink:0"></i>
            <div style="flex:1;min-width:0">
              <div style="font-size:0.78rem;font-weight:600;color:var(--ov-navy)"><?= h($d['prenom'].' '.$d['nom']) ?></div>
              <div style="font-size:0.78rem;color:#6b7280"><?= h($typeLabel) ?></div>
            </div>
            <span class="badge <?= $jours<=0?'bg-danger':($jours<=14?'bg-danger':'bg-warning text-dark') ?>" style="font-size:0.72rem;flex-shrink:0">
              <?= $jours<=0 ? 'Expiré' : "J-$jours" ?>
            </span>
          </div>
        </a>
        <?php endforeach; ?>
        <?php endif; ?>
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
