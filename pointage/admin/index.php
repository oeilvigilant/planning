<?php
/** Tableau de bord responsable : agents en poste, pointages à vérifier, mise en route. */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/pointer.php';
$user = ptRequireAdmin('view');
$db = getDB();

$nbAgents   = (int)$db->query("SELECT COUNT(*) FROM agents WHERE actif = 1 AND (statut_inscription IS NULL OR statut_inscription = 'validee')")->fetchColumn();
$nbActives  = (int)$db->query("SELECT COUNT(*) FROM pt_acces pa JOIN agents a ON a.id = pa.agent_id WHERE a.actif = 1 AND pa.pin_hash IS NOT NULL")->fetchColumn();
$nbInvites  = (int)$db->query("SELECT COUNT(*) FROM pt_acces pa JOIN agents a ON a.id = pa.agent_id WHERE a.actif = 1 AND pa.pin_hash IS NULL AND pa.activation_token IS NOT NULL AND pa.activation_expires >= NOW()")->fetchColumn();
$nbMissions = (int)$db->query("SELECT COUNT(*) FROM missions WHERE actif = 1")->fetchColumn();
$nbGeo      = (int)$db->query("SELECT COUNT(*) FROM missions WHERE actif = 1 AND pt_latitude IS NOT NULL")->fetchColumn();

