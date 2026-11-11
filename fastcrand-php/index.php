<?php
/**
 * FastCrand — شركة برمجيات & متجر تطبيقات متكامل (All-in-One PHP)
 *
 * الأقسام:
 *   - القسم الأول: البطاقة التعريفية + تطبيقات الشركة (شريطان بحركة متعاكسة).
 *   - القسم الثاني: من نحن + المقالات.
 *   - القسم الثالث: المهارات + التواصل معنا + عداد زوار الموقع.
 *
 * تحديثات الأمان والاستقرار (مراجعة شاملة):
 *   - حماية مجلد البيانات data/ من الوصول المباشر (كان يكشف هاش كلمة المرور وتوكن تيليجرام وأرقام العملاء).
 *   - منع تنفيذ أي سكربت داخل مجلد uploads/ ومنع رفع SVG (ثغرة XSS مخزّنة).
 *   - حماية CSRF لكل عمليات لوحة التحكم (الحفظ والحذف واختبار البوت).
 *   - تجديد معرف الجلسة عند الدخول + حماية من تخمين كلمة المرور + كوكيز جلسة آمنة.
 *   - منع ثغرة Path Traversal في تنزيل ملفات APK (existing_apk_file).
 *   - تهريب (Escape) كل البيانات المعروضة في الواجهة لمنع XSS.
 *   - كتابة ملفات JSON بشكل ذري مع قفل لمنع تلف البيانات عند الزيارات المتزامنة.
 *   - تحديد طول المدخلات ومعدل الإرسال للطلبات والتقييمات لمنع السبام.
 *   - تفعيل التحقق من شهادة SSL عند الاتصال بتيليجرام.
 */

declare(strict_types=1);

// ===================== إعدادات عامة وجلسة آمنة =====================
if (!ini_get('date.timezone')) {
    date_default_timezone_set('Asia/Baghdad');
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

define('DATA_DIR', __DIR__ . '/data/');
define('UPLOADS_DIR', __DIR__ . '/uploads/');
define('MAX_APK_SIZE', 50 * 1024 * 1024);   // 50MB
define('MAX_IMAGE_SIZE', 8 * 1024 * 1024);  // 8MB
define('IMAGE_EXTS', ['png', 'jpg', 'jpeg', 'webp', 'gif']); // ملاحظة: SVG ممنوع لأنه يسمح بتنفيذ JavaScript
define('ADMIN_TABS', ['apps', 'settings', 'telegram', 'articles']);
define('APP_COLORS', ['blue', 'cyan', 'gold', 'pink', 'green', 'purple']);

if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0755, true);
if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0755, true);

// منع الوصول المباشر لمجلد البيانات (Apache / LiteSpeed - Hostinger)
if (!file_exists(DATA_DIR . '.htaccess')) {
    @file_put_contents(DATA_DIR . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
}
if (!file_exists(DATA_DIR . 'index.html')) {
    @file_put_contents(DATA_DIR . 'index.html', '');
}
// منع تنفيذ أي سكربت داخل مجلد الرفع
if (!file_exists(UPLOADS_DIR . '.htaccess')) {
    @file_put_contents(UPLOADS_DIR . '.htaccess',
        "Options -Indexes -ExecCGI\n" .
        "<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|cgi|sh|svg|html?|js)$\">\n  Require all denied\n</FilesMatch>\n" .
        "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n" .
        "<IfModule mod_php7.c>\n  php_flag engine off\n</IfModule>\n" .
        "<IfModule mod_php8.c>\n  php_flag engine off\n</IfModule>\n"
    );
}
if (!file_exists(UPLOADS_DIR . 'index.html')) {
    @file_put_contents(UPLOADS_DIR . 'index.html', '');
}

$configFile       = DATA_DIR . 'site_config.json';
$appsFile         = DATA_DIR . 'site_apps.json';
$articlesFile     = DATA_DIR . 'site_articles.json';
$visitorsFile     = DATA_DIR . 'site_visitors.json';
$reviewsFile      = DATA_DIR . 'site_reviews.json';
$demoRequestsFile = DATA_DIR . 'demo_requests.json';

// ===================== دوال مساعدة =====================

/** تهريب النص لعرضه داخل HTML */
function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function loadJson(string $path): array {
    if (!is_file($path)) return [];
    $fp = @fopen($path, 'rb');
    if (!$fp) return [];
    @flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    @flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

/** كتابة ذرية: ملف مؤقت ثم rename لمنع تلف الملف عند الكتابة المتزامنة */
function saveJson(string $path, $data): bool {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        return @file_put_contents($path, $json, LOCK_EX) !== false;
    }
    return true;
}

/** تعديل ملف JSON تحت قفل حصري (للعدادات والإضافات المتزامنة) */
function updateJson(string $path, callable $fn) {
    $fp = @fopen($path, 'c+');
    if (!$fp) return null;
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) $data = [];
    $result = $fn($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

/** قص النص لطول أقصى بأمان مع UTF-8 */
function clip($v, int $max): string {
    $s = trim((string)($v ?? ''));
    return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
}

/** قبول روابط http/https فقط (لمنع javascript: وغيرها) */
function safeUrl($url): string {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) return '';
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

/** اسم ملف آمن داخل uploads (بدون مسارات) */
function safeUploadName($name): string {
    $name = basename((string)$name);
    return preg_match('/^[A-Za-z0-9._-]+$/', $name) ? $name : '';
}

/** حفظ صورة مرفوعة بعد التحقق من الامتداد والمحتوى الحقيقي */
function saveUploadedImage(array $file, string $prefix): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return '';
    if (!is_uploaded_file($file['tmp_name'])) return '';
    if (($file['size'] ?? 0) > MAX_IMAGE_SIZE) return '';
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, IMAGE_EXTS, true)) return '';
    if (@getimagesize($file['tmp_name']) === false) return '';
    $fn = $prefix . bin2hex(random_bytes(8)) . '.' . $ext;
    return move_uploaded_file($file['tmp_name'], UPLOADS_DIR . $fn) ? $fn : '';
}

function deleteUpload($name): void {
    $name = safeUploadName($name);
    if ($name !== '' && is_file(UPLOADS_DIR . $name)) @unlink(UPLOADS_DIR . $name);
}

/** تحويل رقم واتساب إلى صيغة دولية لرابط wa.me */
function waDigits($number): string {
    $digits = preg_replace('/\D/', '', (string)$number);
    if (preg_match('/^0?7[3-9]\d{8}$/', $digits)) {
        return '964' . ltrim($digits, '0');
    }
    return ltrim(preg_replace('/^00/', '', $digits), '0');
}

/** رابط واتساب صالح من رقم أو رابط مدخل في الإعدادات */
function whatsappLink($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';
    if (preg_match('#^https?://#i', $value)) return safeUrl($value);
    $d = waDigits($value);
    return $d !== '' ? 'https://wa.me/' . $d : '';
}

function jsonResponse(array $payload, int $code = 200): void {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** تحديد بسيط لمعدل الطلبات لكل جلسة */
function rateLimited(string $key, int $seconds): bool {
    $now = time();
    $last = (int)($_SESSION['rl_' . $key] ?? 0);
    if ($now - $last < $seconds) return true;
    $_SESSION['rl_' . $key] = $now;
    return false;
}

// ===================== CSRF =====================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function csrfValid(): bool {
    $t = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && $t !== '' && hash_equals($_SESSION['csrf_token'] ?? '', $t);
}

// ===================== البيانات الافتراضية =====================
if (!file_exists($configFile)) {
    $defaultConfig = [
        'name' => 'FastCrand',
        'role' => 'مجموعة مطورين مواقع وتطبيقات بجميع لغات البرمجة',
        'about' => 'نحن مجموعة من المطورين المحترفين المتخصصين في بناء المواقع والتطبيقات بكافة لغات البرمجة. نقدم حلولاً برمجية متكاملة لعملائنا بجودة عالية وأسعار تنافسية.',
        'photo' => '',
        'base_visitors' => 12540,
        'counter_active' => true,
        'whatsapp_url' => 'https://wa.me/9647763835403',
        'instagram_user' => 'fastcrand_official',
        'instagram_status' => 'disabled',
        'instagram_message' => "نأسف للإزعاج، حسابنا على إنستغرام معطل حالياً بسبب مشكلة تقنية.\nنعمل على حل المشكلة في أقرب وقت ممكن.\nيمكنك متابعة آخر مستجداتنا عبر قنوات التواصل الأخرى.",
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
        'admin_user' => 'admin',
        // ⚠️ كلمة المرور الافتراضية admin123 — غيّرها فوراً من لوحة التحكم بعد أول دخول
        'admin_pass' => password_hash('admin123', PASSWORD_DEFAULT)
    ];
    saveJson($configFile, $defaultConfig);
}

if (!file_exists($visitorsFile)) {
    saveJson($visitorsFile, ['count' => 0]);
}

// دمج الإعدادات مع القيم الافتراضية لتفادي أخطاء المفاتيح الناقصة
$siteConfig = array_merge([
    'name' => 'FastCrand', 'role' => '', 'about' => '',
    'photo' => '', 'base_visitors' => 10000, 'counter_active' => true,
    'whatsapp_url' => '', 'instagram_user' => '', 'instagram_status' => 'disabled',
    'instagram_message' => '',
    'telegram_bot_token' => '', 'telegram_chat_id' => '',
    'admin_user' => 'admin', 'admin_pass' => '',
], loadJson($configFile));

// زيادة عداد الزوار مرة واحدة لكل جلسة (تحت قفل لمنع فقدان الزيارات المتزامنة)
if (!empty($siteConfig['counter_active']) && !isset($_SESSION['has_visited_site'])) {
    $_SESSION['has_visited_site'] = true;
    $increment = random_int(2, 5);
    updateJson($visitorsFile, function (array &$d) use ($increment) {
        $d['count'] = (int)($d['count'] ?? 0) + $increment;
    });
}
$visitorsData = loadJson($visitorsFile) ?: ['count' => 0];

