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

// ── AJAX : calcul total heures depuis le planning ─────────────────────────────
if (($_GET['action'] ?? '') === 'get_heures_planning') {
    header('Content-Type: application/json');
    $agentId   = (int)($_GET['agent_id']   ?? $id);
    $dateDebut = $_GET['date_debut'] ?? '';
    $dateFin   = $_GET['date_fin']   ?? '';
    $dD = $dateDebut ? DateTime::createFromFormat('d/m/Y', $dateDebut) : null;
    $dF = $dateFin   ? DateTime::createFromFormat('d/m/Y', $dateFin)   : null;
    $totalH = 0;
    if ($agentId && $dD && $dF && $dF >= $dD) {
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(pl.min_normal + pl.min_nuit + pl.min_dimanche
                              + pl.min_ferie_normal + pl.min_ferie_dimanche + pl.min_ferie_nuit), 0) AS total_min
            FROM planning_lignes pl
            JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
            WHERE pl.agent_id = ? AND pl.date_travail BETWEEN ? AND ?
        ");
        $stmt->execute([$agentId, $dD->format('Y-m-d'), $dF->format('Y-m-d')]);
        $totalMin = (int)($stmt->fetch()['total_min'] ?? 0);
        $totalH   = $totalMin > 0 ? round($totalMin / 60, 2) : 0;
    }
    echo json_encode(['ok' => true, 'total_heures' => $totalH]);
    exit;
}

// ── Init BDD signature (auto-migration) ──────────────────────────────────────
try {
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS signature LONGTEXT NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS signature_date DATETIME NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS signature_ip VARCHAR(45) NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS lieu_signature VARCHAR(100) NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS date_signature VARCHAR(10) NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS inclure_annexe_24h TINYINT(1) NOT NULL DEFAULT 1");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS mutuelle_choix VARCHAR(20) NOT NULL DEFAULT 'dispense'");
    $db->exec("CREATE TABLE IF NOT EXISTS signatures_log (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        agent_id  INT NOT NULL,
        contrat_hash VARCHAR(64) NOT NULL,
        ip_address   VARCHAR(45),
        user_agent   TEXT,
        signed_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_agent (agent_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS signature_tokens (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        agent_id     INT NOT NULL,
        token        VARCHAR(64) NOT NULL UNIQUE,
        email        VARCHAR(255) NOT NULL,
        contrat_data LONGTEXT NULL,
        sent_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at   DATETIME NOT NULL,
        signed_at    DATETIME NULL,
        ip_signed    VARCHAR(45) NULL,
        ua_signed    TEXT NULL,
        INDEX idx_token (token),
        INDEX idx_agent_sig (agent_id)
    )");
} catch (Exception $e) {}

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
                          ? "accroissement temporaire d'activité"
                          : ($a['motif_embauche'] ?? "accroissement temporaire d'activité"),
    'description_motif'=> "lié à une demande urgente et imprévisible (Article L1242-2-2° du Code du travail).",
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
    'date_signature'     => $a['date_signature'] ?? date('d/m/Y'),
    'lieu_signature'     => $a['lieu_signature'] ?? ($params['entreprise_ville'] ?? 'Paris'),
    'non_renouvelable'   => '1',
    'inclure_annexe_24h' => (string)($a['inclure_annexe_24h'] ?? '1'),
    'mutuelle_choix'     => $a['mutuelle_choix'] ?? 'dispense',
];

// ── Signature électronique : enregistrement ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_signature'])) {
    $sigData = $_POST['signature_data'] ?? '';
    if (preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $sigData)) {
        $ip      = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua      = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $db->prepare("UPDATE agents SET signature=?, signature_date=NOW(), signature_ip=? WHERE id=?")
           ->execute([$sigData, $ip, $id]);
        $db->prepare("INSERT INTO signatures_log (agent_id, contrat_hash, ip_address, user_agent) VALUES (?,?,?,?)")
           ->execute([$id, hash('sha256', $sigData . $id . date('Y-m-d')), $ip, $ua]);
        // Rafraîchir $a pour le reste de la page
        $a = $db->prepare("SELECT * FROM agents WHERE id=?")->execute([$id]) ? null : null;
        $stmt2 = $db->prepare("SELECT * FROM agents WHERE id=?"); $stmt2->execute([$id]); $a = $stmt2->fetch();
        flash('success', 'Signature enregistrée — horodatage conservé.');
    } else {
        flash('danger', 'Données de signature invalides.');
    }
    header('Location: contrat.php?id=' . $id); exit;
}

