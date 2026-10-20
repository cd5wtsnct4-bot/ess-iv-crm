<?php
declare(strict_types=1);

/**
 * Maps the four record types the browser works with to MySQL tables.
 * Field types: s string, t text, i int, d decimal, date, time, b bool.
 */
const COLLECTIONS = [
    'bookings' => [
        'table' => 'bookings',
        'fields' => [
            'ref' => ['ref', 's', 30], 'guestName' => ['guest_name', 's', 120], 'idNumber' => ['id_number', 's', 40],
            'nationality' => ['nationality', 's', 60], 'phone' => ['phone', 's', 40], 'email' => ['email', 's', 120],
            'company' => ['company', 's', 120], 'guestType' => ['guest_type', 's', 30], 'vehicleReg' => ['vehicle_reg', 's', 30],
            'emergencyName' => ['emergency_name', 's', 120], 'emergencyPhone' => ['emergency_phone', 's', 40],
            'roomId' => ['room_id', 's', 8], 'roomType' => ['room_type', 's', 20],
            'checkIn' => ['check_in', 'date'], 'checkOut' => ['check_out', 'date'],
            'arrivalTime' => ['arrival_time', 'time'], 'departureTime' => ['departure_time', 'time'],
            'adults' => ['adults', 'i'], 'children' => ['children', 'i'],
            'rate' => ['rate', 'd'], 'total' => ['total', 'd'], 'deposit' => ['deposit', 'd'],
            'payment' => ['payment', 's', 30], 'billingRef' => ['billing_ref', 's', 60], 'source' => ['source', 's', 30],
            'status' => ['status', 's', 20], 'keyCard' => ['key_card', 's', 30],
            'cancellationFee' => ['cancellation_fee', 'd'], 'cancelledAt' => ['cancelled_on', 'date'],
            'specialRequests' => ['special_requests', 't'], 'notes' => ['notes', 't'],
            'createdAt' => ['created_on', 'date'], 'checkedInAt' => ['checked_in_on', 'date'], 'checkedOutAt' => ['checked_out_on', 'date'],
            'example' => ['example', 'b'],
        ],
    ],
    'rooms' => [
        'table' => 'rooms',
        'fields' => [
            'service' => ['service', 's', 5], 'hk' => ['hk', 's', 10], 'reason' => ['reason', 's', 80],
            'reasonDetail' => ['reason_detail', 't'], 'since' => ['since', 'date'], 'expectedReturn' => ['expected_return', 'date'],
            'example' => ['example', 'b'],
        ],
    ],
    'tickets' => [
        'table' => 'maintenance_tickets',
        'fields' => [
            'no' => ['ticket_no', 's', 30], 'roomId' => ['room_id', 's', 8], 'category' => ['category', 's', 40],
            'priority' => ['priority', 's', 10], 'title' => ['title', 's', 200], 'description' => ['description', 't'],
            'reportedBy' => ['reported_by', 's', 60], 'assignedTo' => ['assigned_to', 's', 80], 'status' => ['status', 's', 20],
            'reportedAt' => ['reported_on', 'date'], 'dueDate' => ['due_date', 'date'], 'completedAt' => ['completed_on', 'date'],
            'estCost' => ['est_cost', 'd'], 'actualCost' => ['actual_cost', 'd'], 'takesOffline' => ['takes_offline', 'b'],
            'example' => ['example', 'b'],
        ],
    ],
    'schedules' => [
        'table' => 'maintenance_schedules',
        'fields' => [
            'task' => ['task', 's', 200], 'scope' => ['scope', 's', 120], 'frequency' => ['frequency_days', 'i'],
            'lastDone' => ['last_done', 'date'], 'owner' => ['owner', 's', 80], 'example' => ['example', 'b'],
        ],
    ],
];

