<?php
declare(strict_types=1);

/**
 * Operations module: stock & purchasing, kitchen & catering, cleaning & housekeeping.
 * Shared rules used by operations.php, stock.php, catering.php, cleaning.php, finance.php and alerts.
 */

const STOCK_CATEGORIES = [
    'Food: dry goods', 'Food: fresh produce', 'Food: meat & poultry', 'Food: dairy & eggs', 'Food: frozen', 'Beverages',
    'Cleaning chemicals', 'Cleaning equipment', 'Paper & consumables', 'Guest amenities', 'Linen', 'Maintenance spares',
];
const STOCK_DEPARTMENTS = ['Kitchen', 'Housekeeping', 'Maintenance', 'General'];
const STOCK_UNITS = ['each', 'kg', 'g', 'L', 'ml', 'box', 'case', 'pack', 'roll', 'bag', 'tray', 'dozen'];
const ISSUE_TO = [
    'Kitchen', 'Dining hall', 'Housekeeping: Block A', 'Housekeeping: Block B', 'Housekeeping: Block C', 'Housekeeping: Block D',
    'Housekeeping: Block E', 'Housekeeping: Block F', 'Laundry', 'Common areas & ablutions', 'Maintenance', 'Reception', 'Other',
];
const MEALS = ['Breakfast', 'Lunch', 'Packed lunch', 'Dinner'];
const DEFAULT_TAKE_UP = ['Breakfast' => 85, 'Lunch' => 30, 'Packed lunch' => 55, 'Dinner' => 80];
const CLEANING_AREAS = [
    'Kitchen', 'Dining hall', 'Reception & lobby', 'Laundry', 'Ablutions & showers', 'Block A corridors & stairs', 'Block B corridors & stairs',
    'Block C corridors & stairs', 'Block D corridors & stairs', 'Block E corridors & stairs', 'Block F corridors & stairs', 'Grounds & parking', 'Gym & recreation',
];
/** Food-safety limits (°C), per South African R638 / common HACCP practice. */
const TEMP_RULES = [
    'Fridge' => ['max' => 5.0, 'label' => '0 to 5 °C'],
    'Freezer' => ['max' => -18.0, 'label' => '−18 °C or colder'],
    'Hot holding' => ['min' => 63.0, 'label' => '63 °C or hotter'],
    'Delivery (chilled)' => ['max' => 7.0, 'label' => '7 °C or colder'],
];

/** Which P&L line a purchase of this stock category lands on. */
function expense_category_for(string $stockCategory): string
{
    return match (true) {
        str_starts_with($stockCategory, 'Food') || $stockCategory === 'Beverages' => 'Catering: food & beverage',
        in_array($stockCategory, ['Cleaning chemicals', 'Cleaning equipment', 'Paper & consumables'], true) => 'Cleaning supplies',
        in_array($stockCategory, ['Guest amenities', 'Linen'], true) => 'Guest amenities & linen',
        default => 'Maintenance spares',
    };
}

/** The supply groups used on the operations dashboard and in Finance. */
function stock_group(string $category): string
{
    return match (expense_category_for($category)) {
        'Catering: food & beverage' => 'Catering',
        'Cleaning supplies' => 'Cleaning',
        'Guest amenities & linen' => 'Amenities & linen',
        default => 'Maintenance',
    };
}

function temp_ok(string $kind, float $t): bool
{
    $r = TEMP_RULES[$kind] ?? null;
    if (!$r) {
        return true;
    }
    if ($kind === 'Fridge' && $t < -1.0) {
        return false; // freezing the fridge contents is also a fault
    }
    return (!isset($r['max']) || $t <= $r['max']) && (!isset($r['min']) || $t >= $r['min']);
}

function qty_fmt(float $q): string
{
    return rtrim(rtrim(number_format($q, 3, '.', ' '), '0'), '.');
}

function rands(float $v, int $dec = 2): string
{
    return 'R ' . number_format($v, $dec, '.', ' ');
}

/**
 * Change a stock level and log the movement. $delta is signed.
 * Issues and waste are refused when they would take stock below zero.
 */
