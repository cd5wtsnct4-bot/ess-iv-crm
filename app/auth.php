<?php
declare(strict_types=1);

const MAX_FAILED_LOGINS = 5;
const LOCKOUT_MINUTES = 15;

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('ESS4CRM');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
    // Sign out after 10 hours of inactivity (one long front-desk shift).
    if (isset($_SESSION['seen']) && time() - $_SESSION['seen'] > 36000) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['seen'] = time();
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    start_session();
    if (empty($_SESSION['uid'])) {
        return $user = null;
    }
    $st = db()->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $st->execute([$_SESSION['uid']]);
    $user = $st->fetch() ?: null;
    if ($user) {
        unset($user['password_hash']);
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: login.php');
        exit;
    }
    return $u;
}

const AREAS = [
    'reservations' => 'Reservations, calendar & check-in/out',
    'rooms'        => 'Room status',
    'maintenance'  => 'Room maintenance',
    'finance'      => 'Finance (Profit & Loss)',
    'catering'     => 'Kitchen & catering',
    'stock'        => 'Stock & purchasing',
    'cleaning'     => 'Cleaning & housekeeping',
];
const DEPARTMENTS = ['Front desk', 'Reservations', 'Housekeeping', 'Maintenance', 'Kitchen / Catering', 'Stores', 'Finance', 'Management', 'Security', 'Other'];
const ACCESS_LEVELS = ['none' => 'No access', 'view' => 'View only', 'edit' => 'Can edit'];

/** ['reservations' => 'edit', 'rooms' => 'view', 'maintenance' => 'none'] — admins get edit everywhere. */
function user_perms(array $u): array
{
    $out = [];
    foreach (array_keys(AREAS) as $a) {
        // Admins have full operational access; Finance is only ever granted explicitly.
        $out[$a] = ($u['role'] === 'admin' && $a !== 'finance') ? 'edit' : ($u['perm_' . $a] ?? 'none');
    }
    return $out;
}

function can(array $u, string $area, string $level = 'view'): bool
{
    $have = user_perms($u)[$area] ?? 'none';
    return $level === 'view' ? $have !== 'none' : $have === 'edit';
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

/** Returns null on success, or a message to show the user. */
function attempt_login(string $username, string $password): ?string
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([trim($username)]);
    $u = $st->fetch();
    if (!$u || !$u['active']) {
        password_hash($password, PASSWORD_DEFAULT); // same cost as a real check, so unknown usernames aren't revealed by timing
        return 'Incorrect username or password.';
    }
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        return 'Too many failed attempts. Try again after ' . date('H:i', strtotime($u['locked_until'])) . ' or ask an administrator to reset your password.';
    }
    if (!password_verify($password, $u['password_hash'])) {
        $fails = (int)$u['failed_logins'] + 1;
        $lock = $fails >= MAX_FAILED_LOGINS ? date('Y-m-d H:i:s', time() + LOCKOUT_MINUTES * 60) : null;
        db()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
            ->execute([$lock ? 0 : $fails, $lock, $u['id']]);
        return 'Incorrect username or password.';
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    db()->prepare('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?')
        ->execute([$u['id']]);
    start_session();
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return null;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function password_problem(string $pw): ?string
{
    if (strlen($pw) < 10) {
        return 'Use a password of at least 10 characters.';
    }
    return null;
}
