<?php
/** Création / modification d'un agent et de ses sites, directement dans l'outil. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');
$db = getDB();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$isNew = $id === 0;
if ($isNew) {
    ptRequireAdmin('create');
    $a = ['id' => 0, 'nom' => '', 'prenom' => '', 'sexe' => 'M', 'telephone' => '', 'email' => '', 'matricule' => '', 'actif' => 1];
    $mesSites = [];
} else {
    $st = $db->prepare("SELECT id, nom, prenom, sexe, telephone, email, matricule, actif FROM agents WHERE id = ?");
    $st->execute([$id]);
    $a = $st->fetch();
    if (!$a) { ptFlash('danger', 'Agent introuvable.'); ptRedirect('admin/agents.php'); }
    $st = $db->prepare("SELECT mission_id FROM mission_agents WHERE agent_id = ?");
    $st->execute([$id]);
    $mesSites = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}
$sites = $db->query("SELECT m.id, m.nom, c.nom AS client_nom FROM missions m LEFT JOIN clients c ON c.id = m.client_id
    WHERE m.actif = 1 ORDER BY m.nom")->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ptRequireAdmin($isNew ? 'create' : 'edit');
    if (!ptCsrfCheck()) $errors[] = 'Session expirée, réessayez.';
    $in = [
        'prenom'    => trim((string)($_POST['prenom'] ?? '')),
        'nom'       => mb_strtoupper(trim((string)($_POST['nom'] ?? ''))),
        'sexe'      => ($_POST['sexe'] ?? 'M') === 'F' ? 'F' : 'M',
        'telephone' => trim((string)($_POST['telephone'] ?? '')),
        'email'     => trim((string)($_POST['email'] ?? '')),
        'matricule' => strtoupper(trim((string)($_POST['matricule'] ?? ''))),
        'actif'     => $isNew ? 1 : (isset($_POST['actif']) ? 1 : 0),
    ];
    $choisis = array_values(array_intersect(array_map('intval', (array)($_POST['sites'] ?? [])), array_map('intval', array_column($sites, 'id'))));

    if ($in['prenom'] === '' || $in['nom'] === '') $errors[] = 'Le nom et le prénom sont obligatoires.';
    if ($in['telephone'] !== '' && !preg_match('/^[\d\s+().-]{6,20}$/', $in['telephone'])) $errors[] = 'Numéro de téléphone invalide.';
    if ($in['email'] !== '' && !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Adresse email invalide.';
    if ($in['telephone'] === '' && $in['email'] === '') $errors[] = 'Indiquez au moins un téléphone ou un email (pour envoyer le lien d\'activation).';
    if ($in['matricule'] !== '') {
        $st = $db->prepare("SELECT COUNT(*) FROM agents WHERE matricule = ? AND id <> ?");
        $st->execute([$in['matricule'], $id]);
        if ($st->fetchColumn()) $errors[] = 'Ce matricule est déjà utilisé.';
    }

    if (!$errors) {
        $db->beginTransaction();
        if ($isNew) {
            if ($in['matricule'] === '') $in['matricule'] = ptGenererMatricule($in['sexe']);
            $db->prepare("INSERT INTO agents (nom, prenom, sexe, telephone, email, matricule, actif) VALUES (?,?,?,?,?,?,1)")
               ->execute([$in['nom'], $in['prenom'], $in['sexe'], $in['telephone'] ?: null, $in['email'] ?: null, $in['matricule']]);
            $id = (int)$db->lastInsertId();
        } else {
            $db->prepare("UPDATE agents SET nom = ?, prenom = ?, sexe = ?, telephone = ?, email = ?, matricule = ?, actif = ? WHERE id = ?")
               ->execute([$in['nom'], $in['prenom'], $in['sexe'], $in['telephone'] ?: null, $in['email'] ?: null,
                          $in['matricule'] ?: $a['matricule'], $in['actif'], $id]);
            if (!$in['actif']) {   // agent désactivé : plus d'accès à l'application
                $db->prepare("DELETE FROM pt_appareils WHERE agent_id = ?")->execute([$id]);
            }
        }
        // Affectations : on ne touche qu'aux sites actifs listés ici (les missions inactives restent intactes)
        $actifs = array_map('intval', array_column($sites, 'id'));
        if ($actifs) {
            $db->prepare("DELETE FROM mission_agents WHERE agent_id = ? AND mission_id IN (" . implode(',', $actifs) . ")")->execute([$id]);
        }
        $ins = $db->prepare("INSERT IGNORE INTO mission_agents (mission_id, agent_id) VALUES (?, ?)");
        foreach ($choisis as $mid) $ins->execute([$mid, $id]);
        $db->commit();

        if ($isNew && !empty($_POST['inviter'])) {
            ptGenererActivation($id);
            ptFlash('success', 'Agent ' . $in['prenom'] . ' ' . $in['nom'] . ' créé (matricule ' . $in['matricule'] . '). Envoyez-lui son lien d\'activation ci-dessous.');
            ptRedirect('admin/agents.php?show=' . $id . '#agent-' . $id);
        }
        ptFlash('success', 'Agent ' . $in['prenom'] . ' ' . $in['nom'] . ($isNew ? ' créé (matricule ' . $in['matricule'] . ').' : ' enregistré.'));
        ptRedirect('admin/agents.php#agent-' . $id);
    }
    $a = array_merge($a, $in);
    $mesSites = $choisis;
}

ptAdminHead($isNew ? 'Nouvel agent' : $a['prenom'] . ' ' . $a['nom'], 'agents');
?>
<div class="pta-title">
    <div>
        <a href="agents.php" class="text-muted small text-decoration-none"><i class="fa fa-arrow-left me-1"></i>Agents</a>
        <h1><?= $isNew ? 'Nouvel agent' : h($a['prenom'] . ' ' . $a['nom']) ?></h1>
        <?php if (!$isNew): ?><div class="text-muted small">Matricule <?= h($a['matricule'] ?: '—') ?></div><?php endif; ?>
    </div>
</div>
<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="row g-3">
    <?= ptCsrfField() ?>
    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
    <div class="col-lg-6">
        <div class="pta-card">
            <h2><i class="fa fa-id-card me-1 text-warning"></i> Identité</h2>
            <div class="row g-2">
                <div class="col-sm-6"><label class="form-label small fw-semibold" for="prenom">Prénom *</label>
                    <input class="form-control" id="prenom" name="prenom" value="<?= h($a['prenom']) ?>" required></div>
                <div class="col-sm-6"><label class="form-label small fw-semibold" for="nom">Nom *</label>
                    <input class="form-control" id="nom" name="nom" value="<?= h($a['nom']) ?>" required style="text-transform:uppercase"></div>
                <div class="col-sm-6"><label class="form-label small fw-semibold" for="sexe">Civilité</label>
                    <select class="form-select" id="sexe" name="sexe">
                        <option value="M" <?= $a['sexe'] === 'M' ? 'selected' : '' ?>>Monsieur</option>
                        <option value="F" <?= $a['sexe'] === 'F' ? 'selected' : '' ?>>Madame</option>
                    </select></div>
                <div class="col-sm-6"><label class="form-label small fw-semibold" for="matricule">Matricule</label>
                    <input class="form-control" id="matricule" name="matricule" value="<?= h((string)$a['matricule']) ?>" placeholder="<?= $isNew ? 'Automatique' : '' ?>"></div>
                <div class="col-sm-6"><label class="form-label small fw-semibold" for="tel">Téléphone mobile</label>
                    <input class="form-control" id="tel" name="telephone" value="<?= h((string)$a['telephone']) ?>" placeholder="06 12 34 56 78"></div>
                <div class="col-sm-6"><label class="form-label small fw-semibold" for="email">Email</label>
                    <input class="form-control" id="email" type="email" name="email" value="<?= h((string)$a['email']) ?>"></div>
            </div>
            <div class="small text-muted mt-2">L'agent se connectera avec son matricule ou son téléphone, et le code PIN qu'il choisira lui-même.</div>
            <?php if (!$isNew): ?>
            <label class="form-check mt-3">
                <input class="form-check-input" type="checkbox" name="actif" value="1" <?= $a['actif'] ? 'checked' : '' ?>>
                <span class="form-check-label">Agent actif <span class="text-muted small">(décocher coupe son accès à l'application)</span></span>
            </label>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="pta-card">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="mb-0"><i class="fa fa-location-dot me-1 text-warning"></i> Sites affectés</h2>
                <a href="mission.php" class="small">+ Nouveau site</a>
            </div>
            <?php if (!$sites): ?>
                <div class="text-muted small">Aucun site actif. <a href="mission.php">Créez votre premier site</a>.</div>
            <?php endif; ?>
            <div style="max-height:340px;overflow-y:auto">
            <?php foreach ($sites as $s): ?>
                <label class="d-flex align-items-center gap-2 py-2 border-bottom">
                    <input class="form-check-input m-0" type="checkbox" name="sites[]" value="<?= (int)$s['id'] ?>" <?= in_array((int)$s['id'], $mesSites, true) ? 'checked' : '' ?>>
                    <span><span class="fw-semibold"><?= h($s['nom']) ?></span><?php if ($s['client_nom']): ?> <span class="text-muted small">· <?= h($s['client_nom']) ?></span><?php endif; ?></span>
                </label>
            <?php endforeach; ?>
            </div>
            <div class="small text-muted mt-2">L'agent ne peut pointer que sur ses sites (ou sur ceux où il est prévu au planning).</div>
        </div>
        <?php if ($isNew): ?>
        <label class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="inviter" value="1" checked>
            <span class="form-check-label">Créer tout de suite son lien d'activation (WhatsApp, SMS, email ou QR code)</span>
        </label>
        <?php endif; ?>
        <button class="btn btn-pta w-100 py-2"><i class="fa fa-check me-1"></i><?= $isNew ? 'Créer l\'agent' : 'Enregistrer' ?></button>
    </div>
</form>
<?php ptAdminFoot();
