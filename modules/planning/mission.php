<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('planning', 'view');

$db = getDB();

// ── AJAX POST (filtrage agents) ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];
    if ($action === 'toggle_agent') {
        $agId = (int)($_POST['agent_id'] ?? 0);
        if ($agId) {
            if (!isset($_SESSION['planning_hidden'])) $_SESSION['planning_hidden'] = [];
            $idx = array_search($agId, $_SESSION['planning_hidden']);
            if ($idx !== false) { array_splice($_SESSION['planning_hidden'], $idx, 1); echo json_encode(['ok'=>true,'hidden'=>false]); }
            else                { $_SESSION['planning_hidden'][] = $agId;              echo json_encode(['ok'=>true,'hidden'=>true]);  }
        } else { echo json_encode(['ok'=>false]); }
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
        if ($s['debut']===$hD && $s['fin']===$hF) return ['code'=>$code,'color'=>$s['color'],'bg'=>$s['bg'],'label'=>$s['label']];
    }
    return null;
}

function dayStatM(array $allAgents, array $planningData, string $date): array {
    $j=$n=$tot=$h=0;
    foreach ($allAgents as $ag) {
        $l = $planningData[$ag['id']][$date] ?? null;
        if ($l) {
            $tot++;
            $h += $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit'];
            if (($l['min_nuit']+$l['min_ferie_nuit']) > 0) $n++; else $j++;
        }
    }
    return ['j'=>$j,'n'=>$n,'t'=>$tot,'h'=>$h];
}

// ── Paramètres URL ────────────────────────────────────────────────────────────
$dateDebut = $_GET['date_debut'] ?? date('Y-m-d');
$dateFin   = $_GET['date_fin']   ?? date('Y-m-d', strtotime('+6 days'));
$dateDebut = date('Y-m-d', strtotime($dateDebut));
$dateFin   = date('Y-m-d', strtotime($dateFin));
if ($dateDebut > $dateFin) [$dateDebut, $dateFin] = [$dateFin, $dateDebut];

// Limiter à 62 jours
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
$allAgents    = $db->query("SELECT id, nom, prenom, poste FROM agents WHERE actif=1 ORDER BY nom, prenom")->fetchAll();
$agents       = array_values(array_filter($allAgents, fn($ag) => !in_array($ag['id'], $hiddenAgents)));

// ── Jours fériés ──────────────────────────────────────────────────────────────
$anneeDebut = (int)substr($dateDebut, 0, 4);
$anneeFin   = (int)substr($dateFin, 0, 4);
$feries = getJoursFeries($anneeDebut);
if ($anneeFin !== $anneeDebut) $feries = array_merge($feries, getJoursFeries($anneeFin));

