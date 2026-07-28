<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_inscription_common.php';
ensureInscriptionSchema();

$db     = getDB();
$params = getAllParams();
$errors = [];
$success = false;
$data    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';

    $limitParJour = max(1, (int)getParam('inscription_rate_limit_par_jour', '3'));
    $stC = $db->prepare("SELECT COUNT(*) FROM agents WHERE ip_inscription = ? AND created_at >= NOW() - INTERVAL 1 DAY");
    $stC->execute([$ip]);
    $rateLimitExceeded = $ip !== '' && (int)$stC->fetchColumn() >= $limitParJour;

    $result = handleInscriptionSubmit($db, $_POST, $_FILES, null, $ip, $rateLimitExceeded);
    if ($result['ok']) {
        $success = true;
    } else {
        $errors = $result['errors'];
    }
}

if ($success) {
    renderInscriptionSuccessPage($params);
} else {
    renderInscriptionForm($data, $errors, $params);
}
