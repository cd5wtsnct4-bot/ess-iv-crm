<?php
declare(strict_types=1);

/**
 * Server-side alerts — the same rules the Dashboard shows — and the emails for them.
 *  - run_alert_check(): emails any alert that has not been emailed before (each once),
 *    plus the daily summary after the configured hour.
 *  - notify_change(): emails a notification whenever a record changes (booking made,
 *    check-in, check-out, cancellation, room offline/back, maintenance ticket...).
 */

require_once __DIR__ . '/ops.php';

const PRIO_DAYS = ['Critical' => 1, 'High' => 3, 'Medium' => 7, 'Low' => 14];

/**
 * Operations alerts (stock, food safety, cleaning, deliveries). Each group lists the access areas
 * that may see it on the dashboard; emails go to the alert recipients regardless.
 */
function ops_alert_groups(): array
{
    $pdo = db();
    $t = today();
    $g = [];
    $add = function (string $key, string $title, array $areas, string $link, array $head, array $items, bool $urgent = false) use (&$g) {
        $g[$key] = ['title' => $title, 'areas' => $areas, 'link' => $link, 'head' => $head, 'items' => $items, 'urgent' => $urgent];
    };
    $rows = stock_alert_rows();
    $sHead = ['Item', 'In stock', 'Reorder at', 'Outlook', 'Supplier'];
    $sRow = fn($i) => [$i['name'], qty_fmt((float)$i['qty']) . ' ' . $i['unit'], qty_fmt((float)$i['reorder_level']) . ' ' . $i['unit'], cover_text($i['cover']), $i['supplier'] ?: '—'];
    $pick = fn(bool $food, string $level) => array_values(array_filter($rows, fn($r) => $r['food'] === $food && $r['level'] === $level));
    $add('food_low', 'Food shortages: below reorder level', ['catering', 'stock'], 'stock.php?dept=Kitchen&low=1', $sHead,
        array_map(fn($i) => ['key' => "food_low:{$i['id']}:{$t}", 'cols' => $sRow($i)], $pick(true, 'low')), true);
    $add('food_near', 'Food running low: close to reorder level', ['catering', 'stock'], 'stock.php?dept=Kitchen', $sHead,
        array_map(fn($i) => ['key' => "food_near:{$i['id']}:{$t}", 'cols' => $sRow($i)], $pick(true, 'near')));
    $add('stock_low', 'Supplies below reorder level (cleaning, amenities, spares)', ['stock', 'cleaning'], 'stock.php?low=1', $sHead,
        array_map(fn($i) => ['key' => "stock_low:{$i['id']}:{$t}", 'cols' => $sRow($i)], $pick(false, 'low')), true);
    $add('stock_near', 'Supplies close to reorder level', ['stock', 'cleaning'], 'stock.php', $sHead,
        array_map(fn($i) => ['key' => "stock_near:{$i['id']}:{$t}", 'cols' => $sRow($i)], $pick(false, 'near')));

    $st = $pdo->prepare('SELECT * FROM stock_items WHERE active = 1 AND qty > 0 AND expiry_date IS NOT NULL AND expiry_date <= ? ORDER BY expiry_date');
    $st->execute([date('Y-m-d', strtotime('+3 days'))]);
    $add('expiring', 'Stock expiring within 3 days', ['catering', 'stock'], 'stock.php', ['Item', 'In stock', 'Expires', 'Location'],
        array_map(fn($i) => ['key' => "expiring:{$i['id']}:{$i['expiry_date']}", 'cols' => [$i['name'], qty_fmt((float)$i['qty']) . ' ' . $i['unit'], d_fmt($i['expiry_date']) . ' (' . rel_day($i['expiry_date']) . ')', $i['location']]], $st->fetchAll()), true);

    $rowsK = $pdo->query('SELECT * FROM kitchen_checks WHERE ok = 0 AND checked_at >= NOW() - INTERVAL 1 DAY ORDER BY checked_at DESC')->fetchAll();
    $add('temp_fail', 'Food-safety temperature failures (last 24 hours)', ['catering'], 'catering.php#checks', ['Equipment', 'Temperature', 'When', 'Action taken'],
        array_map(fn($k) => ['key' => "temp_fail:{$k['id']}", 'cols' => [$k['equipment'], number_format((float)$k['temperature'], 1) . ' °C', date('D j M H:i', strtotime($k['checked_at'])), $k['action_taken']]], $rowsK), true);

    $rowsC = $pdo->query('SELECT t.*, DATE_ADD(COALESCE(t.last_done, CURDATE() - INTERVAL 1 DAY), INTERVAL t.frequency_days DAY) next_due FROM cleaning_tasks t WHERE active = 1')->fetchAll();
    $rowsC = array_values(array_filter($rowsC, fn($c) => $c['next_due'] < $t));
    $add('clean_overdue', 'Cleaning overdue', ['cleaning'], 'cleaning.php#schedule', ['Area', 'Task', 'Was due', 'Assigned to'],
        array_map(fn($c) => ['key' => "clean:{$c['id']}:{$t}", 'cols' => [$c['area'], $c['task'], d_fmt($c['next_due']) . ' (' . rel_day($c['next_due']) . ')', $c['assigned_to']]], $rowsC));

    $st = $pdo->prepare("SELECT * FROM purchase_orders WHERE status = 'Ordered' AND expected_on < ? ORDER BY expected_on");
    $st->execute([$t]);
    $add('po_late', 'Deliveries overdue', ['stock', 'catering'], 'stock.php?view=orders', ['Order', 'Supplier', 'Expected', 'Value'],
        array_map(fn($p) => ['key' => "po_late:{$p['id']}:{$t}", 'cols' => [$p['po_no'], $p['supplier'], d_fmt($p['expected_on']) . ' (' . rel_day($p['expected_on']) . ')', rands((float)$p['total'])]], $st->fetchAll()));
    return $g;
}

