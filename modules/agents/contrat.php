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
$taux   = getTauxHoraires();

$defaults = [
    'civilite'         => 'M.',
    'nom_prenom'       => strtoupper($a['nom']) . ' ' . $a['prenom'],
    'adresse'          => trim(($a['adresse']??'') . ', ' . ($a['cp']??'') . ' ' . ($a['ville']??''), ', '),
    'date_naissance'   => $a['date_naissance'] ? date('d/m/Y', strtotime($a['date_naissance'])) : '',
    'lieu_naissance'   => $a['lieu_naissance'] ?? '',
    'nationalite'      => $a['nationalite'] ?? '',
    'num_secu'         => $a['num_secu'] ?? '',
    'num_cnaps'        => $a['num_autorisation_cnaps'] ?? '',
    'type_contrat'     => $a['type_contrat'] ?? 'CDD',
    'poste'            => $a['poste'] ?? 'Agent de sécurité',
    'categorie'        => 'Employé - Niveau III - Échelon 2 - Coefficient 140',
    'date_debut'       => $a['date_debut_contrat'] ? date('d/m/Y', strtotime($a['date_debut_contrat'])) : '',
    'date_fin'         => $a['date_fin_contrat'] ? date('d/m/Y', strtotime($a['date_fin_contrat'])) : '',
    'motif_cdd'        => $a['motif_embauche'] === 'Accroissement activité'
                          ? "Accroissement temporaire d'activité"
                          : ($a['motif_embauche'] ?? "Accroissement temporaire d'activité"),
    'description_motif'=> "Ce contrat est conclu pour faire face à un accroissement temporaire d'activité lié à une demande urgente et imprévisible.",
    'periode_essai'    => calculerPeriodeEssai(
                              $a['date_debut_contrat'] ? date('d/m/Y', strtotime($a['date_debut_contrat'])) : '',
                              $a['date_fin_contrat']   ? date('d/m/Y', strtotime($a['date_fin_contrat']))   : ''
                          ),
    'total_heures_contrat' => '',
    'site_affectation' => $a['lieu_travail'] ?? '',
    'salaire_horaire'  => $a['remuneration'] ? number_format((float)$a['remuneration'], 2, '.', '') : '12.70',
    'type_remuneration'=> $a['type_remuneration'] ?? 'Brute',
    'majoration_nuit'  => '10',
    'majoration_dim'   => '10',
    'majoration_ferie' => '100',
    'date_signature'   => date('d/m/Y'),
    'lieu_signature'   => $params['entreprise_ville'] ?? 'Paris',
    'non_renouvelable' => '1',
];

$data      = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_merge($defaults, $_POST) : $defaults;
$exportPdf = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_pdf']) && $_POST['export_pdf'] === '1';

// Export PDF — avant tout output HTML
if ($exportPdf) {
    $html = buildContratHtml($data, $params, $a);
    renderPdf($html, 'contrat_' . strtolower(str_replace(' ','_',$a['nom'])) . '_' . strtolower(str_replace(' ','_',$a['prenom'])) . '.pdf');
}

$pageTitle    = 'Édition du contrat';
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour fiche</a>
</div>

<div class="row g-3">

