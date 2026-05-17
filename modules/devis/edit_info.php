<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'edit');

$db = getDB();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$devis = $db->prepare("SELECT * FROM devis WHERE id = ?");
$devis->execute([$id]);
$devis = $devis->fetch();
if (!$devis) { header('Location: index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero       = trim($_POST['numero'] ?? '');
    $clientNom    = trim($_POST['client_nom'] ?? '');
    $clientAdr    = trim($_POST['client_adresse'] ?? '');
    $periodeDebut = trim($_POST['periode_debut'] ?? '');
    $periodeFin   = trim($_POST['periode_fin'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $tvaTaux      = (float)($_POST['tva_taux'] ?? 20);
    $statut       = $_POST['statut'] ?? 'brouillon';

    if (empty($numero))       $errors[] = 'Le numéro est obligatoire.';
    if (empty($periodeDebut)) $errors[] = 'La date de début est obligatoire.';
    if (empty($periodeFin))   $errors[] = 'La date de fin est obligatoire.';
    if ($periodeDebut && $periodeFin && $periodeFin < $periodeDebut) {
        $errors[] = 'La date de fin doit être postérieure à la date de début.';
    }

    // Vérifier unicité du numéro (sauf l'actuel)
    if (empty($errors)) {
        $stmtChk = $db->prepare("SELECT COUNT(*) FROM devis WHERE numero = ? AND id != ?");
        $stmtChk->execute([$numero, $id]);
        if ((int)$stmtChk->fetchColumn() > 0) {
            $errors[] = 'Ce numéro de devis est déjà utilisé.';
        }
    }

    if (empty($errors)) {
        // Si la période a changé, gérer les lignes
        $oldDebut = $devis['periode_debut'];
        $oldFin   = $devis['periode_fin'];
        $periodeChanged = ($periodeDebut !== $oldDebut || $periodeFin !== $oldFin);

        $db->prepare("
            UPDATE devis SET
                numero = ?, client_nom = ?, client_adresse = ?,
                periode_debut = ?, periode_fin = ?,
                description = ?, tva_taux = ?, statut = ?
            WHERE id = ?
        ")->execute([
            $numero, $clientNom, $clientAdr,
            $periodeDebut, $periodeFin,
            $description, $tvaTaux,
            in_array($statut, ['brouillon','envoye','accepte','refuse']) ? $statut : 'brouillon',
            $id
        ]);

        // Si la période a changé, ajouter les nouveaux jours manquants pour tous les profils
        if ($periodeChanged) {
            $profils = $db->prepare("SELECT id FROM devis_profils WHERE devis_id = ?");
            $profils->execute([$id]);
            $profils = $profils->fetchAll();

            $jours = [];
            $cur   = strtotime($periodeDebut);
            $end   = strtotime($periodeFin);
            while ($cur <= $end) {
                $jours[] = date('Y-m-d', $cur);
                $cur = strtotime('+1 day', $cur);
            }

            $stmtIns = $db->prepare("
                INSERT IGNORE INTO devis_lignes (profil_id, date, h_jn, h_nn, h_jd, h_nd, h_jf, h_nf)
                VALUES (?, ?, 0, 0, 0, 0, 0, 0)
            ");
            foreach ($profils as $profil) {
                foreach ($jours as $jour) {
                    $stmtIns->execute([$profil['id'], $jour]);
                }
            }

            // Supprimer les lignes hors période
            $stmtDel = $db->prepare("
                DELETE dl FROM devis_lignes dl
                JOIN devis_profils dp ON dp.id = dl.profil_id
                WHERE dp.devis_id = ? AND (dl.date < ? OR dl.date > ?)
            ");
            $stmtDel->execute([$id, $periodeDebut, $periodeFin]);
        }

        flash('success', 'Devis mis à jour.');
        header('Location: view.php?id=' . $id);
        exit;
    }

    // Si erreur, pré-remplir avec les données POST
    $devis['numero']        = $numero;
    $devis['client_nom']    = $clientNom;
    $devis['client_adresse']= $clientAdr;
    $devis['periode_debut'] = $periodeDebut;
    $devis['periode_fin']   = $periodeFin;
    $devis['description']   = $description;
    $devis['tva_taux']      = $tvaTaux;
    $devis['statut']        = $statut;
}

$pageTitle     = 'Modifier — ' . $devis['numero'];
$currentModule = 'devis';
$topbarActions = '<a href="view.php?id=' . $id . '" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Retour au devis</a>';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="ov-card">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-pen me-2" style="color:var(--ov-gold)"></i>
            Modifier les informations — <?= h($devis['numero']) ?>
        </h2>
    </div>
    <div class="ov-card-body">
        <form method="POST">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Numéro de devis <span class="text-danger">*</span></label>
                    <input type="text" name="numero" class="form-control"
                        value="<?= h($devis['numero']) ?>" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Nom du client</label>
                    <input type="text" name="client_nom" class="form-control"
                        value="<?= h($devis['client_nom']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date début <span class="text-danger">*</span></label>
                    <input type="date" name="periode_debut" class="form-control"
                        value="<?= h($devis['periode_debut']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date fin <span class="text-danger">*</span></label>
                    <input type="date" name="periode_fin" class="form-control"
                        value="<?= h($devis['periode_fin']) ?>" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Adresse du client</label>
                    <textarea name="client_adresse" class="form-control" rows="2"><?= h($devis['client_adresse'] ?? '') ?></textarea>
                </div>
                <div class="col-md-2">
                    <label class="form-label">TVA (%)</label>
                    <input type="number" name="tva_taux" class="form-control" step="0.01" min="0" max="100"
                        value="<?= h($devis['tva_taux']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-select">
                        <option value="brouillon" <?= $devis['statut'] === 'brouillon' ? 'selected' : '' ?>>Brouillon</option>
                        <option value="envoye"    <?= $devis['statut'] === 'envoye'    ? 'selected' : '' ?>>Envoyé</option>
                        <option value="accepte"   <?= $devis['statut'] === 'accepte'   ? 'selected' : '' ?>>Accepté</option>
                        <option value="refuse"    <?= $devis['statut'] === 'refuse'    ? 'selected' : '' ?>>Refusé</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?= h($devis['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="alert alert-info mb-0" style="font-size:0.85rem">
                        <i class="fa fa-circle-info me-1"></i>
                        Si vous modifiez la période, les lignes de jours manquantes seront créées automatiquement.
                        Les lignes hors de la nouvelle période seront supprimées.
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-ov-primary">
                        <i class="fa fa-save me-2"></i>Enregistrer les modifications
                    </button>
                    <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary">Annuler</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
