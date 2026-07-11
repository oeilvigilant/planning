<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('parametres', 'view');

$db = getDB();

// Migration table postes
try { $db->exec("CREATE TABLE IF NOT EXISTS postes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(255) NOT NULL,
    coefficient INT DEFAULT NULL,
    taux_horaire DECIMAL(8,4) NOT NULL DEFAULT 0,
    actif TINYINT(1) NOT NULL DEFAULT 1,
    ordre INT NOT NULL DEFAULT 0
)"); } catch(Exception $e){}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && canDo('parametres','edit')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_entreprise') {
        $champs = ['entreprise_nom','entreprise_dirigeant','entreprise_adresse','entreprise_cp',
                   'entreprise_ville','entreprise_siret','entreprise_tel','entreprise_email'];
        foreach ($champs as $c) setParam($c, $_POST[$c] ?? '');

        // Logo upload
        if (!empty($_FILES['logo']['name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','svg'])) {
                $nom = 'logo_custom.' . $ext;
                move_uploaded_file($_FILES['logo']['tmp_name'], APP_ROOT . '/assets/img/' . $nom);
                setParam('logo_principal', $nom);
            }
        }
        // Signature président upload
        if (!empty($_FILES['signature_president']['name'])) {
            $ext = strtolower(pathinfo($_FILES['signature_president']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png'])) {
                $nom = 'signature-president.' . $ext;
                move_uploaded_file($_FILES['signature_president']['tmp_name'], APP_ROOT . '/uploads/photos/' . $nom);
                setParam('signature_president', $nom);
            }
        }
        flash('success','Informations entreprise sauvegardées.');
        header('Location: index.php'); exit;
    }

    if ($action === 'save_taux') {
        $types = ['normal','nuit','dimanche','ferie_normal','ferie_dimanche','ferie_nuit'];
        foreach ($types as $t) {
            $taux = (float)($_POST['taux_'.$t] ?? 0);
            $db->prepare("UPDATE taux_horaires SET taux=? WHERE type_heure=?")->execute([$taux,$t]);
        }
        flash('success','Taux horaires mis à jour.');
        header('Location: index.php?tab=taux'); exit;
    }

    if ($action === 'add_poste') {
        $label  = trim($_POST['poste_label'] ?? '');
        $coef   = (int)($_POST['poste_coefficient'] ?? 0) ?: null;
        $taux   = (float)($_POST['poste_taux'] ?? 0);
        if ($label) {
            $db->prepare("INSERT INTO postes (label, coefficient, taux_horaire, ordre) VALUES (?,?,?,(SELECT COALESCE(MAX(p.ordre),0)+1 FROM postes p))")
               ->execute([$label, $coef, $taux]);
            flash('success','Poste ajouté.');
        }
        header('Location: index.php?tab=postes'); exit;
    }
    if ($action === 'save_postes') {
        foreach ($_POST['postes'] ?? [] as $pid => $d) {
            $db->prepare("UPDATE postes SET label=?, coefficient=?, taux_horaire=?, actif=? WHERE id=?")
               ->execute([
                   trim($d['label'] ?? ''),
                   (int)($d['coefficient'] ?? 0) ?: null,
                   (float)($d['taux_horaire'] ?? 0),
                   isset($d['actif']) ? 1 : 0,
                   (int)$pid,
               ]);
        }
        flash('success','Postes mis à jour.');
        header('Location: index.php?tab=postes'); exit;
    }
    if ($action === 'del_poste') {
        $db->prepare("DELETE FROM postes WHERE id=?")->execute([(int)($_POST['poste_id'] ?? 0)]);
        flash('success','Poste supprimé.');
        header('Location: index.php?tab=postes'); exit;
    }

    if ($action === 'save_planning') {
        setParam('nuit_debut', $_POST['nuit_debut'] ?? '21:00');
        setParam('nuit_fin',   $_POST['nuit_fin']   ?? '06:00');
        setParam('token_expiration_jours', (string)max(1,(int)($_POST['token_expiration_jours']??7)));
        flash('success','Paramètres planning sauvegardés.');
        header('Location: index.php'); exit;
    }

    if ($action === 'add_ferie') {
        $date = $_POST['ferie_date'] ?? '';
        $nom  = $_POST['ferie_nom']  ?? '';
        if ($date && $nom) {
            try {
                $db->prepare("INSERT INTO jours_feries (date, nom, recurrent, annee) VALUES (?,?,?,?)")
                   ->execute([$date, $nom, isset($_POST['ferie_recurrent'])?1:0, date('Y',strtotime($date))]);
                flash('success','Jour férié ajouté.');
            } catch (Exception $e) { flash('danger','Date déjà existante.'); }
        }
        header('Location: index.php#feries'); exit;
    }

    if ($action === 'del_ferie') {
        $id = (int)($_POST['ferie_id'] ?? 0);
        if ($id) $db->prepare("DELETE FROM jours_feries WHERE id=?")->execute([$id]);
        header('Location: index.php#feries'); exit;
    }

    if ($action === 'save_carte') {
        $champs = $_POST['champ'] ?? [];
        foreach ($champs as $champId => $d) {
            $db->prepare("UPDATE carte_champs SET actif=?, ordre=?, face=? WHERE id=?")
               ->execute([isset($d['actif'])?1:0, (int)$d['ordre'], $d['face'], (int)$champId]);
        }
        flash('success','Champs carte agent mis à jour.');
        header('Location: index.php#carte'); exit;
    }

    if ($action === 'save_carte_textes') {
        setParam('entreprise_slogan',    trim($_POST['entreprise_slogan']    ?? ''));
        setParam('entreprise_cnaps',     trim($_POST['entreprise_cnaps']     ?? ''));
        setParam('carte_mention_legale', trim($_POST['carte_mention_legale'] ?? ''));
        flash('success','Textes du badge mis à jour.');
        header('Location: index.php#carte'); exit;
    }

    if ($action === 'save_pdf') {
        $champs = $_POST['champ'] ?? [];
        foreach ($champs as $champId => $d) {
            $db->prepare("UPDATE pdf_champs SET actif=?, ordre=? WHERE id=?")
               ->execute([isset($d['actif'])?1:0, (int)$d['ordre'], (int)$champId]);
        }
        flash('success','Champs PDF comptable mis à jour.');
        header('Location: index.php#pdf'); exit;
    }

    if ($action === 'add_champ_perso') {
        $label = trim($_POST['cp_label'] ?? '');
        $type  = in_array($_POST['cp_type']??'', ['text','textarea','date','select','file']) ? $_POST['cp_type'] : 'text';
        $oblig = isset($_POST['cp_obligatoire']) ? 1 : 0;
        $opts  = trim($_POST['cp_options'] ?? '');
        if ($label) {
            $cle  = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $label));
            $cle  = trim($cle, '_') ?: 'champ';
            $base = $cle; $i = 2;
            $stmtC = $db->prepare("SELECT COUNT(*) FROM agent_champs_perso WHERE cle=?");
            $stmtC->execute([$cle]);
            while ($stmtC->fetchColumn() > 0) {
                $cle = $base.'_'.$i++;
                $stmtC->execute([$cle]);
            }
            $optJson = null;
            if ($type === 'select' && $opts) {
                $optArr = array_values(array_filter(array_map('trim', explode("\n", $opts))));
                if ($optArr) $optJson = json_encode($optArr, JSON_UNESCAPED_UNICODE);
            }
            $maxOrdre = (int)$db->query("SELECT COALESCE(MAX(ordre),0) FROM agent_champs_perso")->fetchColumn();
            $db->prepare("INSERT INTO agent_champs_perso (label,cle,type,options,obligatoire,ordre) VALUES (?,?,?,?,?,?)")
               ->execute([$label, $cle, $type, $optJson, $oblig, $maxOrdre+1]);
            flash('success','Champ personnalisé ajouté.');
        }
        header('Location: index.php?tab=champs-agents'); exit;
    }

    if ($action === 'del_champ_perso') {
        $cpId = (int)($_POST['cp_id'] ?? 0);
        if ($cpId) {
            $db->prepare("DELETE FROM agent_valeurs_perso WHERE champ_id=?")->execute([$cpId]);
            $db->prepare("DELETE FROM agent_champs_perso WHERE id=?")->execute([$cpId]);
            flash('success','Champ et ses valeurs supprimés.');
        }
        header('Location: index.php?tab=champs-agents'); exit;
    }

    if ($action === 'save_champs_perso') {
        $champs = $_POST['cp'] ?? [];
        foreach ($champs as $cpId => $d) {
            $db->prepare("UPDATE agent_champs_perso SET actif=?,ordre=?,obligatoire=?,label=? WHERE id=?")
               ->execute([isset($d['actif'])?1:0, (int)($d['ordre']??0), isset($d['obligatoire'])?1:0, trim($d['label']??''), (int)$cpId]);
        }
        flash('success','Champs personnalisés mis à jour.');
        header('Location: index.php?tab=champs-agents'); exit;
    }

    if ($action === 'save_smtp') {
        $champs = ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_from','notification_signature_email'];
        foreach ($champs as $c) setParam($c, trim($_POST[$c] ?? ''));
        flash('success', 'Configuration email sauvegardée.');
        header('Location: index.php?tab=email'); exit;
    }

    if ($action === 'test_smtp') {
        require_once __DIR__ . '/../../includes/mailer.php';
        $params = getAllParams(); // charger ici car $params global n'est pas encore défini
        $dest = trim($_POST['test_email'] ?? '');
        if (!filter_var($dest, FILTER_VALIDATE_EMAIL)) {
            flash('danger', 'Adresse email invalide pour le test.');
        } elseif (empty($params['smtp_host'])) {
            flash('warning', 'Aucun serveur SMTP configuré — remplissez et sauvegardez d\'abord la configuration.');
        } else {
            $res = sendMail($dest, $dest, 'Test SMTP — ' . ($params['entreprise_nom'] ?? 'OV-Gestion'),
                '<p>✅ La configuration SMTP fonctionne correctement.</p><p>Serveur : <strong>' . htmlspecialchars($params['smtp_host']) . ':' . htmlspecialchars($params['smtp_port'] ?? '587') . '</strong></p>');
            if ($res['ok']) {
                flash('success', '<i class="fa fa-check-circle me-1"></i>Email de test envoyé avec succès à <strong>' . htmlspecialchars($dest) . '</strong>.');
            } else {
                flash('danger', '<i class="fa fa-times-circle me-1"></i>Échec : ' . htmlspecialchars($res['error'] ?? 'erreur inconnue'));
            }
        }
        header('Location: index.php?tab=email'); exit;
    }

    if ($action === 'save_api') {
        $key = trim($_POST['anthropic_api_key'] ?? '');
        if ($key && strpos($key, 'sk-ant-api') !== 0) {
            flash('danger', 'Clé invalide — doit commencer par sk-ant-api...');
        } else {
            setParam('anthropic_api_key', $key);
            flash('success', $key ? 'Clé API Anthropic sauvegardée.' : 'Clé API supprimée.');
        }
        header('Location: index.php?tab=api'); exit;
    }

    if ($action === 'save_cotisations') {
        initCotisationsTable();
        foreach ($_POST['cotis'] ?? [] as $cId => $d) {
            $cat = in_array($d['categorie'] ?? '', ['secu_sociale','retraite','chomage','prevoyance','csg_crds','autres'])
                ? $d['categorie'] : 'autres';
            $db->prepare("UPDATE cotisations_taux SET libelle=?,categorie=?,taux_salarial=?,taux_patronal=?,actif=?,ordre=? WHERE id=?")
               ->execute([
                   trim($d['libelle'] ?? ''), $cat,
                   max(0, min(100, (float)($d['taux_salarial'] ?? 0))),
                   max(0, min(100, (float)($d['taux_patronal'] ?? 0))),
                   isset($d['actif']) ? 1 : 0,
                   (int)($d['ordre'] ?? 0),
                   (int)$cId,
               ]);
        }
        flash('success', 'Taux de cotisations mis à jour.');
        header('Location: index.php?tab=cotisations'); exit;
    }

    if ($action === 'add_cotisation') {
        initCotisationsTable();
        $libelle   = trim($_POST['new_libelle'] ?? '');
        $cat       = in_array($_POST['new_categorie'] ?? '', ['secu_sociale','retraite','chomage','prevoyance','csg_crds','autres'])
                     ? $_POST['new_categorie'] : 'autres';
        $tSal      = max(0, min(100, (float)($_POST['new_taux_salarial'] ?? 0)));
        $tPat      = max(0, min(100, (float)($_POST['new_taux_patronal'] ?? 0)));
        if ($libelle) {
            $maxOrdre = (int)$db->query("SELECT COALESCE(MAX(ordre),0) FROM cotisations_taux")->fetchColumn();
            $db->prepare("INSERT INTO cotisations_taux (libelle,categorie,taux_salarial,taux_patronal,actif,ordre) VALUES (?,?,?,?,1,?)")
               ->execute([$libelle, $cat, $tSal, $tPat, $maxOrdre + 1]);
            flash('success', 'Cotisation ajoutée.');
        }
        header('Location: index.php?tab=cotisations'); exit;
    }

    if ($action === 'del_cotisation') {
        $cId = (int)($_POST['cotis_id'] ?? 0);
        if ($cId) $db->prepare("DELETE FROM cotisations_taux WHERE id=?")->execute([$cId]);
        flash('success', 'Cotisation supprimée.');
        header('Location: index.php?tab=cotisations'); exit;
    }
}

