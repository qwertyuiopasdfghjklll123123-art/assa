<?php
/**
 * معالجات الطلبات الأطول (تسجيل الدخول، الحملات، الاشتراكات، الأدمن).
 */

/** يرسل الرد للمتصفح فوراً ثم يكمّل عمل إضافي (مثل إرسال رسالة واتساب) بدون ما يعلّق المستخدم */
function json_out_then($data, callable $after): void
{
    ignore_user_abort(true);
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        @ob_end_flush();
        flush();
    }
    try {
        $after();
    } catch (Throwable $e) {
        error_log('[واصل] ' . $e->getMessage());
    }
    exit;
}

// ================================================================ تسجيل الدخول بكوكل

function handle_google_login(): void
{
    if (GOOGLE_CLIENT_ID === '') {
        json_err('تسجيل الدخول بكوكل غير مفعّل');
    }
    $token = (string)(request_json()['credential'] ?? '');
    if ($token === '') {
        json_err('بيانات كوكل مفقودة');
    }
    // التحقق من التوكن عند كوكل نفسها (توقيع + صلاحية)
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($token));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $info = $resp ? json_decode($resp, true) : null;
    if ($code !== 200 || !is_array($info)) {
        json_err('تعذر التحقق من حساب كوكل، حاول مرة أخرى', 401);
    }
    $issOk = in_array($info['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true);
    $verified = ($info['email_verified'] ?? '') === 'true' || ($info['email_verified'] ?? false) === true;
    if (($info['aud'] ?? '') !== GOOGLE_CLIENT_ID || !$issOk || (int)($info['exp'] ?? 0) < time() || !$verified) {
        json_err('حساب كوكل غير صالح', 401);
    }
    $sub = (string)$info['sub'];
    $email = strtolower((string)($info['email'] ?? ''));
    $name = clean_text(trim((string)($info['name'] ?? '')));

    $user = user_by('google_sub', $sub) ?? user_by('email', $email);
    $isNew = false;
    if (!$user) {
        $user = create_user(['email' => $email, 'google_sub' => $sub, 'name' => $name ?: null], users_count() === 0);
        $isNew = true;
    } else {
        $fields = ['google_sub' => $sub];
        if (empty($user['name']) && $name !== '') {
            $fields['name'] = $name;
        }
        if (empty($user['email'])) {
            $fields['email'] = $email;
        }
        $user = update_user((int)$user['id'], $fields);
    }
    cleanup_bootstrap();
    set_session_user($user);
    json_out(['ok' => true, 'is_new' => $isNew && empty($user['name'])]);
}

// ================================================================ البريد الإلكتروني وكلمة المرور

function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0');
}

/** يمنع تخمين كلمات المرور: 10 محاولات فاشلة كل 15 دقيقة لكل IP */
function login_throttle(bool $failed = false): bool
{
    $ip = client_ip();
    $allowed = true;
    store_update_doc('loginrl', function ($d) use ($ip, $failed, &$allowed) {
        $now = time();
        foreach ($d as $k => $times) {
            $d[$k] = array_values(array_filter($times, fn($t) => $now - $t < 900));
            if (!$d[$k]) {
                unset($d[$k]);
            }
        }
        $allowed = count($d[$ip] ?? []) < 10;
        if ($failed) {
            $d[$ip][] = $now;
        }
        return $d;
    });
    return $allowed;
}

function handle_email_signup(): void
{
    $d = request_json();
    $name = clean_text(mb_substr(trim((string)($d['name'] ?? '')), 0, 60));
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $password = (string)($d['password'] ?? '');
    if ($name === '' || $email === '' || $password === '') {
        json_err('عبّي كل الحقول');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_err('أدخل بريد إلكتروني صحيح');
    }
    if (mb_strlen($password) < 6) {
        json_err('كلمة المرور لازم تكون 6 أحرف أو أكثر');
    }
    if (user_by('email', $email)) {
        json_err('هذا البريد مسجّل من قبل - سجّل دخولك بدل إنشاء حساب');
    }
    $user = create_user(['email' => $email, 'name' => $name, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)], users_count() === 0);
    cleanup_bootstrap();
    set_session_user($user);
    json_out(['ok' => true]);
}