// ── Signature électronique : suppression ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_signature'])) {
    $db->prepare("UPDATE agents SET signature=NULL, signature_date=NULL, signature_ip=NULL WHERE id=?")->execute([$id]);
    flash('success', 'Signature supprimée.');
    header('Location: contrat.php?id=' . $id); exit;
}

// ── Envoyer un lien de signature par email ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['send_for_signature'])) {
    require_once __DIR__ . '/../../includes/mailer.php';
    $emailDest = trim($_POST['sig_email'] ?? $a['email'] ?? '');
    if (!filter_var($emailDest, FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'Adresse email invalide.');
    } else {
        $token     = bin2hex(random_bytes(32));
        $nbJours   = max(1, min(30, (int)($_POST['sig_expiry_jours'] ?? 7)));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $nbJours . ' days'));
        $snap      = json_decode($_POST['contrat_snapshot'] ?? '{}', true);
        $snapData  = (is_array($snap) && !empty($snap)) ? array_merge($defaults, $snap) : $defaults;
        unset($snapData['export_pdf'], $snapData['save_contrat'], $snapData['sign_submit']);
        $dataSnap  = json_encode($snapData, JSON_UNESCAPED_UNICODE);
        $db->prepare("INSERT INTO signature_tokens (agent_id, token, email, contrat_data, expires_at) VALUES (?,?,?,?,?)")
           ->execute([$id, $token, $emailDest, $dataSnap, $expiresAt]);
        $sigLink   = rtrim(APP_URL, '/') . '/token/signer.php?t=' . $token;
        $expiryFmt = date('d/m/Y à H:i', strtotime($expiresAt));
        $linkHtml  = '<div class="mt-2 p-2" style="background:#f8f9fa;border-radius:4px;word-break:break-all;font-size:12px"><strong>Lien à copier :</strong><br><a href="' . htmlspecialchars($sigLink) . '" target="_blank">' . htmlspecialchars($sigLink) . '</a></div>';

        if (empty($params['smtp_host'])) {
            // No SMTP configured — show link directly, no attempt to send
            flash('warning', '<i class="fa fa-exclamation-triangle me-1"></i>SMTP non configuré — copiez ce lien et transmettez-le à l\'agent. <a href="' . APP_URL . '/modules/parametres/index.php?tab=email" class="alert-link">Configurer le SMTP</a>' . $linkHtml);
        } else {
            $result = sendMail(
                $emailDest,
                trim($a['prenom'] . ' ' . strtoupper($a['nom'])),
                'Signature de votre contrat — ' . ($params['entreprise_nom'] ?? 'Oeil Vigilant'),
                buildSignatureEmailHtml($a, $params, $sigLink, $expiryFmt)
            );
            if ($result['ok']) {
                flash('success', '<i class="fa fa-check-circle me-1"></i>Email envoyé à <strong>' . htmlspecialchars($emailDest) . '</strong>. Lien valide jusqu\'au ' . $expiryFmt . '.' . $linkHtml);
            } else {
                flash('danger', '<i class="fa fa-times-circle me-1"></i>Envoi impossible : ' . htmlspecialchars($result['error'] ?? 'erreur inconnue') . $linkHtml);
            }
        }
    }
    header('Location: contrat.php?id=' . $id); exit;
}