$pageTitle    = 'Paramètres';
$currentModule = 'parametres';
require_once __DIR__ . '/../../includes/header.php';

$params = getAllParams();
$taux   = $db->query("SELECT * FROM taux_horaires ORDER BY ordre")->fetchAll();
$postes = $db->query("SELECT * FROM postes ORDER BY ordre, label")->fetchAll();
$feries = $db->query("SELECT * FROM jours_feries ORDER BY date")->fetchAll();
$carteChamps = $db->query("SELECT * FROM carte_champs ORDER BY face, ordre")->fetchAll();
$pdfChamps   = $db->query("SELECT * FROM pdf_champs ORDER BY ordre")->fetchAll();
try {
    initCotisationsTable();
    $cotisationsTaux = $db->query("SELECT * FROM cotisations_taux ORDER BY ordre,id")->fetchAll();
} catch (Exception $e) {
    $cotisationsTaux = [];
}
?>

<ul class="nav nav-tabs mb-4" style="border-bottom:2px solid #e5e7eb">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-entreprise">Entreprise</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-taux">Taux horaires</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-planning">Planning</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-feries" id="tab-feries-link">Jours fériés</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-carte">Carte agent</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-pdf">PDF comptable</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-champs-agents">Champs agents</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-email">Email</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-api">API</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-cotisations"><i class="fa fa-percent me-1" style="color:var(--ov-gold)"></i>Cotisations</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-postes"><i class="fa fa-briefcase me-1" style="color:var(--ov-gold)"></i>Postes</a></li>
</ul>

