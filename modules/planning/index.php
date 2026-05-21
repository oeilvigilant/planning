<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('planning', 'view');

$db  = getDB();
$vue = $_GET['vue'] ?? 'mois';

// ── Postes prédéfinis sécurité ────────────────────────────────────────────────
$shifts = [
    'J'  => ['label' => 'Journée',  'debut' => '07:00', 'fin' => '19:00', 'color' => '#16a34a', 'bg' => 'rgba(22,163,74,0.14)'],
    'N'  => ['label' => 'Nuit',     'debut' => '19:00', 'fin' => '07:00', 'color' => '#4f46e5', 'bg' => 'rgba(79,70,229,0.14)'],
    'M'  => ['label' => 'Matin',    'debut' => '06:00', 'fin' => '14:00', 'color' => '#ea580c', 'bg' => 'rgba(234,88,12,0.14)'],
    'S'  => ['label' => 'Soir',     'debut' => '14:00', 'fin' => '22:00', 'color' => '#7c3aed', 'bg' => 'rgba(124,58,237,0.14)'],
    'NC' => ['label' => 'Nuit C.',  'debut' => '22:00', 'fin' => '06:00', 'color' => '#1d4ed8', 'bg' => 'rgba(29,78,216,0.14)'],
];

function detectShift(string $hD, string $hF, array $shifts) {
    $hD = substr($hD, 0, 5); $hF = substr($hF, 0, 5);
    foreach ($shifts as $code => $s) {
        if ($s['debut'] === $hD && $s['fin'] === $hF) {
            return ['code' => $code, 'label' => $s['label'], 'color' => $s['color'], 'bg' => $s['bg']];
        }
    }
    return null;
}

function dayStat(array $allAgents, array $planningData, string $date): array {
    $j = $n = $tot = $h = 0;
    foreach ($allAgents as $ag) {
        $l = $planningData[$ag['id']][$date] ?? null;
        if ($l) {
            $tot++;
            $h += $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit'];
            if (($l['min_nuit']+$l['min_ferie_nuit']) > 0) $n++; else $j++;
        }
    }
    return ['j' => $j, 'n' => $n, 't' => $tot, 'h' => $h];
}

function covBorderColor(array $stat): string {
    if ($stat['t'] === 0) return '#f87171';
    if ($stat['j'] > 0 && $stat['n'] > 0) return '#4ade80';
    return '#fbbf24';
}

function covCellBg(array $stat): string {
    if ($stat['t'] === 0) return '#fef2f2';
    if ($stat['j'] > 0 && $stat['n'] > 0) return '#f0fdf4';
    return '#fffbeb';
}

$moisFr = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];

