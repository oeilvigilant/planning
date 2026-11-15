<?php
/** Écran principal de l'agent. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$agent = ptRequireAgent();
$vac   = ptVacationCourante((int)$agent['id']);

$contact = ptContact($vac);
$waTexte = 'Bonjour, ici ' . $agent['prenom'] . ' ' . $agent['nom'] . ($vac ? ' (' . $vac['mission_nom'] . ')' : '') . '. ';

$initiales = mb_strtoupper(mb_substr($agent['prenom'], 0, 1) . mb_substr($agent['nom'], 0, 1));
$photoUrl  = ($agent['photo'] && ptPhotosUrl() !== '') ? ptPhotosUrl() . '/' . rawurlencode($agent['photo']) : '';

function ptHeureCourte(string $dt): string {
    return date('H\hi', strtotime($dt));
}

ptHead('Accueil');
?>
<div class="pt-top">
    <div style="display:flex;align-items:center;gap:12px;min-width:0">
        <?php if ($photoUrl): ?>
            <img class="pt-avatar" src="<?= h($photoUrl) ?>" alt="">
        <?php else: ?>
            <div class="pt-avatar"><?= h($initiales) ?></div>
        <?php endif; ?>
        <div style="min-width:0">
            <div class="pt-hello">Bonjour <?= h($agent['prenom']) ?></div>
            <div class="pt-muted pt-small"><?= h($agent['matricule'] ?: '') ?></div>
        </div>
    </div>
    <a class="pt-icon-btn" href="#menu" data-bs-toggle="offcanvas" data-bs-target="#ptMenu" aria-label="Menu"><i class="fa fa-bars"></i></a>
</div>

<div class="pt-card" style="text-align:center">
    <div class="pt-clock" data-pt-clock><?= date('H:i:s') ?></div>
    <div class="pt-date" data-pt-date></div>

    <button class="pt-main-btn soon" type="button" disabled style="margin-top:18px">
        <i class="fa fa-fingerprint"></i>
        <span>Pointer</span>
    </button>
    <div class="pt-muted pt-small" style="margin-top:10px">Le pointage arrive dans la prochaine mise à jour.</div>
</div>

<?php if ($vac):
    $enCours = time() >= strtotime($vac['debut_dt']) && time() <= strtotime($vac['fin_dt']);
    $adresse = $vac['pt_adresse'] ?: $vac['lieu'];
?>
<div class="pt-card">
    <div class="pt-card-label"><?= $enCours ? 'Vacation en cours' : 'Prochaine vacation' ?></div>
    <h2 style="margin-bottom:6px"><?= h($vac['mission_nom']) ?></h2>
    <div class="pt-info-row"><i class="fa fa-clock"></i>
        <div><?= h(formatDate($vac['date_travail'])) ?> · <strong><?= ptHeureCourte($vac['debut_dt']) ?> → <?= ptHeureCourte($vac['fin_dt']) ?></strong>
            <?php if ($enCours): ?> <span class="pt-badge pt-badge-green">En cours</span><?php endif; ?></div>
    </div>
    <?php if ($vac['client_nom']): ?>
    <div class="pt-info-row"><i class="fa fa-building"></i><div><?= h($vac['client_nom']) ?></div></div>
    <?php endif; ?>
    <?php if ($adresse): ?>
    <div class="pt-info-row"><i class="fa fa-location-dot"></i>
        <div><?= h($adresse) ?><br>
            <a class="pt-small" target="_blank" rel="noopener"
               href="https://www.google.com/maps/dir/?api=1&destination=<?= h(rawurlencode($vac['pt_latitude'] ? $vac['pt_latitude'] . ',' . $vac['pt_longitude'] : $adresse)) ?>">
               <i class="fa fa-route"></i> Itinéraire</a></div>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="pt-card">
    <div class="pt-card-label">Planning</div>
    <div class="pt-muted">Aucune vacation prévue dans les prochaines 24 h.</div>
</div>
<?php endif; ?>

<div class="pt-actions">
    <?php if ($contact['tel']): ?>
        <a class="pt-action" href="tel:<?= h(preg_replace('/[^\d+]/', '', $contact['tel'])) ?>"><i class="fa fa-phone"></i>Appeler<br><span class="pt-muted pt-small"><?= h($contact['nom']) ?></span></a>
    <?php else: ?>
        <span class="pt-action disabled"><i class="fa fa-phone"></i>Appeler<br><span class="pt-small">Non renseigné</span></span>
    <?php endif; ?>
    <?php if ($contact['whatsapp']): ?>
        <a class="pt-action" target="_blank" rel="noopener" href="https://wa.me/<?= h(ptTelWhatsapp($contact['whatsapp'])) ?>?text=<?= h(rawurlencode($waTexte)) ?>"><i class="fa-brands fa-whatsapp" style="color:#25d366"></i>WhatsApp<br><span class="pt-muted pt-small"><?= h($contact['nom']) ?></span></a>
    <?php else: ?>
        <span class="pt-action disabled"><i class="fa-brands fa-whatsapp"></i>WhatsApp<br><span class="pt-small">Non renseigné</span></span>
    <?php endif; ?>
    <span class="pt-action disabled"><i class="fa fa-triangle-exclamation"></i>Signaler<br><span class="pt-small">Bientôt</span></span>
    <span class="pt-action sos disabled"><i class="fa fa-bell"></i>SOS<br><span class="pt-small">Bientôt</span></span>
</div>

<?php if ($vac && trim((string)$vac['pt_consignes']) !== ''): ?>
<div class="pt-card">
    <h2><i class="fa fa-clipboard-list me-1"></i> Consignes du poste</h2>
    <div style="white-space:pre-line;font-size:.95rem"><?= h($vac['pt_consignes']) ?></div>
</div>
<?php endif; ?>

<div class="pt-card pt-install" data-pt-install>
    <h2><i class="fa fa-mobile-screen me-1"></i> Installer l'application</h2>
    <p class="pt-muted pt-small mb-2">Ajoutez OV Pointage sur votre écran d'accueil pour l'ouvrir en un geste et recevoir les rappels.</p>
    <div data-ios class="pt-small">Sur iPhone : touchez <i class="fa fa-arrow-up-from-bracket"></i> <strong>Partager</strong>, puis <strong>« Sur l'écran d'accueil »</strong>.</div>
    <button type="button" class="pt-btn pt-btn-gold" data-install-btn><i class="fa fa-download"></i> Installer</button>
</div>

<!-- Menu -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="ptMenu" style="background:var(--pt-card);color:var(--pt-text);max-width:85vw">
    <div class="offcanvas-header">
        <h5 class="offcanvas-title"><?= h($agent['prenom'] . ' ' . $agent['nom']) ?></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Fermer"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column gap-2">
        <a class="pt-btn pt-btn-ghost" href="<?= h(ptUrl('rgpd.php')) ?>"><i class="fa fa-shield-halved"></i> Vos données</a>
        <form method="post" action="<?= h(ptUrl('logout.php')) ?>" class="mt-auto">
            <?= ptCsrfField() ?>
            <button class="pt-btn pt-btn-ghost" type="submit"><i class="fa fa-right-from-bracket"></i> Se déconnecter</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php ptFoot();
