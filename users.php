<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';

$me = require_login();
$isAdmin = $me['role'] === 'admin';
$msg = '';
$err = '';
$pdo = db();

/** Reads the four access selects from a form, e.g. perm_rooms=view. */
function posted_perms(): array
{
    $out = [];
    foreach (array_keys(AREAS) as $a) {
        $v = (string)($_POST['perm_' . $a] ?? 'none');
        $out['perm_' . $a] = isset(ACCESS_LEVELS[$v]) ? $v : 'none';
    }
    return $out;
}

function perm_selects(array $values, string $idPrefix): string
{
    $html = '';
    foreach (AREAS as $a => $label) {
        $id = $idPrefix . $a;
        $html .= '<div class="field"><label for="' . h($id) . '">' . h($label) . '</label><select id="' . h($id) . '" name="perm_' . h($a) . '">';
        foreach (ACCESS_LEVELS as $k => $v) {
            $html .= '<option value="' . h($k) . '"' . (($values['perm_' . $a] ?? '') === $k ? ' selected' : '') . '>' . h($v) . '</option>';
        }
        $html .= '</select></div>';
    }
    return $html;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $err = 'The form expired. Try again.';
    } else {
        $do = (string)($_POST['do'] ?? '');
        try {
            if ($do === 'own_password') {
                $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
                $st->execute([$me['id']]);
                $new = (string)($_POST['new'] ?? '');
                if (!password_verify((string)($_POST['current'] ?? ''), (string)$st->fetchColumn())) {
                    $err = 'Your current password is not correct.';
                } elseif ($p = password_problem($new)) {
                    $err = $p;
                } elseif ($new !== ($_POST['confirm'] ?? '')) {
                    $err = 'The new passwords do not match.';
                } else {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $me['id']]);
                    $msg = 'Your password has been changed.';
                }
            } elseif ($isAdmin && $do === 'add') {
                $username = strtolower(trim((string)($_POST['username'] ?? '')));
                $pw = (string)($_POST['password'] ?? '');
                $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
                $dept = in_array($_POST['department'] ?? '', DEPARTMENTS, true) ? $_POST['department'] : '';
                if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
                    $err = 'Usernames are 3–60 characters: letters, numbers, dot, dash or underscore.';
                } elseif ($p = password_problem($pw)) {
                    $err = $p;
                } else {
                    $st = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
                    $st->execute([$username]);
                    if ($st->fetchColumn()) {
                        $err = "The username “{$username}” is already taken.";
                    } else {
                        $perms = posted_perms();
                        $pdo->prepare('INSERT INTO users (username, full_name, department, password_hash, role, perm_reservations, perm_rooms, perm_maintenance, perm_finance) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$username, trim((string)($_POST['full_name'] ?? '')), $dept, password_hash($pw, PASSWORD_DEFAULT), $role,
                                $perms['perm_reservations'], $perms['perm_rooms'], $perms['perm_maintenance'], $perms['perm_finance']]);
                        $msg = "Account “{$username}” created. Give the person their username and password.";
                    }
                }
            } elseif ($isAdmin && $do === 'access') {
                $id = (int)($_POST['id'] ?? 0);
                $perms = posted_perms();
                $dept = in_array($_POST['department'] ?? '', DEPARTMENTS, true) ? $_POST['department'] : '';
                $pdo->prepare('UPDATE users SET department = ?, perm_reservations = ?, perm_rooms = ?, perm_maintenance = ?, perm_finance = ? WHERE id = ?')
                    ->execute([$dept, $perms['perm_reservations'], $perms['perm_rooms'], $perms['perm_maintenance'], $perms['perm_finance'], $id]);
                $msg = 'Access updated. It applies the next time that person loads a page.';
            } elseif ($isAdmin && in_array($do, ['reset', 'toggle', 'role'], true)) {
                $id = (int)($_POST['id'] ?? 0);
                if ($id === (int)$me['id'] && $do !== 'reset') {
                    $err = 'You cannot change your own role or switch off your own account.';
                } elseif ($do === 'reset') {
                    $pw = (string)($_POST['password'] ?? '');
                    if ($p = password_problem($pw)) {
                        $err = $p;
                    } else {
                        $pdo->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')
                            ->execute([password_hash($pw, PASSWORD_DEFAULT), $id]);
                        $msg = 'Password reset.';
                    }
                } elseif ($do === 'toggle') {
                    $pdo->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$id]);
                    $msg = 'Account updated.';
                } else {
                    $pdo->prepare("UPDATE users SET role = IF(role = 'admin', 'staff', 'admin') WHERE id = ?")->execute([$id]);
                    $msg = 'Role updated.';
                }
            }
        } catch (PDOException $e) {
            error_log('[ess-iv-crm] ' . $e);
            $err = 'The change could not be saved.';
        }
    }
}

