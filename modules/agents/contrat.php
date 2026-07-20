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
            SELECT COALESCE(SUM(pl.min_normal + pl.min_nuit + pl.min_dimanche + pl.min_nuit_dimanche
                              + pl.min_ferie_normal + pl.min_ferie_nuit), 0) AS total_min
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

// ── Migration colonne heures_unite (indépendante : le serveur MySQL de prod
//    ne supporte pas la syntaxe "ADD COLUMN IF NOT EXISTS" ci-dessous) ────────
try {
    $hasHeuresUnite = (int)$db->query("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'contrats' AND COLUMN_NAME = 'heures_unite'
    ")->fetchColumn();
    if (!$hasHeuresUnite) {
        $db->exec("ALTER TABLE contrats ADD COLUMN heures_unite VARCHAR(10) NOT NULL DEFAULT 'periode' AFTER total_heures_contrat");
    }
} catch (Exception $e) {}

// ── Auto-migration ─────────────────────────────────────────────────────────────
try {
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS signature LONGTEXT NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS signature_date DATETIME NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS signature_ip VARCHAR(45) NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS lieu_signature VARCHAR(100) NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS date_signature VARCHAR(10) NULL");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS inclure_annexe_24h TINYINT(1) NOT NULL DEFAULT 1");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS mutuelle_choix VARCHAR(20) NOT NULL DEFAULT 'dispense'");
    $db->exec("ALTER TABLE agents ADD COLUMN IF NOT EXISTS total_heures_contrat DECIMAL(8,2) NULL");
    $db->exec("CREATE TABLE IF NOT EXISTS signatures_log (
        id        INT AUTO_INCREMENT PRIMARY KEY,
        agent_id  INT NOT NULL,
        contrat_id INT NULL,
        contrat_hash VARCHAR(64) NOT NULL,
        ip_address   VARCHAR(45),
        user_agent   TEXT,
        signed_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_agent (agent_id)
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS signature_tokens (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        agent_id     INT NOT NULL,
        contrat_id   INT NULL,
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
    $db->exec("ALTER TABLE signature_tokens ADD COLUMN IF NOT EXISTS contrat_id INT NULL");
    $db->exec("ALTER TABLE signatures_log  ADD COLUMN IF NOT EXISTS contrat_id INT NULL");
    $db->exec("ALTER TABLE contrats ADD COLUMN IF NOT EXISTS horaires_vacation VARCHAR(100) NULL AFTER total_heures_contrat");
    $db->exec("ALTER TABLE contrats ADD COLUMN IF NOT EXISTS nom_evenement VARCHAR(255) NULL AFTER horaires_vacation");
    $db->exec("ALTER TABLE contrats ADD COLUMN IF NOT EXISTS dpae_chemin VARCHAR(255) NULL AFTER nom_evenement");
    // Table contrats
    $db->exec("CREATE TABLE IF NOT EXISTS contrats (
        id                   INT AUTO_INCREMENT PRIMARY KEY,
        agent_id             INT NOT NULL,
        type_contrat         VARCHAR(50)  DEFAULT 'CDD',
        poste                VARCHAR(100),
        categorie            VARCHAR(200),
        date_debut           DATE         NULL,
        date_fin             DATE         NULL,
        motif_embauche       VARCHAR(100),
        description_motif    TEXT,
        periode_essai        VARCHAR(50),
        lieu_travail         VARCHAR(200),
        remuneration         DECIMAL(8,2),
        type_remuneration    VARCHAR(20)  DEFAULT 'Brute',
        total_heures_contrat DECIMAL(8,2) NULL,
        heures_unite         VARCHAR(10)  DEFAULT 'periode',
        majoration_nuit      VARCHAR(5)   DEFAULT '10',
        majoration_dim       VARCHAR(5)   DEFAULT '10',
        majoration_ferie     VARCHAR(5)   DEFAULT '100',
        non_renouvelable     TINYINT(1)   DEFAULT 1,
        inclure_annexe_24h   TINYINT(1)   DEFAULT 1,
        mutuelle_choix       VARCHAR(20)  DEFAULT 'dispense',
        lieu_signature       VARCHAR(100),
        date_signature       VARCHAR(10),
        signature            LONGTEXT     NULL,
        signature_date       DATETIME     NULL,
        signature_ip         VARCHAR(45)  NULL,
        statut               ENUM('actif','archive') DEFAULT 'actif',
        notes                TEXT         NULL,
        created_at           DATETIME     DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_agent_id (agent_id)
    )");
    // remuneration en DECIMAL(10,4) pour conserver 4 décimales (taux horaire brut IDCC)
    $db->exec("ALTER TABLE contrats MODIFY COLUMN remuneration DECIMAL(10,4) DEFAULT NULL");
} catch (Exception $e) {}

// ── Charger l'agent ────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT * FROM agents WHERE id = ?");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) { flash('danger','Agent introuvable.'); header('Location: index.php'); exit; }

$params = getAllParams();
$taux   = getTauxHoraires();

// ── Migrer les données existantes uniquement si l'agent a déjà un contrat ─────
$stCount = $db->prepare("SELECT COUNT(*) FROM contrats WHERE agent_id=?");
$stCount->execute([$id]);
$hasContrats = (int)$stCount->fetchColumn() > 0;

if (!$hasContrats && (
    !empty($a['date_debut_contrat']) ||
    !empty($a['date_fin_contrat'])   ||
    !empty($a['signature'])          ||
    !empty($a['total_heures_contrat'])
)) {
    $db->prepare("INSERT INTO contrats (
        agent_id, type_contrat, poste, categorie,
        date_debut, date_fin, motif_embauche,
        periode_essai, lieu_travail, remuneration, type_remuneration,
        total_heures_contrat, inclure_annexe_24h, mutuelle_choix,
        lieu_signature, date_signature
    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
    ->execute([
        $id,
        $a['type_contrat']         ?? 'CDD',
        $a['poste']                ?? 'Agent de sécurité',
        'Employé - Niveau III - Échelon 2 - Coefficient 140',
        $a['date_debut_contrat']   ?: null,
        $a['date_fin_contrat']     ?: null,
        $a['motif_embauche']       ?? null,
        $a['periode_essai']        ?? null,
        $a['lieu_travail']         ?? null,
        $a['remuneration']         ?? null,
        $a['type_remuneration']    ?? 'Brute',
        $a['total_heures_contrat'] ?? null,
        (int)($a['inclure_annexe_24h'] ?? 1),
        $a['mutuelle_choix']       ?? 'dispense',
        $a['lieu_signature']       ?? null,
        $a['date_signature']       ?? null,
    ]);
    $hasContrats = true;
}

// Nettoyer les contrats fantômes (sans date, sans heures, sans signature)
// Ne jamais supprimer un contrat signé, même s'il n'a pas de dates
try {
    $db->exec("DELETE FROM contrats
        WHERE date_debut IS NULL AND date_fin IS NULL AND total_heures_contrat IS NULL
          AND (signature IS NULL OR signature = '')
          AND agent_id IN (SELECT agent_id FROM (SELECT agent_id FROM contrats GROUP BY agent_id HAVING COUNT(*) > 1) t)");
} catch (Exception $e) {}

// Recompter après nettoyage
$stCount->execute([$id]);
$hasContrats = (int)$stCount->fetchColumn() > 0;

// ── Routing ────────────────────────────────────────────────────────────────────
$contratId = (int)($_GET['contrat_id'] ?? 0);
$action    = $_GET['action'] ?? '';
$isNew     = ($action === 'new');
$isDl      = (($_GET['dl'] ?? '') === '1');

// Archiver / Restaurer
if (in_array($action, ['archive','restore']) && $contratId) {
    requirePerm('agents','edit');
    $newStatut = $action === 'archive' ? 'archive' : 'actif';
    $db->prepare("UPDATE contrats SET statut=? WHERE id=? AND agent_id=?")->execute([$newStatut, $contratId, $id]);
    flash('success', $action === 'archive' ? 'Contrat archivé.' : 'Contrat restauré.');
    header("Location: contrat.php?id=$id"); exit;
}

// Dupliquer
if ($action === 'dupliquer' && $contratId) {
    requirePerm('agents','edit');
    $stSrc = $db->prepare("SELECT * FROM contrats WHERE id=? AND agent_id=?");
    $stSrc->execute([$contratId, $id]);
    $src = $stSrc->fetch();
    if ($src) {
        $db->prepare("INSERT INTO contrats (
            agent_id, type_contrat, poste, categorie,
            date_debut, date_fin, motif_embauche, description_motif,
            periode_essai, lieu_travail, remuneration, type_remuneration,
            total_heures_contrat, heures_unite, majoration_nuit, majoration_dim, majoration_ferie,
            non_renouvelable, inclure_annexe_24h, mutuelle_choix,
            lieu_signature, date_signature
        ) VALUES (?,?,?,?,NULL,NULL,?,?,?,?,?,?,NULL,?,?,?,?,?,?,?,?,?)")
        ->execute([
            $id, $src['type_contrat'], $src['poste'], $src['categorie'],
            $src['motif_embauche'], $src['description_motif'],
            $src['periode_essai'], $src['lieu_travail'], $src['remuneration'], $src['type_remuneration'],
            $src['heures_unite'] ?? 'periode', $src['majoration_nuit'], $src['majoration_dim'], $src['majoration_ferie'],
            $src['non_renouvelable'], $src['inclure_annexe_24h'], $src['mutuelle_choix'],
            $src['lieu_signature'], date('d/m/Y'),
        ]);
        $newId = (int)$db->lastInsertId();
        flash('success', 'Contrat dupliqué — dates à compléter.');
        header("Location: contrat.php?id=$id&contrat_id=$newId"); exit;
    }
    header("Location: contrat.php?id=$id"); exit;
}

// Supprimer un contrat
if ($action === 'supprimer' && $contratId) {
    requirePerm('agents','edit');
    $stCount2 = $db->prepare("SELECT COUNT(*) FROM contrats WHERE agent_id=?");
    $stCount2->execute([$id]);
    if ((int)$stCount2->fetchColumn() > 1) {
        $db->prepare("DELETE FROM contrats WHERE id=? AND agent_id=?")->execute([$contratId, $id]);
        flash('success', 'Contrat supprimé.');
    } else {
        flash('danger', 'Impossible de supprimer le seul contrat de cet agent.');
    }
    header("Location: contrat.php?id=$id"); exit;
}

// Si pas de contrat_id, charger le plus récent actif (ou tout dernier)
// Si aucun contrat n'existe, basculer automatiquement en mode "nouveau contrat"
if (!$contratId && !$isNew) {
    if (!$hasContrats) {
        $isNew = true;
    } else {
        $stLast = $db->prepare("SELECT id FROM contrats WHERE agent_id=? ORDER BY FIELD(statut,'actif','archive'), created_at DESC, id DESC LIMIT 1");
        $stLast->execute([$id]);
        $contratId = (int)($stLast->fetchColumn() ?: 0);
    }
}

// Charger le contrat courant
$c = [];
if ($contratId) {
    $stC = $db->prepare("SELECT * FROM contrats WHERE id=? AND agent_id=?");
    $stC->execute([$contratId, $id]);
    $c = $stC->fetch() ?: [];
    if (!$c && !$isNew) {
        flash('danger','Contrat introuvable.');
        header("Location: contrat.php?id=$id"); exit;
    }
}

// Tous les contrats pour le sélecteur
$stAll = $db->prepare("SELECT id, date_debut, date_fin, type_contrat, statut, created_at FROM contrats WHERE agent_id=? ORDER BY created_at DESC, id DESC");
$stAll->execute([$id]);
$allContrats = $stAll->fetchAll();

// ── Construire $defaults depuis le contrat ─────────────────────────────────────
$defaults = [
    'civilite'             => 'M.',
    'nom_prenom'           => strtoupper($a['nom']) . ' ' . $a['prenom'],
    'adresse'              => trim(($a['adresse']??'') . ', ' . ($a['cp']??'') . ' ' . ($a['ville']??''), ', '),
    'date_naissance'       => $a['date_naissance'] ? date('d/m/Y', strtotime($a['date_naissance'])) : '',
    'lieu_naissance'       => $a['lieu_naissance'] ?? '',
    'nationalite'          => $a['nationalite'] ?? '',
    'num_secu'             => $a['num_secu'] ?? '',
    'num_cnaps'            => $a['num_autorisation_cnaps'] ?? '',
    'type_contrat'         => $c['type_contrat'] ?? 'CDD',
    'poste'                => $c['poste'] ?? ($a['poste'] ?? 'Agent de sécurité'),
    'categorie'            => ($c['categorie'] ?? '') ?: 'Employé - Niveau III - Échelon 2 - Coefficient 140',
    'date_debut'           => ($c['date_debut'] ?? '') ? date('d/m/Y', strtotime($c['date_debut'])) : '',
    'date_fin'             => ($c['date_fin']   ?? '') ? date('d/m/Y', strtotime($c['date_fin']))   : '',
    'motif_cdd'            => ($c['motif_embauche'] ?? '') ?: ($a['motif_embauche'] === 'Accroissement activité'
                                ? "accroissement temporaire d'activité"
                                : ($a['motif_embauche'] ?? "accroissement temporaire d'activité")),
    'description_motif'    => ($c['description_motif'] ?? '') ?: "lié à une demande urgente et imprévisible (Article L1242-2-2° du Code du travail).",
    'periode_essai'        => '',
    'total_heures_contrat' => ($c['total_heures_contrat'] ?? '') ? (string)$c['total_heures_contrat'] : '',
    'heures_unite'         => ($c['heures_unite'] ?? '') ?: 'periode',
    'horaires_vacation'    => $c['horaires_vacation'] ?? '',
    'nom_evenement'        => $c['nom_evenement'] ?? '',
    'site_affectation'     => ($c['lieu_travail'] ?? '') ?: ($a['lieu_travail'] ?? ''),
    'salaire_horaire'      => ($c['remuneration'] ?? '')
                                ? number_format((float)$c['remuneration'], 4, '.', '')
                                : ($a['remuneration'] ? number_format((float)$a['remuneration'], 4, '.', '') : '12.7000'),
    'type_remuneration'    => ($c['type_remuneration'] ?? '') ?: ($a['type_remuneration'] ?? 'Brute'),
    'majoration_nuit'      => ($c['majoration_nuit']  ?? '') ?: '10',
    'majoration_dim'       => ($c['majoration_dim']   ?? '') ?: '10',
    'majoration_ferie'     => ($c['majoration_ferie'] ?? '') ?: '100',
    'date_signature'       => ($c['date_signature'] ?? '') ?: ($a['date_signature'] ?? date('d/m/Y')),
    'lieu_signature'       => ($c['lieu_signature']  ?? '') ?: ($a['lieu_signature'] ?? ($params['entreprise_ville'] ?? 'Paris')),
    'non_renouvelable'     => array_key_exists('non_renouvelable',   $c) ? (string)(int)$c['non_renouvelable']   : '1',
    'inclure_annexe_24h'   => array_key_exists('inclure_annexe_24h', $c) ? (string)(int)$c['inclure_annexe_24h'] : '1',
    'mutuelle_choix'       => ($c['mutuelle_choix'] ?? '') ?: ($a['mutuelle_choix'] ?? 'dispense'),
];

$defaults['periode_essai'] = calculerPeriodeEssai($defaults['date_debut'], $defaults['date_fin']);

// Auto-détecter dates depuis le planning si absentes (seulement pour contrats existants)
if (!$isNew && (!$defaults['date_debut'] || !$defaults['date_fin'])) {
    $stP = $db->prepare("SELECT MIN(pl.date_travail) AS min_date, MAX(pl.date_travail) AS max_date
        FROM planning_lignes pl JOIN planning_versions pv ON pv.id=pl.version_id AND pv.is_current=1
        WHERE pl.agent_id=?");
    $stP->execute([$id]);
    $planRow = $stP->fetch();
    if ($planRow && $planRow['min_date']) {
        if (!$defaults['date_debut']) $defaults['date_debut'] = date('d/m/Y', strtotime($planRow['min_date']));
        if (!$defaults['date_fin'])   $defaults['date_fin']   = date('d/m/Y', strtotime($planRow['max_date']));
        $defaults['periode_essai'] = calculerPeriodeEssai($defaults['date_debut'], $defaults['date_fin']);
    }
}

// ── POST : Signature électronique — enregistrement ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_signature'])) {
    $sigData = $_POST['signature_data'] ?? '';
    if ($contratId && preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $sigData)) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
        $db->prepare("UPDATE contrats SET signature=?, signature_date=NOW(), signature_ip=? WHERE id=? AND agent_id=?")
           ->execute([$sigData, $ip, $contratId, $id]);
        $db->prepare("UPDATE agents SET signature=?, signature_date=NOW(), signature_ip=? WHERE id=?")
           ->execute([$sigData, $ip, $id]);
        $db->prepare("INSERT INTO signatures_log (agent_id, contrat_id, contrat_hash, ip_address, user_agent) VALUES (?,?,?,?,?)")
           ->execute([$id, $contratId, hash('sha256', $sigData . $id . date('Y-m-d')), $ip, $ua]);
        flash('success', 'Signature enregistrée — horodatage conservé.');
    } else {
        flash('danger', 'Données de signature invalides.');
    }
    header('Location: contrat.php?id=' . $id . '&contrat_id=' . $contratId); exit;
}

// ── POST : Signature électronique — suppression ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['delete_signature'])) {
    if ($contratId) {
        $db->prepare("UPDATE contrats SET signature=NULL, signature_date=NULL, signature_ip=NULL WHERE id=? AND agent_id=?")->execute([$contratId, $id]);
        $db->prepare("UPDATE agents SET signature=NULL, signature_date=NULL, signature_ip=NULL WHERE id=?")->execute([$id]);
    }
    flash('success', 'Signature supprimée.');
    header('Location: contrat.php?id=' . $id . '&contrat_id=' . $contratId); exit;
}

// ── POST : Envoyer un lien de signature par email ────────────────────────────
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
        $db->prepare("INSERT INTO signature_tokens (agent_id, contrat_id, token, email, contrat_data, expires_at) VALUES (?,?,?,?,?,?)")
           ->execute([$id, $contratId ?: null, $token, $emailDest, $dataSnap, $expiresAt]);
        $sigLink   = rtrim(APP_URL, '/') . '/token/signer.php?t=' . $token;
        $expiryFmt = date('d/m/Y à H:i', strtotime($expiresAt));
        $linkHtml  = '<div class="mt-2 p-2" style="background:#f8f9fa;border-radius:4px;word-break:break-all;font-size:12px"><strong>Lien à copier :</strong><br><a href="' . htmlspecialchars($sigLink) . '" target="_blank">' . htmlspecialchars($sigLink) . '</a></div>';

        if (empty($params['smtp_host'])) {
            flash('warning', '<i class="fa fa-exclamation-triangle me-1"></i>SMTP non configuré — copiez ce lien et transmettez-le à l\'agent. <a href="' . APP_URL . '/modules/parametres/index.php?tab=email" class="alert-link">Configurer le SMTP</a>' . $linkHtml);
        } else {
            $result = sendMail($emailDest, trim($a['prenom'] . ' ' . strtoupper($a['nom'])),
                'Signature de votre contrat — ' . ($params['entreprise_nom'] ?? 'Oeil Vigilant'),
                buildSignatureEmailHtml($a, $params, $sigLink, $expiryFmt));
            if ($result['ok']) {
                flash('success', '<i class="fa fa-check-circle me-1"></i>Email envoyé à <strong>' . htmlspecialchars($emailDest) . '</strong>. Lien valide jusqu\'au ' . $expiryFmt . '.' . $linkHtml);
            } else {
                flash('danger', '<i class="fa fa-times-circle me-1"></i>Envoi impossible : ' . htmlspecialchars($result['error'] ?? 'erreur inconnue') . $linkHtml);
            }
            // Notification interne : avertir l'admin qu'un lien a été envoyé
            $notifEmail = $params['notification_signature_email'] ?? '';
            if ($notifEmail && $notifEmail !== $emailDest) {
                $nom = trim($a['prenom'] . ' ' . strtoupper($a['nom']));
                sendMail($notifEmail, $params['entreprise_nom'] ?? 'OV-Gestion',
                    '📨 Lien de signature envoyé — ' . $nom,
                    '<div style="font-family:Arial,sans-serif;max-width:500px">
                      <div style="background:#1a2332;padding:16px 24px;border-radius:8px 8px 0 0">
                        <span style="color:#c9a84c;font-weight:700;font-size:1rem">📨 Lien de signature envoyé</span>
                      </div>
                      <div style="border:1px solid #e5e7eb;border-top:none;padding:20px 24px;border-radius:0 0 8px 8px">
                        <p style="margin:0 0 12px;color:#374151">Un lien de signature a été envoyé à <strong>' . htmlspecialchars($emailDest) . '</strong> pour le contrat de :</p>
                        <p style="margin:0 0 16px;font-size:1.1rem;font-weight:700;color:#1a2332">' . htmlspecialchars($nom) . '</p>
                        <p style="margin:0 0 16px;color:#6b7280;font-size:0.85rem">Lien valide jusqu\'au <strong>' . $expiryFmt . '</strong>.</p>
                        <a href="' . APP_URL . '/modules/agents/view.php?id=' . $id . '" style="display:inline-block;background:#c9a84c;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-weight:600;font-size:0.875rem">Voir la fiche agent →</a>
                      </div>
                    </div>');
            }
        }
    }
    header('Location: contrat.php?id=' . $id . '&contrat_id=' . $contratId); exit;
}