// التطبيقات الافتراضية
if (!file_exists($appsFile)) {
    $defaultApps = [
        [
            'id' => 'app_1',
            'title' => 'صار',
            'category' => 'استضافة وسيرفرات',
            'description' => 'منصة بيع VPS واستضافة سحابية عالية الأداء مع لوحة تحكم ذكية وإدارة فورية للموارد.',
            'version' => '2.4.0',
            'icon' => '',
            'icon_class' => 'fas fa-server',
            'color' => 'blue',
            'downloads' => 1420,
            'apk_file' => '',
            'screenshots' => [],
            'features' => ['سيرفرات سحابية فائقة السرعة', 'حماية DDoS متقدمة', 'دعم فني 24/7'],
        ],
        [
            'id' => 'app_2',
            'title' => 'بوتي تي في',
            'category' => 'أدوات تسويق',
            'description' => 'نظام إدارة حملات واتساب تلقائية متقدم مع روبوت رد آلي وإحصائيات تفاعلية مباشرة.',
            'version' => '1.8.5',
            'icon' => '',
            'icon_class' => 'fab fa-whatsapp',
            'color' => 'cyan',
            'downloads' => 890,
            'apk_file' => '',
            'screenshots' => [],
            'features' => ['إرسال رسائل جماعية ذكية', 'جدولة الحملات بدقة', 'فلترة الأرقام التلقائية'],
        ],
        [
            'id' => 'app_3',
            'title' => 'SMM DSM',
            'category' => 'خدمات رقمية',
            'description' => 'موقع متخصص في تزويد وبيع خدمات السوشيل ميديا بسرعة فائقة وأسعار منافسة.',
            'version' => '3.0.1',
            'icon' => '',
            'icon_class' => 'fas fa-share-nodes',
            'color' => 'gold',
            'downloads' => 2340,
            'apk_file' => '',
            'screenshots' => [],
            'features' => ['تنفيذ فوري للطلبات', 'دعم جميع المنصات الاجتماعية', 'واجهة API للموزعين'],
        ],
        [
            'id' => 'app_4',
            'title' => 'FastCrand Hub',
            'category' => 'خدمات واشتراكات',
            'description' => 'بيع خدمات سوشيل ميديا واشتراكات متنوعة وإدارة الحسابات الرقمية.',
            'version' => '1.0.0',
            'icon' => '',
            'icon_class' => 'fas fa-rocket',
            'color' => 'pink',
            'downloads' => 3120,
            'apk_file' => '',
            'screenshots' => [],
            'features' => ['اشتراكات بريميوم مضمونة', 'دفع إلكتروني آمن', 'تسليم آلي وفوري'],
        ],
        [
            'id' => 'app_5',
            'title' => 'الملا',
            'category' => 'متاجر ومبيعات',
            'description' => 'نظام بيع سيراميك وإدارة الطلبات والمخازن مع فواتير وإشعارات ذكية.',
            'version' => '2.1.0',
            'icon' => '',
            'icon_class' => 'fas fa-store',
            'color' => 'green',
            'downloads' => 560,
            'apk_file' => '',
            'screenshots' => [],
            'features' => ['إدارة المخزون بدقة', 'طباعة فواتير حرارية وPDF', 'تتبع المندوبين'],
        ],
        [
            'id' => 'app_6',
            'title' => 'Tok Mart',
            'category' => 'تجارة إلكترونية',
            'description' => 'متجر متكامل لبيع المنتجات المنزلية وتوصيلها السريع مع تتبع فوري للشحنات.',
            'version' => '1.5.0',
            'icon' => '',
            'icon_class' => 'fas fa-basket-shopping',
            'color' => 'purple',
            'downloads' => 1780,
            'apk_file' => '',
            'screenshots' => [],
            'features' => ['سلة تسوق خفيفة وسلسة', 'إشعارات بوصول الطلب', 'عروض وخصومات يومية'],
        ],
        [
            'id' => 'app_7',
            'title' => 'مندوب صيدلية',
            'category' => 'إدارة وتوصيل',
            'description' => 'نظام متكامل لإدارة فواتير توصيل الأدوية والحسابات اليومية والربح وإرسال تقارير واتساب.',
            'version' => '2.0.0',
            'icon' => '',
            'icon_class' => 'fas fa-pills',
            'color' => 'blue',
            'downloads' => 950,
            'apk_file' => '',
            'screenshots' => [],
            'features' => ['حساب أرباح تلقائي', 'إرسال فواتير واتساب', 'إحصائيات يومية'],
        ],
        [
            'id' => 'app_8',
            'title' => 'كلاود شات',
            'category' => 'محادثات وتواصل',
            'description' => 'تطبيق دردشة مشفر وسريع يدعم المكالمات ومشاركة الملفات الضخمة.',
            'version' => '1.2.0',
            'icon' => '',
            'icon_class' => 'fas fa-comments',
            'color' => 'cyan',
            'downloads' => 1120,
            'apk_file' => '',
            'screenshots' => [],
            'features' => ['تشفير تام للرسائل', 'غرف محادثة جماعية', 'خفيف وسريع'],
        ]
    ];
    saveJson($appsFile, $defaultApps);
}

// المقالات الافتراضية
if (!file_exists($articlesFile)) {
    $defaultArticles = [
        [
            'id' => 'art_1',
            'title' => 'أفضل ممارسات تطوير الويب 2026',
            'description' => 'نصائح وإرشادات لتطوير مواقع ويب بجودة عالية وأحدث التقنيات.',
            'badge' => 'تطوير',
            'badge_color' => 'blue',
            'date' => '2026-01-15',
            'read_time' => 5,
            'full' => 'تطوير الويب هو مجال ديناميكي يتطور باستمرار، ويحتاج المطورون إلى مواكبة أحدث الممارسات لضمان جودة وكفاءة المواقع. تشمل الممارسات الأساسية استخدام إطار عمل مناسب، كتابة كود نظيف وقابل للصيانة، تحسين أداء الموقع، واعتماد معايير الأمان الحديثة مثل HTTPS وCORS.',
            'author' => 'أحمد محمد',
        ],
        [
            'id' => 'art_2',
            'title' => 'دليل اختيار لغة البرمجة المناسبة',
            'description' => 'كيف تختار اللغة المناسبة لمشروعك البرمجي وفق متطلباتك.',
            'badge' => 'برمجة',
            'badge_color' => 'cyan',
            'date' => '2026-01-10',
            'read_time' => 4,
            'full' => 'اختيار لغة البرمجة المناسبة يعتمد على عدة عوامل منها نوع المشروع، حجم الفريق، وقت التطوير، والميزانية. للويب: JavaScript وPython هي خيارات شائعة، لتطبيقات الجوال: Swift وKotlin أو أطر مثل Flutter وReact Native.',
            'author' => 'سارة علي',
        ],
        [
            'id' => 'art_3',
            'title' => 'تصميم تطبيقات الجوال UX',
            'description' => 'أساسيات تجربة المستخدم في تطبيقات الجوال لتحقيق أعلى تفاعل.',
            'badge' => 'تصميم',
            'badge_color' => 'pink',
            'date' => '2026-01-05',
            'read_time' => 3,
            'full' => 'تصميم تجربة المستخدم (UX) هو عامل حاسم في نجاح أي تطبيق جوال. يجب أن يكون التصميم بديهياً وسهل الاستخدام مع التركيز على احتياجات المستخدم وسرعة التنقل وجمالية الواجهات.',
            'author' => 'نور إبراهيم',
        ]
    ];
    saveJson($articlesFile, $defaultArticles);
}

// ===================== تيليجرام =====================
function telegramPost(string $url, array $params): bool {
    $body = http_build_query($params);
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT        => 6,
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-type: application/x-www-form-urlencoded\r\n",
                'content'       => $body,
                'timeout'       => 6,
                'ignore_errors' => true,
            ],
        ]);
        $res = @file_get_contents($url, false, $context);
    }
    if ($res === false || $res === null) return false;
    $json = json_decode((string)$res, true);
    return !empty($json['ok']);
}

function sendTelegramMessage($token, $chatId, $message, $replyMarkup = null): bool {
    $cleanToken = trim((string)$token);
    // إزالة كلمة bot في حال نسخها المستخدم مع التوكن
    if (stripos($cleanToken, 'bot') === 0) {
        $cleanToken = substr($cleanToken, 3);
    }
    $chatId = trim((string)$chatId);
    // التحقق من صيغة التوكن لمنع حقن المسار في الرابط
    if (!preg_match('/^\d+:[A-Za-z0-9_-]+$/', $cleanToken) || $chatId === '') return false;

    $url = 'https://api.telegram.org/bot' . $cleanToken . '/sendMessage';
    $markup = null;
    if (!empty($replyMarkup)) {
        $markup = is_array($replyMarkup) ? json_encode($replyMarkup, JSON_UNESCAPED_UNICODE) : (string)$replyMarkup;
    }

    $params = [
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ];
    if ($markup) $params['reply_markup'] = $markup;
    if (telegramPost($url, $params)) return true;

    // محاولة ثانية كنص عادي في حال كان الخطأ بسبب وسوم HTML
    $plain = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $message)), ENT_QUOTES, 'UTF-8');
    $paramsPlain = ['chat_id' => $chatId, 'text' => $plain];
    if ($markup) $paramsPlain['reply_markup'] = $markup;
    return telegramPost($url, $paramsPlain);
}

$siteApps = loadJson($appsFile);
$siteArticles = loadJson($articlesFile);
$isAdmin = !empty($_SESSION['fc_admin_logged']);

function findAppIndex(array $apps, $id): int {
    foreach ($apps as $i => $a) {
        if (($a['id'] ?? null) === $id) return (int)$i;
    }
    return -1;
}

// حساب إجمالي الزوار
$totalVisitorsCount = (int)($siteConfig['base_visitors'] ?? 10000) + (int)($visitorsData['count'] ?? 0);

// ===================== تنزيل التطبيق / زيادة عداد التحميل =====================
if (isset($_GET['download_app']) || isset($_GET['increment_download'])) {
    $isAjax = isset($_GET['increment_download']);
    $targetId = (string)($_GET['download_app'] ?? $_GET['increment_download']);
    $idx = findAppIndex($siteApps, $targetId);

    if ($idx < 0) {
        if ($isAjax) jsonResponse(['success' => false], 404);
        header('Location: ./');
        exit;
    }

    // زيادة العداد مرة واحدة لكل تطبيق في الجلسة (منع التلاعب بالتحديث المتكرر)
    if (($siteApps[$idx]['status'] ?? 'live') === 'wip') {
        if ($isAjax) jsonResponse(['success' => false], 403);
        header('Location: ./');
        exit;
    }
    $downloads = (int)($siteApps[$idx]['downloads'] ?? 0);
    if (empty($_SESSION['dl_counted'][$targetId])) {
        $_SESSION['dl_counted'][$targetId] = true;
        $downloads = (int)updateJson($appsFile, function (array &$apps) use ($targetId) {
            foreach ($apps as &$a) {
                if (($a['id'] ?? null) === $targetId) {
                    $a['downloads'] = (int)($a['downloads'] ?? 0) + 1;
                    return $a['downloads'];
                }
            }
            return 0;
        });
    }

    if ($isAjax) jsonResponse(['success' => true, 'downloads' => $downloads]);

    $app = $siteApps[$idx];
    $apkName = safeUploadName($app['apk_file'] ?? '');
    $filePath = UPLOADS_DIR . $apkName;

    if ($apkName !== '' && preg_match('/\.apk$/i', $apkName) && is_file($filePath)) {
        $dlTitle = (string)($app['title'] ?? 'app');
        $asciiName = preg_replace('/[^A-Za-z0-9._-]+/', '_', $dlTitle) ?: 'app';
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="' . $asciiName . '.apk"; filename*=UTF-8\'\'' . rawurlencode($dlTitle) . '.apk');
        header('Content-Length: ' . filesize($filePath));
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
    }

    // لا يوجد ملف APK: تحويل لرابط المتجر أو الموقع بدل إرسال ملف APK تالف
    $fallback = safeUrl($app['android_url'] ?? '') ?: safeUrl($app['web_url'] ?? '');
    header('Location: ' . ($fallback ?: './'));
    exit;
}

