<?php
/**
 * تصدير/استيراد كل بيانات المنصة كملف JSON، وإرسالها لبوت تيليجرام (نسخ احتياطي)،
 * والاستيراد بإرسال ملف JSON للبوت من محادثة الأدمن.
 */

const BACKUP_TABLES = ['users', 'wa_accounts', 'subscribers', 'payments', 'api_keys', 'history', 'events',
    'wallet_tx', 'recharges', 'payment_methods', 'plans'];
const BACKUP_DOCS = ['settings', 'subscription_settings'];

function backup_export(): array
{
    $out = ['app' => 'wasel', 'version' => 2, 'exported_at' => date(DATE_ATOM), 'tables' => [], 'docs' => []];
    foreach (BACKUP_TABLES as $t) {
        $fh = store_lock($t, LOCK_SH);
        $out['tables'][$t] = store_load_raw($t, store_empty_table());
        store_unlock($fh);
    }
    foreach (BACKUP_DOCS as $d) {
        $out['docs'][$d] = store_get_doc($d);
    }
    $logo = logo_path();
    $out['logo'] = is_file($logo) ? base64_encode((string)file_get_contents($logo)) : null;
    return $out;
}

function backup_json(): string
{
    return json_encode(backup_export(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** يستورد نسخة (يستبدل البيانات الحالية). يرجع [ok, رسالة] */
function backup_import(string $json): array
{
    $d = json_decode($json, true);
    if (!is_array($d) || ($d['app'] ?? '') !== 'wasel' || !isset($d['tables']) || !is_array($d['tables'])) {
        return [false, 'الملف مو نسخة احتياطية صالحة من واصل'];
    }
    // نحتفظ بنسخة من الوضع الحالي قبل الاستبدال
    file_put_contents(pm_dir('backups') . '/before-import-' . date('Ymd-His') . '.json', backup_json());
    $n = 0;
    foreach (BACKUP_TABLES as $t) {
        if (isset($d['tables'][$t]['rows']) && is_array($d['tables'][$t]['rows'])) {
            $fh = store_lock($t, LOCK_EX);
            store_save_raw($t, ['next_id' => (int)($d['tables'][$t]['next_id'] ?? 1), 'rows' => $d['tables'][$t]['rows']]);
            store_unlock($fh);
            $n += count($d['tables'][$t]['rows']);
        }
    }
    foreach (BACKUP_DOCS as $doc) {
        if (isset($d['docs'][$doc]) && is_array($d['docs'][$doc])) {
            $cur = store_get_doc($doc);
            $new = $d['docs'][$doc];
            if ($doc === 'settings') {
                // إعدادات تيليجرام الحالية تبقى (حتى ما ينقطع البوت بعد الاستيراد)
                foreach (['tg_token', 'tg_chat_id', 'tg_webhook_key'] as $k) {
                    $new[$k] = $cur[$k] ?? '';
                }
            }
            store_update_doc($doc, fn() => $new);
        }
    }
    if (!empty($d['logo'])) {
        file_put_contents(logo_path(), base64_decode($d['logo']));
    }
    return [true, "تم الاستيراد ({$n} سجل)"];
}

// ---------------------------------------------------------------- تيليجرام

function tg_api(string $method, array $params = [], bool $multipart = false): ?array
{
    $token = settings()['tg_token'] ?? '';
    if ($token === '') {
        return null;
    }
    $base = defined('TG_API_BASE') ? TG_API_BASE : 'https://api.telegram.org';
    $ch = curl_init("{$base}/bot{$token}/{$method}");
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_POSTFIELDS => $multipart ? $params : http_build_query($params)]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return $resp ? json_decode($resp, true) : null;
}

function tg_send_backup(string $caption = ''): array
{
    $s = settings();
    if (empty($s['tg_token']) || empty($s['tg_chat_id'])) {
        return [false, 'اضبط توكن البوت ورقم المحادثة أولاً'];
    }
    $file = pm_dir('backups') . '/wasel-backup-' . date('Ymd-His') . '.json';
    file_put_contents($file, backup_json());
    $r = tg_api('sendDocument', [
        'chat_id' => $s['tg_chat_id'],
        'caption' => $caption ?: ('📦 نسخة احتياطية - ' . date('Y-m-d H:i') . "\nلاستعادتها: أرسل هذا الملف للبوت"),
        'document' => new CURLFile($file, 'application/json', basename($file)),
    ], true);
    // نحتفظ بآخر 10 نسخ محلياً فقط
    $all = glob(pm_dir('backups') . '/wasel-backup-*.json') ?: [];
    sort($all);
    foreach (array_slice($all, 0, max(0, count($all) - 10)) as $old) {
        @unlink($old);
    }
    if (empty($r['ok'])) {
        return [false, 'فشل الإرسال لتيليجرام: ' . ($r['description'] ?? 'تأكد من التوكن ورقم المحادثة')];
    }
    update_settings(['tg_last_backup' => now_str()]);
    return [true, 'تم إرسال النسخة لتيليجرام'];
}

/** رابط webhook الخاص بالبوت (فيه مفتاح سري عشوائي) */
function tg_webhook_url(string $baseUrl): string
{
    $s = settings();
    $key = $s['tg_webhook_key'] ?: bin2hex(random_bytes(12));
    if (!$s['tg_webhook_key']) {
        update_settings(['tg_webhook_key' => $key]);
    }
    return rtrim($baseUrl, '/') . '/telegram/webhook/' . $key;
}

/** رسائل البوت: /backup يرسل نسخة، وإرسال ملف JSON يستورده (من محادثة الأدمن فقط) */
function tg_handle_update(array $u): void
{
    $msg = $u['message'] ?? null;
    $s = settings();
    if (!$msg || (string)($msg['chat']['id'] ?? '') !== (string)$s['tg_chat_id']) {
        return;
    }
    $chat = $s['tg_chat_id'];
    $text = trim((string)($msg['text'] ?? ''));
    // القائمة الرئيسية + أوامر الخدمات
    if (in_array($text, ['/start', '/services', '/خدمات', 'خدمات', '/help'], true)) {
        $lines = array_map(fn($x) => "{$x['emoji']} {$x['label']}: {$x['count']}", svc_list());
        tg_api('sendMessage', ['chat_id' => $chat, 'text' => "📋 خدمات واصل - اختر من الأزرار:\n" . implode("\n", $lines) . "\n\nأو أرسل أي ملف JSON لاستيراده.", 'reply_markup' => json_encode(tg_services_keyboard())]);
        return;
    }
    if (in_array($text, ['🗄️ نسخة كاملة', '/backup', '/export', 'نسخة'], true)) {
        [$ok, $m] = tg_send_backup();
        tg_api('sendMessage', ['chat_id' => $chat, 'text' => ($ok ? '✅ ' : '❌ ') . $m]);
        return;
    }
    if (in_array($text, ['📦 كل الخدمات', '/export_all'], true)) {
        [$ok, $m] = tg_send_all_services();
        tg_api('sendMessage', ['chat_id' => $chat, 'text' => ($ok ? '✅ ' : '❌ ') . $m]);
        return;
    }
    if ($text !== '' && ($svc = tg_match_service($text))) {
        [$ok, $m] = tg_send_service($svc);
        if (!$ok) {
            tg_api('sendMessage', ['chat_id' => $chat, 'text' => '❌ ' . $m]);
        }
        return;
    }
    if (!empty($msg['document'])) {
        $doc = $msg['document'];
        if (!preg_match('/\.json$/i', (string)($doc['file_name'] ?? '')) || ($doc['file_size'] ?? 0) > 40 * 1024 * 1024) {
            tg_api('sendMessage', ['chat_id' => $chat, 'text' => 'أرسل ملف JSON صادر من واصل']);
            return;
        }
        $f = tg_api('getFile', ['file_id' => $doc['file_id']]);
        $path = $f['result']['file_path'] ?? null;
        $content = $path ? @file_get_contents((defined('TG_FILE_BASE') ? TG_FILE_BASE : 'https://api.telegram.org/file') . '/bot' . $s['tg_token'] . '/' . $path) : false;
        if ($content === false) {
            tg_api('sendMessage', ['chat_id' => $chat, 'text' => 'تعذر تنزيل الملف']);
            return;
        }
        [$ok, $m] = svc_detect($content) ? svc_import($content) : backup_import($content);
        tg_api('sendMessage', ['chat_id' => $chat, 'text' => ($ok ? '✅ ' : '❌ ') . $m]);
        return;
    }
    tg_api('sendMessage', ['chat_id' => $chat, 'text' => '📋 خدمات واصل - اختر من الأزرار، أو أرسل ملف JSON لاستيراده.', 'reply_markup' => json_encode(tg_services_keyboard())]);
}
