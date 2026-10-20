<?php
/** Stock & purchasing: items, movements (receive / issue / count / waste), purchase orders and usage. */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/layout.php';
require __DIR__ . '/app/mailer.php';
require __DIR__ . '/app/ops.php';

$me = require_login();
if (!can($me, 'stock')) {
    http_response_code(403);
    exit('You do not have access to Stock & purchasing. Ask an administrator.');
}
$edit = can($me, 'stock', 'edit');
$pdo = db();
$view = in_array($_GET['view'] ?? '', ['items', 'orders', 'movements', 'usage'], true) ? $_GET['view'] : 'items';

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_post_csrf();
    if (!$edit) {
        redirect_with('You have view-only access to Stock & purchasing.', false);
    }
    $do = (string)($_POST['do'] ?? '');
    try {
        switch ($do) {
            case 'save_item':
                $id = (int)($_POST['id'] ?? 0);
                $f = [
                    'sku' => strtoupper(trim((string)($_POST['sku'] ?? ''))), 'name' => trim((string)($_POST['name'] ?? '')),
                    'category' => in_array($_POST['category'] ?? '', STOCK_CATEGORIES, true) ? $_POST['category'] : '',
                    'department' => in_array($_POST['department'] ?? '', STOCK_DEPARTMENTS, true) ? $_POST['department'] : 'General',
                    'unit' => in_array($_POST['unit'] ?? '', STOCK_UNITS, true) ? $_POST['unit'] : 'each',
                    'reorder_level' => max(0, (float)($_POST['reorder_level'] ?? 0)), 'reorder_qty' => max(0, (float)($_POST['reorder_qty'] ?? 0)),
                    'unit_cost' => max(0, round((float)($_POST['unit_cost'] ?? 0), 2)), 'supplier' => trim((string)($_POST['supplier'] ?? '')),
                    'location' => trim((string)($_POST['location'] ?? '')), 'expiry_date' => ($_POST['expiry_date'] ?? '') ?: null,
                ];
                if ($f['name'] === '' || $f['category'] === '') {
                    redirect_with('Enter the item name and choose a category.', false);
                }
                if ($f['sku'] === '') {
                    $f['sku'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $f['category']), 0, 3)) . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
                }
                if ($id) {
                    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($f)));
                    $pdo->prepare("UPDATE stock_items SET $set WHERE id = ?")->execute([...array_values($f), $id]);
                    redirect_with("Saved {$f['name']}.", true, 'stock.php');
                }
                $f['qty'] = 0;
                $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($f)));
                $pdo->prepare("INSERT INTO stock_items ($cols) VALUES (" . implode(', ', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
                $newId = (int)$pdo->lastInsertId();
                if (($open = (float)($_POST['opening_qty'] ?? 0)) > 0) {
                    stock_move($newId, 'receive', $open, $me, ['note' => 'Opening stock', 'unit_cost' => $f['unit_cost']]);
                }
                redirect_with("Added {$f['name']} to stock.", true, 'stock.php');
            case 'move':
                $item = (int)($_POST['item_id'] ?? 0);
                $type = (string)($_POST['type'] ?? '');
                $qty = (float)($_POST['qty'] ?? 0);
                if (!in_array($type, ['receive', 'issue', 'waste', 'count'], true) || ($qty <= 0 && $type !== 'count') || $qty < 0) {
                    redirect_with('Choose what happened and enter a quantity.', false);
                }
                if ($type === 'count') { // stock-take: set the level to what was counted
                    $st = $pdo->prepare('SELECT qty FROM stock_items WHERE id = ?');
                    $st->execute([$item]);
                    $delta = $qty - (float)$st->fetchColumn();
                    stock_move($item, 'adjust', $delta, $me, ['note' => trim('Stock count. ' . ($_POST['note'] ?? ''))]);
                    redirect_with('Stock count recorded (' . ($delta >= 0 ? '+' : '') . qty_fmt($delta) . ').');
                }
                $delta = $type === 'receive' ? $qty : -$qty;
                $opt = ['issued_to' => $type === 'issue' ? (string)($_POST['issued_to'] ?? '') : '', 'reference' => (string)($_POST['reference'] ?? ''), 'note' => (string)($_POST['note'] ?? '')];
                if ($type === 'receive' && ($c = (float)($_POST['unit_cost'] ?? 0)) > 0) {
                    $opt['unit_cost'] = $c;
                }
                if ($type === 'receive' && !empty($_POST['expiry_date'])) {
                    $opt['expiry_date'] = $_POST['expiry_date'];
                }
                if ($type === 'issue' && $opt['issued_to'] === '') {
                    redirect_with('Choose who the stock was issued to.', false);
                }
                $r = stock_move($item, $type, $delta, $me, $opt);
                if ($type === 'receive' && isset($opt['unit_cost'])) { // a delivery outside a purchase order still counts as spend
                    $pdo->prepare('INSERT INTO finance_expenses (spent_on, category, description, supplier, amount, source, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                        ->execute([today(), expense_category_for($r['item']['category']), 'Stock received: ' . $r['item']['name'], $r['item']['supplier'], round($qty * $opt['unit_cost'], 2), 'stock', $me['id']]);
                }
                redirect_with(ucfirst($type === 'issue' ? 'issued' : ($type === 'waste' ? 'written off' : 'received')) . ': ' . qty_fmt($qty) . ' ' . $r['item']['unit'] . ' ' . $r['item']['name'] . '. Now ' . qty_fmt($r['after']) . ' in stock.');
            case 'reorder':
                $nos = create_reorder_pos($me);
                redirect_with($nos ? 'Draft purchase orders created: ' . implode(', ', $nos) . '. Check them, then mark them Ordered.' : 'Nothing needs reordering (or it is already on an open order).', true, 'stock.php?view=orders');
            case 'po_create':
                $sup = trim((string)($_POST['supplier'] ?? ''));
                $lines = [];
                foreach ((array)($_POST['line_item'] ?? []) as $k => $iid) {
                    $q = (float)($_POST['line_qty'][$k] ?? 0);
                    if ((int)$iid && $q > 0) {
                        $lines[] = [(int)$iid, $q, (float)($_POST['line_cost'][$k] ?? 0)];
                    }
                }
                if ($sup === '' || !$lines) {
                    redirect_with('Enter the supplier and at least one item with a quantity.', false);
                }
                $no = next_po_no();
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO purchase_orders (po_no, supplier, status, expected_on, notes, created_by) VALUES (?, ?, 'Draft', ?, ?, ?)")
                    ->execute([$no, $sup, ($_POST['expected_on'] ?? '') ?: null, trim((string)($_POST['notes'] ?? '')), $me['id']]);
                $poId = (int)$pdo->lastInsertId();
                // A blank unit cost uses the item's usual cost.
                $ins = $pdo->prepare('INSERT INTO purchase_order_lines (po_id, item_id, qty, unit_cost) SELECT ?, id, ?, IF(? > 0, ?, unit_cost) FROM stock_items WHERE id = ?');
                foreach ($lines as [$iid, $q, $c]) {
                    $ins->execute([$poId, $q, $c, $c, $iid]);
                }
                po_recalc($poId);
                $pdo->commit();
                redirect_with("Purchase order $no created as a draft.", true, "stock.php?view=orders&po=$poId");
            case 'po_order':
                $poId = (int)($_POST['po_id'] ?? 0);
                $pdo->prepare("UPDATE purchase_orders SET status = 'Ordered', ordered_on = ?, expected_on = ? WHERE id = ? AND status = 'Draft'")
                    ->execute([today(), ($_POST['expected_on'] ?? '') ?: date('Y-m-d', strtotime('+3 days')), $poId]);
                $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
                $st->execute([$poId]);
                $po = $st->fetch();
                queue_email('notice', "Purchase order placed: {$po['po_no']} with {$po['supplier']} (" . rands((float)$po['total']) . ')',
                    ($me['full_name'] ?: $me['username']) . ' placed a purchase order.', [['title' => 'Order', 'pairs' => [['Order', $po['po_no']], ['Supplier', $po['supplier']], ['Total', rands((float)$po['total'])], ['Expected', date('D j M Y', strtotime($po['expected_on']))]]]]);
                redirect_with("{$po['po_no']} marked as ordered.", true, "stock.php?view=orders&po=$poId");
            case 'po_receive':
                $poId = (int)($_POST['po_id'] ?? 0);
                receive_po($poId, array_map('floatval', (array)($_POST['recv'] ?? [])), ($_POST['received_on'] ?? '') ?: today(), $me);
                redirect_with('Delivery received into stock, and the cost added to the Profit & Loss.', true, "stock.php?view=orders&po=$poId");
            case 'alert_settings':
                set_setting('stock_warn_pct', (string)max(0, min(200, (int)($_POST['warn_pct'] ?? 25))));
                set_setting('stock_cover_days', (string)max(0, min(60, (int)($_POST['cover_days'] ?? 5))));
                redirect_with('Stock alert settings saved.');
            case 'po_cancel':
                $poId = (int)($_POST['po_id'] ?? 0);
                $pdo->prepare("UPDATE purchase_orders SET status = 'Cancelled' WHERE id = ? AND status IN ('Draft','Ordered')")->execute([$poId]);
                redirect_with('Purchase order cancelled.', true, 'stock.php?view=orders');
        }
    } catch (PDOException $e) { // database problems: log the detail, show a plain message
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[ess-iv-crm] stock: ' . $e);
        redirect_with('The change could not be saved because of a database error. Nothing was changed.', false);
    } catch (RuntimeException $e) { // business rules, e.g. not enough stock
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect_with($e->getMessage(), false);
    }
    redirect_with('Nothing to do.', false);
}

