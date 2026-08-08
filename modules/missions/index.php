<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('missions', 'view');
ensureMissionsSchema();

$db     = getDB();
$filtre = $_GET['filtre'] ?? 'actif';

$where = ['1=1'];
if ($filtre === 'actif')   $where[] = 'm.actif = 1';
if ($filtre === 'inactif') $where[] = 'm.actif = 0';

$missions = $db->query("
    SELECT m.*, c.nom AS client_nom,
        (SELECT COUNT(*) FROM mission_agents ma WHERE ma.mission_id = m.id) AS nb_agents
    FROM missions m
    LEFT JOIN clients c ON c.id = m.client_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY m.is_default DESC, m.nom
")->fetchAll();

$pageTitle     = 'Missions';
$currentModule = 'missions';
$topbarActions = canDo('missions','create')
    ? '<a href="add.php" class="btn btn-ov-primary btn-sm"><i class="fa fa-plus me-1"></i> Nouvelle mission</a>'
    : '';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="ov-card">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-map-location-dot me-2" style="color:var(--ov-gold)"></i>Missions
            <span class="badge bg-secondary ms-2"><?= count($missions) ?></span>
        </h2>
        <div class="btn-group btn-group-sm">
            <?php foreach (['tous'=>'Toutes','actif'=>'Actives','inactif'=>'Inactives'] as $k=>$v): ?>
            <a href="?filtre=<?= $k ?>" class="btn <?= $filtre===$k ? 'btn-dark' : 'btn-outline-secondary' ?>" style="font-size:0.78rem"><?= $v ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="ov-card-body p-0">
        <?php if (empty($missions)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-map-location-dot fa-2x mb-2 d-block opacity-25"></i>
            Aucune mission trouvée.
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="ov-table">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Client</th>
                    <th>Lieu</th>
                    <th class="text-center">Agents affectés</th>
                    <th>Statut</th>
                    <th style="width:150px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($missions as $m): ?>
            <tr>
                <td>
                    <div class="fw-600"><?= h($m['nom']) ?></div>
                    <?php if ($m['is_default']): ?>
                    <small class="text-muted"><i class="fa fa-star me-1" style="color:var(--ov-gold)"></i>Mission par défaut</small>
                    <?php endif; ?>
                </td>
                <td><?= h($m['client_nom'] ?: '—') ?></td>
                <td><?= h($m['lieu'] ?: '—') ?></td>
                <td class="text-center">
                    <a href="agents.php?id=<?= $m['id'] ?>" class="badge-ov" style="background:rgba(99,102,241,0.1);color:#4f46e5;padding:2px 8px;border-radius:20px;font-size:0.72rem;text-decoration:none">
                        <i class="fa fa-users"></i> <?= $m['nb_agents'] ?>
                    </a>
                </td>
                <td>
                    <span class="badge-ov badge-<?= $m['actif'] ? 'actif' : 'inactif' ?>">
                        <?= $m['actif'] ? 'Active' : 'Inactive' ?>
                    </span>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="../planning/index.php?mission=<?= $m['id'] ?>" class="btn-sm-icon view" title="Planning de la mission"><i class="fa fa-calendar-days"></i></a>
                        <a href="agents.php?id=<?= $m['id'] ?>" class="btn-sm-icon" style="background:rgba(99,102,241,0.1);color:#4f46e5" title="Affecter des agents"><i class="fa fa-users"></i></a>
                        <?php if (canDo('missions','edit')): ?>
                        <a href="add.php?id=<?= $m['id'] ?>" class="btn-sm-icon edit" title="Modifier"><i class="fa fa-pen"></i></a>
                        <?php endif; ?>
                        <?php if (canDo('missions','delete')): ?>
                        <form method="POST" action="delete.php" style="display:inline" onsubmit="return confirm('<?= $m['actif'] ? 'Désactiver' : 'Réactiver' ?> la mission <?= h(addslashes($m['nom'])) ?> ?')">
                            <input type="hidden" name="id" value="<?= $m['id'] ?>">
                            <input type="hidden" name="action" value="<?= $m['actif'] ? 'deactivate' : 'reactivate' ?>">
                            <button type="submit" class="btn-sm-icon <?= $m['actif'] ? 'delete' : '' ?>" style="<?= $m['actif'] ? '' : 'background:rgba(34,197,94,0.1);color:#16a34a' ?>" title="<?= $m['actif'] ? 'Désactiver' : 'Réactiver' ?>"><i class="fa <?= $m['actif'] ? 'fa-trash' : 'fa-rotate-left' ?>"></i></button>
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
