<?php
/**
 * One-time web installer: creates the tables, loads the 600 rooms, creates the first
 * administrator and writes config.php. It locks itself (install.lock) when it finishes.
 */
declare(strict_types=1);

define('APP_ROOT', __DIR__);
date_default_timezone_set('Africa/Johannesburg');
ini_set('display_errors', '0');

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/layout.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$lock = __DIR__ . '/install.lock';
$checks = [
    'PHP 8.1 or newer (this server: ' . PHP_VERSION . ')' => PHP_VERSION_ID >= 80100,
    'PDO MySQL extension (pdo_mysql)' => extension_loaded('pdo_mysql'),
    'mbstring extension' => extension_loaded('mbstring'),
    'JSON extension' => function_exists('json_encode'),
];
$ready = !in_array(false, $checks, true);

$f = [
    'db_host' => $_POST['db_host'] ?? 'localhost', 'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? '', 'db_user' => $_POST['db_user'] ?? '',
    'admin_user' => $_POST['admin_user'] ?? 'admin', 'admin_name' => $_POST['admin_name'] ?? '',
    'timezone' => $_POST['timezone'] ?? 'Africa/Johannesburg',
    'alert_to' => $_POST['alert_to'] ?? 'sean.h.petersen@gmail.com',
    'transport' => $_POST['transport'] ?? 'smtp', 'mail_from' => $_POST['mail_from'] ?? '',
    'smtp_host' => $_POST['smtp_host'] ?? '', 'smtp_port' => $_POST['smtp_port'] ?? '465',
    'smtp_secure' => $_POST['smtp_secure'] ?? 'ssl', 'smtp_user' => $_POST['smtp_user'] ?? '',
];
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$appUrl = ($https ? 'https' : 'http') . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/';
$errors = [];
$done = false;
$configText = '';

