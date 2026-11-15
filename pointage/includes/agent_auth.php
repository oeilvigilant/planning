<?php
/**
 * Authentification agent : lien d'activation personnel -> choix d'un PIN
 * -> connexion persistante par appareil (cookie selector:validator).
 */

const PT_DEVICE_COOKIE   = 'ovpt_dev';
const PT_DEVICE_DAYS     = 90;
const PT_PIN_MAX_ESSAIS  = 5;
const PT_PIN_BLOCAGE_MIN = 15;

function ptAgentId(): int {
    return (int)($_SESSION['pt_agent_id'] ?? 0);
}

/** Agent connecté (ligne agents + pt_acces), ou null. Tente la reconnexion par appareil. */
function ptCurrentAgent(): ?array {
    static $cache = null;
    if ($cache !== null) return $cache ?: null;

    if (!ptAgentId()) ptTryDeviceLogin();
    $id = ptAgentId();
    if (!$id) { $cache = false; return null; }

    $st = getDB()->prepare("SELECT a.id, a.nom, a.prenom, a.matricule, a.telephone, a.photo, a.actif,
            pa.rgpd_version, pa.pin_hash
        FROM agents a JOIN pt_acces pa ON pa.agent_id = a.id
        WHERE a.id = ?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row || !(int)$row['actif'] || empty($row['pin_hash'])) {
        ptAgentLogout(false);
        $cache = false;
        return null;
    }
    $cache = $row;
    return $row;
}

/** Page agent : exige une connexion et l'acceptation de la version RGPD en vigueur. */
function ptRequireAgent(bool $checkRgpd = true): array {
    $agent = ptCurrentAgent();
    if (!$agent) ptRedirect('login.php');
    if ($checkRgpd && (int)$agent['rgpd_version'] < ptRgpdVersion()) ptRedirect('rgpd.php');
    return $agent;
}

/** Variante API JSON. */
function ptRequireAgentApi(): array {
    $agent = ptCurrentAgent();
    if (!$agent) ptJson(['ok' => false, 'message' => 'Session expirée, reconnectez-vous.', 'relogin' => true], 401);
    if ((int)$agent['rgpd_version'] < ptRgpdVersion()) {
        ptJson(['ok' => false, 'message' => 'Veuillez lire et accepter l\'information RGPD.', 'rgpd' => true], 403);
    }
    return $agent;
}

function ptRgpdVersion(): int {
    return max(1, (int)getParam('pointage_rgpd_version', '1'));
}

function ptAgentLoginSession(int $agentId): void {
    session_regenerate_id(true);
    $_SESSION['pt_agent_id'] = $agentId;
    getDB()->prepare("UPDATE pt_acces SET derniere_connexion = NOW(), pin_essais = 0, bloque_jusqua = NULL WHERE agent_id = ?")
        ->execute([$agentId]);
}

