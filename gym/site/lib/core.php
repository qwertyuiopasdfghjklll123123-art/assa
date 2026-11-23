<?php
/**
 * تمريني - الأساسيات: الإعدادات، قاعدة البيانات JSON (مع قفل الملفات)، الجلسات، والحماية.
 */
declare(strict_types=1);

const APP_NAME = 'تمريني';
define('DATA_DIR', __DIR__ . '/../data');
define('VIEWS_DIR', __DIR__ . '/../views');

// ================================================================ الحماية
function security_headers(): void
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** طلبات التعديل لازم تجي من الواجهة نفسها (حماية CSRF) */
function require_same_origin(): void
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'tamreeni') {
        json_err('طلب غير مسموح', 403);
    }
    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($src !== '' && $src !== 'null') {
        $h = strtolower((string)parse_url($src, PHP_URL_HOST) . (parse_url($src, PHP_URL_PORT) ? ':' . parse_url($src, PHP_URL_PORT) : ''));
        if ($h !== strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) {
            json_err('طلب غير مسموح', 403);
        }
    }
}

// ================================================================ ردود JSON
function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_err(string $msg, int $status = 400): never
{
    json_out(['ok' => false, 'error' => $msg], $status);
}

function request_json(): array
{
    static $d = null;
    if ($d === null) {
        $d = json_decode((string)file_get_contents('php://input'), true);
        $d = is_array($d) ? $d : [];
    }
    return $d;
}

/** نص نظيف (بدون رموز HTML) بطول محدد */
function clean(string $s, int $max = 200): string
{
    $s = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '');
    return mb_substr(strtr($s, ['<' => '‹', '>' => '›']), 0, $max);
}

// ================================================================ قاعدة البيانات JSON
function data_path(string $name): string
{
    if (!preg_match('/^[a-z0-9_]+$/', $name)) {
        throw new RuntimeException('bad store name');
    }
    return DATA_DIR . '/' . $name . '.json';
}

/** يقرأ ملف JSON (مصفوفة فارغة إذا ما موجود) */
function db_read(string $name, array $default = []): array
{
    $f = data_path($name);
    if (!is_file($f)) {
        return $default;
    }
    $fh = fopen($f, 'r');
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : $default;
}

/** يعدّل ملف JSON بأمان (قفل حصري: ما يضيع تعديل لو طلبين بنفس اللحظة) */
function db_update(string $name, callable $fn, array $default = []): array
{
    $f = data_path($name);
    $fh = fopen($f, 'c+');
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $d = json_decode((string)$raw, true);
    $d = is_array($d) ? $d : $default;
    $out = $fn($d);
    $d = is_array($out) ? $out : $d;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $d;
}

function config(): array
{
    static $c = null;
    if ($c === null) {
        $c = db_read('config');
        if (empty($c['cron_key']) || empty($c['vapid_private'])) {
            // أول تشغيل: نولّد مفتاح Cron ومفاتيح الإشعارات (داخل القفل حتى ما يتولدون مرتين)
            $c = db_update('config', function (array $d) {
                if (empty($d['cron_key'])) {
                    $d['cron_key'] = bin2hex(random_bytes(16));
                }
                if (empty($d['vapid_private'])) {
                    [$d['vapid_private'], $d['vapid_public']] = vapid_generate();
                }
                return $d;
            });
        }
    }
    return $c;
}

