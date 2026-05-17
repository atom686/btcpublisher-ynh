<?php
/**
 * btcpublisher web entry — front controller + tiny router.
 *
 * Routes:
 *   GET  /                   overview (status table)
 *   GET  /upload             upload form
 *   POST /upload/single      one tx hex + (exact | random)
 *   POST /upload/bulk        N hexes + window controls
 *   POST /random/preview     return candidate fire-time fragment
 *   POST /random/commit      INSERT a previewed candidate
 *   GET  /tx/{id}            detail page
 *   POST /tx/{id}/cancel     delete pending
 *   POST /tx/{id}/reschedule change fire_at
 *   POST /tx/{id}/fire-now   set fire_at = now
 *   POST /tx/{id}/retry      failed → pending now
 *   GET  /settings           settings page (Telegram, etc.)
 *   POST /settings           save settings
 *   GET  /api/status.json    live status strip data (JSON)
 *   GET  /assets/style.css   static CSS
 *   GET  /healthz            liveness
 */

declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';

use Btcpub\{State, Scheduler, Broadcast};
use function Btcpub\{load_config, load_settings, save_settings, current_user};
// h(), url_for(), relative_time(), clean_hex() are in the global namespace —
// no `use` needed; views call them directly.

$cfg = load_config();
$pdo = State\open_db($cfg['db_path']);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$prefix = rtrim($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '', '/');
if ($prefix !== '' && str_starts_with($path, $prefix)) {
    $path = substr($path, strlen($prefix)) ?: '/';
}

