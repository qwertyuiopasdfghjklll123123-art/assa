<?php
/**
 * الدوال المشتركة: الجلسة، المستخدمين، الاشتراكات، الإشعارات، الهوية البصرية.
 */

const PLAN_NAME = 'الخطة الاحترافية';
const PLAN_PRICE_IQD = 60000;
const TRIAL_DAYS = 3;
const PLAN_ACTIVATION_DAYS = 30;
const WHATSAPP_PAY_NUMBER = '9647763835403';
const DEFAULT_MESSAGE = 'هلوو';
const DEFAULT_DELAY = 15;
const DEFAULT_MEDIA_DELAY = 2;
const EVENTS_PER_USER = 50;
const HISTORY_PER_ACCOUNT = 20;
const API_RATE_LIMIT_PER_MIN = 20;
const DEFAULT_COUNTRY_CODE_FALLBACK = '964';
const MAX_LOGO_B64_CHARS = 2 * 1024 * 1024 * 4 / 3 + 100;

const DEFAULT_START_MESSAGE = 'مرحباً {name}، تم تفعيل اشتراكك بنجاح لمدة {days} يوم.';
const DEFAULT_REMINDER_MESSAGE = 'مرحباً {name}، اشتراكك سينتهي خلال {days} أيام. تواصل معنا للتجديد.';
const DEFAULT_EXPIRED_MESSAGE = 'مرحباً {name}، اشتراكك انتهى اليوم. تواصل معنا للتجديد.';

const COUNTRY_CODES = [
    ['20', '🇪🇬 +20'], ['212', '🇲🇦 +212'], ['213', '🇩🇿 +213'], ['216', '🇹🇳 +216'],
    ['218', '🇱🇾 +218'], ['90', '🇹🇷 +90'], ['91', '🇮🇳 +91'], ['92', '🇵🇰 +92'],
    ['93', '🇦🇫 +93'], ['94', '🇱🇰 +94'], ['95', '🇲🇲 +95'], ['960', '🇲🇻 +960'],
    ['961', '🇱🇧 +961'], ['962', '🇯🇴 +962'], ['963', '🇸🇾 +963'], ['964', '🇮🇶 +964'],
    ['965', '🇰🇼 +965'], ['966', '🇸🇦 +966'], ['967', '🇾🇪 +967'], ['968', '🇴🇲 +968'],
    ['970', '🇵🇸 +970'], ['971', '🇦🇪 +971'], ['972', '🇮🇱 +972'], ['973', '🇧🇭 +973'],
    ['974', '🇶🇦 +974'], ['975', '🇧🇹 +975'], ['976', '🇲🇳 +976'], ['977', '🇳🇵 +977'],
    ['98', '🇮🇷 +98'], ['262', '🇾🇹 +262'], ['992', '🇹🇯 +992'], ['993', '🇹🇲 +993'],
    ['994', '🇦🇿 +994'], ['995', '🇬🇪 +995'], ['996', '🇰🇬 +996'], ['998', '🇺🇿 +998'],
    ['1', '🇺🇸 +1'], ['44', '🇬🇧 +44'], ['49', '🇩🇪 +49'], ['33', '🇫🇷 +33'],
    ['39', '🇮🇹 +39'], ['34', '🇪🇸 +34'], ['7', '🇷🇺 +7'], ['86', '🇨🇳 +86'],
    ['81', '🇯🇵 +81'], ['82', '🇰🇷 +82'], ['60', '🇲🇾 +60'], ['62', '🇮🇩 +62'],
    ['63', '🇵🇭 +63'], ['64', '🇳🇿 +64'], ['61', '🇦🇺 +61'],
];

// ---------------------------------------------------------------- أدوات عامة

function now_str(): string
{
    return date('Y-m-d H:i');
}

