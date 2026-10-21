<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string)($_POST['username'] ?? '');
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'The sign-in form expired. Try again.';
    } else {
        $error = attempt_login($username, (string)($_POST['password'] ?? '')) ?? '';
        if ($error === '') {
            header('Location: index.php');
            exit;
        }
    }
}

page_open('Sign in · ESS IV CRM');
card_open('Sign in', 'Front desk, housekeeping and maintenance staff sign in with the account an administrator created for them.');
?>
<form method="post" class="stack-form">
  <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
  <div class="field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required autofocus value="<?= h($username) ?>"></div>
  <div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required></div>
  <?php if ($error): ?><p class="err" role="alert"><?= h($error) ?></p><?php endif ?>
  <button class="btn primary">Sign in</button>
</form>
<?php if (!empty($CONFIG['demo'])): ?>
<div class="demo-logins">
  <h3>Demo system: sign in as any department</h3>
  <p class="muted">Each login shows what that team can see and do. Example data is loaded automatically and resets when the demo restarts.</p>
  <div class="tablewrap"><table>
    <thead><tr><th>Username</th><th>Password</th><th>What they can do</th><th></th></tr></thead><tbody>
    <?php foreach ([
        ['admin', 'Demo-Admin-2026', 'Everything: all modules, Users & access, Finance (P&L), Demo mailbox'],
        ['frontdesk', 'Demo-Desk-2026', 'Reservations, bulk bookings, guests, check-in/out'],
        ['housekeeping', 'Demo-House-2026', 'Housekeeping board, cleaning schedule, room status'],
        ['maintenance', 'Demo-Maint-2026', 'Maintenance tickets, room status'],
        ['kitchen', 'Demo-Kitchen-2026', 'Meal planner, food-safety log, stock & purchase orders'],
        ['finance', 'Demo-Finance-2026', 'Profit & Loss, expenses; view-only operations'],
    ] as [$u, $p, $what]): ?>
      <tr><td class="mono"><?= h($u) ?></td><td class="mono"><?= h($p) ?></td><td style="font-size:12px"><?= h($what) ?></td>
      <td><button type="button" class="btn sm" data-u="<?= h($u) ?>" data-p="<?= h($p) ?>">Use</button></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
</div>
<script>
document.querySelectorAll('[data-u]').forEach(b=>b.addEventListener('click',()=>{
  document.getElementById('username').value=b.dataset.u;document.getElementById('password').value=b.dataset.p;document.querySelector('form').submit();
}));
</script>
<?php endif ?>
<?php
card_close();
page_close();
