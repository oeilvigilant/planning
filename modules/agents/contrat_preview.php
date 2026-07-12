<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/contrat_builder.php';
requireLogin();
requirePerm('agents', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(400); exit; }

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { http_response_code(404); exit; }

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) { http_response_code(400); exit; }

$params = getAllParams();

// Utiliser uniquement la signature du contrat courant (pas le résidu legacy dans agents)
$contratId = (int)($_GET['contrat_id'] ?? 0);
$aForPreview = $a;
if ($contratId > 0) {
    $stC = $db->prepare("SELECT signature, signature_date, signature_ip FROM contrats WHERE id=? AND agent_id=?");
    $stC->execute([$contratId, $id]);
    $ct = $stC->fetch();
    if ($ct && !empty($ct['signature'])) {
        $aForPreview['signature']      = $ct['signature'];
        $aForPreview['signature_date'] = $ct['signature_date'];
        $aForPreview['signature_ip']   = $ct['signature_ip'];
    } else {
        $aForPreview['signature']      = null;
        $aForPreview['signature_date'] = null;
        $aForPreview['signature_ip']   = null;
    }
} else {
    $aForPreview['signature']      = null;
    $aForPreview['signature_date'] = null;
    $aForPreview['signature_ip']   = null;
}

header('Content-Type: text/html; charset=UTF-8');
$html = buildContratHtml($data, $params, $aForPreview);
echo $html;
