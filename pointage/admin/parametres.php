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
    $cNom = trim((string)($_POST['contact_nom'] ?? ''));
    $cTel = trim((string)($_POST['contact_tel'] ?? ''));
    $cWa  = trim((string)($_POST['contact_whatsapp'] ?? ''));
    foreach (['téléphone' => $cTel, 'WhatsApp' => $cWa] as $lib => $num) {
        if ($num !== '' && !preg_match('/^[\d\s+().-]{6,25}$/', $num)) $errors[] = "Numéro $lib du contact par défaut invalide.";
    }

    $selfie = (string)($_POST['selfie'] ?? 'optionnel');
    if (!in_array($selfie, ['non', 'optionnel', 'obligatoire'], true)) $selfie = 'optionnel';
    $marque = trim((string)($_POST['marque'] ?? ''));

    $emails = implode(', ', array_filter(array_map('trim', explode(',', (string)($_POST['alerte_emails'] ?? '')))));
    foreach (array_filter(array_map('trim', explode(',', $emails))) as $em) {
        if (!filter_var($em, FILTER_VALIDATE_EMAIL)) $errors[] = "Email d'alerte invalide : $em";
    }
    $rappel = max(0, min(120, (int)($_POST['rappel_avant'] ?? 15)));
    $nonPris = max(5, min(180, (int)($_POST['non_pris'] ?? 15)));

    $groupe = trim((string)($_POST['whatsapp_groupe'] ?? ''));
    if ($groupe !== '' && ptLienGroupeWhatsapp($groupe) === '') $errors[] = 'Lien de groupe WhatsApp invalide (il doit commencer par https://chat.whatsapp.com/).';

    if (!$errors) {
        setParam('pointage_whatsapp_groupe', ptLienGroupeWhatsapp($groupe));
        setParam('pointage_alerte_emails', $emails);
        setParam('pointage_rappel_avant_min', (string)$rappel);
        setParam('pointage_alerte_non_pris_min', (string)$nonPris);
        setParam('pointage_selfie', $selfie);
        setParam('pointage_marque', mb_substr($marque, 0, 80));
        setParam('pointage_contact_nom', $cNom !== '' ? $cNom : 'Permanence');
        setParam('pointage_contact_tel', $cTel);
        setParam('pointage_contact_whatsapp', $cWa);
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
            <h2><i class="fa fa-building me-1 text-warning"></i> Votre société</h2>
            <label class="form-label small fw-semibold" for="marque">Nom affiché aux agents</label>
            <input class="form-control" id="marque" name="marque" value="<?= h(getParam('pointage_marque', '')) ?>" placeholder="<?= h(ptMarque()) ?>">
            <div class="small text-muted mt-1">Sur l'application, les messages d'invitation et les rapports.</div>
        </div>
        <div class="pta-card">
            <h2><i class="fa fa-camera me-1 text-warning"></i> Photo au pointage</h2>
            <?php $sm = getParam('pointage_selfie', 'optionnel'); ?>
            <?php foreach (['non' => 'Pas de photo', 'optionnel' => 'Au choix de l\'agent (recommandé)', 'obligatoire' => 'Obligatoire à la prise de service'] as $k => $l): ?>
                <label class="form-check"><input class="form-check-input" type="radio" name="selfie" value="<?= $k ?>" <?= $sm === $k ? 'checked' : '' ?>>
                    <span class="form-check-label"><?= $l ?></span></label>
            <?php endforeach; ?>
            <div class="small text-muted mt-1">La caméra arrière s'ouvre : l'agent photographie son poste, sa tenue, ou se prend en photo s'il le souhaite (il peut retourner la caméra). Photos visibles par les responsables uniquement.</div>
        </div>
        <div class="pta-card">
            <h2><i class="fa fa-headset me-1 text-warning"></i> Contact par défaut</h2>
            <div class="small text-muted mb-2">Joint par l'agent quand le site n'a pas de responsable renseigné (permanence, astreinte…).</div>
            <label class="form-label small fw-semibold" for="cn">Nom affiché</label>
            <input class="form-control mb-2" id="cn" name="contact_nom" value="<?= h(getParam('pointage_contact_nom', 'Permanence')) ?>">
            <label class="form-label small fw-semibold" for="ct"><i class="fa fa-phone"></i> Téléphone</label>
            <input class="form-control mb-2" id="ct" name="contact_tel" value="<?= h(getParam('pointage_contact_tel', '')) ?>" placeholder="06 12 34 56 78">
            <label class="form-label small fw-semibold" for="cw"><i class="fa-brands fa-whatsapp text-success"></i> WhatsApp <span class="text-muted fw-normal">(si différent)</span></label>
            <input class="form-control mb-2" id="cw" name="contact_whatsapp" value="<?= h(getParam('pointage_contact_whatsapp', '')) ?>" placeholder="Même numéro si vide">
            <label class="form-label small fw-semibold" for="wg"><i class="fa-brands fa-whatsapp text-success"></i> Groupe WhatsApp des agents</label>
            <input class="form-control" id="wg" name="whatsapp_groupe" value="<?= h(getParam('pointage_whatsapp_groupe', '')) ?>" placeholder="https://chat.whatsapp.com/…">
            <div class="small text-muted mt-1">Bouton « Groupe WhatsApp » chez l'agent (un groupe propre à un site peut être indiqué sur la fiche du site). WhatsApp → groupe → nom du groupe → « Inviter via un lien ».</div>
        </div>
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
    <div class="col-lg-6">
        <div class="pta-card">
            <h2><i class="fa fa-bell me-1 text-warning"></i> Alertes et rappels</h2>
            <label class="form-label small fw-semibold">Emails prévenus en cas de SOS, de poste non pris ou d'incident critique</label>
            <input class="form-control mb-2" name="alerte_emails" value="<?= h(getParam('pointage_alerte_emails', '')) ?>" placeholder="direction@…, astreinte@…">
            <div class="row g-2">
                <div class="col-6"><label class="form-label small fw-semibold">Rappel à l'agent avant sa vacation</label>
                    <div class="input-group"><input class="form-control" type="number" min="0" max="120" name="rappel_avant" value="<?= (int)getParam('pointage_rappel_avant_min', '15') ?>"><span class="input-group-text">min</span></div></div>
                <div class="col-6"><label class="form-label small fw-semibold">Alerte « poste non pris » après</label>
                    <div class="input-group"><input class="form-control" type="number" min="5" max="180" name="non_pris" value="<?= (int)getParam('pointage_alerte_non_pris_min', '15') ?>"><span class="input-group-text">min</span></div></div>
            </div>
            <div class="small text-muted mt-2">Les alertes arrivent aussi en notification sur votre téléphone et votre ordinateur : bouton <i class="fa fa-bell"></i> « Activer les alertes » en haut de page. Les rappels concernent les vacations prévues au planning.</div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="pta-card">
            <h2><i class="fa fa-stopwatch me-1 text-warning"></i> Tâche planifiée (rappels à l'heure)</h2>
            <?php $ts = (int)getParam('pointage_taches_ts', '0'); ?>
            <div class="small mb-2">Dernière exécution : <strong><?= $ts ? date('d/m/Y H:i', $ts) : 'jamais' ?></strong>
                <?= $ts && time() - $ts > 900 ? ' <span class="text-danger">(il y a plus de 15 min : configurez la tâche ci-dessous)</span>' : '' ?></div>
            <div class="small text-muted mb-1">Adresse à appeler toutes les 5 minutes :</div>
            <div class="pta-link-box mb-2"><?= h(ptUrl('cron.php?cle=' . getParam('pointage_cron_cle', ''))) ?></div>
            <div class="small text-muted">LWS : Espace client → votre hébergement → <strong>Tâches planifiées (Cron)</strong> → ajouter, fréquence « toutes les 5 minutes », type URL. Ou gratuitement sur <strong>cron-job.org</strong>. Sans cela, les rappels partent seulement quand quelqu'un utilise l'outil.</div>
        </div>
    </div>
    <?php if (ptAdminCan('edit')): ?>
    <div class="col-12 text-end"><button class="btn btn-pta px-4 py-2"><i class="fa fa-check me-1"></i>Enregistrer</button></div>
    <?php endif; ?>
</form>
<?php ptAdminFoot();