// ── AJAX POST — avant tout output HTML ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requirePerm('planning', 'create');
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'save_ligne') {
        $agentId   = (int)($_POST['agent_id']   ?? 0);
        $date      =      ($_POST['date']        ?? '');
        $hDebut    =      ($_POST['heure_debut'] ?? '');
        $hFin      =      ($_POST['heure_fin']   ?? '');
        $note      =      ($_POST['note']        ?? '');
        $versionId = (int)($_POST['version_id']  ?? 0);

        if (!$agentId || !$date || !$hDebut || !$hFin || !$versionId) {
            echo json_encode(['ok' => false, 'error' => 'Paramètres manquants']);
            exit;
        }
        $minutes = calculerHeuresParType($date, $hDebut, $hFin);
        $depasse = timeToMinutes($hFin) <= timeToMinutes($hDebut) ? 1 : 0;

        $ex = $db->prepare("SELECT id FROM planning_lignes WHERE version_id=? AND agent_id=? AND date_travail=?");
        $ex->execute([$versionId, $agentId, $date]);
        $exist = $ex->fetch();

        if ($exist) {
            $db->prepare("UPDATE planning_lignes
                SET heure_debut=?,heure_fin=?,depasse_minuit=?,note=?,
                    min_normal=?,min_nuit=?,min_dimanche=?,min_ferie_normal=?,min_ferie_dimanche=?,min_ferie_nuit=?,calcul_ok=1
                WHERE id=?")
               ->execute([$hDebut,$hFin,$depasse,$note,
                          $minutes['normal'],$minutes['nuit'],$minutes['dimanche'],
                          $minutes['ferie_normal'],$minutes['ferie_dimanche'],$minutes['ferie_nuit'],
                          $exist['id']]);
        } else {
            $db->prepare("INSERT INTO planning_lignes
                (version_id,agent_id,date_travail,heure_debut,heure_fin,depasse_minuit,note,
                 min_normal,min_nuit,min_dimanche,min_ferie_normal,min_ferie_dimanche,min_ferie_nuit,calcul_ok)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
               ->execute([$versionId,$agentId,$date,$hDebut,$hFin,$depasse,$note,
                          $minutes['normal'],$minutes['nuit'],$minutes['dimanche'],
                          $minutes['ferie_normal'],$minutes['ferie_dimanche'],$minutes['ferie_nuit']]);
        }
        echo json_encode(['ok' => true, 'minutes' => $minutes]);
        exit;
    }

    if ($action === 'delete_ligne') {
        $agentId   = (int)($_POST['agent_id']  ?? 0);
        $date      =      ($_POST['date']       ?? '');
        $versionId = (int)($_POST['version_id'] ?? 0);
        if ($agentId && $date && $versionId) {
            $db->prepare("DELETE FROM planning_lignes WHERE version_id=? AND agent_id=? AND date_travail=?")
               ->execute([$versionId, $agentId, $date]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'new_version') {
        $reqMois  = (int)($_POST['mois']  ?? date('n'));
        $reqAnnee = (int)($_POST['annee'] ?? date('Y'));
        $note     =      ($_POST['note']  ?? '');
        $stmtCur  = $db->prepare("SELECT * FROM planning_versions WHERE mois=? AND annee=? AND is_current=1 LIMIT 1");
        $stmtCur->execute([$reqMois, $reqAnnee]);
        $curV = $stmtCur->fetch();
        $db->prepare("UPDATE planning_versions SET is_current=0 WHERE mois=? AND annee=?")->execute([$reqMois,$reqAnnee]);
        $stmtMax = $db->prepare("SELECT MAX(version) as mv FROM planning_versions WHERE mois=? AND annee=?");
        $stmtMax->execute([$reqMois,$reqAnnee]);
        $nextV = ((int)$stmtMax->fetch()['mv']) + 1;
        $db->prepare("INSERT INTO planning_versions (mois,annee,version,note,is_current,created_by) VALUES (?,?,?,?,1,?)")
           ->execute([$reqMois,$reqAnnee,$nextV,$note,getCurrentUser()['id']]);
        $newVId = (int)$db->lastInsertId();
        if ($curV) {
            $oldL = $db->prepare("SELECT * FROM planning_lignes WHERE version_id=?");
            $oldL->execute([$curV['id']]);
            foreach ($oldL->fetchAll() as $ol) {
                $db->prepare("INSERT INTO planning_lignes
                    (version_id,agent_id,date_travail,heure_debut,heure_fin,depasse_minuit,note,
                     min_normal,min_nuit,min_dimanche,min_ferie_normal,min_ferie_dimanche,min_ferie_nuit,calcul_ok)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$newVId,$ol['agent_id'],$ol['date_travail'],$ol['heure_debut'],$ol['heure_fin'],
                              $ol['depasse_minuit'],$ol['note'],
                              $ol['min_normal'],$ol['min_nuit'],$ol['min_dimanche'],
                              $ol['min_ferie_normal'],$ol['min_ferie_dimanche'],$ol['min_ferie_nuit'],$ol['calcul_ok']]);
            }
        }
        echo json_encode(['ok' => true, 'version' => $nextV, 'version_id' => $newVId]);
        exit;
    }

    if ($action === 'toggle_agent') {
        $agentId = (int)($_POST['agent_id'] ?? 0);
        if (!isset($_SESSION['planning_hidden'])) $_SESSION['planning_hidden'] = [];
        $key = array_search($agentId, $_SESSION['planning_hidden']);
        if ($key !== false) {
            array_splice($_SESSION['planning_hidden'], $key, 1);
            echo json_encode(['ok' => true, 'hidden' => false]);
        } else {
            $_SESSION['planning_hidden'][] = $agentId;
            echo json_encode(['ok' => true, 'hidden' => true]);
        }
        exit;
    }

    if ($action === 'show_all_agents') {
        $_SESSION['planning_hidden'] = [];
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'bulk_save') {
        $agentId   = (int)($_POST['agent_id']   ?? 0);
        $dateDebut =      ($_POST['date_debut']  ?? '');
        $dateFin   =      ($_POST['date_fin']    ?? '');
        $hDebut    =      ($_POST['heure_debut'] ?? '');
        $hFin      =      ($_POST['heure_fin']   ?? '');
        $joursStr  =      ($_POST['jours']       ?? '1,2,3,4,5');
        $note      =      ($_POST['note']        ?? '');

        if (!$agentId || !$dateDebut || !$dateFin || !$hDebut || !$hFin) {
            echo json_encode(['ok' => false, 'error' => 'Paramètres manquants']);
            exit;
        }

        $joursOk = array_map('intval', array_filter(explode(',', $joursStr), 'is_numeric'));
        if (empty($joursOk)) {
            echo json_encode(['ok' => false, 'error' => 'Aucun jour sélectionné']);
            exit;
        }

        $vMap = [];
        foreach ($db->query("SELECT id,mois,annee FROM planning_versions WHERE is_current=1")->fetchAll() as $v) {
            $vMap[$v['annee'].'-'.sprintf('%02d',$v['mois'])] = (int)$v['id'];
        }

        $userId = getCurrentUser()['id'];
        $saved  = 0;

        try {
            $d   = new DateTime($dateDebut);
            $end = new DateTime($dateFin);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => 'Dates invalides']);
            exit;
        }

        while ($d->format('Y-m-d') <= $end->format('Y-m-d')) {
            $dow = (int)$d->format('N');
            if (in_array($dow, $joursOk)) {
                $m = (int)$d->format('n');
                $y = (int)$d->format('Y');
                $k = $y.'-'.sprintf('%02d',$m);

                if (!isset($vMap[$k])) {
                    $db->prepare("INSERT INTO planning_versions (mois,annee,version,is_current,created_by) VALUES (?,?,1,1,?)")
                       ->execute([$m, $y, $userId]);
                    $vMap[$k] = (int)$db->lastInsertId();
                }

                $vId     = $vMap[$k];
                $dateStr = $d->format('Y-m-d');
                $minutes = calculerHeuresParType($dateStr, $hDebut, $hFin);
                $depasse = timeToMinutes($hFin) <= timeToMinutes($hDebut) ? 1 : 0;

                $ex = $db->prepare("SELECT id FROM planning_lignes WHERE version_id=? AND agent_id=? AND date_travail=?");
                $ex->execute([$vId, $agentId, $dateStr]);
                if ($ex->fetch()) {
                    $db->prepare("UPDATE planning_lignes SET heure_debut=?,heure_fin=?,depasse_minuit=?,note=?,
                        min_normal=?,min_nuit=?,min_dimanche=?,min_ferie_normal=?,min_ferie_dimanche=?,min_ferie_nuit=?,calcul_ok=1
                        WHERE version_id=? AND agent_id=? AND date_travail=?")
                       ->execute([$hDebut,$hFin,$depasse,$note,
                                  $minutes['normal'],$minutes['nuit'],$minutes['dimanche'],
                                  $minutes['ferie_normal'],$minutes['ferie_dimanche'],$minutes['ferie_nuit'],
                                  $vId,$agentId,$dateStr]);
                } else {
                    $db->prepare("INSERT INTO planning_lignes
                        (version_id,agent_id,date_travail,heure_debut,heure_fin,depasse_minuit,note,
                         min_normal,min_nuit,min_dimanche,min_ferie_normal,min_ferie_dimanche,min_ferie_nuit,calcul_ok)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,1)")
                       ->execute([$vId,$agentId,$dateStr,$hDebut,$hFin,$depasse,$note,
                                  $minutes['normal'],$minutes['nuit'],$minutes['dimanche'],
                                  $minutes['ferie_normal'],$minutes['ferie_dimanche'],$minutes['ferie_nuit']]);
                }
                $saved++;
            }
            $d->modify('+1 day');
        }

        echo json_encode(['ok' => true, 'saved' => $saved]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Action inconnue']);
    exit;
}

// ── Données communes ──────────────────────────────────────────────────────────
$hiddenAgents = $_SESSION['planning_hidden'] ?? [];

// Agents actifs + agents inactifs ayant encore des lignes dans une version courante
$allAgents = $db->query("
    SELECT a.id, a.nom, a.prenom, a.matricule, a.poste
    FROM agents a
    WHERE a.actif = 1
    UNION
    SELECT a.id, a.nom, a.prenom, a.matricule, a.poste
    FROM agents a
    INNER JOIN planning_lignes pl ON pl.agent_id = a.id
    INNER JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
    WHERE a.actif = 0
    ORDER BY nom, prenom
")->fetchAll();

$agents = array_values(array_filter($allAgents, fn($ag) => !in_array($ag['id'], $hiddenAgents)));

$versionsMap = [];
foreach ($db->query("SELECT id,mois,annee FROM planning_versions WHERE is_current=1 ORDER BY annee DESC, mois DESC LIMIT 24")->fetchAll() as $v) {
    $versionsMap[$v['annee'].'-'.sprintf('%02d',$v['mois'])] = (int)$v['id'];
}

$nomsJours  = ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
$canEdit    = canDo('planning','create');
$shiftsJson = json_encode($shifts);

// ── Vue hebdomadaire ──────────────────────────────────────────────────────────
if ($vue === 'semaine') {
    $semaine = (int)($_GET['semaine'] ?? date('W'));
    $annee   = (int)($_GET['annee']   ?? date('Y'));
    if ($semaine < 1) $semaine = 1;
    if ($semaine > 53) $semaine = 52;

    $lundi = new DateTime();
    $lundi->setISODate($annee, $semaine, 1);

    $dates = [];
    for ($i = 0; $i < 7; $i++) {
        $d = clone $lundi; $d->modify("+$i days");
        $dates[] = $d;
    }
    $dimanche = $dates[6];

    $prevL = clone $lundi; $prevL->modify('-7 days');
    $prevSem = (int)$prevL->format('W'); $prevAnnSem = (int)$prevL->format('Y');
    $nextL = clone $lundi; $nextL->modify('+7 days');
    $nextSem = (int)$nextL->format('W'); $nextAnnSem = (int)$nextL->format('Y');

    $feries = getJoursFeries($annee);
    if ((int)$dimanche->format('Y') !== $annee) {
        $feries = array_merge($feries, getJoursFeries((int)$dimanche->format('Y')));
    }

    // Auto-créer versions pour chaque mois couvert
    $moisCouverts = [];
    foreach ($dates as $dt) {
        $k = $dt->format('Y').'-'.sprintf('%02d',(int)$dt->format('n'));
        if (!isset($moisCouverts[$k])) $moisCouverts[$k] = [(int)$dt->format('n'), (int)$dt->format('Y')];
    }
    foreach ($moisCouverts as $k => [$m, $y]) {
        if (!isset($versionsMap[$k]) && $canEdit) {
            $db->prepare("INSERT INTO planning_versions (mois,annee,version,is_current,created_by) VALUES (?,?,1,1,?)")
               ->execute([$m, $y, getCurrentUser()['id']]);
            $versionsMap[$k] = (int)$db->lastInsertId();
        }
    }

    // Charger planning semaine (multi-mois)
    $dateDebutW   = $lundi->format('Y-m-d');
    $dateFinW     = $dimanche->format('Y-m-d');
    $planningData = [];
    $stmtWeek = $db->prepare("
        SELECT pl.* FROM planning_lignes pl
        JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
        WHERE pl.date_travail BETWEEN ? AND ?");
    $stmtWeek->execute([$dateDebutW, $dateFinW]);
    foreach ($stmtWeek->fetchAll() as $l) {
        $planningData[$l['agent_id']][$l['date_travail']] = $l;
    }

    // Pré-calculer couverture par jour
    $dayStats = [];
    foreach ($dates as $dt) {
        $dayStats[$dt->format('Y-m-d')] = dayStat($allAgents, $planningData, $dt->format('Y-m-d'));
    }

    $mois    = (int)$lundi->format('n');
    $annee   = (int)$lundi->format('Y');
    $mainKey = $lundi->format('Y').'-'.sprintf('%02d',$mois);
    $versionId = $versionsMap[$mainKey] ?? null;

    $moisNom = $moisFr[(int)$lundi->format('n')];
    $moisFinNom = ($lundi->format('m') !== $dimanche->format('m'))
        ? ' – '.$dimanche->format('d').' '.$moisFr[(int)$dimanche->format('n')].' '.$dimanche->format('Y')
        : '';

    $pageTitle    = 'Planning — Semaine '.$semaine;
    $currentModule = 'planning';
    require_once __DIR__ . '/../../includes/header.php';
    ?>

    <!-- TOOLBAR semaine -->
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
      <div class="btn-group btn-group-sm me-1">
        <a href="?vue=mois&mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn btn-ov-secondary"><i class="fa fa-calendar me-1"></i>Mensuel</a>
        <button class="btn btn-dark" disabled><i class="fa fa-calendar-week me-1"></i>Hebdo</button>
      </div>
      <a href="?vue=semaine&semaine=<?= $prevSem ?>&annee=<?= $prevAnnSem ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-left"></i></a>
      <span style="font-weight:700;font-size:0.95rem;color:var(--ov-navy)">
        Sem.<?= $semaine ?> — <?= $lundi->format('d') ?> <?= $moisNom ?> <?= $lundi->format('Y') ?><?= $moisFinNom ?>
      </span>
      <a href="?vue=semaine&semaine=<?= $nextSem ?>&annee=<?= $nextAnnSem ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-right"></i></a>
      <a href="?vue=semaine&semaine=<?= date('W') ?>&annee=<?= date('Y') ?>" class="btn btn-ov-secondary btn-sm">Cette sem.</a>
      <div class="ms-auto d-flex gap-2 flex-wrap">
        <?php if ($canEdit): ?>
        <button class="btn btn-ov-primary btn-sm" id="btnBulkAssign"><i class="fa fa-bolt me-1"></i>Affecter</button>
        <?php endif; ?>
        <button class="btn btn-ov-secondary btn-sm" id="btnAgentFilter">
          <i class="fa fa-eye me-1"></i>Agents<?php if ($hiddenAgents): ?> <span class="badge bg-warning text-dark"><?= count($hiddenAgents) ?></span><?php endif; ?>
        </button>
        <?php if ($canEdit): ?>
        <button class="btn btn-ov-secondary btn-sm" id="btnNewVersion"><i class="fa fa-code-branch me-1"></i>Nouvelle version</button>
        <?php endif; ?>
        <a href="versions.php?mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-clock-rotate-left me-1"></i>Historique</a>
        <?php if (canDo('planning','export')): ?>
        <button class="btn btn-ov-secondary btn-sm" id="btnExport"><i class="fa fa-file-export me-1"></i>Exporter</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- LÉGENDE shifts -->
    <div class="d-flex gap-2 mb-2 flex-wrap" style="font-size:0.72rem">
      <?php foreach ($shifts as $code => $s): ?>
      <span style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>;border:1px solid <?= $s['color'] ?>;padding:2px 8px;border-radius:5px;font-weight:700"><?= $code ?> <span style="font-weight:400;opacity:0.8"><?= $s['debut'] ?>–<?= $s['fin'] ?></span></span>
      <?php endforeach; ?>
      <span style="background:#f8f9fa;color:#6b7280;border:1px solid #e5e7eb;padding:2px 8px;border-radius:5px">Libre = horaires personnalisés</span>
      <span class="ms-auto" style="color:#9ca3af">■ <span style="color:#4ade80">Couv. complète</span> ■ <span style="color:#fbbf24">Partielle</span> ■ <span style="color:#f87171">Non couverte</span></span>
    </div>

    <!-- GRILLE hebdomadaire -->
    <div class="ov-card">
      <div class="ov-card-body p-0">
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;min-width:800px">
          <thead>
            <tr>
              <th style="min-width:140px;padding:10px 12px;background:#f8f9fa;font-size:0.78rem;font-weight:600;color:var(--ov-navy);border-bottom:2px solid #e5e7eb;position:sticky;left:0;z-index:2">Agent</th>
              <?php foreach ($dates as $dt):
                $dateStr   = $dt->format('Y-m-d');
                $jourSem   = (int)$dt->format('N');
                $isDim     = $jourSem === 7;
                $isSam     = $jourSem === 6;
                $isFer     = in_array($dateStr, $feries);
                $isToday   = $dateStr === date('Y-m-d');
                $bgHead    = $isFer ? '#fff3cd' : ($isDim ? '#fef2f2' : ($isSam ? '#f0f4ff' : ($isToday ? '#f0fdf4' : '#f8f9fa')));
                $colHead   = $isFer ? '#92400e' : ($isDim ? '#dc2626' : ($isSam ? '#4f46e5' : ($isToday ? '#16a34a' : 'var(--ov-navy)')));
                $verKey    = $dt->format('Y').'-'.sprintf('%02d',(int)$dt->format('n'));
                $verIdCell = $versionsMap[$verKey] ?? 0;
                $stat      = $dayStats[$dateStr];
                $covColor  = covBorderColor($stat);
              ?>
              <th style="min-width:120px;padding:8px 6px 0;text-align:center;background:<?= $bgHead ?>;border-left:1px solid #f0f2f5;border-bottom:4px solid <?= $covColor ?>">
                <div style="font-size:0.7rem;color:<?= $colHead ?>;font-weight:700;text-transform:uppercase"><?= $nomsJours[$jourSem] ?></div>
                <div style="font-size:1.1rem;font-weight:800;color:<?= $colHead ?>"><?= $dt->format('d') ?></div>
                <div style="font-size:0.6rem;color:#9ca3af;padding-bottom:4px"><?= $dt->format('M') ?><?= $isFer?' <span style="background:#fde68a;color:#92400e;padding:0 3px;border-radius:2px">F</span>':'' ?></div>
              </th>
              <?php endforeach; ?>
              <th style="min-width:70px;padding:8px 6px;background:#f8f9fa;font-size:0.72rem;font-weight:600;color:var(--ov-navy);border-bottom:2px solid #e5e7eb;border-left:2px solid #e5e7eb;text-align:center">Total</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($agents as $agent):
            $totalMin = 0;
            foreach ($dates as $dt) {
                $ds = $dt->format('Y-m-d');
                if (isset($planningData[$agent['id']][$ds])) {
                    $l = $planningData[$agent['id']][$ds];
                    $totalMin += $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit'];
                }
            }
          ?>
          <tr class="planning-row" data-agent-id="<?= $agent['id'] ?>">
            <td style="padding:8px 12px;border-bottom:1px solid #f0f2f5;position:sticky;left:0;background:white;z-index:1">
              <div class="planning-agent-name" style="font-weight:600;font-size:0.875rem"><?= h($agent['prenom'].' '.$agent['nom']) ?></div>
              <div style="font-size:0.7rem;color:#9ca3af"><?= h($agent['poste'] ?? '') ?></div>
            </td>
            <?php foreach ($dates as $dt):
              $dateStr   = $dt->format('Y-m-d');
              $ligne     = $planningData[$agent['id']][$dateStr] ?? null;
              $jourSem   = (int)$dt->format('N');
              $isDim     = $jourSem === 7;
              $isFer     = in_array($dateStr, $feries);
              $isToday   = $dateStr === date('Y-m-d');
              $verKey    = $dt->format('Y').'-'.sprintf('%02d',(int)$dt->format('n'));
              $verIdCell = $versionsMap[$verKey] ?? 0;
              if ($ligne) {
                  $shift = detectShift($ligne['heure_debut'], $ligne['heure_fin'], $shifts);
                  if ($shift) {
                      $bgCell = $shift['bg'];
                  } else {
                      $hasNuit = ($ligne['min_nuit']+$ligne['min_ferie_nuit']) > 0;
                      $bgCell  = $hasNuit ? 'rgba(79,70,229,0.08)' : ($isFer ? 'rgba(234,179,8,0.12)' : ($isDim ? 'rgba(239,68,68,0.06)' : 'rgba(34,197,94,0.06)'));
                  }
              } else {
                  $bgCell = $isDim ? 'rgba(239,68,68,0.03)' : ($isFer ? 'rgba(234,179,8,0.04)' : ($isToday ? 'rgba(34,197,94,0.04)' : 'white'));
              }
            ?>
            <td class="planning-cell"
                style="padding:6px 4px;text-align:center;border-bottom:1px solid #f0f2f5;border-left:1px solid #f0f2f5;background:<?= $bgCell ?>;cursor:<?= $canEdit&&$verIdCell?'pointer':'default' ?>;vertical-align:middle"
                data-date="<?= $dateStr ?>"
                data-agent="<?= $agent['id'] ?>"
                data-version="<?= $verIdCell ?>"
                <?php if ($canEdit && $verIdCell): ?>onclick="openCell(this)"<?php endif; ?>>
              <?php if ($ligne): ?>
              <?php
                $hDeb  = substr($ligne['heure_debut'], 0, 5);
                $hFin2 = substr($ligne['heure_fin'],   0, 5);
                $totH  = ($ligne['min_normal']+$ligne['min_nuit']+$ligne['min_dimanche']+$ligne['min_ferie_normal']+$ligne['min_ferie_dimanche']+$ligne['min_ferie_nuit'])/60;
                $shift = detectShift($hDeb, $hFin2, $shifts);
              ?>
              <?php if ($shift): ?>
              <div style="font-size:1.3rem;font-weight:900;color:<?= $shift['color'] ?>;line-height:1.1"><?= $shift['code'] ?></div>
              <div style="font-size:0.65rem;color:<?= $shift['color'] ?>;opacity:0.8;font-weight:600"><?= formatHeureCourte($hDeb) ?> - <?= formatHeureCourte($hFin2) ?></div>
              <div style="font-size:0.7rem;color:#6b7280;font-weight:600"><?= number_format($totH, 1) ?>h</div>
              <?php else: ?>
              <div style="font-size:0.82rem;font-weight:700;color:var(--ov-navy);line-height:1.2"><?= formatHeureCourte($hDeb) ?> - <?= formatHeureCourte($hFin2) ?></div>
              <div style="font-size:0.75rem;font-weight:600;color:#16a34a"><?= number_format($totH, 1) ?>h</div>
              <?php endif; ?>
              <?php if ($ligne['note']): ?><div style="font-size:0.6rem;color:#9ca3af;margin-top:1px;font-style:italic;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:110px"><?= h($ligne['note']) ?></div><?php endif; ?>
              <?php else: ?>
              <div style="color:#d1d5db;font-size:1.2rem">—</div>
              <?php if (!$verIdCell && $canEdit): ?><div style="font-size:0.58rem;color:#fca5a5">Pas de version</div><?php endif; ?>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td style="text-align:center;border-bottom:1px solid #f0f2f5;border-left:2px solid #e5e7eb;font-weight:700;font-size:0.9rem;color:<?= $totalMin>0?'var(--ov-navy)':'#9ca3af' ?>">
              <?= $totalMin > 0 ? number_format($totalMin/60, 1).'h' : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>

          <!-- Ligne couverture -->
          <tr style="border-top:2px solid #e5e7eb">
            <td style="padding:8px 12px;font-size:0.75rem;font-weight:700;color:var(--ov-navy);position:sticky;left:0;background:#f8f9fa;border-bottom:1px solid #e5e7eb">
              <i class="fa fa-shield-halved me-1" style="color:var(--ov-gold)"></i>Couverture
            </td>
            <?php foreach ($dates as $dt):
              $ds   = $dt->format('Y-m-d');
              $stat = $dayStats[$ds];
              $bg   = covCellBg($stat);
            ?>
            <td style="text-align:center;padding:6px 3px;background:<?= $bg ?>;border-left:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;vertical-align:middle">
              <?php if ($stat['t'] > 0): ?>
              <?php if ($stat['j'] > 0): ?><div style="font-size:0.72rem;font-weight:800;color:#16a34a;line-height:1.3">J:<?= $stat['j'] ?></div><?php endif; ?>
              <?php if ($stat['n'] > 0): ?><div style="font-size:0.72rem;font-weight:800;color:#4f46e5;line-height:1.3">N:<?= $stat['n'] ?></div><?php endif; ?>
              <div style="font-size:0.65rem;color:#6b7280;margin-top:2px"><?= number_format($stat['h']/60,1) ?>h</div>
              <?php else: ?>
              <div style="font-size:0.8rem;color:#fca5a5;font-weight:700">—</div>
              <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td style="border-left:2px solid #e5e7eb;background:#f8f9fa"></td>
          </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <?php
    $jsVue       = 'semaine';
    $jsMois      = $mois;
    $jsAnnee     = $annee;
    $jsVersionId = 0;
    $jsSemaine   = $semaine;

} else {
    // ── Vue mensuelle ─────────────────────────────────────────────────────────
    $mois  = (int)($_GET['mois']  ?? date('n'));
    $annee = (int)($_GET['annee'] ?? date('Y'));
    if ($mois < 1 || $mois > 12) $mois = (int)date('n');

    $stmtV = $db->prepare("SELECT * FROM planning_versions WHERE mois=? AND annee=? AND is_current=1 ORDER BY version DESC LIMIT 1");
    $stmtV->execute([$mois, $annee]);
    $version = $stmtV->fetch();

    if (!$version && $canEdit) {
        $db->prepare("INSERT INTO planning_versions (mois,annee,version,is_current,created_by) VALUES (?,?,1,1,?)")
           ->execute([$mois, $annee, getCurrentUser()['id']]);
        $stmtV->execute([$mois, $annee]);
        $version = $stmtV->fetch();
        $versionsMap[$annee.'-'.sprintf('%02d',$mois)] = $version ? (int)$version['id'] : null;
    }

    $versionId = $version ? (int)$version['id'] : null;
    $feries    = getJoursFeries($annee);
    $nbJours   = (int)date('t', mktime(0,0,0,$mois,1,$annee));

    $planningData = [];
    if ($version) {
        $stmtL = $db->prepare("SELECT * FROM planning_lignes WHERE version_id=?");
        $stmtL->execute([$version['id']]);
        foreach ($stmtL->fetchAll() as $l) {
            $planningData[$l['agent_id']][$l['date_travail']] = $l;
        }
    }

    // Pré-calculer couverture par jour
    $dayStats = [];
    for ($d = 1; $d <= $nbJours; $d++) {
        $date = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
        $dayStats[$date] = dayStat($allAgents, $planningData, $date);
    }

    $prevMois  = $mois == 1 ? 12 : $mois - 1;
    $prevAnnee = $mois == 1 ? $annee - 1 : $annee;
    $nextMois  = $mois == 12 ? 1 : $mois + 1;
    $nextAnnee = $mois == 12 ? $annee + 1 : $annee;
    $semaineCourante = (int)date('W', mktime(0,0,0,$mois,1,$annee));

    $pageTitle    = 'Planning mensuel';
    $currentModule = 'planning';
    require_once __DIR__ . '/../../includes/header.php';
    ?>

    <!-- TOOLBAR mensuel -->
    <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
      <div class="btn-group btn-group-sm me-1">
        <button class="btn btn-dark" disabled><i class="fa fa-calendar me-1"></i>Mensuel</button>
        <a href="?vue=semaine&semaine=<?= $semaineCourante ?>&annee=<?= $annee ?>" class="btn btn-ov-secondary"><i class="fa fa-calendar-week me-1"></i>Hebdo</a>
      </div>
      <a href="?vue=mois&mois=<?= $prevMois ?>&annee=<?= $prevAnnee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-left"></i></a>
      <h2 style="font-size:1.05rem;font-weight:700;color:var(--ov-navy);margin:0"><?= formatMois($mois,$annee) ?></h2>
      <a href="?vue=mois&mois=<?= $nextMois ?>&annee=<?= $nextAnnee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-right"></i></a>
      <a href="?vue=mois&mois=<?= date('n') ?>&annee=<?= date('Y') ?>" class="btn btn-ov-secondary btn-sm">Ce mois</a>
      <?php if ($version): ?>
      <span class="badge" style="background:rgba(34,197,94,0.1);color:#16a34a;border-radius:20px;padding:4px 12px;font-size:0.78rem">
        <i class="fa fa-code-branch me-1"></i>V<?= $version['version'] ?>
      </span>
      <?php endif; ?>
      <div class="ms-auto d-flex gap-2 flex-wrap">
        <?php if ($canEdit): ?>
        <button class="btn btn-ov-primary btn-sm" id="btnBulkAssign"><i class="fa fa-bolt me-1"></i>Affecter</button>
        <?php endif; ?>
        <button class="btn btn-ov-secondary btn-sm" id="btnAgentFilter">
          <i class="fa fa-eye me-1"></i>Agents<?php if ($hiddenAgents): ?> <span class="badge bg-warning text-dark"><?= count($hiddenAgents) ?></span><?php endif; ?>
        </button>
        <?php if ($canEdit): ?>
        <button class="btn btn-ov-secondary btn-sm" id="btnNewVersion"><i class="fa fa-code-branch me-1"></i>Nouvelle version</button>
        <?php endif; ?>
        <a href="versions.php?mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-clock-rotate-left me-1"></i>Historique</a>
        <?php if ($version && canDo('planning','export')): ?>
        <button class="btn btn-ov-secondary btn-sm" id="btnExport"><i class="fa fa-file-export me-1"></i>Exporter</button>
        <?php endif; ?>
      </div>
    </div>

    <!-- LÉGENDE shifts -->
    <div class="d-flex gap-2 mb-2 flex-wrap" style="font-size:0.72rem">
      <?php foreach ($shifts as $code => $s): ?>
      <span style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>;border:1px solid <?= $s['color'] ?>;padding:2px 8px;border-radius:5px;font-weight:700"><?= $code ?> <span style="font-weight:400;opacity:0.8"><?= $s['debut'] ?>–<?= $s['fin'] ?></span></span>
      <?php endforeach; ?>
      <span class="ms-auto" style="color:#9ca3af">■ <span style="color:#4ade80">J+N</span> ■ <span style="color:#fbbf24">J ou N</span> ■ <span style="color:#f87171">Aucun</span></span>
    </div>

    <!-- GRILLE mensuelle -->
    <div class="ov-card">
      <div class="ov-card-body p-0">
        <div style="overflow-x:auto">
        <table style="width:100%;border-collapse:collapse;min-width:900px">
          <thead>
            <tr>
              <th style="min-width:130px;padding:8px 12px;background:#f8f9fa;font-size:0.78rem;font-weight:600;color:var(--ov-navy);border-bottom:2px solid #e5e7eb;position:sticky;left:0;z-index:2">Agent</th>
              <?php for ($d = 1; $d <= $nbJours; $d++):
                $date    = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
                $jourSem = (int)date('N', strtotime($date));
                $isFer   = in_array($date, $feries);
                $isDim   = $jourSem === 7;
                $isSam   = $jourSem === 6;
                $isToday = $date === date('Y-m-d');
                $bgHead  = $isFer ? '#fff3cd' : ($isDim ? '#fef2f2' : ($isSam ? '#f0f4ff' : ($isToday ? '#f0fdf4' : '#f8f9fa')));
                $colHead = $isFer ? '#92400e' : ($isDim ? '#dc2626' : ($isSam ? '#4f46e5' : ($isToday ? '#16a34a' : 'var(--ov-navy)')));
                $stat    = $dayStats[$date];
                $covColor = covBorderColor($stat);
              ?>
              <th style="min-width:55px;padding:4px 2px 0;text-align:center;background:<?= $bgHead ?>;border-left:1px solid #f0f2f5;border-bottom:4px solid <?= $covColor ?>">
                <div style="font-size:0.58rem;color:<?= $colHead ?>;font-weight:700"><?= $nomsJours[$jourSem] ?></div>
                <div style="font-size:0.9rem;font-weight:700;color:<?= $colHead ?>"><?= $d ?></div>
                <div style="font-size:0.5rem;padding-bottom:3px;color:#92400e"><?= $isFer?'Fér':'' ?></div>
              </th>
              <?php endfor; ?>
              <th style="min-width:60px;padding:8px 4px;background:#f8f9fa;font-size:0.72rem;font-weight:600;color:var(--ov-navy);border-bottom:2px solid #e5e7eb;border-left:2px solid #e5e7eb;text-align:center">Total</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($agents as $agent):
            $totalMin = 0;
            for ($d = 1; $d <= $nbJours; $d++) {
                $date = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
                if (isset($planningData[$agent['id']][$date])) {
                    $l = $planningData[$agent['id']][$date];
                    $totalMin += $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit'];
                }
            }
          ?>
          <tr class="planning-row" data-agent-id="<?= $agent['id'] ?>">
            <td style="padding:6px 10px;border-bottom:1px solid #f0f2f5;position:sticky;left:0;background:white;z-index:1;min-width:130px">
              <div class="planning-agent-name" style="font-weight:600;font-size:0.82rem"><?= h($agent['prenom'].' '.$agent['nom']) ?></div>
              <div style="font-size:0.65rem;color:#9ca3af"><?= h($agent['poste'] ?? '') ?></div>
            </td>
            <?php for ($d = 1; $d <= $nbJours; $d++):
              $date    = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
              $ligne   = $planningData[$agent['id']][$date] ?? null;
              $jourSem = (int)date('N', strtotime($date));
              $isDim   = $jourSem === 7;
              $isFer   = in_array($date, $feries);
              $isToday = $date === date('Y-m-d');
              if ($ligne) {
                  $shift = detectShift($ligne['heure_debut'], $ligne['heure_fin'], $shifts);
                  if ($shift) {
                      $bgCell = $shift['bg'];
                  } else {
                      $hasNuit = ($ligne['min_nuit']+$ligne['min_ferie_nuit']) > 0;
                      $bgCell  = $hasNuit ? 'rgba(79,70,229,0.1)' : ($isFer ? 'rgba(234,179,8,0.12)' : ($isDim ? 'rgba(239,68,68,0.08)' : 'rgba(34,197,94,0.08)'));
                  }
              } else {
                  $bgCell = $isDim ? 'rgba(239,68,68,0.03)' : ($isFer ? 'rgba(234,179,8,0.04)' : ($isToday ? 'rgba(34,197,94,0.04)' : 'white'));
              }
            ?>
            <td class="planning-cell"
                style="padding:2px 1px;text-align:center;border-bottom:1px solid #f0f2f5;border-left:1px solid #f0f2f5;background:<?= $bgCell ?>;cursor:<?= $canEdit&&$versionId?'pointer':'default' ?>;vertical-align:middle"
                data-date="<?= $date ?>"
                data-agent="<?= $agent['id'] ?>"
                data-version="<?= $versionId ?? 0 ?>"
                <?php if ($canEdit && $versionId): ?>onclick="openCell(this)"<?php endif; ?>>
              <?php if ($ligne): ?>
              <?php
                $hDeb  = substr($ligne['heure_debut'], 0, 5);
                $hFin2 = substr($ligne['heure_fin'],   0, 5);
                $totH  = ($ligne['min_normal']+$ligne['min_nuit']+$ligne['min_dimanche']+$ligne['min_ferie_normal']+$ligne['min_ferie_dimanche']+$ligne['min_ferie_nuit'])/60;
                $shift = detectShift($hDeb, $hFin2, $shifts);
              ?>
              <?php if ($shift): ?>
              <div style="font-size:1.05rem;font-weight:900;color:<?= $shift['color'] ?>;line-height:1.1;padding-top:1px"><?= $shift['code'] ?></div>
              <div style="font-size:0.52rem;color:<?= $shift['color'] ?>;opacity:0.85;font-weight:600;line-height:1.2"><?= formatHeureCourte($hDeb) ?> - <?= formatHeureCourte($hFin2) ?></div>
              <div style="font-size:0.52rem;color:#6b7280;opacity:0.85"><?= number_format($totH,0) ?>h</div>
              <?php else: ?>
              <?php $hasN = ($ligne['min_nuit']+$ligne['min_ferie_nuit']) > 0; ?>
              <div style="font-size:0.6rem;font-weight:700;color:var(--ov-navy);line-height:1.2"><?= formatHeureCourte($hDeb) ?> - <?= formatHeureCourte($hFin2) ?></div>
              <div style="font-size:0.58rem;color:#16a34a;font-weight:600"><?= number_format($totH,1) ?>h<?= $hasN?'<i class="fa fa-moon" style="font-size:0.45rem;margin-left:1px;color:#4f46e5"></i>':'' ?></div>
              <?php endif; ?>
              <?php else: ?>
              <div style="color:#e5e7eb;font-size:0.85rem;padding:2px 0">—</div>
              <?php endif; ?>
            </td>
            <?php endfor; ?>
            <td style="text-align:center;border-bottom:1px solid #f0f2f5;border-left:2px solid #e5e7eb;font-weight:700;font-size:0.82rem;color:<?= $totalMin>0?'var(--ov-navy)':'#9ca3af' ?>">
              <?= $totalMin > 0 ? number_format($totalMin/60,1).'h' : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>

          <!-- Ligne couverture -->
          <tr style="border-top:2px solid #e5e7eb">
            <td style="padding:5px 10px;font-size:0.73rem;font-weight:700;color:var(--ov-navy);position:sticky;left:0;background:#f1f5f9;border-bottom:1px solid #e5e7eb">
              <i class="fa fa-shield-halved me-1" style="color:var(--ov-gold)"></i>Couverture
            </td>
            <?php for ($d = 1; $d <= $nbJours; $d++):
              $date = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
              $stat = $dayStats[$date];
              $bg   = covCellBg($stat);
            ?>
            <td style="text-align:center;padding:3px 1px;background:<?= $bg ?>;border-left:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;vertical-align:middle">
              <?php if ($stat['t'] > 0): ?>
              <?php if ($stat['j'] > 0): ?><div style="font-size:0.62rem;font-weight:800;color:#16a34a;line-height:1.2">J<?= $stat['j'] ?></div><?php endif; ?>
              <?php if ($stat['n'] > 0): ?><div style="font-size:0.62rem;font-weight:800;color:#4f46e5;line-height:1.2">N<?= $stat['n'] ?></div><?php endif; ?>
              <?php else: ?>
              <div style="font-size:0.7rem;color:#fca5a5">—</div>
              <?php endif; ?>
            </td>
            <?php endfor; ?>
            <td style="border-left:2px solid #e5e7eb;background:#f1f5f9"></td>
          </tr>

          <!-- Ligne total heures/jour -->
          <tr>
            <td style="padding:5px 10px;font-size:0.7rem;font-weight:600;color:#6b7280;position:sticky;left:0;background:#f8f9fa;border-bottom:1px solid #e5e7eb">
              <i class="fa fa-clock me-1"></i>Total h/jour
            </td>
            <?php for ($d = 1; $d <= $nbJours; $d++):
              $date = sprintf('%04d-%02d-%02d', $annee, $mois, $d);
              $stat = $dayStats[$date];
            ?>
            <td style="text-align:center;padding:3px 1px;font-size:0.62rem;font-weight:700;color:<?= $stat['h']>0?'#374151':'#d1d5db' ?>;border-left:1px solid #f0f2f5;border-bottom:1px solid #e5e7eb;background:#f8f9fa">
              <?= $stat['h'] > 0 ? number_format($stat['h']/60,0).'h' : '—' ?>
            </td>
            <?php endfor; ?>
            <td style="border-left:2px solid #e5e7eb;background:#f8f9fa"></td>
          </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <?php
    $jsVue       = 'mois';
    $jsMois      = $mois;
    $jsAnnee     = $annee;
    $jsVersionId = $versionId ?? 0;
    $jsSemaine   = 0;
}
?>

<!-- ═══ MODAL saisie horaires ══════════════════════════════════════════════════ -->
<div class="modal fade" id="cellModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border-radius:12px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.18)">
      <div class="modal-header" style="background:var(--ov-navy);border-radius:12px 12px 0 0;border:none;padding:12px 16px">
        <h5 class="modal-title text-white" style="font-size:0.88rem" id="cellModalTitle">Saisir les horaires</h5>
        <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:14px 16px">
        <input type="hidden" id="modalDate">
        <input type="hidden" id="modalAgentId">
        <input type="hidden" id="modalVersionId">

        <!-- Presets shifts -->
        <div class="mb-3">
          <div style="font-size:0.75rem;font-weight:600;color:#6b7280;margin-bottom:6px">Poste rapide</div>
          <div class="d-flex gap-1 flex-wrap" id="shiftPresets">
            <?php foreach ($shifts as $code => $s): ?>
            <button type="button" class="shift-preset-btn"
                    data-debut="<?= $s['debut'] ?>" data-fin="<?= $s['fin'] ?>"
                    onclick="applyPreset(this)"
                    style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>;border:1.5px solid <?= $s['color'] ?>;border-radius:6px;font-weight:800;font-size:0.78rem;padding:5px 9px;cursor:pointer;transition:all 0.15s">
              <?= $code ?><br><span style="font-size:0.6rem;font-weight:400;opacity:0.85"><?= $s['debut'] ?>–<?= $s['fin'] ?></span>
            </button>
            <?php endforeach; ?>
            <button type="button" class="shift-preset-btn"
                    data-debut="" data-fin=""
                    onclick="applyPreset(this)"
                    style="background:#f8f9fa;color:#374151;border:1.5px solid #d1d5db;border-radius:6px;font-weight:700;font-size:0.78rem;padding:5px 9px;cursor:pointer">
              Libre<br><span style="font-size:0.6rem;font-weight:400;opacity:0.7">Personnalisé</span>
            </button>
          </div>
        </div>

        <!-- Heures -->
        <div class="row g-2 mb-2" id="timeInputsRow">
          <div class="col-6">
            <label class="form-label" style="font-size:0.78rem">Heure début</label>
            <input type="time" id="modalDebut" class="form-control form-control-sm">
          </div>
          <div class="col-6">
            <label class="form-label" style="font-size:0.78rem">Heure fin</label>
            <input type="time" id="modalFin" class="form-control form-control-sm">
          </div>
          <div class="col-12">
            <div class="form-text" style="font-size:0.7rem"><i class="fa fa-moon me-1 text-primary"></i>Fin &lt; Début → dépassement minuit détecté auto.</div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:0.78rem">Note <small class="text-muted">(optionnel)</small></label>
          <input type="text" id="modalNote" class="form-control form-control-sm" placeholder="Site, remplacement...">
        </div>
        <div id="calcPreview" class="p-2 rounded" style="background:#f8f9fa;font-size:0.78rem;display:none;border-left:3px solid var(--ov-gold)">
          <div style="font-weight:600;color:var(--ov-navy);margin-bottom:4px"><i class="fa fa-calculator me-1"></i>Aperçu :</div>
          <div id="calcResult"></div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0" style="padding:0 16px 14px">
        <button type="button" class="btn btn-sm btn-danger me-auto" id="btnDeleteLigne" style="display:none" onclick="deleteLigne()"><i class="fa fa-trash me-1"></i>Supprimer</button>
        <button type="button" class="btn btn-sm btn-ov-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-sm btn-ov-primary" onclick="saveLigne()" id="btnSaveLigne"><i class="fa fa-check me-1"></i>Enregistrer</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL affectation en masse ════════════════════════════════════════════ -->
<div class="modal fade" id="bulkModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:12px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.18)">
      <div class="modal-header" style="background:var(--ov-navy);border-radius:12px 12px 0 0;border:none">
        <h5 class="modal-title text-white" style="font-size:0.9rem"><i class="fa fa-bolt me-2" style="color:var(--ov-gold)"></i>Affecter en masse</h5>
        <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:16px">

        <!-- Agent -->
        <div class="mb-3">
          <label class="form-label" style="font-size:0.82rem;font-weight:600">Agent</label>
          <select id="bulkAgent" class="form-select form-select-sm">
            <?php foreach ($allAgents as $ag): ?>
            <option value="<?= $ag['id'] ?>"><?= h($ag['prenom'].' '.$ag['nom']) ?><?= $ag['poste'] ? ' — '.h($ag['poste']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <!-- Poste / shift -->
        <div class="mb-3">
          <label class="form-label" style="font-size:0.82rem;font-weight:600">Poste</label>
          <div class="d-flex gap-1 flex-wrap mb-2" id="bulkShiftBtns">
            <?php foreach ($shifts as $code => $s): ?>
            <button type="button" class="bulk-shift-btn <?= $code==='J'?'active':'' ?>"
                    data-debut="<?= $s['debut'] ?>" data-fin="<?= $s['fin'] ?>"
                    style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>;border:1.5px solid <?= $s['color'] ?>;border-radius:6px;font-weight:800;font-size:0.8rem;padding:6px 10px;cursor:pointer;<?= $code==='J'?'box-shadow:0 0 0 2px '.$s['color'].'40':'' ?>">
              <?= $code ?> <span style="font-size:0.68rem;font-weight:400"><?= $s['debut'] ?>–<?= $s['fin'] ?></span>
            </button>
            <?php endforeach; ?>
            <button type="button" class="bulk-shift-btn"
                    data-debut="" data-fin=""
                    style="background:#f8f9fa;color:#374151;border:1.5px solid #d1d5db;border-radius:6px;font-weight:700;font-size:0.8rem;padding:6px 10px;cursor:pointer">
              Libre
            </button>
          </div>
          <div id="bulkLibreInputs" style="display:none" class="row g-2">
            <div class="col-6">
              <input type="time" id="bulkDebut" class="form-control form-control-sm" placeholder="Début">
            </div>
            <div class="col-6">
              <input type="time" id="bulkFin" class="form-control form-control-sm" placeholder="Fin">
            </div>
          </div>
        </div>

        <!-- Période -->
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label" style="font-size:0.82rem;font-weight:600">Du</label>
            <input type="date" id="bulkDateDebut" class="form-control form-control-sm">
          </div>
          <div class="col-6">
            <label class="form-label" style="font-size:0.82rem;font-weight:600">Au</label>
            <input type="date" id="bulkDateFin" class="form-control form-control-sm">
          </div>
        </div>

        <!-- Jours de la semaine -->
        <div class="mb-3">
          <label class="form-label" style="font-size:0.82rem;font-weight:600">Jours</label>
          <div class="d-flex gap-1 flex-wrap">
            <input class="btn-check" type="checkbox" id="bdow1" value="1" checked><label class="btn btn-sm btn-outline-secondary" for="bdow1">Lun</label>
            <input class="btn-check" type="checkbox" id="bdow2" value="2" checked><label class="btn btn-sm btn-outline-secondary" for="bdow2">Mar</label>
            <input class="btn-check" type="checkbox" id="bdow3" value="3" checked><label class="btn btn-sm btn-outline-secondary" for="bdow3">Mer</label>
            <input class="btn-check" type="checkbox" id="bdow4" value="4" checked><label class="btn btn-sm btn-outline-secondary" for="bdow4">Jeu</label>
            <input class="btn-check" type="checkbox" id="bdow5" value="5" checked><label class="btn btn-sm btn-outline-secondary" for="bdow5">Ven</label>
            <input class="btn-check" type="checkbox" id="bdow6" value="6"><label class="btn btn-sm btn-outline-secondary" for="bdow6">Sam</label>
            <input class="btn-check" type="checkbox" id="bdow7" value="7"><label class="btn btn-sm btn-outline-secondary" for="bdow7">Dim</label>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="selectAllDow(true)" style="font-size:0.72rem">Tous</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="selectAllDow(false)" style="font-size:0.72rem">Aucun</button>
          </div>
        </div>

        <!-- Note -->
        <div class="mb-2">
          <label class="form-label" style="font-size:0.82rem;font-weight:600">Note <small class="text-muted">(optionnel)</small></label>
          <input type="text" id="bulkNote" class="form-control form-control-sm" placeholder="Site, mission, remplacement...">
        </div>

        <div id="bulkStatus"></div>
      </div>
      <div class="modal-footer border-0 pt-0" style="padding:0 16px 14px">
        <button type="button" class="btn btn-sm btn-ov-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-sm btn-ov-primary" onclick="saveBulk()" id="btnSaveBulk">
          <i class="fa fa-bolt me-1"></i>Affecter
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL nouvelle version ════════════════════════════════════════════════ -->
<div class="modal fade" id="versionModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content" style="border-radius:12px">
      <div class="modal-header" style="background:var(--ov-navy);border-radius:12px 12px 0 0;border:none">
        <h5 class="modal-title text-white" style="font-size:0.9rem"><i class="fa fa-code-branch me-2"></i>Nouvelle version</h5>
        <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted" style="font-size:0.83rem">La version actuelle sera archivée. Une copie complète est créée.</p>
        <label class="form-label">Commentaire <small class="text-muted">(optionnel)</small></label>
        <input type="text" id="versionNote" class="form-control form-control-sm" placeholder="Ex : Correction semaine 3">
      </div>
      <div class="modal-footer border-0 pt-0">
        <button class="btn btn-sm btn-ov-secondary" data-bs-dismiss="modal">Annuler</button>
        <button class="btn btn-sm btn-ov-primary" onclick="createVersion()"><i class="fa fa-code-branch me-1"></i>Créer</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL filtre agents ════════════════════════════════════════════════════ -->
<div class="modal fade" id="agentFilterModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:12px">
      <div class="modal-header" style="background:var(--ov-navy);border-radius:12px 12px 0 0;border:none">
        <h5 class="modal-title text-white" style="font-size:0.9rem"><i class="fa fa-eye me-2"></i>Afficher / Masquer des agents</h5>
        <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert alert-info py-1 px-2 mb-3" style="font-size:0.78rem"><i class="fa fa-info-circle me-1"></i>Les agents masqués restent dans les calculs de couverture.</div>
        <div class="d-flex justify-content-between align-items-center mb-3">
          <span style="font-size:0.85rem;font-weight:600"><?= count($allAgents) ?> agents actifs</span>
          <button class="btn btn-sm btn-ov-secondary" onclick="showAllAgents()"><i class="fa fa-eye me-1"></i>Tout afficher</button>
        </div>
        <div id="agentFilterList" style="max-height:300px;overflow-y:auto">
          <?php foreach ($allAgents as $ag): ?>
          <?php $hidden = in_array($ag['id'], $hiddenAgents); ?>
          <div class="d-flex align-items-center justify-content-between py-2" style="border-bottom:1px solid #f0f2f5">
            <div>
              <div style="font-size:0.875rem;font-weight:600"><?= h($ag['prenom'].' '.$ag['nom']) ?></div>
              <div style="font-size:0.72rem;color:#9ca3af"><?= h($ag['poste'] ?? '') ?></div>
            </div>
            <button class="btn btn-sm <?= $hidden ? 'btn-warning' : 'btn-outline-secondary' ?>"
                    data-agent-id="<?= $ag['id'] ?>"
                    onclick="toggleAgent(<?= $ag['id'] ?>, this)"
                    style="min-width:90px;font-size:0.78rem">
              <i class="fa <?= $hidden ? 'fa-eye-slash' : 'fa-eye' ?> me-1"></i><?= $hidden ? 'Masqué' : 'Visible' ?>
            </button>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button class="btn btn-ov-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
        <button class="btn btn-ov-primary btn-sm" onclick="applyAgentFilter()"><i class="fa fa-rotate me-1"></i>Appliquer & Recharger</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL export ══════════════════════════════════════════════════════════ -->
<div class="modal fade" id="exportModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:12px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.18)">
      <div class="modal-header" style="background:var(--ov-navy);border-radius:12px 12px 0 0;border:none">
        <h5 class="modal-title text-white" style="font-size:0.9rem"><i class="fa fa-file-export me-2" style="color:var(--ov-gold)"></i>Exporter le planning</h5>
        <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:16px">
        <div class="mb-3">
          <label class="form-label" style="font-size:0.82rem;font-weight:600">Période</label>
          <div id="exportPeriodInfo" style="font-size:0.85rem;color:var(--ov-navy);font-weight:700;padding:7px 12px;background:#f8f9fa;border-radius:6px;border-left:3px solid var(--ov-gold)"></div>
        </div>
        <div class="mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="exportCustomDates">
            <label class="form-check-label" style="font-size:0.82rem;font-weight:600" for="exportCustomDates">Dates spécifiques</label>
          </div>
          <div id="exportDateRange" style="display:none" class="row g-2 ps-1">
            <div class="col-6">
              <label class="form-label" style="font-size:0.78rem">Du</label>
              <input type="date" id="exportDateDebut" class="form-control form-control-sm">
            </div>
            <div class="col-6">
              <label class="form-label" style="font-size:0.78rem">Au</label>
              <input type="date" id="exportDateFin" class="form-control form-control-sm">
            </div>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:0.82rem;font-weight:600">Agents</label>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="exportAllAgents" checked>
            <label class="form-check-label" style="font-size:0.82rem" for="exportAllAgents">Tous les agents</label>
          </div>
          <div id="exportAgentList" style="display:none;max-height:160px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:8px">
            <?php foreach ($allAgents as $ag): ?>
            <div class="form-check">
              <input class="form-check-input export-agent-cb" type="checkbox" value="<?= $ag['id'] ?>" id="eag<?= $ag['id'] ?>" checked>
              <label class="form-check-label" style="font-size:0.8rem" for="eag<?= $ag['id'] ?>"><?= h($ag['prenom'].' '.$ag['nom']) ?><?= $ag['poste'] ? ' <span style="color:#9ca3af;font-size:0.72rem">('.h($ag['poste']).')</span>' : '' ?></label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label" style="font-size:0.82rem;font-weight:600">Format</label>
          <div class="d-flex gap-3 flex-wrap">
            <div class="form-check">
              <input class="form-check-input" type="radio" name="exportFormat" id="exportFmtPdf" value="pdf" checked>
              <label class="form-check-label" style="font-size:0.82rem" for="exportFmtPdf"><i class="fa fa-file-pdf me-1" style="color:#dc2626"></i>PDF (paysage)</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="exportFormat" id="exportFmtExcel" value="excel">
              <label class="form-check-label" style="font-size:0.82rem" for="exportFmtExcel"><i class="fa fa-file-excel me-1" style="color:#16a34a"></i>Excel / CSV</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="radio" name="exportFormat" id="exportFmtZip" value="zip">
              <label class="form-check-label" style="font-size:0.82rem" for="exportFmtZip"><i class="fa fa-file-zipper me-1" style="color:#7c3aed"></i>PDF individuels (ZIP)</label>
            </div>
          </div>
        </div>
        <div class="mb-0">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="exportShowFooter" checked>
            <label class="form-check-label" style="font-size:0.82rem" for="exportShowFooter">Inclure les mentions légales</label>
          </div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0" style="padding:0 16px 14px">
        <button type="button" class="btn btn-sm btn-ov-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-sm btn-ov-primary" onclick="doExport()"><i class="fa fa-download me-1"></i>Télécharger</button>
      </div>
    </div>
  </div>
</div>

<?php
$versionsMapJson = json_encode($versionsMap);
$extraJs = <<<ENDJS
<script>
document.addEventListener('DOMContentLoaded', function() {

// ── Modaux ────────────────────────────────────────────────────────────────────
var cellModal        = new bootstrap.Modal(document.getElementById('cellModal'));
var bulkModal        = new bootstrap.Modal(document.getElementById('bulkModal'));
var versionModal     = new bootstrap.Modal(document.getElementById('versionModal'));
var agentFilterModal = new bootstrap.Modal(document.getElementById('agentFilterModal'));
var exportModal      = new bootstrap.Modal(document.getElementById('exportModal'));

var versionsMap     = {$versionsMapJson};
var shifts          = {$shiftsJson};
var currentMois     = {$jsMois};
var currentAnnee    = {$jsAnnee};
var exportVersionId = {$jsVersionId};
var exportSemaine   = {$jsSemaine};
var exportVue       = '{$jsVue}';

// ── Boutons toolbar ───────────────────────────────────────────────────────────
var btnAgent = document.getElementById('btnAgentFilter');
if (btnAgent) btnAgent.addEventListener('click', function() { agentFilterModal.show(); });

var btnVer = document.getElementById('btnNewVersion');
if (btnVer) btnVer.addEventListener('click', function() { versionModal.show(); });

var btnBulk = document.getElementById('btnBulkAssign');
if (btnBulk) {
    btnBulk.addEventListener('click', function() {
        // Dates par défaut = mois courant
        var m  = String(currentMois).padStart(2, '0');
        var y  = currentAnnee;
        var last = new Date(y, currentMois, 0).getDate();
        document.getElementById('bulkDateDebut').value = y + '-' + m + '-01';
        document.getElementById('bulkDateFin').value   = y + '-' + m + '-' + String(last).padStart(2, '0');
        document.getElementById('bulkStatus').innerHTML = '';
        document.getElementById('btnSaveBulk').disabled = false;
        document.getElementById('btnSaveBulk').innerHTML = '<i class="fa fa-bolt me-1"></i>Affecter';
        bulkModal.show();
    });
}

// ── Bulk shift buttons ────────────────────────────────────────────────────────
var activeBulkDebut = '07:00';
var activeBulkFin   = '19:00';

document.querySelectorAll('.bulk-shift-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.bulk-shift-btn').forEach(function(b) {
            b.style.boxShadow = 'none';
            b.classList.remove('active');
        });
        btn.style.boxShadow = '0 0 0 3px ' + (btn.style.color || '#374151') + '40';
        btn.classList.add('active');

        if (btn.dataset.debut === '' && btn.dataset.fin === '') {
            document.getElementById('bulkLibreInputs').style.display = '';
            activeBulkDebut = '';
            activeBulkFin   = '';
        } else {
            document.getElementById('bulkLibreInputs').style.display = 'none';
            activeBulkDebut = btn.dataset.debut;
            activeBulkFin   = btn.dataset.fin;
        }
    });
});

// ── Listeners heures modal cellule ───────────────────────────────────────────
document.getElementById('modalDebut').addEventListener('change', updateCalcPreview);
document.getElementById('modalFin').addEventListener('change', updateCalcPreview);
document.getElementById('modalDebut').addEventListener('input', updateCalcPreview);
document.getElementById('modalFin').addEventListener('input', updateCalcPreview);

// ── Preset highlight dans cellModal ──────────────────────────────────────────
function highlightPreset(debutFin) {
    document.querySelectorAll('.shift-preset-btn').forEach(function(btn) {
        var key = btn.dataset.debut + '-' + btn.dataset.fin;
        if (key === debutFin && debutFin !== '-') {
            btn.style.boxShadow = '0 0 0 3px ' + (btn.style.color || '#374151') + '60';
            btn.style.opacity = '1';
        } else {
            btn.style.boxShadow = 'none';
            btn.style.opacity = '0.7';
        }
    });
}

window.applyPreset = function(btn) {
    if (btn.dataset.debut) document.getElementById('modalDebut').value = btn.dataset.debut;
    if (btn.dataset.fin)   document.getElementById('modalFin').value   = btn.dataset.fin;
    if (btn.dataset.debut === '' && btn.dataset.fin === '') {
        // Libre: vider les champs pour saisie manuelle
        document.getElementById('modalDebut').value = '';
        document.getElementById('modalFin').value   = '';
        document.getElementById('calcPreview').style.display = 'none';
    }
    highlightPreset(btn.dataset.debut + '-' + btn.dataset.fin);
    if (btn.dataset.debut) updateCalcPreview();
};

// ── openCell ─────────────────────────────────────────────────────────────────
window.openCell = function(el) {
    var date      = el.dataset.date;
    var agentId   = el.dataset.agent;
    var versionId = el.dataset.version;

    document.getElementById('modalDate').value      = date;
    document.getElementById('modalAgentId').value   = agentId;
    document.getElementById('modalVersionId').value = versionId;
    document.getElementById('modalDebut').value     = '';
    document.getElementById('modalFin').value       = '';
    document.getElementById('modalNote').value      = '';
    document.getElementById('calcPreview').style.display   = 'none';
    document.getElementById('btnDeleteLigne').style.display = 'none';
    document.getElementById('btnSaveLigne').disabled = false;
    document.getElementById('btnSaveLigne').innerHTML = '<i class="fa fa-check me-1"></i>Enregistrer';
    highlightPreset('-');

    var agentName = '';
    var row = el.closest('tr');
    if (row) {
        var nameEl = row.querySelector('.planning-agent-name');
        if (nameEl) agentName = nameEl.textContent.trim();
    }
    var p = date.split('-');
    document.getElementById('cellModalTitle').textContent = agentName + ' — ' + p[2]+'/'+p[1]+'/'+p[0];

    fetch('get_ligne.php?version_id='+versionId+'&agent_id='+agentId+'&date='+date)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ligne) {
                var debut = (data.ligne.heure_debut || '').substring(0, 5);
                var fin   = (data.ligne.heure_fin   || '').substring(0, 5);
                document.getElementById('modalDebut').value = debut;
                document.getElementById('modalFin').value   = fin;
                document.getElementById('modalNote').value  = data.ligne.note || '';
                document.getElementById('btnDeleteLigne').style.display = '';
                highlightPreset(debut + '-' + fin);
                updateCalcPreview();
            }
        }).catch(function() {});

    cellModal.show();
};

// ── Calcul aperçu ─────────────────────────────────────────────────────────────
function updateCalcPreview() {
    var debut = document.getElementById('modalDebut').value;
    var fin   = document.getElementById('modalFin').value;
    var date  = document.getElementById('modalDate').value;
    if (!debut || !fin || !date) { document.getElementById('calcPreview').style.display = 'none'; return; }

    // Highlight preset automatiquement si les heures correspondent
    highlightPreset(debut + '-' + fin);

    fetch('calc_preview.php?date='+encodeURIComponent(date)+'&debut='+encodeURIComponent(debut)+'&fin='+encodeURIComponent(fin))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var labels = {normal:'Normal',nuit:'Nuit',dimanche:'Dimanche',ferie_normal:'Férié',ferie_dimanche:'Fér.Dim',ferie_nuit:'Nuit Fér.'};
            var colors = {normal:'#374151',nuit:'#4f46e5',dimanche:'#dc2626',ferie_normal:'#92400e',ferie_dimanche:'#be185d',ferie_nuit:'#1d4ed8'};
            var html = '', total = 0;
            for (var k in data) {
                if (data[k] > 0) {
                    html += '<span style="display:inline-block;background:#fff;border:1px solid '+colors[k]+';color:'+colors[k]+';padding:2px 6px;border-radius:4px;font-size:0.72rem;margin:2px">'+labels[k]+': '+(data[k]/60).toFixed(2)+'h</span>';
                    total += data[k];
                }
            }
            if (total === 0) {
                html = '<span style="color:#9ca3af">Aucune heure calculée</span>';
            } else {
                html += '<div style="margin-top:4px;font-weight:700;color:var(--ov-navy)">Total : '+(total/60).toFixed(2)+'h</div>';
                if (fin < debut) html += '<div style="font-size:0.7rem;color:#4f46e5"><i class="fa fa-moon me-1"></i>Dépassement minuit détecté</div>';
            }
            document.getElementById('calcResult').innerHTML = html;
            document.getElementById('calcPreview').style.display = '';
        }).catch(function() {});
}

