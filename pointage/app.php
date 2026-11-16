<?php
/** Écran principal de l'agent : pointage, site, contact, heures. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/pointer.php';

$agent   = ptRequireAgent();
$agentId = (int)$agent['id'];
$ouvert  = ptServiceOuvert($agentId);
$sites   = ptSitesAgent($agentId);
$vac     = ptVacationCourante($agentId);

// Site affiché : celui du service en cours, sinon de la vacation prévue, sinon le site unique
$site = null;
if ($ouvert) $site = ptSite((int)$ouvert['mission_id']);
elseif ($vac) $site = ptSite((int)$vac['mission_id']);
elseif (count($sites) === 1) $site = $sites[0];
$choixSite = !$ouvert && !$site && count($sites) > 1;

$contact = ptContact($site ? $site : ($vac ?: null));
$waTexte = 'Bonjour, ici ' . $agent['prenom'] . ' ' . $agent['nom'] . ($site ? ' (' . $site['nom'] . ')' : '') . '. ';

// Heures du mois et derniers services
$db = getDB();
$st = $db->prepare("SELECT s.*, m.nom AS mission_nom FROM pt_services s JOIN missions m ON m.id = s.mission_id
    WHERE s.agent_id = ? AND s.statut <> 'annule' AND s.debut_retenu >= ? ORDER BY s.debut_retenu DESC");
$st->execute([$agentId, date('Y-m-01 00:00:00')]);
$servicesMois = $st->fetchAll();
$totalMois = 0;
foreach ($servicesMois as $s) $totalMois += ptDureeMin($s);

$initiales = mb_strtoupper(mb_substr($agent['prenom'], 0, 1) . mb_substr($agent['nom'], 0, 1));
$photoUrl  = ($agent['photo'] && ptPhotosUrl() !== '') ? ptPhotosUrl() . '/' . rawurlencode($agent['photo']) : '';
$selfie    = getParam('pointage_selfie', 'optionnel');

function ptH(string $dt): string { return date('H\hi', strtotime($dt)); }

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

<div class="pt-alert pt-alert-info" data-pt-queue style="display:none"></div>

<div class="pt-card pt-pointage" style="text-align:center"
     data-pt-pointage
     data-api="<?= h(ptUrl('api/pointer.php')) ?>"
     data-agent="<?= $agentId ?>"
     data-etat="<?= $ouvert ? 'en_service' : 'hors_service' ?>"
     data-debut="<?= $ouvert ? h(date('c', strtotime($ouvert['debut_reel']))) : '' ?>"
     data-selfie="<?= h($selfie) ?>"
     data-site="<?= $site ? (int)$site['id'] : '' ?>">

    <div class="pt-clock" data-pt-clock><?= date('H:i:s') ?></div>
    <div class="pt-date" data-pt-date></div>

    <div class="pt-service-info" data-en-service <?= $ouvert ? '' : 'hidden' ?>>
        <span class="pt-badge pt-badge-green"><i class="fa fa-circle fa-beat-fade" style="font-size:.5rem"></i> En service</span>
        <div class="pt-small pt-muted mt-1">
            <?= $ouvert ? h($ouvert['mission_nom']) . ' · depuis ' . ptH($ouvert['debut_reel']) : '' ?>
        </div>
        <div class="pt-chrono" data-pt-chrono>0:00:00</div>
    </div>

    <?php if ($choixSite): ?>
    <div class="pt-sites" data-hors-service>
        <div class="pt-card-label" style="margin-top:14px">Sur quel site êtes-vous ?</div>
        <?php foreach ($sites as $i => $s): ?>
            <label class="pt-site-choice">
                <input type="radio" name="pt_site" value="<?= (int)$s['id'] ?>">
                <span><strong><?= h($s['nom']) ?></strong><?php if ($s['client_nom']): ?><br><small class="pt-muted"><?= h($s['client_nom']) ?></small><?php endif; ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$ouvert && !$sites && !$vac): ?>
        <div class="pt-alert pt-alert-danger" style="margin-top:16px">Vous n'êtes affecté à aucun site. Contactez votre responsable.</div>
    <?php else: ?>
        <button class="pt-main-btn <?= $ouvert ? 'stop' : 'start' ?>" type="button" data-pt-btn style="margin-top:18px">
            <i class="fa <?= $ouvert ? 'fa-right-from-bracket' : 'fa-fingerprint' ?>"></i>
            <span data-pt-btn-label><?= $ouvert ? 'Fin de service' : 'Prise de service' ?></span>
        </button>
        <?php if ($selfie === 'optionnel'): ?>
            <label class="pt-check pt-small" style="justify-content:center;margin:12px 0 0">
                <input type="checkbox" data-pt-selfie-opt style="width:20px;height:20px"> <span>Ajouter une photo (poste, tenue, selfie…)</span>
            </label>
        <?php elseif ($selfie === 'obligatoire'): ?>
            <div class="pt-muted pt-small" style="margin-top:10px"><i class="fa fa-camera"></i> Une photo vous sera demandée (poste, tenue ou selfie).</div>
        <?php endif; ?>
        <div class="pt-muted pt-small" data-pt-status style="margin-top:10px;min-height:1.3em"></div>
    <?php endif; ?>
    <input type="file" accept="image/*" capture="environment" data-pt-camera hidden>
</div>

<?php if ($site || $vac):
    $nomSite = $site['nom'] ?? $vac['mission_nom'];
    $adresse = ($site['pt_adresse'] ?? '') ?: ($site['lieu'] ?? ($vac['lieu'] ?? ''));
    $gps = !empty($site['pt_latitude']) ? $site['pt_latitude'] . ',' . $site['pt_longitude'] : $adresse;
?>
<div class="pt-card">
    <div class="pt-card-label"><?= $ouvert ? 'Site en cours' : ($vac ? 'Prochaine vacation' : 'Mon site') ?></div>
    <h2 style="margin-bottom:6px"><?= h($nomSite) ?></h2>
    <?php if ($vac && (!$site || (int)$vac['mission_id'] === (int)$site['id'])): ?>
    <div class="pt-info-row"><i class="fa fa-clock"></i>
        <div><?= h(formatDate($vac['date_travail'])) ?> · <strong><?= ptH($vac['debut_dt']) ?> → <?= ptH($vac['fin_dt']) ?></strong></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($site['client_nom'])): ?>
    <div class="pt-info-row"><i class="fa fa-building"></i><div><?= h($site['client_nom']) ?></div></div>
    <?php endif; ?>
    <?php if ($adresse): ?>
    <div class="pt-info-row"><i class="fa fa-location-dot"></i>
        <div><?= h($adresse) ?><br>
            <a class="pt-small" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=<?= h(rawurlencode($gps)) ?>"><i class="fa fa-route"></i> Itinéraire</a></div>
    </div>
    <?php endif; ?>
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
    <?php $groupe = ptGroupeWhatsapp($site); if ($groupe): ?>
    <a class="pt-action" target="_blank" rel="noopener" href="<?= h($groupe) ?>" style="grid-column:1 / -1"><i class="fa-brands fa-whatsapp" style="color:#25d366"></i>Groupe WhatsApp<?= $site ? ' — ' . h($site['nom']) : '' ?></a>
    <?php endif; ?>
    <a class="pt-action" href="<?= h(ptUrl('evenement.php')) ?>"><i class="fa fa-triangle-exclamation"></i>Signaler<br><span class="pt-muted pt-small">Incident, photo</span></a>
    <a class="pt-action" href="<?= h(ptUrl('evenement.php?type=main_courante')) ?>"><i class="fa fa-book"></i>Main courante<br><span class="pt-muted pt-small">Rondes, passation</span></a>
</div>

<?php
$siteRonde = $site ? (int)$site['id'] : 0;
$nbPoints = 0;
if ($siteRonde) { $nbPoints = ($site['pt_ronde_mode'] ?? 'aucune') !== 'aucune' ? count(ptPointsSite($siteRonde)) : 0; }
elseif ($sites) { foreach ($sites as $s) if (($s['pt_ronde_mode'] ?? 'aucune') !== 'aucune') $nbPoints += count(ptPointsSite((int)$s['id'])); }
if ($nbPoints):
    $etatR = $siteRonde ? ptEtatRonde($siteRonde, $agentId) : null; ?>
<a class="pt-btn pt-btn-ghost" href="<?= h(ptUrl('ronde.php')) ?>" style="margin-bottom:14px;border-color:var(--pt-gold)">
    <i class="fa fa-qrcode" style="color:var(--pt-gold)"></i> Faire ma ronde
    <?php if ($etatR): ?><span class="pt-badge pt-badge-grey"><?= (int)$etatR['faits'] ?>/<?= (int)$etatR['total'] ?></span><?php endif; ?>
</a>
<?php endif; ?>

<button type="button" class="pt-sos" data-pt-sos data-api="<?= h(ptUrl('api/evenement.php')) ?>"
        data-tel="<?= h(preg_replace('/[^\d+]/', '', $contact['tel'])) ?>">
    <span class="pt-sos-fill"></span>
    <span class="pt-sos-label"><i class="fa fa-bell"></i> SOS — maintenir 2 secondes</span>
</button>

<div class="pt-card" data-pt-push hidden data-api="<?= h(ptUrl('api/push.php')) ?>">
    <h2><i class="fa fa-bell me-1"></i> Activer les rappels</h2>
    <p class="pt-muted pt-small mb-2">Recevez un rappel avant votre vacation et si vous oubliez de pointer.</p>
    <button type="button" class="pt-btn pt-btn-gold" data-pt-push-btn><i class="fa fa-bell"></i> Activer les notifications</button>
    <div class="pt-muted pt-small" data-pt-push-ios hidden style="margin-top:8px">Sur iPhone, installez d'abord l'application sur l'écran d'accueil (voir ci-dessous), puis ouvrez-la depuis l'icône.</div>
</div>

<?php if ($site && trim((string)($site['pt_consignes'] ?? '')) !== ''): ?>
<div class="pt-card">
    <h2><i class="fa fa-clipboard-list me-1"></i> Consignes du poste</h2>
    <div style="white-space:pre-line;font-size:.95rem"><?= h($site['pt_consignes']) ?></div>
</div>
<?php endif; ?>

<div class="pt-card">
    <div style="display:flex;justify-content:space-between;align-items:baseline">
        <h2 style="margin:0"><i class="fa fa-clock-rotate-left me-1"></i> Mes heures</h2>
        <span class="pt-muted pt-small"><?= h(getNomMois((int)date('n'))) ?></span>
    </div>
    <div class="pt-total"><?= ptFormatDuree($totalMois) ?></div>
    <?php if (!$servicesMois): ?>
        <div class="pt-muted pt-small">Aucun service ce mois-ci.</div>
    <?php endif; ?>
    <?php foreach (array_slice($servicesMois, 0, 6) as $s): ?>
        <div class="pt-info-row">
            <i class="fa <?= $s['fin_retenu'] ? 'fa-check' : 'fa-hourglass-half' ?>"></i>
            <div style="flex:1">
                <div><strong><?= date('d/m', strtotime($s['debut_retenu'])) ?></strong> · <?= ptH($s['debut_retenu']) ?> → <?= $s['fin_retenu'] ? ptH($s['fin_retenu']) : 'en cours' ?></div>
                <div class="pt-muted pt-small"><?= h($s['mission_nom']) ?></div>
            </div>
            <div style="font-weight:700"><?= $s['fin_retenu'] ? ptFormatDuree(ptDureeMin($s)) : '' ?></div>
        </div>
    <?php endforeach; ?>
    <div class="pt-muted pt-small" style="margin-top:8px">Heures retenues, sous réserve de validation par votre responsable.</div>
</div>

<div class="pt-card pt-install" data-pt-install>
    <h2><i class="fa fa-mobile-screen me-1"></i> Installer l'application</h2>
    <p class="pt-muted pt-small mb-2">Ajoutez l'application sur votre écran d'accueil pour l'ouvrir en un geste et recevoir les rappels.</p>
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
        <form method="post" action="<?= h(ptUrl('logout.php')) ?>" class="mt-auto" data-pt-logout>
            <?= ptCsrfField() ?>
            <button class="pt-btn pt-btn-ghost" type="submit"><i class="fa fa-right-from-bracket"></i> Se déconnecter</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php ptFoot(['assets/app.js']);
