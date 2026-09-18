<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('produits', 'view');

$db = getDB();
ensureProduitsSchema();

$familles = produitFamilles();
$search   = trim($_GET['search'] ?? '');
$famille  = $_GET['famille'] ?? '';
$statut   = $_GET['statut'] ?? 'actifs';

$where  = [];
$params = [];
if ($search) {
    $where[] = "(code LIKE ? OR designation_courte LIKE ? OR designation_longue LIKE ?)";
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s);
}
if ($famille && isset($familles[$famille])) {
    $where[] = "famille = ?";
    $params[] = $famille;
}
if ($statut === 'actifs')   $where[] = "actif = 1";
if ($statut === 'inactifs') $where[] = "actif = 0";

$sql = "SELECT * FROM produits";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY famille, ordre, code";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$produits = $stmt->fetchAll();

$qualifs = produitQualifications();
$types   = produitTypesHeure();
$unites  = produitUnites();

$pageTitle     = 'Produits & Prestations';
$currentModule = 'produits';
$topbarActions = '';
if (canDo('produits','export')) {
    $qs = http_build_query(['search'=>$search,'famille'=>$famille,'statut'=>$statut]);
    $topbarActions .= '<a href="export.php?' . h($qs) . '" class="btn btn-ov-secondary btn-sm"><i class="fa fa-file-export me-1"></i> Exporter</a> ';
}
if (canDo('produits','create')) {
    $topbarActions .= '<a href="import.php" class="btn btn-ov-secondary btn-sm"><i class="fa fa-file-import me-1"></i> Importer</a> ';
    $topbarActions .= '<a href="add.php" class="btn btn-ov-primary btn-sm"><i class="fa fa-plus me-1"></i> Nouveau produit</a>';
}
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="ov-card">
    <div class="ov-card-header flex-wrap gap-2">
        <h2 class="ov-card-title">
            <i class="fa fa-boxes-stacked me-2" style="color:var(--ov-gold)"></i>Produits & Prestations
            <span class="badge bg-secondary ms-2"><?= count($produits) ?></span>
        </h2>
        <form method="GET" class="d-flex gap-2 ms-auto flex-wrap">
            <input type="text" name="search" class="form-control form-control-sm"
                placeholder="Code, désignation…" value="<?= h($search) ?>" style="width:200px">
            <select name="famille" class="form-select form-select-sm" style="width:200px">
                <option value="">Toutes les familles</option>
                <?php foreach ($familles as $k=>$v): ?>
                <option value="<?= h($k) ?>" <?= $famille===$k?'selected':'' ?>><?= h($v) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="statut" class="form-select form-select-sm" style="width:150px">
                <option value="actifs"   <?= $statut==='actifs'?'selected':'' ?>>Actifs</option>
                <option value="inactifs" <?= $statut==='inactifs'?'selected':'' ?>>Inactifs</option>
                <option value="tous"     <?= $statut==='tous'?'selected':'' ?>>Tous</option>
            </select>
            <button class="btn btn-sm btn-ov-secondary"><i class="fa fa-search"></i></button>
            <?php if ($search || $famille || $statut!=='actifs'): ?>
            <a href="index.php" class="btn btn-sm btn-outline-secondary" title="Réinitialiser"><i class="fa fa-times"></i></a>
            <?php endif; ?>
        </form>
    </div>
    <div class="ov-card-body p-0">
        <?php if (empty($produits)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-boxes-stacked fa-2x mb-2 d-block opacity-25"></i>
            <?= ($search || $famille) ? 'Aucun produit ne correspond à ces critères.' : 'Aucun produit enregistré.' ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="ov-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Désignation</th>
                    <th>Famille</th>
                    <th>Unité</th>
                    <th class="text-end">Prix par défaut</th>
                    <th class="text-center" style="width:70px">TVA</th>
                    <th class="text-center" style="width:90px">Statut</th>
                    <th style="width:100px"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($produits as $p): ?>
            <tr>
                <td><code><?= h($p['code']) ?></code></td>
                <td>
                    <div class="fw-600"><?= h($p['designation_courte']) ?></div>
                    <small class="text-muted"><?= h($p['designation_longue']) ?></small>
                </td>
                <td>
                    <span class="badge bg-light text-dark border"><?= h($familles[$p['famille']] ?? $p['famille']) ?></span>
                    <?php if ($p['qualification'] || $p['type_heure']): ?>
                    <br><small class="text-muted">
                        <?= h($qualifs[$p['qualification']] ?? '') ?><?= ($p['qualification'] && $p['type_heure']) ? ' · ' : '' ?><?= h($types[$p['type_heure']] ?? '') ?>
                    </small>
                    <?php endif; ?>
                </td>
                <td><?= h($unites[$p['unite']] ?? $p['unite']) ?></td>
                <td class="text-end fw-600"><?= number_format((float)$p['prix_defaut'], 2, ',', ' ') ?> €</td>
                <td class="text-center"><?= number_format((float)$p['tva_taux'], 0) ?>%</td>
                <td class="text-center">
                    <form method="POST" action="toggle.php" style="display:inline">
                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                        <input type="hidden" name="back" value="<?= h($_SERVER['QUERY_STRING'] ?? '') ?>">
                        <button type="submit" class="btn btn-sm <?= $p['actif'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                            <?= canDo('produits','edit') ? '' : 'disabled' ?>
                            title="<?= $p['actif'] ? 'Désactiver' : 'Activer' ?>">
                            <?= $p['actif'] ? 'Actif' : 'Inactif' ?>
                        </button>
                    </form>
                </td>
                <td>
                    <div class="d-flex gap-1 justify-content-end">
                        <?php if (canDo('produits','edit')): ?>
                        <a href="add.php?id=<?= $p['id'] ?>" class="btn-sm-icon edit" title="Modifier">
                            <i class="fa fa-pen"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (canDo('produits','delete')): ?>
                        <form method="POST" action="delete.php" style="display:inline"
                            onsubmit="return confirm('Supprimer <?= h(addslashes($p['designation_courte'])) ?> ?')">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
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