function handle_email_login(): void
{
    if (!login_throttle()) {
        json_err('محاولات كثيرة، حاول بعد 15 دقيقة', 429);
    }
    $d = request_json();
    $email = strtolower(trim((string)($d['email'] ?? '')));
    $user = user_by('email', $email);
    if (!$user || empty($user['password_hash']) || !password_verify((string)($d['password'] ?? ''), $user['password_hash'])) {
        login_throttle(true);
        if ($user && empty($user['password_hash']) && !empty($user['google_sub'])) {
            json_err('هذا الحساب مسجّل بكوكل - استخدم زر كوكل');
        }
        json_err('البريد الإلكتروني أو كلمة المرور غير صحيحة');
    }
    set_session_user($user);
    json_out(['ok' => true]);
}

// ================================================================ تسجيل الدخول برمز واتساب

function otp_store(string $phone, string $code): void
{
    store_update_doc('otp', function ($d) use ($phone, $code) {
        foreach ($d as $p => $e) {
            if (($e['expires'] ?? 0) < time()) {
                unset($d[$p]);
            }
        }
        $d[$phone] = ['hash' => password_hash($code, PASSWORD_DEFAULT), 'expires' => time() + 600, 'attempts' => 0, 'sent_at' => time()];
        return $d;
    });
}

function otp_message(string $code): string
{
    [$siteName] = branding_context();
    return "رمز التحقق الخاص بك في " . html_entity_decode($siteName) . ": {$code}\nصالح لمدة 10 دقائق.";
}

function handle_send_code(): void
{
    $phone = digits_only(request_json()['phone'] ?? '');
    if (strlen($phone) < 8) {
        json_err('أدخل رقم واتساب صحيح مع مفتاح الدولة');
    }
    // حساب الأدمن الثابت: يدخل برمزه الثابت بدون إرسال (لازم يكتب الرمز - مو دخول مباشر)
    if (MASTER_ADMIN_PHONE !== '' && $phone === MASTER_ADMIN_PHONE) {
        json_out(['ok' => true]);
    }
    $last = store_get_doc('otp')[$phone] ?? null;
    if ($last && time() - ($last['sent_at'] ?? 0) < 45) {
        json_err('انتظر قليلاً قبل طلب رمز جديد');
    }
    $sender = find_otp_sender();
    if (!$sender) {
        // أول استخدام للموقع: نفتح جلسة واتساب جديدة يمسح منها المستخدم QR بنفسه ويصير المدير
        if (users_count() === 0) {
            $boot = store_get_doc('bootstrap');
            $accId = $boot['acc_id'] ?? null;
            $live = $accId ? engine_accounts()[$accId] ?? null : null;
            if (!$live) {
                $accId = bin2hex(random_bytes(4));
                $r = engine_json('POST', '/accounts', ['id' => $accId, 'owner' => null, 'name' => 'حساب الإعداد الأولي']);
                if (empty($r['ok'])) {
                    json_err($r['error'] ?? 'تعذر بدء الإعداد الأولي', 503);
                }
            }
            store_update_doc('bootstrap', fn($d) => ['acc_id' => $accId, 'phone' => $phone, 'code_sent' => ($d['phone'] ?? '') === $phone && !empty($d['code_sent']) && ($d['acc_id'] ?? '') === $accId]);
            json_out(['ok' => true, 'bootstrap' => true, 'acc_id' => $accId]);
        }
        json_err('تسجيل الدخول عبر واتساب غير مفعّل حالياً - سجّل بالبريد الإلكتروني' . (GOOGLE_CLIENT_ID !== '' ? ' أو بحساب كوكل' : ''));
    }
    $code = (string)random_int(100000, 999999);
    otp_store($phone, $code);
    [$ok, $err] = engine_send($sender['id'], $phone, otp_message($code));
    if (!$ok) {
        store_update_doc('otp', function ($d) use ($phone) {
            unset($d[$phone]);
            return $d;
        });
        json_err($err === 'الرقم لا يستخدم واتساب' ? 'هذا الرقم لا يستخدم واتساب' : 'تعذر إرسال الرمز، تأكد إن الرقم يستخدم واتساب وحاول مرة أخرى');
    }
    json_out(['ok' => true]);
}