// ===================== تقييم المستخدم لتطبيق =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_user_review') {
    $appId = (string)($_POST['app_id'] ?? '');
    if (findAppIndex($siteApps, $appId) < 0) {
        jsonResponse(['success' => false, 'message' => 'تطبيق غير موجود'], 404);
    }
    if (!empty($_SESSION['reviewed'][$appId]) || rateLimited('review', 10)) {
        jsonResponse(['success' => false, 'message' => 'تم استلام تقييمك مسبقاً'], 429);
    }

    $rating = max(1, min(5, (int)($_POST['stars'] ?? 5)));
    $newReview = [
        'id' => 'rev_' . bin2hex(random_bytes(6)),
        'author' => clip($_POST['author_name'] ?? '', 60) ?: 'مستخدم FastCrand',
        'stars' => $rating,
        'comment' => clip($_POST['comment'] ?? '', 1000),
        'date' => date('Y-m-d'),
    ];
    updateJson($reviewsFile, function (array &$all) use ($appId, $newReview) {
        if (!isset($all[$appId]) || !is_array($all[$appId])) $all[$appId] = [];
        array_unshift($all[$appId], $newReview);
        $all[$appId] = array_slice($all[$appId], 0, 500);
    });
    $_SESSION['reviewed'][$appId] = true;

    jsonResponse(['success' => true, 'review' => $newReview]);
}

// ===================== طلب عرض تقديمي / نموذج تجريبي =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? $_GET['action'] ?? '') === 'request_demo_presentation')) {
    // حقل مخفي لاصطياد البوتات (honeypot)
    if (!empty($_POST['website'])) {
        jsonResponse(['success' => true, 'message' => 'تم إرسال طلبك قيد التدقيق']);
    }

    $name = clip($_POST['name'] ?? '', 100);
    $project = clip($_POST['project_details'] ?? $_POST['project'] ?? '', 3000);
    $whatsapp = clip($_POST['whatsapp'] ?? '', 30);
    $cleanWhatsapp = preg_replace('/[^\d]/', '', preg_replace('/^(\+|00)/', '', $whatsapp));

    if ($name === '' || $cleanWhatsapp === '') {
        jsonResponse(['success' => false, 'message' => 'يرجى إدخال اسمك ورقم الواتساب']);
    }
    if (strlen($cleanWhatsapp) < 7 || strlen($cleanWhatsapp) > 15) {
        jsonResponse(['success' => false, 'message' => 'رقم الواتساب غير صحيح']);
    }
    if (rateLimited('demo', 60)) {
        jsonResponse(['success' => false, 'message' => 'لقد أرسلت طلباً قبل قليل، يرجى الانتظار دقيقة ثم المحاولة مجدداً.'], 429);
    }

    $newReq = [
        'id' => 'demo_' . bin2hex(random_bytes(6)),
        'name' => $name,
        'whatsapp' => $cleanWhatsapp,
        'project_details' => $project,
        'date' => date('Y-m-d H:i:s'),
    ];
    updateJson($demoRequestsFile, function (array &$all) use ($newReq) {
        array_unshift($all, $newReq);
        $all = array_slice($all, 0, 2000);
    });

    // إشعار تيليجرام برقم الواتساب ورابط مباشر لفتح المحادثة
    $tgSent = false;
    $botToken = trim((string)$siteConfig['telegram_bot_token']);
    $chatId = trim((string)$siteConfig['telegram_chat_id']);
    if ($botToken !== '' && $chatId !== '') {
        try {
            $waLink = 'https://wa.me/' . waDigits($cleanWhatsapp);
            $tgText  = "💼 <b>طلب عرض تقديمي ونموذج تجريبي جديد!</b>\n\n";
            $tgText .= "👤 <b>اسم العميل:</b> " . e($name) . "\n";
            $tgText .= "📱 <b>رقم الواتساب:</b> <code>" . e($cleanWhatsapp) . "</code>\n";
            $tgText .= "💬 <b>رابط واتساب مباشر لمراسلة العميل:</b>\n" . e($waLink) . "\n\n";
            $tgText .= "💡 <b>فكرة ومتطلبات المشروع:</b>\n" . e($project ?: 'لم تذكر تفاصيل') . "\n\n";
            $tgText .= "🕒 <b>التاريخ والوقت:</b> " . date('Y-m-d H:i:s') . "\n";
            $tgText .= "🌐 <b>منصة:</b> " . e($siteConfig['name'] ?: 'FastCrand');

            $replyMarkup = ['inline_keyboard' => [[['text' => '💬 مراسلة العميل عبر واتساب فوراً', 'url' => $waLink]]]];
            $tgSent = sendTelegramMessage($botToken, $chatId, $tgText, $replyMarkup);
        } catch (\Throwable $ex) {
            error_log('Telegram send exception: ' . $ex->getMessage());
        }
    }

    jsonResponse([
        'success' => true,
        'message' => 'تم إرسال طلبك قيد التدقيق',
        'telegram_sent' => $tgSent,
    ]);
}

// ===================== اختبار بوت التيليجرام (للمدير فقط) =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'test_telegram_bot')) {
    if (!$isAdmin) jsonResponse(['success' => false, 'message' => 'غير مصرح'], 403);
    if (!csrfValid()) jsonResponse(['success' => false, 'message' => 'انتهت صلاحية الجلسة، حدّث الصفحة وحاول مجدداً.'], 403);

    $token = trim((string)($_POST['token'] ?? '')) ?: (string)$siteConfig['telegram_bot_token'];
    $chatId = trim((string)($_POST['chat_id'] ?? '')) ?: (string)$siteConfig['telegram_chat_id'];
    if ($token === '' || $chatId === '') {
        jsonResponse(['success' => false, 'message' => 'يرجى كتابة التوكن والآيدي أولاً للتجربة.']);
    }

    $msg = "🔔 <b>رسالة اختبار من منصة " . e($siteConfig['name'] ?: 'FastCrand') . "</b>\n\nتهانينا! تم ربط بوت التيليجرام بنجاح وتعمل الإشعارات بشكل ممتاز.";
    if (sendTelegramMessage($token, $chatId, $msg)) {
        jsonResponse(['success' => true, 'message' => 'تم إرسال رسالة الاختبار بنجاح! تفقد محادثة البوت على تيليجرام.']);
    }
    jsonResponse(['success' => false, 'message' => 'تعذر إرسال الرسالة إلى تيليجرام. تأكد من صحة التوكن والآيدي، وأنك قمت بالدخول للبوت والضغط على Start أو /start أولاً.']);
}

// ===================== API بيانات الواجهة =====================
function buildPublicData(array $siteApps, array $siteArticles, array $siteConfig, int $totalVisitors): array {
    // حذف أي حقول حساسة من التطبيقات قبل إرسالها
    $publicApps = array_map(function ($a) {
        $a['android_url'] = safeUrl($a['android_url'] ?? '');
        $a['web_url'] = safeUrl($a['web_url'] ?? '');
        $a['has_apk'] = ($a['apk_file'] ?? '') !== '';
        $a['status'] = (($a['status'] ?? 'live') === 'wip') ? 'wip' : 'live';
        $a['progress'] = max(0, min(100, (int)($a['progress'] ?? 0)));
        unset($a['apk_file']);
        return $a;
    }, $siteApps);

    return [
        'profile' => [
            'name' => $siteConfig['name'],
            'role' => $siteConfig['role'],
            'about' => $siteConfig['about'],
            'available' => true,
            'photo' => safeUploadName($siteConfig['photo']),
        ],
        'works' => $publicApps,
        'articles' => $siteArticles,
        'reviews' => new stdClass(),
        'total_visitors' => $totalVisitors,
        'skills' => [
            ['name' => 'Python', 'icon' => 'fab fa-python', 'color' => 'var(--gradient-primary)', 'percentage' => 95],
            ['name' => 'JavaScript', 'icon' => 'fab fa-js', 'color' => 'linear-gradient(135deg,#ffa64d,#d08030)', 'percentage' => 92],
            ['name' => 'PHP', 'icon' => 'fab fa-php', 'color' => 'linear-gradient(135deg,#8b5cf6,#7c3aed)', 'percentage' => 88],
            ['name' => 'HTML & CSS', 'icon' => 'fab fa-html5', 'color' => 'linear-gradient(135deg,#ff6b8a,#e84a70)', 'percentage' => 96],
            ['name' => 'React / Vue', 'icon' => 'fab fa-react', 'color' => 'linear-gradient(135deg,#22c55e,#16a34a)', 'percentage' => 85],
            ['name' => 'Flask / Django', 'icon' => 'fas fa-flask', 'color' => 'linear-gradient(135deg,#f26a00,#d05a00)', 'percentage' => 90],
        ],
        'social' => [
            ['icon' => 'fab fa-whatsapp', 'url' => '#', 'platform' => 'واتساب', 'type' => 'whatsapp', 'action' => 'openWhatsAppContact'],
            ['icon' => 'fab fa-instagram', 'url' => '#', 'platform' => 'إنستغرام', 'type' => 'instagram', 'action' => 'showInstagramStatus'],
        ],
        'instagram_meta' => [
            'status' => $siteConfig['instagram_status'],
            'user' => $siteConfig['instagram_user'],
            'message' => $siteConfig['instagram_message'],
        ],
        'whatsapp_target' => whatsappLink($siteConfig['whatsapp_url']),
    ];
}

if (($_GET['api'] ?? '') === 'data') {
    session_write_close(); // تحرير قفل الجلسة حتى لا تتأخر الطلبات المتوازية
    jsonResponse(buildPublicData($siteApps, $siteArticles, $siteConfig, $totalVisitorsCount));
}

// ===================== لوحة التحكم =====================
$adminMsg = '';
$adminErr = '';

if (isset($_GET['admin_logout'])) {
    unset($_SESSION['fc_admin_logged']);
    session_regenerate_id(true);
    header('Location: ./');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_login') {
    $attempts = (int)($_SESSION['login_attempts'] ?? 0);
    $lockUntil = (int)($_SESSION['login_lock_until'] ?? 0);

    if ($lockUntil > time()) {
        $adminErr = 'محاولات كثيرة خاطئة. حاول مجدداً بعد ' . ($lockUntil - time()) . ' ثانية.';
    } elseif (!csrfValid()) {
        $adminErr = 'انتهت صلاحية الصفحة، حدّثها وحاول مجدداً.';
    } else {
        $u = trim((string)($_POST['username'] ?? ''));
        $p = (string)($_POST['password'] ?? '');
        if (hash_equals((string)$siteConfig['admin_user'], $u) && $siteConfig['admin_pass'] !== '' && password_verify($p, (string)$siteConfig['admin_pass'])) {
            session_regenerate_id(true);
            $_SESSION['fc_admin_logged'] = true;
            $_SESSION['login_attempts'] = 0;
            unset($_SESSION['login_lock_until']);
            // ترقية الهاش تلقائياً إن تغيرت خوارزمية PHP الافتراضية
            if (password_needs_rehash((string)$siteConfig['admin_pass'], PASSWORD_DEFAULT)) {
                $siteConfig['admin_pass'] = password_hash($p, PASSWORD_DEFAULT);
                saveJson($configFile, $siteConfig);
            }
            header('Location: ?admin=panel');
            exit;
        }
        usleep(400000); // إبطاء محاولات التخمين
        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['login_lock_until'] = time() + 300;
            $_SESSION['login_attempts'] = 0;
        }
        $adminErr = 'اسم المستخدم أو كلمة المرور غير صحيحة!';
    }
}