<div class="tab-content">

<!-- ENTREPRISE -->
<div class="tab-pane fade show active" id="tab-entreprise">
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-building me-2" style="color:var(--ov-gold)"></i>Informations entreprise</h2></div>
  <div class="ov-card-body">
    <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="action" value="save_entreprise">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label">Nom de l'entreprise</label>
        <input type="text" name="entreprise_nom" class="form-control" value="<?= h($params['entreprise_nom']??'') ?>"></div>
      <div class="col-md-6"><label class="form-label">Nom du dirigeant</label>
        <input type="text" name="entreprise_dirigeant" class="form-control" value="<?= h($params['entreprise_dirigeant']??'') ?>"></div>
      <div class="col-12"><label class="form-label">Adresse</label>
        <input type="text" name="entreprise_adresse" class="form-control" value="<?= h($params['entreprise_adresse']??'') ?>"></div>
      <div class="col-md-3"><label class="form-label">Code postal</label>
        <input type="text" name="entreprise_cp" class="form-control" value="<?= h($params['entreprise_cp']??'') ?>"></div>
      <div class="col-md-5"><label class="form-label">Ville</label>
        <input type="text" name="entreprise_ville" class="form-control" value="<?= h($params['entreprise_ville']??'') ?>"></div>
      <div class="col-md-4"><label class="form-label">N° SIRET</label>
        <input type="text" name="entreprise_siret" class="form-control" value="<?= h($params['entreprise_siret']??'') ?>"></div>
      <div class="col-md-4"><label class="form-label">Téléphone</label>
        <input type="text" name="entreprise_tel" class="form-control" value="<?= h($params['entreprise_tel']??'') ?>"></div>
      <div class="col-md-4"><label class="form-label">Email</label>
        <input type="email" name="entreprise_email" class="form-control" value="<?= h($params['entreprise_email']??'') ?>"></div>
      <div class="col-md-4">
        <label class="form-label">Logo</label>
        <div class="d-flex align-items-center gap-3 mb-2">
          <img src="<?= APP_URL ?>/assets/img/<?= h($params['logo_principal']??'logo.png') ?>" style="height:40px" onerror="this.src='<?= APP_URL ?>/assets/img/logo.png'">
          <span style="font-size:0.78rem;color:#9ca3af">Logo actuel</span>
        </div>
        <input type="file" name="logo" class="form-control form-control-sm" accept="image/*">
      </div>
      <div class="col-md-4">
        <label class="form-label">Signature du président <small class="text-muted">(apparaît dans les contrats)</small></label>
        <?php
          $sigFile = $params['signature_president'] ?? 'signature-Traore.JPG';
          $sigPath = APP_ROOT . '/uploads/photos/' . $sigFile;
          $sigUrl  = APP_URL  . '/uploads/photos/' . $sigFile;
        ?>
        <?php if (file_exists($sigPath)): ?>
        <div class="mb-2 p-2" style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;display:inline-block">
          <img src="<?= h($sigUrl) ?>" style="height:50px;max-width:200px;object-fit:contain;display:block">
        </div><br>
        <span class="text-success small"><i class="fa fa-check-circle me-1"></i><?= h($sigFile) ?></span><br>
        <?php else: ?>
        <div class="alert alert-warning py-1 mb-2 small">Fichier introuvable : <?= h($sigFile) ?></div>
        <?php endif; ?>
        <input type="file" name="signature_president" class="form-control form-control-sm mt-1" accept="image/jpeg,image/png">
        <div class="form-text">JPG ou PNG recommandé. Fond blanc ou transparent.</div>
      </div>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder</button></div>
    </form>
  </div>
</div>
</div>

