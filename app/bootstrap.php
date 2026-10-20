<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('ESS IV CRM needs PHP 8.1 or newer. This server runs PHP ' . PHP_VERSION . '.');
}

if (!is_file(APP_ROOT . '/config.php')) {
    header('Location: install.php');
    exit;
}

$CONFIG = require APP_ROOT . '/config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'Africa/Johannesburg');

if (empty($CONFIG['debug'])) {
    ini_set('display_errors', '0');
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function today(): string
{
    return date('Y-m-d');
}
