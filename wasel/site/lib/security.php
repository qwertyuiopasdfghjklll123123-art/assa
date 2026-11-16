<?php
/**
 * طبقة الحماية العامة: ترويسات الأمان، HTTPS إجباري، حماية CSRF، تحديد معدل الطلبات،
 * سجل عمليات الأدمن، وتنبيه محاولات الدخول المشبوهة.
 */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function is_local_host(): bool
{
    $h = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    return in_array($h, ['localhost', '127.0.0.1', '::1'], true);
}

/** يُستدعى أول كل طلب: يقفل الأخطاء التفصيلية، يفرض HTTPS، ويضيف ترويسات الأمان */
function security_bootstrap(): void
{
    // (20) لا رسائل أخطاء تفصيلية للزوار - تنكتب بسجل السيرفر فقط
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
    set_exception_handler(function (Throwable $e) {
        error_log('[واصل] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'صار خطأ غير متوقع، حاول مرة ثانية'], JSON_UNESCAPED_UNICODE);
    });

    // (4) HTTPS إجباري: يتفعّل تلقائياً لما يكون الموقع منصّب على HTTPS (ما يكسر موقع بدون شهادة)
    if (!is_https() && !is_local_host() && PHP_SAPI !== 'cli' && force_https_enabled()) {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
        exit;
    }

    // (14) ترويسات الأمان
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    header('X-Permitted-Cross-Domain-Policies: none');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://accounts.google.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://accounts.google.com; "
        . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
        . "img-src 'self' data: blob: https:; connect-src 'self' https://accounts.google.com; "
        . "frame-src https://accounts.google.com; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"
        . (is_https() ? '; upgrade-insecure-requests' : ''));
    // (13) CORS: ما نضيف أي Access-Control-Allow-Origin، فالمتصفحات تمنع المواقع الثانية من قراءة الردود
}

function force_https_enabled(): bool
{
    if (!function_exists('settings') || !APP_INSTALLED) {
        return false;
    }
    return !empty(settings()['force_https']);
}

/**
 * (16) حماية CSRF: أي طلب يغيّر بيانات لازم يكون من نفس الموقع.
 * - لو فيه Origin أو Referer لازم يطابق الدومين
 * - لو ماكو ولا واحد منهم، لازم الترويسة X-Requested-With (تضيفها واجهة الموقع فقط)
 */
function csrf_check(string $method, string $path): void
{
    if (!in_array($method, ['POST', 'DELETE', 'PUT', 'PATCH'], true)) {
        return;
    }
    // نقاط تستلم من خارج الموقع وعندها حمايتها الخاصة (مفتاح/توقيع)
    foreach (['/engine_hook', '/api/v1/', '/telegram/webhook/', '/cron'] as $pfx) {
        if (strpos($path, $pfx) === 0) {
            return;
        }
    }
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $src = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($src === '' || $src === 'null') {
        $src = (string)($_SERVER['HTTP_REFERER'] ?? '');
    }
    if ($src !== '') {
        $u = parse_url($src);
        $srcHost = strtolower(($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : ''));
        if ($srcHost !== $host) {
            security_log('csrf_blocked', $src);
            json_err('طلب مرفوض', 403);
        }
        return;
    }
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'wasel') {
        security_log('csrf_blocked', 'no-origin');
        json_err('طلب مرفوض', 403);
    }
}

/**
 * (12) تحديد معدل الطلبات لكل IP: يرجع false لو تعدّى الحد.
 * $bucket اسم العملية، $max عدد المحاولات خلال $window ثانية.
 */
function rate_limit(string $bucket, int $max, int $window, ?string $who = null): bool
{
    $key = $bucket . '|' . ($who ?? client_ip_addr());
    $ok = true;
    store_update_doc('ratelimit_ip', function ($d) use ($key, $max, $window, &$ok) {
        $now = time();
        // تنظيف دوري للمفاتيح القديمة
        if (count($d) > 2000) {
            foreach ($d as $k => $times) {
                if (!$times || max($times) < $now - 3600) {
                    unset($d[$k]);
                }
            }
        }
        $times = array_values(array_filter($d[$key] ?? [], fn($t) => $now - $t < $window));
        if (count($times) >= $max) {
            $ok = false;
        } else {
            $times[] = $now;
        }
        $d[$key] = $times;
        return $d;
    });
    return $ok;
}

function rate_limit_or_fail(string $bucket, int $max, int $window, string $msg = 'طلبات كثيرة، انتظر قليلاً وحاول مرة ثانية'): void
{
    if (!rate_limit($bucket, $max, $window)) {
        security_log('rate_limited', $bucket);
        json_err($msg, 429);
    }
}

/** (8) سجل الأمان وعمليات الأدمن (بدون بيانات حساسة) - آخر 500 */
function security_log(string $action, string $detail = ''): void
{
    store_update_all('security_log', function (array &$rows) use ($action, $detail) {
        $max = 0;
        foreach ($rows as $r) {
            $max = max($max, (int)$r['id']);
        }
        $id = $max + 1;
        $rows[(string)$id] = [
            'id' => $id, 'action' => $action, 'detail' => clean_text(mb_substr($detail, 0, 200)),
            'user_id' => function_exists('uid') ? uid() : null, 'ip' => client_ip_addr(), 'time' => now_str(),
        ];
        if (count($rows) > 500) {
            ksort($rows, SORT_NUMERIC);
            $rows = array_slice($rows, -500, null, true);
        }
    });
}

function admin_log(string $action, string $detail = ''): void
{
    security_log('admin:' . $action, $detail);
}

/** (8) تنبيه الأدمن لما تتكرر محاولات دخول فاشلة من نفس الـ IP */
function failed_login_alert(): void
{
    $ip = client_ip_addr();
    $recent = array_filter(store_all('login_log'), fn($l) => !$l['ok'] && $l['ip'] === $ip && strtotime($l['time']) > time() - 900);
    if (count($recent) === 5 && function_exists('notify_admins')) {
        notify_admins('⚠️ محاولات دخول مشبوهة', "5 محاولات دخول فاشلة من IP {$ip} خلال 15 دقيقة", 'warning');
        security_log('suspicious_logins', $ip);
    }
}