<!-- TAUX -->
<div class="tab-pane fade" id="tab-taux">
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-euro-sign me-2" style="color:var(--ov-gold)"></i>Taux horaires (€/heure)</h2></div>
  <div class="ov-card-body">
    <p style="font-size:0.82rem;color:#6b7280" class="mb-3"><i class="fa fa-lightbulb me-1" style="color:var(--ov-gold)"></i>Modifiez le taux <strong>Heure normale</strong> — les autres seront mis à jour automatiquement selon les majorations légales. Vous pouvez ajuster chaque valeur avant de sauvegarder.</p>
    <form method="POST">
    <input type="hidden" name="action" value="save_taux">
    <div class="row g-3">
      <?php foreach ($taux as $t): ?>
      <div class="col-md-4">
        <label class="form-label"><?= h($t['label']) ?><?= $t['type_heure']==='normal' ? ' <span style="color:var(--ov-gold);font-size:0.75rem">★ base</span>' : '' ?></label>
        <div class="input-group">
          <input type="number" name="taux_<?= h($t['type_heure']) ?>" id="taux_<?= h($t['type_heure']) ?>"
                 class="form-control<?= $t['type_heure']==='normal' ? ' taux-base' : '' ?>"
                 style="<?= $t['type_heure']==='normal' ? 'border-color:var(--ov-gold);background:rgba(201,168,76,0.06);font-weight:700' : '' ?>"
                 step="0.01" min="0" value="<?= h($t['taux']) ?>">
          <span class="input-group-text">€/h</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder les taux</button></div>
    </form>
  </div>
</div>
</div>

<!-- PLANNING -->
<div class="tab-pane fade" id="tab-planning">
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-moon me-2" style="color:var(--ov-gold)"></i>Paramètres planning</h2></div>
  <div class="ov-card-body">
    <form method="POST">
    <input type="hidden" name="action" value="save_planning">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label">Début heures de nuit</label>
        <input type="time" name="nuit_debut" class="form-control" value="<?= h($params['nuit_debut']??'21:00') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Fin heures de nuit</label>
        <input type="time" name="nuit_fin" class="form-control" value="<?= h($params['nuit_fin']??'06:00') ?>">
        <div class="form-text">Les heures entre <?= h($params['nuit_debut']??'21:00') ?> et <?= h($params['nuit_fin']??'06:00') ?> sont comptées comme heures de nuit</div>
      </div>
      <div class="col-md-3">
        <label class="form-label">Validité lien agent (jours)</label>
        <input type="number" name="token_expiration_jours" class="form-control" min="1" max="30" value="<?= h($params['token_expiration_jours']??'7') ?>">
      </div>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder</button></div>
    </form>
  </div>
</div>
</div>

<!-- FERIES -->
<div class="tab-pane fade" id="tab-feries">
<div class="ov-card mb-3">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-calendar-xmark me-2" style="color:var(--ov-gold)"></i>Ajouter un jour férié</h2></div>
  <div class="ov-card-body">
    <form method="POST" class="row g-3 align-items-end">
    <input type="hidden" name="action" value="add_ferie">
    <div class="col-md-3"><label class="form-label">Date</label>
      <input type="date" name="ferie_date" class="form-control" required></div>
    <div class="col-md-5"><label class="form-label">Nom du jour férié</label>
      <input type="text" name="ferie_nom" class="form-control" required placeholder="Ex: Fête nationale"></div>
    <div class="col-md-2 d-flex align-items-center gap-2" style="margin-top:1.75rem">
      <input type="checkbox" name="ferie_recurrent" class="form-check-input" id="recurrent">
      <label class="form-check-label" for="recurrent">Récurrent</label>
    </div>
    <div class="col-md-2"><button type="submit" class="btn btn-ov-primary w-100"><i class="fa fa-plus me-1"></i>Ajouter</button></div>
    </form>
  </div>
</div>
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-list me-2" style="color:var(--ov-gold)"></i>Liste des jours fériés</h2></div>
  <div class="ov-card-body p-0">
    <table class="ov-table">
      <thead><tr><th>Date</th><th>Nom</th><th>Année</th><th>Récurrent</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($feries as $f): ?>
      <tr>
        <td><?= formatDate($f['date']) ?></td>
        <td><?= h($f['nom']) ?></td>
        <td><?= $f['annee'] ?: '—' ?></td>
        <td><?= $f['recurrent'] ? '<span style="color:#16a34a"><i class="fa fa-check"></i></span>' : '<span style="color:#d1d5db"><i class="fa fa-minus"></i></span>' ?></td>
        <td>
          <form method="POST" style="display:inline">
            <input type="hidden" name="action" value="del_ferie">
            <input type="hidden" name="ferie_id" value="<?= $f['id'] ?>">
            <button class="btn-sm-icon delete" data-confirm="Supprimer ce jour férié ?"><i class="fa fa-trash"></i></button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<!-- CARTE AGENT -->
<div class="tab-pane fade" id="tab-carte">

<!-- Textes du badge -->
<div class="ov-card mb-3">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-pen-to-square me-2" style="color:var(--ov-gold)"></i>Textes du badge</h2></div>
  <div class="ov-card-body">
    <form method="POST">
    <input type="hidden" name="action" value="save_carte_textes">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Slogan entreprise</label>
        <input type="text" name="entreprise_slogan" class="form-control" value="<?= h($params['entreprise_slogan'] ?? '') ?>" placeholder="Ex : VOTRE SÉCURITÉ, NOTRE PRIORITÉ">
        <div class="form-text">Affiché sous le logo sur le badge</div>
      </div>
      <div class="col-md-6">
        <label class="form-label">N° autorisation CNAPS entreprise</label>
        <input type="text" name="entreprise_cnaps" class="form-control" value="<?= h($params['entreprise_cnaps'] ?? '') ?>" placeholder="AUT-XXX-XXXX-XX-XX-XXXXXXXXXXXXXXX">
        <div class="form-text">Affiché dans le pied du badge (commun à tous les agents)</div>
      </div>
      <div class="col-12">
        <label class="form-label">Mention légale badge</label>
        <textarea name="carte_mention_legale" class="form-control" rows="2" placeholder="L'autorisation d'exercice ne confère..."><?= h($params['carte_mention_legale'] ?? '') ?></textarea>
        <div class="form-text">Texte affiché en bas du badge sous le n° CNAPS</div>
      </div>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder</button></div>
    </form>
  </div>
</div>

