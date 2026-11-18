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
function engine_send_campaign(string $accId, array $numbers, string $text, ?array $media, int $delay, int $mediaDelay, ?string $sendAt = null, ?string $tag = null): array
{
    $payload = ['numbers' => array_values($numbers), 'text' => $text, 'delay' => $delay, 'media_delay' => $mediaDelay, 'media' => $media, 'tag' => $tag];
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

/** موعد بداية اليوم الجاي: نفس الساعة المحددة (مثلاً 10:00) باليوم اللي بعده، وإلا بعد 24 ساعة */
function drip_next_start(array $d): int
{
    $base = (int)($d['day_started'] ?? time());
    $anchor = (string)($d['anchor'] ?? '');
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $anchor)) {
        // دفعة وحدة لكل يوم: اليوم اللي بعده بنفس الساعة (حتى لو Cron تأخر، الوقت ما يزحف)
        return strtotime(date('Y-m-d', $base + 86400) . ' ' . $anchor);
    }
    return $base + DRIP_GAP_HOURS * 3600;
}

/** يحفظ باقي الأرقام كدفعات مجدولة */
function drip_create(int $owner, string $accId, string $accName, array $rest, string $text, ?array $media, int $delay, int $mediaDelay, int $batch, int $firstAt, int $total, array $schedule = [], string $anchor = ''): int
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
        'next_at' => drip_next_start(['day_started' => $firstAt, 'anchor' => $anchor]), 'anchor' => $anchor, 'batches_sent' => 1, 'created_at' => now_str(), 'schedule' => $schedule,
        // حالة اليوم الحالي: كم المطلوب، كم نجح، وهل فيه دفعة لسه ما رجعت نتيجتها
        'day_started' => $firstAt, 'day_size' => $batch, 'day_ok' => 0, 'awaiting' => true, 'launched_at' => time(), 'launched' => $batch,
        'retry' => [], 'retried' => [], 'invalid' => 0, 'result' => null,
    ]);
}

/** نتيجة دفعة وصلت من المحرك (من hook الحملة) */
function drip_record_result(int $id, int $sent, array $failed, array $reasons): void
{
    store_update('drips', $id, fn($d) => array_merge($d, ['result' => ['sent' => $sent, 'failed' => array_values(array_map('strval', $failed)), 'reasons' => $reasons, 'at' => time()]]));
}

/** يرسل دفعة ويعلّمها بانتظار النتيجة */
function drip_launch(array $d, array $nums, bool $topup): bool
{
    $media = null;
    if (!empty($d['media']['file']) && is_file($f = drip_media_dir() . '/' . basename($d['media']['file']))) {
        $media = ['name' => $d['media']['name'], 'mime' => $d['media']['mime'], 'data' => base64_encode((string)file_get_contents($f))];
    }
    $r = engine_send_campaign($d['account_id'], $nums, with_trial_footer((int)$d['owner'], (string)$d['text']), $media, (int)$d['delay'], (int)$d['media_delay'], null, 'drip:' . $d['id'] . ':' . (int)$d['batches_sent'] . ($topup ? ':t' : ''));
    return !empty($r['ok']);
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
    $retry = count($d['retry'] ?? []) + (int)($d['retry_next'] ?? 0);
    $next = $next === 0 ? count($d['numbers']) : min($next, count($d['numbers']));
    return ['id' => $d['id'], 'account_id' => $d['account_id'], 'account_name' => $d['account_name'], 'left' => count($d['numbers']),
        'total' => (int)$d['total'], 'batch' => (int)$d['batch'], 'next_batch' => $next, 'custom' => !empty($d['schedule']),
        'batches_left' => drip_days(count($d['numbers']), $next, array_slice($d['schedule'] ?? [], (int)$d['batches_sent'])), 'batches_sent' => (int)$d['batches_sent'],
        'next_at' => date('Y-m-d H:i', (int)$d['next_at']), 'text' => mb_substr((string)$d['text'], 0, 60),
        'today_ok' => (int)($d['day_ok'] ?? 0), 'today_size' => (int)($d['day_size'] ?? 0), 'today_running' => !empty($d['awaiting']),
        'retry' => $retry, 'invalid' => (int)($d['invalid'] ?? 0), 'anchor' => (string)($d['anchor'] ?? '')];
}

