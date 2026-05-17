<?php
/**
 * Random fire-time generation. Port of btcpub/scheduler.py.
 *
 * Uniform distribution across [start, end] with a minimum gap enforced
 * between any two consecutive fires. Optional seed for reproducibility.
 */

namespace Btcpub\Scheduler;

function generate_fire_times(
    int $n,
    \DateTimeImmutable $start,
    \DateTimeImmutable $end,
    int $min_gap_seconds,
    ?int $seed = null,
): array {
    if ($n <= 0) return [];
    if ($start >= $end) {
        throw new \InvalidArgumentException("start ({$start->format('c')}) must be before end ({$end->format('c')})");
    }
    if ($min_gap_seconds < 0) {
        throw new \InvalidArgumentException('min_gap must be non-negative');
    }
    $window = $end->getTimestamp() - $start->getTimestamp();
    $reserved = ($n - 1) * $min_gap_seconds;
    if ($reserved >= $window) {
        throw new \InvalidArgumentException(
            "cannot fit {$n} fires with min_gap={$min_gap_seconds}s in window={$window}s"
        );
    }
    $free = $window - $reserved;

    if ($seed !== null) mt_srand($seed);
    $offsets = [];
    for ($i = 0; $i < $n; $i++) {
        // mt_rand max is 2**31-1; for sub-second precision use this approach.
        $offsets[] = mt_rand(0, $free * 1000) / 1000.0;
    }
    sort($offsets);

    $times = [];
    foreach ($offsets as $i => $offset) {
        $delta_s = (int) round($offset + $i * $min_gap_seconds);
        $times[] = $start->add(new \DateInterval("PT{$delta_s}S"));
    }
    return $times;
}

/**
 * Draw a single uniform fire-time in [start, end] that is at least min_gap
 * away from every existing fire-time. Throws if it can't fit.
 */
function pick_random_slot(
    \DateTimeImmutable $start,
    \DateTimeImmutable $end,
    int $min_gap_seconds,
    array $existing,
    int $max_attempts = 100,
    ?int $seed = null,
): \DateTimeImmutable {
    if ($start >= $end) {
        throw new \InvalidArgumentException('start must be before end');
    }
    if ($seed !== null) mt_srand($seed);
    $window = $end->getTimestamp() - $start->getTimestamp();
    for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
        $offset_s = mt_rand(0, $window * 1000) / 1000.0;
        $candidate = $start->add(new \DateInterval('PT' . (int) round($offset_s) . 'S'));
        $candidate_ts = $candidate->getTimestamp();
        $fits = true;
        foreach ($existing as $t) {
            if (abs($candidate_ts - $t->getTimestamp()) < $min_gap_seconds) {
                $fits = false;
                break;
            }
        }
        if ($fits) return $candidate;
    }
    throw new \RuntimeException(
        "could not place a fire-time in window with min_gap={$min_gap_seconds}s after {$max_attempts} attempts; "
        . count($existing) . ' existing pending. Widen the window or lower min_gap.'
    );
}

/** -------- duration / datetime parsing -------------------------------- */

/** Parses '30s', '5m', '2h', '7d' → seconds. */
function parse_duration(string $s): int {
    $s = strtolower(trim($s));
    if ($s === '') throw new \InvalidArgumentException('empty duration');
    $unit = $s[strlen($s) - 1];
    $value = (float) substr($s, 0, -1);
    return match ($unit) {
        's' => (int) round($value),
        'm' => (int) round($value * 60),
        'h' => (int) round($value * 3600),
        'd' => (int) round($value * 86400),
        default => throw new \InvalidArgumentException("unknown duration unit '{$unit}'; use s/m/h/d"),
    };
}

/** Parses 'now', '+1h' / '+7d', or ISO 8601. Returns UTC. */
function parse_when(string $s): \DateTimeImmutable {
    $s = trim($s);
    $utc = new \DateTimeZone('UTC');
    if ($s === 'now') {
        return new \DateTimeImmutable('now', $utc);
    }
    if (str_starts_with($s, '+')) {
        $seconds = parse_duration(substr($s, 1));
        return (new \DateTimeImmutable('now', $utc))->add(new \DateInterval("PT{$seconds}S"));
    }
    $dt = new \DateTimeImmutable($s);
    return $dt->setTimezone($utc);
}