// ── Regénérer un lien de signature (sans envoyer par email) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['regenerate_token'])) {
    // Auto-détecter les dates depuis le planning si absentes
    if (!$defaults['date_debut'] || !$defaults['date_fin']) {
        $stR = $db->prepare("SELECT MIN(pl.date_travail) AS min_date, MAX(pl.date_travail) AS max_date
            FROM planning_lignes pl JOIN planning_versions pv ON pv.id=pl.version_id AND pv.is_current=1
            WHERE pl.agent_id=?");
        $stR->execute([$id]);
        $pr = $stR->fetch();
        if ($pr && $pr['min_date']) {
            if (!$defaults['date_debut']) $defaults['date_debut'] = date('d/m/Y', strtotime($pr['min_date']));
            if (!$defaults['date_fin'])   $defaults['date_fin']   = date('d/m/Y', strtotime($pr['max_date']));
            $defaults['periode_essai'] = calculerPeriodeEssai($defaults['date_debut'], $defaults['date_fin']);
        }
    }
    $nbJours   = max(1, min(30, (int)($_POST['regen_expiry_jours'] ?? 7)));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $nbJours . ' days'));
    $token     = bin2hex(random_bytes(32));
    $snap      = json_decode($_POST['contrat_snapshot'] ?? '{}', true);
    $snapData  = (is_array($snap) && !empty($snap)) ? array_merge($defaults, $snap) : $defaults;
    unset($snapData['export_pdf'], $snapData['save_contrat'], $snapData['sign_submit']);
    $dataSnap  = json_encode($snapData, JSON_UNESCAPED_UNICODE);
    $db->prepare("INSERT INTO signature_tokens (agent_id, token, email, contrat_data, expires_at) VALUES (?,?,?,?,?)")
       ->execute([$id, $token, $a['email'] ?? '', $dataSnap, $expiresAt]);
    $sigLink   = rtrim(APP_URL, '/') . '/token/signer.php?t=' . $token;
    $expiryFmt = date('d/m/Y à H:i', strtotime($expiresAt));
    $linkHtml  = '<div class="mt-2 p-2" style="background:#f8f9fa;border-radius:4px;word-break:break-all;font-size:12px"><strong>Lien à copier :</strong><br><a href="' . htmlspecialchars($sigLink) . '" target="_blank">' . htmlspecialchars($sigLink) . '</a></div>';
    flash('success', '<i class="fa fa-link me-1"></i>Nouveau lien généré — données du contrat à jour. Valide jusqu\'au ' . $expiryFmt . '.' . $linkHtml);
    header('Location: contrat.php?id=' . $id); exit;
}

// ── Sauvegarder les données du contrat ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_contrat'])) {
    $dateDebut = null;
    $dateFin   = null;
    if (!empty($_POST['date_debut'])) {
        $d = DateTime::createFromFormat('d/m/Y', trim($_POST['date_debut']));
        if ($d) $dateDebut = $d->format('Y-m-d');
    }
    if (!empty($_POST['date_fin'])) {
        $d = DateTime::createFromFormat('d/m/Y', trim($_POST['date_fin']));
        if ($d) $dateFin = $d->format('Y-m-d');
    }
    $stmt = $db->prepare("
        UPDATE agents SET
            type_contrat       = ?,
            date_debut_contrat = ?,
            date_fin_contrat   = ?,
            remuneration       = ?,
            type_remuneration  = ?,
            periode_essai      = ?,
            lieu_travail       = ?,
            poste              = ?,
            lieu_signature     = ?,
            date_signature     = ?,
            inclure_annexe_24h = ?,
            mutuelle_choix     = ?
        WHERE id = ?
    ");
    $stmt->execute([
        $_POST['type_contrat']        ?? null,
        $dateDebut,
        $dateFin,
        $_POST['salaire_horaire']     ?? null,
        $_POST['type_remuneration']   ?? 'Brute',
        $_POST['periode_essai']       ?? null,
        $_POST['site_affectation']    ?? null,
        $_POST['poste']               ?? null,
        trim($_POST['lieu_signature'] ?? ''),
        trim($_POST['date_signature'] ?? ''),
        isset($_POST['inclure_annexe_24h']) ? 1 : 0,
        $_POST['mutuelle_choix']      ?? 'dispense',
        $id,
    ]);
    flash('success', 'Données du contrat sauvegardées dans la fiche agent.');
    header('Location: contrat.php?id=' . $id);
    exit;
}

