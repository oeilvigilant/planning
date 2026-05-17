<?php
/**
 * install_devis.php — Script one-shot de création des tables du module Devis
 * À supprimer après usage.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/db.php';

$db = getDB();
$results = [];

$sqls = [
    'devis' => "CREATE TABLE IF NOT EXISTS devis (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero VARCHAR(30) NOT NULL,
        client_nom VARCHAR(200) DEFAULT '',
        client_adresse TEXT,
        periode_debut DATE NOT NULL,
        periode_fin DATE NOT NULL,
        description TEXT,
        tva_taux DECIMAL(5,2) DEFAULT 20.00,
        statut ENUM('brouillon','envoye','accepte','refuse') DEFAULT 'brouillon',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        created_by INT,
        UNIQUE KEY uk_numero (numero)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'devis_profils' => "CREATE TABLE IF NOT EXISTS devis_profils (
        id INT AUTO_INCREMENT PRIMARY KEY,
        devis_id INT NOT NULL,
        ordre TINYINT DEFAULT 0,
        label VARCHAR(200) NOT NULL,
        activite VARCHAR(100) DEFAULT 'Agent de Sécurité',
        plage VARCHAR(50) DEFAULT 'De 07h00 à 19h00',
        taux_jn DECIMAL(10,2) DEFAULT 25.90,
        taux_nn DECIMAL(10,2) DEFAULT 27.90,
        taux_jd DECIMAL(10,2) DEFAULT 27.90,
        taux_nd DECIMAL(10,2) DEFAULT 30.90,
        taux_jf DECIMAL(10,2) DEFAULT 51.80,
        taux_nf DECIMAL(10,2) DEFAULT 55.80,
        FOREIGN KEY (devis_id) REFERENCES devis(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'devis_lignes' => "CREATE TABLE IF NOT EXISTS devis_lignes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        profil_id INT NOT NULL,
        date DATE NOT NULL,
        h_jn DECIMAL(5,2) DEFAULT 0,
        h_nn DECIMAL(5,2) DEFAULT 0,
        h_jd DECIMAL(5,2) DEFAULT 0,
        h_nd DECIMAL(5,2) DEFAULT 0,
        h_jf DECIMAL(5,2) DEFAULT 0,
        h_nf DECIMAL(5,2) DEFAULT 0,
        FOREIGN KEY (profil_id) REFERENCES devis_profils(id) ON DELETE CASCADE,
        UNIQUE KEY uk_profil_date (profil_id, date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($sqls as $table => $sql) {
    try {
        $db->exec($sql);
        $results[] = ['table' => $table, 'status' => 'ok', 'msg' => 'Table créée ou déjà existante'];
    } catch (PDOException $e) {
        $results[] = ['table' => $table, 'status' => 'error', 'msg' => $e->getMessage()];
    }
}

// Ajouter les permissions pour le module devis à tous les rôles existants (sauf admin qui bypass)
try {
    $roles = $db->query("SELECT id, slug FROM roles")->fetchAll();
    $stmtPerm = $db->prepare("
        INSERT IGNORE INTO role_permissions (role_id, module, can_view, can_create, can_edit, can_delete, can_export)
        VALUES (?, 'devis', ?, ?, ?, ?, ?)
    ");
    foreach ($roles as $role) {
        // admin : tous droits (même si bypass, on insère pour cohérence)
        // manager : tous droits sauf delete
        // autres : view seulement
        if ($role['slug'] === 'admin') {
            $stmtPerm->execute([$role['id'], 1, 1, 1, 1, 1]);
        } elseif ($role['slug'] === 'manager') {
            $stmtPerm->execute([$role['id'], 1, 1, 1, 0, 1]);
        } else {
            $stmtPerm->execute([$role['id'], 1, 0, 0, 0, 0]);
        }
    }
    $results[] = ['table' => 'role_permissions (devis)', 'status' => 'ok', 'msg' => 'Permissions ajoutées pour tous les rôles'];
} catch (PDOException $e) {
    $results[] = ['table' => 'role_permissions (devis)', 'status' => 'error', 'msg' => $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Installation Module Devis</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container mt-5" style="max-width:600px">
    <div class="card shadow-sm">
        <div class="card-header bg-dark text-white">
            <h4 class="mb-0"><i class="fa fa-database"></i> Installation — Module Devis</h4>
        </div>
        <div class="card-body">
            <?php foreach ($results as $r): ?>
            <div class="d-flex align-items-center mb-2 p-2 rounded <?= $r['status']==='ok' ? 'bg-success bg-opacity-10' : 'bg-danger bg-opacity-10' ?>">
                <span class="badge <?= $r['status']==='ok' ? 'bg-success' : 'bg-danger' ?> me-2"><?= $r['status']==='ok' ? 'OK' : 'ERREUR' ?></span>
                <strong class="me-2"><?= htmlspecialchars($r['table']) ?></strong>
                <small class="text-muted"><?= htmlspecialchars($r['msg']) ?></small>
            </div>
            <?php endforeach; ?>

            <?php $ok = array_filter($results, function($r){ return $r['status']==='ok'; }); ?>
            <?php if (count($ok) === count($results)): ?>
            <div class="alert alert-success mt-3 mb-0">
                <strong>Installation réussie !</strong> Les 3 tables du module Devis ont été créées.
                <hr>
                <a href="modules/devis/index.php" class="btn btn-success btn-sm me-2">Aller au module Devis</a>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?delete=1" class="btn btn-outline-danger btn-sm"
                   onclick="return confirm('Supprimer ce fichier d\'installation ?')">
                    Supprimer ce fichier
                </a>
            </div>
            <?php else: ?>
            <div class="alert alert-danger mt-3 mb-0">Des erreurs se sont produites. Vérifiez les droits MySQL.</div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
<?php
// Auto-suppression
if (isset($_GET['delete']) && $_GET['delete'] === '1') {
    @unlink(__FILE__);
    header('Location: modules/devis/index.php');
    exit;
}
