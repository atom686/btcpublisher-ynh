<?php
/**
 * Tx decoder: parse a raw Bitcoin transaction (legacy or segwit) and
 * compute its txid. No network calls. Includes bech32/bech32m address
 * encoding so we can label P2WPKH/P2WSH/P2TR outputs.
 *
 * Port of btcpub/decode.py + the txid + helpers from btcpub/p2p.py.
 */

namespace Btcpub\Decode;

/** -------- varint helpers ----------------------------------------------- */

function varint_encode(int $n): string {
    if ($n < 0xfd) return chr($n);
    if ($n <= 0xffff) return "\xfd" . pack('v', $n);
    if ($n <= 0xffffffff) return "\xfe" . pack('V', $n);
    return "\xff" . pack('P', $n);
}

/**
 * Returns [value, new_pos].
 */
function varint_decode(string $data, int $pos): array {
    $first = ord($data[$pos]);
    if ($first < 0xfd) return [$first, $pos + 1];
    if ($first == 0xfd) return [unpack('v', substr($data, $pos + 1, 2))[1], $pos + 3];
    if ($first == 0xfe) return [unpack('V', substr($data, $pos + 1, 4))[1], $pos + 5];
    // 64-bit; PHP 'P' = little-endian 64
    return [unpack('P', substr($data, $pos + 1, 8))[1], $pos + 9];
}

/** -------- txid computation -------------------------------------------- */

function is_segwit_serialization(string $raw): bool {
    return strlen($raw) >= 6 && $raw[4] === "\x00" && $raw[5] === "\x01";
}

/**
 * Re-serialize a segwit tx into legacy form (no marker/flag/witness).
 * Required so txid hashing matches consensus.
 */
function strip_witness(string $raw): string {
    $pos = 6;  // skip 4-byte version + marker + flag
    $inputs_start = $pos;

    [$n_in, $pos] = varint_decode($raw, $pos);
    for ($i = 0; $i < $n_in; $i++) {
        $pos += 32 + 4;                           // prevout (txid+vout)
        [$sl, $pos] = varint_decode($raw, $pos);  // scriptSig len
        $pos += $sl;
        $pos += 4;                                // sequence
    }
    [$n_out, $pos] = varint_decode($raw, $pos);
    for ($i = 0; $i < $n_out; $i++) {
        $pos += 8;                                // value
        [$sl, $pos] = varint_decode($raw, $pos);  // scriptPubKey len
        $pos += $sl;
    }
    $body = substr($raw, $inputs_start, $pos - $inputs_start);
    $locktime = substr($raw, -4);
    return substr($raw, 0, 4) . $body . $locktime;
}

/** Returns 32 raw bytes (internal byte order — reverse for display). */
function compute_txid(string $raw): string {
    $legacy = is_segwit_serialization($raw) ? strip_witness($raw) : $raw;
    return hash('sha256', hash('sha256', $legacy, true), true);
}

function txid_hex(string $raw): string {
    return bin2hex(strrev(compute_txid($raw)));
}

/** -------- output classification --------------------------------------- */

function classify_output(string $script): array {
    $len = strlen($script);
    if ($len === 22 && $script[0] === "\x00" && $script[1] === "\x14") {
        return ['P2WPKH', bech32_encode('bc', 0, substr($script, 2))];
    }
    if ($len === 34 && $script[0] === "\x00" && $script[1] === "\x20") {
        return ['P2WSH', bech32_encode('bc', 0, substr($script, 2))];
    }
    if ($len === 34 && $script[0] === "\x51" && $script[1] === "\x20") {
        return ['P2TR', bech32_encode('bc', 1, substr($script, 2))];
    }
    if ($len === 25 && $script[0] === "\x76" && $script[1] === "\xa9"
        && $script[2] === "\x14" && $script[23] === "\x88" && $script[24] === "\xac") {
        return ['P2PKH', null];
    }
    if ($len === 23 && $script[0] === "\xa9" && $script[1] === "\x14" && $script[22] === "\x87") {
        return ['P2SH', null];
    }
    if ($len > 0 && $script[0] === "\x6a") {
        return ['OP_RETURN', bin2hex($script)];
    }
    return ['unknown', null];
}

/** -------- decode_tx --------------------------------------------------- */

/**
 * Returns an assoc array:
 *   txid, version, is_segwit, size, locktime, witness_present,
 *   inputs[]  => ['prevout_txid','prevout_vout','scriptsig_hex','sequence']
 *   outputs[] => ['value_sat','script_hex','kind','address_or_data']
 */
