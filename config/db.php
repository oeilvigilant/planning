<?php
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die(json_encode(['error' => 'Connexion base de données impossible : ' . $e->getMessage()]));
        }
        // Migration : colonne nuit_dimanche dans planning_lignes
        try {
            $cols = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='planning_lignes'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('min_nuit_dimanche', $cols)) {
                $pdo->exec("ALTER TABLE planning_lignes ADD COLUMN min_nuit_dimanche DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER min_nuit");
            }
            // Migration : fusionner min_ferie_dimanche dans min_ferie_normal puis supprimer la colonne
            if (in_array('min_ferie_dimanche', $cols)) {
                $pdo->exec("UPDATE planning_lignes SET min_ferie_normal = min_ferie_normal + min_ferie_dimanche WHERE min_ferie_dimanche > 0");
                $pdo->exec("ALTER TABLE planning_lignes DROP COLUMN min_ferie_dimanche");
            }
            // Supprimer le taux ferie_dimanche (fusionné dans ferie_normal)
            $pdo->exec("DELETE FROM taux_horaires WHERE type_heure='ferie_dimanche'");
            $hasTaux = (int)$pdo->query("SELECT COUNT(*) FROM taux_horaires WHERE type_heure='nuit_dimanche'")->fetchColumn();
            if (!$hasTaux) {
                $tNormal = (float)($pdo->query("SELECT taux FROM taux_horaires WHERE type_heure='normal'")->fetchColumn() ?: 0);
                $tNuit   = (float)($pdo->query("SELECT taux FROM taux_horaires WHERE type_heure='nuit'")->fetchColumn() ?: 0);
                $tDim    = (float)($pdo->query("SELECT taux FROM taux_horaires WHERE type_heure='dimanche'")->fetchColumn() ?: 0);
                $tND     = round($tNuit + $tDim - $tNormal, 4);
                $pdo->prepare("INSERT INTO taux_horaires (type_heure, label, taux, ordre) VALUES ('nuit_dimanche', 'Nuit Dimanche', ?, 3)")->execute([$tND]);
            }
        } catch (Exception $e) {}
    }
    return $pdo;
}
