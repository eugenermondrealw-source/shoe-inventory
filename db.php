<?php
declare(strict_types=1);

/**
 * Shared PDO connection, session setup and small helpers.
 * Edit the credentials below for your local MySQL.
 */

const DB_HOST      = 'localhost';
const DB_NAME      = 'shoe_inventory';   // <-- your database name
const DB_USER      = 'root';
const DB_PASS      = '';
const APP_TIMEZONE = 'Asia/Manila';

date_default_timezone_set(APP_TIMEZONE);

if (session_status() === PHP_SESSION_NONE) {
    session_name('shoe_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection failed. Check db.php credentials.');
}

/** Escape output for HTML. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Per-session CSRF token. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/** Store a one-time banner message (survives a redirect). */
function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

/** Format a unix timestamp for display. */
function fmt_time(?int $ts): string
{
    return $ts ? date('M j, Y g:i A', $ts) : 'Never';
}
