<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// Charger le devis
$devis = $db->prepare("SELECT * FROM devis WHERE id = ?");
$devis->execute([$id]);
$devis = $devis->fetch();
if (!$devis) { header('Location: index.php'); exit; }

// Charger les profils
$profils = $db->prepare("SELECT * FROM devis_profils WHERE devis_id = ? ORDER BY ordre, id");
$profils->execute([$id]);
$profils = $profils->fetchAll();

// Charger les lignes pour chaque profil (indexées par profil_id puis date)
$lignesParProfil = [];
if ($profils) {
    $profilIds = array_column($profils, 'id');
    $placeholders = implode(',', array_fill(0, count($profilIds), '?'));
    $stmtL = $db->prepare("SELECT * FROM devis_lignes WHERE profil_id IN ($placeholders) ORDER BY date");
    $stmtL->execute($profilIds);
    foreach ($stmtL->fetchAll() as $l) {
        $lignesParProfil[$l['profil_id']][$l['date']] = $l;
    }
}

// Générer la liste de jours de la période
$jours = [];
$cur = strtotime($devis['periode_debut']);
$end = strtotime($devis['periode_fin']);
while ($cur <= $end) {
    $jours[] = date('Y-m-d', $cur);
    $cur = strtotime('+1 day', $cur);
}

// Traitement POST — sauvegarde des heures
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_lignes'])) {
    requirePerm('devis', 'edit');
    $lignesPost = $_POST['l'] ?? [];
    $stmtUps = $db->prepare("
        INSERT INTO devis_lignes (profil_id, date, h_jn, h_nn, h_jd, h_nd, h_jf, h_nf)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            h_jn = VALUES(h_jn), h_nn = VALUES(h_nn),
            h_jd = VALUES(h_jd), h_nd = VALUES(h_nd),
            h_jf = VALUES(h_jf), h_nf = VALUES(h_nf)
    ");
    foreach ($lignesPost as $profilId => $dates) {
        $profilId = (int)$profilId;
        // Vérifier que ce profil appartient bien à ce devis
        $chkP = $db->prepare("SELECT id FROM devis_profils WHERE id = ? AND devis_id = ?");
        $chkP->execute([$profilId, $id]);
        if (!$chkP->fetch()) continue;

        foreach ($dates as $date => $heures) {
            $stmtUps->execute([
                $profilId,
                $date,
                (float)($heures['h_jn'] ?? 0),
                (float)($heures['h_nn'] ?? 0),
                (float)($heures['h_jd'] ?? 0),
                (float)($heures['h_nd'] ?? 0),
                (float)($heures['h_jf'] ?? 0),
                (float)($heures['h_nf'] ?? 0),
            ]);
        }
    }
    flash('success', 'Les heures ont été sauvegardées.');
    header('Location: view.php?id=' . $id);
    exit;
}

$statutColors = ['brouillon'=>'secondary','envoye'=>'primary','accepte'=>'success','refuse'=>'danger'];
$statutLabels = ['brouillon'=>'Brouillon','envoye'=>'Envoyé','accepte'=>'Accepté','refuse'=>'Refusé'];
$sColor = $statutColors[$devis['statut']] ?? 'secondary';
$sLabel = $statutLabels[$devis['statut']] ?? $devis['statut'];

$joursAbrev = ['1'=>'lun','2'=>'mar','3'=>'mer','4'=>'jeu','5'=>'ven','6'=>'sam','7'=>'dim'];

$pageTitle     = 'Devis ' . $devis['numero'];
$currentModule = 'devis';

$topbarActions = '
<a href="index.php" class="btn btn-ov-secondary btn-sm me-1"><i class="fa fa-arrow-left me-1"></i> Retour</a>
<a href="edit_info.php?id=' . $id . '" class="btn btn-ov-secondary btn-sm me-1"><i class="fa fa-pen me-1"></i> Modifier infos</a>
<a href="duplicate.php?id=' . $id . '" class="btn btn-ov-secondary btn-sm me-1"
   onclick="return confirm(\'Dupliquer ce devis ?\')"><i class="fa fa-copy me-1"></i> Dupliquer</a>
