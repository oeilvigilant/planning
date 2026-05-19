<?php
require_once __DIR__ . '/../config/db.php';

// ── Migration automatique ────────────────────────────────────────────────────

function ensureAgentsSchema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db  = getDB();
        $has = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'agents' AND COLUMN_NAME = 'sexe'")->fetchColumn();
        if (!$has) {
            $db->exec("ALTER TABLE agents ADD COLUMN sexe ENUM('M','F') NOT NULL DEFAULT 'M' AFTER prenom");
            $db->exec("UPDATE agents SET sexe = CASE WHEN LEFT(TRIM(num_secu),1) = '2' THEN 'F' ELSE 'M' END WHERE num_secu IS NOT NULL AND num_secu != ''");
        }
    } catch (Exception $e) {}
}

function ensureDevisSchema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $exists = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devis'")->fetchColumn();
        if (!$exists) {
            $db->exec("CREATE TABLE devis (
                id INT AUTO_INCREMENT PRIMARY KEY,
                numero VARCHAR(50) NOT NULL UNIQUE,
                client_nom VARCHAR(255) DEFAULT '',
                client_adresse TEXT,
                periode_debut DATE NOT NULL,
                periode_fin DATE NOT NULL,
                description TEXT,
                tva_taux DECIMAL(5,2) NOT NULL DEFAULT 20.00,
                remise_type ENUM('pct','val') DEFAULT NULL,
                remise_valeur DECIMAL(10,2) NOT NULL DEFAULT 0,
                statut ENUM('brouillon','envoye','accepte','refuse') NOT NULL DEFAULT 'brouillon',
                created_by INT DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->exec("CREATE TABLE devis_profils (
                id INT AUTO_INCREMENT PRIMARY KEY,
                devis_id INT NOT NULL,
                ordre INT NOT NULL DEFAULT 0,
                label VARCHAR(255) NOT NULL DEFAULT '',
                activite VARCHAR(255) DEFAULT '',
                plage VARCHAR(100) DEFAULT '',
                taux_jn DECIMAL(8,2) NOT NULL DEFAULT 0,
                taux_nn DECIMAL(8,2) NOT NULL DEFAULT 0,
                taux_jd DECIMAL(8,2) NOT NULL DEFAULT 0,
                taux_nd DECIMAL(8,2) NOT NULL DEFAULT 0,
                taux_jf DECIMAL(8,2) NOT NULL DEFAULT 0,
                taux_nf DECIMAL(8,2) NOT NULL DEFAULT 0,
                INDEX (devis_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

            $db->exec("CREATE TABLE devis_lignes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profil_id INT NOT NULL,
                date DATE NOT NULL,
                h_jn DECIMAL(8,2) NOT NULL DEFAULT 0,
                h_nn DECIMAL(8,2) NOT NULL DEFAULT 0,
                h_jd DECIMAL(8,2) NOT NULL DEFAULT 0,
                h_nd DECIMAL(8,2) NOT NULL DEFAULT 0,
                h_jf DECIMAL(8,2) NOT NULL DEFAULT 0,
                h_nf DECIMAL(8,2) NOT NULL DEFAULT 0,
                UNIQUE KEY profil_date (profil_id, date),
                INDEX (profil_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        // Colonnes remise (migration auto)
        if ($exists) {
            $hasRemise = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devis' AND COLUMN_NAME = 'remise_type'")->fetchColumn();
            if (!$hasRemise) {
                $db->exec("ALTER TABLE devis ADD COLUMN remise_type ENUM('pct','val') DEFAULT NULL AFTER tva_taux");
                $db->exec("ALTER TABLE devis ADD COLUMN remise_valeur DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER remise_type");
            }
        }
    } catch (Exception $e) {}
}

function ensureClientsSchema(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $exists = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clients'")->fetchColumn();
        if (!$exists) {
            $db->exec("CREATE TABLE clients (
                id INT AUTO_INCREMENT PRIMARY KEY,
                nom VARCHAR(255) NOT NULL,
                adresse TEXT,
                email VARCHAR(255) DEFAULT '',
                telephone VARCHAR(30) DEFAULT '',
                contact VARCHAR(255) DEFAULT '',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        // Ajouter client_id à devis si absent
        $hasDevis = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devis'")->fetchColumn();
        if ($hasDevis) {
            $hasClientId = $db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devis' AND COLUMN_NAME = 'client_id'")->fetchColumn();
            if (!$hasClientId) {
                $db->exec("ALTER TABLE devis ADD COLUMN client_id INT DEFAULT NULL AFTER id");
            }
        }
    } catch (Exception $e) {}
}

// ── Paramètres ──────────────────────────────────────────────────────────────

function getParam(string $cle, string $default = ''): string {
    static $cache = [];
    if (!isset($cache[$cle])) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT valeur FROM parametres WHERE cle = ?");
        $stmt->execute([$cle]);
        $row  = $stmt->fetch();
        $cache[$cle] = $row ? $row['valeur'] : $default;
    }
    return $cache[$cle];
}

function setParam(string $cle, string $valeur): void {
    $db = getDB();
    $db->prepare("INSERT INTO parametres (cle, valeur) VALUES (?, ?)
                  ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)")
       ->execute([$cle, $valeur]);
}

function getAllParams(): array {
    $db   = getDB();
    $rows = $db->query("SELECT cle, valeur FROM parametres")->fetchAll();
    $out  = [];
    foreach ($rows as $r) $out[$r['cle']] = $r['valeur'];
    return $out;
}

// ── Matricule ────────────────────────────────────────────────────────────────

/**
 * Génère le prochain matricule disponible.
 * Format : YY + M|F + NN  (ex: 25F07, 26M03)
 * YY  = 2 derniers chiffres de l'année
 * M|F = sexe de l'agent
 * NN  = numéro séquentiel sur 2+ chiffres (unique par préfixe YY+genre)
 */
function generateMatricule(string $sexe = 'M', int $year = 0): string {
    $db = getDB();
    if ($year <= 0) $year = (int)date('y');
    $s    = strtoupper(substr($sexe, 0, 1));
    if (!in_array($s, ['M', 'F'])) $s = 'M';
    $pref = sprintf('%02d%s', $year, $s);
    // SUBSTRING(matricule, pos) = partie numérique après le préfixe (1-indexé)
    $pos  = strlen($pref) + 1;
    $stmt = $db->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING(matricule, ?) AS UNSIGNED)), 0)
         FROM agents
         WHERE matricule LIKE ? AND matricule REGEXP ?"
    );
    $stmt->execute([$pos, $pref . '%', '^' . $pref . '[0-9]+$']);
    $next = (int)$stmt->fetchColumn() + 1;
    return $pref . sprintf('%02d', $next);
}

