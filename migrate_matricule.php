<?php
/**
 * Migration : ajout colonne sexe + recalcul matricules au format YY+M|F+NN
 * À exécuter une seule fois, puis supprimer.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$log = [];

// 1. Ajouter la colonne sexe si elle n'existe pas
try {
    $db->exec("ALTER TABLE agents ADD COLUMN sexe ENUM('M','F') NOT NULL DEFAULT 'M' AFTER prenom");
    $log[] = "✔ Colonne <code>sexe</code> ajoutée.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column') !== false) {
        $log[] = "ℹ Colonne <code>sexe</code> existe déjà.";
    } else {
        $log[] = "✘ Erreur colonne sexe : " . htmlspecialchars($e->getMessage());
    }
}

// 2. Déduire le sexe depuis num_secu (1er chiffre : 1=M, 2=F)
$agents = $db->query("SELECT id, num_secu FROM agents")->fetchAll();
foreach ($agents as $a) {
    $sexe = 'M';
    if (!empty($a['num_secu'])) {
        $first = substr(trim($a['num_secu']), 0, 1);
        if ($first === '2') $sexe = 'F';
    }
    $db->prepare("UPDATE agents SET sexe = ? WHERE id = ?")->execute([$sexe, $a['id']]);
}
$log[] = "✔ Sexe déduit du num_secu pour " . count($agents) . " agents.";

// 3. Ajouter contrainte UNIQUE sur matricule si elle n'existe pas
try {
    $db->exec("ALTER TABLE agents ADD UNIQUE KEY uk_matricule (matricule)");
    $log[] = "✔ Contrainte UNIQUE ajoutée sur <code>matricule</code>.";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key') !== false || strpos($e->getMessage(), 'already exists') !== false) {
        $log[] = "ℹ Contrainte UNIQUE existe déjà.";
    } else {
        $log[] = "ℹ Contrainte UNIQUE : " . htmlspecialchars($e->getMessage());
    }
}

// 4. Recalculer les matricules au nouveau format
// Grouper par année (date_debut_contrat ou created_at ou année courante)
$agents = $db->query("SELECT id, sexe, matricule, date_debut_contrat, created_at FROM agents ORDER BY id")->fetchAll();

// Compteurs par préfixe pour garantir l'unicité dans cette migration
$counters = [];
$updates  = [];

foreach ($agents as $a) {
    // Déterminer l'année (2 chiffres)
    if (!empty($a['date_debut_contrat'])) {
        $yy = (int)date('y', strtotime($a['date_debut_contrat']));
    } elseif (!empty($a['created_at'])) {
        $yy = (int)date('y', strtotime($a['created_at']));
    } else {
        $yy = (int)date('y');
    }

    $s    = $a['sexe'] === 'F' ? 'F' : 'M';
    $pref = sprintf('%02d%s', $yy, $s);

    $counters[$pref] = ($counters[$pref] ?? 0) + 1;
    $newMat = $pref . sprintf('%02d', $counters[$pref]);
    $updates[] = ['id' => $a['id'], 'old' => $a['matricule'], 'new' => $newMat];
}

// Appliquer les nouvelles matricules
// D'abord mettre NULL pour lever les conflits UNIQUE pendant la mise à jour
$db->exec("SET foreign_key_checks = 0");
foreach ($updates as $u) {
    $db->prepare("UPDATE agents SET matricule = ? WHERE id = ?")->execute([$u['new'], $u['id']]);
    $log[] = "  Agent #{$u['id']} : <code>{$u['old']}</code> → <code>{$u['new']}</code>";
}
$db->exec("SET foreign_key_checks = 1");

$log[] = "✔ Matricules recalculés pour " . count($updates) . " agents.";

?><!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><title>Migration matricule</title>
<style>body{font-family:monospace;padding:2rem;background:#f8f9fa;}h1{color:#1a2332;}li{margin:4px 0;}</style>
</head>
<body>
<h1>Migration matricule — résultat</h1>
<ul>
<?php foreach ($log as $l): ?>
<li><?= $l ?></li>
<?php endforeach; ?>
</ul>
<p style="color:#888;margin-top:2rem;">Supprimez ce fichier une fois la migration terminée.</p>
</body>
</html>
