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
    $action = $_POST['action'] ?? 'save_info';

    // ── Sauvegarder les infos générales ───────────────────────────────────
    if ($action === 'save_info') {
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
        if ($periodeDebut && $periodeFin && $periodeFin < $periodeDebut)
            $errors[] = 'La date de fin doit être postérieure à la date de début.';

        if (empty($errors)) {
            $stmtChk = $db->prepare("SELECT COUNT(*) FROM devis WHERE numero = ? AND id != ?");
            $stmtChk->execute([$numero, $id]);
            if ((int)$stmtChk->fetchColumn() > 0)
                $errors[] = 'Ce numéro de devis est déjà utilisé.';
        }

        if (empty($errors)) {
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

            if ($periodeChanged) {
                $profils = $db->prepare("SELECT id FROM devis_profils WHERE devis_id = ?");
                $profils->execute([$id]);
                $profils = $profils->fetchAll();

                $jours = [];
                $cur   = strtotime($periodeDebut);
                $end   = strtotime($periodeFin);
                while ($cur <= $end) { $jours[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }

                $stmtIns = $db->prepare("INSERT IGNORE INTO devis_lignes (profil_id, date, h_jn, h_nn, h_jd, h_nd, h_jf, h_nf) VALUES (?, ?, 0, 0, 0, 0, 0, 0)");
                foreach ($profils as $profil) {
                    foreach ($jours as $jour) { $stmtIns->execute([$profil['id'], $jour]); }
                }
                $db->prepare("DELETE dl FROM devis_lignes dl JOIN devis_profils dp ON dp.id = dl.profil_id WHERE dp.devis_id = ? AND (dl.date < ? OR dl.date > ?)")->execute([$id, $periodeDebut, $periodeFin]);
            }

            flash('success', 'Devis mis à jour.');
            header('Location: view.php?id=' . $id);
            exit;
        }
        // Pré-remplir en cas d'erreur
        $devis = array_merge($devis, compact('numero','clientNom','clientAdr','periodeDebut','periodeFin','description','tvaTaux','statut'));
    }

    // ── Ajouter un profil ─────────────────────────────────────────────────
    elseif ($action === 'add_profil') {
        requirePerm('devis', 'create');
        $label    = trim($_POST['label']    ?? '');
        $activite = trim($_POST['activite'] ?? 'Agent de Sécurité');
        $plage    = trim($_POST['plage']    ?? '');
        if (empty($label)) { flash('danger', 'Le label du profil est obligatoire.'); header('Location: edit_info.php?id='.$id); exit; }

        $jours = [];
        $cur   = strtotime($devis['periode_debut']);
        $end   = strtotime($devis['periode_fin']);
        while ($cur <= $end) { $jours[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }

        $maxOrdre = (int)$db->prepare("SELECT COALESCE(MAX(ordre),0) FROM devis_profils WHERE devis_id = ?")->execute([$id]);
        $stmtMax  = $db->prepare("SELECT COALESCE(MAX(ordre),0) FROM devis_profils WHERE devis_id = ?");
        $stmtMax->execute([$id]);
        $ordre = (int)$stmtMax->fetchColumn() + 1;

        $stmtP = $db->prepare("INSERT INTO devis_profils (devis_id, ordre, label, activite, plage, taux_jn, taux_nn, taux_jd, taux_nd, taux_jf, taux_nf) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmtP->execute([
            $id, $ordre, $label, $activite, $plage,
            (float)($_POST['taux_jn'] ?? 25.90),
            (float)($_POST['taux_nn'] ?? 27.90),
            (float)($_POST['taux_jd'] ?? 27.90),
            (float)($_POST['taux_nd'] ?? 30.90),
            (float)($_POST['taux_jf'] ?? 51.80),
            (float)($_POST['taux_nf'] ?? 55.80),
        ]);
        $newProfilId = (int)$db->lastInsertId();
        $stmtL = $db->prepare("INSERT IGNORE INTO devis_lignes (profil_id, date, h_jn, h_nn, h_jd, h_nd, h_jf, h_nf) VALUES (?,?,0,0,0,0,0,0)");
        foreach ($jours as $jour) { $stmtL->execute([$newProfilId, $jour]); }

        flash('success', 'Profil ajouté.');
        header('Location: edit_info.php?id='.$id);
        exit;
    }

    // ── Modifier un profil ────────────────────────────────────────────────
    elseif ($action === 'edit_profil') {
        $pid = (int)($_POST['profil_id'] ?? 0);
        // Vérifier appartenance
        $chk = $db->prepare("SELECT id FROM devis_profils WHERE id = ? AND devis_id = ?");
        $chk->execute([$pid, $id]);
        if (!$chk->fetch()) { flash('danger', 'Profil introuvable.'); header('Location: edit_info.php?id='.$id); exit; }

        $db->prepare("UPDATE devis_profils SET label=?, activite=?, plage=?, taux_jn=?, taux_nn=?, taux_jd=?, taux_nd=?, taux_jf=?, taux_nf=? WHERE id = ?")->execute([
            trim($_POST['label']    ?? ''),
            trim($_POST['activite'] ?? ''),
            trim($_POST['plage']    ?? ''),
            (float)($_POST['taux_jn'] ?? 25.90),
            (float)($_POST['taux_nn'] ?? 27.90),
            (float)($_POST['taux_jd'] ?? 27.90),
            (float)($_POST['taux_nd'] ?? 30.90),
            (float)($_POST['taux_jf'] ?? 51.80),
            (float)($_POST['taux_nf'] ?? 55.80),
            $pid,
        ]);
        flash('success', 'Profil mis à jour.');
        header('Location: edit_info.php?id='.$id);
        exit;
    }

    // ── Dupliquer un profil ───────────────────────────────────────────────
    elseif ($action === 'duplicate_profil') {
        requirePerm('devis', 'create');
        $pid = (int)($_POST['profil_id'] ?? 0);
        $src = $db->prepare("SELECT * FROM devis_profils WHERE id = ? AND devis_id = ?");
        $src->execute([$pid, $id]);
        $src = $src->fetch();
        if (!$src) { flash('danger', 'Profil introuvable.'); header('Location: edit_info.php?id='.$id); exit; }

        $stmtMax = $db->prepare("SELECT COALESCE(MAX(ordre),0) FROM devis_profils WHERE devis_id = ?");
        $stmtMax->execute([$id]);
        $ordre = (int)$stmtMax->fetchColumn() + 1;

        $db->prepare("INSERT INTO devis_profils (devis_id, ordre, label, activite, plage, taux_jn, taux_nn, taux_jd, taux_nd, taux_jf, taux_nf) VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $id, $ordre,
            $src['label'] . ' (copie)',
            $src['activite'], $src['plage'],
            $src['taux_jn'], $src['taux_nn'], $src['taux_jd'],
            $src['taux_nd'], $src['taux_jf'], $src['taux_nf'],
        ]);
        $newPid = (int)$db->lastInsertId();

        $jours = [];
        $cur   = strtotime($devis['periode_debut']);
        $end   = strtotime($devis['periode_fin']);
        while ($cur <= $end) { $jours[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }
        $stmtL = $db->prepare("INSERT IGNORE INTO devis_lignes (profil_id, date, h_jn, h_nn, h_jd, h_nd, h_jf, h_nf) VALUES (?,?,0,0,0,0,0,0)");
        foreach ($jours as $jour) { $stmtL->execute([$newPid, $jour]); }

        flash('success', 'Profil dupliqué.');
        header('Location: edit_info.php?id='.$id);
        exit;
    }

    // ── Supprimer un profil ───────────────────────────────────────────────
    elseif ($action === 'delete_profil') {
        $pid = (int)($_POST['profil_id'] ?? 0);
        $chk = $db->prepare("SELECT id FROM devis_profils WHERE id = ? AND devis_id = ?");
        $chk->execute([$pid, $id]);
        if (!$chk->fetch()) { flash('danger', 'Profil introuvable.'); header('Location: edit_info.php?id='.$id); exit; }
        $db->prepare("DELETE FROM devis_lignes WHERE profil_id = ?")->execute([$pid]);
        $db->prepare("DELETE FROM devis_profils WHERE id = ?")->execute([$pid]);
        flash('success', 'Profil supprimé.');
        header('Location: edit_info.php?id='.$id);
        exit;
    }
}