// ── Jours fériés & Types de jours ───────────────────────────────────────────

function getJoursFeries(int $annee): array {
    static $cache = [];
    if (!isset($cache[$annee])) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT date FROM jours_feries WHERE YEAR(date) = ?");
        $stmt->execute([$annee]);
        $cache[$annee] = array_column($stmt->fetchAll(), 'date');
    }
    return $cache[$annee];
}

function isFerie(string $date): bool {
    $annee = (int)date('Y', strtotime($date));
    return in_array(date('Y-m-d', strtotime($date)), getJoursFeries($annee));
}

function isDimanche(string $date): bool {
    return date('N', strtotime($date)) == 7;
}

// ── Calcul des heures par type ───────────────────────────────────────────────
// Retourne un tableau de minutes par type pour une plage heure_debut→heure_fin
// sur une date donnée. Gère le dépassement de minuit.

function calculerHeuresParType(string $date, string $hDebut, string $hFin): array {
    $nuitDebut = getParam('nuit_debut', '21:00');
    $nuitFin   = getParam('nuit_fin',   '06:00');

    $result = [
        'normal'        => 0,
        'nuit'          => 0,
        'dimanche'      => 0,
        'ferie_normal'  => 0,
        'ferie_dimanche'=> 0,
        'ferie_nuit'    => 0,
    ];

    // Décomposer la plage en segments par jour
    $segments = decouperParJour($date, $hDebut, $hFin);

    foreach ($segments as $seg) {
        $segDate  = $seg['date'];
        $segStart = $seg['debut'];   // minutes depuis minuit
        $segEnd   = $seg['fin'];     // minutes depuis minuit

        $ferie    = isFerie($segDate);
        $dimanche = isDimanche($segDate);

        $ndMin = timeToMinutes($nuitDebut);
        $nfMin = timeToMinutes($nuitFin);

        // Construire la liste des plages nuit pour ce jour
        // Nuit = [0, nfMin) et [ndMin, 1440)
        $nuitPlages = [
            ['debut' => 0,      'fin' => $nfMin],
            ['debut' => $ndMin, 'fin' => 1440],
        ];

        $totalMin = $segEnd - $segStart;

        // Calculer les minutes nuit dans ce segment
        $minNuit = 0;
        foreach ($nuitPlages as $np) {
            $ov = min($segEnd, $np['fin']) - max($segStart, $np['debut']);
            if ($ov > 0) $minNuit += $ov;
        }
        $minJour = $totalMin - $minNuit;

        if ($ferie && $dimanche) {
            $result['ferie_dimanche'] += $minJour;
            $result['ferie_nuit']     += $minNuit;
        } elseif ($ferie) {
            $result['ferie_normal']   += $minJour;
            $result['ferie_nuit']     += $minNuit;
        } elseif ($dimanche) {
            $result['dimanche']       += $minJour;
            $result['nuit']           += $minNuit;
        } else {
            $result['normal']         += $minJour;
            $result['nuit']           += $minNuit;
        }
    }

    return $result;
}

