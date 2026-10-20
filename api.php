<?php
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/repo.php';
require __DIR__ . '/app/mailer.php';
require __DIR__ . '/app/alerts.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Email work runs after the reply is sent, so staff never wait for the mail server. */
$AFTER = null;

function respond(array|object $body, int $status = 200): never
{
    global $AFTER;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // don't hold the session lock while email is sent
    }
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($AFTER && $status < 400) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            header('Content-Length: ' . ob_get_length());
            @ob_end_flush();
            flush();
        }
        ignore_user_abort(true);
        try {
            ($AFTER)();
        } catch (Throwable $e) {
            error_log('[ess-iv-crm] background email: ' . $e->getMessage());
        }
    }
    exit;
}

ob_start();

try {
    $user = current_user();
    if (!$user) {
        throw new ApiError('Your session has ended. Sign in again.', 401);
    }
    $action = (string)($_GET['r'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'state') {
            $AFTER = fn() => maybe_run_alerts();
            $state = load_state($user);
            $state['ops'] = ops_alerts_for($user);
            respond($state);
        }
        throw new ApiError('Unknown request.', 404);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new ApiError('Method not allowed.', 405);
    }
    if (!csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
        throw new ApiError('Your security token has expired. Reload the page and try again.', 419);
    }
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) {
        throw new ApiError('The request could not be read.');
    }

    $AFTER = fn() => flush_email_queue(10);
    switch ($action) {
        case 'save':
            $data = is_array($in['data'] ?? null) ? $in['data'] : [];
            $also = [];
            $rec = save_record((string)($in['coll'] ?? ''), (string)($in['id'] ?? ''), $data, (string)($in['mode'] ?? 'set'), $user, $also);
            $note = $also['_note'] ?? '';
            unset($also['_note']);
            respond(['record' => $rec, 'also' => (object)$also, 'note' => $note]);
        case 'bulk_preview':
            require_area($user, 'bookings', 'edit');
            $a = allocate_group((string)($in['checkIn'] ?? ''), (string)($in['checkOut'] ?? ''), (array)($in['counts'] ?? []), (array)($in['blocks'] ?? []));
            respond(['rooms' => (object)$a['rooms'], 'short' => (object)$a['short'], 'available' => (object)$a['available']]);
        case 'bulk_book':
            $r = bulk_book($in, $user);
            respond(['groupRef' => $r['groupRef'], 'records' => (object)$r['records']]);
        case 'group_status':
            $r = group_status((string)($in['groupRef'] ?? ''), (string)($in['action'] ?? ''), $user);
            respond(['records' => (object)$r['records'], 'also' => (object)$r['also']]);
        case 'delete':
            delete_record((string)($in['coll'] ?? ''), (string)($in['id'] ?? ''), $user);
            respond(['ok' => true]);
        case 'demo_load':
            if ($user['role'] !== 'admin') {
                throw new ApiError('Only an administrator can load example data.', 403);
            }
            respond(['count' => load_examples(is_array($in['data'] ?? null) ? $in['data'] : [], $user)]);
        case 'demo_clear':
            if ($user['role'] !== 'admin') {
                throw new ApiError('Only an administrator can clear example data.', 403);
            }
            respond(['count' => clear_examples($user)]);
        default:
            throw new ApiError('Unknown request.', 404);
    }
} catch (ApiError $e) {
    respond(['error' => $e->getMessage()], $e->status);
} catch (PDOException $e) {
    error_log('[ess-iv-crm] ' . $e);
    $msg = !empty($CONFIG['debug']) ? $e->getMessage() : 'The database could not save this change. Try again; if it keeps happening, check the server error log.';
    respond(['error' => $msg], 500);
} catch (Throwable $e) {
    error_log('[ess-iv-crm] ' . $e);
    respond(['error' => !empty($CONFIG['debug']) ? $e->getMessage() : 'Server error. Check the server error log.'], 500);
}
