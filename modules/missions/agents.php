<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('missions', 'edit');
ensureMissionsSchema();

$db = getDB();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM missions WHERE id = ?");
$stmt->execute([$id]);
$mission = $stmt->fetch();
if (!$mission) { flash('danger', 'Mission introuvable.'); header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = array_map('intval', $_POST['agent_ids'] ?? []);

    $curStmt = $db->prepare("SELECT agent_id FROM mission_agents WHERE mission_id = ?");
    $curStmt->execute([$id]);
    $current = array_map('intval', $curStmt->fetchAll(PDO::FETCH_COLUMN));

    $toAdd    = array_diff($submitted, $current);
    $toRemove = array_diff($current, $submitted);

    $insert = $db->prepare("INSERT IGNORE INTO mission_agents (mission_id, agent_id) VALUES (?,?)");
    foreach ($toAdd as $aid) $insert->execute([$id, $aid]);

    $delete = $db->prepare("DELETE FROM mission_agents WHERE mission_id=? AND agent_id=?");
    foreach ($toRemove as $aid) $delete->execute([$id, $aid]);

    flash('success', 'Affectations mises à jour pour <strong>' . h($mission['nom']) . '</strong>.');
    header('Location: agents.php?id=' . $id); exit;
}

// Tous les agents actifs, plus ceux déjà affectés même s'ils ont été désactivés depuis
$agents = $db->prepare("
    SELECT a.id, a.nom, a.prenom, a.matricule, a.poste, a.actif,
        (SELECT COUNT(*) FROM mission_agents ma WHERE ma.mission_id = ? AND ma.agent_id = a.id) AS affecte
    FROM agents a
    WHERE a.actif = 1 OR a.id IN (SELECT agent_id FROM mission_agents WHERE mission_id = ?)
    ORDER BY a.nom, a.prenom
");
$agents->execute([$id, $id]);
$agents = $agents->fetchAll();

$pageTitle     = 'Affectation — ' . $mission['nom'];
$currentModule = 'missions';
$topbarActions = '<a href="index.php" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Retour</a>';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="ov-card" style="max-width:800px">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-users me-2" style="color:var(--ov-gold)"></i>
            Agents affectés — <?= h($mission['nom']) ?>
        </h2>
    </div>
    <div class="ov-card-body">
        <?php if (empty($agents)): ?>
        <div class="text-center py-4 text-muted">Aucun agent actif dans le système.</div>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="row g-2 mb-3">
                <?php foreach ($agents as $ag): ?>
                <div class="col-md-6">
                    <label class="d-flex align-items-center gap-2 p-2 rounded" style="background:#f8f9fa;border:1px solid #e5e7eb;font-size:0.85rem">
                        <input type="checkbox" class="form-check-input" name="agent_ids[]" value="<?= $ag['id'] ?>" style="margin-top:0" <?= $ag['affecte'] ? 'checked' : '' ?>>
                        <span>
                            <?= h($ag['prenom'] . ' ' . $ag['nom']) ?>
                            <span class="text-muted"><?= $ag['matricule'] ? '(' . h($ag['matricule']) . ')' : '' ?></span>
                            <?php if (!$ag['actif']): ?><span class="badge bg-secondary ms-1" style="font-size:0.65rem">inactif</span><?php endif; ?>
                        </span>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Enregistrer les affectations</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
