<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('devis', 'view');

$pageTitle     = 'Devis';
$currentModule = 'devis';

$db = getDB();

// Récupérer tous les devis avec Total TTC calculé
$sql = "
SELECT d.*,
    COALESCE(SUM(
        dl.h_jn * dp.taux_jn +
        dl.h_nn * dp.taux_nn +
        dl.h_jd * dp.taux_jd +
        dl.h_nd * dp.taux_nd +
        dl.h_jf * dp.taux_jf +
        dl.h_nf * dp.taux_nf
    ), 0) AS total_ht,
    COALESCE(SUM(dl.h_jn + dl.h_nn + dl.h_jd + dl.h_nd + dl.h_jf + dl.h_nf), 0) AS total_h
FROM devis d
LEFT JOIN devis_profils dp ON dp.devis_id = d.id
LEFT JOIN devis_lignes dl ON dl.profil_id = dp.id
GROUP BY d.id
ORDER BY d.created_at DESC
";
$devisList = $db->query($sql)->fetchAll();

$topbarActions = '<a href="add.php" class="btn btn-ov-primary btn-sm"><i class="fa fa-plus me-1"></i> Nouveau devis</a>';
require_once __DIR__ . '/../../includes/header.php';

$statutColors = [
    'brouillon' => 'secondary',
    'envoye'    => 'primary',
    'accepte'   => 'success',
    'refuse'    => 'danger',
];
$statutLabels = [
    'brouillon' => 'Brouillon',
    'envoye'    => 'Envoyé',
    'accepte'   => 'Accepté',
    'refuse'    => 'Refusé',
];
?>

<div class="ov-card">
    <div class="ov-card-header">
        <h2 class="ov-card-title">
            <i class="fa fa-file-invoice me-2" style="color:var(--ov-gold)"></i>Liste des devis
        </h2>
        <a href="add.php" class="btn btn-ov-primary btn-sm">
            <i class="fa fa-plus me-1"></i> Nouveau devis
        </a>
    </div>
    <div class="ov-card-body p-0">
        <?php if (empty($devisList)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-file-invoice fa-3x mb-3 opacity-25"></i>
            <p>Aucun devis créé pour l'instant.</p>
            <a href="add.php" class="btn btn-ov-primary">
                <i class="fa fa-plus me-1"></i> Créer le premier devis
            </a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="ov-table">
            <thead>
                <tr>
                    <th>Numéro</th>
                    <th>Client</th>
                    <th>Période</th>
                    <th>Description</th>
                    <th>Statut</th>
                    <th class="text-end">Total HT</th>
                    <th class="text-end">TVA</th>
                    <th class="text-end">Total TTC</th>
                    <th style="width:120px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($devisList as $d):
                $tva     = $d['total_ht'] * ($d['tva_taux'] / 100);
                $ttc     = $d['total_ht'] + $tva;
                $sColor  = $statutColors[$d['statut']] ?? 'secondary';
                $sLabel  = $statutLabels[$d['statut']] ?? $d['statut'];
                $desc    = mb_strlen($d['description'] ?? '') > 60
                           ? mb_substr($d['description'], 0, 60) . '…'
                           : ($d['description'] ?? '—');
            ?>
            <tr>
                <td>
                    <code class="fw-bold text-dark"><?= h($d['numero']) ?></code>
                </td>
                <td>
                    <div class="fw-600"><?= h($d['client_nom'] ?: '—') ?></div>
                </td>
                <td style="white-space:nowrap">
                    <?= h(formatDate($d['periode_debut'])) ?> →<br>
                    <?= h(formatDate($d['periode_fin'])) ?>
                </td>
                <td style="max-width:200px">
                    <small class="text-muted"><?= h($desc) ?></small>
                </td>
                <td>
                    <span class="badge bg-<?= $sColor ?>"><?= h($sLabel) ?></span>
                </td>
                <td class="text-end">
                    <?= number_format($d['total_ht'], 2, ',', ' ') ?> €
                </td>
                <td class="text-end text-muted">
                    <?= number_format($tva, 2, ',', ' ') ?> €
                </td>
                <td class="text-end fw-bold" style="color:var(--ov-gold-dark)">
                    <?= number_format($ttc, 2, ',', ' ') ?> €
                </td>
                <td>
                    <div class="d-flex gap-1">
                        <a href="view.php?id=<?= $d['id'] ?>" class="btn-sm-icon view" title="Voir / Éditer">
                            <i class="fa fa-eye"></i>
                        </a>
                        <a href="edit_info.php?id=<?= $d['id'] ?>" class="btn-sm-icon edit" title="Modifier infos">
                            <i class="fa fa-pen"></i>
                        </a>
                        <a href="export_pdf.php?id=<?= $d['id'] ?>" class="btn-sm-icon" style="background:rgba(239,68,68,0.1);color:#dc2626" title="Export PDF" target="_blank">
                            <i class="fa fa-file-pdf"></i>
                        </a>
                        <a href="#"
                           onclick="event.preventDefault(); if(confirm('Supprimer le devis <?= addslashes(h($d['numero'])) ?> ?\nCette action est irréversible.')) document.getElementById('del-<?= $d['id'] ?>').submit();"
                           class="btn-sm-icon delete" title="Supprimer">
                            <i class="fa fa-trash"></i>
                        </a>
                        <form id="del-<?= $d['id'] ?>" method="POST" action="delete.php" style="display:none">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
