<?php
/**
 * Point d'entrée commun de l'outil Pointage.
 * Outil séparé du back-office : session propre, URL propre, mise en page propre.
 * Il partage seulement la base de données (agents, missions, planning).
 */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/metier.php';
require_once __DIR__ . '/agent_auth.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/pointer.php';
require_once __DIR__ . '/rondes.php';
require_once __DIR__ . '/inscriptions.php';
require_once __DIR__ . '/taches.php';

ptRedirectionCanonique();

if (session_status() === PHP_SESSION_NONE) {
    session_name('ov_pointage');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => ptIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');   // jamais de page personnelle dans un cache partagé
// Origine seule vers l'extérieur (jamais l'URL complète, qui peut contenir un jeton) ;
// requise par les tuiles OpenStreetMap.
header('Referrer-Policy: strict-origin-when-cross-origin');

ensurePointageSchema();
ptTachesSiBesoin();

function ptIsHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/**
 * URL de base de l'outil :
 * - constante POINTAGE_URL si définie dans config.php,
 * - sinon déduite de la requête (hôte + chemin du dossier de l'outil),
 *   ce qui marche aussi bien sur pointage.oeilvigilant.com qu'en local.
 */
function ptBaseUrl(): string {
    static $base = null;
    if ($base !== null) return $base;
    if (defined('POINTAGE_URL')) return $base = rtrim(POINTAGE_URL, '/');

    $rel  = ptScriptRelatif();
    $name = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $path = ($rel !== '' && substr($name, -strlen($rel)) === $rel) ? substr($name, 0, -strlen($rel)) : '';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $base = (ptIsHttps() ? 'https://' : 'http://') . $host . rtrim($path, '/');
}

/** Chemin du script courant relatif au dossier de l'outil (ex. « /admin/agents.php »). */
function ptScriptRelatif(): string {
    $root   = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
    $script = str_replace('\\', '/', (string)realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
    if ($root === '' || $script === '' || strpos($script, $root) !== 0) return '';
    return substr($script, strlen($root));
}

/**
 * L'adresse officielle est https://pointage.oeilvigilant.com.
 * Toute autre adresse publique d'Oeil Vigilant (ex. l'ancienne
 * oeilvigilant.com/planning/pointage/) y est redirigée en conservant la page
 * et ses paramètres, pour que les liens déjà envoyés restent valables.
 * Le développement local (localhost) n'est pas concerné.
 */
function ptRedirectionCanonique(): void {
    $canon = 'pointage.oeilvigilant.com';
    $host  = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || $host === $canon || !preg_match('/(^|\.)oeilvigilant\.com$/', $host)) return;
    $rel = ptScriptRelatif();
    if ($rel === '') return;
    $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    header('Location: https://' . $canon . $rel . ($qs !== '' ? '?' . $qs : ''), true, $method === 'GET' ? 301 : 307);
    exit;
}

function ptUrl(string $path = ''): string {
    return ptBaseUrl() . '/' . ltrim($path, '/');
}

function ptRedirect(string $path): void {
    header('Location: ' . ptUrl($path));
    exit;
}

function ptJson(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function ptCsrfToken(): string {
    if (empty($_SESSION['pt_csrf'])) $_SESSION['pt_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['pt_csrf'];
}

function ptCsrfField(): string {
    return '<input type="hidden" name="csrf" value="' . h(ptCsrfToken()) . '">';
}

function ptCsrfCheck(): bool {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(ptCsrfToken(), $sent);
}

function ptClientIp(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function ptUserAgent(): string {
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/** Mise en forme minimale et sûre : ## titres, - listes, **gras**. */
function ptTexteRiche(string $txt): string {
    $html = '';
    $inList = false;
    foreach (preg_split('/\r\n|\r|\n/', $txt) as $line) {
        $line = rtrim($line);
        $esc = h($line);
        $esc = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $esc);
        if (strpos($line, '- ') === 0) {
            if (!$inList) { $html .= '<ul>'; $inList = true; }
            $html .= '<li>' . substr($esc, 2) . '</li>';
            continue;
        }
        if ($inList) { $html .= '</ul>'; $inList = false; }
        if ($line === '') continue;
        if (strpos($line, '## ') === 0) {
            $html .= '<h3>' . substr($esc, 3) . '</h3>';
        } else {
            $html .= '<p>' . $esc . '</p>';
        }
    }
    if ($inList) $html .= '</ul>';
    return $html;
}

/** Flash propre à l'outil (n'utilise pas la session du back-office). */
function ptFlash(string $type, string $msg): void {
    $_SESSION['pt_flash'][] = ['type' => $type, 'message' => $msg];
}

function ptGetFlash(): array {
    $f = $_SESSION['pt_flash'] ?? [];
    unset($_SESSION['pt_flash']);
    return $f;
}
