<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/mailer.php';
require __DIR__ . '/app/alerts.php';

$me = require_login();
if ($me['role'] !== 'admin') {
    http_response_code(403);
    exit('Only administrators can change email settings.');
}
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $err = 'The form expired. Try again.';
    } else {
        try {
            switch ($_POST['do'] ?? '') {
                case 'save':
                    $list = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['recipients'] ?? '')) ?: []));
                    $bad = array_filter($list, fn($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));
                    if ($bad) {
                        $err = 'These are not valid email addresses: ' . implode(', ', $bad);
                        break;
                    }
                    if (!$list) {
                        $err = 'Enter at least one email address.';
                        break;
                    }
                    set_setting('alert_recipients', implode(', ', $list));
                    foreach (['alert', 'digest', 'notice'] as $k) {
                        set_setting('email_' . $k, isset($_POST['email_' . $k]) ? '1' : '0');
                    }
                    set_setting('digest_hour', (string)max(0, min(23, (int)($_POST['digest_hour'] ?? 6))));
                    $msg = 'Email settings saved.';
                    break;
                case 'test':
                    queue_email('test', 'ESS IV CRM test email', 'If you can read this, alert and notification emails are working. Sent by ' . ($me['full_name'] ?: $me['username']) . '.');
                    [$s, $f] = flush_email_queue(5);
                    $f ? $err = 'The test email could not be sent. See the error in the log below and check the mail settings in config.php.' : $msg = 'Test email sent to ' . implode(', ', alert_recipients()) . '. Check the inbox (and spam folder).';
                    break;
                case 'run':
                    $r = run_alert_check(isset($_POST['digest']));
                    [$s, $f] = flush_email_queue(50);
                    $msg = "Alert check done: {$r['new']} new alert(s)" . ($r['digest'] ? ', daily summary sent' : '') . "; {$s} email(s) sent" . ($f ? ", {$f} failed" : '') . '.';
                    break;
                case 'retry':
                    db()->exec("UPDATE email_queue SET status = 'pending', attempts = 0 WHERE status = 'failed'");
                    [$s, $f] = flush_email_queue(50);
                    $msg = "{$s} email(s) sent" . ($f ? ", {$f} still failing" : '') . '.';
                    break;
            }
        } catch (Throwable $e) {
            error_log('[ess-iv-crm] settings: ' . $e);
            $err = $e->getMessage();
        }
    }
}

