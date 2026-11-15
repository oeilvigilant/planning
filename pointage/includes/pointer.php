<?php
/**
 * Traitement d'un pointage (prise ou fin de service), indépendant du transport HTTP.
 *
 * $p : action ('debut'|'fin'), uuid, mission_id?, hors_ligne (bool), ts_appareil (ms),
 *      lat, lng, precision (m) — null si GPS indisponible —, photo (data URL)?
 * Retour : ['http' => code, 'ok' => bool, 'message' => texte pour l'agent, ...]
 */
function ptTraiterPointage(array $agent, array $p, ?int $now = null): array {
    $db = getDB();
    $now = $now ?? time();
    $agentId = (int)$agent['id'];
    $action = $p['action'] ?? '';
    $uuid = (string)($p['uuid'] ?? '');
    if (!in_array($action, ['debut', 'fin'], true)) return ptErr(400, 'Action inconnue.');
    if (!preg_match('/^[0-9a-f-]{36}$/i', $uuid)) return ptErr(400, 'Identifiant de pointage invalide.');

    // Rejeu (envoi hors connexion répété) : on renvoie le résultat déjà enregistré
    $col = $action === 'debut' ? 'debut_uuid' : 'fin_uuid';
    $st = $db->prepare("SELECT id FROM pt_services WHERE $col = ? AND agent_id = ?");
    $st->execute([$uuid, $agentId]);
    if ($id = $st->fetchColumn()) return ['http' => 200, 'ok' => true, 'deja' => true, 'service_id' => (int)$id, 'message' => 'Pointage déjà enregistré.'];

    // Heure : serveur, sauf pointage fait hors connexion (heure du téléphone, bornée à 48 h)
    $ts = $now;
    $horsLigne = !empty($p['hors_ligne']);
    if ($horsLigne) {
        $tsApp = (int)round(((float)($p['ts_appareil'] ?? 0)) / 1000);
        if ($tsApp > $now - 48 * 3600 && $tsApp <= $now + 120) $ts = min($tsApp, $now);
        else $horsLigne = false;
    }
    $reel = date('Y-m-d H:i:s', $ts);

    // Position
    $lat = isset($p['lat']) && is_numeric($p['lat']) ? (float)$p['lat'] : null;
    $lng = isset($p['lng']) && is_numeric($p['lng']) ? (float)$p['lng'] : null;
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) { $lat = $lng = null; }
    $prec = isset($p['precision']) && is_numeric($p['precision']) ? (int)round($p['precision']) : null;
    $tol = max(0, (int)getParam('pointage_tolerance_min', '5'));

    $ouvert = ptServiceOuvert($agentId);

    if ($action === 'debut') {
        if ($ouvert) {
            return ptErr(409, 'Vous êtes déjà en service depuis ' . date('H\hi', strtotime($ouvert['debut_reel'])) . ' (' . $ouvert['mission_nom'] . ').', ['etat' => 'en_service']);
        }
        // Site : choisi par l'agent, sinon vacation planifiée, sinon son site unique
        $ids = array_map('intval', array_column(ptSitesAgent($agentId), 'id'));
        $missionId = (int)($p['mission_id'] ?? 0);
        if (!$missionId) {
            $vc = ptVacationCourante($agentId, $ts);
            if ($vc && $ts >= strtotime($vc['debut_dt']) - 3 * 3600) $missionId = (int)$vc['mission_id'];
            elseif (count($ids) === 1) $missionId = $ids[0];
        }
        if (!$missionId) return ptErr(422, 'Choisissez votre site avant de pointer.', ['choisir_site' => true]);
        $vac = ptVacationPourPrise($agentId, $missionId, $ts);
        if (!in_array($missionId, $ids, true) && !$vac) return ptErr(403, 'Vous n\'êtes pas affecté à ce site.');

        $site = ptSite($missionId);
        if (!$site || !(int)$site['actif']) return ptErr(403, 'Ce site n\'est plus actif.');
        [$retenu, $ecart] = ptAppliquerTolerance($reel, $vac['debut_dt'] ?? null);
        [$dist, $horsZone] = ptControleZone($site, $lat, $lng);

        $photo = !empty($p['photo']) ? ptEnregistrerPhoto((string)$p['photo'], 'a' . $agentId . '-debut') : null;
        if (getParam('pointage_selfie', 'optionnel') === 'obligatoire' && !$photo) {
            return ptErr(422, 'Une photo est demandée pour pointer.', ['photo_requise' => true]);
        }

        $alertes = [];
        if ($lat === null) $alertes[] = 'sans_gps';
        if ($horsZone) $alertes[] = 'hors_zone';
        if ($ecart !== null && $ecart > $tol) $alertes[] = 'retard';
        if ($ecart !== null && $ecart < -$tol) $alertes[] = 'avance';
        if ($horsLigne) $alertes[] = 'hors_ligne';

        $db->prepare("INSERT INTO pt_services (agent_id, mission_id, planning_ligne_id, prevu_debut, prevu_fin,
                debut_reel, debut_retenu, debut_uuid, debut_lat, debut_lng, debut_precision_m, debut_distance_m, debut_hors_zone,
                debut_photo, debut_hors_ligne, statut, alertes, source)
            VALUES (?,?,?,?,?, ?,?,?,?,?,?,?,?, ?,?, 'en_cours', ?, 'agent')")
           ->execute([$agentId, $missionId, $vac['id'] ?? null, $vac['debut_dt'] ?? null, $vac['fin_dt'] ?? null,
                      $reel, $retenu, $uuid, $lat, $lng, $prec, $dist, $horsZone ? 1 : 0,
                      $photo, $horsLigne ? 1 : 0, $alertes ? implode(',', $alertes) : null]);
        $id = (int)$db->lastInsertId();

        $msg = 'Prise de service enregistrée à ' . date('H\hi', $ts) . ' — ' . $site['nom'] . '.';
        if ($horsZone) $msg .= ' Vous êtes à ' . ptFormatDistance($dist) . ' du site : votre responsable sera prévenu.';
        elseif ($lat === null) $msg .= ' Position non disponible.';
        return ['http' => 200, 'ok' => true, 'service_id' => $id, 'etat' => 'en_service', 'message' => $msg, 'alertes' => $alertes];
    }

    // Fin de service
    if (!$ouvert) return ptErr(409, 'Aucun service en cours à terminer.', ['etat' => 'hors_service']);
    if ($ts < strtotime($ouvert['debut_reel'])) $ts = strtotime($ouvert['debut_reel']);
    $reel = date('Y-m-d H:i:s', $ts);
    $site = ptSite((int)$ouvert['mission_id']);
    [$retenu, $ecart] = ptAppliquerTolerance($reel, $ouvert['prevu_fin']);
    if (strtotime($retenu) < strtotime($ouvert['debut_retenu'])) $retenu = $ouvert['debut_retenu'];
    [$dist, $horsZone] = ptControleZone($site, $lat, $lng);

    $alertes = $ouvert['alertes'] ? explode(',', $ouvert['alertes']) : [];
    if ($lat === null) $alertes[] = 'fin_sans_gps';
    if ($horsZone) $alertes[] = 'fin_hors_zone';
    if ($ecart !== null && $ecart < -$tol) $alertes[] = 'depart_anticipe';
    if ($ecart !== null && $ecart > $tol) $alertes[] = 'depassement';
    if ($horsLigne) $alertes[] = 'hors_ligne';
    $alertes = array_values(array_unique($alertes));
    $statut = array_diff($alertes, ['hors_ligne']) ? 'a_verifier' : 'ok';

    $photo = !empty($p['photo']) ? ptEnregistrerPhoto((string)$p['photo'], 'a' . $agentId . '-fin') : null;

    $db->prepare("UPDATE pt_services SET fin_reel = ?, fin_retenu = ?, fin_uuid = ?, fin_lat = ?, fin_lng = ?, fin_precision_m = ?,
            fin_distance_m = ?, fin_hors_zone = ?, fin_photo = ?, fin_hors_ligne = ?, statut = ?, alertes = ?
        WHERE id = ? AND fin_retenu IS NULL")
       ->execute([$reel, $retenu, $uuid, $lat, $lng, $prec, $dist, $horsZone ? 1 : 0, $photo, $horsLigne ? 1 : 0,
                  $statut, $alertes ? implode(',', $alertes) : null, $ouvert['id']]);

    $duree = (int)round((strtotime($retenu) - strtotime($ouvert['debut_retenu'])) / 60);
    return ['http' => 200, 'ok' => true, 'service_id' => (int)$ouvert['id'], 'etat' => 'hors_service', 'alertes' => $alertes,
            'message' => 'Fin de service enregistrée à ' . date('H\hi', $ts) . '. Durée : ' . ptFormatDuree($duree) . '. Bon repos !'];
}