/** Which access area guards each record type. */
const AREA_OF = ['bookings' => 'reservations', 'rooms' => 'rooms', 'tickets' => 'maintenance', 'schedules' => 'maintenance'];
const TICKET_ROOM_REASON = [
    'Plumbing' => 'Plumbing leak / blockage', 'Electrical' => 'Electrical fault', 'HVAC' => 'Air-con / HVAC failure',
    'Pest control' => 'Pest control treatment', 'Fire & safety' => 'Fire & safety compliance', 'Locks & access' => 'Door lock / access fault',
    'Carpentry & furniture' => 'Furniture / fixtures broken', 'Cleaning' => 'Deep clean',
];

function require_area(array $user, string $coll, string $level): void
{
    $area = AREA_OF[$coll] ?? null;
    if ($area && !can($user, $area, $level)) {
        $what = AREAS[$area];
        throw new ApiError($level === 'edit'
            ? "You have view-only or no access to {$what}. Ask an administrator for edit access."
            : "You don't have access to {$what}.", 403);
    }
}

const BOOKING_STATUSES = ['Provisional', 'Confirmed', 'Checked-in', 'Checked-out', 'Cancelled', 'No-show'];
const ACTIVE_STATUSES = ['Provisional', 'Confirmed', 'Checked-in'];
const TICKET_STATUSES = ['Open', 'Assigned', 'In progress', 'Awaiting parts', 'Completed', 'Verified'];
const PRIORITIES = ['Critical', 'High', 'Medium', 'Low'];

final class ApiError extends RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

function collection(string $coll): array
{
    if (!isset(COLLECTIONS[$coll])) {
        throw new ApiError('Unknown record type.', 404);
    }
    return COLLECTIONS[$coll];
}

function valid_date(string $s): bool
{
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

/** Browser record -> column values. Unknown keys are ignored. */
function to_columns(string $coll, array $rec): array
{
    $out = [];
    foreach (collection($coll)['fields'] as $key => $spec) {
        if (!array_key_exists($key, $rec)) {
            continue;
        }
        [$col, $type] = $spec;
        $v = $rec[$key];
        switch ($type) {
            case 's':
                $v = mb_substr(trim((string)($v ?? '')), 0, $spec[2]);
                break;
            case 't':
                $v = mb_substr((string)($v ?? ''), 0, 20000);
                break;
            case 'i':
                $v = max(0, (int)$v);
                break;
            case 'd':
                $v = round(max(0, (float)$v), 2);
                break;
            case 'b':
                $v = $v ? 1 : 0;
                break;
            case 'date':
                $v = trim((string)($v ?? ''));
                if ($v === '') {
                    $v = null;
                } elseif (!valid_date($v)) {
                    throw new ApiError("“{$key}” is not a valid date.");
                }
                break;
            case 'time':
                $v = trim((string)($v ?? ''));
                if ($v === '') {
                    $v = null;
                } elseif (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $v)) {
                    throw new ApiError("“{$key}” is not a valid time.");
                }
                break;
        }
        $out[$col] = $v;
    }
    return $out;
}

/** Database row -> browser record. */
function to_record(string $coll, array $row): array
{
    $rec = [];
    foreach (collection($coll)['fields'] as $key => $spec) {
        [$col, $type] = $spec;
        $v = $row[$col] ?? null;
        $rec[$key] = match ($type) {
            'i' => (int)$v,
            'd' => (float)$v,
            'b' => (bool)$v,
            'time' => $v === null ? '' : substr((string)$v, 0, 5),
            default => $v === null ? '' : (string)$v,
        };
    }
    return $rec;
}