try {
    route($method, $path, $pdo, $cfg);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('[btcpub] ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    render('error', ['title' => '500', 'message' => $e->getMessage()]);
}

// ---------------------------------------------------------------------------

function route(string $method, string $path, PDO $pdo, array $cfg): void {
    if ($path === '/healthz') {
        header('Content-Type: text/plain'); echo 'ok'; return;
    }
    // Static assets (CSS) live under public/assets/ and are served directly
    // by nginx in production. The dev server (`php -S -t public`) also serves
    // them as static files. No PHP route needed.
    if ($path === '/api/status.json' && $method === 'GET') {
        header('Content-Type: application/json');
        echo json_encode(api_status($pdo));
        return;
    }

    if ($path === '/' && $method === 'GET')                  { overview($pdo); return; }
    if ($path === '/upload' && $method === 'GET')            { render('upload', []); return; }
    if ($path === '/upload/single' && $method === 'POST')    { upload_single($pdo, $cfg); return; }
    if ($path === '/upload/bulk' && $method === 'POST')      { upload_bulk($pdo, $cfg); return; }
    if ($path === '/random/preview' && $method === 'POST')   { random_preview($pdo); return; }
    if ($path === '/random/commit' && $method === 'POST')    { random_commit($pdo); return; }
    if ($path === '/settings' && $method === 'GET')          { settings_form($cfg); return; }
    if ($path === '/settings' && $method === 'POST')         { settings_save($cfg); return; }
    if (preg_match('#^/tx/(\d+)$#', $path, $m) && $method === 'GET') {
        tx_detail($pdo, (int) $m[1]); return;
    }
    if (preg_match('#^/tx/(\d+)/(cancel|reschedule|fire-now|retry)$#', $path, $m) && $method === 'POST') {
        tx_action($pdo, (int) $m[1], $m[2]); return;
    }

    http_response_code(404);
    render('error', ['title' => '404', 'message' => 'no route: ' . $path]);
}

// ---- handlers -------------------------------------------------------------

function overview(PDO $pdo): void {
    $txs = State\list_all($pdo);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    render('overview', ['txs' => $txs, 'now' => $now]);
}

function tx_detail(PDO $pdo, int $id): void {
    $tx = State\get($pdo, $id);
    if ($tx === null) {
        http_response_code(404);
        render('error', ['title' => '404', 'message' => "no tx #{$id}"]);
        return;
    }
    $decoded = null; $total = null;
    try {
        $raw = hex2bin($tx['hex']);
        if ($raw !== false) {
            $decoded = Btcpub\Decode\decode_tx($raw);
            $total = Btcpub\Decode\total_out_sat($decoded);
        }
    } catch (Throwable) { /* render anyway */ }
    render('tx_detail', ['tx' => $tx, 'decoded' => $decoded, 'total_out_sat' => $total]);
}

function upload_single(PDO $pdo, array $cfg): void {
    try {
        $hex = clean_hex($_POST['hex_tx'] ?? '');
    } catch (Throwable $e) {
        err_fragment('bad hex: ' . $e->getMessage()); return;
    }

    $mode = $_POST['mode'] ?? '';
    if ($mode === 'exact') {
        try {
            $t = Scheduler\parse_when($_POST['fire_at'] ?? '');
        } catch (Throwable $e) {
            err_fragment('bad fire_at: ' . $e->getMessage()); return;
        }
        if ($t <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            err_fragment('fire_at must be in the future'); return;
        }
        $id = State\insert_one($pdo, $hex, $t);
        error_log("[btcpub] upload single exact id={$id} fire_at=" . $t->format('c'));
        hx_redirect('/'); return;
    }
    if ($mode === 'random') {
        try {
            $start = Scheduler\parse_when($_POST['window_start'] ?? 'now');
            $end   = Scheduler\parse_when($_POST['window_end']   ?? '+7d');
            $gap   = Scheduler\parse_duration($_POST['min_gap']  ?? '30m');
            $candidate = Scheduler\pick_random_slot($start, $end, $gap, State\list_pending_fire_times($pdo));
        } catch (Throwable $e) {
            err_fragment($e->getMessage()); return;
        }
        render_partial('random_preview', [
            'hex_tx' => $hex,
            'candidate' => $candidate,
            'window_start' => $_POST['window_start'] ?? 'now',
            'window_end'   => $_POST['window_end']   ?? '+7d',
            'min_gap'      => $_POST['min_gap']      ?? '30m',
        ]);
        return;
    }
    err_fragment("unknown mode: {$mode}");
}

function random_preview(PDO $pdo): void {
    try {
        $hex   = clean_hex($_POST['hex_tx'] ?? '');
        $start = Scheduler\parse_when($_POST['window_start'] ?? 'now');
        $end   = Scheduler\parse_when($_POST['window_end']   ?? '+7d');
        $gap   = Scheduler\parse_duration($_POST['min_gap']  ?? '30m');
        $candidate = Scheduler\pick_random_slot($start, $end, $gap, State\list_pending_fire_times($pdo));
    } catch (Throwable $e) {
        err_fragment($e->getMessage()); return;
    }
    render_partial('random_preview', [
        'hex_tx' => $hex, 'candidate' => $candidate,
        'window_start' => $_POST['window_start'] ?? 'now',
        'window_end'   => $_POST['window_end']   ?? '+7d',
        'min_gap'      => $_POST['min_gap']      ?? '30m',
    ]);
}

function random_commit(PDO $pdo): void {
    try {
        $hex = clean_hex($_POST['hex_tx'] ?? '');
        $t = new DateTimeImmutable($_POST['fire_at'] ?? '');
        $t = $t->setTimezone(new DateTimeZone('UTC'));
    } catch (Throwable $e) {
        err_fragment('bad form data: ' . $e->getMessage()); return;
    }
    if ($t <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
        err_fragment('candidate is in the past — please re-roll'); return;
    }
    State\insert_one($pdo, $hex, $t);
    hx_redirect('/');
}

function upload_bulk(PDO $pdo, array $cfg): void {
    $lines = preg_split('/\r?\n/', (string) ($_POST['hex_blob'] ?? ''));
    $hexes = [];
    foreach ($lines as $i => $ln) {
        $ln = trim($ln);
        if ($ln === '' || str_starts_with($ln, '#')) continue;
        try {
            $hexes[] = clean_hex($ln);
        } catch (Throwable $e) {
            err_fragment("line " . ($i + 1) . ": " . $e->getMessage()); return;
        }
    }
    if (!$hexes) {
        err_fragment('no tx hexes in input'); return;
    }

    try {
        $start = Scheduler\parse_when($_POST['window_start'] ?? 'now');
        $end   = Scheduler\parse_when($_POST['window_end']   ?? '+7d');
        $gap   = Scheduler\parse_duration($_POST['min_gap']  ?? '30m');
        $seed  = ($_POST['seed'] ?? '') !== '' ? (int) $_POST['seed'] : null;
    } catch (Throwable $e) {
        err_fragment('bad window/gap/seed: ' . $e->getMessage()); return;
    }

    $preserve = ($_POST['preserve_existing'] ?? '') === 'on';
    if (!$preserve) State\clear_all($pdo);

    if ($preserve) {
        $existing = State\list_pending_fire_times($pdo);
        $placed = [];
        foreach ($hexes as $h) {
            try {
                $t = Scheduler\pick_random_slot($start, $end, $gap, array_merge($existing, $placed));
            } catch (Throwable $e) {
                err_fragment('placed ' . count($placed) . '/' . count($hexes) . ' before: ' . $e->getMessage());
                return;
            }
            State\insert_one($pdo, $h, $t);
            $placed[] = $t;
        }
    } else {
        $times = Scheduler\generate_fire_times(count($hexes), $start, $end, $gap, $seed);
        State\insert_schedule($pdo, array_map(null, $hexes, $times));
    }
    hx_redirect('/');
}

function tx_action(PDO $pdo, int $id, string $action): void {
    $tx = State\get($pdo, $id);
    if ($tx === null) {
        http_response_code(404);
        err_fragment("no tx #{$id}", 404); return;
    }

    if ($action === 'cancel') {
        if ($tx['status'] !== 'pending') {
            err_fragment("cannot cancel: status={$tx['status']}"); return;
        }
        State\delete_one($pdo, $id);
        echo ''; return;
    }
    if ($action === 'fire-now') {
        if ($tx['status'] !== 'pending') {
            err_fragment("cannot fire-now: status={$tx['status']}"); return;
        }
        State\update_fire_at($pdo, $id, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        render_partial('tx_row', ['tx' => State\get($pdo, $id), 'now' => new DateTimeImmutable('now', new DateTimeZone('UTC'))]);
        return;
    }
    if ($action === 'retry') {
        if (!in_array($tx['status'], ['failed', 'missed'], true)) {
            err_fragment("cannot retry: status={$tx['status']}"); return;
        }
        State\reset_to_pending($pdo, $id, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        render_partial('tx_row', ['tx' => State\get($pdo, $id), 'now' => new DateTimeImmutable('now', new DateTimeZone('UTC'))]);
        return;
    }
    if ($action === 'reschedule') {
        if ($tx['status'] !== 'pending') {
            err_fragment("cannot reschedule: status={$tx['status']}"); return;
        }
        $mode = $_POST['mode'] ?? '';
        try {
            if ($mode === 'exact') {
                $t = Scheduler\parse_when($_POST['fire_at'] ?? '');
                if ($t <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                    err_fragment('fire_at must be in the future'); return;
                }
            } elseif ($mode === 'random') {
                $start = Scheduler\parse_when($_POST['window_start'] ?? 'now');
                $end   = Scheduler\parse_when($_POST['window_end']   ?? '+7d');
                $gap   = Scheduler\parse_duration($_POST['min_gap']  ?? '30m');
                $tx_when = (new DateTimeImmutable($tx['fire_at']))->format('c');
                $existing = array_filter(
                    State\list_pending_fire_times($pdo),
                    fn($ft) => $ft->format('c') !== $tx_when,
                );
                $t = Scheduler\pick_random_slot($start, $end, $gap, $existing);
            } else {
                err_fragment("unknown mode: {$mode}"); return;
            }
        } catch (Throwable $e) {
            err_fragment($e->getMessage()); return;
        }
        State\update_fire_at($pdo, $id, $t);
        render_partial('tx_row', ['tx' => State\get($pdo, $id), 'now' => new DateTimeImmutable('now', new DateTimeZone('UTC'))]);
        return;
    }
    err_fragment("unknown action: {$action}", 404);
}

function api_status(PDO $pdo): array {
    $txs = State\list_all($pdo);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $pending = array_values(array_filter($txs, fn($t) => $t['status'] === 'pending'));
    $next = null;
    if ($pending) {
        usort($pending, fn($a, $b) => strcmp($a['fire_at'], $b['fire_at']));
        $n = $pending[0];
        $when = new DateTimeImmutable($n['fire_at']);
        $next = [
            'id' => (int) $n['id'],
            'iso' => $when->format('c'),
            'rel_seconds' => $when->getTimestamp() - $now->getTimestamp(),
        ];
    }
    return [
        'tor' => tor_alive(),
        'summary' => [
            'pending' => count($pending),
            'firing'  => count(array_filter($txs, fn($t) => $t['status'] === 'firing')),
            'done'    => count(array_filter($txs, fn($t) => $t['status'] === 'done')),
            'failed'  => count(array_filter($txs, fn($t) => $t['status'] === 'failed')),
            'missed'  => count(array_filter($txs, fn($t) => $t['status'] === 'missed')),
            'next_fire' => $next,
        ],
    ];
}

function tor_alive(): bool {
    $sock = @stream_socket_client('tcp://127.0.0.1:9050', $errno, $errstr, 0.5);
    if ($sock) { fclose($sock); return true; }
    return false;
}

function settings_form(array $cfg): void {
    render('settings', ['settings' => load_settings($cfg['settings_path'] ?? '')]);
}

function settings_save(array $cfg): void {
    $path = $cfg['settings_path'] ?? '';
    if (!$path) { http_response_code(500); echo 'no settings_path configured'; return; }
    $existing = load_settings($path);
    $existing['telegram_enabled']   = !empty($_POST['telegram_enabled']);
    $existing['telegram_bot_token'] = trim($_POST['telegram_bot_token'] ?? '');
    $existing['telegram_chat_id']   = trim($_POST['telegram_chat_id']   ?? '');
    $existing['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
    save_settings($path, $existing);
    hx_redirect('/settings');
}

// ---- view helpers ---------------------------------------------------------

function render(string $name, array $vars): void {
    $vars['user'] = current_user();
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../views/_base_open.php';
    require __DIR__ . '/../views/' . $name . '.php';
    require __DIR__ . '/../views/_base_close.php';
}

function render_partial(string $name, array $vars): void {
    extract($vars, EXTR_SKIP);
    require __DIR__ . '/../views/partials/' . $name . '.php';
}

function err_fragment(string $msg, int $code = 400): void {
    http_response_code($code);
    render_partial('error', ['message' => $msg]);
}

function hx_redirect(string $path): void {
    header('HX-Redirect: ' . url_for($path));
    header('Location: ' . url_for($path));
    http_response_code(200);
}
