<?php
/**
 * Minimal Bitcoin P2P client.
 *
 * Connects to a peer over SOCKS5 (Tor), exchanges version/verack, advertises
 * our tx via `inv`, and sends `tx` when the peer asks via `getdata`.
 *
 * Port of btcpub/p2p.py.
 */

namespace Btcpub\P2P;

require_once __DIR__ . '/decode.php';

use function Btcpub\Decode\{compute_txid, varint_encode, varint_decode};

const MAGIC               = "\xf9\xbe\xb4\xd9";   // mainnet
const NODE_NETWORK        = 1;
const NODE_WITNESS        = 1 << 3;
const MSG_TX              = 1;
const MSG_WITNESS_TX      = 1 | (1 << 30);
const PROTOCOL_VERSION    = 70016;

class P2PError extends \RuntimeException {}
class P2PConnectError extends P2PError {}   // transient: connect/timeout
class P2PRejected extends P2PError {}       // permanent: peer said no

/** -------- message framing --------------------------------------------- */

function checksum(string $payload): string {
    return substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
}

function frame(string $command, string $payload): string {
    $cmd = str_pad($command, 12, "\x00");
    return MAGIC . $cmd . pack('V', strlen($payload)) . checksum($payload) . $payload;
}

/**
 * Returns [command, length, checksum_bytes].
 */
function parse_header(string $hdr): array {
    if (substr($hdr, 0, 4) !== MAGIC) {
        throw new P2PError('bad magic: ' . bin2hex(substr($hdr, 0, 4)));
    }
    $cmd = rtrim(substr($hdr, 4, 12), "\x00");
    $len = unpack('V', substr($hdr, 16, 4))[1];
    $csum = substr($hdr, 20, 4);
    return [$cmd, $len, $csum];
}

/** -------- payload builders -------------------------------------------- */

function net_addr(int $services = 0, string $ip = "", int $port = 0): string {
    $ip = str_pad($ip, 16, "\x00");
    return pack('P', $services) . substr($ip, 0, 16) . pack('n', $port);
}

function build_version(string $user_agent = '/btcpublisher:0.1/'): string {
    return pack('V', PROTOCOL_VERSION)                 // protocol version
         . pack('P', NODE_NETWORK | NODE_WITNESS)      // services
         . pack('P', time())                           // timestamp
         . net_addr() . net_addr()                     // addr_recv + addr_from
         . random_bytes(8)                             // nonce
         . varint_encode(strlen($user_agent)) . $user_agent
         . pack('V', 0)                                // start_height
         . "\x00";                                     // relay = false (no flood)
}

/** items: list of [type, 32-byte hash]. Used for both inv and getdata. */
function build_inv(array $items): string {
    $out = varint_encode(count($items));
    foreach ($items as [$type, $hash]) {
        if (strlen($hash) !== 32) {
            throw new \InvalidArgumentException('hash must be 32 bytes');
        }
        $out .= pack('V', $type) . $hash;
    }
    return $out;
}

function parse_inv(string $payload): array {
    [$n, $pos] = varint_decode($payload, 0);
    $items = [];
    for ($i = 0; $i < $n; $i++) {
        $type = unpack('V', substr($payload, $pos, 4))[1];
        $hash = substr($payload, $pos + 4, 32);
        $items[] = [$type, $hash];
        $pos += 36;
    }
    return $items;
}

/** -------- SOCKS5 connect --------------------------------------------- */

/**
 * Open a TCP stream to $host:$port via SOCKS5 at $socks_host:$socks_port.
 * Supports .onion hostnames (no local DNS resolution; SOCKS5 does it).
 *
 * Returns the open socket resource.
 */
function socks5_connect(string $host, int $port, string $socks_host, int $socks_port, int $timeout): mixed {
    $errno = 0;
    $errstr = '';
    $sock = @stream_socket_client("tcp://{$socks_host}:{$socks_port}", $errno, $errstr, $timeout);
    if (!$sock) {
        throw new P2PConnectError("SOCKS5 connect failed: {$errstr} ({$errno})");
    }
    stream_set_timeout($sock, $timeout);

    // Greeting: VER=5, NMETHODS=1, METHOD=0 (no auth)
    fwrite($sock, "\x05\x01\x00");
    $resp = fread($sock, 2);
    if (strlen($resp) !== 2 || $resp[0] !== "\x05" || $resp[1] !== "\x00") {
        fclose($sock);
        throw new P2PConnectError('SOCKS5 greeting failed: ' . bin2hex($resp));
    }

    // CONNECT request: VER=5, CMD=1, RSV=0, ATYP=3 (domain), len, host, port
    $req = "\x05\x01\x00\x03" . chr(strlen($host)) . $host . pack('n', $port);
    fwrite($sock, $req);

    // Reply: VER, REP, RSV, ATYP, BND.ADDR (variable), BND.PORT
    $reply_hdr = fread($sock, 4);
    if (strlen($reply_hdr) !== 4 || $reply_hdr[0] !== "\x05") {
        fclose($sock);
        throw new P2PConnectError('SOCKS5 reply header bad: ' . bin2hex($reply_hdr));
    }
    if ($reply_hdr[1] !== "\x00") {
        fclose($sock);
        $rep = ord($reply_hdr[1]);
        throw new P2PConnectError("SOCKS5 connect refused (rep=0x" . dechex($rep) . ")");
    }
    // Consume BND.ADDR + BND.PORT based on ATYP
    $atyp = ord($reply_hdr[3]);
    if ($atyp === 1) {        // IPv4
        fread($sock, 4 + 2);
    } elseif ($atyp === 4) {  // IPv6
        fread($sock, 16 + 2);
    } elseif ($atyp === 3) {  // domain
        $len = ord(fread($sock, 1));
        fread($sock, $len + 2);
    }

    return $sock;
}

