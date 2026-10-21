<?php
/**
 * DEMO ONLY. Loads the example data set into an empty demo database.
 *   php scripts/seed-demo.php /tmp/demo.json
 * Does nothing if bookings already exist, so it is safe to run on every start.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/repo.php';
require dirname(__DIR__) . '/app/mailer.php';
require dirname(__DIR__) . '/app/alerts.php';
@set_time_limit(0);

$file = $argv[1] ?? '';
$data = json_decode((string)@file_get_contents($file), true);
if (!is_array($data)) {
    fwrite(STDERR, "Usage: php scripts/seed-demo.php demo.json\n");
    exit(1);
}
$pdo = db();
if ((int)$pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn() > 0) {
    echo "Demo data already present; nothing to do.\n";
    exit(0);
}
$admin = $pdo->query("SELECT * FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$admin) {
    fwrite(STDERR, "No administrator account; run scripts/demo-setup.php first.\n");
    exit(1);
}
$t = microtime(true);
$n = load_examples($data, $admin);
echo "Loaded $n example records in " . round(microtime(true) - $t, 1) . "s.\n";