// ── saveLigne ─────────────────────────────────────────────────────────────────
window.saveLigne = function() {
    var agentId   = document.getElementById('modalAgentId').value;
    var date      = document.getElementById('modalDate').value;
    var debut     = document.getElementById('modalDebut').value;
    var fin       = document.getElementById('modalFin').value;
    var note      = document.getElementById('modalNote').value;
    var versionId = document.getElementById('modalVersionId').value;

    if (!debut || !fin) { alert('Veuillez saisir les heures de début et de fin.'); return; }
    if (!versionId || versionId === '0') { alert('Aucune version de planning disponible pour ce mois.'); return; }

    document.getElementById('btnSaveLigne').disabled = true;
    document.getElementById('btnSaveLigne').innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Enregistrement...';

    var body = new URLSearchParams({action:'save_ligne', agent_id:agentId, date:date, heure_debut:debut, heure_fin:fin, note:note, version_id:versionId});
    fetch('index.php', {method:'POST', body:body})
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) { cellModal.hide(); location.reload(); }
            else {
                alert('Erreur : ' + (d.error || 'Enregistrement échoué'));
                document.getElementById('btnSaveLigne').disabled = false;
                document.getElementById('btnSaveLigne').innerHTML = '<i class="fa fa-check me-1"></i>Enregistrer';
            }
        }).catch(function() {
            alert('Erreur réseau.');
            document.getElementById('btnSaveLigne').disabled = false;
            document.getElementById('btnSaveLigne').innerHTML = '<i class="fa fa-check me-1"></i>Enregistrer';
        });
};