// ================================================================ المستخدمين والجلسات
function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('tmr_sid');
    session_set_cookie_params(['lifetime' => 365 * 86400, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', (string)(365 * 86400));
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    session_save_path($dir);
    session_start();
}

function current_uid(): ?int
{
    start_session();
    return isset($_SESSION['uid']) ? (int)$_SESSION['uid'] : null;
}

function require_login(): int
{
    $uid = current_uid();
    if (!$uid || !user_find($uid)) {
        json_err('سجّل دخولك أولاً', 401);
    }
    return $uid;
}

function user_find(int $uid): ?array
{
    $u = db_read('users')[(string)$uid] ?? null;
    return is_array($u) ? $u : null;
}

/** حدّ محاولات الدخول: 8 محاولات خاطئة كل 15 دقيقة لكل IP */
function login_throttle(bool $failed = false): void
{
    $ip = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $now = time();
    $d = db_update('login_rl', function (array $d) use ($ip, $now, $failed) {
        foreach ($d as $k => $v) {
            if ($now - ($v['t'] ?? 0) > 900) {
                unset($d[$k]);
            }
        }
        if ($failed) {
            $d[$ip] = ['n' => ($d[$ip]['n'] ?? 0) + 1, 't' => $now];
        }
        return $d;
    });
    if (!$failed && ($d[$ip]['n'] ?? 0) >= 8) {
        json_err('محاولات كثيرة - انتظر 15 دقيقة وجرب مرة ثانية', 429);
    }
}

// ================================================================ بيانات المستخدم (ملف لكل مستخدم)
function user_doc_name(int $uid): string
{
    return 'u' . $uid;
}

function user_doc(int $uid): array
{
    return db_read(user_doc_name($uid), default_user_doc());
}

function user_doc_update(int $uid, callable $fn): array
{
    return db_update(user_doc_name($uid), $fn, default_user_doc());
}

function exercise_library(): array
{
    static $lib = null;
    if ($lib === null) {
        $lib = json_decode((string)file_get_contents(VIEWS_DIR . '/exercises.json'), true) ?: [];
    }
    return $lib;
}

function exercise_ids(): array
{
    return array_column(exercise_library()['exercises'] ?? [], 'id');
}

/** البرنامج الافتراضي (من ورقة الجيم) - المستخدم يعدّله براحته */
function default_user_doc(): array
{
    $ex = fn(string $id, string $reps, int $sets = 4, int $rest = 90, string $note = '') => ['ex' => $id, 'sets' => $sets, 'reps' => $reps, 'rest' => $rest, 'note' => $note];
    return [
        'profile' => ['weight' => '', 'height' => '', 'age' => '', 'goal' => 'build'],
        'settings' => ['tz' => 'Asia/Baghdad', 'rest' => 90, 'sound' => true, 'vibrate' => true, 'meals_on' => true, 'water_on' => false],
        'equipment' => ['barbell', 'dumbbell', 'bench', 'cable', 'ez_bar', 'leg_machine', 'bodyweight'],
        'days' => [
            'D1' => ['title' => 'صدر + ترايسبس + ساعد', 'items' => [
                $ex('bench_flat_bb', '12-10-8-6'), $ex('bench_incline_bb', '12-10-8', 3), $ex('db_fly', '10'),
                $ex('rope_pushdown', '10', 4, 60), $ex('reverse_pushdown', '10', 4, 60, 'سوبر سِت ويا تراي خلف الرأس'), $ex('overhead_ext', '10', 4, 60), $ex('wrist_curl', '15', 4, 45),
            ]],
            'D2' => ['title' => 'أكتاف + ترابيس', 'items' => [
                $ex('ohp_bb', '12-10-8-6'), $ex('lateral_raise', '12', 4, 60), $ex('front_raise', '12', 3, 60), $ex('rear_delt', '12', 3, 60), $ex('upright_row', '10', 3), $ex('shrug', '15', 4, 60),
            ]],
            'D3' => ['title' => 'ظهر + بايسبس', 'items' => [
                $ex('lat_pulldown', '12'), $ex('seated_row', '10'), $ex('db_row', '12'), $ex('back_ext', '15', 4, 60),
                $ex('bb_curl', '10', 4, 60, 'سوبر سِت ويا باي مطرقة'), $ex('hammer_curl', '10', 4, 60), $ex('preacher_curl', '12', 4, 60),
            ]],
            'D4' => ['title' => 'أرجل + بطن', 'items' => [
                $ex('squat', '12-10-8-6', 4, 120), $ex('lunge', '12', 3), $ex('leg_ext', '12', 4, 60), $ex('leg_curl', '12', 4, 60), $ex('calf_raise', '20', 4, 45), $ex('crunch', '20', 3, 45), $ex('leg_raise', '15', 3, 45),
            ]],
        ],
        // أيام الأسبوع (0 = الأحد ... 6 = السبت) -> اليوم التدريبي أو '' = راحة
        'week' => ['6' => 'D1', '0' => 'D2', '1' => 'D3', '2' => 'D4', '3' => '', '4' => '', '5' => ''],
        'meals' => [
            ['id' => 'm1', 'name' => 'الفطور', 'time' => '08:00', 'note' => 'بيض + شوفان + فاكهة', 'on' => true],
            ['id' => 'm2', 'name' => 'وجبة خفيفة', 'time' => '11:00', 'note' => 'زبادي + مكسرات', 'on' => true],
            ['id' => 'm3', 'name' => 'الغداء', 'time' => '14:00', 'note' => 'رز + دجاج + سلطة', 'on' => true],
            ['id' => 'm4', 'name' => 'قبل التمرين', 'time' => '17:00', 'note' => 'موز + تمر', 'on' => true],
            ['id' => 'm5', 'name' => 'بعد التمرين', 'time' => '19:30', 'note' => 'بروتين + رز أو بطاطا', 'on' => true],
            ['id' => 'm6', 'name' => 'العشاء', 'time' => '22:00', 'note' => 'تونة أو جبن + خبز أسمر', 'on' => true],
        ],
        'media' => [],     // تمرين -> صورة/GIF رفعها المستخدم
        'last' => [],      // تمرين -> آخر وزن انستخدم
        'log' => [],       // سجل التمارين المكتملة
        'session' => null, // التمرين الجاري (حتى يكمل لو سكّر التطبيق)
    ];
}