function decouperParJour(string $date, string $hDebut, string $hFin): array {
    $segments  = [];
    $startMin  = timeToMinutes($hDebut);
    $endMin    = timeToMinutes($hFin);

    if ($endMin <= $startMin) {
        // Dépassement minuit
        $segments[] = ['date' => $date, 'debut' => $startMin, 'fin' => 1440];
        $lendemain  = date('Y-m-d', strtotime($date . ' +1 day'));
        $segments[] = ['date' => $lendemain, 'debut' => 0, 'fin' => $endMin];
    } else {
        $segments[] = ['date' => $date, 'debut' => $startMin, 'fin' => $endMin];
    }
    return $segments;
}

function timeToMinutes(string $time): int {
    [$h, $m] = explode(':', $time);
    return (int)$h * 60 + (int)$m;
}

function minutesToHeures(int $min): float {
    return round($min / 60, 2);
}

// ── Salaire ──────────────────────────────────────────────────────────────────

function getTauxHoraires(): array {
    static $taux = null;
    if ($taux === null) {
        $db   = getDB();
        $rows = $db->query("SELECT type_heure, taux FROM taux_horaires")->fetchAll();
        $taux = [];
        foreach ($rows as $r) $taux[$r['type_heure']] = (float)$r['taux'];
    }
    return $taux;
}

function calculerSalaire(array $minutesParType): float {
    $taux    = getTauxHoraires();
    $salaire = 0;
    foreach ($minutesParType as $type => $minutes) {
        $heures   = $minutes / 60;
        $tauxUnit = $taux[$type] ?? 0;
        $salaire += $heures * $tauxUnit;
    }
    return round($salaire, 2);
}

// ── Upload ────────────────────────────────────────────────────────────────────

function uploadFichier(array $file, string $sousDossier, array $extAutorisees = ['pdf','jpg','jpeg','png']) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $extAutorisees)) return false;
    if ($file['size'] > 10 * 1024 * 1024) return false;

    $dossier = UPLOAD_PATH . '/' . $sousDossier;
    if (!is_dir($dossier)) mkdir($dossier, 0755, true);

    $nom = uniqid('', true) . '.' . $ext;
    if (move_uploaded_file($file['tmp_name'], $dossier . '/' . $nom)) {
        return $sousDossier . '/' . $nom;
    }
    return false;
}

// ── Utilitaires ───────────────────────────────────────────────────────────────

function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function formatDate(?string $date): string {
    if (!$date) return '—';
    return date('d/m/Y', strtotime($date));
}

function formatMois(int $mois, int $annee): string {
    $noms = ['','Janvier','Février','Mars','Avril','Mai','Juin',
             'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    return $noms[$mois] . ' ' . $annee;
}

function generateToken(): string {
    return bin2hex(random_bytes(32));
}

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlash(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function getNomMois(int $mois): string {
    $noms = ['','Janvier','Février','Mars','Avril','Mai','Juin',
             'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
    return $noms[$mois] ?? '';
}
