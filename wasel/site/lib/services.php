<?php
/**
 * استيراد/تصدير «الخدمات» كملفات JSON منفصلة (كل خدمة بملف)، عبر بوت تيليجرام أو من لوحة الأدمن.
 * الخدمات: الباقات، طرق الدفع، أكواد الخصم، الأزرار والاستبيانات.
 * - التصدير: ملف فيه {app:'wasel', kind:'service', service, rows}.
 * - الاستيراد: ندمج الصفوف بالمعرف (نحدّث الموجود ونضيف الجديد) بدون ما نمسح الباقي.
 */

const SERVICE_DEFS = [
    'plans'    => ['label' => 'الباقات والأسعار', 'store' => 'plans', 'owner' => false, 'emoji' => '💎'],
    'payments' => ['label' => 'طرق الدفع', 'store' => 'payment_methods', 'owner' => false, 'emoji' => '💳'],
    'coupons'  => ['label' => 'أكواد الخصم', 'store' => 'coupons', 'owner' => false, 'emoji' => '🎟️'],
    'polls'    => ['label' => 'الأزرار والاستبيانات', 'store' => 'poll_tpls', 'owner' => true, 'emoji' => '🔘'],
];

function svc_count(string $key): int
{
    $def = SERVICE_DEFS[$key] ?? null;
    return $def ? count(store_all($def['store'])) : 0;
}

/** أول حساب أدمن (لإسناد الخدمات صاحبة المالك عند الاستيراد عبر البوت) */
function svc_admin_uid(): int
{
    $a = store_first('users', fn($u) => !empty($u['is_admin']));
    return $a ? (int)$a['id'] : 1;
}

/** مصفوفة التصدير لخدمة */
function svc_export(string $key): array
{
    $def = SERVICE_DEFS[$key] ?? null;
    if (!$def) {
        return [];
    }
    $fh = store_lock($def['store'], LOCK_SH);
    $raw = store_load_raw($def['store'], store_empty_table());
    store_unlock($fh);
    return ['app' => 'wasel', 'kind' => 'service', 'service' => $key, 'label' => $def['label'],
        'exported_at' => date(DATE_ATOM), 'count' => count($raw['rows'] ?? []),
        'next_id' => (int)($raw['next_id'] ?? 1), 'rows' => array_values($raw['rows'] ?? [])];
}

