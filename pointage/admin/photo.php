<?php
/** Affiche une photo de pointage, uniquement à un responsable connecté. */
require_once __DIR__ . '/_init.php';
ptRequireAdmin('view');

$f = (string)($_GET['f'] ?? '');
if (!preg_match('#^\d{4}/\d{2}/[a-z0-9-]+\.(jpg|png|webp)$#', $f)) { http_response_code(404); exit; }
$abs = ptUploadsDir() . '/' . $f;
if (!is_file($abs)) { http_response_code(404); exit('Photo introuvable.'); }

$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[pathinfo($f, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($abs));
header('Cache-Control: private, max-age=3600');
readfile($abs);