/** [distance en m ou null, hors zone ?] — hors zone seulement si le site est géolocalisé et la position connue. */
function ptControleZone(?array $site, ?float $lat, ?float $lng): array {
    if (!$site || $lat === null || $lng === null || $site['pt_latitude'] === null) return [null, false];
    $d = ptDistanceM($lat, $lng, (float)$site['pt_latitude'], (float)$site['pt_longitude']);
    return [$d, $d > (int)($site['pt_rayon_m'] ?: 200)];
}

function ptFormatDistance(?int $m): string {
    if ($m === null) return '?';
    return $m >= 1000 ? number_format($m / 1000, 1, ',', ' ') . ' km' : $m . ' m';
}

function ptErr(int $http, string $message, array $extra = []): array {
    return ['http' => $http, 'ok' => false, 'message' => $message] + $extra;
}

/** Libellés lisibles des alertes. */
function ptLibelleAlerte(string $a): string {
    $l = [
        'sans_gps' => 'Sans position', 'hors_zone' => 'Hors zone', 'retard' => 'Retard', 'avance' => 'En avance',
        'hors_ligne' => 'Hors connexion', 'fin_sans_gps' => 'Fin sans position', 'fin_hors_zone' => 'Fin hors zone',
        'depart_anticipe' => 'Départ anticipé', 'depassement' => 'Dépassement', 'manuel' => 'Saisie manuelle',
    ];
    return $l[$a] ?? $a;
}
