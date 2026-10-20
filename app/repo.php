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
            'cancellationFee' => ['cancellation_fee', 'd'], 'cancelledAt' => ['cancelled_on', 'date'], 'groupRef' => ['group_ref', 's', 30], 'guestId' => ['guest_id', 's', 40],
            'specialRequests' => ['special_requests', 't'], 'notes' => ['notes', 't'],
            'createdAt' => ['created_on', 'date'], 'checkedInAt' => ['checked_in_on', 'date'], 'checkedOutAt' => ['checked_out_on', 'date'],
            'example' => ['example', 'b'],
        ],
    ],
    'guests' => [
        'table' => 'guest_profiles',
        'fields' => [
            'guestName' => ['guest_name', 's', 120], 'idNumber' => ['id_number', 's', 40], 'email' => ['email', 's', 120], 'phone' => ['phone', 's', 40],
            'company' => ['company', 's', 120], 'nationality' => ['nationality', 's', 60], 'vehicleReg' => ['vehicle_reg', 's', 30],
            'standingRequests' => ['standing_requests', 't'], 'dietary' => ['dietary', 's', 160], 'roomPreference' => ['room_preference', 's', 160],
            'notes' => ['notes', 't'], 'vip' => ['vip', 'b'], 'stays' => ['stays', 'i'], 'lastStay' => ['last_stay', 'date'], 'example' => ['example', 'b'],
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
const AREA_OF = ['guests' => 'reservations', 'bookings' => 'reservations', 'rooms' => 'rooms', 'tickets' => 'maintenance', 'schedules' => 'maintenance'];
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
    $state['guests'] = [];
    if (can($user, 'reservations')) {
        foreach ($pdo->query('SELECT * FROM guest_profiles ORDER BY last_stay DESC, guest_name LIMIT 5000') as $row) {
            $state['guests'][$row['id']] = to_record('guests', $row);
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

    if ($coll === 'guests') {
        if (trim((string)($rec['guestName'] ?? '')) === '') {
            throw new ApiError('Enter the guest’s name.');
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

/* ---------------- guest profiles & saved special requests ---------------- */

function req_lines(?string $text): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n|;/', (string)$text) ?: []), fn($l) => $l !== ''));
}

/** Dietary and room-preference lines live in their own profile fields, so they are not saved as standing requests. */
function standing_only(string $text): string
{
    return implode("\n", array_filter(req_lines($text), fn($l) => !preg_match('/^(dietary|room preference):/i', $l)));
}

/** Adds lines from $add that aren't already in $base (case-insensitive). */
function merge_requests(string $base, string $add): string
{
    $have = req_lines($base);
    $lower = array_map('mb_strtolower', $have);
    foreach (req_lines($add) as $l) {
        if (!in_array(mb_strtolower($l), $lower, true)) {
            $have[] = $l;
            $lower[] = mb_strtolower($l);
        }
    }
    return implode("\n", $have);
}

/** Phone numbers compared in one format: digits only, South African 0XX… written as 27XX…. */
function norm_phone(string $p): string
{
    $d = (string)preg_replace('/\D+/', '', $p);
    if (strlen($d) === 10 && $d[0] === '0') {
        $d = '27' . substr($d, 1);
    }
    return $d;
}

/** Finds a guest by ID/passport number, then email, then phone + surname. */
function find_guest_profile(array $rec): ?array
{
    $pdo = db();
    $idn = preg_replace('/\s+/', '', (string)($rec['idNumber'] ?? ''));
    if (strlen($idn) >= 5) {
        $st = $pdo->prepare('SELECT * FROM guest_profiles WHERE REPLACE(id_number, " ", "") = ? LIMIT 1');
        $st->execute([$idn]);
        if ($g = $st->fetch()) {
            return $g;
        }
    }
    $email = strtolower(trim((string)($rec['email'] ?? '')));
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $st = $pdo->prepare('SELECT * FROM guest_profiles WHERE LOWER(email) = ? LIMIT 1');
        $st->execute([$email]);
        if ($g = $st->fetch()) {
            return $g;
        }
    }
    $phone = norm_phone((string)($rec['phone'] ?? ''));
    $name = trim((string)($rec['guestName'] ?? ''));
    if (strlen($phone) >= 9 && $name !== '') {
        $surname = mb_strtolower((string)preg_replace('/^.*\s/', '', $name));
        $st = $pdo->prepare('SELECT * FROM guest_profiles WHERE phone <> "" LIMIT 20000');
        $st->execute();
        foreach ($st as $g) {
            if (norm_phone($g['phone']) === $phone && str_ends_with(mb_strtolower($g['guest_name']), $surname)) {
                return $g;
            }
        }
    }
    return null;
}

/**
 * Links a booking to its guest profile (creating one the first time), copies the guest's saved
 * special requests into a NEW booking, and optionally remembers this booking's requests on the profile.
 * Returns a short note for the front desk, e.g. "Returning guest (3 previous stays): 2 saved requests added".
 */
function link_guest_profile(array &$rec, ?array $existing, array $user, bool $remember): string
{
    $pdo = db();
    $g = null;
    if (!empty($rec['guestId'])) {
        $st = $pdo->prepare('SELECT * FROM guest_profiles WHERE id = ?');
        $st->execute([$rec['guestId']]);
        $g = $st->fetch() ?: null;
    }
    $g ??= find_guest_profile($rec);
    $note = '';
    if ($g) {
        if ($existing === null) {
            $saved = trim(implode("\n", array_filter([
                (string)$g['standing_requests'],
                $g['dietary'] ? 'Dietary: ' . $g['dietary'] : '',
                $g['room_preference'] ? 'Room preference: ' . $g['room_preference'] : '',
            ])));
            $before = count(req_lines((string)($rec['specialRequests'] ?? '')));
            $rec['specialRequests'] = merge_requests((string)($rec['specialRequests'] ?? ''), $saved);
            $added = count(req_lines($rec['specialRequests'])) - $before;
            $note = 'Returning guest' . ($g['stays'] ? ' (' . (int)$g['stays'] . ' previous stay' . ((int)$g['stays'] === 1 ? '' : 's') . ')' : '') . ($g['vip'] ? ', VIP' : '')
                . ($added ? ": {$added} saved request" . ($added === 1 ? '' : 's') . ' added to this booking' : '');
        }
        // Fill gaps in the profile from this booking; never overwrite what staff already recorded.
        $fill = [];
        foreach (['id_number' => 'idNumber', 'email' => 'email', 'phone' => 'phone', 'company' => 'company', 'nationality' => 'nationality', 'vehicle_reg' => 'vehicleReg'] as $col => $key) {
            if (trim((string)$g[$col]) === '' && trim((string)($rec[$key] ?? '')) !== '') {
                $fill[$col] = mb_substr(trim((string)$rec[$key]), 0, 120);
            }
        }
        if ($remember && trim((string)($rec['specialRequests'] ?? '')) !== '') {
            $fill['standing_requests'] = merge_requests((string)$g['standing_requests'], standing_only((string)$rec['specialRequests']));
        }
        if ($fill) {
            $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($fill)));
            $pdo->prepare("UPDATE guest_profiles SET $set, updated_by = ? WHERE id = ?")->execute([...array_values($fill), $user['id'], $g['id']]);
        }
        $rec['guestId'] = $g['id'];
    } elseif (trim((string)($rec['guestName'] ?? '')) !== '' && empty($rec['groupRef'])
        && (trim((string)($rec['idNumber'] ?? '')) !== '' || trim((string)($rec['email'] ?? '')) !== '' || trim((string)($rec['phone'] ?? '')) !== '')) {
        // First stay: start a profile so the guest is recognised next time.
        $gid = 'g_' . bin2hex(random_bytes(8));
        $pdo->prepare('INSERT INTO guest_profiles (id, guest_name, id_number, email, phone, company, nationality, vehicle_reg, standing_requests, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$gid, mb_substr(trim((string)$rec['guestName']), 0, 120), trim((string)($rec['idNumber'] ?? '')), trim((string)($rec['email'] ?? '')), trim((string)($rec['phone'] ?? '')),
                trim((string)($rec['company'] ?? '')), trim((string)($rec['nationality'] ?? '')), trim((string)($rec['vehicleReg'] ?? '')),
                $remember ? standing_only((string)($rec['specialRequests'] ?? '')) : '', $user['id']]);
        $rec['guestId'] = $gid;
        $note = 'New guest profile created';
    }
    return $note;
}

/** Stays = current or completed stays; last stay = latest check-in that actually happened. */
function refresh_guest_stats(string $guestId): void
{
    db()->prepare("UPDATE guest_profiles g SET stays = (SELECT COUNT(*) FROM bookings b WHERE b.guest_id = g.id AND b.status IN ('Checked-in','Checked-out')),
        last_stay = (SELECT MAX(check_in) FROM bookings b WHERE b.guest_id = g.id AND b.status IN ('Checked-in','Checked-out')) WHERE g.id = ?")->execute([$guestId]);
}

function save_record(string $coll, string $id, array $data, string $mode, array $user, array &$also = [], bool $notify = true): array
{
    collection($coll);
    check_id($id);
    require_area($user, $coll, 'edit');
    $pdo = db();
    $own = !$pdo->inTransaction(); // group bookings run many saves inside one transaction
    if ($own) {
        $pdo->beginTransaction();
    }
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
        $guestNote = '';
        if ($coll === 'bookings') {
            $guestNote = link_guest_profile($rec, $existing, $user, !empty($data['saveRequests']) || (!array_key_exists('saveRequests', $data) && $existing === null));
        }
        $cols = to_columns($coll, $rec);
        write_row($coll, $id, $cols, $row !== null, $user);
        $changes = $existing ? array_diff_assoc(array_map('strval', array_intersect_key($rec, $existing)), array_map('strval', $existing)) : $rec;
        audit($user, $coll, $id, $row ? 'update' : 'create', $changes);
        $saved = to_record($coll, fetch_row($coll, $id));
        if ($coll === 'bookings' && $saved['guestId'] !== '') {
            refresh_guest_stats($saved['guestId']);
            $g = fetch_row('guests', $saved['guestId']);
            if ($g) {
                $also['guests'] = [$saved['guestId'] => to_record('guests', $g)];
            }
        }
        if ($guestNote !== '') {
            $also['_note'] = $guestNote;
        }
        if ($notify) {
            notify_change($coll, $existing, $saved + ['__id' => $id], $user);
        }
        $also = array_merge($also, apply_side_effects($coll, $existing, $saved, $user));
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return $saved;
}

/* ---------------- bulk (group) bookings ---------------- */

const ROOM_TYPES = ['Single', 'Double', 'Twin', 'Suite', 'Accessible'];

/**
 * Picks available rooms for a group. Rooms in the preferred blocks come first, and rooms are taken
 * block by block, floor by floor, so a group stays together as far as availability allows.
 * $counts: ['Single' => 10, 'Double' => 4, ...]. Returns ['rooms' => [id => type], 'short' => [type => missing]].
 */
function allocate_group(string $ci, string $co, array $counts, array $preferBlocks = [], bool $lock = false): array
{
    if (!valid_date($ci) || !valid_date($co) || $co <= $ci) {
        throw new ApiError('Check-out must be at least one day after check-in.');
    }
    $sql = "SELECT r.id, r.block, r.floor, r.room_type FROM rooms r
        WHERE NOT (r.service <> 'in' AND (r.since IS NULL OR r.since < ?) AND (r.expected_return IS NULL OR r.expected_return > ?))
          AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.room_id = r.id AND b.status IN ('Provisional','Confirmed','Checked-in') AND b.check_in < ? AND b.check_out > ?)
        ORDER BY r.block, r.floor, r.id" . ($lock ? ' FOR UPDATE' : '');
    $st = db()->prepare($sql);
    $st->execute([$co, $ci, $co, $ci]);
    $free = [];
    foreach ($st as $r) {
        $free[$r['room_type']][] = $r;
    }
    $prefer = array_values(array_intersect(['A', 'B', 'C', 'D', 'E', 'F'], array_map('strtoupper', $preferBlocks)));
    $picked = [];
    $short = [];
    foreach ($counts as $type => $n) {
        $n = (int)$n;
        if ($n <= 0) {
            continue;
        }
        if (!in_array($type, ROOM_TYPES, true)) {
            throw new ApiError("Unknown room type “{$type}”.");
        }
        $cands = $free[$type] ?? [];
        // Prefer the chosen blocks, then whichever block/floor has the most free rooms of this type (keeps the group together).
        $perFloor = [];
        $perBlock = [];
        foreach ($cands as $c) {
            $perFloor[$c['block'] . $c['floor']] = ($perFloor[$c['block'] . $c['floor']] ?? 0) + 1;
            $perBlock[$c['block']] = ($perBlock[$c['block']] ?? 0) + 1;
        }
        // Order: preferred blocks first, then the block with the most free rooms, then its fullest-free floors.
        $key = fn($r) => [in_array($r['block'], $prefer, true) ? array_search($r['block'], $prefer, true) : 99,
            -$perBlock[$r['block']], $r['block'], -$perFloor[$r['block'] . $r['floor']], $r['floor'], $r['id']];
        usort($cands, fn($a, $b) => $key($a) <=> $key($b));
        foreach (array_slice($cands, 0, $n) as $c) {
            $picked[$c['id']] = $type;
        }
        if (count($cands) < $n) {
            $short[$type] = $n - count($cands);
        }
    }
    return ['rooms' => $picked, 'short' => $short, 'available' => array_map('count', $free)];
}

/** Books many rooms for one client in a single transaction: all rooms are booked, or none. */
function bulk_book(array $in, array $user): array
{
    require_area($user, 'bookings', 'edit');
    $client = trim((string)($in['company'] ?? ''));
    if ($client === '') {
        throw new ApiError('Enter the client or company name.');
    }
    $ci = (string)($in['checkIn'] ?? '');
    $co = (string)($in['checkOut'] ?? '');
    $rooms = array_values(array_unique(array_filter(array_map('strval', (array)($in['roomIds'] ?? [])))));
    if (!$rooms) {
        throw new ApiError('No rooms were selected. Use “Find rooms” first.');
    }
    if (count($rooms) > 600) {
        throw new ApiError('A group booking can have at most 600 rooms.');
    }
    $names = array_values(array_filter(array_map('trim', (array)($in['guestNames'] ?? []))));
    $rates = (array)($in['rates'] ?? []);
    $status = in_array($in['status'] ?? '', ['Provisional', 'Confirmed'], true) ? $in['status'] : 'Confirmed';
    $contact = trim((string)($in['contact'] ?? ''));
    $pdo = db();
    $groupRef = unique_code('bookings', 'group_ref', 'GRP-' . date('ymd') . '-');
    $records = [];
    $also = [];
    $pdo->beginTransaction();
    try {
        foreach ($rooms as $k => $roomId) {
            $room = fetch_row('rooms', $roomId);
            if (!$room) {
                throw new ApiError("Room {$roomId} does not exist.");
            }
            $id = 'b_' . bin2hex(random_bytes(8));
            $data = [
                'guestName' => $names[$k] ?? (($contact ?: $client) . ' · guest ' . ($k + 1)),
                'company' => $client, 'guestType' => in_array($in['guestType'] ?? '', ['Group', 'Corporate', 'Contractor', 'Student', 'Staff', 'Individual'], true) ? $in['guestType'] : 'Group',
                'phone' => (string)($in['phone'] ?? ''), 'email' => (string)($in['email'] ?? ''),
                'emergencyName' => $contact, 'roomId' => $roomId, 'checkIn' => $ci, 'checkOut' => $co,
                'arrivalTime' => (string)($in['arrivalTime'] ?? ''), 'departureTime' => (string)($in['departureTime'] ?? '10:00'),
                'adults' => 1, 'children' => 0,
                'rate' => isset($rates[$room['room_type']]) && (float)$rates[$room['room_type']] > 0 ? (float)$rates[$room['room_type']] : (float)$room['rate'],
                'deposit' => 0, 'payment' => (string)($in['payment'] ?? 'Company account'), 'billingRef' => (string)($in['billingRef'] ?? ''),
                'source' => (string)($in['source'] ?? 'Corporate account'), 'status' => $status,
                'specialRequests' => (string)($in['specialRequests'] ?? ''), 'notes' => trim('Group booking ' . $groupRef . ($contact ? " · contact {$contact}" : '') . '. ' . (string)($in['notes'] ?? '')),
                'groupRef' => $groupRef,
            ];
            $records[$id] = save_record('bookings', $id, $data, 'set', $user, $also, false);
        }
        $total = array_sum(array_map(fn($r) => (float)$r['total'], $records));
        $byType = array_count_values(array_map(fn($r) => $r['roomType'], $records));
        $nights = (int)((strtotime($co) - strtotime($ci)) / 86400);
        queue_email('notice', "Group booking {$groupRef}: " . count($records) . " rooms for {$client}",
            ($user['full_name'] ?: $user['username']) . ' made a group booking.',
            [['title' => 'Group', 'pairs' => [['Group reference', $groupRef], ['Client', $client], ['Contact', $contact ?: '—'], ['Phone', (string)($in['phone'] ?? '') ?: '—'],
                ['Stay', d_fmt($ci) . ' → ' . d_fmt($co) . " ({$nights} nights)"], ['Rooms', count($records) . ' (' . implode(', ', array_map(fn($t, $c) => "$c $t", array_keys($byType), $byType)) . ')'],
                ['Status', $status], ['Total value', 'R ' . number_format($total, 2)], ['PO / billing reference', (string)($in['billingRef'] ?? '') ?: '—']]],
             ['title' => 'Rooms', 'head' => ['Room', 'Type', 'Guest', 'Rate'], 'rows' => array_map(fn($r) => [$r['roomId'], $r['roomType'], $r['guestName'], 'R ' . number_format((float)$r['rate'], 2)], array_values($records))]]);
        audit($user, 'bookings', $groupRef, 'group-create', ['rooms' => count($records), 'client' => $client]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['groupRef' => $groupRef, 'records' => $records];
}

/** Check in, check out or cancel every booking in a group that can make that move. One summary email. */
function group_status(string $groupRef, string $action, array $user): array
{
    require_area($user, 'bookings', 'edit');
    $moves = ['checkin' => [['Provisional', 'Confirmed'], 'Checked-in'], 'checkout' => [['Checked-in'], 'Checked-out'], 'cancel' => [['Provisional', 'Confirmed'], 'Cancelled']];
    if (!isset($moves[$action]) || !preg_match('/^GRP-[A-Z0-9-]{3,25}$/', $groupRef)) {
        throw new ApiError('Unknown group action.');
    }
    [$fromStatuses, $to] = $moves[$action];
    $pdo = db();
    $in = implode(',', array_fill(0, count($fromStatuses), '?'));
    $st = $pdo->prepare("SELECT id FROM bookings WHERE group_ref = ? AND status IN ($in)");
    $st->execute([$groupRef, ...$fromStatuses]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) {
        throw new ApiError('No bookings in this group can be moved to ' . strtolower($to) . '.');
    }
    $records = [];
    $rooms = [];
    $pdo->beginTransaction();
    try {
        foreach ($ids as $id) {
            $upd = ['status' => $to];
            if ($to === 'Checked-in') {
                $upd['checkedInAt'] = today();
            }
            if ($to === 'Checked-out') {
                $upd['checkedOutAt'] = today();
                $cur = to_record('bookings', fetch_row('bookings', $id));
                if ($cur['checkOut'] > today()) {
                    $upd['checkOut'] = today();
                }
            }
            $also = [];
            $records[$id] = save_record('bookings', $id, $upd, 'update', $user, $also, false);
            $rooms += $also['rooms'] ?? [];
        }
        $first = reset($records);
        queue_email('notice', "Group {$groupRef} " . ['checkin' => 'checked in', 'checkout' => 'checked out', 'cancel' => 'cancelled'][$action] . ': ' . count($records) . " rooms ({$first['company']})",
            ($user['full_name'] ?: $user['username']) . ' updated the whole group to ' . $to . '.',
            [['title' => 'Rooms', 'head' => ['Room', 'Guest', 'Stay', 'Status'], 'rows' => array_map(fn($r) => [$r['roomId'], $r['guestName'], d_fmt($r['checkIn']) . ' → ' . d_fmt($r['checkOut']), $r['status']], array_values($records))]]);
        audit($user, 'bookings', $groupRef, 'group-' . $action, ['rooms' => count($records)]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['records' => $records, 'also' => $rooms ? ['rooms' => $rooms] : []];
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
        // Example guest profiles: 150 guests with stays on record, many with saved requests.
        $reqs = ['Ground floor room', 'Extra pillows', 'Late arrival (after 21:00)', 'Early breakfast at 05:00', 'Quiet room away from the lifts', 'Extra blanket',
            'Packed lunch every day', 'Iron and ironing board in room', 'Room near the parking area', 'Twin beds instead of double'];
        $diet = ['', '', '', 'Halaal', 'Vegetarian', 'Diabetic', 'No pork', 'Gluten free', 'Lactose intolerant'];
        $pick = $pdo->query("SELECT id, guest_name, id_number, email, phone, company, nationality, vehicle_reg FROM bookings
            WHERE example = 1 AND status IN ('Checked-in','Checked-out') ORDER BY id LIMIT 150")->fetchAll();
        $gi = $pdo->prepare('INSERT INTO guest_profiles (id, guest_name, id_number, email, phone, company, nationality, vehicle_reg, standing_requests, dietary, room_preference, vip, example)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
        $link = $pdo->prepare('UPDATE bookings SET guest_id = ? WHERE id = ?');
        foreach ($pick as $k => $b) {
            $gid = 'g_ex' . $k;
            $standing = $k % 3 === 2 ? '' : implode("\n", array_slice($reqs, $k % count($reqs), 1 + $k % 2));
            $gi->execute([$gid, $b['guest_name'], $b['id_number'], $b['email'], $b['phone'], $b['company'], $b['nationality'], $b['vehicle_reg'], $standing,
                $diet[$k % count($diet)], $k % 5 === 0 ? 'Block A, low floor' : '', $k % 17 === 0 ? 1 : 0]);
            $link->execute([$gid, $b['id']]);
            refresh_guest_stats($gid);
            $n++;
        }
        require_once __DIR__ . '/ops.php';
        $n += load_ops_examples($user); // stock, purchase orders, meals, temperature checks, cleaning
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
        // Operations data first (child rows before their parents).
        foreach (['cleaning_log', 'cleaning_tasks', 'kitchen_checks', 'meal_plans'] as $t) {
            $n += $pdo->exec("DELETE FROM `$t` WHERE example = 1");
        }
        $n += $pdo->exec('DELETE l FROM purchase_order_lines l JOIN purchase_orders p ON p.id = l.po_id WHERE p.example = 1');
        // Lines on real orders that point at example items go too (the items are being removed); empty orders are then dropped.
        $n += $pdo->exec('DELETE l FROM purchase_order_lines l JOIN stock_items i ON i.id = l.item_id WHERE i.example = 1');
        $n += $pdo->exec('DELETE FROM purchase_orders WHERE example = 1 OR id NOT IN (SELECT po_id FROM purchase_order_lines)');
        $n += $pdo->exec('DELETE FROM stock_items WHERE example = 1'); // movements go with them (ON DELETE CASCADE)
        foreach (['bookings', 'maintenance_tickets', 'maintenance_schedules', 'finance_expenses', 'guest_profiles'] as $t) {
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
