<?php
/** Liens d'inscription : l'agent crée lui-même son compte ; validation des inscriptions en attente. */
require_once __DIR__ . '/_init.php';
$user = ptRequireAdmin('view');
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ptCsrfCheck()) { ptFlash('danger', 'Session expirée, réessayez.'); ptRedirect('admin/inscriptions.php'); }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'creer') {
        ptRequireAdmin('create');
        $libelle = trim((string)($_POST['libelle'] ?? ''));
        $siteId  = (int)($_POST['mission_id'] ?? 0) ?: null;
        $jours   = (int)($_POST['jours'] ?? 30);
        $max     = (int)($_POST['max_util'] ?? 0);
        $nomSite = '';
        if ($siteId) {
            $st = $db->prepare("SELECT nom FROM missions WHERE id = ? AND actif = 1");
            $st->execute([$siteId]);
            $nomSite = (string)$st->fetchColumn();
            if ($nomSite === '') $siteId = null;
        }
        if ($libelle === '') $libelle = $siteId ? 'Inscription — ' . $nomSite : 'Inscription des agents';
        $db->prepare("INSERT INTO pt_inscriptions (token, libelle, mission_id, validation, expire_le, max_util, created_by) VALUES (?,?,?,?,?,?,?)")
           ->execute([bin2hex(random_bytes(16)), mb_substr($libelle, 0, 150), $siteId, isset($_POST['validation']) ? 1 : 0,
                      in_array($jours, [1, 7, 30, 90], true) ? date('Y-m-d H:i:s', strtotime("+$jours days")) : null,
                      $max > 0 ? min($max, 500) : null, $user['id'] ?? null]);
        ptFlash('success', 'Lien d\'inscription créé. Envoyez-le à vos agents ci-dessous.');
        ptRedirect('admin/inscriptions.php?show=' . (int)$db->lastInsertId());
    }

    ptRequireAdmin('edit');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'desactiver' || $action === 'activer') {
        $db->prepare("UPDATE pt_inscriptions SET actif = ? WHERE id = ?")->execute([$action === 'activer' ? 1 : 0, $id]);
        ptFlash('success', $action === 'activer' ? 'Lien réactivé.' : 'Lien désactivé : il ne permet plus de s\'inscrire.');
        ptRedirect('admin/inscriptions.php');
    }
    if ($action === 'supprimer') {
        $db->prepare("DELETE FROM pt_inscriptions WHERE id = ?")->execute([$id]);
        ptFlash('success', 'Lien supprimé. Les agents déjà inscrits sont conservés.');
        ptRedirect('admin/inscriptions.php');
    }

    // Validation / refus d'un agent inscrit
    $st = $db->prepare("SELECT id, prenom, nom FROM agents WHERE id = ? AND statut_inscription = 'en_attente'");
    $st->execute([$id]);
    $ag = $st->fetch();
    if (!$ag) { ptFlash('danger', 'Inscription introuvable ou déjà traitée.'); ptRedirect('admin/inscriptions.php'); }
    if ($action === 'valider') {
        $db->prepare("UPDATE agents SET actif = 1, statut_inscription = 'validee' WHERE id = ?")->execute([$id]);
        try { ptNotifierAgent($id, 'Compte validé', 'Votre compte est validé : vous pouvez pointer.', 'app.php'); } catch (Throwable $e) {}
        ptFlash('success', $ag['prenom'] . ' ' . $ag['nom'] . ' est validé : il peut pointer.');
    } elseif ($action === 'refuser') {
        $db->beginTransaction();
        $db->prepare("UPDATE agents SET actif = 0, statut_inscription = 'refusee' WHERE id = ?")->execute([$id]);
        $db->prepare("DELETE FROM pt_appareils WHERE agent_id = ?")->execute([$id]);
        $db->prepare("UPDATE pt_acces SET pin_hash = NULL WHERE agent_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM mission_agents WHERE agent_id = ?")->execute([$id]);
        $db->commit();
        ptFlash('success', 'Inscription de ' . $ag['prenom'] . ' ' . $ag['nom'] . ' refusée.');
    }
    ptRedirect('admin/inscriptions.php');
}

