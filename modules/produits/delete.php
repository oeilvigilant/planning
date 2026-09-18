<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('produits', 'delete');

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT code, designation_courte FROM produits WHERE id = ?");
$stmt->execute([$id]);
$produit = $stmt->fetch();
if (!$produit) { flash('danger', 'Produit introuvable.'); header('Location: index.php'); exit; }

$db->prepare("DELETE FROM produits WHERE id = ?")->execute([$id]);

flash('success', 'Produit <strong>' . h($produit['code']) . '</strong> supprimé.');
header('Location: index.php');
exit;
