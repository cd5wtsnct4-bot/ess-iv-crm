<?php
/** Kitchen & catering: weekly meal planner with headcount forecast, meals served, food-safety temperature log. */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/mailer.php';
require __DIR__ . '/app/ops.php';

$me = require_login();
if (!can($me, 'catering')) {
    http_response_code(403);
    exit('You do not have access to Kitchen & catering. Ask an administrator.');
}
$edit = can($me, 'catering', 'edit');
$pdo = db();
$start = (string)($_GET['start'] ?? '');
$start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : date('Y-m-d', strtotime('monday this week'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (!$edit) {
        redirect_with('You have view-only access to Kitchen & catering.', false);
    }
    switch ($_POST['do'] ?? '') {
        case 'meal':
            $date = (string)($_POST['plan_date'] ?? '');
            $meal = (string)($_POST['meal'] ?? '');
            if (!valid_ymd($date) || !in_array($meal, MEALS, true)) {
                redirect_with('Choose a date and meal.', false);
            }
            $planned = ($_POST['planned'] ?? '') === '' ? null : max(0, (int)$_POST['planned']);
            $served = ($_POST['served'] ?? '') === '' ? null : max(0, (int)$_POST['served']);
            $pdo->prepare('INSERT INTO meal_plans (plan_date, meal, menu, planned, served, notes, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE menu = VALUES(menu), planned = VALUES(planned), served = VALUES(served), notes = VALUES(notes), updated_by = VALUES(updated_by), example = 0')
                ->execute([$date, $meal, trim((string)($_POST['menu'] ?? '')), $planned, $served, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 255), $me['id']]);
            redirect_with("$meal on " . date('D j M', strtotime($date)) . ' saved.', true, "catering.php?start=$start");
        case 'check':
            $kind = (string)($_POST['kind'] ?? '');
            $equip = trim((string)($_POST['equipment'] ?? ''));
            $t = (float)str_replace(',', '.', (string)($_POST['temperature'] ?? ''));
            if (!isset(TEMP_RULES[$kind]) || $equip === '' || ($_POST['temperature'] ?? '') === '') {
                redirect_with('Choose the equipment, its type and the temperature.', false);
            }
            $ok = temp_ok($kind, $t);
            $action = trim((string)($_POST['action_taken'] ?? ''));
            if (!$ok && $action === '') {
                redirect_with(sprintf('%.1f °C is outside the safe range for a %s (%s). Write down what you did about it, then save again.', $t, strtolower($kind), TEMP_RULES[$kind]['label']), false);
            }
            $pdo->prepare('INSERT INTO kitchen_checks (equipment, kind, temperature, ok, action_taken, user_id) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$equip, $kind, $t, $ok ? 1 : 0, mb_substr($action, 0, 255), $me['id']]);
            if (!$ok) {
                // Emailed right now, so the scheduled alert check shouldn't send it again.
                $pdo->prepare('INSERT IGNORE INTO alerts_sent (alert_key) VALUES (?)')->execute(['temp_fail:' . $pdo->lastInsertId()]);
                queue_email('alert', sprintf('Food-safety alert: %s at %.1f °C', $equip, $t),
                    ($me['full_name'] ?: $me['username']) . " recorded a temperature outside the safe range ({$kind}: " . TEMP_RULES[$kind]['label'] . ').',
                    [['title' => 'Check', 'pairs' => [['Equipment', $equip], ['Type', $kind], ['Temperature', sprintf('%.1f °C', $t)], ['Safe range', TEMP_RULES[$kind]['label']], ['Action taken', $action], ['Time', date('D j M Y H:i')]]]]);
            }
            redirect_with($ok ? sprintf('%s: %.1f °C recorded. Within range.', $equip, $t) : sprintf('%s: %.1f °C recorded as OUT OF RANGE. An alert email has been sent.', $equip, $t), $ok, 'catering.php?start=' . $start . '#checks');
        case 'take_up':
            foreach (MEALS as $m) {
                set_setting('take_up_' . str_replace(' ', '_', strtolower($m)), (string)max(0, min(100, (int)($_POST['tu'][$m] ?? DEFAULT_TAKE_UP[$m]))));
            }
            redirect_with('Meal take-up percentages saved. Forecasts now use them.');
    }
    redirect_with('Nothing to do.', false);
}

function valid_ymd(string $d): bool
{
    $x = DateTime::createFromFormat('!Y-m-d', $d);
    return $x && $x->format('Y-m-d') === $d;
}

$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[] = date('Y-m-d', strtotime("$start +$i day"));
}
$st = $pdo->prepare('SELECT * FROM meal_plans WHERE plan_date BETWEEN ? AND ?');
$st->execute([$days[0], $days[6]]);
$plans = [];
foreach ($st as $p) {
    $plans[$p['plan_date']][$p['meal']] = $p;
}
$tu = take_up();
$fc = [];
foreach ($days as $d) {
    $fc[$d] = meal_forecast($d, $tu);
}
$checks = $pdo->query('SELECT k.*, u.username FROM kitchen_checks k LEFT JOIN users u ON u.id = k.user_id ORDER BY checked_at DESC LIMIT 60')->fetchAll();
$fails7 = (int)$pdo->query('SELECT COUNT(*) FROM kitchen_checks WHERE ok = 0 AND checked_at >= NOW() - INTERVAL 7 DAY')->fetchColumn();
$checkedToday = $pdo->query('SELECT COUNT(DISTINCT equipment) FROM kitchen_checks WHERE checked_at >= CURDATE()')->fetchColumn();
$equipment = $pdo->query('SELECT DISTINCT equipment, kind FROM kitchen_checks ORDER BY equipment')->fetchAll();
$ops30 = ops_period(date('Y-m-d', strtotime('-30 days')), today());
$todayFc = meal_forecast(today(), $tu);
$kitchenLow = $pdo->query("SELECT name, qty, unit, reorder_level FROM stock_items WHERE active = 1 AND department = 'Kitchen' AND qty <= reorder_level AND reorder_level > 0 ORDER BY name")->fetchAll();
$editMeal = null;
if (isset($_GET['d'], $_GET['m']) && in_array($_GET['m'], MEALS, true) && valid_ymd((string)$_GET['d'])) {
    $editMeal = ['date' => $_GET['d'], 'meal' => $_GET['m'], 'row' => $plans[$_GET['d']][$_GET['m']] ?? null];
}
$csrf = csrf_token();

