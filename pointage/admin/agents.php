<?php
/** Agents & accès : générer / renvoyer le lien d'activation, couper un accès. */
require_once __DIR__ . '/_init.php';
$user = ptRequireAdmin('view');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ptCsrfCheck()) { ptFlash('danger', 'Session expirée, réessayez.'); ptRedirect('admin/agents.php'); }
    ptRequireAdmin('edit');
    $agentId = (int)($_POST['agent_id'] ?? 0);
    $action  = (string)($_POST['action'] ?? '');
    $st = $db->prepare("SELECT id, prenom, nom FROM agents WHERE id = ? AND actif = 1");
    $st->execute([$agentId]);
    $ag = $st->fetch();
    if (!$ag) { ptFlash('danger', 'Agent introuvable.'); ptRedirect('admin/agents.php'); }

    if ($action === 'lien') {
        ptGenererActivation($agentId);
        ptFlash('success', 'Lien d\'activation créé pour ' . $ag['prenom'] . ' ' . $ag['nom'] . '. Envoyez-le-lui ci-dessous.');
        ptRedirect('admin/agents.php?show=' . $agentId . '#agent-' . $agentId);
    }
    if ($action === 'couper') {
        $db->prepare("DELETE FROM pt_appareils WHERE agent_id = ?")->execute([$agentId]);
        $db->prepare("UPDATE pt_acces SET pin_hash = NULL, activation_token = NULL, activation_expires = NULL WHERE agent_id = ?")->execute([$agentId]);
        ptFlash('success', 'Accès coupé pour ' . $ag['prenom'] . ' ' . $ag['nom'] . '. Il faudra un nouveau lien pour se reconnecter.');
        ptRedirect('admin/agents.php');
    }
    ptRedirect('admin/agents.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$show = (int)($_GET['show'] ?? 0);
$sql = "SELECT a.id, a.nom, a.prenom, a.matricule, a.telephone, a.email,
        pa.activation_token, pa.activation_expires, pa.pin_hash, pa.active_le, pa.rgpd_version, pa.rgpd_accepte_le, pa.derniere_connexion,
        (SELECT GROUP_CONCAT(m.nom ORDER BY m.nom SEPARATOR ', ') FROM mission_agents ma JOIN missions m ON m.id = ma.mission_id
            WHERE ma.agent_id = a.id AND m.actif = 1) AS missions
    FROM agents a
    LEFT JOIN pt_acces pa ON pa.agent_id = a.id
    WHERE a.actif = 1 AND (a.statut_inscription IS NULL OR a.statut_inscription = 'validee')";
$params = [];
if ($q !== '') {
    $sql .= " AND (a.nom LIKE ? OR a.prenom LIKE ? OR a.matricule LIKE ? OR a.telephone LIKE ?)";
    $params = array_fill(0, 4, '%' . $q . '%');
}
$sql .= " ORDER BY a.nom, a.prenom";
$st = $db->prepare($sql);
$st->execute($params);
$agents = $st->fetchAll();

$rgpdV = ptRgpdVersion();
$nbActifs = 0;
foreach ($agents as $a) if ($a['pin_hash']) $nbActifs++;

function ptStatutAcces(array $a, int $rgpdV): array {
    if ($a['pin_hash']) {
        if ((int)$a['rgpd_version'] < $rgpdV) return ['wait', 'RGPD à revalider'];
        return ['ok', 'Activé'];
    }
    if ($a['activation_token']) {
        return strtotime($a['activation_expires']) < time() ? ['ko', 'Lien expiré'] : ['wait', 'Lien envoyé'];
    }
    return ['off', 'Non invité'];
}

ptAdminHead('Agents & accès', 'agents');
?>
<div class="pta-title">
    <div>
        <h1>Agents & accès</h1>
        <div class="text-muted small"><?= $nbActifs ?> agent(s) sur <?= count($agents) ?> ont activé l'application</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <form class="d-flex gap-2" method="get">
            <input class="form-control form-control-sm" name="q" value="<?= h($q) ?>" placeholder="Rechercher un agent…" style="min-width:200px">
            <button class="btn btn-sm btn-outline-secondary"><i class="fa fa-search"></i></button>
        </form>
        <?php if (ptAdminCan('create')): ?>
        <a class="btn btn-sm btn-pta" href="agent.php"><i class="fa fa-user-plus me-1"></i>Nouvel agent</a>
        <?php endif; ?>
    </div>
</div>

<div class="pta-card p-0">
<div class="table-responsive">
<table class="pta-table">
    <thead><tr>
        <th class="ps-3">Agent</th><th>Missions</th><th>Accès</th><th>Dernière connexion</th><th class="text-end pe-3">Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($agents as $a):
        [$cls, $lib] = ptStatutAcces($a, $rgpdV);
        $lienActif = $a['activation_token'] && strtotime($a['activation_expires']) >= time();
        $lien = $lienActif ? ptUrl('activer.php?t=' . $a['activation_token']) : '';
        $msg = "Bonjour " . $a['prenom'] . ", voici votre lien personnel pour activer l'application de pointage " . ptMarque() . " : " . $lien
             . " (valable jusqu'au " . ($a['activation_expires'] ? date('d/m/Y', strtotime($a['activation_expires'])) : '') . ").";
        $tel = $a['telephone'] ? ptTelWhatsapp($a['telephone']) : '';
    ?>
        <tr id="agent-<?= (int)$a['id'] ?>">
            <td class="ps-3">
                <a class="fw-semibold text-reset" href="agent.php?id=<?= (int)$a['id'] ?>"><?= h($a['nom'] . ' ' . $a['prenom']) ?></a>
                <div class="text-muted small"><?= h($a['matricule'] ?: '—') ?> · <?= h($a['telephone'] ?: 'pas de téléphone') ?></div>
            </td>
            <td class="small text-muted" style="max-width:240px"><?= h($a['missions'] ?: '—') ?></td>
            <td>
                <span class="pta-badge <?= $cls ?>"><?= h($lib) ?></span>
                <?php if ($a['active_le']): ?><div class="text-muted small mt-1">depuis le <?= date('d/m/Y', strtotime($a['active_le'])) ?></div><?php endif; ?>
            </td>
            <td class="small text-muted"><?= $a['derniere_connexion'] ? date('d/m/Y H:i', strtotime($a['derniere_connexion'])) : '—' ?></td>
            <td class="text-end pe-3 text-nowrap">
                <?php if ($lienActif): ?>
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#lien-<?= (int)$a['id'] ?>">
                        <i class="fa fa-paper-plane me-1"></i>Envoyer le lien</button>
                <?php endif; ?>
                <?php if (ptAdminCan('edit')): ?>
                <form method="post" class="d-inline">
                    <?= ptCsrfField() ?>
                    <input type="hidden" name="agent_id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="action" value="lien">
                    <button class="btn btn-sm btn-pta" <?= $a['pin_hash'] ? 'onclick="return confirm(\'Générer un nouveau lien déconnectera l\\\'agent de ses appareils et lui fera choisir un nouveau PIN. Continuer ?\')"' : '' ?>>
                        <i class="fa fa-link me-1"></i><?= $a['pin_hash'] ? 'Réinitialiser' : ($a['activation_token'] ? 'Nouveau lien' : 'Inviter') ?></button>
                </form>
                <?php if ($a['pin_hash']): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Couper l\'accès de cet agent à l\'application ?')">
                    <?= ptCsrfField() ?>
                    <input type="hidden" name="agent_id" value="<?= (int)$a['id'] ?>">
                    <input type="hidden" name="action" value="couper">
                    <button class="btn btn-sm btn-outline-danger" title="Couper l'accès"><i class="fa fa-ban"></i></button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($lienActif): ?>
        <tr class="collapse <?= $show === (int)$a['id'] ? 'show' : '' ?>" id="lien-<?= (int)$a['id'] ?>">
            <td colspan="5" class="ps-3 pe-3" style="background:#fafbfc">
                <div class="row g-3 align-items-center py-2">
                    <div class="col-auto"><div class="pta-qr bg-white p-2 rounded border" data-qr="<?= h($lien) ?>"></div></div>
                    <div class="col">
                        <div class="small text-muted mb-1">Lien personnel d'activation — valable jusqu'au <?= date('d/m/Y à H:i', strtotime($a['activation_expires'])) ?></div>
                        <div class="pta-link-box mb-2"><?= h($lien) ?></div>
                        <div class="d-flex flex-wrap gap-2">
                            <button class="btn btn-sm btn-outline-secondary" type="button" data-copy="<?= h($lien) ?>"><i class="fa fa-copy me-1"></i>Copier</button>
                            <?php if ($tel): ?>
                            <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="https://wa.me/<?= h($tel) ?>?text=<?= h(rawurlencode($msg)) ?>"><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp</a>
                            <a class="btn btn-sm btn-outline-primary" href="sms:<?= h(preg_replace('/[^\d+]/', '', $a['telephone'])) ?>?&body=<?= h(rawurlencode($msg)) ?>"><i class="fa fa-comment-sms me-1"></i>SMS</a>
                            <?php endif; ?>
                            <?php if ($a['email']): ?>
                            <a class="btn btn-sm btn-outline-primary" href="mailto:<?= h($a['email']) ?>?subject=<?= h(rawurlencode('Votre application de pointage ' . ptMarque())) ?>&body=<?= h(rawurlencode($msg)) ?>"><i class="fa fa-envelope me-1"></i>Email</a>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted mt-2">Astuce : l'agent peut aussi scanner le QR code avec son téléphone.</div>
                    </div>
                </div>
            </td>
        </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php if (!$agents): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">Aucun agent actif. <a href="agent.php">Créez votre premier agent</a>.</td></tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div>
<?php
$scripts = <<<HTML
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(function (el) {
    new QRCode(el, { text: el.getAttribute('data-qr'), width: 120, height: 120, correctLevel: QRCode.CorrectLevel.M });
});
document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
        navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () {
            var t = b.innerHTML; b.innerHTML = '<i class="fa fa-check me-1"></i>Copié'; setTimeout(function () { b.innerHTML = t; }, 1500);
        });
    });
});
</script>
HTML;
ptAdminFoot($scripts);
