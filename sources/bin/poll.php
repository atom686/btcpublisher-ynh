<?php
/**
 * btcpublisher cron poll — fires any pending tx whose fire_at <= now.
 * Replaces the long-lived Python daemon. Wired into cron at install time.
 *
 * Behavior:
 *  - On each run: find pending tx with fire_at <= now (earliest first).
 *  - For each, attempt broadcast. Persist outcome. Notify on success/failure.
 *  - Any pending tx whose fire_at is older than grace (2h default) → 'missed'.
 *  - Single instance only (flock on a state-dir lockfile) so two crons
 *    can't race.
 */

require __DIR__ . '/../lib/bootstrap.php';

use function Btcpub\load_config;
use function Btcpub\load_settings;
use function Btcpub\State\{open_db, next_pending, find_missed, mark_firing,
                          mark_done, mark_failed, mark_missed, list_all};
use function Btcpub\Broadcast\try_broadcast;
use Btcpub\Broadcast\BroadcastError;
use function Btcpub\Notify\notify;

const GRACE_SECONDS = 7200;   // 2h

$cfg = load_config();
$db_path = $cfg['db_path'] ?? throw new RuntimeException('config.db_path missing');
$settings_path = $cfg['settings_path'] ?? '';

// Single-instance lock
$lock_dir = $cfg['lock_dir'] ?? sys_get_temp_dir();
@mkdir($lock_dir, 0755, true);
$lock_path = $lock_dir . '/btcpublisher.lock';
$lock_fp = fopen($lock_path, 'c');
if (!$lock_fp || !flock($lock_fp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "[poll] another instance is running; exiting\n");
    exit(0);
}

try {
    $pdo = open_db($db_path);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $cutoff = $now->sub(new DateInterval('PT' . GRACE_SECONDS . 'S'));

    // Step 1: mark genuinely-missed txs (older than grace)
    foreach (find_missed($pdo, $cutoff) as $tx) {
        $id = (int) $tx['id'];
        fwrite(STDERR, "[poll] marking #{$id} missed (was due {$tx['fire_at']})\n");
        mark_missed($pdo, $id);
        if ($settings_path) {
            notify("btcpub: tx #{$id} MISSED (was due {$tx['fire_at']})", $settings_path);
        }
    }

    // Step 2: fire all due pendings (loop in case multiple are ripe)
    while (true) {
        $tx = next_pending($pdo);
        if ($tx === null) break;
        $fire_at = new DateTimeImmutable($tx['fire_at']);
        if ($fire_at > $now) break;
        fire_one($pdo, $tx, $cfg, $settings_path);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
} finally {
    flock($lock_fp, LOCK_UN);
    fclose($lock_fp);
}

function fire_one(PDO $pdo, array $tx, array $cfg, string $settings_path): void {
    $id = (int) $tx['id'];
    fwrite(STDERR, "[poll] firing #{$id}\n");
    mark_firing($pdo, $id);

    try {
        $r = try_broadcast($tx['hex'], $cfg);
        mark_done($pdo, $id, $r['txid'], $r['route']);
        fwrite(STDERR, "[poll] #{$id} DONE txid={$r['txid']} route={$r['route']}\n");
        if ($settings_path) {
            notify(
                "btcpub: tx #{$id} broadcast\ntxid: {$r['txid']}\nroute: {$r['route']}",
                $settings_path,
            );
        }
    } catch (BroadcastError $e) {
        $attempts = (int) $tx['attempts'] + ($cfg['primary_attempts'] ?? 4);
        mark_failed($pdo, $id, $e->getMessage(), $attempts);
        fwrite(STDERR, "[poll] #{$id} FAILED kind={$e->kind}: " . $e->getMessage() . "\n");
        if ($settings_path) {
            notify("btcpub: tx #{$id} FAILED ({$e->kind})\n" . $e->getMessage(), $settings_path);
        }
    } catch (Throwable $e) {
        $attempts = (int) $tx['attempts'] + 1;
        mark_failed($pdo, $id, 'crash: ' . $e->getMessage(), $attempts);
        fwrite(STDERR, "[poll] #{$id} CRASHED: " . $e->getMessage() . "\n");
    }
}
