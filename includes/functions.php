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
    $db = getDB();

    // Tables de base devis / devis_profils / devis_lignes
    try {
        $exists = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
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
    } catch (Exception $e) {}

    // Colonnes remise (migration auto)
    try {
        $devisExists = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devis'")->fetchColumn();
        if ($devisExists) {
            $hasRemise = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devis' AND COLUMN_NAME = 'remise_type'")->fetchColumn();
            if (!$hasRemise) {
                $db->exec("ALTER TABLE devis ADD COLUMN remise_type ENUM('pct','val') DEFAULT NULL AFTER tva_taux");
                $db->exec("ALTER TABLE devis ADD COLUMN remise_valeur DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER remise_type");
            }
        }
    } catch (Exception $e) {}

    // Table devis_periodes
    try {
        $hasPeriodes = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devis_periodes'")->fetchColumn();
        if (!$hasPeriodes) {
            $db->exec("CREATE TABLE devis_periodes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                devis_id INT NOT NULL,
                ordre INT NOT NULL DEFAULT 0,
                date_debut DATE NOT NULL,
                date_fin DATE NOT NULL,
                INDEX (devis_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            // Migrer les devis existants (une période par devis = la plage complète)
            $db->exec("INSERT IGNORE INTO devis_periodes (devis_id, ordre, date_debut, date_fin)
                SELECT id, 0, periode_debut, periode_fin FROM devis
                WHERE periode_debut IS NOT NULL AND periode_fin IS NOT NULL");
        }
    } catch (Exception $e) {}

    // Table devis_dates_exclues
    try {
        $hasExclues = (int)$db->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'devis_dates_exclues'")->fetchColumn();
        if (!$hasExclues) {
            $db->exec("CREATE TABLE devis_dates_exclues (
                devis_id INT NOT NULL,
                date DATE NOT NULL,
                PRIMARY KEY (devis_id, date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $e) {}
}

// ── Helpers devis : périodes & jours ────────────────────────────────────────

function buildJoursDevis(PDO $db, int $devisId): array {
    $stmtP = $db->prepare("SELECT date_debut, date_fin FROM devis_periodes WHERE devis_id = ? ORDER BY date_debut");
    $stmtP->execute([$devisId]);
    $periodes = $stmtP->fetchAll();

    $stmtE = $db->prepare("SELECT date FROM devis_dates_exclues WHERE devis_id = ?");
    $stmtE->execute([$devisId]);
    $exclu = array_flip(array_column($stmtE->fetchAll(), 'date'));

    $jourSet = [];
    foreach ($periodes as $p) {
        $cur = strtotime($p['date_debut']);
        $end = strtotime($p['date_fin']);
        while ($cur <= $end) {
            $d = date('Y-m-d', $cur);
            if (!isset($exclu[$d])) $jourSet[$d] = true;
            $cur = strtotime('+1 day', $cur);
        }
    }
    ksort($jourSet);
    return array_keys($jourSet);
}

function syncDevisBounds(PDO $db, int $devisId): void {
    $row = $db->prepare("SELECT MIN(date_debut) AS dmin, MAX(date_fin) AS dmax FROM devis_periodes WHERE devis_id = ?");
    $row->execute([$devisId]);
    $row = $row->fetch();
    if ($row && $row['dmin']) {
        $db->prepare("UPDATE devis SET periode_debut = ?, periode_fin = ? WHERE id = ?")
           ->execute([$row['dmin'], $row['dmax'], $devisId]);
    }
}

function insertLignesProfil(PDO $db, int $profilId, array $jours): void {
    $stmt = $db->prepare("INSERT IGNORE INTO devis_lignes (profil_id, date, h_jn, h_nn, h_jd, h_nd, h_jf, h_nf) VALUES (?,?,0,0,0,0,0,0)");
    foreach ($jours as $jour) $stmt->execute([$profilId, $jour]);
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

// ── Cotisations sociales ──────────────────────────────────────────────────────

function initCotisationsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS cotisations_taux (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        libelle       VARCHAR(100) NOT NULL,
        categorie     ENUM('secu_sociale','retraite','chomage','prevoyance','csg_crds','autres') DEFAULT 'autres',
        taux_salarial DECIMAL(6,3) NOT NULL DEFAULT 0.000,
        taux_patronal DECIMAL(6,3) NOT NULL DEFAULT 0.000,
        actif         TINYINT(1)   NOT NULL DEFAULT 1,
        ordre         INT          NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if ((int)$db->query("SELECT COUNT(*) FROM cotisations_taux")->fetchColumn() === 0) {
        $stmt = $db->prepare("INSERT INTO cotisations_taux (libelle,categorie,taux_salarial,taux_patronal,ordre) VALUES (?,?,?,?,?)");
        foreach ([
            ['Maladie / Maternité / Invalidité', 'secu_sociale', 0.000,  7.000,  1],
            ['Vieillesse plafonnée (SS)',         'secu_sociale', 6.900,  8.550,  2],
            ['Vieillesse déplafonnée (SS)',       'secu_sociale', 0.400,  1.900,  3],
            ['AT/MP',                             'secu_sociale', 0.000,  2.500,  4],
            ['Allocations familiales',            'secu_sociale', 0.000,  3.450,  5],
            ['Assurance chômage',                 'chomage',      2.400,  4.050,  6],
            ['AGIRC-ARRCO T1',                    'retraite',     3.150,  4.720,  7],
            ['CEG (complément retraite)',          'retraite',     0.860,  1.290,  8],
            ['CSG déductible',                    'csg_crds',     6.800,  0.000,  9],
            ['CSG non déductible + CRDS',         'csg_crds',     2.900,  0.000, 10],
            ['Prévoyance IDCC 1351',              'prevoyance',   0.500,  1.000, 11],
        ] as $d) $stmt->execute($d);
    }
}

function getCotisationsTaux(): array {
    static $cotis = null;
    if ($cotis === null) {
        initCotisationsTable();
        $cotis = getDB()->query(
            "SELECT * FROM cotisations_taux WHERE actif=1 ORDER BY ordre,id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }
    return $cotis;
}

function calculerCotisations(float $brut): array {
    $lignes   = getCotisationsTaux();
    $totalSal = 0.0;
    $totalPat = 0.0;
    $detail   = [];
    foreach ($lignes as $c) {
        $mSal = round($brut * ((float)$c['taux_salarial'] / 100), 2);
        $mPat = round($brut * ((float)$c['taux_patronal'] / 100), 2);
        $totalSal += $mSal;
        $totalPat += $mPat;
        $detail[] = [
            'libelle'     => $c['libelle'],
            'categorie'   => $c['categorie'],
            'taux_sal'    => (float)$c['taux_salarial'],
            'taux_pat'    => (float)$c['taux_patronal'],
            'montant_sal' => $mSal,
            'montant_pat' => $mPat,
        ];
    }
    return [
        'brut'           => $brut,
        'salarial'       => round($totalSal, 2),
        'patronal'       => round($totalPat, 2),
        'net'            => round($brut - $totalSal, 2),
        'cout_employeur' => round($brut + $totalPat, 2),
        'detail'         => $detail,
    ];
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

function formatHeureCourte(string $h): string {
    $parts = explode(':', $h);
    $heure = (int)($parts[0] ?? 0);
    $min   = (int)($parts[1] ?? 0);
    return sprintf('%02d', $heure) . 'h' . ($min !== 0 ? sprintf('%02d', $min) : '');
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

/**
 * Vérifie la complétude contractuelle et légale d'un agent.
 * @param array $a           Ligne agents (SELECT *)
 * @param array $docTypes    Types de documents uploadés
 * @param array $documents   Lignes complètes agent_documents (pour les dates d'expiration)
 * @param array $ignoredKeys Clés d'alertes ignorées (agents.alertes_ignorees JSON)
 * @return array ['ok','errors','warnings','ignored','count','champs','docs']
 */
function agentCompletion(array $a, array $docTypes, array $documents = [], array $ignoredKeys = []): array {
    $errors   = [];
    $warnings = [];
    $ignored  = [];

    // Helper : ajoute l'alerte dans la bonne liste selon si elle est ignorée ou non
    $add = function(array $entry, string $bucket) use (&$errors, &$warnings, &$ignored, $ignoredKeys) {
        if (in_array($entry['key'], $ignoredKeys)) {
            $ignored[] = $entry;
        } elseif ($bucket === 'error') {
            $errors[] = $entry;
        } else {
            $warnings[] = $entry;
        }
    };

    // Index date_expiration par type de document
    $expByType = [];
    foreach ($documents as $doc) {
        if (!empty($doc['date_expiration'])) $expByType[$doc['type_document']] = $doc['date_expiration'];
    }

    // ── Identité contractuelle ────────────────────────────────────────────────
    foreach ([
        'nom'           => 'Nom',
        'prenom'        => 'Prénom',
        'date_naissance'=> 'Date de naissance',
        'lieu_naissance'=> 'Lieu de naissance',
        'num_secu'      => 'N° sécurité sociale',
        'adresse'       => 'Adresse',
        'cp'            => 'Code postal',
        'ville'         => 'Ville',
    ] as $k => $lbl) {
        if (empty($a[$k])) $add(['label'=>$lbl, 'icon'=>'fa-user', 'cat'=>'Identité', 'key'=>'champ_'.$k], 'error');
    }

    // ── CNAPS ────────────────────────────────────────────────────────────────
    if (empty($a['num_autorisation_cnaps'])) {
        $add(['label'=>'N° autorisation CNAPS manquant', 'icon'=>'fa-shield-halved', 'cat'=>'CNAPS', 'key'=>'cnaps_num'], 'error');
    }
    if (empty($a['date_expiration_cnaps'])) {
        $add(['label'=>'Date d\'expiration CNAPS manquante', 'icon'=>'fa-calendar-xmark', 'cat'=>'CNAPS', 'key'=>'cnaps_date'], 'error');
    } else {
        $expTs = strtotime($a['date_expiration_cnaps']);
        if ($expTs < time()) {
            $add(['label'=>'Autorisation CNAPS expirée le '.date('d/m/Y', $expTs), 'icon'=>'fa-circle-xmark', 'cat'=>'CNAPS', 'key'=>'cnaps_expire'], 'error');
        } elseif ($expTs < strtotime('+60 days')) {
            $add(['label'=>'CNAPS expire le '.date('d/m/Y', $expTs).' ('.ceil(($expTs-time())/86400).' j)', 'icon'=>'fa-hourglass-half', 'cat'=>'CNAPS', 'key'=>'cnaps_bientot'], 'warning');
        }
    }

    // ── Document d'identité (selon nationalité) ───────────────────────────────
    $nat = strtolower(trim($a['nationalite'] ?? ''));

    if ($nat === '') {
        $add(['label'=>'Nationalité non renseignée (détermine la pièce d\'identité requise)', 'icon'=>'fa-flag', 'cat'=>'Identité', 'key'=>'nat_manquante'], 'error');
    } elseif (str_contains($nat, 'fran')) {
        if (!in_array('piece_identite', $docTypes)) {
            $add(['label'=>"Carte d'identité manquante", 'icon'=>'fa-id-card', 'cat'=>'Documents', 'key'=>'doc_cni'], 'error');
        } elseif (isset($expByType['piece_identite'])) {
            $exp = strtotime($expByType['piece_identite']);
            if ($exp < time()) {
                $add(['label'=>"Carte d'identité expirée le ".date('d/m/Y',$exp), 'icon'=>'fa-id-card', 'cat'=>'Documents', 'key'=>'doc_cni_exp'], 'error');
            } elseif ($exp < strtotime('+60 days')) {
                $add(['label'=>"Carte d'identité expire le ".date('d/m/Y',$exp).' ('.ceil(($exp-time())/86400).' j)', 'icon'=>'fa-id-card', 'cat'=>'Documents', 'key'=>'doc_cni_soon'], 'warning');
            }
        }
    } else {
        if (!in_array('titre_sejour', $docTypes)) {
            $add(['label'=>'Carte de séjour manquante', 'icon'=>'fa-passport', 'cat'=>'Documents', 'key'=>'doc_sejour'], 'error');
        } elseif (isset($expByType['titre_sejour'])) {
            $exp = strtotime($expByType['titre_sejour']);
            if ($exp < time()) {
                $add(['label'=>'Carte de séjour expirée le '.date('d/m/Y',$exp), 'icon'=>'fa-passport', 'cat'=>'Documents', 'key'=>'doc_sejour_exp'], 'error');
            } elseif ($exp < strtotime('+60 days')) {
                $add(['label'=>'Carte de séjour expire le '.date('d/m/Y',$exp).' ('.ceil(($exp-time())/86400).' j)', 'icon'=>'fa-passport', 'cat'=>'Documents', 'key'=>'doc_sejour_soon'], 'warning');
            }
        }
    }

    // ── Autres documents contractuels ─────────────────────────────────────────
    if (!in_array('attestation_cnaps', $docTypes)) {
        $add(['label'=>'Attestation CNAPS manquante', 'icon'=>'fa-shield-halved', 'cat'=>'Documents', 'key'=>'doc_cnaps'], 'error');
    }
    if (!in_array('attestation_domicile', $docTypes)) {
        $add(['label'=>'Justificatif de domicile manquant', 'icon'=>'fa-house', 'cat'=>'Documents', 'key'=>'doc_domicile'], 'warning');
    }
    if (!in_array('carte_vitale', $docTypes)) {
        $add(['label'=>'Carte vitale manquante', 'icon'=>'fa-heart-pulse', 'cat'=>'Documents', 'key'=>'doc_vitale'], 'warning');
    }
    if (!in_array('rib', $docTypes)) {
        $add(['label'=>'RIB manquant', 'icon'=>'fa-building-columns', 'cat'=>'Documents', 'key'=>'doc_rib'], 'warning');
    }

    $count = count($errors) + count($warnings);
    return [
        'ok'       => $count === 0,
        'errors'   => $errors,
        'warnings' => $warnings,
        'ignored'  => $ignored,
        'count'    => $count,
        'champs'   => array_column(array_filter($errors, fn($e) => $e['cat'] === 'Identité'), 'label'),
        'docs'     => array_column(array_filter($errors, fn($e) => $e['cat'] === 'Documents'), 'label'),
    ];
}
