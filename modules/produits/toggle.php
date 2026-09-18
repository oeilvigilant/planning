<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('produits', 'edit');

$db   = getDB();
$id   = (int)($_POST['id'] ?? 0);
$back = $_POST['back'] ?? '';

if ($id) {
    $db->prepare("UPDATE produits SET actif = 1 - actif WHERE id = ?")->execute([$id]);
}

header('Location: index.php' . ($back ? '?' . $back : ''));
exit;
