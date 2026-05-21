<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// Supprimer document — doit se faire avant header.php
if ($_GET['del_doc'] ?? false) {
    requirePerm('agents','edit');
    $docId = (int)$_GET['del_doc'];
    $doc = $db->prepare("SELECT * FROM agent_documents WHERE id=? AND agent_id=?");
    $doc->execute([$docId,$id]);
    $d = $doc->fetch();
    if ($d) {
        @unlink(UPLOAD_PATH . '/' . $d['chemin']);
        $db->prepare("DELETE FROM agent_documents WHERE id=?")->execute([$docId]);
        flash('success','Document supprimé.');
    }
    header('Location: view.php?id='.$id);
    exit;
}

$pageTitle    = 'Fiche agent';
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { flash('danger','Agent introuvable'); header('Location: index.php'); exit; }

$docs = $db->prepare("SELECT * FROM agent_documents WHERE agent_id = ? ORDER BY type_document");
$docs->execute([$id]);
$documents = $docs->fetchAll();

$docsLabels = [
    'piece_identite'      => ['Pièce d\'identité','fa-id-card'],
    'carte_vitale'        => ['Carte vitale','fa-heart-pulse'],
    'attestation_domicile'=> ['Attestation domicile','fa-house'],
    'titre_sejour'        => ['Titre de séjour','fa-passport'],
    'attestation_cnaps'   => ['Attestation CNAPS','fa-shield-halved'],
    'contrat'             => ['Contrat','fa-file-contract'],
];
?>

<?php
// Impact counts pour le modal de suppression
$nbPlanning = 0; $nbDocs = 0;
if (canDo('agents','delete')) {
    $s1 = $db->prepare("SELECT COUNT(*) FROM planning_lignes WHERE agent_id=?"); $s1->execute([$id]); $nbPlanning = (int)$s1->fetchColumn();
    $s2 = $db->prepare("SELECT COUNT(*) FROM agent_documents WHERE agent_id=?"); $s2->execute([$id]); $nbDocs = (int)$s2->fetchColumn();
}
?>

<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="index.php" class="btn btn-ov-secondary"><i class="fa fa-arrow-left me-1"></i>Retour</a>
    <?php if (canDo('agents','edit')): ?>
    <a href="edit.php?id=<?= $id ?>" class="btn btn-ov-secondary"><i class="fa fa-pen me-1"></i>Modifier</a>
    <?php endif; ?>
    <a href="carte.php?id=<?= $id ?>" class="btn btn-ov-primary"><i class="fa fa-id-card me-1"></i>Carte agent</a>
    <a href="export_pdf.php?id=<?= $id ?>" class="btn" style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem"><i class="fa fa-file-pdf me-1"></i>Export PDF comptable</a>
    <a href="contrat.php?id=<?= $id ?>" class="btn" style="background:rgba(201,168,76,0.1);color:#92400e;border:1px solid rgba(201,168,76,0.3);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem"><i class="fa fa-file-contract me-1"></i>Contrat</a>
    <a href="avenant.php?id=<?= $id ?>" class="btn" style="background:rgba(16,185,129,0.1);color:#065f46;border:1px solid rgba(16,185,129,0.3);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem"><i class="fa fa-file-pen me-1"></i>Avenant</a>
    <?php if (canDo('agents','create')): ?>
    <a href="token.php?id=<?= $id ?>" class="btn" style="background:rgba(99,102,241,0.1);color:#4f46e5;border:1px solid rgba(99,102,241,0.2);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem"><i class="fa fa-link me-1"></i>Lien auto-remplissage</a>
    <?php endif; ?>
    <?php if (canDo('agents','delete')): ?>
    <button type="button" class="btn ms-auto" style="background:rgba(239,68,68,0.08);color:#dc2626;border:1px solid rgba(239,68,68,0.25);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem" data-bs-toggle="modal" data-bs-target="#modalDelete">
        <i class="fa fa-trash me-1"></i>Supprimer
    </button>
    <?php endif; ?>
</div>

