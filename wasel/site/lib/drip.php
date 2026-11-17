<?php
/**
 * إرسال الحملة على دفعات: 40 رقم الآن، و40 بعد 24 ساعة من نفس الحساب... لحد ما تخلص الأرقام.
 * الدفعات الباقية تنحفظ بجدول drips، وCron يرسل الدفعة الجاية بوقتها.
 */

const DRIP_BATCH_DEFAULT = 40;
const DRIP_GAP_HOURS = 24;

function drip_media_dir(): string
{
    $d = DATA_DIR . '/drip_media';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

/** يرسل دفعة للمحرك ويرجع رده (بدون ما يطبع) */
function engine_send_campaign(string $accId, array $numbers, string $text, ?array $media, int $delay, int $mediaDelay, ?string $sendAt = null): array
{
    $payload = ['numbers' => array_values($numbers), 'text' => $text, 'delay' => $delay, 'media_delay' => $mediaDelay, 'media' => $media];
    if ($sendAt) {
        $payload['send_at'] = $sendAt;
    }
    return engine_json('POST', '/accounts/' . rawurlencode($accId) . '/campaign', $payload, 120);
}

/** جدول الدفعات: أرقام مفصولة بفواصل، 0 = «كل الباقي». أقصى 60 يوم، وكل يوم ما يتجاوز حد الباقة اليومي */
function drip_parse_schedule(string $raw, int $dailyLimit = 0): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return [];
    }
    $arr = json_decode($raw, true);
    if (!is_array($arr)) {
        $arr = preg_split('/[,\s]+/', $raw);
    }
    $out = [];
    foreach (array_slice($arr, 0, 60) as $v) {
        $n = max(0, min(5000, (int)$v));
        if ($dailyLimit > 0) {
            $n = $n === 0 ? $dailyLimit : min($n, $dailyLimit);
        }
        $out[] = $n;
    }
    // ما نقبل جدول كله أصفار إلا إذا أول يوم رقم
    return $out && $out[0] === 0 && count($out) === 1 ? [] : $out;
}

/** حجم الدفعة رقم $k (0 = الأولى). 0 = كل الباقي */
function drip_batch_size(array $d, int $k): int
{
    $s = $d['schedule'] ?? [];
    if ($s) {
        return (int)($s[$k] ?? end($s));
    }
    return (int)$d['batch'];
}

function drip_days(int $total, int $first, array $sched): int
{
    $left = $total - $first;
    $days = 1;
    for ($k = 1; $left > 0 && $days < 400; $k++, $days++) {
        $n = $sched ? (int)($sched[$k] ?? end($sched)) : $first;
        $left -= $n === 0 ? $left : $n;
    }
    return $days;
}

/** يحفظ باقي الأرقام كدفعات مجدولة */
function drip_create(int $owner, string $accId, string $accName, array $rest, string $text, ?array $media, int $delay, int $mediaDelay, int $batch, int $firstAt, int $total, array $schedule = []): int
{
    $mediaRef = null;
    if ($media) {
        $file = bin2hex(random_bytes(8)) . '.bin';
        file_put_contents(drip_media_dir() . '/' . $file, base64_decode($media['data']));
        $mediaRef = ['name' => $media['name'], 'mime' => $media['mime'], 'file' => $file];
    }
    return (int)store_insert('drips', [
        'owner' => $owner, 'account_id' => $accId, 'account_name' => $accName, 'numbers' => array_values($rest), 'total' => $total,
        'text' => $text, 'media' => $mediaRef, 'delay' => $delay, 'media_delay' => $mediaDelay, 'batch' => $batch,
        'next_at' => $firstAt + DRIP_GAP_HOURS * 3600, 'batches_sent' => 1, 'created_at' => now_str(), 'schedule' => $schedule,
    ]);
}

function drip_delete(int $id): void
{
    $d = store_find('drips', $id);
    if ($d && !empty($d['media']['file'])) {
        @unlink(drip_media_dir() . '/' . basename($d['media']['file']));
    }
    store_delete('drips', $id);
}

