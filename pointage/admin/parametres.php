<?php
/** Paramètres de l'outil : tolérance, périmètre par défaut, texte RGPD. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ptRequireAdmin('edit');
    if (!ptCsrfCheck()) $errors[] = 'Session expirée, réessayez.';
    $tol = (int)($_POST['tolerance'] ?? 5);
    $rayon = (int)($_POST['rayon'] ?? 200);
    $texte = trim(str_replace("\r\n", "\n", (string)($_POST['rgpd_texte'] ?? '')));
    if ($tol < 0 || $tol > 60) $errors[] = 'La tolérance doit être comprise entre 0 et 60 minutes.';
    if ($rayon < 30 || $rayon > 2000) $errors[] = 'Le périmètre doit être compris entre 30 et 2000 m.';
    if (mb_strlen($texte) < 50) $errors[] = 'Le texte d\'information RGPD est trop court.';

    if (!$errors) {
        setParam('pointage_tolerance_min', (string)$tol);
        setParam('pointage_rayon_defaut_m', (string)$rayon);
        $ancien = str_replace("\r\n", "\n", getParam('pointage_rgpd_texte', ''));
        if ($texte !== trim($ancien)) {
            setParam('pointage_rgpd_texte', $texte);
            if (!empty($_POST['nouvelle_version'])) {
                setParam('pointage_rgpd_version', (string)(ptRgpdVersion() + 1));
                ptFlash('success', 'Paramètres enregistrés. Les agents devront relire et valider le nouveau texte RGPD.');
                ptRedirect('admin/parametres.php');
            }
        }
        ptFlash('success', 'Paramètres enregistrés.');
        ptRedirect('admin/parametres.php');
    }
}

$db = getDB();
$v = ptRgpdVersion();
$nbAJour = (int)$db->query("SELECT COUNT(*) FROM pt_acces WHERE pin_hash IS NOT NULL AND rgpd_version >= " . $v)->fetchColumn();
$nbActifs = (int)$db->query("SELECT COUNT(*) FROM pt_acces WHERE pin_hash IS NOT NULL")->fetchColumn();

ptAdminHead('Paramètres', 'parametres');
?>
<div class="pta-title"><h1>Paramètres</h1></div>
<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<form method="post" class="row g-3">
    <?= ptCsrfField() ?>
    <div class="col-lg-4">
        <div class="pta-card">
            <h2><i class="fa fa-clock me-1 text-warning"></i> Tolérance de pointage</h2>
            <div class="input-group mb-2">
                <span class="input-group-text">±</span>
                <input class="form-control" type="number" name="tolerance" min="0" max="60" value="<?= (int)getParam('pointage_tolerance_min', '5') ?>">
                <span class="input-group-text">min</span>
            </div>
            <div class="small text-muted">Un pointage dans cette marge autour de l'heure prévue au planning est automatiquement ramené à l'heure prévue. Au-delà, il est marqué « à vérifier ».</div>
        </div>
        <div class="pta-card">
            <h2><i class="fa fa-bullseye me-1 text-warning"></i> Périmètre par défaut</h2>
            <div class="input-group mb-2">
                <input class="form-control" type="number" name="rayon" min="30" max="2000" value="<?= (int)getParam('pointage_rayon_defaut_m', '200') ?>">
                <span class="input-group-text">mètres</span>
            </div>
            <div class="small text-muted">Proposé pour les nouveaux sites. Réglable site par site.</div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="pta-card">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                <h2 class="mb-0"><i class="fa fa-shield-halved me-1 text-warning"></i> Information RGPD affichée aux agents</h2>
                <span class="pta-badge <?= $nbAJour === $nbActifs ? 'ok' : 'wait' ?>">Version <?= $v ?> · <?= $nbAJour ?>/<?= $nbActifs ?> agents à jour</span>
            </div>
            <textarea class="form-control font-monospace" name="rgpd_texte" rows="18" style="font-size:.85rem"><?= h(getParam('pointage_rgpd_texte', ptRgpdTexteDefaut())) ?></textarea>
            <div class="small text-muted mt-2">Mise en forme : <code>## Titre</code>, <code>- élément de liste</code>, <code>**gras**</code>. Faites valider ce texte par votre conseil (RGPD / CSE).</div>
            <label class="form-check mt-3">
                <input class="form-check-input" type="checkbox" name="nouvelle_version" value="1">
                <span class="form-check-label">Changement important : publier une nouvelle version, que chaque agent devra relire et valider à sa prochaine ouverture.</span>
            </label>
        </div>
    </div>
    <?php if (ptAdminCan('edit')): ?>
    <div class="col-12 text-end"><button class="btn btn-pta px-4 py-2"><i class="fa fa-check me-1"></i>Enregistrer</button></div>
    <?php endif; ?>
</form>
<?php ptAdminFoot();
