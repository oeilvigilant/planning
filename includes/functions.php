<?php
require_once __DIR__ . '/../config/db.php';

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

function generateMatricule(): string {
    $db   = getDB();
    $max  = $db->query("SELECT MAX(CAST(matricule AS UNSIGNED)) as m FROM agents WHERE matricule REGEXP '^[0-9]+$'")->fetch();
    $next = ($max['m'] ?? 0) + 1;
    return str_pad($next, 5, '0', STR_PAD_LEFT);
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