// أي عملية إدارية (POST أو حذف عبر GET) تتطلب رمز CSRF صحيح
$adminActionRequested = $isAdmin && (
    ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['save_site_settings', 'save_app', 'save_article'], true))
    || isset($_GET['delete_app']) || isset($_GET['delete_article']) || isset($_GET['delete_demo_req'])
);
$adminActionAllowed = $adminActionRequested && csrfValid();
if ($adminActionRequested && !$adminActionAllowed) {
    $adminErr = 'انتهت صلاحية الجلسة أو الطلب غير صالح (CSRF). حدّث الصفحة وأعد المحاولة.';
}

// حفظ إعدادات الموقع (نموذج الإعدادات ونموذج التيليجرام يستخدمان نفس الإجراء)
if ($adminActionAllowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_site_settings') {
    $textFields = [
        'name' => 100, 'role' => 300, 'about' => 3000,
        'whatsapp_url' => 300, 'instagram_user' => 100, 'instagram_message' => 2000,
        'telegram_bot_token' => 200, 'telegram_chat_id' => 50,
    ];
    foreach ($textFields as $field => $max) {
        if (isset($_POST[$field])) {
            $siteConfig[$field] = clip($_POST[$field], $max);
        }
    }
    $siteConfig['instagram_user'] = ltrim((string)$siteConfig['instagram_user'], '@');
    // لا نعيد ضبط حالة الإنستغرام إلا إذا أُرسلت فعلاً (نموذج التيليجرام لا يرسلها)
    if (isset($_POST['instagram_status']) && in_array($_POST['instagram_status'], ['active', 'disabled'], true)) {
        $siteConfig['instagram_status'] = $_POST['instagram_status'];
    }

    if (isset($_POST['base_visitors'])) {
        $siteConfig['base_visitors'] = max(0, (int)$_POST['base_visitors']);
    }
    if (isset($_POST['start_counter'])) {
        // تصفير الزيادات وبدء العد من الرقم المدخل
        $siteConfig['counter_active'] = true;
        saveJson($visitorsFile, ['count' => 0]);
        $visitorsData = ['count' => 0];
        $adminMsg = 'تم تفعيل بدء العد لعداد الزوار بنجاح!';
    } else {
        $adminMsg = 'تم حفظ إعدادات الموقع بنجاح!';
    }

    if (!empty($_POST['new_password'])) {
        if (strlen((string)$_POST['new_password']) < 8) {
            $adminErr = 'كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل. لم يتم تغييرها.';
        } else {
            $siteConfig['admin_pass'] = password_hash((string)$_POST['new_password'], PASSWORD_DEFAULT);
        }
    }

    if (isset($_FILES['site_photo'])) {
        $fn = saveUploadedImage($_FILES['site_photo'], 'site_logo_');
        if ($fn !== '') {
            deleteUpload($siteConfig['photo']);
            $siteConfig['photo'] = $fn;
        }
    }
    saveJson($configFile, $siteConfig);
    $totalVisitorsCount = (int)$siteConfig['base_visitors'] + (int)($visitorsData['count'] ?? 0);
}

// حفظ أو تعديل تطبيق باللغتين
if ($adminActionAllowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_app') {
    $postedId = trim((string)($_POST['app_id'] ?? ''));
    $appId = preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $postedId) ? $postedId : ('app_' . bin2hex(random_bytes(6)));

    $title = clip($_POST['title'] ?? '', 120);
    $category = clip($_POST['category'] ?? '', 80) ?: 'تطبيقات عامة';
    $desc = clip($_POST['description'] ?? '', 5000);
    $version = clip($_POST['version'] ?? '', 20) ?: '1.0.0';
    $web_url = safeUrl($_POST['web_url'] ?? '');
    $android_url = safeUrl($_POST['android_url'] ?? '');
    $downloads = max(0, (int)($_POST['downloads'] ?? 0));
    $rating = round(max(1, min(5, (float)($_POST['rating'] ?? 4.9))), 1);
    $color = in_array($_POST['color'] ?? '', APP_COLORS, true) ? $_POST['color'] : 'blue';
    $status = (($_POST['status'] ?? 'live') === 'wip') ? 'wip' : 'live';
    $progress = max(0, min(100, (int)($_POST['progress'] ?? 0)));
    $feats = array_values(array_filter(array_map(fn($f) => clip($f, 200), explode("\n", (string)($_POST['features'] ?? '')))));

    if ($title === '') {
        $adminErr = 'اسم التطبيق مطلوب.';
    } else {
        $existingIndex = findAppIndex($siteApps, $appId);
        $currentApp = ($existingIndex >= 0) ? $siteApps[$existingIndex] : [
            'id' => $appId, 'icon' => '', 'icon_class' => 'fas fa-mobile-screen',
            'apk_file' => '', 'android_url' => '', 'screenshots' => [],
        ];

        $currentApp = array_merge($currentApp, [
            'title' => $title,
            'category' => $category,
            'description' => $desc,
            'version' => $version, 'web_url' => $web_url,
            'downloads' => $downloads, 'rating' => $rating, 'color' => $color,
            'status' => $status, 'progress' => $progress,
            'features' => $feats,
        ]);

        if (isset($_FILES['app_icon'])) {
            $iconFn = saveUploadedImage($_FILES['app_icon'], 'icon_');
            if ($iconFn !== '') {
                deleteUpload($currentApp['icon'] ?? '');
                $currentApp['icon'] = $iconFn;
            }
        }

        // الحصرية بين رابط سوق بلاي وملف الـ APK
        $oldApk = safeUploadName($currentApp['apk_file'] ?? '');
        if ($android_url !== '') {
            $currentApp['android_url'] = $android_url;
            $currentApp['apk_file'] = '';
        } else {
            $currentApp['android_url'] = '';
            $apkUpload = $_FILES['apk_file'] ?? null;
            if ($apkUpload && ($apkUpload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $apkExt = strtolower(pathinfo((string)$apkUpload['name'], PATHINFO_EXTENSION));
                // ملف APK هو أرشيف ZIP ويبدأ بالتوقيع PK
                $magic = @file_get_contents($apkUpload['tmp_name'], false, null, 0, 2);
                if ($apkExt === 'apk' && $apkUpload['size'] <= MAX_APK_SIZE && $magic === 'PK' && is_uploaded_file($apkUpload['tmp_name'])) {
                    $apkFn = 'apk_' . bin2hex(random_bytes(8)) . '.apk';
                    if (move_uploaded_file($apkUpload['tmp_name'], UPLOADS_DIR . $apkFn)) {
                        $currentApp['apk_file'] = $apkFn;
                    }
                } else {
                    $adminErr = 'ملف APK غير صالح أو أكبر من 50MB.';
                }
            } else {
                // الاحتفاظ بالملف الحالي فقط إن كان هو نفس ملف هذا التطبيق (منع Path Traversal)
                $keep = safeUploadName($_POST['existing_apk_file'] ?? '');
                $currentApp['apk_file'] = ($keep !== '' && $keep === $oldApk) ? $oldApk : '';
            }
        }
        if ($oldApk !== '' && $oldApk !== ($currentApp['apk_file'] ?? '')) {
            deleteUpload($oldApk);
        }

        if (isset($_FILES['screenshots']) && is_array($_FILES['screenshots']['name'])) {
            $count = count($_FILES['screenshots']['name']);
            for ($i = 0; $i < $count && $i < 10; $i++) {
                $scFn = saveUploadedImage([
                    'name' => $_FILES['screenshots']['name'][$i],
                    'tmp_name' => $_FILES['screenshots']['tmp_name'][$i],
                    'error' => $_FILES['screenshots']['error'][$i],
                    'size' => $_FILES['screenshots']['size'][$i],
                ], 'sc_');
                if ($scFn !== '') $currentApp['screenshots'][] = $scFn;
            }
        }

        if ($existingIndex >= 0) {
            $siteApps[$existingIndex] = $currentApp;
            $adminMsg = 'تم تحديث بيانات التطبيق بنجاح!';
        } else {
            $siteApps[] = $currentApp;
            $adminMsg = 'تم حفظ ونشر التطبيق الجديد بنجاح!';
        }
        saveJson($appsFile, $siteApps);
    }
}

if ($adminActionAllowed && isset($_GET['delete_app'])) {
    $delId = (string)$_GET['delete_app'];
    $idx = findAppIndex($siteApps, $delId);
    if ($idx >= 0) {
        $del = $siteApps[$idx];
        deleteUpload($del['icon'] ?? '');
        deleteUpload($del['apk_file'] ?? '');
        foreach ((array)($del['screenshots'] ?? []) as $sc) deleteUpload($sc);
        array_splice($siteApps, $idx, 1);
        saveJson($appsFile, $siteApps);
        $adminMsg = 'تم حذف التطبيق بنجاح!';
    }
}

