<?php
/**
 * منصة واصل (نسخة PHP) - كل طلبات الموقع تمر من هنا (.htaccess يحوّلها لهذا الملف).
 * البيانات تنحفظ بملفات JSON داخل مجلد data/، وجلسات واتساب يديرها محرك Node.js منفصل.
 */

require __DIR__ . '/config.php';
date_default_timezone_set(APP_TIMEZONE);
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/engine.php';
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/pages.php';
require __DIR__ . '/lib/xlsx.php';
require __DIR__ . '/lib/tasks.php';
require __DIR__ . '/lib/wallet.php';
require __DIR__ . '/lib/backup.php';
require __DIR__ . '/lib/api.php';
require __DIR__ . '/lib/leads.php';
require __DIR__ . '/lib/drip.php';
require __DIR__ . '/lib/coupons.php';
require __DIR__ . '/lib/inbox.php';
require __DIR__ . '/lib/security.php';

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0700, true);
}

$method = $_SERVER['REQUEST_METHOD'];
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
// لو الموقع مرفوع داخل مجلد فرعي بدل جذر الدومين
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
if ($base !== '' && strpos($path, $base) === 0) {
    $path = substr($path, strlen($base));
}
$path = '/' . trim($path, '/');

// ------------------------------------------------ حماية: أخطاء عامة، HTTPS، ترويسات أمان، CSRF
security_bootstrap();
csrf_check($method, $path);

// ضغط الصفحات والبيانات (gzip) - يقلل الحجم 70-80% ويسرّع التحميل على الإنترنت البطيء
if (extension_loaded('zlib') && !ini_get('zlib.output_compression') && stripos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false
    && !preg_match('#^/(assets/|pm-image/|branding/|pwa-|dev-photo|receipt|api/backup|backup|auth/bootstrap/qr|accounts/[^/]+/qr)#', $path)) {
    ob_start('ob_gzhandler');
}

// مهام مجدولة تلقائية مع زيارات الموقع (بديل Cron): كل 5 دقائق كحد أقصى، بعد إرسال الرد للزائر
$finishFn = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : null);
if (APP_INSTALLED && $method === 'GET' && $finishFn) {
    $lastCron = (int)(settings()['last_cron'] ?? 0);
    if (time() - $lastCron > 300) {
        register_shutdown_function(function () use ($finishFn) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            $finishFn();
            run_scheduled_tasks();
        });
    }
}

// مسارات ما تحتاج جلسة (المحرك، Cron، API الخارجي)
if ($path === '/engine_hook' && $method === 'POST') {
    handle_engine_hook();
}
if ($path === '/cron') {
    if (CRON_KEY === '' || !hash_equals(CRON_KEY, (string)($_GET['key'] ?? ''))) {
        json_err('unauthorized', 401);
    }
    json_out(run_scheduled_tasks());
}
if ($path === '/api/v1/send' && $method === 'POST') {
    handle_api_send();
}
// تنصيب المهام المجدولة برابط: افتحه مرة بالمتصفح
if ($path === '/cron/setup') {
    if (CRON_KEY === '' || !hash_equals(CRON_KEY, (string)($_GET['key'] ?? ''))) {
        http_response_code(403);
        exit('رابط غير صالح');
    }
    cron_setup_page();
}
if ($path === '/dev-photo') {
    $f = DATA_DIR . '/dev.bin';
    if (!is_file($f)) {
        http_response_code(404);
        exit;
    }
    $info = @getimagesize($f);
    header('Content-Type: ' . ($info['mime'] ?? 'image/png'));
    header('Cache-Control: public, max-age=86400');
    readfile($f);
    exit;
}
start_session();
enforce_session();

// الموقع لسا ما تنصّب: كل الصفحات تروح لصفحة التنصيب
if (!APP_INSTALLED && $method === 'GET' && !preg_match('#^/(branding/logo|icon\.svg|pwa-\d+\.png|manifest\.json|app\.webmanifest|sw\.js|assets/.+)$#', $path)) {
    redirect_to(($base ?: '') . '/install.php');
}

