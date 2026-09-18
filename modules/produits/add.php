<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$db = getDB();
ensureProduitsSchema();

$familles = produitFamilles();
$qualifs  = produitQualifications();
$types    = produitTypesHeure();
$unites   = produitUnites();

$id     = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

if ($isEdit) {
    requirePerm('produits', 'edit');
    $stmt = $db->prepare("SELECT * FROM produits WHERE id = ?");
    $stmt->execute([$id]);
    $produit = $stmt->fetch();
    if (!$produit) { flash('danger', 'Produit introuvable.'); header('Location: index.php'); exit; }
} else {
    requirePerm('produits', 'create');
    $produit = [
        'id' => 0, 'code' => '', 'famille' => 'agent_securite', 'qualification' => '', 'type_heure' => '',
        'designation_courte' => '', 'designation_longue' => '', 'unite' => 'heure',
        'prix_defaut' => '', 'tva_taux' => '20.00', 'actif' => 1, 'notes' => '', 'ordre' => 0,
    ];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code       = strtoupper(trim($_POST['code'] ?? ''));
    $famille    = $_POST['famille'] ?? '';
    $qualif     = trim($_POST['qualification'] ?? '');
    $typeHeure  = trim($_POST['type_heure'] ?? '');
    $courte     = trim($_POST['designation_courte'] ?? '');
    $longue     = trim($_POST['designation_longue'] ?? '');
    $unite      = $_POST['unite'] ?? 'heure';
    $prix       = (float)str_replace(',', '.', $_POST['prix_defaut'] ?? '0');
    $tva        = (float)str_replace(',', '.', $_POST['tva_taux'] ?? '20');
    $actif      = isset($_POST['actif']) ? 1 : 0;
    $notes      = trim($_POST['notes'] ?? '');
    $ordre      = (int)($_POST['ordre'] ?? 0);

    if (empty($code))    $errors[] = 'Le code produit est obligatoire.';
    if (empty($courte))  $errors[] = 'La désignation courte est obligatoire.';
    if (!isset($familles[$famille])) $errors[] = 'Famille invalide.';
    if (!isset($unites[$unite]))     $errors[] = 'Unité invalide.';

    if (empty($errors)) {
        $dup = $db->prepare("SELECT id FROM produits WHERE code = ? AND id != ?");
        $dup->execute([$code, $id]);
        if ($dup->fetch()) $errors[] = 'Ce code produit existe déjà.';
    }

    if (empty($errors)) {
        if ($isEdit) {
            $db->prepare("UPDATE produits SET code=?, famille=?, qualification=?, type_heure=?, designation_courte=?,
                designation_longue=?, unite=?, prix_defaut=?, tva_taux=?, actif=?, notes=?, ordre=? WHERE id=?")
               ->execute([$code, $famille, $qualif ?: null, $typeHeure ?: null, $courte, $longue, $unite, $prix, $tva, $actif, $notes, $ordre, $id]);
            flash('success', 'Produit <strong>' . h($code) . '</strong> mis à jour.');
        } else {
            $db->prepare("INSERT INTO produits
                (code, famille, qualification, type_heure, designation_courte, designation_longue, unite, prix_defaut, tva_taux, actif, notes, ordre)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$code, $famille, $qualif ?: null, $typeHeure ?: null, $courte, $longue, $unite, $prix, $tva, $actif, $notes, $ordre]);
            flash('success', 'Produit <strong>' . h($code) . '</strong> ajouté.');
        }
        header('Location: index.php');
        exit;
    }
    $produit = array_merge($produit, compact('code','famille','courte','longue','unite','prix','tva','actif','notes','ordre'));
    $produit['designation_courte'] = $courte;
    $produit['designation_longue'] = $longue;
    $produit['prix_defaut'] = $prix;
    $produit['tva_taux'] = $tva;
    $produit['qualification'] = $qualif;
    $produit['type_heure'] = $typeHeure;
}

$pageTitle     = $isEdit ? 'Modifier — ' . $produit['code'] : 'Nouveau produit';
$currentModule = 'produits';
$topbarActions = '<a href="index.php" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Retour</a>';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="ov-card" style="max-width:800px">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-boxes-stacked me-2" style="color:var(--ov-gold)"></i>
            <?= $isEdit ? 'Modifier le produit' : 'Nouveau produit' ?>
        </h2>
    </div>
    <div class="ov-card-body">
        <form method="POST">
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Code <span class="text-danger">*</span></label>
                    <input type="text" name="code" class="form-control" style="text-transform:uppercase"
                        value="<?= h($produit['code']) ?>" placeholder="Ex: AS-STD-JN" required autofocus>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Famille <span class="text-danger">*</span></label>
                    <select name="famille" class="form-select" required>
                        <?php foreach ($familles as $k=>$v): ?>
                        <option value="<?= h($k) ?>" <?= $produit['famille']===$k?'selected':'' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Qualification <small class="text-muted">(si agent de sécurité)</small></label>
                    <select name="qualification" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($qualifs as $k=>$v): ?>
                        <option value="<?= h($k) ?>" <?= $produit['qualification']===$k?'selected':'' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Type d'heure <small class="text-muted">(si prestation horaire)</small></label>
                    <select name="type_heure" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($types as $k=>$v): ?>
                        <option value="<?= h($k) ?>" <?= $produit['type_heure']===$k?'selected':'' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Désignation courte <span class="text-danger">*</span> <small class="text-muted">(liste, tableau)</small></label>
                    <input type="text" name="designation_courte" class="form-control"
                        value="<?= h($produit['designation_courte']) ?>" placeholder="Ex: Agent Jour" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Désignation longue <small class="text-muted">(ligne de devis)</small></label>
                    <input type="text" name="designation_longue" class="form-control"
                        value="<?= h($produit['designation_longue']) ?>" placeholder="Ex: Agent de sécurité — Jour (tarif normal, jour ouvré)">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Unité <span class="text-danger">*</span></label>
                    <select name="unite" class="form-select" required>
                        <?php foreach ($unites as $k=>$v): ?>
                        <option value="<?= h($k) ?>" <?= $produit['unite']===$k?'selected':'' ?>><?= h($v) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Prix par défaut (€ HT)</label>
                    <input type="text" name="prix_defaut" class="form-control"
                        value="<?= h($produit['prix_defaut']) ?>" placeholder="0,00">
                </div>
                <div class="col-md-4">
                    <label class="form-label">TVA (%)</label>
                    <input type="text" name="tva_taux" class="form-control"
                        value="<?= h($produit['tva_taux']) ?>" placeholder="20">
                </div>

                <div class="col-md-8">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" value="<?= h($produit['notes']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Ordre d'affichage</label>
                    <input type="number" name="ordre" class="form-control" value="<?= h($produit['ordre']) ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check">
                        <input type="checkbox" name="actif" id="actif" class="form-check-input" <?= $produit['actif']?'checked':'' ?>>
                        <label class="form-check-label" for="actif">Actif</label>
                    </div>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-ov-primary">
                        <i class="fa fa-save me-2"></i><?= $isEdit ? 'Enregistrer' : 'Créer le produit' ?>
                    </button>
                    <a href="index.php" class="btn btn-ov-secondary">Annuler</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
