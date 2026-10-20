<?php
/**
 * Scheduled job: emails new alerts, the daily summary, and anything still waiting in the email queue.
 *
 * Run every 10–15 minutes, either:
 *   Command line (best):  php /home/USER/public_html/crm/cron.php
 *   Web URL (if your host only offers URL cron):  https://yourdomain/crm/cron.php?key=CRON_KEY_FROM_CONFIG
 */
declare(strict_types=1);

$cli = PHP_SAPI === 'cli';
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/mailer.php';
require __DIR__ . '/app/alerts.php';

if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string)($_GET['key'] ?? '');
    if (empty($CONFIG['cron_key']) || !hash_equals((string)$CONFIG['cron_key'], $key)) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}
@set_time_limit(300);

try {
    $r = run_alert_check(in_array('--digest', $argv ?? [], true));
    [$sent, $failed] = flush_email_queue(100);
    echo date('Y-m-d H:i:s') . " alerts: {$r['new']} new" . ($r['digest'] ? ', daily summary queued' : '') . "; emails sent: {$sent}, failed: {$failed}\n";
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    error_log('[ess-iv-crm] cron: ' . $e);
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(2);
}
