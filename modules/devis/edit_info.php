<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'edit');

$db = getDB();
ensureDevisSchema();
ensureClientsSchema();
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
        $numero     = trim($_POST['numero'] ?? '');
        $clientId   = (int)($_POST['client_id'] ?? 0) ?: null;
        $clientNom  = trim($_POST['client_nom'] ?? '');
        $clientAdr  = trim($_POST['client_adresse'] ?? '');
        $description= trim($_POST['description'] ?? '');
        $tvaTaux    = (float)($_POST['tva_taux'] ?? 20);
        $statut     = $_POST['statut'] ?? 'brouillon';

        if (empty($numero)) $errors[] = 'Le numéro est obligatoire.';
        if (empty($errors)) {
            $stmtChk = $db->prepare("SELECT COUNT(*) FROM devis WHERE numero = ? AND id != ?");
            $stmtChk->execute([$numero, $id]);
            if ((int)$stmtChk->fetchColumn() > 0) $errors[] = 'Ce numéro de devis est déjà utilisé.';
        }

        if (empty($errors)) {
            $db->prepare("
                UPDATE devis SET
                    client_id=?, numero=?, client_nom=?, client_adresse=?,
                    description=?, tva_taux=?, statut=?
                WHERE id=?
            ")->execute([
                $clientId, $numero, $clientNom, $clientAdr,
                $description, $tvaTaux,
                in_array($statut, ['brouillon','envoye','accepte','refuse']) ? $statut : 'brouillon',
                $id,
            ]);
            flash('success', 'Devis mis à jour.');
            header('Location: view.php?id=' . $id);
            exit;
        }
        $devis = array_merge($devis, compact('numero','clientNom','clientAdr','description','tvaTaux','statut'));
    }

    // ── Ajouter une période ───────────────────────────────────────────────
    elseif ($action === 'add_periode') {
        $dateDebut = trim($_POST['date_debut'] ?? '');
        $dateFin   = trim($_POST['date_fin']   ?? '');
        if (!$dateDebut || !$dateFin)      { flash('danger', 'Dates requises.'); header('Location: edit_info.php?id='.$id); exit; }
        if ($dateFin < $dateDebut)          { flash('danger', 'La date de fin doit être postérieure au début.'); header('Location: edit_info.php?id='.$id); exit; }

        $stmtMax = $db->prepare("SELECT COALESCE(MAX(ordre),0) FROM devis_periodes WHERE devis_id = ?");
        $stmtMax->execute([$id]);
        $db->prepare("INSERT INTO devis_periodes (devis_id, ordre, date_debut, date_fin) VALUES (?,?,?,?)")
           ->execute([$id, (int)$stmtMax->fetchColumn() + 1, $dateDebut, $dateFin]);

        syncDevisBounds($db, $id);

        // Générer lignes pour les nouveaux jours (exclus non réintégrés)
        $stmtE = $db->prepare("SELECT date FROM devis_dates_exclues WHERE devis_id = ?");
        $stmtE->execute([$id]);
        $exclu = array_flip(array_column($stmtE->fetchAll(), 'date'));

        $newJours = [];
        $cur = strtotime($dateDebut); $end = strtotime($dateFin);
        while ($cur <= $end) {
            $d = date('Y-m-d', $cur);
            if (!isset($exclu[$d])) $newJours[] = $d;
            $cur = strtotime('+1 day', $cur);
        }

        $stmtPids = $db->prepare("SELECT id FROM devis_profils WHERE devis_id = ?");
        $stmtPids->execute([$id]);
        $pids = array_column($stmtPids->fetchAll(), 'id');
        foreach ($pids as $pid) insertLignesProfil($db, $pid, $newJours);

        flash('success', 'Période ajoutée.');
        header('Location: edit_info.php?id='.$id);
        exit;
    }

    // ── Supprimer une période ─────────────────────────────────────────────
    elseif ($action === 'remove_periode') {
        $pid = (int)($_POST['periode_id'] ?? 0);
        $per = $db->prepare("SELECT * FROM devis_periodes WHERE id = ? AND devis_id = ?");
        $per->execute([$pid, $id]);
        $per = $per->fetch();
        if (!$per) { flash('danger', 'Période introuvable.'); header('Location: edit_info.php?id='.$id); exit; }

        // Jours couverts par cette période
        $toCheck = [];
        $cur = strtotime($per['date_debut']); $end = strtotime($per['date_fin']);
        while ($cur <= $end) { $toCheck[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }

        // Jours couverts par les AUTRES périodes
        $otherPeriodes = $db->prepare("SELECT date_debut, date_fin FROM devis_periodes WHERE devis_id = ? AND id != ?");
        $otherPeriodes->execute([$id, $pid]);
        $otherCovered = [];
        foreach ($otherPeriodes->fetchAll() as $op) {
            $c = strtotime($op['date_debut']); $e = strtotime($op['date_fin']);
            while ($c <= $e) { $otherCovered[date('Y-m-d', $c)] = true; $c = strtotime('+1 day', $c); }
        }

        // Supprimer uniquement les jours exclusifs à cette période
        $toDelete = array_filter($toCheck, fn($d) => !isset($otherCovered[$d]));
        if ($toDelete) {
            $stmtPids2 = $db->prepare("SELECT id FROM devis_profils WHERE devis_id = ?");
            $stmtPids2->execute([$id]);
            $profilIds = array_column($stmtPids2->fetchAll(), 'id');
            if ($profilIds) {
                $ph = implode(',', array_fill(0, count($profilIds), '?'));
                foreach ($toDelete as $date) {
                    $params = array_merge([$date], $profilIds);
                    $db->prepare("DELETE FROM devis_lignes WHERE date = ? AND profil_id IN ($ph)")->execute($params);
                    $db->prepare("DELETE FROM devis_dates_exclues WHERE devis_id = ? AND date = ?")->execute([$id, $date]);
                }
            }
        }

        $db->prepare("DELETE FROM devis_periodes WHERE id = ?")->execute([$pid]);
        syncDevisBounds($db, $id);

        flash('success', 'Période supprimée.');
        header('Location: edit_info.php?id='.$id);
        exit;
    }

    // ── Réinitialiser tous les jours ──────────────────────────────────────
    elseif ($action === 'reset_jours') {
        // Effacer les exclusions
        $db->prepare("DELETE FROM devis_dates_exclues WHERE devis_id = ?")->execute([$id]);
        // Reconstruire tous les jours depuis les périodes
        $jours = buildJoursDevis($db, $id);
        $stmtPids3 = $db->prepare("SELECT id FROM devis_profils WHERE devis_id = ?");
        $stmtPids3->execute([$id]);
        $profilIds = array_column($stmtPids3->fetchAll(), 'id');
        foreach ($profilIds as $pid) insertLignesProfil($db, $pid, $jours);
        // Supprimer les lignes orphelines (hors périodes)
        if ($jours && $profilIds) {
            $ph = implode(',', array_fill(0, count($profilIds), '?'));
            $phJ = implode(',', array_fill(0, count($jours), '?'));
            $params = array_merge($profilIds, $jours);
            $db->prepare("DELETE FROM devis_lignes WHERE profil_id IN ($ph) AND date NOT IN ($phJ)")->execute($params);
        }
        flash('success', 'Jours réinitialisés depuis les périodes définies.');
        header('Location: edit_info.php?id='.$id);
        exit;
    }

    // ── Ajouter un profil ─────────────────────────────────────────────────
    elseif ($action === 'add_profil') {
        requirePerm('devis', 'create');
        $label = trim($_POST['label'] ?? '');
        if (empty($label)) { flash('danger', 'Le label du profil est obligatoire.'); header('Location: edit_info.php?id='.$id); exit; }

        $stmtMax = $db->prepare("SELECT COALESCE(MAX(ordre),0) FROM devis_profils WHERE devis_id = ?");
        $stmtMax->execute([$id]);
        $db->prepare("INSERT INTO devis_profils (devis_id, ordre, label, activite, plage, taux_jn, taux_nn, taux_jd, taux_nd, taux_jf, taux_nf) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$id, (int)$stmtMax->fetchColumn() + 1,
               $label, trim($_POST['activite'] ?? 'Agent de Sécurité'), trim($_POST['plage'] ?? ''),
               (float)($_POST['taux_jn']??25.90),(float)($_POST['taux_nn']??27.90),(float)($_POST['taux_jd']??27.90),
               (float)($_POST['taux_nd']??30.90),(float)($_POST['taux_jf']??51.80),(float)($_POST['taux_nf']??55.80),
           ]);
        insertLignesProfil($db, (int)$db->lastInsertId(), buildJoursDevis($db, $id));
        flash('success', 'Profil ajouté.');
        header('Location: edit_info.php?id='.$id);
        exit;
    }

    // ── Modifier un profil ────────────────────────────────────────────────
    elseif ($action === 'edit_profil') {
        $pid = (int)($_POST['profil_id'] ?? 0);
        $chk = $db->prepare("SELECT id FROM devis_profils WHERE id = ? AND devis_id = ?");
        $chk->execute([$pid, $id]);
        if (!$chk->fetch()) { flash('danger', 'Profil introuvable.'); header('Location: edit_info.php?id='.$id); exit; }
        $db->prepare("UPDATE devis_profils SET label=?,activite=?,plage=?,taux_jn=?,taux_nn=?,taux_jd=?,taux_nd=?,taux_jf=?,taux_nf=? WHERE id=?")
           ->execute([trim($_POST['label']??''),trim($_POST['activite']??''),trim($_POST['plage']??''),
               (float)($_POST['taux_jn']??25.90),(float)($_POST['taux_nn']??27.90),(float)($_POST['taux_jd']??27.90),
               (float)($_POST['taux_nd']??30.90),(float)($_POST['taux_jf']??51.80),(float)($_POST['taux_nf']??55.80),$pid]);
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
        $db->prepare("INSERT INTO devis_profils (devis_id,ordre,label,activite,plage,taux_jn,taux_nn,taux_jd,taux_nd,taux_jf,taux_nf) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$id,(int)$stmtMax->fetchColumn()+1,$src['label'].' (copie)',$src['activite'],$src['plage'],
               $src['taux_jn'],$src['taux_nn'],$src['taux_jd'],$src['taux_nd'],$src['taux_jf'],$src['taux_nf']]);
        insertLignesProfil($db, (int)$db->lastInsertId(), buildJoursDevis($db, $id));
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

