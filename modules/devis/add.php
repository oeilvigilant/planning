<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'create');

$db     = getDB();
ensureDevisSchema();
$errors = [];

// Génération auto du numéro de devis
function generateNumeroDevis() {
    $db = getDB();
    $yy = date('y');
    $mm = date('m');
    $prefix = "S-$yy-$mm-";
    $stmt = $db->prepare("SELECT COUNT(*) FROM devis WHERE numero LIKE ?");
    $stmt->execute([$prefix . '%']);
    $n = (int)$stmt->fetchColumn() + 1;
    return $prefix . $n;
}

$defaultNumero = generateNumeroDevis();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero       = trim($_POST['numero'] ?? '');
    $clientNom    = trim($_POST['client_nom'] ?? '');
    $clientAdr    = trim($_POST['client_adresse'] ?? '');
    $periodeDebut = trim($_POST['periode_debut'] ?? '');
    $periodeFin   = trim($_POST['periode_fin'] ?? '');
    $description  = trim($_POST['description'] ?? '');
    $tvaTaux      = (float)($_POST['tva_taux'] ?? 20);
    $statut       = $_POST['statut'] ?? 'brouillon';
    $profils      = $_POST['profils'] ?? [];

    // Validation
    if (empty($numero))       $errors[] = 'Le numéro de devis est obligatoire.';
    if (empty($periodeDebut)) $errors[] = 'La date de début est obligatoire.';
    if (empty($periodeFin))   $errors[] = 'La date de fin est obligatoire.';
    if ($periodeDebut && $periodeFin && $periodeFin < $periodeDebut) {
        $errors[] = 'La date de fin doit être postérieure à la date de début.';
    }
    if (empty($profils))      $errors[] = 'Au moins un profil d\'agent est requis.';

    // Vérifier unicité du numéro
    if (empty($errors)) {
        $stmtChk = $db->prepare("SELECT COUNT(*) FROM devis WHERE numero = ?");
        $stmtChk->execute([$numero]);
        if ((int)$stmtChk->fetchColumn() > 0) {
            $errors[] = 'Ce numéro de devis existe déjà. Utilisez un numéro différent.';
        }
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // Insérer le devis
            $stmtD = $db->prepare("
                INSERT INTO devis (numero, client_nom, client_adresse, periode_debut, periode_fin,
                    description, tva_taux, statut, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtD->execute([
                $numero, $clientNom, $clientAdr,
                $periodeDebut, $periodeFin,
                $description, $tvaTaux,
                in_array($statut, ['brouillon','envoye','accepte','refuse']) ? $statut : 'brouillon',
                getCurrentUser()['id']
            ]);
            $devisId = (int)$db->lastInsertId();

            // Générer la liste des jours de la période
            $jours = [];
            $cur = strtotime($periodeDebut);
            $end = strtotime($periodeFin);
            while ($cur <= $end) {
                $jours[] = date('Y-m-d', $cur);
                $cur = strtotime('+1 day', $cur);
            }

            // Insérer les profils
            $stmtP = $db->prepare("
                INSERT INTO devis_profils (devis_id, ordre, label, activite, plage,
                    taux_jn, taux_nn, taux_jd, taux_nd, taux_jf, taux_nf)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtL = $db->prepare("
                INSERT INTO devis_lignes (profil_id, date, h_jn, h_nn, h_jd, h_nd, h_jf, h_nf)
                VALUES (?, ?, 0, 0, 0, 0, 0, 0)
            ");

            foreach ($profils as $idx => $p) {
                $label   = trim($p['label'] ?? 'Profil ' . ($idx + 1));
                $activite= trim($p['activite'] ?? 'Agent de Sécurité');
                $plage   = trim($p['plage'] ?? 'De 07h00 à 19h00');
                $tauxJn  = (float)($p['taux_jn'] ?? 25.90);
                $tauxNn  = (float)($p['taux_nn'] ?? 27.90);
                $tauxJd  = (float)($p['taux_jd'] ?? 27.90);
                $tauxNd  = (float)($p['taux_nd'] ?? 30.90);
                $tauxJf  = (float)($p['taux_jf'] ?? 51.80);
                $tauxNf  = (float)($p['taux_nf'] ?? 55.80);

                $stmtP->execute([
                    $devisId, $idx,
                    $label, $activite, $plage,
                    $tauxJn, $tauxNn, $tauxJd, $tauxNd, $tauxJf, $tauxNf
                ]);
                $profilId = (int)$db->lastInsertId();

                // Créer une ligne vide par jour pour ce profil
                foreach ($jours as $jour) {
                    $stmtL->execute([$profilId, $jour]);
                }
            }

            $db->commit();
            flash('success', 'Devis <strong>' . h($numero) . '</strong> créé avec succès !');
            header('Location: view.php?id=' . $devisId);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Erreur lors de la création : ' . $e->getMessage();
        }
    }
}

$pageTitle     = 'Nouveau devis';
$currentModule = 'devis';
require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<form method="POST" id="formDevis">
<div class="row g-3">

<!-- Partie 1 : Informations générales -->
<div class="col-12">
<div class="ov-card mb-3">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-circle-info me-2" style="color:var(--ov-gold)"></i>Informations générales
        </h2>
    </div>
    <div class="ov-card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Numéro de devis <span class="text-danger">*</span></label>
                <input type="text" name="numero" class="form-control"
                    value="<?= h($_POST['numero'] ?? $defaultNumero) ?>" required>
                <div class="form-text">Format : S-YY-MM-N (auto-généré)</div>
            </div>
            <div class="col-md-5">
                <label class="form-label">Nom du client</label>
                <input type="text" name="client_nom" class="form-control"
                    value="<?= h($_POST['client_nom'] ?? '') ?>"
                    placeholder="Nom de l'entreprise ou du client">
            </div>
            <div class="col-md-2">
                <label class="form-label">Date début <span class="text-danger">*</span></label>
                <input type="date" name="periode_debut" class="form-control"
                    value="<?= h($_POST['periode_debut'] ?? '') ?>" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">Date fin <span class="text-danger">*</span></label>
                <input type="date" name="periode_fin" class="form-control"
                    value="<?= h($_POST['periode_fin'] ?? '') ?>" required>
            </div>
            <div class="col-md-8">
                <label class="form-label">Adresse du client</label>
                <textarea name="client_adresse" class="form-control" rows="2"
                    placeholder="Adresse complète du client"><?= h($_POST['client_adresse'] ?? '') ?></textarea>
            </div>
            <div class="col-md-2">
                <label class="form-label">TVA (%)</label>
                <input type="number" name="tva_taux" class="form-control" step="0.01" min="0" max="100"
                    value="<?= h($_POST['tva_taux'] ?? '20') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Statut</label>
                <select name="statut" class="form-select">
                    <option value="brouillon" <?= ($_POST['statut'] ?? 'brouillon') === 'brouillon' ? 'selected' : '' ?>>Brouillon</option>
                    <option value="envoye"    <?= ($_POST['statut'] ?? '') === 'envoye'    ? 'selected' : '' ?>>Envoyé</option>
                    <option value="accepte"   <?= ($_POST['statut'] ?? '') === 'accepte'   ? 'selected' : '' ?>>Accepté</option>
                    <option value="refuse"    <?= ($_POST['statut'] ?? '') === 'refuse'    ? 'selected' : '' ?>>Refusé</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Description / Objet du devis</label>
                <textarea name="description" class="form-control" rows="3"
                    placeholder="Description des prestations..."><?= h($_POST['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Partie 2 : Profils d'agents -->
<div class="col-12">
<div class="ov-card mb-3">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-users me-2" style="color:var(--ov-gold)"></i>Profils d'agents
        </h2>
        <button type="button" class="btn btn-ov-secondary btn-sm" id="btnAddProfil">
            <i class="fa fa-plus me-1"></i> Ajouter un profil
        </button>
    </div>
    <div class="ov-card-body">
        <div id="profilsContainer">
            <!-- Le premier profil est injecté par JS au chargement -->
        </div>
        <div class="text-muted mt-2" id="noProfilMsg" style="display:none">
            <i class="fa fa-triangle-exclamation me-1 text-warning"></i>
            Au moins un profil est requis.
        </div>
    </div>
</div>
</div>

<!-- Boutons de soumission -->
<div class="col-12">
    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-ov-primary">
            <i class="fa fa-save me-2"></i>Créer le devis
        </button>
        <a href="index.php" class="btn btn-ov-secondary">Annuler</a>
    </div>
</div>

</div>
</form>

<!-- Template profil (caché) -->
<template id="tplProfil">
<div class="profil-block border rounded mb-3 p-3 position-relative" data-idx="__IDX__">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h6 class="mb-0 fw-bold" style="color:var(--ov-gold)">
            <i class="fa fa-user-shield me-1"></i> Profil <span class="profil-num">__NUM__</span>
        </h6>
        <div class="d-flex gap-2 align-items-center">
            <select class="form-select form-select-sm tpl-select" style="width:200px" title="Charger un profil type">
                <option value="">— Profil type —</option>
                <option value="agent-jour">Agent de Jour (07h-19h)</option>
                <option value="agent-nuit">Agent de Nuit (19h-07h)</option>
                <option value="cynophile">Maître Chien (20h-06h)</option>
                <option value="ssiap1">Agent SSIAP 1</option>
                <option value="ssiap2">Chef d'équipe SSIAP 2</option>
                <option value="chef-equipe">Chef d'Équipe</option>
            </select>
            <button type="button" class="btn btn-sm btn-outline-danger btn-remove-profil" title="Supprimer ce profil">
                <i class="fa fa-times"></i>
            </button>
        </div>
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Label du profil <span class="text-danger">*</span></label>
            <input type="text" name="profils[__IDX__][label]" class="form-control tpl-label"
                placeholder="Ex: Profil A : 1 Agent De Jour" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Activité</label>
            <input type="text" name="profils[__IDX__][activite]" class="form-control tpl-activite"
                value="Agent de Sécurité">
        </div>
        <div class="col-md-4">
            <label class="form-label">Plage horaire</label>
            <input type="text" name="profils[__IDX__][plage]" class="form-control tpl-plage"
                value="De 07h00 à 19h00" placeholder="De HHhMM à HHhMM">
        </div>
    </div>
    <div class="form-section-title mt-3 mb-2" style="font-size:0.75rem;color:var(--ov-gold);text-transform:uppercase;letter-spacing:1px">
        Taux horaires HT (€)
    </div>
    <div class="row g-2">
        <div class="col">
            <label class="form-label text-center d-block" style="font-size:0.75rem">JN Jour<br><small class="text-muted">Jour Normal</small></label>
            <input type="number" name="profils[__IDX__][taux_jn]" class="form-control form-control-sm text-center tpl-jn" step="0.01" min="0" value="25.90">
        </div>
        <div class="col">
            <label class="form-label text-center d-block" style="font-size:0.75rem">NN Nuit<br><small class="text-muted">Jour Normal</small></label>
            <input type="number" name="profils[__IDX__][taux_nn]" class="form-control form-control-sm text-center tpl-nn" step="0.01" min="0" value="27.90">
        </div>
        <div class="col">
            <label class="form-label text-center d-block" style="font-size:0.75rem">JD Jour<br><small class="text-muted">Dimanche</small></label>
            <input type="number" name="profils[__IDX__][taux_jd]" class="form-control form-control-sm text-center tpl-jd" step="0.01" min="0" value="27.90">
        </div>
        <div class="col">
            <label class="form-label text-center d-block" style="font-size:0.75rem">ND Nuit<br><small class="text-muted">Dimanche</small></label>
            <input type="number" name="profils[__IDX__][taux_nd]" class="form-control form-control-sm text-center tpl-nd" step="0.01" min="0" value="30.90">
        </div>
        <div class="col">
            <label class="form-label text-center d-block" style="font-size:0.75rem">JF Jour<br><small class="text-muted">Férié</small></label>
            <input type="number" name="profils[__IDX__][taux_jf]" class="form-control form-control-sm text-center tpl-jf" step="0.01" min="0" value="51.80">
        </div>
        <div class="col">
            <label class="form-label text-center d-block" style="font-size:0.75rem">NF Nuit<br><small class="text-muted">Férié</small></label>
            <input type="number" name="profils[__IDX__][taux_nf]" class="form-control form-control-sm text-center tpl-nf" step="0.01" min="0" value="55.80">
        </div>
    </div>
</div>
</template>

<script>
(function() {
    var container = document.getElementById('profilsContainer');
    var btnAdd    = document.getElementById('btnAddProfil');
    var noMsg     = document.getElementById('noProfilMsg');
    var counter   = 0;

    var PROFILS_TYPES = {
        'agent-jour':   { label: 'Profil : Agent De Jour',        activite: 'Agent de Sécurité',  plage: 'De 07h00 à 19h00', jn: 25.90, nn: 27.90, jd: 27.90, nd: 30.90, jf: 51.80, nf: 55.80 },
        'agent-nuit':   { label: 'Profil : Agent De Nuit',        activite: 'Agent de Sécurité',  plage: 'De 19h00 à 07h00', jn: 25.90, nn: 27.90, jd: 27.90, nd: 30.90, jf: 51.80, nf: 55.80 },
        'cynophile':    { label: 'Profil : Maître Chien',         activite: 'Agent Cynophile',    plage: 'De 20h00 à 06h00', jn: 28.00, nn: 30.00, jd: 30.00, nd: 33.00, jf: 56.00, nf: 60.00 },
        'ssiap1':       { label: 'Profil : Agent SSIAP 1',        activite: 'Agent SSIAP',        plage: 'De 07h00 à 19h00', jn: 26.50, nn: 28.50, jd: 28.50, nd: 31.50, jf: 53.00, nf: 57.00 },
        'ssiap2':       { label: "Profil : Chef d'équipe SSIAP 2",activite: 'Agent SSIAP',        plage: 'De 07h00 à 19h00', jn: 28.00, nn: 30.00, jd: 30.00, nd: 33.00, jf: 56.00, nf: 60.00 },
        'chef-equipe':  { label: "Profil : Chef D'Équipe",        activite: "Chef d'Équipe",      plage: 'De 07h00 à 19h00', jn: 27.50, nn: 29.50, jd: 29.50, nd: 32.50, jf: 55.00, nf: 59.00 },
    };

    function applyTemplate(block, key) {
        var t = PROFILS_TYPES[key];
        if (!t) return;
        block.querySelector('.tpl-label').value   = t.label;
        block.querySelector('.tpl-activite').value = t.activite;
        block.querySelector('.tpl-plage').value    = t.plage;
        block.querySelector('.tpl-jn').value = t.jn.toFixed(2);
        block.querySelector('.tpl-nn').value = t.nn.toFixed(2);
        block.querySelector('.tpl-jd').value = t.jd.toFixed(2);
        block.querySelector('.tpl-nd').value = t.nd.toFixed(2);
        block.querySelector('.tpl-jf').value = t.jf.toFixed(2);
        block.querySelector('.tpl-nf').value = t.nf.toFixed(2);
    }

    function addProfil() {
        var tpl = document.getElementById('tplProfil');
        var idx = counter++;
        var num = container.querySelectorAll('.profil-block').length + 1;
        var html = tpl.innerHTML
            .replace(/__IDX__/g, idx)
            .replace(/__NUM__/g, num);
        var div = document.createElement('div');
        div.innerHTML = html;
        var block = div.firstElementChild;
        block.querySelector('.btn-remove-profil').addEventListener('click', function() {
            block.remove();
            renumberProfils();
            checkEmpty();
        });
        block.querySelector('.tpl-select').addEventListener('change', function() {
            if (this.value) applyTemplate(block, this.value);
            this.value = '';
        });
        container.appendChild(block);
        checkEmpty();
    }

    function renumberProfils() {
        var blocks = container.querySelectorAll('.profil-block');
        blocks.forEach(function(b, i) {
            var span = b.querySelector('.profil-num');
            if (span) span.textContent = i + 1;
        });
    }

    function checkEmpty() {
        var count = container.querySelectorAll('.profil-block').length;
        noMsg.style.display = count === 0 ? '' : 'none';
    }

    btnAdd.addEventListener('click', addProfil);

    // Ajouter un profil par défaut au chargement
    addProfil();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
