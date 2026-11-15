<?php
/** Agent : signaler un incident ou écrire dans la main courante. */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/evenements.php';

$agent = ptRequireAgent();
$agentId = (int)$agent['id'];
$type = ($_GET['type'] ?? '') === 'main_courante' ? 'main_courante' : 'incident';
$ouvert = ptServiceOuvert($agentId);
$sites = ptSitesAgent($agentId);
$siteId = $ouvert ? (int)$ouvert['mission_id'] : (count($sites) === 1 ? (int)$sites[0]['id'] : 0);

// Main courante du site (aujourd'hui et hier), pour la passation entre agents
$journal = [];
if ($siteId) {
    $st = getDB()->prepare("SELECT e.*, a.prenom, a.nom FROM pt_evenements e JOIN agents a ON a.id = e.agent_id
        WHERE e.mission_id = ? AND e.survenu_le >= ? ORDER BY e.survenu_le DESC LIMIT 30");
    $st->execute([$siteId, date('Y-m-d 00:00:00', strtotime('-1 day'))]);
    $journal = $st->fetchAll();
}

ptHead($type === 'main_courante' ? 'Main courante' : 'Signaler');
?>
<div class="pt-top">
    <a class="pt-icon-btn" href="<?= h(ptUrl('app.php')) ?>" aria-label="Retour"><i class="fa fa-arrow-left"></i></a>
    <div class="pt-hello" style="flex:1;text-align:center"><?= $type === 'main_courante' ? 'Main courante' : 'Signaler un événement' ?></div>
    <span style="width:44px"></span>
</div>

<div class="pt-tabs">
    <a href="?type=incident" class="<?= $type === 'incident' ? 'active' : '' ?>"><i class="fa fa-triangle-exclamation"></i> Incident</a>
    <a href="?type=main_courante" class="<?= $type === 'main_courante' ? 'active' : '' ?>"><i class="fa fa-book"></i> Main courante</a>
</div>

<form class="pt-card" data-pt-evenement data-api="<?= h(ptUrl('api/evenement.php')) ?>" data-agent="<?= $agentId ?>" data-type="<?= $type ?>">
    <?php if (!$siteId && count($sites) > 1): ?>
        <label class="pt-label" style="margin-top:0">Site</label>
        <select class="pt-input" name="mission_id" required>
            <option value="">Choisir…</option>
            <?php foreach ($sites as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['nom']) ?></option><?php endforeach; ?>
        </select>
    <?php else: ?>
        <input type="hidden" name="mission_id" value="<?= $siteId ?: '' ?>">
    <?php endif; ?>

    <label class="pt-label" style="margin-top:0">Catégorie</label>
    <div class="pt-chips">
        <?php $i = 0; foreach (ptCategories($type) as $k => $l): ?>
            <label class="pt-chip"><input type="radio" name="categorie" value="<?= h($k) ?>" <?= $i++ === 0 ? 'checked' : '' ?>><span><?= h($l) ?></span></label>
        <?php endforeach; ?>
    </div>

    <?php if ($type === 'incident'): ?>
    <label class="pt-label">Gravité</label>
    <div class="pt-chips pt-gravite">
        <?php foreach (['faible' => 'Faible', 'moyenne' => 'Moyenne', 'haute' => 'Haute', 'critique' => 'Critique'] as $k => $l): ?>
            <label class="pt-chip g-<?= $k ?>"><input type="radio" name="gravite" value="<?= $k ?>" <?= $k === 'faible' ? 'checked' : '' ?>><span><?= $l ?></span></label>
        <?php endforeach; ?>
    </div>
    <div class="pt-muted pt-small" style="margin-top:6px">Haute ou critique : votre responsable est prévenu immédiatement.</div>
    <?php endif; ?>

    <label class="pt-label" for="desc">Description</label>
    <textarea class="pt-input" id="desc" name="description" rows="4" style="font-size:1rem"
        placeholder="<?= $type === 'main_courante' ? 'Ex. Ronde extérieure 22h : RAS, portail fermé.' : 'Que s\'est-il passé ? Où ? Qui ?' ?>"></textarea>
    <div class="pt-muted pt-small" style="margin-top:4px"><i class="fa fa-microphone"></i> Astuce : utilisez le micro du clavier pour dicter.</div>

    <label class="pt-label">Photos <span class="pt-muted">(4 max)</span></label>
    <div class="pt-photos" data-photos></div>
    <label class="pt-btn pt-btn-ghost" style="margin-top:8px">
        <i class="fa fa-camera"></i> Ajouter une photo
        <input type="file" accept="image/*" capture="environment" data-photo-input hidden multiple>
    </label>

    <div class="pt-muted pt-small" data-status style="margin:12px 0;min-height:1.2em"></div>
    <button class="pt-btn <?= $type === 'incident' ? 'pt-btn-red' : 'pt-btn-gold' ?>" type="submit" data-submit>
        <i class="fa fa-paper-plane"></i> <?= $type === 'main_courante' ? 'Ajouter à la main courante' : 'Envoyer le signalement' ?>
    </button>
</form>

<?php if ($journal): ?>
<div class="pt-card">
    <h2><i class="fa fa-book-open me-1"></i> Journal du site (24 h)</h2>
    <?php foreach ($journal as $e):
        $cat = ptCategories($e['type'] === 'main_courante' ? 'main_courante' : 'incident')[$e['categorie']] ?? ($e['type'] === 'sos' ? 'SOS' : $e['categorie']); ?>
        <div class="pt-info-row">
            <i class="fa <?= $e['type'] === 'main_courante' ? 'fa-book' : ($e['type'] === 'sos' ? 'fa-bell' : 'fa-triangle-exclamation') ?>"></i>
            <div style="flex:1">
                <div><strong><?= date('d/m H:i', strtotime($e['survenu_le'])) ?></strong> · <?= h($cat) ?>
                    <span class="pt-muted pt-small">— <?= h(ptNomCourt($e['prenom'], $e['nom'])) ?></span></div>
                <?php if ($e['description']): ?><div class="pt-small" style="white-space:pre-line"><?= h($e['description']) ?></div><?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php ptFoot(['assets/evenement.js']);
