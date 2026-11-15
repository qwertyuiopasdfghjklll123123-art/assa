<?php
// نقل البيانات من قاعدة SQLite القديمة (app.db الخاص بنسخة بايثون) إلى ملفات JSON.
// التشغيل مرة وحدة من سطر الأوامر (SSH أو Terminal بـ DirectAdmin):
//   php tools/import_sqlite.php /path/to/app.db
// ملاحظة: جلسات واتساب القديمة (مجلد wa_sessions لكروم) ما تنتقل - كل حساب واتساب
// يحتاج مسح رمز QR مرة وحدة من جديد بعد النقل.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$dbPath = $argv[1] ?? '';
if (!is_file($dbPath)) {
    fwrite(STDERR, "الاستخدام: php tools/import_sqlite.php /path/to/app.db\n");
    exit(1);
}
require __DIR__ . '/../config.php';
date_default_timezone_set(APP_TIMEZONE);
require __DIR__ . '/../lib/store.php';
require __DIR__ . '/../lib/engine.php';
require __DIR__ . '/../lib/core.php';

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0700, true);
}
if (store_all('users')) {
    fwrite(STDERR, "يوجد مستخدمين بقاعدة JSON أصلاً - النقل يكون على موقع جديد فارغ فقط.\n");
    exit(1);
}

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$has = fn($t) => (bool)$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . $pdo->quote($t))->fetch();
$b = fn($v) => (bool)(int)$v;

$put = function (string $table, array $rows) {
    $data = ['next_id' => 1, 'rows' => []];
    foreach ($rows as $r) {
        $data['rows'][(string)$r['id']] = $r;
        if (is_int($r['id'])) {
            $data['next_id'] = max($data['next_id'], $r['id'] + 1);
        }
    }
    store_save_raw($table, $data);
    echo "  {$table}: " . count($rows) . "\n";
};

echo "نقل البيانات...\n";
$put('users', array_map(fn($u) => [
    'id' => (int)$u['id'], 'email' => $u['email'] ?: null, 'phone' => $u['phone'] ?: null,
    'name' => $u['name'] !== null ? clean_text($u['name']) : null, 'google_sub' => null,
    'is_admin' => $b($u['is_admin']), 'plan_active' => $b($u['plan_active']),
    'plan_ends_at' => $u['plan_ends_at'] ?? null, 'trial_ends_at' => $u['trial_ends_at'],
    'trial_ended_notified' => $b($u['trial_ended_notified'] ?? 0), 'created_at' => $u['created_at'],
], $pdo->query('SELECT * FROM users')->fetchAll()));

if ($has('payments')) {
    $put('payments', array_map(fn($p) => [
        'id' => (int)$p['id'], 'user_id' => (int)$p['user_id'], 'plan_name' => $p['plan_name'],
        'amount_iqd' => (int)$p['amount_iqd'], 'reference' => $p['reference'],
        'status' => $p['status'], 'created_at' => $p['created_at'],
    ], $pdo->query('SELECT * FROM payments')->fetchAll()));
}

if ($has('subscribers')) {
    $put('subscribers', array_map(fn($s) => [
        'id' => (int)$s['id'], 'owner' => (int)$s['owner'], 'name' => $s['name'], 'phone' => $s['phone'],
        'subscribed_at' => $s['subscribed_at'], 'duration_days' => (int)$s['duration_days'],
        'reminder_sent' => $b($s['reminder_sent']), 'expired_sent' => $b($s['expired_sent']),
        'created_at' => $s['created_at'],
    ], $pdo->query('SELECT * FROM subscribers')->fetchAll()));
}

if ($has('subscription_settings')) {
    $doc = [];
    foreach ($pdo->query('SELECT * FROM subscription_settings')->fetchAll() as $r) {
        $doc[(string)$r['owner']] = [
            'default_duration_days' => (int)$r['default_duration_days'],
            'reminder_days_before' => (int)$r['reminder_days_before'],
            'start_message' => $r['start_message'] ?? '', 'reminder_message' => $r['reminder_message'],
            'expired_message' => $r['expired_message'],
        ];
    }
    store_save_raw('subscription_settings', $doc);
    echo "  subscription_settings: " . count($doc) . "\n";
}

if ($has('api_keys')) {
    $put('api_keys', array_map(fn($k) => [
        'id' => (int)$k['id'], 'owner' => (int)$k['owner'], 'account_id' => $k['account_id'],
        'label' => $k['label'], 'key_hash' => $k['key_hash'], 'key_prefix' => $k['key_prefix'],
        'created_at' => $k['created_at'], 'last_used_at' => $k['last_used_at'], 'revoked' => $b($k['revoked']),
    ], $pdo->query('SELECT * FROM api_keys')->fetchAll()));
}

if ($has('wa_accounts')) {
    $rows = array_map(fn($a) => [
        'id' => $a['id'], 'owner' => (int)$a['owner'], 'name' => $a['name'],
        'auto_reply_enabled' => $b($a['auto_reply_enabled']), 'auto_reply_ai_enabled' => $b($a['auto_reply_ai_enabled']),
        'auto_reply_rules' => json_decode($a['auto_reply_rules'] ?: '[]', true) ?: [],
        'otp_sender' => $b($a['otp_sender']), 'created_at' => $a['created_at'],
    ], $pdo->query('SELECT * FROM wa_accounts')->fetchAll());
    $put('wa_accounts', $rows);
    // تسجيل الحسابات بالمحرك (لو شغّال) حتى تظهر رموز QR لها مباشرة
    foreach ($rows as $a) {
        $r = engine_json('POST', '/accounts', ['id' => $a['id'], 'owner' => $a['owner'], 'name' => $a['name']]);
        if (!empty($r['ok'])) {
            engine_sync_account($a);
        } else {
            echo "  تنبيه: المحرك غير متصل - الحساب {$a['name']} يحتاج إعادة إضافة من الموقع\n";
        }
    }
}

$settings = [];
if ($has('ai_settings') && ($r = $pdo->query('SELECT * FROM ai_settings WHERE id = 1')->fetch())) {
    $settings['ai_api_key'] = $r['api_key'] ?? '';
    $settings['ai_knowledge_base'] = $r['knowledge_base'] ?? '';
}
if ($has('site_settings') && ($r = $pdo->query('SELECT * FROM site_settings WHERE id = 1')->fetch())) {
    $settings += [
        'site_name' => $r['site_name'] ?? '', 'default_country_code' => $r['default_country_code'] ?? '964',
        'whatsapp_subscribe_number' => $r['whatsapp_subscribe_number'] ?? '',
        'whatsapp_support_number' => $r['whatsapp_support_number'] ?? '',
        'plan_price_iqd' => $r['plan_price_iqd'] ?? null, 'port' => $r['port'] ?? null, 'domain' => $r['domain'] ?? '',
    ];
    if (!empty($r['logo']) && preg_match('#^data:(image/[\w.+-]+);base64,(.+)$#s', $r['logo'], $m)) {
        file_put_contents(logo_path(), base64_decode($m[2]));
        $settings['has_logo'] = true;
        $settings['logo_mime'] = $m[1];
    }
}
update_settings($settings);
echo "  settings: تم\n";
echo "اكتمل النقل. حسابات واتساب تحتاج مسح رمز QR مرة وحدة من جديد.\n";