/** متروكة للتوافق مع الواجهة: الإرسال صار مباشر فالحالة دائماً "أُرسل" لو فيه رمز */
function handle_send_status(): void
{
    $phone = digits_only($_GET['phone'] ?? '');
    $e = store_get_doc('otp')[$phone] ?? null;
    json_out($e ? ['status' => 'sent', 'error' => null] : ['status' => 'unknown']);
}

function handle_verify_code(): void
{
    $d = request_json();
    $phone = digits_only($d['phone'] ?? '');
    $code = trim((string)($d['code'] ?? ''));
    $isMaster = MASTER_ADMIN_PHONE !== '' && MASTER_ADMIN_CODE !== ''
        && $phone === MASTER_ADMIN_PHONE && hash_equals(MASTER_ADMIN_CODE, $code);
    if (!$isMaster) {
        $result = null;
        store_update_doc('otp', function ($all) use ($phone, $code, &$result) {
            $e = $all[$phone] ?? null;
            if (!$e || $e['expires'] < time()) {
                $result = 'الرمز غير صحيح أو منتهي الصلاحية';
                return $all;
            }
            if ($e['attempts'] >= 5) {
                unset($all[$phone]);
                $result = 'محاولات كثيرة، اطلب رمز جديد';
                return $all;
            }
            if (!password_verify($code, $e['hash'])) {
                $all[$phone]['attempts']++;
                $result = 'الرمز غير صحيح أو منتهي الصلاحية';
                return $all;
            }
            unset($all[$phone]);
            return $all;
        });
        if ($result !== null) {
            json_err($result);
        }
    }
    $isNew = login_as_phone($phone, $isMaster);
    json_out(['ok' => true, 'is_new' => $isNew]);
}

function login_as_phone(string $phone, bool $isMaster): bool
{
    $user = user_by('phone', $phone);
    $isNew = !$user;
    if (!$user) {
        $isAdmin = $isMaster || users_count() === 0;
        $user = create_user(['phone' => $phone], $isAdmin);
        if ($isAdmin && !$isMaster) {
            // حساب الإعداد الأولي يصير حساب واتساب الأول للمدير ومرسل رموز التحقق
            $boot = store_get_doc('bootstrap');
            if (!empty($boot['acc_id']) && ($boot['phone'] ?? '') === $phone) {
                $acc = [
                    'id' => $boot['acc_id'], 'owner' => (int)$user['id'], 'name' => 'حسابي الأول',
                    'auto_reply_enabled' => false, 'auto_reply_ai_enabled' => false, 'auto_reply_rules' => [],
                    'otp_sender' => true, 'created_at' => now_str(),
                ];
                store_insert('wa_accounts', $acc);
                engine_sync_account($acc);
                store_update_doc('bootstrap', fn() => []);
            }
        }
    } elseif ($isMaster && empty($user['is_admin'])) {
        $user = update_user((int)$user['id'], ['is_admin' => true]);
    }
    cleanup_bootstrap();
    set_session_user($user);
    return $isNew;
}

/** لو صار عندنا مستخدمين (مثلاً أول دخول كان بكوكل) نحذف جلسة الإعداد الأولي المتروكة */
function cleanup_bootstrap(): void
{
    $boot = store_get_doc('bootstrap');
    if (empty($boot['acc_id']) || users_count() === 0) {
        return;
    }
    if (!wa_account($boot['acc_id'])) {
        engine_request('DELETE', '/accounts/' . rawurlencode($boot['acc_id']), null, 15);
    }
    store_update_doc('bootstrap', fn() => []);
}

function handle_bootstrap_qr($accId): void
{
    $boot = store_get_doc('bootstrap');
    if (users_count() > 0 || ($boot['acc_id'] ?? '') !== $accId) {
        http_response_code(204);
        return;
    }
    output_engine_qr($accId);
}

