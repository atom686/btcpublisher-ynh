<?php
/**
 * SQLite persistence via PDO. Mirrors btcpub/state.py.
 *
 * Schema:
 *   txs(
 *     id INTEGER PRIMARY KEY,
 *     hex TEXT NOT NULL,
 *     fire_at TEXT NOT NULL,        -- ISO 8601 UTC
 *     status TEXT NOT NULL,         -- pending|firing|done|failed|missed
 *     txid TEXT, attempts INTEGER, last_error TEXT,
 *     fired_at TEXT, route TEXT, created_at TEXT
 *   )
 */

namespace Btcpub\State;

const SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS txs (
    id INTEGER PRIMARY KEY,
    hex TEXT NOT NULL,
    fire_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    txid TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    last_error TEXT,
    fired_at TEXT,
    route TEXT,
    created_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_status_fire_at ON txs(status, fire_at);
SQL;

function open_db(string $path): \PDO {
    if (!is_dir(dirname($path))) {
        @mkdir(dirname($path), 0750, true);
    }
    $pdo = new \PDO('sqlite:' . $path);
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec(SCHEMA);
    return $pdo;
}

function now_iso(): string {
    return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.up');
}

function utc_iso(\DateTimeInterface $dt): string {
    return $dt instanceof \DateTimeImmutable
        ? $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.up')
        : (\DateTimeImmutable::createFromInterface($dt))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.up');
}

/** -------- inserts ---------------------------------------------------- */

function insert_one(\PDO $pdo, string $hex, \DateTimeInterface $fire_at): int {
    $stmt = $pdo->prepare('INSERT INTO txs(hex, fire_at, created_at) VALUES (?, ?, ?)');
    $stmt->execute([$hex, utc_iso($fire_at), now_iso()]);
    return (int) $pdo->lastInsertId();
}

function insert_schedule(\PDO $pdo, array $hexes_with_times): int {
    $now = now_iso();
    $stmt = $pdo->prepare('INSERT INTO txs(hex, fire_at, created_at) VALUES (?, ?, ?)');
    $n = 0;
    foreach ($hexes_with_times as [$hex, $t]) {
        $stmt->execute([$hex, utc_iso($t), $now]);
        $n++;
    }
    return $n;
}

/** -------- queries ---------------------------------------------------- */

function list_all(\PDO $pdo): array {
    return $pdo->query('SELECT * FROM txs ORDER BY fire_at ASC')->fetchAll();
}

function get(\PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM txs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function list_pending_fire_times(\PDO $pdo): array {
    $rows = $pdo->query("SELECT fire_at FROM txs WHERE status = 'pending'")->fetchAll();
    return array_map(
        fn($r) => new \DateTimeImmutable($r['fire_at']),
        $rows,
    );
}

function next_pending(\PDO $pdo): ?array {
    $row = $pdo->query("SELECT * FROM txs WHERE status = 'pending' ORDER BY fire_at ASC LIMIT 1")->fetch();
    return $row === false ? null : $row;
}

function find_missed(\PDO $pdo, \DateTimeInterface $cutoff): array {
    $stmt = $pdo->prepare("SELECT * FROM txs WHERE status = 'pending' AND fire_at < ? ORDER BY fire_at ASC");
    $stmt->execute([utc_iso($cutoff)]);
    return $stmt->fetchAll();
}

/** -------- status transitions ----------------------------------------- */

function mark_firing(\PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE txs SET status='firing' WHERE id=?")->execute([$id]);
}

function mark_done(\PDO $pdo, int $id, string $txid, string $route): void {
    $pdo->prepare("UPDATE txs SET status='done', txid=?, fired_at=?, route=?, last_error=NULL WHERE id=?")
        ->execute([$txid, now_iso(), $route, $id]);
}

function mark_failed(\PDO $pdo, int $id, string $error, int $attempts): void {
    $pdo->prepare("UPDATE txs SET status='failed', last_error=?, attempts=?, fired_at=? WHERE id=?")
        ->execute([$error, $attempts, now_iso(), $id]);
}

function mark_missed(\PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE txs SET status='missed' WHERE id=?")->execute([$id]);
}

/** -------- mutations from UI ----------------------------------------- */

function delete_one(\PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare('DELETE FROM txs WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->rowCount() > 0;
}

function update_fire_at(\PDO $pdo, int $id, \DateTimeInterface $fire_at): bool {
    $stmt = $pdo->prepare("UPDATE txs SET fire_at = ? WHERE id = ? AND status = 'pending'");
    $stmt->execute([utc_iso($fire_at), $id]);
    return $stmt->rowCount() > 0;
}

function reset_to_pending(\PDO $pdo, int $id, \DateTimeInterface $fire_at): bool {
    $stmt = $pdo->prepare(
        "UPDATE txs SET status='pending', fire_at=?, txid=NULL, fired_at=NULL,
                last_error=NULL, route=NULL
         WHERE id=? AND status IN ('failed','missed')"
    );
    $stmt->execute([utc_iso($fire_at), $id]);
    return $stmt->rowCount() > 0;
}

function clear_all(\PDO $pdo): int {
    return $pdo->exec('DELETE FROM txs');
}

function has_active_schedule(\PDO $pdo): bool {
    return (bool) $pdo->query("SELECT 1 FROM txs WHERE status IN ('pending','firing') LIMIT 1")->fetch();
}
