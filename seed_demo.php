<?php
/**
 * Seed données réelles — 11 agents Oeil Vigilant + planning Avril 2026
 * URL : https://oeilvigilant.com/planning/seed_demo.php
 * SUPPRIMER après usage.
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$db  = getDB();
$ok  = [];
$err = [];

// ── Génération avatar GD (initiales colorées) ─────────────────────
function makeAvatar(string $initials, string $hex, string $dest): bool {
    if (!function_exists('imagecreatetruecolor')) return false;
    $s = 300;
    $img = imagecreatetruecolor($s, $s);
    [$r,$g,$b] = sscanf($hex, '#%02x%02x%02x');
    $bg = imagecolorallocate($img, $r, $g, $b);
    $fg = imagecolorallocate($img, 255, 255, 255);
    imagefill($img, 0, 0, $bg);
    $fw = imagefontwidth(5) * strlen($initials);
    $fh = imagefontheight(5);
    $tmp = imagecreatetruecolor($fw + 4, $fh + 4);
    $tb  = imagecolorallocate($tmp, $r, $g, $b);
    $tf  = imagecolorallocate($tmp, 255, 255, 255);
    imagefill($tmp, 0, 0, $tb);
    imagestring($tmp, 5, 2, 2, $initials, $tf);
    $tw = ($fw + 4) * 7; $th = ($fh + 4) * 7;
    imagecopyresized($img, $tmp, ($s-$tw)/2, ($s-$th)/2, 0, 0, $tw, $th, $fw+4, $fh+4);
    imagedestroy($tmp);
    $res = imagejpeg($img, $dest, 90);
    imagedestroy($img);
    return $res;
}

// ── 11 agents réels (fiches renseignements Oeil Vigilant) ─────────
$colors = ['#1d4ed8','#7c3aed','#16a34a','#ea580c','#0891b2','#b45309','#dc2626','#0369a1','#65a30d','#9333ea','#c2410c'];

$agentsData = [
  [
    'matricule'=>'25F01','sexe'=>'F','nom'=>'BLOUHI','prenom'=>'Fatiha',
    'date_naissance'=>'1980-11-12','lieu_naissance'=>'Sidi Bel Abbès, Algérie',
    'nationalite'=>'Algérienne','num_secu'=>'2 80 11 99 352 762 09',
    'situation_familiale'=>'Divorcé(e)','nb_enfants'=>2,
    'adresse'=>'136 rue François Girard','cp'=>'06220','ville'=>'Vallauris',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>12,'h_mardi'=>0,'h_mercredi'=>0,'h_jeudi'=>12,'h_vendredi'=>12,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-07-22','date_fin_contrat'=>'2025-09-01',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Nette',
    'num_autorisation_cnaps'=>'CAR-006-2026-12-22-20210764566',
    'date_expiration_cnaps'=>'2026-12-22',
    'bulletins_depuis'=>'juillet 2025',
    'color'=>$colors[0],
    'shift'=>'J','off_dow'=>[7],
  ],
  [
    'matricule'=>'25F02','sexe'=>'M','nom'=>'TARROUFI','prenom'=>'Abderrahim',
    'date_naissance'=>'1977-11-13','lieu_naissance'=>'Kaf el Ghar, Maroc',
    'nationalite'=>'Marocaine','num_secu'=>'1 77 11 99 380 141 84',
    'situation_familiale'=>'Marié(e)','nb_enfants'=>2,
    'adresse'=>'7 avenue de Lérins','cp'=>'06160','ville'=>'Juan-les-Pins, Antibes',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>0,'h_mardi'=>12,'h_mercredi'=>0,'h_jeudi'=>0,'h_vendredi'=>12,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-07-22','date_fin_contrat'=>'2025-09-01',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Nette',
    'num_autorisation_cnaps'=>null,'date_expiration_cnaps'=>null,
    'bulletins_depuis'=>'juillet 2025',
    'color'=>$colors[1],
    'shift'=>'N','pattern'=>[1,1,1,0,0,0],
  ],
  [
    'matricule'=>'25F03','sexe'=>'F','nom'=>'KSOURI','prenom'=>'Hanen',
    'date_naissance'=>'1978-11-19','lieu_naissance'=>'Menzel Bourguiba, Tunisie',
    'nationalite'=>'Tunisienne','num_secu'=>'2 78 11 99 351 266 51',
    'situation_familiale'=>'Divorcé(e)','nb_enfants'=>2,
    'adresse'=>'2 avenue Bel Air','cp'=>'06600','ville'=>'Antibes',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>12,'h_mardi'=>0,'h_mercredi'=>12,'h_jeudi'=>0,'h_vendredi'=>0,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-07-22','date_fin_contrat'=>'2025-09-01',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Nette',
    'num_autorisation_cnaps'=>null,'date_expiration_cnaps'=>null,
    'bulletins_depuis'=>'juillet 2025',
    'color'=>$colors[2],
    'shift'=>'S','off_dow'=>[7],
  ],
  [
    'matricule'=>'25F04','sexe'=>'M','nom'=>'FERREIRA MONTEIRO','prenom'=>'José',
    'date_naissance'=>'1962-03-20','lieu_naissance'=>'Cabeça de Carreira, Santa Catarina',
    'nationalite'=>'Française','num_secu'=>'1 62 03 99 396 034 27',
    'situation_familiale'=>null,'nb_enfants'=>0,
    'adresse'=>'10 rue François Blanc','cp'=>'06220','ville'=>'Vallauris',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>0,'h_mardi'=>12,'h_mercredi'=>0,'h_jeudi'=>0,'h_vendredi'=>12,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-07-22','date_fin_contrat'=>'2025-09-01',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Nette',
    'num_autorisation_cnaps'=>null,'date_expiration_cnaps'=>null,
    'bulletins_depuis'=>'juillet 2025',
    'color'=>$colors[3],
    'shift'=>'M','off_dow'=>[1,7],
  ],
  [
    'matricule'=>'25F05','sexe'=>'F','nom'=>'GATALOVA','prenom'=>'Khadijat',
    'date_naissance'=>'1962-11-20','lieu_naissance'=>'Goragorsk',
    'nationalite'=>'Française','num_secu'=>'2 62 11 99 123 051 33',
    'situation_familiale'=>'Divorcé(e)','nb_enfants'=>0,
    'adresse'=>'9 rue Torrini','cp'=>'06000','ville'=>'Nice',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>12,'h_mardi'=>0,'h_mercredi'=>12,'h_jeudi'=>12,'h_vendredi'=>12,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-07-22','date_fin_contrat'=>'2025-09-01',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Nette',
    'num_autorisation_cnaps'=>'CAR-006-2029-04-19-20240651397','date_expiration_cnaps'=>'2029-04-19',
    'bulletins_depuis'=>'juillet 2025',
    'color'=>$colors[4],
    'shift'=>'J','off_dow'=>[6,7],
  ],
  [
    'matricule'=>'25F06','sexe'=>'M','nom'=>'GNING','prenom'=>'Omar',
    'date_naissance'=>'1997-02-12','lieu_naissance'=>'Ndieyene Sirakh, Sénégal',
    'nationalite'=>'Sénégalaise','num_secu'=>'1 97 02 99 341 144 30',
    'situation_familiale'=>null,'nb_enfants'=>0,
    'adresse'=>'Chez SOW Assane, 83 rue Longue des Capucins','cp'=>'13001','ville'=>'Marseille',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>0,'h_mardi'=>12,'h_mercredi'=>0,'h_jeudi'=>0,'h_vendredi'=>12,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-07-24','date_fin_contrat'=>'2025-09-01',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Brute',
    'num_autorisation_cnaps'=>null,'date_expiration_cnaps'=>null,
    'bulletins_depuis'=>'juillet 2025',
    'color'=>$colors[5],
    'shift'=>'NC','pattern'=>[1,1,1,1,0,0],
  ],
  [
    'matricule'=>'25F07','sexe'=>'F','nom'=>'ABIDI','prenom'=>'Inès',
    'date_naissance'=>'1986-01-16','lieu_naissance'=>'Nice',
    'nationalite'=>'Française','num_secu'=>'2 86 01 06 088 263 09',
    'situation_familiale'=>null,'nb_enfants'=>0,
    'adresse'=>'2 rue Saint Charles, Rés. Saint Charles','cp'=>'06160','ville'=>'Juan-les-Pins, Antibes',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>0,'h_mardi'=>12,'h_mercredi'=>0,'h_jeudi'=>0,'h_vendredi'=>12,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-07-30','date_fin_contrat'=>'2025-08-31',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Brute',
    'num_autorisation_cnaps'=>null,'date_expiration_cnaps'=>null,
    'bulletins_depuis'=>'juillet 2025',
    'color'=>$colors[6],
    'shift'=>'S','pattern'=>[0,1,0,0,1,0,0],
  ],
  [
    'matricule'=>'25F08','sexe'=>'M','nom'=>'BEN HASSEN','prenom'=>'Sahbi',
    'date_naissance'=>'1998-09-22','lieu_naissance'=>'M\'Saken, Tunisie',
    'nationalite'=>'Tunisienne','num_secu'=>'1 98 09 99 350 753 87',
    'situation_familiale'=>null,'nb_enfants'=>0,
    'adresse'=>'03 impasse Juan','cp'=>'06160','ville'=>'Juan-les-Pins',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>0,'h_mardi'=>0,'h_mercredi'=>0,'h_jeudi'=>0,'h_vendredi'=>0,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-08-11','date_fin_contrat'=>'2025-08-31',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.70,'type_remuneration'=>'Brute',
    'num_autorisation_cnaps'=>null,'date_expiration_cnaps'=>null,
    'bulletins_depuis'=>'août 2025',
    'color'=>$colors[7],
    'shift'=>'N','off_dow'=>[6,7],
  ],
  [
    'matricule'=>'25F09','sexe'=>'M','nom'=>'GUERRAB','prenom'=>'Halim',
    'date_naissance'=>'1964-09-24','lieu_naissance'=>'Aïn-Taya, Algérie',
    'nationalite'=>'Française','num_secu'=>'1 64 09 99 358 056 76',
    'situation_familiale'=>null,'nb_enfants'=>0,
    'adresse'=>'2 avenue du Claus','cp'=>'06110','ville'=>'Le Cannet',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>0,'h_mardi'=>0,'h_mercredi'=>0,'h_jeudi'=>0,'h_vendredi'=>0,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-08-11','date_fin_contrat'=>'2025-08-31',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Brute',
    'num_autorisation_cnaps'=>null,'date_expiration_cnaps'=>null,
    'bulletins_depuis'=>'août 2025',
    'color'=>$colors[8],
    'shift'=>'J','off_dow'=>[6,7],
  ],
  [
    'matricule'=>'25F10','sexe'=>'M','nom'=>'BEN SLIMANE','prenom'=>'Slim',
    'date_naissance'=>'1976-12-03','lieu_naissance'=>'Redeyef, Tunisie',
    'nationalite'=>'Française','num_secu'=>'1 76 12 99 350 205 82',
    'situation_familiale'=>null,'nb_enfants'=>0,
    'adresse'=>'5 rue René de Chateaubriand, Cannes la Bocca','cp'=>'06150','ville'=>'Cannes',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>0,'h_mardi'=>0,'h_mercredi'=>0,'h_jeudi'=>0,'h_vendredi'=>0,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-08-11','date_fin_contrat'=>'2025-08-31',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Brute',
    'num_autorisation_cnaps'=>null,'date_expiration_cnaps'=>null,
    'bulletins_depuis'=>'août 2025',
    'color'=>$colors[9],
    'shift'=>'M','off_dow'=>[7],
  ],
  [
    'matricule'=>'25F11','sexe'=>'M','nom'=>'PAVLOVIC','prenom'=>'Stevan',
    'date_naissance'=>'2001-07-11','lieu_naissance'=>'Cluses',
    'nationalite'=>'Française','num_secu'=>'1 01 07 74 081 031 16',
    'situation_familiale'=>null,'nb_enfants'=>0,
    'adresse'=>'3 rue de la Verrerie','cp'=>'06150','ville'=>'Cannes',
    'type_contrat'=>'CDD Usage','poste'=>'Agent de sécurité','statut'=>'Non cadre',
    'temps_travail_hebdo'=>'Prévisionnelle selon planning',
    'h_lundi'=>0,'h_mardi'=>0,'h_mercredi'=>0,'h_jeudi'=>0,'h_vendredi'=>0,'h_samedi'=>0,'h_dimanche'=>0,
    'date_debut_contrat'=>'2025-09-01','date_fin_contrat'=>'2025-09-12',
    'lieu_travail'=>'66 Bd Gardiole Bacon, 06160 Antibes',
    'periode_essai'=>'2 semaines','motif_embauche'=>'Accroissement activité',
    'remuneration'=>12.00,'type_remuneration'=>'Brute',
    'num_autorisation_cnaps'=>null,'date_expiration_cnaps'=>null,
    'bulletins_depuis'=>'septembre 2025',
    'color'=>$colors[10],
    'shift'=>'NC','pattern'=>[1,1,1,0,0,0],
  ],
];

// ── Insertion agents ──────────────────────────────────────────────
$stmtIns = $db->prepare("
    INSERT IGNORE INTO agents
        (matricule, nom, prenom, sexe, date_naissance, lieu_naissance, nationalite, num_secu,
         situation_familiale, nb_enfants, adresse, cp, ville,
         type_contrat, poste, statut, temps_travail_hebdo,
         h_lundi, h_mardi, h_mercredi, h_jeudi, h_vendredi, h_samedi, h_dimanche,
         date_debut_contrat, date_fin_contrat, lieu_travail, periode_essai, motif_embauche,
         remuneration, type_remuneration,
         num_autorisation_cnaps, date_expiration_cnaps,
         bulletins_depuis, dpae, contrat_realise, photo, actif)
    VALUES
        (:matricule, :nom, :prenom, :sexe, :ddn, :lieu, :nat, :secu,
         :sitfam, :enfants, :adresse, :cp, :ville,
         :contrat, :poste, :statut, :hebdo,
         :hl, :hma, :hme, :hj, :hv, :hsa, :hdi,
         :deb, :fin, :lieut, :essai, :motif,
         :rem, :trem,
         :cnaps, :expcnaps,
         :bulletins, 1, 1, :photo, 1)
");
$stmtFnd = $db->prepare("SELECT id FROM agents WHERE matricule = ?");

$agentIds = [];
foreach ($agentsData as &$ag) {
    $photoFile = 'photos/demo_'.strtolower(str_replace([' ','\''], '_', $ag['matricule'])).'.jpg';
    $photoDest = UPLOAD_PATH.'/'.$photoFile;
    if (!file_exists($photoDest)) {
        $init = strtoupper(mb_substr($ag['prenom'],0,1).mb_substr($ag['nom'],0,1));
        makeAvatar($init, $ag['color'], $photoDest);
    }
    $photoSaved = file_exists($photoDest) ? $photoFile : '';

    $stmtIns->execute([
        ':matricule'=>$ag['matricule'], ':nom'=>$ag['nom'], ':prenom'=>$ag['prenom'],
        ':sexe'=>($ag['sexe'] ?? 'M'),
        ':ddn'=>$ag['date_naissance'], ':lieu'=>$ag['lieu_naissance'],
        ':nat'=>$ag['nationalite'], ':secu'=>$ag['num_secu'],
        ':sitfam'=>$ag['situation_familiale'], ':enfants'=>$ag['nb_enfants'],
        ':adresse'=>$ag['adresse'], ':cp'=>$ag['cp'], ':ville'=>$ag['ville'],
        ':contrat'=>$ag['type_contrat'], ':poste'=>$ag['poste'], ':statut'=>$ag['statut'],
        ':hebdo'=>$ag['temps_travail_hebdo'],
        ':hl'=>$ag['h_lundi'],':hma'=>$ag['h_mardi'],':hme'=>$ag['h_mercredi'],
        ':hj'=>$ag['h_jeudi'],':hv'=>$ag['h_vendredi'],':hsa'=>$ag['h_samedi'],':hdi'=>$ag['h_dimanche'],
        ':deb'=>$ag['date_debut_contrat'], ':fin'=>$ag['date_fin_contrat'],
        ':lieut'=>$ag['lieu_travail'], ':essai'=>$ag['periode_essai'], ':motif'=>$ag['motif_embauche'],
        ':rem'=>$ag['remuneration'], ':trem'=>$ag['type_remuneration'],
        ':cnaps'=>$ag['num_autorisation_cnaps'], ':expcnaps'=>$ag['date_expiration_cnaps'],
        ':bulletins'=>$ag['bulletins_depuis'],
        ':photo'=>$photoSaved,
    ]);

    $stmtFnd->execute([$ag['matricule']]);
    $row = $stmtFnd->fetch();
    if ($row) {
        $agentIds[$ag['matricule']] = $row['id'];
        $ok[] = "✓ <b>{$ag['prenom']} {$ag['nom']}</b> ({$ag['matricule']}) — ".($photoSaved?'avatar OK':'sans avatar');
    } else {
        $err[] = "✗ Impossible d'insérer {$ag['nom']} {$ag['prenom']}";
    }
}
unset($ag);

// ── UPDATE forcé des numéros de carte pro (même si INSERT IGNORE a ignoré) ──
$cartesConnues = [
    '25F01' => ['cnaps'=>'CAR-006-2026-12-22-20210764566', 'exp'=>'2026-12-22'],
    '25F05' => ['cnaps'=>'CAR-006-2029-04-19-20240651397', 'exp'=>'2029-04-19'],
];
$stmtUpCnaps = $db->prepare("UPDATE agents SET num_autorisation_cnaps=?, date_expiration_cnaps=? WHERE matricule=?");
foreach ($cartesConnues as $mat => $data) {
    $stmtUpCnaps->execute([$data['cnaps'], $data['exp'], $mat]);
}

// ── Paramètres entreprise pour le badge ──────────────────────────
setParam('entreprise_cnaps',    'AUT-075-2123-06-21-20240934026');
setParam('entreprise_slogan',   'VOTRE SÉCURITÉ, NOTRE PRIORITÉ');
setParam('carte_mention_legale',"L'autorisation d'exercice ne confère aucune prérogative de puissance publique à l'entreprise ou aux personnes qui en bénéficient");
$ok[] = '✓ Paramètres badge (CNAPS entreprise, slogan, mention légale) injectés';

// ── Planning Avril 2026 (données démo fictives) ───────────────────
$shiftsH = [
    'J' =>['debut'=>'07:00','fin'=>'19:00','minuit'=>0],
    'N' =>['debut'=>'19:00','fin'=>'07:00','minuit'=>1],
    'M' =>['debut'=>'06:00','fin'=>'14:00','minuit'=>0],
    'S' =>['debut'=>'14:00','fin'=>'22:00','minuit'=>0],
    'NC'=>['debut'=>'22:00','fin'=>'06:00','minuit'=>1],
];

$mois = 4; $annee = 2026;
$stmtV = $db->prepare("SELECT id FROM planning_versions WHERE mois=? AND annee=? AND is_current=1");
$stmtV->execute([$mois, $annee]);
$existing = $stmtV->fetch();

if ($existing) {
    $versionId = $existing['id'];
    $ok[] = "Version Avril 2026 existante (id=$versionId).";
} else {
    $db->prepare("UPDATE planning_versions SET is_current=0 WHERE mois=? AND annee=?")->execute([$mois,$annee]);
    $db->prepare("INSERT INTO planning_versions (mois,annee,version,is_current,created_by) VALUES (?,?,1,1,1)")->execute([$mois,$annee]);
    $versionId = $db->lastInsertId();
    $ok[] = "Version planning Avril 2026 créée (id=$versionId).";
}

$nbJours = 30;
$stmtL = $db->prepare("
    INSERT IGNORE INTO planning_lignes
        (version_id,agent_id,date_travail,heure_debut,heure_fin,depasse_minuit,
         min_normal,min_nuit,min_dimanche,min_ferie_normal,min_ferie_dimanche,min_ferie_nuit,calcul_ok)
    VALUES (:vid,:aid,:date,:deb,:fin,:mn,:mnorm,:mnuit,:mdim,:mfn,:mfd,:mfnuit,1)
");

$totalL = 0;
foreach ($agentsData as $ag) {
    $aid = $agentIds[$ag['matricule']] ?? null;
    if (!$aid) continue;
    $h = $shiftsH[$ag['shift']];
    for ($d = 1; $d <= $nbJours; $d++) {
        $dateStr = sprintf('2026-04-%02d', $d);
        $dow = (int)date('N', strtotime($dateStr));
        $travaille = true;
        if (isset($ag['off_dow']) && in_array($dow, $ag['off_dow'])) {
            $travaille = false;
        } elseif (isset($ag['pattern'])) {
            $pat = $ag['pattern'];
            if (!$pat[($d-1) % count($pat)]) $travaille = false;
        }
        if (!$travaille) continue;
        $mins = calculerHeuresParType($dateStr, $h['debut'], $h['fin'], $db);
        $stmtL->execute([
            ':vid'=>$versionId,':aid'=>$aid,':date'=>$dateStr,
            ':deb'=>$h['debut'],':fin'=>$h['fin'],':mn'=>$h['minuit'],
            ':mnorm'=>$mins['normal'],':mnuit'=>$mins['nuit'],':mdim'=>$mins['dimanche'],
            ':mfn'=>$mins['ferie_normal'],':mfd'=>$mins['ferie_dimanche'],':mfnuit'=>$mins['ferie_nuit'],
        ]);
        $totalL++;
    }
    $ok[] = "Planning <b>{$ag['prenom']} {$ag['nom']}</b> ({$ag['shift']}) — inséré.";
}
$ok[] = "<b>$totalL lignes de planning</b> insérées.";
?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><title>Seed — OV-Gestion</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="bg-dark text-white d-flex justify-content-center py-5">
<div class="card bg-secondary p-4" style="max-width:680px;width:100%">
  <h4 class="mb-3">🛡️ Seed — 11 agents Oeil Vigilant</h4>
  <?php foreach ($ok  as $m): ?><div class="alert alert-success py-1 mb-1 small"><?= $m ?></div><?php endforeach; ?>
  <?php foreach ($err as $m): ?><div class="alert alert-danger  py-1 mb-1 small"><?= htmlspecialchars($m) ?></div><?php endforeach; ?>
  <?php if (empty($err)): ?>
  <div class="alert alert-warning mt-3">
    ⚠️ <strong>Supprimez ce fichier</strong> du serveur après usage.<br>
    <a href="index.php" class="btn btn-sm btn-light mt-2">→ Aller à l'application</a>
  </div>
  <?php endif; ?>
</div></body></html>