// ── Auto-détecter dates depuis le planning si absentes de la fiche agent ──────
if (!$defaults['date_debut'] || !$defaults['date_fin']) {
    $stmt = $db->prepare("
        SELECT MIN(pl.date_travail) AS min_date, MAX(pl.date_travail) AS max_date
        FROM planning_lignes pl
        JOIN planning_versions pv ON pv.id = pl.version_id AND pv.is_current = 1
        WHERE pl.agent_id = ?
    ");
    $stmt->execute([$id]);
    $planRow = $stmt->fetch();
    if ($planRow && $planRow['min_date']) {
        if (!$defaults['date_debut']) $defaults['date_debut'] = date('d/m/Y', strtotime($planRow['min_date']));
        if (!$defaults['date_fin'])   $defaults['date_fin']   = date('d/m/Y', strtotime($planRow['max_date']));
        // Recalculer la période d'essai avec les dates détectées
        $defaults['periode_essai'] = calculerPeriodeEssai($defaults['date_debut'], $defaults['date_fin']);
    }
}


$data      = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_merge($defaults, $_POST) : $defaults;
$exportPdf = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_pdf']) && $_POST['export_pdf'] === '1';

// Export PDF — avant tout output HTML
if ($exportPdf) {
    $html = buildContratHtml($data, $params, $a);
    renderPdf($html, 'contrat_' . strtolower(str_replace(' ','_',$a['nom'])) . '_' . strtolower(str_replace(' ','_',$a['prenom'])) . '.pdf');
}

// Téléchargement direct depuis la fiche agent (?dl=1)
if (($_GET['dl'] ?? '') === '1') {
    $html = buildContratHtml($defaults, $params, $a);
    renderPdf($html, 'Contrat_' . strtoupper(str_replace(' ', '_', $a['nom'])) . '_' . str_replace(' ', '_', $a['prenom']) . '.pdf');
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
    <input type="hidden" name="export_pdf"  value="0" id="exportFlag">
    <input type="hidden" name="save_contrat" value="0" id="saveFlag">

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
            <input type="text" name="date_debut" id="dateDebut" class="form-control form-control-sm" value="<?= h($data['date_debut']) ?>" placeholder="dd/mm/yyyy" oninput="calcPeriodeEssai(); updatePreview(); check24hCoherence()">
          </div>
          <div class="col-6">
            <label class="form-label">Date de fin <small class="text-muted">(CDD)</small></label>
            <input type="text" name="date_fin" id="dateFin" class="form-control form-control-sm" value="<?= h($data['date_fin']) ?>" placeholder="dd/mm/yyyy" oninput="calcPeriodeEssai(); updatePreview(); check24hCoherence()">
          </div>
          <div class="col-6">
            <label class="form-label">Total heures contrat</label>
            <div class="input-group input-group-sm">
              <input type="number" name="total_heures_contrat" id="totalHeuresContrat" class="form-control" step="0.5" min="0" value="<?= h($data['total_heures_contrat'] ?? '') ?>" placeholder="ex: 36" oninput="updatePreview(); check24hCoherence()">
              <span class="input-group-text">h</span>
              <button type="button" class="btn btn-outline-secondary" id="btnCalcHeures" onclick="calcHeuresPlanning()" title="Calculer depuis le planning">
                <i class="fa fa-rotate" id="calcHeuresIcon"></i>
              </button>
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

    <!-- Options annexes -->
    <div class="ov-card mb-3">
      <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-paperclip me-2" style="color:var(--ov-gold)"></i>Options annexes</h2></div>
      <div class="ov-card-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Annexe 1 — Dérogation &lt;24h/semaine</label>
            <select name="inclure_annexe_24h" class="form-select form-select-sm" onchange="updatePreview(); check24hCoherence()">
              <option value="1" <?= ($data['inclure_annexe_24h'] ?? '1') === '1' ? 'selected' : '' ?>>Inclure (contrat &lt;24h/semaine)</option>
              <option value="0" <?= ($data['inclure_annexe_24h'] ?? '1') === '0' ? 'selected' : '' ?>>Ne pas inclure (&gt;= 24h/semaine)</option>
            </select>
            <div id="alert24h" class="mt-1"></div>
          </div>
          <div class="col-12">
            <label class="form-label">Annexe 3 — Mutuelle</label>
            <select name="mutuelle_choix" class="form-select form-select-sm" onchange="updatePreview()">
              <option value="dispense" <?= ($data['mutuelle_choix'] ?? 'dispense') === 'dispense' ? 'selected' : '' ?>>Demande de dispense d'affiliation</option>
              <option value="adhesion" <?= ($data['mutuelle_choix'] ?? 'dispense') === 'adhesion' ? 'selected' : '' ?>>Adhésion à la mutuelle d'entreprise</option>
            </select>
          </div>
        </div>
      </div>
    </div>

    <div class="d-grid gap-2">
      <button type="button" onclick="saveContrat()" class="btn" style="background:rgba(16,185,129,0.1);color:#065f46;border:1px solid rgba(16,185,129,0.4);font-weight:500">
        <i class="fa fa-floppy-disk me-2"></i>Sauvegarder dans la fiche agent
      </button>
      <button type="button" onclick="exportPdf()" class="btn btn-ov-primary">
        <i class="fa fa-file-pdf me-2"></i>Générer &amp; Télécharger le contrat PDF
      </button>
      <?php if (!empty($a['signature'])): ?>
      <button type="button" onclick="exportPdf()" class="btn btn-success">
        <i class="fa fa-file-pdf me-2"></i>Télécharger le contrat <strong>signé</strong>
        <span class="badge bg-white text-success ms-1" style="font-size:0.7rem">✓ Signé le <?= date('d/m/Y', strtotime($a['signature_date'])) ?></span>
      </button>
      <?php endif; ?>
      <button type="button" onclick="document.getElementById('contratPreview').contentWindow.print()" class="btn btn-ov-secondary">
        <i class="fa fa-print me-2"></i>Imprimer l'aperçu
      </button>
    </div>
  </form>

  <!-- ── Signature électronique (hors formulaire contrat) ─────────────────── -->
  <div class="ov-card mt-3">
    <div class="ov-card-header">
      <h2 class="ov-card-title"><i class="fa fa-pen-nib me-2" style="color:var(--ov-gold)"></i>Signature électronique du salarié</h2>
    </div>
    <div class="ov-card-body">

      <?php if (!empty($a['signature'])): ?>
      <!-- Signature déjà enregistrée -->
      <div class="d-flex align-items-center gap-3 p-2 mb-3" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px">
        <img src="<?= h($a['signature']) ?>" style="height:54px;max-width:140px;background:#fff;border:1px solid #d1fae5;border-radius:4px;padding:3px;object-fit:contain">
        <div class="small">
          <div class="fw-semibold text-success"><i class="fa fa-check-circle me-1"></i>Signature enregistrée</div>
          <?php if ($a['signature_date']): ?>
          <div class="text-muted"><?= date('d/m/Y à H:i', strtotime($a['signature_date'])) ?></div>
          <div class="text-muted">IP : <?= h($a['signature_ip'] ?? '—') ?></div>
          <?php endif; ?>
        </div>
        <form method="post" class="ms-auto" onsubmit="return confirm('Supprimer définitivement la signature ?')">
          <input type="hidden" name="delete_signature" value="1">
          <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="fa fa-trash"></i></button>
        </form>
      </div>
      <p class="text-muted small mb-2">Pour re-signer, effacez la signature ci-dessus puis tracez et enregistrez la nouvelle.</p>
      <?php else: ?>
      <p class="text-muted small mb-2">Le salarié signe ici (souris ou écran tactile). La signature est horodatée et liée à l'identifiant du contrat.</p>
      <?php endif; ?>

      <div style="border:2px dashed #d1d5db;border-radius:6px;background:#fafafa;position:relative;user-select:none">
        <canvas id="sigCanvas" width="600" height="150" style="width:100%;height:150px;display:block;touch-action:none;cursor:crosshair;border-radius:4px"></canvas>
        <span id="sigPlaceholder" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#d1d5db;font-size:14px;pointer-events:none;white-space:nowrap">✍ Signez ici</span>
      </div>

      <div class="d-flex gap-2 mt-2 align-items-center">
        <button type="button" onclick="clearSig()" class="btn btn-sm btn-outline-secondary">
          <i class="fa fa-eraser me-1"></i>Effacer
        </button>
        <button type="button" onclick="saveSig()" class="btn btn-sm btn-ov-primary ms-auto">
          <i class="fa fa-check me-1"></i>Enregistrer la signature
        </button>
      </div>

      <form method="post" id="sigForm">
        <input type="hidden" name="save_signature" value="1">
        <input type="hidden" name="signature_data" id="sigData">
      </form>

      <p class="text-muted mt-2 mb-0" style="font-size:10px">
        <i class="fa fa-shield-halved me-1 text-success"></i>
        Signature électronique simple — règlement eIDAS (UE 910/2014). Horodatage + IP conservés à des fins probatoires.
      </p>

      <hr class="my-3">

      <!-- Envoi par email -->
      <h6 class="mb-2"><i class="fa fa-envelope me-2 text-warning"></i>Envoyer pour signature par email</h6>
      <?php
        // Récupérer le dernier token envoyé pour cet agent
        $lastToken = $db->prepare("SELECT * FROM signature_tokens WHERE agent_id=? ORDER BY sent_at DESC LIMIT 1");
        $lastToken->execute([$id]);
        $lastTok = $lastToken->fetch();
      ?>
      <?php if ($lastTok): ?>
      <div class="mb-2 p-2 rounded small <?= $lastTok['signed_at'] ? 'bg-success bg-opacity-10 border border-success border-opacity-25' : (strtotime($lastTok['expires_at']) < time() ? 'bg-secondary bg-opacity-10' : 'bg-warning bg-opacity-10 border border-warning border-opacity-25') ?>">
        <?php if ($lastTok['signed_at']): ?>
          <i class="fa fa-check-circle text-success me-1"></i>
          <strong>Signé</strong> le <?= date('d/m/Y à H:i', strtotime($lastTok['signed_at'])) ?> — envoyé à <?= h($lastTok['email'] ?: '(sans email)') ?>
          <?php if (empty($a['signature'])): ?>
          <div class="alert alert-warning py-1 px-2 mt-2 mb-0" style="font-size:0.78rem">
            <i class="fa fa-triangle-exclamation me-1"></i>
            <strong>Signature manquante</strong> — le token est marqué signé mais la signature n'a pas été enregistrée (bug corrigé).
            Regénérez un nouveau lien et demandez à l'agent de re-signer.
          </div>
          <?php endif; ?>
        <?php elseif (strtotime($lastTok['expires_at']) < time()): ?>
          <i class="fa fa-clock text-muted me-1"></i>
          <strong>Lien expiré</strong> — envoyé à <?= h($lastTok['email']) ?> le <?= date('d/m/Y', strtotime($lastTok['sent_at'])) ?>
        <?php else: ?>
          <i class="fa fa-hourglass-half text-warning me-1"></i>
          <strong>En attente</strong> — envoyé à <?= h($lastTok['email']) ?> le <?= date('d/m/Y à H:i', strtotime($lastTok['sent_at'])) ?>,
          expire le <?= date('d/m/Y à H:i', strtotime($lastTok['expires_at'])) ?>
          <button type="button" class="btn btn-xs btn-outline-secondary ms-2 py-0 px-1" style="font-size:10px"
            onclick="navigator.clipboard.writeText('<?= rtrim(APP_URL,'/') ?>/token/signer.php?t=<?= h($lastTok['token']) ?>').then(()=>this.textContent='✓ Copié!')">
            <i class="fa fa-copy"></i> Copier lien
          </button>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <!-- Regénérer le lien -->
      <form method="post" class="mb-2" id="regenForm">
        <input type="hidden" name="regenerate_token" value="1">
        <input type="hidden" name="contrat_snapshot" id="regenSnapshot">
        <div class="row g-2 align-items-end">
          <div class="col-auto">
            <label class="form-label mb-1 small">Validité</label>
            <div class="input-group input-group-sm" style="width:90px">
              <input type="number" name="regen_expiry_jours" class="form-control" value="7" min="1" max="30">
              <span class="input-group-text">j</span>
            </div>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-secondary" style="white-space:nowrap" title="Génère un nouveau lien avec les données actuelles du formulaire">
              <i class="fa fa-rotate me-1"></i>Regénérer le lien
            </button>
          </div>
        </div>
      </form>

      <form method="post" id="sendForm">
        <input type="hidden" name="send_for_signature" value="1">
        <input type="hidden" name="contrat_snapshot" id="sendSnapshot">
        <div class="row g-2 align-items-end">
          <div class="col">
            <label class="form-label mb-1 small">Email du salarié</label>
            <input type="email" name="sig_email" class="form-control form-control-sm"
                   value="<?= h($a['email'] ?? '') ?>" placeholder="prenom.nom@email.com" required>
          </div>
          <div class="col-auto">
            <label class="form-label mb-1 small">Validité</label>
            <div class="input-group input-group-sm" style="width:100px">
              <input type="number" name="sig_expiry_jours" class="form-control" value="7" min="1" max="30">
              <span class="input-group-text">j</span>
            </div>
          </div>
          <div class="col-auto">
            <button type="submit" class="btn btn-sm" style="background:#1a2332;color:#c9a84c;border-color:#1a2332;white-space:nowrap">
              <i class="fa fa-paper-plane me-1"></i>Envoyer par email
            </button>
          </div>
        </div>
      </form>
      <p class="text-muted mt-1 mb-0" style="font-size:10px">Le salarié lit le contrat et signe directement depuis son téléphone ou PC. Validité de 1 à 30 jours.</p>
    </div>
  </div>
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
    var diffDays = Math.round((d2 - d1) / 86400000);
    var nbSem    = Math.floor(diffDays / 7);
    if (nbSem === 0) { el.value = '0 jour'; }
    else if (nbSem === 1) { el.value = '1 jour travaillé'; }
    else { el.value = nbSem + ' jours travaillés'; }
}

function calcHeuresPlanning() {
    var debut = document.getElementById('dateDebut').value;
    var fin   = document.getElementById('dateFin').value;
    if (!debut || !fin) {
        alert('Veuillez renseigner les dates de début et de fin avant de calculer.');
        return;
    }
    var icon = document.getElementById('calcHeuresIcon');
    var btn  = document.getElementById('btnCalcHeures');
    icon.className = 'fa fa-spinner fa-spin';
    btn.disabled = true;

    var url = 'contrat.php?id=<?= $id ?>&action=get_heures_planning&agent_id=<?= $id ?>'
            + '&date_debut=' + encodeURIComponent(debut)
            + '&date_fin='   + encodeURIComponent(fin);
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.ok && data.total_heures > 0) {
                document.getElementById('totalHeuresContrat').value = data.total_heures;
                updatePreview();
                check24hCoherence();
                icon.className = 'fa fa-check text-success';
                setTimeout(function() { icon.className = 'fa fa-rotate'; }, 2000);
            } else {
                icon.className = 'fa fa-triangle-exclamation text-warning';
                setTimeout(function() { icon.className = 'fa fa-rotate'; }, 2000);
            }
            btn.disabled = false;
        })
        .catch(function() {
            icon.className = 'fa fa-rotate';
            btn.disabled = false;
        });
}

