<?php
/**
 * DEMO ONLY (GitHub Codespaces). Creates the tables, the 600 rooms and four demo logins.
 * Never run this on a real server; use install.php there.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/mailer.php';

$pdo = db();
run_schema($pdo);
seed_rooms($pdo);
if (setting('alert_recipients') === null) {
    set_setting('alert_recipients', DEFAULT_ALERT_EMAIL);
}
$demo = [
    // username, password, name, department, role, reservations, rooms, maintenance, finance
    ['admin', 'Demo-Admin-2026', 'Demo Administrator', 'Management', 'admin', 'edit', 'edit', 'edit', 'edit'],
    ['frontdesk', 'Demo-Desk-2026', 'Demo Front Desk', 'Front desk', 'staff', 'edit', 'view', 'view', 'none'],
    ['maintenance', 'Demo-Maint-2026', 'Demo Maintenance', 'Maintenance', 'staff', 'view', 'edit', 'edit', 'none'],
    ['finance', 'Demo-Finance-2026', 'Demo Finance', 'Finance', 'staff', 'view', 'none', 'view', 'edit'],
    ['kitchen', 'Demo-Kitchen-2026', 'Demo Kitchen', 'Kitchen / Catering', 'staff', 'view', 'none', 'none', 'none'],
    ['housekeeping', 'Demo-House-2026', 'Demo Housekeeping', 'Housekeeping', 'staff', 'view', 'edit', 'view', 'none'],
];
// Operations access: catering, stock, cleaning
$opsAccess = ['admin' => ['edit', 'edit', 'edit'], 'frontdesk' => ['none', 'none', 'view'], 'maintenance' => ['none', 'view', 'none'],
    'finance' => ['view', 'view', 'view'], 'kitchen' => ['edit', 'edit', 'none'], 'housekeeping' => ['none', 'view', 'edit']];
$st = $pdo->prepare('INSERT INTO users (username, password_hash, full_name, department, role, perm_reservations, perm_rooms, perm_maintenance, perm_finance)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE username = username');
$ops = $pdo->prepare('UPDATE users SET perm_catering = ?, perm_stock = ?, perm_cleaning = ? WHERE username = ?');
foreach ($demo as $u) {
    $u[1] = password_hash($u[1], PASSWORD_DEFAULT);
    $st->execute($u);
    $ops->execute([...$opsAccess[$u[0]], $u[0]]);
}
echo 'Demo ready: 600 rooms; users: ' . implode(', ', array_column($demo, 0)) . "\n";
