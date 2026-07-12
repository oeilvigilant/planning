<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'create');

$db = getDB();
ensureDevisSchema();
ensureClientsSchema();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$src = $db->prepare("SELECT * FROM devis WHERE id = ?");
$src->execute([$id]);
$src = $src->fetch();
if (!$src) { header('Location: index.php'); exit; }

function generateNumeroCopie($db) {
    $yy = date('y');
    $mm = date('m');
    $prefix = "S-$yy-$mm-";
    $stmt = $db->prepare("SELECT COUNT(*) FROM devis WHERE numero LIKE ?");
    $stmt->execute([$prefix . '%']);
    $n = (int)$stmt->fetchColumn() + 1;
    return $prefix . $n;
}

$newNumero = generateNumeroCopie($db);

$db->beginTransaction();
try {
    // Insérer le devis copié (sans les heures)
    $stmtD = $db->prepare("
        INSERT INTO devis (client_id, numero, client_nom, client_adresse, periode_debut, periode_fin,
            description, tva_taux, remise_type, remise_valeur, statut, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'brouillon', ?)
    ");
    $stmtD->execute([
        $src['client_id'],
        $newNumero,
        $src['client_nom'],
        $src['client_adresse'],
        $src['periode_debut'],
        $src['periode_fin'],
        $src['description'],
        $src['tva_taux'],
        $src['remise_type'],
        $src['remise_valeur'],
        getCurrentUser()['id'],
    ]);
    $newDevisId = (int)$db->lastInsertId();

    // Copier les périodes
    $srcPer = $db->prepare("SELECT date_debut, date_fin, ordre FROM devis_periodes WHERE devis_id = ? ORDER BY ordre, date_debut");
    $srcPer->execute([$id]);
    $stmtPerIns = $db->prepare("INSERT INTO devis_periodes (devis_id, ordre, date_debut, date_fin) VALUES (?,?,?,?)");
    foreach ($srcPer->fetchAll() as $p) {
        $stmtPerIns->execute([$newDevisId, $p['ordre'], $p['date_debut'], $p['date_fin']]);
    }

    // Copier les exclusions (mêmes jours exclus)
    $srcEx = $db->prepare("SELECT date FROM devis_dates_exclues WHERE devis_id = ?");
    $srcEx->execute([$id]);
    $stmtExIns = $db->prepare("INSERT IGNORE INTO devis_dates_exclues (devis_id, date) VALUES (?,?)");
    foreach ($srcEx->fetchAll() as $ex) {
        $stmtExIns->execute([$newDevisId, $ex['date']]);
    }

    // Construire la liste des jours actifs du nouveau devis (périodes - exclusions)
    $jours = buildJoursDevis($db, $newDevisId);

    // Copier les profils et créer des lignes vides pour chaque jour actif
    $srcProfils = $db->prepare("SELECT * FROM devis_profils WHERE devis_id = ? ORDER BY ordre, id");
    $srcProfils->execute([$id]);

    $stmtP = $db->prepare("
        INSERT INTO devis_profils (devis_id, ordre, label, activite, plage,
            taux_jn, taux_nn, taux_jd, taux_nd, taux_jf, taux_nf, taux_jdf, taux_ndf)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($srcProfils->fetchAll() as $sp) {
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
            $sp['taux_jdf'],
            $sp['taux_ndf'],
        ]);
        $newProfilId = (int)$db->lastInsertId();
        insertLignesProfil($db, $newProfilId, $jours);
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