<!-- Champs visibilité -->
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-id-card me-2" style="color:var(--ov-gold)"></i>Champs visibles sur le badge</h2></div>
  <div class="ov-card-body">
    <form method="POST">
    <input type="hidden" name="action" value="save_carte">
    <div class="table-responsive">
    <table class="ov-table">
      <thead><tr><th>Actif</th><th>Champ</th><th>Face</th><th>Ordre</th></tr></thead>
      <tbody>
      <?php foreach ($carteChamps as $c): ?>
      <tr>
        <td><input type="checkbox" name="champ[<?= $c['id'] ?>][actif]" <?= $c['actif']?'checked':'' ?> class="form-check-input"></td>
        <td><?= h($c['label']) ?> <small class="text-muted">(<?= h($c['source']) ?>)</small></td>
        <td>
          <select name="champ[<?= $c['id'] ?>][face]" class="form-select form-select-sm" style="width:100px">
            <option value="recto" <?= $c['face']==='recto'?'selected':'' ?>>Recto</option>
            <option value="verso" <?= $c['face']==='verso'?'selected':'' ?>>Verso</option>
          </select>
        </td>
        <td><input type="number" name="champ[<?= $c['id'] ?>][ordre]" class="form-control form-control-sm" style="width:70px" value="<?= $c['ordre'] ?>"></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder</button></div>
    </form>
  </div>
</div>
</div>

<!-- PDF COMPTABLE -->
<div class="tab-pane fade" id="tab-pdf">
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-file-pdf me-2" style="color:var(--ov-gold)"></i>Champs PDF comptable</h2></div>
  <div class="ov-card-body">
    <form method="POST">
    <input type="hidden" name="action" value="save_pdf">
    <div class="table-responsive">
    <table class="ov-table">
      <thead><tr><th>Actif</th><th>Champ</th><th>Source</th><th>Ordre</th></tr></thead>
      <tbody>
      <?php foreach ($pdfChamps as $c): ?>
      <tr>
        <td><input type="checkbox" name="champ[<?= $c['id'] ?>][actif]" <?= $c['actif']?'checked':'' ?> class="form-check-input"></td>
        <td><?= h($c['label']) ?></td>
        <td><span class="badge-ov" style="background:rgba(107,114,128,0.1);color:#6b7280;padding:2px 8px;border-radius:20px;font-size:0.72rem"><?= h($c['source']) ?></span></td>
        <td><input type="number" name="champ[<?= $c['id'] ?>][ordre]" class="form-control form-control-sm" style="width:70px" value="<?= $c['ordre'] ?>"></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder</button></div>
    </form>
  </div>
</div>
</div>

<!-- CHAMPS AGENTS PERSONNALISÉS -->
<div class="tab-pane fade" id="tab-champs-agents">

<div class="ov-card mb-3">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-plus-circle me-2" style="color:var(--ov-gold)"></i>Ajouter un champ</h2></div>
  <div class="ov-card-body">
    <form method="POST">
    <input type="hidden" name="action" value="add_champ_perso">
    <div class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label">Label du champ</label>
        <input type="text" name="cp_label" class="form-control" required placeholder="Ex : Formation SST, Véhicule...">
      </div>
      <div class="col-md-2">
        <label class="form-label">Type</label>
        <select name="cp_type" class="form-select" id="cpTypeSelect">
          <option value="text">Texte court</option>
          <option value="textarea">Texte long</option>
          <option value="date">Date</option>
          <option value="select">Liste déroulante</option>
          <option value="file">Fichier / Document</option>
        </select>
      </div>
      <div class="col-md-2">
        <div class="form-check" style="margin-top:1.75rem">
          <input class="form-check-input" type="checkbox" name="cp_obligatoire" id="cpOblig">
          <label class="form-check-label" for="cpOblig">Obligatoire</label>
        </div>
      </div>
      <div class="col-md-4" id="cpOptionsBlock" style="display:none">
        <label class="form-label">Options <small class="text-muted">(une par ligne)</small></label>
        <textarea name="cp_options" class="form-control form-control-sm" rows="3" placeholder="Option A&#10;Option B&#10;Option C"></textarea>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-ov-primary"><i class="fa fa-plus me-1"></i>Ajouter</button>
      </div>
    </div>
    </form>
  </div>
</div>

<?php
try {
    $champsPerso = $db->query("SELECT * FROM agent_champs_perso ORDER BY ordre, id")->fetchAll();
} catch(Exception $e) {
    $champsPerso = null;
}
?>

<?php if ($champsPerso === null): ?>
<div class="alert alert-warning"><i class="fa fa-triangle-exclamation me-1"></i>Les tables ne sont pas encore créées. <a href="<?= APP_URL ?>/migrate_champs_agents.php" target="_blank">Lancer la migration</a></div>

<?php elseif ($champsPerso): ?>
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-sliders me-2" style="color:var(--ov-gold)"></i>Champs existants</h2></div>
  <div class="ov-card-body">
    <form method="POST" id="formSaveChamps">
    <input type="hidden" name="action" value="save_champs_perso">
    <div class="table-responsive">
    <table class="ov-table">
      <thead><tr><th>Actif</th><th>Label</th><th>Type</th><th>Obligatoire</th><th>Ordre</th><th>Supprimer</th></tr></thead>
      <tbody>
      <?php foreach ($champsPerso as $cp): ?>
      <tr>
        <td><input type="checkbox" name="cp[<?= $cp['id'] ?>][actif]" <?= $cp['actif']?'checked':'' ?> class="form-check-input"></td>
        <td><input type="text" name="cp[<?= $cp['id'] ?>][label]" class="form-control form-control-sm" value="<?= h($cp['label']) ?>" style="min-width:150px"></td>
        <td>
          <span style="background:rgba(107,114,128,0.1);color:#6b7280;padding:2px 8px;border-radius:20px;font-size:0.72rem;display:inline-block">
            <?= ['text'=>'Texte','textarea'=>'Texte long','date'=>'Date','select'=>'Liste','file'=>'Fichier'][$cp['type']] ?? $cp['type'] ?>
          </span>
        </td>
        <td><input type="checkbox" name="cp[<?= $cp['id'] ?>][obligatoire]" <?= $cp['obligatoire']?'checked':'' ?> class="form-check-input"></td>
        <td><input type="number" name="cp[<?= $cp['id'] ?>][ordre]" class="form-control form-control-sm" style="width:70px" value="<?= $cp['ordre'] ?>"></td>
        <td>
          <button type="submit" form="delChampForm<?= $cp['id'] ?>" class="btn-sm-icon delete"
            data-confirm="Supprimer «<?= h($cp['label']) ?>» et toutes ses valeurs ?"><i class="fa fa-trash"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder</button></div>
    </form>

    <?php foreach ($champsPerso as $cp): ?>
    <form id="delChampForm<?= $cp['id'] ?>" method="POST" style="display:none">
      <input type="hidden" name="action" value="del_champ_perso">
      <input type="hidden" name="cp_id" value="<?= $cp['id'] ?>">
    </form>
    <?php endforeach; ?>
  </div>