// ── deleteLigne ───────────────────────────────────────────────────────────────
window.deleteLigne = function() {
    if (!confirm('Supprimer ce créneau ?')) return;
    var body = new URLSearchParams({
        action:     'delete_ligne',
        agent_id:   document.getElementById('modalAgentId').value,
        date:       document.getElementById('modalDate').value,
        version_id: document.getElementById('modalVersionId').value
    });
    fetch('index.php', {method:'POST', body:body})
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.ok) { cellModal.hide(); location.reload(); } });
};

// ── createVersion ─────────────────────────────────────────────────────────────
window.createVersion = function() {
    var note = document.getElementById('versionNote').value;
    var body = new URLSearchParams({action:'new_version', mois:currentMois, annee:currentAnnee, note:note});
    fetch('index.php', {method:'POST', body:body})
        .then(function(r) { return r.json(); })
        .then(function(d) { if (d.ok) { versionModal.hide(); location.reload(); } });
};

// ── toggleAgent / showAllAgents / applyAgentFilter ────────────────────────────
window.toggleAgent = function(agentId, btn) {
    fetch('index.php', {method:'POST', body: new URLSearchParams({action:'toggle_agent', agent_id:agentId})})
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.hidden) {
                btn.className = 'btn btn-sm btn-warning';
                btn.innerHTML = '<i class="fa fa-eye-slash me-1"></i>Masqué';
            } else {
                btn.className = 'btn btn-sm btn-outline-secondary';
                btn.innerHTML = '<i class="fa fa-eye me-1"></i>Visible';
            }
            btn.style.minWidth = '90px'; btn.style.fontSize = '0.78rem';
        });
};

