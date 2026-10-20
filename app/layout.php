<?php
declare(strict_types=1);

function asset(string $path): string
{
    $file = APP_ROOT . '/assets/' . $path;
    return 'assets/' . $path . (is_file($file) ? '?v=' . filemtime($file) : '');
}

function page_open(string $title, string $bodyClass = ''): void
{
    ?><!doctype html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= h($title) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap">
<link rel="stylesheet" href="<?= h(asset('css/app.css')) ?>">
</head>
<body class="<?= h($bodyClass) ?>">
<?php
}

function page_close(): void
{
    echo "</body>\n</html>\n";
}

/** Small centred card used by the sign-in, users and installer pages. */
function card_open(string $heading, string $sub = ''): void
{
    ?><main class="wrap card-page"><div class="card">
<div class="brand" style="margin-bottom:14px"><h1>ESS IV</h1><span>Accommodation CRM</span></div>
<h2><?= h($heading) ?></h2><?php if ($sub): ?><p class="muted"><?= h($sub) ?></p><?php endif ?>
<?php
}

function card_close(): void
{
    echo "</div></main>\n";
}
