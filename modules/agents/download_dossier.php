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

$defaults = [
    'civilite'             => 'M.',
    'nom_prenom'           => strtoupper($a['nom']) . ' ' . $a['prenom'],
    'adresse'              => trim(($a['adresse'] ?? '') . ', ' . ($a['cp'] ?? '') . ' ' . ($a['ville'] ?? ''), ', '),
    'date_naissance'       => $a['date_naissance'] ? date('d/m/Y', strtotime($a['date_naissance'])) : '',
    'lieu_naissance'       => $a['lieu_naissance'] ?? '',
    'nationalite'          => $a['nationalite'] ?? '',
    'num_secu'             => $a['num_secu'] ?? '',
    'num_cnaps'            => $a['num_autorisation_cnaps'] ?? '',
    'type_contrat'         => $a['type_contrat'] ?? 'CDD',
    'poste'                => $a['poste'] ?? 'Agent de sécurité',
    'categorie'            => 'Employé - Niveau III - Échelon 2 - Coefficient 140',
    'date_debut'           => $a['date_debut_contrat'] ? date('d/m/Y', strtotime($a['date_debut_contrat'])) : '',
    'date_fin'             => $a['date_fin_contrat']   ? date('d/m/Y', strtotime($a['date_fin_contrat']))   : '',
    'motif_cdd'            => $a['motif_embauche'] === 'Accroissement activité'
                              ? "accroissement temporaire d'activité"
                              : ($a['motif_embauche'] ?? "accroissement temporaire d'activité"),
    'description_motif'    => "lié à une demande urgente et imprévisible (Article L1242-2-2° du Code du travail).",
    'periode_essai'        => '',
    'total_heures_contrat' => '',
    'site_affectation'     => $a['lieu_travail'] ?? '',
    'salaire_horaire'      => $a['remuneration'] ? number_format((float)$a['remuneration'], 2, '.', '') : '12.70',
    'type_remuneration'    => $a['type_remuneration'] ?? 'Brute',
    'majoration_nuit'      => '10',
    'majoration_dim'       => '10',
    'majoration_ferie'     => '100',
    'date_signature'       => $a['date_signature'] ?? date('d/m/Y'),
    'lieu_signature'       => $a['lieu_signature'] ?? ($params['entreprise_ville'] ?? 'Paris'),
    'non_renouvelable'     => '1',
    'inclure_annexe_24h'   => (string)($a['inclure_annexe_24h'] ?? '1'),
    'mutuelle_choix'       => $a['mutuelle_choix'] ?? 'dispense',
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

// Générer le contrat PDF en mémoire
$pdfBytes = renderPdfToString(buildContratHtml($defaults, $params, $a));

// Documents joints
$docStmt = $db->prepare("SELECT * FROM agent_documents WHERE agent_id = ? ORDER BY type_document");
$docStmt->execute([$id]);
$documents = $docStmt->fetchAll();

// Créer le ZIP
$zipTmp = tempnam(sys_get_temp_dir(), 'dossier_');
$zip = new ZipArchive();
if ($zip->open($zipTmp, ZipArchive::OVERWRITE) !== true) {
    flash('danger', 'Impossible de créer l\'archive ZIP.');
    header('Location: view.php?id=' . $id);
    exit;
}

$zip->addFromString('Contrat_' . $nomBase . '.pdf', $pdfBytes);

$docsLabels = [
    'piece_identite'       => 'Piece_identite',
    'carte_vitale'         => 'Carte_vitale',
    'attestation_domicile' => 'Attestation_domicile',
    'titre_sejour'         => 'Titre_sejour',
    'attestation_cnaps'    => 'Attestation_CNAPS',
    'rib'                  => 'RIB',
    'contrat'              => 'Contrat_signe',
];

foreach ($documents as $doc) {
    $filePath = UPLOAD_PATH . '/' . $doc['chemin'];
    if (!file_exists($filePath)) continue;
    $ext      = strtolower(pathinfo($doc['chemin'], PATHINFO_EXTENSION));
    $label    = $docsLabels[$doc['type_document']] ?? preg_replace('/[^A-Za-z0-9]/', '_', $doc['type_document']);
    $zip->addFile($filePath, $label . '_' . $nomBase . '.' . $ext);
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