</div>

<?php else: ?>
<div class="ov-card">
  <div class="ov-card-body text-center text-muted py-4">
    <i class="fa fa-sliders fa-2x mb-2 d-block" style="opacity:0.3"></i>
    Aucun champ personnalisé. Utilisez le formulaire ci-dessus pour en créer.
  </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var cpType = document.getElementById('cpTypeSelect');
    if (cpType) cpType.addEventListener('change', function() {
        document.getElementById('cpOptionsBlock').style.display = this.value === 'select' ? '' : 'none';
    });
});
</script>
</div>

<!-- EMAIL / SMTP -->
<div class="tab-pane fade" id="tab-email">
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-envelope me-2" style="color:var(--ov-gold)"></i>Configuration email (SMTP)</h2></div>
  <div class="ov-card-body">
    <p class="text-muted small mb-3">
      Utilisé pour l'envoi des liens de signature électronique et des notifications aux agents.<br>
      Laissez <strong>Serveur SMTP</strong> vide pour utiliser la fonction <code>mail()</code> native du serveur (déconseillé en production).
    </p>
    <form method="POST">
    <input type="hidden" name="action" value="save_smtp">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label">Serveur SMTP</label>
        <input type="text" name="smtp_host" class="form-control" placeholder="smtp.gmail.com" value="<?= h($params['smtp_host']??'') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Port</label>
        <input type="number" name="smtp_port" class="form-control" placeholder="587" min="1" max="65535" value="<?= h($params['smtp_port']??'587') ?>">
        <div class="form-text">587 = TLS · 465 = SSL</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Adresse expéditeur (From)</label>
        <input type="email" name="smtp_from" class="form-control" placeholder="noreply@votredomaine.fr" value="<?= h($params['smtp_from']??'') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Identifiant SMTP</label>
        <input type="text" name="smtp_user" class="form-control" placeholder="votre@email.com" value="<?= h($params['smtp_user']??'') ?>" autocomplete="off">
      </div>
      <div class="col-md-6">
        <label class="form-label">Mot de passe SMTP</label>
        <input type="password" name="smtp_pass" class="form-control font-monospace" placeholder="••••••••" value="<?= h($params['smtp_pass']??'') ?>" autocomplete="new-password">
        <div class="form-text">Pour Gmail : utilisez un <a href="https://myaccount.google.com/apppasswords" target="_blank">mot de passe d'application</a> (compte avec 2FA activé).</div>
      </div>
      <div class="col-md-6">
        <label class="form-label"><i class="fa fa-bell me-1" style="color:var(--ov-gold)"></i>Email de notification signature <small class="text-muted">(optionnel)</small></label>
        <input type="email" name="notification_signature_email" class="form-control" placeholder="rh@votreentreprise.fr" value="<?= h($params['notification_signature_email']??'') ?>">
        <div class="form-text">Reçoit un email dès qu'un agent signe son contrat. Si vide, utilise l'adresse expéditeur.</div>
      </div>
    </div>
    <?php if (!empty($params['smtp_host'])): ?>
    <div class="alert alert-success py-2 mt-3 small">
      <i class="fa fa-check-circle me-1"></i>
      SMTP configuré sur <strong><?= h($params['smtp_host']) ?>:<?= h($params['smtp_port']??'587') ?></strong>
      — expéditeur : <strong><?= h($params['smtp_from']??'—') ?></strong>
    </div>
    <?php else: ?>
    <div class="alert alert-warning py-2 mt-3 small">
      <i class="fa fa-exclamation-triangle me-1"></i>
      Aucun serveur SMTP configuré — les emails de signature ne seront pas envoyés.
    </div>
    <?php endif; ?>
    <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder</button></div>
    </form>

    <hr class="my-4">
    <h6 class="mb-2"><i class="fa fa-paper-plane me-1 text-warning"></i>Tester la configuration</h6>
    <form method="POST" class="d-flex gap-2 align-items-end">
      <input type="hidden" name="action" value="test_smtp">
      <div>
        <label class="form-label small mb-1">Envoyer un email de test à</label>
        <input type="email" name="test_email" class="form-control" placeholder="votre@email.com" style="width:260px" required>
      </div>
      <button type="submit" class="btn btn-outline-secondary"><i class="fa fa-vial me-1"></i>Tester</button>
    </form>
  </div>
</div>
</div>

<!-- API -->
<div class="tab-pane fade" id="tab-api">
<div class="ov-card">
  <div class="ov-card-body">
    <h5 class="mb-3"><i class="fa fa-key me-2 text-warning"></i>Clé API Anthropic</h5>
    <p class="text-muted small mb-3">
      Utilisée pour la fonctionnalité <strong>Analyse 4 experts</strong> sur les contrats agents.<br>
      Obtenez votre clé sur <code>console.anthropic.com</code> → <em>API Keys</em>.<br>
      Format attendu : <code>sk-ant-api03-...</code>
    </p>
    <form method="post">
      <input type="hidden" name="action" value="save_api">
      <div class="mb-3">
        <label class="form-label fw-semibold">Clé API Anthropic</label>
        <?php $apiKey = $params['anthropic_api_key'] ?? ''; ?>
        <?php if ($apiKey): ?>
          <div class="alert alert-success py-2 mb-2 small">
            <i class="fa fa-check-circle me-1"></i>
            Clé configurée : <code><?= h(substr($apiKey,0,20)) ?>...<?= h(substr($apiKey,-4)) ?></code>
          </div>
        <?php else: ?>
          <div class="alert alert-warning py-2 mb-2 small">
            <i class="fa fa-exclamation-triangle me-1"></i>
            Aucune clé configurée — l'analyse 4 experts est désactivée.
          </div>
        <?php endif; ?>
        <input type="password" name="anthropic_api_key" class="form-control font-monospace"
               placeholder="sk-ant-api03-..."
               value="<?= h($apiKey) ?>" autocomplete="off">
        <div class="form-text">Laissez vide pour supprimer la clé.</div>
      </div>
      <?php if (canDo('parametres','edit')): ?>
      <button type="submit" class="btn btn-ov-primary">
        <i class="fa fa-save me-1"></i>Enregistrer
      </button>
      <?php endif; ?>
    </form>
  </div>
