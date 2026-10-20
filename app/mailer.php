<?php
declare(strict_types=1);

/**
 * Outgoing email. Everything is queued in email_queue first (so nothing is lost if the
 * mail server is down) and sent by flush_email_queue(), which cron.php and the API call.
 *
 * config.php → 'mail' => [
 *   'transport' => 'smtp' | 'mail',     // 'mail' uses PHP's mail() function
 *   'from' => 'crm@yourdomain.co.za', 'from_name' => 'ESS IV CRM',
 *   'smtp_host' => 'mail.yourdomain.co.za', 'smtp_port' => 465,
 *   'smtp_secure' => 'ssl' | 'tls' | '', 'smtp_user' => '...', 'smtp_pass' => '...',
 * ]
 */

const DEFAULT_ALERT_EMAIL = 'sean.h.petersen@gmail.com';

function setting(string $k, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT v FROM settings WHERE k = ?');
    $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $default : (string)$v;
}

function set_setting(string $k, string $v): void
{
    db()->prepare('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)')->execute([$k, $v]);
}

/** @return string[] */
function alert_recipients(): array
{
    $raw = setting('alert_recipients', DEFAULT_ALERT_EMAIL) ?? '';
    $list = array_filter(array_map('trim', preg_split('/[\s,;]+/', $raw) ?: []));
    return array_values(array_filter($list, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
}

function email_enabled(string $kind): bool
{
    // kind: 'alert' | 'digest' | 'notice' | 'test'
    if ($kind === 'test') {
        return true;
    }
    return setting('email_' . $kind, '1') === '1';
}

/** Queue an email to the alert recipients. $rows: list of [label, value] or an HTML string for the body. */
function queue_email(string $kind, string $subject, string $intro, array $sections = [], ?array $to = null): void
{
    $cat = in_array($kind, ['alert', 'digest', 'test'], true) ? $kind : 'notice';
    if (!email_enabled($cat)) {
        return;
    }
    $to ??= alert_recipients();
    if (!$to) {
        return;
    }
    [$text, $html] = render_email($subject, $intro, $sections);
    db()->prepare('INSERT INTO email_queue (kind, subject, body_text, body_html, recipients) VALUES (?, ?, ?, ?, ?)')
        ->execute([$kind, mb_substr($subject, 0, 250), $text, $html, implode(',', $to)]);
}

/**
 * $sections: [ ['title' => 'Overdue check-outs', 'rows' => [[col1, col2, ...], ...], 'head' => [...]], ... ]
 * or ['title' => ..., 'pairs' => [[label, value], ...]]
 */
function render_email(string $subject, string $intro, array $sections): array
{
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $url = app_url();
    $text = $subject . "\n" . str_repeat('=', min(70, mb_strlen($subject))) . "\n\n" . $intro . "\n";
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;color:#16212b;max-width:720px">'
        . '<div style="border-bottom:3px solid #0f6e74;padding-bottom:8px;margin-bottom:14px"><b style="font-size:18px">ESS IV</b> <span style="color:#5b6b78">Accommodation CRM</span></div>'
        . '<h2 style="font-size:17px;margin:0 0 8px">' . $e($subject) . '</h2><p style="margin:0 0 16px">' . nl2br($e($intro)) . '</p>';
    foreach ($sections as $s) {
        $text .= "\n" . strtoupper($s['title']) . "\n";
        $html .= '<h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#5b6b78;margin:18px 0 6px">' . $e($s['title']) . '</h3>';
        if (isset($s['pairs'])) {
            $html .= '<table style="border-collapse:collapse;font-size:14px">';
            foreach ($s['pairs'] as [$k, $v]) {
                $text .= "  {$k}: {$v}\n";
                $html .= '<tr><td style="padding:3px 14px 3px 0;color:#5b6b78">' . $e($k) . '</td><td style="padding:3px 0">' . $e($v) . '</td></tr>';
            }
            $html .= '</table>';
        } else {
            $html .= '<table style="border-collapse:collapse;font-size:13px;width:100%">';
            if (!empty($s['head'])) {
                $html .= '<tr>' . implode('', array_map(fn($c) => '<th style="text-align:left;padding:5px 8px;border-bottom:1px solid #d9dfe5;color:#5b6b78;font-size:11px;text-transform:uppercase">' . $e($c) . '</th>', $s['head'])) . '</tr>';
            }
            foreach ($s['rows'] as $r) {
                $text .= '  - ' . implode(' | ', $r) . "\n";
                $html .= '<tr>' . implode('', array_map(fn($c) => '<td style="padding:5px 8px;border-bottom:1px solid #eef1f4">' . $e($c) . '</td>', $r)) . '</tr>';
            }
            $html .= '</table>';
        }
    }
    if ($url) {
        $text .= "\nOpen the CRM: {$url}\n";
        $html .= '<p style="margin-top:20px"><a href="' . $e($url) . '" style="background:#0f6e74;color:#fff;padding:8px 14px;border-radius:6px;text-decoration:none">Open the CRM</a></p>';
    }
    $html .= '<p style="color:#5b6b78;font-size:12px;margin-top:20px">Sent automatically by ESS IV Accommodation CRM on ' . date('D j M Y H:i') . '. Recipients are managed under Settings.</p></div>';
    return [$text, $html];
}

function app_url(): string
{
    global $CONFIG;
    if (!empty($CONFIG['app_url'])) {
        return rtrim($CONFIG['app_url'], '/') . '/';
    }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/';
    }
    return '';
}

/** Send pending emails. Returns [sent, failed]. */
function flush_email_queue(int $limit = 20): array
{
    $pdo = db();
    if ((int)$pdo->query("SELECT GET_LOCK('ess4crm_mail', 0)")->fetchColumn() !== 1) {
        return [0, 0];
    }
    $sent = $failed = 0;
    try {
        $rows = $pdo->query("SELECT * FROM email_queue WHERE status = 'pending' OR (status = 'failed' AND attempts < 5 AND created_at > NOW() - INTERVAL 2 DAY) ORDER BY id LIMIT " . max(1, $limit))->fetchAll();
        foreach ($rows as $m) {
            try {
                send_mail(explode(',', $m['recipients']), $m['subject'], $m['body_text'], $m['body_html']);
                $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = '' WHERE id = ?")->execute([$m['id']]);
                $sent++;
            } catch (Throwable $e) {
                $pdo->prepare("UPDATE email_queue SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 490), $m['id']]);
                error_log('[ess-iv-crm] mail failed: ' . $e->getMessage());
                $failed++;
            }
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ess4crm_mail')");
    }
    return [$sent, $failed];
}

function mime_message(array $to, string $subject, string $text, string $html, string $from, string $fromName): array
{
    $boundary = 'b_' . bin2hex(random_bytes(12));
    $host = parse_url(app_url() ?: 'http://localhost', PHP_URL_HOST) ?: 'localhost';
    $headers = [
        'From' => mb_encode_mimeheader($fromName, 'UTF-8') . " <{$from}>",
        'To' => implode(', ', $to),
        'Subject' => mb_encode_mimeheader($subject, 'UTF-8'),
        'Date' => date('r'),
        'Message-ID' => '<' . bin2hex(random_bytes(10)) . '@' . $host . '>',
        'MIME-Version' => '1.0',
        'Content-Type' => "multipart/alternative; boundary=\"{$boundary}\"",
        'X-Mailer' => 'ESS-IV-CRM',
    ];
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($text))
        . "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($html))
        . "--{$boundary}--\r\n";
    return [$headers, $body];
}

function send_mail(array $to, string $subject, string $text, string $html): void
{
    global $CONFIG;
    $m = $CONFIG['mail'] ?? [];
    $to = array_values(array_filter(array_map('trim', $to), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    if (!$to) {
        throw new RuntimeException('No valid recipient addresses.');
    }
    $from = $m['from'] ?? ('crm@' . (parse_url(app_url() ?: 'http://localhost', PHP_URL_HOST) ?: 'localhost'));
    $fromName = $m['from_name'] ?? 'ESS IV CRM';
    [$headers, $body] = mime_message($to, $subject, $text, $html, $from, $fromName);

    if (($m['transport'] ?? 'mail') === 'smtp') {
        smtp_send($m, $from, $to, $headers, $body);
        return;
    }
    $subj = $headers['Subject'];
    unset($headers['To'], $headers['Subject']);
    $hdr = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers));
    if (!mail(implode(', ', $to), $subj, $body, $hdr, '-f' . $from)) {
        throw new RuntimeException('PHP mail() refused the message. Switch to SMTP in config.php.');
    }
}

function smtp_send(array $m, string $from, array $to, array $headers, string $body): void
{
    $host = $m['smtp_host'] ?? 'localhost';
    $port = (int)($m['smtp_port'] ?? 587);
    $secure = $m['smtp_secure'] ?? ($port === 465 ? 'ssl' : 'tls');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client(($secure === 'ssl' ? 'ssl://' : 'tcp://') . "{$host}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Could not connect to SMTP server {$host}:{$port} ({$errstr}).");
    }
    stream_set_timeout($fp, 30);
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        return $out;
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): string {
        if ($c !== '') {
            fwrite($fp, $c . "\r\n");
        }
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) {
            $shown = str_starts_with($c, 'AUTH') || strlen($c) > 60 ? '(credentials)' : $c;
            throw new RuntimeException('SMTP error after ' . $shown . ': ' . trim($r));
        }
        return $r;
    };
    try {
        $cmd('', [220]);
        $ehlo = 'EHLO ' . (gethostname() ?: 'localhost');
        $cmd($ehlo, [250]);
        if ($secure === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('Could not start TLS with the SMTP server.');
            }
            $cmd($ehlo, [250]);
        }
        if (!empty($m['smtp_user'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($m['smtp_user']), [334]);
            $cmd(base64_encode((string)($m['smtp_pass'] ?? '')), [235]);
        }
        $cmd("MAIL FROM:<{$from}>", [250]);
        foreach ($to as $rcpt) {
            $cmd("RCPT TO:<{$rcpt}>", [250, 251]);
        }
        $cmd('DATA', [354]);
        $data = implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)) . "\r\n\r\n" . $body;
        $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $data));
        fwrite($fp, $data . "\r\n.\r\n");
        $cmd('', [250]);
        fwrite($fp, "QUIT\r\n"); // message is already accepted; don't fail on the goodbye
    } finally {
        fclose($fp);
    }
}
