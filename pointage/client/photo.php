<?php
/** Photo d'incident pour le client : seulement si autorisée et rattachée à ses sites. */
require_once __DIR__ . '/_init.php';
$p = ptPortail();
$f = (string)($_GET['f'] ?? '');
if (!$p['voir_photos'] || !preg_match('#^\d{4}/\d{2}/ev[a-z0-9-]+\.(jpg|png|webp)$#', $f)) { http_response_code(404); exit; }
$st = getDB()->prepare("SELECT COUNT(*) FROM pt_evenements WHERE mission_id IN (" . ptInMissions($p) . ") AND visible_client = 1 AND photos LIKE ?");
$st->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], json_encode($f, JSON_UNESCAPED_SLASHES)) . '%']);
$abs = ptUploadsDir() . '/' . $f;
if (!$st->fetchColumn() || !is_file($abs)) { http_response_code(404); exit; }
header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($f, PATHINFO_EXTENSION)]);
header('Cache-Control: private, max-age=3600');
readfile($abs);