<!-- Formulaire gauche -->
<div class="col-lg-5">
  <form method="POST" id="contratForm">
    <input type="hidden" name="export_pdf" value="0" id="exportFlag">

    <!-- Parties -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-user me-2" style="color:var(--ov-gold)"></i>Le Salarié</h2></div>
      <div class="ov-card-body">
        <div class="row g-2">
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
            <label class="form-label">Adresse complète</label>
            <input type="text" name="adresse" class="form-control form-control-sm" value="<?= h($data['adresse']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Date de naissance</label>
            <input type="text" name="date_naissance" class="form-control form-control-sm" value="<?= h($data['date_naissance']) ?>" placeholder="dd/mm/yyyy" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Lieu de naissance</label>
            <input type="text" name="lieu_naissance" class="form-control form-control-sm" value="<?= h($data['lieu_naissance']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Nationalité</label>
            <input type="text" name="nationalite" class="form-control form-control-sm" value="<?= h($data['nationalite']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">N° Sécurité Sociale</label>
            <input type="text" name="num_secu" class="form-control form-control-sm" value="<?= h($data['num_secu']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-12">
            <label class="form-label">N° Carte professionnelle (CNAPS)</label>
            <input type="text" name="num_cnaps" class="form-control form-control-sm" value="<?= h($data['num_cnaps']) ?>" oninput="updatePreview()">
          </div>
        </div>
      </div>
    </div>

    <!-- Contrat -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-file-contract me-2" style="color:var(--ov-gold)"></i>Contrat</h2></div>
      <div class="ov-card-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Type de contrat</label>
            <select name="type_contrat" class="form-select form-select-sm" onchange="updatePreview()">
              <option value="CDD" <?= $data['type_contrat']==='CDD'?'selected':'' ?>>CDD</option>
              <option value="CDI" <?= $data['type_contrat']==='CDI'?'selected':'' ?>>CDI</option>
              <option value="CDD Usage" <?= $data['type_contrat']==='CDD Usage'?'selected':'' ?>>CDD d'Usage</option>
              <option value="Saisonnier" <?= $data['type_contrat']==='Saisonnier'?'selected':'' ?>>Saisonnier</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Poste</label>
            <input type="text" name="poste" class="form-control form-control-sm" value="<?= h($data['poste']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-12">
            <label class="form-label">Catégorie / Niveau / Coefficient</label>
            <input type="text" name="categorie" class="form-control form-control-sm" value="<?= h($data['categorie']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Date de début</label>
            <input type="text" name="date_debut" id="dateDebut" class="form-control form-control-sm" value="<?= h($data['date_debut']) ?>" placeholder="dd/mm/yyyy" oninput="calcPeriodeEssai(); updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Date de fin <small class="text-muted">(CDD)</small></label>
            <input type="text" name="date_fin" id="dateFin" class="form-control form-control-sm" value="<?= h($data['date_fin']) ?>" placeholder="dd/mm/yyyy" oninput="calcPeriodeEssai(); updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Total heures contrat <small class="text-muted">(calculé planning)</small></label>
            <div class="input-group input-group-sm">
              <input type="number" name="total_heures_contrat" class="form-control" step="0.5" min="0" value="<?= h($data['total_heures_contrat'] ?? '') ?>" placeholder="ex: 36" oninput="updatePreview()">
              <span class="input-group-text">h</span>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label">Période d'essai <small class="text-muted">(auto-calculée)</small></label>
            <input type="text" name="periode_essai" id="periodeEssai" class="form-control form-control-sm" value="<?= h($data['periode_essai']) ?>" oninput="updatePreview()" readonly style="background:#f8f9fa;color:#6b7280">
          </div>
          <div class="col-6">
            <label class="form-label">Motif CDD</label>
            <input type="text" name="motif_cdd" class="form-control form-control-sm" value="<?= h($data['motif_cdd']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-12">
            <label class="form-label">Description du motif</label>
            <textarea name="description_motif" class="form-control form-control-sm" rows="2" oninput="updatePreview()"><?= h($data['description_motif']) ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">Site d'affectation</label>
            <input type="text" name="site_affectation" class="form-control form-control-sm" value="<?= h($data['site_affectation']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-6">
            <label class="form-label">Non renouvelable</label>
            <select name="non_renouvelable" class="form-select form-select-sm" onchange="updatePreview()">
              <option value="1" <?= $data['non_renouvelable']==='1'?'selected':'' ?>>Oui</option>
              <option value="0" <?= $data['non_renouvelable']==='0'?'selected':'' ?>>Non (renouvelable)</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- Rémunération -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-euro-sign me-2" style="color:var(--ov-gold)"></i>Rémunération</h2></div>
      <div class="ov-card-body">
        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Salaire horaire <small class="text-muted">(brut €/h)</small></label>
            <div class="input-group input-group-sm">
              <input type="number" name="salaire_horaire" class="form-control" step="0.01" min="0" value="<?= h($data['salaire_horaire']) ?>" oninput="updatePreview()">
              <span class="input-group-text">€/h</span>
            </div>
          </div>
          <div class="col-6">
            <label class="form-label">Type (brut/net)</label>
            <select name="type_remuneration" class="form-select form-select-sm" onchange="updatePreview()">
              <option value="Brute" <?= $data['type_remuneration']==='Brute'?'selected':'' ?>>Brute</option>
              <option value="Nette" <?= $data['type_remuneration']==='Nette'?'selected':'' ?>>Nette</option>
            </select>
          </div>
          <div class="col-4">
            <label class="form-label">Majoration nuit %</label>
            <input type="number" name="majoration_nuit" class="form-control form-control-sm" value="<?= h($data['majoration_nuit']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-4">
            <label class="form-label">Majoration dim. %</label>
            <input type="number" name="majoration_dim" class="form-control form-control-sm" value="<?= h($data['majoration_dim']) ?>" oninput="updatePreview()">
          </div>
          <div class="col-4">
            <label class="form-label">Majoration férié %</label>
            <input type="number" name="majoration_ferie" class="form-control form-control-sm" value="<?= h($data['majoration_ferie']) ?>" oninput="updatePreview()">
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
            <input type="text" name="date_signature" class="form-control form-control-sm" value="<?= h($data['date_signature']) ?>" placeholder="dd/mm/yyyy" oninput="updatePreview()">
          </div>
        </div>
      </div>
    </div>

    <div class="d-grid gap-2">
      <button type="button" onclick="exportPdf()" class="btn btn-ov-primary">
        <i class="fa fa-file-pdf me-2"></i>Générer & Télécharger le contrat PDF
      </button>
      <button type="button" onclick="window.print()" class="btn btn-ov-secondary">
        <i class="fa fa-print me-2"></i>Imprimer l'aperçu
      </button>
    </div>
  </form>
