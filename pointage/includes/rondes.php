<?php
/**
 * Rondes : chaque site a des points de contrôle (étiquette QR code collée sur place).
 * L'agent scanne chaque point pendant sa ronde ; si une fréquence est réglée sur le
 * site (ex. toutes les 2 h), un retard de ronde déclenche un rappel puis une alerte.
 */

function ptPointsSite(int $missionId, bool $actifsSeulement = true): array {
    $st = getDB()->prepare("SELECT * FROM pt_points WHERE mission_id = ?" . ($actifsSeulement ? " AND actif = 1" : "") . " ORDER BY ordre, nom");
    $st->execute([$missionId]);
    return $st->fetchAll();
}

function ptGenererCodePoint(): string {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // sans 0/O/1/I : lisible si saisi à la main
    $c = '';
    for ($i = 0; $i < 16; $i++) $c .= $alpha[random_int(0, strlen($alpha) - 1)];
    return $c;
}

/** URL encodée dans le QR code : marche aussi avec l'appareil photo du téléphone. */
function ptUrlPoint(string $code): string {
    return ptUrl('p.php?c=' . $code);
}

/**
 * Enregistre le passage d'un agent sur un point (code scanné).
 * $p : code, uuid, lat, lng, precision, hors_ligne, ts_appareil (ms), note.
 */
function ptEnregistrerPassage(array $agent, array $p, ?int $now = null): array {
    $db = getDB();
    $now = $now ?? time();
    $agentId = (int)$agent['id'];
    $code = strtoupper(trim((string)($p['code'] ?? '')));
    // Le QR contient une URL : on n'en garde que le code
    if (preg_match('/[?&]C=([A-Z0-9]{16})/', $code, $m)) $code = $m[1];
    if (!preg_match('/^[A-Z0-9]{16}$/', $code)) return ptErr(400, 'Ce QR code n\'est pas un point de ronde.');
    $uuid = (string)($p['uuid'] ?? '');
    if ($uuid !== '' && !preg_match('/^[0-9a-f-]{36}$/i', $uuid)) $uuid = '';

    if ($uuid !== '') {
        $st = $db->prepare("SELECT id FROM pt_passages WHERE uuid = ? AND agent_id = ?");
        $st->execute([$uuid, $agentId]);
        if ($st->fetchColumn()) return ['http' => 200, 'ok' => true, 'deja' => true, 'message' => 'Passage déjà enregistré.'];
    }

    $st = $db->prepare("SELECT pt.*, m.nom AS site_nom, m.actif AS site_actif FROM pt_points pt JOIN missions m ON m.id = pt.mission_id WHERE pt.code = ?");
    $st->execute([$code]);
    $pt = $st->fetch();
    if (!$pt || !(int)$pt['actif'] || !(int)$pt['site_actif']) return ptErr(404, 'Point de ronde inconnu ou désactivé.');

    $ids = array_map('intval', array_column(ptSitesAgent($agentId), 'id'));
    $ouvert = ptServiceOuvert($agentId);
    if (!in_array((int)$pt['mission_id'], $ids, true) && !($ouvert && (int)$ouvert['mission_id'] === (int)$pt['mission_id'])) {
        return ptErr(403, 'Ce point appartient au site « ' . $pt['site_nom'] . ' », où vous n\'êtes pas affecté.');
    }

    $ts = $now;
    if (!empty($p['hors_ligne'])) {
        $tsApp = (int)round(((float)($p['ts_appareil'] ?? 0)) / 1000);
        if ($tsApp > $now - 48 * 3600 && $tsApp <= $now + 120) $ts = min($tsApp, $now);
    }
    // Anti double-scan : même point par le même agent il y a moins de 2 minutes
    $st = $db->prepare("SELECT COUNT(*) FROM pt_passages WHERE point_id = ? AND agent_id = ? AND scanne_le > ? AND scanne_le <= ?");
    $st->execute([$pt['id'], $agentId, date('Y-m-d H:i:s', $ts - 120), date('Y-m-d H:i:s', $ts)]);
    if ($st->fetchColumn()) return ['http' => 200, 'ok' => true, 'deja' => true, 'message' => 'Point « ' . $pt['nom'] . ' » déjà scanné à l\'instant.'];

    $lat = isset($p['lat']) && is_numeric($p['lat']) ? (float)$p['lat'] : null;
    $lng = isset($p['lng']) && is_numeric($p['lng']) ? (float)$p['lng'] : null;
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) $lat = $lng = null;

    $db->prepare("INSERT INTO pt_passages (uuid, point_id, mission_id, agent_id, service_id, scanne_le, lat, lng, precision_m, hors_ligne, note)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)")
       ->execute([$uuid ?: null, $pt['id'], $pt['mission_id'], $agentId,
                  ($ouvert && (int)$ouvert['mission_id'] === (int)$pt['mission_id']) ? $ouvert['id'] : null,
                  date('Y-m-d H:i:s', $ts), $lat, $lng,
                  isset($p['precision']) && is_numeric($p['precision']) ? (int)round($p['precision']) : null,
                  !empty($p['hors_ligne']) ? 1 : 0, mb_substr(trim((string)($p['note'] ?? '')), 0, 255) ?: null]);

    $etat = ptEtatRonde((int)$pt['mission_id'], $agentId, $ts);
    $msg = '✔ ' . $pt['nom'] . ' — ' . date('H\hi', $ts) . '. ';
    $complete = $etat['derniere_complete'] && strtotime($etat['derniere_complete']) === strtotime(date('Y-m-d H:i:s', $ts));
    $msg .= !$complete && $etat['restants'] ? $etat['faits'] . '/' . $etat['total'] . ' points, reste : ' . implode(', ', array_slice($etat['restants'], 0, 3)) . (count($etat['restants']) > 3 ? '…' : '') . '.'
                              : 'Ronde complète (' . $etat['total'] . '/' . $etat['total'] . ') !';
    return ['http' => 200, 'ok' => true, 'message' => $msg, 'point' => $pt['nom'], 'consigne' => (string)$pt['consigne'], 'etat' => $etat];
}

/**
 * État de la ronde en cours d'un agent sur un site : une nouvelle ronde commence
 * après chaque ronde complète ; on regarde les 6 dernières heures.
 */
function ptEtatRonde(int $missionId, int $agentId, ?int $now = null): array {
    $now = $now ?? time();
    $points = ptPointsSite($missionId);
    $idsPoints = array_map('intval', array_column($points, 'id'));
    $total = count($points);
    $st = getDB()->prepare("SELECT point_id, scanne_le FROM pt_passages WHERE mission_id = ? AND agent_id = ? AND scanne_le BETWEEN ? AND ?
        ORDER BY scanne_le");
    $st->execute([$missionId, $agentId, date('Y-m-d H:i:s', $now - 6 * 3600), date('Y-m-d H:i:s', $now)]);
    $vus = []; $dernier = null; $derniereComplete = null;
    foreach ($st->fetchAll() as $r) {
        $vus[(int)$r['point_id']] = $r['scanne_le'];
        $dernier = $r['scanne_le'];
        if ($total && count(array_intersect(array_keys($vus), $idsPoints)) >= $total) {
            $derniereComplete = $r['scanne_le'];
            $vus = [];
        }
    }
    $restants = [];
    foreach ($points as $pt) if (!isset($vus[(int)$pt['id']])) $restants[] = $pt['nom'];
    return ['total' => $total, 'faits' => $total - count($restants), 'restants' => $restants, 'vus' => $vus,
            'dernier' => $dernier, 'derniere_complete' => $derniereComplete];
}
