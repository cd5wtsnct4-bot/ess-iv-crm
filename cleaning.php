<?php
/** Cleaning & housekeeping: room housekeeping board, common-area cleaning schedule, cleaning supplies. */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/mailer.php';
require __DIR__ . '/app/ops.php';

$me = require_login();
if (!can($me, 'cleaning')) {
    http_response_code(403);
    exit('You do not have access to Cleaning & housekeeping. Ask an administrator.');
}
$edit = can($me, 'cleaning', 'edit');
$pdo = db();
$who = $me['full_name'] ?: $me['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (!$edit) {
        redirect_with('You have view-only access to Cleaning & housekeeping.', false);
    }
    switch ($_POST['do'] ?? '') {
        case 'rooms':
            $to = in_array($_POST['hk'] ?? '', ['Clean', 'Inspected'], true) ? $_POST['hk'] : 'Clean';
            $ids = array_values(array_filter((array)($_POST['room'] ?? []), fn($r) => preg_match('/^[A-F]\d{3,4}$/', (string)$r)));
            if (!$ids) {
                redirect_with('Tick the rooms you cleaned first.', false);
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE rooms SET hk = ?, updated_by = ? WHERE id IN ($in)")->execute([$to, $me['id'], ...$ids]);
            $pdo->prepare('INSERT INTO audit_log (user_id, username, entity, entity_id, action, details) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$me['id'], $me['username'], 'rooms', implode(',', array_slice($ids, 0, 10)), 'housekeeping', json_encode(['hk' => $to, 'rooms' => $ids])]);
            redirect_with(count($ids) . ' room(s) marked ' . strtolower($to) . ': ' . implode(', ', array_slice($ids, 0, 12)) . (count($ids) > 12 ? '…' : '') . '.');
        case 'done':
            $id = (int)($_POST['task_id'] ?? 0);
            $pdo->prepare('UPDATE cleaning_tasks SET last_done = ? WHERE id = ?')->execute([today(), $id]);
            $pdo->prepare('INSERT INTO cleaning_log (task_id, done_on, done_by, notes, user_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$id, today(), $who, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255), $me['id']]);
            redirect_with('Cleaning task signed off.');
        case 'task':
            $id = (int)($_POST['id'] ?? 0);
            $f = [trim((string)($_POST['area'] ?? '')), trim((string)($_POST['task'] ?? '')), max(1, (int)($_POST['frequency_days'] ?? 1)),
                trim((string)($_POST['assigned_to'] ?? '')), mb_substr(trim((string)($_POST['supplies'] ?? '')), 0, 255)];
            if ($f[0] === '' || $f[1] === '') {
                redirect_with('Enter the area and the task.', false);
            }
            if ($id) {
                $pdo->prepare('UPDATE cleaning_tasks SET area = ?, task = ?, frequency_days = ?, assigned_to = ?, supplies = ? WHERE id = ?')->execute([...$f, $id]);
            } else {
                $pdo->prepare('INSERT INTO cleaning_tasks (area, task, frequency_days, assigned_to, supplies, last_done) VALUES (?, ?, ?, ?, ?, NULL)')->execute($f);
            }
            redirect_with('Cleaning task saved.', true, 'cleaning.php#schedule');
        case 'remove':
            $pdo->prepare('UPDATE cleaning_tasks SET active = 0 WHERE id = ?')->execute([(int)($_POST['task_id'] ?? 0)]);
            redirect_with('Task removed from the schedule.', true, 'cleaning.php#schedule');
        case 'issue':
            if (!can($me, 'stock', 'edit')) {
                redirect_with('Issuing supplies needs edit access to Stock & purchasing.', false);
            }
            try {
                $r = stock_move((int)($_POST['item_id'] ?? 0), 'issue', -abs((float)($_POST['qty'] ?? 0)), $me, ['issued_to' => (string)($_POST['issued_to'] ?? ''), 'note' => 'Cleaning supplies']);
            } catch (PDOException $e) {
                error_log('[ess-iv-crm] cleaning: ' . $e);
                redirect_with('The supplies could not be issued because of a database error.', false);
            } catch (RuntimeException $e) {
                redirect_with($e->getMessage(), false);
            }
            redirect_with('Issued ' . $r['item']['name'] . ' to ' . ($_POST['issued_to'] ?? '') . '. ' . qty_fmt($r['after']) . ' ' . $r['item']['unit'] . ' left.', true, 'cleaning.php#supplies');
    }
    redirect_with('Nothing to do.', false);
}

/* ---------- data ---------- */
$dirty = $pdo->query("SELECT r.id, r.block, r.floor, r.room_type, r.hk,
    (SELECT b.check_in FROM bookings b WHERE b.room_id = r.id AND b.status IN ('Provisional','Confirmed') AND b.check_in >= CURDATE() ORDER BY b.check_in LIMIT 1) next_in
    FROM rooms r WHERE r.hk = 'Dirty' AND r.service = 'in' ORDER BY next_in IS NULL, next_in, r.id")->fetchAll();
$dueOut = $pdo->query("SELECT room_id, guest_name FROM bookings WHERE status = 'Checked-in' AND check_out <= CURDATE() ORDER BY room_id")->fetchAll();
$cleanedToday = (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE action = 'housekeeping' AND at >= CURDATE()")->fetchColumn();
$tasks = $pdo->query('SELECT t.*, DATE_ADD(COALESCE(t.last_done, CURDATE() - INTERVAL 1 DAY), INTERVAL t.frequency_days DAY) next_due FROM cleaning_tasks t WHERE active = 1 ORDER BY next_due, area')->fetchAll();
$overdue = count(array_filter($tasks, fn($t) => $t['next_due'] < today()));
$supplies = $pdo->query("SELECT * FROM stock_items WHERE active = 1 AND category IN ('Cleaning chemicals','Cleaning equipment','Paper & consumables') ORDER BY (qty <= reorder_level) DESC, name")->fetchAll();
$ops30 = ops_period(date('Y-m-d', strtotime('-30 days')), today());
$st = $pdo->prepare("SELECT m.issued_to, SUM(-m.qty * m.unit_cost) cost FROM stock_movements m JOIN stock_items i ON i.id = m.item_id
    WHERE m.type = 'issue' AND i.category IN ('Cleaning chemicals','Cleaning equipment','Paper & consumables') AND m.moved_at >= ? GROUP BY m.issued_to ORDER BY cost DESC");
$st->execute([date('Y-m-d', strtotime('-30 days'))]);
$byArea = $st->fetchAll();
$maxArea = max(1, ...array_values(array_map(fn($r) => (float)$r['cost'], $byArea ?: [['cost' => 1]])));
$log = $pdo->query('SELECT l.*, t.area, t.task FROM cleaning_log l JOIN cleaning_tasks t ON t.id = l.task_id ORDER BY l.done_on DESC, l.id DESC LIMIT 30')->fetchAll();
$editTask = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM cleaning_tasks WHERE id = ?');
    $s->execute([(int)$_GET['edit']]);
    $editTask = $s->fetch() ?: null;
}
$csrf = csrf_token();
$canIssue = can($me, 'stock', 'edit');

ops_page_open($me, 'Cleaning & housekeeping', 'cleaning', 'Operations · Cleaning & housekeeping');
echo flash();
?>
<div class="kpis">
  <div class="kpi" style="--k:var(--dirty)"><div class="v"><?= count($dirty) ?></div><div class="l">Rooms waiting to be cleaned</div></div>
  <div class="kpi" style="--k:var(--arr)"><div class="v"><?= count($dueOut) ?></div><div class="l">Check-outs due or overdue (will need cleaning)</div></div>
  <div class="kpi" style="--k:var(--vac)"><div class="v"><?= $cleanedToday ?></div><div class="l">Housekeeping sign-offs today</div></div>
  <div class="kpi" style="--k:<?= $overdue ? 'var(--oos)' : 'var(--vac)' ?>"><div class="v"><?= $overdue ?></div><div class="l">Common-area tasks overdue</div></div>
  <div class="kpi" style="--k:var(--accent)"><div class="v"><?= rands($ops30['cleaning_per_room_night']) ?></div><div class="l">Cleaning supplies per occupied room-night (30 days)</div></div>
</div>

<section class="panel"><div class="panel-head"><h2 class="grow">Housekeeping board</h2><span class="chip">Rooms marked dirty after check-out</span></div>
  <?php if ($dirty): ?>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="rooms">
    <div class="roomgrid">
      <?php foreach ($dirty as $r): $soon = $r['next_in'] && $r['next_in'] <= date('Y-m-d', strtotime('+1 day')); ?>
        <label class="roomchk <?= $soon ? 'urgent' : '' ?>"><?php if ($edit): ?><input type="checkbox" name="room[]" value="<?= h($r['id']) ?>"><?php endif ?>
          <span class="mono"><b><?= h($r['id']) ?></b></span><small><?= h($r['room_type']) ?><?= $r['next_in'] ? ' · next guest ' . h($r['next_in'] === today() ? 'today' : date('D j M', strtotime($r['next_in']))) : '' ?></small></label>
      <?php endforeach ?>
    </div>
    <?php if ($edit): ?><div class="filters" style="margin-top:12px"><button class="btn primary" name="hk" value="Clean">Mark ticked rooms clean</button><button class="btn" name="hk" value="Inspected">Mark ticked rooms inspected</button><span class="muted">Rooms outlined in red have a guest arriving today or tomorrow, so clean them first.</span></div><?php endif ?>
  </form>
  <?php else: ?><div class="empty">Every room is clean.</div><?php endif ?>
  <?php if ($dueOut): ?><p class="muted" style="margin-bottom:0">Still occupied, due out: <?= h(implode(', ', array_map(fn($b) => $b['room_id'], $dueOut))) ?></p><?php endif ?>
</section>

<section class="panel" id="schedule"><div class="panel-head"><h2 class="grow">Common-area cleaning schedule</h2></div>
  <div class="tablewrap"><table><thead><tr><th>Area</th><th>Task</th><th>Every</th><th>Last done</th><th>Next due</th><th>Assigned to</th><th>Supplies needed</th><?= $edit ? '<th></th>' : '' ?></tr></thead><tbody>
  <?php foreach ($tasks as $t): $d = (int)round((strtotime($t['next_due']) - strtotime(today())) / 86400); ?>
    <tr><td><b><?= h($t['area']) ?></b></td><td><?= h($t['task']) ?><?= $t['example'] ? ' <span class="pill s-muted">example</span>' : '' ?></td><td class="num"><?= (int)$t['frequency_days'] === 1 ? 'Daily' : (int)$t['frequency_days'] . ' days' ?></td>
    <td class="mono"><?= $t['last_done'] ? h(date('j M', strtotime($t['last_done']))) : 'never' ?></td>
    <td><span class="pill <?= $d < 0 ? 's-oos' : ($d === 0 ? 's-dirty' : 's-vac') ?>"><?= $d < 0 ? (-$d) . ' day' . ($d < -1 ? 's' : '') . ' overdue' : ($d === 0 ? 'Today' : h(date('D j M', strtotime($t['next_due'])))) ?></span></td>
    <td><?= h($t['assigned_to']) ?></td><td class="muted" style="font-size:12px"><?= h($t['supplies']) ?></td>
    <?php if ($edit): ?><td style="white-space:nowrap"><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="task_id" value="<?= (int)$t['id'] ?>"><button class="btn sm primary" name="do" value="done">Done</button><a class="btn sm" href="cleaning.php?edit=<?= (int)$t['id'] ?>#taskform">Edit</a></form></td><?php endif ?></tr>
  <?php endforeach ?>
  </tbody></table><?php if (!$tasks): ?><div class="empty">No cleaning tasks yet. Add the daily and weekly jobs for each common area below.</div><?php endif ?></div>
</section>

<div class="two">
  <section class="panel" id="supplies"><div class="panel-head"><h2 class="grow">Cleaning supplies</h2><?php if (can($me, 'stock')): ?><a class="btn sm" href="stock.php?view=usage">Usage report</a><?php endif ?></div>
    <?php if ($canIssue && $supplies): ?>
    <form method="post" style="margin-bottom:12px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="issue">
      <fieldset><legend>Issue supplies to an area</legend>
        <div class="field span2"><label for="is_item">Item</label><select id="is_item" name="item_id" required><?php foreach ($supplies as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?> (<?= qty_fmt((float)$s['qty']) ?> <?= h($s['unit']) ?>)</option><?php endforeach ?></select></div>
        <div class="field"><label for="is_qty">Quantity</label><input id="is_qty" name="qty" type="number" step="0.001" min="0.001" required></div>
        <div class="field"><label for="is_to">To</label><select id="is_to" name="issued_to"><?php foreach (ISSUE_TO as $a): if ($a === 'Kitchen') continue; ?><option><?= h($a) ?></option><?php endforeach ?></select></div>
        <div class="spanall"><button class="btn primary">Issue</button></div>
      </fieldset>
    </form>
    <?php endif ?>
    <div class="tablewrap"><table><thead><tr><th>Item</th><th class="r">In stock</th><th class="r">Reorder at</th><th class="r">Value</th></tr></thead><tbody>
    <?php foreach ($supplies as $s): $isLow = (float)$s['qty'] <= (float)$s['reorder_level'] && (float)$s['reorder_level'] > 0; ?>
      <tr><td><?= h($s['name']) ?></td><td class="num r"><?= $isLow ? '<span class="pill s-oos">' . qty_fmt((float)$s['qty']) . ' ' . h($s['unit']) . '</span>' : qty_fmt((float)$s['qty']) . ' ' . h($s['unit']) ?></td><td class="num r"><?= qty_fmt((float)$s['reorder_level']) ?></td><td class="num r"><?= rands((float)$s['qty'] * (float)$s['unit_cost']) ?></td></tr>
    <?php endforeach ?>
    </tbody></table><?php if (!$supplies): ?><div class="empty">No cleaning supplies set up in stock yet.</div><?php endif ?></div>
  </section>

  <div class="grid">
    <section class="panel"><div class="panel-head"><h2 class="grow">Supplies used by area · 30 days</h2></div>
      <div class="blockbars">
        <?php foreach ($byArea as $a): ?><div class="blockrow" style="grid-template-columns:170px minmax(0,1fr) 90px"><span><?= h($a['issued_to'] ?: '(not set)') ?></span><div class="stack"><i style="width:<?= round((float)$a['cost'] / $maxArea * 100, 1) ?>%;background:var(--accent)"></i></div><span class="num r"><?= rands((float)$a['cost'], 0) ?></span></div><?php endforeach ?>
        <?php if (!$byArea): ?><div class="empty">No cleaning supplies issued in the last 30 days.</div><?php endif ?>
      </div>
    </section>
    <section class="panel"><div class="panel-head"><h2 class="grow">Recently signed off</h2></div>
      <ul class="plain"><?php foreach ($log as $l): ?><li><span class="mono"><?= h(date('j M', strtotime($l['done_on']))) ?></span> <b><?= h($l['area']) ?></b>: <?= h($l['task']) ?> <span class="muted"><?= h($l['done_by']) ?></span></li><?php endforeach ?></ul>
      <?php if (!$log): ?><div class="empty">Nothing signed off yet.</div><?php endif ?>
    </section>
  </div>
</div>

<?php if ($edit): $e = $editTask ?? []; ?>
<section class="panel" id="taskform"><div class="panel-head"><h2 class="grow"><?= $editTask ? 'Edit cleaning task' : 'Add a cleaning task' ?></h2><?php if ($editTask): ?><a class="btn sm" href="cleaning.php">Cancel</a><?php endif ?></div>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="task"><input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
    <fieldset><legend>Task</legend>
      <div class="field"><label for="t_area">Area</label><input id="t_area" name="area" list="areas" required value="<?= h($e['area'] ?? '') ?>"><datalist id="areas"><?php foreach (CLEANING_AREAS as $a): ?><option value="<?= h($a) ?>"><?php endforeach ?></datalist></div>
      <div class="field span2"><label for="t_task">Task</label><input id="t_task" name="task" required value="<?= h($e['task'] ?? '') ?>" placeholder="e.g. Mop floors and sanitise surfaces"></div>
      <div class="field"><label for="t_freq">Every (days)</label><input id="t_freq" name="frequency_days" type="number" min="1" value="<?= (int)($e['frequency_days'] ?? 1) ?>"></div>
      <div class="field"><label for="t_who">Assigned to</label><input id="t_who" name="assigned_to" value="<?= h($e['assigned_to'] ?? '') ?>"></div>
      <div class="field span2"><label for="t_sup">Supplies needed</label><input id="t_sup" name="supplies" value="<?= h($e['supplies'] ?? '') ?>" placeholder="e.g. Floor cleaner, sanitiser, mop heads"></div>
      <div class="spanall"><button class="btn primary">Save task</button></div>
    </fieldset>
  </form>
  <?php if ($editTask): ?>
  <form method="post" style="margin-top:10px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="task_id" value="<?= (int)$editTask['id'] ?>"><button class="btn danger" name="do" value="remove">Remove from schedule</button></form>
  <?php endif ?>
</section>
<?php endif ?>
<?php
ops_page_close();
