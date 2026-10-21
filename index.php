<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';

$user = require_login();
$app = [
    'csrf'  => csrf_token(),
    'today' => today(),
    'user'  => ['name' => $user['full_name'] ?: $user['username'], 'username' => $user['username'], 'role' => $user['role'], 'perms' => user_perms($user)],
];

page_open('ESS IV Accommodation CRM');
?>
<header class="top">
  <div class="wrap">
    <div class="topbar">
      <div class="brand"><h1>ESS IV</h1><span>Accommodation CRM · 600 rooms</span></div>
      <span class="chip" id="modeChip">Connecting…</span>
      <?php if (!empty($CONFIG['demo'])): ?><span class="chip" style="background:var(--arr-soft);color:var(--arr);border-color:var(--arr)" title="Demo data resets when the demo restarts">Demo system</span><?php endif ?>
      <span class="today" id="todayLbl"></span>
      <button class="btn" id="bulkBtn">Bulk booking</button>
      <button class="btn primary" id="newBookingBtn">New reservation</button>
      <div class="usermenu">
        <span class="muted"><?= h($app['user']['name']) ?><?= $user['role'] === 'admin' ? ' · admin' : '' ?></span>
        <a class="btn sm" href="users.php"><?= $user['role'] === 'admin' ? 'Users' : 'My password' ?></a>
        <?php if (can($user, 'catering') || can($user, 'stock') || can($user, 'cleaning')): ?><a class="btn sm" href="operations.php">Operations</a><?php endif ?>
        <?php if (can($user, 'finance')): ?><a class="btn sm" href="finance.php">Finance</a><?php endif ?>
        <?php if ($user['role'] === 'admin'): ?><a class="btn sm" href="settings.php">Email alerts</a><?php endif ?>
        <form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= h($app['csrf']) ?>"><button class="btn sm">Sign out</button></form>
      </div>
    </div>
    <nav class="tabs" role="tablist" id="tabs">
      <button role="tab" data-tab="dash" aria-selected="true">Dashboard <span class="count" id="alertCount" hidden></span></button>
      <button role="tab" data-tab="cal">Booking calendar</button>
      <button role="tab" data-tab="res">Reservations</button>
      <button role="tab" data-tab="desk">Check-in / Check-out</button>
      <button role="tab" data-tab="rooms">Room status</button>
      <button role="tab" data-tab="maint">Maintenance</button>
      <button role="tab" data-tab="guests">Guests</button>
    </nav>
  </div>
</header>

<main class="wrap" id="view"></main>

<dialog id="dlg"></dialog>
<div class="toast" id="toast" hidden></div>

<script>window.APP = <?= json_encode($app, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="<?= h(asset('js/shared.js')) ?>"></script>
<script src="<?= h(asset('js/app.js')) ?>"></script>
<?php
page_close();