function ptRememberDevice(int $agentId): void {
    $selector  = bin2hex(random_bytes(12));
    $validator = bin2hex(random_bytes(32));
    $expires   = date('Y-m-d H:i:s', strtotime('+' . PT_DEVICE_DAYS . ' days'));
    getDB()->prepare("INSERT INTO pt_appareils (agent_id, selector, validator_hash, user_agent, expires_at, last_used_at)
        VALUES (?,?,?,?,?,NOW())")
        ->execute([$agentId, $selector, hash('sha256', $validator), ptUserAgent(), $expires]);
    ptSetDeviceCookie($selector . ':' . $validator, time() + PT_DEVICE_DAYS * 86400);
}

function ptSetDeviceCookie(string $value, int $expires): void {
    setcookie(PT_DEVICE_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => ptIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function ptTryDeviceLogin(): void {
    $raw = $_COOKIE[PT_DEVICE_COOKIE] ?? '';
    if (!is_string($raw) || !preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) return;
    $db = getDB();
    $st = $db->prepare("SELECT id, agent_id, validator_hash FROM pt_appareils WHERE selector = ? AND expires_at > NOW()");
    $st->execute([$m[1]]);
    $dev = $st->fetch();
    if (!$dev || !hash_equals($dev['validator_hash'], hash('sha256', $m[2]))) {
        ptSetDeviceCookie('', time() - 3600);
        return;
    }
    $db->prepare("UPDATE pt_appareils SET last_used_at = NOW() WHERE id = ?")->execute([$dev['id']]);
    ptAgentLoginSession((int)$dev['agent_id']);
}

function ptAgentLogout(bool $forgetDevice = true): void {
    if ($forgetDevice) {
        $raw = $_COOKIE[PT_DEVICE_COOKIE] ?? '';
        if (is_string($raw) && preg_match('/^([a-f0-9]{24}):/', $raw, $m)) {
            getDB()->prepare("DELETE FROM pt_appareils WHERE selector = ?")->execute([$m[1]]);
        }
        ptSetDeviceCookie('', time() - 3600);
    }
    unset($_SESSION['pt_agent_id']);
}

/** Normalise un identifiant : matricule tel quel, ou téléphone réduit à ses chiffres (+33 -> 0). */
function ptNormaliserTel(string $tel): string {
    $d = preg_replace('/\D+/', '', $tel);
    if (strpos($d, '33') === 0 && strlen($d) === 11) $d = '0' . substr($d, 2);
    return $d;
}

/**
 * Connexion par identifiant (matricule ou téléphone) + PIN.
 * Retourne ['ok'=>bool, 'agent_id'=>int, 'error'=>string].
 */
function ptAgentLoginPin(string $identifiant, string $pin): array {
    $identifiant = trim($identifiant);
    $db = getDB();
    $erreur = ['ok' => false, 'agent_id' => 0, 'error' => 'Identifiant ou code PIN incorrect.'];
    if ($identifiant === '' || !preg_match('/^\d{4}$/', $pin)) return $erreur;

    $st = $db->prepare("SELECT a.id, a.telephone, a.matricule, pa.pin_hash, pa.pin_essais, pa.bloque_jusqua
        FROM agents a JOIN pt_acces pa ON pa.agent_id = a.id
        WHERE a.actif = 1 AND pa.pin_hash IS NOT NULL");
    $st->execute();
    $telCherche = ptNormaliserTel($identifiant);
    $cible = null;
    foreach ($st->fetchAll() as $row) {
        if (strcasecmp((string)$row['matricule'], $identifiant) === 0
            || ($telCherche !== '' && strlen($telCherche) >= 9 && ptNormaliserTel((string)$row['telephone']) === $telCherche)) {
            $cible = $row;
            break;
        }
    }
    if (!$cible) return $erreur;

    if ($cible['bloque_jusqua'] && strtotime($cible['bloque_jusqua']) > time()) {
        $min = (int)ceil((strtotime($cible['bloque_jusqua']) - time()) / 60);
        return ['ok' => false, 'agent_id' => 0, 'error' => "Trop d'essais. Réessayez dans $min min ou contactez votre responsable."];
    }

    if (!password_verify($pin, $cible['pin_hash'])) {
        $essais = (int)$cible['pin_essais'] + 1;
        if ($essais >= PT_PIN_MAX_ESSAIS) {
            $db->prepare("UPDATE pt_acces SET pin_essais = 0, bloque_jusqua = DATE_ADD(NOW(), INTERVAL " . PT_PIN_BLOCAGE_MIN . " MINUTE) WHERE agent_id = ?")
               ->execute([$cible['id']]);
            return ['ok' => false, 'agent_id' => 0, 'error' => 'Trop d\'essais. Compte bloqué ' . PT_PIN_BLOCAGE_MIN . ' min.'];
        }
        $db->prepare("UPDATE pt_acces SET pin_essais = ? WHERE agent_id = ?")->execute([$essais, $cible['id']]);
        $reste = PT_PIN_MAX_ESSAIS - $essais;
        return ['ok' => false, 'agent_id' => 0, 'error' => "Code PIN incorrect. $reste essai(s) restant(s)."];
    }

    return ['ok' => true, 'agent_id' => (int)$cible['id'], 'error' => ''];
}

/** Codes PIN refusés car trop faciles à deviner. */
function ptPinTropSimple(string $pin): bool {
    if (preg_match('/^(\d)\1{3}$/', $pin)) return true;               // 0000, 1111…
    return in_array($pin, ['1234', '4321', '0123', '9876', '1212', '2580', '1004', '2000'], true);
}

/** Génère (ou régénère) un lien d'activation pour un agent. Réinitialise PIN et appareils. */
function ptGenererActivation(int $agentId, int $jours = 7): string {
    $db = getDB();
    $token = generateToken();
    $exp = date('Y-m-d H:i:s', strtotime("+$jours days"));
    $db->prepare("INSERT INTO pt_acces (agent_id, activation_token, activation_expires, pin_hash, pin_essais, bloque_jusqua)
        VALUES (?,?,?,NULL,0,NULL)
        ON DUPLICATE KEY UPDATE activation_token = VALUES(activation_token), activation_expires = VALUES(activation_expires),
            pin_hash = NULL, pin_essais = 0, bloque_jusqua = NULL")
       ->execute([$agentId, $token, $exp]);
    $db->prepare("DELETE FROM pt_appareils WHERE agent_id = ?")->execute([$agentId]);
    return $token;
}
