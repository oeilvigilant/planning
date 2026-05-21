<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
require_once __DIR__ . '/../../includes/contrat_builder.php';
requireLogin();
requirePerm('agents', 'view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { flash('danger','Agent introuvable.'); header('Location: index.php'); exit; }

$params = getAllParams();

$defaults = [
    'civilite'              => 'M.',
    'nom_prenom'            => strtoupper($a['nom']) . ' ' . $a['prenom'],
    'adresse'               => trim(($a['adresse']??'') . ', ' . ($a['cp']??'') . ' ' . ($a['ville']??''), ', '),
    'poste'                 => $a['poste'] ?? 'Agent de sécurité',
    'type_contrat'          => $a['type_contrat'] ?? 'CDD',
    'type_remuneration'     => $a['type_remuneration'] ?? 'Brute',
    'avenant_numero'        => '1',
    'date_contrat_reference'=> $a['date_debut_contrat'] ? date('d/m/Y', strtotime($a['date_debut_contrat'])) : '',
    'date_effet'            => date('d/m/Y'),
    'date_signature'        => date('d/m/Y'),
    'lieu_signature'        => $params['entreprise_ville'] ?? 'Paris',
    'types_modification'    => [],
    // Site
    'ancien_site'           => $a['lieu_travail'] ?? '',
    'nouveau_site'          => '',
    // Salaire
    'ancien_salaire'        => $a['remuneration'] ? number_format((float)$a['remuneration'], 2, '.', '') : '',
    'nouveau_salaire'       => '',
    // Prolongation
    'ancienne_date_fin'     => $a['date_fin_contrat'] ? date('d/m/Y', strtotime($a['date_fin_contrat'])) : '',
    'nouvelle_date_fin'     => '',
    'total_heures_nouveau'  => '',
    // Poste
    'poste_precedent'       => $a['poste'] ?? '',
    'nouveau_poste'         => '',
    'nouvelle_categorie'    => '',
    // Horaires
    'ancien_total_heures'   => '',
    'nouveau_total_heures'  => '',
    // Autre
    'titre_autre'           => '',
    'contenu_autre'         => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = array_merge($defaults, $_POST);
    $data['types_modification'] = $_POST['types_modification'] ?? [];
    $exportPdf = !empty($_POST['export_pdf']) && $_POST['export_pdf'] === '1';
    if ($exportPdf) {
        $html = buildAvenantHtml($data, $params, $a);
        $fname = 'avenant_' . $data['avenant_numero'] . '_' . strtolower(str_replace(' ','_',$a['nom'])) . '.pdf';
        renderPdf($html, $fname);
    }
    $data = $data; // keep for re-display
} else {
    $data = $defaults;
}

$pageTitle    = 'Avenant — ' . $a['prenom'] . ' ' . $a['nom'];
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex gap-2 mb-3 flex-wrap">
  <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour fiche</a>
  <a href="contrat.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-file-contract me-1"></i>Contrat principal</a>
</div>

<div class="row g-3">

<!-- Formulaire -->
<div class="col-lg-5">
  <form method="POST" id="avenantForm">
    <input type="hidden" name="export_pdf" value="0" id="exportFlag">

    <!-- En-tête avenant -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-file-pen me-2" style="color:var(--ov-gold)"></i>Avenant</h2></div>
      <div class="ov-card-body">
        <div class="row g-2">
          <div class="col-4">
            <label class="form-label">N° avenant</label>
            <input type="number" name="avenant_numero" class="form-control form-control-sm" min="1" value="<?= h($data['avenant_numero']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-4">
            <label class="form-label">Date de référence</label>
            <input type="text" name="date_contrat_reference" class="form-control form-control-sm" value="<?= h($data['date_contrat_reference']) ?>" placeholder="dd/mm/yyyy" oninput="updatePreview()">
          </div>
          <div class="col-4">
            <label class="form-label">Date d'effet</label>
            <input type="text" name="date_effet" class="form-control form-control-sm" value="<?= h($data['date_effet']) ?>" placeholder="dd/mm/yyyy" oninput="updatePreview()">
          </div>
          <div class="col-4">
            <label class="form-label">Civilité</label>
            <select name="civilite" class="form-select form-select-sm" onchange="updatePreview()">
              <option value="M." <?= $data['civilite']==='M.'?'selected':'' ?>>M.</option>
              <option value="Mme" <?= $data['civilite']==='Mme'?'selected':'' ?>>Mme</option>
            </select>
          </div>
          <div class="col-8">
            <label class="form-label">Nom Prénom</label>
            <input type="text" name="nom_prenom" class="form-control form-control-sm" value="<?= h($data['nom_prenom']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-12">
            <label class="form-label">Adresse</label>
            <input type="text" name="adresse" class="form-control form-control-sm" value="<?= h($data['adresse']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Type contrat</label>
            <select name="type_contrat" class="form-select form-select-sm" onchange="updatePreview()">
              <option value="CDD" <?= $data['type_contrat']==='CDD'?'selected':'' ?>>CDD</option>
              <option value="CDD Usage" <?= $data['type_contrat']==='CDD Usage'?'selected':'' ?>>CDD d'Usage</option>
              <option value="CDI" <?= $data['type_contrat']==='CDI'?'selected':'' ?>>CDI</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Poste actuel</label>
            <input type="text" name="poste" class="form-control form-control-sm" value="<?= h($data['poste']) ?>" oninput="updatePreview()">
          </div>
        </div>
      </div>
    </div>

    <!-- Types de modification -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-list-check me-2" style="color:var(--ov-gold)"></i>Modifications</h2></div>
      <div class="ov-card-body">
        <p class="text-muted" style="font-size:0.8rem">Cochez les modifications à inclure dans l'avenant.</p>

        <!-- Site -->
        <div class="mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input mod-toggle" type="checkbox" name="types_modification[]" value="site" id="modSite" <?= in_array('site',$data['types_modification'])?'checked':'' ?> onchange="toggleMod('site',this.checked)">
            <label class="form-check-label fw-semibold" for="modSite"><i class="fa fa-map-pin me-1 text-muted"></i>Changement de site</label>
          </div>
          <div id="blockSite" class="ps-3 <?= !in_array('site',$data['types_modification'])?'d-none':'' ?>">
            <div class="row g-2">
              <div class="col-6"><label class="form-label" style="font-size:0.78rem">Site précédent</label>
                <input type="text" name="ancien_site" class="form-control form-control-sm" value="<?= h($data['ancien_site']) ?>" oninput="updatePreview()"></div>
              <div class="col-6"><label class="form-label" style="font-size:0.78rem">Nouveau site <span class="text-danger">*</span></label>
                <input type="text" name="nouveau_site" class="form-control form-control-sm" value="<?= h($data['nouveau_site']) ?>" oninput="updatePreview()"></div>
            </div>
          </div>
        </div>

        <!-- Salaire -->
        <div class="mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input mod-toggle" type="checkbox" name="types_modification[]" value="salaire" id="modSalaire" <?= in_array('salaire',$data['types_modification'])?'checked':'' ?> onchange="toggleMod('salaire',this.checked)">
            <label class="form-check-label fw-semibold" for="modSalaire"><i class="fa fa-euro-sign me-1 text-muted"></i>Modification de la rémunération</label>
          </div>
          <div id="blockSalaire" class="ps-3 <?= !in_array('salaire',$data['types_modification'])?'d-none':'' ?>">
            <div class="row g-2">
              <div class="col-4"><label class="form-label" style="font-size:0.78rem">Type</label>
                <select name="type_remuneration" class="form-select form-select-sm" onchange="updatePreview()">
                  <option value="Brute" <?= $data['type_remuneration']==='Brute'?'selected':'' ?>>Brute</option>
                  <option value="Nette" <?= $data['type_remuneration']==='Nette'?'selected':'' ?>>Nette</option>
                </select></div>
              <div class="col-4"><label class="form-label" style="font-size:0.78rem">Ancien taux (€/h)</label>
                <input type="number" name="ancien_salaire" step="0.01" class="form-control form-control-sm" value="<?= h($data['ancien_salaire']) ?>" oninput="updatePreview()"></div>
              <div class="col-4"><label class="form-label" style="font-size:0.78rem">Nouveau taux <span class="text-danger">*</span></label>
                <input type="number" name="nouveau_salaire" step="0.01" class="form-control form-control-sm" value="<?= h($data['nouveau_salaire']) ?>" oninput="updatePreview()"></div>
            </div>
          </div>
        </div>

        <!-- Prolongation -->
        <div class="mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input mod-toggle" type="checkbox" name="types_modification[]" value="prolongation" id="modProlongation" <?= in_array('prolongation',$data['types_modification'])?'checked':'' ?> onchange="toggleMod('prolongation',this.checked)">
            <label class="form-check-label fw-semibold" for="modProlongation"><i class="fa fa-calendar-plus me-1 text-muted"></i>Prolongation du CDD</label>
          </div>
          <div id="blockProlongation" class="ps-3 <?= !in_array('prolongation',$data['types_modification'])?'d-none':'' ?>">
            <div class="row g-2">
              <div class="col-4"><label class="form-label" style="font-size:0.78rem">Terme actuel</label>
                <input type="text" name="ancienne_date_fin" class="form-control form-control-sm" value="<?= h($data['ancienne_date_fin']) ?>" placeholder="dd/mm/yyyy" oninput="updatePreview()"></div>
              <div class="col-4"><label class="form-label" style="font-size:0.78rem">Nouveau terme <span class="text-danger">*</span></label>
                <input type="text" name="nouvelle_date_fin" class="form-control form-control-sm" value="<?= h($data['nouvelle_date_fin']) ?>" placeholder="dd/mm/yyyy" oninput="updatePreview()"></div>
              <div class="col-4"><label class="form-label" style="font-size:0.78rem">Nouvelles heures totales</label>
                <input type="number" name="total_heures_nouveau" step="0.5" class="form-control form-control-sm" value="<?= h($data['total_heures_nouveau']) ?>" placeholder="ex: 48" oninput="updatePreview()"></div>
            </div>
          </div>
        </div>

        <!-- Poste -->
        <div class="mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input mod-toggle" type="checkbox" name="types_modification[]" value="poste" id="modPoste" <?= in_array('poste',$data['types_modification'])?'checked':'' ?> onchange="toggleMod('poste',this.checked)">
            <label class="form-check-label fw-semibold" for="modPoste"><i class="fa fa-id-badge me-1 text-muted"></i>Modification du poste / fonctions</label>
          </div>
          <div id="blockPoste" class="ps-3 <?= !in_array('poste',$data['types_modification'])?'d-none':'' ?>">
            <div class="row g-2">
              <div class="col-6"><label class="form-label" style="font-size:0.78rem">Poste précédent</label>
                <input type="text" name="poste_precedent" class="form-control form-control-sm" value="<?= h($data['poste_precedent']) ?>" oninput="updatePreview()"></div>
              <div class="col-6"><label class="form-label" style="font-size:0.78rem">Nouveau poste <span class="text-danger">*</span></label>
                <input type="text" name="nouveau_poste" class="form-control form-control-sm" value="<?= h($data['nouveau_poste']) ?>" oninput="updatePreview()"></div>
              <div class="col-12"><label class="form-label" style="font-size:0.78rem">Nouvelle catégorie / coefficient</label>
                <input type="text" name="nouvelle_categorie" class="form-control form-control-sm" value="<?= h($data['nouvelle_categorie']) ?>" oninput="updatePreview()"></div>
            </div>
          </div>
        </div>

        <!-- Horaires -->
        <div class="mb-3">
          <div class="form-check mb-2">
            <input class="form-check-input mod-toggle" type="checkbox" name="types_modification[]" value="horaires" id="modHoraires" <?= in_array('horaires',$data['types_modification'])?'checked':'' ?> onchange="toggleMod('horaires',this.checked)">
            <label class="form-check-label fw-semibold" for="modHoraires"><i class="fa fa-clock me-1 text-muted"></i>Modification de la durée du travail</label>
          </div>
          <div id="blockHoraires" class="ps-3 <?= !in_array('horaires',$data['types_modification'])?'d-none':'' ?>">
            <div class="row g-2">
              <div class="col-6"><label class="form-label" style="font-size:0.78rem">Heures actuelles</label>
                <input type="number" name="ancien_total_heures" step="0.5" class="form-control form-control-sm" value="<?= h($data['ancien_total_heures']) ?>" oninput="updatePreview()"></div>
              <div class="col-6"><label class="form-label" style="font-size:0.78rem">Nouvelles heures <span class="text-danger">*</span></label>
                <input type="number" name="nouveau_total_heures" step="0.5" class="form-control form-control-sm" value="<?= h($data['nouveau_total_heures']) ?>" oninput="updatePreview()"></div>
            </div>
          </div>
        </div>

        <!-- Autre -->
        <div class="mb-0">
          <div class="form-check mb-2">
            <input class="form-check-input mod-toggle" type="checkbox" name="types_modification[]" value="autre" id="modAutre" <?= in_array('autre',$data['types_modification'])?'checked':'' ?> onchange="toggleMod('autre',this.checked)">
            <label class="form-check-label fw-semibold" for="modAutre"><i class="fa fa-pen me-1 text-muted"></i>Autre modification</label>
          </div>
          <div id="blockAutre" class="ps-3 <?= !in_array('autre',$data['types_modification'])?'d-none':'' ?>">
            <div class="row g-2">
              <div class="col-12"><label class="form-label" style="font-size:0.78rem">Intitulé</label>
                <input type="text" name="titre_autre" class="form-control form-control-sm" value="<?= h($data['titre_autre']) ?>" oninput="updatePreview()"></div>
              <div class="col-12"><label class="form-label" style="font-size:0.78rem">Contenu <span class="text-danger">*</span></label>
                <textarea name="contenu_autre" class="form-control form-control-sm" rows="4" oninput="updatePreview()"><?= h($data['contenu_autre']) ?></textarea></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Signature -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-pen-to-square me-2" style="color:var(--ov-gold)"></i>Signature</h2></div>
      <div class="ov-card-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Lieu</label>
            <input type="text" name="lieu_signature" class="form-control form-control-sm" value="<?= h($data['lieu_signature']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Date</label>
            <input type="text" name="date_signature" class="form-control form-control-sm" value="<?= h($data['date_signature']) ?>" oninput="updatePreview()">
          </div>
        </div>
      </div>
    </div>

    <div class="d-grid gap-2">
      <button type="button" onclick="exportPdf()" class="btn btn-ov-primary">
        <i class="fa fa-file-pdf me-2"></i>Générer & Télécharger l'avenant PDF
      </button>
    </div>
  </form>
</div>

<!-- Aperçu -->
<div class="col-lg-7">
  <div class="ov-card" style="position:sticky;top:70px">
    <div class="ov-card-header">
      <h2 class="ov-card-title"><i class="fa fa-eye me-2" style="color:var(--ov-gold)"></i>Aperçu avenant</h2>
      <span style="font-size:0.75rem;color:#9ca3af">Mis à jour en temps réel</span>
    </div>
    <div class="ov-card-body p-0" style="max-height:calc(100vh - 180px);overflow-y:auto">
      <iframe id="avenantPreview" style="width:100%;height:calc(100vh - 200px);border:none" srcdoc=""></iframe>
    </div>
  </div>
</div>

</div><!-- /row -->

<script>
function toggleMod(type, show) {
    document.getElementById('block' + type.charAt(0).toUpperCase() + type.slice(1)).classList.toggle('d-none', !show);
    updatePreview();
}

function getFormData() {
    const form = document.getElementById('avenantForm');
    const fd   = new FormData(form);
    const obj  = {};
    // handle multiple checkboxes
    obj['types_modification'] = [];
    for (let [k,v] of fd.entries()) {
        if (k === 'types_modification[]') { obj['types_modification'].push(v); }
        else { obj[k] = v; }
    }
    return obj;
}

function updatePreview() {
    const d = getFormData();
    fetch('avenant_preview.php?id=<?= $id ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(d)
    }).then(r => r.text()).then(html => {
        document.getElementById('avenantPreview').srcdoc = html;
    });
}

function exportPdf() {
    document.getElementById('exportFlag').value = '1';
    document.getElementById('avenantForm').submit();
}

document.addEventListener('DOMContentLoaded', updatePreview);
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
