<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('planning', 'view');
ensureMissionsSchema();

$db = getDB();

$missions     = $db->query("SELECT id, nom FROM missions WHERE actif = 1 ORDER BY nom")->fetchAll();
$defaultMissionId = (int)$db->query("SELECT id FROM missions WHERE is_default = 1 LIMIT 1")->fetchColumn();
$missionId        = (int)($_GET['mission'] ?? 0);
if (!$missionId || !in_array($missionId, array_column($missions, 'id'))) {
    $missionId = $defaultMissionId;
}

// ── AJAX POST (filtrage agents — session partagée avec index.php) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    if ($action === 'toggle_agent') {
        $agId = (int)($_POST['agent_id'] ?? 0);
        if (!isset($_SESSION['planning_hidden'])) $_SESSION['planning_hidden'] = [];
        $idx = array_search($agId, $_SESSION['planning_hidden']);
        if ($idx !== false) { array_splice($_SESSION['planning_hidden'], $idx, 1); echo json_encode(['ok'=>true,'hidden'=>false]); }
        else                { $_SESSION['planning_hidden'][] = $agId;              echo json_encode(['ok'=>true,'hidden'=>true]);  }
        exit;
    }
    if ($action === 'show_all_agents') {
        $_SESSION['planning_hidden'] = [];
        echo json_encode(['ok'=>true]);
        exit;
    }
    echo json_encode(['ok'=>false,'error'=>'Action inconnue']);
    exit;
}

// ── Shifts ────────────────────────────────────────────────────────────────────
$shifts = [
    'J'  => ['label'=>'Journée', 'debut'=>'07:00','fin'=>'19:00','color'=>'#16a34a','bg'=>'rgba(22,163,74,0.14)'],
    'N'  => ['label'=>'Nuit',    'debut'=>'19:00','fin'=>'07:00','color'=>'#4f46e5','bg'=>'rgba(79,70,229,0.14)'],
    'M'  => ['label'=>'Matin',   'debut'=>'06:00','fin'=>'14:00','color'=>'#ea580c','bg'=>'rgba(234,88,12,0.14)'],
    'S'  => ['label'=>'Soir',    'debut'=>'14:00','fin'=>'22:00','color'=>'#7c3aed','bg'=>'rgba(124,58,237,0.14)'],
    'NC' => ['label'=>'Nuit C.', 'debut'=>'22:00','fin'=>'06:00','color'=>'#1d4ed8','bg'=>'rgba(29,78,216,0.14)'],
];

function detectShiftM(string $hD, string $hF, array $shifts): ?array {
    $hD = substr($hD,0,5); $hF = substr($hF,0,5);
    foreach ($shifts as $code => $s) {
        if ($s['debut']===$hD && $s['fin']===$hF) return ['code'=>$code,'color'=>$s['color'],'bg'=>$s['bg']];
    }
    return null;
}

function dayStatM(array $allAgents, array $planningData, string $date): array {
    $tot=$h=$hj=$hn=$fj=$fn=0;
    foreach ($allAgents as $ag) {
        $l = $planningData[$ag['id']][$date] ?? null;
        if ($l) {
            $tot++;
            $h += $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_nuit_dimanche']+$l['min_ferie_normal']+$l['min_ferie_nuit'];
            $isNuit = ($l['min_nuit']+$l['min_nuit_dimanche']+$l['min_ferie_nuit']) > 0;
            if (($ag['sexe'] ?? 'M') === 'F') { if ($isNuit) $fn++; else $fj++; }
            else                               { if ($isNuit) $hn++; else $hj++; }
        }
    }
    return ['hj'=>$hj,'hn'=>$hn,'fj'=>$fj,'fn'=>$fn,'j'=>$hj+$fj,'n'=>$hn+$fn,'t'=>$tot,'h'=>$h];
}

// ── Paramètres URL ────────────────────────────────────────────────────────────
$dateDebut = $_GET['date_debut'] ?? date('Y-m-d');
$dateFin   = $_GET['date_fin']   ?? date('Y-m-d', strtotime('+6 days'));
$dateDebut = date('Y-m-d', strtotime($dateDebut));
$dateFin   = date('Y-m-d', strtotime($dateFin));
if ($dateDebut > $dateFin) [$dateDebut, $dateFin] = [$dateFin, $dateDebut];
$nbJours = (int)((strtotime($dateFin) - strtotime($dateDebut)) / 86400) + 1;
if ($nbJours > 62) {
    $dateFin = date('Y-m-d', strtotime($dateDebut . ' +61 days'));
    $nbJours = 62;
}

// ── Dates ─────────────────────────────────────────────────────────────────────
$dates = [];
$dIter = new DateTime($dateDebut);
for ($i = 0; $i < $nbJours; $i++) {
    $dates[] = clone $dIter;
    $dIter->modify('+1 day');
}