<a href="export_pdf.php?id=' . $id . '" class="btn btn-sm me-1" style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.3)" target="_blank"><i class="fa fa-file-pdf me-1"></i> PDF</a>
<span class="badge bg-' . $sColor . ' ms-1">' . h($sLabel) . '</span>
';
require_once __DIR__ . '/../../includes/header.php';
?>

<!-- Infos devis -->
<div class="ov-card mb-3">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-file-invoice me-2" style="color:var(--ov-gold)"></i>
            <?= h($devis['numero']) ?>
        </h2>
        <span class="badge bg-<?= $sColor ?>"><?= h($sLabel) ?></span>
    </div>
    <div class="ov-card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:1px">Client</div>
                <div class="fw-600"><?= h($devis['client_nom'] ?: '—') ?></div>
                <?php if ($devis['client_adresse']): ?>
                <div class="text-muted" style="font-size:0.8rem;white-space:pre-line"><?= h($devis['client_adresse']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:1px">Période</div>
                <div class="fw-600"><?= h(formatDate($devis['periode_debut'])) ?> → <?= h(formatDate($devis['periode_fin'])) ?></div>
                <div class="text-muted" style="font-size:0.8rem"><?= count($jours) ?> jour(s)</div>
            </div>
            <div class="col-md-5">
                <div class="text-muted" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:1px">Description</div>
                <div style="white-space:pre-line"><?= h($devis['description'] ?: '—') ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Formulaire grille éditable -->
<form method="POST" id="formLignes">
<input type="hidden" name="save_lignes" value="1">

<?php
// Accumulateurs totaux généraux
$grandTotalH  = 0;
$grandTotalHT = 0;

foreach ($profils as $profil):
    $pid = $profil['id'];
    $lignes = $lignesParProfil[$pid] ?? [];

    // Accumulateurs pour ce profil
    $stJn = $stNn = $stJd = $stNd = $stJf = $stNf = 0;
    $stH  = 0;
    $stHT = 0;

    $tauxJn = (float)$profil['taux_jn'];
    $tauxNn = (float)$profil['taux_nn'];
    $tauxJd = (float)$profil['taux_jd'];
    $tauxNd = (float)$profil['taux_nd'];
    $tauxJf = (float)$profil['taux_jf'];
    $tauxNf = (float)$profil['taux_nf'];
?>
<div class="ov-card mb-3 profil-card"
     data-profil-id="<?= $pid ?>"
     data-taux-jn="<?= $tauxJn ?>"
     data-taux-nn="<?= $tauxNn ?>"
     data-taux-jd="<?= $tauxJd ?>"
     data-taux-nd="<?= $tauxNd ?>"
     data-taux-jf="<?= $tauxJf ?>"
     data-taux-nf="<?= $tauxNf ?>">
    <div class="ov-card-header flex-wrap gap-2">
        <h2 class="ov-card-title" style="font-size:0.95rem">
            <i class="fa fa-user-shield me-2" style="color:var(--ov-gold)"></i>
            <?= h($profil['label']) ?>
        </h2>
        <div class="text-muted" style="font-size:0.8rem">
            <?= h($profil['activite']) ?> &nbsp;|&nbsp; <?= h($profil['plage']) ?>
        </div>
        <div class="ms-auto d-flex gap-1">
            <button type="button" class="btn btn-sm btn-outline-secondary btn-autofill"
                data-profil-id="<?= $pid ?>"
                title="Remplir automatiquement toutes les lignes">
                <i class="fa fa-wand-magic-sparkles me-1"></i>Remplir auto
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary btn-copy-first"
                data-profil-id="<?= $pid ?>"
                title="Copier les valeurs de la 1ère ligne sur toutes les lignes">
                <i class="fa fa-copy me-1"></i>Copier 1ère ligne
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger btn-clear-all"
                data-profil-id="<?= $pid ?>"
                title="Vider toutes les cellules">
                <i class="fa fa-eraser me-1"></i>Vider
            </button>
        </div>
    </div>
    <div class="ov-card-body p-0">
        <div class="table-responsive">
        <table class="ov-table devis-grid" style="font-size:0.8rem">
            <thead>
                <tr>
                    <th rowspan="3" style="width:110px;vertical-align:middle">Date</th>
                    <th colspan="2" class="text-center" style="background:rgba(99,102,241,0.08)">Jour Normal</th>
                    <th colspan="2" class="text-center" style="background:rgba(59,130,246,0.08)">Dimanche</th>
                    <th colspan="2" class="text-center" style="background:rgba(239,68,68,0.08)">Jour Férié</th>
                    <th rowspan="3" class="text-center" style="width:70px;vertical-align:middle">Total H</th>
                    <th rowspan="3" class="text-center" style="width:90px;vertical-align:middle">Total HT</th>
                </tr>
                <tr>
                    <th class="text-center" style="background:rgba(99,102,241,0.06)">JOUR</th>
                    <th class="text-center" style="background:rgba(99,102,241,0.06)">NUIT</th>
                    <th class="text-center" style="background:rgba(59,130,246,0.06)">JOUR</th>
                    <th class="text-center" style="background:rgba(59,130,246,0.06)">NUIT</th>
                    <th class="text-center" style="background:rgba(239,68,68,0.06)">JOUR</th>
                    <th class="text-center" style="background:rgba(239,68,68,0.06)">NUIT</th>
                </tr>
                <tr style="font-size:0.7rem;color:var(--ov-gold)">
                    <td class="text-center" style="background:rgba(99,102,241,0.04)"><?= number_format($tauxJn, 2) ?></td>
                    <td class="text-center" style="background:rgba(99,102,241,0.04)"><?= number_format($tauxNn, 2) ?></td>
                    <td class="text-center" style="background:rgba(59,130,246,0.04)"><?= number_format($tauxJd, 2) ?></td>
                    <td class="text-center" style="background:rgba(59,130,246,0.04)"><?= number_format($tauxNd, 2) ?></td>
                    <td class="text-center" style="background:rgba(239,68,68,0.04)"><?= number_format($tauxJf, 2) ?></td>
                    <td class="text-center" style="background:rgba(239,68,68,0.04)"><?= number_format($tauxNf, 2) ?></td>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jours as $jour):
                $ferie    = isFerie($jour);
                $dimanche = isDimanche($jour);
                $lg       = $lignes[$jour] ?? ['h_jn'=>0,'h_nn'=>0,'h_jd'=>0,'h_nd'=>0,'h_jf'=>0,'h_nf'=>0];

                $hJn = (float)$lg['h_jn'];
                $hNn = (float)$lg['h_nn'];
                $hJd = (float)$lg['h_jd'];
                $hNd = (float)$lg['h_nd'];
                $hJf = (float)$lg['h_jf'];
                $hNf = (float)$lg['h_nf'];

                $totalH  = $hJn + $hNn + $hJd + $hNd + $hJf + $hNf;
                $totalHT = $hJn*$tauxJn + $hNn*$tauxNn + $hJd*$tauxJd + $hNd*$tauxNd + $hJf*$tauxJf + $hNf*$tauxNf;

                $stJn += $hJn; $stNn += $hNn;
                $stJd += $hJd; $stNd += $hNd;
                $stJf += $hJf; $stNf += $hNf;
                $stH  += $totalH;
                $stHT += $totalHT;

                $jourAbrev = $joursAbrev[date('N', strtotime($jour))] ?? '';
                $jourFmt   = $jourAbrev . ' ' . date('d/m', strtotime($jour));

                // Couleur de la date
                if ($ferie) {
                    $dateCls = 'style="color:#dc2626;font-weight:600"';
                } elseif ($dimanche) {
                    $dateCls = 'style="color:#2563eb;font-weight:600"';
                } else {
                    $dateCls = '';
                }

                // Colonnes désactivées selon le type de jour
                // Jour normal : on peut écrire partout (mais logiquement h_jd, h_nd, h_jf, h_nf = 0)
                // Dimanche : h_jn et h_nn désactivés
                // Férié : h_jn, h_nn, h_jd, h_nd désactivés
                $disJn = $ferie || $dimanche ? 'disabled style="background:#f3f4f6;color:#9ca3af"' : '';
                $disNn = $ferie || $dimanche ? 'disabled style="background:#f3f4f6;color:#9ca3af"' : '';
                $disJd = $ferie             ? 'disabled style="background:#f3f4f6;color:#9ca3af"' : ($dimanche ? '' : 'disabled style="background:#f3f4f6;color:#9ca3af"');
                $disNd = $ferie             ? 'disabled style="background:#f3f4f6;color:#9ca3af"' : ($dimanche ? '' : 'disabled style="background:#f3f4f6;color:#9ca3af"');
                $disJf = !$ferie            ? 'disabled style="background:#f3f4f6;color:#9ca3af"' : '';
                $disNf = !$ferie            ? 'disabled style="background:#f3f4f6;color:#9ca3af"' : '';

                // Champs désactivés : valeur forcée à 0 via hidden
                $inputBase = "l[$pid][$jour]";
            ?>
            <tr class="ligne-jour" data-profil="<?= $pid ?>" data-date="<?= $jour ?>">
                <td <?= $dateCls ?>><?= h($jourFmt) ?></td>

                <!-- h_jn : Jour Normal JOUR -->
                <td class="p-1" style="background:rgba(99,102,241,0.04)">
                    <?php if ($disJn): ?>
                        <input type="hidden" name="<?= $inputBase ?>[h_jn]" value="0">
                        <span class="d-block text-center text-muted" style="font-size:0.75rem">—</span>
                    <?php else: ?>
                        <input type="number" name="<?= $inputBase ?>[h_jn]" class="h-input form-control form-control-sm text-center px-1"
                            data-col="h_jn" step="0.5" min="0" value="<?= h($hJn ?: '') ?>"
                            style="width:60px;min-width:50px" placeholder="0">
                    <?php endif; ?>
                </td>

                <!-- h_nn : Jour Normal NUIT -->
                <td class="p-1" style="background:rgba(99,102,241,0.04)">
                    <?php if ($disNn): ?>
                        <input type="hidden" name="<?= $inputBase ?>[h_nn]" value="0">
                        <span class="d-block text-center text-muted" style="font-size:0.75rem">—</span>
                    <?php else: ?>
                        <input type="number" name="<?= $inputBase ?>[h_nn]" class="h-input form-control form-control-sm text-center px-1"
                            data-col="h_nn" step="0.5" min="0" value="<?= h($hNn ?: '') ?>"
                            style="width:60px;min-width:50px" placeholder="0">
                    <?php endif; ?>
                </td>

                <!-- h_jd : Dimanche JOUR -->
                <td class="p-1" style="background:rgba(59,130,246,0.04)">
                    <?php if (strpos($disJd, 'disabled') !== false): ?>
                        <input type="hidden" name="<?= $inputBase ?>[h_jd]" value="0">
                        <span class="d-block text-center text-muted" style="font-size:0.75rem">—</span>
                    <?php else: ?>
                        <input type="number" name="<?= $inputBase ?>[h_jd]" class="h-input form-control form-control-sm text-center px-1"
                            data-col="h_jd" step="0.5" min="0" value="<?= h($hJd ?: '') ?>"
                            style="width:60px;min-width:50px" placeholder="0">
                    <?php endif; ?>
                </td>

                <!-- h_nd : Dimanche NUIT -->
                <td class="p-1" style="background:rgba(59,130,246,0.04)">
                    <?php if (strpos($disNd, 'disabled') !== false): ?>
                        <input type="hidden" name="<?= $inputBase ?>[h_nd]" value="0">
                        <span class="d-block text-center text-muted" style="font-size:0.75rem">—</span>
                    <?php else: ?>
                        <input type="number" name="<?= $inputBase ?>[h_nd]" class="h-input form-control form-control-sm text-center px-1"
                            data-col="h_nd" step="0.5" min="0" value="<?= h($hNd ?: '') ?>"
                            style="width:60px;min-width:50px" placeholder="0">
                    <?php endif; ?>
                </td>

                <!-- h_jf : Férié JOUR -->
                <td class="p-1" style="background:rgba(239,68,68,0.04)">
                    <?php if (strpos($disJf, 'disabled') !== false): ?>
                        <input type="hidden" name="<?= $inputBase ?>[h_jf]" value="0">
                        <span class="d-block text-center text-muted" style="font-size:0.75rem">—</span>
                    <?php else: ?>
                        <input type="number" name="<?= $inputBase ?>[h_jf]" class="h-input form-control form-control-sm text-center px-1"
                            data-col="h_jf" step="0.5" min="0" value="<?= h($hJf ?: '') ?>"
                            style="width:60px;min-width:50px" placeholder="0">
                    <?php endif; ?>
                </td>

                <!-- h_nf : Férié NUIT -->
                <td class="p-1" style="background:rgba(239,68,68,0.04)">
                    <?php if (strpos($disNf, 'disabled') !== false): ?>
                        <input type="hidden" name="<?= $inputBase ?>[h_nf]" value="0">
                        <span class="d-block text-center text-muted" style="font-size:0.75rem">—</span>
                    <?php else: ?>
                        <input type="number" name="<?= $inputBase ?>[h_nf]" class="h-input form-control form-control-sm text-center px-1"
                            data-col="h_nf" step="0.5" min="0" value="<?= h($hNf ?: '') ?>"
                            style="width:60px;min-width:50px" placeholder="0">
                    <?php endif; ?>
                </td>

                <td class="text-center fw-bold ligne-total-h"><?= $totalH > 0 ? number_format($totalH, 1) : '—' ?></td>
                <td class="text-end fw-bold ligne-total-ht" style="font-size:0.78rem"><?= $totalHT > 0 ? number_format($totalHT, 2, ',', ' ') . ' €' : '—' ?></td>
            </tr>
            <?php
                $grandTotalH  += $totalH;
                $grandTotalHT += $totalHT;
            endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:rgba(201,168,76,0.08);font-weight:700;font-size:0.82rem">
                    <td>Sous-total</td>
                    <td class="text-center st-col" data-col="h_jn"><?= $stJn > 0 ? number_format($stJn, 1) : '—' ?></td>
                    <td class="text-center st-col" data-col="h_nn"><?= $stNn > 0 ? number_format($stNn, 1) : '—' ?></td>
                    <td class="text-center st-col" data-col="h_jd"><?= $stJd > 0 ? number_format($stJd, 1) : '—' ?></td>
                    <td class="text-center st-col" data-col="h_nd"><?= $stNd > 0 ? number_format($stNd, 1) : '—' ?></td>
                    <td class="text-center st-col" data-col="h_jf"><?= $stJf > 0 ? number_format($stJf, 1) : '—' ?></td>
                    <td class="text-center st-col" data-col="h_nf"><?= $stNf > 0 ? number_format($stNf, 1) : '—' ?></td>
                    <td class="text-center st-total-h"><?= $stH > 0 ? number_format($stH, 1) : '—' ?></td>
                    <td class="text-end st-total-ht"><?= $stHT > 0 ? number_format($stHT, 2, ',', ' ') . ' €' : '—' ?></td>
                </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Totaux généraux -->
