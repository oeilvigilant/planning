<?php
/** Portails clients : liens sécurisés en lecture seule (direct, heures, événements, rapport signé). */
require_once __DIR__ . '/_init.php';
$user = ptRequireAdmin('view');
$db = getDB();

$missions = $db->query("SELECT m.id, m.nom, m.client_id, c.nom AS client_nom FROM missions m LEFT JOIN clients c ON c.id = m.client_id
    WHERE m.actif = 1 ORDER BY c.nom, m.nom")->fetchAll();
$clients = $db->query("SELECT id, nom FROM clients ORDER BY nom")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ptCsrfCheck()) { ptFlash('danger', 'Session expirée.'); ptRedirect('admin/portails.php'); }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'revoquer' || $action === 'reactiver') {
        ptRequireAdmin('edit');
        $db->prepare("UPDATE pt_portails SET revoque = ? WHERE id = ?")->execute([$action === 'revoquer' ? 1 : 0, $id]);
        ptFlash('success', $action === 'revoquer' ? 'Lien désactivé : le client n\'y a plus accès.' : 'Lien réactivé.');
        ptRedirect('admin/portails.php');
    }
    if ($action === 'nouveau_lien') {
        ptRequireAdmin('edit');
        $db->prepare("UPDATE pt_portails SET token = ? WHERE id = ?")->execute([generateToken(), $id]);
        ptFlash('success', 'Nouveau lien généré : l\'ancien ne fonctionne plus.');
        ptRedirect('admin/portails.php#p' . $id);
    }
    if ($action === 'enregistrer') {
        ptRequireAdmin($id ? 'edit' : 'create');
        $libelle = trim((string)($_POST['libelle'] ?? ''));
        $ids = array_values(array_intersect(array_map('intval', (array)($_POST['missions'] ?? [])), array_map('intval', array_column($missions, 'id'))));
        $email = trim((string)($_POST['email'] ?? ''));
        $expire = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expire_le'] ?? '') ? $_POST['expire_le'] : null;
        $clientId = (int)($_POST['client_id'] ?? 0) ?: null;
        if ($libelle === '' || !$ids) { ptFlash('danger', 'Indiquez un nom et au moins un site.'); ptRedirect('admin/portails.php'); }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { ptFlash('danger', 'Email invalide.'); ptRedirect('admin/portails.php'); }
        $vals = [$libelle, $clientId, implode(',', $ids), $email ?: null, isset($_POST['voir_heures']) ? 1 : 0, isset($_POST['voir_evenements']) ? 1 : 0,
                 isset($_POST['voir_photos']) ? 1 : 0, isset($_POST['envoi_hebdo']) ? 1 : 0, $expire];
        if ($id) {
            $db->prepare("UPDATE pt_portails SET libelle=?, client_id=?, missions=?, email=?, voir_heures=?, voir_evenements=?, voir_photos=?, envoi_hebdo=?, expire_le=? WHERE id=?")
               ->execute(array_merge($vals, [$id]));
        } else {
            $db->prepare("INSERT INTO pt_portails (libelle, client_id, missions, email, voir_heures, voir_evenements, voir_photos, envoi_hebdo, expire_le, token, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")->execute(array_merge($vals, [generateToken(), $user['id']]));
            $id = (int)$db->lastInsertId();
        }
        ptFlash('success', 'Portail « ' . $libelle . ' » enregistré. Envoyez le lien à votre client.');
        ptRedirect('admin/portails.php#p' . $id);
    }
}

$portails = $db->query("SELECT p.*, c.nom AS client_nom FROM pt_portails p LEFT JOIN clients c ON c.id = p.client_id ORDER BY p.revoque, p.libelle")->fetchAll();
$nomsMissions = array_column($missions, 'nom', 'id');
$edit = null;
if (!empty($_GET['edit'])) foreach ($portails as $p) if ((int)$p['id'] === (int)$_GET['edit']) $edit = $p;
$editIds = $edit ? array_map('intval', explode(',', $edit['missions'])) : [];

ptAdminHead('Portails clients', 'portails');
?>
<div class="pta-title">
    <div><h1>Portails clients</h1>
        <div class="text-muted small">Un lien sécurisé, sans compte à créer : votre client suit la présence en direct, les heures, les événements et signe ses rapports.</div></div>
</div>