$routes = [
    // ------------------------------------------------ الصفحات
    ['GET', '#^/$#', function () {
        if (uid()) {
            redirect_to('/app');
        }
        echo render_welcome_page();
    }],
    ['GET', '#^/welcome$#', function () {
        if (uid()) {
            redirect_to('/app');
        }
        echo render_welcome_page();
    }],
    ['GET', '#^/(login|signup)$#', fn() => redirect_to('/')],
    ['GET', '#^/(privacy|terms|security-policy)$#', function () {
        [$siteName] = branding_context();
        header('Content-Type: text/html; charset=utf-8');
        echo strtr(view_file('privacy.html'), ['__SITE_NAME__' => $siteName, '__UPDATED__' => date('Y-m-d', (int)filemtime(__DIR__ . '/views/privacy.html'))]);
    }],
    ['GET', '#^/app(?:/([a-z0-9-]+))?$#', function ($section = '') {
        if (!uid() || !user_by_id(uid())) {
            $_SESSION = [];
            redirect_to('/');
        }
        // المستخدم العادي يشوف /app فقط - الأدمن كل صفحة برابطها المباشر (/users، /settings...)
        if ($section !== '') {
            redirect_to(empty($_SESSION['is_admin']) ? '/app' : '/' . $section);
        }
        echo render_app_page('');
    }],
    ['GET', '#^/(' . implode('|', APP_SLUGS) . ')$#', function ($section) {
        if (!uid() || !user_by_id(uid())) {
            $_SESSION = [];
            redirect_to('/');
        }
        if (empty($_SESSION['is_admin'])) {
            redirect_to('/app');
        }
        echo render_app_page($section);
    }],
    ['POST', '#^/logout$#', function () {
        $_SESSION = [];
        session_destroy();
        redirect_to('/');
    }],
    ['GET', '#^/(?:manifest\.json|app\.webmanifest)$#', function () {
        [$siteName] = branding_context();
        $name = html_entity_decode($siteName);
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name' => $name, 'short_name' => $name, 'id' => '/app', 'start_url' => '/app', 'scope' => '/',
            'display' => 'standalone', 'background_color' => '#f3f7f8', 'theme_color' => '#f3f7f8',
            'dir' => 'rtl', 'lang' => 'ar', 'description' => 'حملات واتساب، رد آلي، ورسائل اشتراكات',
            'icons' => [
                ['src' => '/pwa-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/pwa-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }],
    ['GET', '#^/pwa-(192|512)\.png$#', 'serve_pwa_icon'],
    ['GET', '#^/assets/(ui\.css|app\.js|logo(?:-(?:gold|violet|rose))?\.(?:png|webp)|vendor/[a-z0-9-]+\.(?:css|woff2))$#', 'serve_asset'],
    ['POST', '#^/api/me/ui$#', function () {
        $id = require_login();
        $p = ui_clean(request_json());
        update_user($id, ['ui' => $p]);
        json_out(['ok' => true, 'ui' => $p]);
    }],
    ['GET', '#^/offline$#', function () {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');
        echo strtr(view_file('offline.html'), ['__SITE_NAME__' => branding_context()[0]]);
    }],
    ['GET', '#^/icon\.svg$#', fn() => serve_pwa_icon('192')],
    ['GET', '#^/sw\.js$#', function () {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: no-cache');
        header('Service-Worker-Allowed: /');
        echo view_file('sw.js');
    }],
    ['GET', '#^/branding/logo$#', function () {
        [, $hasLogo] = branding_context();
        if (!$hasLogo) {
            http_response_code(404);
            return;
        }
        header('Content-Type: ' . (settings()['logo_mime'] ?: 'image/png'));
        header('Cache-Control: public, max-age=300');
        readfile(logo_path());
    }],

    // ------------------------------------------------ تسجيل الدخول
    ['POST', '#^/auth/google$#', 'handle_google_login'],
    ['POST', '#^/auth/email/login$#', 'handle_email_login'],
    ['POST', '#^/auth/whatsapp/send_code$#', 'handle_send_code'],
    ['GET', '#^/auth/whatsapp/send_status$#', 'handle_send_status'],
    ['POST', '#^/auth/whatsapp/verify$#', 'handle_verify_code'],
    ['GET', '#^/auth/bootstrap/qr/([\w-]+)$#', 'handle_bootstrap_qr'],
    ['GET', '#^/auth/bootstrap/status/([\w-]+)$#', 'handle_bootstrap_status'],
    ['POST', '#^/auth/set_name$#', function () {
        $id = require_login();
        $name = trim((string)(request_json()['name'] ?? ''));
        if ($name === '') {
            json_err('أدخل اسمك');
        }
        $name = clean_text(mb_substr($name, 0, 60));
        update_user($id, ['name' => $name]);
        $_SESSION['name'] = $name;
        json_out(['ok' => true]);
    }],

    // ------------------------------------------------ الإشعارات
    ['GET', '#^/events$#', function () {
        $id = require_login();
        $since = (int)($_GET['since'] ?? 0);
        $last = $_SESSION['trial_checked_at'] ?? 0;
        if (time() - $last >= 60) {
            $_SESSION['trial_checked_at'] = time();
            if ($u = user_by_id($id)) {
                check_trial_ended_event($u);
            }
        }
        json_out(store_where('events', fn($e) => (int)$e['owner'] === $id && (int)$e['id'] > $since));
    }],
    ['POST', '#^/events/(\d+)/read$#', function ($eid) {
        $id = require_login();
        $ok = store_update('events', $eid, fn($e) => (int)$e['owner'] === $id ? array_merge($e, ['read' => true]) : $e);
        $ok ? json_out(['ok' => true]) : json_err('غير موجود', 404);
    }],
    ['POST', '#^/events/read_all$#', function () {
        $id = require_login();
        store_update_all('events', function (array &$rows) use ($id) {
            foreach ($rows as $k => $e) {
                if ((int)$e['owner'] === $id) {
                    $rows[$k]['read'] = true;
                }
            }
        });
        json_out(['ok' => true]);
    }],
    ['DELETE', '#^/events/(\d+)$#', function ($eid) {
        $id = require_login();
        $e = store_find('events', $eid);
        if (!$e || (int)$e['owner'] !== $id) {
            json_err('غير موجود', 404);
        }
        store_delete('events', $eid);
        json_out(['ok' => true]);
    }],

    // ------------------------------------------------ إعدادات الأدمن
    ['GET', '#^/admin/ai_settings$#', function () {
        require_admin();
        $s = settings();
        json_out(['api_key_set' => $s['ai_api_key'] !== '', 'knowledge_base' => $s['ai_knowledge_base']]);
    }],
    ['POST', '#^/admin/ai_settings$#', function () {
        require_admin();
        $d = request_json();
        $key = trim((string)($d['api_key'] ?? ''));
        $fields = ['ai_knowledge_base' => (string)($d['knowledge_base'] ?? '')];
        if ($key !== '') {
            $fields['ai_api_key'] = $key;
        }
        update_settings($fields);
        json_out(['ok' => true]);
    }],
    ['GET', '#^/admin/site_settings$#', function () {
        require_admin();
        $s = settings();
        json_out(['port' => $s['port'], 'domain' => $s['domain']]);
    }],
    ['POST', '#^/admin/site_settings$#', function () {
        require_admin();
        $d = request_json();
        $port = null;
        if (($d['port'] ?? '') !== '' && $d['port'] !== null) {
            if (!ctype_digit((string)$d['port']) || (int)$d['port'] < 1 || (int)$d['port'] > 65535) {
                json_err('رقم المنفذ لازم يكون بين 1 و65535');
            }
            $port = (int)$d['port'];
        }
        update_settings(['port' => $port, 'domain' => trim((string)($d['domain'] ?? ''))]);
        json_out(['ok' => true]);
    }],
    ['GET', '#^/admin/branding$#', function () {
        require_admin();
        $s = settings();
        [, $hasLogo] = branding_context();
        json_out([
            'has_logo' => $hasLogo,
            'site_name' => $s['site_name'],
            'default_country_code' => default_country_code(),
            'whatsapp_subscribe_number' => $s['whatsapp_subscribe_number'] ?: WHATSAPP_PAY_NUMBER,
            'whatsapp_support_number' => $s['whatsapp_support_number'] ?: WHATSAPP_PAY_NUMBER,
            'plan_price_iqd' => plan_price(),
        ]);
    }],
    ['POST', '#^/admin/branding$#', 'handle_set_branding'],

    // ------------------------------------------------ الاشتراك والدفع
    ['GET', '#^/subscription$#', 'handle_get_subscription'],
    ['POST', '#^/subscription/pay$#', function () {
        $id = require_login();
        $ref = trim((string)(request_json()['reference'] ?? ''));
        if ($ref === '') {
            json_err('أدخل رقم إثبات الدفع/التحويل');
        }
        store_insert('payments', [
            'user_id' => $id, 'plan_name' => PLAN_NAME, 'amount_iqd' => plan_price(),
            'reference' => clean_text(mb_substr($ref, 0, 120)), 'status' => 'pending', 'created_at' => now_str(),
        ]);
        json_out(['ok' => true]);
    }],

    // ------------------------------------------------ مشتركي المستخدم
    ['GET', '#^/subscribers$#', 'handle_list_subscribers'],
    ['POST', '#^/subscribers$#', 'handle_create_subscriber'],
    ['POST', '#^/subscribers/(\d+)/delete$#', function ($sid) {
        $id = require_login();
        $s = store_find('subscribers', $sid);
        if ($s && (int)$s['owner'] === $id) {
            store_delete('subscribers', $sid);
        }
        json_out(['ok' => true]);
    }],
    ['POST', '#^/subscribers/(\d+)/renew$#', 'handle_renew_subscriber'],
    ['GET', '#^/subscription_settings$#', function () {
        json_out(subscription_settings(require_login()));
    }],
    ['POST', '#^/subscription_settings$#', 'handle_set_subscription_settings'],

    // ------------------------------------------------ لوحة الأدمن
    ['GET', '#^/admin/customers$#', function () {
        require_admin();
        $rows = [];
        foreach (store_all('users') as $u) {
            $rows[] = [
                'id' => $u['id'], 'email' => $u['email'], 'phone' => $u['phone'], 'name' => $u['name'],
                'is_admin' => !empty($u['is_admin']) ? 1 : 0, 'plan_active' => effective_plan_active($u),
                'plan_ends_at' => $u['plan_ends_at'], 'trial_ends_at' => $u['trial_ends_at'],
                'created_at' => $u['created_at'], 'login' => !empty($u['google_sub']) ? 'google' : (!empty($u['password_hash']) ? 'email' : 'whatsapp'),
            ];
        }
        json_out($rows);
    }],
    ['POST', '#^/admin/customers/(\d+)/plan$#', 'handle_admin_set_plan'],
    ['POST', '#^/admin/announcement/([\w-]+)$#', 'handle_announcement'],
    ['GET', '#^/admin/payments$#', function () {
        require_admin();
        $out = [];
        foreach (store_where('payments', fn($p) => $p['status'] === 'pending') as $p) {
            $u = user_by_id((int)$p['user_id']);
            $p['email'] = $u ? ($u['email'] ?: $u['phone']) : null;
            $out[] = $p;
        }
        json_out($out);
    }],
    ['POST', '#^/admin/payments/(\d+)/(approve|reject)$#', function ($pid, $action) {
        require_admin();
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $p = store_update('payments', $pid, fn($p) => array_merge($p, ['status' => $status]));
        if ($p && $status === 'approved') {
            update_user((int)$p['user_id'], ['plan_active' => true]);
            add_event((int)$p['user_id'], null, 'تم تفعيل الاشتراك', 'تم قبول دفعتك وتفعيل خطتك بنجاح', 'success');
        }
        json_out(['ok' => (bool)$p]);
    }],

    // ------------------------------------------------ الإحصائيات
    ['GET', '#^/dashboard/stats$#', function () {
        $id = require_login();
        $mine = wa_accounts_of($id);
        $live = engine_accounts();
        $hist = history_of_owner($id);
        $sent = array_sum(array_column($hist, 'sent'));
        $failed = array_sum(array_column($hist, 'failed'));
        $total = $sent + $failed;
        json_out([
            'accounts_total' => count($mine),
            'accounts_connected' => count(array_filter($mine, fn($a) => !empty($live[$a['id']]['logged_in']))),
            'messages_sent' => $sent,
            'success_rate' => $total ? (int)round($sent / $total * 100) : 0,
            'campaigns_total' => count($hist),
            'campaigns_success' => count(array_filter($hist, fn($h) => (int)$h['failed'] === 0)),
            'campaigns_with_failures' => count(array_filter($hist, fn($h) => (int)$h['failed'] > 0)),
        ]);
    }],
    ['GET', '#^/dashboard/campaigns$#', function () {
        $id = require_login();
        json_out(array_map('history_public', array_slice(history_of_owner($id), 0, 20)));
    }],
    // تفاصيل حملة كاملة (النص الكامل، الأوقات، الأرقام)
    ['GET', '#^/dashboard/campaigns/(\d+)$#', function ($hid) {
        $id = require_login();
        $h = store_find('history', (int)$hid);
        if (!$h || (int)$h['owner'] !== $id) {
            json_err('التقرير غير موجود', 404);
        }
        $l = track_lists($h['lists'] ?? []);
        json_out(['ok' => true, 'report' => history_public($h) + ['text_full' => (string)($h['text_full'] ?? $h['text'] ?? ''),
            'counts' => array_map('count', $l) + ['failed' => count($h['failed_numbers'] ?? [])]]]);
    }],
    // أرقام كل فئة بتقرير حملة (مين رد، مين شاهد بدون رد...)
    ['GET', '#^/dashboard/campaigns/(\d+)/numbers$#', function ($hid) {
        $id = require_login();
        $h = store_find('history', (int)$hid);
        if (!$h || (int)$h['owner'] !== $id) {
            json_err('التقرير غير موجود', 404);
        }
        $l = track_lists($h['lists'] ?? []);
        $l['failed'] = digit_list($h['failed_numbers'] ?? []);
        json_out(['ok' => true, 'lists' => $l]);
    }],
    ['GET', '#^/dashboard/running_campaigns$#', function () {
        $id = require_login();
        $live = engine_accounts();
        $rows = [];
        foreach (wa_accounts_of($id) as $a) {
            $l = $live[$a['id']] ?? null;
            if ($l && (!empty($l['campaign_running']) || !empty($l['campaign_paused']))) {
                $st = engine_json('GET', '/accounts/' . rawurlencode($a['id']) . '/campaign');
                unset($st['_status']);
                $rows[] = ['account_id' => $a['id'], 'account_name' => $a['name']] + $st;
            }
        }
        json_out($rows);
    }],

    // ------------------------------------------------ حسابات واتساب
    ['GET', '#^/accounts$#', function () {
        $id = require_login();
        $live = engine_accounts();
        json_out(array_map(fn($a) => [
            'id' => $a['id'], 'name' => $a['name'],
            'logged_in' => !empty($live[$a['id']]['logged_in']),
            'otp_sender' => !empty($a['otp_sender']),
        ], wa_accounts_of($id)));
    }],
    ['POST', '#^/accounts$#', function () {
        $id = require_login();
        $lim = user_limits(user_by_id($id));
        if ($lim['numbers'] > 0 && count(wa_accounts_of($id)) >= $lim['numbers']) {
            json_err(numbers_limit_error($lim), 403, ['limit' => true]);
        }
        $name = trim((string)(request_json()['name'] ?? ''));
        $accId = bin2hex(random_bytes(4));
        if ($name === '') {
            $name = 'حساب ' . (count(wa_accounts_of($id)) + 1);
        }
        $acc = [
            'id' => $accId, 'owner' => $id, 'name' => clean_text(mb_substr($name, 0, 60)),
            'auto_reply_enabled' => false, 'auto_reply_ai_enabled' => false, 'auto_reply_rules' => [],
            'otp_sender' => false, 'created_at' => now_str(),
        ];
        $r = engine_json('POST', '/accounts', ['id' => $accId, 'owner' => $id, 'name' => $acc['name']]);
        if (empty($r['ok'])) {
            json_err($r['error'] ?? 'تعذر إنشاء الحساب', 503);
        }
        store_insert('wa_accounts', $acc);
        json_out(['id' => $accId, 'name' => $acc['name']]);
    }],
    ['POST', '#^/accounts/([\w-]+)/logout$#', function ($accId) {
        require_login();
        if (!owned_account($accId)) {
            json_err('حساب غير موجود', 404);
        }
        delete_wa_account($accId);
        json_out(['ok' => true]);
    }],
    ['POST', '#^/settings/delete_my_data$#', function () {
        $id = require_login();
        $mine = wa_accounts_of($id);
        foreach ($mine as $a) {
            delete_wa_account($a['id']);
        }
        json_out(['ok' => true, 'deleted' => count($mine)]);
    }],
    ['POST', '#^/accounts/([\w-]+)/otp_sender$#', function ($accId) {
        require_admin();
        if (!owned_account($accId)) {
            json_err('حساب غير موجود', 404);
        }
        $enabled = !empty(request_json()['enabled']);
        store_update_all('wa_accounts', function (array &$rows) use ($accId, $enabled) {
            foreach ($rows as $k => $a) {
                if ($enabled) {
                    $rows[$k]['otp_sender'] = ($a['id'] === $accId);
                } elseif ($a['id'] === $accId) {
                    $rows[$k]['otp_sender'] = false;
                }
            }
        });
        engine_request('POST', '/accounts/' . rawurlencode($accId) . '/config', ['otp_sender' => $enabled], 8);
        json_out(['ok' => true]);
    }],
    ['GET', '#^/accounts/([\w-]+)/qr$#', function ($accId) {
        require_login();
        $acc = owned_account($accId);
        if (!$acc) {
            http_response_code(204);
            return;
        }
        // حساب محفوظ بالموقع لكن المحرك ما يعرفه (منقول من النسخة القديمة أو المحرك انمسح) - نسجّله
        if (!isset(engine_accounts()[$accId])) {
            engine_request('POST', '/accounts', ['id' => $accId, 'owner' => (int)$acc['owner'], 'name' => $acc['name']], 8);
            engine_sync_account($acc);
        }
        output_engine_qr($accId);
    }],
    ['GET', '#^/accounts/([\w-]+)/status$#', function ($accId) {
        require_login();
        if (!owned_account($accId)) {
            json_out(['logged_in' => false]);
        }
        $r = engine_json('GET', '/accounts/' . rawurlencode($accId) . '/status', null, 6);
        json_out(['logged_in' => !empty($r['logged_in']), 'status' => $r['status'] ?? 'offline']);
    }],
    ['GET', '#^/accounts/([\w-]+)/auto_reply$#', function ($accId) {
        require_login();
        $a = owned_account($accId);
        if (!$a) {
            json_err('حساب غير موجود', 404);
        }
        json_out([
            'enabled' => !empty($a['auto_reply_enabled']),
            'ai_enabled' => !empty($a['auto_reply_ai_enabled']),
            'rules' => $a['auto_reply_rules'] ?? [],
            'ai_profile' => $a['ai_profile'] ?? new stdClass(),
            'ai_fields' => array_map(fn($f) => ['label' => $f[0], 'max' => $f[1], 'required' => $f[2] > 0], AI_PROFILE_FIELDS),
            'ai_available' => !empty(settings()['ai_api_key']),
        ]);
    }],
    ['POST', '#^/accounts/([\w-]+)/auto_reply$#', 'handle_set_auto_reply'],

    // ------------------------------------------------ الحملات
    ['POST', '#^/campaign/preview_numbers$#', function () {
        require_login();
        $f = $_FILES['numbers_file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['name'] === '') {
            json_err('ما فيه ملف');
        }
        try {
            json_out(['ok' => true, 'numbers' => numbers_from_upload($f['tmp_name'], $f['name'])]);
        } catch (Throwable $e) {
            json_err('تعذرت قراءة الملف - تأكد إنه ملف Excel صالح (.xlsx) أو CSV');
        }
    }],
    ['POST', '#^/accounts/([\w-]+)/campaign$#', 'handle_start_campaign'],
    ['GET', '#^/accounts/([\w-]+)/campaign_status$#', function ($accId) {
        require_login();
        $empty = ['total' => 0, 'sent' => 0, 'failed' => 0, 'running' => false, 'failed_numbers' => [],
            'scheduled_for' => null, 'pending_numbers' => [], 'paused' => false];
        if (!owned_account($accId)) {
            json_out($empty);
        }
        $r = engine_json('GET', '/accounts/' . rawurlencode($accId) . '/campaign', null, 6);
        if (($r['_status'] ?? 0) !== 200) {
            json_out($empty);
        }
        unset($r['_status']);
        json_out($r);
    }],
    ['GET', '#^/accounts/([\w-]+)/campaigns$#', function ($accId) {
        $id = require_login();
        if (!owned_account($accId)) {
            json_out([]);
        }
        $rows = array_filter(history_of_owner($id), fn($h) => $h['account_id'] === $accId);
        json_out(array_map('history_public', array_values($rows)));
    }],
    ['POST', '#^/accounts/([\w-]+)/campaign/(stop|cancel|resume|exclude)$#', function ($accId, $action) {
        require_login();
        if (!owned_account($accId)) {
            json_err('حساب غير موجود');
        }
        $body = $action === 'exclude' ? ['number' => digits_only(request_json()['number'] ?? '')] : [];
        $r = engine_json('POST', '/accounts/' . rawurlencode($accId) . '/campaign/' . $action, $body);
        $status = $r['_status'] ?? 200;
        unset($r['_status']);
        json_out($r, $status === 200 ? 200 : ($status ?: 503));
    }],

    // ------------------------------------------------ مفاتيح API
    ['GET', '#^/api_keys$#', function () {
        $id = require_login();
        $live = engine_accounts();
        $keys = [];
        foreach (array_reverse(store_where('api_keys', fn($k) => (int)$k['owner'] === $id)) as $k) {
            $keys[] = [
                'id' => $k['id'], 'label' => $k['label'], 'account_id' => $k['account_id'],
                'key_prefix' => $k['key_prefix'], 'created_at' => $k['created_at'],
                'last_used_at' => $k['last_used_at'], 'revoked' => !empty($k['revoked']),
                'account_connected' => !empty($live[$k['account_id']]['logged_in']),
            ];
        }
        json_out(['plan_active' => effective_plan_active(user_by_id($id)), 'keys' => $keys]);
    }],
    ['POST', '#^/api_keys$#', function () {
        $id = require_login();
        $me = user_by_id($id);
        if (!effective_plan_active($me) && !(message_price() > 0 && (int)($me['balance'] ?? 0) > 0) && empty($me['is_admin'])) {
            json_err('مفاتيح API تحتاج اشتراك مفعّل أو رصيد (مو فترة تجربة)', 402, ['needs_subscription' => true]);
        }
        $d = request_json();
        $accId = (string)($d['account_id'] ?? '');
        if (!owned_account($accId)) {
            json_err('اختر حساب واتساب صحيح تملكه');
        }
        if (!engine_logged_in($accId)) {
            json_err('اختر حساب واتساب متصل حالياً - ما يمكن توليد مفتاح لحساب غير متصل');
        }
        $raw = 'wsl_' . bin2hex(random_bytes(24));
        store_insert('api_keys', [
            'owner' => $id, 'account_id' => $accId, 'label' => clean_text(mb_substr(trim((string)($d['label'] ?? '')), 0, 60)),
            'key_hash' => hash('sha256', $raw), 'key_prefix' => substr($raw, 0, 12),
            'created_at' => now_str(), 'last_used_at' => null, 'revoked' => false,
        ]);
        json_out(['ok' => true, 'key' => $raw]);
    }],
    ['DELETE', '#^/api_keys/(\d+)$#', function ($kid) {
        $id = require_login();
        store_update('api_keys', $kid, fn($k) => (int)$k['owner'] === $id ? array_merge($k, ['revoked' => true]) : $k);
        json_out(['ok' => true]);
    }],
];

$routes[] = ['POST', '#^/api/admin/notify$#', function () {
    require_admin();
    $d = request_json();
    $title = clean_text(mb_substr(trim((string)($d['title'] ?? '')), 0, 80));
    $body = clean_text(mb_substr(trim((string)($d['body'] ?? '')), 0, 500));
    if ($title === '' || $body === '') {
        json_err('اكتب عنوان ونص الإعلان');
    }
    $links = ['wallet', 'campaign', 'leads', 'accounts', 'reports', 'apikeys'];
    $link = in_array($d['link'] ?? '', $links, true) ? $d['link'] : null;
    $aud = (string)($d['audience'] ?? 'all');
    $owners = [];
    foreach (store_all('users') as $u) {
        if (!empty($u['banned'])) {
            continue;
        }
        $k = user_limits($u)['kind'];
        if ($aud === 'all' || ($aud === 'plan' && $k === 'plan') || ($aud === 'trial' && $k === 'trial') || ($aud === 'free' && in_array($k, ['payg'], true))) {
            $owners[] = (int)$u['id'];
        }
    }
    $n = add_events_bulk($owners, $title, $body, 'announce', $link);
    admin_log('notify', "{$title} → {$aud} ({$n})");
    json_out(['ok' => true, 'total' => $n]);
}];
$routes = array_merge($routes, api_routes(), leads_routes(), drip_routes(), coupon_routes(), inbox_routes());

foreach ($routes as [$m, $re, $fn]) {
    if ($m !== $method || !preg_match($re, $path, $mm)) {
        continue;
    }
    array_shift($mm);
    $fn(...$mm);
    exit;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo 'الصفحة غير موجودة';

/** الملفات الثابتة: تخزين سنة بالمتصفح (الرابط فيه رقم نسخة)، ETag، وضغط gzip للنصوص */
function serve_asset(string $f): void
{
    $file = __DIR__ . '/views/' . $f;
    if (strpos($f, '..') !== false || !is_file($file)) {
        http_response_code(404);
        return;
    }
    $ext = pathinfo($f, PATHINFO_EXTENSION);
    $types = ['css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'png' => 'image/png', 'webp' => 'image/webp', 'woff2' => 'font/woff2'];
    $etag = '"' . base_convert((string)filemtime($file), 10, 36) . '-' . base_convert((string)filesize($file), 10, 36) . '"';
    header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: ' . (isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'public, max-age=3600'));
    header('ETag: ' . $etag);
    header('Vary: Accept-Encoding');
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        return;
    }
    if ($ext === 'css' || $ext === 'js') {
        $body = str_replace('__V__', asset_version(), (string)file_get_contents($file));
        if (extension_loaded('zlib') && !ini_get('zlib.output_compression') && stripos((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') !== false) {
            $body = gzencode($body, 6);
            header('Content-Encoding: gzip');
        }
        header('Content-Length: ' . strlen($body));
        echo $body;
        return;
    }
    header('Content-Length: ' . filesize($file));
    readfile($file);
}

