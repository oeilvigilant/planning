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
if (!$a) { header('Location: index.php'); exit; }

$params = getAllParams();
$taux   = getTauxHoraires();

// Charger le contrat actif le plus récent depuis la table contrats
$c = [];
try {
    $stC = $db->prepare("SELECT * FROM contrats WHERE agent_id=? AND statut='actif' ORDER BY created_at DESC, id DESC LIMIT 1");
    $stC->execute([$id]);
    $c = $stC->fetch() ?: [];
    // Fallback : même archivé, prendre le plus récent
    if (!$c) {
        $stC2 = $db->prepare("SELECT * FROM contrats WHERE agent_id=? ORDER BY created_at DESC, id DESC LIMIT 1");
        $stC2->execute([$id]);
        $c = $stC2->fetch() ?: [];
    }
} catch (Exception $e) { $c = []; }

$defaults = [
    'civilite'             => 'M.',
    'nom_prenom'           => strtoupper($a['nom']) . ' ' . $a['prenom'],
    'adresse'              => trim(($a['adresse'] ?? '') . ', ' . ($a['cp'] ?? '') . ' ' . ($a['ville'] ?? ''), ', '),
    'date_naissance'       => $a['date_naissance'] ? date('d/m/Y', strtotime($a['date_naissance'])) : '',
    'lieu_naissance'       => $a['lieu_naissance'] ?? '',
    'nationalite'          => $a['nationalite'] ?? '',
    'num_secu'             => $a['num_secu'] ?? '',
    'num_cnaps'            => $a['num_autorisation_cnaps'] ?? '',
    'type_contrat'         => $c['type_contrat'] ?? ($a['type_contrat'] ?? 'CDD'),
    'poste'                => $c['poste'] ?? ($a['poste'] ?? 'Agent de sécurité'),
    'categorie'            => ($c['categorie'] ?: '') ?: 'Employé - Niveau III - Échelon 2 - Coefficient 140',
    'date_debut'           => $c['date_debut'] ? date('d/m/Y', strtotime($c['date_debut'])) : ($a['date_debut_contrat'] ? date('d/m/Y', strtotime($a['date_debut_contrat'])) : ''),
    'date_fin'             => $c['date_fin']   ? date('d/m/Y', strtotime($c['date_fin']))   : ($a['date_fin_contrat']   ? date('d/m/Y', strtotime($a['date_fin_contrat']))   : ''),
    'motif_cdd'            => $c['motif_embauche'] ?: ($a['motif_embauche'] === 'Accroissement activité'
                              ? "accroissement temporaire d'activité"
                              : ($a['motif_embauche'] ?? "accroissement temporaire d'activité")),
    'description_motif'    => ($c['description_motif'] ?: '') ?: "lié à une demande urgente et imprévisible (Article L1242-2-2° du Code du travail).",
    'periode_essai'        => '',
    'total_heures_contrat' => $c['total_heures_contrat'] ? (string)$c['total_heures_contrat'] : ($a['total_heures_contrat'] ? (string)$a['total_heures_contrat'] : ''),
    'horaires_vacation'    => $c['horaires_vacation'] ?? '',
    'nom_evenement'        => $c['nom_evenement'] ?? '',
    'site_affectation'     => $c['lieu_travail'] ?? ($a['lieu_travail'] ?? ''),
    'salaire_horaire'      => $c['remuneration'] ? number_format((float)$c['remuneration'], 2, '.', '') : ($a['remuneration'] ? number_format((float)$a['remuneration'], 2, '.', '') : '12.70'),
    'type_remuneration'    => $c['type_remuneration'] ?? ($a['type_remuneration'] ?? 'Brute'),
    'majoration_nuit'      => ($c['majoration_nuit']  ?: '') ?: '10',
    'majoration_dim'       => ($c['majoration_dim']   ?: '') ?: '10',
    'majoration_ferie'     => ($c['majoration_ferie'] ?: '') ?: '100',
    'date_signature'       => ($c['date_signature'] ?: '') ?: ($a['date_signature'] ?? date('d/m/Y')),
    'lieu_signature'       => ($c['lieu_signature']  ?: '') ?: ($a['lieu_signature'] ?? ($params['entreprise_ville'] ?? 'Paris')),
    'non_renouvelable'     => isset($c['non_renouvelable']) ? (string)(int)$c['non_renouvelable'] : '1',
    'inclure_annexe_24h'   => isset($c['inclure_annexe_24h']) ? (string)(int)$c['inclure_annexe_24h'] : (string)($a['inclure_annexe_24h'] ?? '1'),
    'mutuelle_choix'       => $c['mutuelle_choix'] ?? ($a['mutuelle_choix'] ?? 'dispense'),
];

