<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('clients', 'view');

$db = getDB();
ensureClientsSchema();

$search = trim($_GET['search'] ?? '');
if ($search) {
    $s    = '%' . $search . '%';
    $stmt = $db->prepare("
        SELECT c.*,
            (SELECT COUNT(*) FROM devis d WHERE d.client_id = c.id) AS nb_devis
        FROM clients c
        WHERE c.nom LIKE ? OR c.contact LIKE ? OR c.email LIKE ? OR c.telephone LIKE ?
        ORDER BY c.nom
    ");
    $stmt->execute([$s, $s, $s, $s]);
} else {
    $stmt = $db->query("
        SELECT c.*,
            (SELECT COUNT(*) FROM devis d WHERE d.client_id = c.id) AS nb_devis
        FROM clients c ORDER BY c.nom
    ");
}
$clients = $stmt->fetchAll();

$pageTitle     = 'Clients';
$currentModule = 'clients';
$topbarActions = canDo('clients','create')
    ? '<a href="add.php" class="btn btn-ov-primary btn-sm"><i class="fa fa-plus me-1"></i> Nouveau client</a>'
    : '';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="ov-card">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-building me-2" style="color:var(--ov-gold)"></i>Clients
            <span class="badge bg-secondary ms-2"><?= count($clients) ?></span>
        </h2>
        <form method="GET" class="d-flex gap-2 ms-auto">
            <input type="text" name="search" class="form-control form-control-sm"
                placeholder="Rechercher…" value="<?= h($search) ?>" style="width:220px">
            <button class="btn btn-sm btn-ov-secondary"><i class="fa fa-search"></i></button>
            <?php if ($search): ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary" title="Effacer"><i class="fa fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="ov-card-body p-0">
        <?php if (empty($clients)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-building fa-2x mb-2 d-block opacity-25"></i>
            <?= $search ? 'Aucun client ne correspond à "<strong>' . h($search) . '</strong>".' : 'Aucun client enregistré.' ?>
            <?php if (!$search && canDo('clients','create')): ?>
            <div class="mt-2">
                <a href="add.php" class="btn btn-ov-primary btn-sm">
                    <i class="fa fa-plus me-1"></i> Ajouter le premier client
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="ov-table">
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Contact</th>
                    <th>Téléphone</th>
                    <th>Email</th>
                    <th class="text-center" style="width:70px">Devis</th>
                    <th style="width:100px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($clients as $c): ?>
            <tr>
                <td>
                    <div class="fw-600"><?= h($c['nom']) ?></div>
                    <?php if ($c['adresse']): ?>
                    <small class="text-muted"><?= h(mb_strimwidth($c['adresse'], 0, 60, '…')) ?></small>
                    <?php endif; ?>
                </td>
                <td><?= h($c['contact'] ?: '—') ?></td>
                <td><?= h($c['telephone'] ?: '—') ?></td>
                <td><?= h($c['email'] ?: '—') ?></td>
                <td class="text-center">
                    <?php if ($c['nb_devis'] > 0): ?>
                    <a href="../devis/index.php?client_id=<?= $c['id'] ?>" class="badge bg-primary text-decoration-none">
                        <?= $c['nb_devis'] ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td>
                    <div class="d-flex gap-1 justify-content-end">
                        <?php if (canDo('clients','edit')): ?>
                        <a href="add.php?id=<?= $c['id'] ?>" class="btn-sm-icon edit" title="Modifier">
                            <i class="fa fa-pen"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (canDo('clients','delete')): ?>
                        <form method="POST" action="delete.php" style="display:inline"
                            onsubmit="return confirm('Supprimer <?= h(addslashes($c['nom'])) ?> ?')">
                            <input type="hidden" name="id" value="<?= $c['id'] ?>">
                            <button type="submit" class="btn-sm-icon delete" title="Supprimer">
                                <i class="fa fa-trash"></i>
                            </button>
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
