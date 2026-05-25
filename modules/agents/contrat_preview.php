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
// Inject interactive checkboxes (web preview only — DomPDF ignores <script>)
$checkboxJs = '<script>document.addEventListener("DOMContentLoaded",function(){document.querySelectorAll("span").forEach(function(s){if(s.textContent.trim()==="[ ]"&&s.style.fontFamily&&s.style.fontFamily.indexOf("Courier")!==-1){var c=document.createElement("input");c.type="checkbox";c.style.cssText="width:14px;height:14px;vertical-align:middle;cursor:pointer;margin-right:6px";s.parentNode.replaceChild(c,s);}});});</script>';
echo str_replace('</body>', $checkboxJs . '</body>', $html);
