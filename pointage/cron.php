<?php
/**
 * Point d'entrée des tâches planifiées.
 * À appeler toutes les 5 minutes : https://pointage.oeilvigilant.com/cron.php?cle=XXXX
 * (la clé est affichée dans Paramètres de l'espace responsable).
 */
require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
$cle = (string)($_GET['cle'] ?? (PHP_SAPI === 'cli' ? ($argv[1] ?? '') : ''));
if ($cle === '' || !hash_equals(getParam('pointage_cron_cle', ''), $cle)) {
    http_response_code(403);
    exit("Clé invalide.\n");
}
$log = ptExecuterTaches();
echo date('Y-m-d H:i:s') . " — " . (count($log) ? implode("\n", $log) : 'rien à faire') . "\n";