$recipients = setting('alert_recipients', DEFAULT_ALERT_EMAIL);
$demoMail = ($CONFIG['mail']['transport'] ?? '') === 'log';
// Read one email in full (the "demo mailbox", and handy for checking what recipients receive).
if (isset($_GET['view'])) {
    $st = db()->prepare('SELECT * FROM email_queue WHERE id = ?');
    $st->execute([(int)$_GET['view']]);
    $mail = $st->fetch();
    page_open('Email · ESS IV CRM');
    echo '<main class="wrap" style="padding-block:24px 40px;display:flex;flex-direction:column;gap:14px;max-width:900px"><div class="topbar" style="padding:0"><div class="brand"><h1>ESS IV</h1><span>Email</span></div><a class="btn" href="settings.php#log">Back to the email log</a></div>';
    if ($mail) {
        echo '<section class="panel"><dl class="kv"><dt>Subject</dt><dd><b>' . h($mail['subject']) . '</b></dd><dt>To</dt><dd>' . h(str_replace(',', ', ', $mail['recipients'])) . '</dd><dt>Queued</dt><dd>' . h($mail['created_at']) . '</dd><dt>Status</dt><dd>' . h($mail['status']) . ($demoMail ? ' (demo: kept in this mailbox, not sent)' : '') . '</dd></dl></section>';
        echo '<iframe title="Email content" sandbox="" style="width:100%;height:70vh;border:1px solid var(--line);border-radius:8px;background:#fff" srcdoc="' . h($mail['body_html']) . '"></iframe>';
    } else {
        echo '<div class="empty">That email no longer exists.</div>';
    }
    echo '</main>';
    page_close();
    exit;
}
$log = db()->query('SELECT id, created_at, kind, subject, recipients, status, attempts, last_error, sent_at FROM email_queue ORDER BY id DESC LIMIT 60')->fetchAll();
$counts = db()->query('SELECT status, COUNT(*) n FROM email_queue GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
$lastCheck = setting('last_alert_check');
$transport = ($CONFIG['mail']['transport'] ?? 'mail') === 'smtp' ? 'SMTP · ' . ($CONFIG['mail']['smtp_host'] ?? '') . ':' . ($CONFIG['mail']['smtp_port'] ?? '') : 'PHP mail() (server default)';
$csrf = csrf_token();
$chk = fn($k) => setting('email_' . $k, '1') === '1' ? 'checked' : '';

page_open('Email alerts · ESS IV CRM');
?>
<main class="wrap" style="padding-block:24px 40px;display:flex;flex-direction:column;gap:18px;max-width:1100px">
  <div class="topbar" style="padding:0"><div class="brand"><h1>ESS IV</h1><span>Email alerts &amp; notifications</span></div><a class="btn" href="index.php">Back to the CRM</a></div>
  <?php if ($msg): ?><div class="banner"><p><?= h($msg) ?></p></div><?php endif ?>
  <?php if ($err): ?><p class="err" role="alert"><?= h($err) ?></p><?php endif ?>

  <section class="panel"><div class="panel-head"><h2 class="grow">Who gets emailed</h2><span class="chip">Sending via <?= h($transport) ?></span></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="save">
      <fieldset><legend>Recipients &amp; what to send</legend>
        <div class="field spanall"><label for="recipients">Email addresses (separate with commas)</label><textarea id="recipients" name="recipients" rows="2"><?= h($recipients) ?></textarea></div>
        <label class="check spanall"><input type="checkbox" name="email_alert" <?= $chk('alert') ?>> <span><b>Alerts</b>, as soon as they appear: check-ins and check-outs due today, overdue or in the next 7 days, missed arrivals, overdue maintenance, rooms due back in service, preventive tasks due</span></label>
        <label class="check spanall"><input type="checkbox" name="email_notice" <?= $chk('notice') ?>> <span><b>Notifications</b> for every change: new or changed reservations, check-ins, check-outs, cancellations, rooms taken offline or returned, maintenance tickets logged or updated, preventive tasks done</span></label>
        <label class="check"><input type="checkbox" name="email_digest" <?= $chk('digest') ?>> <span><b>Daily summary</b> of all pending check-ins, check-outs and maintenance</span></label>
        <div class="field"><label for="digest_hour">Send the daily summary from</label><select id="digest_hour" name="digest_hour"><?php for ($i = 0; $i < 24; $i++): ?><option value="<?= $i ?>" <?= (int)setting('digest_hour', '6') === $i ? 'selected' : '' ?>><?= sprintf('%02d:00', $i) ?></option><?php endfor ?></select></div>
        <div class="spanall"><button class="btn primary">Save settings</button></div>
      </fieldset>
    </form>
  </section>

  <section class="panel"><div class="panel-head"><h2 class="grow">Check and send now</h2></div>
    <div class="summary" style="margin-bottom:12px"><span>Last alert check <b><?= $lastCheck ? h(date('D j M H:i', strtotime($lastCheck))) : 'never' ?></b></span><span>Sent <b><?= (int)($counts['sent'] ?? 0) ?></b></span><span>Waiting <b><?= (int)($counts['pending'] ?? 0) ?></b></span><span>Failed <b><?= (int)($counts['failed'] ?? 0) ?></b></span></div>
    <div class="filters">
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn" name="do" value="test">Send a test email</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn" name="do" value="run">Check for new alerts now</button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="digest" value="1"><button class="btn" name="do" value="run">Send today's summary now</button></form>
      <?php if (!empty($counts['failed'])): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn danger" name="do" value="retry">Retry failed emails</button></form><?php endif ?>
    </div>
    <p class="muted" style="margin-bottom:0">Alerts are checked automatically every 10 minutes while anyone has the CRM open. For alerts around the clock, add the scheduled task (cron job) described in DEPLOY.md.</p>
  </section>

  <section class="panel" id="log"><div class="panel-head"><h2 class="grow"><?= $demoMail ? 'Demo mailbox' : 'Email log' ?></h2><span class="chip">latest 60 · click a subject to read it</span></div>
    <?php if ($demoMail): ?><p class="muted" style="margin-top:0">This is a demo system: alert and notification emails are kept here instead of being sent. On a real installation they go to the recipients above.</p><?php endif ?>
    <div class="tablewrap"><table><thead><tr><th>Queued</th><th>Type</th><th>Subject</th><th>To</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($log as $m): ?>
      <tr><td class="mono"><?= h(date('j M H:i', strtotime($m['created_at']))) ?></td><td><?= h($m['kind']) ?></td><td><a href="settings.php?view=<?= (int)$m['id'] ?>"><?= h($m['subject']) ?></a><?php if ($m['last_error']): ?><div class="err" style="font-size:12px"><?= h($m['last_error']) ?></div><?php endif ?></td><td style="font-size:12px"><?= h(str_replace(',', ', ', $m['recipients'])) ?></td>
      <td><span class="pill <?= ['sent' => 's-vac', 'pending' => 's-dirty', 'failed' => 's-oos'][$m['status']] ?>"><?= h($m['status']) ?></span></td></tr>
    <?php endforeach ?>
    </tbody></table><?php if (!$log): ?><div class="empty">No emails yet.</div><?php endif ?></div>
  </section>
</main>
<?php
page_close();
