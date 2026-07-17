<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('planning', 'view');

$pageTitle    = 'Planning hebdomadaire';
$currentModule = 'planning';
require_once __DIR__ . '/../../includes/header.php';

$db = getDB();

// Semaine courante ou choisie
$today     = new DateTime();
$semaine   = (int)($_GET['semaine'] ?? $today->format('W'));
$annee     = (int)($_GET['annee']   ?? $today->format('Y'));

// Calculer le lundi de la semaine
$lundi = new DateTime();
$lundi->setISODate($annee, $semaine, 1);
$dimanche = clone $lundi;
$dimanche->modify('+6 days');

$mois = (int)$lundi->format('n');

// Version planning du mois
$stmtV = $db->prepare("SELECT * FROM planning_versions WHERE mois=? AND annee=? AND is_current=1 LIMIT 1");
$stmtV->execute([$mois, $annee]);
$version = $stmtV->fetch();

// Si la semaine chevauche deux mois, prendre la version du mois du lundi
if (!$version) {
    $stmtV = $db->prepare("SELECT * FROM planning_versions WHERE mois=? AND annee=? AND is_current=1 LIMIT 1");
    $stmtV->execute([(int)$dimanche->format('n'), (int)$dimanche->format('Y')]);
    $version = $stmtV->fetch();
}

$agents = $db->query("SELECT id, nom, prenom, matricule, poste, sexe FROM agents WHERE actif=1 ORDER BY CASE WHEN sexe='F' THEN 0 ELSE 1 END, nom, prenom")->fetchAll();
$feries = getJoursFeries($annee);