/**
 * يُستدعى من Cron. لكل جدول دفعات:
 * 1) إذا فيه دفعة رجعت نتيجتها: نحسب الناجح فقط من حصة اليوم. الفاشل بخطأ مؤقت ينحجز لدفعة باچر (مرة وحدة)،
 *    والرقم اللي ما عليه واتساب ما ينعاد. وإذا الناجح أقل من حصة اليوم نكمّل الناقص من الأرقام الباقية بنفس اليوم.
 * 2) إذا خلص اليوم ووصل وقت اليوم الجاي: نبدأ دفعة جديدة (الفاشل من أمس أولها).
 */
function drip_run_due(): int
{
    $launched = 0;
    foreach (store_all('drips') as $d) {
        $launched += drip_step((int)$d['id']);
    }
    return $launched;
}

/** خطوة وحدة لجدول دفعات (تنادى من Cron، وفوراً لما توصل نتيجة دفعة من المحرك). بقفل حتى ما تشتغل مرتين بنفس اللحظة */
function drip_step(int $dripId): int
{
    $lk = @fopen(DATA_DIR . '/drip_' . $dripId . '.lock', 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) {
        return 0;
    }
    try {
        $d = store_find('drips', $dripId);
        return $d ? drip_step_row($d) : 0;
    } finally {
        flock($lk, LOCK_UN);
        fclose($lk);
        if (!store_find('drips', $dripId)) {
            @unlink(DATA_DIR . '/drip_' . $dripId . '.lock');
        }
    }
}

