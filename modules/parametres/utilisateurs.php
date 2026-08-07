<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requirePerm('utilisateurs', 'view');
ensurePlanificateurRole();

$db = getDB();

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canDo('utilisateurs','create')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $nom    = trim($_POST['nom'] ?? '');
        $prenom = trim($_POST['prenom'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $pass   = $_POST['password'] ?? '';
        $roleId = (int)($_POST['role_id'] ?? 2);
        if ($nom && $email && $pass) {
            try {
                $db->prepare("INSERT INTO utilisateurs (nom,prenom,email,password,role_id) VALUES (?,?,?,?,?)")
                   ->execute([$nom,$prenom,$email,password_hash($pass,PASSWORD_DEFAULT),$roleId]);
                flash('success','Utilisateur créé avec succès.');
            } catch (Exception $e) { flash('danger','Cet email existe déjà.'); }
        }
        header('Location: utilisateurs.php'); exit;
    }

    if ($action === 'toggle_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid && $uid !== getCurrentUser()['id']) {
            $db->prepare("UPDATE utilisateurs SET actif = NOT actif WHERE id=?")->execute([$uid]);
        }
        header('Location: utilisateurs.php'); exit;
    }

    if ($action === 'change_password') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $pass = $_POST['new_password'] ?? '';
        if ($uid && strlen($pass) >= 6) {
            $db->prepare("UPDATE utilisateurs SET password=? WHERE id=?")->execute([password_hash($pass,PASSWORD_DEFAULT),$uid]);
            flash('success','Mot de passe mis à jour.');
        }
        header('Location: utilisateurs.php'); exit;
    }

    if ($action === 'add_role') {
        requirePerm('utilisateurs','edit');
        $nomRole = trim($_POST['nom_role'] ?? '');
        $slug    = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $nomRole)));
        if ($nomRole && $slug) {
            try {
                $db->prepare("INSERT INTO roles (nom, slug) VALUES (?,?)")->execute([$nomRole, $slug]);
                flash('success', 'Rôle « ' . h($nomRole) . ' » créé — configurez ses permissions ci-dessous.');
            } catch (Exception $e) { flash('danger', 'Ce rôle existe déjà.'); }
        } else {
            flash('danger', 'Nom de rôle invalide.');
        }
        header('Location: utilisateurs.php'); exit;
    }

    if ($action === 'save_perms') {
        requirePerm('utilisateurs','edit');
        $roleId  = (int)($_POST['role_id'] ?? 0);
        $modules = ['dashboard','agents','missions','planning','salaires','clients','devis','parametres','rapports','utilisateurs'];
        foreach ($modules as $module) {
            $perms = $_POST['perms'][$module] ?? [];
            $db->prepare("INSERT INTO role_permissions (role_id,module,can_view,can_create,can_edit,can_delete,can_export)
                          VALUES (?,?,?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE can_view=VALUES(can_view),can_create=VALUES(can_create),
                          can_edit=VALUES(can_edit),can_delete=VALUES(can_delete),can_export=VALUES(can_export)")
               ->execute([
                   $roleId, $module,
                   in_array('view',$perms)?1:0,
                   in_array('create',$perms)?1:0,
                   in_array('edit',$perms)?1:0,
                   in_array('delete',$perms)?1:0,
                   in_array('export',$perms)?1:0,
               ]);
        }
        flash('success','Permissions mises à jour.');
        header('Location: utilisateurs.php'); exit;
    }
}

$pageTitle    = 'Utilisateurs & Rôles';
$currentModule = 'utilisateurs';
require_once __DIR__ . '/../../includes/header.php';

$users = $db->query("SELECT u.*, r.nom as role_nom FROM utilisateurs u JOIN roles r ON r.id=u.role_id ORDER BY u.nom")->fetchAll();
$roles = $db->query("SELECT * FROM roles WHERE slug != 'agent' ORDER BY id")->fetchAll();
$modules = ['dashboard','agents','missions','planning','salaires','clients','devis','parametres','rapports','utilisateurs'];
$actions = ['view'=>'Voir','create'=>'Créer','edit'=>'Modifier','delete'=>'Supprimer','export'=>'Exporter'];

// Permissions par rôle
$permsParRole = [];
foreach ($roles as $r) {
    $stmtP = $db->prepare("SELECT * FROM role_permissions WHERE role_id=?");
    $stmtP->execute([$r['id']]);
    foreach ($stmtP->fetchAll() as $p) {
        $permsParRole[$r['id']][$p['module']] = $p;
    }
}
?>

<div class="row g-3">

<div class="col-lg-5">
  <div class="ov-card mb-3">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-user-plus me-2" style="color:var(--ov-gold)"></i>Ajouter un utilisateur</h2></div>
    <div class="ov-card-body">
      <form method="POST">
      <input type="hidden" name="action" value="add_user">
      <div class="row g-2">
        <div class="col-6"><label class="form-label">Nom</label><input type="text" name="nom" class="form-control form-control-sm" required></div>
        <div class="col-6"><label class="form-label">Prénom</label><input type="text" name="prenom" class="form-control form-control-sm"></div>
        <div class="col-12"><label class="form-label">Email</label><input type="email" name="email" class="form-control form-control-sm" required></div>
        <div class="col-12"><label class="form-label">Mot de passe</label><input type="password" name="password" class="form-control form-control-sm" required minlength="6"></div>
        <div class="col-12"><label class="form-label">Rôle</label>
          <select name="role_id" class="form-select form-select-sm">
            <?php foreach ($roles as $r): ?>
            <option value="<?= $r['id'] ?>"><?= h($r['nom']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="mt-3"><button type="submit" class="btn btn-ov-primary btn-sm w-100"><i class="fa fa-plus me-1"></i>Créer</button></div>
      </form>
    </div>
  </div>

  <div class="ov-card">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-users me-2" style="color:var(--ov-gold)"></i>Utilisateurs</h2></div>
    <div class="ov-card-body p-0">
      <?php foreach ($users as $u): ?>
      <div class="d-flex align-items-center gap-2 px-3 py-2" style="border-bottom:1px solid #f0f2f5">
        <div style="width:34px;height:34px;border-radius:50%;background:rgba(201,168,76,0.12);display:flex;align-items:center;justify-content:center;color:var(--ov-gold);font-weight:700;font-size:0.78rem;flex-shrink:0">
          <?= strtoupper(substr($u['prenom'],0,1).substr($u['nom'],0,1)) ?>
        </div>
        <div class="flex-grow-1">
          <div style="font-size:0.875rem;font-weight:600"><?= h($u['prenom'].' '.$u['nom']) ?></div>
          <div style="font-size:0.72rem;color:#9ca3af"><?= h($u['email']) ?> · <?= h($u['role_nom']) ?></div>
        </div>
        <form method="POST" class="d-inline">
          <input type="hidden" name="action" value="toggle_user">
          <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
          <button class="btn-sm-icon <?= $u['actif'] ? 'edit' : 'view' ?>" title="<?= $u['actif']?'Désactiver':'Activer' ?>">
            <i class="fa fa-<?= $u['actif'] ? 'toggle-on' : 'toggle-off' ?>"></i>
          </button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="col-lg-7">
  <div class="ov-card">
    <div class="ov-card-header"><h2 class="ov-card-title"><i class="fa fa-shield-halved me-2" style="color:var(--ov-gold)"></i>Permissions par rôle</h2></div>
    <div class="ov-card-body">
      <?php if (canDo('utilisateurs','edit')): ?>
      <form method="POST" class="d-flex gap-2 align-items-end mb-3 pb-3" style="border-bottom:1px solid #f0f2f5">
        <input type="hidden" name="action" value="add_role">
        <div class="flex-grow-1">
          <label class="form-label" style="font-size:0.8rem">Nouveau rôle</label>
          <input type="text" name="nom_role" class="form-control form-control-sm" placeholder="Ex : Planificateur" required>
        </div>
        <button type="submit" class="btn btn-ov-secondary btn-sm"><i class="fa fa-plus me-1"></i>Créer le rôle</button>
      </form>
      <?php endif; ?>
      <?php foreach ($roles as $r):
        if ($r['slug'] === 'admin') continue; // Admin a tout, pas configurable
      ?>
      <details class="mb-3" <?= $r['slug']==='manager'?'open':'' ?>>
        <summary style="cursor:pointer;font-weight:600;padding:8px;background:#f8f9fa;border-radius:6px;list-style:none;display:flex;justify-content:space-between">
          <span><i class="fa fa-users-gear me-2" style="color:var(--ov-gold)"></i><?= h($r['nom']) ?></span>
          <i class="fa fa-chevron-down"></i>
        </summary>
        <form method="POST" class="mt-2">
          <input type="hidden" name="action" value="save_perms">
          <input type="hidden" name="role_id" value="<?= $r['id'] ?>">
          <div class="table-responsive">
          <table class="ov-table" style="font-size:0.78rem">
            <thead><tr>
              <th>Module</th>
              <?php foreach ($actions as $k=>$v): ?><th class="text-center"><?= $v ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($modules as $mod): ?>
            <?php $rp = $permsParRole[$r['id']][$mod] ?? []; ?>
            <tr>
              <td class="fw-600"><?= ucfirst($mod) ?></td>
              <?php foreach ($actions as $k=>$v): ?>
              <td class="text-center">
                <input type="checkbox" name="perms[<?= $mod ?>][]" value="<?= $k ?>" class="form-check-input"
                       <?= ($rp['can_'.$k]??0)?'checked':'' ?>>
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
          <button type="submit" class="btn btn-ov-primary btn-sm mt-2"><i class="fa fa-save me-1"></i>Sauvegarder permissions <?= h($r['nom']) ?></button>
        </form>
      </details>
      <?php endforeach; ?>
      <div class="alert alert-info py-2 mb-0" style="font-size:0.8rem">
        <i class="fa fa-info-circle me-1"></i>L'Administrateur a accès à tout par défaut et ne peut pas être restreint.
      </div>
    </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
