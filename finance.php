<?php
/**
 * Finance: Profit & Loss statement built from bookings, cancellations, maintenance costs
 * and operating expenses. Only for users with Finance access (set under Users).
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/mailer.php';

$me = require_login();
if (!can($me, 'finance')) {
    http_response_code(403);
    page_open('No access · ESS IV CRM');
    card_open('Finance is restricted', 'Only the finance department can open the Profit & Loss statement. Ask an administrator to give you Finance access under Users.');
    echo '<p><a class="btn" href="index.php">Back to the CRM</a></p>';
    card_close();
    page_close();
    exit;
}
$canEdit = can($me, 'finance', 'edit');
$pdo = db();
$msg = '';
$err = '';

const EXPENSE_CATEGORIES = [
    'Staff salaries & wages', 'Utilities: electricity', 'Utilities: water & sanitation', 'Cleaning & laundry', 'Guest amenities',
    'Security', 'Insurance', 'Internet & IT', 'Rates & taxes', 'Repairs (not on tickets)', 'Marketing & commission',
    'Bank & card fees', 'Professional fees', 'Other',
];

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $err = 'The form expired. Try again.';
    } elseif (!$canEdit) {
        $err = 'You have view-only Finance access.';
    } else {
        $do = (string)($_POST['do'] ?? '');
        if ($do === 'add_expense') {
            $date = (string)($_POST['spent_on'] ?? '');
            $amount = round((float)($_POST['amount'] ?? 0), 2);
            $cat = in_array($_POST['category'] ?? '', EXPENSE_CATEGORIES, true) ? $_POST['category'] : 'Other';
            $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255);
            $sup = mb_substr(trim((string)($_POST['supplier'] ?? '')), 0, 120);
            $d = DateTime::createFromFormat('!Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                $err = 'Enter the date of the expense.';
            } elseif ($amount <= 0) {
                $err = 'Enter an amount greater than zero.';
            } else {
                $pdo->prepare('INSERT INTO finance_expenses (spent_on, category, description, supplier, amount, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$date, $cat, $desc, $sup, $amount, $me['id']]);
                $eid = (string)$pdo->lastInsertId();
                $pdo->prepare('INSERT INTO audit_log (user_id, username, entity, entity_id, action, details) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$me['id'], $me['username'], 'expenses', $eid, 'create', json_encode(compact('date', 'cat', 'desc', 'sup', 'amount'))]);
                queue_email('notice', 'Expense recorded: R ' . number_format($amount, 2) . " ({$cat})", ($me['full_name'] ?: $me['username']) . ' recorded an operating expense.',
                    [['title' => 'Details', 'pairs' => [['Date', date('D j M Y', strtotime($date))], ['Category', $cat], ['Description', $desc ?: '—'], ['Supplier', $sup ?: '—'], ['Amount', 'R ' . number_format($amount, 2)]]]]);
                $msg = 'Expense recorded.';
            }
        } elseif ($do === 'delete_expense') {
            $id = (int)($_POST['id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM finance_expenses WHERE id = ?');
            $st->execute([$id]);
            if ($x = $st->fetch()) {
                $pdo->prepare('DELETE FROM finance_expenses WHERE id = ?')->execute([$id]);
                $pdo->prepare('INSERT INTO audit_log (user_id, username, entity, entity_id, action, details) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$me['id'], $me['username'], 'expenses', (string)$id, 'delete', json_encode($x)]);
                queue_email('notice', 'Expense deleted: R ' . number_format((float)$x['amount'], 2) . " ({$x['category']})", ($me['full_name'] ?: $me['username']) . ' deleted an operating expense.',
                    [['title' => 'Deleted expense', 'pairs' => [['Date', $x['spent_on']], ['Category', $x['category']], ['Description', $x['description'] ?: '—'], ['Amount', 'R ' . number_format((float)$x['amount'], 2)]]]]);
                $msg = 'Expense deleted.';
            }
        } elseif ($do === 'vat') {
            set_setting('vat_rate', (string)max(0, min(50, (float)($_POST['vat_rate'] ?? 15))));
            set_setting('rates_include_vat', isset($_POST['rates_include_vat']) ? '1' : '0');
            $msg = 'VAT settings saved.';
        }
        try {
            flush_email_queue(5);
        } catch (Throwable $e) {
            error_log('[ess-iv-crm] ' . $e->getMessage());
        }
    }
}

/* ---------- period ---------- */
$preset = (string)($_GET['p'] ?? 'this_month');
$t = new DateTimeImmutable(today());
$q0 = fn(DateTimeImmutable $d) => $d->setDate((int)$d->format('Y'), intdiv((int)$d->format('n') - 1, 3) * 3 + 1, 1);
[$from, $to, $label] = match ($preset) {
    'last_month'   => [$t->modify('first day of last month'), $t->modify('last day of last month'), 'Last month'],
    'this_quarter' => [$q0($t), $q0($t)->modify('+3 months -1 day'), 'This quarter'],
    'last_quarter' => [$q0($t)->modify('-3 months'), $q0($t)->modify('-1 day'), 'Last quarter'],
    'ytd'          => [$t->setDate((int)$t->format('Y'), 1, 1), $t, 'Year to date'],
    'last_12'      => [$t->modify('first day of this month')->modify('-11 months'), $t->modify('last day of this month'), 'Last 12 months'],
    'custom'       => [new DateTimeImmutable((string)($_GET['from'] ?? $t->format('Y-m-01'))), new DateTimeImmutable((string)($_GET['to'] ?? $t->format('Y-m-d'))), 'Custom period'],
    default        => [$t->modify('first day of this month'), $t->modify('last day of this month'), 'This month'],
};
if ($to < $from) {
    [$from, $to] = [$to, $from];
}
$days = (int)$from->diff($to)->days + 1;
$prevTo = $from->modify('-1 day');
$prevFrom = $prevTo->modify('-' . ($days - 1) . ' days');