</div>
</div>

<!-- COTISATIONS SOCIALES -->
<div class="tab-pane fade" id="tab-cotisations">

<div class="ov-card mb-3">
  <div class="ov-card-header">
    <h2 class="ov-card-title"><i class="fa fa-percent me-2" style="color:var(--ov-gold)"></i>Taux de cotisations sociales</h2>
  </div>
  <div class="ov-card-body">
    <p class="text-muted small mb-3">
      Taux pré-remplis selon la convention collective de la sécurité privée <strong>(IDCC 1351)</strong>.<br>
      Ces taux alimentent le calcul du <strong>Brut → Net</strong> (cotisations salariales) et du <strong>Coût employeur</strong> (cotisations patronales) dans les exports XLS.
      À adapter si votre situation diffère (taille entreprise, taux AT/MP, mutuelle…).
    </p>

    <?php
    $catLabels = ['secu_sociale'=>'Séc. sociale','retraite'=>'Retraite','chomage'=>'Chômage','prevoyance'=>'Prévoyance','csg_crds'=>'CSG/CRDS','autres'=>'Autres'];
    $catColors = ['secu_sociale'=>'#3b82f6','retraite'=>'#8b5cf6','chomage'=>'#f59e0b','prevoyance'=>'#10b981','csg_crds'=>'#ef4444','autres'=>'#6b7280'];
    $tSalActif = array_sum(array_column(array_filter($cotisationsTaux, fn($c)=>$c['actif']), 'taux_salarial'));
    $tPatActif = array_sum(array_column(array_filter($cotisationsTaux, fn($c)=>$c['actif']), 'taux_patronal'));
    ?>

    <!-- Totaux affichés -->
    <div class="d-flex gap-3 mb-3 flex-wrap">
      <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 16px;min-width:160px">
        <div style="font-size:0.72rem;color:#ef4444;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Cotisations salariales</div>
        <div style="font-size:1.5rem;font-weight:700;color:#dc2626"><?= number_format($tSalActif, 2) ?> %</div>
        <div style="font-size:0.72rem;color:#9ca3af">déduites du brut → net</div>
      </div>
      <div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:8px 16px;min-width:160px">
        <div style="font-size:0.72rem;color:#f97316;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Cotisations patronales</div>
        <div style="font-size:1.5rem;font-weight:700;color:#ea580c"><?= number_format($tPatActif, 2) ?> %</div>
        <div style="font-size:0.72rem;color:#9ca3af">ajoutées au brut → coût total</div>
      </div>
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:8px 16px;min-width:160px">
        <div style="font-size:0.72rem;color:#16a34a;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Charge totale entreprise</div>
        <div style="font-size:1.5rem;font-weight:700;color:#15803d"><?= number_format(100 + $tPatActif, 2) ?> %</div>
        <div style="font-size:0.72rem;color:#9ca3af">du salaire brut</div>
      </div>
    </div>

    <form method="POST">
    <input type="hidden" name="action" value="save_cotisations">
    <div class="table-responsive">
    <table class="ov-table">
      <thead>
        <tr>
          <th style="width:40px">Actif</th>
          <th>Libellé</th>
          <th style="width:130px">Catégorie</th>
          <th style="width:120px">Taux salarial</th>
          <th style="width:120px">Taux patronal</th>
          <th style="width:70px">Ordre</th>
          <th style="width:40px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($cotisationsTaux as $c): ?>
      <tr <?= !$c['actif'] ? 'style="opacity:0.5"' : '' ?>>
        <td><input type="checkbox" name="cotis[<?= $c['id'] ?>][actif]" <?= $c['actif']?'checked':'' ?> class="form-check-input"></td>
        <td><input type="text" name="cotis[<?= $c['id'] ?>][libelle]" class="form-control form-control-sm" value="<?= h($c['libelle']) ?>" style="min-width:200px"></td>
        <td>
          <select name="cotis[<?= $c['id'] ?>][categorie]" class="form-select form-select-sm">
            <?php foreach ($catLabels as $k => $l): ?>
            <option value="<?= $k ?>" <?= $c['categorie']===$k?'selected':'' ?>
                    style="color:<?= $catColors[$k] ?? '#6b7280' ?>"><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <td>
          <div class="input-group input-group-sm">
            <input type="number" name="cotis[<?= $c['id'] ?>][taux_salarial]" class="form-control"
                   step="0.001" min="0" max="100" value="<?= h($c['taux_salarial']) ?>">
            <span class="input-group-text">%</span>
          </div>
        </td>
        <td>
          <div class="input-group input-group-sm">
            <input type="number" name="cotis[<?= $c['id'] ?>][taux_patronal]" class="form-control"
                   step="0.001" min="0" max="100" value="<?= h($c['taux_patronal']) ?>">
            <span class="input-group-text">%</span>
          </div>
        </td>
        <td><input type="number" name="cotis[<?= $c['id'] ?>][ordre]" class="form-control form-control-sm" style="width:60px" value="<?= $c['ordre'] ?>"></td>
        <td>
          <button type="submit" form="delCotisForm<?= $c['id'] ?>" class="btn-sm-icon delete"
                  data-confirm="Supprimer «<?= h($c['libelle']) ?>» ?"><i class="fa fa-trash"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="mt-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder les taux</button></div>
    </form>

    <?php foreach ($cotisationsTaux as $c): ?>
    <form id="delCotisForm<?= $c['id'] ?>" method="POST" style="display:none">
      <input type="hidden" name="action" value="del_cotisation">
      <input type="hidden" name="cotis_id" value="<?= $c['id'] ?>">
    </form>
    <?php endforeach; ?>
  </div>
</div>

