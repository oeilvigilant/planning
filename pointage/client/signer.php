<?php
/** Enregistre la signature d'un rapport par le client. */
require_once __DIR__ . '/_init.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$p = ptPortail();
$du = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['du'] ?? '') ? $_POST['du'] : null;
$au = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['au'] ?? '') ? $_POST['au'] : null;
$nom = trim(mb_substr((string)($_POST['nom'] ?? ''), 0, 150));
$fonction = trim(mb_substr((string)($_POST['fonction'] ?? ''), 0, 150));
$sig = (string)($_POST['signature'] ?? '');
$retour = 'client/index.php?t=' . $p['token'] . '&v=rapport&du=' . $du . '&au=' . $au;

if (!$du || !$au || $nom === '' || !preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $sig) || strlen($sig) > 600000) {
    ptRedirect($retour);
}
$bin = base64_decode(substr($sig, 22), true);
$info = $bin ? @getimagesizefromstring($bin) : false;
if (!$info || $info[2] !== IMAGETYPE_PNG) ptRedirect($retour);

$total = 0;
foreach (ptServicesPortail($p, $du, $au) as $s) $total += ptDureeMin($s);
getDB()->prepare("INSERT INTO pt_signatures (portail_id, periode_du, periode_au, total_min, nom, fonction, signature, ip, user_agent) VALUES (?,?,?,?,?,?,?,?,?)")
    ->execute([$p['id'], $du, $au, $total, $nom, $fonction ?: null, $sig, ptClientIp(), ptUserAgent()]);

ptNotifierResponsables('Rapport signé par le client', $nom . ' (' . $p['libelle'] . ') a signé le rapport du ' . date('d/m', strtotime($du)) . ' au ' . date('d/m/Y', strtotime($au))
    . ' — ' . ptFormatDuree($total) . '.', 'admin/portails.php');
ptRedirect($retour);