function load_state(array $user): array
{
    $pdo = db();
    $since = date('Y-m-d', strtotime('-60 days'));
    $state = ['bookings' => [], 'rooms' => [], 'tickets' => [], 'schedules' => []];

    // Bookings are visible to anyone who can see reservations or room status (rooms show who is in them).
    if (can($user, 'reservations') || can($user, 'rooms')) {
        $st = $pdo->prepare("SELECT * FROM bookings WHERE check_out >= ? OR status IN ('Provisional','Confirmed','Checked-in') ORDER BY check_in");
        $st->execute([$since]);
        foreach ($st as $row) {
            $state['bookings'][$row['id']] = to_record('bookings', $row);
        }
    }
    // Room condition is always loaded: availability checks need to know which rooms are offline.
    foreach ($pdo->query('SELECT * FROM rooms') as $row) {
        $state['rooms'][$row['id']] = to_record('rooms', $row);
    }
    if (can($user, 'maintenance')) {
        $st = $pdo->prepare("SELECT * FROM maintenance_tickets WHERE status NOT IN ('Completed','Verified') OR completed_on IS NULL OR completed_on >= ?");
        $st->execute([date('Y-m-d', strtotime('-365 days'))]);
        foreach ($st as $row) {
            $state['tickets'][$row['id']] = to_record('tickets', $row);
        }
        foreach ($pdo->query('SELECT * FROM maintenance_schedules') as $row) {
            $state['schedules'][$row['id']] = to_record('schedules', $row);
        }
    }
    // Empty maps must stay JSON objects, not arrays.
    foreach ($state as $k => $v) {
        $state[$k] = (object)$v;
    }
    return $state;
}

function fetch_row(string $coll, string $id, bool $lock = false): ?array
{
    $t = collection($coll)['table'];
    $st = db()->prepare("SELECT * FROM `$t` WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function write_row(string $coll, string $id, array $cols, bool $exists, ?array $user): void
{
    $t = collection($coll)['table'];
    $cols['updated_by'] = $user['id'] ?? null;
    if ($exists) {
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($cols)));
        db()->prepare("UPDATE `$t` SET $set WHERE id = ?")->execute([...array_values($cols), $id]);
    } else {
        $cols = ['id' => $id] + $cols;
        $names = implode(', ', array_map(fn($c) => "`$c`", array_keys($cols)));
        $marks = implode(', ', array_fill(0, count($cols), '?'));
        db()->prepare("INSERT INTO `$t` ($names) VALUES ($marks)")->execute(array_values($cols));
    }
}