<div class="row g-3">
<div class="col-lg-5">
<?php if (ptAdminCan($edit ? 'edit' : 'create')): ?>
<form method="post" class="pta-card">
    <?= ptCsrfField() ?>
    <input type="hidden" name="action" value="enregistrer"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <h2><?= $edit ? 'Modifier le portail' : 'Nouveau portail client' ?></h2>
    <label class="form-label small fw-semibold">Nom du portail *</label>
    <input class="form-control mb-2" name="libelle" required value="<?= h($edit['libelle'] ?? '') ?>" placeholder="Ex. Centre commercial Rivoli — direction">
    <label class="form-label small fw-semibold">Client</label>
    <select class="form-select mb-2" name="client_id" id="pClient" onchange="var c=this.value;document.querySelectorAll('[data-client]').forEach(function(x){if(c)x.checked=x.dataset.client===c;})">
        <option value="">—</option>
        <?php foreach ($clients as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($edit['client_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['nom']) ?></option><?php endforeach; ?>
    </select>
    <label class="form-label small fw-semibold">Sites visibles *</label>
    <div class="border rounded p-2 mb-2" style="max-height:200px;overflow-y:auto">
        <?php foreach ($missions as $m): ?>
            <label class="d-flex gap-2 small py-1"><input class="form-check-input m-0" type="checkbox" name="missions[]" value="<?= (int)$m['id'] ?>" data-client="<?= (int)$m['client_id'] ?>"
                <?= in_array((int)$m['id'], $editIds, true) ? 'checked' : '' ?>>
                <span><?= h($m['nom']) ?><?= $m['client_nom'] ? ' <span class="text-muted">· ' . h($m['client_nom']) . '</span>' : '' ?></span></label>
        <?php endforeach; ?>
    </div>
    <div class="row g-2 mb-2">
        <div class="col-7"><label class="form-label small fw-semibold">Email du client</label><input class="form-control" type="email" name="email" value="<?= h((string)($edit['email'] ?? '')) ?>"></div>
        <div class="col-5"><label class="form-label small fw-semibold">Expire le</label><input class="form-control" type="date" name="expire_le" value="<?= h((string)($edit['expire_le'] ?? '')) ?>"></div>
    </div>
    <?php foreach (['voir_heures' => 'Voir les heures par agent', 'voir_evenements' => 'Voir la main courante et les incidents', 'voir_photos' => 'Voir les photos des incidents',
                    'envoi_hebdo' => 'Envoyer le rapport chaque lundi par email'] as $k => $l): ?>
        <label class="form-check small"><input class="form-check-input" type="checkbox" name="<?= $k ?>" value="1"
            <?= ($edit ? (int)$edit[$k] : in_array($k, ['voir_heures', 'voir_evenements'], true)) ? 'checked' : '' ?>><span class="form-check-label"><?= $l ?></span></label>
    <?php endforeach; ?>
    <div class="small text-muted mt-2">Les selfies des agents ne sont jamais montrés au client. Les noms apparaissent sous la forme « Prénom N. ».</div>
    <div class="d-flex gap-2 mt-3">
        <button class="btn btn-pta flex-grow-1"><?= $edit ? 'Enregistrer' : 'Créer le portail' ?></button>
        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="portails.php">Annuler</a><?php endif; ?>
    </div>
</form>
<?php endif; ?>
</div>

<div class="col-lg-7">
<?php if (!$portails): ?><div class="pta-card text-muted">Aucun portail pour l'instant.</div><?php endif; ?>
<?php foreach ($portails as $p):
    $lien = ptUrl('client/index.php?t=' . $p['token']);
    $expire = $p['expire_le'] && $p['expire_le'] < date('Y-m-d');
    $msg = 'Bonjour, voici votre accès au suivi de la prestation ' . ptMarque() . ' : ' . $lien;
?>
<div class="pta-card <?= $p['revoque'] || $expire ? 'opacity-50' : '' ?>" id="p<?= (int)$p['id'] ?>">
    <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
            <div class="fw-bold"><?= h($p['libelle']) ?> <?php if ($p['revoque']): ?><span class="pta-badge off">Désactivé</span><?php elseif ($expire): ?><span class="pta-badge ko">Expiré</span><?php endif; ?></div>
            <div class="small text-muted"><?= h(implode(', ', array_filter(array_map(function ($i) use ($nomsMissions) { return $nomsMissions[(int)$i] ?? null; }, explode(',', $p['missions']))))) ?></div>
            <div class="small text-muted">Dernière visite : <?= $p['derniere_visite'] ? date('d/m/Y H:i', strtotime($p['derniere_visite'])) : 'jamais' ?><?= $p['expire_le'] ? ' · expire le ' . date('d/m/Y', strtotime($p['expire_le'])) : '' ?></div>
        </div>
        <div class="text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$p['id'] ?>" title="Modifier"><i class="fa fa-pen"></i></a>
            <?php if (ptAdminCan('edit')): ?>
            <form method="post" class="d-inline"><?= ptCsrfField() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" name="action" value="nouveau_lien" title="Nouveau lien (l'ancien ne marchera plus)" onclick="return confirm('Générer un nouveau lien ? L\'ancien ne fonctionnera plus.')"><i class="fa fa-rotate"></i></button>
                <button class="btn btn-sm btn-outline-<?= $p['revoque'] ? 'success' : 'danger' ?>" name="action" value="<?= $p['revoque'] ? 'reactiver' : 'revoquer' ?>" title="<?= $p['revoque'] ? 'Réactiver' : 'Désactiver' ?>"><i class="fa fa-<?= $p['revoque'] ? 'check' : 'ban' ?>"></i></button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!$p['revoque']): ?>
    <div class="pta-link-box mt-2"><?= h($lien) ?></div>
    <div class="d-flex flex-wrap gap-2 mt-2">
        <button class="btn btn-sm btn-outline-secondary" type="button" data-copy="<?= h($lien) ?>"><i class="fa fa-copy me-1"></i>Copier</button>
        <a class="btn btn-sm btn-outline-secondary" target="_blank" href="<?= h($lien) ?>"><i class="fa fa-up-right-from-square me-1"></i>Ouvrir</a>
        <button class="btn btn-sm btn-pta" type="button" data-bs-toggle="collapse" data-bs-target="#pdf<?= (int)$p['id'] ?>"><i class="fa fa-file-pdf me-1"></i>Rapport PDF…</button>
    </div>
    <form class="collapse border rounded p-2 mt-2 small" id="pdf<?= (int)$p['id'] ?>" method="get" action="<?= h(ptUrl('client/rapport-pdf.php')) ?>">
        <input type="hidden" name="t" value="<?= h($p['token']) ?>">
        <div class="d-flex flex-wrap gap-2 align-items-end mb-2">
            <div><label class="form-label small mb-0">Du</label><input class="form-control form-control-sm" type="date" name="du" value="<?= date('Y-m-01', strtotime('first day of last month')) ?>"></div>
            <div><label class="form-label small mb-0">Au</label><input class="form-control form-control-sm" type="date" name="au" value="<?= date('Y-m-t', strtotime('last day of last month')) ?>"></div>
        </div>
        <div class="d-flex flex-wrap gap-3">
        <?php require_once __DIR__ . '/../includes/pdf.php';
        $dispo = ptSectionsDemandees($p, null);
        foreach (ptSectionsRapport() as $k => $l): if (!in_array($k, $dispo, true)) continue; ?>
            <label class="form-check"><input class="form-check-input" type="checkbox" name="s[]" value="<?= $k ?>" checked><span class="form-check-label"><?= h($l) ?></span></label>
        <?php endforeach; ?>
        </div>
        <button class="btn btn-sm btn-pta mt-2"><i class="fa fa-download me-1"></i>Générer</button>
    </form>
    <div>
        <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="https://wa.me/?text=<?= h(rawurlencode($msg)) ?>"><i class="fa-brands fa-whatsapp me-1"></i>WhatsApp</a>
        <?php if ($p['email']): ?><a class="btn btn-sm btn-outline-primary" href="mailto:<?= h($p['email']) ?>?subject=<?= h(rawurlencode('Suivi de prestation — ' . ptMarque())) ?>&body=<?= h(rawurlencode($msg)) ?>"><i class="fa fa-envelope me-1"></i>Email</a><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</div>
<?php
ptAdminFoot('<script>document.querySelectorAll("[data-copy]").forEach(function(b){b.addEventListener("click",function(){navigator.clipboard.writeText(b.dataset.copy).then(function(){var t=b.innerHTML;b.innerHTML="<i class=\"fa fa-check me-1\"></i>Copié";setTimeout(function(){b.innerHTML=t},1500)})})});</script>');