$vatRate = (float)(setting('vat_rate', '15') ?? 15);
$inclVat = setting('rates_include_vat', '1') === '1';
$exVat = fn(float $v) => $inclVat ? $v / (1 + $vatRate / 100) : $v;

/* ---------- figures ---------- */
function pnl(PDO $pdo, string $from, string $to, callable $exVat): array
{
    $end = date('Y-m-d', strtotime($to . ' +1 day'));
    // Nights of each stay that fall inside [from, end).
    $overlap = 'GREATEST(0, DATEDIFF(LEAST(check_out, ?), GREATEST(check_in, ?)))';
    $sql = "SELECT COALESCE(SUM(rate * $overlap),0) rev, COALESCE(SUM($overlap),0) nights FROM bookings WHERE status IN (%s) AND check_in < ? AND check_out > ?";
    $args = [$end, $from, $end, $from, $end, $from];
    $st = $pdo->prepare(sprintf($sql, "'Checked-in','Checked-out'"));
    $st->execute($args);
    $r = $st->fetch();
    $st = $pdo->prepare(sprintf($sql, "'Confirmed','Provisional'"));
    $st->execute($args);
    $otb = $st->fetch();

    $st = $pdo->prepare("SELECT status, COUNT(*) n, COALESCE(SUM(cancellation_fee),0) fees, COALESCE(SUM(total),0) value FROM bookings
        WHERE status IN ('Cancelled','No-show') AND cancelled_on BETWEEN ? AND ? GROUP BY status");
    $st->execute([$from, $to]);
    $canc = ['Cancelled' => ['n' => 0, 'fees' => 0, 'value' => 0], 'No-show' => ['n' => 0, 'fees' => 0, 'value' => 0]];
    foreach ($st as $c) {
        $canc[$c['status']] = ['n' => (int)$c['n'], 'fees' => (float)$c['fees'], 'value' => (float)$c['value']];
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE check_in BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    $arrivalsBooked = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COALESCE(SUM(actual_cost),0) FROM maintenance_tickets WHERE status IN ('Completed','Verified') AND completed_on BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $maint = (float)$st->fetchColumn();

    $st = $pdo->prepare('SELECT category, SUM(amount) amt FROM finance_expenses WHERE spent_on BETWEEN ? AND ? GROUP BY category ORDER BY amt DESC');
    $st->execute([$from, $to]);
    $opex = array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));

    $roomRev = $exVat((float)$r['rev']);
    $cancFees = $exVat($canc['Cancelled']['fees']);
    $noShowFees = $exVat($canc['No-show']['fees']);
    $income = $roomRev + $cancFees + $noShowFees;
    $expenses = $maint + array_sum($opex);
    $daysN = (int)((strtotime($end) - strtotime($from)) / 86400);
    $capacity = 600 * max(1, $daysN);
    return [
        'room_rev' => $roomRev, 'canc_fees' => $cancFees, 'noshow_fees' => $noShowFees, 'income' => $income,
        'maint' => $maint, 'opex' => $opex, 'expenses' => $expenses, 'net' => $income - $expenses,
        'nights' => (int)$r['nights'], 'adr' => $r['nights'] ? $roomRev / (int)$r['nights'] : 0,
        'occ' => (int)$r['nights'] / $capacity * 100, 'revpar' => $roomRev / $capacity,
        'canc' => $canc, 'lost' => $exVat($canc['Cancelled']['value'] + $canc['No-show']['value'] - $canc['Cancelled']['fees'] - $canc['No-show']['fees']),
        'canc_rate' => $arrivalsBooked ? ($canc['Cancelled']['n'] + $canc['No-show']['n']) / $arrivalsBooked * 100 : 0,
        'otb_rev' => $exVat((float)$otb['rev']), 'otb_nights' => (int)$otb['nights'],
    ];
}