function audit(?array $user, string $entity, string $id, string $action, ?array $details = null): void
{
    db()->prepare('INSERT INTO audit_log (user_id, username, entity, entity_id, action, details) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$user['id'] ?? null, $user['username'] ?? '', $entity, $id, $action, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null]);
}

function unique_code(string $table, string $col, string $prefix): string
{
    $st = db()->prepare("SELECT 1 FROM `$table` WHERE `$col` = ?");
    do {
        $code = $prefix . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $st->execute([$code]);
    } while ($st->fetchColumn());
    return $code;
}

function fmt_date(string $d): string
{
    return date('D j M', strtotime($d));
}

/** Business rules applied to every save, on top of the checks the screens already make. */
function validate(string $coll, string $id, array &$rec, ?array $existing): void
{
    $pdo = db();
    if ($coll === 'bookings') {
        if (trim((string)($rec['guestName'] ?? '')) === '') {
            throw new ApiError('Enter the guest’s full name.');
        }
        $ci = (string)($rec['checkIn'] ?? '');
        $co = (string)($rec['checkOut'] ?? '');
        if (!valid_date($ci) || !valid_date($co) || $co <= $ci) {
            throw new ApiError('Check-out must be at least one day after check-in.');
        }
        $status = (string)($rec['status'] ?? 'Confirmed');
        if (!in_array($status, BOOKING_STATUSES, true)) {
            throw new ApiError('Unknown booking status.');
        }
        $room = fetch_row('rooms', (string)($rec['roomId'] ?? ''), true); // locks the room row so two clerks can't book it at once
        if (!$room) {
            throw new ApiError('Choose a valid room.');
        }
        if (in_array($status, ACTIVE_STATUSES, true)) {
            $st = $pdo->prepare("SELECT guest_name, check_in, check_out FROM bookings
                WHERE room_id = ? AND id <> ? AND status IN ('Provisional','Confirmed','Checked-in') AND check_in < ? AND check_out > ? LIMIT 1");
            $st->execute([$room['id'], $id, $co, $ci]);
            if ($c = $st->fetch()) {
                throw new ApiError(sprintf('Room %s is already booked by %s (%s – %s). Pick another room.',
                    $room['id'], $c['guest_name'], fmt_date($c['check_in']), fmt_date($c['check_out'])), 409);
            }
            if ($room['service'] !== 'in'
                && ($room['since'] === null || $room['since'] < $co)
                && ($room['expected_return'] === null || $room['expected_return'] > $ci)) {
                throw new ApiError("Room {$room['id']} is out of service or under maintenance for part of these dates.", 409);
            }
        }
        $rec['roomType'] = $room['room_type'];
        // Cancellations / no-shows: stamp the date, and retain the deposit as the fee unless a fee was entered.
        if (in_array($status, ['Cancelled', 'No-show'], true)) {
            if (!$existing || !in_array($existing['status'], ['Cancelled', 'No-show'], true)) {
                $rec['cancelledAt'] = today();
                if ((float)($rec['cancellationFee'] ?? 0) <= 0) {
                    $rec['cancellationFee'] = (float)($rec['deposit'] ?? 0);
                }
            }
        } else {
            $rec['cancelledAt'] = '';
            $rec['cancellationFee'] = 0;
        }
        $nights = (int)((strtotime($co) - strtotime($ci)) / 86400);
        $rec['total'] = round(max(0, (float)($rec['rate'] ?? 0)) * $nights, 2);
        if (empty($rec['createdAt'])) {
            $rec['createdAt'] = today();
        }
        $ref = trim((string)($rec['ref'] ?? ''));
        if ($ref === '' || ($existing === null && $pdo->query('SELECT 1 FROM bookings WHERE ref = ' . $pdo->quote($ref))->fetchColumn())) {
            $rec['ref'] = unique_code('bookings', 'ref', 'ESS-' . date('ymd') . '-');
        }
    }

    if ($coll === 'rooms') {
        $svc = (string)($rec['service'] ?? 'in');
        if (!in_array($svc, ['in', 'maint', 'oos'], true)) {
            throw new ApiError('Unknown service status.');
        }
        if (!in_array((string)($rec['hk'] ?? 'Clean'), ['Clean', 'Dirty', 'Inspected'], true)) {
            throw new ApiError('Unknown housekeeping status.');
        }
        if ($svc === 'in') {
            $rec['reason'] = '';
            $rec['reasonDetail'] = '';
            $rec['since'] = '';
            $rec['expectedReturn'] = '';
        } else {
            if (trim((string)($rec['reason'] ?? '')) === '') {
                throw new ApiError('Choose a reason for taking the room offline.');
            }
            if (!empty($rec['since']) && !empty($rec['expectedReturn']) && $rec['expectedReturn'] < $rec['since']) {
                throw new ApiError('Expected return date must be on or after the offline date.');
            }
        }
    }

    if ($coll === 'tickets') {
        if (trim((string)($rec['title'] ?? '')) === '') {
            throw new ApiError('Add a short description of the issue.');
        }
        if (!fetch_row('rooms', (string)($rec['roomId'] ?? ''))) {
            throw new ApiError('Choose the room this issue is in.');
        }
        if (!in_array($rec['priority'] ?? '', PRIORITIES, true)) {
            $rec['priority'] = 'Medium';
        }
        if (!in_array($rec['status'] ?? '', TICKET_STATUSES, true)) {
            $rec['status'] = 'Open';
        }
        if (in_array($rec['status'], ['Completed', 'Verified'], true) && empty($rec['completedAt'])) {
            $rec['completedAt'] = today();
        }
        if (empty($rec['reportedAt'])) {
            $rec['reportedAt'] = today();
        }
        $no = trim((string)($rec['no'] ?? ''));
        if ($no === '' || ($existing === null && $pdo->query('SELECT 1 FROM maintenance_tickets WHERE ticket_no = ' . $pdo->quote($no))->fetchColumn())) {
            $rec['no'] = unique_code('maintenance_tickets', 'ticket_no', 'MT-' . date('ym') . '-');
        }
    }

    if ($coll === 'schedules') {
        if (trim((string)($rec['task'] ?? '')) === '') {
            throw new ApiError('Name the task.');
        }
        $rec['frequency'] = max(1, (int)($rec['frequency'] ?? 30));
        if (empty($rec['lastDone'])) {
            $rec['lastDone'] = today();
        }
    }
}

function check_id(string $id): void
{
    if (!preg_match('/^[A-Za-z0-9_\-]{1,40}$/', $id)) {
        throw new ApiError('Invalid record id.');
    }
}

/** mode "set" replaces the record; "update" merges the given fields into it. */
/**
 * Knock-on changes the server makes itself, so they happen even for users who can't edit rooms:
 *  - checking a guest out marks the room Dirty for housekeeping;
 *  - a maintenance ticket that takes its room offline puts the room Under maintenance,
 *    and completing the last such ticket puts the room back in service (Dirty, ready to clean).
 * Returns the room records it changed.
 */
function apply_side_effects(string $coll, ?array $old, array $new, array $user): array
{
    $changed = [];
    $setRoom = function (string $roomId, array $fields) use (&$changed, $user) {
        $before = fetch_row('rooms', $roomId, true);
        if (!$before) {
            return;
        }
        $beforeRec = to_record('rooms', $before);
        write_row('rooms', $roomId, to_columns('rooms', $fields), true, $user);
        $after = to_record('rooms', fetch_row('rooms', $roomId));
        audit($user, 'rooms', $roomId, 'auto-update', $fields);
        notify_change('rooms', $beforeRec, $after + ['__id' => $roomId], $user);
        $changed[$roomId] = $after;
    };

    if ($coll === 'bookings' && $new['status'] === 'Checked-out' && ($old['status'] ?? '') !== 'Checked-out') {
        $setRoom($new['roomId'], ['hk' => 'Dirty']);
    }

    if ($coll === 'tickets') {
        $open = !in_array($new['status'], ['Completed', 'Verified'], true);
        $room = fetch_row('rooms', $new['roomId']);
        if ($room && $new['takesOffline'] && $open && $room['service'] === 'in') {
            $setRoom($new['roomId'], [
                'service' => 'maint', 'reason' => TICKET_ROOM_REASON[$new['category']] ?? 'Other',
                'reasonDetail' => $new['title'] . ' (' . $new['no'] . ')',
                'since' => $new['reportedAt'] ?: today(), 'expectedReturn' => $new['dueDate'],
            ]);
        } elseif ($room && !$open && $old && !in_array($old['status'], ['Completed', 'Verified'], true)
            && ($new['takesOffline'] || $old['takesOffline']) && $room['service'] === 'maint') {
            $st = db()->prepare("SELECT COUNT(*) FROM maintenance_tickets WHERE room_id = ? AND takes_offline = 1 AND status NOT IN ('Completed','Verified')");
            $st->execute([$new['roomId']]);
            if ((int)$st->fetchColumn() === 0) {
                $setRoom($new['roomId'], ['service' => 'in', 'hk' => 'Dirty', 'reason' => '', 'reasonDetail' => '', 'since' => '', 'expectedReturn' => '']);
            }
        }
    }
    return $changed ? ['rooms' => $changed] : [];
}

function save_record(string $coll, string $id, array $data, string $mode, array $user, array &$also = []): array
{
    collection($coll);
    check_id($id);
    require_area($user, $coll, 'edit');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $row = fetch_row($coll, $id, true);
        if ($coll === 'rooms' && !$row) {
            throw new ApiError('That room does not exist.', 404);
        }
        $existing = $row ? to_record($coll, $row) : null;
        $rec = ($mode === 'update' && $existing) ? array_merge($existing, $data) : $data;
        if ($coll === 'rooms' && $existing) {
            $rec = array_merge($existing, $rec);
        }
        validate($coll, $id, $rec, $existing);
        $cols = to_columns($coll, $rec);
        write_row($coll, $id, $cols, $row !== null, $user);
        $changes = $existing ? array_diff_assoc(array_map('strval', array_intersect_key($rec, $existing)), array_map('strval', $existing)) : $rec;
        audit($user, $coll, $id, $row ? 'update' : 'create', $changes);
        $saved = to_record($coll, fetch_row($coll, $id));
        notify_change($coll, $existing, $saved + ['__id' => $id], $user);
        $also = apply_side_effects($coll, $existing, $saved, $user);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $saved;
}

function delete_record(string $coll, string $id, array $user): void
{
    $c = collection($coll);
    check_id($id);
    require_area($user, $coll, 'edit');
    if ($coll === 'rooms') {
        db()->prepare("UPDATE rooms SET service='in', hk='Clean', reason='', reason_detail=NULL, since=NULL, expected_return=NULL, example=0, updated_by=? WHERE id = ?")
            ->execute([$user['id'], $id]);
        audit($user, $coll, $id, 'reset');
        return;
    }
    if ($coll === 'bookings') {
        throw new ApiError('Reservations are cancelled, not deleted, so the history is kept.', 403);
    }
    if ($user['role'] !== 'admin') {
        throw new ApiError('Only an administrator can delete records.', 403);
    }
    db()->prepare("DELETE FROM `{$c['table']}` WHERE id = ?")->execute([$id]);
    audit($user, $coll, $id, 'delete');
}

/** Bulk-load the example data set generated in the browser (admins only). */
function load_examples(array $data, array $user): int
{
    $pdo = db();
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach (['rooms', 'schedules', 'tickets', 'bookings'] as $coll) {
            foreach (($data[$coll] ?? []) as $id => $rec) {
                if (!is_array($rec)) {
                    continue;
                }
                check_id((string)$id);
                $rec['example'] = true;
                $row = fetch_row($coll, (string)$id);
                if ($coll === 'rooms' && !$row) {
                    continue;
                }
                if ($coll === 'bookings' && empty($rec['ref'])) {
                    $rec['ref'] = 'EX-' . $id;
                }
                write_row($coll, (string)$id, to_columns($coll, $rec), $row !== null, $user);
                $n++;
            }
        }
        // Example operating expenses for the last four months, so the Profit & Loss is complete.
        $ins = $pdo->prepare('INSERT INTO finance_expenses (spent_on, category, description, supplier, amount, example, created_by) VALUES (?, ?, ?, ?, ?, 1, ?)');
        $monthly = [
            ['Staff salaries & wages', 'Front desk, housekeeping & maintenance payroll', 'Payroll', 612000],
            ['Utilities: electricity', 'Municipal electricity', 'City of Johannesburg', 148000],
            ['Utilities: water & sanitation', 'Municipal water', 'City of Johannesburg', 46500],
            ['Cleaning & laundry', 'Linen service and cleaning chemicals', 'Bidvest Laundry', 72800],
            ['Security', 'Guarding & access control', 'Fidelity Services', 89000],
            ['Insurance', 'Buildings & public liability', 'Santam', 31500],
            ['Internet & IT', 'Fibre and Wi-Fi support', 'Vox', 18900],
            ['Rates & taxes', 'Municipal property rates', 'City of Johannesburg', 54000],
        ];
        for ($i = 3; $i >= 0; $i--) {
            $day = date('Y-m-25', strtotime("first day of -{$i} month"));
            if ($day > today()) {
                continue;
            }
            foreach ($monthly as $k => [$cat, $desc, $sup, $amt]) {
                $ins->execute([$day, $cat, $desc, $sup, round($amt * (0.94 + (($i * 7 + $k * 3) % 12) / 100), 2), $user['id']]);
                $n++;
            }
        }
        audit($user, 'system', 'examples', 'load', ['records' => $n]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $n;
}

function clear_examples(array $user): int
{
    $pdo = db();
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach (['bookings', 'maintenance_tickets', 'maintenance_schedules', 'finance_expenses'] as $t) {
            $n += $pdo->exec("DELETE FROM `$t` WHERE example = 1");
        }
        $n += $pdo->exec("UPDATE rooms SET service='in', hk='Clean', reason='', reason_detail=NULL, since=NULL, expected_return=NULL, example=0 WHERE example = 1");
        audit($user, 'system', 'examples', 'clear', ['records' => $n]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $n;
}