/* ---------------- data ---------------- */
$q = trim((string)($_GET['q'] ?? ''));
$fCat = (string)($_GET['cat'] ?? '');
$fDept = (string)($_GET['dept'] ?? '');
$low = isset($_GET['low']);
$where = ['active = 1'];
$args = [];
if ($q !== '') {
    $where[] = '(name LIKE ? OR sku LIKE ? OR supplier LIKE ?)';
    array_push($args, "%$q%", "%$q%", "%$q%");
}
if (in_array($fCat, STOCK_CATEGORIES, true)) {
    $where[] = 'category = ?';
    $args[] = $fCat;
}
if (in_array($fDept, STOCK_DEPARTMENTS, true)) {
    $where[] = 'department = ?';
    $args[] = $fDept;
}
if ($low) {
    $where[] = 'qty <= reorder_level';
}
$st = $pdo->prepare('SELECT * FROM stock_items WHERE ' . implode(' AND ', $where) . ' ORDER BY (qty <= reorder_level) DESC, category, name');
$st->execute($args);
$items = $st->fetchAll();
$allItems = $pdo->query('SELECT id, name, unit, qty, unit_cost, supplier FROM stock_items WHERE active = 1 ORDER BY name')->fetchAll();
$alertRows = [];
foreach (stock_alert_rows() as $r) {
    $alertRows[(int)$r['id']] = $r;
}
$nearCount = count(array_filter($alertRows, fn($r) => $r['level'] === 'near'));
$lowCount = (int)$pdo->query('SELECT COUNT(*) FROM stock_items WHERE active = 1 AND qty <= reorder_level AND reorder_level > 0')->fetchColumn();
$expiring = (int)$pdo->query("SELECT COUNT(*) FROM stock_items WHERE active = 1 AND qty > 0 AND expiry_date IS NOT NULL AND expiry_date <= CURDATE() + INTERVAL 3 DAY")->fetchColumn();
$openPos = $pdo->query("SELECT status, COUNT(*) n, SUM(total) t FROM purchase_orders WHERE status IN ('Draft','Ordered') GROUP BY status")->fetchAll(PDO::FETCH_UNIQUE);
$editItem = null;
if (isset($_GET['edit'])) {
    $st = $pdo->prepare('SELECT * FROM stock_items WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $editItem = $st->fetch() ?: null;
}
$csrf = csrf_token();
$opts = fn(array $list, $sel = '', string $blank = '') => ($blank !== '' ? '<option value="">' . h($blank) . '</option>' : '') . implode('', array_map(fn($v) => '<option' . ((string)$sel === (string)$v ? ' selected' : '') . '>' . h((string)$v) . '</option>', $list));

ops_page_open($me, 'Stock & purchasing', 'stock', 'Operations · Stock & purchasing');
echo flash();
?>
<div class="kpis">
  <div class="kpi" style="--k:var(--accent)"><div class="v"><?= rands(stock_value(), 0) ?></div><div class="l">Stock on hand (value)</div></div>
  <div class="kpi" style="--k:var(--oos)"><div class="v"><?= $lowCount ?></div><div class="l">Items at or below reorder level</div></div>
  <div class="kpi" style="--k:var(--dirty)"><div class="v"><?= $nearCount ?></div><div class="l">Close to reorder level</div></div>
  <div class="kpi" style="--k:var(--dirty)"><div class="v"><?= $expiring ?></div><div class="l">Expiring within 3 days</div></div>
  <div class="kpi" style="--k:var(--arr)"><div class="v"><?= (int)($openPos['Draft']['n'] ?? 0) ?> / <?= (int)($openPos['Ordered']['n'] ?? 0) ?></div><div class="l">Purchase orders: draft / on order</div></div>
</div>

<nav class="filters">
  <?php foreach (['items' => 'Stock items', 'orders' => 'Purchase orders', 'movements' => 'Movements', 'usage' => 'Usage report'] as $k => $l): ?>
    <a class="btn <?= $view === $k ? 'primary' : '' ?>" href="stock.php?view=<?= $k ?>"><?= h($l) ?></a>
  <?php endforeach ?>
</nav>

<?php if ($view === 'items'): ?>
  <?php if ($edit): ?>
  <section class="panel"><div class="panel-head"><h2 class="grow">Record a stock movement</h2></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="move">
      <fieldset><legend>What happened</legend>
        <div class="field span2"><label for="mv_item">Item</label><select id="mv_item" name="item_id" required><option value="">Select item</option><?php foreach ($allItems as $i): ?><option value="<?= (int)$i['id'] ?>"><?= h($i['name']) ?> (<?= qty_fmt((float)$i['qty']) ?> <?= h($i['unit']) ?>)</option><?php endforeach ?></select></div>
        <div class="field"><label for="mv_type">Movement</label><select id="mv_type" name="type"><option value="issue">Issue to a department / area</option><option value="receive">Receive (delivery without a PO)</option><option value="count">Stock count (set to counted quantity)</option><option value="waste">Waste / expired / damaged</option></select></div>
        <div class="field"><label for="mv_qty">Quantity</label><input id="mv_qty" name="qty" type="number" step="0.001" min="0" required></div>
        <div class="field"><label for="mv_to">Issued to</label><select id="mv_to" name="issued_to"><?= $opts(ISSUE_TO, '', 'Select') ?></select></div>
        <div class="field"><label for="mv_cost">Unit cost (deliveries)</label><input id="mv_cost" name="unit_cost" type="number" step="0.01" min="0"></div>
        <div class="field"><label for="mv_exp">Expiry date (deliveries)</label><input id="mv_exp" name="expiry_date" type="date"></div>
        <div class="field"><label for="mv_ref">Reference</label><input id="mv_ref" name="reference" placeholder="Invoice / requisition no."></div>
        <div class="field span2"><label for="mv_note">Note / reason</label><input id="mv_note" name="note"></div>
        <div class="spanall"><button class="btn primary">Record movement</button></div>
      </fieldset>
    </form>
  </section>
  <?php endif ?>

  <section class="panel"><div class="panel-head"><h2 class="grow">Stock items</h2>
    <form method="get" class="filters"><input type="hidden" name="view" value="items">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search name, code, supplier" aria-label="Search">
      <select name="cat" aria-label="Category"><?= $opts(STOCK_CATEGORIES, $fCat, 'All categories') ?></select>
      <select name="dept" aria-label="Department"><?= $opts(STOCK_DEPARTMENTS, $fDept, 'All departments') ?></select>
      <label class="check"><input type="checkbox" name="low" <?= $low ? 'checked' : '' ?>> Low only</label>
      <button class="btn">Filter</button>
    </form>
    <?php if ($edit): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn primary" name="do" value="reorder">Create orders for low stock</button></form><?php endif ?>
    </div>
    <div class="tablewrap"><table><thead><tr><th>Code</th><th>Item</th><th>Category</th><th>Dept</th><th class="r">In stock</th><th class="r">Reorder at</th><th class="r">Unit cost</th><th class="r">Value</th><th>Supplier</th><th>Location</th><th>Expiry</th><?= $edit ? '<th></th>' : '' ?></tr></thead><tbody>
    <?php foreach ($items as $i): $isLow = (float)$i['qty'] <= (float)$i['reorder_level'] && (float)$i['reorder_level'] > 0; $exp = $i['expiry_date'] && (float)$i['qty'] > 0 && $i['expiry_date'] <= date('Y-m-d', strtotime('+3 days')); ?>
      <tr>
        <td class="mono"><?= h($i['sku']) ?></td><td><b><?= h($i['name']) ?></b><?= $i['example'] ? ' <span class="pill s-muted">example</span>' : '' ?></td><td><?= h($i['category']) ?></td><td><?= h($i['department']) ?></td>
        <td class="num r"><?php $ar = $alertRows[(int)$i['id']] ?? null; ?><?= $isLow ? '<span class="pill s-oos">' . qty_fmt((float)$i['qty']) . ' ' . h($i['unit']) . '</span>' : ($ar ? '<span class="pill s-dirty" title="' . h(cover_text($ar['cover'])) . '">' . qty_fmt((float)$i['qty']) . ' ' . h($i['unit']) . '</span>' : qty_fmt((float)$i['qty']) . ' ' . h($i['unit'])) ?><?php if ($ar && $ar['cover'] !== null): ?><div class="muted" style="font-size:11px"><?= h(cover_text($ar['cover'])) ?></div><?php endif ?></td>
        <td class="num r"><?= qty_fmt((float)$i['reorder_level']) ?></td><td class="num r"><?= rands((float)$i['unit_cost']) ?></td><td class="num r"><?= rands((float)$i['qty'] * (float)$i['unit_cost']) ?></td>
        <td><?= h($i['supplier']) ?></td><td><?= h($i['location']) ?></td>
        <td class="mono"><?= $i['expiry_date'] ? ($exp ? '<span class="pill s-dirty">' . h(date('j M', strtotime($i['expiry_date']))) . '</span>' : h(date('j M Y', strtotime($i['expiry_date'])))) : '—' ?></td>
        <?php if ($edit): ?><td><a class="btn sm" href="stock.php?edit=<?= (int)$i['id'] ?>#itemform">Edit</a></td><?php endif ?>
      </tr>
    <?php endforeach ?>
    </tbody></table><?php if (!$items): ?><div class="empty">No stock items match. <?= $edit ? 'Add your first item below.' : '' ?></div><?php endif ?></div>
  </section>

  <?php if ($edit): ?>
  <section class="panel"><div class="panel-head"><h2 class="grow">Stock alert settings</h2></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="alert_settings">
      <fieldset><legend>When to warn (alerts are shown on the dashboards and emailed)</legend>
        <div class="field"><label for="warn_pct">"Getting close" warning: within this % above the reorder level</label><input id="warn_pct" name="warn_pct" type="number" min="0" max="200" value="<?= (int)(setting('stock_warn_pct', '25') ?? 25) ?>"></div>
        <div class="field"><label for="cover_days">Also warn when usage means stock runs out within (days)</label><input id="cover_days" name="cover_days" type="number" min="0" max="60" value="<?= (int)(setting('stock_cover_days', '5') ?? 5) ?>"></div>
        <div class="spanall"><button class="btn">Save alert settings</button></div>
      </fieldset>
    </form>
  </section>
  <?php endif ?>

  <?php if ($edit): $e = $editItem ?? []; ?>
  <section class="panel" id="itemform"><div class="panel-head"><h2 class="grow"><?= $editItem ? 'Edit ' . h($editItem['name']) : 'Add a stock item' ?></h2><?php if ($editItem): ?><a class="btn sm" href="stock.php">Cancel</a><?php endif ?></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="save_item"><input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
      <fieldset><legend>Item</legend>
        <div class="field span2"><label for="it_name">Name</label><input id="it_name" name="name" required value="<?= h($e['name'] ?? '') ?>"></div>
        <div class="field"><label for="it_sku">Code (blank = automatic)</label><input id="it_sku" name="sku" value="<?= h($e['sku'] ?? '') ?>"></div>
        <div class="field"><label for="it_cat">Category</label><select id="it_cat" name="category" required><?= $opts(STOCK_CATEGORIES, $e['category'] ?? '', 'Select') ?></select></div>
        <div class="field"><label for="it_dept">Department</label><select id="it_dept" name="department"><?= $opts(STOCK_DEPARTMENTS, $e['department'] ?? 'General') ?></select></div>
        <div class="field"><label for="it_unit">Unit</label><select id="it_unit" name="unit"><?= $opts(STOCK_UNITS, $e['unit'] ?? 'each') ?></select></div>
        <div class="field"><label for="it_rl">Reorder when at or below</label><input id="it_rl" name="reorder_level" type="number" step="0.001" min="0" value="<?= h((string)($e['reorder_level'] ?? '')) ?>"></div>
        <div class="field"><label for="it_rq">Usual order quantity</label><input id="it_rq" name="reorder_qty" type="number" step="0.001" min="0" value="<?= h((string)($e['reorder_qty'] ?? '')) ?>"></div>
        <div class="field"><label for="it_cost">Unit cost (R)</label><input id="it_cost" name="unit_cost" type="number" step="0.01" min="0" value="<?= h((string)($e['unit_cost'] ?? '')) ?>"></div>
        <div class="field"><label for="it_sup">Supplier</label><input id="it_sup" name="supplier" value="<?= h($e['supplier'] ?? '') ?>"></div>
        <div class="field"><label for="it_loc">Storage location</label><input id="it_loc" name="location" value="<?= h($e['location'] ?? '') ?>" placeholder="e.g. Dry store shelf 3"></div>
        <div class="field"><label for="it_exp">Expiry date (current batch)</label><input id="it_exp" name="expiry_date" type="date" value="<?= h($e['expiry_date'] ?? '') ?>"></div>
        <?php if (!$editItem): ?><div class="field"><label for="it_open">Opening quantity</label><input id="it_open" name="opening_qty" type="number" step="0.001" min="0"></div><?php endif ?>
        <div class="spanall"><button class="btn primary"><?= $editItem ? 'Save item' : 'Add item' ?></button></div>
      </fieldset>
    </form>
  </section>
  <?php endif ?>

<?php elseif ($view === 'orders'):
    $poId = (int)($_GET['po'] ?? 0);
    $pos = $pdo->query("SELECT * FROM purchase_orders ORDER BY FIELD(status,'Draft','Ordered','Received','Cancelled'), created_at DESC LIMIT 200")->fetchAll();
    $po = null;
    if ($poId) {
        $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ?');
        $st->execute([$poId]);
        $po = $st->fetch() ?: null;
        $st = $pdo->prepare('SELECT l.*, i.name, i.unit, i.qty stock FROM purchase_order_lines l JOIN stock_items i ON i.id = l.item_id WHERE l.po_id = ?');
        $st->execute([$poId]);
        $lines = $st->fetchAll();
    }
    $stc = ['Draft' => 's-muted', 'Ordered' => 's-arr', 'Received' => 's-vac', 'Cancelled' => 's-oos'];
?>
  <?php if ($po): ?>
  <section class="panel"><div class="panel-head"><h2 class="grow">Purchase order <?= h($po['po_no']) ?></h2><span class="pill <?= $stc[$po['status']] ?>"><?= h($po['status']) ?></span><a class="btn sm" href="stock.php?view=orders">All orders</a></div>
    <div class="summary" style="margin-bottom:10px"><span>Supplier <b><?= h($po['supplier']) ?></b></span><span>Ordered <b><?= $po['ordered_on'] ? h(date('j M Y', strtotime($po['ordered_on']))) : '—' ?></b></span><span>Expected <b><?= $po['expected_on'] ? h(date('j M Y', strtotime($po['expected_on']))) : '—' ?></b></span><span>Received <b><?= $po['received_on'] ? h(date('j M Y', strtotime($po['received_on']))) : '—' ?></b></span><span>Total <b><?= rands((float)$po['total']) ?></b></span></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
      <div class="tablewrap"><table><thead><tr><th>Item</th><th class="r">In stock now</th><th class="r">Ordered</th><th class="r">Unit cost</th><th class="r">Line total</th><th class="r">Received</th></tr></thead><tbody>
      <?php foreach ($lines as $l): ?>
        <tr><td><?= h($l['name']) ?></td><td class="num r"><?= qty_fmt((float)$l['stock']) ?> <?= h($l['unit']) ?></td><td class="num r"><?= qty_fmt((float)$l['qty']) ?> <?= h($l['unit']) ?></td><td class="num r"><?= rands((float)$l['unit_cost']) ?></td><td class="num r"><?= rands((float)$l['qty'] * (float)$l['unit_cost']) ?></td>
        <td class="r"><?php if ($edit && in_array($po['status'], ['Draft', 'Ordered'], true)): ?><input type="number" step="0.001" min="0" name="recv[<?= (int)$l['id'] ?>]" value="<?= h(qty_fmt((float)$l['qty'])) ?>" style="width:110px" aria-label="Received quantity of <?= h($l['name']) ?>"><?php else: ?><span class="num"><?= qty_fmt((float)$l['received_qty']) ?></span><?php endif ?></td></tr>
      <?php endforeach ?>
      </tbody></table></div>
      <?php if ($edit && in_array($po['status'], ['Draft', 'Ordered'], true)): ?>
      <div class="filters" style="margin-top:12px">
        <?php if ($po['status'] === 'Draft'): ?><label class="check">Expected delivery <input type="date" name="expected_on" value="<?= h(date('Y-m-d', strtotime('+3 days'))) ?>" style="width:auto"></label><button class="btn primary" name="do" value="po_order">Mark as ordered</button><?php endif ?>
        <label class="check">Received on <input type="date" name="received_on" value="<?= h(today()) ?>" style="width:auto"></label><button class="btn primary" name="do" value="po_receive">Receive into stock</button>
        <button class="btn danger" name="do" value="po_cancel">Cancel order</button>
      </div>
      <p class="muted" style="font-size:12px">Receiving adds the quantities above to stock, updates the average cost, and records the spend in the Profit &amp; Loss.</p>
      <?php endif ?>
    </form>
  </section>
  <?php endif ?>

  <section class="panel"><div class="panel-head"><h2 class="grow">Purchase orders</h2><?php if ($edit): ?><form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><button class="btn" name="do" value="reorder">Create orders for low stock</button></form><?php endif ?></div>
    <div class="tablewrap"><table><thead><tr><th>Order</th><th>Supplier</th><th>Status</th><th>Created</th><th>Expected</th><th>Received</th><th class="r">Total</th></tr></thead><tbody>
    <?php foreach ($pos as $p): $late = $p['status'] === 'Ordered' && $p['expected_on'] && $p['expected_on'] < today(); ?>
      <tr class="clickable" onclick="location.href='stock.php?view=orders&po=<?= (int)$p['id'] ?>'"><td class="mono"><a href="stock.php?view=orders&po=<?= (int)$p['id'] ?>"><?= h($p['po_no']) ?></a></td><td><?= h($p['supplier']) ?></td><td><span class="pill <?= $stc[$p['status']] ?>"><?= h($p['status']) ?></span><?= $late ? ' <span class="pill s-oos">late</span>' : '' ?></td>
      <td class="mono"><?= h(date('j M', strtotime($p['created_at']))) ?></td><td class="mono"><?= $p['expected_on'] ? h(date('j M', strtotime($p['expected_on']))) : '—' ?></td><td class="mono"><?= $p['received_on'] ? h(date('j M', strtotime($p['received_on']))) : '—' ?></td><td class="num r"><?= rands((float)$p['total']) ?></td></tr>
    <?php endforeach ?>
    </tbody></table><?php if (!$pos): ?><div class="empty">No purchase orders yet.</div><?php endif ?></div>
  </section>

  <?php if ($edit): ?>
  <section class="panel"><div class="panel-head"><h2 class="grow">New purchase order</h2></div>
    <form method="post"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="do" value="po_create">
      <fieldset><legend>Order</legend>
        <div class="field span2"><label for="po_sup">Supplier</label><input id="po_sup" name="supplier" required list="suppliers"><datalist id="suppliers"><?php foreach (array_unique(array_filter(array_column($allItems, 'supplier'))) as $s): ?><option value="<?= h($s) ?>"><?php endforeach ?></datalist></div>
        <div class="field"><label for="po_exp">Expected delivery</label><input id="po_exp" name="expected_on" type="date"></div>
        <div class="field"><label for="po_notes">Notes</label><input id="po_notes" name="notes"></div>
        <?php for ($k = 0; $k < 8; $k++): ?>
          <div class="field span2"><label for="pl_i<?= $k ?>">Item <?= $k + 1 ?></label><select id="pl_i<?= $k ?>" name="line_item[<?= $k ?>]"><option value="">—</option><?php foreach ($allItems as $i): ?><option value="<?= (int)$i['id'] ?>"><?= h($i['name']) ?> (<?= h($i['unit']) ?>)</option><?php endforeach ?></select></div>
          <div class="field"><label for="pl_q<?= $k ?>">Quantity</label><input id="pl_q<?= $k ?>" name="line_qty[<?= $k ?>]" type="number" step="0.001" min="0"></div>
          <div class="field"><label for="pl_c<?= $k ?>">Unit cost (blank = usual)</label><input id="pl_c<?= $k ?>" name="line_cost[<?= $k ?>]" type="number" step="0.01" min="0"></div>
        <?php endfor ?>
        <div class="spanall"><button class="btn primary">Create draft order</button></div>
      </fieldset>
    </form>
  </section>
  <?php endif ?>

<?php elseif ($view === 'movements'):
    $mv = $pdo->query('SELECT m.*, i.name, i.unit, u.username FROM stock_movements m JOIN stock_items i ON i.id = m.item_id LEFT JOIN users u ON u.id = m.user_id ORDER BY m.moved_at DESC, m.id DESC LIMIT 400')->fetchAll();
    $tc = ['receive' => 's-vac', 'issue' => 's-occ', 'adjust' => 's-muted', 'waste' => 's-oos'];
?>
  <section class="panel"><div class="panel-head"><h2 class="grow">Stock movements</h2><span class="chip">latest 400</span></div>
    <div class="tablewrap"><table><thead><tr><th>When</th><th>Item</th><th>Type</th><th class="r">Change</th><th class="r">Balance</th><th class="r">Value</th><th>Issued to</th><th>Reference</th><th>Note</th><th>By</th></tr></thead><tbody>
    <?php foreach ($mv as $m): ?>
      <tr><td class="mono"><?= h(date('j M H:i', strtotime($m['moved_at']))) ?></td><td><?= h($m['name']) ?></td><td><span class="pill <?= $tc[$m['type']] ?>"><?= h($m['type']) ?></span></td>
      <td class="num r"><?= ((float)$m['qty'] > 0 ? '+' : '') . qty_fmt((float)$m['qty']) ?> <?= h($m['unit']) ?></td><td class="num r"><?= qty_fmt((float)$m['qty_after']) ?></td><td class="num r"><?= rands(abs((float)$m['qty']) * (float)$m['unit_cost']) ?></td>
      <td><?= h($m['issued_to']) ?></td><td class="mono"><?= h($m['reference']) ?></td><td><?= h($m['note']) ?></td><td class="muted"><?= h($m['username'] ?? '') ?></td></tr>
    <?php endforeach ?>
    </tbody></table><?php if (!$mv): ?><div class="empty">No movements yet.</div><?php endif ?></div>
  </section>

<?php else:
    $days = max(1, min(365, (int)($_GET['days'] ?? 30)));
    $from = date('Y-m-d', strtotime("-{$days} days"));
    $st = $pdo->prepare("SELECT m.issued_to, i.category, SUM(-m.qty * m.unit_cost) cost FROM stock_movements m JOIN stock_items i ON i.id = m.item_id WHERE m.type = 'issue' AND m.moved_at >= ? GROUP BY m.issued_to, i.category");
    $st->execute([$from]);
    $byArea = [];
    foreach ($st as $r) {
        $byArea[$r['issued_to'] ?: '(not set)'][stock_group($r['category'])] = ($byArea[$r['issued_to'] ?: '(not set)'][stock_group($r['category'])] ?? 0) + (float)$r['cost'];
    }
    uasort($byArea, fn($a, $b) => array_sum($b) <=> array_sum($a));
    $st = $pdo->prepare("SELECT i.name, i.unit, i.category, SUM(-m.qty) used, SUM(-m.qty * m.unit_cost) cost FROM stock_movements m JOIN stock_items i ON i.id = m.item_id WHERE m.type IN ('issue','waste') AND m.moved_at >= ? GROUP BY i.id ORDER BY cost DESC LIMIT 25");
    $st->execute([$from]);
    $top = $st->fetchAll();
    $ops = ops_period($from, today());
    $groups = ['Catering', 'Cleaning', 'Amenities & linen', 'Maintenance'];
?>
  <section class="panel"><div class="panel-head"><h2 class="grow">Usage report</h2>
    <form method="get" class="filters"><input type="hidden" name="view" value="usage"><select name="days" onchange="this.form.submit()" aria-label="Period"><?php foreach ([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'] as $k => $v): ?><option value="<?= $k ?>" <?= $days === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach ?></select></form></div>
    <div class="summary" style="margin-bottom:12px"><span>Food cost per meal served <b><?= rands($ops['food_cost_per_meal']) ?></b></span><span>Cleaning supplies per occupied room-night <b><?= rands($ops['cleaning_per_room_night']) ?></b></span><span>Wastage <b><?= rands($ops['wasted']) ?></b></span></div>
    <h3 style="margin:8px 0">Issued by department / area</h3>
    <div class="tablewrap"><table><thead><tr><th>Issued to</th><?php foreach ($groups as $g): ?><th class="r"><?= h($g) ?></th><?php endforeach ?><th class="r">Total</th></tr></thead><tbody>
    <?php foreach ($byArea as $area => $g): ?><tr><td><?= h($area) ?></td><?php foreach ($groups as $gg): ?><td class="num r"><?= isset($g[$gg]) ? rands($g[$gg]) : '—' ?></td><?php endforeach ?><td class="num r"><b><?= rands(array_sum($g)) ?></b></td></tr><?php endforeach ?>
    </tbody></table><?php if (!$byArea): ?><div class="empty">Nothing issued in this period.</div><?php endif ?></div>
    <h3 style="margin:16px 0 8px">Most used items</h3>
    <div class="tablewrap"><table><thead><tr><th>Item</th><th>Category</th><th class="r">Used</th><th class="r">Cost</th></tr></thead><tbody>
    <?php foreach ($top as $t): ?><tr><td><?= h($t['name']) ?></td><td><?= h($t['category']) ?></td><td class="num r"><?= qty_fmt((float)$t['used']) ?> <?= h($t['unit']) ?></td><td class="num r"><?= rands((float)$t['cost']) ?></td></tr><?php endforeach ?>
    </tbody></table></div>
  </section>
<?php endif ?>
<?php
ops_page_close();