function handle_bootstrap_status($accId): void
{
    $boot = store_get_doc('bootstrap');
    if (users_count() > 0 || ($boot['acc_id'] ?? '') !== $accId) {
        json_out(['connected' => false]);
    }
    if (!empty($boot['code_sent'])) {
        json_out(['connected' => true, 'code_sent' => true]);
    }
    $st = engine_json('GET', '/accounts/' . rawurlencode($accId) . '/status', null, 6);
    if (empty($st['logged_in'])) {
        json_out(['connected' => false]);
    }
    $code = (string)random_int(100000, 999999);
    otp_store($boot['phone'], $code);
    [$ok] = engine_send($accId, $boot['phone'], otp_message($code));
    if ($ok) {
        store_update_doc('bootstrap', fn($d) => array_merge($d, ['code_sent' => true]));
    }
    json_out(['connected' => true, 'code_sent' => $ok]);
}

function output_engine_qr(string $accId): void
{
    [$status, $png] = engine_request('GET', '/accounts/' . rawurlencode($accId) . '/qr', null, 8);
    if ($status !== 200 || !is_string($png) || $png === '') {
        http_response_code(204);
        return;
    }
    header('Content-Type: image/png');
    header('Cache-Control: no-store');
    echo $png;
}

// ================================================================ الهوية البصرية

function handle_set_branding(): void
{
    require_admin();
    $d = request_json();
    $cc = trim((string)($d['default_country_code'] ?? ''));
    $price = (int)($d['plan_price_iqd'] ?? 0) ?: PLAN_PRICE_IQD;
    $fields = [
        'site_name' => clean_text(mb_substr(trim((string)($d['site_name'] ?? '')), 0, 60)),
        'default_country_code' => valid_country_code($cc) ? $cc : DEFAULT_COUNTRY_CODE_FALLBACK,
        'whatsapp_subscribe_number' => digits_only($d['whatsapp_subscribe_number'] ?? '') ?: WHATSAPP_PAY_NUMBER,
        'whatsapp_support_number' => digits_only($d['whatsapp_support_number'] ?? '') ?: WHATSAPP_PAY_NUMBER,
        'plan_price_iqd' => max(0, $price),
    ];
    if (array_key_exists('logo', $d)) {
        $logo = $d['logo'];
        if ($logo) {
            if (!is_string($logo) || !preg_match('#^data:(image/(png|jpeg|jpg|gif|webp|svg\+xml));base64,(.+)$#s', $logo, $m)) {
                json_err('صيغة الشعار غير صحيحة');
            }
            if (strlen($logo) > MAX_LOGO_B64_CHARS) {
                json_err('حجم الصورة كبير جداً (الحد الأقصى 2 ميغابايت)');
            }
            $raw = base64_decode($m[3], true);
            if ($raw === false) {
                json_err('صيغة الشعار غير صحيحة');
            }
            file_put_contents(logo_path(), $raw);
            $fields['has_logo'] = true;
            $fields['logo_mime'] = $m[1];
        } else {
            @unlink(logo_path());
            $fields['has_logo'] = false;
        }
    }
    update_settings($fields);
    json_out(['ok' => true]);
}

// ================================================================ اشتراك المستخدم بالمنصة

function handle_get_subscription(): void
{
    $id = require_login();
    $u = user_by_id($id);
    $active = effective_plan_active($u);
    $trialLeft = 0;
    if ($u && !$active && ($t = parse_dt($u['trial_ends_at']))) {
        $trialLeft = max(0, (int)floor(($t - time()) / 86400) + 1);
        if ($t <= time()) {
            $trialLeft = 0;
        }
    }
    $s = settings();
    $price = plan_price();
    $subscriberId = $u ? ($u['phone'] ?: $u['email'] ?: 'غير محدد') : 'غير محدد';
    $payText = rawurlencode('أرغب بتفعيل اشتراكي في منصة واصل (' . number_format($price) . " د.ع) - رقمي: {$subscriberId}");
    $payments = array_reverse(store_where('payments', fn($p) => (int)$p['user_id'] === $id));
    json_out([
        'plan_active' => $active,
        'plan_ends_at' => $active ? $u['plan_ends_at'] : null,
        'has_access' => user_has_access($u),
        'trial_days_left' => $trialLeft,
        'plan_name' => PLAN_NAME,
        'price_iqd' => $price,
        'wa_pay_link' => 'https://wa.me/' . ($s['whatsapp_subscribe_number'] ?: WHATSAPP_PAY_NUMBER) . '?text=' . $payText,
        'wa_support_link' => 'https://wa.me/' . ($s['whatsapp_support_number'] ?: WHATSAPP_PAY_NUMBER) . '?text=' . rawurlencode('مرحباً، احتاج مساعدة'),
        'payments' => $payments,
    ]);
}

