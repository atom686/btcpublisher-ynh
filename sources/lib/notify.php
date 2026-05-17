<?php
/**
 * Telegram notifier. Reads token + chat from settings.json (managed via the
 * web UI) and POSTs to the Bot API. Failures are logged but never raised —
 * notifications must not break the broadcast loop.
 */

namespace Btcpub\Notify;

function notify(string $text, string $settings_path): bool {
    if (!is_file($settings_path)) return false;
    $settings = json_decode((string) file_get_contents($settings_path), true) ?: [];
    if (empty($settings['telegram_enabled'])) return false;

    $token = $settings['telegram_bot_token'] ?? '';
    $chat  = $settings['telegram_chat_id']   ?? '';
    if ($token === '' || $chat === '') return false;

    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $payload = json_encode([
        'chat_id' => $chat,
        'text' => $text,
        'disable_web_page_preview' => true,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        error_log("[btcpub-notify] HTTP {$code}: " . substr((string) $body, 0, 200));
        return false;
    }
    return true;
}
