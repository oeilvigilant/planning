<?php
/** Tableau de bord responsable (le suivi en direct arrive en phase 4). */
require_once __DIR__ . '/_init.php';
$user = ptRequireAdmin('view');
$db = getDB();

$nbAgents   = (int)$db->query("SELECT COUNT(*) FROM agents WHERE actif = 1 AND (statut_inscription IS NULL OR statut_inscription = 'validee')")->fetchColumn();
$nbActives  = (int)$db->query("SELECT COUNT(*) FROM pt_acces pa JOIN agents a ON a.id = pa.agent_id WHERE a.actif = 1 AND pa.pin_hash IS NOT NULL")->fetchColumn();
$nbInvites  = (int)$db->query("SELECT COUNT(*) FROM pt_acces pa JOIN agents a ON a.id = pa.agent_id WHERE a.actif = 1 AND pa.pin_hash IS NULL AND pa.activation_token IS NOT NULL AND pa.activation_expires >= NOW()")->fetchColumn();
$nbMissions = (int)$db->query("SELECT COUNT(*) FROM missions WHERE actif = 1")->fetchColumn();
$nbGeo      = (int)$db->query("SELECT COUNT(*) FROM missions WHERE actif = 1 AND pt_latitude IS NOT NULL")->fetchColumn();

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

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="pta-kpi"><div class="v"><?= $nbActives ?><span class="text-muted fs-6">/<?= $nbAgents ?></span></div><div class="l">Agents équipés</div></div></div>
    <div class="col-6 col-lg-3"><div class="pta-kpi"><div class="v"><?= $nbInvites ?></div><div class="l">Invitations en attente</div></div></div>
    <div class="col-6 col-lg-3"><div class="pta-kpi"><div class="v"><?= $nbGeo ?><span class="text-muted fs-6">/<?= $nbMissions ?></span></div><div class="l">Sites géolocalisés</div></div></div>
    <div class="col-6 col-lg-3"><div class="pta-kpi"><div class="v">—</div><div class="l">Agents en poste (bientôt)</div></div></div>
</div>

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
<?php ptAdminFoot();