/** The operations alerts a given user may see, shaped for the dashboard. */
function ops_alerts_for(array $user): array
{
    $out = [];
    foreach (ops_alert_groups() as $key => $g) {
        if (!$g['items'] || !array_filter($g['areas'], fn($a) => can($user, $a))) {
            continue;
        }
        $out[] = ['key' => $key, 'title' => $g['title'], 'link' => $g['link'], 'urgent' => $g['urgent'], 'head' => $g['head'], 'rows' => array_map(fn($i) => $i['cols'], $g['items'])];
    }
    return $out;
}

function d_fmt(?string $d): string
{
    return $d ? date('D j M Y', strtotime($d)) : '—';
}

function rel_day(string $d): string
{
    $n = (int)round((strtotime($d) - strtotime(today())) / 86400);
    return match (true) {
        $n === 0 => 'today', $n === 1 => 'tomorrow', $n === -1 => 'yesterday',
        $n > 0 => "in {$n} days", default => (-$n) . ' days ago',
    };
}

/** Returns alert groups: key => ['title'=>..., 'items'=>[['key'=>..., 'cols'=>[...]], ...], 'head'=>[...]] */
function compute_alerts(): array
{
    $pdo = db();
    $t = today();
    $in7 = date('Y-m-d', strtotime('+7 days'));
    $g = [];
    $bk = fn(string $sql, array $args) => (function () use ($pdo, $sql, $args) {
        $st = $pdo->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    })();
    $bhead = ['Guest', 'Room', 'Date', 'When', 'Company', 'Ref'];
    $brow = fn(array $b, string $date) => [$b['guest_name'], $b['room_id'], d_fmt($date), rel_day($date), $b['company'], $b['ref']];

    $add = function (string $key, string $title, array $head, array $items) use (&$g) {
        $g[$key] = ['title' => $title, 'head' => $head, 'items' => $items];
    };

    $rows = $bk("SELECT * FROM bookings WHERE status = 'Checked-in' AND check_out < ? ORDER BY check_out", [$t]);
    $add('overdue_out', 'Overdue check-outs', $bhead, array_map(fn($b) => ['key' => "overdue_out:{$b['id']}:{$t}", 'cols' => $brow($b, $b['check_out'])], $rows));

    $rows = $bk("SELECT * FROM bookings WHERE status IN ('Provisional','Confirmed') AND check_in < ? ORDER BY check_in", [$t]);
    $add('missed', 'Missed arrivals (possible no-show)', $bhead, array_map(fn($b) => ['key' => "missed:{$b['id']}:{$t}", 'cols' => $brow($b, $b['check_in'])], $rows));

    $rows = $bk("SELECT * FROM bookings WHERE status = 'Checked-in' AND check_out = ? ORDER BY room_id", [$t]);
    $add('due_out', 'Check-outs due today', $bhead, array_map(fn($b) => ['key' => "due_out:{$b['id']}:{$t}", 'cols' => $brow($b, $b['check_out'])], $rows));

    $rows = $bk("SELECT * FROM bookings WHERE status IN ('Provisional','Confirmed') AND check_in = ? ORDER BY arrival_time, room_id", [$t]);
    $add('arr_today', 'Check-ins due today', $bhead, array_map(fn($b) => ['key' => "arr_today:{$b['id']}:{$t}", 'cols' => $brow($b, $b['check_in'])], $rows));

    $rows = $bk("SELECT * FROM bookings WHERE status IN ('Provisional','Confirmed') AND check_in > ? AND check_in <= ? ORDER BY check_in", [$t, $in7]);
    $add('up_in', 'Upcoming check-ins (next 7 days)', $bhead, array_map(fn($b) => ['key' => "up_in:{$b['id']}:{$b['check_in']}", 'cols' => $brow($b, $b['check_in'])], $rows));

    $rows = $bk("SELECT * FROM bookings WHERE status = 'Checked-in' AND check_out > ? AND check_out <= ? ORDER BY check_out", [$t, $in7]);
    $add('up_out', 'Upcoming check-outs (next 7 days)', $bhead, array_map(fn($b) => ['key' => "up_out:{$b['id']}:{$b['check_out']}", 'cols' => $brow($b, $b['check_out'])], $rows));

    $items = [];
    foreach ($pdo->query("SELECT * FROM maintenance_tickets WHERE status NOT IN ('Completed','Verified')") as $m) {
        $due = $m['due_date'] ?: date('Y-m-d', strtotime(($m['reported_on'] ?: $t) . ' +' . (PRIO_DAYS[$m['priority']] ?? 7) . ' days'));
        if ($due < $t) {
            $items[] = ['key' => "m_overdue:{$m['id']}:{$t}", 'cols' => [$m['ticket_no'], $m['room_id'], $m['title'], $m['priority'], d_fmt($due) . ' (' . rel_day($due) . ')', $m['assigned_to'] ?: 'Unassigned']];
        }
    }
    $add('m_overdue', 'Overdue maintenance', ['Ticket', 'Room', 'Issue', 'Priority', 'Was due', 'Assigned to'], $items);

    $rows = $bk("SELECT * FROM rooms WHERE service <> 'in' AND expected_return IS NOT NULL AND expected_return <= ? ORDER BY expected_return", [$t]);
    $add('room_back', 'Rooms due back in service', ['Room', 'Status', 'Reason', 'Expected back'], array_map(fn($r) => [
        'key' => "room_back:{$r['id']}:{$r['expected_return']}",
        'cols' => [$r['id'], $r['service'] === 'oos' ? 'Out of service' : 'Under maintenance', $r['reason'], d_fmt($r['expected_return']) . ' (' . rel_day($r['expected_return']) . ')'],
    ], $rows));

    $items = [];
    foreach ($pdo->query('SELECT * FROM maintenance_schedules') as $s) {
        $next = date('Y-m-d', strtotime(($s['last_done'] ?: $t) . ' +' . max(1, (int)$s['frequency_days']) . ' days'));
        if ($next <= $in7) {
            $items[] = ['key' => "pm_due:{$s['id']}:{$next}", 'cols' => [$s['task'], $s['scope'], d_fmt($next) . ' (' . rel_day($next) . ')', $s['owner']]];
        }
    }
    $add('pm_due', 'Preventive maintenance due', ['Task', 'Scope', 'Due', 'Owner'], $items);

    foreach (ops_alert_groups() as $key => $og) {
        $g[$key] = ['title' => $og['title'], 'head' => $og['head'], 'items' => $og['items']];
    }
    return $g;
}