<div class="row g-3">
<div class="col-lg-4">
  <!-- Carte identité -->
  <div class="ov-card mb-3">
    <div class="ov-card-body text-center">
      <?php if ($a['photo']): ?>
      <img src="<?= UPLOAD_URL ?>/<?= h($a['photo']) ?>" class="rounded-circle mb-3" style="width:100px;height:100px;object-fit:cover;border:3px solid var(--ov-gold)">
      <?php else: ?>
      <div class="rounded-circle mx-auto mb-3 d-flex align-items-center justify-content-center" style="width:100px;height:100px;background:rgba(201,168,76,0.1);border:3px solid var(--ov-gold);font-size:2rem;font-weight:700;color:var(--ov-gold)">
        <?= strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1)) ?>
      </div>
      <?php endif; ?>
      <h3 class="fw-bold mb-0" style="font-size:1.1rem"><?= h($a['prenom'].' '.$a['nom']) ?></h3>
      <p class="text-muted mb-2" style="font-size:0.85rem"><?= h($a['poste'] ?? '—') ?></p>
      <span class="badge-ov" style="background:<?= $a['actif']?'rgba(34,197,94,0.12)':'rgba(107,114,128,0.12)' ?>;color:<?= $a['actif']?'#16a34a':'#6b7280' ?>;padding:4px 12px;border-radius:20px;font-size:0.75rem">
        <?= $a['actif'] ? 'Actif' : 'Inactif' ?>
      </span>
      <div class="mt-3 pt-3" style="border-top:1px solid #f0f2f5">
        <div class="text-muted" style="font-size:0.75rem">Matricule</div>
        <code class="fs-6 fw-bold"><?= h($a['matricule'] ?? '—') ?></code>
      </div>
    </div>
  </div>

  <!-- CNAPS -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-shield-halved me-2" style="color:var(--ov-gold)"></i>CNAPS</h2></div>
    <div class="ov-card-body">
      <?php
      $fields = [
        'N° Autorisation' => $a['num_autorisation_cnaps'],
        'Date autorisation' => formatDate($a['date_autorisation_cnaps']),
        'Date expiration' => formatDate($a['date_expiration_cnaps']),
      ];
      foreach ($fields as $l => $v): ?>
      <div class="d-flex justify-content-between py-1" style="border-bottom:1px solid #f0f2f5;font-size:0.85rem">
        <span class="text-muted"><?= h($l) ?></span>
        <span class="fw-500"><?= h($v ?: '—') ?></span>
      </div>
      <?php endforeach; ?>
      <?php if ($a['date_expiration_cnaps'] && strtotime($a['date_expiration_cnaps']) < strtotime('+30 days')): ?>
      <div class="alert alert-warning mt-2 py-1 px-2 mb-0" style="font-size:0.78rem"><i class="fa fa-triangle-exclamation me-1"></i>Autorisation expire bientôt</div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Documents -->
  <div class="ov-card">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-folder-open me-2" style="color:var(--ov-gold)"></i>Documents</h2></div>
    <div class="ov-card-body p-0">
      <?php if (empty($documents)): ?>
        <p class="text-center text-muted py-3 mb-0" style="font-size:0.85rem">Aucun document</p>
      <?php else: ?>
        <?php foreach ($documents as $doc): ?>
        <div class="d-flex align-items-center gap-2 px-3 py-2" style="border-bottom:1px solid #f0f2f5;font-size:0.82rem">
          <i class="fa <?= $docsLabels[$doc['type_document']][1] ?? 'fa-file' ?> text-muted"></i>
          <div class="flex-grow-1">
            <div><?= h($docsLabels[$doc['type_document']][0] ?? $doc['type_document']) ?></div>
            <div style="font-size:0.72rem;color:#9ca3af"><?= h($doc['nom_fichier']) ?></div>
          </div>
          <a href="<?= UPLOAD_URL ?>/<?= h($doc['chemin']) ?>" target="_blank" class="btn-sm-icon view" title="Voir"><i class="fa fa-eye"></i></a>
          <?php if (canDo('agents','edit')): ?>
          <a href="view.php?id=<?= $id ?>&del_doc=<?= $doc['id'] ?>" class="btn-sm-icon delete" title="Supprimer" data-confirm="Supprimer ce document ?"><i class="fa fa-trash"></i></a>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="col-lg-8">
  <!-- Informations personnelles -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-user me-2" style="color:var(--ov-gold)"></i>Informations personnelles</h2></div>
    <div class="ov-card-body">
      <?php
      $rows = [
        ['Date de naissance', formatDate($a['date_naissance']), 'Lieu de naissance', $a['lieu_naissance']],
        ['Nationalité', $a['nationalite'], 'N° Sécurité Sociale', $a['num_secu']],
        ['Situation familiale', $a['situation_familiale'], 'Nombre d\'enfants', $a['nb_enfants']],
        ['Adresse', $a['adresse'], 'CP / Ville', trim(($a['cp']??'').' '.($a['ville']??''))],
        ['Téléphone', $a['telephone'], 'Email', $a['email']],
      ];
      ?>
      <div class="row g-2">
      <?php foreach ($rows as $row): ?>
        <div class="col-md-6">
          <div class="p-2 rounded" style="background:#f8f9fa">
            <div style="font-size:0.72rem;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px"><?= h($row[0]) ?></div>
            <div style="font-size:0.875rem;color:#1a2332;font-weight:500"><?= h($row[1] ?: '—') ?></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="p-2 rounded" style="background:#f8f9fa">
            <div style="font-size:0.72rem;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px"><?= h($row[2]) ?></div>
            <div style="font-size:0.875rem;color:#1a2332;font-weight:500"><?= h($row[3] ?: '—') ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- Contrat -->
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-file-contract me-2" style="color:var(--ov-gold)"></i>Contrat</h2></div>
    <div class="ov-card-body">
      <?php
      $rows2 = [
        ['Type de contrat', $a['type_contrat'], 'Statut', $a['statut']],
        ['Date début', formatDate($a['date_debut_contrat']), 'Date fin', formatDate($a['date_fin_contrat'])],
        ['Lieu de travail', $a['lieu_travail'], 'Période d\'essai', $a['periode_essai']],
        ['Rémunération', $a['remuneration'] ? number_format($a['remuneration'],2).' € '.$a['type_remuneration'] : '—', 'Temps hebdomadaire', $a['temps_travail_hebdo']],
        ['Motif d\'embauche', $a['motif_embauche'], 'Bulletins depuis', $a['bulletins_depuis']],
      ];
      ?>
      <div class="row g-2">
      <?php foreach ($rows2 as $row): ?>
        <div class="col-md-6">
          <div class="p-2 rounded" style="background:#f8f9fa">
            <div style="font-size:0.72rem;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px"><?= h($row[0]) ?></div>
            <div style="font-size:0.875rem;color:#1a2332;font-weight:500"><?= h($row[1] ?: '—') ?></div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="p-2 rounded" style="background:#f8f9fa">
            <div style="font-size:0.72rem;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px"><?= h($row[2]) ?></div>
            <div style="font-size:0.875rem;color:#1a2332;font-weight:500"><?= h($row[3] ?: '—') ?></div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>

      <!-- Répartition horaire -->
      <?php $jours = ['lundi'=>'Lun','mardi'=>'Mar','mercredi'=>'Mer','jeudi'=>'Jeu','vendredi'=>'Ven','samedi'=>'Sam','dimanche'=>'Dim']; ?>
      <div class="mt-3">
        <div style="font-size:0.72rem;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:0.5rem">Répartition hebdomadaire</div>
        <div class="d-flex gap-2 flex-wrap">
          <?php foreach ($jours as $k => $v): ?>
          <?php $h_val = (float)$a['h_'.$k]; ?>
          <div class="text-center p-2 rounded" style="background:<?= $h_val > 0 ? 'rgba(201,168,76,0.1)' : '#f8f9fa' ?>;min-width:50px">
            <div style="font-size:0.7rem;color:#9ca3af"><?= $v ?></div>
            <div style="font-weight:700;color:<?= $h_val > 0 ? 'var(--ov-gold-dark)' : '#9ca3af' ?>"><?= $h_val > 0 ? $h_val.'h' : '—' ?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Champs personnalisés -->
  <?php
  try {
      $champsPerso = $db->query("SELECT * FROM agent_champs_perso WHERE actif=1 ORDER BY ordre")->fetchAll();
      $stmtVP = $db->prepare("SELECT avp.*, acp.label, acp.type FROM agent_valeurs_perso avp JOIN agent_champs_perso acp ON acp.id=avp.champ_id WHERE avp.agent_id=?");
      $stmtVP->execute([$id]);
      $valeursPerso = [];
      foreach ($stmtVP->fetchAll() as $vp) { $valeursPerso[$vp['champ_id']] = $vp; }
  } catch(Exception $e) { $champsPerso = []; $valeursPerso = []; }
  if ($champsPerso):
  ?>
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-sliders me-2" style="color:var(--ov-gold)"></i>Champs personnalisés</h2></div>
    <div class="ov-card-body">
      <div class="row g-2">
      <?php foreach ($champsPerso as $cp):
        $vp = $valeursPerso[$cp['id']] ?? null;
        $val = $vp['valeur'] ?? null;
        $fic = $vp['fichier'] ?? null;
      ?>
      <div class="col-md-6">
        <div class="p-2 rounded" style="background:#f8f9fa">
          <div style="font-size:0.72rem;color:#9ca3af;text-transform:uppercase;letter-spacing:0.5px"><?= h($cp['label']) ?></div>
          <div style="font-size:0.875rem;color:#1a2332;font-weight:500">
            <?php if ($cp['type'] === 'file'): ?>
              <?php if ($fic): ?>
              <a href="<?= UPLOAD_URL ?>/<?= h($fic) ?>" target="_blank" style="font-size:0.8rem"><i class="fa fa-file me-1"></i>Voir le fichier</a>
              <?php else: ?>—<?php endif; ?>
            <?php elseif ($cp['type'] === 'date' && $val): ?>
              <?= h(date('d/m/Y', strtotime($val))) ?>
            <?php elseif ($cp['type'] === 'textarea' && $val): ?>
              <span style="white-space:pre-line;font-size:0.82rem"><?= h($val) ?></span>
            <?php else: ?>
              <?= h($val ?: '—') ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Pôle Social -->
  <div class="ov-card">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-building-columns me-2" style="color:var(--ov-gold)"></i>Pôle Social</h2></div>
    <div class="ov-card-body">
      <div class="d-flex gap-3 flex-wrap">
        <?php
        $checks = [
          'dpae'            => 'DPAE réalisée',
          'contrat_realise' => 'Contrat réalisé',
          'prelevement_auto'=> 'Prélèvement auto.',
        ];
        foreach ($checks as $key => $label): ?>
        <div class="d-flex align-items-center gap-2">
          <span style="color:<?= $a[$key] ? '#16a34a' : '#dc2626' ?>"><i class="fa <?= $a[$key] ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i></span>
          <span style="font-size:0.85rem"><?= h($label) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
