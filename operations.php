<?php
/** Operations dashboard: catering & kitchen, stock / food shortages and cleaning-supply management at a glance. */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/mailer.php';
require __DIR__ . '/app/alerts.php';

$me = require_login();
if (!can_any_ops($me)) {
    http_response_code(403);
    exit('You do not have access to Operations. Ask an administrator for Kitchen & catering, Stock or Cleaning access.');
}
$cCat = can($me, 'catering');
$cStock = can($me, 'stock');
$cClean = can($me, 'cleaning');
$pdo = db();
$days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90], true) ? (int)($_GET['days'] ?? 30) : 30;
$from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
$to = today();
$P = ops_period($from, $to);
$prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
$prev = ops_period(date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days')), $prevTo);
$alerts = ops_alerts_for($me);
$stockRows = stock_alert_rows();
$foodLow = count(array_filter($stockRows, fn($r) => $r['food'] && $r['level'] === 'low'));
$foodNear = count(array_filter($stockRows, fn($r) => $r['food'] && $r['level'] === 'near'));
$suppLow = count(array_filter($stockRows, fn($r) => !$r['food'] && $r['level'] === 'low'));
$tu = take_up();
$todayFc = meal_forecast($to, $tu);

// Meals: last 14 days served vs forecast, next 7 days forecast
$mealDays = [];
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $mealDays[$d] = ['served' => 0, 'forecast' => array_sum(meal_forecast($d, $tu))];
}
$st = $pdo->prepare('SELECT plan_date, SUM(served) s FROM meal_plans WHERE plan_date BETWEEN ? AND ? GROUP BY plan_date');
$st->execute([array_key_first($mealDays), $to]);
foreach ($st as $r) {
    if (isset($mealDays[$r['plan_date']])) {
        $mealDays[$r['plan_date']]['served'] = (int)$r['s'];
    }
}
$mealMax = max(1, ...array_values(array_map(fn($d) => max($d['served'], $d['forecast']), $mealDays)));
$next7 = [];
for ($i = 1; $i <= 7; $i++) {
    $d = date('Y-m-d', strtotime("+$i days"));
    $next7[$d] = meal_forecast($d, $tu);
}