ops_page_open($me, 'Kitchen & catering', 'catering', 'Operations · Kitchen & catering');
echo flash();
?>
<div class="kpis">
  <?php foreach ($todayFc as $m => $n): $p = $plans[today()][$m] ?? null; ?>
    <div class="kpi" style="--k:var(--accent)"><div class="v"><?= (int)($p['planned'] ?? $n) ?></div><div class="l"><?= h($m) ?> today<?= isset($p['served']) ? ' · ' . (int)$p['served'] . ' served' : '' ?></div></div>
  <?php endforeach ?>
  <div class="kpi" style="--k:var(--dirty)"><div class="v"><?= rands($ops30['food_cost_per_meal']) ?></div><div class="l">Food cost per meal (30 days)</div></div>
  <div class="kpi" style="--k:<?= $fails7 ? 'var(--oos)' : 'var(--vac)' ?>"><div class="v"><?= $fails7 ?></div><div class="l">Temperature failures (7 days) · <?= (int)$checkedToday ?> units checked today</div></div>
</div>

<section class="panel"><div class="panel-head"><h2 class="grow">Meal planner</h2>
  <div class="filters">
    <a class="btn sm" href="catering.php?start=<?= h(date('Y-m-d', strtotime("$start -7 days"))) ?>">◀ Week</a>
    <a class="btn sm" href="catering.php">This week</a>
    <a class="btn sm" href="catering.php?start=<?= h(date('Y-m-d', strtotime("$start +7 days"))) ?>">Week ▶</a>
  </div></div>
  <p class="muted" style="margin-top:0">Portions are forecast from guests in-house each night × take-up %. Breakfast uses the previous night's guests. Type a planned number to override the forecast. Record what was actually served after each meal.</p>
  <div class="tablewrap"><table class="planner"><thead><tr><th>Meal</th><?php foreach ($days as $d): ?><th class="<?= $d === today() ? 'today-col' : '' ?>"><?= h(date('D j M', strtotime($d))) ?><div class="muted" style="font-weight:400;text-transform:none;letter-spacing:0"><?= guests_on_night($d) ?> guests tonight</div></th><?php endforeach ?></tr></thead><tbody>
  <?php foreach (MEALS as $m): ?>
    <tr><th><?= h($m) ?><div class="muted" style="font-weight:400;text-transform:none;letter-spacing:0"><?= $tu[$m] ?>% take-up</div></th>
    <?php foreach ($days as $d): $p = $plans[$d][$m] ?? null; $planned = $p['planned'] ?? null; ?>
      <td class="<?= $d === today() ? 'today-col' : '' ?>">
        <div class="mono"><b><?= (int)($planned ?? $fc[$d][$m]) ?></b> <span class="muted"><?= $planned !== null ? 'planned' : 'forecast' ?></span><?php if (isset($p['served'])): ?> · <span style="color:var(--vac)"><?= (int)$p['served'] ?> served</span><?php endif ?></div>
        <div class="menu"><?= $p && $p['menu'] ? nl2br(h($p['menu'])) : '<span class="muted">No menu yet</span>' ?></div>
        <?php if ($edit): ?><a class="btn sm" href="catering.php?start=<?= h($start) ?>&d=<?= h($d) ?>&m=<?= urlencode($m) ?>#mealform">Edit</a><?php endif ?>
      </td>
    <?php endforeach ?></tr>
  <?php endforeach ?>
  </tbody></table></div>
