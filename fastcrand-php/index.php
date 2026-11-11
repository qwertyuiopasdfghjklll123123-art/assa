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
<style>
/* === GENERAL STYLES === */
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{touch-action:manipulation;-ms-touch-action:manipulation;scroll-behavior:smooth}
:root{
  --bg:#f8f6f3;--card:#ffffff;--text:#1a1a2e;--muted:#6b7280;
  --blue:#ff7a1a;--cyan:#f26a00;--gold:#ffa64d;--pink:#ff6b8a;
  --shadow:0 1px 2px rgba(255,122,26,0.08),0 6px 18px rgba(255,122,26,0.06);
  --shadow-hover:0 12px 32px rgba(255,122,26,0.14);
  --border:rgba(255,122,26,0.09);--hover-bg:rgba(255,122,26,0.05);
  --gradient-primary:linear-gradient(135deg,#ff7a1a,#f26a00);
  --gradient-secondary:linear-gradient(135deg,#ffa64d,#ff6b8a);
}
[data-theme="dark"]{
  --bg:#0d0d0d;--card:#1a1a1a;--text:#f0e6d8;--muted:#a09080;
  --blue:#ff944d;--cyan:#ff8a33;--gold:#ffc274;--pink:#ff8fa8;
  --shadow:0 1px 2px rgba(0,0,0,0.5),0 6px 20px rgba(0,0,0,0.6);
  --shadow-hover:0 12px 32px rgba(255,148,77,0.18);
  --border:rgba(240,230,216,0.08);--hover-bg:rgba(240,230,216,0.05);
  --gradient-primary:linear-gradient(135deg,#ff944d,#ff8a33);
  --gradient-secondary:linear-gradient(135deg,#ffc274,#ff8fa8);
}
body{
  font-family:'IBM Plex Sans Arabic',sans-serif;
  background:var(--bg);color:var(--text);
  width:100%;max-width:100%;margin:0;padding:0 0 20px 0;
  min-height:100vh;overflow-x:hidden;position:relative;
  scrollbar-width:none;-ms-overflow-style:none;
  -webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;
}
body::-webkit-scrollbar{display:none}
input, textarea, select, [contenteditable="true"]{
  -webkit-user-select:text !important;-moz-user-select:text !important;-ms-user-select:text !important;user-select:text !important;
}
::selection{background:transparent;color:inherit}
input::selection, textarea::selection{background:var(--blue);color:#fff}

.bg-orb{position:fixed;border-radius:50%;pointer-events:none;z-index:0}
.bo1{width:500px;height:500px;background:radial-gradient(circle,rgba(255,122,26,0.12),transparent 68%);top:-190px;right:-170px}
.bo2{width:400px;height:400px;background:radial-gradient(circle,rgba(242,106,0,0.08),transparent 68%);bottom:10%;left:-170px}

/* HEADER & NAVBAR */
.hdr{
  display:flex;align-items:center;justify-content:space-between;
  padding:calc(12px + env(safe-area-inset-top,0px)) 16px 12px;
  position:relative;z-index:100;
  max-width:850px;margin:0 auto;
}
.hdr .logo{
  font-size:1.2rem;font-weight:900;letter-spacing:0.5px;
  background:var(--gradient-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent;
  cursor:pointer;display:inline-flex;align-items:center;text-decoration:none;
}
.hdr-right{display:flex;align-items:center;gap:8px}

.theme-toggle-btn{
  width:36px;height:36px;border-radius:50%;
  background:var(--card);box-shadow:var(--shadow);border:none;cursor:pointer;
  display:flex;align-items:center;justify-content:center;
  font-size:0.9rem;color:var(--text);transition:transform 0.15s,box-shadow 0.2s;
}
.theme-toggle-btn:active{transform:scale(0.88)}
.theme-toggle-btn .sun{display:none}
.theme-toggle-btn .moon{display:inline}
[data-theme="dark"] .theme-toggle-btn .sun{display:inline;color:var(--gold)}
[data-theme="dark"] .theme-toggle-btn .moon{display:none}

.lang-btn{
  display:flex;align-items:center;gap:6px;padding:6px 14px;
  border-radius:100px;background:var(--card);box-shadow:var(--shadow);
  border:none;cursor:pointer;font-family:inherit;font-size:0.75rem;font-weight:700;color:var(--text);
}
.lang-wrap{position:relative;z-index:200}
.lang-dropdown{
  position:absolute;top:100%;left:0;margin-top:6px;
  background:var(--card);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,0.12);
  overflow:hidden;z-index:200;display:none;min-width:150px;
}
[dir="rtl"] .lang-dropdown{left:auto;right:0}
[dir="ltr"] .lang-dropdown{right:auto;left:0}
.lang-dropdown.open{display:block}
.lang-opt{
  display:flex;align-items:center;gap:8px;padding:10px 14px;
  font-size:0.75rem;font-weight:700;cursor:pointer;border:none;background:none;width:100%;color:var(--text);font-family:inherit;
}
.lang-opt:hover{background:var(--hover-bg)}
.lang-opt.active{color:var(--blue)}

/* CONTAINER */
.page-container{
  max-width:850px;margin:0 auto;position:relative;z-index:1;padding:0 14px;
}

.site-section{
  margin-bottom: 24px;
  border-radius: 26px;
  transition: opacity 0.3s ease;
}

/* القسم النشط والمركز عليه: واضح وبارز بالكامل ومفتوح للتفاعل */
.site-section.is-focused{
  opacity: 1;
  pointer-events: auto;
}

/* الأقسام الأخرى: مغبشة وهادئة (Blur) لكن قابلة للمس والنقر للتركيز المباشر دون أي اهتزاز أو إزاحة */
.site-section.is-blurred{
  opacity: 0.38;
  pointer-events: auto;
  cursor: pointer;
}

/* عند لمس أو مرور المؤشر على القسم المغبش */
.site-section.is-blurred:hover{
  opacity: 0.72;
}

/* HERO PROFILE CARD (البطاقة البرتقالية التعريفية اللامعة) */
.prof{
  margin:10px 0 18px;border-radius:24px;padding:24px 20px;
  background:linear-gradient(135deg, #ff8c2b 0%, #ff6f00 45%, #ff9d42 80%, #f26a00 100%);
  position:relative;overflow:hidden;color:#fff;
  box-shadow:0 10px 35px rgba(255,122,26,0.38), 0 0 20px rgba(255,166,77,0.25);
  border:1px solid rgba(255,255,255,0.3);
}
.prof::before{
  content:'';position:absolute;top:0;right:0;width:250px;height:250px;
  background:radial-gradient(circle,rgba(255,255,255,0.35),transparent 70%);pointer-events:none;
}
/* تأثير اللمعان المتحرك الفاخر (Shiny Shimmer Effect) */
.prof::after{
  content:'';position:absolute;top:-50%;left:-60%;width:50%;height:200%;
  background:linear-gradient(60deg, transparent 20%, rgba(255,255,255,0.38) 50%, rgba(255,255,255,0.05) 70%, transparent 100%);
  transform:rotate(25deg);
  pointer-events:none;
  animation:profShimmer 4.5s ease-in-out infinite;
}
@keyframes profShimmer{
  0%{ left:-80%; opacity:0; }
  20%{ opacity:1; }
  60%{ left:140%; opacity:0.8; }
  100%{ left:140%; opacity:0; }
}
.p-row{display:flex;align-items:center;gap:16px;position:relative;z-index:1}
.p-av{
  width:68px;height:68px;border-radius:22px;
  background:rgba(255,255,255,0.22);backdrop-filter:blur(10px);
  display:flex;align-items:center;justify-content:center;flex-shrink:0;
  border:2px solid rgba(255,255,255,0.45);overflow:hidden;
  box-shadow:0 0 0 4px rgba(255,255,255,0.2),0 8px 20px rgba(0,0,0,0.16);
}
.p-av img{width:100%;height:100%;object-fit:cover}
.p-av i{font-size:1.8rem;color:#fff}
.p-info h2{font-size:1.25rem;font-weight:900;letter-spacing:-0.2px;margin-bottom:3px;text-shadow:0 2px 4px rgba(0,0,0,0.15)}
.p-info p{font-size:0.75rem;opacity:0.95;line-height:1.4;text-shadow:0 1px 2px rgba(0,0,0,0.1)}
.p-info .online{display:inline-flex;align-items:center;gap:5px;font-size:0.65rem;color:#d4ffd4;margin-top:6px;font-weight:700}
.p-info .online .dot{width:6px;height:6px;border-radius:50%;background:#80ff80;animation:blink 2s infinite;box-shadow:0 0 8px #80ff80}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0.3}}

/* SECTION HEADERS */
.sec{margin-bottom:20px}
.sh{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding:0 4px}
.sh h3{font-size:0.95rem;font-weight:900;display:flex;align-items:center;gap:8px}
.sh h3 i{
  width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;
  background:var(--hover-bg);border-radius:8px;font-size:0.85em;flex-shrink:0;
}
.sh .al{font-size:0.72rem;color:var(--blue);font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:4px}

/* ===== حركة شريطي التطبيقات المتعاكسة تلقائياً (Opposite Continuous Infinite Marquee) ===== */
.apps-marquee-container{
  display:flex;flex-direction:column;gap:12px;
  margin:0 -14px 14px;padding:0 14px;overflow:hidden;position:relative;
}
.marquee-track-row{
  display:flex;width:100%;overflow:hidden;user-select:none;position:relative;
}
.marquee-track-inner{
  display:flex;gap:12px;flex-shrink:0;min-width:100%;
}

/* الصف الأول يتحرك لليسار */
.animate-forward{
  animation: moveForward 30s linear infinite;
}
/* الصف الثاني يتحرك في الاتجاه المعاكس تماماً */
.animate-reverse{
  animation: moveReverse 30s linear infinite;
}

@keyframes moveForward {
  0% { transform: translateX(0%); }
  100% { transform: translateX(-100%); }
}

@keyframes moveReverse {
  0% { transform: translateX(-100%); }
  100% { transform: translateX(0%); }
}

/* بالنسبة لاتجاه RTL */
[dir="rtl"] .animate-forward{
  animation: moveForwardRtl 30s linear infinite;
}
[dir="rtl"] .animate-reverse{
  animation: moveReverseRtl 30s linear infinite;
}

@keyframes moveForwardRtl {
  0% { transform: translateX(0%); }
  100% { transform: translateX(100%); }
}

@keyframes moveReverseRtl {
  0% { transform: translateX(100%); }
  100% { transform: translateX(0%); }
}

.app-card-item{
  min-width:215px;max-width:215px;
  background:var(--card);border-radius:18px;padding:12px 14px;
  box-shadow:var(--shadow);border:1px solid var(--border);
  display:flex;align-items:center;gap:12px;
  flex-shrink:0;cursor:pointer;
  transition:transform 0.15s,box-shadow 0.2s,border-color 0.2s;
}
.app-card-item:hover{transform:translateY(-2px);border-color:var(--blue);box-shadow:var(--shadow-hover)}
.app-card-item:active{transform:scale(0.97)}

.app-card-icon{
  width:44px;height:44px;border-radius:14px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.15rem;color:#fff;flex-shrink:0;overflow:hidden;
  box-shadow:0 4px 12px rgba(255,122,26,0.22);
}
.app-card-icon img{width:100%;height:100%;object-fit:cover}

.app-card-details{flex:1;min-width:0}
.app-card-details h4{font-size:0.88rem;font-weight:800;margin-bottom:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.app-card-meta{display:flex;align-items:center;gap:6px;font-size:0.62rem;font-weight:700}
.app-card-meta .badge{color:var(--blue);background:var(--hover-bg);padding:2px 6px;border-radius:6px}

.app-btn-action{
  padding:6px 11px;border-radius:10px;font-size:0.65rem;font-weight:800;
  background:var(--gradient-primary);color:#fff;border:none;cursor:pointer;
  display:inline-flex;align-items:center;gap:4px;box-shadow:0 2px 8px rgba(255,122,26,0.28);
  flex-shrink:0;
}

/* زر عرض جميع التطبيقات والمواقع */
.btn-view-all-apps{
  width:100%;padding:12px;border-radius:14px;
  background:var(--card);color:var(--blue);
  border:1.5px solid var(--border);box-shadow:var(--shadow);
  font-family:inherit;font-size:0.82rem;font-weight:800;
  cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;
  transition:all 0.2s;
}
.btn-view-all-apps:hover{background:var(--hover-bg);border-color:var(--blue)}
.btn-view-all-apps:active{transform:scale(0.98)}

/* شبكة عرض جميع التطبيقات كبطاقات مربعة متساوية الطول والارتفاع جنباً إلى جنب (بحجم أصغر قليلاً وأنيق) */
#allAppsOverlay .page-content{
  max-width:800px;
}
.all-apps-square-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill, minmax(130px, 1fr));
  gap:10px;
  padding:4px 0 24px;
  justify-content:center;
}
@media (max-width: 480px){
  .all-apps-square-grid{
    grid-template-columns:repeat(2, 1fr);
    gap:10px;
  }
}
@media (min-width: 600px){
  .all-apps-square-grid{
    grid-template-columns:repeat(3, 1fr);
    gap:12px;
  }
}
@media (min-width: 850px){
  .all-apps-square-grid{
    grid-template-columns:repeat(4, 1fr);
    gap:14px;
  }
}

.app-square-card{
  aspect-ratio:1 / 1;
  width:100%;
  max-width:160px;
  margin:0 auto;
  background:var(--card);
  border:1.5px solid var(--border);
  border-radius:18px;
  padding:10px 8px 8px;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:space-between;
  text-align:center;
  box-shadow:var(--shadow);
  cursor:pointer;
  transition:transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), box-shadow 0.2s, border-color 0.2s;
  position:relative;
  overflow:hidden;
  box-sizing:border-box;
}
.app-square-card:hover{
  transform:translateY(-3px) scale(1.02);
  border-color:var(--blue);
  box-shadow:0 8px 20px rgba(255,122,26,0.2);
}
.app-square-card:active{
  transform:scale(0.96);
}
.app-square-card-icon{
  width:44px;
  height:44px;
  border-radius:13px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:1.28rem;
  color:#fff;
  overflow:hidden;
  box-shadow:0 4px 12px rgba(0,0,0,0.16);
  flex-shrink:0;
  margin-top:1px;
}
.app-square-card-icon img{
  width:100%;
  height:100%;
  object-fit:cover;
}
.app-square-card-info{
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:2px;
  width:100%;
  overflow:hidden;
}
.app-square-card-info h4{
  font-size:0.82rem;
  font-weight:900;
  margin:0;
  color:var(--text);
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  width:100%;
}
.app-square-card-meta{
  font-size:0.65rem;
  color:var(--muted);
  font-weight:800;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:4px;
}
.app-square-card-meta i{
  color:var(--blue);
  font-size:0.68rem;
}
.app-square-card-btn{
  width:100%;
  padding:6px 6px;
  border-radius:10px;
  background:var(--gradient-primary);
  color:#fff;
  border:none;
  font-family:inherit;
  font-size:0.72rem;
  font-weight:900;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:5px;
  box-shadow:0 2px 8px rgba(255,122,26,0.28);
  transition:all 0.15s;
}
.app-square-card-btn:active{
  transform:scale(0.95);
}

/* ABOUT */
.abt{
  background:var(--card);border-radius:20px;padding:18px 20px;
  font-size:0.85rem;line-height:1.9;color:var(--muted);box-shadow:var(--shadow);border:1px solid var(--border);
}

/* ARTICLES */
.art-list{display:flex;flex-direction:column;gap:10px}
.b-art{
  background:var(--card);border-radius:18px;padding:16px;
  box-shadow:var(--shadow);border:1px solid var(--border);cursor:pointer;
  transition:transform 0.15s,box-shadow 0.2s;
}
.b-art:hover{transform:translateY(-2px);box-shadow:var(--shadow-hover)}
.b-art-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.b-art-badge{font-size:0.58rem;padding:4px 10px;border-radius:8px;font-weight:800;background:var(--hover-bg);color:var(--blue)}
.b-art-date{font-size:0.62rem;color:var(--muted);display:flex;align-items:center;gap:4px}
.b-art h4{font-size:0.9rem;font-weight:800;margin-bottom:4px;line-height:1.5}
.b-art p{font-size:0.75rem;color:var(--muted);line-height:1.6}
.b-art-footer{display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)}
.b-art-read{font-size:0.7rem;color:var(--blue);font-weight:800;display:flex;align-items:center;gap:4px}
.b-art-time{font-size:0.62rem;color:var(--muted)}

/* SKILLS */
.sk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(0,1fr));gap:10px}
.sk-card{
  background:var(--card);border-radius:16px;padding:12px;box-shadow:var(--shadow);
  border:1px solid var(--border);display:flex;align-items:center;gap:10px;
  opacity:0;
  transform:translateY(22px) scale(0.88);
  transition:opacity 0.45s cubic-bezier(0.34, 1.56, 0.64, 1), transform 0.45s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s;
  will-change:transform, opacity;
}
.sk-card.popped{
  opacity:1;
  transform:translateY(0) scale(1);
}
.sk-card:hover{
  transform:translateY(-2px) scale(1.02);
  box-shadow:var(--shadow-hover);
}
.sk-ico{
  width:40px;height:40px;border-radius:12px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.05rem;color:#fff;flex-shrink:0;
}
.sk-info{flex:1;min-width:0}
.sk-name-row{
  display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;
}
.sk-info .sk-name{font-size:0.78rem;font-weight:800}
.sk-info .sk-percent{font-size:0.75rem;font-weight:900;color:var(--blue);font-variant-numeric:tabular-nums;}
.sk-bar{height:6px;background:rgba(0,0,0,0.06);border-radius:10px;overflow:hidden}
[data-theme="dark"] .sk-bar{background:rgba(255,255,255,0.08)}
.sk-fill{height:100%;border-radius:10px;background:var(--gradient-primary);width:0%;transition:width 0.9s cubic-bezier(0.16, 1, 0.3, 1);}

/* CONTACT */
.ct-card{
  background:var(--card);border-radius:20px;box-shadow:var(--shadow);
  border:1px solid var(--border);padding:16px;display:flex;align-items:center;justify-content:center;
  position:relative;overflow:hidden;
}
.ct-card.impact-shake{
  animation:cardImpactShake 0.4s ease-in-out 0.40s;
}
@keyframes cardImpactShake{
  0%{transform:translate(0,0)}
  25%{transform:translate(-3px,2px)}
  50%{transform:translate(3px,-2px)}
  75%{transform:translate(-2px,1px)}
  100%{transform:translate(0,0)}
}
.ct-collision-wrapper{
  position:relative;width:100%;display:flex;justify-content:center;align-items:center;overflow:visible;
}
.ct-icons{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;position:relative;z-index:2;}
.ct-btn{
  padding:12px 28px;border-radius:14px;background:var(--gradient-primary);
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  font-size:0.88rem;color:#fff;text-decoration:none;font-weight:800;
  box-shadow:0 4px 14px rgba(255,122,26,0.25);border:none;cursor:pointer;font-family:inherit;
  position:relative;
}
.ct-btn:active{transform:scale(0.92)}
.ct-btn-whatsapp{background:#25D366;box-shadow:0 4px 14px rgba(37,211,102,0.3)}
.ct-btn-instagram{background:linear-gradient(135deg,#f09433,#e6683c,#dc2743,#c13584,#833ab4,#5851db);box-shadow:0 4px 14px rgba(225,48,108,0.3)}

/* حركة التصادم الأبطأ قليلاً والأكثر وضوحاً وسلاسة من اليمين واليسار */
.ct-collision-active .ct-btn-collide-right{
  animation:collideFromRight 0.95s cubic-bezier(0.25, 0.95, 0.35, 1.15) forwards;
}
.ct-collision-active .ct-btn-collide-left{
  animation:collideFromLeft 0.95s cubic-bezier(0.25, 0.95, 0.35, 1.15) forwards;
}

@keyframes collideFromRight{
  0%{transform:translateX(80vw) scale(0.65);opacity:0}
  15%{opacity:1}
  42%{transform:translateX(-26px) scale(0.9,1.18);opacity:1}
  52%{transform:translateX(15px) scale(1.14,0.9)}
  72%{transform:translateX(-5px) scale(0.98,1.02)}
  100%{transform:translateX(0) scale(1);opacity:1}
}

@keyframes collideFromLeft{
  0%{transform:translateX(-80vw) scale(0.65);opacity:0}
  15%{opacity:1}
  42%{transform:translateX(26px) scale(0.9,1.18);opacity:1}
  52%{transform:translateX(-15px) scale(1.14,0.9)}
  72%{transform:translateX(5px) scale(0.98,1.02)}
  100%{transform:translateX(0) scale(1);opacity:1}
}

/* تأثير الانفجار: ومضات وموجة وشظايا */
.ct-explosion-burst{
  position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);
  width:10px;height:10px;pointer-events:none;z-index:5;display:none;
}
.ct-collision-active .ct-explosion-burst{display:block}

.ct-shockwave{
  position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) scale(0);
  width:32px;height:32px;border-radius:50%;border:4px solid #ff7a1a;
  box-shadow:0 0 25px #ff7a1a,0 0 40px #25D366;opacity:0;
}
.ct-collision-active .ct-shockwave{
  animation:shockwaveBoom 0.65s ease-out 0.40s forwards;
}
@keyframes shockwaveBoom{
  0%{transform:translate(-50%,-50%) scale(0.1);opacity:1;border-width:6px}
  60%{opacity:0.9;border-color:#fff}
  100%{transform:translate(-50%,-50%) scale(6);opacity:0;border-width:1px}
}

.ct-flash{
  position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) scale(0);
  width:70px;height:70px;border-radius:50%;
  background:radial-gradient(circle,rgba(255,255,255,1) 0%,rgba(255,122,26,0.85) 45%,rgba(255,122,26,0) 80%);
  opacity:0;
}
.ct-collision-active .ct-flash{
  animation:flashBurst 0.5s ease-out 0.40s forwards;
}
@keyframes flashBurst{
  0%{transform:translate(-50%,-50%) scale(0.2);opacity:1}
  35%{transform:translate(-50%,-50%) scale(2.4);opacity:0.95}
  100%{transform:translate(-50%,-50%) scale(4);opacity:0}
}

.ct-sparks{position:absolute;top:50%;left:50%;width:0;height:0}
.spk{
  position:absolute;width:6px;height:6px;border-radius:50%;
  opacity:0;box-shadow:0 0 8px currentColor;
}
.ct-collision-active .spk{
  animation:sparkFly 0.58s ease-out 0.41s forwards;
}
.spk1{--tx:50px;--ty:-35px;background:#25D366;color:#25D366}
.spk2{--tx:-50px;--ty:-40px;background:#e1306c;color:#e1306c}
.spk3{--tx:65px;--ty:15px;background:#ffb703;color:#ffb703}
.spk4{--tx:-65px;--ty:15px;background:#00f5d4;color:#00f5d4}
.spk5{--tx:35px;--ty:45px;background:#ff7a1a;color:#ff7a1a}
.spk6{--tx:-35px;--ty:45px;background:#ffffff;color:#ffffff}
.spk7{--tx:0px;--ty:-60px;background:#ffd166;color:#ffd166}
.spk8{--tx:0px;--ty:60px;background:#06d6a0;color:#06d6a0}
@keyframes sparkFly{
  0%{transform:translate(0,0) scale(1.6);opacity:1}
  100%{transform:translate(var(--tx),var(--ty)) scale(0);opacity:0}
}

/* فوتر عداد الزوار */
.visitors-footer{
  text-align:center;padding:16px 16px 14px;
}
.visitors-pill{
  display:inline-flex;align-items:center;gap:8px;
  background:var(--card);border:1px solid var(--border);
  box-shadow:var(--shadow);padding:8px 18px;border-radius:100px;
  font-size:0.75rem;font-weight:800;color:var(--text);
}
.visitors-pill i{color:var(--blue);font-size:0.85rem}
.visitors-pill .num{
  color:var(--blue);font-size:0.9rem;font-weight:900;letter-spacing:0.5px;
}

/* OVERLAYS (النوافذ المنبثقة) */
.page-overlay{
  position:fixed;inset:0;z-index:9999;background:var(--bg);padding:18px;
  overflow-y:auto;-webkit-overflow-scrolling:touch;display:none;
}
.page-overlay.open{display:block}
.page-overlay .close-btn{
  position:sticky;top:0;z-index:10;background:var(--bg);
  padding:8px 0 12px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border);
}
.page-overlay .close-btn button{background:none;border:none;font-size:1rem;color:var(--text);cursor:pointer;font-weight:800;font-family:inherit}
.page-overlay .page-content{padding:16px 0;max-width:580px;margin:0 auto}

/* أزرار التحميل: أندرويد وآيفون (حجم مدمج وأصغر قليلاً وأنيق) */
.download-action-grid{
  display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0;
}
.btn-dl-android{
  background:linear-gradient(135deg, #3ddc84, #1aa356);color:#fff;
  padding:9px 10px;border-radius:12px;font-size:0.76rem;font-weight:800;
  border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;
  box-shadow:0 3px 10px rgba(61,220,132,0.28);font-family:inherit;width:100%;transition:transform 0.15s;
}
.btn-dl-android:active{transform:scale(0.96)}
.btn-dl-ios{
  background:linear-gradient(135deg, #1c1c1e, #2c2c2e);color:#fff;
  padding:9px 10px;border-radius:12px;font-size:0.76rem;font-weight:800;
  border:none;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:6px;
  box-shadow:0 3px 10px rgba(0,0,0,0.25);font-family:inherit;width:100%;transition:transform 0.15s;
}
[data-theme="dark"] .btn-dl-ios{background:linear-gradient(135deg, #3a3a3c, #2c2c2e);border:1px solid rgba(255,255,255,0.15)}
.btn-dl-ios:active{transform:scale(0.96)}

/* خطوات تثبيت تطبيق الآيفون PWA مع حركة تساقط احترافية وظهور نصوص كلوحة الطلب */
.pwa-step-card{
  background:var(--card);
  border-radius:18px;
  padding:15px 16px;
  margin-bottom:12px;
  border:1.5px solid var(--border);
  box-shadow:var(--shadow);
  display:flex;
  gap:14px;
  align-items:flex-start;
  transition:border-color 0.2s, box-shadow 0.2s, transform 0.2s;
}
.pwa-step-card:hover{
  border-color:var(--blue);
  box-shadow:0 8px 24px rgba(255,122,26,0.22);
}
.pwa-step-num{
  width:36px;
  height:36px;
  border-radius:12px;
  background:var(--gradient-primary);
  color:#fff;
  display:flex;
  align-items:center;
  justify-content:center;
  font-weight:900;
  font-size:0.95rem;
  flex-shrink:0;
  box-shadow:0 4px 14px rgba(255,122,26,0.32);
}
.pwa-step-body h4{
  font-size:0.92rem;
  font-weight:900;
  margin-bottom:4px;
  color:var(--text);
}
.pwa-step-body p{
  font-size:0.77rem;
  color:var(--muted);
  line-height:1.65;
  font-weight:600;
}

/* حركة تساقط القوالب المتتالية وظهور النصوص كلوحة الطلب */
.pwa-step-drop{
  position:relative;
  overflow:hidden;
  opacity:0;
  transform:translateY(-50px) scale(0.92);
  will-change:transform, opacity, filter;
  transition:border-color 0.2s, box-shadow 0.2s;
}
.pwa-step-drop::after{
  content:'';
  position:absolute;
  inset:0;
  background:linear-gradient(90deg, transparent, rgba(255,122,26,0.38), transparent);
  transform:translateX(-100%);
  pointer-events:none;
}
.pwa-drop-header{
  animation:pwaStepCascadeFall 0.42s cubic-bezier(0.18, 0.9, 0.26, 1.22) 0.06s forwards;
}
.pwa-drop-header::after{
  animation:templateScanGlow 0.48s ease-out 0.15s;
}
.pwa-drop-1{
  animation:pwaStepCascadeFall 0.42s cubic-bezier(0.18, 0.9, 0.26, 1.22) 0.26s forwards;
}
.pwa-drop-1::after{
  animation:templateScanGlow 0.48s ease-out 0.36s;
}
.pwa-drop-2{
  animation:pwaStepCascadeFall 0.42s cubic-bezier(0.18, 0.9, 0.26, 1.22) 0.48s forwards;
}
.pwa-drop-2::after{
  animation:templateScanGlow 0.48s ease-out 0.58s;
}
.pwa-drop-3{
  animation:pwaStepCascadeFall 0.42s cubic-bezier(0.18, 0.9, 0.26, 1.22) 0.70s forwards;
}
.pwa-drop-3::after{
  animation:templateScanGlow 0.48s ease-out 0.80s;
}
.pwa-drop-4{
  animation:pwaStepCascadeFall 0.38s cubic-bezier(0.18, 0.9, 0.26, 1.25) 0.92s forwards;
}
.pwa-drop-4::after{
  animation:templateScanGlow 0.48s ease-out 1.02s;
}

@keyframes pwaStepCascadeFall{
  0%{
    opacity:0;
    transform:translateY(-50px) scale(0.92);
    filter:blur(8px);
    border-color:var(--blue);
  }
  65%{
    opacity:1;
    transform:translateY(5px) scale(1.02);
    border-color:var(--blue);
    box-shadow:0 0 22px rgba(255,122,26,0.35);
  }
  85%{
    transform:translateY(-2px) scale(0.995);
  }
  100%{
    opacity:1;
    transform:translateY(0) scale(1);
    filter:blur(0);
    border-color:var(--border);
    box-shadow:var(--shadow);
  }
}

/* نموذج التقييم وكتابة المراجعة */
.rating-form-card{
  background:var(--card);border-radius:18px;padding:16px;margin:16px 0;
  border:1px solid var(--border);box-shadow:var(--shadow);
}
.star-rating-select{
  display:inline-flex;gap:6px;font-size:1.4rem;color:#d1d5db;cursor:pointer;margin:8px 0 12px;
}
.star-rating-select .fa-star.active{color:var(--gold)}
.review-item{
  background:var(--hover-bg);border-radius:14px;padding:12px 14px;margin-bottom:8px;
  font-size:0.8rem;border:1px solid var(--border);
}
.review-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.review-author{font-weight:800;font-size:0.82rem}
.review-stars{color:var(--gold);font-size:0.75rem}

/* كاتب المقال مع الصورة الحقيقية */
.author-box{
  display:flex;align-items:center;gap:12px;padding:14px 0;border-top:1px solid var(--border);margin-top:16px;
}
.author-avatar{
  width:44px;height:44px;border-radius:50%;overflow:hidden;flex-shrink:0;
  background:var(--gradient-primary);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;
  box-shadow:0 4px 12px rgba(255,122,26,0.25);border:2px solid var(--card);
}
.author-avatar img{width:100%;height:100%;object-fit:cover}

/* تفاصيل التطبيق */
.app-detail-head{display:flex;gap:16px;align-items:center;margin-bottom:18px}
.app-detail-icon{
  width:72px;height:72px;border-radius:20px;display:flex;align-items:center;justify-content:center;
  font-size:2rem;color:#fff;overflow:hidden;flex-shrink:0;box-shadow:0 6px 18px rgba(255,122,26,0.28);
}
.app-detail-icon img{width:100%;height:100%;object-fit:cover}
.app-detail-meta h2{font-size:1.35rem;font-weight:900;margin-bottom:4px}
.app-stat-bar{
  display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;
  background:var(--card);border-radius:14px;padding:12px;margin-bottom:18px;text-align:center;box-shadow:var(--shadow);border:1px solid var(--border);
}
.app-stat-box .num{font-size:1.1rem;font-weight:900;color:var(--blue)}
.app-stat-box .lbl{font-size:0.68rem;color:var(--muted);margin-top:2px}
.app-screenshots-scroll{display:flex;gap:10px;overflow-x:auto;padding-bottom:10px;margin:12px 0}
.app-screenshot-thumb{width:140px;height:240px;border-radius:14px;object-fit:cover;flex-shrink:0;border:1px solid var(--border);box-shadow:var(--shadow)}
.app-feat-item{display:flex;gap:8px;align-items:center;padding:10px 14px;background:var(--card);border-radius:12px;margin-bottom:6px;font-size:0.82rem;box-shadow:var(--shadow);border:1px solid var(--border)}

/* إنستغرام */
.instagram-card{text-align:center;background:var(--card);border-radius:22px;padding:36px 20px;box-shadow:var(--shadow);border:1px solid var(--border)}
.instagram-card .ig-logo{font-size:74px;background:linear-gradient(135deg,#f09433,#e6683c,#dc2743,#c13584,#833ab4,#5851db);-webkit-background-clip:text;-webkit-text-fill-color:transparent;display:inline-block;margin-bottom:12px}
.instagram-card .ig-status{display:inline-block;padding:6px 20px;border-radius:100px;background:#dc2743;color:#fff;font-weight:800;font-size:0.82rem;margin:12px 0 14px}

/* لوحة تحكم المدير وتصميم التبويبات المقسمة المنظمة */
.admin-card{background:var(--card);border-radius:18px;padding:20px;margin-bottom:16px;box-shadow:var(--shadow);border:1px solid var(--border)}
.admin-input, .admin-textarea, select.admin-input{
  width:100%;padding:10px 14px;border-radius:12px;border:1px solid var(--border);background:var(--bg);color:var(--text);font-family:inherit;font-size:0.85rem;margin-bottom:12px;outline:none;
}
.admin-label{display:block;font-size:0.75rem;font-weight:800;margin-bottom:6px}

/* شريط التبويبات العلوي للوحة التحكم */
.admin-tabs-nav{
  display:flex;
  gap:8px;
  overflow-x:auto;
  padding:6px;
  background:var(--card);
  border-radius:18px;
  border:1px solid var(--border);
  box-shadow:var(--shadow);
  margin-bottom:18px;
  scrollbar-width:none;
  -webkit-overflow-scrolling:touch;
}
.admin-tabs-nav::-webkit-scrollbar{display:none;}
.admin-tab-btn{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 16px;
  border-radius:12px;
  border:1.5px solid transparent;
  background:transparent;
  color:var(--muted);
  font-family:inherit;
  font-size:0.84rem;
  font-weight:800;
  cursor:pointer;
  white-space:nowrap;
  transition:all 0.22s cubic-bezier(0.16, 1, 0.3, 1);
  user-select:none;
}
.admin-tab-btn:hover{
  color:var(--text);
  background:var(--hover-bg);
}
.admin-tab-btn.active{
  background:var(--gradient-primary);
  color:#fff;
  box-shadow:0 4px 16px rgba(255,122,26,0.38);
  border-color:rgba(255,255,255,0.2);
}
.admin-tab-badge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:20px;
  height:20px;
  padding:0 6px;
  border-radius:10px;
  font-size:0.68rem;
  font-weight:900;
  background:var(--hover-bg);
  color:var(--text);
}
.admin-tab-btn.active .admin-tab-badge{
  background:rgba(255,255,255,0.28);
  color:#fff;
}
.admin-tab-pane{
  display:none;
}
.admin-tab-pane.active{
  display:block;
  animation:adminTabFadeIn 0.28s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes adminTabFadeIn{
  0%{
    opacity:0;
    transform:translateY(8px);
  }
  100%{
    opacity:1;
    transform:translateY(0);
  }
}
.admin-section-header{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:14px;
  flex-wrap:wrap;
  gap:10px;
}
.admin-section-title{
  font-size:1rem;
  font-weight:900;
  color:var(--blue);
  display:flex;
  align-items:center;
  gap:8px;
}

/* بطاقة التقييم المنبثقة الصغيرة بأسفل الشاشة */
.bottom-rate-popup{
  position:fixed;
  bottom:78px;
  left:50%;
  transform:translateX(-50%) translateY(140%);
  width:calc(100% - 24px);
  max-width:380px;
  background:var(--card);
  border:1.5px solid var(--border);
  border-radius:20px;
  box-shadow:0 14px 40px rgba(0,0,0,0.38), 0 0 20px rgba(255,122,26,0.18);
  z-index:10005;
  backdrop-filter:blur(18px);
  -webkit-backdrop-filter:blur(18px);
  transition:transform 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease;
  opacity:0;
  pointer-events:none;
}
.bottom-rate-popup.visible{
  transform:translateX(-50%) translateY(0);
  opacity:1;
  pointer-events:auto;
}
.bottom-rate-content{
  padding:12px 14px;
}
.bottom-rate-header{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:6px;
}
.bottom-rate-title{
  display:flex;
  align-items:center;
  gap:7px;
  font-size:0.86rem;
  font-weight:900;
  color:var(--text);
}
.bottom-rate-close{
  background:var(--hover-bg);
  border:none;
  color:var(--muted);
  width:26px;
  height:26px;
  border-radius:50%;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:0.75rem;
  cursor:pointer;
  transition:all 0.15s;
}
.bottom-rate-close:hover{
  color:var(--text);
  transform:scale(1.1);
}
.bottom-rate-stars-row{
  display:flex;
  justify-content:center;
  margin:4px 0 8px;
}
.bottom-rate-stars-row .star-rating-select{
  margin:0;
  font-size:1.65rem;
  gap:10px;
}
.bottom-rate-actions{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:8px;
}
.bottom-rate-submit{
  background:var(--gradient-primary);
  color:#fff;
  border:none;
  border-radius:12px;
  padding:9px 12px;
  font-size:0.8rem;
  font-weight:800;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:6px;
  box-shadow:0 3px 10px rgba(255,122,26,0.28);
  font-family:inherit;
  transition:transform 0.15s;
}
.bottom-rate-submit:active{transform:scale(0.96)}
.bottom-rate-mute{
  background:var(--hover-bg);
  color:var(--muted);
  border:1px solid var(--border);
  border-radius:12px;
  padding:9px 12px;
  font-size:0.78rem;
  font-weight:800;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:5px;
  font-family:inherit;
  transition:all 0.15s;
}
.bottom-rate-mute:hover{
  color:#ef4444;
  border-color:#ef4444;
}
.bottom-rate-mute:active{transform:scale(0.96)}

/* أزرار الإجراءات العائمة أسفل الشاشة (ابدأ الآن في الرئيسية / تراجع في صفحة التطبيق) */
.floating-bottom-action-btn{
  position:fixed;
  bottom:22px;
  right:18px;
  z-index:10002;
  display:inline-flex;
  align-items:center;
  gap:9px;
  padding:12px 22px;
  border-radius:100px;
  border:none;
  cursor:pointer;
  font-family:inherit;
  font-size:0.86rem;
  font-weight:900;
  text-decoration:none;
  box-shadow:0 8px 25px rgba(0,0,0,0.28);
  -webkit-tap-highlight-color:transparent;
  will-change:transform;
  transition:box-shadow 0.25s, transform 0.2s cubic-bezier(0.16, 1, 0.3, 1), background 0.2s;
}
.floating-bottom-action-btn:active{
  transform:scale(0.92);
}

/* زر ابدأ الآن مع لمعة متحركة وبريق متوهج (Shine Shimmer Effect) */
.floating-bottom-action-btn.demo-btn{
  background:var(--gradient-primary);
  color:#fff;
  box-shadow:0 8px 26px rgba(255,122,26,0.48);
  overflow:hidden;
  position:fixed;
}
.floating-bottom-action-btn.demo-btn::before{
  content:'';
  position:absolute;
  top:-60%;
  left:-80%;
  width:45%;
  height:220%;
  background:linear-gradient(90deg, rgba(255,255,255,0) 0%, rgba(255,255,255,0.85) 50%, rgba(255,255,255,0) 100%);
  transform:rotate(25deg);
  animation:btnShimmerGlow 2.8s infinite cubic-bezier(0.4, 0, 0.2, 1);
  pointer-events:none;
}
@keyframes btnShimmerGlow{
  0%{ left:-80%; opacity:0; }
  10%{ opacity:1; }
  45%{ left:140%; opacity:1; }
  50%, 100%{ left:140%; opacity:0; }
}
.floating-bottom-action-btn.demo-btn i{
  font-size:1.05rem;
  transition:transform 0.25s;
}
.floating-bottom-action-btn.demo-btn:hover i{
  transform:rotate(-15deg) scale(1.18);
}

/* حركة انطلاق زر ابدأ الآن كالصاروخ الناري في الفضاء */
.floating-bottom-action-btn.demo-btn.launching-rocket{
  animation:rocketBlastOff 0.75s cubic-bezier(0.25, 0.7, 0.1, 1) forwards !important;
  pointer-events:none;
  overflow:visible !important;
}
.floating-bottom-action-btn.demo-btn.launching-rocket::after{
  content:'';
  position:absolute;
  bottom:-26px;
  left:50%;
  transform:translateX(-50%);
  width:18px;
  height:35px;
  background:radial-gradient(ellipse at top, #fff 0%, #ffea00 35%, #ff4500 75%, rgba(255,69,0,0) 100%);
  border-radius:50%;
  filter:blur(2px) drop-shadow(0 0 16px #ff6600);
  animation:rocketThrustFlame 0.15s infinite alternate;
}
@keyframes rocketThrustFlame{
  0%{ height:28px; width:16px; opacity:0.85; transform:translateX(-50%) scaleX(0.9); }
  100%{ height:44px; width:22px; opacity:1; transform:translateX(-50%) scaleX(1.2); }
}
@keyframes rocketBlastOff{
  0%{
    transform:translateX(var(--current-x, 0px)) translateY(0) scale(1);
    box-shadow:0 8px 26px rgba(255,122,26,0.48);
  }
  15%{
    transform:translateX(var(--current-x, 0px)) translateY(8px) scale(0.94);
    box-shadow:0 0 35px rgba(255,100,0,0.95), 0 12px 30px rgba(255,160,0,0.85);
  }
  30%{
    transform:translateX(var(--current-x, 0px)) translateY(-18px) scale(1.08);
  }
  60%{
    transform:translateX(var(--current-x, 0px)) translateY(-240px) scale(1.18) rotate(-6deg);
    opacity:1;
  }
  100%{
    transform:translateX(var(--current-x, 0px)) translateY(-130vh) scale(0.28) rotate(-10deg);
    opacity:0;
  }
}

/* حركة بناء الصفحة الخاصة بتقديم الطلب بشكل قوالب احترافية قالب تلو الآخر في 1.5 ثانية بالكامل */
.modal-blurred-card.building-active{
  animation:buildModalCardFrame 0.45s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes buildModalCardFrame{
  0%{
    transform:scale(0.82) translateY(50px);
    opacity:0;
    filter:blur(8px);
  }
  100%{
    transform:scale(1) translateY(0);
    opacity:1;
    filter:blur(0);
    box-shadow:0 25px 65px rgba(0, 0, 0, 0.45), 0 0 45px rgba(255, 122, 26, 0.2);
  }
}

/* تنسيق القوالب المعمارية لصفحة تقديم الطلب */
.build-template-block{
  background:rgba(255,255,255,0.035);
  border:1.5px solid var(--border);
  border-radius:18px;
  padding:12px 14px;
  margin-bottom:10px;
  position:relative;
  overflow:hidden;
  transition:border-color 0.2s, box-shadow 0.2s, background 0.2s;
}
[data-theme="dark"] .build-template-block{
  background:rgba(255,255,255,0.02);
}
.build-template-block:hover{
  border-color:rgba(255,122,26,0.3);
}
.build-template-block:focus-within{
  border-color:var(--blue);
  box-shadow:0 0 16px rgba(255,122,26,0.22);
}

/* حالة البناء: كل قالب يُبنى في توقيته المحسوب بدقة ليكتمل البناء في 1.5 ثانية */
.modal-blurred-card.building-active .build-template-block{
  opacity:0;
  transform:translateY(36px) scale(0.92);
}
.modal-blurred-card.building-active .build-template-block::after{
  content:'';
  position:absolute;
  inset:0;
  background:linear-gradient(90deg, transparent, rgba(255,122,26,0.4), transparent);
  transform:translateX(-100%);
  pointer-events:none;
}

/* القالب 1: ترويسة الطلب والأيقونة (يبدأ عند 0.08s) */
.modal-blurred-card.building-active .tpl-block-1{
  animation:constructTemplate 0.38s cubic-bezier(0.18, 0.9, 0.26, 1.2) 0.08s forwards;
}
.modal-blurred-card.building-active .tpl-block-1::after{
  animation:templateScanGlow 0.45s ease-out 0.15s;
}

/* القالب 2: الاسم الكامل (يبدأ عند 0.38s) */
.modal-blurred-card.building-active .tpl-block-2{
  animation:constructTemplate 0.38s cubic-bezier(0.18, 0.9, 0.26, 1.2) 0.38s forwards;
}
.modal-blurred-card.building-active .tpl-block-2::after{
  animation:templateScanGlow 0.45s ease-out 0.45s;
}

/* القالب 3: رقم الواتساب (يبدأ عند 0.68s) */
.modal-blurred-card.building-active .tpl-block-3{
  animation:constructTemplate 0.38s cubic-bezier(0.18, 0.9, 0.26, 1.2) 0.68s forwards;
}
.modal-blurred-card.building-active .tpl-block-3::after{
  animation:templateScanGlow 0.45s ease-out 0.75s;
}

/* القالب 4: فكرة ومتطلبات المشروع (يبدأ عند 0.98s) */
.modal-blurred-card.building-active .tpl-block-4{
  animation:constructTemplate 0.38s cubic-bezier(0.18, 0.9, 0.26, 1.2) 0.98s forwards;
}
.modal-blurred-card.building-active .tpl-block-4::after{
  animation:templateScanGlow 0.45s ease-out 1.05s;
}

/* القالب 5: زر الإرسال والاعتماد النهائي (يبدأ عند 1.22s وينتهي عند 1.50s) */
.modal-blurred-card.building-active .tpl-block-5{
  animation:constructTemplate 0.30s cubic-bezier(0.18, 0.9, 0.26, 1.25) 1.22s forwards;
}
.modal-blurred-card.building-active .tpl-block-5::after{
  animation:templateScanGlow 0.50s ease-out 1.25s;
}

@keyframes constructTemplate{
  0%{
    opacity:0;
    transform:translateY(36px) scale(0.90);
    filter:blur(6px);
    border-color:var(--blue);
  }
  65%{
    transform:translateY(-4px) scale(1.02);
    border-color:var(--blue);
    box-shadow:0 0 22px rgba(255,122,26,0.38);
  }
  100%{
    opacity:1;
    transform:translateY(0) scale(1);
    filter:blur(0);
    border-color:var(--border);
    box-shadow:none;
  }
}

@keyframes templateScanGlow{
  0%{ transform:translateX(-100%); }
  100%{ transform:translateX(200%); }
}

/* زر رجوع تفاعلي مبتكر ومتحرك */
.floating-bottom-action-btn.back-btn{
  z-index:100005;
  background:var(--card);
  color:var(--text);
  border:1.8px solid var(--border);
  box-shadow:0 8px 24px rgba(0,0,0,0.28);
  backdrop-filter:blur(16px);
  -webkit-backdrop-filter:blur(16px);
  overflow:hidden;
  transition:all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.floating-bottom-action-btn.back-btn i{
  color:var(--blue);
  font-size:1rem;
  transition:transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}
.floating-bottom-action-btn.back-btn:hover{
  border-color:var(--blue);
  color:var(--blue);
  box-shadow:0 12px 32px rgba(255,122,26,0.38), 0 0 16px rgba(255,122,26,0.25);
  transform:translateY(-3px) scale(1.06);
}
.floating-bottom-action-btn.back-btn:hover i{
  transform:translateX(5px) scale(1.15);
}
[dir="ltr"] .floating-bottom-action-btn.back-btn:hover i{
  transform:translateX(-5px) scale(1.15);
}
.floating-bottom-action-btn.back-btn:active,
.floating-bottom-action-btn.back-btn.is-clicked{
  transform:scale(0.88) !important;
  border-color:var(--blue);
  background:rgba(255,122,26,0.12);
}

@media (min-width: 768px){
  .page-container{padding:0 24px}
  .app-card-item{min-width:240px;max-width:240px;padding:14px 16px}
  .sk-grid{grid-template-columns:1fr 1fr 1fr}
  .floating-bottom-action-btn{bottom:26px;right:30px;padding:14px 26px;font-size:0.92rem;}
}

/* بطاقة منبثقة مع تغبيش خلفي كامل (Frost Glass Blurred Modal) غير قابلة للسحب أو التمرير */
.modal-blurred-backdrop{
  position:fixed;
  inset:0;
  z-index:99999;
  background:rgba(0, 0, 0, 0.68);
  backdrop-filter:blur(16px);
  -webkit-backdrop-filter:blur(16px);
  display:none;
  align-items:center;
  justify-content:center;
  padding:14px;
  opacity:0;
  transition:opacity 0.25s ease;
  touch-action:none;
  overscroll-behavior:none;
  -webkit-user-drag:none;
}
.modal-blurred-backdrop.open{
  display:flex;
  opacity:1;
}
.modal-blurred-card{
  background:var(--card);
  border:1.5px solid var(--border);
  border-radius:24px;
  width:100%;
  max-width:460px;
  max-height:96vh;
  overflow:hidden !important;
  overflow-y:hidden !important;
  box-shadow:0 25px 65px rgba(0, 0, 0, 0.45), 0 0 40px rgba(255, 122, 26, 0.15);
  padding:16px 16px 14px;
  position:relative;
  animation:modalCardPop 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  touch-action:manipulation;
  overscroll-behavior:none;
  user-select:none;
}
.modal-blurred-card input,
.modal-blurred-card textarea{
  user-select:text;
  -webkit-user-select:text;
}
@keyframes modalCardPop{
  from{ transform:scale(0.92); opacity:0; }
  to{ transform:scale(1); opacity:1; }
}
.modal-close-icon{
  position:absolute;
  top:16px;
  left:16px;
  width:36px;
  height:36px;
  border-radius:50%;
  background:var(--hover-bg);
  border:1px solid var(--border);
  color:var(--muted);
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
  font-size:0.95rem;
  transition:all 0.15s;
  z-index:5;
}
[dir="ltr"] .modal-close-icon{
  left:auto;
  right:16px;
}
.modal-close-icon:hover{
  color:var(--text);
  transform:scale(1.08);
}

/* حالة النجاح بدون بطاقة وبدون زر: تساقط الصح، حركة النصوص يميناً ويساراً، وعداد 3 ثوانٍ */
.modal-blurred-card.success-frameless{
  background:transparent !important;
  border:none !important;
  box-shadow:none !important;
  backdrop-filter:none !important;
  -webkit-backdrop-filter:none !important;
  padding:10px !important;
}
.demo-success-frameless-wrap{
  text-align:center;
  padding:20px 10px;
}
.success-check-drop{
  text-align:center;
  margin-bottom:18px;
  animation:checkDropFall 0.7s cubic-bezier(0.18, 0.9, 0.26, 1.25) forwards;
}
.success-check-icon{
  width:92px;
  height:92px;
  border-radius:50%;
  background:rgba(34, 197, 94, 0.18);
  border:3px solid #22c55e;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  color:#22c55e;
  font-size:2.8rem;
  box-shadow:0 0 35px rgba(34, 197, 94, 0.65), inset 0 0 20px rgba(34, 197, 94, 0.25);
  animation:checkIconPulse 2s infinite ease-in-out;
}
@keyframes checkDropFall{
  0%{
    opacity:0;
    transform:translateY(-140px) scale(0.4);
    filter:blur(10px);
  }
  60%{
    opacity:1;
    transform:translateY(18px) scale(1.18);
    filter:blur(0);
  }
  80%{
    transform:translateY(-6px) scale(0.95);
  }
  100%{
    opacity:1;
    transform:translateY(0) scale(1);
    filter:blur(0);
  }
}
@keyframes checkIconPulse{
  0%, 100%{ transform:scale(1); box-shadow:0 0 30px rgba(34,197,94,0.45); }
  50%{ transform:scale(1.06); box-shadow:0 0 50px rgba(34,197,94,0.8); }
}

/* النص الأول يظهر من اليمين بحركة أنيميشن */
.success-title-slide{
  font-size:1.35rem;
  font-weight:900;
  color:#ffffff;
  text-shadow:0 2px 14px rgba(0,0,0,0.65);
  margin-bottom:10px;
  animation:titleSlideFromRight 0.65s cubic-bezier(0.16, 1, 0.3, 1) 0.22s both;
}
@keyframes titleSlideFromRight{
  0%{
    opacity:0;
    transform:translateX(90px);
    filter:blur(8px);
  }
  100%{
    opacity:1;
    transform:translateX(0);
    filter:blur(0);
  }
}

/* النص الثاني يظهر من اليسار بحركة أنيميشن */
.success-desc-slide{
  font-size:0.88rem;
  color:rgba(255, 255, 255, 0.9);
  text-shadow:0 1px 8px rgba(0,0,0,0.55);
  line-height:1.65;
  margin-bottom:24px;
  max-width:380px;
  margin-left:auto;
  margin-right:auto;
  font-weight:600;
  animation:descSlideFromLeft 0.65s cubic-bezier(0.16, 1, 0.3, 1) 0.38s both;
}
@keyframes descSlideFromLeft{
  0%{
    opacity:0;
    transform:translateX(-90px);
    filter:blur(8px);
  }
  100%{
    opacity:1;
    transform:translateX(0);
    filter:blur(0);
  }
}

/* عداد الـ 3 ثواني للإغلاق */
.success-countdown-wrapper{
  display:inline-flex;
  flex-direction:column;
  align-items:center;
  gap:8px;
  animation:countdownFadeIn 0.5s ease 0.52s both;
}
@keyframes countdownFadeIn{
  from{ opacity:0; transform:translateY(15px); }
  to{ opacity:1; transform:translateY(0); }
}
.success-countdown-badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  background:rgba(255, 255, 255, 0.12);
  border:1px solid rgba(255, 255, 255, 0.22);
  color:#fff;
  padding:7px 18px;
  border-radius:100px;
  font-size:0.82rem;
  font-weight:700;
  backdrop-filter:blur(10px);
  -webkit-backdrop-filter:blur(10px);
  box-shadow:0 4px 16px rgba(0,0,0,0.3);
}
.success-countdown-badge i{
  color:var(--blue);
  font-size:0.88rem;
}
.success-countdown-badge strong{
  font-size:0.95rem;
  color:#22c55e;
}
.success-countdown-track{
  width:140px;
  height:4px;
  border-radius:10px;
  background:rgba(255, 255, 255, 0.15);
  overflow:hidden;
}
.success-countdown-progress{
  width:100%;
  height:100%;
  background:linear-gradient(90deg, #22c55e, #3ddc84);
  border-radius:10px;
  animation:countdownFillBar 3s linear forwards;
}
@keyframes countdownFillBar{
  from{ width:100%; }
  to{ width:0%; }
}

/* زر مشاركة التطبيق داخل صفحة التطبيق */
.btn-share-app{
  width:100%;
  margin:2px 0 16px;
  padding:12px 18px;
  border-radius:14px;
  background:var(--card);
  color:var(--text);
  border:1.5px solid var(--border);
  font-family:inherit;
  font-size:0.85rem;
  font-weight:800;
  cursor:pointer;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  box-shadow:var(--shadow);
  transition:all 0.2s;
}
.btn-share-app i{
  color:var(--blue);
  font-size:1rem;
}
.btn-share-app:hover{
  background:var(--hover-bg);
  border-color:var(--blue);
}
.btn-share-app:active{
  transform:scale(0.98);
}

/* إشعار عائم Toast لتأكيد النسخ والعمليات */
.site-global-toast{
  position:fixed;
  bottom:80px;
  left:50%;
  transform:translateX(-50%) translateY(20px);
  background:rgba(20, 20, 25, 0.94);
  color:#fff;
  border:1px solid rgba(255,255,255,0.18);
  box-shadow:0 10px 30px rgba(0,0,0,0.45);
  backdrop-filter:blur(16px);
  -webkit-backdrop-filter:blur(16px);
  padding:12px 24px;
  border-radius:100px;
  font-size:0.86rem;
  font-weight:800;
  display:inline-flex;
  align-items:center;
  gap:10px;
  z-index:999999;
  opacity:0;
  pointer-events:none;
  transition:all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  white-space:nowrap;
}
.site-global-toast.visible{
  transform:translateX(-50%) translateY(0);
  opacity:1;
  pointer-events:auto;
}
.site-global-toast i{
  color:#22c55e;
  font-size:1.15rem;
}

/* ===== تحسينات الأداء ===== */
/* تعطيل الانتقالات لحظياً عند تبديل الوضع الداكن ليتم التبديل فوراً */
html.theme-switching *, html.theme-switching *::before, html.theme-switching *::after{
  transition:none !important;
}
/* إيقاف حركة الشريطين عندما لا يكون القسم نشطاً أو عند فتح نافذة فوقه */
.site-section.is-blurred .marquee-track-inner, body.has-overlay .marquee-track-inner{animation-play-state:paused}
.marquee-track-inner{will-change:transform;backface-visibility:hidden}
.app-card-item, .app-square-card, .b-art, .sk-card{contain:layout paint}
@media (prefers-reduced-motion: reduce){
  .marquee-track-inner{animation:none !important}
}

/* ===== مشاريع قيد التطوير + ترتيب شاشة عرض الكل ===== */
.wip-badge{
  display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:100px;
  background:rgba(245,158,11,0.16);color:#d97706;font-size:0.62rem;font-weight:900;white-space:nowrap;
}
[data-theme="dark"] .wip-badge{color:#fbbf24}
.app-btn-action.is-wip, .app-square-card-btn.is-wip{
  background:rgba(245,158,11,0.16);color:#d97706;box-shadow:none;cursor:pointer;
}
[data-theme="dark"] .app-btn-action.is-wip, [data-theme="dark"] .app-square-card-btn.is-wip{color:#fbbf24}
.wip-progress{height:5px;border-radius:100px;background:var(--hover-bg);overflow:hidden;width:100%}
.wip-progress > span{display:block;height:100%;border-radius:100px;background:linear-gradient(90deg,#f59e0b,#ff7a1a)}

.apps-all-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:2px 0 12px}
.apps-all-head h3{font-size:1.05rem;font-weight:900}
.apps-all-head small{color:var(--muted);font-weight:700}
.apps-filter{display:flex;gap:6px;flex-wrap:wrap}
.apps-filter button{
  border:1.5px solid var(--border);background:var(--card);color:var(--text);font-family:inherit;
  font-size:0.74rem;font-weight:800;padding:6px 14px;border-radius:100px;cursor:pointer;
}
.apps-filter button.active{background:var(--gradient-primary);color:#fff;border-color:transparent}
.apps-group-title{font-size:0.88rem;font-weight:900;margin:14px 2px 8px;display:flex;align-items:center;gap:8px}
.apps-group-title .cnt{background:var(--hover-bg);border-radius:100px;padding:1px 9px;font-size:0.7rem;color:var(--muted)}

.all-apps-square-grid{grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)) !important;gap:12px !important;padding:2px 0 10px !important}
.app-square-card{
  aspect-ratio:auto !important;max-width:none !important;margin:0 !important;
  padding:14px 10px 10px !important;gap:8px;justify-content:flex-start !important;
}
.app-square-card-icon{width:56px !important;height:56px !important;border-radius:16px !important;font-size:1.5rem !important}
.app-square-card-info{gap:4px !important}
.app-square-card-info h4{font-size:0.88rem !important}
.app-square-card-cat{font-size:0.66rem;color:var(--muted);font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.app-square-card-meta{gap:10px !important;flex-wrap:wrap}
.app-square-card .wip-progress{margin-top:2px}
.app-square-card-btn{margin-top:auto}
.app-square-card.is-wip{border-style:dashed}
@media (max-width: 480px){
  .all-apps-square-grid{grid-template-columns:repeat(2, 1fr) !important;gap:10px !important}
}
.wip-box{
  border:2px dashed rgba(245,158,11,0.55);background:rgba(245,158,11,0.08);border-radius:18px;
  padding:16px;text-align:center;margin:14px 0;
}
.wip-box .ico{font-size:1.8rem;margin-bottom:6px}
.wip-box h4{font-size:0.95rem;font-weight:900;margin-bottom:4px}
.wip-box p{font-size:0.78rem;color:var(--muted);font-weight:700;margin-bottom:10px}
.wip-box .pct{font-size:0.75rem;font-weight:900;margin-top:6px;color:#d97706}
</style>
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
let DATA = null;
const INITIAL_DATA = <?= json_encode($initialData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
const ENDPOINT = <?= json_encode($_SERVER['SCRIPT_NAME'] ?? 'index.php', JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
const CSRF_TOKEN = <?= json_encode($isAdmin ? $csrfToken : '', JSON_HEX_TAG) ?>;

// تهريب النصوص قبل إدخالها في HTML لمنع XSS
function esc(v){
  return String(v === undefined || v === null ? '' : v)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
// وسيط آمن لاستدعاء دالة داخل onclick
function jsArg(v){ return esc(JSON.stringify(String(v === undefined || v === null ? '' : v))); }
// قبول روابط http/https فقط
function safeHref(u){
  u = String(u || '').trim();
  return /^https?:\/\//i.test(u) ? u : '';
}
// مسار ملف مرفوع آمن
function upl(name){
  name = String(name || '');
  return /^[A-Za-z0-9._-]+$/.test(name) ? 'uploads/' + encodeURIComponent(name) : '';
}
function openExternal(u){
  const href = safeHref(u);
  if(!href) return false;
  const w = window.open(href, '_blank', 'noopener,noreferrer');
  if(w) w.opener = null;
  return true;
}
const CL = 'ar';

const COLORS = {
  blue: 'var(--gradient-primary)',
  pink: 'linear-gradient(135deg,#ff6b8a,#e84a70)',
  cyan: 'linear-gradient(135deg,#f26a00,#d05a00)',
  gold: 'linear-gradient(135deg,#ffa64d,#d08030)',
  green: 'linear-gradient(135deg,#22c55e,#16a34a)',
  purple: 'linear-gradient(135deg,#8b5cf6,#7c3aed)'
};

const LANGS = {
  ar: {
    dir: 'rtl', label: 'العربية',
    about: 'من نحن', works: 'تطبيقاتنا وأعمالنا',
    all: 'عرض الكل ←', articles: 'مقالاتنا', skills: 'مهاراتنا', contact: 'تواصل معنا',
    read: 'اقرأ المزيد', min: 'دقائق قراءة', avail: 'نعمل الآن',
    back: 'رجوع', install: 'تثبيت', downloads: 'تنزيل', view_all_btn: 'عرض جميع التطبيقات والمواقع',
    downloads_total: 'إجمالي التحميلات', user_rating: 'تقييم المستخدمين', security_check: 'فحص الحماية',
    safe: 'آمن', direct_install_hint: 'تنزيل ملف APK مباشر وسريع لجميع أجهزة Android',
    app_details: '📱 تفاصيل وتثبيت التطبيق', all_apps_title: '🚀 جميع تطبيقات ومشاريع الشركة',
    art_details: '📄 تفاصيل المقال', about_app: 'عن التطبيق', app_screens: 'واجهات وشاشات التطبيق:',
    app_features: 'المميزات الرئيسية:', intro: '📌 مقدمة', article_content: '📖 محتوى المقال',
    team: 'فريق FastCrand', download_apk_btn: 'تحميل للأندرويد (تنزيل APK)',
    dl_android_btn: 'تحميل للأندرويد (APK)',
    dl_play_store: 'تحميل من سوق بلاي',
    dl_ios_btn: 'تحميل للآيفون (iOS)',
    pwa_title: '🍏 خطوات تثبيت الموقع كتطبيق على الآيفون (iOS)',
    pwa_step1_title: 'الخطوة 1: فتح الرابط في Safari',
    pwa_step1_desc: 'اضغط على زر "متابعة وفتح الموقع في Safari" بالأسفل لفتح الرابط مباشرة.',
    pwa_step2_title: 'الخطوة 2: زر المشاركة في الآيفون',
    pwa_step2_desc: 'اضغط على زر المشاركة (Share Icon ⎋) في أسفل شاشة المتصفح.',
    pwa_step3_title: 'الخطوة 3: إضافة إلى الشاشة الرئيسية',
    pwa_step3_desc: 'قم بالتمرير للأسفل واختر "إضافة إلى الشاشة الرئيسية" (Add to Home Screen ➕) ليتم تثبيته كتطبيق فوري على هاتفك.',
    pwa_continue_btn: 'متابعة وفتح الموقع للآيفون ←',
    reviews_title: '⭐ التقييمات',
    admin_rating_badge: 'تقييم المنصة',
    add_review_title: 'أضف تقييمك ورأيك في التطبيق:',
    rate_after_dl_title: '⭐ شاركنا تقييمك بعد التحميل:',
    your_name: 'اسمك الكريم',
    your_comment: 'اكتب تجربتك أو تعليقك...',
    submit_review: 'إرسال التقييم الآن',
    skip_review: 'إغلاق ومتابعة',
    review_success: 'شكراً لك! تم إرسال تقييمك بنجاح.',
    rate_app: 'تقييم التطبيق',
    submit_rate_btn: 'إرسال التقييم',
    mute_rate_btn: 'منع العرض',
    mute_rate_success: 'تم منع عرض بطاقة التقييم.',
    get_demo: 'ابدأ الآن',
    demo_header: 'ابدأ الآن',
    demo_title: 'ابدأ الآن',
    demo_desc: 'املأ بياناتك وسيقوم الدعم الفني بتجهيز وإرسال نموذج تجريبي وعرض مخصص لمشروعك عبر الواتساب فوراً.',
    demo_success_title: 'تم إرسال طلبك قيد التدقيق',
    demo_success_desc: 'سيتواصل معك فريق الدعم قريباً عبر الواتساب لإعطائك النظام أو النموذج التجريبي لعملك.',
    share_app_btn: 'مشاركة رابط التطبيق',
    share_copied: 'تم نسخ رابط التطبيق بنجاح!',
    your_fullname: 'الاسم الكامل',
    your_whatsapp: 'رقم الواتساب (بدون رمز الدولة)',
    project_idea: 'شلون المشروع اللي بتحب تشتغله؟ (فكرة ومتطلبات مشروعك)',
    send_demo_req: 'إرسال الطلب الآن',
    ig_active: 'الحساب نشط الآن', ig_disabled: 'الحساب معطل حالياً', ig_last_updated: '🕐 آخر تحديث:',
    visitors_label: 'عدد زوار الموقع:',
    wip: 'قيد التطوير', soon: 'قريباً', wip_title: 'هذا المشروع قيد التطوير حالياً',
    wip_desc: 'نعمل عليه الآن وسيتوفر للتحميل قريباً.', progress_lbl: 'نسبة الإنجاز',
    f_all: 'الكل', f_live: 'المنشورة', f_wip: 'قيد التطوير', apps_word: 'تطبيق',
    live_group: 'تطبيقات متاحة', wip_group: 'مشاريع تحت العمل'
  }
};

// إزالة index.php من شريط العنوان تلقائياً
(function cleanIndexPhpFromUrl(){
  if(window.location.pathname.toLowerCase().endsWith('index.php')){
    const cleanPath = window.location.pathname.replace(/\/index\.php$/i, '/') || '/';
    window.history.replaceState(null, '', cleanPath + window.location.search + window.location.hash);
  }
})();

function openAdmin(preferredTab){
  if(preferredTab){
    switchAdminTab(preferredTab);
  } else {
    try {
      const saved = sessionStorage.getItem('fc_admin_active_tab');
      if(saved && document.getElementById('adminTab_' + saved)){
        switchAdminTab(saved);
      }
    } catch(e){}
  }
  openOverlay('adminOverlay');
  if(window.history && window.history.pushState){
    window.history.pushState(null, '', '?admin=panel');
  }
}

function switchAdminTab(tabKey){
  const validTabs = ['apps', 'settings', 'telegram', 'articles'];
  if(!validTabs.includes(tabKey)) tabKey = 'apps';

  document.querySelectorAll('.admin-tab-btn').forEach(btn => btn.classList.remove('active'));
  document.querySelectorAll('.admin-tab-pane').forEach(pane => pane.classList.remove('active'));

  const btn = document.getElementById('tabBtn_' + tabKey);
  const pane = document.getElementById('adminTab_' + tabKey);

  if(btn) btn.classList.add('active');
  if(pane) pane.classList.add('active');

  try {
    sessionStorage.setItem('fc_admin_active_tab', tabKey);
  } catch(e){}
}

function resetAppForm(){
  switchAdminTab('apps');
  document.getElementById('form_app_id').value = '';
  document.getElementById('form_app_title').value = '';
  document.getElementById('form_app_category').value = '';
  document.getElementById('form_app_web_url').value = '';
  document.getElementById('form_app_downloads').value = 1000;
  document.getElementById('form_app_rating').value = 4.9;
  document.getElementById('form_app_version').value = '1.0.0';
  document.getElementById('form_app_color').value = 'blue';
  document.getElementById('form_app_status').value = 'live';
  document.getElementById('form_app_progress').value = 0;
  document.getElementById('form_app_desc').value = '';
  document.getElementById('form_app_features').value = '';
  resetAndroidOptions();
  document.getElementById('appFormHeading').textContent = 'نشر تطبيق APK جديد باللغتين';
  document.getElementById('appSubmitBtnText').textContent = 'حفظ ونشر التطبيق';
  document.getElementById('appFormBox').scrollIntoView({ behavior: 'smooth' });
}

function resetArticleForm(){
  switchAdminTab('articles');
  document.getElementById('form_art_id').value = '';
  document.getElementById('form_art_title').value = '';
  document.getElementById('form_art_badge').value = 'تطوير';
  document.getElementById('form_art_read_time').value = 4;
  document.getElementById('form_art_author').value = 'سارة علي';
  document.getElementById('form_art_date').value = new Date().toISOString().slice(0, 10);
  document.getElementById('form_art_desc').value = '';
  document.getElementById('form_art_full').value = '';
  const previewBox = document.getElementById('author_photo_preview_box');
  if(previewBox) previewBox.style.display = 'none';
  document.getElementById('artFormHeading').textContent = 'نشر مقال جديد باللغتين';
  document.getElementById('artSubmitBtnText').textContent = 'حفظ ونشر المقال';
  document.getElementById('artFormBox').scrollIntoView({ behavior: 'smooth' });
}

function openWhatsAppContact(){
  if(DATA && DATA.whatsapp_target){
    openExternal(DATA.whatsapp_target);
  }
}

async function testTelegramConnection(){
  const token = document.getElementById('admin_tg_token')?.value.trim() || '';
  const chatId = document.getElementById('admin_tg_chat_id')?.value.trim() || '';
  const resultEl = document.getElementById('telegramTestResult');
  if(!token || !chatId){
    alert('يرجى كتابة توكن البوت والآيدي أولاً قبل الاختبار.');
    return;
  }
  if(resultEl){
    resultEl.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:var(--blue)"></i> جارٍ فحص الاتصال بتيليجرام...';
    resultEl.style.color = 'var(--text)';
  }
  const fd = new FormData();
  fd.append('action', 'test_telegram_bot');
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('token', token);
  fd.append('chat_id', chatId);
  try {
    const res = await fetch(ENDPOINT, { method:'POST', body:fd, credentials:'same-origin' });
    const data = await res.json();
    if(resultEl){
      if(data && data.success){
        resultEl.innerHTML = '<i class="fas fa-check-circle" style="color:#22c55e"></i> ' + esc(data.message);
        resultEl.style.color = '#22c55e';
      } else {
        resultEl.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#ef4444"></i> ' + esc((data && data.message) || 'تعذر الاتصال');
        resultEl.style.color = '#ef4444';
      }
    }
  } catch(e){
    if(resultEl){
      resultEl.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#ef4444"></i> حدث خطأ: ' + esc(e.message);
      resultEl.style.color = '#ef4444';
    }
  }
}

function updateFloatingButtonsState(){
  const appOverlayOpen = document.getElementById('appOverlay')?.classList.contains('open');
  const articleOverlayOpen = document.getElementById('articleOverlay')?.classList.contains('open');
  const allAppsOverlayOpen = document.getElementById('allAppsOverlay')?.classList.contains('open');
  const pwaOverlayOpen = document.getElementById('pwaStepsOverlay')?.classList.contains('open');
  const demoModalOpen = document.getElementById('demoPresentationOverlay')?.classList.contains('open');
  const anyOpen = document.querySelectorAll('.page-overlay.open').length > 0 || demoModalOpen;
  document.body.classList.toggle('has-overlay', !!anyOpen);
  const demoBtn = document.getElementById('floatingMainDemoBtn');
  const backBtn = document.getElementById('floatingAppBackBtn');

  if(appOverlayOpen || articleOverlayOpen || allAppsOverlayOpen || demoModalOpen || pwaOverlayOpen){
    if(backBtn) backBtn.style.display = 'inline-flex';
    if(demoBtn) demoBtn.style.display = 'none';
  } else if(!anyOpen){
    if(demoBtn) {
      demoBtn.style.display = 'inline-flex';
      updateDemoButtonScrollPosition();
    }
    if(backBtn) backBtn.style.display = 'none';
  } else {
    if(demoBtn) demoBtn.style.display = 'none';
    if(backBtn) backBtn.style.display = 'none';
  }
}

function openOverlay(id, content){
  const overlay = document.getElementById(id);
  if(!overlay) return;
  if(content){
    const targetEl = document.getElementById(
      id === 'articleOverlay' ? 'articleDetailContent' :
      (id === 'appOverlay' ? 'appDetailContent' :
      (id === 'allAppsOverlay' ? 'allAppsListContent' :
      (id === 'pwaStepsOverlay' ? 'pwaStepsContent' : null)))
    );
    if(targetEl) targetEl.innerHTML = content;
  }
  overlay.classList.add('open');
  overlay.style.display = 'block';
  overlay.scrollTop = 0;
  document.body.style.overflow = 'hidden';

  updateFloatingButtonsState();
}

function closeOverlay(id){
  if(id === 'demoPresentationOverlay'){
    closeDemoPresentationModal();
    return;
  }
  const overlay = document.getElementById(id);
  if(!overlay) return;
  if(id === 'appOverlay'){
    closeBottomRatePopup();
  }
  overlay.classList.remove('open');
  overlay.style.display = 'none';
  
  const anyOpen = document.querySelectorAll('.page-overlay.open').length > 0;
  if(!anyOpen){
    document.body.style.overflow = '';
  }

  if(id === 'adminOverlay' || id === 'appOverlay' || id === 'articleOverlay'){
    if(window.history && window.history.replaceState){
      const cleanPath = window.location.pathname.replace(/\/index\.php$/i, '/') || './';
      window.history.replaceState(null, '', cleanPath);
    }
  }

  updateFloatingButtonsState();
}


function toggleTheme(){
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const next = isDark ? 'light' : 'dark';
  const root = document.documentElement;
  root.classList.add('theme-switching');
  if(next === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  else document.documentElement.removeAttribute('data-theme');
  try{ localStorage.setItem('theme', next); }catch(e){}
  requestAnimationFrame(() => requestAnimationFrame(() => root.classList.remove('theme-switching')));
}
(function(){
  try{
    if(localStorage.getItem('theme') === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  }catch(e){}
})();

function getAppSeq(appId){
  if(!DATA || !DATA.works) return 1;
  const idx = DATA.works.findIndex(w => w.id === appId);
  return idx >= 0 ? (idx + 1) : 1;
}

function getAppBySeq(seq){
  if(!DATA || !DATA.works || !DATA.works.length) return null;
  const idx = parseInt(seq, 10) - 1;
  return (idx >= 0 && idx < DATA.works.length) ? DATA.works[idx] : null;
}

async function loadData(){
  try{
    DATA = INITIAL_DATA; // البيانات مضمّنة في الصفحة: لا طلب شبكة إضافي
    render();

    // فتح التطبيق فوراً عند الدخول عبر رابط المشاركة التسلسلي (مثال: ?app1 أو ?app2)
    const searchStr = window.location.search || '';
    const hashStr = window.location.hash || '';
    let targetApp = null;

    const m = searchStr.match(/[?&]app(\d+)/i) || hashStr.match(/#app(\d+)/i);
    if(m){
      targetApp = getAppBySeq(m[1]);
    } else {
      const urlParams = new URLSearchParams(searchStr);
      const qVal = urlParams.get('app');
      if(qVal){
        const num = parseInt(qVal.replace(/\D/g, ''), 10);
        if(!isNaN(num) && num > 0){
          targetApp = getAppBySeq(num);
        }
        if(!targetApp){
          targetApp = DATA.works.find(w => w.id === qVal);
        }
      }
    }

    if(targetApp){
      setTimeout(() => {
        showAppDetail(targetApp.id);
      }, 150);
    }
  }catch(e){
    console.error(e);
    const main = document.getElementById('mainApp');
    if(main) main.innerHTML = '<div style="text-align:center;padding:50px;color:var(--muted);font-weight:700;"><i class="fas fa-triangle-exclamation"></i> ' +
      'تعذر تحميل المحتوى، يرجى تحديث الصفحة.' + '</div>';
  }
}

function render(){
  if(!DATA) return;
  const P = DATA.profile, W = DATA.works, A = DATA.articles, S = DATA.skills, SC = DATA.social;
  const L = LANGS[CL];
  const visitorsCount = Number(DATA.total_visitors || 12540).toLocaleString();

  const av = upl(P.photo) ? `<img src="${upl(P.photo)}" alt="${esc(P.name)}">` : `<i class="fas fa-code"></i>`;

  const half = Math.ceil(W.length / 2);
  const row1Apps = W.slice(0, half);
  const row2Apps = W.slice(half);

  const makeCard = (w) => {
    const iconContent = upl(w.icon) ? `<img src="${upl(w.icon)}" alt="" loading="lazy">` : `<i class="${esc(w.icon_class || 'fas fa-mobile-screen')}"></i>`;
    const title = w.title;

    return `
      <div class="app-card-item" onclick="showAppDetail(${jsArg(w.id)})">
        <div class="app-card-icon" style="background:${COLORS[w.color] || COLORS.blue}">${iconContent}</div>
        <div class="app-card-details">
          <h4>${esc(title)}</h4>
          <div class="app-card-meta">
            ${w.status === 'wip'
              ? `<span class="wip-badge">🚧 ${L.wip} ${Number(w.progress) || 0}%</span>`
              : `<span class="badge"><i class="fas fa-download"></i> ${Number(w.downloads || 0).toLocaleString()}</span>`}
          </div>
        </div>
        ${w.status === 'wip'
          ? `<button type="button" class="app-btn-action is-wip"><i class="fas fa-hammer"></i> ${L.soon}</button>`
          : `<button type="button" class="app-btn-action"><i class="fas fa-download"></i> ${L.install}</button>`}
      </div>
    `;
  };

  const roleText = (P.role || '');
  const aboutText = (P.about || '');

  let h = `
    <!-- ==================== القسم الأول: البطاقة التعريفية + التطبيقات ==================== -->
    <div class="site-section is-focused" id="section-1" onclick="focusSection('section-1')">
      <!-- البطاقة التعريفية -->
      <div class="prof">
        <div class="p-row">
          <div class="p-av">${av}</div>
          <div class="p-info">
            <h2>${esc(P.name || 'FastCrand')}</h2>
            <p>${esc(roleText)}</p>
            ${P.available ? `<div class="online"><span class="dot"></span> ${L.avail}</div>` : ''}
          </div>
        </div>
      </div>

      <!-- قسم التطبيقات بحركتين متعاكستين تلقائياً -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-mobile-screen-button" style="color:var(--cyan)"></i> <span>${L.works}</span></h3>
          <span class="al" onclick="showAllAppsModal()">${L.all}</span>
        </div>

        <div class="apps-marquee-container">
          <div class="marquee-track-row">
            <div class="marquee-track-inner animate-forward">
              ${row1Apps.map(w => makeCard(w)).join('')}
            </div>
            <div class="marquee-track-inner animate-forward" aria-hidden="true">
              ${row1Apps.map(w => makeCard(w)).join('')}
            </div>
          </div>

          <div class="marquee-track-row">
            <div class="marquee-track-inner animate-reverse">
              ${row2Apps.map(w => makeCard(w)).join('')}
            </div>
            <div class="marquee-track-inner animate-reverse" aria-hidden="true">
              ${row2Apps.map(w => makeCard(w)).join('')}
            </div>
          </div>
        </div>

        <button type="button" class="btn-view-all-apps" onclick="showAllAppsModal()">
          <i class="fas fa-layer-group"></i> ${L.view_all_btn} (${W.length})
        </button>
      </div>
    </div>

    <!-- ==================== القسم الثاني: من نحن + المقالات ==================== -->
    <div class="site-section is-blurred" id="section-2" onclick="focusSection('section-2')">
      <!-- من نحن -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-user" style="color:var(--blue)"></i> <span>${L.about}</span></h3>
        </div>
        <div class="abt" style="white-space:pre-line;">${esc(aboutText)}</div>
      </div>

      <!-- المقالات -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-newspaper" style="color:var(--gold)"></i> <span>${L.articles}</span></h3>
        </div>
        <div class="art-list">
          ${A.map(a => {
            const aTitle = a.title;
            const aDesc = a.description;
            const aBadge = a.badge;

            return `
              <div class="b-art" onclick="showArticleDetail(${jsArg(a.id)})">
                <div class="b-art-top">
                  <span class="b-art-badge"><i class="fas fa-star"></i> ${esc(aBadge)}</span>
                  <span class="b-art-date"><i class="far fa-calendar"></i> ${esc(a.date)}</span>
                </div>
                <h4>${esc(aTitle)}</h4>
                <p>${esc(aDesc)}</p>
                <div class="b-art-footer">
                  <span class="b-art-read">${L.read} <i class="fas fa-arrow-left"></i></span>
                  <span class="b-art-time"><i class="far fa-clock"></i> ${Number(a.read_time) || 1} ${L.min}</span>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    </div>

    <!-- ==================== القسم الثالث: المهارات + التواصل معنا + عداد الزوار ==================== -->
    <div class="site-section is-blurred" id="section-3" onclick="focusSection('section-3')">
      <!-- المهارات -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-bolt" style="color:var(--pink)"></i> <span>${L.skills}</span></h3>
        </div>
        <div class="sk-grid" id="skillsGridBox">
          ${S.map((s, idx) => `
            <div class="sk-card" id="skCard_${idx}" data-target-percent="${s.percentage}">
              <div class="sk-ico" style="background:${s.color}"><i class="${s.icon}"></i></div>
              <div class="sk-info">
                <div class="sk-name-row">
                  <span class="sk-name">${s.name}</span>
                  <span class="sk-percent" id="skNum_${idx}">0%</span>
                </div>
                <div class="sk-bar"><div class="sk-fill" id="skFill_${idx}" style="width:0%"></div></div>
              </div>
            </div>
          `).join('')}
        </div>
      </div>

      <!-- تواصل معنا -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-paper-plane" style="color:var(--blue)"></i> <span>${L.contact}</span></h3>
        </div>
        <div class="ct-card" id="contactCardBox">
          <div class="ct-collision-wrapper" id="ctCollisionWrap">
            <!-- شظايا وانفجار وموجة صدمة تصادم الأزرار -->
            <div class="ct-explosion-burst">
              <div class="ct-shockwave"></div>
              <div class="ct-flash"></div>
              <div class="ct-sparks">
                <span class="spk spk1"></span>
                <span class="spk spk2"></span>
                <span class="spk spk3"></span>
                <span class="spk spk4"></span>
                <span class="spk spk5"></span>
                <span class="spk spk6"></span>
                <span class="spk spk7"></span>
                <span class="spk spk8"></span>
              </div>
            </div>

            <div class="ct-icons">
              <button type="button" class="ct-btn ct-btn-whatsapp ct-btn-collide-right" onclick="openWhatsAppContact()"><i class="fab fa-whatsapp"></i> <span>${'واتساب'}</span></button>
              <button type="button" class="ct-btn ct-btn-instagram ct-btn-collide-left" onclick="showInstagramStatus()"><i class="fab fa-instagram"></i> <span>${'إنستغرام'}</span></button>
            </div>
          </div>
        </div>
      </div>

      <!-- عداد زوار الموقع الفعلي الذي يبدأ من زر "بدء العد" -->
      <div class="visitors-footer">
        <div class="visitors-pill">
          <i class="fas fa-chart-line"></i>
          <span>${L.visitors_label}</span>
          <span class="num">${visitorsCount}</span>
        </div>
      </div>
    </div>
  `;

  document.getElementById('mainApp').innerHTML = h;

  // تشغيل التتبع التلقائي للتغبيش عند التمرير (Smart Scroll Spy)
  setupScrollBlurObserver();
}

// إدارة تركيز الأقسام وتأثير التغبيش والأنيميشن
let skillsTimeouts = [];
let skillsIntervals = [];
let isSkillsCurrentlyActive = false;
let currentFocusedSectionId = 'section-1';

// حالة تتبع أنيميشن القسم الثالث لضمان عدم حدوث أي حركة عجيبة أو إعادة تشغيل أثناء التصفح
let section3AnimationPlayed = false;
let section3OffscreenReady = true;

function resetSkillsAnimation(){
  skillsTimeouts.forEach(t => clearTimeout(t));
  skillsIntervals.forEach(i => clearInterval(i));
  skillsTimeouts = [];
  skillsIntervals = [];
  isSkillsCurrentlyActive = false;

  const cards = document.querySelectorAll('.sk-card');
  cards.forEach((card, idx) => {
    card.classList.remove('popped');
    const fillEl = document.getElementById(`skFill_${idx}`);
    const numEl = document.getElementById(`skNum_${idx}`);
    if(fillEl){
      fillEl.style.transition = 'none';
      fillEl.style.width = '0%';
      void fillEl.offsetWidth; // Force reflow
      fillEl.style.transition = '';
    }
    if(numEl){
      numEl.textContent = '0%';
    }
  });
}

function triggerSkillsAnimation(force = false){
  const cards = document.querySelectorAll('.sk-card');
  if(!cards.length) return;
  if(isSkillsCurrentlyActive && !force) return;

  resetSkillsAnimation();
  isSkillsCurrentlyActive = true;

  cards.forEach((card, idx) => {
    // انبثاق متتالي وسريع لواحدة تلو الأخرى مع زيادة النسبة من الصفر
    const t = setTimeout(() => {
      card.classList.add('popped');
      const targetPercent = parseInt(card.getAttribute('data-target-percent') || '0', 10);
      const fillEl = document.getElementById(`skFill_${idx}`);
      const numEl = document.getElementById(`skNum_${idx}`);

      if(fillEl){
        fillEl.style.width = targetPercent + '%';
      }

      if(numEl){
        let current = 0;
        const totalDuration = 650;
        const steps = Math.max(targetPercent, 1);
        const intervalTime = Math.max(8, Math.floor(totalDuration / steps));
        const numTimer = setInterval(() => {
          current++;
          if(current >= targetPercent){
            current = targetPercent;
            clearInterval(numTimer);
          }
          numEl.textContent = current + '%';
        }, intervalTime);
        skillsIntervals.push(numTimer);
      }
    }, idx * 75);
    skillsTimeouts.push(t);
  });
}

// تشغيل تصادم وانفجار أزرار التواصل عند النزول إليها
let contactCollisionActive = false;
function triggerContactCollision(force = false){
  const wrap = document.getElementById('ctCollisionWrap');
  const card = document.getElementById('contactCardBox');
  if(!wrap) return;
  if(contactCollisionActive && !force) return;

  contactCollisionActive = true;

  wrap.classList.remove('ct-collision-active');
  if(card) card.classList.remove('impact-shake');

  void wrap.offsetWidth; // Force reflow

  wrap.classList.add('ct-collision-active');
  if(card) card.classList.add('impact-shake');

  setTimeout(() => {
    try {
      if(navigator.vibrate) navigator.vibrate([35, 25, 45]);
    } catch(e){}
  }, 400);
}

function resetContactCollision(){
  contactCollisionActive = false;
  const wrap = document.getElementById('ctCollisionWrap');
  const card = document.getElementById('contactCardBox');
  if(wrap) wrap.classList.remove('ct-collision-active');
  if(card) card.classList.remove('impact-shake');
}

// تشغيل أنيميشن القسم الثالث فقط عند النزول إليه بعد أن كان خارج الشاشة تماماً
function runSection3AnimationsIfNeeded(){
  if(section3OffscreenReady && !section3AnimationPlayed){
    section3AnimationPlayed = true;
    section3OffscreenReady = false;
    triggerSkillsAnimation(true);
    triggerContactCollision(true);
  }
}

// إعادة تهيئة وتصفير أنيميشن القسم الثالث فقط عندما يخرج تماماً عن أسفل الشاشة (في قسم المقالات أو الأول)
function checkSection3OffscreenReset(r3, windowH){
  // إذا أصبح القسم الثالث خارج الشاشة لأسفل تماماً
  if(r3.top >= windowH + 40){
    if(section3AnimationPlayed){
      section3AnimationPlayed = false;
      section3OffscreenReady = true;
      resetSkillsAnimation();
      resetContactCollision();
    }
  }
}

// دالة التركيز على قسم معين وإزالة التغبيش عنه وتغبيش الأقسام الأخرى
function focusSection(focusedId){
  if(focusedId !== currentFocusedSectionId){
    currentFocusedSectionId = focusedId;

    const sections = ['section-1', 'section-2', 'section-3'];
    sections.forEach(id => {
      const el = document.getElementById(id);
      if(el){
        if(id === focusedId){
          el.classList.add('is-focused');
          el.classList.remove('is-blurred');
        } else {
          el.classList.remove('is-focused');
          el.classList.add('is-blurred');
        }
      }
    });

    // تشغيل الأنيميشن للقسم الثالث إذا حان وقته
    if(focusedId === 'section-3'){
      runSection3AnimationsIfNeeded();
    }
  }
}

// حركة زر "ابدأ الآن" ببطء وسلاسة نحو الجانب المعاكس (اليسار) مع التمرير للأسفل
function updateDemoButtonScrollPosition(){
  const demoBtn = document.getElementById('floatingMainDemoBtn');
  if(!demoBtn || demoBtn.style.display === 'none') return;
  if(demoBtn.classList.contains('launching-rocket')) return;
  const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
  if(maxScroll <= 15){
    demoBtn.style.setProperty('--current-x', '0px');
    demoBtn.style.transform = 'translateX(0px)';
    return;
  }
  const scrollY = window.scrollY || window.pageYOffset;
  const progress = Math.min(Math.max(scrollY / maxScroll, 0), 1);
  const btnWidth = demoBtn.offsetWidth || 140;
  const padding = window.innerWidth > 768 ? 30 : 18;
  const travelDistance = Math.max(0, window.innerWidth - btnWidth - (padding * 2));
  const moveX = progress * travelDistance;
  demoBtn.style.setProperty('--current-x', `-${moveX}px`);
  demoBtn.style.transform = `translateX(-${moveX}px)`;
}

// مراقبة التمرير لتحديد القسم النشط وإزالة التغبيش عنه تلقائياً عند الصعود والنزول
let lastScrollYPos = 0;
let scrollBlurHandler = null;
function setupScrollBlurObserver(){
  const sec1 = document.getElementById('section-1');
  const sec2 = document.getElementById('section-2');
  const sec3 = document.getElementById('section-3');
  if(!sec1 || !sec2 || !sec3) return;

  lastScrollYPos = window.scrollY || window.pageYOffset || 0;

  const onScroll = () => {
    updateDemoButtonScrollPosition();

    const scrollY = window.scrollY || window.pageYOffset || 0;
    const windowH = window.innerHeight;
    const docH = document.documentElement.scrollHeight;
    const isScrollingUp = scrollY < lastScrollYPos;
    lastScrollYPos = scrollY;

    const r1 = sec1.getBoundingClientRect();
    const r2 = sec2.getBoundingClientRect();
    const r3 = sec3.getBoundingClientRect();

    // فحص إمكانية إعادة تهيئة أنيميشن القسم الثالث في الخلفية فقط إذا كان خارج الشاشة بالكامل لأسفل
    checkSection3OffscreenReset(r3, windowH);

    // 1. عند التواجد في أعلى الصفحة تماماً: القسم الأول دائماً واضح ونشط
    if(scrollY <= 60){
      focusSection('section-1');
      return;
    }

    // 2. إذا وصل التمرير إلى نهاية الصفحة تماماً: قسم المهارات والتواصل
    if(scrollY + windowH >= docH - 45){
      focusSection('section-3');
      return;
    }

    let targetSectionId = currentFocusedSectionId;

    if(currentFocusedSectionId === 'section-1'){
      // النزول من القسم الأول إلى قسم (من نحن والمقالات)
      if(r2.top < windowH * 0.42){
        targetSectionId = 'section-2';
      }
    } else if(currentFocusedSectionId === 'section-2'){
      // الصعود من قسم المقالات إلى القسم الأول (البطاقة والتطبيقات والأعمال)
      if(r2.top > windowH * 0.48 || scrollY <= 120){
        targetSectionId = 'section-1';
      }
      // النزول من قسم المقالات إلى قسم (المهارات وتواصل معنا)
      else if(r3.top < windowH * 0.45){
        targetSectionId = 'section-3';
      }
    } else if(currentFocusedSectionId === 'section-3'){
      // الصعود من قسم (المهارات وتواصل معنا) إلى قسم (من نحن والمقالات) ليكون واضحاً وغير مغبش
      if(r3.top > windowH * 0.55){
        targetSectionId = 'section-2';
      }
      // في حال الصعود السريع والمباشر نحو القسم الأول
      if(r2.top > windowH * 0.55 || scrollY <= 120){
        targetSectionId = 'section-1';
      }
    }

    if(targetSectionId && targetSectionId !== currentFocusedSectionId){
      focusSection(targetSectionId);
    }
  };

  // إزالة المستمع السابق فعلياً (كان يتراكم مستمع جديد مع كل render / تغيير لغة)
  if(scrollBlurHandler) window.removeEventListener('scroll', scrollBlurHandler);
  let ticking = false;
  scrollBlurHandler = () => {
    if(ticking) return;
    ticking = true;
    requestAnimationFrame(() => { ticking = false; onScroll(); });
  };
  window.addEventListener('scroll', scrollBlurHandler, { passive: true });
  // تشغيل فحص أولي لضمان ظهور القسم الأول بوضوح
  onScroll();
}

// إدارة خيارات تثبيت الأندرويد (حصرية متبادلة بين رابط سوق بلاي وملف الـ APK)
function handleAndroidOptionChange(source){
  const urlInput = document.getElementById('form_app_android_url');
  const fileInput = document.getElementById('form_app_apk_file');
  const existingApkInput = document.getElementById('form_app_existing_apk');
  const existingBadge = document.getElementById('existingApkBadge');
  const hintUrl = document.getElementById('hintUrlStatus');
  const hintApk = document.getElementById('hintApkStatus');

  if(source === 'url'){
    const hasUrl = urlInput && urlInput.value.trim() !== '';
    if(hasUrl){
      if(fileInput){
        fileInput.value = '';
        fileInput.disabled = true;
      }
      if(existingApkInput) existingApkInput.value = '';
      if(existingBadge) existingBadge.style.display = 'none';
      if(hintApk) hintApk.textContent = '❌ معطل: تم إدخال رابط سوق بلاي';
      if(hintUrl) hintUrl.textContent = '✅ نشط: سيتم نقل الزائر إلى سوق بلاي';
    } else {
      if(fileInput) fileInput.disabled = false;
      if(hintApk) hintApk.textContent = '';
      if(hintUrl) hintUrl.textContent = '';
    }
  } else if(source === 'file'){
    const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
    if(hasFile){
      if(urlInput){
        urlInput.value = '';
        urlInput.disabled = true;
      }
      if(existingApkInput) existingApkInput.value = '';
      if(existingBadge) existingBadge.style.display = 'none';
      if(hintUrl) hintUrl.textContent = '❌ معطل: تم اختيار ملف APK مباشر';
      if(hintApk) hintApk.textContent = '✅ نشط: سيتم تنزيل ملف APK مباشرة';
    } else {
      if(urlInput) urlInput.disabled = false;
      if(hintUrl) hintUrl.textContent = '';
      if(hintApk) hintApk.textContent = '';
    }
  }
}

function removeExistingApk(){
  const existingApkInput = document.getElementById('form_app_existing_apk');
  const existingBadge = document.getElementById('existingApkBadge');
  const urlInput = document.getElementById('form_app_android_url');
  const fileInput = document.getElementById('form_app_apk_file');
  const hintUrl = document.getElementById('hintUrlStatus');
  const hintApk = document.getElementById('hintApkStatus');

  if(existingApkInput) existingApkInput.value = '';
  if(existingBadge) existingBadge.style.display = 'none';
  if(urlInput) urlInput.disabled = false;
  if(fileInput) fileInput.disabled = false;
  if(hintUrl) hintUrl.textContent = '';
  if(hintApk) hintApk.textContent = '';
}

function resetAndroidOptions(){
  const urlInput = document.getElementById('form_app_android_url');
  const fileInput = document.getElementById('form_app_apk_file');
  const existingApkInput = document.getElementById('form_app_existing_apk');
  const existingBadge = document.getElementById('existingApkBadge');
  const hintUrl = document.getElementById('hintUrlStatus');
  const hintApk = document.getElementById('hintApkStatus');

  if(urlInput){
    urlInput.value = '';
    urlInput.disabled = false;
  }
  if(fileInput){
    fileInput.value = '';
    fileInput.disabled = false;
  }
  if(existingApkInput) existingApkInput.value = '';
  if(existingBadge) existingBadge.style.display = 'none';
  if(hintUrl) hintUrl.textContent = '';
  if(hintApk) hintApk.textContent = '';
}

// تعديل تطبيق من لوحة المدير
function editApp(ap){
  switchAdminTab('apps');
  document.getElementById('form_app_id').value = ap.id || '';
  document.getElementById('form_app_title').value = ap.title || '';
  document.getElementById('form_app_category').value = ap.category || '';
  document.getElementById('form_app_web_url').value = ap.web_url || '';
  document.getElementById('form_app_downloads').value = ap.downloads !== undefined ? ap.downloads : 0;
  document.getElementById('form_app_rating').value = ap.rating !== undefined ? ap.rating : 4.9;
  document.getElementById('form_app_version').value = ap.version || '1.0.0';
  document.getElementById('form_app_color').value = ap.color || 'blue';
  document.getElementById('form_app_status').value = ap.status === 'wip' ? 'wip' : 'live';
  document.getElementById('form_app_progress').value = ap.progress !== undefined ? ap.progress : 0;
  document.getElementById('form_app_desc').value = ap.description || '';
  document.getElementById('form_app_features').value = (ap.features || []).join('\n');

  // تعبئة خيارات الأندرويد وإدارة الحصرية
  resetAndroidOptions();
  const urlInput = document.getElementById('form_app_android_url');
  const fileInput = document.getElementById('form_app_apk_file');
  const existingApkInput = document.getElementById('form_app_existing_apk');
  const existingBadge = document.getElementById('existingApkBadge');
  const existingName = document.getElementById('existingApkName');
  const hintUrl = document.getElementById('hintUrlStatus');
  const hintApk = document.getElementById('hintApkStatus');

  if(ap.android_url && ap.android_url.trim() !== ''){
    if(urlInput) urlInput.value = ap.android_url;
    handleAndroidOptionChange('url');
  } else if(ap.apk_file && ap.apk_file.trim() !== ''){
    if(existingApkInput) existingApkInput.value = ap.apk_file;
    if(existingName) existingName.textContent = ap.apk_file;
    if(existingBadge) existingBadge.style.display = 'flex';
    if(urlInput) urlInput.disabled = true;
    if(fileInput) fileInput.disabled = false;
    if(hintUrl) hintUrl.textContent = '❌ معطل: يوجد ملف APK مرفق';
    if(hintApk) hintApk.textContent = '✅ نشط: سيتم تنزيل ملف APK مباشرة';
  }
  
  document.getElementById('appFormHeading').textContent = 'تعديل التطبيق: ' + (ap.title || '');
  document.getElementById('appSubmitBtnText').textContent = 'حفظ التعديلات على التطبيق';
  document.getElementById('appFormBox').scrollIntoView({ behavior: 'smooth' });
}

// تعديل مقال من لوحة المدير
function editArticle(ar){
  switchAdminTab('articles');
  document.getElementById('form_art_id').value = ar.id || '';
  document.getElementById('form_art_title').value = ar.title || '';
  document.getElementById('form_art_badge').value = ar.badge || 'تطوير';
  document.getElementById('form_art_read_time').value = ar.read_time || 4;
  document.getElementById('form_art_author').value = ar.author || 'سارة علي';
  document.getElementById('form_art_date').value = ar.date || new Date().toISOString().slice(0, 10);
  document.getElementById('form_art_desc').value = ar.description || '';
  document.getElementById('form_art_full').value = ar.full || '';
  
  const previewBox = document.getElementById('author_photo_preview_box');
  const previewImg = document.getElementById('author_photo_preview_img');
  if(previewBox && previewImg){
    if(ar.author_photo){
      previewImg.src = 'uploads/' + ar.author_photo;
      previewBox.style.display = 'flex';
    } else {
      previewBox.style.display = 'none';
      previewImg.src = '';
    }
  }

  document.getElementById('artFormHeading').textContent = 'تعديل المقال: ' + (ar.title || '');
  document.getElementById('artSubmitBtnText').textContent = 'حفظ التعديلات على المقال';
  document.getElementById('artFormBox').scrollIntoView({ behavior: 'smooth' });
}

// عرض جميع التطبيقات: ترويسة + فلاتر + مجموعتان (متاحة / قيد التطوير)
let allAppsFilter = 'all';
function allAppsCardHtml(w){
  const L = LANGS[CL];
  const wip = w.status === 'wip';
  const iconContent = upl(w.icon) ? `<img src="${upl(w.icon)}" alt="" loading="lazy">` : `<i class="${esc(w.icon_class || 'fas fa-mobile-screen')}"></i>`;
  const title = w.title;
  const cat = (w.category || '');
  const pct = Math.max(0, Math.min(100, Number(w.progress) || 0));
  return `
    <div class="app-square-card${wip ? ' is-wip' : ''}" onclick="closeOverlay('allAppsOverlay'); showAppDetail(${jsArg(w.id)})">
      <div class="app-square-card-icon" style="background:${COLORS[w.color] || COLORS.blue}">${iconContent}</div>
      <div class="app-square-card-info">
        <h4>${esc(title)}</h4>
        ${cat ? `<div class="app-square-card-cat">${esc(cat)}</div>` : ''}
        ${wip
          ? `<span class="wip-badge">🚧 ${L.wip}</span><div class="wip-progress"><span style="width:${pct}%"></span></div>`
          : `<div class="app-square-card-meta"><span><i class="fas fa-download"></i> ${Number(w.downloads || 0).toLocaleString()}</span><span><i class="fas fa-star" style="color:var(--gold)"></i> ${Number(w.rating !== undefined ? w.rating : 4.9).toFixed(1)}</span></div>`}
      </div>
      <button type="button" class="app-square-card-btn${wip ? ' is-wip' : ''}">
        <i class="fas ${wip ? 'fa-hammer' : 'fa-download'}"></i>
        <span>${wip ? L.soon : L.install}</span>
      </button>
    </div>`;
}
function renderAllApps(){
  if(!DATA || !DATA.works) return '';
  const L = LANGS[CL];
  const live = DATA.works.filter(w => w.status !== 'wip');
  const wip = DATA.works.filter(w => w.status === 'wip');
  const hasWip = wip.length > 0;
  let html = `<div class="apps-all-head">
    <h3>${'جميع التطبيقات والمشاريع'} <small>(${DATA.works.length})</small></h3>
    ${hasWip ? `<div class="apps-filter">
      <button type="button" class="${allAppsFilter === 'all' ? 'active' : ''}" onclick="setAllAppsFilter('all')">${L.f_all}</button>
      <button type="button" class="${allAppsFilter === 'live' ? 'active' : ''}" onclick="setAllAppsFilter('live')">${L.f_live} (${live.length})</button>
      <button type="button" class="${allAppsFilter === 'wip' ? 'active' : ''}" onclick="setAllAppsFilter('wip')">🚧 ${L.f_wip} (${wip.length})</button>
    </div>` : ''}
  </div>`;
  const group = (title, icon, list) => list.length ? `
    <div class="apps-group-title"><i class="${icon}" style="color:var(--blue)"></i> ${title} <span class="cnt">${list.length}</span></div>
    <div class="all-apps-square-grid">${list.map(allAppsCardHtml).join('')}</div>` : '';
  if(allAppsFilter !== 'wip') html += group(L.live_group, 'fas fa-mobile-screen-button', live);
  if(allAppsFilter !== 'live') html += group(L.wip_group, 'fas fa-hammer', wip);
  return html;
}
function setAllAppsFilter(f){
  allAppsFilter = f;
  const box = document.getElementById('allAppsListContent');
  if(box) box.innerHTML = renderAllApps();
}
function showAllAppsModal(){
  if(!DATA || !DATA.works) return;
  allAppsFilter = 'all';
  openOverlay('allAppsOverlay', renderAllApps());
}

// بطاقة تقييم صغيرة منبثقة بأسفل الشاشة تظهر عند الدخول على التطبيق
let currentRatingAppId = null;
let bottomSelectedRating = 5;

function openBottomRatePopup(appId){
  currentRatingAppId = appId;
  // إزالة أي قفل عام قديم من التخزين المحلي لضمان ظهور بطاقة التقييم في التطبيقات
  try {
    localStorage.removeItem('mute_all_ratings');
    if(sessionStorage.getItem('mute_rate_' + appId) === 'true'){
      return;
    }
  } catch(e){}

  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app) return;
  const L = LANGS[CL];
  const title = app.title;

  const titleEl = document.getElementById('bottomRateAppTitle');
  if(titleEl){
    titleEl.textContent = 'تقييم ' + title;
  }
  const submitLbl = document.getElementById('lblSubmitRate');
  if(submitLbl) submitLbl.textContent = L.submit_rate_btn || 'إرسال التقييم';
  const muteLbl = document.getElementById('lblMuteRate');
  if(muteLbl) muteLbl.textContent = L.mute_rate_btn || 'منع العرض';

  setBottomReviewStars(5);

  const el = document.getElementById('bottomRatePopup');
  if(el){
    el.style.display = 'block';
    setTimeout(() => {
      el.classList.add('visible');
    }, 60);
  }
}

function closeBottomRatePopup(){
  const el = document.getElementById('bottomRatePopup');
  if(el){
    el.classList.remove('visible');
    setTimeout(() => {
      el.style.display = 'none';
    }, 350);
  }
}

function muteBottomRatePopup(){
  try {
    if(currentRatingAppId){
      sessionStorage.setItem('mute_rate_' + currentRatingAppId, 'true');
    }
  } catch(e){}
  // إغلاق فوري بدون أي رسالة تأكيد للمنع
  closeBottomRatePopup();
}

function setBottomReviewStars(stars){
  bottomSelectedRating = stars;
  const starsContainer = document.getElementById('bottomStarsBox');
  if(!starsContainer) return;
  const starEls = starsContainer.querySelectorAll('i');
  starEls.forEach((el, idx) => {
    if(idx < stars){
      el.classList.add('active');
      el.classList.remove('far');
      el.classList.add('fas');
    } else {
      el.classList.remove('active');
      el.classList.remove('fas');
      el.classList.add('far');
    }
  });
}

async function submitBottomRate(){
  if(!currentRatingAppId) return;
  const fd = new FormData();
  fd.append('action', 'add_user_review');
  fd.append('app_id', currentRatingAppId);
  fd.append('author_name', 'مستخدم');
  fd.append('stars', bottomSelectedRating);
  fd.append('comment', '');

  try {
    const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials:'same-origin' });
    const data = await res.json();
    if(data && data.success){
      // إغلاق بطاقة التقييم مباشرة بدون أي رسالة إتمام التقييم حسب طلب المستخدم
      closeBottomRatePopup();
      if(!DATA.reviews) DATA.reviews = {};
      if(!DATA.reviews[currentRatingAppId]) DATA.reviews[currentRatingAppId] = [];
      DATA.reviews[currentRatingAppId].unshift(data.review);
    } else {
      closeBottomRatePopup();
    }
  } catch(e){
    closeBottomRatePopup();
  }
}

let successCountdownTimer = null;
let successCountdownInterval = null;

function startSuccessCountdown(){
  clearTimeout(successCountdownTimer);
  clearInterval(successCountdownInterval);

  let secondsLeft = 3;
  const numEl = document.getElementById('demoCountdownNum');
  const barEl = document.getElementById('demoCountdownBar');
  
  if(numEl) numEl.textContent = '3';
  if(barEl){
    barEl.style.animation = 'none';
    void barEl.offsetWidth; // trigger reflow
    barEl.style.animation = 'countdownFillBar 3s linear forwards';
  }

  // إعادة إطلاق أنيميشن سقوط الصح وحركة النصوص يميناً ويساراً
  const checkEl = document.querySelector('.success-check-drop');
  const titleEl = document.getElementById('lblDemoSuccessTitle');
  const descEl = document.getElementById('lblDemoSuccessDesc');
  const cntWrap = document.querySelector('.success-countdown-wrapper');
  [checkEl, titleEl, descEl, cntWrap].forEach(el => {
    if(el){
      el.style.animation = 'none';
      void el.offsetWidth;
      el.style.animation = '';
    }
  });

  successCountdownInterval = setInterval(() => {
    secondsLeft--;
    if(numEl && secondsLeft >= 0){
      numEl.textContent = secondsLeft;
    }
    if(secondsLeft <= 0){
      clearInterval(successCountdownInterval);
    }
  }, 1000);

  successCountdownTimer = setTimeout(() => {
    closeDemoPresentationModal();
  }, 3000);
}

function openDemoPresentationModal(){
  clearTimeout(successCountdownTimer);
  clearInterval(successCountdownInterval);

  const btn = document.getElementById('floatingMainDemoBtn');
  const modal = document.getElementById('demoPresentationOverlay');
  const card = modal ? modal.querySelector('.modal-blurred-card') : null;
  const formState = document.getElementById('demoFormState');
  const successState = document.getElementById('demoSuccessState');

  if(card){
    card.classList.remove('success-frameless');
  }
  if(formState) formState.style.display = 'block';
  if(successState) successState.style.display = 'none';

  // انطلاق زر ابدأ الآن كصاروخ ناري متصاعد مباشرة من موقعه الحالي أينما كان
  if(btn){
    let currentX = 0;
    try {
      const st = window.getComputedStyle(btn);
      const tf = st.transform;
      if(tf && tf !== 'none'){
        if(window.DOMMatrixReadOnly){
          const m = new DOMMatrixReadOnly(tf);
          currentX = m.m41 || 0;
        } else if(window.WebKitCSSMatrix){
          const m = new WebKitCSSMatrix(tf);
          currentX = m.m41 || 0;
        }
      }
    } catch(e){}
    btn.style.setProperty('--current-x', `${currentX}px`);
    btn.classList.add('launching-rocket');
    try {
      if(navigator.vibrate) navigator.vibrate([40, 40, 70]);
    } catch(e){}
  }

  // بناء وظهور الصفحة الخاصة بتقديم الطلب قالب قالب في 1.5 ثانية
  setTimeout(() => {
    if(modal){
      modal.classList.add('open');
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      updateFloatingButtonsState();
      if(card){
        card.classList.remove('building-active');
        void card.offsetWidth; // Force reflow
        card.classList.add('building-active');
      }
      try {
        if(navigator.vibrate) navigator.vibrate([15, 260, 15, 260, 15, 260, 25]);
      } catch(e){}
    }
  }, 260);
}

function closeDemoPresentationModal(){
  clearTimeout(successCountdownTimer);
  clearInterval(successCountdownInterval);

  const modal = document.getElementById('demoPresentationOverlay');
  const card = modal ? modal.querySelector('.modal-blurred-card') : null;
  const btn = document.getElementById('floatingMainDemoBtn');

  if(modal){
    modal.classList.remove('open');
    modal.style.display = 'none';
  }
  if(card){
    card.classList.remove('building-active');
    card.classList.remove('success-frameless');
  }

  updateFloatingButtonsState();

  const anyOtherOpen = document.querySelectorAll('.page-overlay.open').length > 0;
  if(!anyOtherOpen){
    document.body.style.overflow = '';
  }

  // إعادة زر الصاروخ إلى مكانه الحالي بسلاسة دون القفز للمكان الأساسي
  if(btn){
    btn.classList.remove('launching-rocket');
    btn.style.opacity = '0';
    const currentX = btn.style.getPropertyValue('--current-x') || '0px';
    btn.style.transform = `translateX(${currentX}) translateY(24px)`;
    setTimeout(() => {
      btn.style.transition = 'transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease';
      btn.style.opacity = '1';
      btn.style.transform = `translateX(${currentX})`;
      setTimeout(() => {
        btn.style.transition = '';
        updateDemoButtonScrollPosition();
      }, 450);
    }, 60);
  }
}

function handleDemoBackdropClick(e){
  if(e.target.id === 'demoPresentationOverlay'){
    closeDemoPresentationModal();
  }
}

// منع أي سحب للأعلى أو للأسفل في بطاقة تقديم الطلب
(function preventDemoModalDrag(){
  const demoModal = document.getElementById('demoPresentationOverlay');
  if(demoModal){
    demoModal.addEventListener('touchmove', (e) => {
      if(!e.target.closest('textarea')){
        e.preventDefault();
      }
    }, { passive: false });
  }
})();

function showToastNotification(msg, isSuccess = true){
  let toast = document.getElementById('siteGlobalToast');
  if(!toast){
    toast = document.createElement('div');
    toast.id = 'siteGlobalToast';
    toast.className = 'site-global-toast';
    document.body.appendChild(toast);
  }
  toast.innerHTML = `<i class="${isSuccess ? 'fas fa-circle-check' : 'fas fa-info-circle'}"></i> <span>${esc(msg)}</span>`;
  toast.classList.add('visible');
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => {
    toast.classList.remove('visible');
  }, 3200);
}

async function shareApp(appId){
  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app) return;
  const title = app.title;
  const L = LANGS[CL];
  const seq = getAppSeq(app.id);

  const origin = window.location.origin;
  const path = window.location.pathname.replace(/\/index\.php$/i, '/');
  // رابط التطبيق هو فقط app + رقم تسلسل التطبيق (مثال: ?app1)
  const shareUrl = `${origin}${path}?app${seq}`;

  const shareData = {
    title: `${title} - FastCrand`,
    text: `تحميل تطبيق ${title}:`,
    url: shareUrl
  };

  if(navigator.share && navigator.canShare && navigator.canShare(shareData)){
    try {
      await navigator.share(shareData);
      return;
    } catch(err) {
      if(err.name !== 'AbortError'){
        console.error(err);
      } else {
        return;
      }
    }
  }

  try {
    if(navigator.clipboard && navigator.clipboard.writeText){
      await navigator.clipboard.writeText(shareUrl);
    } else {
      const tempInput = document.createElement('input');
      tempInput.value = shareUrl;
      document.body.appendChild(tempInput);
      tempInput.select();
      document.execCommand('copy');
      document.body.removeChild(tempInput);
    }
    showToastNotification(L.share_copied || 'تم نسخ رابط التطبيق بنجاح! جاهز للمشاركة.');
  } catch(e){
    prompt('انسخ رابط التطبيق:', shareUrl);
  }
}

async function submitDemoPresentation(event){
  if(event) event.preventDefault();
  const nameInput = document.getElementById('demo_name');
  const whatsappInput = document.getElementById('demo_whatsapp');
  const projectInput = document.getElementById('demo_project_details');
  const submitBtn = document.getElementById('btnSubmitDemo');
  const L = LANGS[CL];

  const name = nameInput ? nameInput.value.trim() : '';
  const whatsapp = whatsappInput ? whatsappInput.value.trim() : '';
  const project = projectInput ? projectInput.value.trim() : '';

  if(!name || !whatsapp || !project){
    alert('يرجى ملء جميع الحقول المطلوبة.');
    return;
  }

  if(submitBtn){
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + 'جارٍ الإرسال...';
  }

  let cleanWhatsapp = whatsapp.replace(/^\+/, '').replace(/^00/, '').trim();

  const fd = new FormData();
  fd.append('action', 'request_demo_presentation');
  fd.append('name', name);
  fd.append('whatsapp', cleanWhatsapp);
  fd.append('project_details', project);
  fd.append('website', document.getElementById('demo_website')?.value || '');

  try {
    const res = await fetch(ENDPOINT + '?action=request_demo_presentation', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch(parseErr) {
      console.error('Non-JSON response from server:', text);
      throw new Error('Invalid server response');
    }

    if(data && data.success){
      const form = document.getElementById('demoPresentationForm');
      if(form) form.reset();
      
      // إظهار رسالة النجاح بدون بطاقة وبدون زر مع تساقط الصح وحركة النصوص والعداد التنازلي
      const card = document.querySelector('#demoPresentationOverlay .modal-blurred-card');
      const formState = document.getElementById('demoFormState');
      const successState = document.getElementById('demoSuccessState');
      if(card) card.classList.add('success-frameless');
      if(formState) formState.style.display = 'none';
      if(successState){
        successState.style.display = 'block';
        startSuccessCountdown();
      }
    } else {
      alert((data && data.message) ? data.message : 'تعذر إرسال الطلب، يرجى المحاولة لاحقاً.');
    }
  } catch(err){
    console.error(err);
    alert('حدث خطأ في الاتصال، يرجى المحاولة مرة أخرى.');
  } finally {
    if(submitBtn){
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> <span id="lblSubmitDemoText">' + (L.send_demo_req || 'إرسال الطلب الآن') + '</span>';
    }
  }
}

// تحميل للأندرويد: فتح سوق بلاي أو تنزيل APK مباشر
function triggerAndroidDownload(appId){
  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app || app.status === 'wip') return;

  // تحديث عداد التحميلات محلياً
  app.downloads = (parseInt(app.downloads) || 0) + 1;
  const statBoxNum = document.querySelector('#appDetailContent .app-stat-box .num');
  if(statBoxNum){
    statBoxNum.innerHTML = `<i class="fas fa-download"></i> ` + Number(app.downloads).toLocaleString();
  }

  // إذا كان التطبيق محدد له رابط سوق بلاي / خارجي
  if(safeHref(app.android_url)){
    // إرسال طلب في الخلفية لتحديث العداد على السيرفر
    fetch(ENDPOINT + '?increment_download=' + encodeURIComponent(appId), { credentials:'same-origin' }).catch(()=>{});
    // فتح رابط سوق بلاي في تبويب جديد
    openExternal(app.android_url);
  } else if(!app.has_apk){
    // لا يوجد ملف APK ولا رابط متجر: فتح نسخة الويب إن وجدت بدلاً من تنزيل ملف تالف
    app.downloads = Math.max(0, (parseInt(app.downloads) || 1) - 1);
    if(statBoxNum) statBoxNum.innerHTML = `<i class="fas fa-download"></i> ` + Number(app.downloads).toLocaleString();
    if(!openExternal(app.web_url)){
      showToastNotification('نسخة الأندرويد ستتوفر قريباً.', false);
    }
  } else {
    // بدء التنزيل المباشر لحزمة APK وتحديث العداد بالسيرفر
    const dlLink = document.createElement('a');
    dlLink.href = ENDPOINT + '?download_app=' + encodeURIComponent(appId);
    dlLink.setAttribute('download', '');
    document.body.appendChild(dlLink);
    dlLink.click();
    document.body.removeChild(dlLink);
  }
}

// عرض خطوات تثبيت الموقع كتطبيق على الآيفون (iOS عبر Safari)
function openIosPwaSteps(appId){
  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app) return;
  const L = LANGS[CL];
  const webUrl = safeHref(app.web_url) || '#';
  const title = app.title;

  const html = `
    <!-- ترويسة تعليمات الآيفون المتساقطة -->
    <div class="pwa-step-drop pwa-drop-header" style="text-align:center;margin-bottom:18px;padding:16px 14px;background:linear-gradient(135deg, rgba(255,122,26,0.08), transparent);border-radius:20px;border:1.5px solid var(--border);box-shadow:var(--shadow);">
      <div style="width:52px;height:52px;border-radius:16px;background:var(--gradient-primary);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:1.6rem;margin-bottom:8px;box-shadow:0 6px 18px rgba(255,122,26,0.35);">
        <i class="fab fa-apple"></i>
      </div>
      <h3 style="font-size:1.15rem;font-weight:900;margin-bottom:4px;color:var(--text);">${L.pwa_title}</h3>
      <p style="font-size:0.8rem;color:var(--muted);font-weight:700;">${esc(title)}</p>
    </div>

    <!-- خطوة 1 تتساقط أولاً -->
    <div class="pwa-step-card pwa-step-drop pwa-drop-1">
      <div class="pwa-step-num">1</div>
      <div class="pwa-step-body">
        <h4>${L.pwa_step1_title}</h4>
        <p>${L.pwa_step1_desc}</p>
      </div>
    </div>

    <!-- خطوة 2 تتساقط ثانياً -->
    <div class="pwa-step-card pwa-step-drop pwa-drop-2">
      <div class="pwa-step-num">2</div>
      <div class="pwa-step-body">
        <h4>${L.pwa_step2_title}</h4>
        <p>${L.pwa_step2_desc}</p>
      </div>
    </div>

    <!-- خطوة 3 تتساقط ثالثاً -->
    <div class="pwa-step-card pwa-step-drop pwa-drop-3">
      <div class="pwa-step-num">3</div>
      <div class="pwa-step-body">
        <h4>${L.pwa_step3_title}</h4>
        <p>${L.pwa_step3_desc}</p>
      </div>
    </div>

    <!-- زر المتابعة يتساقط في النهاية -->
    <div class="pwa-step-drop pwa-drop-4" style="margin-top:20px;display:flex;flex-direction:column;gap:10px;">
      <a href="${webUrl !== '#' ? esc(webUrl) : 'javascript:void(0)'}" ${webUrl !== '#' ? 'target="_blank" rel="noopener noreferrer"' : ''} class="ct-btn" style="width:100%;padding:14px;font-size:0.92rem;border-radius:16px;box-shadow:0 8px 25px rgba(255,122,26,0.35);">
        <i class="fas fa-arrow-up-right-from-square"></i> ${L.pwa_continue_btn}
      </a>
    </div>
  `;

  openOverlay('pwaStepsOverlay', html);
}

// زر رجوع تفاعلي مع تأثير انضغاط واهتزاز سلس
function interactiveGoBack(){
  const btn = document.getElementById('floatingAppBackBtn');
  const doClose = () => {
    if(document.getElementById('demoPresentationOverlay')?.classList.contains('open')){
      closeDemoPresentationModal();
    } else if(document.getElementById('pwaStepsOverlay')?.classList.contains('open')){
      closeOverlay('pwaStepsOverlay');
    } else if(document.getElementById('articleOverlay')?.classList.contains('open')){
      closeOverlay('articleOverlay');
    } else if(document.getElementById('allAppsOverlay')?.classList.contains('open')){
      closeOverlay('allAppsOverlay');
    } else if(document.getElementById('appOverlay')?.classList.contains('open')){
      closeOverlay('appOverlay');
    } else {
      const openOverlays = document.querySelectorAll('.page-overlay.open');
      openOverlays.forEach(o => closeOverlay(o.id));
    }
  };

  if(btn){
    btn.classList.add('is-clicked');
    try {
      if(navigator.vibrate) navigator.vibrate([20, 30]);
    } catch(e){}
    setTimeout(() => {
      btn.classList.remove('is-clicked');
      doClose();
    }, 140);
  } else {
    doClose();
  }
}

// نافذة تفاصيل التطبيق والتثبيت الداخلي (التقييم يظهر فقط في بطاقة الإحصائيات في البداية)
function showAppDetail(appId){
  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app) return;
  const L = LANGS[CL];
  const iconHtml = upl(app.icon) ? `<img src="${upl(app.icon)}" alt="">` : `<i class="${esc(app.icon_class || 'fas fa-mobile-screen')}"></i>`;
  const title = app.title;
  const desc = app.description;
  const cat = (app.category || 'App');
  const isWip = app.status === 'wip';
  const wipPct = Math.max(0, Math.min(100, Number(app.progress) || 0));
  const ratingValue = (app.rating !== undefined) ? Number(app.rating).toFixed(1) : '4.9';

  let screenshotsHtml = '';
  if(app.screenshots && app.screenshots.length > 0){
    screenshotsHtml = `<div style="margin:16px 0;"><h4 style="font-size:0.85rem;margin-bottom:8px;font-weight:800;"><i class="fas fa-images" style="color:var(--blue)"></i> ${L.app_screens}</h4><div class="app-screenshots-scroll">` +
    app.screenshots.filter(s => upl(s)).map(s => `<img src="${upl(s)}" class="app-screenshot-thumb" alt="screenshot" loading="lazy">`).join('') +
    `</div></div>`;
  }

  const rawFeatures = app.features || [];
  let featsHtml = '';
  if(rawFeatures && rawFeatures.length > 0){
    featsHtml = `<div style="margin:16px 0;"><h4 style="font-size:0.85rem;margin-bottom:8px;font-weight:800;"><i class="fas fa-check-double" style="color:var(--blue)"></i> ${L.app_features}</h4>` +
    rawFeatures.map(f => `<div class="app-feat-item"><i class="fas fa-circle-check" style="color:var(--blue)"></i> <span>${esc(f)}</span></div>`).join('') +
    `</div>`;
  }

  const content = `
    <div class="app-detail-head">
      <div class="app-detail-icon" style="background:${COLORS[app.color] || COLORS.blue}">${iconHtml}</div>
      <div class="app-detail-meta">
        <h2>${esc(title)}</h2>
        <div style="font-size:0.75rem;color:var(--muted);font-weight:700;">
          <span style="color:var(--blue);">${esc(cat)}</span> · v${esc(app.version || '1.0.0')}
        </div>
      </div>
    </div>

    <!-- بطاقة التقييمات والإحصائيات فقط هنا في البداية بعد الاسم واللوجو -->
    <div class="app-stat-bar">
      <div class="app-stat-box">
        <div class="num"><i class="fas fa-download"></i> ${Number(app.downloads || 0).toLocaleString()}</div>
        <div class="lbl">${L.downloads_total}</div>
      </div>
      <div class="app-stat-box">
        <div class="num"><i class="fas fa-star" style="color:var(--gold)"></i> ${ratingValue}</div>
        <div class="lbl">${L.user_rating}</div>
      </div>
      <div class="app-stat-box">
        <div class="num"><i class="fas fa-shield-halved" style="color:#22c55e"></i> ${L.safe}</div>
        <div class="lbl">${L.security_check}</div>
      </div>
    </div>

    <!-- زرا التحميل: أندرويد (سوق بلاي أو APK)، وآيفون يعرض خطوات التثبيت عبر Safari -->
    ${isWip ? `
    <div class="wip-box">
      <div class="ico">🚧</div>
      <h4>${L.wip_title}</h4>
      <p>${L.wip_desc}</p>
      <div class="wip-progress"><span style="width:${wipPct}%"></span></div>
      <div class="pct">${L.progress_lbl}: ${wipPct}%</div>
    </div>` : `
    <div class="download-action-grid">
      <button type="button" class="btn-dl-android" onclick="triggerAndroidDownload(${jsArg(app.id)})">
        <i class="${safeHref(app.android_url) ? 'fab fa-google-play' : 'fab fa-android'}" style="font-size:0.95rem;"></i>
        <span>${safeHref(app.android_url) ? L.dl_play_store : L.dl_android_btn}</span>
      </button>

      <button type="button" class="btn-dl-ios" onclick="openIosPwaSteps(${jsArg(app.id)})" style="border:none;cursor:pointer;">
        <i class="fab fa-apple" style="font-size:0.95rem;"></i>
        <span>${L.dl_ios_btn}</span>
      </button>
    </div>`}

    <!-- زر مشاركة رابط التطبيق المباشر -->
    <button type="button" class="btn-share-app" onclick="shareApp(${jsArg(app.id)})">
      <i class="fas fa-share-nodes"></i>
      <span>${L.share_app_btn || 'مشاركة رابط التطبيق'}</span>
    </button>

    <div class="abt" style="margin-bottom:14px;">
      <h4 style="font-size:0.9rem;font-weight:800;margin-bottom:6px;color:var(--text);"><i class="fas fa-circle-info"></i> ${L.about_app}</h4>
      <p style="white-space:pre-line;">${esc(desc)}</p>
    </div>

    ${screenshotsHtml}
    ${featsHtml}
  `;

  openOverlay('appOverlay', content);
  const seq = getAppSeq(app.id);
  if(window.history && window.history.replaceState){
    history.replaceState({ appId: app.id, seq }, '', `?app${seq}`);
  }
  if(!isWip){
    setTimeout(() => {
      openBottomRatePopup(app.id);
    }, 250);
  }
}

// عرض المقال مع اسم المستخدم (سارة علي مثلاً) وصورته والتاريخ الحقيقي
function showArticleDetail(artId){
  const a = (DATA && DATA.articles) ? DATA.articles.find(item => item.id === artId) : null;
  if(!a) return;
  const L = LANGS[CL];
  const aTitle = a.title;
  const aDesc = a.description;
  const aBadge = a.badge;
  const aFull = (a.full || '');
  const aAuthor = (a.author || 'سارة علي');
  const aPhoto = upl(a.author_photo) ? `<img src="${upl(a.author_photo)}" alt="${esc(aAuthor)}">` : `<i class="fas fa-user"></i>`;
  const aRealDate = a.date || new Date().toISOString().slice(0, 10);

  const content = `
    <h2 style="font-size:1.3rem;font-weight:900;margin-bottom:8px;">${esc(aTitle)}</h2>
    <div style="font-size:0.72rem;color:var(--muted);margin-bottom:16px;display:flex;gap:12px;font-weight:700;">
      <span><i class="far fa-calendar"></i> ${esc(aRealDate)}</span>
      <span><i class="far fa-clock"></i> ${Number(a.read_time) || 1} ${L.min}</span>
      <span style="color:var(--blue);">${esc(aBadge)}</span>
    </div>

    <div class="abt" style="margin-bottom:12px;">
      <h4 style="font-size:0.88rem;font-weight:800;margin-bottom:6px;color:var(--text);">${L.intro}</h4>
      <p>${esc(aDesc)}</p>
    </div>

    <div class="abt" style="margin-bottom:12px;">
      <h4 style="font-size:0.88rem;font-weight:800;margin-bottom:6px;color:var(--text);">${L.article_content}</h4>
      <p style="line-height:1.8;white-space:pre-line;">${esc(aFull || aDesc)}</p>
    </div>

    <!-- بطاقة الكاتب/المستخدم مع صورته وتاريخ النشر الحقيقي -->
    <div class="author-box">
      <div class="author-avatar">${aPhoto}</div>
      <div style="font-size:0.82rem;">
        <strong style="color:var(--text);font-size:0.9rem;">${esc(aAuthor)}</strong>
        <div style="color:var(--muted);font-size:0.7rem;margin-top:2px;">
          <span><i class="far fa-calendar-check" style="color:var(--blue);"></i> ${'تاريخ النشر:'} ${esc(aRealDate)}</span>
        </div>
      </div>
    </div>
  `;
  openOverlay('articleOverlay', content);
}

function showInstagramStatus(){
  if(!DATA) return;
  const L = LANGS[CL];
  const meta = DATA.instagram_meta || {};
  const isAct = meta.status === 'active';
  const msg = (meta.message || '');

  const html = `
    <div class="instagram-card">
      <div class="ig-logo"><i class="fab fa-instagram"></i></div>
      <h2 style="font-size:1.4rem;font-weight:900;margin-bottom:8px;">@${esc(meta.user || 'fastcrand_official')}</h2>
      ${isAct ? `
        <div style="display:inline-block;padding:6px 20px;border-radius:100px;background:#22c55e;color:#fff;font-weight:800;margin:12px 0;"><i class="fas fa-circle-check"></i> ${L.ig_active}</div>
        <p style="color:var(--muted);font-size:0.9rem;line-height:1.7;">${'يمكنك متابعة أحدث أخبارنا ومشاريعنا فوراً.'}</p>
      ` : `
        <div class="ig-status"><i class="fas fa-circle-exclamation"></i> ${L.ig_disabled}</div>
        <p style="color:var(--muted);font-size:0.9rem;line-height:1.8;white-space:pre-line;">${esc(msg)}</p>
      `}
      <p style="font-size:0.75rem;color:var(--muted);margin-top:16px;">${L.ig_last_updated} ${new Date().toISOString().slice(0, 10)}</p>
    </div>
  `;

  document.getElementById('instagramModalBody').innerHTML = html;
  openOverlay('instagramOverlay');
}

// منع نسخ وتحديد نصوص الموقع مع استثناء حقول الإدخال لتظل قابلة للكتابة والتعديل
document.addEventListener('copy', e => {
  if (!e.target.closest('input, textarea')) {
    e.preventDefault();
  }
});
document.addEventListener('cut', e => {
  if (!e.target.closest('input, textarea')) {
    e.preventDefault();
  }
});
document.addEventListener('contextmenu', e => {
  if (!e.target.closest('input, textarea')) {
    e.preventDefault();
  }
});
document.addEventListener('selectstart', e => {
  if (!e.target.closest('input, textarea')) {
    e.preventDefault();
  }
});

loadData();
</script>
</body>
</html>
