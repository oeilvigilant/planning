<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
ensureMissionsSchema();
ensureClientsSchema();

$db     = getDB();
$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

if ($isEdit) {
    requirePerm('missions', 'edit');
    $mission = $db->prepare("SELECT * FROM missions WHERE id = ?");
    $mission->execute([$id]);
    $mission = $mission->fetch();
    if (!$mission) { flash('danger', 'Mission introuvable.'); header('Location: index.php'); exit; }
} else {
    requirePerm('missions', 'create');
    $mission = ['id'=>0,'nom'=>'','client_id'=>null,'lieu'=>'','description'=>'','actif'=>1];
}

$clients = $db->query("SELECT id, nom FROM clients ORDER BY nom")->fetchAll();
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom        = trim($_POST['nom'] ?? '');
    $clientId   = (int)($_POST['client_id'] ?? 0) ?: null;
    $lieu       = trim($_POST['lieu'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $actif      = $isEdit ? (isset($_POST['actif']) ? 1 : 0) : 1;

    if (empty($nom)) $errors[] = 'Le nom de la mission est obligatoire.';

    if (empty($errors)) {
        if ($isEdit) {
            $db->prepare("UPDATE missions SET nom=?, client_id=?, lieu=?, description=?, actif=? WHERE id=?")
               ->execute([$nom, $clientId, $lieu, $description, $actif, $id]);
            flash('success', 'Mission <strong>' . h($nom) . '</strong> mise à jour.');
        } else {
            $db->prepare("INSERT INTO missions (nom, client_id, lieu, description, actif, created_by) VALUES (?,?,?,?,1,?)")
               ->execute([$nom, $clientId, $lieu, $description, getCurrentUser()['id']]);
            $newId = (int)$db->lastInsertId();
            flash('success', 'Mission <strong>' . h($nom) . '</strong> créée. Affectez maintenant des agents.');
            header('Location: agents.php?id=' . $newId); exit;
        }
        header('Location: index.php');
        exit;
    }
    $mission = array_merge($mission, compact('nom','clientId','lieu','description','actif'));
    $mission['client_id'] = $clientId;
}

$pageTitle     = $isEdit ? 'Modifier — ' . h($mission['nom']) : 'Nouvelle mission';
$currentModule = 'missions';
$topbarActions = '<a href="index.php" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Retour</a>';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="ov-card" style="max-width:700px">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-map-location-dot me-2" style="color:var(--ov-gold)"></i>
            <?= $isEdit ? 'Modifier la mission' : 'Nouvelle mission' ?>
        </h2>
    </div>
    <div class="ov-card-body">
        <form method="POST">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Nom de la mission <span class="text-danger">*</span></label>
                    <input type="text" name="nom" class="form-control"
                        value="<?= h($mission['nom']) ?>"
                        placeholder="Ex: Site Logistique Nord, Gardiennage Mairie…" required autofocus>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Client</label>
                    <select name="client_id" class="form-select">
                        <option value="">Aucun client</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (int)($mission['client_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Lieu</label>
                    <input type="text" name="lieu" class="form-control"
                        value="<?= h($mission['lieu']) ?>"
                        placeholder="Adresse ou site">
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?= h($mission['description']) ?></textarea>
                </div>
                <?php if ($isEdit): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="actif" id="actif" class="form-check-input" <?= $mission['actif'] ? 'checked' : '' ?>>
                        <label for="actif" class="form-check-label">Mission active</label>
                    </div>
                    <?php if ($mission['is_default']): ?>
                    <div class="form-text" style="font-size:0.75rem">
                        <i class="fa fa-circle-info me-1"></i>Mission par défaut : si vous la désactivez et qu'aucune autre mission n'est active, le planning affichera un message bloquant tant qu'une mission active n'existe pas.
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-ov-primary">
                        <i class="fa fa-save me-2"></i><?= $isEdit ? 'Enregistrer' : 'Créer la mission' ?>
                    </button>
                    <a href="index.php" class="btn btn-ov-secondary">Annuler</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
