<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

header('Content-Type: application/json');

$sexe = $_GET['sexe'] ?? 'M';
echo json_encode(['matricule' => generateMatricule($sexe)]);
