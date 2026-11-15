<?php
/** API JSON : passage sur un point de ronde (QR code scanné). */
require_once __DIR__ . '/../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') ptJson(['ok' => false, 'message' => 'Méthode non autorisée.'], 405);
if (($_SERVER['HTTP_X_OV_POINTAGE'] ?? '') !== '1') ptJson(['ok' => false, 'message' => 'Requête refusée.'], 403);

$agent = ptRequireAgentApi();
$p = json_decode((string)file_get_contents('php://input', false, null, 0, 65536), true);
if (!is_array($p)) ptJson(['ok' => false, 'message' => 'Données illisibles.'], 400);

try {
    $res = ptEnregistrerPassage($agent, $p);
} catch (Throwable $e) {
    error_log('OV Pointage — passage : ' . $e->getMessage());
    ptJson(['ok' => false, 'message' => 'Erreur serveur, réessayez.'], 500);
}
$http = $res['http'];
unset($res['http']);
ptJson($res, $http);
