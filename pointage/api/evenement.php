<?php
/** API JSON : signalement d'événement, SOS, main courante. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/pointer.php';
require_once __DIR__ . '/../includes/evenements.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ptJson(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
if (($_SERVER['HTTP_X_OV_POINTAGE'] ?? '') !== '1') ptJson(['ok' => false, 'message' => 'Requête refusée.'], 403);

$agent = ptRequireAgentApi();
$p = json_decode((string)file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024), true);
if (!is_array($p)) ptJson(['ok' => false, 'message' => 'Données illisibles.'], 400);

try {
    $res = ptEnregistrerEvenement($agent, $p);
} catch (Throwable $e) {
    error_log('OV Pointage — événement : ' . $e->getMessage());
    ptJson(['ok' => false, 'message' => 'Erreur serveur, réessayez.'], 500);
}
$http = $res['http'];
unset($res['http']);
ptJson($res, $http);
