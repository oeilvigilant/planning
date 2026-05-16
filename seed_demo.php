<?php
/**
 * Jeu de démonstration — 6 agents + planning Avril 2026
 * Exécuter UNE SEULE FOIS : https://oeilvigilant.com/planning/seed_demo.php
 * SUPPRIMER ce fichier après usage.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$db  = getDB();
$ok  = [];
$err = [];

// ════════════════════════════════════════════════════════════════════
// 1. PHOTOS — téléchargement depuis randomuser.me (ou GD si indisponible)
// ════════════════════════════════════════════════════════════════════
function fetchPhoto(string $url, string $dest): bool {
    // Essai curl
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data && $code === 200) {
            file_put_contents($dest, $data);
            return true;
        }
    }
    // Fallback file_get_contents
    $data = @file_get_contents($url);
    if ($data) { file_put_contents($dest, $data); return true; }
    return false;
}

function makeAvatar(string $initials, string $hex, string $dest): void {
    if (!function_exists('imagecreatetruecolor')) return;
    $s = 300;
    $img = imagecreatetruecolor($s, $s);
    [$r,$g,$b] = sscanf($hex, '#%02x%02x%02x');
    $bg = imagecolorallocate($img, $r, $g, $b);
    $fg = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $bg);
    // Initiales grossies par copie pixelisée
    $tmp = imagecreatetruecolor(imagefontwidth(5)*strlen($initials)+2, imagefontheight(5)+2);
    $tb  = imagecolorallocate($tmp, $r, $g, $b);
    $tf  = imagecolorallocate($tmp, 255, 255, 255);
    imagefill($tmp, 0, 0, $tb);
    imagestring($tmp, 5, 1, 1, $initials, $tf);
    $tw = imagesx($tmp)*8; $th = imagesy($tmp)*8;
    imagecopyresized($img, $tmp, ($s-$tw)/2, ($s-$th)/2, 0, 0, $tw, $th, imagesx($tmp), imagesy($tmp));
    imagedestroy($tmp);
    imagejpeg($img, $dest, 88);
    imagedestroy($img);
}

// ════════════════════════════════════════════════════════════════════
// 2. DONNÉES AGENTS
// ════════════════════════════════════════════════════════════════════
$agentsData = [
  [
    'matricule'              => '25F01',
    'nom'                    => 'BLOUHI',
    'prenom'                 => 'Fatiha',
    'date_naissance'         => '1980-11-12',
    'lieu_naissance'         => 'Casablanca',
    'nationalite'            => 'Française',
    'situation_familiale'    => 'Marié(e)',
    'nb_enfants'             => 2,
    'adresse'                => '12 rue des Lilas',
    'cp'                     => '93100',
    'ville'                  => 'Montreuil',
    'telephone'              => '06 12 34 56 78',
    'email'                  => 'f.blouhi@oeilvigilant.fr',
    'type_contrat'           => 'CDI',
    'poste'                  => 'Agent de sécurité',
    'statut'                 => 'Non cadre',
    'temps_travail_hebdo'    => '35h',
    'date_debut_contrat'     => '2024-01-15',
    'remuneration'           => 1820.00,
    'type_remuneration'      => 'Brute',
    'num_autorisation_cnaps' => 'AUT-075-2123-06-21-20240934026',
    'date_autorisation_cnaps'=> '2021-06-21',
    'date_expiration_cnaps'  => '2026-12-22',
    'dpae'                   => 1,
    'contrat_realise'        => 1,
    'photo_src'              => 'https://randomuser.me/api/portraits/women/5.jpg',
    'avatar_color'           => '#7c3aed',
    // Planning : J (07:00-19:00), Mon-Sam, repos dimanche
    'shift' => 'J',
    'off_dow' => [7], // dimanche
  ],
  [
    'matricule'              => '25F02',
    'nom'                    => 'MARTIN',
    'prenom'                 => 'Thomas',
    'date_naissance'         => '1992-03-05',
    'lieu_naissance'         => 'Lyon',
    'nationalite'            => 'Française',
    'situation_familiale'    => 'Célibataire',
    'nb_enfants'             => 0,
    'adresse'                => '8 avenue Voltaire',
    'cp'                     => '75011',
    'ville'                  => 'Paris',
    'telephone'              => '06 23 45 67 89',
    'email'                  => 't.martin@oeilvigilant.fr',
    'type_contrat'           => 'CDI',
    'poste'                  => 'Agent de sécurité',
    'statut'                 => 'Non cadre',
    'temps_travail_hebdo'    => '35h',
    'date_debut_contrat'     => '2023-06-01',
    'remuneration'           => 1820.00,
    'type_remuneration'      => 'Brute',
    'num_autorisation_cnaps' => 'AUT-075-2456-03-05-20221456789',
    'date_autorisation_cnaps'=> '2022-03-05',
    'date_expiration_cnaps'  => '2027-03-05',
    'dpae'                   => 1,
    'contrat_realise'        => 1,
    'photo_src'              => 'https://randomuser.me/api/portraits/men/12.jpg',
    'avatar_color'           => '#1d4ed8',
    // Planning : N (19:00-07:00), 3 travaillés / 3 repos
    'shift'   => 'N',
    'pattern' => [1,1,1,0,0,0], // cycle 3-3 à partir du 1er avril
  ],
  [
    'matricule'              => '25F03',
    'nom'                    => 'DIALLO',
    'prenom'                 => 'Mamadou',
    'date_naissance'         => '1985-07-18',
    'lieu_naissance'         => 'Dakar',
    'nationalite'            => 'Française',
    'situation_familiale'    => 'Marié(e)',
    'nb_enfants'             => 3,
    'adresse'                => '45 boulevard Davout',
    'cp'                     => '75020',
    'ville'                  => 'Paris',
    'telephone'              => '06 34 56 78 90',
    'email'                  => 'm.diallo@oeilvigilant.fr',
    'type_contrat'           => 'CDI',
    'poste'                  => 'Chef de poste',
    'statut'                 => 'Non cadre',
    'temps_travail_hebdo'    => '35h',
    'date_debut_contrat'     => '2022-09-01',
    'remuneration'           => 2100.00,
    'type_remuneration'      => 'Brute',
    'num_autorisation_cnaps' => 'AUT-075-3891-07-18-20231789456',
    'date_autorisation_cnaps'=> '2023-07-18',
    'date_expiration_cnaps'  => '2027-07-18',
    'dpae'                   => 1,
    'contrat_realise'        => 1,
    'photo_src'              => 'https://randomuser.me/api/portraits/men/34.jpg',
    'avatar_color'           => '#16a34a',
    // Planning : J, Lun-Ven uniquement
    'shift'   => 'J',
    'off_dow' => [6, 7], // samedi + dimanche
  ],
  [
    'matricule'              => '25F04',
    'nom'                    => 'BERNARD',
    'prenom'                 => 'Sophie',
    'date_naissance'         => '1988-09-22',
    'lieu_naissance'         => 'Bordeaux',
    'nationalite'            => 'Française',
    'situation_familiale'    => 'Divorcé(e)',
    'nb_enfants'             => 1,
    'adresse'                => '3 rue de la Paix',
    'cp'                     => '94300',
    'ville'                  => 'Vincennes',
    'telephone'              => '06 45 67 89 01',
    'email'                  => 's.bernard@oeilvigilant.fr',
    'type_contrat'           => 'CDI',
    'poste'                  => 'Agent de sécurité',
    'statut'                 => 'Non cadre',
    'temps_travail_hebdo'    => '35h',
    'date_debut_contrat'     => '2024-03-01',
    'remuneration'           => 1820.00,
    'type_remuneration'      => 'Brute',
    'num_autorisation_cnaps' => 'AUT-075-1547-09-22-20241234567',
    'date_autorisation_cnaps'=> '2024-09-22',
    'date_expiration_cnaps'  => '2028-09-22',
    'dpae'                   => 1,
    'contrat_realise'        => 1,
    'photo_src'              => 'https://randomuser.me/api/portraits/women/23.jpg',
    'avatar_color'           => '#ea580c',
    // Planning : S (14:00-22:00), Mon-Sam
    'shift'   => 'S',
    'off_dow' => [7],
  ],
  [
    'matricule'              => '25F05',
    'nom'                    => 'RODRIGUEZ',
    'prenom'                 => 'Carlos',
    'date_naissance'         => '1990-02-14',
    'lieu_naissance'         => 'Madrid',
    'nationalite'            => 'Française',
    'situation_familiale'    => 'Marié(e)',
    'nb_enfants'             => 2,
    'adresse'                => '27 rue Lepic',
    'cp'                     => '75018',
    'ville'                  => 'Paris',
    'telephone'              => '06 56 78 90 12',
    'email'                  => 'c.rodriguez@oeilvigilant.fr',
    'type_contrat'           => 'CDI',
    'poste'                  => 'Agent de sécurité',
    'statut'                 => 'Non cadre',
    'temps_travail_hebdo'    => '35h',
    'date_debut_contrat'     => '2023-11-15',
    'remuneration'           => 1820.00,
    'type_remuneration'      => 'Brute',
    'num_autorisation_cnaps' => 'AUT-075-4023-02-14-20231023456',
    'date_autorisation_cnaps'=> '2023-02-14',
    'date_expiration_cnaps'  => '2027-02-14',
    'dpae'                   => 1,
    'contrat_realise'        => 1,
    'photo_src'              => 'https://randomuser.me/api/portraits/men/47.jpg',
    'avatar_color'           => '#0891b2',
    // Planning : NC (22:00-06:00), cycle 4 travaillés / 2 repos
    'shift'   => 'NC',
    'pattern' => [1,1,1,1,0,0],
  ],
  [
    'matricule'              => '25F06',
    'nom'                    => 'LEFEVRE',
    'prenom'                 => 'Pierre',
    'date_naissance'         => '1978-01-30',
    'lieu_naissance'         => 'Lille',
    'nationalite'            => 'Française',
    'situation_familiale'    => 'Marié(e)',
    'nb_enfants'             => 4,
    'adresse'                => '15 rue du Général Leclerc',
    'cp'                     => '92130',
    'ville'                  => 'Issy-les-Moulineaux',
    'telephone'              => '06 67 89 01 23',
    'email'                  => 'p.lefevre@oeilvigilant.fr',
    'type_contrat'           => 'CDI',
    'poste'                  => 'Rondier',
    'statut'                 => 'Non cadre',
    'temps_travail_hebdo'    => '35h',
    'date_debut_contrat'     => '2021-04-01',
    'remuneration'           => 1900.00,
    'type_remuneration'      => 'Brute',
    'num_autorisation_cnaps' => 'AUT-075-0897-01-30-20210897654',
    'date_autorisation_cnaps'=> '2021-01-30',
    'date_expiration_cnaps'  => '2027-01-30',
    'dpae'                   => 1,
    'contrat_realise'        => 1,
    'photo_src'              => 'https://randomuser.me/api/portraits/men/56.jpg',
    'avatar_color'           => '#b45309',
    // Planning : M (06:00-14:00), Mon-Sam
    'shift'   => 'M',
    'off_dow' => [7],
  ],
];

// Horaires des postes
$shiftsHoraires = [
    'J'  => ['debut' => '07:00', 'fin' => '19:00', 'minuit' => 0],
    'N'  => ['debut' => '19:00', 'fin' => '07:00', 'minuit' => 1],
    'M'  => ['debut' => '06:00', 'fin' => '14:00', 'minuit' => 0],
    'S'  => ['debut' => '14:00', 'fin' => '22:00', 'minuit' => 0],
    'NC' => ['debut' => '22:00', 'fin' => '06:00', 'minuit' => 1],
];

// ════════════════════════════════════════════════════════════════════
// 3. INSERTION AGENTS
// ════════════════════════════════════════════════════════════════════
$agentIds = [];

$stmtInsert = $db->prepare("
    INSERT IGNORE INTO agents
        (matricule, nom, prenom, date_naissance, lieu_naissance, nationalite,
         situation_familiale, nb_enfants, adresse, cp, ville, telephone, email,
         type_contrat, poste, statut, temps_travail_hebdo, date_debut_contrat,
         remuneration, type_remuneration, num_autorisation_cnaps,
         date_autorisation_cnaps, date_expiration_cnaps, dpae, contrat_realise,
         photo, actif)
    VALUES
        (:matricule, :nom, :prenom, :date_naissance, :lieu_naissance, :nationalite,
         :situation_familiale, :nb_enfants, :adresse, :cp, :ville, :telephone, :email,
         :type_contrat, :poste, :statut, :temps_travail_hebdo, :date_debut_contrat,
         :remuneration, :type_remuneration, :num_autorisation_cnaps,
         :date_autorisation_cnaps, :date_expiration_cnaps, :dpae, :contrat_realise,
         :photo, 1)
");

$stmtFind = $db->prepare("SELECT id FROM agents WHERE matricule = ?");

foreach ($agentsData as &$ag) {
    // Photo
    $photoFile = 'photos/demo_'.strtolower($ag['matricule']).'.jpg';
    $photoDest = UPLOAD_PATH.'/'.$photoFile;
    $photoSaved = '';

    if (!file_exists($photoDest)) {
        if (fetchPhoto($ag['photo_src'], $photoDest)) {
            $photoSaved = $photoFile;
        } else {
            makeAvatar(
                strtoupper(substr($ag['prenom'],0,1).substr($ag['nom'],0,1)),
                $ag['avatar_color'],
                $photoDest
            );
            if (file_exists($photoDest)) $photoSaved = $photoFile;
        }
    } else {
        $photoSaved = $photoFile;
    }

    // INSERT agent
    $stmtInsert->execute([
        ':matricule'              => $ag['matricule'],
        ':nom'                    => $ag['nom'],
        ':prenom'                 => $ag['prenom'],
        ':date_naissance'         => $ag['date_naissance'],
        ':lieu_naissance'         => $ag['lieu_naissance'],
        ':nationalite'            => $ag['nationalite'],
        ':situation_familiale'    => $ag['situation_familiale'],
        ':nb_enfants'             => $ag['nb_enfants'],
        ':adresse'                => $ag['adresse'],
        ':cp'                     => $ag['cp'],
        ':ville'                  => $ag['ville'],
        ':telephone'              => $ag['telephone'],
        ':email'                  => $ag['email'],
        ':type_contrat'           => $ag['type_contrat'],
        ':poste'                  => $ag['poste'],
        ':statut'                 => $ag['statut'],
        ':temps_travail_hebdo'    => $ag['temps_travail_hebdo'],
        ':date_debut_contrat'     => $ag['date_debut_contrat'],
        ':remuneration'           => $ag['remuneration'],
        ':type_remuneration'      => $ag['type_remuneration'],
        ':num_autorisation_cnaps' => $ag['num_autorisation_cnaps'],
        ':date_autorisation_cnaps'=> $ag['date_autorisation_cnaps'],
        ':date_expiration_cnaps'  => $ag['date_expiration_cnaps'],
        ':dpae'                   => $ag['dpae'],
        ':contrat_realise'        => $ag['contrat_realise'],
        ':photo'                  => $photoSaved,
    ]);

    $stmtFind->execute([$ag['matricule']]);
    $agentRow = $stmtFind->fetch();
    if ($agentRow) {
        $agentIds[$ag['matricule']] = $agentRow['id'];
        $ok[] = "Agent <b>{$ag['prenom']} {$ag['nom']}</b> (MAT:{$ag['matricule']}) — "
              . ($photoSaved ? 'photo OK' : 'sans photo');
    } else {
        $err[] = "Impossible d'insérer {$ag['nom']} {$ag['prenom']}";
    }
}
unset($ag);

// ════════════════════════════════════════════════════════════════════
// 4. VERSION PLANNING AVRIL 2026
// ════════════════════════════════════════════════════════════════════
$mois  = 4;
$annee = 2026;

// Vérifier si une version existe déjà
$stmtV = $db->prepare("SELECT id FROM planning_versions WHERE mois=? AND annee=? AND is_current=1");
$stmtV->execute([$mois, $annee]);
$existingVersion = $stmtV->fetch();

if ($existingVersion) {
    $versionId = $existingVersion['id'];
    $ok[] = "Version planning Avril 2026 existante (id=$versionId) — lignes complétées.";
} else {
    $db->prepare("UPDATE planning_versions SET is_current=0 WHERE mois=? AND annee=?")
       ->execute([$mois, $annee]);
    $db->prepare("INSERT INTO planning_versions (mois, annee, version, is_current, created_by) VALUES (?,?,1,1,1)")
       ->execute([$mois, $annee]);
    $versionId = $db->lastInsertId();
    $ok[] = "Version planning Avril 2026 créée (id=$versionId).";
}

// ════════════════════════════════════════════════════════════════════
// 5. LIGNES DE PLANNING
// ════════════════════════════════════════════════════════════════════
$nbJours = cal_days_in_month(CAL_GREGORIAN, $mois, $annee);
$feries  = getJoursFeries($annee);

$stmtLigne = $db->prepare("
    INSERT IGNORE INTO planning_lignes
        (version_id, agent_id, date_travail, heure_debut, heure_fin, depasse_minuit,
         min_normal, min_nuit, min_dimanche, min_ferie_normal, min_ferie_dimanche, min_ferie_nuit,
         calcul_ok)
    VALUES
        (:vid, :aid, :date, :debut, :fin, :minuit,
         :mn, :mu, :md, :mfn, :mfd, :mfu, 1)
");

$totalLignes = 0;

foreach ($agentsData as $ag) {
    $agentId = $agentIds[$ag['matricule']] ?? null;
    if (!$agentId) continue;

    $horaire  = $shiftsHoraires[$ag['shift']];
    $patternI = 0; // index dans le cycle si pattern

    for ($d = 1; $d <= $nbJours; $d++) {
        $dateStr = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
        $dow     = (int)date('N', strtotime($dateStr)); // 1=Lun … 7=Dim

        // Déterminer si l'agent travaille ce jour
        $travaille = true;

        if (isset($ag['off_dow'])) {
            // Repos sur certains jours de la semaine
            if (in_array($dow, $ag['off_dow'])) $travaille = false;
        } elseif (isset($ag['pattern'])) {
            // Cycle répétitif (ex: [1,1,1,0,0,0])
            $pat = $ag['pattern'];
            $idx = ($d - 1) % count($pat);
            if (!$pat[$idx]) $travaille = false;
        }

        if (!$travaille) continue;

        // Calcul des minutes par type
        $mins = calculerHeuresParType($dateStr, $horaire['debut'], $horaire['fin'], $db);

        $stmtLigne->execute([
            ':vid'   => $versionId,
            ':aid'   => $agentId,
            ':date'  => $dateStr,
            ':debut' => $horaire['debut'],
            ':fin'   => $horaire['fin'],
            ':minuit'=> $horaire['minuit'],
            ':mn'    => $mins['normal'],
            ':mu'    => $mins['nuit'],
            ':md'    => $mins['dimanche'],
            ':mfn'   => $mins['ferie_normal'],
            ':mfd'   => $mins['ferie_dimanche'],
            ':mfu'   => $mins['ferie_nuit'],
        ]);
        $totalLignes++;
    }
    $ok[] = "Planning <b>{$ag['prenom']} {$ag['nom']}</b> ({$ag['shift']}) inséré.";
}

$ok[] = "<b>$totalLignes lignes de planning</b> insérées au total.";
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Seed démo — OV-Gestion</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark text-white d-flex align-items-center justify-content-center py-5">
<div class="card bg-secondary p-4" style="max-width:650px;width:100%">
  <h4 class="mb-4">🛡️ Données de démonstration — OV-Gestion</h4>

  <?php foreach ($ok  as $m): ?>
  <div class="alert alert-success py-1 mb-1 small"><?= $m ?></div>
  <?php endforeach; ?>

  <?php foreach ($err as $m): ?>
  <div class="alert alert-danger py-1 mb-1 small"><?= htmlspecialchars($m) ?></div>
  <?php endforeach; ?>

  <?php if (empty($err)): ?>
  <div class="alert alert-warning mt-3">
    ⚠️ <strong>Supprimez ce fichier</strong> <code>seed_demo.php</code> du serveur après usage.<br>
    <a href="index.php" class="btn btn-sm btn-light mt-2">→ Aller à l'application</a>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
