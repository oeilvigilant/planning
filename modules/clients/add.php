<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$db = getDB();
ensureClientsSchema();

$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

if ($isEdit) {
    requirePerm('clients', 'edit');
    $client = $db->prepare("SELECT * FROM clients WHERE id = ?");
    $client->execute([$id]);
    $client = $client->fetch();
    if (!$client) { flash('danger', 'Client introuvable.'); header('Location: index.php'); exit; }
} else {
    requirePerm('clients', 'create');
    $client = ['id'=>0,'nom'=>'','adresse'=>'','email'=>'','telephone'=>'','contact'=>''];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom       = trim($_POST['nom']       ?? '');
    $adresse   = trim($_POST['adresse']   ?? '');
    $email     = trim($_POST['email']     ?? '');
    $telephone = trim($_POST['telephone'] ?? '');
    $contact   = trim($_POST['contact']   ?? '');

    if (empty($nom)) $errors[] = 'Le nom du client est obligatoire.';

    if (empty($errors)) {
        if ($isEdit) {
            $db->prepare("UPDATE clients SET nom=?, adresse=?, email=?, telephone=?, contact=? WHERE id=?")
               ->execute([$nom, $adresse, $email, $telephone, $contact, $id]);
            flash('success', 'Client <strong>' . h($nom) . '</strong> mis à jour.');
        } else {
            $db->prepare("INSERT INTO clients (nom, adresse, email, telephone, contact) VALUES (?,?,?,?,?)")
               ->execute([$nom, $adresse, $email, $telephone, $contact]);
            flash('success', 'Client <strong>' . h($nom) . '</strong> ajouté.');
        }
        header('Location: index.php');
        exit;
    }
    $client = array_merge($client, compact('nom','adresse','email','telephone','contact'));
}

$pageTitle     = $isEdit ? 'Modifier — ' . h($client['nom']) : 'Nouveau client';
$currentModule = 'clients';
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
            <i class="fa fa-building me-2" style="color:var(--ov-gold)"></i>
            <?= $isEdit ? 'Modifier le client' : 'Nouveau client' ?>
        </h2>
    </div>
    <div class="ov-card-body">
        <form method="POST">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Nom du client / Société <span class="text-danger">*</span></label>
                    <input type="text" name="nom" class="form-control"
                        value="<?= h($client['nom']) ?>"
                        placeholder="Ex: Société Dupont, Mairie de Paris…" required autofocus>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact (interlocuteur)</label>
                    <input type="text" name="contact" class="form-control"
                        value="<?= h($client['contact']) ?>"
                        placeholder="Nom du responsable">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Téléphone</label>
                    <input type="text" name="telephone" class="form-control"
                        value="<?= h($client['telephone']) ?>"
                        placeholder="06 xx xx xx xx">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                        value="<?= h($client['email']) ?>"
                        placeholder="contact@societe.fr">
                </div>
                <div class="col-12">
                    <label class="form-label">Adresse complète</label>
                    <textarea name="adresse" class="form-control" rows="3"
                        placeholder="Numéro, rue, code postal, ville"><?= h($client['adresse']) ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-ov-primary">
                        <i class="fa fa-save me-2"></i><?= $isEdit ? 'Enregistrer' : 'Créer le client' ?>
                    </button>
                    <a href="index.php" class="btn btn-ov-secondary">Annuler</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