// ── Agents ────────────────────────────────────────────────────────────────────
$hiddenAgents = $_SESSION['planning_hidden'] ?? [];
$stmtAgM = $db->prepare("
    SELECT a.id, a.nom, a.prenom, a.poste, a.sexe
    FROM agents a
    JOIN mission_agents ma ON ma.agent_id = a.id AND ma.mission_id = ?
    WHERE a.actif=1 ORDER BY CASE WHEN a.sexe='F' THEN 0 ELSE 1 END, a.nom, a.prenom
");
$stmtAgM->execute([$missionId]);
$allAgents    = $stmtAgM->fetchAll();
$agents       = array_values(array_filter($allAgents, fn($ag) => !in_array($ag['id'], $hiddenAgents)));

// ── Jours fériés ──────────────────────────────────────────────────────────────
$anneeDebut = (int)substr($dateDebut, 0, 4);
$anneeFin   = (int)substr($dateFin, 0, 4);
$feries = getJoursFeries($anneeDebut);
if ($anneeFin !== $anneeDebut) $feries = array_merge($feries, getJoursFeries($anneeFin));

// ── Versions (versions courantes par mois) ────────────────────────────────────
$canEdit = canDo('planning', 'create');
$versionsMap = [];
$stmtVmM = $db->prepare("SELECT id,mois,annee FROM planning_versions WHERE mission_id=? AND is_current=1");
$stmtVmM->execute([$missionId]);
foreach ($stmtVmM->fetchAll() as $v) {
    $versionsMap[$v['annee'].'-'.sprintf('%02d',$v['mois'])] = (int)$v['id'];
}

// Auto-créer les versions manquantes pour les mois couverts (si droits)
if ($canEdit) {
    $moisCouverts = [];
    foreach ($dates as $dt) {
        $k = $dt->format('Y').'-'.sprintf('%02d',(int)$dt->format('n'));
        if (!isset($moisCouverts[$k])) $moisCouverts[$k] = [(int)$dt->format('n'), (int)$dt->format('Y')];
    }
    foreach ($moisCouverts as $k => [$m, $y]) {
        if (!isset($versionsMap[$k])) {
            $db->prepare("INSERT INTO planning_versions (mission_id,mois,annee,version,is_current,created_by) VALUES (?,?,?,1,1,?)")
               ->execute([$missionId, $m, $y, getCurrentUser()['id']]);
            $versionsMap[$k] = (int)$db->lastInsertId();
        }
    }
}

// ── Planning data ─────────────────────────────────────────────────────────────
$planningData = [];
$stmtP = $db->prepare("
    SELECT pl.* FROM planning_lignes pl
    JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1 AND pv.mission_id = ?
    WHERE pl.date_travail BETWEEN ? AND ?
");
$stmtP->execute([$missionId, $dateDebut, $dateFin]);
foreach ($stmtP->fetchAll() as $l) {
    $planningData[$l['agent_id']][$l['date_travail']] = $l;
}

// ── Stats de couverture ───────────────────────────────────────────────────────
$dayStats = [];
foreach ($dates as $dt) {
    $dayStats[$dt->format('Y-m-d')] = dayStatM($allAgents, $planningData, $dt->format('Y-m-d'));
}

// ── Navigation ────────────────────────────────────────────────────────────────
$prevDebut = date('Y-m-d', strtotime($dateDebut . " -$nbJours days"));
$prevFin   = date('Y-m-d', strtotime($dateFin   . " -$nbJours days"));
$nextDebut = date('Y-m-d', strtotime($dateDebut . " +$nbJours days"));
$nextFin   = date('Y-m-d', strtotime($dateFin   . " +$nbJours days"));

// ── Affichage adaptatif ───────────────────────────────────────────────────────
$colWidth     = $nbJours <= 10 ? '115px' : ($nbJours <= 21 ? '78px' : '52px');
$cellFontSize = $nbJours <= 10 ? '0.68rem' : ($nbJours <= 21 ? '0.58rem' : '0.52rem');
$shiftFontSz  = $nbJours <= 10 ? '1.05rem' : ($nbJours <= 21 ? '0.78rem' : '0.65rem');
$showTimes    = $nbJours <= 21;

$nomsJours = ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
$moisFr    = ['','jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];
$dfDebut   = date('d/m/Y', strtotime($dateDebut));
$dfFin     = date('d/m/Y', strtotime($dateFin));

// ── Export & JS data ──────────────────────────────────────────────────────────
$agentIdsStr    = implode(',', array_column($agents, 'id'));
$exportBase     = "export.php?type=mission&date_debut=$dateDebut&date_fin=$dateFin&agent_ids=$agentIdsStr&mission=$missionId";
$versionsMapJson = json_encode($versionsMap);
$shiftsJson      = json_encode($shifts);

$pageTitle     = "Planning Mission — $dfDebut → $dfFin";
$currentModule = 'planning';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- ═══ TOOLBAR ══════════════════════════════════════════════════════════════ -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <div class="btn-group btn-group-sm me-1">
    <?php
      $moisActuel    = (int)date('n');
      $anneeActuelle = (int)date('Y');
      $semActuelle   = (int)date('W');
    ?>
    <a href="index.php?vue=mois&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>&mission=<?= $missionId ?>" class="btn btn-ov-secondary"><i class="fa fa-calendar me-1"></i>Mensuel</a>
    <a href="index.php?vue=semaine&semaine=<?= $semActuelle ?>&annee=<?= $anneeActuelle ?>&mission=<?= $missionId ?>" class="btn btn-ov-secondary"><i class="fa fa-calendar-week me-1"></i>Hebdo</a>
    <button class="btn btn-dark" disabled><i class="fa fa-map-marker-alt me-1"></i>Mission</button>
  </div>

  <select class="form-select form-select-sm" style="width:auto" onchange="location.href='?date_debut=<?= $dateDebut ?>&date_fin=<?= $dateFin ?>&mission='+this.value">
    <?php foreach ($missions as $m): ?>
    <option value="<?= $m['id'] ?>" <?= $m['id']==$missionId?'selected':'' ?>><?= h($m['nom']) ?></option>
    <?php endforeach; ?>
  </select>

  <a href="?date_debut=<?= $prevDebut ?>&date_fin=<?= $prevFin ?>&mission=<?= $missionId ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-left"></i></a>
  <span style="font-weight:700;font-size:0.92rem;color:var(--ov-navy);white-space:nowrap">
    <i class="fa fa-map-marker-alt me-1" style="color:var(--ov-gold)"></i>
    <?= $dfDebut ?> → <?= $dfFin ?>
    <span style="font-size:0.72rem;font-weight:400;color:#9ca3af;margin-left:4px">(<?= $nbJours ?> j)</span>
  </span>
  <a href="?date_debut=<?= $nextDebut ?>&date_fin=<?= $nextFin ?>&mission=<?= $missionId ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-right"></i></a>

  <form method="get" class="d-flex align-items-center gap-1" style="flex-wrap:nowrap">
    <input type="hidden" name="mission" value="<?= $missionId ?>">
    <input type="date" name="date_debut" class="form-control form-control-sm" value="<?= $dateDebut ?>" style="width:140px" required>
    <span style="color:#9ca3af;font-size:0.8rem">→</span>
    <input type="date" name="date_fin"   class="form-control form-control-sm" value="<?= $dateFin   ?>" style="width:140px" required>
    <button type="submit" class="btn btn-ov-primary btn-sm"><i class="fa fa-search me-1"></i>Voir</button>
  </form>

  <div class="ms-auto d-flex gap-2 flex-wrap">
    <?php if ($canEdit): ?>
    <button class="btn btn-ov-primary btn-sm" id="btnBulkAssign"><i class="fa fa-bolt me-1"></i>Affecter</button>
    <?php endif; ?>
    <button class="btn btn-ov-secondary btn-sm" id="btnAgentFilterM">
      <i class="fa fa-eye me-1"></i>Agents
      <?php if ($hiddenAgents): ?><span class="badge bg-warning text-dark"><?= count($hiddenAgents) ?></span><?php endif; ?>
    </button>
    <?php if (canDo('planning','export')): ?>
    <a href="<?= $exportBase ?>&format=pdf" target="_blank" class="btn btn-sm"
       style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.3rem 0.7rem;font-size:0.82rem">
      <i class="fa fa-file-pdf me-1"></i>PDF
    </a>
    <a href="<?= $exportBase ?>&format=excel" class="btn btn-sm"
       style="background:rgba(34,197,94,0.1);color:#16a34a;border:1px solid rgba(34,197,94,0.2);border-radius:8px;padding:0.3rem 0.7rem;font-size:0.82rem">
      <i class="fa fa-file-csv me-1"></i>CSV
    </a>
    <?php endif; ?>
  </div>
</div>

<!-- LÉGENDE -->
<div class="d-flex gap-2 mb-2 flex-wrap" style="font-size:0.72rem">
  <?php foreach ($shifts as $code => $s): ?>
  <span style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>;border:1px solid <?= $s['color'] ?>;padding:2px 8px;border-radius:5px;font-weight:700">
    <?= $code ?> <span style="font-weight:400;opacity:0.8"><?= $s['debut'] ?>–<?= $s['fin'] ?></span>
  </span>
  <?php endforeach; ?>
  <?php if ($canEdit): ?>
  <span style="background:#f8f9fa;color:#6b7280;border:1px solid #e5e7eb;padding:2px 8px;border-radius:5px"><i class="fa fa-hand-pointer me-1" style="font-size:0.65rem"></i>Cliquer pour modifier</span>
  <?php endif; ?>
  <span class="ms-auto" style="color:#9ca3af">■ <span style="color:#4ade80">Couv. complète</span> ■ <span style="color:#fbbf24">Partielle</span> ■ <span style="color:#f87171">Non couverte</span></span>
</div>

<!-- ═══ GRILLE ════════════════════════════════════════════════════════════════ -->
<?php if (empty($agents)): ?>
<div class="ov-card"><div class="ov-card-body text-center text-muted py-5">
  <i class="fa fa-users fa-2x mb-2 d-block" style="opacity:0.3"></i>Aucun agent actif.
</div></div>
<?php else: ?>
<div class="ov-card">
  <div class="ov-card-body p-0">
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;min-width:600px">
      <thead>
        <tr>
          <th style="min-width:135px;padding:8px 10px;background:#f8f9fa;font-size:0.76rem;font-weight:600;color:var(--ov-navy);border-bottom:2px solid #e5e7eb;position:sticky;left:0;z-index:2;text-align:left">Agent</th>
          <?php
          $prevMonth = null;
          foreach ($dates as $dt):
            $dateStr   = $dt->format('Y-m-d');
            $jourSem   = (int)$dt->format('N');
            $isFer     = in_array($dateStr, $feries);
            $isToday   = $dateStr === date('Y-m-d');
            $curMonth  = $dt->format('m');
            $isNewMonth = ($curMonth !== $prevMonth);
            $prevMonth  = $curMonth;
            $bgHead = $isFer ? '#fff3cd' : ($jourSem===7 ? '#fef2f2' : ($jourSem===6 ? '#eff6ff' : ($isToday ? '#f0fdf4' : '#f8f9fa')));
            $colHead = $isFer ? '#92400e' : ($jourSem===7 ? '#dc2626' : ($jourSem===6 ? '#4338ca' : ($isToday ? '#16a34a' : 'var(--ov-navy)')));
            $blHead  = $isNewMonth && $dateStr !== $dateDebut ? 'border-left:2px solid #c9a84c;' : '';
          ?>
          <th style="min-width:<?= $colWidth ?>;max-width:<?= $colWidth ?>;padding:4px 2px;text-align:center;background:<?= $bgHead ?>;color:<?= $colHead ?>;border-bottom:2px solid #e5e7eb;font-size:<?= $cellFontSize ?>;<?= $blHead ?>">
            <?php if ($isNewMonth): ?><div style="font-size:0.55rem;font-weight:700;color:var(--ov-gold);text-transform:uppercase;letter-spacing:.03em;line-height:1"><?= $moisFr[(int)$dt->format('n')] ?></div><?php endif; ?>
            <div style="font-size:0.58rem;opacity:0.8;line-height:1.1"><?= $nomsJours[$jourSem] ?></div>
            <div style="font-size:0.88rem;font-weight:800;line-height:1.2"><?= $dt->format('d') ?></div>
            <?php if ($isFer): ?><div style="font-size:0.48rem;font-weight:700;color:#92400e;background:#fde68a;border-radius:2px;padding:0 2px;line-height:1.3">F</div><?php endif; ?>
          </th>
          <?php endforeach; ?>
          <th style="min-width:56px;padding:6px 4px;text-align:center;background:#f8f9fa;border-left:2px solid #e5e7eb;border-bottom:2px solid #e5e7eb;font-size:0.65rem;font-weight:600;color:var(--ov-navy)">Total</th>
        </tr>
      </thead>
      <tbody>
      <?php $prevSexeMis = null; foreach ($agents as $ag):
        $totalMin = 0;
        $curSexeMis = $ag['sexe'] ?? 'M';
        $isSepMis   = ($prevSexeMis === 'F' && $curSexeMis === 'M');
        $prevSexeMis = $curSexeMis;
      ?>
      <tr class="planning-row" style="border-bottom:1px solid #f0f2f5;<?= $isSepMis ? 'border-top:2px dashed #cbd5e1' : '' ?>">
        <td style="position:sticky;left:0;z-index:1;background:#fff;padding:6px 10px;border-right:1px solid #e5e7eb;<?= $isSepMis ? 'border-top:2px dashed #cbd5e1' : '' ?>">
          <div class="planning-agent-name" style="font-weight:600;font-size:0.78rem;color:var(--ov-navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px" title="<?= h($ag['prenom'].' '.$ag['nom']) ?>"><?= h($ag['prenom'].' '.$ag['nom']) ?></div>
          <?php if ($ag['poste']): ?><div style="font-size:0.62rem;color:#9ca3af"><?= h($ag['poste']) ?></div><?php endif; ?>
        </td>
        <?php
        $prevMonthRow = null;
        foreach ($dates as $dt):
          $dateStr   = $dt->format('Y-m-d');
          $jourSem   = (int)$dt->format('N');
          $isFer     = in_array($dateStr, $feries);
          $isToday   = $dateStr === date('Y-m-d');
          $curMonth  = $dt->format('m');
          $isNewMRow = ($curMonth !== $prevMonthRow);
          $prevMonthRow = $curMonth;
          $blCell = $isNewMRow && $dateStr !== $dateDebut ? 'border-left:2px solid rgba(201,168,76,0.3);' : '';
          $bgCell = $isFer ? 'rgba(234,179,8,0.09)' : ($jourSem===7 ? 'rgba(239,68,68,0.05)' : ($isToday ? 'rgba(22,163,74,0.04)' : 'transparent'));
          $verKey = $dt->format('Y').'-'.sprintf('%02d',(int)$dt->format('n'));
          $verIdCell = $versionsMap[$verKey] ?? 0;
          $ligne  = $planningData[$ag['id']][$dateStr] ?? null;

          if ($ligne):
            $hDeb    = substr($ligne['heure_debut'],0,5);
            $hFin    = substr($ligne['heure_fin'],0,5);
            $minT    = $ligne['min_normal']+$ligne['min_nuit']+$ligne['min_dimanche']+$ligne['min_nuit_dimanche']+$ligne['min_ferie_normal']+$ligne['min_ferie_nuit'];
            $totalMin += $minT;
            $shiftInfo = detectShiftM($hDeb, $hFin, $shifts);
            $cellBg  = $shiftInfo ? $shiftInfo['bg'] : ($bgCell ?: 'rgba(0,0,0,0.02)');
            $color   = $shiftInfo ? $shiftInfo['color'] : '#374151';
            $dur     = $minT >= 60 ? round($minT/60).'h' : $minT.'min';
        ?>
        <td style="padding:3px 2px;text-align:center;background:<?= $cellBg ?>;<?= $blCell ?><?= $canEdit ? 'cursor:pointer;' : '' ?>"
            <?php if ($canEdit): ?>onclick="openCell(this)" data-date="<?= $dateStr ?>" data-agent="<?= $ag['id'] ?>" data-version="<?= $verIdCell ?>"<?php endif; ?>
            title="<?= h($ag['prenom'].' '.$ag['nom']) ?> — <?= $dt->format('d/m/Y') ?> | <?= h(formatHeureCourte($hDeb).' - '.formatHeureCourte($hFin)) ?><?= $ligne['note'] ? ' | '.h($ligne['note']) : '' ?>">
          <div style="font-size:<?= $shiftFontSz ?>;font-weight:900;color:<?= $color ?>;line-height:1.1"><?= $shiftInfo ? $shiftInfo['code'] : '·' ?></div>
          <?php if ($showTimes): ?><div style="font-size:0.5rem;color:<?= $color ?>;opacity:0.85;line-height:1.1"><?= h(formatHeureCourte($hDeb)) ?>-<?= h(formatHeureCourte($hFin)) ?></div><?php endif; ?>
          <div style="font-size:0.5rem;color:#6b7280;line-height:1.1"><?= $dur ?></div>
          <?php if ($ligne['note']): ?><div style="font-size:0.45rem;color:#9ca3af" title="<?= h($ligne['note']) ?>">📝</div><?php endif; ?>
        </td>
        <?php else: ?>
        <td style="padding:3px 2px;text-align:center;background:<?= $bgCell ?: 'transparent' ?>;<?= $blCell ?><?= $canEdit ? 'cursor:pointer;' : '' ?>"
            <?php if ($canEdit): ?>onclick="openCell(this)" data-date="<?= $dateStr ?>" data-agent="<?= $ag['id'] ?>" data-version="<?= $verIdCell ?>"<?php endif; ?>>
          <span style="color:#e5e7eb;font-size:0.65rem">—</span>
        </td>
        <?php endif; endforeach; ?>
        <td style="padding:4px 6px;text-align:center;border-left:2px solid #e5e7eb;font-weight:700;font-size:0.72rem;color:<?= $totalMin > 0 ? '#16a34a' : '#d1d5db' ?>">
          <?= $totalMin > 0 ? number_format($totalMin/60, 1).'h' : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>

      <!-- Ligne couverture -->
      <tr style="border-top:2px solid #e5e7eb">
        <td style="position:sticky;left:0;z-index:1;background:#f1f5f9;padding:5px 10px;font-size:0.65rem;font-weight:600;color:#6b7280;border-right:1px solid #e5e7eb">Couverture</td>
        <?php
        $prevMonthCov = null;
        foreach ($dates as $dt):
          $dateStr = $dt->format('Y-m-d');
          $stat    = $dayStats[$dateStr];
          $curMonth = $dt->format('m');
          $blCov = ($curMonth !== $prevMonthCov && $dateStr !== $dateDebut) ? 'border-left:2px solid rgba(201,168,76,0.3);' : '';
          $prevMonthCov = $curMonth;
          if ($stat['t'] === 0)                         { $covBg='#fef2f2'; $covBord='#f87171'; }
          elseif ($stat['j'] > 0 && $stat['n'] > 0)    { $covBg='#f0fdf4'; $covBord='#4ade80'; }
          else                                           { $covBg='#fffbeb'; $covBord='#fbbf24'; }
        ?>
        <td style="text-align:center;background:<?= $covBg ?>;border-top:2px solid <?= $covBord ?>;padding:3px 1px;<?= $blCov ?>">
          <?php if ($stat['t'] > 0):
            $hTot = $stat['hj']+$stat['hn']; $fTot = $stat['fj']+$stat['fn']; ?>
          <?php if ($hTot > 0): ?><div style="font-size:0.52rem;color:#3b82f6;font-weight:700;line-height:1.3">H:<?php if ($stat['hj']>0): ?> <span style="color:#16a34a"><?= $stat['hj'] ?>j</span><?php endif; ?><?php if ($stat['hn']>0): ?><?= $stat['hj']>0?' ,':'' ?> <span style="color:#4f46e5"><?= $stat['hn'] ?>n</span><?php endif; ?></div><?php endif; ?>
          <?php if ($fTot > 0): ?><div style="font-size:0.52rem;color:#ec4899;font-weight:700;line-height:1.3">F:<?php if ($stat['fj']>0): ?> <span style="color:#16a34a"><?= $stat['fj'] ?>j</span><?php endif; ?><?php if ($stat['fn']>0): ?><?= $stat['fj']>0?' ,':'' ?> <span style="color:#4f46e5"><?= $stat['fn'] ?>n</span><?php endif; ?></div><?php endif; ?>
          <?php else: ?><div style="font-size:0.55rem;color:#f87171">—</div><?php endif; ?>
        </td>
        <?php endforeach; ?>
        <td style="background:#f1f5f9;border-left:2px solid #e5e7eb"></td>
      </tr>

      <!-- Ligne total heures/jour -->
      <tr>
        <td style="position:sticky;left:0;z-index:1;background:#f8f9fa;padding:5px 10px;font-size:0.65rem;font-weight:600;color:#6b7280;border-right:1px solid #e5e7eb"><i class="fa fa-clock me-1"></i>Total h/jour</td>
        <?php
        $grandTotal = 0;
        $prevMonthTot = null;
        foreach ($dates as $dt):
          $dateStr = $dt->format('Y-m-d');
          $stat    = $dayStats[$dateStr];
          $grandTotal += $stat['h'];
          $blTot = ($dt->format('m') !== $prevMonthTot && $dateStr !== $dateDebut) ? 'border-left:2px solid rgba(201,168,76,0.3);' : '';
          $prevMonthTot = $dt->format('m');
        ?>
        <td style="text-align:center;background:#f8f9fa;padding:3px 1px;<?= $blTot ?>">
          <span style="font-size:0.57rem;font-weight:600;color:<?= $stat['h'] > 0 ? 'var(--ov-navy)' : '#d1d5db' ?>">
            <?= $stat['h'] > 0 ? number_format($stat['h']/60,1).'h' : '—' ?>
          </span>
        </td>
        <?php endforeach; ?>
        <td style="text-align:center;background:#f8f9fa;border-left:2px solid #e5e7eb;font-size:0.7rem;font-weight:700;color:var(--ov-navy)"><?= number_format($grandTotal/60,1) ?>h</td>
      </tr>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ═══ MODAL saisie horaires ════════════════════════════════════════════════ -->
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
        <div class="mb-3">
          <div style="font-size:0.75rem;font-weight:600;color:#6b7280;margin-bottom:6px">Poste rapide</div>
          <div class="d-flex gap-1 flex-wrap" id="shiftPresets">
            <?php foreach ($shifts as $code => $s): ?>
            <button type="button" class="shift-preset-btn" data-debut="<?= $s['debut'] ?>" data-fin="<?= $s['fin'] ?>" onclick="applyPreset(this)"
                    style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>;border:1.5px solid <?= $s['color'] ?>;border-radius:6px;font-weight:800;font-size:0.78rem;padding:5px 9px;cursor:pointer;transition:all 0.15s">
              <?= $code ?><br><span style="font-size:0.6rem;font-weight:400;opacity:0.85"><?= $s['debut'] ?>–<?= $s['fin'] ?></span>
            </button>
            <?php endforeach; ?>
            <button type="button" class="shift-preset-btn" data-debut="" data-fin="" onclick="applyPreset(this)"
                    style="background:#f8f9fa;color:#374151;border:1.5px solid #d1d5db;border-radius:6px;font-weight:700;font-size:0.78rem;padding:5px 9px;cursor:pointer">
              Libre<br><span style="font-size:0.6rem;font-weight:400;opacity:0.7">Personnalisé</span>
            </button>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label" style="font-size:0.78rem">Heure début</label>
            <input type="time" id="modalDebut" class="form-control form-control-sm">
          </div>
          <div class="col-6">
            <label class="form-label" style="font-size:0.78rem">Heure fin</label>
            <input type="time" id="modalFin" class="form-control form-control-sm">
          </div>
          <div class="col-12"><div class="form-text" style="font-size:0.7rem"><i class="fa fa-moon me-1 text-primary"></i>Fin &lt; Début → dépassement minuit détecté auto.</div></div>
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
        <button type="button" class="btn btn-sm btn-outline-danger me-auto" onclick="deleteLigne()"><i class="fa fa-eraser me-1"></i>Effacer</button>
        <button type="button" class="btn btn-sm btn-ov-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-sm btn-ov-primary" onclick="saveLigne()" id="btnSaveLigne"><i class="fa fa-check me-1"></i>Enregistrer</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL affectation en masse ══════════════════════════════════════════ -->
<div class="modal fade" id="bulkModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:12px;border:none;box-shadow:0 20px 60px rgba(0,0,0,0.18)">
      <div class="modal-header" style="background:var(--ov-navy);border-radius:12px 12px 0 0;border:none">
        <h5 class="modal-title text-white" style="font-size:0.9rem"><i class="fa fa-bolt me-2" style="color:var(--ov-gold)"></i>Affecter en masse</h5>
        <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" style="padding:16px">
        <div class="mb-3">
          <label class="form-label" style="font-size:0.82rem;font-weight:600">Agent</label>
          <select id="bulkAgent" class="form-select form-select-sm">
            <?php foreach ($allAgents as $ag): ?>
            <option value="<?= $ag['id'] ?>"><?= h($ag['prenom'].' '.$ag['nom']) ?><?= $ag['poste'] ? ' — '.h($ag['poste']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
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
            <button type="button" class="bulk-shift-btn" data-debut="" data-fin=""
                    style="background:#f8f9fa;color:#374151;border:1.5px solid #d1d5db;border-radius:6px;font-weight:700;font-size:0.8rem;padding:6px 10px;cursor:pointer">Libre</button>
            <button type="button" class="bulk-shift-btn" data-debut="__vide__" data-fin="__vide__"
                    style="background:#fff5f5;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;font-weight:700;font-size:0.8rem;padding:6px 10px;cursor:pointer"><i class="fa fa-eraser me-1"></i>Vide</button>
          </div>
          <div id="bulkLibreInputs" style="display:none" class="row g-2">
            <div class="col-6"><input type="time" id="bulkDebut" class="form-control form-control-sm"></div>
            <div class="col-6"><input type="time" id="bulkFin"   class="form-control form-control-sm"></div>
          </div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label" style="font-size:0.82rem;font-weight:600">Du</label>
            <input type="date" id="bulkDateDebut" class="form-control form-control-sm" value="<?= $dateDebut ?>">
          </div>
          <div class="col-6">
            <label class="form-label" style="font-size:0.82rem;font-weight:600">Au</label>
            <input type="date" id="bulkDateFin" class="form-control form-control-sm" value="<?= $dateFin ?>">
          </div>
        </div>
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
        <div class="mb-2">
          <label class="form-label" style="font-size:0.82rem;font-weight:600">Note <small class="text-muted">(optionnel)</small></label>
          <input type="text" id="bulkNote" class="form-control form-control-sm" placeholder="Site, mission, remplacement...">
        </div>
        <div id="bulkStatus"></div>
      </div>
      <div class="modal-footer border-0 pt-0" style="padding:0 16px 14px">
        <button type="button" class="btn btn-sm btn-ov-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-sm btn-ov-primary" onclick="saveBulk()" id="btnSaveBulk"><i class="fa fa-bolt me-1"></i>Affecter</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL filtre agents ══════════════════════════════════════════════════ -->
<div class="modal fade" id="agentFilterModalM" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content" style="border-radius:12px">
      <div class="modal-header" style="background:var(--ov-navy);border-radius:12px 12px 0 0;border:none">
        <h5 class="modal-title text-white" style="font-size:0.9rem"><i class="fa fa-eye me-2"></i>Afficher / Masquer des agents</h5>
        <button type="button" class="btn-close btn-close-white btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div style="padding:10px 16px;background:#f8f9fa;border-bottom:1px solid #e5e7eb">
          <button class="btn btn-sm btn-ov-secondary" onclick="showAllAgents()"><i class="fa fa-eye me-1"></i>Tout afficher</button>
        </div>
        <ul class="list-group list-group-flush">
          <?php foreach ($allAgents as $ag): $isHidden = in_array($ag['id'], $hiddenAgents); ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2">
            <span style="font-size:0.85rem;<?= $isHidden ? 'opacity:0.4;text-decoration:line-through' : '' ?>"><?= h($ag['prenom'].' '.$ag['nom']) ?></span>
            <button class="btn btn-sm <?= $isHidden ? 'btn-warning' : 'btn-outline-secondary' ?>"
                    onclick="toggleAgent(<?= $ag['id'] ?>, this)" style="min-width:90px;font-size:0.78rem">
              <i class="fa <?= $isHidden ? 'fa-eye-slash' : 'fa-eye' ?> me-1"></i><?= $isHidden ? 'Masqué' : 'Visible' ?>
            </button>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button class="btn btn-ov-secondary btn-sm" data-bs-dismiss="modal">Fermer</button>
        <button class="btn btn-ov-primary btn-sm" onclick="agentFilterModalInst.hide(); location.reload()"><i class="fa fa-rotate me-1"></i>Appliquer & Recharger</button>
      </div>
    </div>
  </div>
</div>

<?php
$versionsMapJson = json_encode($versionsMap);
$shiftsJson      = json_encode($shifts);
echo <<<ENDJS
<script>
document.addEventListener('DOMContentLoaded', function() {

var cellModal         = new bootstrap.Modal(document.getElementById('cellModal'));
var bulkModal         = new bootstrap.Modal(document.getElementById('bulkModal'));
var agentFilterModalInst = new bootstrap.Modal(document.getElementById('agentFilterModalM'));
var versionsMap       = $versionsMapJson;
var shifts            = $shiftsJson;
var currentMissionId  = $missionId;
var activeBulkDebut   = '07:00';
var activeBulkFin     = '19:00';

// ── Toolbar buttons ───────────────────────────────────────────────────────────
document.getElementById('btnAgentFilterM').addEventListener('click', function() { agentFilterModalInst.show(); });

var btnBulk = document.getElementById('btnBulkAssign');
if (btnBulk) btnBulk.addEventListener('click', function() {
    document.getElementById('bulkStatus').innerHTML = '';
    document.getElementById('btnSaveBulk').disabled = false;
    document.getElementById('btnSaveBulk').innerHTML = '<i class="fa fa-bolt me-1"></i>Affecter';
    bulkModal.show();
});

// ── Bulk shift buttons ────────────────────────────────────────────────────────
document.querySelectorAll('.bulk-shift-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.bulk-shift-btn').forEach(function(b) { b.style.boxShadow='none'; });
        btn.style.boxShadow = '0 0 0 3px ' + (btn.style.color||'#374151') + '40';
        if (btn.dataset.debut === '__vide__') {
            document.getElementById('bulkLibreInputs').style.display = 'none';
            activeBulkDebut = '__vide__'; activeBulkFin = '__vide__';
            document.getElementById('btnSaveBulk').innerHTML = '<i class="fa fa-eraser me-1"></i>Effacer';
        } else if (btn.dataset.debut === '' && btn.dataset.fin === '') {
            document.getElementById('bulkLibreInputs').style.display = '';
            activeBulkDebut = ''; activeBulkFin = '';
            document.getElementById('btnSaveBulk').innerHTML = '<i class="fa fa-bolt me-1"></i>Affecter';
        } else {
            document.getElementById('bulkLibreInputs').style.display = 'none';
            activeBulkDebut = btn.dataset.debut; activeBulkFin = btn.dataset.fin;
            document.getElementById('btnSaveBulk').innerHTML = '<i class="fa fa-bolt me-1"></i>Affecter';
        }
    });
});

// ── Calcul aperçu ─────────────────────────────────────────────────────────────
document.getElementById('modalDebut').addEventListener('input', updateCalcPreview);
document.getElementById('modalFin').addEventListener('input',   updateCalcPreview);

function updateCalcPreview() {
    var debut = document.getElementById('modalDebut').value;
    var fin   = document.getElementById('modalFin').value;
    var date  = document.getElementById('modalDate').value;
    if (!debut || !fin || !date) { document.getElementById('calcPreview').style.display='none'; return; }
    highlightPreset(debut+'-'+fin);
    fetch('calc_preview.php?date='+encodeURIComponent(date)+'&debut='+encodeURIComponent(debut)+'&fin='+encodeURIComponent(fin))
        .then(function(r){return r.json();}).then(function(data){
            var labels={normal:'Normal',nuit:'Nuit',dimanche:'Dimanche',nuit_dimanche:'Nuit Dim.',ferie_normal:'Férié',ferie_nuit:'Nuit Fér.'};
            var colors={normal:'#374151',nuit:'#4f46e5',dimanche:'#dc2626',nuit_dimanche:'#7c3aed',ferie_normal:'#92400e',ferie_nuit:'#1d4ed8'};
            var html='',total=0;
            for(var k in data){ if(data[k]>0){ html+='<span style="display:inline-block;background:#fff;border:1px solid '+colors[k]+';color:'+colors[k]+';padding:2px 6px;border-radius:4px;font-size:0.72rem;margin:2px">'+labels[k]+': '+(data[k]/60).toFixed(2)+'h</span>'; total+=data[k]; } }
            if(total===0) html='<span style="color:#9ca3af">Aucune heure calculée</span>';
            else { html+='<div style="margin-top:4px;font-weight:700;color:var(--ov-navy)">Total : '+(total/60).toFixed(2)+'h</div>'; if(fin<debut) html+='<div style="font-size:0.7rem;color:#4f46e5"><i class="fa fa-moon me-1"></i>Dépassement minuit détecté</div>'; }
            document.getElementById('calcResult').innerHTML = html;
            document.getElementById('calcPreview').style.display = '';
        }).catch(function(){});
}

function highlightPreset(key) {
    document.querySelectorAll('.shift-preset-btn').forEach(function(btn) {
        var k = btn.dataset.debut+'-'+btn.dataset.fin;
        btn.style.boxShadow = (k===key && key!=='-') ? '0 0 0 3px '+(btn.style.color||'#374151')+'60' : 'none';
        btn.style.opacity   = (k===key && key!=='-') ? '1' : '0.7';
    });
}

window.applyPreset = function(btn) {
    if (btn.dataset.debut==='' && btn.dataset.fin==='') {
        document.getElementById('modalDebut').value = '';
        document.getElementById('modalFin').value   = '';
        document.getElementById('calcPreview').style.display = 'none';
    } else {
        if (btn.dataset.debut) document.getElementById('modalDebut').value = btn.dataset.debut;
        if (btn.dataset.fin)   document.getElementById('modalFin').value   = btn.dataset.fin;
        updateCalcPreview();
    }
    highlightPreset(btn.dataset.debut+'-'+btn.dataset.fin);
};

// ── openCell ──────────────────────────────────────────────────────────────────
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
    document.getElementById('calcPreview').style.display = 'none';
    document.getElementById('btnSaveLigne').disabled = false;
    document.getElementById('btnSaveLigne').innerHTML = '<i class="fa fa-check me-1"></i>Enregistrer';
    highlightPreset('-');
    var row = el.closest('tr');
    var agentName = row ? (row.querySelector('.planning-agent-name')||{textContent:''}).textContent.trim() : '';
    var p = date.split('-');
    document.getElementById('cellModalTitle').textContent = agentName + ' — ' + p[2]+'/'+p[1]+'/'+p[0];
    fetch('get_ligne.php?version_id='+versionId+'&agent_id='+agentId+'&date='+date)
        .then(function(r){return r.json();}).then(function(data){
            if (data.ligne) {
                var d = (data.ligne.heure_debut||'').substring(0,5);
                var f = (data.ligne.heure_fin||'').substring(0,5);
                document.getElementById('modalDebut').value = d;
                document.getElementById('modalFin').value   = f;
                document.getElementById('modalNote').value  = data.ligne.note||'';
                highlightPreset(d+'-'+f);
                updateCalcPreview();
            }
        }).catch(function(){});
    cellModal.show();
};

// ── saveLigne / deleteLigne ───────────────────────────────────────────────────
window.saveLigne = function() {
    var debut = document.getElementById('modalDebut').value;
    var fin   = document.getElementById('modalFin').value;
    var vid   = document.getElementById('modalVersionId').value;
    if (!debut||!fin) { alert('Veuillez saisir les heures de début et de fin.'); return; }
    if (!vid||vid==='0') { alert('Aucune version de planning disponible pour ce mois.'); return; }
    document.getElementById('btnSaveLigne').disabled = true;
    document.getElementById('btnSaveLigne').innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Enregistrement...';
    fetch('index.php', {method:'POST', body: new URLSearchParams({
        action:'save_ligne', agent_id:document.getElementById('modalAgentId').value,
        date:document.getElementById('modalDate').value, heure_debut:debut, heure_fin:fin,
        note:document.getElementById('modalNote').value, version_id:vid
    })}).then(function(r){return r.json();}).then(function(d){
        if(d.ok){ cellModal.hide(); location.reload(); }
        else { alert('Erreur : '+(d.error||'Enregistrement échoué')); document.getElementById('btnSaveLigne').disabled=false; document.getElementById('btnSaveLigne').innerHTML='<i class="fa fa-check me-1"></i>Enregistrer'; }
    }).catch(function(){ alert('Erreur réseau.'); document.getElementById('btnSaveLigne').disabled=false; document.getElementById('btnSaveLigne').innerHTML='<i class="fa fa-check me-1"></i>Enregistrer'; });
};

window.deleteLigne = function() {
    fetch('index.php', {method:'POST', body: new URLSearchParams({
        action:'delete_ligne', agent_id:document.getElementById('modalAgentId').value,
        date:document.getElementById('modalDate').value, version_id:document.getElementById('modalVersionId').value
    })}).then(function(r){return r.json();}).then(function(d){ if(d.ok){ cellModal.hide(); location.reload(); } });
};

// ── saveBulk ──────────────────────────────────────────────────────────────────
window.saveBulk = function() {
    var agentId = document.getElementById('bulkAgent').value;
    var dateD   = document.getElementById('bulkDateDebut').value;
    var dateF   = document.getElementById('bulkDateFin').value;
    var note    = document.getElementById('bulkNote').value;
    var isVide  = activeBulkDebut === '__vide__';
    var debut   = activeBulkDebut;
    var fin     = activeBulkFin;
    if (!isVide && (debut===''||fin==='')) { debut=document.getElementById('bulkDebut').value; fin=document.getElementById('bulkFin').value; }
    if (!agentId||!dateD||!dateF) { alert('Veuillez remplir agent et les dates.'); return; }
    if (!isVide&&(!debut||!fin)) { alert('Veuillez sélectionner un poste ou saisir les heures.'); return; }
    var jours=[];
    document.querySelectorAll('[id^="bdow"]:checked').forEach(function(cb){jours.push(cb.value);});
    if (!jours.length) { alert('Sélectionnez au moins un jour.'); return; }
    document.getElementById('btnSaveBulk').disabled = true;
    document.getElementById('btnSaveBulk').innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>En cours...';
    var body = isVide
        ? new URLSearchParams({action:'bulk_delete',agent_id:agentId,date_debut:dateD,date_fin:dateF,jours:jours.join(','),mission_id:currentMissionId})
        : new URLSearchParams({action:'bulk_save',agent_id:agentId,date_debut:dateD,date_fin:dateF,heure_debut:debut,heure_fin:fin,jours:jours.join(','),note:note,mission_id:currentMissionId});
    fetch('index.php',{method:'POST',body:body}).then(function(r){return r.json();}).then(function(d){
        if(d.ok){
            var count=isVide?d.deleted:d.saved;
            document.getElementById('bulkStatus').innerHTML='<div class="alert alert-success py-2 px-3 mt-2" style="font-size:0.82rem"><i class="fa fa-check-circle me-1"></i><strong>'+count+' créneau(x)</strong> '+(isVide?'effacé(s)':'affecté(s)')+'.</div>';
            setTimeout(function(){ bulkModal.hide(); location.reload(); }, 900);
        } else { alert('Erreur : '+(d.error||'?')); document.getElementById('btnSaveBulk').disabled=false; document.getElementById('btnSaveBulk').innerHTML=isVide?'<i class="fa fa-eraser me-1"></i>Effacer':'<i class="fa fa-bolt me-1"></i>Affecter'; }
    }).catch(function(){ alert('Erreur réseau.'); document.getElementById('btnSaveBulk').disabled=false; });
};

window.selectAllDow = function(s){ document.querySelectorAll('[id^="bdow"]').forEach(function(cb){cb.checked=s;}); };

// ── Agent filter (poste vers index.php — session partagée) ────────────────────
window.toggleAgent = function(agentId, btn) {
    fetch('index.php',{method:'POST',body:new URLSearchParams({action:'toggle_agent',agent_id:agentId})})
        .then(function(r){return r.json();}).then(function(d){
            if(d.hidden){ btn.className='btn btn-sm btn-warning'; btn.innerHTML='<i class="fa fa-eye-slash me-1"></i>Masqué'; }
            else         { btn.className='btn btn-sm btn-outline-secondary'; btn.innerHTML='<i class="fa fa-eye me-1"></i>Visible'; }
            btn.style.minWidth='90px'; btn.style.fontSize='0.78rem';
        });
};
window.showAllAgents = function() {
    fetch('index.php',{method:'POST',body:new URLSearchParams({action:'show_all_agents'})})
        .then(function(r){return r.json();}).then(function(){ location.reload(); });
};

}); // end DOMContentLoaded
</script>
ENDJS;
?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
