<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('missions', 'delete');
ensureMissionsSchema();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}

$db     = getDB();
$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$id || !in_array($action, ['deactivate', 'reactivate', 'hard_delete'])) {
    header('Location: index.php'); exit;
}

$stmt = $db->prepare("SELECT * FROM missions WHERE id = ?");
$stmt->execute([$id]);
$mission = $stmt->fetch();
if (!$mission) {
    flash('danger', 'Mission introuvable.');
    header('Location: index.php'); exit;
}

if ($action === 'deactivate') {
    $db->prepare("UPDATE missions SET actif = 0 WHERE id = ?")->execute([$id]);
    flash('success', 'Mission <strong>' . h($mission['nom']) . '</strong> désactivée.');
    header('Location: index.php'); exit;
}

if ($action === 'reactivate') {
    $db->prepare("UPDATE missions SET actif = 1 WHERE id = ?")->execute([$id]);
    flash('success', 'Mission <strong>' . h($mission['nom']) . '</strong> réactivée.');
    header('Location: index.php'); exit;
}

// hard_delete — la mission par défaut ne peut jamais être supprimée définitivement
if ($mission['is_default']) {
    flash('danger', 'La mission par défaut ne peut pas être supprimée définitivement.');
    header('Location: index.php'); exit;
}

// hard_delete — bloqué si un planning existe déjà pour cette mission
$stmtV = $db->prepare("SELECT COUNT(*) FROM planning_versions WHERE mission_id = ?");
$stmtV->execute([$id]);
$nbVersions = (int)$stmtV->fetchColumn();

if ($nbVersions > 0) {
    flash('danger', 'Impossible de supprimer définitivement : cette mission a un historique de planning (' . $nbVersions . ' version(s)). Désactivez-la à la place.');
    header('Location: index.php'); exit;
}

$db->prepare("DELETE FROM mission_agents WHERE mission_id = ?")->execute([$id]);
$db->prepare("DELETE FROM missions WHERE id = ?")->execute([$id]);

flash('success', 'Mission ' . h($mission['nom']) . ' supprimée définitivement.');
header('Location: index.php'); exit;