// Charger les profils existants
$stmtP = $db->prepare("SELECT * FROM devis_profils WHERE devis_id = ? ORDER BY ordre, id");
$stmtP->execute([$id]);
$profils = $stmtP->fetchAll();

$pageTitle     = 'Modifier — ' . $devis['numero'];
$currentModule = 'devis';
$topbarActions = '<a href="view.php?id=' . $id . '" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Retour au devis</a>';
require_once __DIR__ . '/../../includes/header.php';

$PROFILS_TYPES = [
    'agent-jour'  => ['label'=>'Profil : Agent De Jour',         'activite'=>'Agent de Sécurité', 'plage'=>'De 07h00 à 19h00', 'jn'=>25.90,'nn'=>27.90,'jd'=>27.90,'nd'=>30.90,'jf'=>51.80,'nf'=>55.80],
    'agent-nuit'  => ['label'=>'Profil : Agent De Nuit',         'activite'=>'Agent de Sécurité', 'plage'=>'De 19h00 à 07h00', 'jn'=>25.90,'nn'=>27.90,'jd'=>27.90,'nd'=>30.90,'jf'=>51.80,'nf'=>55.80],
    'cynophile'   => ['label'=>'Profil : Maître Chien',          'activite'=>'Agent Cynophile',   'plage'=>'De 20h00 à 06h00', 'jn'=>28.00,'nn'=>30.00,'jd'=>30.00,'nd'=>33.00,'jf'=>56.00,'nf'=>60.00],
    'ssiap1'      => ['label'=>'Profil : Agent SSIAP 1',         'activite'=>'Agent SSIAP',       'plage'=>'De 07h00 à 19h00', 'jn'=>26.50,'nn'=>28.50,'jd'=>28.50,'nd'=>31.50,'jf'=>53.00,'nf'=>57.00],
    'ssiap2'      => ['label'=>"Profil : Chef d'équipe SSIAP 2", 'activite'=>'Agent SSIAP',       'plage'=>'De 07h00 à 19h00', 'jn'=>28.00,'nn'=>30.00,'jd'=>30.00,'nd'=>33.00,'jf'=>56.00,'nf'=>60.00],
    'chef-equipe' => ['label'=>"Profil : Chef D'Équipe",         'activite'=>"Chef d'Équipe",     'plage'=>'De 07h00 à 19h00', 'jn'=>27.50,'nn'=>29.50,'jd'=>29.50,'nd'=>32.50,'jf'=>55.00,'nf'=>59.00],
];
?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<!-- ── Infos générales ─────────────────────────────────────────────────── -->
<div class="ov-card mb-3">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-circle-info me-2" style="color:var(--ov-gold)"></i>
            Informations générales — <?= h($devis['numero']) ?>
        </h2>
    </div>
    <div class="ov-card-body">
        <form method="POST">
            <input type="hidden" name="id"     value="<?= $id ?>">
            <input type="hidden" name="action" value="save_info">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Numéro <span class="text-danger">*</span></label>
                    <input type="text" name="numero" class="form-control" value="<?= h($devis['numero']) ?>" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label">Client</label>
                    <input type="text" name="client_nom" class="form-control" value="<?= h($devis['client_nom'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date début <span class="text-danger">*</span></label>
                    <input type="date" name="periode_debut" class="form-control" value="<?= h($devis['periode_debut']) ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Date fin <span class="text-danger">*</span></label>
                    <input type="date" name="periode_fin" class="form-control" value="<?= h($devis['periode_fin']) ?>" required>
                </div>
                <div class="col-md-8">
                    <label class="form-label">Adresse client</label>
                    <textarea name="client_adresse" class="form-control" rows="2"><?= h($devis['client_adresse'] ?? '') ?></textarea>
                </div>
                <div class="col-md-2">
                    <label class="form-label">TVA (%)</label>
                    <input type="number" name="tva_taux" class="form-control" step="0.01" min="0" max="100" value="<?= h($devis['tva_taux']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Statut</label>
                    <select name="statut" class="form-select">
                        <option value="brouillon" <?= $devis['statut']==='brouillon'?'selected':'' ?>>Brouillon</option>
                        <option value="envoye"    <?= $devis['statut']==='envoye'   ?'selected':'' ?>>Envoyé</option>
                        <option value="accepte"   <?= $devis['statut']==='accepte'  ?'selected':'' ?>>Accepté</option>
                        <option value="refuse"    <?= $devis['statut']==='refuse'   ?'selected':'' ?>>Refusé</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="3"><?= h($devis['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <div class="alert alert-info mb-0" style="font-size:0.85rem">
                        <i class="fa fa-circle-info me-1"></i>
                        Si vous modifiez la période, les jours manquants seront créés automatiquement et les jours hors période supprimés.
                    </div>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-ov-primary">
                        <i class="fa fa-save me-2"></i>Enregistrer
                    </button>
                    <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary">Annuler</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Profils existants ──────────────────────────────────────────────── -->
<div class="ov-card mb-3">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-users me-2" style="color:var(--ov-gold)"></i>Profils d'agents
            <span class="badge bg-secondary ms-2"><?= count($profils) ?></span>
        </h2>
    </div>
    <div class="ov-card-body">
    <?php if (empty($profils)): ?>
        <p class="text-muted">Aucun profil. Ajoutez-en un ci-dessous.</p>
    <?php endif; ?>

    <?php foreach ($profils as $p): ?>
    <div class="border rounded mb-3 p-3">
        <div class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <span class="fw-bold" style="color:var(--ov-gold)"><?= h($p['label']) ?></span>
                <span class="text-muted ms-2" style="font-size:0.82rem"><?= h($p['activite']) ?> | <?= h($p['plage']) ?></span>
            </div>
            <div class="d-flex gap-1">
                <button type="button" class="btn btn-sm btn-outline-secondary"
                    onclick="toggleEditProfil(<?= $p['id'] ?>)" title="Modifier">
                    <i class="fa fa-pen"></i>
                </button>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="id"        value="<?= $id ?>">
                    <input type="hidden" name="action"    value="duplicate_profil">
                    <input type="hidden" name="profil_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Dupliquer ce profil">
                        <i class="fa fa-copy"></i>
                    </button>
                </form>
                <form method="POST" style="display:inline"
                    onsubmit="return confirm('Supprimer ce profil et toutes ses heures ?')">
                    <input type="hidden" name="id"        value="<?= $id ?>">
                    <input type="hidden" name="action"    value="delete_profil">
                    <input type="hidden" name="profil_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer">
                        <i class="fa fa-trash"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- Taux résumé -->
        <div class="d-flex gap-3 text-muted" style="font-size:0.78rem">
            <span>JN <strong><?= number_format($p['taux_jn'],2) ?></strong></span>
            <span>NN <strong><?= number_format($p['taux_nn'],2) ?></strong></span>
            <span>JD <strong><?= number_format($p['taux_jd'],2) ?></strong></span>
            <span>ND <strong><?= number_format($p['taux_nd'],2) ?></strong></span>
            <span>JF <strong><?= number_format($p['taux_jf'],2) ?></strong></span>
            <span>NF <strong><?= number_format($p['taux_nf'],2) ?></strong></span>
        </div>

        <!-- Formulaire d'édition (caché par défaut) -->
        <div id="edit-profil-<?= $p['id'] ?>" style="display:none; margin-top:1rem">
            <form method="POST">
                <input type="hidden" name="id"        value="<?= $id ?>">
                <input type="hidden" name="action"    value="edit_profil">
                <input type="hidden" name="profil_id" value="<?= $p['id'] ?>">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label" style="font-size:0.8rem">Label</label>
                        <input type="text" name="label" class="form-control form-control-sm" value="<?= h($p['label']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="font-size:0.8rem">Activité</label>
                        <input type="text" name="activite" class="form-control form-control-sm" value="<?= h($p['activite']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" style="font-size:0.8rem">Plage horaire</label>
                        <input type="text" name="plage" class="form-control form-control-sm" value="<?= h($p['plage']) ?>">
                    </div>
                </div>
                <div class="row g-2 mt-1">
                    <?php foreach (['jn'=>'JN Jour Normal','nn'=>'NN Nuit Normal','jd'=>'JD Dimanche','nd'=>'ND Nuit Dim.','jf'=>'JF Férié','nf'=>'NF Nuit Férié'] as $k => $lbl): ?>
                    <div class="col">
                        <label class="form-label text-center d-block" style="font-size:0.72rem"><?= $lbl ?></label>
                        <input type="number" name="taux_<?= $k ?>" class="form-control form-control-sm text-center" step="0.01" min="0" value="<?= h($p['taux_'.$k]) ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-2 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-ov-primary"><i class="fa fa-save me-1"></i>Enregistrer</button>
                    <button type="button" class="btn btn-sm btn-ov-secondary" onclick="toggleEditProfil(<?= $p['id'] ?>)">Annuler</button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
</div>

<!-- ── Ajouter un profil ──────────────────────────────────────────────── -->
<div class="ov-card mb-3">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-plus me-2" style="color:var(--ov-gold)"></i>Ajouter un profil
        </h2>
    </div>
    <div class="ov-card-body">
        <form method="POST" id="formAddProfil">
            <input type="hidden" name="id"     value="<?= $id ?>">
            <input type="hidden" name="action" value="add_profil">
            <div class="mb-3">
                <label class="form-label">Charger un profil type</label>
                <select class="form-select form-select-sm" id="newTplSelect" style="max-width:280px">
                    <option value="">— Sélectionner un profil type —</option>
                    <option value="agent-jour">Agent de Jour (07h-19h)</option>
                    <option value="agent-nuit">Agent de Nuit (19h-07h)</option>
                    <option value="cynophile">Maître Chien (20h-06h)</option>
                    <option value="ssiap1">Agent SSIAP 1</option>
                    <option value="ssiap2">Chef d'équipe SSIAP 2</option>
                    <option value="chef-equipe">Chef d'Équipe</option>
                </select>
            </div>
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Label <span class="text-danger">*</span></label>
                    <input type="text" name="label" id="newLabel" class="form-control" placeholder="Ex: Profil A : 1 Agent De Jour" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Activité</label>
                    <input type="text" name="activite" id="newActivite" class="form-control" value="Agent de Sécurité">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Plage horaire</label>
                    <input type="text" name="plage" id="newPlage" class="form-control" value="De 07h00 à 19h00">
                </div>
            </div>
            <div class="row g-2 mt-1">
                <?php foreach (['jn'=>['JN','Jour Normal',25.90],'nn'=>['NN','Nuit Normal',27.90],'jd'=>['JD','Dimanche',27.90],'nd'=>['ND','Nuit Dim.',30.90],'jf'=>['JF','Férié',51.80],'nf'=>['NF','Nuit Férié',55.80]] as $k => [$abr,$lbl,$def]): ?>
                <div class="col">
                    <label class="form-label text-center d-block" style="font-size:0.75rem"><?= $abr ?><br><small class="text-muted"><?= $lbl ?></small></label>
                    <input type="number" name="taux_<?= $k ?>" id="new_<?= $k ?>" class="form-control form-control-sm text-center" step="0.01" min="0" value="<?= $def ?>">
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-ov-primary">
                    <i class="fa fa-plus me-2"></i>Ajouter ce profil
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleEditProfil(pid) {
    var el = document.getElementById('edit-profil-' + pid);
    el.style.display = el.style.display === 'none' ? '' : 'none';
}

var PROFILS_TYPES = <?= json_encode(array_map(function($t) {
    return ['label'=>$t['label'],'activite'=>$t['activite'],'plage'=>$t['plage'],
            'jn'=>$t['jn'],'nn'=>$t['nn'],'jd'=>$t['jd'],'nd'=>$t['nd'],'jf'=>$t['jf'],'nf'=>$t['nf']];
}, $PROFILS_TYPES)) ?>;

document.getElementById('newTplSelect').addEventListener('change', function() {
    var t = PROFILS_TYPES[this.value];
    if (!t) return;
    document.getElementById('newLabel').value   = t.label;
    document.getElementById('newActivite').value = t.activite;
    document.getElementById('newPlage').value    = t.plage;
    document.getElementById('new_jn').value = t.jn.toFixed(2);
    document.getElementById('new_nn').value = t.nn.toFixed(2);
    document.getElementById('new_jd').value = t.jd.toFixed(2);
    document.getElementById('new_nd').value = t.nd.toFixed(2);
    document.getElementById('new_jf').value = t.jf.toFixed(2);
    document.getElementById('new_nf').value = t.nf.toFixed(2);
    this.value = '';
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