window.showAllAgents = function() {
    fetch('index.php', {method:'POST', body: new URLSearchParams({action:'show_all_agents'})})
        .then(function(r) { return r.json(); })
        .then(function() {
            document.querySelectorAll('#agentFilterList .btn').forEach(function(btn) {
                btn.className = 'btn btn-sm btn-outline-secondary';
                btn.innerHTML = '<i class="fa fa-eye me-1"></i>Visible';
                btn.style.minWidth = '90px'; btn.style.fontSize = '0.78rem';
            });
        });
};

window.applyAgentFilter = function() { agentFilterModal.hide(); location.reload(); };

// ── saveBulk ──────────────────────────────────────────────────────────────────
window.saveBulk = function() {
    var agentId   = document.getElementById('bulkAgent').value;
    var dateDebut = document.getElementById('bulkDateDebut').value;
    var dateFin   = document.getElementById('bulkDateFin').value;
    var note      = document.getElementById('bulkNote').value;

    var debut = activeBulkDebut;
    var fin   = activeBulkFin;
    if (debut === '' || fin === '') {
        debut = document.getElementById('bulkDebut').value;
        fin   = document.getElementById('bulkFin').value;
    }

    if (!agentId || !dateDebut || !dateFin) { alert('Veuillez remplir agent et les dates.'); return; }
    if (!debut || !fin) { alert('Veuillez sélectionner un poste ou saisir les heures.'); return; }
    if (dateDebut > dateFin) { alert('La date de fin doit être après la date de début.'); return; }

    var jours = [];
    document.querySelectorAll('[id^="bdow"]:checked').forEach(function(cb) { jours.push(cb.value); });
    if (jours.length === 0) { alert('Sélectionnez au moins un jour de la semaine.'); return; }

    document.getElementById('btnSaveBulk').disabled = true;
    document.getElementById('btnSaveBulk').innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>En cours...';
    document.getElementById('bulkStatus').innerHTML  = '';

    var body = new URLSearchParams({
        action:      'bulk_save',
        agent_id:    agentId,
        date_debut:  dateDebut,
        date_fin:    dateFin,
        heure_debut: debut,
        heure_fin:   fin,
        jours:       jours.join(','),
        note:        note
    });

    fetch('index.php', {method:'POST', body:body})
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if (d.ok) {
                document.getElementById('bulkStatus').innerHTML =
                    '<div class="alert alert-success py-2 px-3 mt-2" style="font-size:0.82rem"><i class="fa fa-check-circle me-1"></i><strong>' + d.saved + ' créneau(x)</strong> affecté(s) avec succès.</div>';
                setTimeout(function() { bulkModal.hide(); location.reload(); }, 900);
            } else {
                alert('Erreur : ' + (d.error || 'Affectation échouée'));
                document.getElementById('btnSaveBulk').disabled = false;
                document.getElementById('btnSaveBulk').innerHTML = '<i class="fa fa-bolt me-1"></i>Affecter';
            }
        }).catch(function() {
            alert('Erreur réseau.');
            document.getElementById('btnSaveBulk').disabled = false;
            document.getElementById('btnSaveBulk').innerHTML = '<i class="fa fa-bolt me-1"></i>Affecter';
        });
};

