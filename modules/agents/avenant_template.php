<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/contrat_builder.php';
requireLogin();
requirePerm('agents', 'view');

$type = $_GET['type'] ?? 'avenant';
if (!in_array($type, ['avenant', 'rupture', 'libre'], true)) $type = 'avenant';

header('Content-Type: text/html; charset=UTF-8');
echo avenantTemplateCorps($type);
