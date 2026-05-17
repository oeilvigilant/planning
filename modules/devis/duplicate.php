<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'create');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$src = $db->prepare("SELECT * FROM devis WHERE id = ?");
$src->execute([$id]);
$src = $src->fetch();
if (!$src) { header('Location: index.php'); exit; }

// Générer un nouveau numéro : numéro d'origine + _COPIE ou incrément
function generateNumeroCopie($db, $base) {
    $yy = date('y');
    $mm = date('m');
    $prefix = "S-$yy-$mm-";
    $stmt = $db->prepare("SELECT COUNT(*) FROM devis WHERE numero LIKE ?");
    $stmt->execute([$prefix . '%']);
    $n = (int)$stmt->fetchColumn() + 1;
    return $prefix . $n;
}

$newNumero = generateNumeroCopie($db, $src['numero']);

$db->beginTransaction();
try {
    // Insérer le devis copié
    $stmtD = $db->prepare("
        INSERT INTO devis (numero, client_nom, client_adresse, periode_debut, periode_fin,
            description, tva_taux, statut, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'brouillon', ?)
    ");
    $stmtD->execute([
        $newNumero,
        $src['client_nom'],
        $src['client_adresse'],
        $src['periode_debut'],
        $src['periode_fin'],
        $src['description'],
        $src['tva_taux'],
        getCurrentUser()['id'],
    ]);
    $newDevisId = (int)$db->lastInsertId();

    // Générer la liste des jours
    $jours = [];
    $cur   = strtotime($src['periode_debut']);
    $end   = strtotime($src['periode_fin']);
    while ($cur <= $end) {
        $jours[] = date('Y-m-d', $cur);
        $cur = strtotime('+1 day', $cur);
    }

    // Copier les profils
    $srcProfils = $db->prepare("SELECT * FROM devis_profils WHERE devis_id = ? ORDER BY ordre, id");
    $srcProfils->execute([$id]);
    $srcProfils = $srcProfils->fetchAll();

    $stmtP = $db->prepare("
        INSERT INTO devis_profils (devis_id, ordre, label, activite, plage,
            taux_jn, taux_nn, taux_jd, taux_nd, taux_jf, taux_nf)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtL = $db->prepare("
        INSERT INTO devis_lignes (profil_id, date) VALUES (?, ?)
    ");

    foreach ($srcProfils as $sp) {
        $stmtP->execute([
            $newDevisId,
            $sp['ordre'],
            $sp['label'],
            $sp['activite'],
            $sp['plage'],
            $sp['taux_jn'],
            $sp['taux_nn'],
            $sp['taux_jd'],
            $sp['taux_nd'],
            $sp['taux_jf'],
            $sp['taux_nf'],
        ]);
        $newProfilId = (int)$db->lastInsertId();
        foreach ($jours as $jour) {
            $stmtL->execute([$newProfilId, $jour]);
        }
    }

    $db->commit();
    flash('success', 'Devis dupliqué : <strong>' . h($newNumero) . '</strong>. Les heures ne sont pas copiées — vous pouvez les saisir.');
    header('Location: view.php?id=' . $newDevisId);
    exit;

} catch (Exception $e) {
    $db->rollBack();
    flash('danger', 'Erreur lors de la duplication : ' . $e->getMessage());
    header('Location: view.php?id=' . $id);
    exit;
}
