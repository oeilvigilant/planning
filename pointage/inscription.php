<?php
/**
 * Inscription d'un agent depuis un lien créé par le responsable :
 * identité + information RGPD + choix du code PIN, sur un seul écran.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$token = (string)($_GET['i'] ?? $_POST['i'] ?? '');
$lien = ptInscriptionParToken($token);
$refus = ptInscriptionRefus($lien);

$errors = [];
$d = ['prenom' => '', 'nom' => '', 'sexe' => 'M', 'telephone' => '', 'email' => ''];
if (!$refus && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = array_merge($d, array_intersect_key(array_map(function ($v) { return is_string($v) ? $v : ''; }, $_POST), $d));
    if (!ptCsrfCheck()) $errors[] = 'La page a expiré, réessayez.';
    elseif (trim((string)($_POST['site_web'] ?? '')) !== '') $errors[] = 'Inscription refusée.';   // champ piège anti-robots
    else {
        $res = ptInscrireAgent($lien, $_POST);
        if ($res['ok']) {
            ptAgentLoginSession($res['agent_id']);
            ptRememberDevice($res['agent_id']);
            if ($res['attente']) ptRedirect('attente.php?ok=1');
            ptFlash('success', 'Compte créé ! Votre identifiant : ' . $res['matricule'] . ' (ou votre n° de téléphone).');
            ptRedirect('app.php?bienvenue=1');
        }
        $errors = $res['errors'];
    }
}

ptHead('Inscription');
?>
<div class="pt-access">
    <?php ptBrand(); ?>
    <?php if ($refus): ?>
        <h1>Lien indisponible</h1>
        <p class="pt-muted"><?= h($refus) ?></p>
        <p class="pt-muted pt-small">Demandez un nouveau lien à votre responsable.</p>
        <a class="pt-btn pt-btn-gold" href="<?= h(ptUrl('login.php')) ?>">J'ai déjà un compte</a>
    <?php else: ?>
        <h1>Inscription</h1>
        <p class="pt-muted"><?= h($lien['libelle']) ?><?= $lien['site_nom'] ? ' · site <strong>' . h($lien['site_nom']) . '</strong>' : '' ?></p>

        <?php foreach ($errors as $e): ?><div class="pt-alert pt-alert-danger"><?= h($e) ?></div><?php endforeach; ?>

        <form method="post" autocomplete="off">
            <?= ptCsrfField() ?>
            <input type="hidden" name="i" value="<?= h($token) ?>">
            <div style="position:absolute;left:-5000px" aria-hidden="true"><input name="site_web" tabindex="-1" autocomplete="off"></div>

            <div class="pt-card">
                <div class="pt-card-label">Étape 1</div>
                <h2><i class="fa fa-user me-1"></i> Qui êtes-vous ?</h2>
                <div class="pt-seg" role="radiogroup" style="display:flex;gap:8px;margin-bottom:6px">
                    <label class="pt-check" style="margin:0"><input type="radio" name="sexe" value="M" <?= $d['sexe'] !== 'F' ? 'checked' : '' ?>> <span>M.</span></label>
                    <label class="pt-check" style="margin:0"><input type="radio" name="sexe" value="F" <?= $d['sexe'] === 'F' ? 'checked' : '' ?>> <span>Mme</span></label>
                </div>
                <label class="pt-label" for="prenom">Prénom</label>
                <input class="pt-input" id="prenom" name="prenom" value="<?= h($d['prenom']) ?>" required maxlength="100" autocomplete="given-name">
                <label class="pt-label" for="nom">Nom</label>
                <input class="pt-input" id="nom" name="nom" value="<?= h($d['nom']) ?>" required maxlength="100" autocomplete="family-name" autocapitalize="characters">
                <label class="pt-label" for="telephone">Téléphone portable</label>
                <input class="pt-input" id="telephone" name="telephone" type="tel" inputmode="tel" value="<?= h($d['telephone']) ?>" required maxlength="20" placeholder="06 12 34 56 78" autocomplete="tel">
                <div class="pt-muted pt-small" style="margin-top:4px">Il vous servira d'identifiant pour vous connecter.</div>
                <label class="pt-label" for="email">E-mail <span class="pt-muted">(facultatif)</span></label>
                <input class="pt-input" id="email" name="email" type="email" value="<?= h($d['email']) ?>" maxlength="150" autocomplete="email">
            </div>

            <div class="pt-card">
                <div class="pt-card-label">Étape 2</div>
                <h2><i class="fa fa-shield-halved me-1"></i> Vos données personnelles</h2>
                <div class="pt-rgpd"><?= ptTexteRiche(getParam('pointage_rgpd_texte', ptRgpdTexteDefaut())) ?></div>
                <label class="pt-check">
                    <input type="checkbox" name="rgpd" value="1" required <?= !empty($_POST['rgpd']) ? 'checked' : '' ?>>
                    <span>J'ai lu et compris comment mes données sont utilisées.</span>
                </label>
            </div>

            <div class="pt-card">
                <div class="pt-card-label">Étape 3</div>
                <h2><i class="fa fa-key me-1"></i> Choisissez votre code PIN</h2>
                <p class="pt-muted pt-small">4 chiffres, à garder pour vous.</p>
                <label class="pt-label" for="pin">Code PIN</label>
                <input class="pt-input pt-pin" id="pin" name="pin" type="tel" inputmode="numeric" maxlength="4" data-pin required autocomplete="new-password">
                <label class="pt-label" for="pin2">Confirmez le code</label>
                <input class="pt-input pt-pin" id="pin2" name="pin2" type="tel" inputmode="numeric" maxlength="4" data-pin required autocomplete="new-password">
            </div>

            <button class="pt-btn pt-btn-gold" type="submit"><i class="fa fa-check"></i> Créer mon compte</button>
        </form>
        <p class="pt-muted pt-small" style="text-align:center;margin-top:14px"><a href="<?= h(ptUrl('login.php')) ?>">J'ai déjà un compte</a></p>
    <?php endif; ?>
</div>
<?php ptFoot();