function occupancy_today(): array
{
    $pdo = db();
    $t = today();
    $st = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE status IN ('Provisional','Confirmed','Checked-in') AND check_in <= ? AND check_out > ?");
    $st->execute([$t, $t]);
    $occ = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE service <> 'in' AND (since IS NULL OR since <= ?) AND (expected_return IS NULL OR expected_return > ?)");
    $st->execute([$t, $t]);
    $off = (int)$st->fetchColumn();
    $sellable = 600 - $off;
    $inHouse = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'Checked-in'")->fetchColumn();
    return ['booked' => $occ, 'offline' => $off, 'sellable' => $sellable, 'in_house' => $inHouse, 'pct' => $sellable ? round($occ / $sellable * 100, 1) : 0];
}

/** Email new alerts (each one once) and the daily summary. Safe to call often. */
function run_alert_check(bool $force_digest = false): array
{
    $pdo = db();
    if ((int)$pdo->query("SELECT GET_LOCK('ess4crm_alerts', 0)")->fetchColumn() !== 1) {
        return ['new' => 0, 'digest' => false];
    }
    try {
        set_setting('last_alert_check', date('Y-m-d H:i:s'));
        $groups = compute_alerts();
        $newSections = [];
        $newKeys = [];
        $check = $pdo->prepare('SELECT 1 FROM alerts_sent WHERE alert_key = ?');
        foreach ($groups as $g) {
            $rows = [];
            foreach ($g['items'] as $it) {
                $check->execute([$it['key']]);
                if (!$check->fetchColumn()) {
                    $rows[] = $it['cols'];
                    $newKeys[] = $it['key'];
                }
            }
            if ($rows) {
                $newSections[] = ['title' => $g['title'] . ' · ' . count($rows), 'head' => $g['head'], 'rows' => $rows];
            }
        }
        $pdo->beginTransaction();
        if ($newKeys) {
            $n = count($newKeys);
            queue_email('alert', "ESS IV alert: {$n} new item" . ($n > 1 ? 's' : '') . ' need attention',
                'These alerts have just appeared in the CRM. They also show on the Dashboard and the Check-in / Check-out page.', $newSections);
            $ins = $pdo->prepare('INSERT IGNORE INTO alerts_sent (alert_key) VALUES (?)');
            foreach ($newKeys as $k) {
                $ins->execute([$k]);
            }
        }
        $digest = false;
        $hour = (int)(setting('digest_hour', '6') ?? 6);
        if ($force_digest || (setting('last_digest_date') !== today() && (int)date('G') >= $hour)) {
            $o = occupancy_today();
            $sections = [['title' => 'Today at a glance', 'pairs' => [
                ['Occupancy tonight', "{$o['pct']}% ({$o['booked']} of {$o['sellable']} sellable rooms)"],
                ['Guests in-house', (string)$o['in_house']],
                ['Rooms offline', (string)$o['offline']],
            ]]];
            foreach ($groups as $g) {
                $sections[] = ['title' => $g['title'] . ' · ' . count($g['items']), 'head' => $g['head'],
                    'rows' => $g['items'] ? array_map(fn($i) => $i['cols'], $g['items']) : [['None']]];
            }
            queue_email('digest', 'ESS IV daily summary · ' . date('D j M Y'), 'Pending check-ins and check-outs with their dates, plus maintenance that needs attention.', $sections);
            set_setting('last_digest_date', today());
            $digest = true;
        }
        $pdo->commit();
        $pdo->exec('DELETE FROM alerts_sent WHERE sent_at < NOW() - INTERVAL 90 DAY');
        return ['new' => count($newKeys), 'digest' => $digest];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('ess4crm_alerts')");
    }
}

