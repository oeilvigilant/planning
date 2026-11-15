<?php
/**
 * Notifications : Web Push (RFC 8291 aes128gcm + VAPID RFC 8292, sans bibliothèque
 * externe, OpenSSL uniquement) et emails (mail()).
 */

/** Configuration OpenSSL pour les clés EC (nécessaire sous Windows/WAMP). */
function ptOpensslEcCfg(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
    if (@openssl_pkey_new($cfg)) return $cfg;
    $candidats = [getenv('OPENSSL_CONF') ?: '', dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
                  'C:/wamp64/bin/php/php' . PHP_VERSION . '/extras/ssl/openssl.cnf'];
    foreach ($candidats as $c) {
        if ($c && is_file($c) && @openssl_pkey_new($cfg + ['config' => $c])) return $cfg = $cfg + ['config' => $c];
    }
    return $cfg;
}

function ptB64uEnc(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function ptB64uDec(string $s): string { return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

/** Point public non compressé (65 octets) d'une clé EC. */
function ptEcPoint($key): string {
    $d = openssl_pkey_get_details($key);
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

/** Clé publique OpenSSL à partir d'un point P-256 brut (65 octets). */
function ptEcPublicFromPoint(string $point) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/** Clés VAPID : générées une seule fois puis stockées. Retourne [clé privée, clé publique base64url]. */
function ptVapid(): array {
    $priv = getParam('pointage_vapid_private', '');
    $pub = getParam('pointage_vapid_public', '');
    if ($priv === '' || $pub === '') {
        $cfg = ptOpensslEcCfg();
        $k = openssl_pkey_new($cfg);
        if (!$k) return [null, ''];
        openssl_pkey_export($k, $priv, null, $cfg);
        $pub = ptB64uEnc(ptEcPoint($k));
        setParam('pointage_vapid_private', $priv);
        setParam('pointage_vapid_public', $pub);
    }
    return [openssl_pkey_get_private($priv), $pub];
}

function ptHkdf(string $salt, string $ikm, string $info, int $len): string {
    $prk = hash_hmac('sha256', $ikm, $salt, true);
    return substr(hash_hmac('sha256', $info . "\x01", $prk, true), 0, $len);
}

/** Signature DER ECDSA -> format brut r||s (64 octets) pour JWT ES256. */
function ptDerToRaw(string $der): string {
    $off = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0);
    $rLen = ord($der[$off + 1]); $r = substr($der, $off + 2, $rLen);
    $off += 2 + $rLen;
    $sLen = ord($der[$off + 1]); $s = substr($der, $off + 2, $sLen);
    return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
}

/**
 * Envoie une notification push chiffrée à un abonnement.
 * Retourne le code HTTP (201 = OK, 404/410 = abonnement expiré), 0 si échec local.
 */
function ptWebPush(array $sub, array $payload, bool $urgent = false): int {
    $req = ptWebPushRequete($sub, $payload, $urgent);
    if (!$req) return 0;
    [$body, $headers] = $req;
    if (function_exists('curl_init')) {
        $ch = curl_init($sub['endpoint']);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
        'timeout' => 8, 'ignore_errors' => true]]);
    @file_get_contents($sub['endpoint'], false, $ctx);
    return isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
}