$F = $from->format('Y-m-d');
$T = $to->format('Y-m-d');
$cur = pnl($pdo, $F, $T, $exVat);
$prev = pnl($pdo, $prevFrom->format('Y-m-d'), $prevTo->format('Y-m-d'), $exVat);

// Month by month (max 24 months)
$months = [];
for ($m = $from->modify('first day of this month'); $m <= $to && count($months) < 24; $m = $m->modify('+1 month')) {
    $ms = max($F, $m->format('Y-m-d'));
    $me_ = min($T, $m->modify('last day of this month')->format('Y-m-d'));
    $months[] = ['label' => $m->format('M Y'), 'p' => pnl($pdo, $ms, $me_, $exVat)];
}

// Room revenue by block and by guest type
$end = $to->modify('+1 day')->format('Y-m-d');
$ov = 'GREATEST(0, DATEDIFF(LEAST(b.check_out, ?), GREATEST(b.check_in, ?)))';
$st = $pdo->prepare("SELECT r.block k, SUM(b.rate * $ov) rev, SUM($ov) nights FROM bookings b JOIN rooms r ON r.id = b.room_id
    WHERE b.status IN ('Checked-in','Checked-out') AND b.check_in < ? AND b.check_out > ? GROUP BY r.block ORDER BY r.block");
$st->execute([$end, $F, $end, $F, $end, $F]);
$byBlock = $st->fetchAll();
$st = $pdo->prepare("SELECT COALESCE(NULLIF(b.company,''),'(none)') k, SUM(b.rate * $ov) rev, SUM($ov) nights FROM bookings b
    WHERE b.status IN ('Checked-in','Checked-out') AND b.check_in < ? AND b.check_out > ? GROUP BY k ORDER BY rev DESC LIMIT 10");
$st->execute([$end, $F, $end, $F, $end, $F]);
$byCompany = $st->fetchAll();

$st = $pdo->prepare("SELECT ref, guest_name, company, room_id, check_in, check_out, status, total, cancellation_fee, cancelled_on FROM bookings
    WHERE status IN ('Cancelled','No-show') AND cancelled_on BETWEEN ? AND ? ORDER BY cancelled_on DESC LIMIT 200");
$st->execute([$F, $T]);
$cancList = $st->fetchAll();

$st = $pdo->prepare('SELECT e.*, u.username FROM finance_expenses e LEFT JOIN users u ON u.id = e.created_by WHERE spent_on BETWEEN ? AND ? ORDER BY spent_on DESC, id DESC LIMIT 300');
$st->execute([$F, $T]);
$expenses = $st->fetchAll();

$R = fn(float $v) => 'R ' . number_format($v, 2, '.', ' ');
$R0 = fn(float $v) => 'R ' . number_format($v, 0, '.', ' '); // whole rands for the summary tiles
$chg = function (float $now, float $was, bool $costLine = false): string {
    if (abs($was) < 0.005) {
        return '<span class="muted">—</span>';
    }
    $pct = ($now - $was) / abs($was) * 100;
    $good = $costLine ? $pct <= 0 : $pct >= 0;
    return '<span style="color:var(' . ($good ? '--vac' : '--oos') . ')">' . ($pct >= 0 ? '+' : '') . number_format($pct, 1) . '%</span>';
};

/* ---------- CSV export ---------- */
function csv_row($fh, array $row): void
{
    fputcsv($fh, $row, ',', '"', ''); // explicit escape: RFC 4180 and no deprecation notice on PHP 8.4+
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ess-iv-pnl-' . $F . '-to-' . $T . '.csv"');
    $o = fopen('php://output', 'w');
    csv_row($o, ['ESS IV Profit & Loss', $label, $F, $T, $inclVat ? "Income excl. VAT ({$vatRate}%)" : 'Income as charged'], ",", "\"", "");
    csv_row($o, [], ",", "\"", "");
    csv_row($o, ['Line', 'This period', 'Previous period'], ",", "\"", "");
    $lines = [['Room revenue', $cur['room_rev'], $prev['room_rev']], ['Cancellation fees retained', $cur['canc_fees'], $prev['canc_fees']],
        ['No-show fees retained', $cur['noshow_fees'], $prev['noshow_fees']], ['TOTAL INCOME', $cur['income'], $prev['income']],
        ['Maintenance (completed tickets)', $cur['maint'], $prev['maint']]];
    foreach (array_unique(array_merge(array_keys($cur['opex']), array_keys($prev['opex']))) as $c) {
        $lines[] = [$c, $cur['opex'][$c] ?? 0, $prev['opex'][$c] ?? 0];
    }
    $lines[] = ['TOTAL EXPENSES', $cur['expenses'], $prev['expenses']];
    $lines[] = ['NET PROFIT / (LOSS)', $cur['net'], $prev['net']];
    $lines[] = ['Revenue lost to cancellations & no-shows', $cur['lost'], $prev['lost']];
    foreach ($lines as $l) {
        csv_row($o, [$l[0], number_format($l[1], 2, '.', ''), number_format($l[2], 2, '.', '')], ",", "\"", "");
    }
    csv_row($o, [], ",", "\"", "");
    csv_row($o, ['Month', 'Room revenue', 'Cancellation & no-show fees', 'Maintenance', 'Operating expenses', 'Net profit', 'Room nights', 'Occupancy %'], ",", "\"", "");
    foreach ($months as $m) {
        $p = $m['p'];
        csv_row($o, [$m['label'], number_format($p['room_rev'], 2, '.', ''), number_format($p['canc_fees'] + $p['noshow_fees'], 2, '.', ''), number_format($p['maint'], 2, '.', ''),
            number_format(array_sum($p['opex']), 2, '.', ''), number_format($p['net'], 2, '.', ''), $p['nights'], number_format($p['occ'], 1)]);
    }
    exit;
}

$csrf = csrf_token();
$qs = fn(array $extra) => '?' . http_build_query(array_merge(['p' => $preset, 'from' => $F, 'to' => $T], $extra));
page_open('Profit & Loss · ESS IV CRM');
?>
<main class="wrap" style="padding-block:24px 40px;display:flex;flex-direction:column;gap:18px">
  <div class="topbar" style="padding:0"><div class="brand"><h1>ESS IV</h1><span>Finance · Profit &amp; Loss</span></div><span class="chip"><?= $canEdit ? 'Finance: can edit' : 'Finance: view only' ?></span><a class="btn" href="index.php">Back to the CRM</a></div>
  <?php if ($msg): ?><div class="banner"><p><?= h($msg) ?></p></div><?php endif ?>
  <?php if ($err): ?><p class="err" role="alert"><?= h($err) ?></p><?php endif ?>

  <section class="panel">
    <form method="get" class="filters">
      <select name="p" id="p" aria-label="Period" onchange="document.getElementById('custom').hidden=this.value!=='custom';if(this.value!=='custom')this.form.submit()">
        <?php foreach (['this_month' => 'This month', 'last_month' => 'Last month', 'this_quarter' => 'This quarter', 'last_quarter' => 'Last quarter', 'ytd' => 'Year to date', 'last_12' => 'Last 12 months', 'custom' => 'Custom dates…'] as $k => $v): ?>
          <option value="<?= $k ?>" <?= $preset === $k ? 'selected' : '' ?>><?= h($v) ?></option>
        <?php endforeach ?>
      </select>
      <span id="custom" class="filters" <?= $preset === 'custom' ? '' : 'hidden' ?>><input type="date" name="from" value="<?= h($F) ?>" aria-label="From"><input type="date" name="to" value="<?= h($T) ?>" aria-label="To"><button class="btn">Show</button></span>
      <span class="muted"><?= h($from->format('j M Y')) ?> – <?= h($to->format('j M Y')) ?> · <?= $days ?> days · compared with <?= h($prevFrom->format('j M')) ?> – <?= h($prevTo->format('j M Y')) ?></span>
      <a class="btn" style="margin-left:auto" href="<?= h($qs(['export' => 'csv'])) ?>">Export CSV</a>
    </form>
  </section>

  <div class="kpis fin">
    <div class="kpi" style="--k:var(--accent)"><div class="v"><?= $R0($cur['income']) ?></div><div class="l">Total income <?= $chg($cur['income'], $prev['income']) ?></div></div>
    <div class="kpi" style="--k:var(--mnt)"><div class="v"><?= $R0($cur['expenses']) ?></div><div class="l">Total expenses <?= $chg($cur['expenses'], $prev['expenses'], true) ?></div></div>
    <div class="kpi" style="--k:<?= $cur['net'] >= 0 ? 'var(--vac)' : 'var(--oos)' ?>"><div class="v"><?= $R0($cur['net']) ?></div><div class="l">Net profit <?= $chg($cur['net'], $prev['net']) ?></div></div>
    <div class="kpi" style="--k:var(--occ)"><div class="v"><?= number_format($cur['occ'], 1) ?>%</div><div class="l">Occupancy · <?= number_format($cur['nights']) ?> room nights</div></div>
    <div class="kpi" style="--k:var(--occ)"><div class="v"><?= $R0($cur['adr']) ?></div><div class="l">Average rate per night (ADR)</div></div>
    <div class="kpi" style="--k:var(--occ)"><div class="v"><?= $R0($cur['revpar']) ?></div><div class="l">Revenue per available room (RevPAR)</div></div>
    <div class="kpi" style="--k:var(--oos)"><div class="v"><?= number_format($cur['canc_rate'], 1) ?>%</div><div class="l">Cancellation &amp; no-show rate</div></div>
    <div class="kpi" style="--k:var(--oos)"><div class="v"><?= $R0($cur['lost']) ?></div><div class="l">Revenue lost to cancellations</div></div>
  </div>

  <div class="two">
    <section class="panel"><div class="panel-head"><h2 class="grow">Profit &amp; Loss statement</h2><span class="chip"><?= $inclVat ? 'Income excl. VAT (' . h((string)$vatRate) . '%)' : 'Income as charged' ?></span></div>
      <div class="tablewrap"><table class="pnl">
        <thead><tr><th></th><th class="r">This period</th><th class="r">Previous</th><th class="r">Change</th></tr></thead>
        <tbody>
          <tr class="sec"><td colspan="4">Income</td></tr>
          <tr><td>Room revenue <span class="muted">(nights stayed × rate)</span></td><td class="num r"><?= $R($cur['room_rev']) ?></td><td class="num r"><?= $R($prev['room_rev']) ?></td><td class="r"><?= $chg($cur['room_rev'], $prev['room_rev']) ?></td></tr>
          <tr><td>Cancellation fees retained <span class="muted">(<?= $cur['canc']['Cancelled']['n'] ?> cancellations)</span></td><td class="num r"><?= $R($cur['canc_fees']) ?></td><td class="num r"><?= $R($prev['canc_fees']) ?></td><td class="r"><?= $chg($cur['canc_fees'], $prev['canc_fees']) ?></td></tr>
          <tr><td>No-show fees retained <span class="muted">(<?= $cur['canc']['No-show']['n'] ?> no-shows)</span></td><td class="num r"><?= $R($cur['noshow_fees']) ?></td><td class="num r"><?= $R($prev['noshow_fees']) ?></td><td class="r"><?= $chg($cur['noshow_fees'], $prev['noshow_fees']) ?></td></tr>
          <tr class="tot"><td>Total income</td><td class="num r"><?= $R($cur['income']) ?></td><td class="num r"><?= $R($prev['income']) ?></td><td class="r"><?= $chg($cur['income'], $prev['income']) ?></td></tr>
          <tr class="sec"><td colspan="4">Expenses</td></tr>
          <tr><td>Maintenance <span class="muted">(completed tickets, actual cost)</span></td><td class="num r"><?= $R($cur['maint']) ?></td><td class="num r"><?= $R($prev['maint']) ?></td><td class="r"><?= $chg($cur['maint'], $prev['maint'], true) ?></td></tr>
          <?php foreach (array_unique(array_merge(array_keys($cur['opex']), array_keys($prev['opex']))) as $c): ?>
          <tr><td><?= h($c) ?></td><td class="num r"><?= $R($cur['opex'][$c] ?? 0) ?></td><td class="num r"><?= $R($prev['opex'][$c] ?? 0) ?></td><td class="r"><?= $chg($cur['opex'][$c] ?? 0, $prev['opex'][$c] ?? 0, true) ?></td></tr>
          <?php endforeach ?>
          <tr class="tot"><td>Total expenses</td><td class="num r"><?= $R($cur['expenses']) ?></td><td class="num r"><?= $R($prev['expenses']) ?></td><td class="r"><?= $chg($cur['expenses'], $prev['expenses'], true) ?></td></tr>
          <tr class="net <?= $cur['net'] >= 0 ? 'pos' : 'neg' ?>"><td>Net profit / (loss)</td><td class="num r"><?= $R($cur['net']) ?></td><td class="num r"><?= $R($prev['net']) ?></td><td class="r"><?= $chg($cur['net'], $prev['net']) ?></td></tr>
          <tr class="sec"><td colspan="4">For information (not in the totals)</td></tr>
          <tr><td>Booking value lost to cancellations &amp; no-shows, after fees</td><td class="num r"><?= $R($cur['lost']) ?></td><td class="num r"><?= $R($prev['lost']) ?></td><td></td></tr>
          <tr><td>On the books: confirmed stays in this period not yet checked in <span class="muted">(<?= number_format($cur['otb_nights']) ?> nights)</span></td><td class="num r"><?= $R($cur['otb_rev']) ?></td><td class="num r"><?= $R($prev['otb_rev']) ?></td><td></td></tr>
        </tbody>
      </table></div>
      <p class="muted" style="font-size:12px;margin-bottom:0">Room revenue counts each night a guest actually stayed (checked in or checked out) inside the period, at the booking's nightly rate. Cancellation and no-show fees count on the date of cancellation. Maintenance counts the actual cost of tickets completed in the period.</p>
    </section>

    <div class="grid">
      <section class="panel"><div class="panel-head"><h2 class="grow">Month by month</h2></div>
        <?php $maxv = max(1, ...array_map(fn($m) => max($m['p']['income'], $m['p']['expenses']), $months)); ?>
        <div class="mbars">
          <?php foreach ($months as $m): $p = $m['p']; ?>
            <div class="mrow"><span class="mono"><?= h($m['label']) ?></span>
              <div class="mtrack"><i style="width:<?= round($p['income'] / $maxv * 100, 1) ?>%;background:var(--accent)" title="Income <?= h($R($p['income'])) ?>"></i><i style="width:<?= round($p['expenses'] / $maxv * 100, 1) ?>%;background:var(--mnt)" title="Expenses <?= h($R($p['expenses'])) ?>"></i></div>
              <span class="num r" style="color:var(<?= $p['net'] >= 0 ? '--vac' : '--oos' ?>)"><?= $R($p['net']) ?></span></div>
          <?php endforeach ?>
        </div>
        <div class="legend" style="margin-top:10px"><span><b style="background:var(--accent)"></b>Income</span><span><b style="background:var(--mnt)"></b>Expenses</span><span>Right: net profit</span></div>
      </section>
      <section class="panel"><div class="panel-head"><h2 class="grow">Room revenue by block</h2></div>
        <div class="tablewrap"><table><thead><tr><th>Block</th><th class="r">Room nights</th><th class="r">Revenue</th><th class="r">Occupancy</th></tr></thead><tbody>
          <?php foreach ($byBlock as $b): ?><tr><td>Block <?= h($b['k']) ?></td><td class="num r"><?= number_format((int)$b['nights']) ?></td><td class="num r"><?= $R($exVat((float)$b['rev'])) ?></td><td class="num r"><?= number_format((int)$b['nights'] / (100 * $days) * 100, 1) ?>%</td></tr><?php endforeach ?>
        </tbody></table><?php if (!$byBlock): ?><div class="empty">No stays in this period.</div><?php endif ?></div>
      </section>
      <section class="panel"><div class="panel-head"><h2 class="grow">Top companies</h2></div>
        <div class="tablewrap"><table><thead><tr><th>Company</th><th class="r">Room nights</th><th class="r">Revenue</th></tr></thead><tbody>
          <?php foreach ($byCompany as $b): ?><tr><td><?= h($b['k']) ?></td><td class="num r"><?= number_format((int)$b['nights']) ?></td><td class="num r"><?= $R($exVat((float)$b['rev'])) ?></td></tr><?php endforeach ?>
        </tbody></table></div>
      </section>
    </div>
  </div>

  <section class="panel"><div class="panel-head"><h2 class="grow">Cancellations &amp; no-shows</h2><span class="chip"><?= count($cancList) ?> in period</span></div>
    <div class="tablewrap"><table><thead><tr><th>Cancelled on</th><th>Ref</th><th>Guest</th><th>Company</th><th>Room</th><th>Stay</th><th>Status</th><th class="r">Booking value</th><th class="r">Fee retained</th></tr></thead><tbody>
    <?php foreach ($cancList as $c): ?>
      <tr><td class="mono"><?= h(date('j M Y', strtotime($c['cancelled_on']))) ?></td><td class="mono"><?= h($c['ref']) ?></td><td><?= h($c['guest_name']) ?></td><td><?= h($c['company']) ?></td><td class="mono"><?= h($c['room_id']) ?></td>
      <td class="mono"><?= h(date('j M', strtotime($c['check_in']))) ?> – <?= h(date('j M', strtotime($c['check_out']))) ?></td><td><span class="pill s-oos"><?= h($c['status']) ?></span></td><td class="num r"><?= $R((float)$c['total']) ?></td><td class="num r"><?= $R((float)$c['cancellation_fee']) ?></td></tr>
    <?php endforeach ?>
    </tbody></table><?php if (!$cancList): ?><div class="empty">No cancellations or no-shows in this period.</div><?php endif ?></div>
  </section>

  <section class="panel"><div class="panel-head"><h2 class="grow">Operating expenses</h2><span class="chip"><?= $R(array_sum(array_map(fn($e) => (float)$e['amount'], $expenses))) ?> in period</span></div>
    <?php if ($canEdit): ?>
    <form method="post" style="margin-bottom:14px"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="add_expense">
      <fieldset><legend>Record an expense</legend>
        <div class="field"><label for="spent_on">Date</label><input type="date" id="spent_on" name="spent_on" value="<?= h(today()) ?>" required></div>
        <div class="field"><label for="category">Category</label><select id="category" name="category"><?php foreach (EXPENSE_CATEGORIES as $c): ?><option><?= h($c) ?></option><?php endforeach ?></select></div>
        <div class="field"><label for="amount">Amount (R)</label><input type="number" id="amount" name="amount" min="0.01" step="0.01" required></div>
        <div class="field"><label for="supplier">Supplier</label><input id="supplier" name="supplier"></div>
        <div class="field span2"><label for="description">Description</label><input id="description" name="description"></div>
        <div class="spanall"><button class="btn primary">Add expense</button></div>
      </fieldset>
    </form>
    <?php endif ?>
    <div class="tablewrap"><table><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Supplier</th><th class="r">Amount</th><th>Entered by</th><?= $canEdit ? '<th></th>' : '' ?></tr></thead><tbody>
    <?php foreach ($expenses as $e): ?>
      <tr><td class="mono"><?= h(date('j M Y', strtotime($e['spent_on']))) ?></td><td><?= h($e['category']) ?><?= $e['example'] ? ' <span class="pill s-muted">example</span>' : '' ?></td><td><?= h($e['description']) ?></td><td><?= h($e['supplier']) ?></td><td class="num r"><?= $R((float)$e['amount']) ?></td><td class="muted"><?= h($e['username'] ?? '') ?></td>
      <?php if ($canEdit): ?><td><form method="post" class="inline-form" onsubmit="return confirmDelete(this)"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="delete_expense"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><button class="btn sm danger">Delete</button></form></td><?php endif ?></tr>
    <?php endforeach ?>
    </tbody></table><?php if (!$expenses): ?><div class="empty">No expenses recorded in this period.</div><?php endif ?></div>
  </section>

  <?php if ($canEdit): ?>
  <section class="panel"><div class="panel-head"><h2 class="grow">VAT</h2></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="vat">
      <fieldset><legend>How room rates are entered</legend>
        <label class="check spanall"><input type="checkbox" name="rates_include_vat" <?= $inclVat ? 'checked' : '' ?>> <span>Room rates and fees include VAT. The P&amp;L shows income excluding VAT.</span></label>
        <div class="field"><label for="vat_rate">VAT rate (%)</label><input type="number" id="vat_rate" name="vat_rate" min="0" max="50" step="0.5" value="<?= h((string)$vatRate) ?>"></div>
        <div class="spanall"><button class="btn">Save VAT settings</button></div>
      </fieldset>
    </form>
  </section>
  <?php endif ?>
</main>
<script>
function confirmDelete(f){
  if(f.dataset.ok)return true;
  const b=f.querySelector('button');b.textContent='Click again to delete';f.dataset.ok='1';
  setTimeout(()=>{delete f.dataset.ok;b.textContent='Delete'},4000);return false;
}
</script>
<?php
page_close();
