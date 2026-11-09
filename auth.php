<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Roles
 *   admin : everything - add/delete shoes, update stock, manage users
 *   staff : view inventory and update stock quantities only
 */
const ROLES        = ['admin' => 'Admin', 'staff' => 'Staff'];
const IDLE_TIMEOUT = 3600;   // seconds of inactivity before auto sign-out
const MAX_FAILED   = 5;      // wrong passwords before a temporary lock
const LOCK_SECONDS = 300;    // lock length (5 minutes)
const APP_PAGES    = ['admin.php', 'add-shoe.php', 'users.php'];

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? null) === 'admin';
}

function sign_in(array $row): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'           => (int) $row['id'],
        'username'     => $row['username'],
        'display_name' => $row['display_name'],
        'role'         => $row['role'],
    ];
    $_SESSION['last_seen'] = time();
}

function sign_out(): void
{
    unset($_SESSION['user'], $_SESSION['last_seen']);
    session_regenerate_id(true);
}

/**
 * Gate a page behind login. Re-reads the account from the database on every
 * request, so a deactivated account or changed role takes effect immediately.
 */
function require_login(): array
{
    global $pdo;

    $u = current_user();
    if ($u === null) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $_SESSION['return_to'] = basename($_SERVER['SCRIPT_NAME']);
        }
        redirect('login.php');
    }

    if (time() - (int) ($_SESSION['last_seen'] ?? 0) > IDLE_TIMEOUT) {
        sign_out();
        flash('warning', 'You were signed out after a period of inactivity. Please sign in again.');
        redirect('login.php');
    }

    $st = $pdo->prepare('SELECT id, username, display_name, role, is_active FROM users WHERE id = :id');
    $st->execute([':id' => $u['id']]);
    $row = $st->fetch();
    if (!$row || (int) $row['is_active'] !== 1) {
        sign_out();
        flash('danger', 'Your account is no longer active. Please contact an administrator.');
        redirect('login.php');
    }

    $_SESSION['user'] = [
        'id'           => (int) $row['id'],
        'username'     => $row['username'],
        'display_name' => $row['display_name'],
        'role'         => $row['role'],
    ];
    $_SESSION['last_seen'] = time();
    return $_SESSION['user'];
}

/** Like require_login(), but only for the listed roles. */
function require_role(string ...$roles): array
{
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) {
        flash('danger', 'You do not have permission to open that page.');
        redirect('admin.php');
    }
    return $u;
}

function validate_username(string $u): ?string
{
    return preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $u)
        ? null
        : 'Username must be 3-30 characters: letters, numbers, dot, dash or underscore.';
}

function validate_password(string $p): ?string
{
    if (strlen($p) < 8)  return 'Password must be at least 8 characters.';
    if (strlen($p) > 72) return 'Password must be 72 characters or fewer.';
    return null;
}