function drip_public(array $d): array
{
    $next = drip_batch_size($d, (int)$d['batches_sent']);
    $next = $next === 0 ? count($d['numbers']) : min($next, count($d['numbers']));
    return ['id' => $d['id'], 'account_id' => $d['account_id'], 'account_name' => $d['account_name'], 'left' => count($d['numbers']),
        'total' => (int)$d['total'], 'batch' => (int)$d['batch'], 'next_batch' => $next, 'custom' => !empty($d['schedule']),
        'batches_left' => drip_days(count($d['numbers']), $next, array_slice($d['schedule'] ?? [], (int)$d['batches_sent'])), 'batches_sent' => (int)$d['batches_sent'],
        'next_at' => date('Y-m-d H:i', (int)$d['next_at']), 'text' => mb_substr((string)$d['text'], 0, 60)];
}

/** يُستدعى من Cron: يرسل الدفعات اللي حان وقتها */
function drip_run_due(): int
{
    $sent = 0;
    foreach (store_all('drips') as $d) {
        if ((int)$d['next_at'] > time()) {
            continue;
        }
        $id = (int)$d['id'];
        $owner = user_by_id((int)$d['owner']);
        if (!$owner || !$d['numbers']) {
            drip_delete($id);
            continue;
        }
        if (!can_send($owner)) {
            // ما عنده رصيد/اشتراك: نأجّل يوم وننبهه
            store_update('drips', $id, fn($x) => array_merge($x, ['next_at' => time() + DRIP_GAP_HOURS * 3600]));
            add_event((int)$d['owner'], $d['account_id'], 'تأجلت دفعة الحملة', 'رصيدك أو اشتراكك ما يكفي - اشحن حتى تكمل الدفعات', 'warning');
            continue;
        }
        // الحساب بعده يرسل الدفعة السابقة؟ ننتظر نص ساعة
        $st = engine_json('GET', '/accounts/' . rawurlencode($d['account_id']) . '/campaign');
        if (!empty($st['running']) || !empty($st['paused']) || empty($st['ok']) && ($st['_status'] ?? 200) >= 500) {
            store_update('drips', $id, fn($x) => array_merge($x, ['next_at' => time() + 1800]));
            continue;
        }
        $size = drip_batch_size($d, (int)$d['batches_sent']);
        $lim = user_limits($owner);
        if ($lim['daily'] > 0) {
            $size = $size === 0 ? $lim['daily'] : min($size, $lim['daily']);
        }
        $size = $size === 0 ? count($d['numbers']) : $size;
        $batch = array_slice($d['numbers'], 0, $size);
        $media = null;
        if (!empty($d['media']['file']) && is_file($f = drip_media_dir() . '/' . basename($d['media']['file']))) {
            $media = ['name' => $d['media']['name'], 'mime' => $d['media']['mime'], 'data' => base64_encode((string)file_get_contents($f))];
        }
        $r = engine_send_campaign($d['account_id'], $batch, (string)$d['text'], $media, (int)$d['delay'], (int)$d['media_delay']);
        if (empty($r['ok'])) {
            store_update('drips', $id, fn($x) => array_merge($x, ['next_at' => time() + 3600]));
            continue;
        }
        $sent++;
        $rest = array_slice($d['numbers'], $size);
        $n = (int)$d['batches_sent'] + 1;
        add_event((int)$d['owner'], $d['account_id'], "انرسلت الدفعة {$n}", count($batch) . ' رقم' . ($rest ? ' - الدفعة الجاية بعد 24 ساعة (باقي ' . count($rest) . ')' : ' - خلصت كل الدفعات'), 'success');
        if (!$rest) {
            drip_delete($id);
        } else {
            store_update('drips', $id, fn($x) => array_merge($x, ['numbers' => $rest, 'batches_sent' => $n, 'next_at' => time() + DRIP_GAP_HOURS * 3600]));
        }
    }
    return $sent;
}

function drip_routes(): array
{
    return [
        ['GET', '#^/api/drips$#', function () {
            $uid = require_login();
            json_out(['ok' => true, 'drips' => array_map('drip_public', array_values(store_where('drips', fn($d) => (int)$d['owner'] === $uid)))]);
        }],
        ['DELETE', '#^/api/drips/(\d+)$#', function ($id) {
            $uid = require_login();
            $d = store_find('drips', (int)$id);
            if (!$d || (int)$d['owner'] !== $uid) {
                json_err('غير موجود', 404);
            }
            drip_delete((int)$id);
            json_out(['ok' => true]);
        }],
    ];
}