</div>

<?php if (canDo('agents','delete')): ?>
<!-- Modal suppression agent -->
<div class="modal fade" id="modalDelete" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold"><i class="fa fa-triangle-exclamation text-warning me-2"></i>Supprimer <?= h($a['prenom'].' '.$a['nom']) ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="alert mb-3" style="background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.2);border-radius:8px;font-size:0.875rem">
          <strong>Impact de la suppression définitive :</strong>
          <ul class="mb-0 mt-1">
            <li><?= $nbPlanning ?> ligne<?= $nbPlanning > 1 ? 's' : '' ?> de planning</li>
            <li><?= $nbDocs ?> document<?= $nbDocs > 1 ? 's' : '' ?> joint<?= $nbDocs > 1 ? 's' : '' ?></li>
            <?php if ($a['photo']): ?><li>1 photo de profil</li><?php endif; ?>
          </ul>
        </div>
        <p style="font-size:0.875rem">Choisissez une action :</p>
        <div class="d-flex flex-column gap-2">

          <!-- Désactiver -->
          <form method="POST" action="delete.php">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="deactivate">
            <button type="submit" class="btn w-100 text-start" style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:8px;padding:0.75rem 1rem">
              <div class="fw-bold" style="color:#b45309"><i class="fa fa-eye-slash me-2"></i>Désactiver l'agent</div>
              <div style="font-size:0.78rem;color:#92400e;margin-top:2px">L'agent reste en base mais n'apparaît plus dans le planning. Réversible.</div>
            </button>
          </form>

          <!-- Supprimer définitivement -->
          <form method="POST" action="delete.php" onsubmit="return confirm('Supprimer définitivement <?= addslashes($a['prenom'].' '.$a['nom']) ?> ? Cette action est irréversible.')">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="hard_delete">
            <button type="submit" class="btn w-100 text-start" style="background:rgba(239,68,68,0.06);border:1px solid rgba(239,68,68,0.25);border-radius:8px;padding:0.75rem 1rem">
              <div class="fw-bold" style="color:#dc2626"><i class="fa fa-trash me-2"></i>Supprimer définitivement</div>
              <div style="font-size:0.78rem;color:#991b1b;margin-top:2px">Supprime l'agent, son planning (<?= $nbPlanning ?> ligne<?= $nbPlanning>1?'s':'' ?>), ses documents et sa photo. Irréversible.</div>
            </button>
          </form>

        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-ov-secondary btn-sm" data-bs-dismiss="modal">Annuler</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