/** -------- P2P client ------------------------------------------------- */

class P2PClient {
    private $sock = null;

    public function __construct(
        public string $host,
        public int $port,
        public string $socks_host = '127.0.0.1',
        public int $socks_port = 9050,
        public int $connect_timeout = 90,
        public int $read_timeout = 30,
    ) {}

    public function connect(): void {
        $this->sock = socks5_connect(
            $this->host, $this->port,
            $this->socks_host, $this->socks_port,
            $this->connect_timeout,
        );
        stream_set_timeout($this->sock, $this->read_timeout);
    }

    public function close(): void {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    private function send_msg(string $command, string $payload): void {
        $data = frame($command, $payload);
        $written = fwrite($this->sock, $data);
        if ($written !== strlen($data)) {
            throw new P2PConnectError("short send: wrote {$written} of " . strlen($data));
        }
    }

    private function recvall(int $n): string {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->sock, $n - strlen($buf));
            $meta = stream_get_meta_data($this->sock);
            if ($meta['timed_out']) {
                throw new P2PConnectError("read timeout after " . strlen($buf) . "/{$n} bytes");
            }
            if ($chunk === false || $chunk === '') {
                throw new P2PConnectError("peer disconnected after " . strlen($buf) . "/{$n} bytes");
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function recv_msg(): array {
        $hdr = $this->recvall(24);
        [$cmd, $len, $csum] = parse_header($hdr);
        $payload = $len > 0 ? $this->recvall($len) : '';
        if (checksum($payload) !== $csum) {
            throw new P2PError("bad checksum on {$cmd}");
        }
        return [$cmd, $payload];
    }

    public function handshake(): void {
        $this->send_msg('version', build_version());
        $got_version = false;
        $got_verack = false;
        $deadline = microtime(true) + $this->read_timeout;
        while (!($got_version && $got_verack)) {
            if (microtime(true) > $deadline) {
                throw new P2PConnectError('handshake timed out');
            }
            [$cmd] = $this->recv_msg();
            if ($cmd === 'version') {
                $got_version = true;
                $this->send_msg('verack', '');
            } elseif ($cmd === 'verack') {
                $got_verack = true;
            }
            // else: ignore wtxidrelay, sendaddrv2, sendcmpct, etc.
        }
    }

    /**
     * Returns ['txid' => hex, 'accepted' => bool].
     * accepted=true  → peer asked for the tx (fresh acceptance).
     * accepted=false → peer didn't ask (likely already-known).
     */
    public function broadcast_tx(string $raw, int $inv_response_s = 15, int $post_tx_idle_s = 2): array {
        $txid_bytes = compute_txid($raw);
        $txid_display = bin2hex(strrev($txid_bytes));

        $this->send_msg('inv', build_inv([[MSG_TX, $txid_bytes]]));

        $deadline = microtime(true) + $inv_response_s;
        $asked = false;
        while (microtime(true) < $deadline) {
            $remaining = max(1, (int) ceil($deadline - microtime(true)));
            stream_set_timeout($this->sock, $remaining);
            try {
                [$cmd, $payload] = $this->recv_msg();
            } catch (P2PConnectError $e) {
                if (str_contains($e->getMessage(), 'timeout')) break;
                throw $e;
            }
            if ($cmd === 'getdata') {
                foreach (parse_inv($payload) as [$type, $hash]) {
                    if ($hash === $txid_bytes && ($type === MSG_TX || $type === MSG_WITNESS_TX)) {
                        $asked = true;
                        break 2;
                    }
                }
            } elseif ($cmd === 'ping') {
                $this->send_msg('pong', $payload);
            } elseif ($cmd === 'reject') {
                throw new P2PRejected('peer rejected (pre-tx): ' . bin2hex($payload));
            }
        }
        stream_set_timeout($this->sock, $this->read_timeout);

        if (!$asked) {
            return ['txid' => $txid_display, 'accepted' => false];
        }

        $this->send_msg('tx', $raw);

        $end = microtime(true) + $post_tx_idle_s;
        while (microtime(true) < $end) {
            $remaining = max(1, (int) ceil($end - microtime(true)));
            stream_set_timeout($this->sock, $remaining);
            try {
                [$cmd, $payload] = $this->recv_msg();
            } catch (P2PConnectError $e) {
                if (str_contains($e->getMessage(), 'timeout')) break;
                throw $e;
            }
            if ($cmd === 'reject') {
                throw new P2PRejected('peer rejected: ' . bin2hex($payload));
            }
            if ($cmd === 'ping') $this->send_msg('pong', $payload);
        }

        return ['txid' => $txid_display, 'accepted' => true];
    }
}

/** Convenience wrapper. */
function broadcast_via_p2p(
    string $raw_hex,
    string $host,
    int $port,
    string $socks_host = '127.0.0.1',
    int $socks_port = 9050,
    int $connect_timeout = 90,
    int $read_timeout = 30,
): array {
    $raw = hex2bin($raw_hex);
    if ($raw === false) {
        throw new \InvalidArgumentException('invalid tx hex');
    }
    $client = new P2PClient($host, $port, $socks_host, $socks_port, $connect_timeout, $read_timeout);
    try {
        $client->connect();
        $client->handshake();
        return $client->broadcast_tx($raw);
    } finally {
        $client->close();
    }
}
