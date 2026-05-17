<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'delete');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $db->prepare("SELECT numero FROM devis WHERE id = ?");
$stmt->execute([$id]);
$devis = $stmt->fetch();

if (!$devis) {
    flash('danger', 'Devis introuvable.');
    header('Location: index.php');
    exit;
}

// Suppression en cascade (devis_profils et devis_lignes via FK ON DELETE CASCADE)
$db->prepare("DELETE FROM devis WHERE id = ?")->execute([$id]);

flash('success', 'Devis <strong>' . h($devis['numero']) . '</strong> supprimé.');
header('Location: index.php');
exit;