$users = $isAdmin ? $pdo->query('SELECT * FROM users ORDER BY active DESC, department, username')->fetchAll() : [];
$csrf = csrf_token();
$levelPill = fn(string $l) => '<span class="pill ' . ['none' => 's-muted', 'view' => 's-arr', 'edit' => 's-vac'][$l] . '">' . h(ACCESS_LEVELS[$l]) . '</span>';

page_open(($isAdmin ? 'Users & access' : 'My account') . ' · ESS IV CRM');
?>
<main class="wrap" style="padding-block:24px 40px;display:flex;flex-direction:column;gap:18px;max-width:1200px">
  <div class="topbar" style="padding:0"><div class="brand"><h1>ESS IV</h1><span><?= $isAdmin ? 'Users &amp; access' : 'My account' ?></span></div><a class="btn" href="index.php">Back to the CRM</a></div>
  <?php if ($msg): ?><div class="banner"><p><?= h($msg) ?></p></div><?php endif ?>
  <?php if ($err): ?><p class="err" role="alert"><?= h($err) ?></p><?php endif ?>

  <?php if ($isAdmin): ?>
  <section class="panel"><div class="panel-head"><h2 class="grow">Who can do what</h2></div>
    <p class="muted" style="margin-top:0">Choose each person's access to each area. <b>View only</b> shows the area without being able to change anything. Admins always have full access to reservations, room status and maintenance. <b>Finance is only available to people you give Finance access</b>, admins included.</p>
    <div class="tablewrap"><table class="users"><thead><tr><th>User</th><th>Department</th><?php foreach (AREAS as $a => $l): ?><th><?= h($l) ?></th><?php endforeach ?><th></th></tr></thead><tbody>
    <?php foreach ($users as $u): $eff = user_perms($u); ?>
      <tr>
        <td><b><?= h($u['full_name'] ?: $u['username']) ?></b><div class="mono muted"><?= h($u['username']) ?> · <?= h($u['role']) ?><?= $u['active'] ? '' : ' · switched off' ?></div></td>
        <td><?= h($u['department'] ?: '—') ?></td>
        <?php foreach (AREAS as $a => $l): ?><td><?= $levelPill($eff[$a]) ?></td><?php endforeach ?>
        <td><button class="btn sm" type="button" data-toggle="edit-<?= (int)$u['id'] ?>">Change</button></td>
      </tr>
      <tr id="edit-<?= (int)$u['id'] ?>" hidden><td colspan="<?= 3 + count(AREAS) ?>" style="background:var(--surface-2)">
        <div class="grid">
          <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="access"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <fieldset><legend>Department &amp; access<?= $u['role'] === 'admin' ? ' (admin: reservations, rooms and maintenance are always full access)' : '' ?></legend>
              <div class="field"><label for="d<?= (int)$u['id'] ?>">Department</label><select id="d<?= (int)$u['id'] ?>" name="department"><option value="">—</option><?php foreach (DEPARTMENTS as $d): ?><option <?= $u['department'] === $d ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach ?></select></div>
              <?= perm_selects($u, 'p' . (int)$u['id'] . '_') ?>
              <div class="spanall"><button class="btn primary">Save access</button></div>
            </fieldset>
          </form>
          <div class="filters">
            <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="reset"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="password" name="password" placeholder="New password" aria-label="New password for <?= h($u['username']) ?>" autocomplete="new-password" required minlength="10"><button class="btn sm">Reset password</button></form>
            <?php if ((int)$u['id'] !== (int)$me['id']): ?>
            <form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn sm" name="do" value="role"><?= $u['role'] === 'admin' ? 'Make staff' : 'Make admin' ?></button><button class="btn sm <?= $u['active'] ? 'danger' : '' ?>" name="do" value="toggle"><?= $u['active'] ? 'Switch off account' : 'Switch on account' ?></button></form>
            <?php endif ?>
            <span class="muted">Last sign-in: <?= $u['last_login_at'] ? h(date('j M Y H:i', strtotime($u['last_login_at']))) : 'never' ?><?= $u['locked_until'] && strtotime($u['locked_until']) > time() ? ' · locked until ' . h(date('H:i', strtotime($u['locked_until']))) : '' ?></span>
          </div>
        </div>
      </td></tr>
    <?php endforeach ?>
    </tbody></table></div>
  </section>

  <section class="panel"><div class="panel-head"><h2 class="grow">Add a user</h2></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="add">
      <fieldset><legend>Account</legend>
        <div class="field"><label for="nu">Username</label><input id="nu" name="username" required pattern="[A-Za-z0-9._\-]{3,60}" autocomplete="off"></div>
        <div class="field"><label for="nf">Full name</label><input id="nf" name="full_name"></div>
        <div class="field"><label for="nd">Department</label><select id="nd" name="department"><option value="">—</option><?php foreach (DEPARTMENTS as $d): ?><option><?= h($d) ?></option><?php endforeach ?></select></div>
        <div class="field"><label for="np">Password (10+ characters)</label><input id="np" name="password" type="password" required minlength="10" autocomplete="new-password"></div>
        <div class="field"><label for="nr">Role</label><select id="nr" name="role"><option value="staff">Staff: only the areas ticked below</option><option value="admin">Admin: full operational access, users and email settings</option></select></div>
      </fieldset>
      <fieldset style="margin-top:12px"><legend>Access</legend>
        <?= perm_selects(['perm_reservations' => 'edit', 'perm_rooms' => 'none', 'perm_maintenance' => 'none', 'perm_finance' => 'none'], 'n_') ?>
        <div class="spanall"><button class="btn primary">Create user</button></div>
      </fieldset>
    </form>
  </section>
  <script>
  document.querySelectorAll('[data-toggle]').forEach(b=>b.addEventListener('click',()=>{const r=document.getElementById(b.dataset.toggle);r.hidden=!r.hidden;b.textContent=r.hidden?'Change':'Close'}));
  document.getElementById('nd').addEventListener('change',e=>{ // sensible defaults per department
    const pre={'Finance':{finance:'edit',reservations:'view',rooms:'none',maintenance:'view'},'Maintenance':{maintenance:'edit',rooms:'edit',reservations:'view',finance:'none'},
      'Housekeeping':{rooms:'edit',maintenance:'view',reservations:'view',finance:'none'},'Front desk':{reservations:'edit',rooms:'view',maintenance:'view',finance:'none'},
      'Reservations':{reservations:'edit',rooms:'view',maintenance:'none',finance:'none'},'Management':{reservations:'view',rooms:'view',maintenance:'view',finance:'view'}}[e.target.value];
    if(pre)for(const[a,v]of Object.entries(pre))document.getElementById('n_'+a).value=v;
  });
  </script>
  <?php endif ?>

  <section class="panel"><div class="panel-head"><h2 class="grow">My access</h2></div>
    <div class="summary"><?php foreach (user_perms($me) as $a => $l): ?><span><?= h(AREAS[$a]) ?> <?= $levelPill($l) ?></span><?php endforeach ?></div>
  </section>

  <section class="panel"><div class="panel-head"><h2 class="grow">Change my password</h2></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="own_password">
      <fieldset><legend><?= h($me['username']) ?></legend>
        <div class="field"><label for="pc">Current password</label><input id="pc" name="current" type="password" required autocomplete="current-password"></div>
        <div class="field"><label for="pn">New password</label><input id="pn" name="new" type="password" required minlength="10" autocomplete="new-password"></div>
        <div class="field"><label for="pk">Repeat new password</label><input id="pk" name="confirm" type="password" required minlength="10" autocomplete="new-password"></div>
        <div class="spanall"><button class="btn primary">Change password</button></div>
      </fieldset>
    </form>
  </section>
</main>
<?php
page_close();
