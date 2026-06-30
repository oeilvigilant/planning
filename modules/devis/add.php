<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'create');

$db = getDB();
ensureDevisSchema();
ensureClientsSchema();

$clientsList = $db->query("SELECT id, nom, adresse, contact, telephone FROM clients ORDER BY nom")->fetchAll();

function generateNumeroDevis() {
    $db     = getDB();
    $prefix = 'S-' . date('y') . '-' . date('m') . '-';
    $stmt   = $db->prepare("SELECT COUNT(*) FROM devis WHERE numero LIKE ?");
    $stmt->execute([$prefix . '%']);
    return $prefix . ((int)$stmt->fetchColumn() + 1);
}

$defaultNumero = generateNumeroDevis();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $numero      = trim($_POST['numero'] ?? '');
    $clientId    = (int)($_POST['client_id'] ?? 0) ?: null;
    $clientNom   = trim($_POST['client_nom'] ?? '');
    $clientAdr   = trim($_POST['client_adresse'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $tvaTaux     = (float)($_POST['tva_taux'] ?? 20);
    $statut      = $_POST['statut'] ?? 'brouillon';
    $groupesRaw  = $_POST['groupes'] ?? [];

    if (empty($numero))     $errors[] = 'Le numéro de devis est obligatoire.';
    if (empty($groupesRaw)) $errors[] = 'Au moins un groupe de prestation est requis.';

    // Valider et normaliser les groupes
    $groupes = [];
    foreach ($groupesRaw as $gIdx => $g) {
        $periodesG = [];
        foreach ($g['periodes'] ?? [] as $p) {
            $d = trim($p['debut'] ?? '');
            $f = trim($p['fin']   ?? '');
            if (!$d || !$f) { $errors[] = 'Chaque plage de dates doit avoir une date début et fin.'; break 2; }
            if ($f < $d)    { $errors[] = 'La date de fin doit être ≥ à la date de début.'; break 2; }
            $periodesG[] = ['debut' => $d, 'fin' => $f];
        }
        if (empty($periodesG)) { $errors[] = 'Chaque groupe doit avoir au moins une plage de dates.'; break; }
        $nb = max(1, (int)($g['nb_agents'] ?? 1));
        $groupes[] = [
            'periodes'  => $periodesG,
            'nb_agents' => $nb,
            'label'     => trim($g['label']    ?? 'Agent de Sécurité'),
            'activite'  => trim($g['activite'] ?? 'Agent de Sécurité'),
            'plage'     => trim($g['plage']    ?? 'De 07h00 à 19h00'),
            'taux_jn'   => (float)($g['taux_jn'] ?? 25.90),
            'taux_nn'   => (float)($g['taux_nn'] ?? 27.90),
            'taux_jd'   => (float)($g['taux_jd'] ?? 27.90),
            'taux_nd'   => (float)($g['taux_nd'] ?? 30.90),
            'taux_jf'   => (float)($g['taux_jf'] ?? 51.80),
            'taux_nf'   => (float)($g['taux_nf'] ?? 55.80),
        ];
    }

    if (empty($errors)) {
        $stmtChk = $db->prepare("SELECT COUNT(*) FROM devis WHERE numero = ?");
        $stmtChk->execute([$numero]);
        if ((int)$stmtChk->fetchColumn() > 0) $errors[] = 'Ce numéro de devis existe déjà.';
    }

    if (empty($errors)) {
        $allDebuts = []; $allFins = [];
        foreach ($groupes as $g) {
            foreach ($g['periodes'] as $p) { $allDebuts[] = $p['debut']; $allFins[] = $p['fin']; }
        }
        $dateMin = min($allDebuts);
        $dateMax = max($allFins);

        $db->beginTransaction();
        try {
            $stmtD = $db->prepare("
                INSERT INTO devis (client_id, numero, client_nom, client_adresse, periode_debut, periode_fin,
                    description, tva_taux, statut, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmtD->execute([
                $clientId, $numero, $clientNom, $clientAdr,
                $dateMin, $dateMax,
                $description, $tvaTaux,
                in_array($statut, ['brouillon','envoye','accepte','refuse']) ? $statut : 'brouillon',
                getCurrentUser()['id'],
            ]);
            $devisId = (int)$db->lastInsertId();

            $stmtPer = $db->prepare("INSERT INTO devis_periodes (devis_id, ordre, date_debut, date_fin) VALUES (?,?,?,?)");
            $stmtP   = $db->prepare("
                INSERT INTO devis_profils (devis_id, ordre, label, activite, plage,
                    taux_jn, taux_nn, taux_jd, taux_nd, taux_jf, taux_nf)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $ordre = 0;
            foreach ($groupes as $gIdx => $g) {
                // Union des jours de ce groupe
                $gJourSet = [];
                foreach ($g['periodes'] as $p) {
                    $cur = strtotime($p['debut']); $end = strtotime($p['fin']);
                    while ($cur <= $end) { $gJourSet[date('Y-m-d', $cur)] = true; $cur = strtotime('+1 day', $cur); }
                }
                ksort($gJourSet);
                $gJours = array_keys($gJourSet);

                // Enregistrer les plages de dates du groupe
                foreach ($g['periodes'] as $i => $p) {
                    $stmtPer->execute([$devisId, $gIdx * 100 + $i, $p['debut'], $p['fin']]);
                }

                // Créer nb_agents profils, chacun avec des lignes uniquement pour les jours de ce groupe
                $nb = $g['nb_agents'];
                for ($a = 1; $a <= $nb; $a++) {
                    $label = $g['label'] . ($nb > 1 ? ' — Agent ' . $a : '');
                    $stmtP->execute([
                        $devisId, $ordre++, $label,
                        $g['activite'], $g['plage'],
                        $g['taux_jn'], $g['taux_nn'],
                        $g['taux_jd'], $g['taux_nd'],
                        $g['taux_jf'], $g['taux_nf'],
                    ]);
                    insertLignesProfil($db, (int)$db->lastInsertId(), $gJours);
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

// Données initiales pour le JS (pré-remplissage en cas d'erreur POST)
$initialGroupes = !empty($_POST['groupes']) ? json_encode($_POST['groupes']) : 'null';

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

<!-- Informations générales -->
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
                        <?= (int)($_POST['client_id'] ?? 0) === $cl['id'] ? 'selected' : '' ?>>
                        <?= h($cl['nom']) ?><?= $cl['contact'] ? ' — ' . h($cl['contact']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <input type="hidden" name="client_id" id="clientIdHidden" value="<?= (int)($_POST['client_id'] ?? 0) ?: '' ?>">
                <input type="text" name="client_nom" id="clientNomInput" class="form-control"
                    value="<?= h($_POST['client_nom'] ?? '') ?>"
                    placeholder="Nom de l'entreprise ou du client">
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
                <input type="number" name="tva_taux" class="form-control" step="0.01" min="0" max="100"
                    value="<?= h($_POST['tva_taux'] ?? '20') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Statut</label>
                <select name="statut" class="form-select">
                    <option value="brouillon" <?= ($_POST['statut'] ?? 'brouillon') === 'brouillon' ? 'selected' : '' ?>>Brouillon</option>
                    <option value="envoye"    <?= ($_POST['statut'] ?? '') === 'envoye'  ? 'selected' : '' ?>>Envoyé</option>
                    <option value="accepte"   <?= ($_POST['statut'] ?? '') === 'accepte' ? 'selected' : '' ?>>Accepté</option>
                    <option value="refuse"    <?= ($_POST['statut'] ?? '') === 'refuse'  ? 'selected' : '' ?>>Refusé</option>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">Adresse du client</label>
                <textarea name="client_adresse" id="clientAdrInput" class="form-control" rows="2"
                    placeholder="Adresse complète"><?= h($_POST['client_adresse'] ?? '') ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Description / Objet du devis</label>
                <textarea name="description" class="form-control" rows="2"
                    placeholder="Description des prestations..."><?= h($_POST['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
</div>
</div>

<!-- Groupes de prestations -->
<div class="col-12">
<div class="ov-card mb-3">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-layer-group me-2" style="color:var(--ov-gold)"></i>Groupes de prestations
        </h2>
        <button type="button" class="btn btn-ov-secondary btn-sm" id="btnAddGroupe">
            <i class="fa fa-plus me-1"></i>Ajouter un groupe
        </button>
    </div>
    <div class="ov-card-body">
        <div class="text-muted mb-3" style="font-size:0.82rem">
            <i class="fa fa-circle-info me-1" style="color:var(--ov-gold)"></i>
            Définissez un groupe par ensemble de dates ayant le même nombre d'agents.
            <br>Ex : <em>3 agents les 5, 6 et 14 juin</em> = un groupe avec 3 plages (ou 1 plage Jun 5-6 + 1 plage Jun 14-14).
        </div>
        <div id="groupesContainer"></div>
    </div>
</div>
</div>

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

<script>
(function() {
    var groupesContainer = document.getElementById('groupesContainer');
    var btnAddGroupe     = document.getElementById('btnAddGroupe');
    var gCounter = 0;

    var PROFILS_TYPES = {
        'agent-jour':  {label:'Agent de Jour',          activite:'Agent de Sécurité', plage:'De 07h00 à 19h00',jn:25.90,nn:27.90,jd:27.90,nd:30.90,jf:51.80,nf:55.80},
        'agent-nuit':  {label:'Agent de Nuit',          activite:'Agent de Sécurité', plage:'De 19h00 à 07h00',jn:25.90,nn:27.90,jd:27.90,nd:30.90,jf:51.80,nf:55.80},
        'cynophile':   {label:'Maître Chien',            activite:'Agent Cynophile',   plage:'De 20h00 à 06h00',jn:28.00,nn:30.00,jd:30.00,nd:33.00,jf:56.00,nf:60.00},
        'ssiap1':      {label:'Agent SSIAP 1',           activite:'Agent SSIAP',       plage:'De 07h00 à 19h00',jn:26.50,nn:28.50,jd:28.50,nd:31.50,jf:53.00,nf:57.00},
        'ssiap2':      {label:"Chef d'équipe SSIAP 2",  activite:'Agent SSIAP',       plage:'De 07h00 à 19h00',jn:28.00,nn:30.00,jd:30.00,nd:33.00,jf:56.00,nf:60.00},
        'chef-equipe': {label:"Chef d'Équipe",           activite:"Chef d'Équipe",     plage:'De 07h00 à 19h00',jn:27.50,nn:29.50,jd:29.50,nd:32.50,jf:55.00,nf:59.00},
    };

    // ── Créer une ligne de plage de dates ─────────────────────────────────────
    function makePeriodeLine(gIdx, pIdx, debut, fin) {
        var div = document.createElement('div');
        div.className = 'gperiode-block d-flex align-items-center gap-2 mb-2';
        div.innerHTML =
            '<input type="date" name="groupes[' + gIdx + '][periodes][' + pIdx + '][debut]"' +
            '   class="form-control form-control-sm" style="max-width:150px"' +
            '   value="' + (debut||'') + '" required>' +
            '<span class="text-muted">→</span>' +
            '<input type="date" name="groupes[' + gIdx + '][periodes][' + pIdx + '][fin]"' +
            '   class="form-control form-control-sm" style="max-width:150px"' +
            '   value="' + (fin||'') + '" required>' +
            '<button type="button" class="btn btn-sm btn-outline-danger btn-remove-gperiode" title="Supprimer cette plage">' +
            '   <i class="fa fa-times"></i>' +
            '</button>';
        div.querySelector('.btn-remove-gperiode').addEventListener('click', function() {
            div.remove();
        });
        return div;
    }

    // ── Créer un bloc groupe ──────────────────────────────────────────────────
    function addGroupe(prefill) {
        var gIdx  = gCounter++;
        var gNum  = groupesContainer.querySelectorAll('.groupe-block').length + 1;
        var block = document.createElement('div');
        block.className = 'groupe-block border rounded mb-3';
        block.dataset.gidx = gIdx;
        block.style.cssText = 'border-color:rgba(201,168,76,0.3)!important';

        var nb    = prefill ? (parseInt(prefill.nb_agents)||1) : 1;
        var label = prefill ? (prefill.label||'Agent de Sécurité') : 'Agent de Sécurité';
        var plage = prefill ? (prefill.plage||'De 07h00 à 19h00') : 'De 07h00 à 19h00';
        var taux  = {
            jn: prefill ? (prefill.taux_jn||25.90) : 25.90,
            nn: prefill ? (prefill.taux_nn||27.90) : 27.90,
            jd: prefill ? (prefill.taux_jd||27.90) : 27.90,
            nd: prefill ? (prefill.taux_nd||30.90) : 30.90,
            jf: prefill ? (prefill.taux_jf||51.80) : 51.80,
            nf: prefill ? (prefill.taux_nf||55.80) : 55.80,
        };

        block.innerHTML = [
            '<div class="p-2 px-3 d-flex align-items-center justify-content-between"',
            '     style="background:rgba(201,168,76,0.07);border-bottom:1px solid rgba(201,168,76,0.15)">',
            '    <h6 class="mb-0 fw-bold" style="color:var(--ov-gold)">',
            '        <i class="fa fa-layer-group me-2"></i>Groupe <span class="groupe-num">'+gNum+'</span>',
            '    </h6>',
            '    <button type="button" class="btn btn-sm btn-outline-danger btn-remove-groupe">',
            '        <i class="fa fa-times"></i>',
            '    </button>',
            '</div>',
            '<div class="p-3">',
            '    <div class="mb-3">',
            '        <div class="d-flex align-items-center justify-content-between mb-2">',
            '            <label class="form-label mb-0 fw-600" style="font-size:0.85rem">',
            '                <i class="fa fa-calendar me-1" style="color:var(--ov-gold)"></i>',
            '                Jours de prestation <span class="text-danger">*</span>',
            '            </label>',
            '            <button type="button" class="btn btn-sm btn-outline-secondary btn-add-gperiode">',
            '                <i class="fa fa-plus me-1"></i>Plage',
            '            </button>',
            '        </div>',
            '        <div class="gperiodes-container"></div>',
            '    </div>',
            '    <div class="row g-3 mb-3">',
            '        <div class="col-md-2">',
            '            <label class="form-label">Nb agents <span class="text-danger">*</span></label>',
            '            <input type="number" name="groupes['+gIdx+'][nb_agents]" class="form-control"',
            '                min="1" value="'+nb+'" required>',
            '        </div>',
            '        <div class="col-md-5">',
            '            <label class="form-label">Label / Activité</label>',
            '            <input type="text" name="groupes['+gIdx+'][label]" class="form-control g-label"',
            '                placeholder="Ex: Agent de Sécurité" value="'+escHtml(label)+'">',
            '        </div>',
            '        <div class="col-md-5">',
            '            <label class="form-label">Plage horaire</label>',
            '            <input type="text" name="groupes['+gIdx+'][plage]" class="form-control g-plage"',
            '                value="'+escHtml(plage)+'">',
            '        </div>',
            '    </div>',
            '    <div class="d-flex align-items-center justify-content-between mb-2">',
            '        <div>',
            '            <div style="font-size:0.72rem;color:var(--ov-gold);text-transform:uppercase;letter-spacing:1px">Taux horaires HT (€)</div>',
            '            <div style="font-size:0.62rem;color:#9ca3af;margin-top:1px"><i class="fa fa-wand-magic-sparkles me-1" style="color:var(--ov-gold)"></i>Modifier <strong>JOUR Normal</strong> → suggestions automatiques</div>',
            '        </div>',
            '        <select class="form-select form-select-sm g-type-select" style="width:200px">',
            '            <option value="">— Appliquer un profil type —</option>',
            '            <option value="agent-jour">Agent de Jour (07h-19h)</option>',
            '            <option value="agent-nuit">Agent de Nuit (19h-07h)</option>',
            '            <option value="cynophile">Maître Chien</option>',
            '            <option value="ssiap1">Agent SSIAP 1</option>',
            '            <option value="ssiap2">Chef d\'équipe SSIAP 2</option>',
            '            <option value="chef-equipe">Chef d\'Équipe</option>',
            '        </select>',
            '    </div>',
            '    <div class="row g-2">',
            makeTauxField(gIdx,'jn','JOUR','Normal',    taux.jn),
            makeTauxField(gIdx,'nn','NUIT','Normal',    taux.nn),
            makeTauxField(gIdx,'jd','JOUR','Dimanche',  taux.jd),
            makeTauxField(gIdx,'nd','NUIT','Dimanche',  taux.nd),
            makeTauxField(gIdx,'jf','JOUR','Férié',     taux.jf),
            makeTauxField(gIdx,'nf','NUIT','Férié',     taux.nf),
            '    </div>',
            '</div>',
        ].join('');

        // Bouton supprimer groupe
        block.querySelector('.btn-remove-groupe').addEventListener('click', function() {
            block.remove();
            renumberGroupes();
        });

        // Bouton ajouter plage
        var pContainer = block.querySelector('.gperiodes-container');
        var pCounter   = 0;
        block.querySelector('.btn-add-gperiode').addEventListener('click', function() {
            pContainer.appendChild(makePeriodeLine(gIdx, pCounter++, '', ''));
        });

        // Pré-remplir les périodes
        if (prefill && prefill.periodes) {
            Object.values(prefill.periodes).forEach(function(p) {
                pContainer.appendChild(makePeriodeLine(gIdx, pCounter++, p.debut||'', p.fin||''));
            });
        } else {
            pContainer.appendChild(makePeriodeLine(gIdx, pCounter++, '', ''));
        }

        // Select type profil
        block.querySelector('.g-type-select').addEventListener('change', function() {
            var t = PROFILS_TYPES[this.value]; if (!t) return;
            block.querySelector('.g-label').value = t.label;
            block.querySelector('.g-plage').value = t.plage;
            ['jn','nn','jd','nd','jf','nf'].forEach(function(k) {
                var inp = block.querySelector('.g-taux-' + k);
                if (inp) inp.value = parseFloat(t[k]).toFixed(2);
            });
            this.value = '';
        });

        // Auto-calcul des majorations depuis le tarif journée normal
        var jnInput = block.querySelector('.g-taux-jn');
        if (jnInput) {
            jnInput.addEventListener('input', function() {
                var jn = parseFloat(this.value) || 0;
                if (jn <= 0) return;
                Object.keys(MAJORATION_RULES).forEach(function(k) {
                    var inp = block.querySelector('.g-taux-' + k);
                    if (inp) inp.value = MAJORATION_RULES[k].fn(jn).toFixed(2);
                });
            });
        }

        groupesContainer.appendChild(block);
    }

    // Règles de majoration depuis le tarif journée normal
    var MAJORATION_RULES = {
        nn: { label: 'Nuit Normal',    fn: function(jn) { return jn + 2; } },
        jd: { label: 'Jour Dim.',      fn: function(jn) { return jn + 2; } },
        nd: { label: 'Nuit Dim.',      fn: function(jn) { return jn + 5; } },
        jf: { label: 'Jour Férié',     fn: function(jn) { return jn * 2; } },
        nf: { label: 'Nuit Férié',     fn: function(jn) { return jn * 2 + 4; } },
    };

    function makeTauxField(gIdx, key, sub, grp, val) {
        var isBase  = (key === 'jn');
        var majRule = !isBase && MAJORATION_RULES[key] ? MAJORATION_RULES[key] : null;
        var inputStyle = isBase
            ? 'style="border-color:var(--ov-gold);background:rgba(201,168,76,0.06);font-weight:700"'
            : '';
        var hint = isBase
            ? '<div style="font-size:0.58rem;color:var(--ov-gold);text-align:center;margin-top:2px;white-space:nowrap">' +
              '<i class="fa fa-wand-magic-sparkles me-1"></i>Base de calcul</div>'
            : '';
        return '<div class="col">' +
            '<label class="form-label text-center d-block" style="font-size:0.72rem">' + sub + '<br><small class="text-muted">' + grp + '</small></label>' +
            '<input type="number" name="groupes[' + gIdx + '][taux_' + key + ']"' +
            '   class="form-control form-control-sm text-center g-taux-' + key + '"' +
            '   step="0.01" min="0" value="' + parseFloat(val).toFixed(2) + '"' +
            '   ' + inputStyle + '>' +
            hint +
            '</div>';
    }

    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function renumberGroupes() {
        groupesContainer.querySelectorAll('.groupe-block').forEach(function(b, i) {
            var s = b.querySelector('.groupe-num'); if (s) s.textContent = i + 1;
        });
    }

    btnAddGroupe.addEventListener('click', function() { addGroupe(null); });

    // Initialisation
    var initialData = <?= $initialGroupes ?? 'null' ?>;
    if (initialData && Object.keys(initialData).length) {
        Object.values(initialData).forEach(function(g) { addGroupe(g); });
    } else {
        addGroupe(null);
    }

    // ── Sélecteur client ──────────────────────────────────────────────────────
    var sel = document.getElementById('clientSelect');
    if (sel) {
        sel.addEventListener('change', function() {
            var opt = this.options[this.selectedIndex];
            document.getElementById('clientIdHidden').value = opt.value || '';
            document.getElementById('clientNomInput').value = opt.value ? opt.dataset.nom     : '';
            document.getElementById('clientAdrInput').value = opt.value ? opt.dataset.adresse : '';
        });
    }
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