$attente = ptInscriptionsEnAttente();
$liens = $db->query("SELECT i.*, m.nom AS site_nom, m.actif AS site_actif FROM pt_inscriptions i LEFT JOIN missions m ON m.id = i.mission_id
    ORDER BY i.actif DESC, i.created_at DESC")->fetchAll();
$sites = $db->query("SELECT id, nom FROM missions WHERE actif = 1 ORDER BY nom")->fetchAll();
$show = (int)($_GET['show'] ?? 0);

ptAdminHead('Liens d\'inscription', 'agents');
?>
<div class="pta-title">
    <div>
        <a href="agents.php" class="text-muted small text-decoration-none"><i class="fa fa-arrow-left me-1"></i>Agents</a>
        <h1>Liens d'inscription</h1>
        <div class="text-muted small">Envoyez un lien : chaque agent saisit son nom, son téléphone et choisit son code PIN.</div>
    </div>
</div>

<?php if ($attente): ?>
<div class="pta-card border border-warning">
    <h2><i class="fa fa-user-clock me-1 text-warning"></i> À valider (<?= count($attente) ?>)</h2>
    <div class="table-responsive"><table class="pta-table">
        <thead><tr><th>Agent</th><th>Téléphone</th><th>Lien / site</th><th>Inscrit le</th><th class="text-end">Décision</th></tr></thead>
        <tbody>
        <?php foreach ($attente as $a): ?>
            <tr>
                <td class="fw-semibold"><?= h($a['nom'] . ' ' . $a['prenom']) ?><div class="text-muted small"><?= h($a['matricule']) ?><?= $a['email'] ? ' · ' . h($a['email']) : '' ?></div></td>
                <td class="small"><?= h($a['telephone']) ?></td>
                <td class="small text-muted"><?= h($a['lien'] ?: '—') ?><?= $a['site_nom'] ? '<br>' . h($a['site_nom']) : '' ?></td>
                <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($a['created_at'])) ?></td>
                <td class="text-end text-nowrap">
                    <?php if (ptAdminCan('edit')): ?>
                    <form method="post" class="d-inline"><?= ptCsrfField() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                        <button class="btn btn-sm btn-success" name="action" value="valider"><i class="fa fa-check me-1"></i>Valider</button>
                        <button class="btn btn-sm btn-outline-danger" name="action" value="refuser" title="Refuser" onclick="return confirm('Refuser cette inscription ?')"><i class="fa fa-xmark"></i></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>
<?php endif; ?>

