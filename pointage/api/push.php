<?php
/**
 * API JSON : abonnement aux notifications push.
 * GET  -> clé publique VAPID
 * POST {subscription, qui:'agent'|'responsable', action:'abonner'|'desabonner'|'tester'}
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../admin/_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    [, $pub] = ptVapid();
    ptJson(['ok' => $pub !== '', 'publicKey' => $pub]);
}
if (($_SERVER['HTTP_X_OV_POINTAGE'] ?? '') !== '1') ptJson(['ok' => false, 'message' => 'Requête refusée.'], 403);

$p = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($p)) ptJson(['ok' => false, 'message' => 'Données illisibles.'], 400);

$qui = ($p['qui'] ?? 'agent') === 'responsable' ? 'responsable' : 'agent';
if ($qui === 'agent') {
    $agent = ptRequireAgentApi();
    $col = 'agent_id'; $id = (int)$agent['id'];
} else {
    $u = ptAdminUser();
    if (!$u) ptJson(['ok' => false, 'message' => 'Connexion responsable requise.'], 401);
    $col = 'user_id'; $id = (int)$u['id'];
}

$sub = $p['subscription'] ?? [];
$endpoint = (string)($sub['endpoint'] ?? '');
$k = $sub['keys'] ?? [];
if (!preg_match('#^https://[^\s]{10,590}$#', $endpoint) || empty($k['p256dh']) || empty($k['auth'])) {
    ptJson(['ok' => false, 'message' => 'Abonnement invalide.'], 400);
}
$db = getDB();
$action = $p['action'] ?? 'abonner';

if ($action === 'desabonner') {
    $db->prepare("DELETE FROM pt_push WHERE endpoint = ?")->execute([$endpoint]);
    ptJson(['ok' => true, 'message' => 'Notifications désactivées sur cet appareil.']);
}

$db->prepare("DELETE FROM pt_push WHERE endpoint = ?")->execute([$endpoint]);
$db->prepare("INSERT INTO pt_push ($col, endpoint, p256dh, auth, user_agent) VALUES (?,?,?,?,?)")
   ->execute([$id, $endpoint, (string)$k['p256dh'], (string)$k['auth'], ptUserAgent()]);
$subId = (int)$db->lastInsertId();

if ($action === 'tester' || $action === 'abonner') {
    $code = ptWebPush(['id' => $subId, 'endpoint' => $endpoint, 'p256dh' => (string)$k['p256dh'], 'auth' => (string)$k['auth']],
        ['title' => ptMarque(), 'body' => 'Notifications activées sur cet appareil ✔', 'url' => ptUrl($qui === 'agent' ? 'app.php' : 'admin/index.php'), 'tag' => 'ovpt-test']);
    ptJson(['ok' => $code >= 200 && $code < 300, 'code' => $code,
            'message' => $code >= 200 && $code < 300 ? 'Notifications activées.' : 'Abonnement enregistré, mais l\'envoi de test a échoué (code ' . $code . ').']);
}
ptJson(['ok' => true, 'message' => 'Notifications activées.']);