</div>

<!-- Aperçu droite -->
<div class="col-lg-7">
  <div class="ov-card" style="position:sticky;top:70px">
    <div class="ov-card-header">
      <h2 class="ov-card-title"><i class="fa fa-eye me-2" style="color:var(--ov-gold)"></i>Aperçu contrat</h2>
      <span style="font-size:0.75rem;color:#9ca3af">Mis à jour en temps réel</span>
    </div>
    <div class="ov-card-body p-0" style="max-height:calc(100vh - 180px);overflow-y:auto">
      <iframe id="contratPreview" style="width:100%;height:calc(100vh - 200px);border:none" srcdoc=""></iframe>
    </div>
  </div>
</div>

</div>

<script>
function parseDate(s) {
    if (!s) return null;
    var p = s.split('/');
    if (p.length === 3) return new Date(+p[2], +p[1]-1, +p[0]);
    return null;
}

function calcPeriodeEssai() {
    var debut = document.getElementById('dateDebut').value;
    var fin   = document.getElementById('dateFin').value;
    var el    = document.getElementById('periodeEssai');
    if (!debut || !fin) { el.value = '0 jour'; return; }
    var d1 = parseDate(debut), d2 = parseDate(fin);
    if (!d1 || !d2 || d2 <= d1) { el.value = '0 jour'; return; }
    var diffDays  = Math.round((d2 - d1) / 86400000);
    var nbSem     = Math.floor(diffDays / 7);
    if (nbSem === 0) { el.value = '0 jour'; }
    else if (nbSem === 1) { el.value = '1 jour travaillé'; }
    else { el.value = nbSem + ' jours travaillés'; }
}

function getFormData() {
    const form = document.getElementById('contratForm');
    const fd = new FormData(form);
    const obj = {};
    for (let [k,v] of fd.entries()) obj[k] = v;
    return obj;
}

function updatePreview() {
    const d = getFormData();
    fetch('contrat_preview.php?id=<?= $id ?>', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(d)
    }).then(r => r.text()).then(html => {
        document.getElementById('contratPreview').srcdoc = html;
    });
}

function exportPdf() {
    document.getElementById('exportFlag').value = '1';
    document.getElementById('contratForm').submit();
}

document.addEventListener('DOMContentLoaded', updatePreview);
</script>

<style>
@media print {
    #sidebar, #topbar, .col-lg-5, .ov-card-header { display:none!important; }
    #main-content { margin:0; padding:0; }
    iframe { width:100%; height:auto; border:none; }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
