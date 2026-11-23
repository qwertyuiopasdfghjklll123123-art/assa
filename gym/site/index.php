<?php
/**
 * تمريني - تطبيق جدول التمارين والوجبات (PWA) بقاعدة بيانات JSON.
 * كل الطلبات تمر من هنا (.htaccess يحولها).
 */
declare(strict_types=1);

require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/push.php';
require __DIR__ . '/lib/api.php';

security_headers();
date_default_timezone_set('Asia/Baghdad');

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/** رقم نسخة الملفات (يتغير مع أي تعديل حتى المتصفح يجيب الجديد) */
function asset_version(): string
{
    $t = 0;
    foreach (['app.js', 'anim.js', 'ui.css', 'gym.css', 'sw.js', 'exercises.json', 'app.html'] as $f) {
        $t = max($t, (int)@filemtime(VIEWS_DIR . '/' . $f));
    }
    return base_convert((string)$t, 10, 36);
}

function serve_file(string $file, string $type, bool $immutable = false, bool $replaceV = false): never
{
    if (!is_file($file)) {
        http_response_code(404);
        exit;
    }
    $body = (string)file_get_contents($file);
    if ($replaceV) {
        $body = str_replace('__V__', asset_version(), $body);
    }
    $etag = '"' . md5($body) . '"';
    header('Content-Type: ' . $type);
    header('ETag: ' . $etag);
    header('Cache-Control: ' . ($immutable && isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    if (strlen($body) > 1024 && str_contains((string)($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') && !preg_match('#^(image|font)/#', $type)) {
        $body = gzencode($body, 6);
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

$types = ['css' => 'text/css; charset=utf-8', 'js' => 'application/javascript; charset=utf-8', 'json' => 'application/json; charset=utf-8',
    'woff2' => 'font/woff2', 'png' => 'image/png', 'svg' => 'image/svg+xml'];

$routes = array_merge(api_routes(), [
    // الواجهة (تطبيق صفحة وحدة)
    ['GET', '#^/(?:index\.php)?$#', function () {
        $uid = current_uid();
        $boot = $uid && user_find($uid) ? state_for($uid) : ['ok' => true, 'user' => null, 'vapid' => config()['vapid_public']];
        $html = str_replace(['__V__', '__APP__', '__BOOT__'], [asset_version(), APP_NAME, json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)],
            (string)file_get_contents(VIEWS_DIR . '/app.html'));
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
        exit;
    }],
    ['GET', '#^/assets/(app\.js|anim\.js|ui\.css|gym\.css|exercises\.json|logo\.png|vendor/[a-z0-9-]+\.(?:css|woff2))$#', function ($f) use ($types) {
        serve_file(VIEWS_DIR . '/' . $f, $types[pathinfo($f, PATHINFO_EXTENSION)], true, (bool)preg_match('/\.(css|js)$/', $f));
    }],
    ['GET', '#^/sw\.js$#', function () {
        header('Service-Worker-Allowed: /');
        serve_file(VIEWS_DIR . '/sw.js', 'application/javascript; charset=utf-8', false, true);
    }],
    ['GET', '#^/(icon-192\.png|icon-512\.png|icon-maskable\.png)$#', function ($f) {
        serve_file(VIEWS_DIR . '/' . $f, 'image/png');
    }],
    ['GET', '#^/app\.webmanifest$#', function () {
        header('Content-Type: application/manifest+json; charset=utf-8');
        echo json_encode([
            'name' => APP_NAME . ' - جدول التمارين والوجبات', 'short_name' => APP_NAME, 'start_url' => '/', 'scope' => '/', 'display' => 'standalone',
            'dir' => 'rtl', 'lang' => 'ar', 'background_color' => '#f3f7f8', 'theme_color' => '#04788b', 'orientation' => 'portrait',
            'icons' => [['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'], ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
                ['src' => '/icon-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }],
    // Cron: كل دقيقة (تنبيهات الوجبات ونهاية الراحة)
    ['GET', '#^/cron$#', function () {
        if (!hash_equals(config()['cron_key'], (string)($_GET['key'] ?? ''))) {
            json_err('مفتاح غلط', 403);
        }
        json_out(['ok' => true] + run_due());
    }],
]);

config(); // أول تشغيل: يولّد المفاتيح
if (str_starts_with($path, '/api/')) {
    tick_maybe();
}
foreach ($routes as [$m, $re, $fn]) {
    if ($m === $method && preg_match($re, $path, $mt)) {
        array_shift($mt);
        $fn(...$mt);
        exit;
    }
}
if (str_starts_with($path, '/api/')) {
    json_err('غير موجود', 404);
}
http_response_code(404);
header('Location: /');