function check24hCoherence() {
    var totalH  = parseFloat(document.querySelector('[name="total_heures_contrat"]').value) || 0;
    var debut   = document.getElementById('dateDebut').value;
    var fin     = document.getElementById('dateFin').value;
    var inclure = document.querySelector('[name="inclure_annexe_24h"]').value;
    var el      = document.getElementById('alert24h');
    if (!el) return;
    el.innerHTML = '';
    if (!totalH || !debut || !fin) return;
    var d1 = parseDate(debut), d2 = parseDate(fin);
    if (!d1 || !d2 || d2 <= d1) return;
    var nbJours     = Math.round((d2 - d1) / 86400000) + 1;
    var hParSemaine = totalH / (nbJours / 7);
    if (hParSemaine >= 24 && inclure === '1') {
        el.innerHTML = '<div class="alert alert-warning py-1 px-2 mb-0 d-flex align-items-center gap-2" style="font-size:0.78rem">'
            + '<i class="fa fa-triangle-exclamation"></i>'
            + '<span><strong>' + hParSemaine.toFixed(1) + 'h/semaine</strong> — &gt;= 24h, cette annexe n\'est pas nécessaire.</span>'
            + '<button type="button" class="btn btn-xs btn-warning ms-auto py-0 px-1" style="font-size:0.72rem;white-space:nowrap" onclick="document.querySelector(\'[name=inclure_annexe_24h]\').value=\'0\'; updatePreview(); check24hCoherence()">Retirer</button>'
            + '</div>';
    } else if (hParSemaine < 24 && inclure === '0') {
        el.innerHTML = '<div class="alert alert-warning py-1 px-2 mb-0 d-flex align-items-center gap-2" style="font-size:0.78rem">'
            + '<i class="fa fa-triangle-exclamation"></i>'
            + '<span><strong>' + hParSemaine.toFixed(1) + 'h/semaine</strong> — &lt; 24h, l\'annexe est recommandée.</span>'
            + '<button type="button" class="btn btn-xs btn-warning ms-auto py-0 px-1" style="font-size:0.72rem;white-space:nowrap" onclick="document.querySelector(\'[name=inclure_annexe_24h]\').value=\'1\'; updatePreview(); check24hCoherence()">Inclure</button>'
            + '</div>';
    } else {
        el.innerHTML = '<div class="text-success" style="font-size:0.75rem"><i class="fa fa-check-circle me-1"></i>' + hParSemaine.toFixed(1) + 'h/sem — cohérent</div>';
    }
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

function saveContrat() {
    document.getElementById('saveFlag').value  = '1';
    document.getElementById('exportFlag').value = '0';
    document.getElementById('contratForm').submit();
}

function exportPdf() {
    document.getElementById('saveFlag').value  = '0';
    document.getElementById('exportFlag').value = '1';
    document.getElementById('contratForm').submit();
}

document.addEventListener('DOMContentLoaded', function() {
    updatePreview();
    check24hCoherence();
    initSigPad();

    // Capture snapshot du formulaire contrat avant chaque envoi de token
    document.getElementById('regenForm').addEventListener('submit', function() {
        document.getElementById('regenSnapshot').value = JSON.stringify(getFormData());
    });
    document.getElementById('sendForm').addEventListener('submit', function() {
        document.getElementById('sendSnapshot').value = JSON.stringify(getFormData());
    });
});
</script>

<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.2.0/dist/signature_pad.umd.min.js"></script>
<script>
var _sigPad = null;

function initSigPad() {
    var canvas = document.getElementById('sigCanvas');
    if (!canvas) return;

    function setCanvasSize() {
        var ratio = window.devicePixelRatio || 1;
        var w = canvas.offsetWidth;
        var h = canvas.offsetHeight;
        canvas.width  = w * ratio;
        canvas.height = h * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        if (_sigPad) _sigPad.clear();
    }

    setCanvasSize();
    window.addEventListener('resize', setCanvasSize);

    _sigPad = new SignaturePad(canvas, {
        backgroundColor: 'rgba(255,255,255,0)',
        penColor: '#1a2332',
        minWidth: 1,
        maxWidth: 2.5
    });

    canvas.addEventListener('mousedown', hidePlaceholder);
    canvas.addEventListener('touchstart', hidePlaceholder);
}

function hidePlaceholder() {
    var p = document.getElementById('sigPlaceholder');
    if (p) p.style.display = 'none';
}

function clearSig() {
    if (_sigPad) _sigPad.clear();
    var p = document.getElementById('sigPlaceholder');
    if (p) p.style.display = '';
}

function saveSig() {
    if (!_sigPad || _sigPad.isEmpty()) {
        alert('Veuillez tracer votre signature avant d\'enregistrer.');
        return;
    }
    document.getElementById('sigData').value = _sigPad.toDataURL('image/png');
    document.getElementById('sigForm').submit();
}
</script>

<style>
@media print {
    #sidebar, #topbar, .col-lg-5, .ov-card-header { display:none!important; }
    #main-content { margin:0; padding:0; }
    iframe { width:100%; height:auto; border:none; }
}
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
