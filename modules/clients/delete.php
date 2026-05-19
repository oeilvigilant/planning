<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('clients', 'delete');

$db = getDB();
$id = (int)($_POST['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$client = $db->prepare("SELECT nom FROM clients WHERE id = ?");
$client->execute([$id]);
$client = $client->fetch();
if (!$client) { flash('danger', 'Client introuvable.'); header('Location: index.php'); exit; }

// Dissocier les devis liés (on ne supprime pas les devis, on retire juste le lien)
$db->prepare("UPDATE devis SET client_id = NULL WHERE client_id = ?")->execute([$id]);
$db->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);

flash('success', 'Client <strong>' . h($client['nom']) . '</strong> supprimé.');
header('Location: index.php');
exit;
