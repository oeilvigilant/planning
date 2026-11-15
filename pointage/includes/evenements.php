<?php
/** Événements terrain : incidents, SOS, main courante. */

function ptCategories(string $type): array {
    if ($type === 'main_courante') {
        return ['ronde' => 'Ronde', 'passation' => 'Passation de consignes', 'visite' => 'Visite / accueil', 'livraison' => 'Livraison',
                'consigne' => 'Consigne reçue', 'observation' => 'Observation', 'autre' => 'Autre'];
    }
    return ['intrusion' => 'Intrusion', 'vol' => 'Vol', 'degradation' => 'Dégradation', 'incendie' => 'Incendie / fumée',
            'inondation' => 'Dégât des eaux', 'malaise' => 'Malaise / blessé', 'agression' => 'Agression / altercation',
            'colis' => 'Colis suspect', 'alarme' => 'Alarme', 'panne' => 'Panne technique', 'autre' => 'Autre'];
}

function ptLibelleGravite(string $g): array {
    $l = ['info' => ['Info', 'off'], 'faible' => ['Faible', 'off'], 'moyenne' => ['Moyenne', 'wait'], 'haute' => ['Haute', 'ko'], 'critique' => ['Critique', 'ko']];
    return $l[$g] ?? [$g, 'off'];
}

/**
 * Enregistre un événement envoyé par un agent.
 * $p : uuid, type, categorie, gravite, description, photos[] (data URL), lat, lng, precision,
 *      mission_id?, hors_ligne, ts_appareil (ms).
 */
function ptEnregistrerEvenement(array $agent, array $p, ?int $now = null): array {
    $db = getDB();
    $now = $now ?? time();
    $agentId = (int)$agent['id'];
    $uuid = (string)($p['uuid'] ?? '');
    $type = (string)($p['type'] ?? 'incident');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $uuid)) return ptErr(400, 'Identifiant invalide.');
    if (!in_array($type, ['incident', 'sos', 'main_courante'], true)) return ptErr(400, 'Type inconnu.');

    $st = $db->prepare("SELECT id FROM pt_evenements WHERE uuid = ? AND agent_id = ?");
    $st->execute([$uuid, $agentId]);
    if ($id = $st->fetchColumn()) return ['http' => 200, 'ok' => true, 'deja' => true, 'id' => (int)$id, 'message' => 'Déjà enregistré.'];

    $cats = ptCategories($type);
    $categorie = $type === 'sos' ? 'sos' : (isset($cats[$p['categorie'] ?? '']) ? $p['categorie'] : 'autre');
    $gravite = $type === 'sos' ? 'critique' : (in_array($p['gravite'] ?? '', ['info', 'faible', 'moyenne', 'haute', 'critique'], true) ? $p['gravite'] : 'info');
    if ($type === 'main_courante') $gravite = 'info';
    $description = trim(mb_substr((string)($p['description'] ?? ''), 0, 4000));
    if ($type !== 'sos' && $description === '' && empty($p['photos'])) return ptErr(422, 'Décrivez l\'événement ou ajoutez une photo.');

    $ts = $now;
    if (!empty($p['hors_ligne'])) {
        $tsApp = (int)round(((float)($p['ts_appareil'] ?? 0)) / 1000);
        if ($tsApp > $now - 48 * 3600 && $tsApp <= $now + 120) $ts = min($tsApp, $now);
    }

    // Site : service en cours, sinon site choisi (si affecté), sinon vacation, sinon site unique
    $ouvert = ptServiceOuvert($agentId);
    $missionId = $ouvert ? (int)$ouvert['mission_id'] : 0;
    if (!$missionId) {
        $ids = array_map('intval', array_column(ptSitesAgent($agentId), 'id'));
        $choix = (int)($p['mission_id'] ?? 0);
        if ($choix && in_array($choix, $ids, true)) $missionId = $choix;
        elseif (($vc = ptVacationCourante($agentId, $ts))) $missionId = (int)$vc['mission_id'];
        elseif (count($ids) === 1) $missionId = $ids[0];
    }

    $lat = isset($p['lat']) && is_numeric($p['lat']) ? (float)$p['lat'] : null;
    $lng = isset($p['lng']) && is_numeric($p['lng']) ? (float)$p['lng'] : null;
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) $lat = $lng = null;
    $prec = isset($p['precision']) && is_numeric($p['precision']) ? (int)round($p['precision']) : null;

    $photos = [];
    foreach (array_slice((array)($p['photos'] ?? []), 0, 4) as $ph) {
        if (is_string($ph) && ($rel = ptEnregistrerPhoto($ph, 'ev' . $agentId))) $photos[] = $rel;
    }

    $db->prepare("INSERT INTO pt_evenements (uuid, agent_id, mission_id, service_id, type, categorie, gravite, description, photos,
            lat, lng, precision_m, survenu_le, statut)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$uuid, $agentId, $missionId ?: null, $ouvert['id'] ?? null, $type, $categorie, $gravite, $description ?: null,
                  $photos ? json_encode($photos, JSON_UNESCAPED_SLASHES) : null, $lat, $lng, $prec, date('Y-m-d H:i:s', $ts),
                  $type === 'main_courante' ? 'clos' : 'ouvert']);
    $id = (int)$db->lastInsertId();

    $site = $missionId ? ptSite($missionId) : null;
    $qui = $agent['prenom'] . ' ' . $agent['nom'];
    $ou = $site ? $site['nom'] : 'site inconnu';
    $pos = $lat !== null ? "\nPosition : https://www.google.com/maps?q=$lat,$lng" : '';
    if ($type === 'sos') {
        ptNotifierResponsables('🚨 SOS — ' . $qui, $qui . ' a déclenché une alerte SOS (' . $ou . ') à ' . date('H\hi', $ts) . '.' . $pos,
            'admin/evenements.php?id=' . $id, true, true);
        return ['http' => 200, 'ok' => true, 'id' => $id, 'message' => 'Alerte SOS envoyée à vos responsables.'];
    }
    if ($type === 'incident' && in_array($gravite, ['haute', 'critique'], true)) {
        ptNotifierResponsables('⚠ Incident ' . $gravite . ' — ' . $ou, $qui . ' : ' . (ptCategories('incident')[$categorie] ?? $categorie)
            . ($description ? ' — ' . mb_substr($description, 0, 140) : '') . $pos, 'admin/evenements.php?id=' . $id, $gravite === 'critique', $gravite === 'critique');
    }
    return ['http' => 200, 'ok' => true, 'id' => $id,
            'message' => $type === 'main_courante' ? 'Ajouté à la main courante.' : 'Événement signalé à votre responsable.'];
}
