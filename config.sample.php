<?php
// Copy to config.php and fill in — or let install.php write it for you.
// Keep config.php private: it holds your database and email passwords.
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'ess4crm',
        'user' => 'ess4crm_user',
        'pass' => 'change-me',
    ],
    'timezone' => 'Africa/Johannesburg',
    'app_url'  => 'https://yourdomain.co.za/crm/',   // used for the "Open the CRM" link in emails
    'cron_key' => 'replace-with-a-long-random-string', // for cron.php?key=... on hosts with URL-only cron
    'debug'    => false,

    // Alert and notification emails. SMTP is strongly recommended: it is far less likely
    // to land in spam than PHP mail(). Use a real mailbox on your domain, e.g. crm@yourdomain.co.za.
    'mail' => [
        'transport'   => 'smtp',            // 'smtp' or 'mail'
        'from'        => 'crm@yourdomain.co.za',
        'from_name'   => 'ESS IV CRM',
        'smtp_host'   => 'mail.yourdomain.co.za',
        'smtp_port'   => 465,               // 465 = SSL, 587 = TLS (STARTTLS)
        'smtp_secure' => 'ssl',             // 'ssl', 'tls' or '' (none)
        'smtp_user'   => 'crm@yourdomain.co.za',
        'smtp_pass'   => 'mailbox-password',
    ],
];