function svc_export_json(string $key): string
{
    return json_encode(svc_export($key), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

/** يكتشف نوع الخدمة من محتوى ملف JSON (أو null) */
function svc_detect(string $json): ?string
{
    $d = json_decode($json, true);
    if (is_array($d) && ($d['app'] ?? '') === 'wasel' && ($d['kind'] ?? '') === 'service' && isset(SERVICE_DEFS[$d['service'] ?? ''])) {
        return $d['service'];
    }
    return null;
}

/**
 * يستورد ملف خدمة. mode: 'merge' (يحدّث بالمعرف ويضيف الجديد) أو 'replace' (يستبدل الكل).
 * يرجع [ok, رسالة].
 */
function svc_import(string $json, string $mode = 'merge'): array
{
    $key = svc_detect($json);
    if (!$key) {
        return [false, 'الملف مو خدمة صالحة من واصل'];
    }
    $def = SERVICE_DEFS[$key];
    $d = json_decode($json, true);
    $rows = array_values(array_filter((array)($d['rows'] ?? []), 'is_array'));
    if (!$rows && $mode !== 'replace') {
        return [false, "ملف {$def['label']} فارغ"];
    }
    // نسخة احتياطية قبل أي تغيير
    @file_put_contents(pm_dir('backups') . "/before-{$key}-" . date('Ymd-His') . '.json', svc_export_json($key));
    $adminUid = svc_admin_uid();
    $added = 0;
    $updated = 0;
    $fh = store_lock($def['store'], LOCK_EX);
    $cur = store_load_raw($def['store'], store_empty_table());
    $map = [];
    foreach ($cur['rows'] as $k => $r) {
        $map[(string)($r['id'] ?? $k)] = $k;
    }
    if ($mode === 'replace') {
        $cur = store_empty_table();
        $map = [];
    }
    $maxId = (int)($cur['next_id'] ?? 1) - 1;
    foreach ($cur['rows'] as $r) {
        $maxId = max($maxId, (int)($r['id'] ?? 0));
    }
    foreach ($rows as $r) {
        if (!isset($r['id'])) {
            continue;
        }
        if ($def['owner']) {
            $r['owner'] = $adminUid; // الخدمات صاحبة المالك تصير للأدمن
        }
        $id = (string)$r['id'];
        if (isset($map[$id])) {
            $cur['rows'][$map[$id]] = $r;
            $updated++;
        } else {
            $cur['rows'][$id] = $r;
            $added++;
        }
        $maxId = max($maxId, (int)$r['id']);
    }
    $cur['next_id'] = $maxId + 1;
    store_save_raw($def['store'], $cur);
    store_unlock($fh);
    $msg = "{$def['emoji']} {$def['label']}: ";
    $msg .= $mode === 'replace' ? "استُبدلت ({$added} سجل)" : "أُضيف {$added}، حُدّث {$updated}";
    return [true, $msg];
}

/** قائمة الخدمات مع أعدادها (للواجهة والبوت) */
function svc_list(): array
{
    $out = [];
    foreach (SERVICE_DEFS as $key => $def) {
        $out[] = ['key' => $key, 'label' => $def['label'], 'emoji' => $def['emoji'], 'count' => svc_count($key)];
    }
    return $out;
}

// ---------------------------------------------------------------- عبر تيليجرام
/** يرسل ملف خدمة وحدة للبوت. يرجع [ok, msg] */
function tg_send_service(string $key): array
{
    $s = settings();
    if (empty($s['tg_token']) || empty($s['tg_chat_id'])) {
        return [false, 'اضبط توكن البوت ورقم المحادثة أولاً'];
    }
    if (!isset(SERVICE_DEFS[$key])) {
        return [false, 'خدمة غير معروفة'];
    }
    $def = SERVICE_DEFS[$key];
    $n = svc_count($key);
    $file = pm_dir('backups') . "/wasel-{$key}-" . date('Ymd-His') . '.json';
    file_put_contents($file, svc_export_json($key));
    $r = tg_api('sendDocument', [
        'chat_id' => $s['tg_chat_id'],
        'caption' => "{$def['emoji']} {$def['label']} ({$n}) - " . date('Y-m-d H:i') . "\nللاستيراد: أرسل هذا الملف للبوت",
        'document' => new CURLFile($file, 'application/json', basename($file)),
    ], true);
    @unlink($file);
    return empty($r['ok']) ? [false, 'فشل الإرسال: ' . ($r['description'] ?? '')] : [true, 'تم'];
}

/** يرسل كل الخدمات كملفات منفصلة */
function tg_send_all_services(): array
{
    $sent = 0;
    $errs = [];
    foreach (array_keys(SERVICE_DEFS) as $key) {
        [$ok, $m] = tg_send_service($key);
        if ($ok) {
            $sent++;
        } else {
            $errs[] = $m;
        }
    }
    return $sent ? [true, "أُرسلت {$sent} خدمات"] : [false, $errs[0] ?? 'فشل الإرسال'];
}

/** لوحة أزرار البوت للخدمات */
function tg_services_keyboard(): array
{
    $rows = [[['text' => '📦 كل الخدمات']]];
    $line = [];
    foreach (SERVICE_DEFS as $key => $def) {
        $line[] = ['text' => $def['emoji'] . ' ' . $def['label']];
        if (count($line) === 2) {
            $rows[] = $line;
            $line = [];
        }
    }
    if ($line) {
        $rows[] = $line;
    }
    $rows[] = [['text' => '🗄️ نسخة كاملة']];
    return ['keyboard' => $rows, 'resize_keyboard' => true];
}

/** يطابق نص رسالة البوت لمفتاح خدمة (بالاسم أو الإيموجي) */
function tg_match_service(string $text): ?string
{
    $text = trim($text);
    foreach (SERVICE_DEFS as $key => $def) {
        if ($text === '/export_' . $key || $text === $def['label'] || $text === $def['emoji'] . ' ' . $def['label'] || mb_stripos($text, $def['label']) !== false) {
            return $key;
        }
    }
    return null;
}
