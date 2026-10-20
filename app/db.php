<?php
declare(strict_types=1);

function db_connect(array $c): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'],
        (int)($c['port'] ?? 3306),
        $c['name']
    );
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // Keep MySQL's CURRENT_TIMESTAMP in step with PHP's timezone.
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        global $CONFIG;
        $pdo = db_connect($CONFIG['db']);
    }
    return $pdo;
}

/** The 600 rooms: blocks A–F, floors 1–10, rooms 1–10 per floor. Mirrors ROOMS in assets/js/shared.js. */
function room_catalogue(): array
{
    $rates = ['Single' => 650, 'Double' => 850, 'Twin' => 850, 'Suite' => 1450, 'Accessible' => 650];
    $rooms = [];
    foreach (['A', 'B', 'C', 'D', 'E', 'F'] as $b) {
        for ($f = 1; $f <= 10; $f++) {
            for ($n = 1; $n <= 10; $n++) {
                $type = $n <= 4 ? 'Single' : ($n <= 7 ? 'Double' : ($n <= 9 ? 'Twin' : 'Suite'));
                if ($f === 1 && $n <= 2) {
                    $type = 'Accessible';
                }
                $rooms[] = ['id' => $b . ($f * 100 + $n), 'block' => $b, 'floor' => $f, 'type' => $type, 'rate' => $rates[$type]];
            }
        }
    }
    return $rooms;
}

/** Creates any missing tables (schema.sql), then adds any missing columns. Safe to run repeatedly. */
function run_schema(PDO $pdo): void
{
    $sql = (string)file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
        $pdo->exec($stmt);
    }
    migrate($pdo);
}

/** Adds columns introduced after a first install (safe to run repeatedly). */
function migrate(PDO $pdo): void
{
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN);
    $add = [
        'department' => "VARCHAR(40) NOT NULL DEFAULT ''",
        'perm_finance' => "ENUM('none','view','edit') NOT NULL DEFAULT 'none'",
        'perm_reservations' => "ENUM('none','view','edit') NOT NULL DEFAULT 'edit'",
        'perm_rooms' => "ENUM('none','view','edit') NOT NULL DEFAULT 'none'",
        'perm_maintenance' => "ENUM('none','view','edit') NOT NULL DEFAULT 'none'",
        'perm_catering' => "ENUM('none','view','edit') NOT NULL DEFAULT 'none'",
        'perm_stock' => "ENUM('none','view','edit') NOT NULL DEFAULT 'none'",
        'perm_cleaning' => "ENUM('none','view','edit') NOT NULL DEFAULT 'none'",
    ];
    foreach ($add as $col => $def) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE users ADD COLUMN `$col` $def");
        }
    }
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings'")->fetchAll(PDO::FETCH_COLUMN);
    if ($have && !in_array('cancellation_fee', $have, true)) {
        $pdo->exec('ALTER TABLE bookings ADD COLUMN cancellation_fee DECIMAL(12,2) NOT NULL DEFAULT 0, ADD COLUMN cancelled_on DATE NULL');
    }
    if ($have && !in_array('guest_id', $have, true)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN guest_id VARCHAR(40) NOT NULL DEFAULT ''");
    }
    if ($have && !in_array('group_ref', $have, true)) {
        $pdo->exec("ALTER TABLE bookings ADD COLUMN group_ref VARCHAR(30) NOT NULL DEFAULT '', ADD KEY idx_bookings_group (group_ref)");
    }
    $have = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'finance_expenses'")->fetchAll(PDO::FETCH_COLUMN);
    if ($have && !in_array('source', $have, true)) {
        $pdo->exec("ALTER TABLE finance_expenses ADD COLUMN source VARCHAR(40) NOT NULL DEFAULT ''");
    }
}

function seed_rooms(PDO $pdo): int
{
    $st = $pdo->prepare('INSERT IGNORE INTO rooms (id, block, floor, room_type, rate) VALUES (?, ?, ?, ?, ?)');
    $n = 0;
    foreach (room_catalogue() as $r) {
        $st->execute([$r['id'], $r['block'], $r['floor'], $r['type'], $r['rate']]);
        $n += $st->rowCount();
    }
    return $n;
}
