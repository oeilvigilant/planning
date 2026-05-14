<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
header('Content-Type: application/json');

$db = getDB();
$versionId = (int)($_GET['version_id'] ?? 0);
$agentId   = (int)($_GET['agent_id']   ?? 0);
$date      = $_GET['date'] ?? '';

$stmt = $db->prepare("SELECT * FROM planning_lignes WHERE version_id=? AND agent_id=? AND date_travail=?");
$stmt->execute([$versionId, $agentId, $date]);
$ligne = $stmt->fetch();

echo json_encode(['ligne' => $ligne ?: null]);