// ── POST : Regénérer un lien de signature ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['regenerate_token'])) {
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
    $db->prepare("INSERT INTO signature_tokens (agent_id, contrat_id, token, email, contrat_data, expires_at) VALUES (?,?,?,?,?,?)")
       ->execute([$id, $contratId ?: null, $token, $a['email'] ?? '', $dataSnap, $expiresAt]);
    $sigLink   = rtrim(APP_URL, '/') . '/token/signer.php?t=' . $token;
    $expiryFmt = date('d/m/Y à H:i', strtotime($expiresAt));
    $linkHtml  = '<div class="mt-2 p-2" style="background:#f8f9fa;border-radius:4px;word-break:break-all;font-size:12px"><strong>Lien à copier :</strong><br><a href="' . htmlspecialchars($sigLink) . '" target="_blank">' . htmlspecialchars($sigLink) . '</a></div>';
    flash('success', '<i class="fa fa-link me-1"></i>Nouveau lien généré. Valide jusqu\'au ' . $expiryFmt . '.' . $linkHtml);
    header('Location: contrat.php?id=' . $id . '&contrat_id=' . $contratId); exit;
}

// ── POST : Sauvegarder le contrat ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_contrat'])) {
    requirePerm('agents','edit');
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
    $fields = [
        $_POST['type_contrat']         ?? null,
        $_POST['poste']                ?? null,
        $_POST['categorie']            ?? null,
        $dateDebut,
        $dateFin,
        $_POST['motif_cdd']            ?? null,
        $_POST['description_motif']    ?? null,
        $_POST['periode_essai']        ?? null,
        $_POST['site_affectation']     ?? null,
        !empty($_POST['salaire_horaire']) ? (float)$_POST['salaire_horaire'] : null,
        $_POST['type_remuneration']    ?? 'Brute',
        !empty($_POST['total_heures_contrat']) ? (float)$_POST['total_heures_contrat'] : null,
        ($_POST['heures_unite'] ?? 'periode') === 'mois' ? 'mois' : 'periode',
        trim($_POST['horaires_vacation'] ?? ''),
        trim($_POST['nom_evenement']   ?? ''),
        $_POST['majoration_nuit']      ?? '10',
        $_POST['majoration_dim']       ?? '10',
        $_POST['majoration_ferie']     ?? '100',
        (int)($_POST['non_renouvelable']   ?? 1),
        ($_POST['inclure_annexe_24h']  ?? '0') === '1' ? 1 : 0,
        $_POST['mutuelle_choix']       ?? 'dispense',
        trim($_POST['lieu_signature']  ?? ''),
        trim($_POST['date_signature']  ?? ''),
    ];
    if ($contratId) {
        $fields[] = $contratId;
        $fields[] = $id;
        $db->prepare("UPDATE contrats SET
            type_contrat=?, poste=?, categorie=?,
            date_debut=?, date_fin=?,
            motif_embauche=?, description_motif=?,
            periode_essai=?, lieu_travail=?,
            remuneration=?, type_remuneration=?,
            total_heures_contrat=?, heures_unite=?, horaires_vacation=?, nom_evenement=?,
            majoration_nuit=?, majoration_dim=?, majoration_ferie=?,
            non_renouvelable=?, inclure_annexe_24h=?, mutuelle_choix=?,
            lieu_signature=?, date_signature=?
            WHERE id=? AND agent_id=?")
        ->execute($fields);
    } else {
        $fields[] = $id;
        $db->prepare("INSERT INTO contrats (
            type_contrat, poste, categorie,
            date_debut, date_fin,
            motif_embauche, description_motif,
            periode_essai, lieu_travail,
            remuneration, type_remuneration,
            total_heures_contrat, heures_unite, horaires_vacation, nom_evenement,
            majoration_nuit, majoration_dim, majoration_ferie,
            non_renouvelable, inclure_annexe_24h, mutuelle_choix,
            lieu_signature, date_signature, agent_id
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute($fields);
        $contratId = (int)$db->lastInsertId();
    }
    // Sync champs principaux dans agents pour compatibilité
    $db->prepare("UPDATE agents SET
        type_contrat=?, poste=?, lieu_travail=?, remuneration=?, type_remuneration=?,
        date_debut_contrat=?, date_fin_contrat=?, total_heures_contrat=?,
        inclure_annexe_24h=?, mutuelle_choix=?, lieu_signature=?, date_signature=?
        WHERE id=?")
    ->execute([
        $_POST['type_contrat'] ?? null,
        $_POST['poste'] ?? null,
        $_POST['site_affectation'] ?? null,
        !empty($_POST['salaire_horaire']) ? (float)$_POST['salaire_horaire'] : null,
        $_POST['type_remuneration'] ?? 'Brute',
        $dateDebut,
        $dateFin,
        !empty($_POST['total_heures_contrat']) ? (float)$_POST['total_heures_contrat'] : null,
        ($_POST['inclure_annexe_24h'] ?? '0') === '1' ? 1 : 0,
        $_POST['mutuelle_choix'] ?? 'dispense',
        trim($_POST['lieu_signature'] ?? ''),
        trim($_POST['date_signature'] ?? ''),
        $id,
    ]);
    flash('success', 'Contrat sauvegardé.');
    header('Location: contrat.php?id=' . $id . '&contrat_id=' . $contratId);
    exit;
}

// ── Construire $data pour le rendu ─────────────────────────────────────────────
$data         = $_SERVER['REQUEST_METHOD'] === 'POST' ? array_merge($defaults, $_POST) : $defaults;
$postesGrille = $db->query("SELECT * FROM postes WHERE actif=1 ORDER BY ordre, label")->fetchAll();
$exportPdf = $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['export_pdf']) && $_POST['export_pdf'] === '1';

// Passer la signature du contrat courant à buildContratHtml
// On utilise UNIQUEMENT la signature du contrat (pas celle de la table agents qui est un résidu legacy)
$aForPdf = $a;
if (!empty($c['signature'])) {
    $aForPdf['signature']      = $c['signature'];
    $aForPdf['signature_date'] = $c['signature_date'] ?? null;
    $aForPdf['signature_ip']   = $c['signature_ip']   ?? null;
} else {
    $aForPdf['signature']      = null;
    $aForPdf['signature_date'] = null;
    $aForPdf['signature_ip']   = null;
}

if ($exportPdf) {
    $html = buildContratHtml($data, $params, $aForPdf);
    renderPdf($html, 'contrat_' . strtolower(str_replace(' ','_',$a['nom'])) . '_' . strtolower(str_replace(' ','_',$a['prenom'])) . '.pdf');
}

if ($isDl) {
    $html = buildContratHtml($defaults, $params, $aForPdf);
    renderPdf($html, 'Contrat_' . strtoupper(str_replace(' ', '_', $a['nom'])) . '_' . str_replace(' ', '_', $a['prenom']) . '.pdf');
}

// ── Statut signature du contrat courant ────────────────────────────────────────
$sigStatus   = 'aucun';
$sigTokenRow = null;
try {
    $sigFromContrat = !empty($c['signature']);
    if ($sigFromContrat) {
        $sigStatus = 'signe';
    } elseif ($contratId > 0) {
        $stTok = $db->prepare("SELECT * FROM signature_tokens WHERE agent_id=? AND contrat_id=? ORDER BY sent_at DESC LIMIT 1");
        $stTok->execute([$id, $contratId]);
        $sigTokenRow = $stTok->fetch();
        if ($sigTokenRow) {
            if (!empty($sigTokenRow['signed_at']))                           $sigStatus = 'signe';
            elseif (strtotime($sigTokenRow['expires_at']) > time())          $sigStatus = 'lien_actif';
            else                                                              $sigStatus = 'lien_expire';
        }
    }
} catch (Exception $e) {}

$pageTitle     = 'Édition du contrat';
$currentModule = 'agents';
require_once __DIR__ . '/../../includes/header.php';

// Helper : libellé d'un contrat pour le sélecteur
function contratLabel(array $ct): string {
    $debut = $ct['date_debut'] ? date('d/m/Y', strtotime($ct['date_debut'])) : '?';
    $fin   = $ct['date_fin']   ? date('d/m/Y', strtotime($ct['date_fin']))   : '...';
    return ($ct['type_contrat'] ?? 'CDD') . ' — ' . $debut . ' → ' . $fin;
}
?>

<?php
// ── Validation champs nécessaires au contrat ───────────────────────────────
$controleContrat = [];
$reqContrat = [
    'prenom'               => 'Prénom',
    'nom'                  => 'Nom',
    'date_naissance'       => 'Date de naissance',
    'lieu_naissance'       => 'Lieu de naissance',
    'num_secu'             => 'N° sécurité sociale',
    'nationalite'          => 'Nationalité',
    'adresse'              => 'Adresse',
    'cp'                   => 'Code postal',
    'ville'                => 'Ville',
    'num_autorisation_cnaps' => 'N° autorisation CNAPS',
    'remuneration'         => 'Rémunération horaire',
];
foreach ($reqContrat as $key => $label) {
    if (empty($a[$key])) $controleContrat[] = $label;
}
// Vérif CNAPS expiration
if (empty($a['date_expiration_cnaps'])) {
    $controleContrat[] = 'Date d\'expiration CNAPS manquante';
} elseif (strtotime($a['date_expiration_cnaps']) < time()) {
    $controleContrat[] = 'Autorisation CNAPS expirée le '.date('d/m/Y', strtotime($a['date_expiration_cnaps']));
}
// Vérif dates contrat
if (empty($defaults['date_debut'])) $controleContrat[] = 'Date de début du contrat';
if ($defaults['type_contrat'] !== 'CDI' && empty($defaults['date_fin'])) $controleContrat[] = 'Date de fin du contrat';
if (empty($defaults['total_heures_contrat'])) $controleContrat[] = 'Total heures contrat';
?>
<?php if ($controleContrat): ?>
<div class="alert mb-3 p-3" style="background:rgba(239,68,68,0.06);border:1.5px solid #f87171;border-radius:12px;color:#7f1d1d">
    <div class="d-flex align-items-start gap-2">
        <i class="fa fa-circle-exclamation mt-1" style="color:#ef4444;font-size:1.1rem;flex-shrink:0"></i>
        <div>
            <div style="font-weight:700;font-size:0.92rem;margin-bottom:6px">
                Le contrat risque d'être incomplet — <?= count($controleContrat) ?> information<?= count($controleContrat)>1?'s':'' ?> manquante<?= count($controleContrat)>1?'s':'' ?>
            </div>
            <div class="d-flex flex-wrap gap-1">
            <?php foreach ($controleContrat as $item): ?>
            <span style="display:inline-block;background:rgba(239,68,68,0.1);color:#7f1d1d;padding:2px 9px;border-radius:10px;font-size:0.78rem;border:1px solid rgba(239,68,68,0.2)"><?= h($item) ?></span>
            <?php endforeach; ?>
            </div>
        </div>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm ms-auto" style="white-space:nowrap;background:rgba(239,68,68,0.1);color:#7f1d1d;border:1px solid #f87171;border-radius:8px;font-size:0.8rem;padding:4px 12px;flex-shrink:0">
            <i class="fa fa-pen me-1"></i>Compléter la fiche
        </a>
    </div>
</div>
<?php endif; ?>

<!-- Barre de navigation contrats -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
  <a href="view.php?id=<?= $id ?>" class="btn btn-ov-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Retour fiche</a>

  <?php if (!$isNew && count($allContrats) > 0): ?>
  <div class="dropdown">
    <button class="btn btn-sm dropdown-toggle" style="background:#f0f2f5;border:1px solid #e5e7eb;font-size:0.82rem" type="button" data-bs-toggle="dropdown">
      <i class="fa fa-file-contract me-1 text-warning"></i>
      <?= $c ? h(contratLabel($c)) : 'Sélectionner' ?>
      <?php if ($c && $c['statut'] === 'archive'): ?><span class="badge bg-secondary ms-1" style="font-size:0.65rem">Archivé</span><?php endif; ?>
    </button>
    <ul class="dropdown-menu" style="min-width:280px">
      <?php foreach ($allContrats as $ct): ?>
      <li>
        <a class="dropdown-item d-flex align-items-center gap-2 <?= $ct['id'] == $contratId ? 'active' : '' ?>" href="contrat.php?id=<?= $id ?>&contrat_id=<?= $ct['id'] ?>">
          <i class="fa fa-file-contract fa-fw" style="font-size:0.8rem"></i>
          <span style="font-size:0.82rem"><?= h(contratLabel($ct)) ?></span>
          <?php if ($ct['statut'] === 'archive'): ?><span class="badge bg-secondary ms-auto" style="font-size:0.6rem">Archivé</span><?php endif; ?>
        </a>
      </li>
      <?php endforeach; ?>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-primary" href="contrat.php?id=<?= $id ?>&action=new"><i class="fa fa-plus me-2"></i>Nouveau contrat</a></li>
    </ul>
  </div>
  <?php endif; ?>

  <?php if (!$isNew && $c): ?>
    <?php if ($c['statut'] === 'actif'): ?>
    <a href="contrat.php?id=<?= $id ?>&contrat_id=<?= $contratId ?>&action=dupliquer"
       class="btn btn-sm" style="background:rgba(99,102,241,0.1);color:#3730a3;border:1px solid rgba(99,102,241,0.3)"
       title="Créer un nouveau contrat avec les mêmes paramètres (dates vides)">
      <i class="fa fa-copy me-1"></i>Dupliquer
    </a>
    <a href="contrat.php?id=<?= $id ?>&contrat_id=<?= $contratId ?>&action=archive"
       class="btn btn-sm" style="background:rgba(107,114,128,0.1);color:#6b7280;border:1px solid rgba(107,114,128,0.3)"
       onclick="return confirm('Archiver ce contrat ?')">
      <i class="fa fa-box-archive me-1"></i>Archiver
    </a>
    <?php else: ?>
    <a href="contrat.php?id=<?= $id ?>&contrat_id=<?= $contratId ?>&action=restore"
       class="btn btn-sm" style="background:rgba(34,197,94,0.1);color:#16a34a;border:1px solid rgba(34,197,94,0.3)">
      <i class="fa fa-rotate-left me-1"></i>Restaurer
    </a>
    <?php endif; ?>
    <?php if (count($allContrats) > 1): ?>
    <a href="contrat.php?id=<?= $id ?>&contrat_id=<?= $contratId ?>&action=supprimer"
       class="btn btn-sm" style="background:rgba(239,68,68,0.08);color:#dc2626;border:1px solid rgba(239,68,68,0.2)"
       onclick="return confirm('Supprimer définitivement ce contrat ?')">
      <i class="fa fa-trash me-1"></i>
    </a>
    <?php endif; ?>
  <?php endif; ?>

  <a href="contrat.php?id=<?= $id ?>&action=new" class="btn btn-sm btn-ov-primary ms-auto">
    <i class="fa fa-plus me-1"></i>Nouveau contrat
  </a>
</div>

<?php if ($isNew): ?>
<div class="alert alert-info py-2 mb-3" style="font-size:0.85rem">
  <i class="fa fa-circle-info me-1"></i>
  <strong>Nouveau contrat</strong> — Les informations du salarié sont pré-remplies. Complétez les dates et les heures, puis sauvegardez.
</div>
<?php elseif ($c && $c['statut'] === 'archive'): ?>
<div class="alert alert-secondary py-2 mb-3" style="font-size:0.85rem">
  <i class="fa fa-box-archive me-1"></i>
  Contrat archivé — lecture seule. <a href="contrat.php?id=<?= $id ?>&contrat_id=<?= $contratId ?>&action=restore">Restaurer</a> pour le modifier.
</div>
<?php endif; ?>

<div class="row g-3">

<!-- Formulaire gauche -->
<div class="col-lg-5">
  <form method="POST" id="contratForm">
    <input type="hidden" name="export_pdf"   value="0" id="exportFlag">
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
              <option value="CDD"       <?= $data['type_contrat']==='CDD'      ?'selected':'' ?>>CDD</option>
              <option value="CDI"       <?= $data['type_contrat']==='CDI'      ?'selected':'' ?>>CDI</option>
              <option value="CDD Usage" <?= $data['type_contrat']==='CDD Usage'?'selected':'' ?>>CDD d'Usage</option>
              <option value="Saisonnier"<?= $data['type_contrat']==='Saisonnier'?'selected':'' ?>>Saisonnier</option>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Poste <small class="text-muted">(intitulé de la fonction)</small></label>
            <input type="text" name="poste" id="posteTexte" class="form-control form-control-sm" value="<?= h($data['poste']) ?>" oninput="updatePreview()" placeholder="Ex : Agent de sécurité">
          </div>
          <div class="col-12">
            <label class="form-label">Catégorie / Niveau / Coefficient</label>
            <?php if ($postesGrille): ?>
            <select id="posteGrilleSelect" class="form-select form-select-sm mb-1" style="border-color:var(--ov-gold);background:rgba(201,168,76,0.04)">
              <option value="">— Sélectionner depuis la grille —</option>
              <?php foreach ($postesGrille as $pg): ?>
              <?php
                // titre  → champ "Poste" : "Agent de sécurité confirmé"
                $titre = trim(explode('(', $pg['label'])[0]);
                // classif → champ "Catégorie" : "Niv. III - Éch. 1 - Coeff. 130" (sans doublon titre)
                if (preg_match('/\(([^)]+)\)\s*-\s*(Coeff\.\s*\d+)/i', $pg['label'], $m)) {
                    $classif = $m[1] . ' - ' . $m[2]; // "Niv. III - Éch. 1 - Coeff. 130"
                } else {
                    $classif = $pg['label']; // fallback : label complet
                }
              ?>
              <option value="<?= h($pg['id']) ?>"
                      data-label="<?= h($classif) ?>"
                      data-titre="<?= h($titre) ?>"
                      data-taux="<?= h($pg['taux_horaire']) ?>"
                      <?= ($data['categorie'] ?? '') === $classif ? 'selected' : '' ?>
              ><?= h($pg['label']) ?> — <?= number_format($pg['taux_horaire'],2) ?> €/h</option>
              <?php endforeach; ?>
            </select>
            <?php endif; ?>
            <input type="text" name="categorie" id="categorieTexte" class="form-control form-control-sm" value="<?= h($data['categorie']) ?>" oninput="updatePreview()" placeholder="Ex : Agent de sécurité confirmé (Niv. III - Éch. 1) - Coeff. 130">
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
            <label class="form-label" id="labelTotalHeures"><?= $data['heures_unite'] === 'mois' ? 'Heures par mois' : 'Total heures contrat' ?></label>
            <div class="input-group input-group-sm">
              <input type="number" name="total_heures_contrat" id="totalHeuresContrat" class="form-control" step="0.5" min="0" value="<?= h($data['total_heures_contrat'] ?? '') ?>" placeholder="ex: 36" oninput="updatePreview(); check24hCoherence()">
              <select name="heures_unite" id="heuresUnite" class="form-select" style="max-width:100px;flex:0 0 auto" onchange="updateHeuresUnite()" title="Unité du volume horaire">
                <option value="periode" <?= $data['heures_unite']==='periode'?'selected':'' ?>>h / période</option>
                <option value="mois"    <?= $data['heures_unite']==='mois'   ?'selected':'' ?>>h / mois</option>
              </select>
              <button type="button" class="btn btn-outline-secondary" id="btnCalcHeures" onclick="calcHeuresPlanning()" title="Calculer depuis le planning">
                <i class="fa fa-rotate" id="calcHeuresIcon"></i>
              </button>
            </div>
            <div class="form-text">« h / mois » calcule un volume mensuel récurrent (utile en CDI ou contrat long) plutôt qu'un total figé pour toute la période.</div>
          </div>
          <div class="col-6">
            <label class="form-label">Période d'essai <small class="text-muted">(auto)</small></label>
            <input type="text" name="periode_essai" id="periodeEssai" class="form-control form-control-sm" value="<?= h($data['periode_essai']) ?>" readonly style="background:#f8f9fa;color:#6b7280">
          </div>
          <div class="col-12" id="rowHorairesVacation">
            <label class="form-label">Horaires de la vacation <small class="text-muted">(ex : 17h30 à 21h30)</small></label>
            <input type="text" name="horaires_vacation" class="form-control form-control-sm" value="<?= h($data['horaires_vacation'] ?? '') ?>" placeholder="ex : 17h30 à 21h30" oninput="updatePreview()">
            <div class="form-text">Si renseigné, remplace « selon le planning » par les horaires exacts dans l'article 4.</div>
          </div>
          <div class="col-12" id="rowNomEvenement">
            <label class="form-label">Nom de l'événement <small class="text-muted">(CDD d'Usage — optionnel)</small></label>
            <input type="text" name="nom_evenement" class="form-control form-control-sm" value="<?= h($data['nom_evenement'] ?? '') ?>" placeholder="ex : Prestation événementielle pour l'Association X" oninput="updatePreview()">
            <div class="form-text">Si renseigné, s'ajoute dans l'article 2 : « Ce contrat est spécifiquement lié à la couverture de l'événement suivant : … »</div>
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
              <input type="number" name="salaire_horaire" class="form-control" step="0.0001" min="0" value="<?= h($data['salaire_horaire']) ?>" oninput="updatePreview()">
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
      <?php if (!$c || $c['statut'] === 'actif'): ?>
      <button type="button" onclick="saveContrat()" class="btn" style="background:rgba(16,185,129,0.1);color:#065f46;border:1px solid rgba(16,185,129,0.4);font-weight:500">
        <i class="fa fa-floppy-disk me-2"></i>Sauvegarder
      </button>
      <?php endif; ?>
      <button type="button" onclick="exportPdf()" class="btn btn-ov-primary">
        <i class="fa fa-file-pdf me-2"></i>Générer &amp; Télécharger le contrat PDF
      </button>
      <?php if (!empty($c['signature'])): ?>
      <button type="button" onclick="exportPdf()" class="btn btn-success">
        <i class="fa fa-file-pdf me-2"></i>Télécharger le contrat <strong>signé</strong>
        <span class="badge bg-white text-success ms-1" style="font-size:0.7rem">✓ Signé le <?= date('d/m/Y', strtotime($c['signature_date'])) ?></span>
      </button>
      <?php endif; ?>
      <button type="button" onclick="document.getElementById('contratPreview').contentWindow.print()" class="btn btn-ov-secondary">
        <i class="fa fa-print me-2"></i>Imprimer l'aperçu
      </button>
    </div>
  </form>

  <!-- ── Signature électronique ──────────────────────────────────────────────── -->
  <?php if (!$isNew && $contratId): ?>
  <div class="ov-card mt-3">
    <div class="ov-card-header">
      <h2 class="ov-card-title"><i class="fa fa-pen-nib me-2" style="color:var(--ov-gold)"></i>Signature électronique du salarié</h2>
    </div>
    <div class="ov-card-body">

      <?php if (!empty($c['signature'])): ?>
      <div class="d-flex align-items-center gap-3 p-2 mb-3" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px">
        <img src="<?= h($c['signature']) ?>" style="height:54px;max-width:140px;background:#fff;border:1px solid #d1fae5;border-radius:4px;padding:3px;object-fit:contain">
        <div class="small">
          <div class="fw-semibold text-success"><i class="fa fa-check-circle me-1"></i>Signature enregistrée</div>
          <?php if ($c['signature_date']): ?>
          <div class="text-muted"><?= date('d/m/Y à H:i', strtotime($c['signature_date'])) ?></div>
          <div class="text-muted">IP : <?= h($c['signature_ip'] ?? '—') ?></div>
          <?php endif; ?>
        </div>
        <form method="post" class="ms-auto" onsubmit="return confirm('Supprimer définitivement la signature ?')">
          <input type="hidden" name="delete_signature" value="1">
          <button type="submit" class="btn btn-sm btn-outline-danger" title="Supprimer"><i class="fa fa-trash"></i></button>
        </form>
      </div>
      <p class="text-muted small mb-2">Pour re-signer, effacez la signature ci-dessus puis tracez et enregistrez.</p>
      <?php else: ?>
      <p class="text-muted small mb-2">Le salarié signe ici (souris ou écran tactile). La signature est horodatée.</p>
      <?php endif; ?>

      <div style="border:2px dashed #d1d5db;border-radius:6px;background:#fafafa;position:relative;user-select:none">
        <canvas id="sigCanvas" width="600" height="150" style="width:100%;height:150px;display:block;touch-action:none;cursor:crosshair;border-radius:4px"></canvas>
        <span id="sigPlaceholder" style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);color:#d1d5db;font-size:14px;pointer-events:none;white-space:nowrap">✍ Signez ici</span>
      </div>

      <div class="d-flex gap-2 mt-2 align-items-center">
        <button type="button" onclick="clearSig()" class="btn btn-sm btn-outline-secondary"><i class="fa fa-eraser me-1"></i>Effacer</button>
        <button type="button" onclick="saveSig()" class="btn btn-sm btn-ov-primary ms-auto"><i class="fa fa-check me-1"></i>Enregistrer la signature</button>
      </div>

      <form method="post" id="sigForm">
        <input type="hidden" name="save_signature" value="1">
        <input type="hidden" name="signature_data" id="sigData">
      </form>

      <p class="text-muted mt-2 mb-0" style="font-size:10px">
        <i class="fa fa-shield-halved me-1 text-success"></i>Signature électronique simple — règlement eIDAS (UE 910/2014).
      </p>

      <hr class="my-3">

      <h6 class="mb-2"><i class="fa fa-envelope me-2 text-warning"></i>Envoyer pour signature par email</h6>
      <?php
        $lastTok = false;
        if ($contratId > 0) {
            $lastToken = $db->prepare("SELECT * FROM signature_tokens WHERE agent_id=? AND contrat_id=? ORDER BY sent_at DESC LIMIT 1");
            $lastToken->execute([$id, $contratId]);
            $lastTok = $lastToken->fetch();
        }
      ?>
      <?php if ($lastTok): ?>
      <div class="mb-2 p-2 rounded small <?= $lastTok['signed_at'] ? 'bg-success bg-opacity-10 border border-success border-opacity-25' : (strtotime($lastTok['expires_at']) < time() ? 'bg-secondary bg-opacity-10' : 'bg-warning bg-opacity-10 border border-warning border-opacity-25') ?>">
        <?php if ($lastTok['signed_at']): ?>
          <i class="fa fa-check-circle text-success me-1"></i>
          <strong>Signé</strong> le <?= date('d/m/Y à H:i', strtotime($lastTok['signed_at'])) ?> — envoyé à <?= h($lastTok['email'] ?: '(sans email)') ?>
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
            <button type="submit" class="btn btn-sm btn-outline-secondary" style="white-space:nowrap">
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
            <input type="email" name="sig_email" class="form-control form-control-sm" value="<?= h($a['email'] ?? '') ?>" placeholder="prenom.nom@email.com" required>
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
      <p class="text-muted mt-1 mb-0" style="font-size:10px">Le salarié lit le contrat et signe depuis son téléphone ou PC.</p>
    </div>
  </div>
  <?php endif; ?>

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
    if (nbSem === 0) el.value = '0 jour';
    else if (nbSem === 1) el.value = '1 jour travaillé';
    else el.value = nbSem + ' jours travaillés';
}