// Charger les lignes de la semaine
$planningData = [];
if ($version) {
    $dateDebut = $lundi->format('Y-m-d');
    $dateFin   = $dimanche->format('Y-m-d');
    $stmtL = $db->prepare("
        SELECT * FROM planning_lignes
        WHERE version_id = ? AND date_travail BETWEEN ? AND ?
    ");
    $stmtL->execute([$version['id'], $dateDebut, $dateFin]);
    foreach ($stmtL->fetchAll() as $l) {
        $planningData[$l['agent_id']][$l['date_travail']] = $l;
    }
}

// Jours de la semaine
$jours = [];
$nomsJours = ['Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];
for ($i = 0; $i < 7; $i++) {
    $d = clone $lundi;
    $d->modify("+$i days");
    $jours[] = [
        'date'    => $d->format('Y-m-d'),
        'nom'     => $nomsJours[$i],
        'num'     => $d->format('d'),
        'mois'    => $d->format('m'),
        'ferie'   => in_array($d->format('Y-m-d'), $feries),
        'dimanche'=> $i === 6,
        'samedi'  => $i === 5,
    ];
}

// Semaine précédente / suivante
$prevLundi = clone $lundi; $prevLundi->modify('-7 days');
$nextLundi = clone $lundi; $nextLundi->modify('+7 days');

// Résumé par agent pour la semaine
function totalSemaineAgent(array $planningData, int $agentId, array $jours): array {
    $mins = ['normal'=>0,'nuit'=>0,'dimanche'=>0,'nuit_dimanche'=>0,'ferie_normal'=>0,'ferie_nuit'=>0];
    foreach ($jours as $j) {
        $l = $planningData[$agentId][$j['date']] ?? null;
        if ($l) {
            foreach ($mins as $k => $_) $mins[$k] += (int)$l['min_'.$k];
            $mins['ferie_normal'] += (int)($l['min_ferie_dimanche'] ?? 0);
        }
    }
    return $mins;
}
?>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <a href="?semaine=<?= $prevLundi->format('W') ?>&annee=<?= $prevLundi->format('Y') ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-left"></i></a>
    <div>
        <span style="font-size:1rem;font-weight:700;color:var(--ov-navy)">
            Semaine <?= $semaine ?> — du <?= $lundi->format('d/m') ?> au <?= $dimanche->format('d/m/Y') ?>
        </span>
        <?php if ($version): ?>
        <span class="ms-2 badge" style="background:rgba(34,197,94,0.1);color:#16a34a;border-radius:20px;padding:3px 10px;font-size:0.72rem">V<?= $version['version'] ?></span>
        <?php endif; ?>
    </div>
    <a href="?semaine=<?= $nextLundi->format('W') ?>&annee=<?= $nextLundi->format('Y') ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-chevron-right"></i></a>
    <a href="?semaine=<?= $today->format('W') ?>&annee=<?= $today->format('Y') ?>" class="btn btn-ov-secondary btn-sm">Cette semaine</a>
    <a href="index.php?mois=<?= $mois ?>&annee=<?= $annee ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-calendar me-1"></i>Vue mensuelle</a>
</div>

<!-- Vue hebdomadaire en cartes -->
<div class="row g-2 mb-3">
    <?php foreach ($jours as $j): ?>
    <?php
    $bgCard  = $j['ferie'] ? '#fffbeb' : ($j['dimanche'] ? '#fef2f2' : ($j['samedi'] ? '#f0f4ff' : 'white'));
    $bdCard  = $j['ferie'] ? '#fde68a' : ($j['dimanche'] ? '#fca5a5' : ($j['samedi'] ? '#c7d2fe' : '#e5e7eb'));
    $colJour = $j['ferie'] ? '#92400e' : ($j['dimanche'] ? '#dc2626' : ($j['samedi'] ? '#4f46e5' : 'var(--ov-navy)'));
    $agentsJour = array_filter($planningData, fn($ad) => isset($ad[$j['date']]));
    ?>
    <div class="col">
        <div class="rounded p-2" style="background:<?= $bgCard ?>;border:1px solid <?= $bdCard ?>;min-height:140px">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <div style="font-size:0.7rem;color:<?= $colJour ?>;font-weight:700;text-transform:uppercase"><?= $j['nom'] ?></div>
                    <div style="font-size:1.2rem;font-weight:800;color:<?= $colJour ?>"><?= $j['num'] ?></div>
                </div>
                <?php if ($j['ferie']): ?>
                <span style="background:#fde68a;color:#92400e;font-size:0.6rem;font-weight:700;padding:2px 5px;border-radius:4px">FÉRIÉ</span>
                <?php endif; ?>
            </div>

            <?php if (empty($agentsJour)): ?>
            <div style="font-size:0.72rem;color:#d1d5db;text-align:center;padding-top:10px">—</div>
            <?php else: ?>
            <?php
            $cntF = $cntM = 0;
            $prevSexeCard = null;
            foreach ($agents as $ag):
                $l = $planningData[$ag['id']][$j['date']] ?? null;
                if (!$l) continue;
                $curSexeCard = $ag['sexe'] ?? 'M';
                $isSepCard   = ($prevSexeCard === 'F' && $curSexeCard === 'M');
                $prevSexeCard = $curSexeCard;
                if ($curSexeCard === 'F') $cntF++; else $cntM++;
                $hDeb   = substr($l['heure_debut'], 0, 5);
                $hFin   = substr($l['heure_fin'],   0, 5);
                $totMin = $l['min_normal']+$l['min_nuit']+$l['min_dimanche']+$l['min_nuit_dimanche']+$l['min_ferie_normal']+$l['min_ferie_dimanche']+$l['min_ferie_nuit'];
                $hasNuit= $l['min_nuit']>0 || $l['min_nuit_dimanche']>0 || $l['min_ferie_nuit']>0;
            ?>
            <?php if ($isSepCard): ?><div style="border-top:1px dashed #cbd5e1;margin:3px 0"></div><?php endif; ?>
            <div class="mb-1 p-1 rounded" style="background:rgba(0,0,0,0.04)">
                <div style="font-size:0.68rem;font-weight:600;color:var(--ov-navy);line-height:1.2"><?= h($ag['prenom'].' '.strtoupper($ag['nom'])) ?></div>
                <div style="font-size:0.62rem;color:#6b7280">
                    <?= formatHeureCourte($hDeb) ?> - <?= formatHeureCourte($hFin) ?>
                    &nbsp;·&nbsp; <?= number_format($totMin/60, 1) ?>h
                    <?php if ($hasNuit): ?><i class="fa fa-moon" style="color:#4f46e5;font-size:0.55rem"></i><?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Comptage F/H -->
            <div style="margin-top:6px;padding-top:4px;border-top:1px solid <?= $bdCard ?>;font-size:0.65rem;color:<?= $colJour ?>;font-weight:700">
                <?= ($cntF+$cntM) ?> agent<?= ($cntF+$cntM)>1?'s':'' ?>
                <?php if ($cntF > 0): ?>&nbsp;<span style="color:#ec4899">F<?= $cntF ?></span><?php endif; ?>
                <?php if ($cntM > 0): ?>&nbsp;<span style="color:#3b82f6">H<?= $cntM ?></span><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Tableau récapitulatif agents -->
<div class="ov-card">
    <div class="ov-card-header">
        <h2 class="ov-card-title"><i class="fa fa-table me-2" style="color:var(--ov-gold)"></i>Récapitulatif semaine</h2>
    </div>
    <div class="ov-card-body p-0">
    <div class="table-responsive">
    <table class="ov-table">
        <thead>
            <tr>
                <th>Agent</th>
                <?php foreach ($jours as $j): ?>
                <?php $col = $j['ferie']?'#92400e':($j['dimanche']?'#dc2626':($j['samedi']?'#4f46e5':'var(--ov-navy)')); ?>
                <th style="text-align:center;color:<?= $col ?>">
                    <?= substr($j['nom'],0,3) ?><br><?= $j['num'] ?>
                </th>
                <?php endforeach; ?>
                <th style="text-align:center;border-left:2px solid #e5e7eb">Normal</th>
                <th style="text-align:center;color:#4f46e5">Nuit</th>
                <th style="text-align:center;color:#dc2626">Dim.</th>
                <th style="text-align:center">Total</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $hasAny = false;
        $prevSexeRec = null;
        foreach ($agents as $ag):
            $semMins = totalSemaineAgent($planningData, $ag['id'], $jours);
            $semTotal = array_sum($semMins);
            if ($semTotal === 0) continue;
            $hasAny = true;
            $curSexeRec = $ag['sexe'] ?? 'M';
            $isSepRec   = ($prevSexeRec === 'F' && $curSexeRec === 'M');
            $prevSexeRec = $curSexeRec;
        ?>
        <tr style="<?= $isSepRec ? 'border-top:2px dashed #cbd5e1' : '' ?>">
            <td style="<?= $isSepRec ? 'border-top:2px dashed #cbd5e1' : '' ?>">
                <div style="font-size:0.875rem;font-weight:600"><?= h($ag['prenom'].' '.$ag['nom']) ?></div>
                <div style="font-size:0.72rem;color:#9ca3af"><?= h($ag['poste']??'') ?></div>
            </td>
            <?php foreach ($jours as $j):
                $l = $planningData[$ag['id']][$j['date']] ?? null;
            ?>
            <td style="text-align:center;font-size:0.72rem">
                <?php if ($l): ?>
                <div style="font-weight:600"><?= substr($l['heure_debut'],0,5) ?></div>
                <div style="color:#9ca3af"><?= substr($l['heure_fin'],0,5) ?></div>
                <?php else: ?>
                <span style="color:#e5e7eb">—</span>
                <?php endif; ?>
            </td>
            <?php endforeach; ?>
            <td style="text-align:center;font-size:0.82rem;border-left:2px solid #e5e7eb">
                <?= $semMins['normal'] > 0 ? number_format($semMins['normal']/60, 1).'h' : '—' ?>
            </td>
            <td style="text-align:center;font-size:0.82rem;color:#4f46e5">
                <?= ($semMins['nuit']+$semMins['ferie_nuit']) > 0 ? number_format(($semMins['nuit']+$semMins['ferie_nuit'])/60, 1).'h' : '—' ?>
            </td>
            <td style="text-align:center;font-size:0.82rem;color:#dc2626">
                <?= $semMins['dimanche'] > 0 ? number_format($semMins['dimanche']/60, 1).'h' : '—' ?>
            </td>
            <td style="text-align:center;font-weight:700;font-size:0.9rem"><?= number_format($semTotal/60, 1) ?>h</td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$hasAny): ?>
        <tr><td colspan="12" class="text-center text-muted py-3">Aucun agent planifié cette semaine</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