// ── Planning data ─────────────────────────────────────────────────────────────
$planningData = [];
$stmtP = $db->prepare("
    SELECT pl.* FROM planning_lignes pl
    JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
    WHERE pl.date_travail BETWEEN ? AND ?
");
$stmtP->execute([$dateDebut, $dateFin]);
foreach ($stmtP->fetchAll() as $l) {
    $planningData[$l['agent_id']][$l['date_travail']] = $l;
}

// ── Stats de couverture ───────────────────────────────────────────────────────
$dayStats = [];
foreach ($dates as $dt) {
    $dayStats[$dt->format('Y-m-d')] = dayStatM($allAgents, $planningData, $dt->format('Y-m-d'));
}

// ── Navigation (décale la plage d'autant de jours) ───────────────────────────
$prevDebut = date('Y-m-d', strtotime($dateDebut . " -$nbJours days"));
$prevFin   = date('Y-m-d', strtotime($dateFin   . " -$nbJours days"));
$nextDebut = date('Y-m-d', strtotime($dateDebut . " +$nbJours days"));
$nextFin   = date('Y-m-d', strtotime($dateFin   . " +$nbJours days"));

// ── Affichage adaptatif selon longueur plage ──────────────────────────────────
$colWidth     = $nbJours <= 10 ? '115px' : ($nbJours <= 21 ? '78px' : '52px');
$cellFontSize = $nbJours <= 10 ? '0.68rem' : ($nbJours <= 21 ? '0.58rem' : '0.52rem');
$shiftFontSz  = $nbJours <= 10 ? '1.05rem' : ($nbJours <= 21 ? '0.78rem' : '0.65rem');
$showTimes    = $nbJours <= 21;

// ── Export ────────────────────────────────────────────────────────────────────
$agentIdsStr  = implode(',', array_column($agents, 'id'));
$exportBase   = "export.php?type=mission&date_debut=$dateDebut&date_fin=$dateFin&agent_ids=$agentIdsStr";

$nomsJours = ['','Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
$moisFr    = ['','jan','fév','mar','avr','mai','jun','jul','aoû','sep','oct','nov','déc'];

$dfDebut = date('d/m/Y', strtotime($dateDebut));
$dfFin   = date('d/m/Y', strtotime($dateFin));

$pageTitle     = "Planning Mission — $dfDebut → $dfFin";
$currentModule = 'planning';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- TOOLBAR -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <!-- Switcher vues -->
  <div class="btn-group btn-group-sm me-1">
    <?php
      $moisActuel    = (int)date('n');
      $anneeActuelle = (int)date('Y');
      $semActuelle   = (int)date('W');
    ?>
    <a href="?vue=mois&mois=<?= $moisActuel ?>&annee=<?= $anneeActuelle ?>" class="btn btn-ov-secondary" style="white-space:nowrap">
      <i class="fa fa-calendar me-1"></i>Mensuel
    </a>
    <a href="?vue=semaine&semaine=<?= $semActuelle ?>&annee=<?= $anneeActuelle ?>" class="btn btn-ov-secondary">
      <i class="fa fa-calendar-week me-1"></i>Hebdo
    </a>
    <button class="btn btn-dark" disabled><i class="fa fa-map-marker-alt me-1"></i>Mission</button>
  </div>

  <!-- Navigation plage -->
  <a href="?date_debut=<?= $prevDebut ?>&date_fin=<?= $prevFin ?>" class="btn btn-ov-secondary btn-sm" title="Plage précédente"><i class="fa fa-chevron-left"></i></a>
  <span style="font-weight:700;font-size:0.92rem;color:var(--ov-navy);white-space:nowrap">
    <i class="fa fa-map-marker-alt me-1" style="color:var(--ov-gold)"></i>
    <?= $dfDebut ?> → <?= $dfFin ?>
    <span style="font-size:0.72rem;font-weight:400;color:#9ca3af;margin-left:4px">(<?= $nbJours ?> j)</span>
  </span>
  <a href="?date_debut=<?= $nextDebut ?>&date_fin=<?= $nextFin ?>" class="btn btn-ov-secondary btn-sm" title="Plage suivante"><i class="fa fa-chevron-right"></i></a>

  <!-- Formulaire plage de dates -->
  <form method="get" class="d-flex align-items-center gap-1 ms-1" style="flex-wrap:nowrap">
    <input type="date" name="date_debut" class="form-control form-control-sm" value="<?= $dateDebut ?>" style="width:140px" required>
    <span style="color:#9ca3af;font-size:0.8rem">→</span>
    <input type="date" name="date_fin"   class="form-control form-control-sm" value="<?= $dateFin   ?>" style="width:140px" required>
    <button type="submit" class="btn btn-ov-primary btn-sm"><i class="fa fa-search me-1"></i>Voir</button>
  </form>

  <!-- Actions droite -->
  <div class="ms-auto d-flex gap-2 flex-wrap">
    <button class="btn btn-ov-secondary btn-sm" id="btnAgentFilterM">
      <i class="fa fa-eye me-1"></i>Agents
      <?php if ($hiddenAgents): ?><span class="badge bg-warning text-dark"><?= count($hiddenAgents) ?></span><?php endif; ?>
    </button>
    <?php if (canDo('planning','export')): ?>
    <a href="<?= $exportBase ?>&format=pdf" class="btn btn-sm" target="_blank"
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

<!-- LÉGENDE shifts -->
<div class="d-flex gap-2 mb-2 flex-wrap" style="font-size:0.72rem">
  <?php foreach ($shifts as $code => $s): ?>
  <span style="background:<?= $s['bg'] ?>;color:<?= $s['color'] ?>;border:1px solid <?= $s['color'] ?>;padding:2px 8px;border-radius:5px;font-weight:700">
    <?= $code ?> <span style="font-weight:400;opacity:0.8"><?= $s['debut'] ?>–<?= $s['fin'] ?></span>
  </span>
  <?php endforeach; ?>
  <span style="background:#f8f9fa;color:#6b7280;border:1px solid #e5e7eb;padding:2px 8px;border-radius:5px">Libre = horaires perso</span>
  <span class="ms-auto" style="color:#9ca3af">■ <span style="color:#4ade80">Couv. complète</span> ■ <span style="color:#fbbf24">Partielle</span> ■ <span style="color:#f87171">Non couverte</span></span>
</div>

<!-- GRILLE -->
<?php if (empty($agents)): ?>
<div class="ov-card"><div class="ov-card-body text-center text-muted py-5">
  <i class="fa fa-users fa-2x mb-2 d-block" style="opacity:0.3"></i>Aucun agent actif.
</div></div>
<?php else: ?>
<div class="ov-card">
  <div class="ov-card-body p-0">
    <div style="overflow-x:auto;overflow-y:visible">
    <table style="width:100%;border-collapse:collapse;min-width:600px">
      <thead>
        <tr>
          <!-- Colonne agent -->
          <th style="min-width:135px;padding:8px 10px;background:#f8f9fa;font-size:0.76rem;font-weight:600;color:var(--ov-navy);border-bottom:2px solid #e5e7eb;position:sticky;left:0;z-index:2;text-align:left">Agent</th>
          <?php
          $prevMonth = null;
          foreach ($dates as $dt):
            $dateStr   = $dt->format('Y-m-d');
            $jourSem   = (int)$dt->format('N');
            $isDim     = $jourSem === 7;
            $isSam     = $jourSem === 6;
            $isFer     = in_array($dateStr, $feries);
            $isToday   = $dateStr === date('Y-m-d');
            $curMonth  = $dt->format('m');
            $isNewMonth = ($curMonth !== $prevMonth);
            $prevMonth = $curMonth;
            $bgHead = $isFer ? '#fff3cd' : ($isDim ? '#fef2f2' : ($isSam ? '#eff6ff' : ($isToday ? '#f0fdf4' : '#f8f9fa')));
            $colHead = $isFer ? '#92400e' : ($isDim ? '#dc2626' : ($isSam ? '#4338ca' : ($isToday ? '#16a34a' : 'var(--ov-navy)')));
            $borderLeft = $isNewMonth && $dt->format('Y-m-d') !== $dateDebut ? 'border-left:2px solid #c9a84c;' : '';
          ?>
          <th style="min-width:<?= $colWidth ?>;max-width:<?= $colWidth ?>;padding:4px 2px;text-align:center;background:<?= $bgHead ?>;color:<?= $colHead ?>;border-bottom:2px solid #e5e7eb;font-size:<?= $cellFontSize ?>;<?= $borderLeft ?>"
              title="<?= $isFer ? 'Jour férié' : '' ?>">
            <?php if ($isNewMonth): ?>
            <div style="font-size:0.55rem;font-weight:700;color:var(--ov-gold);text-transform:uppercase;letter-spacing:.03em;line-height:1"><?= $moisFr[(int)$dt->format('n')] ?></div>
            <?php endif; ?>
            <div style="font-size:0.58rem;opacity:0.8;line-height:1.1"><?= $nomsJours[$jourSem] ?></div>
            <div style="font-size:0.88rem;font-weight:800;line-height:1.2"><?= $dt->format('d') ?></div>
            <?php if ($isFer): ?><div style="font-size:0.48rem;font-weight:700;color:#92400e;background:#fde68a;border-radius:2px;padding:0 2px;line-height:1.3">F</div><?php endif; ?>
          </th>
          <?php endforeach; ?>
          <th style="min-width:56px;padding:6px 4px;text-align:center;background:#f8f9fa;border-left:2px solid #e5e7eb;border-bottom:2px solid #e5e7eb;font-size:0.65rem;font-weight:600;color:var(--ov-navy)">Total</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($agents as $ag):
        $totalMin = 0;
      ?>
      <tr class="planning-row" style="border-bottom:1px solid #f0f2f5">
        <!-- Agent -->
        <td style="position:sticky;left:0;z-index:1;background:#fff;padding:6px 10px;border-right:1px solid #e5e7eb">
          <div style="font-weight:600;font-size:0.78rem;color:var(--ov-navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px" title="<?= h($ag['prenom'].' '.$ag['nom']) ?>"><?= h($ag['prenom'].' '.$ag['nom']) ?></div>
          <?php if ($ag['poste']): ?><div style="font-size:0.62rem;color:#9ca3af"><?= h($ag['poste']) ?></div><?php endif; ?>
        </td>

        <?php
        $prevMonthRow = null;
        foreach ($dates as $dt):
          $dateStr  = $dt->format('Y-m-d');
          $jourSem  = (int)$dt->format('N');
          $isDim    = $jourSem === 7;
          $isFer    = in_array($dateStr, $feries);
          $isToday  = $dateStr === date('Y-m-d');
          $curMonth = $dt->format('m');
          $isNewMonthRow = ($curMonth !== $prevMonthRow);
          $prevMonthRow  = $curMonth;
          $borderLeftRow = $isNewMonthRow && $dt->format('Y-m-d') !== $dateDebut ? 'border-left:2px solid rgba(201,168,76,0.3);' : '';

          $bgCell = $isFer ? 'rgba(234,179,8,0.09)' : ($isDim ? 'rgba(239,68,68,0.05)' : ($isToday ? 'rgba(22,163,74,0.04)' : 'transparent'));
          $ligne  = $planningData[$ag['id']][$dateStr] ?? null;

          if ($ligne):
            $hDeb    = substr($ligne['heure_debut'],0,5);
            $hFin    = substr($ligne['heure_fin'],0,5);
            $minT    = $ligne['min_normal']+$ligne['min_nuit']+$ligne['min_dimanche']+$ligne['min_ferie_normal']+$ligne['min_ferie_dimanche']+$ligne['min_ferie_nuit'];
            $totalMin += $minT;
            $shiftInfo = detectShiftM($hDeb, $hFin, $shifts);
            $cellBg  = $shiftInfo ? $shiftInfo['bg'] : ($bgCell ?: 'rgba(0,0,0,0.02)');
            $color   = $shiftInfo ? $shiftInfo['color'] : '#374151';
            $dur     = $minT >= 60 ? round($minT/60).'h' : $minT.'min';
        ?>
        <td style="padding:3px 2px;text-align:center;background:<?= $cellBg ?>;<?= $borderLeftRow ?>"
            title="<?= h($ag['prenom'].' '.$ag['nom']) ?> — <?= $dt->format('d/m/Y') ?> | <?= h(formatHeureCourte($hDeb).' - '.formatHeureCourte($hFin)) ?><?= $ligne['note'] ? ' | '.h($ligne['note']) : '' ?>">
          <div style="font-size:<?= $shiftFontSz ?>;font-weight:900;color:<?= $color ?>;line-height:1.1"><?= $shiftInfo ? $shiftInfo['code'] : '·' ?></div>
          <?php if ($showTimes): ?>
          <div style="font-size:0.5rem;color:<?= $color ?>;opacity:0.85;line-height:1.1"><?= h(formatHeureCourte($hDeb)) ?>-<?= h(formatHeureCourte($hFin)) ?></div>
          <?php endif; ?>
          <div style="font-size:0.5rem;color:#6b7280;line-height:1.1"><?= $dur ?></div>
          <?php if ($ligne['note']): ?><div style="font-size:0.45rem;color:#9ca3af;max-width:<?= $colWidth ?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($ligne['note']) ?>">📝</div><?php endif; ?>
        </td>
        <?php else: ?>
        <td style="padding:3px 2px;text-align:center;background:<?= $bgCell ?: 'transparent' ?>;<?= $borderLeftRow ?>">
          <span style="color:#e5e7eb;font-size:0.65rem">—</span>
        </td>
        <?php endif; endforeach; ?>

        <!-- Total agent -->
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
          $isNewMonthCov = ($curMonth !== $prevMonthCov);
          $prevMonthCov  = $curMonth;
          $borderLeftCov = $isNewMonthCov && $dt->format('Y-m-d') !== $dateDebut ? 'border-left:2px solid rgba(201,168,76,0.3);' : '';
          if ($stat['t'] === 0)                         { $covBg='#fef2f2'; $covBord='#f87171'; }
          elseif ($stat['j'] > 0 && $stat['n'] > 0)    { $covBg='#f0fdf4'; $covBord='#4ade80'; }
          else                                           { $covBg='#fffbeb'; $covBord='#fbbf24'; }
        ?>
        <td style="text-align:center;background:<?= $covBg ?>;border-top:2px solid <?= $covBord ?>;padding:3px 1px;<?= $borderLeftCov ?>">
          <?php if ($stat['t'] > 0): ?>
          <?php if ($stat['j'] > 0): ?><div style="font-size:0.57rem;color:#16a34a;font-weight:700;line-height:1.2">J<?= $stat['j'] ?></div><?php endif; ?>
          <?php if ($stat['n'] > 0): ?><div style="font-size:0.57rem;color:#4f46e5;font-weight:700;line-height:1.2">N<?= $stat['n'] ?></div><?php endif; ?>
          <?php else: ?>
          <div style="font-size:0.55rem;color:#f87171">—</div>
          <?php endif; ?>
        </td>
        <?php endforeach; ?>
        <td style="background:#f1f5f9;border-left:2px solid #e5e7eb"></td>
      </tr>

      <!-- Ligne total heures/jour -->
      <tr>
        <td style="position:sticky;left:0;z-index:1;background:#f8f9fa;padding:5px 10px;font-size:0.65rem;font-weight:600;color:#6b7280;border-right:1px solid #e5e7eb">Total h/jour</td>
        <?php
        $grandTotal = 0;
        $prevMonthTot = null;
        foreach ($dates as $dt):
          $dateStr = $dt->format('Y-m-d');
          $stat    = $dayStats[$dateStr];
          $grandTotal += $stat['h'];
          $curMonth = $dt->format('m');
          $isNewMonthTot = ($curMonth !== $prevMonthTot);
          $prevMonthTot  = $curMonth;
          $borderLeftTot = $isNewMonthTot && $dt->format('Y-m-d') !== $dateDebut ? 'border-left:2px solid rgba(201,168,76,0.3);' : '';
        ?>
        <td style="text-align:center;background:#f8f9fa;padding:3px 1px;<?= $borderLeftTot ?>">
          <span style="font-size:0.57rem;font-weight:600;color:<?= $stat['h'] > 0 ? 'var(--ov-navy)' : '#d1d5db' ?>">
            <?= $stat['h'] > 0 ? number_format($stat['h']/60,1).'h' : '—' ?>
          </span>
        </td>
        <?php endforeach; ?>
        <td style="text-align:center;background:#f8f9fa;border-left:2px solid #e5e7eb;font-size:0.7rem;font-weight:700;color:var(--ov-navy)">
          <?= number_format($grandTotal/60,1) ?>h
        </td>
      </tr>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- MODAL filtrage agents -->
<div class="modal fade" id="agentFilterModalM" tabindex="-1">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-eye me-2" style="color:var(--ov-gold)"></i>Affichage des agents</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div style="padding:10px 16px;background:#f8f9fa;border-bottom:1px solid #e5e7eb">
          <button class="btn btn-sm btn-ov-secondary" onclick="showAllAgentsM()"><i class="fa fa-eye me-1"></i>Tout afficher</button>
        </div>
        <ul class="list-group list-group-flush">
          <?php foreach ($allAgents as $ag): ?>
          <?php $isHidden = in_array($ag['id'], $hiddenAgents); ?>
          <li class="list-group-item d-flex justify-content-between align-items-center py-2">
            <span style="font-size:0.85rem;<?= $isHidden ? 'opacity:0.4;text-decoration:line-through' : '' ?>"><?= h($ag['prenom'].' '.$ag['nom']) ?></span>
            <button class="btn btn-sm <?= $isHidden ? 'btn-outline-secondary' : 'btn-outline-success' ?>"
                    onclick="toggleAgentM(<?= $ag['id'] ?>, this)" style="min-width:80px;font-size:0.75rem">
              <i class="fa <?= $isHidden ? 'fa-eye-slash' : 'fa-eye' ?> me-1"></i><?= $isHidden ? 'Masqué' : 'Visible' ?>
            </button>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('btnAgentFilterM').addEventListener('click', function() {
    new bootstrap.Modal(document.getElementById('agentFilterModalM')).show();
});
function toggleAgentM(agentId, btn) {
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
               body:'action=toggle_agent&agent_id='+agentId})
        .then(r => r.json()).then(d => { if (d.ok) location.reload(); });
}
function showAllAgentsM() {
    fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
               body:'action=show_all_agents'})
        .then(r => r.json()).then(() => location.reload());
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
