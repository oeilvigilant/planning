<?php
require_once __DIR__ . '/config/config.php';

$errors = [];
$success = [];

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `" . DB_NAME . "`");
    $success[] = "Base de données `" . DB_NAME . "` prête.";

    $sql = file_get_contents(__DIR__ . '/config/database.sql');
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($statements as $stmt) {
        if (!empty($stmt)) {
            $pdo->exec($stmt);
        }
    }
    $success[] = "Tables créées avec succès.";
    $success[] = "Compte admin créé : <strong>admin@ov.fr</strong> / <strong>password</strong>";
    $success[] = "Installation terminée ! <a href='index.php'>→ Aller à la connexion</a>";

} catch (PDOException $e) {
    $errors[] = "Erreur : " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Installation OV-Gestion</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white d-flex align-items-center justify-content-center" style="min-height:100vh">
<div class="card bg-secondary p-4" style="max-width:500px;width:100%">
    <h4 class="mb-4">🛡️ Installation OV-Gestion</h4>
    <?php foreach ($errors as $e): ?>
        <div class="alert alert-danger"><?= $e ?></div>
    <?php endforeach; ?>
    <?php foreach ($success as $s): ?>
        <div class="alert alert-success"><?= $s ?></div>
    <?php endforeach; ?>
    <p class="text-warning small mt-3">⚠️ Supprimez ce fichier install.php après l'installation.</p>
</div>
</body>
</html>