</section>

<?php if ($edit && $editMeal): $r = $editMeal['row']; ?>
<section class="panel" id="mealform"><div class="panel-head"><h2 class="grow"><?= h($editMeal['meal']) ?> · <?= h(date('l j F', strtotime($editMeal['date']))) ?></h2><a class="btn sm" href="catering.php?start=<?= h($start) ?>">Close</a></div>
  <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="meal"><input type="hidden" name="plan_date" value="<?= h($editMeal['date']) ?>"><input type="hidden" name="meal" value="<?= h($editMeal['meal']) ?>">
    <fieldset><legend>Menu &amp; numbers (forecast: <?= (int)meal_forecast($editMeal['date'], $tu)[$editMeal['meal']] ?> portions)</legend>
      <div class="field spanall"><label for="menu">Menu (one dish per line)</label><textarea id="menu" name="menu" rows="4"><?= h($r['menu'] ?? '') ?></textarea></div>
      <div class="field"><label for="planned">Planned portions (blank = use forecast)</label><input id="planned" name="planned" type="number" min="0" value="<?= h((string)($r['planned'] ?? '')) ?>"></div>
      <div class="field"><label for="served">Actually served</label><input id="served" name="served" type="number" min="0" value="<?= h((string)($r['served'] ?? '')) ?>"></div>
      <div class="field span2"><label for="notes">Notes (dietary, functions, special requests)</label><input id="notes" name="notes" value="<?= h($r['notes'] ?? '') ?>"></div>
      <div class="spanall"><button class="btn primary">Save</button></div>
    </fieldset>
  </form>
</section>
<?php endif ?>