function stock_move(int $itemId, string $type, float $delta, array $user, array $opt = []): array
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('SELECT * FROM stock_items WHERE id = ? FOR UPDATE');
        $st->execute([$itemId]);
        $item = $st->fetch();
        if (!$item) {
            throw new RuntimeException('That stock item does not exist.');
        }
        $after = round((float)$item['qty'] + $delta, 3);
        if ($after < 0 && in_array($type, ['issue', 'waste'], true)) {
            throw new RuntimeException(sprintf('Only %s %s of %s in stock; you tried to take %s.', qty_fmt((float)$item['qty']), $item['unit'], $item['name'], qty_fmt(abs($delta))));
        }
        $after = max(0, $after);
        $cost = isset($opt['unit_cost']) ? (float)$opt['unit_cost'] : (float)$item['unit_cost'];
        $fields = ['qty = ?'];
        $args = [$after];
        if ($type === 'receive' && isset($opt['unit_cost']) && $opt['unit_cost'] > 0) {
            // Weighted average cost keeps stock valuation honest when prices change.
            $oldVal = max(0, (float)$item['qty']) * (float)$item['unit_cost'];
            $newCost = $after > 0 ? ($oldVal + $delta * $cost) / $after : $cost;
            $fields[] = 'unit_cost = ?';
            $args[] = round($newCost, 2);
        }
        if ($type === 'receive' && !empty($opt['expiry_date'])) {
            $fields[] = 'expiry_date = ?';
            $args[] = $opt['expiry_date'];
        }
        $args[] = $itemId;
        $pdo->prepare('UPDATE stock_items SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($args);
        $pdo->prepare('INSERT INTO stock_movements (item_id, moved_at, type, qty, qty_after, unit_cost, issued_to, reference, note, user_id, example)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $itemId, $opt['moved_at'] ?? date('Y-m-d H:i:s'), $type, $delta, $after, $cost,
            mb_substr((string)($opt['issued_to'] ?? ''), 0, 60), mb_substr((string)($opt['reference'] ?? ''), 0, 60),
            mb_substr((string)($opt['note'] ?? ''), 0, 255), $user['id'] ?? null, !empty($opt['example']) ? 1 : 0,
        ]);
        if ($type === 'waste' && empty($opt['example'])) {
            queue_email('notice', sprintf('Stock written off: %s %s %s (%s)', qty_fmt(abs($delta)), $item['unit'], $item['name'], rands(abs($delta) * $cost)),
                ((($user['full_name'] ?? '') ?: ($user['username'] ?? 'Someone')) . ' recorded wastage.'),
                [['title' => 'Details', 'pairs' => [['Item', $item['name']], ['Quantity', qty_fmt(abs($delta)) . ' ' . $item['unit']], ['Value', rands(abs($delta) * $cost)], ['Reason', $opt['note'] ?? '—'], ['Left in stock', qty_fmt($after) . ' ' . $item['unit']]]]]);
        }
        if ($own) {
            $pdo->commit();
        }
        return ['item' => $item, 'after' => $after];
    } catch (Throwable $e) {
        if ($own) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function next_po_no(): string
{
    $st = db()->prepare('SELECT 1 FROM purchase_orders WHERE po_no = ?');
    do {
        $no = 'PO-' . date('ym') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
        $st->execute([$no]);
    } while ($st->fetchColumn());
    return $no;
}

function po_recalc(int $poId): void
{
    db()->prepare('UPDATE purchase_orders SET total = (SELECT COALESCE(SUM(qty * unit_cost), 0) FROM purchase_order_lines WHERE po_id = ?) WHERE id = ?')->execute([$poId, $poId]);
}

/** Creates one Draft PO per supplier for everything at or below its reorder level. Returns PO numbers. */
function create_reorder_pos(array $user): array
{
    $pdo = db();
    $items = $pdo->query("SELECT * FROM stock_items WHERE active = 1 AND qty <= reorder_level AND reorder_level > 0
        AND id NOT IN (SELECT l.item_id FROM purchase_order_lines l JOIN purchase_orders p ON p.id = l.po_id WHERE p.status IN ('Draft','Ordered'))
        ORDER BY supplier, name")->fetchAll();
    $bySupplier = [];
    foreach ($items as $i) {
        $bySupplier[$i['supplier'] ?: 'Supplier to be confirmed'][] = $i;
    }
    $made = [];
    $pdo->beginTransaction();
    try {
        foreach ($bySupplier as $sup => $lines) {
            $no = next_po_no();
            $pdo->prepare("INSERT INTO purchase_orders (po_no, supplier, status, notes, created_by) VALUES (?, ?, 'Draft', 'Created from the low-stock list', ?)")
                ->execute([$no, $sup, $user['id']]);
            $poId = (int)$pdo->lastInsertId();
            $ins = $pdo->prepare('INSERT INTO purchase_order_lines (po_id, item_id, qty, unit_cost) VALUES (?, ?, ?, ?)');
            foreach ($lines as $i) {
                $need = max((float)$i['reorder_qty'], (float)$i['reorder_level'] * 2 - (float)$i['qty']);
                $ins->execute([$poId, $i['id'], round($need, 3), $i['unit_cost']]);
            }
            po_recalc($poId);
            $made[] = $no;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $made;
}

/**
 * Receive a purchase order: adds stock, updates average cost, and records the spend
 * as operating expenses (one line per P&L category) so it appears in Finance.
 * $received: [line_id => qty actually received]
 */
function receive_po(int $poId, array $received, string $date, array $user, bool $example = false): void
{
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $st = $pdo->prepare('SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        $st->execute([$poId]);
        $po = $st->fetch();
        if (!$po || !in_array($po['status'], ['Draft', 'Ordered'], true)) {
            throw new RuntimeException('Only draft or ordered purchase orders can be received.');
        }
        $st = $pdo->prepare('SELECT l.*, i.category, i.name, i.unit FROM purchase_order_lines l JOIN stock_items i ON i.id = l.item_id WHERE l.po_id = ?');
        $st->execute([$poId]);
        $byExpense = [];
        $total = 0.0;
        $rows = [];
        foreach ($st->fetchAll() as $l) {
            $q = max(0, round((float)($received[$l['id']] ?? $l['qty']), 3));
            $pdo->prepare('UPDATE purchase_order_lines SET received_qty = ? WHERE id = ?')->execute([$q, $l['id']]);
            if ($q > 0) {
                stock_move((int)$l['item_id'], 'receive', $q, $user, ['unit_cost' => (float)$l['unit_cost'], 'reference' => $po['po_no'], 'note' => 'Received from ' . $po['supplier'],
                    'moved_at' => $date . ' ' . date('H:i:s'), 'example' => $example]);
                $line = $q * (float)$l['unit_cost'];
                $cat = expense_category_for($l['category']);
                $byExpense[$cat] = ($byExpense[$cat] ?? 0) + $line;
                $total += $line;
                $rows[] = [$l['name'], qty_fmt($q) . ' ' . $l['unit'], rands((float)$l['unit_cost']), rands($line)];
            }
        }
        $ins = $pdo->prepare('INSERT INTO finance_expenses (spent_on, category, description, supplier, amount, source, example, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($byExpense as $cat => $amt) {
            $ins->execute([$date, $cat, 'Stock received on ' . $po['po_no'], $po['supplier'], round($amt, 2), $po['po_no'], $example ? 1 : 0, $user['id']]);
        }
        $pdo->prepare("UPDATE purchase_orders SET status = 'Received', received_on = ?, total = ? WHERE id = ?")->execute([$date, round($total, 2), $poId]);
        if (!$example) {
            queue_email('notice', "Delivery received: {$po['po_no']} from {$po['supplier']} (" . rands($total) . ')',
                (($user['full_name'] ?? '') ?: $user['username']) . ' received this order into stock. The cost has been added to the Profit & Loss.',
                [['title' => 'Items received', 'head' => ['Item', 'Quantity', 'Unit cost', 'Line total'], 'rows' => $rows]]);
        }
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Example data for the operations module: ~50 stock items with 60 days of usage, deliveries
 * and purchase orders, meal plans, temperature checks and a cleaning schedule. All rows are tagged example.
 * Must run after the example bookings exist (meal forecasts use them).
 */
function load_ops_examples(array $user): int
{
    $pdo = db();
    mt_srand(20261020);
    $rnd = fn(float $a, float $b) => $a + (mt_rand() / mt_getrandmax()) * ($b - $a);
    // name, category, department, unit, reorder level, order qty, unit cost, supplier, location, daily use, shelf life days (0 = n/a)
    $items = [
        ['Maize meal', 'Food: dry goods', 'Kitchen', 'kg', 300, 900, 12.5, 'Premier FMCG', 'Dry store A', 130, 0],
        ['Rice (parboiled)', 'Food: dry goods', 'Kitchen', 'kg', 250, 750, 22, 'Premier FMCG', 'Dry store A', 100, 0],
        ['Pasta', 'Food: dry goods', 'Kitchen', 'kg', 90, 300, 25, 'Premier FMCG', 'Dry store A', 40, 0],
        ['Bread loaves', 'Food: dry goods', 'Kitchen', 'each', 250, 600, 16.5, 'Albany Bakeries', 'Bread rack', 330, 3],
        ['Breakfast cereal', 'Food: dry goods', 'Kitchen', 'kg', 60, 180, 55, 'Premier FMCG', 'Dry store B', 30, 0],
        ['Cooking oil', 'Food: dry goods', 'Kitchen', 'L', 60, 200, 32, 'Premier FMCG', 'Dry store B', 28, 0],
        ['Sugar', 'Food: dry goods', 'Kitchen', 'kg', 60, 200, 21, 'Premier FMCG', 'Dry store B', 26, 0],
        ['Tea bags (100)', 'Food: dry goods', 'Kitchen', 'box', 20, 60, 45, 'Premier FMCG', 'Dry store B', 10, 0],
        ['Coffee (instant)', 'Food: dry goods', 'Kitchen', 'kg', 10, 30, 180, 'Premier FMCG', 'Dry store B', 6, 0],
        ['Baked beans 410g', 'Food: dry goods', 'Kitchen', 'each', 200, 600, 14, 'Premier FMCG', 'Dry store A', 110, 0],
        ['Potatoes', 'Food: fresh produce', 'Kitchen', 'kg', 350, 900, 14, 'Joburg Market Fresh', 'Veg cold room', 170, 10],
        ['Onions', 'Food: fresh produce', 'Kitchen', 'kg', 120, 300, 15, 'Joburg Market Fresh', 'Veg cold room', 55, 14],
        ['Tomatoes', 'Food: fresh produce', 'Kitchen', 'kg', 90, 220, 25, 'Joburg Market Fresh', 'Veg cold room', 50, 6],
        ['Cabbage', 'Food: fresh produce', 'Kitchen', 'each', 70, 180, 18, 'Joburg Market Fresh', 'Veg cold room', 40, 10],
        ['Bananas', 'Food: fresh produce', 'Kitchen', 'kg', 120, 300, 22, 'Joburg Market Fresh', 'Veg cold room', 70, 5],
        ['Apples', 'Food: fresh produce', 'Kitchen', 'kg', 100, 250, 24, 'Joburg Market Fresh', 'Veg cold room', 55, 14],
        ['Chicken portions', 'Food: meat & poultry', 'Kitchen', 'kg', 350, 900, 62, 'Supreme Poultry', 'Walk-in fridge 1', 200, 5],
        ['Beef mince', 'Food: meat & poultry', 'Kitchen', 'kg', 120, 300, 99, 'Karan Beef', 'Walk-in fridge 1', 70, 4],
        ['Boerewors', 'Food: meat & poultry', 'Kitchen', 'kg', 70, 180, 105, 'Karan Beef', 'Walk-in fridge 1', 40, 5],
        ['Hake fillets (frozen)', 'Food: frozen', 'Kitchen', 'kg', 60, 160, 89, 'Sea Harvest', 'Freezer 1', 22, 90],
        ['Mixed vegetables (frozen)', 'Food: frozen', 'Kitchen', 'kg', 80, 200, 34, 'McCain', 'Freezer 1', 40, 120],
        ['Milk', 'Food: dairy & eggs', 'Kitchen', 'L', 400, 1000, 19, 'Clover', 'Dairy fridge', 260, 6],
        ['Eggs (tray of 30)', 'Food: dairy & eggs', 'Kitchen', 'tray', 80, 200, 85, 'Nulaid', 'Dairy fridge', 50, 14],
        ['Cheese (cheddar)', 'Food: dairy & eggs', 'Kitchen', 'kg', 25, 70, 120, 'Clover', 'Dairy fridge', 14, 30],
        ['Yoghurt', 'Food: dairy & eggs', 'Kitchen', 'L', 60, 150, 30, 'Clover', 'Dairy fridge', 40, 10],
        ['Margarine', 'Food: dairy & eggs', 'Kitchen', 'kg', 20, 60, 70, 'Premier FMCG', 'Dairy fridge', 12, 60],
        ['Fruit juice', 'Beverages', 'Kitchen', 'L', 180, 450, 28, 'Ceres Beverages', 'Dry store B', 110, 60],
        ['Bottled water 500ml', 'Beverages', 'Kitchen', 'each', 250, 700, 7, 'Aquelle', 'Dry store B', 170, 0],
        ['Multi-surface cleaner', 'Cleaning chemicals', 'Housekeeping', 'L', 30, 80, 28, 'Bidvest Hygiene', 'Cleaning store', 9, 0],
        ['Floor cleaner', 'Cleaning chemicals', 'Housekeeping', 'L', 40, 100, 24, 'Bidvest Hygiene', 'Cleaning store', 12, 0],
        ['Bleach', 'Cleaning chemicals', 'Housekeeping', 'L', 25, 70, 18, 'Bidvest Hygiene', 'Cleaning store', 7, 0],
        ['Toilet cleaner', 'Cleaning chemicals', 'Housekeeping', 'L', 20, 50, 35, 'Bidvest Hygiene', 'Cleaning store', 5, 0],
        ['Hand soap', 'Cleaning chemicals', 'Housekeeping', 'L', 30, 80, 40, 'Bidvest Hygiene', 'Cleaning store', 8, 0],
        ['Hand sanitiser', 'Cleaning chemicals', 'Housekeeping', 'L', 15, 40, 75, 'Bidvest Hygiene', 'Cleaning store', 3.5, 0],
        ['Dishwashing liquid', 'Cleaning chemicals', 'Kitchen', 'L', 25, 60, 30, 'Bidvest Hygiene', 'Kitchen scullery', 6, 0],
        ['Laundry detergent', 'Cleaning chemicals', 'Housekeeping', 'kg', 60, 150, 38, 'Bidvest Hygiene', 'Laundry', 16, 0],
        ['Mop heads', 'Cleaning equipment', 'Housekeeping', 'each', 10, 30, 45, 'Bidvest Hygiene', 'Cleaning store', 1.3, 0],
        ['Microfibre cloths (10)', 'Cleaning equipment', 'Housekeeping', 'pack', 6, 18, 85, 'Bidvest Hygiene', 'Cleaning store', 0.9, 0],
        ['Refuse bags (roll)', 'Cleaning equipment', 'Housekeeping', 'roll', 20, 50, 55, 'Bidvest Hygiene', 'Cleaning store', 3.5, 0],
        ['Rubber gloves (pack)', 'Cleaning equipment', 'Housekeeping', 'pack', 5, 15, 60, 'Bidvest Hygiene', 'Cleaning store', 0.7, 0],
        ['Toilet paper', 'Paper & consumables', 'Housekeeping', 'roll', 350, 900, 6.5, 'Tork / Essity', 'Paper store', 85, 0],
        ['Paper towel', 'Paper & consumables', 'Housekeeping', 'roll', 90, 240, 18, 'Tork / Essity', 'Paper store', 22, 0],
        ['Guest soap bars', 'Guest amenities', 'Housekeeping', 'each', 180, 500, 4, 'Hotel Amenities SA', 'Amenities store', 40, 0],
        ['Shampoo sachets', 'Guest amenities', 'Housekeeping', 'each', 180, 500, 3, 'Hotel Amenities SA', 'Amenities store', 35, 0],
        ['Bath towels', 'Linen', 'Housekeeping', 'each', 30, 60, 120, 'Linen House', 'Linen room', 1.0, 0],
        ['Bed sheets', 'Linen', 'Housekeeping', 'each', 20, 40, 180, 'Linen House', 'Linen room', 0.6, 0],
        ['LED light bulbs', 'Maintenance spares', 'Maintenance', 'each', 12, 40, 35, 'Builders Warehouse', 'Maintenance store', 1.2, 0],
        ['Shower heads', 'Maintenance spares', 'Maintenance', 'each', 4, 10, 150, 'Builders Warehouse', 'Maintenance store', 0.2, 0],
    ];
    $areasClean = ['Housekeeping: Block A', 'Housekeeping: Block B', 'Housekeeping: Block C', 'Housekeeping: Block D', 'Housekeeping: Block E', 'Housekeeping: Block F', 'Common areas & ablutions', 'Laundry'];
    $insItem = $pdo->prepare('INSERT INTO stock_items (sku, name, category, department, unit, qty, reorder_level, reorder_qty, unit_cost, supplier, location, expiry_date, example) VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, 1)');
    $n = 0;
    $start = date('Y-m-d', strtotime('-60 days'));
    $ids = [];
    foreach ($items as $k => $it) {
        $sku = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $it[1]), 0, 3)) . '-' . str_pad((string)($k + 1), 3, '0', STR_PAD_LEFT) . 'X';
        $insItem->execute([$sku, $it[0], $it[1], $it[2], $it[3], $it[4], $it[5], $it[6], $it[7], $it[8], $it[10] ? date('Y-m-d', strtotime('+' . mt_rand(1, $it[10]) . ' days')) : null]);
        $ids[$k] = (int)$pdo->lastInsertId();
        stock_move($ids[$k], 'receive', $it[4] * 1.6 + $it[5] * 0.5, $user, ['note' => 'Opening stock', 'moved_at' => $start . ' 07:00:00', 'example' => true]);
        $n++;
    }
    $pending = []; // item index => [po id, arrival date]
    $poIns = $pdo->prepare("INSERT INTO purchase_orders (po_no, supplier, status, ordered_on, expected_on, total, notes, created_by, example) VALUES (?, ?, 'Ordered', ?, ?, ?, 'Example order', ?, 1)");
    $lineIns = $pdo->prepare('INSERT INTO purchase_order_lines (po_id, item_id, qty, unit_cost) VALUES (?, ?, ?, ?)');
    for ($day = 1; $day <= 60; $day++) {
        $d = date('Y-m-d', strtotime("$start +$day days"));
        foreach ($items as $k => $it) {
            if (isset($pending[$k]) && $pending[$k][1] <= $d && $d < today()) {
                receive_po($pending[$k][0], [], $d, $user, true);
                unset($pending[$k]);
            }
            $use = round($it[9] * $rnd(0.8, 1.2), $it[3] === 'each' || $it[3] === 'roll' || $it[3] === 'tray' ? 0 : 1);
            $st = $pdo->prepare('SELECT qty FROM stock_items WHERE id = ?');
            $st->execute([$ids[$k]]);
            $have = (float)$st->fetchColumn();
            $use = min($use, $have);
            if ($use > 0) {
                $to = match ($it[2]) {
                    'Kitchen' => 'Kitchen',
                    'Maintenance' => 'Maintenance',
                    default => $areasClean[mt_rand(0, count($areasClean) - 1)],
                };
                stock_move($ids[$k], 'issue', -$use, $user, ['issued_to' => $to, 'moved_at' => $d . sprintf(' %02d:%02d:00', mt_rand(6, 16), mt_rand(0, 59)), 'example' => true]);
                $n++;
            }
            if ($it[10] && $it[10] <= 14 && mt_rand(1, 100) <= 6) {
                $w = round($it[9] * $rnd(0.05, 0.15), 1);
                if ($w > 0 && $have - $use - $w > 0) {
                    stock_move($ids[$k], 'waste', -$w, $user, ['note' => ['Past expiry date', 'Spoiled in storage', 'Damaged on delivery', 'Over-production'][mt_rand(0, 3)], 'moved_at' => $d . ' 17:30:00', 'example' => true]);
                }
            }
            $st->execute([$ids[$k]]);
            // A sensible stores manager orders before stock runs out: at the reorder level or with 4 days' cover left.
            if ((float)$st->fetchColumn() <= max($it[4], $it[9] * 4) && !isset($pending[$k])) {
                $lead = mt_rand(1, 3);
                $arrive = date('Y-m-d', strtotime("$d +$lead days"));
                $orderQty = max($it[5], ceil($it[9] * 7));
                $poIns->execute([next_po_no(), $it[7], $d, $arrive, round($orderQty * $it[6], 2), $user['id']]);
                $poId = (int)$pdo->lastInsertId();
                $lineIns->execute([$poId, $ids[$k], $orderQty, $it[6]]);
                $pending[$k] = [$poId, $arrive];
            }
        }
    }
    // One delivery running late, so the late-delivery alert has something to show.
    if ($pending) {
        $k = array_key_first($pending);
        $pdo->prepare('UPDATE purchase_orders SET expected_on = ? WHERE id = ?')->execute([date('Y-m-d', strtotime('-1 day')), $pending[$k][0]]);
    }

    // Meal plans: 60 days back (matches the stock history), 7 ahead
    $menus = [
        'Breakfast' => ["Maize porridge\nScrambled eggs\nToast & jam\nFruit", "Cereal & yoghurt\nBoiled eggs\nToast\nBananas", "Maize porridge\nBoerewors & tomato\nToast", "Oats\nFried eggs & beans\nToast & fruit"],
        'Lunch' => ["Chicken stew & rice\nColeslaw", "Beef lasagne\nGreen salad", "Fish & chips\nSalad", "Chicken curry & rice\nSambals"],
        'Packed lunch' => ["Chicken mayo sandwiches\nApple\nJuice\nWater", "Cheese & tomato sandwiches\nBanana\nJuice\nWater", "Beef wrap\nFruit\nJuice\nWater"],
        'Dinner' => ["Pap, wors & chakalaka\nCabbage", "Roast chicken, potatoes & veg\nDessert: custard", "Beef mince & rice\nMixed veg", "Hake, mash & veg\nDessert: jelly", "Chicken & mushroom pasta\nSalad"],
    ];
    $mp = $pdo->prepare('INSERT INTO meal_plans (plan_date, meal, menu, planned, served, notes, example) VALUES (?, ?, ?, NULL, ?, ?, 1) ON DUPLICATE KEY UPDATE menu = VALUES(menu), served = VALUES(served), example = 1');
    $tu = take_up();
    for ($i = -60; $i <= 7; $i++) {
        $d = date('Y-m-d', strtotime("$i days"));
        $fc = meal_forecast($d, $tu);
        foreach (MEALS as $m) {
            $served = $d < today() ? (int)round($fc[$m] * $rnd(0.9, 1.04)) : null;
            $mp->execute([$d, $m, str_replace('\n', "
", $menus[$m][($i + 70) % count($menus[$m])]), $served, $m === 'Dinner' && $i % 7 === 0 ? 'Braai night' : '']);
            $n++;
        }
    }

    // Temperature checks: twice a day for 14 days, with two failures
    $units = [['Walk-in fridge 1', 'Fridge', 2, 4.5], ['Dairy fridge', 'Fridge', 1.5, 4.5], ['Veg cold room', 'Fridge', 3, 5], ['Freezer 1', 'Freezer', -22, -18.5], ['Bain-marie (hot line)', 'Hot holding', 64, 75]];
    $kc = $pdo->prepare('INSERT INTO kitchen_checks (checked_at, equipment, kind, temperature, ok, action_taken, user_id, example) VALUES (?, ?, ?, ?, ?, ?, ?, 1)');
    for ($i = 13; $i >= 0; $i--) {
        foreach (['07:30', '15:30'] as $tm) {
            $at = date('Y-m-d', strtotime("-$i days")) . " $tm:00";
            if ($at > date('Y-m-d H:i:s')) {
                continue;
            }
            foreach ($units as [$name, $kind, $lo, $hi]) {
                $t = round($rnd($lo, $hi), 1);
                $action = '';
                if (($i === 9 && $tm === '15:30' && $name === 'Walk-in fridge 1') || ($i === 0 && $tm === '07:30' && $name === 'Bain-marie (hot line)')) {
                    $t = $kind === 'Fridge' ? 8.4 : 58.2;
                    $action = $kind === 'Fridge' ? 'Door seal damaged; stock moved to fridge 2, technician called' : 'Food reheated to 75 °C; element checked';
                }
                $kc->execute([$at, $name, $kind, $t, temp_ok($kind, $t) ? 1 : 0, $action, $user['id']]);
                $n++;
            }
        }
    }

    // Cleaning schedule with history
    $tasks = [
        ['Kitchen', 'Deep clean extraction hoods and filters', 7, 'Kitchen team', 'Degreaser, scourers'],
        ['Kitchen', 'Sanitise prep surfaces and cold rooms', 1, 'Kitchen team', 'Sanitiser, microfibre cloths'],
        ['Dining hall', 'Clean tables, mop floors', 1, 'Housekeeping team 1', 'Multi-surface cleaner, floor cleaner, mop heads'],
        ['Reception & lobby', 'Vacuum, dust, clean glass', 1, 'Housekeeping team 1', 'Multi-surface cleaner, microfibre cloths'],
        ['Ablutions & showers', 'Clean and disinfect toilets and showers', 1, 'Housekeeping team 2', 'Toilet cleaner, bleach, toilet paper, hand soap'],
        ['Laundry', 'Clean machines and lint traps', 7, 'Laundry team', 'Multi-surface cleaner'],
        ['Block A corridors & stairs', 'Mop corridors and stairwells', 2, 'Housekeeping team 3', 'Floor cleaner, mop heads'],
        ['Block B corridors & stairs', 'Mop corridors and stairwells', 2, 'Housekeeping team 3', 'Floor cleaner, mop heads'],
        ['Block C corridors & stairs', 'Mop corridors and stairwells', 2, 'Housekeeping team 4', 'Floor cleaner, mop heads'],
        ['Block D corridors & stairs', 'Mop corridors and stairwells', 2, 'Housekeeping team 4', 'Floor cleaner, mop heads'],
        ['Block E corridors & stairs', 'Mop corridors and stairwells', 2, 'Housekeeping team 5', 'Floor cleaner, mop heads'],
        ['Block F corridors & stairs', 'Mop corridors and stairwells', 2, 'Housekeeping team 5', 'Floor cleaner, mop heads'],
        ['Grounds & parking', 'Litter pick and empty outdoor bins', 1, 'Grounds team', 'Refuse bags, gloves'],
        ['Gym & recreation', 'Wipe equipment, mop floor', 3, 'Housekeeping team 1', 'Sanitiser, floor cleaner'],
    ];
    $ti = $pdo->prepare('INSERT INTO cleaning_tasks (area, task, frequency_days, assigned_to, supplies, last_done, example) VALUES (?, ?, ?, ?, ?, ?, 1)');
    $li = $pdo->prepare('INSERT INTO cleaning_log (task_id, done_on, done_by, notes, example) VALUES (?, ?, ?, ?, 1)');
    foreach ($tasks as $k => $t) {
        $last = date('Y-m-d', strtotime('-' . ($k === 6 || $k === 13 ? $t[2] + 2 : mt_rand(0, max(0, $t[2] - 1))) . ' days'));
        $ti->execute([$t[0], $t[1], $t[2], $t[3], $t[4], $last]);
        $tid = (int)$pdo->lastInsertId();
        for ($d = $last; $d >= date('Y-m-d', strtotime('-14 days')); $d = date('Y-m-d', strtotime("$d -{$t[2]} days"))) {
            $li->execute([$tid, $d, $t[3], '']);
            $n++;
        }
    }
    return $n;
}

/** Guests sleeping at ESS IV on the night of $date (adults + children of active stays). */
function guests_on_night(string $date): int
{
    $st = db()->prepare("SELECT COALESCE(SUM(adults + children), 0) FROM bookings WHERE status IN ('Provisional','Confirmed','Checked-in','Checked-out') AND check_in <= ? AND check_out > ?");
    $st->execute([$date, $date]);
    return (int)$st->fetchColumn();
}

function take_up(): array
{
    $out = [];
    foreach (MEALS as $m) {
        $out[$m] = (int)(setting('take_up_' . str_replace(' ', '_', strtolower($m)), (string)DEFAULT_TAKE_UP[$m]) ?? DEFAULT_TAKE_UP[$m]);
    }
    return $out;
}

/**
 * Expected portions per meal for a date. Breakfast feeds the guests who slept the night before;
 * lunch, packed lunch and dinner feed the guests staying that night.
 */
function meal_forecast(string $date, ?array $takeUp = null): array
{
    $takeUp ??= take_up();
    $prevNight = guests_on_night(date('Y-m-d', strtotime($date . ' -1 day')));
    $tonight = guests_on_night($date);
    $out = [];
    foreach (MEALS as $m) {
        $base = $m === 'Breakfast' ? $prevNight : $tonight;
        $out[$m] = (int)round($base * $takeUp[$m] / 100);
    }
    return $out;
}

/** Cost of stock consumed (issued + wasted) per supply group between two dates. */
function consumption_by_group(string $from, string $to): array
{
    $st = db()->prepare("SELECT i.category, m.type, SUM(-m.qty * m.unit_cost) cost FROM stock_movements m JOIN stock_items i ON i.id = m.item_id
        WHERE m.type IN ('issue','waste') AND m.moved_at >= ? AND m.moved_at < ? GROUP BY i.category, m.type");
    $st->execute([$from, date('Y-m-d', strtotime($to . ' +1 day'))]);
    $out = [];
    foreach ($st as $r) {
        $g = stock_group($r['category']);
        $out[$g] ??= ['used' => 0.0, 'wasted' => 0.0];
        $out[$g][$r['type'] === 'waste' ? 'wasted' : 'used'] += (float)$r['cost'];
    }
    return $out;
}

/** Operations figures for a period: used by the operations dashboard and the Finance page. */
function ops_period(string $from, string $to): array
{
    $pdo = db();
    $end = date('Y-m-d', strtotime($to . ' +1 day'));
    $cons = consumption_by_group($from, $to);
    $st = $pdo->prepare("SELECT COALESCE(SUM(served),0) FROM meal_plans WHERE plan_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $served = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT category, SUM(amount) FROM finance_expenses WHERE spent_on BETWEEN ? AND ? AND category IN ('Catering: food & beverage','Cleaning supplies','Guest amenities & linen','Maintenance spares') GROUP BY category");
    $st->execute([$from, $to]);
    $purchases = array_map('floatval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    $st = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(0, DATEDIFF(LEAST(check_out, ?), GREATEST(check_in, ?)))),0) FROM bookings WHERE status IN ('Checked-in','Checked-out') AND check_in < ? AND check_out > ?");
    $st->execute([$end, $from, $end, $from]);
    $roomNights = (int)$st->fetchColumn();
    $food = $cons['Catering']['used'] ?? 0;
    $cleanUsed = ($cons['Cleaning']['used'] ?? 0) + ($cons['Cleaning']['wasted'] ?? 0);
    $wasted = array_sum(array_map(fn($g) => $g['wasted'], $cons));
    return [
        'consumption' => $cons, 'purchases' => $purchases, 'meals_served' => $served, 'room_nights' => $roomNights,
        'food_used' => $food, 'food_wasted' => $cons['Catering']['wasted'] ?? 0,
        'food_cost_per_meal' => $served ? ($food + ($cons['Catering']['wasted'] ?? 0)) / $served : 0,
        'cleaning_used' => $cleanUsed, 'cleaning_per_room_night' => $roomNights ? $cleanUsed / $roomNights : 0,
        'wasted' => $wasted,
    ];
}

/**
 * Stock alerts. 'low' = at or below the reorder level (shortage); 'near' = within the warning margin above it,
 * or expected to run out within the cover period at the last 14 days' usage.
 */
function stock_alert_rows(): array
{
    $pdo = db();
    $warn = (float)(setting('stock_warn_pct', '25') ?? 25);
    $coverDays = (float)(setting('stock_cover_days', '5') ?? 5);
    $usage = $pdo->query("SELECT item_id, SUM(-qty) / 14 per_day FROM stock_movements WHERE type IN ('issue','waste') AND moved_at >= NOW() - INTERVAL 14 DAY GROUP BY item_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $rows = [];
    foreach ($pdo->query('SELECT * FROM stock_items WHERE active = 1 AND reorder_level > 0') as $i) {
        $qty = (float)$i['qty'];
        $rl = (float)$i['reorder_level'];
        $perDay = (float)($usage[$i['id']] ?? 0);
        $cover = $perDay > 0 ? $qty / $perDay : null;
        $level = $qty <= $rl ? 'low' : (($qty <= $rl * (1 + $warn / 100) || ($cover !== null && $cover <= $coverDays)) ? 'near' : null);
        if ($level) {
            $rows[] = $i + ['level' => $level, 'per_day' => $perDay, 'cover' => $cover, 'food' => stock_group($i['category']) === 'Catering'];
        }
    }
    $rank = fn($r) => [$r['food'] ? 0 : 1, $r['level'] === 'low' ? 0 : 1, $r['cover'] ?? 999];
    usort($rows, fn($a, $b) => $rank($a) <=> $rank($b));
    return $rows;
}

function cover_text(?float $cover): string
{
    if ($cover === null) {
        return 'no recent usage';
    }
    return $cover < 1 ? 'runs out within a day' : 'about ' . (int)floor($cover) . ' day' . ((int)floor($cover) === 1 ? '' : 's') . ' left at current usage';
}

function stock_value(?string $group = null): float
{
    $total = 0.0;
    foreach (db()->query('SELECT category, qty * unit_cost v FROM stock_items WHERE active = 1') as $r) {
        if ($group === null || stock_group($r['category']) === $group) {
            $total += (float)$r['v'];
        }
    }
    return $total;
}

/** The Operations sub-navigation, showing only the areas this user can open. */
function ops_nav(array $user, string $active): string
{
    $links = ['operations' => ['Operations dashboard', null], 'catering' => ['Kitchen & catering', 'catering'], 'stock' => ['Stock & purchasing', 'stock'], 'cleaning' => ['Cleaning & housekeeping', 'cleaning']];
    $html = '<nav class="tabs subnav">';
    foreach ($links as $page => [$label, $area]) {
        if ($area && !can($user, $area)) {
            continue;
        }
        $html .= '<a href="' . $page . '.php"' . ($page === $active ? ' aria-current="page"' : '') . '>' . h($label) . '</a>';
    }
    return $html . '</nav>';
}

function can_any_ops(array $user): bool
{
    return can($user, 'catering') || can($user, 'stock') || can($user, 'cleaning');
}

/** Standard page shell for the operations pages. */
function ops_page_open(array $user, string $title, string $active, string $sub): void
{
    page_open($title . ' · ESS IV CRM');
    echo '<header class="top"><div class="wrap"><div class="topbar"><div class="brand"><h1>ESS IV</h1><span>' . h($sub) . '</span></div>'
        . '<span class="chip">' . h($user['full_name'] ?: $user['username']) . '</span><a class="btn" href="index.php">Back to the CRM</a></div>'
        . ops_nav($user, $active) . '</div></header><main class="wrap ops">';
}

function ops_page_close(): void
{
    echo "</main>\n";
    page_close();
}

function flash(): string
{
    start_session();
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) {
        return '';
    }
    return $f['ok'] ? '<div class="banner"><p>' . h($f['msg']) . '</p></div>' : '<p class="err" role="alert">' . h($f['msg']) . '</p>';
}

/** Post/redirect/get: store a message and reload the page, so refresh never repeats an action. */
function redirect_with(string $msg, bool $ok = true, string $to = ''): never
{
    start_session();
    $_SESSION['flash'] = ['msg' => $msg, 'ok' => $ok];
    session_write_close();
    header('Location: ' . ($to ?: ($_SERVER['REQUEST_URI'] ?? 'operations.php')));
    // Send any notification emails this action queued, after the browser has its answer.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    try {
        flush_email_queue(10);
    } catch (Throwable $e) {
        error_log('[ess-iv-crm] ' . $e->getMessage());
    }
    exit;
}

function require_post_csrf(): void
{
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        redirect_with('The form expired. Try again.', false);
    }
}
