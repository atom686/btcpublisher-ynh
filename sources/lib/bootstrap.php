<?php
/**
 * Shared bootstrap — loads config, opens DB, surfaces helpers, sets up the
 * upstream-trust identity for the request.
 *
 * Included by both the web entry (public/index.php) and the cron entry
 * (bin/poll.php). Uses bracketed-namespace syntax so the global view
 * helpers can live alongside the Btcpub namespace in one file.
 */

namespace Btcpub {

    require_once __DIR__ . '/decode.php';
    require_once __DIR__ . '/p2p.php';
    require_once __DIR__ . '/state.php';
    require_once __DIR__ . '/scheduler.php';
    require_once __DIR__ . '/broadcast.php';
    require_once __DIR__ . '/notify.php';

    function load_config(): array {
        $candidates = [
            getenv('BTCPUB_CONFIG') ?: null,
            dirname(__DIR__, 2) . '/config.php',
            dirname(__DIR__) . '/config.php',
        ];
        foreach ($candidates as $p) {
            if ($p && is_file($p)) {
                $cfg = require $p;
                if (is_array($cfg)) return $cfg;
            }
        }
        throw new \RuntimeException('btcpublisher: no config.php found (tried ' . implode(', ', array_filter($candidates)) . ')');
    }

    function current_user(): string {
        foreach (['HTTP_AUTH_USER', 'HTTP_X_FORWARDED_USER', 'HTTP_REMOTE_USER', 'REMOTE_USER'] as $k) {
            if (!empty($_SERVER[$k])) return $_SERVER[$k];
        }
        return getenv('BTCPUB_DEV_USER') ?: 'anonymous';
    }

    function load_settings(string $path): array {
        if (!is_file($path)) {
            return ['telegram_enabled' => false, 'telegram_bot_token' => '', 'telegram_chat_id' => ''];
        }
        return json_decode((string) file_get_contents($path), true) ?: [];
    }

    function save_settings(string $path, array $settings): void {
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT));
        rename($tmp, $path);
        @chmod($path, 0600);
    }
}

// View helpers in global namespace so view templates can call them directly.
namespace {

    function h(?string $s): string {
        return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Mounted subpath used as URL prefix. Set once in index.php from
     * $config['subpath'] (rendered by the YunoHost installer). Fallbacks for
     * dev: X-Forwarded-Prefix header, then dirname(SCRIPT_NAME). nginx-alias
     * configs sometimes strip the prefix from SCRIPT_NAME so the config-based
     * source is the only one that's always reliable in production.
     */
    function set_url_prefix(string $prefix): void {
        $GLOBALS['btcpub_url_prefix'] = rtrim($prefix, '/');
    }

    function url_for(string $path): string {
        $base = $GLOBALS['btcpub_url_prefix'] ?? null;
        if ($base === null) {
            $base = rtrim($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? '', '/');
            if ($base === '') {
                $dir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
                if ($dir !== '' && $dir !== '.' && $dir !== '/') {
                    $base = $dir;
                }
            }
        }
        return $base . $path;
    }

    function relative_time(\DateTimeInterface $when, \DateTimeInterface $now): string {
        $s = $when->getTimestamp() - $now->getTimestamp();
        $abs = abs($s);
        $sign = $s < 0 ? '-' : '';
        if ($abs < 60)    return $sign . $abs . 's';
        if ($abs < 3600)  return $sign . round($abs / 60, 1) . 'm';
        if ($abs < 86400) return $sign . round($abs / 3600, 1) . 'h';
        return $sign . round($abs / 86400, 1) . 'd';
    }

    function clean_hex(string $raw): string {
        $s = strtolower(trim($raw));
        if (str_starts_with($s, '0x')) $s = substr($s, 2);
        if ($s === '' || !ctype_xdigit($s)) {
            throw new \InvalidArgumentException('not valid hex');
        }
        if (strlen($s) % 2 !== 0) {
            throw new \InvalidArgumentException('odd-length hex');
        }
        return $s;
    }
}