/** Run the alert check if it hasn't run for $minutes. Used while staff have the CRM open, as a fallback for cron. */
function maybe_run_alerts(int $minutes = 10): void
{
    $last = setting('last_alert_check');
    if ($last && strtotime($last) > time() - $minutes * 60) {
        return;
    }
    run_alert_check();
    flush_email_queue();
}

/** Notification email for a single change. Called inside save_record()'s transaction. */
function notify_change(string $coll, ?array $old, array $new, array $user): void
{
    if (!empty($new['example'])) {
        return;
    }
    $who = $user['full_name'] ?: $user['username'];
    $subject = null;
    $intro = '';
    $pairs = [];

    if ($coll === 'bookings') {
        $stay = d_fmt($new['checkIn']) . ' → ' . d_fmt($new['checkOut']);
        if (in_array($new['status'], ['Cancelled', 'No-show'], true)) {
            $extra = [['Fee retained', 'R ' . number_format((float)$new['cancellationFee'], 2)]];
        }
        $pairs = [['Reference', $new['ref']], ['Guest', $new['guestName']], ['Company', $new['company']], ['Room', $new['roomId'] . ' (' . $new['roomType'] . ')'],
            ['Stay', $stay], ['Arrival time', $new['arrivalTime']], ['Status', $new['status']], ['Total', 'R ' . number_format((float)$new['total'], 2)], ['Phone', $new['phone']]];
        if (!$old) {
            $subject = "New reservation {$new['ref']}: {$new['guestName']}, room {$new['roomId']}";
            $intro = "{$who} created a reservation.";
        } elseif ($old['status'] !== $new['status']) {
            $subject = match ($new['status']) {
                'Checked-in' => "Checked in: {$new['guestName']}, room {$new['roomId']}",
                'Checked-out' => "Checked out: {$new['guestName']}, room {$new['roomId']} (room now needs cleaning)",
                'Cancelled' => "Reservation cancelled: {$new['ref']}, {$new['guestName']}",
                'No-show' => "No-show: {$new['ref']}, {$new['guestName']}",
                default => "Reservation {$new['ref']} is now {$new['status']}",
            };
            $intro = "{$who} changed the status from {$old['status']} to {$new['status']}.";
            $pairs = array_merge($pairs, $extra ?? []);
        } elseif ($old['checkIn'] !== $new['checkIn'] || $old['checkOut'] !== $new['checkOut'] || $old['roomId'] !== $new['roomId']) {
            $subject = "Reservation changed {$new['ref']}: {$new['guestName']}";
            $intro = "{$who} changed the stay. Before: room {$old['roomId']}, " . d_fmt($old['checkIn']) . ' → ' . d_fmt($old['checkOut']) . '.';
        }
    } elseif ($coll === 'rooms') {
        $label = ['in' => 'In service', 'maint' => 'Under maintenance', 'oos' => 'Out of service'];
        $id = $new['__id'] ?? '';
        if ($old && $old['service'] !== $new['service']) {
            $subject = $new['service'] === 'in' ? "Room {$id} is back in service" : "Room {$id} is now " . strtolower($label[$new['service']]) . ": {$new['reason']}";
            $intro = "{$who} changed room {$id} from {$label[$old['service']]} to {$label[$new['service']]}.";
            $pairs = [['Room', $id], ['Status', $label[$new['service']]], ['Reason', $new['reason'] ?: '—'], ['Details', $new['reasonDetail'] ?: '—'],
                ['Offline since', d_fmt($new['since'] ?: null)], ['Expected back', d_fmt($new['expectedReturn'] ?: null)]];
        }
    } elseif ($coll === 'tickets') {
        $pairs = [['Ticket', $new['no']], ['Room', $new['roomId']], ['Issue', $new['title']], ['Category', $new['category']], ['Priority', $new['priority']],
            ['Assigned to', $new['assignedTo'] ?: 'Unassigned'], ['Status', $new['status']], ['Due', d_fmt($new['dueDate'] ?: null)], ['Room offline', $new['takesOffline'] ? 'Yes' : 'No']];
        if (!$old) {
            $subject = "New maintenance ticket {$new['no']} ({$new['priority']}): {$new['title']}, room {$new['roomId']}";
            $intro = "{$who} logged a maintenance issue.";
        } elseif ($old['status'] !== $new['status'] || $old['assignedTo'] !== $new['assignedTo'] || $old['priority'] !== $new['priority']) {
            $subject = "Maintenance {$new['no']} is {$new['status']}: {$new['title']}, room {$new['roomId']}";
            $intro = "{$who} updated the ticket (was {$old['status']}, {$old['priority']}, " . ($old['assignedTo'] ?: 'unassigned') . ').';
        }
    } elseif ($coll === 'schedules') {
        if (!$old) {
            $subject = "New preventive task: {$new['task']}";
        } elseif ($old['lastDone'] !== $new['lastDone']) {
            $subject = "Preventive task done: {$new['task']}";
        }
        $intro = "{$who} updated the preventive maintenance schedule.";
        $pairs = [['Task', $new['task']], ['Scope', $new['scope']], ['Every', $new['frequency'] . ' days'], ['Last done', d_fmt($new['lastDone'] ?: null)], ['Owner', $new['owner'] ?: '—']];
    }

    if ($subject) {
        queue_email('notice', $subject, $intro, [['title' => 'Details', 'pairs' => $pairs]]);
    }
}
