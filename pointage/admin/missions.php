<?php
/** Sites : coordonnées GPS, périmètre et contact terrain de chaque mission. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');
$db = getDB();

$missions = $db->query("SELECT m.id, m.nom, m.lieu, m.actif, m.pt_adresse, m.pt_latitude, m.pt_longitude, m.pt_rayon_m,
        m.pt_responsable_nom, m.pt_responsable_tel, c.nom AS client_nom,
        (SELECT COUNT(*) FROM mission_agents ma WHERE ma.mission_id = m.id) AS nb_agents
    FROM missions m LEFT JOIN clients c ON c.id = m.client_id
    ORDER BY m.actif DESC, m.nom")->fetchAll();

ptAdminHead('Sites', 'sites');
?>
<div class="pta-title">
    <div>
        <h1>Sites</h1>
        <div class="text-muted small">Position du site, périmètre de pointage et responsable à contacter, pour chaque mission.</div>
    </div>
</div>

<div class="pta-card p-0">
<div class="table-responsive">
<table class="pta-table">
    <thead><tr><th class="ps-3">Mission</th><th>Client</th><th>Adresse du site</th><th>Périmètre</th><th>Responsable</th><th class="text-end pe-3"></th></tr></thead>
    <tbody>
    <?php foreach ($missions as $m): ?>
        <tr class="<?= $m['actif'] ? '' : 'opacity-50' ?>">
            <td class="ps-3"><div class="fw-semibold"><?= h($m['nom']) ?></div><div class="text-muted small"><?= (int)$m['nb_agents'] ?> agent(s)<?= $m['actif'] ? '' : ' · inactive' ?></div></td>
            <td class="small"><?= h($m['client_nom'] ?: '—') ?></td>
            <td class="small">
                <?php if ($m['pt_latitude']): ?>
                    <span class="pta-badge ok"><i class="fa fa-location-dot me-1"></i>Géolocalisé</span>
                    <div class="text-muted mt-1"><?= h($m['pt_adresse'] ?: '') ?></div>
                <?php else: ?>
                    <span class="pta-badge wait">À positionner</span>
                    <?php if ($m['lieu']): ?><div class="text-muted mt-1"><?= h($m['lieu']) ?></div><?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="small"><?= $m['pt_latitude'] ? (int)$m['pt_rayon_m'] . ' m' : '—' ?></td>
            <td class="small"><?= h($m['pt_responsable_nom'] ?: '—') ?><div class="text-muted"><?= h($m['pt_responsable_tel'] ?: ($m['pt_responsable_nom'] ? '' : 'contact par défaut')) ?></div></td>
            <td class="text-end pe-3"><a class="btn btn-sm btn-pta" href="mission.php?id=<?= (int)$m['id'] ?>"><i class="fa fa-pen me-1"></i>Configurer</a></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!$missions): ?><tr><td colspan="6" class="text-center text-muted py-4">Aucune mission. Créez-les dans OV-Gestion.</td></tr><?php endif; ?>
    </tbody>
</table>
</div>
</div>
<?php ptAdminFoot();
