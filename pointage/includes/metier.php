<?php
/**
 * Logique métier de l'outil Pointage (sans HTML) : vacations planifiées,
 * distance GPS, tolérance.
 */

/** Distance en mètres entre deux points GPS (formule de haversine). */
function ptDistanceM(float $lat1, float $lng1, float $lat2, float $lng2): int {
    $r = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return (int)round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
}

/**
 * Vacations planifiées d'un agent entre deux dates (incluses), issues de la
 * version courante du planning de chaque mission. Chaque vacation porte ses
 * bornes réelles en datetime (fin le lendemain si dépassement de minuit).
 */
function ptVacations(int $agentId, string $dateDebut, string $dateFin): array {
    $st = getDB()->prepare("SELECT pl.id, pl.date_travail, pl.heure_debut, pl.heure_fin, pl.note,
            m.id AS mission_id, m.nom AS mission_nom, m.lieu, m.pt_adresse, m.pt_latitude, m.pt_longitude,
            m.pt_rayon_m, m.pt_responsable_nom, m.pt_responsable_tel, m.pt_responsable_whatsapp, m.pt_consignes,
            c.nom AS client_nom
        FROM planning_lignes pl
        JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
        JOIN missions m ON m.id = pv.mission_id
        LEFT JOIN clients c ON c.id = m.client_id
        WHERE pl.agent_id = ? AND pl.date_travail BETWEEN ? AND ?
        ORDER BY pl.date_travail, pl.heure_debut");
    $st->execute([$agentId, $dateDebut, $dateFin]);
    $out = [];
    foreach ($st->fetchAll() as $v) {
        $debut = $v['date_travail'] . ' ' . $v['heure_debut'];
        $finJour = $v['heure_fin'] <= $v['heure_debut']
            ? date('Y-m-d', strtotime($v['date_travail'] . ' +1 day'))
            : $v['date_travail'];
        $v['debut_dt'] = $debut;
        $v['fin_dt']   = $finJour . ' ' . $v['heure_fin'];
        $out[] = $v;
    }
    return $out;
}

/**
 * Vacation en cours ou la plus proche à venir dans les prochaines 24 h
 * (on regarde aussi la veille pour les vacations de nuit).
 */
function ptVacationCourante(int $agentId, ?int $now = null): ?array {
    $now = $now ?? time();
    $liste = ptVacations($agentId, date('Y-m-d', $now - 86400), date('Y-m-d', $now + 86400));
    $prochaine = null;
    foreach ($liste as $v) {
        $d = strtotime($v['debut_dt']);
        $f = strtotime($v['fin_dt']);
        if ($now >= $d - 3 * 3600 && $now <= $f + 3600) return $v;   // fenêtre : 3 h avant -> 1 h après
        if ($d > $now && ($prochaine === null || $d < strtotime($prochaine['debut_dt']))) $prochaine = $v;
    }
    return $prochaine;
}

/**
 * Contact à joindre pour une vacation : responsable du site s'il est renseigné,
 * sinon contact par défaut (permanence). WhatsApp = numéro dédié, sinon numéro d'appel.
 * Retourne ['nom'=>, 'tel'=>, 'whatsapp'=>] (chaînes éventuellement vides).
 */
function ptContact(?array $vac): array {
    $tel = trim((string)($vac['pt_responsable_tel'] ?? ''));
    $wa  = trim((string)($vac['pt_responsable_whatsapp'] ?? ''));
    $nom = trim((string)($vac['pt_responsable_nom'] ?? ''));
    if ($tel === '' && $wa === '') {
        $nom = getParam('pointage_contact_nom', 'Permanence');
        $tel = trim(getParam('pointage_contact_tel', ''));
        $wa  = trim(getParam('pointage_contact_whatsapp', ''));
    }
    return ['nom' => $nom ?: 'Responsable', 'tel' => $tel, 'whatsapp' => $wa !== '' ? $wa : $tel];
}

/** Numéro au format international pour wa.me (France par défaut). */
function ptTelWhatsapp(string $tel): string {
    $d = preg_replace('/\D+/', '', $tel);
    if (strpos($d, '0') === 0 && strlen($d) === 10) $d = '33' . substr($d, 1);
    return $d;
}