$defaults['periode_essai'] = calculerPeriodeEssai($defaults['date_debut'], $defaults['date_fin']);

// Auto-détecter dates depuis le planning si absentes
if (!$defaults['date_debut'] || !$defaults['date_fin']) {
    $stP = $db->prepare("SELECT MIN(pl.date_travail) AS min_date, MAX(pl.date_travail) AS max_date
        FROM planning_lignes pl JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
        WHERE pl.agent_id = ?");
    $stP->execute([$id]);
    $pr = $stP->fetch();
    if ($pr && $pr['min_date']) {
        if (!$defaults['date_debut']) $defaults['date_debut'] = date('d/m/Y', strtotime($pr['min_date']));
        if (!$defaults['date_fin'])   $defaults['date_fin']   = date('d/m/Y', strtotime($pr['max_date']));
        $defaults['periode_essai'] = calculerPeriodeEssai($defaults['date_debut'], $defaults['date_fin']);
    }
}

// Pré-calculer heures contrat
if (empty($defaults['total_heures_contrat']) && $defaults['date_debut'] && $defaults['date_fin']) {
    $dD = DateTime::createFromFormat('d/m/Y', $defaults['date_debut']);
    $dF = DateTime::createFromFormat('d/m/Y', $defaults['date_fin']);
    if ($dD && $dF && $dF >= $dD) {
        $stH = $db->prepare("SELECT COALESCE(SUM(pl.min_normal + pl.min_nuit + pl.min_dimanche
                                + pl.min_ferie_normal + pl.min_ferie_dimanche + pl.min_ferie_nuit), 0) AS total_min
            FROM planning_lignes pl JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
            WHERE pl.agent_id = ? AND pl.date_travail BETWEEN ? AND ?");
        $stH->execute([$id, $dD->format('Y-m-d'), $dF->format('Y-m-d')]);
        $totalMin = (int)($stH->fetch()['total_min'] ?? 0);
        if ($totalMin > 0) $defaults['total_heures_contrat'] = round($totalMin / 60, 2);
    }
}

// Nom de base pour les fichiers
$nomBase = strtoupper(preg_replace('/[^A-Za-z0-9]/', '_', $a['nom']))
         . '_' . preg_replace('/[^A-Za-z0-9]/', '_', $a['prenom']);

// Passer la signature du contrat actif au builder
$aForPdf = $a;
if (!empty($c['signature'])) {
    $aForPdf['signature']      = $c['signature'];
    $aForPdf['signature_date'] = $c['signature_date'] ?? $a['signature_date'];
}

// ── 1. Contrat PDF ────────────────────────────────────────────────────────────
$contratPdf = renderPdfToString(buildContratHtml($defaults, $params, $aForPdf));

// ── 2. Fiche agent comptable PDF (même logique qu'export_pdf.php) ─────────────
$pdfChamps = $db->query("SELECT * FROM pdf_champs WHERE actif=1 ORDER BY ordre")->fetchAll();

$agentData = [
    'nom'                    => $a['nom'],
    'prenom'                 => $a['prenom'],
    'date_naissance'         => $a['date_naissance'] ? date('d/m/Y', strtotime($a['date_naissance'])) : '',
    'lieu_naissance'         => $a['lieu_naissance'] ?? '',
    'nationalite'            => $a['nationalite'] ?? '',
    'num_secu'               => $a['num_secu'] ?? '',
    'adresse'                => $a['adresse'] ?? '',
    'cp'                     => $a['cp'] ?? '',
    'ville'                  => $a['ville'] ?? '',
    'situation_familiale'    => $a['situation_familiale'] ?? '',
    'nb_enfants'             => (string)($a['nb_enfants'] ?? 0),
    'type_contrat'           => $a['type_contrat'] ?? '',
    'poste'                  => $a['poste'] ?? '',
    'statut'                 => $a['statut'] ?? '',
    'date_debut_contrat'     => $a['date_debut_contrat'] ? date('d/m/Y', strtotime($a['date_debut_contrat'])) : '',
    'date_fin_contrat'       => $a['date_fin_contrat']   ? date('d/m/Y', strtotime($a['date_fin_contrat']))   : '',
    'lieu_travail'           => $a['lieu_travail'] ?? '',
    'remuneration'           => $a['remuneration'] ? number_format((float)$a['remuneration'], 2) . ' €' : '',
    'type_remuneration'      => $a['type_remuneration'] ?? '',
    'num_autorisation_cnaps' => $a['num_autorisation_cnaps'] ?? '',
    'date_expiration_cnaps'  => $a['date_expiration_cnaps'] ? date('d/m/Y', strtotime($a['date_expiration_cnaps'])) : '',
    'dpae'                   => $a['dpae'] ? 'Oui' : 'Non',
    'contrat_realise'        => $a['contrat_realise'] ? 'Oui' : 'Non',
];

