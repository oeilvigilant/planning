<?php
/** JSON pour la carte en direct (responsables connectés uniquement). */
require_once __DIR__ . '/_init.php';
require_once __DIR__ . '/../includes/pointer.php';
if (!ptAdminUser() || !ptAdminCan('view')) ptJson(['ok' => false], 401);
$db = getDB();

$sites = [];
foreach ($db->query("SELECT id, nom, pt_latitude, pt_longitude, pt_rayon_m FROM missions WHERE actif = 1 AND pt_latitude IS NOT NULL") as $s) {
    $sites[(int)$s['id']] = ['id' => (int)$s['id'], 'nom' => $s['nom'], 'lat' => (float)$s['pt_latitude'], 'lng' => (float)$s['pt_longitude'],
        'rayon' => (int)$s['pt_rayon_m'], 'en_poste' => 0];
}

$agents = [];
$rows = $db->query("SELECT s.id, s.mission_id, s.debut_reel, s.debut_lat, s.debut_lng, s.alertes, a.prenom, a.nom, a.telephone, m.nom AS site_nom,
        m.pt_latitude, m.pt_longitude
    FROM pt_services s JOIN agents a ON a.id = s.agent_id JOIN missions m ON m.id = s.mission_id
    WHERE s.fin_retenu IS NULL AND s.statut <> 'annule' ORDER BY s.debut_reel")->fetchAll();
foreach ($rows as $r) {
    if (isset($sites[(int)$r['mission_id']])) $sites[(int)$r['mission_id']]['en_poste']++;
    $lat = $r['debut_lat'] ?: $r['pt_latitude'];
    $lng = $r['debut_lng'] ?: $r['pt_longitude'];
    $agents[] = ['nom' => $r['prenom'] . ' ' . $r['nom'], 'site' => $r['site_nom'], 'depuis' => date('H\hi', strtotime($r['debut_reel'])),
        'duree' => ptFormatDuree((int)floor((time() - strtotime($r['debut_reel'])) / 60)), 'tel' => (string)$r['telephone'],
        'lat' => $lat !== null ? (float)$lat : null, 'lng' => $lng !== null ? (float)$lng : null,
        'alertes' => array_values(array_map('ptLibelleAlerte', array_diff(array_filter(explode(',', (string)$r['alertes'])), ['hors_ligne'])))];
}

$sos = [];
foreach ($db->query("SELECT e.id, e.survenu_le, e.lat, e.lng, a.prenom, a.nom, a.telephone, m.nom AS site_nom
    FROM pt_evenements e JOIN agents a ON a.id = e.agent_id LEFT JOIN missions m ON m.id = e.mission_id
    WHERE e.type = 'sos' AND e.statut <> 'clos' ORDER BY e.survenu_le DESC") as $e) {
    $sos[] = ['id' => (int)$e['id'], 'nom' => $e['prenom'] . ' ' . $e['nom'], 'site' => (string)$e['site_nom'], 'tel' => (string)$e['telephone'],
        'heure' => date('d/m H\hi', strtotime($e['survenu_le'])), 'lat' => $e['lat'] !== null ? (float)$e['lat'] : null, 'lng' => $e['lng'] !== null ? (float)$e['lng'] : null];
}
$incidents = (int)$db->query("SELECT COUNT(*) FROM pt_evenements WHERE type = 'incident' AND statut <> 'clos'")->fetchColumn();

ptJson(['ok' => true, 'heure' => date('H:i:s'), 'sites' => array_values($sites), 'agents' => $agents, 'sos' => $sos, 'incidents' => $incidents]);