$enPoste = $db->query("SELECT s.id, s.debut_retenu, s.debut_reel, s.alertes, a.nom, a.prenom, m.nom AS site_nom
    FROM pt_services s JOIN agents a ON a.id = s.agent_id JOIN missions m ON m.id = s.mission_id
    WHERE s.fin_retenu IS NULL AND s.statut <> 'annule' ORDER BY s.debut_reel")->fetchAll();
$nbVerif = (int)$db->query("SELECT COUNT(*) FROM pt_services WHERE statut = 'a_verifier'")->fetchColumn();
$sosOuverts = $db->query("SELECT e.id, e.survenu_le, a.prenom, a.nom, m.nom AS site_nom FROM pt_evenements e JOIN agents a ON a.id = e.agent_id
    LEFT JOIN missions m ON m.id = e.mission_id WHERE e.type = 'sos' AND e.statut <> 'clos' ORDER BY e.survenu_le DESC")->fetchAll();
$nbIncidents = (int)$db->query("SELECT COUNT(*) FROM pt_evenements WHERE type = 'incident' AND statut <> 'clos'")->fetchColumn();
$nbAgentsSansSite = (int)$db->query("SELECT COUNT(*) FROM agents a WHERE a.actif = 1 AND (a.statut_inscription IS NULL OR a.statut_inscription = 'validee')
    AND NOT EXISTS (SELECT 1 FROM mission_agents ma JOIN missions m ON m.id = ma.mission_id AND m.actif = 1 WHERE ma.agent_id = a.id)")->fetchColumn();

$etapes = [
    ['ok' => $nbGeo > 0, 'titre' => 'Positionner vos sites', 'texte' => "$nbGeo / $nbMissions mission(s) géolocalisée(s)", 'href' => 'missions.php'],
    ['ok' => $nbActives > 0, 'titre' => 'Inviter vos agents', 'texte' => "$nbActives / $nbAgents agent(s) ont activé l'application" . ($nbInvites ? " · $nbInvites invitation(s) en attente" : ''), 'href' => 'agents.php'],
    ['ok' => (int)getParam('pointage_rgpd_version', '1') >= 1, 'titre' => 'Vérifier l\'information RGPD', 'texte' => 'Texte affiché aux agents à leur première connexion', 'href' => 'parametres.php'],
];

ptAdminHead('Tableau de bord', 'dashboard');
?>
<div class="pta-title">
    <div>
        <h1>Bonjour <?= h($user['prenom']) ?></h1>
        <div class="text-muted small"><?= h(['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'][(int)date('w')] . ' ' . date('j') . ' ' . mb_strtolower(getNomMois((int)date('n'))) . ' ' . date('Y')) ?></div>
    </div>
</div>

<?php foreach ($sosOuverts as $s): ?>
    <a href="evenements.php?id=<?= (int)$s['id'] ?>" class="alert alert-danger d-flex align-items-center gap-3 text-decoration-none">
        <i class="fa fa-bell fa-shake fa-lg"></i>
        <div><strong>SOS — <?= h($s['prenom'] . ' ' . $s['nom']) ?></strong> · <?= h($s['site_nom'] ?: 'site inconnu') ?> · <?= date('d/m H\hi', strtotime($s['survenu_le'])) ?>
            <div class="small">Cliquez pour voir la position et traiter l'alerte.</div></div>
    </a>
<?php endforeach; ?>
<?php if ($nbIncidents): ?>
    <a href="evenements.php?type=incident&statut=ouvert&du=<?= date('Y-m-d', strtotime('-60 days')) ?>" class="alert alert-warning d-block small text-decoration-none">
        <i class="fa fa-triangle-exclamation me-1"></i><?= $nbIncidents ?> incident(s) non clos. Voir les événements</a>
<?php endif; ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="pta-kpi"><div class="v text-success"><?= count($enPoste) ?></div><div class="l">Agents en poste maintenant</div></div></div>
    <div class="col-6 col-lg-3"><a class="pta-kpi d-block text-decoration-none" href="pointages.php?statut=a_verifier&du=<?= date('Y-m-d', strtotime('-60 days')) ?>"><div class="v <?= $nbVerif ? 'text-danger' : '' ?>"><?= $nbVerif ?></div><div class="l">Pointages à vérifier</div></a></div>
    <div class="col-6 col-lg-3"><a class="pta-kpi d-block text-decoration-none" href="agents.php"><div class="v"><?= $nbActives ?><span class="text-muted fs-6">/<?= $nbAgents ?></span></div><div class="l">Agents équipés<?= $nbInvites ? " · $nbInvites invitation(s) en attente" : '' ?></div></a></div>
    <div class="col-6 col-lg-3"><a class="pta-kpi d-block text-decoration-none" href="missions.php"><div class="v"><?= $nbGeo ?><span class="text-muted fs-6">/<?= $nbMissions ?></span></div><div class="l">Sites géolocalisés</div></a></div>
</div>

<div class="pta-card">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="mb-0"><span class="text-success">●</span> En poste maintenant</h2>
        <a class="small" href="pointages.php">Tous les pointages</a>
    </div>
    <?php if (!$enPoste): ?><div class="text-muted small">Aucun agent en service.</div><?php endif; ?>
    <?php foreach ($enPoste as $p):
        $min = (int)floor((time() - strtotime($p['debut_reel'])) / 60); ?>
        <div class="d-flex align-items-center gap-3 py-2 border-top">
            <div class="flex-grow-1"><span class="fw-semibold"><?= h($p['prenom'] . ' ' . $p['nom']) ?></span>
                <span class="text-muted small">· <?= h($p['site_nom']) ?></span></div>
            <?php foreach (array_filter(explode(',', (string)$p['alertes'])) as $x): if ($x === 'hors_ligne') continue; ?>
                <span class="pta-badge ko"><?= h(ptLibelleAlerte($x)) ?></span>
            <?php endforeach; ?>
            <span class="small text-muted text-nowrap">depuis <?= date('H\hi', strtotime($p['debut_reel'])) ?> (<?= ptFormatDuree($min) ?>)</span>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($nbAgentsSansSite): ?>
<div class="alert alert-warning small"><i class="fa fa-triangle-exclamation me-1"></i><?= $nbAgentsSansSite ?> agent(s) actif(s) ne sont affectés à aucun site : ils ne pourront pas pointer. <a href="agents.php">Voir les agents</a></div>
<?php endif; ?>

<div class="pta-card">
    <h2>Mise en route</h2>
    <?php foreach ($etapes as $i => $e): ?>
        <a href="<?= h($e['href']) ?>" class="d-flex align-items-center gap-3 py-2 text-decoration-none text-reset <?= $i ? 'border-top' : '' ?>">
            <span class="d-inline-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
                  style="width:34px;height:34px;background:<?= $e['ok'] ? '#dcfce7' : '#fef3c7' ?>;color:<?= $e['ok'] ? '#166534' : '#92400e' ?>">
                <i class="fa <?= $e['ok'] ? 'fa-check' : 'fa-hourglass-half' ?>"></i></span>
            <span class="flex-grow-1"><span class="fw-semibold d-block"><?= h($e['titre']) ?></span><span class="small text-muted"><?= h($e['texte']) ?></span></span>
            <i class="fa fa-chevron-right text-muted"></i>
        </a>
    <?php endforeach; ?>
</div>

<div class="pta-card">
    <h2>Application agent</h2>
    <div class="small text-muted mb-2">Adresse à communiquer à vos agents (ils peuvent l'ajouter à leur écran d'accueil) :</div>
    <div class="pta-link-box"><?= h(ptUrl('')) ?></div>
</div>
<?php ptAdminFoot('<script>setTimeout(function () { location.reload(); }, 60000);</script>');