<div class="row g-3">
<?php if (ptAdminCan('create')): ?>
<div class="col-lg-4">
    <form method="post" class="pta-card">
        <?= ptCsrfField() ?><input type="hidden" name="action" value="creer">
        <h2><i class="fa fa-link me-1 text-warning"></i> Nouveau lien</h2>
        <label class="form-label small fw-semibold" for="libelle">Nom du lien</label>
        <input class="form-control form-control-sm" id="libelle" name="libelle" maxlength="150" placeholder="Ex. Recrutement novembre">
        <label class="form-label small fw-semibold mt-2" for="site">Affecter au site</label>
        <select class="form-select form-select-sm" id="site" name="mission_id">
            <option value="">— Aucun (j'affecterai plus tard) —</option>
            <?php foreach ($sites as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
        </select>
        <div class="row g-2 mt-1">
            <div class="col-7"><label class="form-label small fw-semibold" for="jours">Valable</label>
                <select class="form-select form-select-sm" id="jours" name="jours">
                    <option value="1">24 heures</option><option value="7">7 jours</option><option value="30" selected>30 jours</option>
                    <option value="90">90 jours</option><option value="0">Sans limite</option>
                </select></div>
            <div class="col-5"><label class="form-label small fw-semibold" for="max">Inscriptions max</label>
                <input class="form-control form-control-sm" id="max" name="max_util" type="number" min="1" max="500" placeholder="illimité"></div>
        </div>
        <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="validation" name="validation" value="1" checked>
            <label class="form-check-label small" for="validation"><strong>Je valide chaque inscription</strong> avant que l'agent puisse pointer
                <span class="text-muted d-block">Conseillé : si le lien circule, personne ne pourra pointer sans votre accord.</span></label>
        </div>
        <button class="btn btn-sm btn-pta w-100 mt-3"><i class="fa fa-plus me-1"></i>Créer le lien</button>
    </form>
</div>
<?php endif; ?>
<div class="col-lg-8">
    <div class="pta-card p-0"><div class="table-responsive">
    <table class="pta-table">
        <thead><tr><th class="ps-3">Lien</th><th>Site</th><th>Inscrits</th><th>État</th><th class="text-end pe-3"></th></tr></thead>
        <tbody>
        <?php foreach ($liens as $l):
            $refus = ptInscriptionRefus($l);
            $url = ptUrlInscription($l);
            $msg = 'Bonjour, voici le lien pour créer votre compte sur l\'application de pointage ' . ptMarque() . ' : ' . $url;
        ?>
            <tr>
                <td class="ps-3 fw-semibold"><?= h($l['libelle']) ?><div class="text-muted small fw-normal"><?= (int)$l['validation'] ? 'avec validation' : 'compte actif tout de suite' ?>
                    · <?= $l['expire_le'] ? 'jusqu\'au ' . date('d/m/Y', strtotime($l['expire_le'])) : 'sans limite' ?></div></td>
                <td class="small"><?= h($l['site_nom'] ?: '—') ?></td>
                <td class="small"><?= (int)$l['nb_util'] ?><?= $l['max_util'] !== null ? ' / ' . (int)$l['max_util'] : '' ?></td>
                <td><span class="pta-badge <?= $refus ? 'off' : 'ok' ?>" title="<?= h($refus) ?>"><?= $refus ? (!(int)$l['actif'] ? 'Désactivé' : 'Indisponible') : 'Actif' ?></span></td>
                <td class="text-end pe-3 text-nowrap">
                    <?php if (!$refus): ?><button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#l-<?= (int)$l['id'] ?>"><i class="fa fa-paper-plane me-1"></i>Envoyer</button><?php endif; ?>
                    <?php if (ptAdminCan('edit')): ?>
                    <form method="post" class="d-inline"><?= ptCsrfField() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
                        <?php if ((int)$l['actif']): ?>
                            <button class="btn btn-sm btn-outline-warning" name="action" value="desactiver" title="Désactiver"><i class="fa fa-pause"></i></button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-success" name="action" value="activer" title="Réactiver"><i class="fa fa-play"></i></button>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-danger" name="action" value="supprimer" title="Supprimer" onclick="return confirm('Supprimer ce lien ? Les agents déjà inscrits sont conservés.')"><i class="fa fa-trash"></i></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if (!$refus): ?>
            <tr class="collapse <?= $show === (int)$l['id'] ? 'show' : '' ?>" id="l-<?= (int)$l['id'] ?>">
                <td colspan="5" class="ps-3 pe-3" style="background:#fafbfc">
                    <div class="row g-3 align-items-center py-2">
                        <div class="col-auto"><div class="bg-white p-2 rounded border" data-qr="<?= h($url) ?>"></div></div>
                        <div class="col">
                            <div class="pta-link-box mb-2"><?= h($url) ?></div>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-sm btn-outline-secondary" type="button" data-copy="<?= h($url) ?>"><i class="fa fa-copy me-1"></i>Copier</button>
                                <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="https://wa.me/?text=<?= h(rawurlencode($msg)) ?>"><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp (contact ou groupe)</a>
                                <a class="btn btn-sm btn-outline-primary" href="sms:?&body=<?= h(rawurlencode($msg)) ?>"><i class="fa fa-comment-sms me-1"></i>SMS</a>
                            </div>
                            <div class="small text-muted mt-2">Le même lien sert à plusieurs agents. Ils peuvent aussi scanner le QR code (à afficher au poste, par exemple).</div>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$liens): ?><tr><td colspan="5" class="text-center text-muted py-4">Aucun lien pour l'instant. Créez-en un à gauche.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div></div>
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
