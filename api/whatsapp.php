<?php
/**
 * Sends one-time verification codes over WhatsApp.
 *
 * Providers (choose in config.php → 'whatsapp' → 'provider'):
 *  - 'meta'     : WhatsApp Cloud API (official, Meta Business). Needs an approved
 *                 "authentication" template with one {{1}} code parameter + a copy-code button.
 *  - 'ultramsg' : UltraMsg (simple paid gateway, links your own WhatsApp number via QR).
 *  - 'none'     : nothing is sent. Only for testing: with 'otp_debug' => true the code is
 *                 returned to the app and shown on screen.
 */

function normalize_phone(string $raw, string $defaultCountry = '964'): string
{
    $d = preg_replace('/\D+/', '', $raw) ?? '';
    if (str_starts_with($d, '00')) $d = substr($d, 2);
    if (str_starts_with($d, '0')) $d = $defaultCountry . substr($d, 1);
    elseif (strlen($d) <= 10 && !str_starts_with($d, $defaultCountry)) $d = $defaultCountry . $d;
    return $d;
}

function whatsapp_send_code(array $cfg, string $phone, string $code, string $appName): bool
{
    $wa = $cfg['whatsapp'] ?? [];
    $provider = $wa['provider'] ?? 'none';

    if ($provider === 'meta') {
        $url = 'https://graph.facebook.com/v21.0/' . rawurlencode($wa['meta_phone_number_id']) . '/messages';
        $body = [
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'template',
            'template' => [
                'name' => $wa['meta_template'] ?? 'otp_code',
                'language' => ['code' => $wa['meta_language'] ?? 'ar'],
                'components' => [
                    ['type' => 'body', 'parameters' => [['type' => 'text', 'text' => $code]]],
                    ['type' => 'button', 'sub_type' => 'url', 'index' => '0', 'parameters' => [['type' => 'text', 'text' => $code]]],
                ],
            ],
        ];
        return http_post_json($url, $body, ['Authorization: Bearer ' . $wa['meta_token']]);
    }

    if ($provider === 'ultramsg') {
        $url = 'https://api.ultramsg.com/' . rawurlencode($wa['ultramsg_instance']) . '/messages/chat';
        $text = "رمز التحقق الخاص بك في {$appName} هو: *{$code}*\nلا تشارك هذا الرمز مع أي شخص. صالح لمدة 10 دقائق.";
        return http_post_json($url, ['token' => $wa['ultramsg_token'], 'to' => '+' . $phone, 'body' => $text]);
    }

    return false; // 'none'
}

function http_post_json(string $url, array $body, array $headers = []): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300) error_log("[sufra] whatsapp send failed ($code): " . substr((string) $res, 0, 300));
    return $code >= 200 && $code < 300;
}

function http_get_json(string $url, array $headers = []): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) return null;
    $j = json_decode((string) $res, true);
    return is_array($j) ? $j : null;
}
