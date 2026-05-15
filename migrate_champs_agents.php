<?php
/**
 * Migration : ajoute les tables pour les champs agents personnalisés.
 * À exécuter une seule fois : http://localhost/ov-gestion/migrate_champs_agents.php
 * Supprimer ce fichier après usage.
 */
require_once __DIR__ . '/config/config.php';

$ok = [];
$err = [];

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS agent_champs_perso (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            label       VARCHAR(100) NOT NULL,
            cle         VARCHAR(50)  NOT NULL,
            type        ENUM('text','textarea','date','select','file') NOT NULL DEFAULT 'text',
            options     TEXT         NULL COMMENT 'JSON array des options (type select)',
            obligatoire TINYINT(1)   NOT NULL DEFAULT 0,
            ordre       INT          NOT NULL DEFAULT 0,
            actif       TINYINT(1)   NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ok[] = "Table <strong>agent_champs_perso</strong> prête.";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS agent_valeurs_perso (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            agent_id INT          NOT NULL,
            champ_id INT          NOT NULL,
            valeur   TEXT         NULL,
            fichier  VARCHAR(255) NULL,
            UNIQUE KEY uk_agent_champ (agent_id, champ_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ok[] = "Table <strong>agent_valeurs_perso</strong> prête.";

} catch (PDOException $e) {
    $err[] = $e->getMessage();
}
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Migration</title>
<style>body{font-family:sans-serif;max-width:600px;margin:40px auto;padding:0 20px} .ok{color:#16a34a} .err{color:#dc2626}</style>
</head><body>
<h2>Migration — Champs agents personnalisés</h2>
<?php foreach ($ok  as $m): ?><p class="ok">✓ <?= $m ?></p><?php endforeach; ?>
<?php foreach ($err as $m): ?><p class="err">✗ <?= htmlspecialchars($m) ?></p><?php endforeach; ?>
<?php if (empty($err)): ?>
<p><strong>Migration terminée.</strong> Vous pouvez maintenant :</p>
<ul>
  <li><a href="modules/parametres/index.php?tab=champs-agents">Configurer les champs → Paramètres &gt; Champs agents</a></li>
  <li>Supprimer ce fichier (migrate_champs_agents.php)</li>
</ul>
<?php endif; ?>
</body></html>
