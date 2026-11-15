<?php
const REQUIRED_ENGINE_VERSION = 3;
/**
 * الاتصال بمحرك واتساب (تطبيق Node.js). كل الطلبات تحمل المفتاح السري ENGINE_SECRET.
 */

/**
 * يرسل طلب للمحرك ويرجع [status, body]. body مصفوفة JSON أو نص خام (لصورة QR).
 * لو المحرك مو شغّال يرجع status = 0.
 */
function engine_request(string $method, string $path, $body = null, int $timeout = 15): array
{
    if (ENGINE_SECRET === '') {
        return [0, null];
    }
    $ch = curl_init(rtrim(ENGINE_URL, '/') . $path);
    $headers = ['X-Engine-Secret: ' . ENGINE_SECRET];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADER => true,
    ]);
    $resp = curl_exec($ch);
    if ($resp === false) {
        curl_close($ch);
        return [0, null];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $raw = substr($resp, $headerSize);
    if (stripos($type, 'application/json') !== false) {
        $json = json_decode($raw, true);
        return [$status, $json];
    }
    return [$status, $raw];
}

/** نفس engine_request لكن يرجع JSON فقط (أو خطأ موحد لو المحرك متوقف) */
function engine_json(string $method, string $path, $body = null, int $timeout = 15): array
{
    [$status, $data] = engine_request($method, $path, $body, $timeout);
    if ($status === 0 || !is_array($data)) {
        return ['ok' => false, 'error' => 'محرك واتساب غير شغّال حالياً - الأدمن يفتح صفحة install.php ويتبع خطوة «محرك واتساب»', '_status' => 503];
    }
    if ($status === 404 && ($data['error'] ?? '') === 'not found') {
        return ['ok' => false, 'error' => 'محرك واتساب المرفوع نسخة قديمة - ارفع مجلد wasel-engine الجديد ثم اضغط Restart من Setup Node.js App', '_status' => 503];
    }
    if ($status === 401) {
        return ['ok' => false, 'error' => 'المفتاح السري للمحرك غير مطابق - من صفحة install.php اضغط «إعادة كتابة إعدادات المحرك» ثم Restart', '_status' => 503];
    }
    $data['_status'] = $status;
    return $data;
}

/** قائمة كل الحسابات بحالتها الحية من المحرك: id => info (نخزنها مؤقتاً لنفس الطلب) */
function engine_accounts(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    [$status, $data] = engine_request('GET', '/accounts', null, 6);
    $cache = [];
    if ($status === 200 && is_array($data)) {
        foreach ($data as $a) {
            $cache[$a['id']] = $a;
        }
    }
    return $cache;
}

function engine_logged_in(string $accId): bool
{
    $all = engine_accounts();
    return !empty($all[$accId]['logged_in']);
}

/** إرسال رسالة مباشرة (رمز تحقق، رسالة اشتراك...). يرجع [ok, error] */
function engine_send(string $accId, string $phone, string $text, int $timeout = 45): array
{
    $r = engine_json('POST', '/accounts/' . rawurlencode($accId) . '/send', ['phone' => $phone, 'text' => $text], $timeout);
    return [!empty($r['ok']), $r['error'] ?? null];
}

/** يزامن إعدادات حساب (المالك، الاسم، الرد الآلي، مرسل الرموز) مع المحرك */
function engine_sync_account(array $acc): void
{
    engine_request('POST', '/accounts/' . rawurlencode($acc['id']) . '/config', [
        'owner' => $acc['owner'],
        'name' => $acc['name'],
        'otp_sender' => !empty($acc['otp_sender']),
        'auto_reply' => [
            'enabled' => !empty($acc['auto_reply_enabled']),
            'ai_enabled' => !empty($acc['auto_reply_ai_enabled']),
            'rules' => $acc['auto_reply_rules'] ?? [],
        ],
    ], 8);
}