<!-- Ajouter une cotisation -->
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-plus me-2" style="color:var(--ov-gold)"></i>Ajouter une cotisation</h2></div>
  <div class="ov-card-body">
    <form method="POST">
    <input type="hidden" name="action" value="add_cotisation">
    <div class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label">Libellé</label>
        <input type="text" name="new_libelle" class="form-control" required placeholder="Ex : Mutuelle entreprise">
      </div>
      <div class="col-md-2">
        <label class="form-label">Catégorie</label>
        <select name="new_categorie" class="form-select">
          <?php foreach ($catLabels as $k => $l): ?>
          <option value="<?= $k ?>"><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Taux salarial</label>
        <div class="input-group">
          <input type="number" name="new_taux_salarial" class="form-control" step="0.001" min="0" max="100" value="0.000" required>
          <span class="input-group-text">%</span>
        </div>
      </div>
      <div class="col-md-2">
        <label class="form-label">Taux patronal</label>
        <div class="input-group">
          <input type="number" name="new_taux_patronal" class="form-control" step="0.001" min="0" max="100" value="0.000" required>
          <span class="input-group-text">%</span>
        </div>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-ov-primary"><i class="fa fa-plus me-1"></i>Ajouter</button>
      </div>
    </div>
    </form>
  </div>
</div>

</div><!-- /tab-cotisations -->

<!-- POSTES -->
<div class="tab-pane fade" id="tab-postes">

<div class="ov-card mb-3">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-plus me-2" style="color:var(--ov-gold)"></i>Ajouter un poste</h2></div>
  <div class="ov-card-body">
    <p style="font-size:0.82rem;color:#6b7280" class="mb-3">
      <i class="fa fa-lightbulb me-1" style="color:var(--ov-gold)"></i>
      Configurez la grille des postes (convention collective). Le taux horaire sera auto-rempli lors du choix du poste dans la fiche agent.
    </p>
    <form method="POST">
    <input type="hidden" name="action" value="add_poste">
    <div class="row g-3 align-items-end">
      <div class="col-md-5">
        <label class="form-label">Intitulé du poste</label>
        <input type="text" name="poste_label" class="form-control" required placeholder="Ex : Employé - Niveau III - Échelon 2 - Coefficient 140">
      </div>
      <div class="col-md-2">
        <label class="form-label">Coefficient <small class="text-muted">(optionnel)</small></label>
        <input type="number" name="poste_coefficient" class="form-control" min="0" step="1" placeholder="Ex : 140">
      </div>
      <div class="col-md-3">
        <label class="form-label">Taux horaire (€/h)</label>
        <div class="input-group">
          <input type="number" name="poste_taux" class="form-control" step="0.0001" min="0" placeholder="12.0000" required>
          <span class="input-group-text">€/h</span>
        </div>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-ov-primary"><i class="fa fa-plus me-1"></i>Ajouter</button>
      </div>
    </div>
    </form>
  </div>
</div>

<?php if ($postes): ?>
<div class="ov-card">
  <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-briefcase me-2" style="color:var(--ov-gold)"></i>Grille des postes</h2></div>
  <div class="ov-card-body p-0">
    <form method="POST" id="formSavePostes">
    <input type="hidden" name="action" value="save_postes">
    <div class="table-responsive">
    <table class="ov-table">
      <thead>
        <tr>
          <th style="width:40px">Actif</th>
          <th>Intitulé du poste</th>
          <th style="width:110px">Coefficient</th>
          <th style="width:160px">Taux (€/h)</th>
          <th style="width:40px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($postes as $p): ?>
      <tr <?= !$p['actif'] ? 'style="opacity:0.5"' : '' ?>>
        <td><input type="checkbox" name="postes[<?= $p['id'] ?>][actif]" <?= $p['actif']?'checked':'' ?> class="form-check-input"></td>
        <td><input type="text" name="postes[<?= $p['id'] ?>][label]" class="form-control form-control-sm" value="<?= h($p['label']) ?>" style="min-width:280px"></td>
        <td><input type="number" name="postes[<?= $p['id'] ?>][coefficient]" class="form-control form-control-sm" min="0" step="1" value="<?= h($p['coefficient'] ?? '') ?>" placeholder="—"></td>
        <td>
          <div class="input-group input-group-sm">
            <input type="number" name="postes[<?= $p['id'] ?>][taux_horaire]" class="form-control" step="0.0001" min="0" value="<?= h($p['taux_horaire']) ?>">
            <span class="input-group-text">€/h</span>
          </div>
        </td>
        <td>
          <button type="submit" form="delPosteForm<?= $p['id'] ?>" class="btn-sm-icon delete"
                  data-confirm="Supprimer le poste «<?= addslashes($p['label']) ?>» ?"><i class="fa fa-trash"></i></button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="p-3"><button type="submit" class="btn btn-ov-primary"><i class="fa fa-save me-2"></i>Sauvegarder la grille</button></div>
    </form>

    <?php foreach ($postes as $p): ?>
    <form id="delPosteForm<?= $p['id'] ?>" method="POST" style="display:none">
      <input type="hidden" name="action" value="del_poste">
      <input type="hidden" name="poste_id" value="<?= $p['id'] ?>">
    </form>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="ov-card">
  <div class="ov-card-body text-center text-muted py-4">
    <i class="fa fa-briefcase fa-2x mb-2 d-block" style="opacity:0.3"></i>
    Aucun poste configuré. Utilisez le formulaire ci-dessus pour en créer.
  </div>
</div>
<?php endif; ?>

</div><!-- /tab-postes -->

</div><!-- /tab-content -->

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Activation de l'onglet via ?tab= dans l'URL
    var urlTab = new URLSearchParams(window.location.search).get('tab');
    if (urlTab) {
        var el = document.querySelector('[href="#tab-' + urlTab + '"]');
        if (el) bootstrap.Tab.getOrCreateInstance(el).show();
    }

    // Auto-suggestion des taux : quand "heure normale" change, proposer les autres
    var baseField = document.getElementById('taux_normal');
    if (baseField) {
        var coeffs = { nuit: 1.2, dimanche: 1.5, ferie_normal: 2, ferie_dimanche: 2, ferie_nuit: 2 };
        baseField.addEventListener('input', function() {
            var base = parseFloat(this.value) || 0;
            Object.entries(coeffs).forEach(function(e) {
                var field = document.getElementById('taux_' + e[0]);
                if (field) {
                    field.value = (base * e[1]).toFixed(2);
                    field.style.borderColor = 'var(--ov-gold)';
                    field.style.background  = 'rgba(201,168,76,0.06)';
                }
            });
        });
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