// Spend by supply group per week (purchases recorded in Finance)
$st = $pdo->prepare("SELECT YEARWEEK(spent_on, 3) wk, MIN(spent_on) d, category, SUM(amount) amt FROM finance_expenses
    WHERE spent_on BETWEEN ? AND ? AND category IN ('Catering: food & beverage','Cleaning supplies','Guest amenities & linen','Maintenance spares') GROUP BY wk, category ORDER BY wk");
$st->execute([$from, $to]);
$weeks = [];
foreach ($st as $r) {
    $weeks[$r['wk']]['label'] ??= date('j M', strtotime($r['d']));
    $weeks[$r['wk']][$r['category']] = (float)$r['amt'];
}
$cats = ['Catering: food & beverage' => 'var(--accent)', 'Cleaning supplies' => 'var(--arr)', 'Guest amenities & linen' => 'var(--occ)', 'Maintenance spares' => 'var(--mnt)'];
$weekMax = max(1, ...array_values(array_map(fn($w) => array_sum(array_intersect_key($w, $cats)), $weeks ?: [[]])));
$groups = ['Catering', 'Cleaning', 'Amenities & linen', 'Maintenance'];
$purchByGroup = ['Catering' => $P['purchases']['Catering: food & beverage'] ?? 0, 'Cleaning' => $P['purchases']['Cleaning supplies'] ?? 0,
    'Amenities & linen' => $P['purchases']['Guest amenities & linen'] ?? 0, 'Maintenance' => $P['purchases']['Maintenance spares'] ?? 0];

$dirty = (int)$pdo->query("SELECT COUNT(*) FROM rooms WHERE hk = 'Dirty' AND service = 'in'")->fetchColumn();
$cleanOver = 0;
foreach ($pdo->query('SELECT DATE_ADD(COALESCE(last_done, CURDATE() - INTERVAL 1 DAY), INTERVAL frequency_days DAY) nd FROM cleaning_tasks WHERE active = 1') as $r) {
    $cleanOver += $r['nd'] < $to ? 1 : 0;
}
$temp7 = (int)$pdo->query('SELECT COUNT(*) FROM kitchen_checks WHERE ok = 0 AND checked_at >= NOW() - INTERVAL 7 DAY')->fetchColumn();
$st = $pdo->prepare("SELECT m.issued_to, SUM(-m.qty * m.unit_cost) cost FROM stock_movements m JOIN stock_items i ON i.id = m.item_id
    WHERE m.type = 'issue' AND i.category IN ('Cleaning chemicals','Cleaning equipment','Paper & consumables') AND m.moved_at >= ? GROUP BY m.issued_to ORDER BY cost DESC LIMIT 8");
$st->execute([$from]);
$cleanAreas = $st->fetchAll();
$cleanMax = max(1, ...array_values(array_map(fn($r) => (float)$r['cost'], $cleanAreas ?: [['cost' => 1]])));

$chg = function (float $now, float $was, bool $lowerIsBetter = true): string {
    if (abs($was) < 0.005) {
        return '';
    }
    $p = ($now - $was) / abs($was) * 100;
    $good = $lowerIsBetter ? $p <= 0 : $p >= 0;
    return ' <span style="color:var(' . ($good ? '--vac' : '--oos') . ')">' . ($p >= 0 ? '+' : '') . number_format($p, 0) . '%</span>';
};

ops_page_open($me, 'Operations dashboard', 'operations', 'Operations dashboard · catering, kitchen & cleaning supplies');
?>
<div class="filters"><span class="muted">Period:</span><?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $k => $v): ?><a class="btn sm <?= $days === $k ? 'primary' : '' ?>" href="operations.php?days=<?= $k ?>"><?= h($v) ?></a><?php endforeach ?><span class="muted">compared with the previous <?= $days ?> days</span></div>

<div class="kpis fin">
  <?php if ($cCat): ?>
  <div class="kpi" style="--k:var(--accent)"><div class="v"><?= array_sum($todayFc) ?></div><div class="l">Meals forecast today (<?= (int)$todayFc['Breakfast'] ?> breakfast · <?= (int)$todayFc['Dinner'] ?> dinner)</div></div>
  <div class="kpi" style="--k:var(--accent)"><div class="v"><?= rands($P['food_cost_per_meal']) ?></div><div class="l">Food cost per meal<?= $chg($P['food_cost_per_meal'], $prev['food_cost_per_meal']) ?></div></div>
  <div class="kpi" style="--k:var(--accent)"><div class="v"><?= number_format($P['meals_served']) ?></div><div class="l">Meals served<?= $chg((float)$P['meals_served'], (float)$prev['meals_served'], false) ?></div></div>
  <div class="kpi" style="--k:<?= $temp7 ? 'var(--oos)' : 'var(--vac)' ?>"><div class="v"><?= $temp7 ?></div><div class="l">Temperature failures (7 days)</div></div>
  <?php endif ?>
  <?php if ($cCat || $cStock): ?>
  <div class="kpi" style="--k:<?= $foodLow ? 'var(--oos)' : 'var(--vac)' ?>"><div class="v"><?= $foodLow ?></div><div class="l">Food items below reorder level</div></div>
  <div class="kpi" style="--k:var(--dirty)"><div class="v"><?= $foodNear ?></div><div class="l">Food items close to reorder level</div></div>
  <?php endif ?>
  <?php if ($cStock || $cClean): ?>
  <div class="kpi" style="--k:<?= $suppLow ? 'var(--oos)' : 'var(--vac)' ?>"><div class="v"><?= $suppLow ?></div><div class="l">Cleaning &amp; other supplies below reorder</div></div>
  <?php endif ?>
  <?php if ($cClean): ?>
  <div class="kpi" style="--k:var(--arr)"><div class="v"><?= rands($P['cleaning_per_room_night']) ?></div><div class="l">Cleaning supplies per occupied room-night<?= $chg($P['cleaning_per_room_night'], $prev['cleaning_per_room_night']) ?></div></div>
  <div class="kpi" style="--k:var(--dirty)"><div class="v"><?= $dirty ?> / <?= $cleanOver ?></div><div class="l">Rooms to clean / common-area tasks overdue</div></div>
  <?php endif ?>
  <?php if ($cStock): ?>
  <div class="kpi" style="--k:var(--occ)"><div class="v"><?= rands(stock_value(), 0) ?></div><div class="l">Stock on hand (<?= rands(stock_value('Catering'), 0) ?> food · <?= rands(stock_value('Cleaning'), 0) ?> cleaning)</div></div>
  <div class="kpi" style="--k:var(--oos)"><div class="v"><?= rands($P['wasted'], 0) ?></div><div class="l">Wastage<?= $chg($P['wasted'], $prev['wasted']) ?></div></div>
  <?php endif ?>
</div>

<div class="two">
  <section class="panel"><div class="panel-head"><h2 class="grow">Alerts</h2><span class="chip">also emailed</span></div>
    <div class="alerts">
      <?php foreach ($alerts as $a): ?>
        <div class="alert-group"><h3><?= h($a['title']) ?> · <?= count($a['rows']) ?></h3>
          <?php foreach (array_slice($a['rows'], 0, 6) as $r): ?>
            <div class="alert"><span class="bar" style="background:<?= $a['urgent'] ? 'var(--oos)' : 'var(--dirty)' ?>"></span><div style="min-width:0"><div class="t"><?= h($r[0]) ?></div><div class="d"><?= h(implode(' · ', array_filter(array_slice($r, 1)))) ?></div></div><a class="btn sm" href="<?= h($a['link']) ?>">Open</a></div>
          <?php endforeach ?>
          <?php if (count($a['rows']) > 6): ?><div class="muted" style="font-size:12px">+<?= count($a['rows']) - 6 ?> more · <a href="<?= h($a['link']) ?>">see all</a></div><?php endif ?>
        </div>
      <?php endforeach ?>
      <?php if (!$alerts): ?><div class="empty">No operations alerts. Stock is above reorder levels, temperatures are in range and cleaning is up to date.</div><?php endif ?>
    </div>
  </section>

  <div class="grid">
    <?php if ($cCat): ?>
    <section class="panel"><div class="panel-head"><h2 class="grow">Meals: served vs forecast</h2><span class="chip">last 14 days</span></div>
      <div class="bars" style="height:150px"><?php foreach ($mealDays as $d => $m): ?><div class="b<?= $d === $to ? ' today' : '' ?>" style="height:<?= round($m['served'] / $mealMax * 100, 1) ?>%;position:relative" title="<?= h(date('D j M', strtotime($d))) ?>: <?= $m['served'] ?> served, <?= $m['forecast'] ?> forecast"><i class="fc" style="bottom:<?= round($m['forecast'] / $mealMax * 150, 1) ?>px"></i></div><?php endforeach ?></div>
      <div class="barlabels"><?php foreach ($mealDays as $d => $m): ?><span><?= (int)date('j', strtotime($d)) ?></span><?php endforeach ?></div>
      <div class="legend" style="margin-top:8px"><span><b style="background:var(--accent)"></b>Served</span><span><b style="background:var(--fg)"></b>Forecast</span></div>
    </section>
    <section class="panel"><div class="panel-head"><h2 class="grow">Next 7 days: portions to prepare</h2><a class="btn sm" href="catering.php">Meal planner</a></div>
      <div class="tablewrap"><table><thead><tr><th>Day</th><?php foreach (MEALS as $m): ?><th class="r"><?= h($m) ?></th><?php endforeach ?></tr></thead><tbody>
      <?php foreach ($next7 as $d => $f): ?><tr><td class="mono"><?= h(date('D j M', strtotime($d))) ?></td><?php foreach (MEALS as $m): ?><td class="num r"><?= (int)$f[$m] ?></td><?php endforeach ?></tr><?php endforeach ?>
      </tbody></table></div>
    </section>
    <?php endif ?>
  </div>
</div>

<?php if ($cStock || $cCat || $cClean): ?>
<div class="two">
  <section class="panel"><div class="panel-head"><h2 class="grow">Supply spend by week</h2><span class="chip">purchases · feeds the P&amp;L</span></div>
    <div class="mbars">
      <?php foreach ($weeks as $w): $tot = array_sum(array_intersect_key($w, $cats)); ?>
        <div class="mrow"><span class="mono">wk <?= h($w['label']) ?></span><div class="stack" style="height:12px"><?php foreach ($cats as $c => $col): ?><i style="width:<?= round(($w[$c] ?? 0) / $weekMax * 100, 2) ?>%;background:<?= $col ?>" title="<?= h($c) ?>: <?= h(rands($w[$c] ?? 0)) ?>"></i><?php endforeach ?></div><span class="num r"><?= rands($tot, 0) ?></span></div>
      <?php endforeach ?>
      <?php if (!$weeks): ?><div class="empty">No supply purchases in this period. Purchases are recorded when deliveries are received in Stock.</div><?php endif ?>
    </div>
    <div class="legend" style="margin-top:10px"><?php foreach ($cats as $c => $col): ?><span><b style="background:<?= $col ?>"></b><?= h($c) ?></span><?php endforeach ?></div>
  </section>

  <section class="panel"><div class="panel-head"><h2 class="grow">Bought vs used</h2><span class="chip"><?= h(date('j M', strtotime($from))) ?> – <?= h(date('j M', strtotime($to))) ?></span></div>
    <div class="tablewrap"><table><thead><tr><th>Supplies</th><th class="r">Purchased</th><th class="r">Used</th><th class="r">Wasted</th><th class="r">On hand now</th></tr></thead><tbody>
    <?php foreach ($groups as $g): ?>
      <tr><td><?= h($g) ?></td><td class="num r"><?= rands($purchByGroup[$g]) ?></td><td class="num r"><?= rands($P['consumption'][$g]['used'] ?? 0) ?></td><td class="num r"><?= rands($P['consumption'][$g]['wasted'] ?? 0) ?></td><td class="num r"><?= rands(stock_value($g)) ?></td></tr>
    <?php endforeach ?>
    </tbody></table></div>
    <?php if ($cClean): ?>
    <h3 style="margin:16px 0 8px">Cleaning supplies used by area</h3>
    <div class="blockbars">
      <?php foreach ($cleanAreas as $a): ?><div class="blockrow" style="grid-template-columns:170px minmax(0,1fr) 90px"><span><?= h($a['issued_to'] ?: '(not set)') ?></span><div class="stack"><i style="width:<?= round((float)$a['cost'] / $cleanMax * 100, 1) ?>%;background:var(--arr)"></i></div><span class="num r"><?= rands((float)$a['cost'], 0) ?></span></div><?php endforeach ?>
      <?php if (!$cleanAreas): ?><div class="empty">No cleaning supplies issued in this period.</div><?php endif ?>
    </div>
    <?php endif ?>
  </section>
</div>
<?php endif ?>
<?php
ops_page_close();