<div class="two">
  <section class="panel" id="checks"><div class="panel-head"><h2 class="grow">Food-safety temperature log</h2></div>
    <?php if ($edit): ?>
    <form method="post" style="margin-bottom:12px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="check">
      <fieldset><legend>Record a check</legend>
        <div class="field"><label for="equipment">Equipment</label><input id="equipment" name="equipment" required list="equiplist" placeholder="e.g. Walk-in fridge 1"><datalist id="equiplist"><?php foreach ($equipment as $e): ?><option value="<?= h($e['equipment']) ?>"><?php endforeach ?></datalist></div>
        <div class="field"><label for="kind">Type</label><select id="kind" name="kind"><?php foreach (TEMP_RULES as $k => $r): ?><option value="<?= h($k) ?>"><?= h($k) ?> (<?= h($r['label']) ?>)</option><?php endforeach ?></select></div>
        <div class="field"><label for="temperature">Temperature (°C)</label><input id="temperature" name="temperature" type="number" step="0.1" required></div>
        <div class="field span2"><label for="action_taken">Action taken (required if out of range)</label><input id="action_taken" name="action_taken"></div>
        <div class="spanall"><button class="btn primary">Save check</button></div>
      </fieldset>
    </form>
    <?php endif ?>
    <div class="tablewrap"><table><thead><tr><th>When</th><th>Equipment</th><th>Type</th><th class="r">°C</th><th>Result</th><th>Action</th><th>By</th></tr></thead><tbody>
    <?php foreach ($checks as $c): ?>
      <tr><td class="mono"><?= h(date('j M H:i', strtotime($c['checked_at']))) ?></td><td><?= h($c['equipment']) ?></td><td><?= h($c['kind']) ?></td><td class="num r"><?= number_format((float)$c['temperature'], 1) ?></td>
      <td><?= $c['ok'] ? '<span class="pill s-vac">OK</span>' : '<span class="pill s-oos">Out of range</span>' ?></td><td><?= h($c['action_taken']) ?></td><td class="muted"><?= h($c['username'] ?? '') ?></td></tr>
    <?php endforeach ?>
    </tbody></table><?php if (!$checks): ?><div class="empty">No checks recorded yet. Check each fridge, freezer and hot-holding unit at least twice a day.</div><?php endif ?></div>
  </section>

  <div class="grid">
    <section class="panel"><div class="panel-head"><h2 class="grow">Kitchen costs · last 30 days</h2></div>
      <dl class="kv">
        <dt>Meals served</dt><dd class="num"><?= number_format($ops30['meals_served']) ?></dd>
        <dt>Food &amp; beverage used</dt><dd class="num"><?= rands($ops30['food_used']) ?></dd>
        <dt>Food wasted</dt><dd class="num"><?= rands($ops30['food_wasted']) ?></dd>
        <dt>Cost per meal</dt><dd class="num"><b><?= rands($ops30['food_cost_per_meal']) ?></b></dd>
        <dt>Food purchases</dt><dd class="num"><?= rands($ops30['purchases']['Catering: food & beverage'] ?? 0) ?></dd>
      </dl>
      <p class="muted" style="font-size:12px;margin-bottom:0">Cost per meal = food and beverage stock issued to the kitchen plus food wastage, divided by meals served.</p>
    </section>
    <section class="panel"><div class="panel-head"><h2 class="grow">Kitchen stock running low</h2><?php if (can($me, 'stock')): ?><a class="btn sm" href="stock.php?dept=Kitchen&low=1">Open stock</a><?php endif ?></div>
      <?php if ($kitchenLow): ?><ul class="plain"><?php foreach ($kitchenLow as $k): ?><li><b><?= h($k['name']) ?></b> <span class="muted"><?= qty_fmt((float)$k['qty']) ?> <?= h($k['unit']) ?> left (reorder at <?= qty_fmt((float)$k['reorder_level']) ?>)</span></li><?php endforeach ?></ul><?php else: ?><div class="empty">All kitchen stock is above its reorder level.</div><?php endif ?>
    </section>
    <?php if ($edit): ?>
    <section class="panel"><div class="panel-head"><h2 class="grow">Meal take-up</h2></div>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="take_up">
        <fieldset><legend>% of in-house guests who eat each meal</legend>
          <?php foreach (MEALS as $m): ?><div class="field"><label for="tu_<?= h($m) ?>"><?= h($m) ?></label><input id="tu_<?= h($m) ?>" name="tu[<?= h($m) ?>]" type="number" min="0" max="100" value="<?= (int)$tu[$m] ?>"></div><?php endforeach ?>
          <div class="spanall"><button class="btn">Save take-up</button></div>
        </fieldset>
      </form>
    </section>
    <?php endif ?>
  </div>
</div>
<?php
ops_page_close();