// حفظ أو تعديل مقال باللغتين مع صورة الكاتب وتاريخ النشر
if ($adminActionAllowed && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_article') {
    $postedId = trim((string)($_POST['article_id'] ?? ''));
    $artId = preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $postedId) ? $postedId : ('art_' . bin2hex(random_bytes(6)));
    $title = clip($_POST['title'] ?? '', 200);
    $desc = clip($_POST['description'] ?? '', 500);
    $badge = clip($_POST['badge'] ?? '', 40) ?: 'تطوير';
    $badgeColor = in_array($_POST['badge_color'] ?? '', APP_COLORS, true) ? $_POST['badge_color'] : 'blue';
    $readTime = max(1, min(120, (int)($_POST['read_time'] ?? 3)));
    $author = clip($_POST['author'] ?? '', 80) ?: 'سارة علي';
    $full = clip($_POST['full'] ?? '', 50000);
    $pubDate = (string)($_POST['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pubDate)) $pubDate = date('Y-m-d');

    if ($title === '') {
        $adminErr = 'عنوان المقال مطلوب.';
    } else {
        $artIndex = -1;
        foreach ($siteArticles as $i => $ar) {
            if (($ar['id'] ?? null) === $artId) { $artIndex = (int)$i; break; }
        }

        $authorPhoto = ($artIndex >= 0) ? ($siteArticles[$artIndex]['author_photo'] ?? '') : '';
        if (isset($_FILES['author_photo'])) {
            $apFn = saveUploadedImage($_FILES['author_photo'], 'author_');
            if ($apFn !== '') {
                deleteUpload($authorPhoto);
                $authorPhoto = $apFn;
            }
        }

        $artData = [
            'id' => $artId,
            'title' => $title,
            'description' => $desc,
            'badge' => $badge, 'badge_color' => $badgeColor,
            'date' => $pubDate, 'read_time' => $readTime,
            'full' => $full,
            'author' => $author,
            'author_photo' => $authorPhoto,
        ];

        if ($artIndex >= 0) {
            $siteArticles[$artIndex] = $artData;
            $adminMsg = 'تم تحديث المقال بنجاح مع صورة الكاتب!';
        } else {
            $siteArticles[] = $artData;
            $adminMsg = 'تم حفظ ونشر المقال الجديد بنجاح!';
        }
        saveJson($articlesFile, $siteArticles);
    }
}

if ($adminActionAllowed && isset($_GET['delete_article'])) {
    $delArtId = (string)$_GET['delete_article'];
    foreach ($siteArticles as $i => $ar) {
        if (($ar['id'] ?? null) === $delArtId) {
            deleteUpload($ar['author_photo'] ?? '');
            array_splice($siteArticles, $i, 1);
            saveJson($articlesFile, $siteArticles);
            $adminMsg = 'تم حذف المقال بنجاح!';
            break;
        }
    }
}

if ($adminActionAllowed && isset($_GET['delete_demo_req'])) {
    $delReqId = (string)$_GET['delete_demo_req'];
    updateJson($demoRequestsFile, function (array &$all) use ($delReqId) {
        $all = array_values(array_filter($all, fn($r) => ($r['id'] ?? '') !== $delReqId));
    });
    $adminMsg = 'تم حذف الطلب بنجاح!';
}

$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$showAdminPanel = isset($_GET['admin']) || $isAdmin || (bool)preg_match('#/admin/?(\?.*)?$#i', $reqUri);
$csrfQs = 'csrf_token=' . urlencode($csrfToken);
$initialData = buildPublicData($siteApps, $siteArticles, $siteConfig, $totalVisitorsCount);
session_write_close(); // لا كتابة للجلسة بعد هذه النقطة: تحرير القفل لتسريع الطلبات
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title><?= e($siteConfig['name']) ?></title>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/style.css?v=<?= (int)@filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body>

<div class="bg-orb bo1"></div>
<div class="bg-orb bo2"></div>

<!-- HEADER -->
<header class="hdr">
  <a href="./" class="logo" onclick="event.preventDefault(); window.scrollTo({top:0, behavior:'smooth'});" title="<?= e($siteConfig['name']) ?>">
    <span><?= e($siteConfig['name']) ?></span>
  </a>
  <div class="hdr-right">
    <button class="theme-toggle-btn" onclick="toggleTheme()" title="Theme"><i class="fas fa-moon moon"></i><i class="fas fa-sun sun"></i></button>
  </div>
</header>

<main class="page-container" id="mainApp">
  <div style="text-align:center;padding:50px;color:var(--muted);font-weight:700;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
</main>

<!-- زر ابدأ الآن العائم في الصفحة الرئيسية أسفل اليمين -->
<button type="button" class="floating-bottom-action-btn demo-btn" id="floatingMainDemoBtn" onclick="openDemoPresentationModal()" title="ابدأ الآن">
  <i class="fas fa-rocket"></i>
  <span id="lblFloatingDemo">ابدأ الآن</span>
</button>

<!-- زر التراجع العائم في صفحة التطبيق أسفل اليمين -->
<button type="button" class="floating-bottom-action-btn back-btn" id="floatingAppBackBtn" onclick="interactiveGoBack()" style="display:none;" title="رجوع">
  <i class="fas fa-arrow-right"></i>
  <span class="lbl-back">رجوع</span>
</button>

<!-- OVERLAY: تفاصيل التطبيق والتثبيت (بدون شريط علوي - التراجع بالأسفل على اليمين) -->
<div class="page-overlay" id="appOverlay" style="padding-top:10px;">
  <div class="page-content" id="appDetailContent" style="padding-top:4px;padding-bottom:80px;"></div>
</div>

<!-- البطاقة المنبثقة: ابدأ الآن (خلفية مغبشة بالكامل مع بطاقة فقط بالمنتصف) -->
<div class="modal-blurred-backdrop" id="demoPresentationOverlay" onclick="handleDemoBackdropClick(event)">
  <div class="modal-blurred-card" onclick="event.stopPropagation()">
    <div id="demoFormState">
      <!-- قالب 1: ترويسة الطلب والأيقونة والعرض التقديمي -->
      <div class="build-template-block tpl-block-1" style="text-align:center;padding:10px 10px 8px;margin-bottom:8px;background:linear-gradient(135deg, rgba(255,122,26,0.08), transparent);">
        <div style="width:46px;height:46px;border-radius:14px;background:var(--gradient-primary);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:1.3rem;margin-bottom:6px;box-shadow:0 4px 14px rgba(255,122,26,0.35);">
          <i class="fas fa-rocket"></i>
        </div>
        <h3 style="font-size:1.05rem;font-weight:900;margin-bottom:3px;color:var(--text);" id="lblDemoModalTitle">ابدأ الآن</h3>
        <p style="font-size:0.74rem;color:var(--muted);line-height:1.45;" id="lblDemoModalDesc">املأ بياناتك وسيقوم الدعم الفني بتجهيز وإرسال نموذج تجريبي وعرض مخصص لمشروعك عبر الواتساب فوراً.</p>
      </div>

      <form id="demoPresentationForm" onsubmit="submitDemoPresentation(event)">
        <input type="text" id="demo_website" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">
        <!-- قالب 2: قالب الاسم الكامل -->
        <div class="build-template-block tpl-block-2" style="padding:7px 12px;margin-bottom:8px;">
          <label class="admin-label" id="lblDemoName" style="display:flex;align-items:center;gap:6px;font-size:0.74rem;margin-bottom:3px;">
            <i class="fas fa-user" style="color:var(--blue)"></i>
            <span>الاسم الكامل *</span>
          </label>
          <input type="text" id="demo_name" maxlength="100" class="admin-input" style="margin-bottom:0;padding:8px 12px;font-size:0.82rem;" required placeholder="مثال: أحمد محمد">
        </div>

        <!-- قالب 3: قالب رقم الواتساب -->
        <div class="build-template-block tpl-block-3" style="padding:7px 12px;margin-bottom:8px;">
          <label class="admin-label" id="lblDemoWhatsapp" style="display:flex;align-items:center;gap:6px;font-size:0.74rem;margin-bottom:3px;">
            <i class="fab fa-whatsapp" style="color:#25D366;font-size:0.95rem;"></i>
            <span>رقم الواتساب (بدون رمز الدولة) *</span>
          </label>
          <input type="tel" id="demo_whatsapp" maxlength="20" inputmode="tel" class="admin-input" style="margin-bottom:0;padding:8px 12px;font-size:0.82rem;" required placeholder="مثال: 07700000000">
        </div>

        <!-- قالب 4: قالب فكرة ومتطلبات المشروع -->
        <div class="build-template-block tpl-block-4" style="padding:7px 12px;margin-bottom:8px;">
          <label class="admin-label" id="lblDemoProject" style="display:flex;align-items:center;gap:6px;font-size:0.74rem;margin-bottom:3px;">
            <i class="fas fa-lightbulb" style="color:var(--gold)"></i>
            <span>شلون المشروع اللي بتحب تشتغله؟ (فكرة ومتطلبات مشروعك) *</span>
          </label>
          <textarea id="demo_project_details" maxlength="3000" class="admin-textarea" rows="2" style="margin-bottom:0;padding:8px 12px;font-size:0.82rem;" required placeholder="اشرح لنا نوع وفكرة المشروع، المميزات التي تحتاجها، وأي تفاصيل ترغب بها..."></textarea>
        </div>

        <!-- قالب 5: قالب زر الإرسال والاعتماد -->
        <div class="build-template-block tpl-block-5" style="border:none;background:transparent;padding:2px 0 0;box-shadow:none;margin-bottom:0;">
          <button type="submit" class="ct-btn" id="btnSubmitDemo" style="width:100%;padding:11px;font-size:0.92rem;font-weight:900;border-radius:12px;">
            <i class="fas fa-paper-plane"></i> <span id="lblSubmitDemoText">إرسال الطلب الآن</span>
          </button>
          <div style="text-align:center;margin-top:6px;font-size:0.7rem;color:var(--muted);display:flex;align-items:center;justify-content:center;gap:6px;">
            <i class="fas fa-shield-halved" style="color:#22c55e;"></i>
            <span>دعم فني فوري ونموذج تجريبي معتمد وسريع</span>
          </div>
        </div>
      </form>
    </div>

    <!-- بطاقة التأكيد بعد الإرسال مع علامة صح -->
    <!-- رسالة التأكيد بعد الإرسال بدون بطاقة وبدون زر مع تساقط الصح وحركة النصوص والعداد -->
    <div id="demoSuccessState" class="demo-success-frameless-wrap" style="display:none;">
      <div class="success-check-drop">
        <div class="success-check-icon">
          <i class="fas fa-check"></i>
        </div>
      </div>
      <h3 class="success-title-slide" id="lblDemoSuccessTitle">تم إرسال طلبك قيد التدقيق</h3>
      <p class="success-desc-slide" id="lblDemoSuccessDesc">سيتواصل معك فريق الدعم قريباً عبر الواتساب لإعطائك النظام أو النموذج التجريبي لعملك.</p>
      
      <div class="success-countdown-wrapper">
        <div class="success-countdown-badge">
          <i class="fas fa-hourglass-half"></i>
          <span>يتم الإغلاق تلقائياً خلال <strong id="demoCountdownNum">3</strong> ثوانٍ...</span>
        </div>
        <div class="success-countdown-track">
          <div class="success-countdown-progress" id="demoCountdownBar"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- OVERLAY: جميع التطبيقات والمواقع (بدون شريط علوي - التراجع بالأسفل على اليمين) -->
<div class="page-overlay" id="allAppsOverlay" style="padding-top:10px;">
  <div class="page-content" id="allAppsListContent" style="padding-top:4px;padding-bottom:80px;"></div>
</div>

<!-- OVERLAY: تفاصيل المقال (بدون شريط علوي - التراجع بالأسفل على اليمين) -->
<div class="page-overlay" id="articleOverlay" style="padding-top:10px;">
  <div class="page-content" id="articleDetailContent" style="padding-top:4px;padding-bottom:80px;"></div>
</div>

<!-- OVERLAY: إنستغرام -->
<div class="page-overlay" id="instagramOverlay">
  <div class="close-btn">
    <button type="button" onclick="closeOverlay('instagramOverlay')"><i class="fas fa-arrow-right"></i> <span class="lbl-back">رجوع</span></button>
    <span style="font-weight:800;font-size:0.9rem;">📸 Instagram</span>
  </div>
  <div class="page-content" id="instagramModalBody"></div>
</div>

<!-- OVERLAY: خطوات تثبيت الموقع كتطبيق من المتصفح (iPhone iOS PWA) بدون شريط علوي والتراجع بالأسفل -->
<div class="page-overlay" id="pwaStepsOverlay" style="padding-top:10px;">
  <div class="page-content" id="pwaStepsContent" style="padding-top:4px;padding-bottom:80px;"></div>
</div>


<!-- بطاقة تقييم صغيرة منبثقة بأسفل الشاشة تظهر عند الدخول على التطبيق ويمكن إيقافها بزر منع العرض -->
<div id="bottomRatePopup" class="bottom-rate-popup" style="display:none;">
  <div class="bottom-rate-content">
    <div class="bottom-rate-header">
      <div class="bottom-rate-title">
        <i class="fas fa-star" style="color:var(--gold);"></i>
        <span id="bottomRateAppTitle">تقييم التطبيق</span>
      </div>
      <button type="button" class="bottom-rate-close" onclick="closeBottomRatePopup()" title="إغلاق">
        <i class="fas fa-times"></i>
      </button>
    </div>
    
    <div class="bottom-rate-stars-row">
      <div class="star-rating-select" id="bottomStarsBox">
        <i class="fas fa-star active" onclick="setBottomReviewStars(1)"></i>
        <i class="fas fa-star active" onclick="setBottomReviewStars(2)"></i>
        <i class="fas fa-star active" onclick="setBottomReviewStars(3)"></i>
        <i class="fas fa-star active" onclick="setBottomReviewStars(4)"></i>
        <i class="fas fa-star active" onclick="setBottomReviewStars(5)"></i>
      </div>
    </div>

    <div class="bottom-rate-actions">
      <button type="button" class="bottom-rate-submit" onclick="submitBottomRate()">
        <i class="fas fa-paper-plane"></i> <span id="lblSubmitRate">إرسال التقييم</span>
      </button>
      <button type="button" class="bottom-rate-mute" onclick="muteBottomRatePopup()">
        <i class="fas fa-ban"></i> <span id="lblMuteRate">منع العرض</span>
      </button>
    </div>
  </div>
</div>

<!-- OVERLAY: لوحة تحكم المدير الكاملة مع زر بدء العد لتسجيل الزوار (?admin) -->
<div class="page-overlay <?= $showAdminPanel ? 'open' : '' ?>" id="adminOverlay" style="<?= $showAdminPanel ? 'display:block' : '' ?>">
  <div class="close-btn">
    <button type="button" onclick="closeOverlay('adminOverlay')"><i class="fas fa-arrow-right"></i> رجوع للموقع</button>
    <span style="font-weight:900;font-size:0.95rem;color:var(--blue);"><i class="fas fa-shield-halved"></i> لوحة إدارة الموقع والتطبيقات</span>
  </div>
  <div class="page-content">
    <?php if (!empty($adminMsg)): ?>
      <div style="background:rgba(34,197,94,0.15);color:#22c55e;padding:12px;border-radius:12px;margin-bottom:14px;font-weight:800;"><i class="fas fa-check-circle"></i> <?= e($adminMsg) ?></div>
    <?php endif; ?>
    <?php if (!empty($adminErr)): ?>
      <div style="background:rgba(239,68,68,0.15);color:#ef4444;padding:12px;border-radius:12px;margin-bottom:14px;font-weight:800;"><i class="fas fa-exclamation-circle"></i> <?= e($adminErr) ?></div>
    <?php endif; ?>

    <?php if (!$isAdmin): ?>
      <div class="admin-card">
        <h3 style="margin-bottom:14px;font-size:1.1rem;font-weight:900;"><i class="fas fa-lock"></i> تسجيل دخول المدير</h3>
        <form method="POST" action="?admin=login">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="action" value="admin_login">
          <label class="admin-label">اسم المستخدم</label>
          <input type="text" name="username" class="admin-input" required placeholder="admin">
          <label class="admin-label">كلمة المرور</label>
          <input type="password" name="password" class="admin-input" required autocomplete="current-password" placeholder="••••••••">
          <button type="submit" class="ct-btn" style="width:100%"><i class="fas fa-sign-in-alt"></i> دخول لوحة التحكم</button>
        </form>
      </div>
    <?php else: ?>
      <?php
        $allDemoReqs = loadJson($demoRequestsFile);
        $activeAdminTab = 'apps';
        if (!empty($_POST['admin_tab']) && in_array($_POST['admin_tab'], ['apps', 'settings', 'telegram', 'articles'])) {
            $activeAdminTab = $_POST['admin_tab'];
        } elseif (!empty($_POST['action'])) {
            if ($_POST['action'] === 'save_app') $activeAdminTab = 'apps';
            elseif ($_POST['action'] === 'save_article') $activeAdminTab = 'articles';
            elseif ($_POST['action'] === 'save_site_settings') {
                $activeAdminTab = (isset($_POST['telegram_bot_token']) && !isset($_POST['name'])) ? 'telegram' : 'settings';
            }
        }
        if (isset($_GET['admin_tab']) && in_array($_GET['admin_tab'], ['apps', 'settings', 'telegram', 'articles'])) {
            $activeAdminTab = $_GET['admin_tab'];
        } elseif (isset($_GET['delete_app'])) {
            $activeAdminTab = 'apps';
        } elseif (isset($_GET['delete_article'])) {
            $activeAdminTab = 'articles';
        } elseif (isset($_GET['delete_demo_req'])) {
            $activeAdminTab = 'telegram';
        }
      ?>

      <?php if (password_verify('admin123', (string)$siteConfig['admin_pass'])): ?>
        <div style="background:rgba(239,68,68,0.15);color:#ef4444;padding:12px;border-radius:12px;margin-bottom:14px;font-weight:800;"><i class="fas fa-triangle-exclamation"></i> تحذير أمني: ما زلت تستخدم كلمة المرور الافتراضية (admin123). غيّرها فوراً من تبويب "إعدادات وهوية الموقع".</div>
      <?php endif; ?>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <span style="font-weight:800;font-size:0.95rem;">مرحباً بالمدير 👋</span>
        <a href="?admin_logout=1" class="ct-btn" style="padding:6px 14px;font-size:0.75rem;background:rgba(239,68,68,0.15);color:#ef4444;box-shadow:none;"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</a>
      </div>

      <!-- شريط التبويبات العلوي للوحة التحكم المقسمة -->
      <div class="admin-tabs-nav" id="adminTabsNav">
        <button type="button" class="admin-tab-btn <?= $activeAdminTab === 'apps' ? 'active' : '' ?>" onclick="switchAdminTab('apps')" id="tabBtn_apps">
          <i class="fas fa-mobile-screen-button"></i>
          <span>إدارة التطبيقات</span>
          <span class="admin-tab-badge"><?= count($siteApps) ?></span>
        </button>

        <button type="button" class="admin-tab-btn <?= $activeAdminTab === 'settings' ? 'active' : '' ?>" onclick="switchAdminTab('settings')" id="tabBtn_settings">
          <i class="fas fa-sliders"></i>
          <span>إعدادات وهوية الموقع</span>
        </button>

        <button type="button" class="admin-tab-btn <?= $activeAdminTab === 'telegram' ? 'active' : '' ?>" onclick="switchAdminTab('telegram')" id="tabBtn_telegram">
          <i class="fab fa-telegram"></i>
          <span>التيليجرام والطلبات</span>
          <span class="admin-tab-badge"><?= count($allDemoReqs) ?></span>
        </button>

        <button type="button" class="admin-tab-btn <?= $activeAdminTab === 'articles' ? 'active' : '' ?>" onclick="switchAdminTab('articles')" id="tabBtn_articles">
          <i class="fas fa-newspaper"></i>
          <span>إدارة المقالات</span>
          <span class="admin-tab-badge"><?= count($siteArticles) ?></span>
        </button>
      </div>

      <!-- ==================== التبويب 1: إدارة ونشر وتعديل التطبيقات ==================== -->
      <div class="admin-tab-pane <?= $activeAdminTab === 'apps' ? 'active' : '' ?>" id="adminTab_apps">
        <div class="admin-section-header">
          <div class="admin-section-title">
            <i class="fas fa-mobile-screen-button"></i>
            <span>قسم إدارة التطبيقات المنشورة والجديدة</span>
          </div>
          <button type="button" onclick="resetAppForm()" class="ct-btn" style="padding:7px 14px;font-size:0.8rem;box-shadow:none;border-radius:10px;">
            <i class="fas fa-plus-circle"></i> إضافة تطبيق جديد
          </button>
        </div>

        <!-- نشر وتعديل التطبيقات باللغتين العربية والإنجليزية -->
        <div class="admin-card" id="appFormBox">
          <h3 style="margin-bottom:14px;font-size:1.05rem;color:var(--blue);font-weight:900;"><i class="fas fa-cloud-arrow-up"></i> <span id="appFormHeading">نشر / تعديل تطبيق APK باللغتين</span></h3>
          <form method="POST" action="?admin=panel" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="action" value="save_app">
            <input type="hidden" name="admin_tab" value="apps">
            <input type="hidden" name="app_id" id="form_app_id" value="">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">اسم التطبيق (بالعربية) *</label>
                <input type="text" name="title" id="form_app_title" class="admin-input" required placeholder="مثال: متجر FastCrand">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">التصنيف (بالعربية)</label>
                <input type="text" name="category" id="form_app_category" class="admin-input" placeholder="أدوات / أعمال">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">رابط الموقع (لتحويله لتطبيق / تصفح ويب) 🌐</label>
                <input type="url" name="web_url" id="form_app_web_url" class="admin-input" placeholder="https://example.com">
              </div>
              <div>
                <label class="admin-label">تحديد عدد التحميلات الأولي 📥</label>
                <input type="number" name="downloads" id="form_app_downloads" class="admin-input" value="1000">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">تقييم التطبيق الثابت من الإدارة (1 إلى 5) ⭐</label>
                <input type="number" step="0.1" min="1" max="5" name="rating" id="form_app_rating" class="admin-input" value="4.9">
              </div>
              <div>
                <label class="admin-label">الإصدار</label>
                <input type="text" name="version" id="form_app_version" class="admin-input" value="1.0.0">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr;gap:10px;">
              <div>
                <label class="admin-label">اللون المميز</label>
                <select name="color" id="form_app_color" class="admin-input">
                  <option value="blue">برتقالي (FastCrand)</option>
                  <option value="cyan">سماوي</option>
                  <option value="gold">ذهبي</option>
                  <option value="pink">وردي</option>
                  <option value="green">أخضر</option>
                  <option value="purple">بنفسجي</option>
                </select>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">حالة المشروع 🚧</label>
                <select name="status" id="form_app_status" class="admin-input">
                  <option value="live">منشور ومتاح للتحميل</option>
                  <option value="wip">قيد التطوير (تحت العمل)</option>
                </select>
              </div>
              <div>
                <label class="admin-label">نسبة الإنجاز % (للمشاريع قيد التطوير)</label>
                <input type="number" min="0" max="100" name="progress" id="form_app_progress" class="admin-input" value="0">
              </div>
            </div>

            <label class="admin-label">لوجو وأيقونة التطبيق (صورة)</label>
            <input type="file" name="app_icon" accept="image/*" class="admin-input">

            <!-- طريقة تثبيت الأندرويد: رابط سوق بلاي أو ملف APK (حصرية متبادلة) -->
            <div style="background:var(--hover-bg);border:1.5px solid var(--border);border-radius:14px;padding:14px;margin:14px 0;">
              <div style="font-weight:900;font-size:0.86rem;color:var(--text);margin-bottom:4px;display:flex;align-items:center;gap:7px;">
                <i class="fab fa-android" style="color:#3ddc84;font-size:1.15rem;"></i>
                <span>طريقة تثبيت الأندرويد (رابط سوق بلاي أو رفع ملف APK اختياري):</span>
              </div>
              <p style="font-size:0.73rem;color:var(--muted);margin-bottom:12px;line-height:1.6;">
                يمكنك إما وضع رابط متجر (مثل سوق بلاي Google Play) أو إرفاق ملف APK مباشر. عند وضع الرابط يتم تعطيل رفع الملف، وعند اختيار ملف يتم تعطيل الرابط تلقائياً.
              </p>

              <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                <!-- الخيار 1: رابط سوق بلاي -->
                <div>
                  <label class="admin-label" style="display:flex;align-items:center;gap:6px;">
                    <i class="fab fa-google-play" style="color:var(--blue)"></i> رابط سوق بلاي / خارجي للأندرويد
                  </label>
                  <input type="url" name="android_url" id="form_app_android_url" class="admin-input" placeholder="https://play.google.com/store/apps/details?id=..." oninput="handleAndroidOptionChange('url')">
                  <span id="hintUrlStatus" style="font-size:0.7rem;color:var(--muted);display:block;margin-top:2px;"></span>
                </div>

                <!-- الخيار 2: ملف APK -->
                <div>
                  <label class="admin-label" style="display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-file-arrow-up" style="color:#22c55e"></i> ملف التطبيق APK (أقصى حجم 50MB) 📦
                  </label>
                  <input type="file" name="apk_file" id="form_app_apk_file" accept=".apk" class="admin-input" onchange="handleAndroidOptionChange('file')">
                  <input type="hidden" name="existing_apk_file" id="form_app_existing_apk" value="">
                  <div id="existingApkBadge" style="display:none;margin-top:6px;font-size:0.74rem;background:rgba(34,197,94,0.15);color:#22c55e;padding:6px 10px;border-radius:8px;align-items:center;justify-content:space-between;">
                    <span><i class="fas fa-check-circle"></i> يوجد ملف APK مرفق: (<strong id="existingApkName"></strong>)</span>
                    <button type="button" onclick="removeExistingApk()" style="background:none;border:none;color:#ef4444;cursor:pointer;font-size:0.75rem;font-weight:800;"><i class="fas fa-trash"></i> حذف</button>
                  </div>
                  <span id="hintApkStatus" style="font-size:0.7rem;color:var(--muted);display:block;margin-top:2px;"></span>
                </div>
              </div>

              <div style="text-align:left;margin-top:8px;">
                <button type="button" onclick="resetAndroidOptions()" style="background:none;border:none;color:var(--blue);cursor:pointer;font-size:0.74rem;font-weight:700;">
                  <i class="fas fa-rotate-left"></i> تفريغ وإلغاء القفل بين الخيارين
                </button>
              </div>
            </div>

            <label class="admin-label">صور واجهات التطبيق (Screenshots)</label>
            <input type="file" name="screenshots[]" multiple accept="image/*" class="admin-input">

            <label class="admin-label">وصف التطبيق (بالعربية) *</label>
            <textarea name="description" id="form_app_desc" class="admin-textarea" rows="3" required placeholder="اكتب تفاصيل وميزات التطبيق..."></textarea>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">المميزات (بالعربية - ميزة لكل سطر)</label>
                <textarea name="features" id="form_app_features" class="admin-textarea" rows="3" placeholder="ميزة 1&#10;ميزة 2"></textarea>
              </div>
            </div>

            <button type="submit" class="ct-btn" style="width:100%"><i class="fas fa-save"></i> <span id="appSubmitBtnText">حفظ ونشر التطبيق</span></button>
          </form>
        </div>

        <!-- قائمة التطبيقات وإمكانية تعديلها وحذفها -->
        <div class="admin-card">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h4 style="margin:0;font-size:0.95rem;font-weight:900;color:var(--text);">
              <i class="fas fa-list-check" style="color:var(--blue)"></i> قائمة التطبيقات المتاحة حالياً (<?= count($siteApps) ?>)
            </h4>
          </div>
          <?php if(empty($siteApps)): ?>
            <p style="font-size:0.8rem;color:var(--muted);text-align:center;padding:12px;">لا توجد تطبيقات مضافة بعد.</p>
          <?php else: ?>
            <?php foreach($siteApps as $ap): ?>
              <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:var(--hover-bg);border-radius:12px;margin-bottom:8px;font-size:0.82rem;border:1px solid var(--border);">
                <div style="display:flex;align-items:center;gap:10px;">
                  <div style="width:36px;height:36px;border-radius:10px;background:var(--card);display:flex;align-items:center;justify-content:center;overflow:hidden;border:1px solid var(--border);flex-shrink:0;">
                    <?php if(!empty($ap['icon'])): ?>
                      <img src="uploads/<?= e($ap['icon']) ?>" style="width:100%;height:100%;object-fit:cover;">
                    <?php else: ?>
                      <i class="fas fa-mobile-screen" style="color:var(--blue)"></i>
                    <?php endif; ?>
                  </div>
                  <div>
                    <strong style="color:var(--text);font-size:0.88rem;"><?= e($ap['title'] ?? '') ?></strong><?php if (($ap['status'] ?? 'live') === 'wip'): ?> <span class="wip-badge">🚧 قيد التطوير <?= (int)($ap['progress'] ?? 0) ?>%</span><?php endif; ?>
                    <div style="font-size:0.72rem;color:var(--muted);margin-top:2px;">
                      <span><i class="fas fa-download"></i> <?= intval($ap['downloads'] ?? 0) ?></span> · 
                      <span>⭐ <?= e($ap['rating'] ?? '4.9') ?></span>
                    </div>
                  </div>
                </div>
                <div style="display:flex;gap:8px;">
                  <button type="button" onclick='editApp(<?= json_encode($ap, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' style="background:var(--card);border:1px solid var(--border);padding:5px 12px;border-radius:8px;cursor:pointer;color:var(--blue);font-weight:700;font-size:0.78rem;">
                    <i class="fas fa-edit"></i> تعديل
                  </button>
                  <a href="?admin=panel&delete_app=<?= urlencode((string)$ap['id']) ?>&amp;admin_tab=apps&amp;<?= e($csrfQs) ?>" onclick="return confirm('حذف هذا التطبيق نهائياً؟')" style="color:#ef4444;text-decoration:none;padding:5px 8px;border-radius:8px;background:rgba(239,68,68,0.1);display:flex;align-items:center;" title="حذف التطبيق">
                    <i class="fas fa-trash"></i>
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- ==================== التبويب 2: إعدادات هوية الموقع وعداد الزوار ==================== -->
      <div class="admin-tab-pane <?= $activeAdminTab === 'settings' ? 'active' : '' ?>" id="adminTab_settings">
        <div class="admin-section-header">
          <div class="admin-section-title">
            <i class="fas fa-sliders"></i>
            <span>إعدادات هوية الموقع وعداد الزوار والتواصل</span>
          </div>
        </div>

        <div class="admin-card">
          <form method="POST" action="?admin=panel" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="action" value="save_site_settings">
            <input type="hidden" name="admin_tab" value="settings">

            <div style="background:var(--hover-bg);border:1.5px dashed var(--border);border-radius:14px;padding:14px;margin-bottom:16px;">
              <label class="admin-label" style="color:var(--blue);font-size:0.85rem;"><i class="fas fa-users"></i> تحديد رقم عداد الزوار وزر "بدء العد":</label>
              <div style="display:flex;gap:10px;align-items:center;margin-top:6px;">
                <input type="number" name="base_visitors" class="admin-input" style="margin-bottom:0;" value="<?= intval($siteConfig['base_visitors'] ?? 10000) ?>" placeholder="أدخل الرقم المطلوب...">
                <button type="submit" name="start_counter" value="1" class="ct-btn" style="white-space:nowrap;padding:10px 18px;background:linear-gradient(135deg,#22c55e,#16a34a);"><i class="fas fa-play"></i> بدء العد</button>
              </div>
              <span style="font-size:0.68rem;color:var(--muted);display:block;margin-top:6px;">اكتب الرقم الذي تريده واضغط "بدء العد"، وسيبدأ الموقع فوراً بالعد التزايدي من هذا الرقم مع كل زائر جديد.</span>
            </div>

            <label class="admin-label">اسم الموقع / الشركة</label>
            <input type="text" name="name" class="admin-input" value="<?= e($siteConfig['name']) ?>" required>

            <label class="admin-label">لوجو وشعار الموقع الرسمي</label>
            <input type="file" name="site_photo" accept="image/*" class="admin-input">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">الوصف النصي القصير (بالعربية)</label>
                <input type="text" name="role" class="admin-input" value="<?= e($siteConfig['role']) ?>" required>
              </div>
            </div>

            <label class="admin-label">نبذة عن الشركة (بالعربية)</label>
            <textarea name="about" class="admin-textarea" rows="3"><?= e($siteConfig['about']) ?></textarea>

            <label class="admin-label">رقم أو رابط واتساب للتواصل المباشر</label>
            <input type="text" name="whatsapp_url" class="admin-input" value="<?= e($siteConfig['whatsapp_url']) ?>" required>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">يوزر إنستغرام</label>
                <input type="text" name="instagram_user" class="admin-input" value="<?= e($siteConfig['instagram_user']) ?>" required>
              </div>
              <div>
                <label class="admin-label">حالة حساب الإنستغرام</label>
                <select name="instagram_status" class="admin-input">
                  <option value="disabled" <?= $siteConfig['instagram_status'] === 'disabled' ? 'selected' : '' ?>>معطل حالياً</option>
                  <option value="active" <?= $siteConfig['instagram_status'] === 'active' ? 'selected' : '' ?>>مفعل ونشط</option>
                </select>
              </div>
            </div>

            <label class="admin-label">رسالة إنستغرام التوضيحية (بالعربية)</label>
            <textarea name="instagram_message" class="admin-textarea" rows="2"><?= e($siteConfig['instagram_message']) ?></textarea>

            <label class="admin-label">كلمة مرور جديدة للمدير (اختياري)</label>
            <input type="password" name="new_password" class="admin-input" minlength="8" autocomplete="new-password" placeholder="اتركها فارغة إن لم ترغب بالتغيير (8 أحرف على الأقل)">

            <button type="submit" class="ct-btn" style="width:100%"><i class="fas fa-check"></i> حفظ تحديثات هوية الموقع</button>
          </form>
        </div>
      </div>

      <!-- ==================== التبويب 3: بوت تيليجرام وصندوق الطلبات ==================== -->
      <div class="admin-tab-pane <?= $activeAdminTab === 'telegram' ? 'active' : '' ?>" id="adminTab_telegram">
        <div class="admin-section-header">
          <div class="admin-section-title">
            <i class="fab fa-telegram"></i>
            <span>إعدادات بوت التيليجرام والطلبات المستلمة</span>
          </div>
        </div>

        <!-- إعدادات بوت التيليجرام -->
        <div class="admin-card">
          <h4 style="margin-bottom:12px;font-size:0.95rem;color:var(--blue);font-weight:900;">
            <i class="fab fa-telegram-plane"></i> ربط بوت تيليجرام لإشعارات العروض التقديمية والطلبات
          </h4>
          <form method="POST" action="?admin=panel">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="action" value="save_site_settings">
            <input type="hidden" name="admin_tab" value="telegram">

            <div style="background:rgba(0,136,204,0.08);border:1.5px solid rgba(0,136,204,0.3);border-radius:14px;padding:14px;margin-bottom:14px;">
              <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
                <div>
                  <label class="admin-label">توكن بوت التيليجرام (Bot Token)</label>
                  <input type="text" name="telegram_bot_token" id="admin_tg_token" class="admin-input" value="<?= e($siteConfig['telegram_bot_token'] ?? '') ?>" placeholder="123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ">
                </div>
                <div>
                  <label class="admin-label">آيدي المدير في التيليجرام (Chat ID)</label>
                  <input type="text" name="telegram_chat_id" id="admin_tg_chat_id" class="admin-input" value="<?= e($siteConfig['telegram_chat_id'] ?? '') ?>" placeholder="123456789">
                </div>
              </div>
              
              <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap;">
                <button type="button" onclick="testTelegramConnection()" class="ct-btn" style="background:#0088cc;padding:8px 16px;font-size:0.78rem;border-radius:10px;box-shadow:none;">
                  <i class="fab fa-telegram-plane"></i> اختبار إرسال رسالة تجريبية للبوت الآن
                </button>
                <span id="telegramTestResult" style="font-size:0.75rem;font-weight:700;"></span>
              </div>
              <span style="font-size:0.7rem;color:var(--muted);display:block;margin-top:8px;line-height:1.5;">
                💡 <b>ملاحظة هامة جداً:</b> تأكد من الدخول أولاً إلى محادثة البوت الخاص بك في تطبيق تيليجرام والضغط على <b>Start</b> أو <b>/start</b> لكي يتمكن البوت من مراسلتك وإرسال الإشعارات.
              </span>
            </div>

            <button type="submit" class="ct-btn" style="width:100%"><i class="fas fa-save"></i> حفظ إعدادات التيليجرام</button>
          </form>
        </div>

        <!-- طلبات العروض التقديمية والنماذج التجريبية المستلمة -->
        <div class="admin-card">
          <h4 style="margin-bottom:14px;font-size:0.95rem;color:var(--blue);font-weight:900;">
            <i class="fas fa-inbox"></i> طلبات العروض التقديمية والنماذج التجريبية (<?= count($allDemoReqs) ?>)
          </h4>
          <?php if(empty($allDemoReqs)): ?>
            <p style="font-size:0.8rem;color:var(--muted);text-align:center;padding:12px;">لا توجد طلبات جديدة حتى الآن.</p>
          <?php else: ?>
            <?php foreach($allDemoReqs as $dr): ?>
              <?php
                $drWaUrl = 'https://wa.me/' . waDigits($dr['whatsapp'] ?? '');
              ?>
              <div style="background:var(--hover-bg);border:1px solid var(--border);border-radius:14px;padding:12px 14px;margin-bottom:10px;">
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                  <div>
                    <strong style="font-size:0.9rem;color:var(--text);"><i class="fas fa-user" style="color:var(--blue)"></i> <?= e($dr['name'] ?? '') ?></strong>
                    <span style="font-size:0.75rem;color:var(--muted);display:block;margin-top:2px;">🕒 <?= e($dr['date'] ?? '') ?></span>
                  </div>
                  <div style="display:flex;gap:6px;align-items:center;">
                    <a href="<?= e($drWaUrl) ?>" target="_blank" rel="noopener noreferrer" class="ct-btn" style="padding:6px 14px;font-size:0.75rem;background:#25D366;box-shadow:none;" title="محادثة واتساب مباشرة">
                      <i class="fab fa-whatsapp"></i> مراسلة: <?= e($dr['whatsapp'] ?? '') ?>
                    </a>
                    <a href="?admin=panel&delete_demo_req=<?= urlencode((string)($dr['id'] ?? '')) ?>&amp;admin_tab=telegram&amp;<?= e($csrfQs) ?>" onclick="return confirm('حذف هذا الطلب نهائياً؟')" style="color:#ef4444;text-decoration:none;padding:6px 8px;font-size:0.85rem;border-radius:8px;background:rgba(239,68,68,0.1);" title="حذف الطلب">
                      <i class="fas fa-trash"></i>
                    </a>
                  </div>
                </div>
                <div style="font-size:0.8rem;color:var(--text);background:var(--card);padding:9px 12px;border-radius:10px;border:1px solid var(--border);margin-top:6px;line-height:1.5;">
                  <strong style="color:var(--gold);"><i class="fas fa-lightbulb"></i> فكرة ومتطلبات المشروع:</strong><br>
                  <?= nl2br(e($dr['project_details'] ?? 'لم تذكر تفاصيل')) ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- ==================== التبويب 4: إدارة ونشر وتعديل المقالات ==================== -->
      <div class="admin-tab-pane <?= $activeAdminTab === 'articles' ? 'active' : '' ?>" id="adminTab_articles">
        <div class="admin-section-header">
          <div class="admin-section-title">
            <i class="fas fa-newspaper"></i>
            <span>قسم المقالات والأخبار المنشورة</span>
          </div>
          <button type="button" onclick="resetArticleForm()" class="ct-btn" style="padding:7px 14px;font-size:0.8rem;box-shadow:none;border-radius:10px;">
            <i class="fas fa-plus-circle"></i> كتابة مقال جديد
          </button>
        </div>

        <!-- تعديل المقالات باللغتين العربية والإنجليزية -->
        <div class="admin-card" id="artFormBox">
          <h3 style="margin-bottom:14px;font-size:1.05rem;color:var(--blue);font-weight:900;"><i class="fas fa-newspaper"></i> <span id="artFormHeading">نشر / تعديل مقال باللغتين</span></h3>
          <form method="POST" action="?admin=panel" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="action" value="save_article">
            <input type="hidden" name="admin_tab" value="articles">
            <input type="hidden" name="article_id" id="form_art_id" value="">

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">عنوان المقال (بالعربية) *</label>
                <input type="text" name="title" id="form_art_title" class="admin-input" required placeholder="عنوان المقال...">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(90px,1fr));gap:10px;">
              <div>
                <label class="admin-label">التصنيف (بالعربية)</label>
                <input type="text" name="badge" id="form_art_badge" class="admin-input" value="تطوير">
              </div>
              <div>
                <label class="admin-label">وقت القراءة (دقائق)</label>
                <input type="number" name="read_time" id="form_art_read_time" class="admin-input" value="4">
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">اسم الكاتب (مثال: سارة علي) *</label>
                <input type="text" name="author" id="form_art_author" class="admin-input" value="سارة علي" required>
              </div>
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px;">
              <div>
                <label class="admin-label">صورة الكاتب (مستخدم المقال)</label>
                <input type="file" name="author_photo" accept="image/*" class="admin-input">
                <div id="author_photo_preview_box" style="margin-top:6px;display:none;align-items:center;gap:8px;font-size:12px;color:var(--text-muted);">
                  <img id="author_photo_preview_img" src="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;border:1px solid rgba(255,255,255,0.2);">
                  <span>صورة الكاتب الحالية</span>
                </div>
              </div>
              <div>
                <label class="admin-label">تاريخ النشر الحقيقي 📅</label>
                <input type="date" name="date" id="form_art_date" class="admin-input" value="<?= date('Y-m-d') ?>">
              </div>
            </div>

            <label class="admin-label">موجز المقال (بالعربية) *</label>
            <input type="text" name="description" id="form_art_desc" class="admin-input" required placeholder="موجز المقال...">

            <label class="admin-label">محتوى المقال الكامل (بالعربية) *</label>
            <textarea name="full" id="form_art_full" class="admin-textarea" rows="4" required placeholder="اكتب تفاصيل المقال..."></textarea>

            <button type="submit" class="ct-btn" style="width:100%"><i class="fas fa-file-circle-plus"></i> <span id="artSubmitBtnText">حفظ ونشر المقال</span></button>
          </form>
        </div>

        <!-- المقالات الحالية وإمكانية تعديلها -->
        <div class="admin-card">
          <h4 style="margin:0 0 12px;font-size:0.95rem;font-weight:900;color:var(--text);">
            <i class="fas fa-newspaper" style="color:var(--blue)"></i> قائمة المقالات المنشورة حالياً (<?= count($siteArticles) ?>)
          </h4>
          <?php if(empty($siteArticles)): ?>
            <p style="font-size:0.8rem;color:var(--muted);text-align:center;padding:12px;">لا توجد مقالات مضافة بعد.</p>
          <?php else: ?>
            <?php foreach($siteArticles as $ar): ?>
              <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;background:var(--hover-bg);border-radius:12px;margin-bottom:8px;font-size:0.82rem;border:1px solid var(--border);">
                <div>
                  <strong style="color:var(--text);font-size:0.88rem;"><?= e($ar['title'] ?? '') ?></strong>
                  <div style="font-size:0.72rem;color:var(--muted);margin-top:2px;">
                    <span>بقلم: <?= e($ar['author'] ?? 'سارة علي') ?></span> · 
                    <span>🕒 <?= e($ar['date'] ?? '') ?></span>
                  </div>
                </div>
                <div style="display:flex;gap:8px;">
                  <button type="button" onclick='editArticle(<?= json_encode($ar, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>)' style="background:var(--card);border:1px solid var(--border);padding:5px 12px;border-radius:8px;cursor:pointer;color:var(--blue);font-weight:700;font-size:0.78rem;">
                    <i class="fas fa-edit"></i> تعديل
                  </button>
                  <a href="?admin=panel&delete_article=<?= urlencode((string)$ar['id']) ?>&amp;admin_tab=articles&amp;<?= e($csrfQs) ?>" onclick="return confirm('حذف هذا المقال نهائياً؟')" style="color:#ef4444;text-decoration:none;padding:5px 8px;border-radius:8px;background:rgba(239,68,68,0.1);display:flex;align-items:center;" title="حذف المقال">
                    <i class="fas fa-trash"></i>
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<script>
window.FC = {
  data: <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>,
  endpoint: <?= json_encode($_SERVER['SCRIPT_NAME'] ?? 'index.php', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>,
  csrf: <?= json_encode($isAdmin ? $csrfToken : '', JSON_HEX_TAG) ?>
};
</script>
<script src="assets/app.js?v=<?= (int)@filemtime(__DIR__ . '/assets/app.js') ?>" defer></script>
</body>
</html>