// Charger les données
$stmtP = $db->prepare("SELECT * FROM devis_profils WHERE devis_id = ? ORDER BY ordre, id");
$stmtP->execute([$id]);
$profils = $stmtP->fetchAll();

$stmtPer = $db->prepare("SELECT * FROM devis_periodes WHERE devis_id = ? ORDER BY date_debut");
$stmtPer->execute([$id]);
$periodes = $stmtPer->fetchAll();

$clientsList = $db->query("SELECT id, nom, adresse, contact FROM clients ORDER BY nom")->fetchAll();

$pageTitle     = 'Modifier — ' . $devis['numero'];
$currentModule = 'devis';
$topbarActions = '<a href="view.php?id=' . $id . '" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i> Retour au devis</a>';
require_once __DIR__ . '/../../includes/header.php';

$PROFILS_TYPES = [
    'agent-jour'  => ['label'=>'Profil : Agent De Jour',         'activite'=>'Agent de Sécurité','plage'=>'De 07h00 à 19h00','jn'=>25.90,'nn'=>27.90,'jd'=>27.90,'nd'=>30.90,'jf'=>51.80,'nf'=>55.80],
    'agent-nuit'  => ['label'=>'Profil : Agent De Nuit',         'activite'=>'Agent de Sécurité','plage'=>'De 19h00 à 07h00','jn'=>25.90,'nn'=>27.90,'jd'=>27.90,'nd'=>30.90,'jf'=>51.80,'nf'=>55.80],
    'cynophile'   => ['label'=>'Profil : Maître Chien',          'activite'=>'Agent Cynophile',  'plage'=>'De 20h00 à 06h00','jn'=>28.00,'nn'=>30.00,'jd'=>30.00,'nd'=>33.00,'jf'=>56.00,'nf'=>60.00],
    'ssiap1'      => ['label'=>'Profil : Agent SSIAP 1',         'activite'=>'Agent SSIAP',      'plage'=>'De 07h00 à 19h00','jn'=>26.50,'nn'=>28.50,'jd'=>28.50,'nd'=>31.50,'jf'=>53.00,'nf'=>57.00],
    'ssiap2'      => ['label'=>"Profil : Chef d'équipe SSIAP 2", 'activite'=>'Agent SSIAP',      'plage'=>'De 07h00 à 19h00','jn'=>28.00,'nn'=>30.00,'jd'=>30.00,'nd'=>33.00,'jf'=>56.00,'nf'=>60.00],
    'chef-equipe' => ['label'=>"Profil : Chef D'Équipe",         'activite'=>"Chef d'Équipe",    'plage'=>'De 07h00 à 19h00','jn'=>27.50,'nn'=>29.50,'jd'=>29.50,'nd'=>32.50,'jf'=>55.00,'nf'=>59.00],
];
?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<!-- ── Infos générales ────────────────────────────────────────────────── -->
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
                    <?php if ($clientsList): ?>
                    <select id="clientSelect" class="form-select mb-2" style="font-size:0.88rem">
                        <option value="">— Sélectionner un client enregistré —</option>
                        <?php foreach ($clientsList as $cl): ?>
                        <option value="<?= $cl['id'] ?>"
                            data-nom="<?= h($cl['nom']) ?>"
                            data-adresse="<?= h($cl['adresse'] ?? '') ?>"
                            <?= (int)($devis['client_id'] ?? 0) === $cl['id'] ? 'selected' : '' ?>>
                            <?= h($cl['nom']) ?><?= $cl['contact'] ? ' — ' . h($cl['contact']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    <input type="hidden" name="client_id" id="clientIdHidden" value="<?= h($devis['client_id'] ?? '') ?>">
                    <input type="text" name="client_nom" id="clientNomInput" class="form-control" value="<?= h($devis['client_nom'] ?? '') ?>">
                    <?php if (canDo('clients','create')): ?>
                    <div class="form-text">
                        <a href="<?= APP_URL ?>/modules/clients/add.php" target="_blank">
                            <i class="fa fa-plus me-1"></i>Créer un nouveau client
                        </a>
                    </div>
                    <?php endif; ?>
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
                <div class="col-md-8">
                    <label class="form-label">Adresse client</label>
                    <textarea name="client_adresse" id="clientAdrInput" class="form-control" rows="2"><?= h($devis['client_adresse'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"><?= h($devis['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Enregistrer</button>
                    <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary">Annuler</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Périodes ───────────────────────────────────────────────────────── -->
<div class="ov-card mb-3">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-calendar-range me-2" style="color:var(--ov-gold)"></i>Périodes
            <span class="badge bg-secondary ms-2"><?= count($periodes) ?></span>
        </h2>
        <form method="POST" style="display:contents">
            <input type="hidden" name="id"     value="<?= $id ?>">
            <input type="hidden" name="action" value="reset_jours">
            <button type="submit" class="btn btn-sm btn-outline-warning ms-auto"
                onclick="return confirm('Réinitialiser tous les jours depuis les périodes ? Les jours supprimés manuellement seront restaurés.')"
                title="Efface les suppressions manuelles et recrée toutes les lignes des périodes définies">
                <i class="fa fa-rotate-left me-1"></i>Réinitialiser les jours
            </button>
        </form>
    </div>
    <div class="ov-card-body">
        <?php if (empty($periodes)): ?>
        <p class="text-muted mb-3">Aucune période définie. Ajoutez-en une ci-dessous.</p>
        <?php else: ?>
        <div class="mb-3">
            <?php foreach ($periodes as $p): ?>
            <div class="d-flex align-items-center gap-3 py-2 border-bottom">
                <i class="fa fa-calendar-days text-muted"></i>
                <span class="fw-600">
                    <?= h(formatDate($p['date_debut'])) ?> → <?= h(formatDate($p['date_fin'])) ?>
                </span>
                <?php
                $nj = 0; $cur = strtotime($p['date_debut']); $end = strtotime($p['date_fin']);
                while ($cur <= $end) { $nj++; $cur = strtotime('+1 day', $cur); }
                ?>
                <span class="text-muted" style="font-size:0.82rem"><?= $nj ?> jour(s)</span>
                <form method="POST" class="ms-auto" style="display:inline"
                    onsubmit="return confirm('Supprimer cette période et ses jours exclusifs ?')">
                    <input type="hidden" name="id"         value="<?= $id ?>">
                    <input type="hidden" name="action"     value="remove_periode">
                    <input type="hidden" name="periode_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">
                        <i class="fa fa-trash"></i>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Ajouter une période -->
        <form method="POST" class="d-flex align-items-end gap-2 flex-wrap mt-2">
            <input type="hidden" name="id"     value="<?= $id ?>">
            <input type="hidden" name="action" value="add_periode">
            <div>
                <label class="form-label mb-1" style="font-size:0.82rem">Date début</label>
                <input type="date" name="date_debut" class="form-control form-control-sm" required>
            </div>
            <div>
                <label class="form-label mb-1" style="font-size:0.82rem">Date fin</label>
                <input type="date" name="date_fin" class="form-control form-control-sm" required>
            </div>
            <button type="submit" class="btn btn-ov-primary btn-sm">
                <i class="fa fa-plus me-1"></i>Ajouter cette période
            </button>
        </form>
        <div class="form-text text-muted mt-1">
            <i class="fa fa-circle-info me-1"></i>
            Ajouter une période génère automatiquement les lignes dans la grille de saisie.
        </div>
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
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleEditProfil(<?= $p['id'] ?>)"><i class="fa fa-pen"></i></button>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="duplicate_profil">
                    <input type="hidden" name="profil_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="Dupliquer"><i class="fa fa-copy"></i></button>
                </form>
                <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce profil ?')">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="delete_profil">
                    <input type="hidden" name="profil_id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
                </form>
            </div>
        </div>
        <div class="d-flex gap-3 text-muted" style="font-size:0.78rem">
            <?php foreach (['jn','nn','jd','nd','jf','nf'] as $k): ?>
            <span><?= strtoupper($k) ?> <strong><?= number_format($p['taux_'.$k],2) ?></strong></span>
            <?php endforeach; ?>
        </div>
        <div id="edit-profil-<?= $p['id'] ?>" style="display:none;margin-top:1rem">
            <form method="POST">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="action" value="edit_profil">
                <input type="hidden" name="profil_id" value="<?= $p['id'] ?>">
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label" style="font-size:0.8rem">Label</label><input type="text" name="label" class="form-control form-control-sm" value="<?= h($p['label']) ?>" required></div>
                    <div class="col-md-4"><label class="form-label" style="font-size:0.8rem">Activité</label><input type="text" name="activite" class="form-control form-control-sm" value="<?= h($p['activite']) ?>"></div>
                    <div class="col-md-4"><label class="form-label" style="font-size:0.8rem">Plage</label><input type="text" name="plage" class="form-control form-control-sm" value="<?= h($p['plage']) ?>"></div>
                </div>
                <div class="row g-2 mt-1">
                    <?php foreach (['jn'=>'JN Normal','nn'=>'NN Normal','jd'=>'JD Dim.','nd'=>'ND Dim.','jf'=>'JF Férié','nf'=>'NF Férié'] as $k => $lbl): ?>
                    <div class="col">
                        <label class="form-label text-center d-block" style="font-size:0.72rem"><?= $lbl ?></label>
                        <input type="number" name="taux_<?= $k ?>"
                            class="form-control form-control-sm text-center ep-taux-<?= $k ?>"
                            step="0.01" min="0" value="<?= h($p['taux_'.$k]) ?>"
                            <?= $k==='jn' ? 'style="border-color:var(--ov-gold);background:rgba(201,168,76,0.06);font-weight:700"' : '' ?>>
                        <?php if ($k==='jn'): ?>
                        <div style="font-size:0.58rem;color:var(--ov-gold);text-align:center;margin-top:2px"><i class="fa fa-wand-magic-sparkles me-1"></i>Base</div>
                        <?php endif; ?>
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
        <h2 class="ov-card-title"><i class="fa fa-plus me-2" style="color:var(--ov-gold)"></i>Ajouter un profil</h2>
    </div>
    <div class="ov-card-body">
        <form method="POST" id="formAddProfil">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="add_profil">
            <div class="mb-3">
                <label class="form-label">Charger un profil type</label>
                <select class="form-select form-select-sm" id="newTplSelect" style="max-width:280px">
                    <option value="">— Sélectionner —</option>
                    <option value="agent-jour">Agent de Jour</option><option value="agent-nuit">Agent de Nuit</option>
                    <option value="cynophile">Maître Chien</option><option value="ssiap1">Agent SSIAP 1</option>
                    <option value="ssiap2">Chef d'équipe SSIAP 2</option><option value="chef-equipe">Chef d'Équipe</option>
                </select>
            </div>
            <div class="row g-2">
                <div class="col-md-4"><label class="form-label">Label <span class="text-danger">*</span></label><input type="text" name="label" id="newLabel" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Activité</label><input type="text" name="activite" id="newActivite" class="form-control" value="Agent de Sécurité"></div>
                <div class="col-md-4"><label class="form-label">Plage</label><input type="text" name="plage" id="newPlage" class="form-control" value="De 07h00 à 19h00"></div>
            </div>
            <div class="row g-2 mt-1">
                <?php foreach (['jn'=>[25.90,'JN'],'nn'=>[27.90,'NN'],'jd'=>[27.90,'JD'],'nd'=>[30.90,'ND'],'jf'=>[51.80,'JF'],'nf'=>[55.80,'NF']] as $k => [$def,$abr]): ?>
                <div class="col">
                    <label class="form-label text-center d-block" style="font-size:0.75rem"><?= $abr ?></label>
                    <input type="number" name="taux_<?= $k ?>" id="new_<?= $k ?>"
                        class="form-control form-control-sm text-center"
                        step="0.01" min="0" value="<?= $def ?>"
                        <?= $k==='jn' ? 'style="border-color:var(--ov-gold);background:rgba(201,168,76,0.06);font-weight:700"' : '' ?>>
                    <?php if ($k==='jn'): ?>
                    <div style="font-size:0.58rem;color:var(--ov-gold);text-align:center;margin-top:2px"><i class="fa fa-wand-magic-sparkles me-1"></i>Base</div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-plus me-2"></i>Ajouter ce profil</button></div>
        </form>
    </div>
</div>

<script>
function toggleEditProfil(pid) {
    var el = document.getElementById('edit-profil-' + pid);
    el.style.display = el.style.display === 'none' ? '' : 'none';
}

// Sélecteur client
(function() {
    var sel = document.getElementById('clientSelect');
    if (!sel) return;
    sel.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        document.getElementById('clientIdHidden').value = opt.value || '';
        document.getElementById('clientNomInput').value = opt.value ? opt.dataset.nom     : '';
        document.getElementById('clientAdrInput').value = opt.value ? opt.dataset.adresse : '';
    });
})();

var PROFILS_TYPES = <?= json_encode(array_map(fn($t) => ['label'=>$t['label'],'activite'=>$t['activite'],'plage'=>$t['plage'],'jn'=>$t['jn'],'nn'=>$t['nn'],'jd'=>$t['jd'],'nd'=>$t['nd'],'jf'=>$t['jf'],'nf'=>$t['nf']], $PROFILS_TYPES)) ?>;

document.getElementById('newTplSelect').addEventListener('change', function() {
    var t = PROFILS_TYPES[this.value]; if (!t) return;
    document.getElementById('newLabel').value   = t.label;
    document.getElementById('newActivite').value = t.activite;
    document.getElementById('newPlage').value   = t.plage;
    ['jn','nn','jd','nd','jf','nf'].forEach(function(k) {
        document.getElementById('new_' + k).value = t[k].toFixed(2);
    });
    this.value = '';
});

// Règles de majoration
var MAJ_RULES = {
    nn: function(jn) { return jn + 2; },
    jd: function(jn) { return jn + 2; },
    nd: function(jn) { return jn + 5; },
    jf: function(jn) { return jn * 2; },
    nf: function(jn) { return jn * 2 + 4; },
};

// Auto-calcul pour "Ajouter un profil"
document.getElementById('new_jn').addEventListener('input', function() {
    var jn = parseFloat(this.value) || 0;
    if (jn <= 0) return;
    Object.keys(MAJ_RULES).forEach(function(k) {
        var inp = document.getElementById('new_' + k);
        if (inp) inp.value = MAJ_RULES[k](jn).toFixed(2);
    });
});

// Auto-calcul pour les éditions de profil existant (délégation)
document.addEventListener('input', function(e) {
    if (!e.target.classList.contains('ep-taux-jn')) return;
    var jn = parseFloat(e.target.value) || 0;
    if (jn <= 0) return;
    var form = e.target.closest('form');
    if (!form) return;
    Object.keys(MAJ_RULES).forEach(function(k) {
        var inp = form.querySelector('.ep-taux-' + k);
        if (inp) inp.value = MAJ_RULES[k](jn).toFixed(2);
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
