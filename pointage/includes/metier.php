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
    if (!ptHasPlanning()) return [];
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

/** Nom de la société affiché dans l'outil (marque blanche). */
function ptMarque(): string {
    $m = trim(getParam('pointage_marque', ''));
    if ($m === '') $m = trim(getParam('entreprise_nom', ''));
    return $m !== '' ? $m : 'Pointage';
}

/** Sites actifs auxquels l'agent est affecté. */
function ptSitesAgent(int $agentId): array {
    $st = getDB()->prepare("SELECT m.id, m.nom, m.lieu, m.pt_adresse, m.pt_latitude, m.pt_longitude, m.pt_rayon_m,
            m.pt_responsable_nom, m.pt_responsable_tel, m.pt_responsable_whatsapp, m.pt_consignes, c.nom AS client_nom
        FROM mission_agents ma
        JOIN missions m ON m.id = ma.mission_id AND m.actif = 1
        LEFT JOIN clients c ON c.id = m.client_id
        WHERE ma.agent_id = ?
        ORDER BY m.nom");
    $st->execute([$agentId]);
    return $st->fetchAll();
}

function ptSite(int $missionId): ?array {
    $st = getDB()->prepare("SELECT m.*, c.nom AS client_nom FROM missions m LEFT JOIN clients c ON c.id = m.client_id WHERE m.id = ?");
    $st->execute([$missionId]);
    return $st->fetch() ?: null;
}

/** Service en cours (prise sans fin) d'un agent, ou null. */
function ptServiceOuvert(int $agentId): ?array {
    $st = getDB()->prepare("SELECT s.*, m.nom AS mission_nom FROM pt_services s JOIN missions m ON m.id = s.mission_id
        WHERE s.agent_id = ? AND s.fin_retenu IS NULL AND s.statut <> 'annule'
        ORDER BY s.debut_reel DESC LIMIT 1");
    $st->execute([$agentId]);
    return $st->fetch() ?: null;
}

/** Minutes retenues d'un service (0 si en cours). */
function ptDureeMin(array $s): int {
    if (empty($s['fin_retenu'])) return 0;
    return max(0, (int)round((strtotime($s['fin_retenu']) - strtotime($s['debut_retenu'])) / 60));
}

function ptFormatDuree(int $min): string {
    return sprintf('%dh%02d', intdiv($min, 60), $min % 60);
}

/**
 * Applique la tolérance : si l'heure réelle est à ± tolérance de l'heure prévue,
 * on retient l'heure prévue. Retourne [heure retenue, écart en minutes (réel - prévu) ou null].
 */
function ptAppliquerTolerance(string $reel, ?string $prevu): array {
    $reel = date('Y-m-d H:i:00', strtotime($reel));
    if (!$prevu) return [$reel, null];
    $ecart = (int)round((strtotime($reel) - strtotime($prevu)) / 60);
    $tol = max(0, (int)getParam('pointage_tolerance_min', '5'));
    return [abs($ecart) <= $tol ? date('Y-m-d H:i:00', strtotime($prevu)) : $reel, $ecart];
}

/** Vacation planifiée correspondant à une prise de service (même site, fenêtre -3 h / +fin). */
function ptVacationPourPrise(int $agentId, int $missionId, int $ts): ?array {
    foreach (ptVacations($agentId, date('Y-m-d', $ts - 86400), date('Y-m-d', $ts + 86400)) as $v) {
        if ((int)$v['mission_id'] !== $missionId) continue;
        if ($ts >= strtotime($v['debut_dt']) - 3 * 3600 && $ts <= strtotime($v['fin_dt'])) return $v;
    }
    return null;
}

/**
 * Enregistre une photo envoyée en data URL JPEG/PNG. Retourne le chemin relatif
 * (dans uploads/) ou null. Les photos ne sont jamais servies directement.
 */
function ptEnregistrerPhoto(string $dataUrl, string $prefixe): ?string {
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,#', $dataUrl, $m)) return null;
    $bin = base64_decode(substr($dataUrl, strpos($dataUrl, ',') + 1), true);
    if ($bin === false || strlen($bin) > 3 * 1024 * 1024) return null;
    $info = @getimagesizefromstring($bin);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) return null;
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2]];
    $rel = date('Y/m') . '/' . $prefixe . '-' . bin2hex(random_bytes(10)) . '.' . $ext;
    $abs = ptUploadsDir() . '/' . $rel;
    if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0755, true)) return null;
    return file_put_contents($abs, $bin) !== false ? $rel : null;
}

/**
 * Dossier des photos, créé à la demande avec son .htaccess de blocage
 * (il n'est pas déployé par FTP : il ne contient que des données).
 */
function ptUploadsDir(): string {
    $dir = dirname(__DIR__) . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ht = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($ht)) {
        @file_put_contents($ht, "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n");
    }
    return $dir;
}

/** Matricule au format AA + sexe + numéro (ex. 26F03), compatible avec OV-Gestion. */
function ptGenererMatricule(string $sexe): string {
    $pref = sprintf('%02d%s', (int)date('y'), $sexe === 'F' ? 'F' : 'M');
    $st = getDB()->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(matricule, ?) AS UNSIGNED)), 0) FROM agents
        WHERE matricule LIKE ? AND matricule REGEXP ?");
    $st->execute([strlen($pref) + 1, $pref . '%', '^' . $pref . '[0-9]+$']);
    return $pref . sprintf('%02d', (int)$st->fetchColumn() + 1);
}

/** Journalise une correction (la donnée d'origine reste dans pt_services.*_reel). */
function ptLogCorrection(int $serviceId, string $action, $avant, $apres, string $motif, ?array $user): void {
    getDB()->prepare("INSERT INTO pt_corrections (service_id, action, avant, apres, motif, user_id, user_nom) VALUES (?,?,?,?,?,?,?)")
        ->execute([$serviceId, $action,
            $avant === null ? null : json_encode($avant, JSON_UNESCAPED_UNICODE),
            $apres === null ? null : json_encode($apres, JSON_UNESCAPED_UNICODE),
            $motif, $user['id'] ?? null, $user ? trim($user['prenom'] . ' ' . $user['nom']) : null]);
}

/** Numéro au format international pour wa.me (France par défaut). */
function ptTelWhatsapp(string $tel): string {
    $d = preg_replace('/\D+/', '', $tel);
    if (strpos($d, '0') === 0 && strlen($d) === 10) $d = '33' . substr($d, 1);
    return $d;
}