function handle_admin_set_plan($userId): void
{
    require_admin();
    $userId = (int)$userId;
    if (!user_by_id($userId)) {
        json_err('مستخدم غير موجود', 404);
    }
    $d = request_json();
    if (!empty($d['active'])) {
        $days = max(1, (int)($d['days'] ?? 0) ?: PLAN_ACTIVATION_DAYS);
        activate_plan_for_days($userId, $days);
        json_out_then(['ok' => true], fn() => send_admin_plan_message($userId, true, $days));
    }
    set_plan_active($userId, false);
    json_out_then(['ok' => true], fn() => send_admin_plan_message($userId, false, null));
}

// ================================================================ مشتركي المستخدم (عملاؤه)

function subscription_settings(int $owner): array
{
    $all = store_get_doc('subscription_settings');
    $row = $all[(string)$owner] ?? [];
    return [
        'default_duration_days' => (int)($row['default_duration_days'] ?? 30),
        'reminder_days_before' => (int)($row['reminder_days_before'] ?? 3),
        'start_message' => ($row['start_message'] ?? '') ?: DEFAULT_START_MESSAGE,
        'reminder_message' => ($row['reminder_message'] ?? '') ?: DEFAULT_REMINDER_MESSAGE,
        'expired_message' => ($row['expired_message'] ?? '') ?: DEFAULT_EXPIRED_MESSAGE,
    ];
}

function handle_set_subscription_settings(): void
{
    $id = require_login();
    $d = request_json();
    $dur = filter_var($d['default_duration_days'] ?? 30, FILTER_VALIDATE_INT);
    $rem = filter_var($d['reminder_days_before'] ?? 3, FILTER_VALIDATE_INT);
    if ($dur === false || $rem === false) {
        json_err('القيم لازم تكون أرقام صحيحة');
    }
    if ($dur < 1 || $rem < 0) {
        json_err('القيم غير صحيحة');
    }
    $row = [
        'default_duration_days' => $dur,
        'reminder_days_before' => $rem,
        'start_message' => mb_substr(trim((string)($d['start_message'] ?? '')), 0, 500) ?: DEFAULT_START_MESSAGE,
        'reminder_message' => mb_substr(trim((string)($d['reminder_message'] ?? '')), 0, 500) ?: DEFAULT_REMINDER_MESSAGE,
        'expired_message' => mb_substr(trim((string)($d['expired_message'] ?? '')), 0, 500) ?: DEFAULT_EXPIRED_MESSAGE,
    ];
    store_update_doc('subscription_settings', function ($all) use ($id, $row) {
        $all[(string)$id] = $row;
        return $all;
    });
    json_out(['ok' => true]);
}

function subscriber_expiry(array $s): ?int
{
    $start = DateTime::createFromFormat('!Y-m-d', $s['subscribed_at']);
    if (!$start) {
        return null;
    }
    $start->modify('+' . (int)$s['duration_days'] . ' days');
    return $start->getTimestamp();
}

function days_until(int $ts): int
{
    $today = new DateTime('today');
    $target = (new DateTime())->setTimestamp($ts)->setTime(0, 0);
    return (int)$today->diff($target)->format('%r%a');
}

function handle_list_subscribers(): void
{
    $id = require_login();
    $rows = store_where('subscribers', fn($s) => (int)$s['owner'] === $id);
    usort($rows, fn($a, $b) => strcmp($b['subscribed_at'], $a['subscribed_at']) ?: $b['id'] <=> $a['id']);
    $out = [];
    foreach ($rows as $s) {
        $exp = subscriber_expiry($s);
        $out[] = [
            'id' => $s['id'], 'name' => $s['name'], 'phone' => $s['phone'],
            'subscribed_at' => $s['subscribed_at'], 'duration_days' => $s['duration_days'],
            'expiry' => $exp ? date('Y-m-d', $exp) : null,
            'days_left' => $exp ? days_until($exp) : null,
        ];
    }
    json_out($out);
}