<?php
$tvaMontant  = $grandTotalHT * ($devis['tva_taux'] / 100);
$grandTotalTTC = $grandTotalHT + $tvaMontant;
?>
<div class="ov-card mb-3" id="cardTotaux">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-calculator me-2" style="color:var(--ov-gold)"></i>Totaux généraux
        </h2>
    </div>
    <div class="ov-card-body">
        <div class="row justify-content-end">
            <div class="col-md-5 col-lg-4">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <td class="text-muted">Total heures</td>
                            <td class="text-end fw-bold" id="gtotalH"><?= number_format($grandTotalH, 1) ?> h</td>
                        </tr>
                        <tr>
                            <td class="text-muted">Total HT</td>
                            <td class="text-end fw-bold" id="gtotalHT"><?= number_format($grandTotalHT, 2, ',', ' ') ?> €</td>
                        </tr>
                        <tr>
                            <td class="text-muted">TVA <?= h($devis['tva_taux']) ?>%</td>
                            <td class="text-end" id="gtotalTVA"><?= number_format($tvaMontant, 2, ',', ' ') ?> €</td>
                        </tr>
                        <tr style="border-top:2px solid var(--ov-gold)">
                            <td class="fw-bold" style="font-size:1.1rem">Total NET TTC</td>
                            <td class="text-end fw-bold" style="font-size:1.25rem;color:var(--ov-gold)" id="gtotalTTC">
                                <?= number_format($grandTotalTTC, 2, ',', ' ') ?> €
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Barre de sauvegarde -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <a href="index.php" class="btn btn-ov-secondary">
        <i class="fa fa-arrow-left me-1"></i> Retour à la liste
    </a>
    <button type="submit" class="btn btn-ov-primary">
        <i class="fa fa-save me-2"></i>Enregistrer les heures
    </button>
