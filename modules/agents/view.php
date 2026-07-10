<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('agents', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

// ── Migrations ───────────────────────────────────────────────────────────────
try { getDB()->exec("ALTER TABLE contrats ADD COLUMN IF NOT EXISTS dpae_chemin VARCHAR(255) NULL"); } catch(Exception $e){}
try { getDB()->exec("ALTER TABLE agent_documents ADD COLUMN IF NOT EXISTS date_expiration DATE NULL"); } catch(Exception $e){}
try { getDB()->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS alertes_ignorees TEXT NULL"); } catch(Exception $e){}

// ── Upload DPAE pour un contrat ───────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'upload_dpae') {
    requirePerm('agents','edit');
    $contratId = (int)($_POST['contrat_id'] ?? 0);
    if ($contratId && !empty($_FILES['dpae_file']['tmp_name'])) {
        $res = uploadFichier($_FILES['dpae_file'], 'documents', ['pdf','jpg','jpeg','png']);
        if ($res['ok']) {
            // Supprimer l'ancien
            $oldRow = getDB()->prepare("SELECT dpae_chemin FROM contrats WHERE id=? AND agent_id=?");
            $oldRow->execute([$contratId, $id]);
            $old = $oldRow->fetchColumn();
            if ($old) @unlink(UPLOAD_PATH.'/'.$old);
            getDB()->prepare("UPDATE contrats SET dpae_chemin=? WHERE id=? AND agent_id=?")->execute([$res['chemin'], $contratId, $id]);
            flash('success','DPAE uploadée.');
        } else { flash('danger','Erreur upload : '.$res['error']); }
    }
    header('Location: view.php?id='.$id); exit;
}

// ── Supprimer DPAE d'un contrat ───────────────────────────────────────────────
if (($_GET['del_dpae'] ?? false)) {
    requirePerm('agents','edit');
    $contratId = (int)$_GET['del_dpae'];
    $row = getDB()->prepare("SELECT dpae_chemin FROM contrats WHERE id=? AND agent_id=?");
    $row->execute([$contratId, $id]);
    $chemin = $row->fetchColumn();
    if ($chemin) { @unlink(UPLOAD_PATH.'/'.$chemin); getDB()->prepare("UPDATE contrats SET dpae_chemin=NULL WHERE id=?")->execute([$contratId]); flash('success','DPAE supprimée.'); }
    header('Location: view.php?id='.$id); exit;
}

// ── Ignorer / restaurer une alerte ───────────────────────────────────────────
if (($_POST['action'] ?? '') === 'ignore_alerte') {
    requirePerm('agents','edit');
    $key  = trim($_POST['alerte_key'] ?? '');
    if ($key) {
        $row  = $db->prepare("SELECT alertes_ignorees FROM agents WHERE id=?")->execute([$id]) ? $db->prepare("SELECT alertes_ignorees FROM agents WHERE id=?") : null;
        $stmt = $db->prepare("SELECT alertes_ignorees FROM agents WHERE id=?");
        $stmt->execute([$id]);
        $current = json_decode($stmt->fetchColumn() ?? '[]', true) ?: [];
        if (!in_array($key, $current)) $current[] = $key;
        $db->prepare("UPDATE agents SET alertes_ignorees=? WHERE id=?")->execute([json_encode($current), $id]);
    }
    header('Location: view.php?id='.$id.'#alerte-dossier'); exit;
}
if (($_POST['action'] ?? '') === 'restore_alerte') {
    requirePerm('agents','edit');
    $key = trim($_POST['alerte_key'] ?? '');
    if ($key) {
        $stmt = $db->prepare("SELECT alertes_ignorees FROM agents WHERE id=?");
        $stmt->execute([$id]);
        $current = json_decode($stmt->fetchColumn() ?? '[]', true) ?: [];
        $current = array_values(array_filter($current, function($k) use ($key) { return $k !== $key; }));
        $db->prepare("UPDATE agents SET alertes_ignorees=? WHERE id=?")->execute([json_encode($current), $id]);
    }
    header('Location: view.php?id='.$id.'#alerte-dossier'); exit;
}

// ── Upload / remplacement de document ────────────────────────────────────────
if (($_POST['action'] ?? '') === 'upload_doc') {
    requirePerm('agents','edit');
    $typeDoc = $_POST['type_document'] ?? '';
    $dateExp = trim($_POST['date_expiration'] ?? '');
    $label   = trim($_POST['doc_label'] ?? '');
    $validTypes = ['piece_identite','carte_vitale','attestation_domicile','titre_sejour','attestation_cnaps','rib','contrat','autre'];
    if (in_array($typeDoc, $validTypes) && !empty($_FILES['doc_file']['tmp_name'])) {
        $chemin = uploadFichier($_FILES['doc_file'], 'documents', ['pdf','jpg','jpeg','png','gif','webp']);
        if ($chemin) {
            // Remplace le(s) document(s) existant(s) du même type (sauf 'autre')
            if ($typeDoc !== 'autre') {
                $oldRows = $db->prepare("SELECT chemin FROM agent_documents WHERE agent_id=? AND type_document=?");
                $oldRows->execute([$id, $typeDoc]);
                foreach ($oldRows->fetchAll() as $o) @unlink(UPLOAD_PATH.'/'.$o['chemin']);
                $db->prepare("DELETE FROM agent_documents WHERE agent_id=? AND type_document=?")->execute([$id, $typeDoc]);
            }
            $nomFic = ($typeDoc === 'autre' && $label !== '') ? $label.' — '.$_FILES['doc_file']['name'] : $_FILES['doc_file']['name'];
            $db->prepare("INSERT INTO agent_documents (agent_id,type_document,nom_fichier,chemin,taille,date_expiration) VALUES (?,?,?,?,?,?)")
               ->execute([$id, $typeDoc, $nomFic, $chemin, $_FILES['doc_file']['size'], $dateExp ?: null]);
            flash('success','Document mis à jour.');
        } else { flash('danger','Erreur lors de l\'upload (format ou taille).'); }
    }
    header('Location: view.php?id='.$id.'#documents'); exit;
}

// ── Modifier type / date expiration d'un document existant (sans re-upload) ──
if (($_POST['action'] ?? '') === 'update_doc_meta') {
    requirePerm('agents','edit');
    $docId   = (int)($_POST['doc_id'] ?? 0);
    $newType = $_POST['type_document'] ?? '';
    $dateExp = trim($_POST['date_expiration'] ?? '');
    $validTypes = ['piece_identite','carte_vitale','attestation_domicile','titre_sejour','attestation_cnaps','rib','contrat','autre'];
    if ($docId && in_array($newType, $validTypes)) {
        // Si changement de type : vérifier qu'il n'y a pas déjà un doc de ce type (sauf 'autre')
        if ($newType !== 'autre') {
            $conflict = $db->prepare("SELECT id FROM agent_documents WHERE agent_id=? AND type_document=? AND id!=?");
            $conflict->execute([$id, $newType, $docId]);
            if ($row = $conflict->fetch()) {
                // Supprimer l'ancien doublon
                $old = $db->prepare("SELECT chemin FROM agent_documents WHERE id=?");
                $old->execute([$row['id']]);
                $o = $old->fetchColumn();
                if ($o) @unlink(UPLOAD_PATH.'/'.$o);
                $db->prepare("DELETE FROM agent_documents WHERE id=?")->execute([$row['id']]);
            }
        }
        $db->prepare("UPDATE agent_documents SET type_document=?, date_expiration=? WHERE id=? AND agent_id=?")
           ->execute([$newType, $dateExp ?: null, $docId, $id]);
        flash('success','Document mis à jour.');
    }
    header('Location: view.php?id='.$id.'#documents'); exit;
}

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

$docTypes    = array_column($documents, 'type_document');
$ignoredKeys = json_decode($a['alertes_ignorees'] ?? '[]', true) ?: [];
$completion  = agentCompletion($a, $docTypes, $documents, $ignoredKeys);

// Charger tous les contrats de l'agent
$allContrats = [];
try {
    $stAllC = $db->prepare("SELECT * FROM contrats WHERE agent_id=? ORDER BY created_at DESC, id DESC");
    $stAllC->execute([$id]);
    $allContrats = $stAllC->fetchAll();
} catch (Exception $e) { $allContrats = []; }

// Statut du dernier contrat actif
$contratSigStatus = 'aucun';
$sigTokenRow      = null;
$dernierContrat   = null;
try {
    foreach ($allContrats as $ct) { if ($ct['statut'] === 'actif') { $dernierContrat = $ct; break; } }
    if (!$dernierContrat && !empty($allContrats)) $dernierContrat = $allContrats[0];

    if ($dernierContrat && !empty($dernierContrat['signature'])) {
        $contratSigStatus = 'signe';
    } else {
        $stTok = $db->prepare("SELECT * FROM signature_tokens WHERE agent_id=? ORDER BY sent_at DESC LIMIT 1");
        $stTok->execute([$id]);
        $sigTokenRow = $stTok->fetch();
        if ($sigTokenRow) {
            if (!empty($sigTokenRow['signed_at']))                          $contratSigStatus = 'signe';
            elseif (strtotime($sigTokenRow['expires_at']) > time())         $contratSigStatus = 'lien_actif';
            else                                                             $contratSigStatus = 'lien_expire';
        } elseif (!empty($a['contrat_realise']) || !empty($allContrats)) {
            $contratSigStatus = 'cree';
        }
    }
} catch (Exception $e) {
    $contratSigStatus = !empty($a['contrat_realise']) ? 'cree' : 'aucun';
}

$docsLabels = [
    'piece_identite'      => ['Carte d\'identité','fa-id-card'],
    'titre_sejour'        => ['Carte de séjour','fa-passport'],
    'carte_vitale'        => ['Carte vitale','fa-heart-pulse'],
    'attestation_domicile'=> ['Attestation domicile','fa-house'],
    'attestation_cnaps'   => ['Attestation CNAPS','fa-shield-halved'],
    'rib'                 => ['RIB','fa-building-columns'],
    'contrat'             => ['Contrat','fa-file-contract'],
    'autre'               => ['Document','fa-file'],
];
// Types pour lesquels on propose la saisie d'une date d'expiration
$docsWithExpiry = ['piece_identite','titre_sejour','attestation_domicile'];
?>

<?php
// Impact counts pour le modal de suppression
$nbPlanning = 0; $nbDocs = 0;
if (canDo('agents','delete')) {
    $s1 = $db->prepare("SELECT COUNT(*) FROM planning_lignes WHERE agent_id=?"); $s1->execute([$id]); $nbPlanning = (int)$s1->fetchColumn();
    $s2 = $db->prepare("SELECT COUNT(*) FROM agent_documents WHERE agent_id=?"); $s2->execute([$id]); $nbDocs = (int)$s2->fetchColumn();
}
?>

<?php if (!$completion['ok'] || $completion['ignored']): ?>
<div id="alerte-dossier" class="mb-3">
    <?php
    // Macro pour afficher une ligne d'alerte avec bouton Ignorer/Restaurer
    function alertItem(array $item, string $bgColor, string $action, string $btnLabel, string $btnStyle, int $agentId): void {
        echo '<div style="display:inline-flex;align-items:center;gap:4px;background:'.$bgColor.';padding:2px 6px 2px 8px;border-radius:10px;margin:2px;font-size:0.78rem">';
        echo '<i class="fa '.h($item['icon']).'" style="font-size:0.7rem"></i> '.h($item['label']);
        if (canDo('agents','edit')) {
            echo '<form method="POST" style="display:inline;margin:0">';
            echo '<input type="hidden" name="action" value="'.$action.'">';
            echo '<input type="hidden" name="alerte_key" value="'.h($item['key']).'">';
            echo '<button type="submit" title="'.$btnLabel.'" style="'.$btnStyle.'border:none;background:none;padding:0 2px;cursor:pointer;line-height:1;font-size:0.75rem;opacity:0.7">'.$btnLabel.'</button>';
            echo '</form>';
        }
        echo '</div>';
    }
    ?>
    <?php if ($completion['errors']): ?>
    <div class="alert p-3 mb-2" style="background:rgba(239,68,68,0.07);border:1.5px solid #ef4444;border-radius:12px;color:#7f1d1d">
        <div class="d-flex align-items-start gap-2">
            <i class="fa fa-circle-xmark mt-1" style="color:#ef4444;font-size:1.1rem;flex-shrink:0"></i>
            <div class="flex-grow-1">
                <div style="font-weight:700;font-size:0.92rem;margin-bottom:6px">
                    <?= count($completion['errors']) ?> élément<?= count($completion['errors'])>1?'s':'' ?> bloquant<?= count($completion['errors'])>1?'s':'' ?>
                </div>
                <?php $bycat = []; foreach ($completion['errors'] as $e) $bycat[$e['cat']][] = $e; ?>
                <?php foreach ($bycat as $cat => $items): ?>
                <div style="font-size:0.82rem;margin-bottom:4px">
                    <strong><?= h($cat) ?> :</strong>
                    <?php foreach ($items as $e) alertItem($e, 'rgba(239,68,68,0.1)', 'ignore_alerte', '✕', 'color:#7f1d1d;', $id); ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if (canDo('agents','edit')): ?>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-sm ms-auto" style="white-space:nowrap;background:rgba(239,68,68,0.1);color:#7f1d1d;border:1px solid #ef4444;border-radius:8px;font-size:0.8rem;padding:4px 12px">
                <i class="fa fa-pen me-1"></i>Compléter
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($completion['warnings']): ?>
    <div class="alert p-3 mb-2" style="background:rgba(245,158,11,0.07);border:1.5px solid #f59e0b;border-radius:12px;color:#92400e">
        <div class="d-flex align-items-start gap-2">
            <i class="fa fa-triangle-exclamation mt-1" style="color:#f59e0b;font-size:1.1rem;flex-shrink:0"></i>
            <div class="flex-grow-1">
                <div style="font-weight:700;font-size:0.92rem;margin-bottom:6px">
                    <?= count($completion['warnings']) ?> point<?= count($completion['warnings'])>1?'s':'' ?> à surveiller
                </div>
                <?php $bycatW = []; foreach ($completion['warnings'] as $w) $bycatW[$w['cat']][] = $w; ?>
                <?php foreach ($bycatW as $cat => $items): ?>
                <div style="font-size:0.82rem;margin-bottom:4px">
                    <strong><?= h($cat) ?> :</strong>
                    <?php foreach ($items as $w) alertItem($w, 'rgba(245,158,11,0.13)', 'ignore_alerte', '✕', 'color:#92400e;', $id); ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($completion['ignored']): ?>
    <div style="font-size:0.78rem;color:#9ca3af;padding:4px 8px;border-radius:8px;background:#f8fafc;border:1px solid #e2e8f0">
        <span style="font-weight:600">Alertes ignorées :</span>
        <?php foreach ($completion['ignored'] as $ig) alertItem($ig, 'rgba(107,114,128,0.08)', 'restore_alerte', '↩', 'color:#6b7280;', $id); ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="index.php" class="btn btn-ov-secondary"><i class="fa fa-arrow-left me-1"></i>Retour</a>
    <?php if (canDo('agents','edit')): ?>
    <a href="edit.php?id=<?= $id ?>" class="btn btn-ov-secondary"><i class="fa fa-pen me-1"></i>Modifier</a>
    <?php endif; ?>
    <a href="carte.php?id=<?= $id ?>" class="btn btn-ov-primary"><i class="fa fa-id-card me-1"></i>Carte agent</a>
    <a href="export_pdf.php?id=<?= $id ?>" class="btn" style="background:rgba(239,68,68,0.1);color:#dc2626;border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem"><i class="fa fa-file-pdf me-1"></i>Export PDF comptable</a>
    <a href="contrat.php?id=<?= $id ?>" class="btn" style="background:rgba(201,168,76,0.1);color:#92400e;border:1px solid rgba(201,168,76,0.3);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem"><i class="fa fa-file-contract me-1"></i>Contrat</a>
    <a href="avenant.php?id=<?= $id ?>" class="btn" style="background:rgba(16,185,129,0.1);color:#065f46;border:1px solid rgba(16,185,129,0.3);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem"><i class="fa fa-file-pen me-1"></i>Avenant</a>
    <a href="analyse_contrat.php?id=<?= $id ?>" class="btn" style="background:rgba(99,102,241,0.1);color:#3730a3;border:1px solid rgba(99,102,241,0.3);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem"><i class="fa fa-magnifying-glass-chart me-1"></i>Analyser</a>
    <a href="download_dossier.php?id=<?= $id ?>" class="btn" style="background:rgba(6,182,212,0.1);color:#0e7490;border:1px solid rgba(6,182,212,0.3);border-radius:8px;padding:0.45rem 1rem;font-size:0.875rem" title="Télécharger le contrat + toutes les pièces jointes en ZIP"><i class="fa fa-file-zipper me-1"></i>Dossier pôle social</a>
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
  <div class="ov-card" id="documents">
    <div class="ov-card-header">
      <h2 class="ov-card-title"><i class="fa fa-folder-open me-2" style="color:var(--ov-gold)"></i>Documents</h2>
      <?php if (canDo('agents','edit')): ?>
      <button class="btn btn-sm" style="background:rgba(201,168,76,0.1);color:#92400e;border:1px solid rgba(201,168,76,0.3);border-radius:8px;font-size:0.78rem"
              type="button" data-bs-toggle="collapse" data-bs-target="#formAjoutDoc">
        <i class="fa fa-plus me-1"></i>Ajouter
      </button>
      <?php endif; ?>
    </div>
    <?php if (canDo('agents','edit')): ?>
    <div class="collapse" id="formAjoutDoc">
      <form method="POST" enctype="multipart/form-data" class="p-3" style="background:#fafafa;border-bottom:1px solid #f0f2f5">
        <input type="hidden" name="action" value="upload_doc">
        <div class="row g-2 align-items-end">
          <div class="col-12 col-sm-5">
            <label class="form-label mb-1" style="font-size:0.78rem;font-weight:600">Type de document</label>
            <select name="type_document" class="form-select form-select-sm" id="selAddType" onchange="toggleAddDocFields()">
              <?php
              $nat2 = strtolower(trim($a['nationalite'] ?? ''));
              $agentFr = ($nat2 === '' || str_contains($nat2, 'fran'));
              foreach ($docsLabels as $val => [$lbl, $ico]):
                  // Masquer le type d'identité non applicable
                  if ($val === 'piece_identite' && !$agentFr) continue;
                  if ($val === 'titre_sejour'   &&  $agentFr) continue;
              ?>
              <option value="<?= $val ?>"><?= h($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm-4" id="expiryAdd">
            <label class="form-label mb-1" style="font-size:0.78rem;font-weight:600">Date d'expiration</label>
            <input type="date" name="date_expiration" class="form-control form-control-sm">
          </div>
          <div class="col-12 col-sm-3 d-none" id="labelAdd">
            <label class="form-label mb-1" style="font-size:0.78rem;font-weight:600">Libellé</label>
            <input type="text" name="doc_label" class="form-control form-control-sm" placeholder="ex: Diplôme">
          </div>
          <div class="col-12">
            <label class="form-label mb-1" style="font-size:0.78rem;font-weight:600">Fichier <span style="color:#9ca3af">(PDF, image)</span></label>
            <input type="file" name="doc_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" required>
          </div>
          <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-ov-primary"><i class="fa fa-upload me-1"></i>Enregistrer</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#formAjoutDoc">Annuler</button>
          </div>
        </div>
      </form>
    </div>
    <?php endif; ?>
    <div class="ov-card-body p-0">
      <?php if (empty($documents)): ?>
        <p class="text-center text-muted py-3 mb-0" style="font-size:0.85rem">Aucun document</p>
      <?php else: ?>
        <?php foreach ($documents as $doc): ?>
        <?php
        $dtype = $doc['type_document'];
        [$dlabel, $dicon] = $docsLabels[$dtype] ?? ['Document','fa-file'];
        if ($dtype === 'autre') {
            $parts  = explode(' — ', $doc['nom_fichier'], 2);
            $dlabel = $parts[0];
            $dsub   = $parts[1] ?? '';
        } else {
            $dsub = $doc['nom_fichier'];
        }
        // Pour l'attestation CNAPS, réutiliser la date déjà enregistrée sur la fiche agent
        $exp    = ($dtype === 'attestation_cnaps') ? ($a['date_expiration_cnaps'] ?? null) : ($doc['date_expiration'] ?? null);
        $expTs  = $exp ? strtotime($exp) : null;
        $expCls = '';
        $expTxt = '';
        if ($expTs) {
            $expTxt = date('d/m/Y', $expTs);
            if ($expTs < time())                       { $expCls = 'text-danger fw-bold'; $expTxt .= ' — EXPIRÉ'; }
            elseif ($expTs < strtotime('+60 days'))    { $expCls = 'text-warning fw-bold'; $expTxt .= ' ('.ceil(($expTs-time())/86400).' j)'; }
        }
        $hasExpiry = in_array($dtype, $docsWithExpiry);
        $colMeta    = 'meta-'.$doc['id'];
        $colReplace = 'replace-'.$doc['id'];
        ?>
        <div>
          <div class="d-flex align-items-center gap-2 px-3 py-2" style="border-bottom:1px solid #f0f2f5;font-size:0.82rem">
            <i class="fa <?= h($dicon) ?> text-muted" style="width:16px;text-align:center"></i>
            <div class="flex-grow-1">
              <div class="fw-500"><?= h($dlabel) ?></div>
              <div style="font-size:0.72rem;color:#9ca3af"><?= h($dsub) ?></div>
              <?php if ($expTxt): ?>
              <div style="font-size:0.72rem" class="<?= $expCls ?>"><i class="fa fa-calendar-days me-1"></i>Exp : <?= h($expTxt) ?></div>
              <?php elseif ($hasExpiry): ?>
              <div style="font-size:0.72rem;color:#f59e0b"><i class="fa fa-calendar-xmark me-1"></i>Date d'expiration non renseignée</div>
              <?php endif; ?>
            </div>
            <a href="<?= UPLOAD_URL ?>/<?= h($doc['chemin']) ?>" target="_blank" class="btn-sm-icon view" title="Voir/télécharger"><i class="fa fa-eye"></i></a>
            <?php if (canDo('agents','edit')): ?>
            <button type="button" class="btn-sm-icon" style="background:rgba(99,102,241,0.1);color:#4f46e5;border:none;width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer"
                    data-bs-toggle="collapse" data-bs-target="#<?= $colMeta ?>" title="Modifier le type / la date d'expiration">
              <i class="fa fa-pen" style="font-size:0.7rem"></i>
            </button>
            <button type="button" class="btn-sm-icon" style="background:rgba(245,158,11,0.1);color:#92400e;border:none;width:28px;height:28px;border-radius:6px;display:flex;align-items:center;justify-content:center;cursor:pointer"
                    data-bs-toggle="collapse" data-bs-target="#<?= $colReplace ?>" title="Remplacer le fichier">
              <i class="fa fa-arrow-up-from-bracket" style="font-size:0.7rem"></i>
            </button>
            <a href="view.php?id=<?= $id ?>&del_doc=<?= $doc['id'] ?>" class="btn-sm-icon delete" title="Supprimer" data-confirm="Supprimer ce document ?"><i class="fa fa-trash"></i></a>
            <?php endif; ?>
          </div>
          <?php if (canDo('agents','edit')): ?>
          <!-- Modifier type / date expiration (sans re-upload) -->
          <div class="collapse" id="<?= $colMeta ?>">
            <form method="POST" class="px-3 py-2 d-flex align-items-end gap-2 flex-wrap" style="background:#eef2ff;border-bottom:1px solid #c7d2fe">
              <input type="hidden" name="action" value="update_doc_meta">
              <input type="hidden" name="doc_id" value="<?= $doc['id'] ?>">
              <div>
                <label class="form-label mb-1" style="font-size:0.72rem;font-weight:600">Type de document</label>
                <select name="type_document" class="form-select form-select-sm" style="width:180px">
                  <?php foreach ($docsLabels as $val => [$lbl2, $ico2]): ?>
                  <option value="<?= $val ?>" <?= $val===$dtype?'selected':'' ?>><?= h($lbl2) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label class="form-label mb-1" style="font-size:0.72rem;font-weight:600">Date d'expiration</label>
                <input type="date" name="date_expiration" class="form-control form-control-sm" value="<?= h($exp ?? '') ?>" style="width:150px">
              </div>
              <button type="submit" class="btn btn-sm" style="background:#4f46e5;color:#fff;border:none;border-radius:7px;padding:5px 12px;font-size:0.78rem">
                <i class="fa fa-check me-1"></i>Enregistrer
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#<?= $colMeta ?>" style="font-size:0.78rem">Annuler</button>
            </form>
          </div>
          <!-- Remplacer le fichier -->
          <div class="collapse" id="<?= $colReplace ?>">
            <form method="POST" enctype="multipart/form-data" class="px-3 py-2 d-flex align-items-end gap-2 flex-wrap" style="background:#fff8ec;border-bottom:1px solid #fde68a">
              <input type="hidden" name="action" value="upload_doc">
              <input type="hidden" name="type_document" value="<?= h($dtype) ?>">
              <?php if ($dtype === 'autre'): ?>
              <div>
                <label class="form-label mb-1" style="font-size:0.72rem">Libellé</label>
                <input type="text" name="doc_label" class="form-control form-control-sm" value="<?= h($dlabel) ?>" style="width:130px">
              </div>
              <?php endif; ?>
              <?php if ($hasExpiry): ?>
              <div>
                <label class="form-label mb-1" style="font-size:0.72rem">Date d'expiration</label>
                <input type="date" name="date_expiration" class="form-control form-control-sm" value="<?= h($exp ?? '') ?>" style="width:140px">
              </div>
              <?php endif; ?>
              <div>
                <label class="form-label mb-1" style="font-size:0.72rem">Nouveau fichier</label>
                <input type="file" name="doc_file" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp" required style="width:180px">
              </div>
              <button type="submit" class="btn btn-sm" style="background:#f59e0b;color:#fff;border:none;border-radius:7px;padding:5px 12px;font-size:0.78rem">
                <i class="fa fa-upload me-1"></i>Remplacer
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#<?= $colReplace ?>" style="font-size:0.78rem">Annuler</button>
            </form>
          </div>
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

  <!-- Contrats (liste) -->
  <div class="ov-card mb-3">
    <div class="ov-card-header d-flex align-items-center justify-content-between">
      <h2 class="ov-card-title mb-0"><i class="fa fa-file-contract me-2" style="color:var(--ov-gold)"></i>Contrats
        <?php if (count($allContrats) > 0): ?>
        <span class="badge ms-1" style="background:rgba(201,168,76,0.15);color:#92400e;font-size:0.72rem"><?= count($allContrats) ?></span>
        <?php endif; ?>
      </h2>
      <a href="contrat.php?id=<?= $id ?>&action=new" class="btn btn-sm btn-ov-primary" style="font-size:0.78rem;padding:3px 10px">
        <i class="fa fa-plus me-1"></i>Nouveau
      </a>
    </div>
    <div class="ov-card-body p-0">
      <?php if (empty($allContrats)): ?>
        <p class="text-center text-muted py-3 mb-0" style="font-size:0.85rem">Aucun contrat</p>
      <?php else: ?>
        <?php foreach ($allContrats as $ct):
          $debut   = $ct['date_debut'] ? date('d/m/Y', strtotime($ct['date_debut'])) : '?';
          $fin     = $ct['date_fin']   ? date('d/m/Y', strtotime($ct['date_fin']))   : '—';
          $archive = $ct['statut'] === 'archive';
          $signed  = !empty($ct['signature']);
        ?>
        <div style="border-bottom:1px solid #f0f2f5;<?= $archive ? 'opacity:0.65' : '' ?>">
          <div class="d-flex align-items-center gap-2 px-3 py-2">
            <i class="fa fa-file-contract fa-fw text-muted" style="font-size:0.85rem"></i>
            <div class="flex-grow-1" style="min-width:0">
              <div style="font-size:0.85rem;font-weight:600;color:#1a2332">
                <?= h($ct['type_contrat'] ?? 'CDD') ?>
                <span class="text-muted fw-normal"><?= h($debut) ?> → <?= h($fin) ?></span>
              </div>
              <div style="font-size:0.72rem;color:#9ca3af">
                <?= $ct['total_heures_contrat'] ? h(number_format($ct['total_heures_contrat'],2)).'h' : '' ?>
                <?= !empty($ct['remuneration']) ? ' · '.number_format($ct['remuneration'],2).' €/h' : '' ?>
              </div>
            </div>
            <div class="d-flex align-items-center gap-1 flex-shrink-0">
              <?php if ($signed): ?>
                <span title="Signé" style="font-size:0.7rem;background:rgba(34,197,94,0.1);color:#16a34a;padding:2px 7px;border-radius:10px"><i class="fa fa-check me-1"></i>Signé</span>
              <?php endif; ?>
              <?php if ($archive): ?>
                <span style="font-size:0.7rem;background:#f3f4f6;color:#6b7280;padding:2px 7px;border-radius:10px">Archivé</span>
              <?php endif; ?>
              <a href="contrat.php?id=<?= $id ?>&contrat_id=<?= $ct['id'] ?>&dl=1" class="btn-sm-icon" title="Télécharger PDF" style="color:#2563eb"><i class="fa fa-download"></i></a>
              <a href="contrat.php?id=<?= $id ?>&contrat_id=<?= $ct['id'] ?>" class="btn-sm-icon" title="Éditer" style="color:#6b7280"><i class="fa fa-pen"></i></a>
            </div>
          </div>
          <!-- DPAE par contrat -->
          <div class="px-3 pb-2 d-flex align-items-center gap-2" style="flex-wrap:wrap">
            <span style="font-size:0.72rem;font-weight:600;color:#6b7280;white-space:nowrap"><i class="fa fa-file-signature me-1"></i>DPAE :</span>
            <?php if (!empty($ct['dpae_chemin'])): ?>
              <a href="<?= UPLOAD_URL ?>/<?= h($ct['dpae_chemin']) ?>" target="_blank"
                 style="font-size:0.72rem;color:#16a34a;font-weight:600"><i class="fa fa-check-circle me-1"></i>Voir DPAE</a>
              <?php if (canDo('agents','edit')): ?>
              <a href="view.php?id=<?= $id ?>&del_dpae=<?= $ct['id'] ?>"
                 onclick="return confirm('Supprimer cette DPAE ?')"
                 style="font-size:0.7rem;color:#dc2626"><i class="fa fa-trash"></i></a>
              <?php endif; ?>
            <?php else: ?>
              <span style="font-size:0.72rem;color:#f59e0b"><i class="fa fa-triangle-exclamation me-1"></i>Non uploadée</span>
            <?php endif; ?>
            <?php if (canDo('agents','edit')): ?>
            <form method="POST" enctype="multipart/form-data" class="d-flex align-items-center gap-1">
              <input type="hidden" name="action" value="upload_dpae">
              <input type="hidden" name="contrat_id" value="<?= $ct['id'] ?>">
              <input type="file" name="dpae_file" accept=".pdf,.jpg,.jpeg,.png"
                     class="form-control form-control-sm"
                     style="font-size:0.7rem;max-width:200px;padding:2px 6px;height:auto"
                     onchange="this.form.submit()">
            </form>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
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

<script>
var docsWithExpiry = <?= json_encode($docsWithExpiry) ?>;
function toggleAddDocFields() {
    var sel = document.getElementById('selAddType');
    if (!sel) return;
    var v = sel.value;
    document.getElementById('expiryAdd').style.display = docsWithExpiry.includes(v) ? '' : 'none';
    document.getElementById('labelAdd').style.display  = v === 'autre' ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleAddDocFields);
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