function validate_date_or_today($v): string
{
    $v = trim((string)$v);
    if ($v === '') {
        return date('Y-m-d');
    }
    $dt = DateTime::createFromFormat('!Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v) {
        json_err('تاريخ الاشتراك غير صحيح');
    }
    return $v;
}

function validate_days($raw, int $default): int
{
    if ($raw === null || $raw === '') {
        return $default;
    }
    $n = filter_var($raw, FILTER_VALIDATE_INT);
    if ($n === false) {
        json_err('عدد أيام الاشتراك لازم يكون رقم صحيح');
    }
    if ($n < 1) {
        json_err('عدد أيام الاشتراك لازم يكون رقم أكبر من صفر');
    }
    return $n;
}

function handle_create_subscriber(): void
{
    $id = require_login();
    $d = request_json();
    $name = clean_text(mb_substr(trim((string)($d['name'] ?? '')), 0, 60));
    $phone = digits_only($d['phone'] ?? '');
    if ($name === '' || strlen($phone) < 8) {
        json_err('أدخل اسم ورقم واتساب صحيحين');
    }
    $at = validate_date_or_today($d['subscribed_at'] ?? '');
    $dur = validate_days($d['duration_days'] ?? null, subscription_settings($id)['default_duration_days']);
    $subId = store_insert('subscribers', [
        'owner' => $id, 'name' => $name, 'phone' => $phone, 'subscribed_at' => $at,
        'duration_days' => $dur, 'reminder_sent' => false, 'expired_sent' => false, 'created_at' => now_str(),
    ]);
    json_out_then(['ok' => true, 'id' => $subId], fn() => send_subscription_start_message($subId));
}

function handle_renew_subscriber($subId): void
{
    $id = require_login();
    $s = store_find('subscribers', $subId);
    if (!$s || (int)$s['owner'] !== $id) {
        json_err('الاشتراك غير موجود', 404);
    }
    $d = request_json();
    $at = validate_date_or_today($d['subscribed_at'] ?? '');
    $dur = validate_days($d['duration_days'] ?? null, (int)$s['duration_days']);
    store_update('subscribers', $subId, fn($r) => array_merge($r, [
        'subscribed_at' => $at, 'duration_days' => $dur, 'reminder_sent' => false, 'expired_sent' => false,
    ]));
    json_out(['ok' => true]);
}

// ================================================================ الحملات والإعلانات

function read_upload_media(): ?array
{
    $f = $_FILES['media_file'] ?? null;
    if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE || $f['name'] === '') {
        return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        json_err('فشل رفع الملف - ممكن حجمه أكبر من المسموح بالاستضافة');
    }
    if ($f['size'] > 40 * 1024 * 1024) {
        json_err('حجم الملف كبير جداً (الحد 40 ميغابايت)');
    }
    $mime = function_exists('mime_content_type') ? (mime_content_type($f['tmp_name']) ?: $f['type']) : $f['type'];
    return ['name' => basename($f['name']), 'mime' => $mime ?: 'application/octet-stream', 'data' => base64_encode(file_get_contents($f['tmp_name']))];
}

function form_int(string $key, int $default): int
{
    $v = filter_var($_POST[$key] ?? '', FILTER_VALIDATE_INT);
    return $v === false ? $default : max(1, $v);
}

function engine_start_campaign(string $accId, array $numbers, string $text, ?string $sendAt = null): void
{
    $payload = [
        'numbers' => $numbers,
        'text' => $text,
        'delay' => form_int('delay', DEFAULT_DELAY),
        'media_delay' => form_int('media_delay', DEFAULT_MEDIA_DELAY),
        'media' => read_upload_media(),
    ];
    if ($sendAt) {
        $payload['send_at'] = $sendAt;
    }
    $r = engine_json('POST', '/accounts/' . rawurlencode($accId) . '/campaign', $payload, 120);
    $status = $r['_status'] ?? 503;
    unset($r['_status']);
    json_out($r, $status === 200 ? 200 : $status);
}