function decode_tx(string $raw): array {
    if (strlen($raw) < 10) {
        throw new \InvalidArgumentException('tx too short to be valid');
    }
    $version = unpack('V', substr($raw, 0, 4))[1];
    $is_segwit = is_segwit_serialization($raw);
    $pos = $is_segwit ? 6 : 4;

    [$n_in, $pos] = varint_decode($raw, $pos);
    $inputs = [];
    for ($i = 0; $i < $n_in; $i++) {
        $prev_txid = bin2hex(strrev(substr($raw, $pos, 32)));
        $prev_vout = unpack('V', substr($raw, $pos + 32, 4))[1];
        $pos += 36;
        [$sl, $pos] = varint_decode($raw, $pos);
        $scriptsig = substr($raw, $pos, $sl);
        $pos += $sl;
        $sequence = unpack('V', substr($raw, $pos, 4))[1];
        $pos += 4;
        $inputs[] = [
            'prevout_txid' => $prev_txid,
            'prevout_vout' => $prev_vout,
            'scriptsig_hex' => bin2hex($scriptsig),
            'sequence' => $sequence,
        ];
    }

    [$n_out, $pos] = varint_decode($raw, $pos);
    $outputs = [];
    for ($i = 0; $i < $n_out; $i++) {
        // 'P' = little-endian uint64
        $value = unpack('P', substr($raw, $pos, 8))[1];
        $pos += 8;
        [$sl, $pos] = varint_decode($raw, $pos);
        $script = substr($raw, $pos, $sl);
        $pos += $sl;
        [$kind, $addr] = classify_output($script);
        $outputs[] = [
            'value_sat' => $value,
            'script_hex' => bin2hex($script),
            'kind' => $kind,
            'address_or_data' => $addr,
        ];
    }

    $witness_present = false;
    if ($is_segwit) {
        for ($i = 0; $i < $n_in; $i++) {
            [$n_w, $pos] = varint_decode($raw, $pos);
            if ($n_w > 0) $witness_present = true;
            for ($j = 0; $j < $n_w; $j++) {
                [$wl, $pos] = varint_decode($raw, $pos);
                $pos += $wl;
            }
        }
    }

    $locktime = unpack('V', substr($raw, $pos, 4))[1];

    return [
        'txid' => txid_hex($raw),
        'version' => $version,
        'is_segwit' => $is_segwit,
        'size' => strlen($raw),
        'locktime' => $locktime,
        'witness_present' => $witness_present,
        'inputs' => $inputs,
        'outputs' => $outputs,
    ];
}

function total_out_sat(array $decoded): int {
    $total = 0;
    foreach ($decoded['outputs'] as $o) $total += $o['value_sat'];
    return $total;
}

/** -------- bech32 / bech32m (BIP-173 + BIP-350) ----------------------- */

const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

function bech32_polymod(array $values): int {
    $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    $chk = 1;
    foreach ($values as $v) {
        $b = $chk >> 25;
        $chk = (($chk & 0x1ffffff) << 5) ^ $v;
        for ($i = 0; $i < 5; $i++) {
            if (($b >> $i) & 1) $chk ^= $gen[$i];
        }
    }
    return $chk;
}

function bech32_hrp_expand(string $hrp): array {
    $hi = [];
    $lo = [];
    for ($i = 0, $L = strlen($hrp); $i < $L; $i++) {
        $c = ord($hrp[$i]);
        $hi[] = $c >> 5;
        $lo[] = $c & 31;
    }
    return array_merge($hi, [0], $lo);
}

function bech32_create_checksum(string $hrp, array $data, int $spec_const): array {
    $values = array_merge(bech32_hrp_expand($hrp), $data);
    $polymod = bech32_polymod(array_merge($values, [0, 0, 0, 0, 0, 0])) ^ $spec_const;
    $out = [];
    for ($i = 0; $i < 6; $i++) {
        $out[] = ($polymod >> (5 * (5 - $i))) & 31;
    }
    return $out;
}

/** Convert from $frombits → $tobits, optionally padding. */
function convertbits(string $data, int $frombits, int $tobits, bool $pad): array {
    $acc = 0;
    $bits = 0;
    $ret = [];
    $maxv = (1 << $tobits) - 1;
    for ($i = 0, $L = strlen($data); $i < $L; $i++) {
        $value = ord($data[$i]);
        $acc = ($acc << $frombits) | $value;
        $bits += $frombits;
        while ($bits >= $tobits) {
            $bits -= $tobits;
            $ret[] = ($acc >> $bits) & $maxv;
        }
    }
    if ($pad && $bits) {
        $ret[] = ($acc << ($tobits - $bits)) & $maxv;
    }
    return $ret;
}

/** Encode a segwit address. witver=0 → bech32, witver>=1 → bech32m. */
function bech32_encode(string $hrp, int $witver, string $witprog): string {
    $spec_const = $witver === 0 ? 1 : 0x2bc830a3;
    $data = array_merge([$witver], convertbits($witprog, 8, 5, true));
    $combined = array_merge($data, bech32_create_checksum($hrp, $data, $spec_const));
    $out = $hrp . '1';
    foreach ($combined as $d) {
        $out .= BECH32_CHARSET[$d];
    }
    return $out;
}
