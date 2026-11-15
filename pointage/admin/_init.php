<?php
/**
 * Espace responsable de l'outil Pointage.
 * Connexion avec les comptes utilisateurs OV existants, mais session propre
 * à l'outil (indépendante du back-office).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_layout.php';

function ptAdminUser(): ?array {
    return $_SESSION['pt_user'] ?? null;
}

function ptAdminCan(string $action): bool {
    $u = ptAdminUser();
    if (!$u) return false;
    if ($u['role_slug'] === 'admin') return true;
    $col = ['view' => 'can_view', 'create' => 'can_create', 'edit' => 'can_edit', 'delete' => 'can_delete', 'export' => 'can_export'][$action] ?? null;
    return $col && !empty($u['perms'][$col]);
}

function ptRequireAdmin(string $action = 'view'): array {
    $u = ptAdminUser();
    if (!$u) ptRedirect('admin/login.php');
    if (!ptAdminCan($action)) {
        http_response_code(403);
        ptAdminHead('Accès refusé', '');
        echo '<div class="alert alert-warning mt-4">Vous n\'avez pas les droits nécessaires pour cette action.</div>';
        ptAdminFoot();
        exit;
    }
    return $u;
}

/** Vérifie identifiants OV + droit de consultation du module pointage. Retourne '' si OK, sinon le message d'erreur. */
function ptAdminLogin(string $email, string $password): string {
    $db = getDB();
    $st = $db->prepare("SELECT u.*, r.slug AS role_slug FROM utilisateurs u JOIN roles r ON r.id = u.role_id
        WHERE u.email = ? AND u.actif = 1");
    $st->execute([$email]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password'])) return 'Email ou mot de passe incorrect.';

    $perms = [];
    if ($u['role_slug'] !== 'admin') {
        $p = $db->prepare("SELECT can_view, can_create, can_edit, can_delete, can_export FROM role_permissions WHERE role_id = ? AND module = 'pointage'");
        $p->execute([$u['role_id']]);
        $perms = $p->fetch() ?: [];
        if (empty($perms['can_view'])) return 'Votre compte n\'a pas accès à l\'outil de pointage.';
    }

    session_regenerate_id(true);
    $_SESSION['pt_user'] = [
        'id' => (int)$u['id'], 'nom' => $u['nom'], 'prenom' => $u['prenom'],
        'email' => $u['email'], 'role_slug' => $u['role_slug'], 'perms' => $perms,
    ];
    $db->prepare("UPDATE utilisateurs SET last_login = NOW() WHERE id = ?")->execute([$u['id']]);
    return '';
}
