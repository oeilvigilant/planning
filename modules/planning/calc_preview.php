<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
header('Content-Type: application/json');

$date  = $_GET['date']  ?? '';
$debut = $_GET['debut'] ?? '';
$fin   = $_GET['fin']   ?? '';

if (!$date || !$debut || !$fin) {
    echo json_encode([]);
    exit;
}

echo json_encode(calculerHeuresParType($date, $debut, $fin));