if (is_file($lock)) {
    page_open('Installed · ESS IV CRM');
    card_open('Already installed', 'This system has been set up. To run the installer again, delete install.lock and config.php from the server first.');
    echo '<p><a class="btn primary" href="login.php">Go to sign in</a></p>';
    card_close();
    page_close();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $ready) {
    $adminPass = (string)($_POST['admin_pass'] ?? '');
    if (!in_array($f['timezone'], timezone_identifiers_list(), true)) {
        $errors[] = 'Choose a valid timezone, for example Africa/Johannesburg.';
    }
    if (trim($f['db_name']) === '' || trim($f['db_user']) === '') {
        $errors[] = 'Enter the database name and database user.';
    }
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', strtolower(trim($f['admin_user'])))) {
        $errors[] = 'The administrator username must be 3–60 characters: letters, numbers, dot, dash or underscore.';
    }
    if ($p = password_problem($adminPass)) {
        $errors[] = 'Administrator password: ' . $p;
    } elseif ($adminPass !== ($_POST['admin_pass2'] ?? '')) {
        $errors[] = 'The two administrator passwords do not match.';
    }
    $alertTo = array_filter(array_map('trim', preg_split('/[\s,;]+/', $f['alert_to']) ?: []));
    if (!$alertTo || array_filter($alertTo, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL))) {
        $errors[] = 'Enter valid email address(es) for alerts, separated by commas.';
    }
    if (!filter_var($f['mail_from'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter the “From” email address (a mailbox on your domain, e.g. crm@yourdomain.co.za).';
    }
    if ($f['transport'] === 'smtp' && trim($f['smtp_host']) === '') {
        $errors[] = 'Enter the SMTP server, or choose “PHP mail()”.';
    }

    if (!$errors) {
        date_default_timezone_set($f['timezone']);
        $db = ['host' => trim($f['db_host']), 'port' => (int)$f['db_port'], 'name' => trim($f['db_name']), 'user' => trim($f['db_user']), 'pass' => (string)($_POST['db_pass'] ?? '')];
        try {
            $pdo = db_connect($db);
            run_schema($pdo);
            seed_rooms($pdo);
            $username = strtolower(trim($f['admin_user']));
            $st = $pdo->prepare('SELECT id FROM users WHERE username = ?');
            $st->execute([$username]);
            if ($id = $st->fetchColumn()) {
                $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin', active = 1, perm_finance = 'edit', full_name = ? WHERE id = ?")
                    ->execute([password_hash($adminPass, PASSWORD_DEFAULT), trim($f['admin_name']), $id]);
            } else {
                $pdo->prepare("INSERT INTO users (username, full_name, department, password_hash, role, perm_finance) VALUES (?, ?, 'Management', ?, 'admin', 'edit')")
                    ->execute([$username, trim($f['admin_name']), password_hash($adminPass, PASSWORD_DEFAULT)]);
            }
            $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')
                ->execute(['alert_recipients', implode(', ', $alertTo)]);
            $config = [
                'db' => $db, 'timezone' => $f['timezone'], 'app_url' => $appUrl,
                'cron_key' => bin2hex(random_bytes(20)), 'debug' => false,
                'mail' => [
                    'transport' => $f['transport'] === 'mail' ? 'mail' : 'smtp',
                    'from' => trim($f['mail_from']), 'from_name' => 'ESS IV CRM',
                    'smtp_host' => trim($f['smtp_host']), 'smtp_port' => (int)$f['smtp_port'],
                    'smtp_secure' => in_array($f['smtp_secure'], ['ssl', 'tls', ''], true) ? $f['smtp_secure'] : 'ssl',
                    'smtp_user' => trim($f['smtp_user']), 'smtp_pass' => (string)($_POST['smtp_pass'] ?? ''),
                ],
            ];
            $configText = "<?php\n// Written by install.php on " . date('Y-m-d H:i') . ". Keep this file private.\nreturn " . var_export($config, true) . ";\n";
            if (@file_put_contents(__DIR__ . '/config.php', $configText) !== false) {
                @chmod(__DIR__ . '/config.php', 0640);
                $configText = '';
            }
            @file_put_contents($lock, date('c') . "\n");
            $done = true;
        } catch (PDOException $e) {
            $errors[] = 'MySQL said: ' . $e->getMessage() . ' Check the database name, user, password and host, and that the user has ALL PRIVILEGES on the database.';
        }
    }
}

page_open('Install · ESS IV CRM');
if ($done) {
    card_open('Installation complete', 'The tables are created, the 600 rooms are loaded and your administrator account is ready.');
    if ($configText !== '') { ?>
        <p class="err">The installer could not write <b>config.php</b> (the folder is not writable). Create a file called <b>config.php</b> next to index.php with exactly this content, then continue:</p>
        <textarea readonly rows="16" style="font-family:var(--font-mono);font-size:12px"><?= h($configText) ?></textarea>
    <?php } ?>
    <ol class="muted">
      <li>Delete <b>install.php</b> from the server now.</li>
      <li>Sign in with the administrator account you just created.</li>
      <li>Set up the scheduled task (cron job) from DEPLOY.md so alerts are emailed around the clock. Your cron URL is <code><?= h($appUrl) ?>cron.php?key=<?= h($config['cron_key']) ?></code></li>
      <li>Sign in, open <b>Email alerts</b> and click <b>Send a test email</b>.</li>
      <li>Optional: on the Dashboard, click <b>Load example data</b> to try the system, then <b>Clear example data</b> before going live.</li>
    </ol>
    <p><a class="btn primary" href="login.php">Go to sign in</a></p>
    <?php
    card_close();
    page_close();
    exit;
}

card_open('Install ESS IV CRM', 'Fill in the MySQL details from your hosting control panel and choose the first administrator account.');
?>
<ul class="checks">
<?php foreach ($checks as $label => $ok): ?>
  <li class="<?= $ok ? 'ok' : 'err' ?>"><?= $ok ? '✓' : '✗' ?> <?= h($label) ?></li>
<?php endforeach ?>
</ul>
<?php if (!$ready): ?><p class="err">Fix the items marked ✗ in your hosting control panel (PHP version / extensions), then reload this page.</p><?php endif ?>
<?php foreach ($errors as $e): ?><p class="err" role="alert"><?= h($e) ?></p><?php endforeach ?>
<form method="post" class="stack-form">
  <fieldset><legend>MySQL database</legend>
    <div class="field"><label for="db_host">Host</label><input id="db_host" name="db_host" value="<?= h($f['db_host']) ?>" required></div>
    <div class="field"><label for="db_port">Port</label><input id="db_port" name="db_port" value="<?= h($f['db_port']) ?>" inputmode="numeric"></div>
    <div class="field"><label for="db_name">Database name</label><input id="db_name" name="db_name" value="<?= h($f['db_name']) ?>" required placeholder="e.g. cpuser_ess4crm"></div>
    <div class="field"><label for="db_user">Database user</label><input id="db_user" name="db_user" value="<?= h($f['db_user']) ?>" required autocomplete="off"></div>
    <div class="field spanall"><label for="db_pass">Database password</label><input id="db_pass" name="db_pass" type="password" autocomplete="off"></div>
  </fieldset>
  <fieldset><legend>First administrator</legend>
    <div class="field"><label for="admin_user">Username</label><input id="admin_user" name="admin_user" value="<?= h($f['admin_user']) ?>" required></div>
    <div class="field"><label for="admin_name">Full name</label><input id="admin_name" name="admin_name" value="<?= h($f['admin_name']) ?>"></div>
    <div class="field"><label for="admin_pass">Password (10+ characters)</label><input id="admin_pass" name="admin_pass" type="password" required minlength="10" autocomplete="new-password"></div>
    <div class="field"><label for="admin_pass2">Repeat password</label><input id="admin_pass2" name="admin_pass2" type="password" required minlength="10" autocomplete="new-password"></div>
  </fieldset>
  <fieldset><legend>Email alerts &amp; notifications</legend>
    <div class="field spanall"><label for="alert_to">Send all alerts and notifications to</label><input id="alert_to" name="alert_to" value="<?= h($f['alert_to']) ?>" required></div>
    <div class="field"><label for="mail_from">From address (mailbox on your domain)</label><input id="mail_from" name="mail_from" type="email" value="<?= h($f['mail_from']) ?>" placeholder="crm@yourdomain.co.za" required></div>
    <div class="field"><label for="transport">Send using</label><select id="transport" name="transport"><option value="smtp" <?= $f['transport'] === 'smtp' ? 'selected' : '' ?>>SMTP mailbox (recommended)</option><option value="mail" <?= $f['transport'] === 'mail' ? 'selected' : '' ?>>PHP mail() (no setup, may go to spam)</option></select></div>
    <div class="field"><label for="smtp_host">SMTP server</label><input id="smtp_host" name="smtp_host" value="<?= h($f['smtp_host']) ?>" placeholder="mail.yourdomain.co.za"></div>
    <div class="field"><label for="smtp_port">SMTP port</label><input id="smtp_port" name="smtp_port" value="<?= h($f['smtp_port']) ?>" inputmode="numeric"></div>
    <div class="field"><label for="smtp_secure">Encryption</label><select id="smtp_secure" name="smtp_secure"><?php foreach (['ssl' => 'SSL (port 465)', 'tls' => 'TLS / STARTTLS (port 587)', '' => 'None'] as $k => $v): ?><option value="<?= h($k) ?>" <?= $f['smtp_secure'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="smtp_user">SMTP username</label><input id="smtp_user" name="smtp_user" value="<?= h($f['smtp_user']) ?>" autocomplete="off"></div>
    <div class="field spanall"><label for="smtp_pass">SMTP password</label><input id="smtp_pass" name="smtp_pass" type="password" autocomplete="off"></div>
  </fieldset>
  <fieldset><legend>Site</legend>
    <div class="field spanall"><label for="timezone">Timezone (sets what “today” means for arrivals and departures)</label><input id="timezone" name="timezone" value="<?= h($f['timezone']) ?>" required></div>
  </fieldset>
  <button class="btn primary" <?= $ready ? '' : 'disabled' ?>>Install</button>
</form>
<?php
card_close();
page_close();