function calcHeuresPlanning() {
    var debut = document.getElementById('dateDebut').value;
    var fin   = document.getElementById('dateFin').value;
    if (!debut || !fin) { alert('Veuillez renseigner les dates avant de calculer.'); return; }
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
                var unite = document.getElementById('heuresUnite').value;
                var val   = data.total_heures;
                if (unite === 'mois') {
                    var d1 = parseDate(debut), d2 = parseDate(fin);
                    var nbJours = (d1 && d2 && d2 > d1) ? (Math.round((d2 - d1) / 86400000) + 1) : 30.44;
                    var nbMois  = Math.max(nbJours / 30.44, 1 / 30.44);
                    val = Math.round((val / nbMois) * 100) / 100;
                }
                document.getElementById('totalHeuresContrat').value = val;
                updatePreview(); check24hCoherence();
                icon.className = 'fa fa-check text-success';
                setTimeout(function() { icon.className = 'fa fa-rotate'; }, 2000);
            } else {
                icon.className = 'fa fa-triangle-exclamation text-warning';
                setTimeout(function() { icon.className = 'fa fa-rotate'; }, 2000);
            }
            btn.disabled = false;
        })
        .catch(function() { icon.className = 'fa fa-rotate'; btn.disabled = false; });
}

function updateHeuresUnite() {
    var unite = document.getElementById('heuresUnite').value;
    document.getElementById('labelTotalHeures').textContent = unite === 'mois' ? 'Heures par mois' : 'Total heures contrat';
    updatePreview();
    check24hCoherence();
}

