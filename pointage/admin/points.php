<?php
/** Points de ronde d'un site : ajout, modification, ordre, désactivation, impression des QR. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');
$db = getDB();
$mid = (int)($_GET['mission'] ?? $_POST['mission'] ?? 0);
$site = ptSite($mid);
if (!$site) { ptFlash('danger', 'Site introuvable.'); ptRedirect('admin/missions.php'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ptRequireAdmin('edit');
    if (!ptCsrfCheck()) { ptFlash('danger', 'Session expirée.'); ptRedirect('admin/points.php?mission=' . $mid); }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    $nom = trim(mb_substr((string)($_POST['nom'] ?? ''), 0, 150));
    $consigne = trim(mb_substr((string)($_POST['consigne'] ?? ''), 0, 255));
    if ($action === 'ajouter') {
        if ($nom === '') { ptFlash('danger', 'Donnez un nom au point (ex. « Portail nord »).'); ptRedirect('admin/points.php?mission=' . $mid); }
        $ordre = (int)$db->query("SELECT COALESCE(MAX(ordre),0)+1 FROM pt_points WHERE mission_id = " . $mid)->fetchColumn();
        $db->prepare("INSERT INTO pt_points (mission_id, nom, consigne, code, ordre) VALUES (?,?,?,?,?)")
           ->execute([$mid, $nom, $consigne ?: null, ptGenererCodePoint(), $ordre]);
        ptFlash('success', 'Point « ' . $nom . ' » ajouté. Pensez à imprimer son étiquette.');
    } elseif ($action === 'modifier' && $nom !== '') {
        $db->prepare("UPDATE pt_points SET nom = ?, consigne = ?, ordre = ? WHERE id = ? AND mission_id = ?")
           ->execute([$nom, $consigne ?: null, (int)($_POST['ordre'] ?? 0), $id, $mid]);
        ptFlash('success', 'Point enregistré.');
    } elseif ($action === 'basculer') {
        $db->prepare("UPDATE pt_points SET actif = 1 - actif WHERE id = ? AND mission_id = ?")->execute([$id, $mid]);
    } elseif ($action === 'nouveau_code') {
        $db->prepare("UPDATE pt_points SET code = ? WHERE id = ? AND mission_id = ?")->execute([ptGenererCodePoint(), $id, $mid]);
        ptFlash('success', 'Nouveau code généré : réimprimez et remplacez l\'étiquette (l\'ancienne ne fonctionne plus).');
    }
    ptRedirect('admin/points.php?mission=' . $mid);
}

$points = ptPointsSite($mid, false);
ptAdminHead('Points de ronde — ' . $site['nom'], 'sites');
?>
<div class="pta-title">
    <div>
        <a href="mission.php?id=<?= $mid ?>" class="text-muted small text-decoration-none"><i class="fa fa-arrow-left me-1"></i><?= h($site['nom']) ?></a>
        <h1>Points de ronde</h1>
        <div class="text-muted small"><?= (int)$site['pt_ronde_freq_min'] ? 'Une ronde complète toutes les ' . (int)$site['pt_ronde_freq_min'] . ' min' : 'Pas de fréquence imposée (réglable sur la fiche du site)' ?></div>
    </div>
    <?php if ($points): ?><a class="btn btn-sm btn-pta" target="_blank" href="qr.php?mission=<?= $mid ?>"><i class="fa fa-print me-1"></i>Imprimer les étiquettes QR</a><?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <?php if (ptAdminCan('edit')): ?>
        <form method="post" class="pta-card">
            <?= ptCsrfField() ?><input type="hidden" name="mission" value="<?= $mid ?>"><input type="hidden" name="action" value="ajouter">
            <h2>Ajouter un point</h2>
            <input class="form-control mb-2" name="nom" required placeholder="Ex. Portail nord, Parking -2, Local technique">
            <input class="form-control mb-2" name="consigne" placeholder="Consigne (facultatif) : ex. vérifier la fermeture">
            <button class="btn btn-pta w-100"><i class="fa fa-plus me-1"></i>Ajouter</button>
        </form>
        <?php endif; ?>
        <div class="pta-card small text-muted">
            <strong class="text-dark">Comment ça marche</strong><br>
            1. Ajoutez les points à contrôler.<br>
            2. Imprimez les étiquettes (de préférence plastifiées ou autocollantes) et collez-les sur place.<br>
            3. Pendant sa ronde, l'agent ouvre « Faire ma ronde » et scanne chaque étiquette. Il peut aussi utiliser l'appareil photo de son téléphone.<br>
            4. Vous voyez chaque passage (heure, agent, position) dans <a href="rondes.php?site=<?= $mid ?>">Rondes</a>. Le client le voit dans son rapport.
        </div>
    </div>
    <div class="col-lg-8">
        <div class="pta-card p-0">
            <table class="pta-table">
                <thead><tr><th class="ps-3" style="width:70px">Ordre</th><th>Point</th><th>Code</th><th class="text-end pe-3"></th></tr></thead>
                <tbody>
                <?php foreach ($points as $pt): ?>
                    <tr class="<?= $pt['actif'] ? '' : 'opacity-50' ?>">
                        <td class="ps-3" colspan="2">
                            <form method="post" class="d-flex gap-2 align-items-center">
                                <?= ptCsrfField() ?><input type="hidden" name="mission" value="<?= $mid ?>"><input type="hidden" name="id" value="<?= (int)$pt['id'] ?>"><input type="hidden" name="action" value="modifier">
                                <input class="form-control form-control-sm" style="width:60px" type="number" name="ordre" value="<?= (int)$pt['ordre'] ?>">
                                <input class="form-control form-control-sm" name="nom" value="<?= h($pt['nom']) ?>" required>
                                <input class="form-control form-control-sm" name="consigne" value="<?= h((string)$pt['consigne']) ?>" placeholder="Consigne">
                                <?php if (ptAdminCan('edit')): ?><button class="btn btn-sm btn-outline-secondary" title="Enregistrer"><i class="fa fa-check"></i></button><?php endif; ?>
                            </form>
                        </td>
                        <td class="small font-monospace"><?= h($pt['code']) ?><?= $pt['actif'] ? '' : ' <span class="pta-badge off">désactivé</span>' ?></td>
                        <td class="text-end pe-3 text-nowrap">
                            <?php if (ptAdminCan('edit')): ?>
                            <form method="post" class="d-inline"><?= ptCsrfField() ?><input type="hidden" name="mission" value="<?= $mid ?>"><input type="hidden" name="id" value="<?= (int)$pt['id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" name="action" value="nouveau_code" title="Nouveau code (étiquette perdue ou copiée)" onclick="return confirm('Générer un nouveau code ? L\'étiquette actuelle ne fonctionnera plus.')"><i class="fa fa-rotate"></i></button>
                                <button class="btn btn-sm btn-outline-<?= $pt['actif'] ? 'danger' : 'success' ?>" name="action" value="basculer" title="<?= $pt['actif'] ? 'Désactiver' : 'Réactiver' ?>"><i class="fa fa-<?= $pt['actif'] ? 'ban' : 'check' ?>"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$points): ?><tr><td colspan="4" class="text-center text-muted py-4">Aucun point pour l'instant.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php ptAdminFoot();
