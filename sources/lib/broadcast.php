<?php
/**
 * Broadcast orchestrator: P2P-over-Tor primary, public mempool HTTP fallback.
 * Port of btcpub/broadcast.py.
 */

namespace Btcpub\Broadcast;

require_once __DIR__ . '/p2p.php';

use Btcpub\P2P\{P2PClient, P2PError, P2PConnectError, P2PRejected};
use function Btcpub\P2P\broadcast_via_p2p;

const ERROR_ALREADY_KNOWN = 'already_known';
const ERROR_PERMANENT     = 'permanent';
const ERROR_TRANSIENT     = 'transient';

const PUBLIC_ENDPOINTS = [
    'https://mempool.space/api/tx',
    'https://blockstream.info/api/tx',
];

class BroadcastError extends \RuntimeException {
    public function __construct(string $msg, public string $kind) {
        parent::__construct($msg);
    }
}

/** -------- error classification (for public-fallback HTTP responses) -- */

const PERMANENT_MARKERS = [
    'bad-txns', 'missingorspent', 'missing-inputs', 'txn-mempool-conflict',
    'non-final', 'non-bip68-final', 'scriptpubkey',
    'mandatory-script-verify-flag-failed', 'tx-size', 'version', 'dust',
    'bad-witness', 'absurdly-high-fee', 'tx decode failed',
];
const ALREADY_KNOWN_MARKERS = [
    'txn-already-known', 'txn-already-in-mempool', 'transaction already in block chain',
];
const TRANSIENT_MARKERS = [
    'min relay fee not met', 'mempool min fee not met', 'too-long-mempool-chain', 'rate limit',
];

function classify_rpc_error(string $message): string {
    $m = strtolower($message);
    foreach (ALREADY_KNOWN_MARKERS as $k) if (str_contains($m, $k)) return ERROR_ALREADY_KNOWN;
    foreach (PERMANENT_MARKERS as $k)     if (str_contains($m, $k)) return ERROR_PERMANENT;
    foreach (TRANSIENT_MARKERS as $k)     if (str_contains($m, $k)) return ERROR_TRANSIENT;
    return ERROR_TRANSIENT;
}

/** -------- P2P route -------------------------------------------------- */

/**
 * Returns ['txid' => hex, 'accepted' => bool].
 * Throws BroadcastError on any failure.
 */
function broadcast_via_node(string $hex_tx, array $node_cfg, array $tor_cfg, int $connect_timeout, int $read_timeout): array {
    try {
        return broadcast_via_p2p(
            $hex_tx,
            $node_cfg['peer_host'],
            (int) ($node_cfg['peer_port'] ?? 8333),
            $tor_cfg['socks_host'] ?? '127.0.0.1',
            (int) ($tor_cfg['socks_port'] ?? 9050),
            $connect_timeout, $read_timeout,
        );
    } catch (P2PRejected $e) {
        throw new BroadcastError('peer rejected: ' . $e->getMessage(), ERROR_PERMANENT);
    } catch (P2PConnectError $e) {
        throw new BroadcastError('p2p connect failed: ' . $e->getMessage(), ERROR_TRANSIENT);
    } catch (P2PError $e) {
        throw new BroadcastError('p2p protocol error: ' . $e->getMessage(), ERROR_TRANSIENT);
    }
}

/** -------- public fallback (HTTPS over clearnet) --------------------- */

function broadcast_via_public(string $hex_tx, int $timeout = 30): array {
    $last_err = null;
    foreach (PUBLIC_ENDPOINTS as $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $hex_tx,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false || $err) {
            $last_err = "{$url}: {$err}";
            continue;
        }
        $body = trim($body);
        if ($code === 200 && strlen($body) === 64 && ctype_xdigit($body)) {
            return ['txid' => $body, 'endpoint' => $url];
        }
        $kind = classify_rpc_error($body);
        if ($kind === ERROR_PERMANENT) {
            throw new BroadcastError("{$url}: {$body}", ERROR_PERMANENT);
        }
        $last_err = "{$url}: HTTP {$code} " . substr($body, 0, 200);
    }
    throw new BroadcastError("all public endpoints failed: {$last_err}", ERROR_TRANSIENT);
}

/** -------- orchestrator --------------------------------------------- */

/**
 * Returns ['txid' => hex, 'route' => 'p2p'|'p2p:already-known'|'public:<url>'].
 *
 * Retries the P2P route with exponential backoff; falls back to public on
 * persistent transient failure (if cfg.public_fallback is true).
 */
function try_broadcast(string $hex_tx, array $cfg): array {
    $primary_attempts   = $cfg['primary_attempts']  ?? 4;
    $primary_backoff_s  = $cfg['primary_backoff_s'] ?? 30;
    $public_fallback    = $cfg['public_fallback']   ?? true;
    $connect_timeout_s  = $cfg['connect_timeout_s'] ?? 90;
    $read_timeout_s     = $cfg['read_timeout_s']    ?? 30;
    $public_timeout_s   = $cfg['public_timeout_s']  ?? 30;

    $backoff = $primary_backoff_s;
    $last_transient = null;

    for ($attempt = 1; $attempt <= $primary_attempts; $attempt++) {
        try {
            $r = broadcast_via_node(
                $hex_tx, $cfg['node'], $cfg['tor'] ?? [],
                $connect_timeout_s, $read_timeout_s,
            );
            return [
                'txid' => $r['txid'],
                'route' => $r['accepted'] ? 'p2p' : 'p2p:already-known',
            ];
        } catch (BroadcastError $e) {
            if ($e->kind === ERROR_PERMANENT) throw $e;
            $last_transient = $e;
            error_log("[btcpub] p2p attempt {$attempt}/{$primary_attempts} failed: " . $e->getMessage());
            if ($attempt < $primary_attempts) {
                sleep($backoff);
                $backoff = min($backoff * 2, 600);
            }
        }
    }

    if (!$public_fallback) throw $last_transient;

    error_log('[btcpub] falling back to public endpoints');
    try {
        $r = broadcast_via_public($hex_tx, $public_timeout_s);
        return ['txid' => $r['txid'], 'route' => 'public:' . $r['endpoint']];
    } catch (BroadcastError $e) {
        if ($e->kind === ERROR_PERMANENT) throw $e;
        throw new BroadcastError(
            "all routes failed; last p2p error: " . ($last_transient?->getMessage() ?? '?')
            . "; public: " . $e->getMessage(),
            ERROR_TRANSIENT,
        );
    }
}