function check24hCoherence() {
    var totalH  = parseFloat(document.querySelector('[name="total_heures_contrat"]').value) || 0;
    var unite   = document.getElementById('heuresUnite').value;
    var debut   = document.getElementById('dateDebut').value;
    var fin     = document.getElementById('dateFin').value;
    var inclure = document.querySelector('[name="inclure_annexe_24h"]').value;
    var el      = document.getElementById('alert24h');
    if (!el) return;
    el.innerHTML = '';
    if (!totalH) return;
    var hParSemaine;
    if (unite === 'mois') {
        hParSemaine = totalH * 12 / 52;
    } else {
        if (!debut || !fin) return;
        var d1 = parseDate(debut), d2 = parseDate(fin);
        if (!d1 || !d2 || d2 <= d1) return;
        var nbJours = Math.round((d2 - d1) / 86400000) + 1;
        hParSemaine = totalH / (nbJours / 7);
    }
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
    fetch('contrat_preview.php?id=<?= $id ?>&contrat_id=<?= $contratId ?>', {
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

    // Grille des postes → auto-remplit poste + catégorie + salaire horaire
    var selPoste = document.getElementById('posteGrilleSelect');
    if (selPoste) {
        selPoste.addEventListener('change', function() {
            var opt = this.options[this.selectedIndex];
            if (!opt.value) return;
            var posteField = document.getElementById('posteTexte');
            var catField   = document.getElementById('categorieTexte');
            var tauxField  = document.querySelector('[name="salaire_horaire"]');
            if (posteField && opt.dataset.titre) { posteField.value = opt.dataset.titre; }
            if (catField)  { catField.value  = opt.dataset.label || ''; }
            if (tauxField && opt.dataset.taux) {
                tauxField.value = parseFloat(opt.dataset.taux).toFixed(4);
                tauxField.style.borderColor = 'var(--ov-gold)';
                tauxField.style.background  = 'rgba(201,168,76,0.06)';
            }
            updatePreview();
        });
    }

    var regenForm = document.getElementById('regenForm');
    var sendForm  = document.getElementById('sendForm');
    if (regenForm) regenForm.addEventListener('submit', function() {
        document.getElementById('regenSnapshot').value = JSON.stringify(getFormData());
    });
    if (sendForm) sendForm.addEventListener('submit', function() {
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
        var w = canvas.offsetWidth, h = canvas.offsetHeight;
        canvas.width  = w * ratio;
        canvas.height = h * ratio;
        canvas.getContext('2d').scale(ratio, ratio);
        if (_sigPad) _sigPad.clear();
    }
    setCanvasSize();
    window.addEventListener('resize', setCanvasSize);
    _sigPad = new SignaturePad(canvas, { backgroundColor:'rgba(255,255,255,0)', penColor:'#1a2332', minWidth:1, maxWidth:2.5 });
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
    if (!_sigPad || _sigPad.isEmpty()) { alert('Veuillez tracer votre signature avant d\'enregistrer.'); return; }
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