function parse_dt(?string $s): ?int
{
    if (!$s) {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i', $s);
    return $dt ? $dt->getTimestamp() : null;
}

/**
 * الواجهة تعرض الأسماء والإشعارات داخل HTML مباشرة، فنستبدل الرموز الخطرة بأشباهها
 * (حتى ما يقدر أحد يحقن كود عبر اسمه مثلاً ويظهر بلوحة الأدمن)
 */
function clean_text($s): string
{
    return strtr((string)$s, ['<' => '‹', '>' => '›', '"' => '”', "'" => '’', '`' => '‘']);
}

/** (5) تشفير كلمات المرور ورموز التحقق: Argon2id إذا متوفر، وإلا bcrypt */
function pw_hash(string $plain): string
{
    return password_hash($plain, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
}

function digits_only($s): string
{
    return preg_replace('/\D/', '', (string)$s);
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $error, int $status = 400, array $extra = []): void
{
    json_out(['ok' => false, 'error' => $error] + $extra, $status);
}

function request_json(): array
{
    static $data = null;
    if ($data === null) {
        $data = json_decode(file_get_contents('php://input') ?: '', true);
        if (!is_array($data)) {
            $data = [];
        }
    }
    return $data;
}

function redirect_to(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

function valid_country_code(?string $code): bool
{
    foreach (COUNTRY_CODES as [$c]) {
        if ($c === $code) {
            return true;
        }
    }
    return false;
}

function apply_country_code(string $number, string $cc): string
{
    if ($number !== '' && $number[0] === '+') {
        return substr($number, 1); // دولي جاهز
    }
    if ($cc === '' || strpos($number, $cc) === 0) {
        return $number;
    }
    if ($number[0] === '0') {
        $number = substr($number, 1);
    }
    return $cc . $number;
}

function fill_subscription_message(string $tpl, string $name, $days): string
{
    return str_replace(['{name}', '{days}'], [$name, (string)$days], $tpl);
}

// ---------------------------------------------------------------- الجلسة

function start_session(): void
{
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $life = 60 * 24 * 3600; // (15) الجلسة تبقى 60 يوم ثم يلزم تسجيل دخول من جديد
    ini_set('session.gc_maxlifetime', (string)$life);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.sid_length', '48');
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    session_name('wasel_sid');
    session_set_cookie_params([
        'lifetime' => $life,
        'path' => '/',
        'secure' => function_exists('is_https') ? is_https() : (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function uid(): ?int
{
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

function require_login(): int
{
    $id = uid();
    if (!$id) {
        json_err('سجّل الدخول أولاً', 401);
    }
    return $id;
}

function require_admin(): int
{
    $id = require_login();
    if (empty($_SESSION['is_admin'])) {
        json_err('هذا الإجراء لأدمن المنصة فقط', 403);
    }
    return $id;
}

function set_session_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['email'] = $user['email'] ?: $user['phone'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['is_admin'] = !empty($user['is_admin']);
    $_SESSION['epoch'] = (int)(settings()['session_epoch'] ?? 1);
    update_user((int)$user['id'], ['last_login_at' => now_str(), 'last_ip' => client_ip_addr()]);
}

function client_ip_addr(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

/** سجل محاولات الدخول (لقسم الأمان) - آخر 300 */
function log_login(?int $userId, string $method, bool $ok, string $note = ''): void
{
    store_update_all('login_log', function (array &$rows) use ($userId, $method, $ok, $note) {
        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int)$r['id']);
        }
        $id = $max + 1;
        $rows[(string)$id] = [
            'id' => $id, 'user_id' => $userId, 'method' => $method, 'ok' => $ok, 'note' => clean_text($note),
            'ip' => client_ip_addr(), 'ua' => clean_text(mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)),
            'time' => now_str(),
        ];
        unset($r);
        if (count($rows) > 300) {
            ksort($rows, SORT_NUMERIC);
            $rows = array_slice($rows, -300, null, true);
        }
    });
    if (!$ok && function_exists('failed_login_alert')) {
        failed_login_alert();
    }
}

/** يطلّع الجلسة لو المستخدم محظور أو الأدمن أنهى كل الجلسات */
function enforce_session(): void
{
    if (!uid()) {
        return;
    }
    $u = user_by_id(uid());
    $epoch = (int)(settings()['session_epoch'] ?? 1);
    if (!$u || !empty($u['banned']) || (int)($_SESSION['epoch'] ?? 0) !== $epoch) {
        $_SESSION = [];
        return;
    }
    // تحديث صلاحية الأدمن واسم المستخدم لو تغيّرت
    $_SESSION['is_admin'] = !empty($u['is_admin']);
    $_SESSION['name'] = $u['name'];
}

// ---------------------------------------------------------------- المستخدمين والاشتراك

function user_by_id(?int $id): ?array
{
    return $id ? store_find('users', $id) : null;
}

function user_by(string $field, string $value): ?array
{
    if ($value === '') {
        return null;
    }
    return store_first('users', fn($u) => ($u[$field] ?? null) === $value);
}

function users_count(): int
{
    return count(store_all('users'));
}

function create_user(array $fields, bool $isAdmin): array
{
    $now = time();
    $trialDays = trial_days();
    $row = array_merge([
        'email' => null, 'phone' => null, 'name' => null, 'google_sub' => null,
        'is_admin' => $isAdmin, 'plan_active' => false, 'plan_ends_at' => null,
        'trial_ends_at' => date('Y-m-d H:i', $now + $trialDays * 86400), 'balance' => 0, 'banned' => false,
        'trial_ended_notified' => false, 'created_at' => date('Y-m-d H:i', $now),
    ], $fields);
    $id = store_insert('users', $row);
    if ($trialDays > 0) {
        add_event($id, null, 'بدأت الفترة التجريبية', 'لديك ' . $trialDays . ' أيام مجانية لتجربة المنصة', 'info');
    }
    return store_find('users', $id);
}

function update_user(int $id, array $fields): ?array
{
    return store_update('users', $id, fn($u) => array_merge($u, $fields));
}

/** الاشتراك فعّال لو plan_active وما انتهت مدته (لو انحددت مدة) */
function effective_plan_active(?array $u): bool
{
    if (!$u || empty($u['plan_active'])) {
        return false;
    }
    $ends = parse_dt($u['plan_ends_at'] ?? null);
    return $ends === null || time() < $ends;
}

/** اشتراك فعّال أو لسا داخل فترة التجربة المجانية */
function user_has_access(?array $u): bool
{
    if (!$u) {
        return false;
    }
    if (effective_plan_active($u)) {
        return true;
    }
    $trial = parse_dt($u['trial_ends_at'] ?? null);
    return $trial !== null && time() < $trial;
}

function check_trial_ended_event(array $u): void
{
    if (!empty($u['plan_active']) || !empty($u['trial_ended_notified']) || empty($u['trial_ends_at'])) {
        return;
    }
    $ends = parse_dt($u['trial_ends_at']);
    if ($ends !== null && time() >= $ends) {
        add_event((int)$u['id'], null, 'انتهت الفترة التجريبية', 'فعّل اشتراكك حتى تقدر تكمل إرسال الحملات والرد الآلي', 'warning');
        update_user((int)$u['id'], ['trial_ended_notified' => true]);
    }
}

function activate_plan_for_days(int $userId, int $days): void
{
    update_user($userId, ['plan_active' => true, 'plan_ends_at' => date('Y-m-d H:i', time() + $days * 86400)]);
    add_event($userId, null, 'تم تفعيل الاشتراك', "فعّل أدمن المنصة اشتراكك لمدة {$days} يوم", 'success');
}

function set_plan_active(int $userId, bool $active): void
{
    if ($active) {
        update_user($userId, ['plan_active' => true]);
        add_event($userId, null, 'تم تفعيل الاشتراك', 'فعّل أدمن المنصة اشتراكك بنجاح', 'success');
    } else {
        update_user($userId, ['plan_active' => false, 'plan_ends_at' => null]);
    }
}

// ---------------------------------------------------------------- الإشعارات

function add_event(?int $owner, ?string $account, string $title, string $body, string $kind = 'info'): void
{
    if (!$owner) {
        return;
    }
    store_update_all('events', function (array &$rows) use ($owner, $account, $title, $body, $kind) {
        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int)$r['id']);
        }
        $id = $max + 1;
        $rows[(string)$id] = [
            'id' => $id, 'owner' => $owner, 'account' => $account === null ? null : clean_text($account),
            'title' => clean_text($title), 'body' => clean_text($body), 'kind' => $kind, 'read' => false, 'time' => date('H:i'),
        ];
        // نحتفظ بآخر EVENTS_PER_USER إشعار لكل مستخدم فقط
        $mine = array_keys(array_filter($rows, fn($r) => (int)$r['owner'] === $owner));
        sort($mine, SORT_NUMERIC);
        while (count($mine) > EVENTS_PER_USER) {
            unset($rows[(string)array_shift($mine)]);
        }
    });
}

// ---------------------------------------------------------------- حسابات واتساب (السجل المحفوظ)

function wa_account(string $id): ?array
{
    return store_find('wa_accounts', $id);
}

/** حساب واتساب يملكه المستخدم الحالي، أو null */
function owned_account(string $id): ?array
{
    $acc = wa_account($id);
    if (!$acc || (int)$acc['owner'] !== uid()) {
        return null;
    }
    return $acc;
}

function wa_accounts_of(int $owner): array
{
    return store_where('wa_accounts', fn($a) => (int)($a['owner'] ?? 0) === $owner);
}

/** حساب مرسل رموز التحقق (حدده الأدمن) لو كان متصلاً */
function find_otp_sender(): ?array
{
    foreach (store_all('wa_accounts') as $a) {
        if (!empty($a['otp_sender']) && engine_logged_in($a['id'])) {
            return $a;
        }
    }
    return null;
}

/** حساب متصل للمستخدم لإرسال رسائل اشتراكات عملائه (يفضّل مرسل الرموز) */
function find_account_for_owner(int $owner): ?array
{
    $mine = wa_accounts_of($owner);
    foreach ($mine as $a) {
        if (!empty($a['otp_sender']) && engine_logged_in($a['id'])) {
            return $a;
        }
    }
    foreach ($mine as $a) {
        if (engine_logged_in($a['id'])) {
            return $a;
        }
    }
    return null;
}

function delete_wa_account(string $id): void
{
    engine_request('DELETE', '/accounts/' . rawurlencode($id), null, 20);
    store_delete('wa_accounts', $id);
    store_update_all('history', function (array &$rows) use ($id) {
        foreach ($rows as $k => $r) {
            if (($r['account_id'] ?? '') === $id) {
                unset($rows[$k]);
            }
        }
    });
    store_update_all('api_keys', function (array &$rows) use ($id) {
        foreach ($rows as $k => $r) {
            if (($r['account_id'] ?? '') === $id) {
                $rows[$k]['revoked'] = true;
            }
        }
    });
}

function history_of_owner(int $owner): array
{
    $rows = store_where('history', fn($h) => (int)$h['owner'] === $owner);
    usort($rows, fn($a, $b) => strcmp($b['time'] ?? '', $a['time'] ?? '') ?: $b['id'] <=> $a['id']);
    return $rows;
}

// ---------------------------------------------------------------- الإعدادات والهوية البصرية

function settings(): array
{
    return store_get_doc('settings', [
        'site_name' => '', 'default_country_code' => '964',
        'whatsapp_subscribe_number' => '', 'whatsapp_support_number' => '',
        'plan_price_iqd' => null, 'has_logo' => false, 'logo_mime' => null,
        'ai_api_key' => '', 'ai_knowledge_base' => '',
        // سحب الأرقام من الخرائط
        'leads_enabled' => true, 'leads_daily' => 5, 'leads_max' => 200,
        // الفوترة
        'trial_days' => TRIAL_DAYS, 'plan_days' => PLAN_ACTIVATION_DAYS, 'message_price' => 0, 'usd_rate' => 1500,
        // تسجيل الدخول والأمان
        'admin_email' => '', 'whatsapp_login' => true, 'session_epoch' => 1,
        // نسخ احتياطي عبر تيليجرام
        'tg_token' => '', 'tg_chat_id' => '', 'tg_webhook_key' => '', 'tg_auto_backup' => false, 'tg_last_backup' => '',
        // بطاقة المطور بقسم حسابي
        'dev_name' => '', 'dev_title' => '', 'dev_phone' => '', 'dev_photo' => false,
        // المهام المجدولة
        'last_cron' => 0, 'cron_installed' => '',
        'port' => null, 'domain' => '',
    ]);
}

function update_settings(array $fields): void
{
    store_update_doc('settings', fn($s) => array_merge($s, $fields));
}

function trial_days(): int
{
    return max(0, (int)(settings()['trial_days'] ?? TRIAL_DAYS));
}

function plan_days(): int
{
    return max(1, (int)(settings()['plan_days'] ?? PLAN_ACTIVATION_DAYS));
}

function message_price(): int
{
    return max(0, (int)(settings()['message_price'] ?? 0));
}

function usd_rate(): float
{
    return max(1, (float)(settings()['usd_rate'] ?? 1500));
}

function plan_price(): int
{
    return (int)(settings()['plan_price_iqd'] ?: PLAN_PRICE_IQD);
}

function default_country_code(): string
{
    $cc = trim((string)(settings()['default_country_code'] ?? ''));
    return valid_country_code($cc) ? $cc : DEFAULT_COUNTRY_CODE_FALLBACK;
}

function logo_path(): string
{
    return DATA_DIR . '/logo.bin';
}

/** [site_name (مهروب HTML), has_logo, has_custom_name] */
function branding_context(): array
{
    $s = settings();
    $raw = trim((string)$s['site_name']);
    $hasLogo = !empty($s['has_logo']) && is_file(logo_path());
    return [$raw !== '' ? htmlspecialchars($raw, ENT_QUOTES) : 'واصل', $hasLogo, $raw !== ''];
}

// ---------------------------------------------------------------- الذكاء الاصطناعي (DeepSeek)

/** حقول بطاقة تعليمات الذكاء الاصطناعي لكل رقم (الإلزامية عليها *) */
const AI_PROFILE_FIELDS = [
    'business_name' => ['اسم النشاط *', 40, 2],
    'business_type' => ['نوع النشاط *', 60, 2],
    'description' => ['وصف النشاط وما تقدمه *', 800, 20],
    'products' => ['المنتجات أو الخدمات مع الأسعار *', 3000, 10],
    'hours' => ['أوقات العمل', 200, 0],
    'location' => ['الموقع / التوصيل', 300, 0],
    'contact' => ['طريقة الطلب أو التواصل', 300, 0],
    'tone' => ['أسلوب الرد', 60, 0],
    'rules' => ['تعليمات إضافية للمساعد', 1200, 0],
    'forbidden' => ['أشياء ممنوع يتكلم عنها', 600, 0],
];

function ai_profile_missing(array $p): array
{
    $missing = [];
    foreach (AI_PROFILE_FIELDS as $k => [$label, , $min]) {
        if ($min > 0 && mb_strlen(trim((string)($p[$k] ?? ''))) < $min) {
            $missing[] = trim($label, ' *');
        }
    }
    return $missing;
}

/**
 * رد ذكي بالاعتماد على بطاقة تعليمات الرقم + سجل المحادثة (حتى ما يكرر الترحيب بكل رسالة)
 */
function ai_reply(string $customerMessage, array $history = [], ?array $profile = null, string $customer = ''): ?string
{
    $s = settings();
    if (empty($s['ai_api_key'])) {
        return null;
    }
    $p = $profile ?: [];
    $info = [];
    foreach (AI_PROFILE_FIELDS as $k => [$label]) {
        $v = trim((string)($p[$k] ?? ''));
        if ($v !== '') {
            $info[] = trim($label, ' *') . ":\n" . $v;
        }
    }
    if (!$info && trim((string)$s['ai_knowledge_base']) !== '') {
        $info[] = "معلومات المتجر:\n" . $s['ai_knowledge_base'];
    }
    $isFirst = count($history) === 0;
    $system = "أنت موظف خدمة عملاء حقيقي لـ «" . ($p['business_name'] ?? 'المتجر') . "» ترد على العملاء عبر واتساب.\n\n"
        . implode("\n\n", $info) . "\n\nقواعد مهمة:\n"
        . "- اكتب بالعربية فقط (لهجة عراقية بسيطة ومهذبة" . (!empty($p['tone']) ? '، وبأسلوب: ' . $p['tone'] : '') . ") ولا تستخدم أي كلمة إنجليزية.\n"
        . ($isFirst ? "- هذه أول رسالة بالمحادثة: رحّب بالعميل ترحيباً قصيراً جداً ثم أجب مباشرة.\n"
            : "- المحادثة مستمرة: لا ترحّب ولا تقل «أهلاً» أو «مرحباً» مرة ثانية، أجب مباشرة على آخر رسالة مع مراعاة سياق ما سبق.\n")
        . "- افهم الرسالة القصيرة مثل «نعم» أو «اي» أو «كم» حسب سؤالك أو كلامك السابق.\n"
        . "- اعتمد فقط على المعلومات أعلاه. إذا ما تعرف الجواب قل إنك راح تحوّل السؤال لصاحب النشاط، ولا تخترع أسعار أو معلومات.\n"
        . "- الرد قصير وواضح (3 أسطر أو أقل غالباً)، بدون تكرار نفس الكلام.\n"
        . (!empty($p['forbidden']) ? "- لا تتكلم أبداً عن: " . $p['forbidden'] . "\n" : '')
        . (!empty($p['rules']) ? "- تعليمات صاحب النشاط: " . $p['rules'] . "\n" : '')
        . ($customer !== '' ? "- اسم العميل على واتساب: {$customer}\n" : '');
    $messages = [['role' => 'system', 'content' => $system]];
    foreach (array_slice($history, -12) as $h) {
        if (in_array($h['role'] ?? '', ['user', 'assistant'], true) && trim((string)($h['content'] ?? '')) !== '') {
            $messages[] = ['role' => $h['role'], 'content' => mb_substr((string)$h['content'], 0, 1500)];
        }
    }
    $messages[] = ['role' => 'user', 'content' => mb_substr($customerMessage, 0, 2000)];
    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $s['ai_api_key'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => 'deepseek-chat', 'messages' => $messages, 'temperature' => 0.5, 'max_tokens' => 400], JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false || $code >= 400) {
        error_log('[رد آلي] فشل استدعاء DeepSeek: HTTP ' . $code);
        return null;
    }
    $data = json_decode($resp, true);
    $txt = $data['choices'][0]['message']['content'] ?? null;
    return $txt ? trim($txt) : null;
}
