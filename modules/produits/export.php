<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('produits', 'export');

$db = getDB();
ensureProduitsSchema();

$familles = produitFamilles();
$search   = trim($_GET['search'] ?? '');
$famille  = $_GET['famille'] ?? '';
$statut   = $_GET['statut'] ?? 'actifs';

$where  = [];
$params = [];
if ($search) {
    $where[] = "(code LIKE ? OR designation_courte LIKE ? OR designation_longue LIKE ?)";
    $s = '%' . $search . '%';
    array_push($params, $s, $s, $s);
}
if ($famille && isset($familles[$famille])) {
    $where[] = "famille = ?";
    $params[] = $famille;
}
if ($statut === 'actifs')   $where[] = "actif = 1";
if ($statut === 'inactifs') $where[] = "actif = 0";

$sql = "SELECT * FROM produits";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY famille, ordre, code";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$produits = $stmt->fetchAll();

$format = $_GET['format'] ?? 'complet'; // 'complet' (sauvegarde/réimport) ou 'simple' (usage externe)
$unites = produitUnites();

header('Content-Type: text/csv; charset=utf-8');
$f = fopen('php://output', 'w');
fprintf($f, chr(0xEF).chr(0xBB).chr(0xBF));

if ($format === 'simple') {
    header('Content-Disposition: attachment; filename="produits_simplifie_' . date('Y-m-d') . '.csv"');
    fputcsv($f, ['Nom','Détails','Prix unitaire','Unité','TVA','Type d\'article','Notes personnelles'], ';');
    foreach ($produits as $p) {
        fputcsv($f, [
            $p['code'] . ' - ' . $p['designation_courte'],
            $p['designation_longue'],
            number_format((float)$p['prix_defaut'], 2, '.', ''),
            $unites[$p['unite']] ?? $p['unite'],
            number_format((float)$p['tva_taux'], 2, '.', ''),
            $familles[$p['famille']] ?? $p['famille'],
            $p['notes'],
        ], ';');
    }
    fclose($f);
    exit;
}

header('Content-Disposition: attachment; filename="produits_' . date('Y-m-d') . '.csv"');
$headers = ['code','famille','qualification','type_heure','designation_courte','designation_longue','unite','prix_defaut','tva_taux','actif','notes','ordre'];
fputcsv($f, $headers, ';');

foreach ($produits as $p) {
    fputcsv($f, [
        $p['code'],
        $p['famille'],
        $p['qualification'],
        $p['type_heure'],
        $p['designation_courte'],
        $p['designation_longue'],
        $p['unite'],
        number_format((float)$p['prix_defaut'], 2, '.', ''),
        number_format((float)$p['tva_taux'], 2, '.', ''),
        $p['actif'],
        $p['notes'],
        $p['ordre'],
    ], ';');
}
fclose($f);
exit;
