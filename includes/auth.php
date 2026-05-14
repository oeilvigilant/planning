<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/index.php');
        exit;
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    return $_SESSION['user'] ?? null;
}

function getUserRole(): string {
    $user = getCurrentUser();
    return $user['role_slug'] ?? '';
}

function isAdmin(): bool {
    return getUserRole() === 'admin';
}

function canDo(string $module, string $action): bool {
    if (isAdmin()) return true;
    $perms = $_SESSION['permissions'][$module] ?? [];
    $map = [
        'view'   => 'can_view',
        'create' => 'can_create',
        'edit'   => 'can_edit',
        'delete' => 'can_delete',
        'export' => 'can_export',
    ];
    $col = $map[$action] ?? null;
    return $col ? (bool)($perms[$col] ?? false) : false;
}

function requirePerm(string $module, string $action): void {
    requireLogin();
    if (!canDo($module, $action)) {
        http_response_code(403);
        include APP_ROOT . '/includes/403.php';
        exit;
    }
}

function login(string $email, string $password): bool {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.*, r.slug as role_slug, r.nom as role_nom
        FROM utilisateurs u
        JOIN roles r ON r.id = u.role_id
        WHERE u.email = ? AND u.actif = 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    // Charger les permissions
    $stmtP = $db->prepare("SELECT * FROM role_permissions WHERE role_id = ?");
    $stmtP->execute([$user['role_id']]);
    $permissions = [];
    foreach ($stmtP->fetchAll() as $p) {
        $permissions[$p['module']] = $p;
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user'] = [
        'id'       => $user['id'],
        'nom'      => $user['nom'],
        'prenom'   => $user['prenom'],
        'email'    => $user['email'],
        'role_id'  => $user['role_id'],
        'role_slug'=> $user['role_slug'],
        'role_nom' => $user['role_nom'],
    ];
    $_SESSION['permissions'] = $permissions;

    $db->prepare("UPDATE utilisateurs SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    return true;
}

function logout(): void {
    session_destroy();
    header('Location: ' . APP_URL . '/index.php');
    exit;
}
