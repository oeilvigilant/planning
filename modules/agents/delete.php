<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}

$db     = getDB();
$id     = (int)($_POST['id'] ?? 0);
$action = $_POST['action'] ?? '';

if (!$id || !in_array($action, ['deactivate', 'hard_delete'])) {
    header('Location: index.php'); exit;
}

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$agent = $stmt->fetch();
if (!$agent) {
    flash('danger', 'Agent introuvable.');
    header('Location: index.php'); exit;
}

if ($action === 'deactivate') {
    $db->prepare("UPDATE agents SET actif = 0 WHERE id = ?")->execute([$id]);
    flash('success', h($agent['prenom'].' '.$agent['nom']).' a été désactivé.');
    header('Location: view.php?id='.$id); exit;
}

// hard_delete

// Suppression des fichiers physiques
$docs = $db->prepare("SELECT chemin FROM agent_documents WHERE agent_id = ?");
$docs->execute([$id]);
foreach ($docs->fetchAll() as $doc) {
    @unlink(UPLOAD_PATH . '/' . $doc['chemin']);
}
if ($agent['photo']) {
    @unlink(UPLOAD_PATH . '/' . $agent['photo']);
}

// Suppression en cascade
$db->prepare("DELETE FROM agent_documents    WHERE agent_id = ?")->execute([$id]);
$db->prepare("DELETE FROM planning_lignes    WHERE agent_id = ?")->execute([$id]);
$db->prepare("DELETE FROM agent_valeurs_perso WHERE agent_id = ?")->execute([$id]);
$db->prepare("DELETE FROM agents             WHERE id = ?")->execute([$id]);

flash('success', 'Agent '.$agent['prenom'].' '.$agent['nom'].' supprimé définitivement.');
header('Location: index.php'); exit;