</div>

</form>

<!-- Modal remplissage auto -->
<div class="modal fade" id="modalAutofill" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fa fa-wand-magic-sparkles me-2"></i>Remplissage automatique</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-3" style="font-size:0.85rem">
            Saisissez les heures à appliquer sur <strong>chaque jour</strong> (selon le type de jour). Les colonnes désactivées pour un jour donné seront ignorées.
        </p>
        <div class="row g-2 text-center">
            <div class="col-4"><div class="p-2 rounded" style="background:rgba(99,102,241,0.08)">
                <div style="font-size:0.7rem;font-weight:700;color:#4f46e5">JOUR NORMAL</div>
                <div class="d-flex gap-1 mt-1">
                    <div class="flex-fill"><small class="text-muted d-block">Jour</small>
                        <input type="number" id="af_jn" class="form-control form-control-sm text-center" step="0.5" min="0" value="" placeholder="0"></div>
                    <div class="flex-fill"><small class="text-muted d-block">Nuit</small>
                        <input type="number" id="af_nn" class="form-control form-control-sm text-center" step="0.5" min="0" value="" placeholder="0"></div>
                </div>
            </div></div>
            <div class="col-4"><div class="p-2 rounded" style="background:rgba(59,130,246,0.08)">
                <div style="font-size:0.7rem;font-weight:700;color:#2563eb">DIMANCHE</div>
                <div class="d-flex gap-1 mt-1">
                    <div class="flex-fill"><small class="text-muted d-block">Jour</small>
                        <input type="number" id="af_jd" class="form-control form-control-sm text-center" step="0.5" min="0" value="" placeholder="0"></div>
                    <div class="flex-fill"><small class="text-muted d-block">Nuit</small>
                        <input type="number" id="af_nd" class="form-control form-control-sm text-center" step="0.5" min="0" value="" placeholder="0"></div>
                </div>
            </div></div>
            <div class="col-4"><div class="p-2 rounded" style="background:rgba(239,68,68,0.08)">
                <div style="font-size:0.7rem;font-weight:700;color:#dc2626">FÉRIÉ</div>
                <div class="d-flex gap-1 mt-1">
                    <div class="flex-fill"><small class="text-muted d-block">Jour</small>
                        <input type="number" id="af_jf" class="form-control form-control-sm text-center" step="0.5" min="0" value="" placeholder="0"></div>
                    <div class="flex-fill"><small class="text-muted d-block">Nuit</small>
                        <input type="number" id="af_nf" class="form-control form-control-sm text-center" step="0.5" min="0" value="" placeholder="0"></div>
                </div>
            </div></div>
        </div>
        <hr class="my-3">
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="afPresetJour">
                <i class="fa fa-sun me-1"></i>Préremplir Jour (07h-19h)
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="afPresetNuit">
                <i class="fa fa-moon me-1"></i>Préremplir Nuit (19h-07h)
            </button>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ov-secondary" data-bs-dismiss="modal">Annuler</button>
        <button type="button" class="btn btn-ov-primary" id="btnApplyAutofill">
            <i class="fa fa-check me-1"></i>Appliquer
        </button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
    var tvaTaux = <?= (float)$devis['tva_taux'] ?>;

    function getTaux(profilCard) {
        return {
            h_jn: parseFloat(profilCard.dataset.tauxJn) || 0,
            h_nn: parseFloat(profilCard.dataset.tauxNn) || 0,
            h_jd: parseFloat(profilCard.dataset.tauxJd) || 0,
            h_nd: parseFloat(profilCard.dataset.tauxNd) || 0,
            h_jf: parseFloat(profilCard.dataset.tauxJf) || 0,
            h_nf: parseFloat(profilCard.dataset.tauxNf) || 0,
        };
    }

    function val(inp) { return parseFloat(inp.value) || 0; }
    function fmtH(v)  { return v > 0 ? v.toFixed(1) : '—'; }
    function fmtHT(v) { return v > 0 ? v.toFixed(2).replace('.', ',') + ' €' : '—'; }

    function recalcRow(row) {
        var profilCard = row.closest('.profil-card');
        var taux = getTaux(profilCard);
        var inputs = row.querySelectorAll('.h-input');
        var values = {};
        inputs.forEach(function(inp) { values[inp.dataset.col] = val(inp); });
        var cols = ['h_jn','h_nn','h_jd','h_nd','h_jf','h_nf'];
        var totalH = 0, totalHT = 0;
        cols.forEach(function(c) {
            var v = values[c] || 0;
            totalH  += v;
            totalHT += v * (taux[c] || 0);
        });
        var cellH  = row.querySelector('.ligne-total-h');
        var cellHT = row.querySelector('.ligne-total-ht');
        if (cellH)  cellH.textContent  = fmtH(totalH);
        if (cellHT) cellHT.textContent = fmtHT(totalHT);
        return { h: totalH, ht: totalHT };
    }

    function recalcProfil(profilCard) {
        var rows   = profilCard.querySelectorAll('tbody .ligne-jour');
        var stCols = {h_jn:0,h_nn:0,h_jd:0,h_nd:0,h_jf:0,h_nf:0};
        var stH    = 0, stHT = 0;
        rows.forEach(function(row) {
            row.querySelectorAll('.h-input').forEach(function(inp) {
                stCols[inp.dataset.col] = (stCols[inp.dataset.col] || 0) + val(inp);
            });
            var r = recalcRow(row);
            stH  += r.h;
            stHT += r.ht;
        });
        profilCard.querySelectorAll('tfoot .st-col').forEach(function(td) {
            td.textContent = stCols[td.dataset.col] > 0 ? stCols[td.dataset.col].toFixed(1) : '—';
        });
        var stTH  = profilCard.querySelector('tfoot .st-total-h');
        var stTHT = profilCard.querySelector('tfoot .st-total-ht');
        if (stTH)  stTH.textContent  = fmtH(stH);
        if (stTHT) stTHT.textContent = fmtHT(stHT);
        return { h: stH, ht: stHT };
    }

    function recalcAll() {
        var cards = document.querySelectorAll('.profil-card');
        var grandH = 0, grandHT = 0;
        cards.forEach(function(card) {
            var r = recalcProfil(card);
            grandH  += r.h;
            grandHT += r.ht;
        });
        var tva = grandHT * (tvaTaux / 100);
        var ttc = grandHT + tva;
        var el;
        el = document.getElementById('gtotalH');   if (el) el.textContent = grandH.toFixed(1) + ' h';
        el = document.getElementById('gtotalHT');  if (el) el.textContent = grandHT.toFixed(2).replace('.', ',') + ' €';
        el = document.getElementById('gtotalTVA'); if (el) el.textContent = tva.toFixed(2).replace('.', ',') + ' €';
        el = document.getElementById('gtotalTTC'); if (el) el.textContent = ttc.toFixed(2).replace('.', ',') + ' €';
    }

    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('h-input')) recalcAll();
    });

    // ── Autofill ──────────────────────────────────────────────────────────
    var currentProfilCard = null;
    var modalEl = document.getElementById('modalAutofill');
    var modal   = new bootstrap.Modal(modalEl);

    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-autofill');
        if (!btn) return;
        currentProfilCard = document.querySelector('.profil-card[data-profil-id="' + btn.dataset.profilId + '"]');
        modal.show();
    });

    document.getElementById('afPresetJour').addEventListener('click', function() {
        document.getElementById('af_jn').value = 12;
        document.getElementById('af_nn').value = '';
        document.getElementById('af_jd').value = 12;
        document.getElementById('af_nd').value = '';
        document.getElementById('af_jf').value = 12;
        document.getElementById('af_nf').value = '';
    });

    document.getElementById('afPresetNuit').addEventListener('click', function() {
        document.getElementById('af_jn').value = 3;
        document.getElementById('af_nn').value = 9;
        document.getElementById('af_jd').value = 3;
        document.getElementById('af_nd').value = 9;
        document.getElementById('af_jf').value = 3;
        document.getElementById('af_nf').value = 9;
    });

    document.getElementById('btnApplyAutofill').addEventListener('click', function() {
        if (!currentProfilCard) return;
        var afVals = {
            h_jn: parseFloat(document.getElementById('af_jn').value) || 0,
            h_nn: parseFloat(document.getElementById('af_nn').value) || 0,
            h_jd: parseFloat(document.getElementById('af_jd').value) || 0,
            h_nd: parseFloat(document.getElementById('af_nd').value) || 0,
            h_jf: parseFloat(document.getElementById('af_jf').value) || 0,
            h_nf: parseFloat(document.getElementById('af_nf').value) || 0,
        };
        currentProfilCard.querySelectorAll('tbody .ligne-jour').forEach(function(row) {
            row.querySelectorAll('.h-input').forEach(function(inp) {
                var col = inp.dataset.col;
                if (afVals[col] !== undefined) inp.value = afVals[col] || '';
            });
        });
        recalcAll();
        modal.hide();
    });

    // ── Copier 1ère ligne ─────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-copy-first');
        if (!btn) return;
        var card = document.querySelector('.profil-card[data-profil-id="' + btn.dataset.profilId + '"]');
        if (!card) return;
        var rows = card.querySelectorAll('tbody .ligne-jour');
        if (rows.length < 2) return;
        var firstVals = {};
        rows[0].querySelectorAll('.h-input').forEach(function(inp) {
            firstVals[inp.dataset.col] = inp.value;
        });
        for (var i = 1; i < rows.length; i++) {
            rows[i].querySelectorAll('.h-input').forEach(function(inp) {
                var col = inp.dataset.col;
                if (firstVals[col] !== undefined) inp.value = firstVals[col];
            });
        }
        recalcAll();
    });

    // ── Vider tout ────────────────────────────────────────────────────────
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-clear-all');
        if (!btn) return;
        if (!confirm('Vider toutes les heures de ce profil ?')) return;
        var card = document.querySelector('.profil-card[data-profil-id="' + btn.dataset.profilId + '"]');
        if (!card) return;
        card.querySelectorAll('tbody .h-input').forEach(function(inp) { inp.value = ''; });
        recalcAll();
    });

    recalcAll();
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