function handle_start_campaign($accId): void
{
    $id = require_login();
    if (!user_has_access(user_by_id($id))) {
        json_err('انتهت فترتك التجريبية، فعّل الاشتراك من قسم الإعدادات للمتابعة', 402, ['needs_subscription' => true]);
    }
    if (!owned_account($accId)) {
        json_err('تأكد من تسجيل الدخول أولاً');
    }
    $numbers = numbers_from_text((string)($_POST['numbers_text'] ?? ''));
    $f = $_FILES['numbers_file'] ?? null;
    if ($f && $f['error'] === UPLOAD_ERR_OK && $f['name'] !== '') {
        try {
            $numbers = array_merge($numbers, numbers_from_upload($f['tmp_name'], $f['name']));
        } catch (Throwable $e) {
            json_err('تعذرت قراءة ملف الأرقام');
        }
    }
    $cc = trim((string)($_POST['country_code'] ?? ''));
    if (!valid_country_code($cc)) {
        $cc = DEFAULT_COUNTRY_CODE_FALLBACK;
    }
    $numbers = array_values(array_unique(array_map(fn($n) => apply_country_code($n, $cc), $numbers)));
    if (!$numbers) {
        json_err('ما لقيت أي رقم صالح (اكتب أرقام أو ارفع ملف Excel)');
    }
    $text = trim((string)($_POST['text'] ?? '')) ?: DEFAULT_MESSAGE;
    $sendAt = null;
    $raw = trim((string)($_POST['send_at'] ?? ''));
    if ($raw !== '') {
        try {
            $dt = new DateTime($raw); // يُفهم بتوقيت APP_TIMEZONE
            if ($dt->getTimestamp() > time()) {
                $sendAt = $dt->format(DATE_ATOM);
            }
        } catch (Throwable $e) {
            $sendAt = null;
        }
    }
    engine_start_campaign($accId, $numbers, $text, $sendAt);
}

function handle_announcement($accId): void
{
    require_admin();
    if (!owned_account($accId)) {
        json_err('تأكد من تسجيل الدخول لهذا الحساب أولاً');
    }
    $numbers = array_values(array_unique(array_filter(array_column(store_all('users'), 'phone'))));
    if (!$numbers) {
        json_err('ما فيه مستخدمين عندهم رقم واتساب مسجل');
    }
    $text = trim((string)($_POST['text'] ?? ''));
    if ($text === '') {
        json_err('اكتب نص الإعلان');
    }
    engine_start_campaign($accId, $numbers, $text);
}

function history_public(array $h): array
{
    return [
        'time' => $h['time'], 'total' => $h['total'], 'sent' => $h['sent'],
        'failed' => $h['failed'], 'text' => $h['text'], 'account_name' => $h['account_name'],
    ];
}

// ================================================================ الرد الآلي

function handle_set_auto_reply($accId): void
{
    $id = require_login();
    $acc = owned_account($accId);
    if (!$acc) {
        json_err('حساب غير موجود', 404);
    }
    $d = request_json();
    $rules = [];
    foreach (($d['rules'] ?? []) as $r) {
        $kw = trim((string)($r['keyword'] ?? ''));
        if ($kw !== '') {
            $rules[] = ['keyword' => $kw, 'reply' => trim((string)($r['reply'] ?? ''))];
        }
    }
    $enabled = !empty($d['enabled']);
    if ($enabled && empty($acc['auto_reply_enabled']) && !user_has_access(user_by_id($id))) {
        json_err('انتهت فترتك التجريبية، فعّل الاشتراك من قسم الإعدادات للمتابعة', 402, ['needs_subscription' => true]);
    }
    $acc = store_update('wa_accounts', $accId, fn($a) => array_merge($a, [
        'auto_reply_enabled' => $enabled,
        'auto_reply_ai_enabled' => !empty($d['ai_enabled']),
        'auto_reply_rules' => $rules,
    ]));
    engine_sync_account($acc);
    json_out(['ok' => true]);
}