$logoB64 = '';
$logoFile = APP_ROOT . '/assets/img/' . ($params['logo_principal'] ?? 'logo.png');
if (file_exists($logoFile)) {
    $ext  = strtolower(pathinfo($logoFile, PATHINFO_EXTENSION));
    $mime = $ext === 'svg' ? 'image/svg+xml' : (($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png');
    $logoB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
}

$photoB64 = '';
if (!empty($a['photo'])) {
    $photoFile = UPLOAD_PATH . '/' . $a['photo'];
    if (file_exists($photoFile)) {
        $ext  = strtolower(pathinfo($photoFile, PATHINFO_EXTENSION));
        $mime = ($ext === 'jpg' || $ext === 'jpeg') ? 'image/jpeg' : 'image/png';
        $photoB64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($photoFile));
    }
}

$sections = [
    'Identité'           => ['nom','prenom','date_naissance','lieu_naissance','nationalite','num_secu','situation_familiale','nb_enfants'],
    'Coordonnées'        => ['adresse','cp','ville'],
    'Contrat'            => ['type_contrat','poste','statut','date_debut_contrat','date_fin_contrat','lieu_travail','remuneration','type_remuneration'],
    'Autorisation CNAPS' => ['num_autorisation_cnaps','date_expiration_cnaps'],
    'Pôle Social'        => ['dpae','contrat_realise'],
];
$champsActifs = array_column($pdfChamps, 'cle');

ob_start();
?><!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><?= pdfBaseStyle() ?></head><body>
<div class="page">
  <div class="pdf-header">
    <div>
      <?php if ($logoB64): ?><img src="<?= $logoB64 ?>" style="height:38px;margin-bottom:6px"><br><?php endif; ?>
      <h1><?= htmlspecialchars($params['entreprise_nom'] ?? 'Oeil Vigilant') ?></h1>
      <p><?= htmlspecialchars($params['entreprise_adresse'] ?? '') ?>, <?= htmlspecialchars($params['entreprise_cp'] ?? '') ?> <?= htmlspecialchars($params['entreprise_ville'] ?? '') ?></p>
      <p>SIRET : <?= htmlspecialchars($params['entreprise_siret'] ?? '') ?></p>
      <?php if (!empty($params['entreprise_tel'])): ?><p>Tél : <?= htmlspecialchars($params['entreprise_tel']) ?></p><?php endif; ?>
    </div>
    <div style="text-align:center">
      <?php if ($photoB64): ?>
      <img src="<?= $photoB64 ?>" style="width:70px;height:80px;object-fit:cover;border:2px solid #c9a84c;border-radius:4px">
      <?php else: ?>
      <div style="width:70px;height:80px;background:#f0f2f5;border:2px solid #c9a84c;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:22pt;color:#c9a84c;font-weight:700"><?= strtoupper(substr($a['prenom'],0,1).substr($a['nom'],0,1)) ?></div>
      <?php endif; ?>
      <div style="margin-top:5px;font-size:7pt;color:#999">Matricule</div>
      <div style="font-weight:700;font-size:9pt"><?= htmlspecialchars($a['matricule'] ?? '—') ?></div>
    </div>
  </div>
  <div class="pdf-title">FICHE DE RENSEIGNEMENTS SALARIÉ</div>
  <?php foreach ($sections as $sectionNom => $cles):
    $champsSection = array_filter($pdfChamps, fn($c) => in_array($c['cle'], $cles) && in_array($c['cle'], $champsActifs));
    if (empty($champsSection)) continue; ?>
  <div class="section-title"><?= htmlspecialchars($sectionNom) ?></div>
  <div class="grid-2">
    <?php foreach ($champsSection as $champ):
      $val = $agentData[$champ['cle']] ?? ''; if ($val === '') continue; ?>
    <div class="field">
      <div class="field-label"><?= htmlspecialchars($champ['label']) ?></div>
      <div class="field-value"><?= htmlspecialchars($val) ?></div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <div class="pdf-footer">
    <span>Généré le <?= date('d/m/Y à H:i') ?></span>
    <span>Document confidentiel — <?= htmlspecialchars($params['entreprise_nom'] ?? '') ?></span>
  </div>
</div></body></html>
<?php
$ficheHtml   = ob_get_clean();
$fichePdf    = renderPdfToString($ficheHtml);

// ── 3. Documents joints ───────────────────────────────────────────────────────
$docStmt = $db->prepare("SELECT * FROM agent_documents WHERE agent_id = ? ORDER BY type_document");
$docStmt->execute([$id]);
$documents = $docStmt->fetchAll();

// ── 4. Créer le ZIP ───────────────────────────────────────────────────────────
$zipTmp = tempnam(sys_get_temp_dir(), 'dossier_');
$zip = new ZipArchive();
if ($zip->open($zipTmp, ZipArchive::OVERWRITE) !== true) {
    flash('danger', 'Impossible de créer l\'archive ZIP.');
    header('Location: view.php?id=' . $id);
    exit;
}

$zip->addFromString('Contrat_'         . $nomBase . '.pdf', $contratPdf);
$zip->addFromString('Fiche_comptable_' . $nomBase . '.pdf', $fichePdf);

// DPAE du contrat actif
if (!empty($c['dpae_chemin'])) {
    $dpaePath = str_replace(['\\','/'], DIRECTORY_SEPARATOR, UPLOAD_PATH.'/'.$c['dpae_chemin']);
    if (file_exists($dpaePath)) {
        $ext = strtolower(pathinfo($c['dpae_chemin'], PATHINFO_EXTENSION));
        $zip->addFromString('DPAE_'.$nomBase.'.'.$ext, file_get_contents($dpaePath));
    }
}

$docsLabels = [
    'piece_identite'       => 'Piece_identite',
    'titre_sejour'         => 'Titre_sejour',
    'carte_vitale'         => 'Carte_vitale',
    'attestation_domicile' => 'Attestation_domicile',
    'attestation_cnaps'    => 'Attestation_CNAPS',
    'rib'                  => 'RIB',
    'contrat'              => 'Contrat_signe',
];

$zipNames = []; // compteur pour éviter les doublons dans le ZIP
foreach ($documents as $doc) {
    $filePath = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, UPLOAD_PATH . '/' . $doc['chemin']);
    if (!file_exists($filePath)) continue;
    $ext = strtolower(pathinfo($doc['chemin'], PATHINFO_EXTENSION));

    if ($doc['type_document'] === 'autre') {
        // Utiliser le libellé saisi par l'agent comme nom de fichier
        $parts = explode(' — ', $doc['nom_fichier'], 2);
        $label = preg_replace('/[^A-Za-z0-9_-]/', '_', $parts[0] ?: 'Document');
    } else {
        $label = $docsLabels[$doc['type_document']] ?? preg_replace('/[^A-Za-z0-9_]/', '_', $doc['type_document']);
    }

    // Déduplication : ajouter un suffixe si le nom existe déjà
    $zipName = $label . '_' . $nomBase . '.' . $ext;
    if (isset($zipNames[$zipName])) {
        $zipNames[$zipName]++;
        $zipName = $label . '_' . $nomBase . '_' . $zipNames[$zipName] . '.' . $ext;
    } else {
        $zipNames[$zipName] = 1;
    }

    $zip->addFromString($zipName, file_get_contents($filePath));
}

$zip->close();

// Envoyer le ZIP
$zipName = 'Dossier_' . $nomBase . '.zip';
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipName . '"');
header('Content-Length: ' . filesize($zipTmp));
header('Cache-Control: no-cache');
readfile($zipTmp);
unlink($zipTmp);
exit;
