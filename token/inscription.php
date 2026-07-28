<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/_inscription_common.php';
ensureInscriptionSchema();

$db    = getDB();
$token = trim($_GET['t'] ?? '');
if (!$token) { renderInscriptionInvalidPage(); exit; }

$stmt = $db->prepare("SELECT * FROM invitations_recrutement WHERE token = ? AND used = 0 AND expires_at > NOW()");
$stmt->execute([$token]);
$invitation = $stmt->fetch();

if (!$invitation) { renderInscriptionInvalidPage(); exit; }

$params  = getAllParams();
$errors  = [];
$success = false;
$data    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = $_POST;
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $result = handleInscriptionSubmit($db, $_POST, $_FILES, $invitation, $ip, false);
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