/** Construit la requête push chiffrée : [corps binaire, en-têtes HTTP] ou null. */
function ptWebPushRequete(array $sub, array $payload, bool $urgent = false): ?array {
    [$vapidKey, $vapidPub] = ptVapid();
    if (!$vapidKey) return null;
    $uaPub = ptB64uDec($sub['p256dh']);
    $authSecret = ptB64uDec($sub['auth']);
    if (strlen($uaPub) !== 65 || strlen($authSecret) < 16) return null;

    // Chiffrement aes128gcm (RFC 8291)
    $cfg = ptOpensslEcCfg();
    $eph = openssl_pkey_new($cfg);
    $asPub = ptEcPoint($eph);
    $shared = openssl_pkey_derive(ptEcPublicFromPoint($uaPub), $eph, 32);
    if (!$shared) return null;
    $ikm = ptHkdf($authSecret, $shared, "WebPush: info\0" . $uaPub . $asPub, 32);
    $salt = random_bytes(16);
    $cek = ptHkdf($salt, $ikm, "Content-Encoding: aes128gcm\0", 16);
    $nonce = ptHkdf($salt, $ikm, "Content-Encoding: nonce\0", 12);
    $tag = '';
    $ct = openssl_encrypt(json_encode($payload, JSON_UNESCAPED_UNICODE) . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    $body = $salt . pack('N', 4096) . chr(65) . $asPub . $ct . $tag;

    // En-tête VAPID (JWT ES256)
    $p = parse_url($sub['endpoint']);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $contact = trim(explode(',', getParam('pointage_alerte_emails', ''))[0]);
    if (!filter_var($contact, FILTER_VALIDATE_EMAIL)) $contact = 'contact@oeilvigilant.com';
    $jwtHead = ptB64uEnc('{"typ":"JWT","alg":"ES256"}');
    $jwtBody = ptB64uEnc(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . $contact]));
    openssl_sign($jwtHead . '.' . $jwtBody, $der, $vapidKey, OPENSSL_ALGO_SHA256);
    $jwt = $jwtHead . '.' . $jwtBody . '.' . ptB64uEnc(ptDerToRaw($der));

    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: ' . ($urgent ? 600 : 3600),
        'Urgency: ' . ($urgent ? 'high' : 'normal'),
        'Authorization: vapid t=' . $jwt . ', k=' . $vapidPub,
    ];
    return [$body, $headers];
}

/** Envoie à une liste d'abonnements ; supprime ceux qui ont expiré. Retourne le nombre d'envois réussis. */
function ptPushAbonnements(array $subs, array $payload, bool $urgent = false): int {
    $ok = 0;
    foreach ($subs as $s) {
        $code = ptWebPush($s, $payload, $urgent);
        if ($code >= 200 && $code < 300) $ok++;
        elseif ($code === 404 || $code === 410) getDB()->prepare("DELETE FROM pt_push WHERE id = ?")->execute([$s['id']]);
    }
    return $ok;
}

function ptNotifierAgent(int $agentId, string $titre, string $corps, string $url = 'app.php', bool $urgent = false): int {
    $st = getDB()->prepare("SELECT * FROM pt_push WHERE agent_id = ?");
    $st->execute([$agentId]);
    return ptPushAbonnements($st->fetchAll(), ['title' => $titre, 'body' => $corps, 'url' => ptUrl($url), 'tag' => 'ovpt-agent'], $urgent);
}

/**
 * Prévient les responsables : push sur leurs appareils + email aux adresses d'alerte
 * (si $email). Retourne le nombre d'envois push réussis.
 */
function ptNotifierResponsables(string $titre, string $corps, string $url = 'admin/index.php', bool $urgent = false, bool $email = false): int {
    $subs = getDB()->query("SELECT * FROM pt_push WHERE user_id IS NOT NULL")->fetchAll();
    $n = ptPushAbonnements($subs, ['title' => $titre, 'body' => $corps, 'url' => ptUrl($url),
        'tag' => $urgent ? 'ovpt-urgent-' . time() : 'ovpt-resp', 'urgent' => $urgent], $urgent);
    if ($email) {
        foreach (array_map('trim', explode(',', getParam('pointage_alerte_emails', ''))) as $to) {
            if (filter_var($to, FILTER_VALIDATE_EMAIL)) ptMail($to, $titre, $corps . "\n\n" . ptUrl($url));
        }
    }
    return $n;
}

/** Email texte simple (mail() de l'hébergeur). */
function ptMail(string $to, string $sujet, string $texte): bool {
    $host = (string)parse_url(ptBaseUrl(), PHP_URL_HOST);
    $from = getParam('pointage_email_expediteur', '') ?: 'no-reply@' . preg_replace('/^(www|pointage)\./', '', $host ?: 'localhost');
    $headers = "From: " . ptMarque() . " <$from>\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit";
    return @mail($to, '=?UTF-8?B?' . base64_encode($sujet) . '?=', $texte, $headers);
}

/** Exécute une action une seule fois pour une clé donnée (anti-doublon des rappels). */
function ptUneFois(string $cle): bool {
    try {
        getDB()->prepare("INSERT INTO pt_notifs (cle) VALUES (?)")->execute([substr($cle, 0, 150)]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}