function drip_step_row(array $d): int
{
    $launched = 0;
    do {
        $id = (int)$d['id'];
        $owner = user_by_id((int)$d['owner']);
        if (!$owner) {
            drip_delete($id);
            break;
        }
        $legacy = !array_key_exists('awaiting', $d); // جدول من النسخة السابقة
        // ---------- 1) دفعة بانتظار النتيجة
        if (!empty($d['awaiting'])) {
            $res = $d['result'] ?? null;
            if (!$res) {
                // ما وصلت النتيجة: إذا الحساب فاضي من مدة طويلة نعتبرها نجحت كلها (احتياط)
                $st = engine_json('GET', '/accounts/' . rawurlencode($d['account_id']) . '/campaign');
                $busy = !empty($st['running']) || !empty($st['paused']) || !empty($st['scheduled_for']);
                if ($busy || time() - (int)($d['launched_at'] ?? 0) < 6 * 3600) {
                    break;
                }
                $res = ['sent' => (int)($d['launched'] ?? 0), 'failed' => [], 'reasons' => []];
            }
            $retried = array_flip(array_map('strval', $d['retried'] ?? []));
            $retry = $d['retry'] ?? [];
            $invalid = (int)($d['invalid'] ?? 0);
            foreach ($res['failed'] as $n) {
                $n = preg_replace('/\D/', '', (string)$n);
                if (($res['reasons'][$n] ?? 'error') === 'invalid') {
                    $invalid++;
                } elseif (!isset($retried[$n])) {
                    $retry[] = $n;
                    $retried[$n] = true;
                }
            }
            $dayOk = (int)($d['day_ok'] ?? 0) + (int)$res['sent'];
            $d = array_merge($d, ['awaiting' => false, 'result' => null, 'day_ok' => $dayOk, 'retry' => array_values(array_unique($retry)),
                'retried' => array_keys($retried), 'invalid' => $invalid]);
            // نكمّل حصة اليوم من الأرقام الباقية (الفاشلة ما تنحسب)
            $need = (int)$d['day_size'] - $dayOk;
            if ($need > 0 && $d['numbers'] && time() < (int)$d['day_started'] + 20 * 3600 && can_send($owner)) {
                $nums = array_slice($d['numbers'], 0, $need);
                $d['numbers'] = array_slice($d['numbers'], count($nums));
                $d = array_merge($d, ['awaiting' => true, 'launched_at' => time(), 'launched' => count($nums)]);
                if (drip_launch($d, $nums, true)) {
                    store_update('drips', $id, fn($x) => $d);
                    add_event((int)$d['owner'], $d['account_id'], 'إكمال دفعة اليوم', count($nums) . ' رقم بدل الرسائل اللي فشلت (حصة اليوم ' . $d['day_size'] . ')', 'info');
                    $launched++;
                    break;
                }
                // تعذر الإرسال: نرجع الأرقام ونكمل باچر
                $d['numbers'] = array_merge($nums, $d['numbers']);
                $d['awaiting'] = false;
            }
            // خلص اليوم: الفاشل (المؤقت) أول دفعة باچر
            $d['retry_next'] = count($d['retry']); // للعرض: كم رقم فاشل ينعاد باچر
            $d['numbers'] = array_values(array_merge($d['retry'], $d['numbers']));
            $d['retry'] = [];
            if (!$d['numbers']) {
                add_event((int)$d['owner'], $d['account_id'], 'خلصت كل الدفعات', 'وصلت الرسالة لـ ' . number_format((int)$d['total'] - $invalid) . ' رقم' . ($invalid ? " ({$invalid} رقم ما عليه واتساب)" : ''), 'success');
                drip_delete($id);
                break;
            }
            $d['next_at'] = drip_next_start($d);
            store_update('drips', $id, fn($x) => $d);
            break;
        }
        // ---------- 2) يوم جديد
        if ((int)$d['next_at'] > time()) {
            break;
        }
        if (!$d['numbers']) {
            drip_delete($id);
            break;
        }
        if (!can_send($owner)) {
            store_update('drips', $id, fn($x) => array_merge($x, ['next_at' => time() + DRIP_GAP_HOURS * 3600]));
            add_event((int)$d['owner'], $d['account_id'], 'تأجلت دفعة الحملة', 'رصيدك أو اشتراكك ما يكفي - اشحن حتى تكمل الدفعات', 'warning');
            break;
        }
        $st = engine_json('GET', '/accounts/' . rawurlencode($d['account_id']) . '/campaign');
        if (!empty($st['running']) || !empty($st['paused']) || empty($st['ok']) && ($st['_status'] ?? 200) >= 500) {
            store_update('drips', $id, fn($x) => array_merge($x, ['next_at' => time() + 1800]));
            break;
        }
        $size = drip_batch_size($d, (int)$d['batches_sent']);
        $lim = user_limits($owner);
        if ($lim['daily'] > 0) {
            $size = $size === 0 ? $lim['daily'] : min($size, $lim['daily']);
        }
        $size = $size === 0 ? count($d['numbers']) : $size;
        $batch = array_slice($d['numbers'], 0, $size);
        $n = (int)$d['batches_sent'] + 1;
        $d2 = array_merge($d, ['numbers' => array_slice($d['numbers'], count($batch)), 'batches_sent' => $n, 'day_started' => time(), 'day_size' => count($batch),
            'day_ok' => 0, 'awaiting' => true, 'launched_at' => time(), 'launched' => count($batch), 'result' => null, 'retry_next' => 0,
            'next_at' => time() + DRIP_GAP_HOURS * 3600] + ($legacy ? ['retry' => [], 'retried' => [], 'invalid' => 0] : []));
        $d2['next_at'] = drip_next_start($d2);
        if (!drip_launch($d2, $batch, false)) {
            store_update('drips', $id, fn($x) => array_merge($x, ['next_at' => time() + 3600]));
            break;
        }
        store_update('drips', $id, fn($x) => $d2);
        $launched++;
        $left = count($d2['numbers']);
        add_event((int)$d['owner'], $d['account_id'], "بدأت الدفعة {$n}", count($batch) . ' رقم' . ($left ? ' - باقي ' . $left : ' - آخر دفعة'), 'success');
    } while (false);
    return $launched;
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