// ── selectAllDow ──────────────────────────────────────────────────────────────
window.selectAllDow = function(state) {
    document.querySelectorAll('[id^="bdow"]').forEach(function(cb) { cb.checked = state; });
};

// ── Export modal ──────────────────────────────────────────────────────────────
var btnExportEl = document.getElementById('btnExport');
if (btnExportEl) {
    btnExportEl.addEventListener('click', function() {
        var infoEl = document.getElementById('exportPeriodInfo');
        if (exportVue === 'semaine') {
            infoEl.textContent = 'Semaine ' + exportSemaine + ' (' + currentAnnee + ')';
        } else {
            var moisNoms = ['','janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
            infoEl.textContent = moisNoms[currentMois] + ' ' + currentAnnee;
        }
        document.getElementById('exportAllAgents').checked = true;
        document.getElementById('exportAgentList').style.display = 'none';
        document.querySelector('input[name="exportFormat"][value="pdf"]').checked = true;
        exportModal.show();
    });
}

var exportAllCb = document.getElementById('exportAllAgents');
if (exportAllCb) {
    exportAllCb.addEventListener('change', function() {
        document.getElementById('exportAgentList').style.display = this.checked ? 'none' : '';
    });
}

var exportCustomDatesCb = document.getElementById('exportCustomDates');
if (exportCustomDatesCb) {
    exportCustomDatesCb.addEventListener('change', function() {
        document.getElementById('exportDateRange').style.display = this.checked ? '' : 'none';
    });
}

window.doExport = function() {
    var format    = document.querySelector('input[name="exportFormat"]:checked').value;
    var allAgents = document.getElementById('exportAllAgents').checked;
    var agentIds  = '';
    if (!allAgents) {
        var ids = [];
        document.querySelectorAll('.export-agent-cb:checked').forEach(function(cb) { ids.push(cb.value); });
        if (!ids.length) { alert('Sélectionnez au moins un agent.'); return; }
        agentIds = ids.join(',');
    }
    var customDates = document.getElementById('exportCustomDates').checked;
    var dateDebut   = customDates ? document.getElementById('exportDateDebut').value : '';
    var dateFin     = customDates ? document.getElementById('exportDateFin').value   : '';
    if (customDates && (!dateDebut || !dateFin)) {
        alert('Veuillez saisir les deux dates.');
        return;
    }
    var url;
    var isZip = format === 'zip';
    var script = isZip ? 'export_zip.php' : 'export.php';
    if (exportVue === 'semaine') {
        url = script + '?type=week&semaine=' + exportSemaine + '&annee=' + currentAnnee;
        if (!isZip) url += '&format=' + format;
    } else {
        url = script + '?version_id=' + exportVersionId;
        if (!isZip) url += '&format=' + format;
    }
    if (agentIds)  url += '&agent_ids='   + encodeURIComponent(agentIds);
    if (dateDebut) url += '&date_debut='  + encodeURIComponent(dateDebut);
    if (dateFin)   url += '&date_fin='    + encodeURIComponent(dateFin);
    if (document.getElementById('exportShowFooter').checked) url += '&footer=1';
    exportModal.hide();
    window.location.href = url;
};

}); // end DOMContentLoaded
</script>
ENDJS;

include __DIR__ . '/../../includes/footer.php';
