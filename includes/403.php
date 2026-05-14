<?php $pageTitle = 'Accès refusé'; include __DIR__ . '/header.php'; ?>
<div class="text-center py-5">
    <i class="fa fa-ban fa-4x mb-3" style="color:var(--ov-gold)"></i>
    <h3>Accès refusé</h3>
    <p class="text-muted">Vous n'avez pas les droits pour accéder à cette section.</p>
    <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="btn-ov-primary btn">Retour au tableau de bord</a>
</div>
<?php include __DIR__ . '/footer.php'; ?>
