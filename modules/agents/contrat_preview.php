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

header('Content-Type: text/html; charset=UTF-8');
$html = buildContratHtml($data, $params, $a);
echo $html;
