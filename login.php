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
<?php
card_close();
page_close();
