<?php
/** Écran de l'agent inscrit par lien, tant que le responsable n'a pas validé son compte. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$agent = ptCurrentAgent();
if (!$agent) ptRedirect('login.php');
if ($agent['statut_inscription'] !== 'en_attente') ptRedirect('app.php');

ptHead('Inscription en attente');
?>
<div class="pt-access" style="text-align:center">
    <?php ptBrand(); ?>
    <div class="pt-card">
        <i class="fa fa-hourglass-half" style="font-size:2.6rem;color:var(--pt-gold)"></i>
        <h1 style="margin-top:12px"><?= !empty($_GET['ok']) ? 'Inscription enregistrée' : 'En attente de validation' ?></h1>
        <p class="pt-muted">Merci <?= h($agent['prenom']) ?>. Votre responsable doit valider votre compte avant que vous puissiez pointer.</p>
        <p class="pt-small">Votre identifiant : <strong><?= h($agent['matricule']) ?></strong> ou votre n° de téléphone.</p>
    </div>
    <a class="pt-btn pt-btn-gold" href="<?= h(ptUrl('attente.php')) ?>"><i class="fa fa-rotate"></i> Vérifier maintenant</a>
    <a class="pt-btn pt-btn-ghost" href="<?= h(ptUrl('logout.php')) ?>" style="margin-top:10px">Se déconnecter</a>
</div>
<?php ptFoot();
