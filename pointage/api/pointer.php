<?php
/** API JSON : prise / fin de service. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/pointer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ptJson(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
// Protection CSRF : en-tête personnalisé impossible à envoyer depuis un autre site sans autorisation CORS
if (($_SERVER['HTTP_X_OV_POINTAGE'] ?? '') !== '1') ptJson(['ok' => false, 'message' => 'Requête refusée.'], 403);

$agent = ptRequireAgentApi();
$raw = file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024);
$p = json_decode((string)$raw, true);
if (!is_array($p)) ptJson(['ok' => false, 'message' => 'Données illisibles.'], 400);

try {
    $res = ptTraiterPointage($agent, $p);
} catch (Throwable $e) {
    error_log('OV Pointage — pointer : ' . $e->getMessage());
    ptJson(['ok' => false, 'message' => 'Erreur serveur, réessayez.'], 500);
}
$http = $res['http'];
unset($res['http']);
ptJson($res, $http);
