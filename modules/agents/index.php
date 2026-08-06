<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'view');
ensureInscriptionSchema();

$db = getDB();

// Réinitialiser toutes les alertes ignorées (tous les agents)
if (($_POST['action'] ?? '') === 'reset_all_alertes') {
    requirePerm('agents','edit');
    $db->exec("UPDATE agents SET alertes_ignorees=NULL WHERE alertes_ignorees IS NOT NULL");
    flash('success','Alertes réinitialisées pour tous les agents.');
    header('Location: index.php'); exit;
}

$pageTitle    = 'Liste des agents';
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';

$search = trim($_GET['q'] ?? '');
$filtre = $_GET['filtre'] ?? 'actif';

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = "(a.nom LIKE ? OR a.prenom LIKE ? OR a.matricule LIKE ? OR a.num_autorisation_cnaps LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s,$s,$s,$s]);
}
if ($filtre === 'actif')   { $where[] = 'a.actif = 1'; }
if ($filtre === 'inactif') { $where[] = "a.actif = 0 AND (a.statut_inscription IS NULL OR a.statut_inscription NOT IN ('en_attente','refusee'))"; }

$sql = "SELECT a.*,
        (SELECT COUNT(*) FROM agent_documents d WHERE d.agent_id = a.id) as nb_docs,
        (SELECT GROUP_CONCAT(d2.type_document) FROM agent_documents d2 WHERE d2.agent_id = a.id) as doc_types,
        (SELECT GROUP_CONCAT(CONCAT(d3.type_document,'|',IFNULL(d3.date_expiration,'')) SEPARATOR ';;')
         FROM agent_documents d3 WHERE d3.agent_id = a.id) as doc_expiries
        FROM agents a
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.nom, a.prenom";
// Migration si colonne absente
try { getDB()->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS alertes_ignorees TEXT NULL"); } catch(Exception $e){}
$stmt = $db->prepare($sql);
$stmt->execute($params);
$agents = $stmt->fetchAll();

// Pré-calculer la complétude pour chaque agent
foreach ($agents as &$ag) {
    $types   = $ag['doc_types'] ? explode(',', $ag['doc_types']) : [];
    $ignored = json_decode($ag['alertes_ignorees'] ?? '[]', true) ?: [];
    // Reconstruire les lignes documents (type + date_expiration) depuis la chaîne concaténée
    $docs = [];
    if (!empty($ag['doc_expiries'])) {
        foreach (explode(';;', $ag['doc_expiries']) as $pair) {
            $parts = explode('|', $pair, 2);
            $docs[] = ['type_document' => $parts[0], 'date_expiration' => $parts[1] !== '' ? $parts[1] : null];
        }
    }
    $ag['_completion'] = agentCompletion($ag, $types, $docs, $ignored);
}
unset($ag);

$total   = $db->query("SELECT COUNT(*) FROM agents WHERE statut_inscription IS NULL OR statut_inscription NOT IN ('en_attente','refusee')")->fetchColumn();
$actifs  = $db->query("SELECT COUNT(*) FROM agents WHERE actif=1")->fetchColumn();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="d-flex gap-2">
        <div class="stat-card py-2 px-3" style="gap:0.6rem">
            <div class="stat-icon gold" style="width:36px;height:36px;font-size:1rem"><i class="fa fa-users"></i></div>
            <div><div class="stat-value" style="font-size:1.25rem"><?= $total ?></div><div class="stat-label">Total</div></div>
        </div>
        <div class="stat-card py-2 px-3" style="gap:0.6rem">
            <div class="stat-icon green" style="width:36px;height:36px;font-size:1rem"><i class="fa fa-circle-check"></i></div>
            <div><div class="stat-value" style="font-size:1.25rem"><?= $actifs ?></div><div class="stat-label">Actifs</div></div>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (canDo('agents','edit')): ?>
        <form method="POST" onsubmit="return confirm('Réinitialiser toutes les alertes ignorées pour tous les agents ?')">
            <input type="hidden" name="action" value="reset_all_alertes">
            <button type="submit" class="btn" style="background:rgba(107,114,128,0.08);border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;color:#6b7280">
                <i class="fa fa-rotate me-1"></i>Réinitialiser les alertes
            </button>
        </form>
        <?php endif; ?>
        <?php if (canDo('agents','create')): ?>
        <a href="inscriptions.php" class="btn" style="background:rgba(234,179,8,0.1);border:1px solid rgba(234,179,8,0.3);border-radius:8px;font-size:0.85rem;color:#92400e">
            <i class="fa fa-user-clock me-1"></i>Inscriptions en attente
            <?php $nbEnAttente = countInscriptionsEnAttente(); ?>
            <?php if ($nbEnAttente > 0): ?><span class="badge bg-danger rounded-pill ms-1"><?= $nbEnAttente ?></span><?php endif; ?>
        </a>
        <a href="invitation_recrutement.php" class="btn" style="background:rgba(99,102,241,0.1);border:1px solid rgba(99,102,241,0.3);border-radius:8px;font-size:0.85rem;color:#3730a3">
            <i class="fa fa-envelope-open-text me-1"></i>Inviter un candidat
        </a>
        <a href="add.php" class="btn-ov-primary btn">
            <i class="fa fa-user-plus me-1"></i> Nouvel agent
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="ov-card">
    <div class="ov-card-header">
        <h2 class="ov-card-title"><i class="fa fa-user-shield me-2" style="color:var(--ov-gold)"></i>Agents de sécurité</h2>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <!-- Filtres -->
            <div class="btn-group btn-group-sm">
                <?php foreach (['tous'=>'Tous','actif'=>'Actifs','inactif'=>'Inactifs'] as $k=>$v): ?>
                <a href="?filtre=<?= $k ?>&q=<?= urlencode($search) ?>"
                   class="btn <?= $filtre===$k ? 'btn-dark' : 'btn-outline-secondary' ?>" style="font-size:0.78rem"><?= $v ?></a>
                <?php endforeach; ?>
            </div>
            <!-- Recherche -->
            <form method="GET" class="d-flex gap-1">
                <input type="hidden" name="filtre" value="<?= h($filtre) ?>">
                <input type="text" name="q" value="<?= h($search) ?>" class="form-control form-control-sm" placeholder="Nom, matricule, CNAPS..." style="width:200px">
                <button class="btn btn-sm btn-ov-primary"><i class="fa fa-search"></i></button>
            </form>
        </div>
    </div>
    <div class="ov-card-body p-0">
        <?php if (empty($agents)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-user-slash fa-3x mb-3 opacity-25"></i>
            <p>Aucun agent trouvé</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="ov-table">
            <thead>
                <tr>
                    <th>Matricule</th>
                    <th>Agent</th>
                    <th>Poste</th>
                    <th>Contrat</th>
                    <th>N° CNAPS</th>
                    <th>Exp. CNAPS</th>
                    <th>Docs</th>
                    <th>Dossier</th>
                    <th>Statut</th>
                    <th style="width:110px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($agents as $a): ?>
            <tr class="row-link" onclick="if(!event.target.closest('a,button,form')) window.location='view.php?id=<?= $a['id'] ?>'" style="cursor:pointer">
                <td><code class="text-dark fw-bold"><?= h($a['matricule'] ?? '—') ?></code></td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <?php if ($a['photo']): ?>
                        <img src="<?= UPLOAD_URL ?>/<?= h($a['photo']) ?>" class="rounded-circle" style="width:34px;height:34px;object-fit:cover">
                        <?php else: ?>
                        <div style="width:34px;height:34px;border-radius:50%;background:rgba(201,168,76,0.15);display:flex;align-items:center;justify-content:center;color:var(--ov-gold);font-weight:700;font-size:0.8rem;flex-shrink:0">
                            <?= strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1)) ?>
                        </div>
                        <?php endif; ?>
                        <div>
                            <div class="fw-600"><?= h($a['prenom'].' '.$a['nom']) ?></div>
                            <div style="font-size:0.75rem;color:#9ca3af"><?= h($a['email'] ?? '') ?></div>
                        </div>
                    </div>
                </td>
                <td><?= h($a['poste'] ?? '—') ?></td>
                <td>
                    <?php if ($a['type_contrat']): ?>
                    <span class="badge-ov badge-<?= strtolower(str_replace(' ','',($a['type_contrat']))) ?>" style="background:rgba(201,168,76,0.1);color:var(--ov-gold-dark);padding:2px 8px;border-radius:20px;font-size:0.72rem;font-weight:600">
                        <?= h($a['type_contrat']) ?>
                    </span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= h($a['num_autorisation_cnaps'] ?? '—') ?></td>
                <td>
                    <?php if ($a['date_expiration_cnaps']): ?>
                        <?php $exp = strtotime($a['date_expiration_cnaps']); $soon = $exp < strtotime('+30 days'); ?>
                        <span class="<?= $soon ? 'text-danger fw-bold' : '' ?>"><?= formatDate($a['date_expiration_cnaps']) ?></span>
                        <?php if ($soon): ?><i class="fa fa-triangle-exclamation text-warning ms-1" title="Expire bientôt"></i><?php endif; ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <span class="badge-ov" style="background:rgba(99,102,241,0.1);color:#4f46e5;padding:2px 8px;border-radius:20px;font-size:0.72rem">
                        <i class="fa fa-paperclip"></i> <?= $a['nb_docs'] ?>
                    </span>
                </td>
                <td>
                    <?php $comp = $a['_completion']; ?>
                    <?php if ($comp['ok']): ?>
                    <span style="color:#16a34a;font-size:0.78rem;font-weight:600"><i class="fa fa-circle-check me-1"></i>Complet</span>
                    <?php else: ?>
                    <?php
                        $errCount  = count($comp['errors']);
                        $warnCount = count($comp['warnings']);
                        $tipParts  = [];
                        foreach ($comp['errors']   as $e) $tipParts[] = '⛔ '.$e['label'];
                        foreach ($comp['warnings']  as $w) $tipParts[] = '⚠ '.$w['label'];
                        $tipColor  = $errCount > 0 ? '#ef4444' : '#f59e0b';
                        $tipIcon   = $errCount > 0 ? 'fa-circle-xmark' : 'fa-triangle-exclamation';
                        $tipLabel  = $errCount > 0
                            ? $errCount.' erreur'.($errCount>1?'s':'').($warnCount?" + $warnCount alerte".($warnCount>1?'s':''):'')
                            : $warnCount.' alerte'.($warnCount>1?'s':'');
                    ?>
                    <a href="view.php?id=<?= $a['id'] ?>#alerte-dossier"
                       style="color:<?= $tipColor ?>;font-size:0.78rem;font-weight:600;text-decoration:none"
                       title="<?= h(implode(' | ', $tipParts)) ?>">
                        <i class="fa <?= $tipIcon ?> me-1"></i><?= h($tipLabel) ?>
                    </a>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge-ov badge-<?= $a['actif'] ? 'actif' : 'inactif' ?>">
                        <?= $a['actif'] ? 'Actif' : 'Inactif' ?>
                    </span>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="view.php?id=<?= $a['id'] ?>" class="btn-sm-icon view" title="Voir fiche"><i class="fa fa-eye"></i></a>
                        <?php if (canDo('agents','edit')): ?>
                        <a href="edit.php?id=<?= $a['id'] ?>" class="btn-sm-icon edit" title="Modifier"><i class="fa fa-pen"></i></a>
                        <?php endif; ?>
                        <a href="carte.php?id=<?= $a['id'] ?>" class="btn-sm-icon print" title="Carte agent"><i class="fa fa-id-card"></i></a>
                        <a href="export_pdf.php?id=<?= $a['id'] ?>" class="btn-sm-icon" style="background:rgba(239,68,68,0.1);color:#dc2626" title="Export PDF"><i class="fa fa-file-pdf"></i></a>
                        <?php if (canDo('agents','delete')): ?>
                        <a href="view.php?id=<?= $a['id'] ?>#" onclick="event.preventDefault();document.getElementById('del-<?= $a['id'] ?>').submit()" class="btn-sm-icon delete" title="Supprimer / Désactiver"><i class="fa fa-trash"></i></a>
                        <form id="del-<?= $a['id'] ?>" method="POST" action="delete.php" style="display:none" onsubmit="return confirm('Désactiver <?= addslashes($a['prenom'].' '.$a['nom']) ?> ?\n\nPour une suppression définitive, ouvrez la fiche agent.')">
                            <input type="hidden" name="id" value="<?= $a['id'] ?>">
                            <input type="hidden" name="action" value="deactivate">
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
