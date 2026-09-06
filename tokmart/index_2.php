<?php
// ============================================================
// نظام إدارة المتجر - Tokmart (النسخة النهائية)
// ============================================================
ini_set('session.cookie_lifetime', 60 * 60 * 24 * 365);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 365);
ini_set('session.gc_probability', 0);
ini_set('session.gc_divisor', 100);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


// ===== إعدادات النظام =====
define('DEBUG_MODE', false);
define('DATA_DIR', __DIR__ . '/data');
define('DB_FILE', DATA_DIR . '/Tokmart.db');
define('SITE_URL', 'https://' . $_SERVER['HTTP_HOST']);
define('UPLOAD_DIR', DATA_DIR . '/uploads');
define('CACHE_VERSION', '1.0.0');



// ===== إعدادات SMTP - يتم تحميلها من قاعدة البيانات =====
function getSMTPConfig() {
    global $db;
    $smtpSettings = queryOne("SELECT value FROM settings WHERE key = 'smtp'");
    if ($smtpSettings) {
        return json_decode($smtpSettings['value'], true);
    }
    return [
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'username' => 'support@fastcrand.com',
        'password' => 'REDACTED_SMTP_PASSWORD',
        'encryption' => 'ssl',
        'from_email' => 'support@fastcrand.com',
        'from_name' => 'Tokmart',
        'enabled' => true
    ];
}

// ===== إنشاء المجلدات =====
$folders = [
    DATA_DIR,
    UPLOAD_DIR,
    UPLOAD_DIR . '/products',
    UPLOAD_DIR . '/categories',
    UPLOAD_DIR . '/avatars',
    UPLOAD_DIR . '/site',
    UPLOAD_DIR . '/chats',
    UPLOAD_DIR . '/transfers',
    UPLOAD_DIR . '/receipts'
];
foreach ($folders as $folder) {
    if (!file_exists($folder)) {
        if (!mkdir($folder, 0777, true)) {
            error_log("❌ فشل إنشاء المجلد: " . $folder);
        } else {
            error_log("✅ تم إنشاء المجلد: " . $folder);
        }
    }
}

// ===== التأكد من صلاحيات المجلدات =====
if (!is_writable(UPLOAD_DIR . '/products')) {
    error_log("⚠️ مجلد المنتجات غير قابل للكتابة: " . UPLOAD_DIR . '/products');
}

// ===== الاتصال بقاعدة البيانات =====
try {
    $db = new SQLite3(DB_FILE);
    $db->enableExceptions(true);
    $db->busyTimeout(10000);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA cache_size = 50000');
    $db->exec('PRAGMA temp_store = MEMORY');
    $db->exec('PRAGMA mmap_size = 30000000');
    $db->exec("PRAGMA timezone = 'UTC'");
} catch (Exception $e) {
    die('❌ فشل الاتصال بقاعدة البيانات');
}

// ===== Google OAuth - يتم تحميلها من قاعدة البيانات =====
function getGoogleOAuthConfig() {
    global $db;
    $settings = queryOne("SELECT value FROM settings WHERE key = 'google_oauth'");
    if ($settings) {
        $data = json_decode($settings['value'], true);
        if ($data && isset($data['client_id']) && isset($data['client_secret'])) {
            return $data;
        }
    }
    return [
        'client_id' => 'REDACTED_GOOGLE_CLIENT_ID',
        'client_secret' => 'REDACTED_GOOGLE_CLIENT_SECRET',
        'enabled' => true
    ];
}

// تعريف ثوابت Google OAuth (للتوافق مع الكود القديم)
$googleConfig = getGoogleOAuthConfig();
define('GOOGLE_CLIENT_ID', $googleConfig['client_id']);
define('GOOGLE_CLIENT_SECRET', $googleConfig['client_secret']);
define('GOOGLE_ENABLED', $googleConfig['enabled']);
// ===== إنشاء الجداول =====
// ===== إضافة عمود refunded إلى جدول orders =====
try {
    $result = $db->query("PRAGMA table_info(orders)");
    $columns = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row['name'];
    }
    
    if (!in_array('refunded', $columns)) {
        $db->exec("ALTER TABLE orders ADD COLUMN refunded INTEGER DEFAULT 0");
        error_log("✅ تم إضافة عمود refunded إلى جدول orders");
    }
} catch (Exception $e) {
    error_log("⚠️ خطأ في إضافة عمود refunded: " . $e->getMessage());
}
$db->exec("
CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, nameEn TEXT NOT NULL,
    desc TEXT, descEn TEXT, category TEXT, brand TEXT,
    price REAL DEFAULT 0, oldPrice REAL,
    image_path TEXT,
    images TEXT DEFAULT '[]',
    deliveryTime TEXT DEFAULT 'خلال 24 ساعة',
    rating REAL DEFAULT 4.5, ratingCount INTEGER DEFAULT 0,
    isNew INTEGER DEFAULT 0, isBestSeller INTEGER DEFAULT 0,
    isFlashDeal INTEGER DEFAULT 0, isFeatured INTEGER DEFAULT 0,
    orderCount INTEGER DEFAULT 0,
    icon TEXT DEFAULT 'fa-solid fa-box',
    discount TEXT,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ===== إضافة الأعمدة المفقودة إلى جدول products =====
try {
    // التحقق من وجود عمود isNew
    $result = $db->query("PRAGMA table_info(products)");
    $columns = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row['name'];
    }
    
    if (!in_array('isNew', $columns)) {
        $db->exec("ALTER TABLE products ADD COLUMN isNew INTEGER DEFAULT 0");
        error_log("✅ تم إضافة عمود isNew");
    }
    if (!in_array('isBestSeller', $columns)) {
        $db->exec("ALTER TABLE products ADD COLUMN isBestSeller INTEGER DEFAULT 0");
        error_log("✅ تم إضافة عمود isBestSeller");
    }
    if (!in_array('isFlashDeal', $columns)) {
        $db->exec("ALTER TABLE products ADD COLUMN isFlashDeal INTEGER DEFAULT 0");
        error_log("✅ تم إضافة عمود isFlashDeal");
    }
    if (!in_array('isFeatured', $columns)) {
        $db->exec("ALTER TABLE products ADD COLUMN isFeatured INTEGER DEFAULT 0");
        error_log("✅ تم إضافة عمود isFeatured");
    }
    if (!in_array('orderCount', $columns)) {
        $db->exec("ALTER TABLE products ADD COLUMN orderCount INTEGER DEFAULT 0");
        error_log("✅ تم إضافة عمود orderCount");
    }
    if (!in_array('icon', $columns)) {
        $db->exec("ALTER TABLE products ADD COLUMN icon TEXT DEFAULT 'fa-solid fa-box'");
        error_log("✅ تم إضافة عمود icon");
    }
    if (!in_array('discount', $columns)) {
        $db->exec("ALTER TABLE products ADD COLUMN discount TEXT");
        error_log("✅ تم إضافة عمود discount");
    }
} catch (Exception $e) {
    error_log("⚠️ خطأ في إضافة الأعمدة: " . $e->getMessage());
}

$db->exec("
CREATE TABLE IF NOT EXISTS categories (
    id TEXT PRIMARY KEY, 
    name TEXT NOT NULL, 
    nameEn TEXT NOT NULL,
    icon TEXT DEFAULT 'fa-solid fa-tag', 
    icon_image_path TEXT,
    banner_image_path TEXT,
    sortOrder INTEGER DEFAULT 0
)");

$db->exec("
CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    orderId TEXT UNIQUE,
    userId INTEGER, 
    address TEXT, 
    phone TEXT, 
    payment TEXT,
    items TEXT, 
    total REAL, 
    status TEXT DEFAULT 'pending',
    proofImage TEXT, 
    transferImage TEXT,
    transferAmount REAL DEFAULT 0,
    accountNumber TEXT,
    beneficiary TEXT,
    date TEXT, 
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL, 
    email TEXT UNIQUE NOT NULL,
    password TEXT, 
    phone TEXT UNIQUE,
    balance REAL DEFAULT 0, 
    isAdmin INTEGER DEFAULT 0,
    adminType TEXT DEFAULT 'user', 
    avatar_path TEXT,
    registeredAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    lastActivity DATETIME,
    google_id TEXT UNIQUE,
    isVerified INTEGER DEFAULT 0,
    verification_code TEXT,
    verification_expires DATETIME
)");

$db->exec("
CREATE TABLE IF NOT EXISTS payments (
    id TEXT PRIMARY KEY, 
    name TEXT NOT NULL, 
    nameEn TEXT NOT NULL,
    icon TEXT DEFAULT 'fa-solid fa-credit-card',
    image_path TEXT, 
    enabled INTEGER DEFAULT 1,
    isRechargeOnly INTEGER DEFAULT 0,
    accountNumber TEXT, 
    beneficiary TEXT
)");

$db->exec("
CREATE TABLE IF NOT EXISTS notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    userId INTEGER NOT NULL,
    title TEXT, 
    message TEXT, 
    type TEXT DEFAULT 'info',
    isRead INTEGER DEFAULT 0, 
    isPushSent INTEGER DEFAULT 0,
    link TEXT, 
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY, value TEXT
)");

$db->exec("
CREATE TABLE IF NOT EXISTS chats (
    id INTEGER PRIMARY KEY AUTOINCREMENT, 
    userId INTEGER NOT NULL,
    adminId INTEGER NOT NULL DEFAULT 1, 
    message TEXT,
    messageType TEXT DEFAULT 'text', 
    fileData TEXT,
    sender TEXT CHECK(sender IN ('user', 'admin')), 
    isRead INTEGER DEFAULT 0,
    readAt DATETIME,
    isArchived INTEGER DEFAULT 0,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("
CREATE TABLE IF NOT EXISTS balance_recharges (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    userId INTEGER NOT NULL,
    amount REAL NOT NULL,
    paymentMethod TEXT NOT NULL,
    receiptImage TEXT,
    status TEXT DEFAULT 'pending',
    notes TEXT,
    createdAt DATETIME DEFAULT CURRENT_TIMESTAMP,
    updatedAt DATETIME,
    FOREIGN KEY (userId) REFERENCES users(id)
)");

$db->exec("
CREATE TABLE IF NOT EXISTS password_resets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    code TEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    used INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// ===== البيانات الافتراضية =====
$result = $db->query("SELECT COUNT(*) as count FROM users");
$row = $result->fetchArray(SQLITE3_ASSOC);
if ($row['count'] == 0) {
    $password = password_hash('admin123', PASSWORD_DEFAULT);
    $db->exec("INSERT INTO users (name, email, password, phone, balance, isAdmin, adminType, lastActivity, isVerified) 
               VALUES ('مدير الموقع', 'admin@Tokmart.com', '$password', '07700000000', 1000, 1, 'super_admin', datetime('now'), 1)");
    
    
    $siteData = [
        'name' => 'Tokmart',
        'description' => 'متجر إلكتروني متكامل يوفر أفضل المنتجات بأفضل الأسعار',
        'slogan' => '🛍️ متجرك الإلكتروني المفضل',
        'logo' => '',
        'privacy_policy' => 'سياسة الخصوصية الخاصة بمتجر Tokmart...',
        'terms_conditions' => 'الشروط والأحكام الخاصة بمتجر Tokmart...',
        'app_version' => '1.0.0'
    ];
    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('site', :value)", [
        ':value' => json_encode($siteData, JSON_UNESCAPED_UNICODE)
    ]);
    
    $db->exec("INSERT OR REPLACE INTO payments (id, name, nameEn, icon, enabled, isRechargeOnly, accountNumber, beneficiary) VALUES 
        ('cash', 'الدفع عند الاستلام', 'Cash on Delivery', 'fa-solid fa-hand-holding-dollar', 1, 0, '', ''),
        ('electronic', 'الدفع الإلكتروني', 'Electronic Payment', 'fa-solid fa-credit-card', 1, 0, '123456789', 'الحوالة تتم عبر سوبر كي حصرا بعد قم بتحويل وارفاق صوره للتحويل  وانتظر موافقة والاضافة خلال دقائق'),
        ('bank_transfer', 'تحويل بنكي', 'Bank Transfer', 'fa-solid fa-university', 1, 1, '7114152353', 'الحوالة تتم عبر سوبر كي حصرا بعد قم بتحويل وارفاق صوره للتحويل  وانتظر موافقة والاضافة خلال دقائق')");
    
    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('google_oauth', :value)", [
        ':value' => json_encode([
            'client_id' => 'REDACTED_GOOGLE_CLIENT_ID',
            'client_secret' => 'REDACTED_GOOGLE_CLIENT_SECRET',
            'enabled' => true
        ], JSON_UNESCAPED_UNICODE)
    ]);
    
    $db->exec("INSERT OR REPLACE INTO settings (key, value) VALUES ('smtp', :value)", [
        ':value' => json_encode([
            'host' => 'smtp.hostinger.com',
            'port' => 465,
            'username' => 'support@fastcrand.com',
            'password' => 'REDACTED_SMTP_PASSWORD',
            'encryption' => 'ssl',
            'from_email' => 'support@fastcrand.com',
            'from_name' => 'Tokmart',
            'enabled' => true
        ], JSON_UNESCAPED_UNICODE)
    ]);
}

$result = $db->query("SELECT COUNT(*) as count FROM categories");
$row = $result->fetchArray(SQLITE3_ASSOC);
if ($row['count'] == 0) {
    $db->exec("INSERT INTO categories (id, name, nameEn, icon, icon_image_path, banner_image_path) VALUES 
        ('general', 'عام', 'General', 'fa-solid fa-tag', '', ''),
        ('electronics', 'إلكترونيات', 'Electronics', 'fa-solid fa-microchip', '', ''),
        ('fashion', 'أزياء', 'Fashion', 'fa-solid fa-vest', '', '')");
}

// ============================================================
// دوال مساعدة
// ============================================================

function query($sql, $params = []) {
    global $db;
    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        $data = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $data[] = $row;
        }
        return $data;
    } catch (Exception $e) {
        if (DEBUG_MODE) error_log("SQL Error: " . $e->getMessage());
        return [];
    }
}
// ===== إضافة عمود refunded إلى جدول orders =====
try {
    $result = $db->query("PRAGMA table_info(orders)");
    $columns = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $columns[] = $row['name'];
    }
    
    if (!in_array('refunded', $columns)) {
        $db->exec("ALTER TABLE orders ADD COLUMN refunded INTEGER DEFAULT 0");
        error_log("✅ تم إضافة عمود refunded إلى جدول orders");
    }
} catch (Exception $e) {
    error_log("⚠️ خطأ في إضافة عمود refunded: " . $e->getMessage());
}
function queryOne($sql, $params = []) {
    $data = query($sql, $params);
    return $data ? $data[0] : null;
}

function execute($sql, $params = []) {
    global $db;
    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        return $stmt->execute();
    } catch (Exception $e) {
        if (DEBUG_MODE) error_log("SQL Error: " . $e->getMessage());
        return false;
    }
}

function getLastInsertId() {
    global $db;
    return $db->lastInsertRowID();
}

function response($success, $message, $data = null) {
    header('Content-Type: application/json');
    echo json_encode(['success' => $success, 'message' => $message, 'data' => $data]);
    exit;
}

function getVal($key, $default = '') {
    global $input;
    $value = isset($input[$key]) ? $input[$key] : $default;
    if (is_string($value)) {
        $value = strip_tags($value);
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
    return $value;
}

function validateSession() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    $user = queryOne("SELECT id, isAdmin, isVerified FROM users WHERE id = :id", [':id' => $_SESSION['user_id']]);
    if (!$user) {
        session_destroy();
        return false;
    }
    $_SESSION['is_admin'] = $user['isAdmin'];
    $_SESSION['is_verified'] = $user['isVerified'];
    return true;
}

function requireAdmin() {
    if (!validateSession() || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] != 1) {
        response(false, 'غير مصرح لك بهذا الإجراء');
        exit;
    }
    return true;
}
function getNotifications($db, $user_id) {
    $stmt = $db->prepare("SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 20");
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    
    $notifications = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $notifications[] = $row;
    }
    return $notifications;
}
function getSafeUserId() {
    return isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
}

function getSafeAdminId() {
    if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
        return getSafeUserId();
    }
    return 1;
}

function generateOTP($length = 6) {
    return str_pad(random_int(0, 999999), $length, '0', STR_PAD_LEFT);
}

function generateResetCode($length = 6) {
    return str_pad(random_int(0, 999999), $length, '0', STR_PAD_LEFT);
}

// ===== دوال نظام التحقق عبر البريد الإلكتروني =====

/**
 * دالة متقدمة لإرسال البريد الإلكتروني مع تفاصيل التشخيص وحماية من Spam
 */
function sendEmailWithDebug($to, $subject, $htmlMessage, $code) {
    $smtp = getSMTPConfig();
    
    // محتوى نصي بديل في حال لم يدعم عميل البريد الـ HTML
    $textMessage = "كود التحقق الخاص بك هو: {$code}\n\n";
    $textMessage .= "هذا الكود صالح لمدة 5 دقائق.\n";
    $textMessage .= "إذا لم تطلب هذا الكود، يرجى تجاهل هذه الرسالة.";
    
    $boundary = "----=" . md5(uniqid(mt_rand(), true));
    
    // رؤوس محسنة لتقليل احتمالية وصول الرسالة إلى الـ Spam
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "From: \"" . $smtp['from_name'] . "\" <" . $smtp['from_email'] . ">\r\n";
    $headers .= "Reply-To: " . $smtp['from_email'] . "\r\n";
    $headers .= "Return-Path: " . $smtp['from_email'] . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "X-Priority: 3\r\n";
    $headers .= "Message-ID: <" . time() . "-" . md5(uniqid()) . "@" . parse_url(SITE_URL, PHP_URL_HOST) . ">\r\n";
    $headers .= "Date: " . date('r') . "\r\n";
    
    // بناء محتوى الرسالة (Multipart)
    $fullMessage = "--{$boundary}\r\n";
    $fullMessage .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $fullMessage .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $fullMessage .= $textMessage . "\r\n\r\n";
    
    $fullMessage .= "--{$boundary}\r\n";
    $fullMessage .= "Content-Type: text/html; charset=UTF-8\r\n";
    $fullMessage .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $fullMessage .= $htmlMessage . "\r\n\r\n";
    $fullMessage .= "--{$boundary}--\r\n";
    
    $additional_parameters = "-f " . $smtp['from_email'];
    
    // محاولة الإرسال باستخدام دالة mail أو PHPMailer إذا وجد
    if (class_exists('PHPMailer\PHPMailer\PHPMailer') && $smtp['enabled']) {
        try {
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $smtp['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp['username'];
            $mail->Password = $smtp['password'];
            $mail->SMTPSecure = $smtp['encryption'] ?? 'ssl';
            $mail->Port = $smtp['port'] ?? 465;
            $mail->setFrom($smtp['from_email'], $smtp['from_name']);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlMessage;
            $mail->AltBody = $textMessage;
            $mail->CharSet = 'UTF-8';
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("SMTP Error: " . $e->getMessage());
            return false;
        }
    }
    
    // البديل عبر دالة البريد العادية مع معامل الإرجاع
    return mail($to, $subject, $fullMessage, $headers, $additional_parameters);
}
function sendVerificationEmail($email, $code) {
    $smtp = getSMTPConfig();
    $subject = "🔐 كود التحقق الخاص بك - " . $smtp['from_name'];
    
    $htmlMessage = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>كود التحقق</title>
        <style>
            body { font-family: Arial, sans-serif; direction: rtl; background: #f4f4f4; margin: 0; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; padding: 30px; background: #ffffff; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
            .header { text-align: center; padding-bottom: 20px; border-bottom: 3px solid #058693; }
            .header h2 { color: #333; margin: 0; }
            .content { padding: 20px 0; }
            .code-box { background: linear-gradient(135deg, #058693 0%, #0AA6B5 100%); padding: 25px; border-radius: 12px; text-align: center; margin: 20px 0; }
            .code-box .code { font-size: 42px; font-weight: bold; color: #ffffff; letter-spacing: 12px; font-family: 'Courier New', monospace; text-shadow: 2px 2px 4px rgba(0,0,0,0.2); }
            .info { background: #f0f8f8; padding: 15px; border-radius: 8px; margin: 15px 0; }
            .info p { margin: 5px 0; color: #555; }
            .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; font-size: 12px; color: #999; text-align: center; }
            .warning { color: #ff6b6b; font-size: 13px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h2>🔐 كود التحقق الخاص بك</h2>
            </div>
            <div class='content'>
                <p style='font-size: 16px; color: #333;'>مرحباً بك في <strong>{$smtp['from_name']}</strong>،</p>
                <p style='font-size: 16px; color: #333;'>لقد قمت بطلب كود تحقق من نظامنا الآلي.</p>
                <div class='info'>
                    <p>📌 <strong>كود التحقق المكون من 6 أرقام هو:</strong></p>
                </div>
                <div class='code-box'>
                    <div class='code'>{$code}</div>
                </div>
                <div class='info'>
                    <p>⏱️ <strong>هذا الكود صالح لمدة 10 دقائق</strong></p>
                    <p>🔒 <strong>لا تشارك هذا الكود مع أي شخص</strong></p>
                </div>
                <p style='color: #888; font-size: 14px; text-align: center;'>إذا لم تطلب هذا الكود، يرجى تجاهل هذه الرسالة.</p>
            </div>
            <div class='footer'>
                <p>تم الإرسال من نظام التحقق الآلي - {$smtp['from_name']}</p>
                <p>📧 {$smtp['from_email']}</p>
                <p class='warning'>⚠️ هذا بريد آلي، يرجى عدم الرد على هذه الرسالة</p>
            </div>
      <!-- ضع هذا الكود في مكان عرض الصفحة (HTML) وليس في أجزاء كود الـ PHP البرمجي -->

        </div>
    </body>
    </html>
    ";

    // استخدام دالة التشخيص والإرسال المحدثة
    return sendEmailWithDebug($email, $subject, $htmlMessage, $code);
}
function saveImageFromBase64($base64Data, $folder, $maxWidth = 800, $maxHeight = 600, $quality = 80) {
    if (empty($base64Data)) return '';
    
    $base64Data = trim($base64Data);
    if (strpos($base64Data, 'base64,') !== false) {
        $parts = explode('base64,', $base64Data);
        $base64Data = $parts[1] ?? '';
    }
    
    $base64Data = str_replace(' ', '+', $base64Data);
    $base64Data = str_replace("\n", '', $base64Data);
    $base64Data = str_replace("\r", '', $base64Data);
    
    $imageData = base64_decode($base64Data, true);
    if ($imageData === false || $imageData === '') return '';
    
    $image = imagecreatefromstring($imageData);
    if (!$image) return '';
    
    $mimeType = 'image/jpeg';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_buffer($finfo, $imageData);
            if ($detected) $mimeType = $detected;
            finfo_close($finfo);
        }
    }
    
    $extension = 'jpg';
    if (strpos($mimeType, 'png') !== false) $extension = 'png';
    elseif (strpos($mimeType, 'gif') !== false) $extension = 'gif';
    elseif (strpos($mimeType, 'webp') !== false) $extension = 'webp';
    
    $width = imagesx($image);
    $height = imagesy($image);
    
    if ($width > $maxWidth || $height > $maxHeight) {
        $ratio = min($maxWidth / $width, $maxHeight / $height);
        $newWidth = round($width * $ratio);
        $newHeight = round($height * $ratio);
    } else {
        $newWidth = $width;
        $newHeight = $height;
    }
    
    $newImage = imagecreatetruecolor($newWidth, $newHeight);
    
    if ($extension === 'png') {
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        $transparent = imagecolorallocatealpha($newImage, 255, 255, 255, 127);
        imagefilledrectangle($newImage, 0, 0, $newWidth, $newHeight, $transparent);
    }
    
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    $filename = uniqid() . '.' . $extension;
    $path = $folder . '/' . $filename;
    
    if ($extension === 'png') imagepng($newImage, $path, 9);
    elseif ($extension === 'gif') imagegif($newImage, $path);
    elseif ($extension === 'webp') imagewebp($newImage, $path, $quality);
    else imagejpeg($newImage, $path, $quality);
    
    imagedestroy($image);
    imagedestroy($newImage);
    return $filename;
}

function deleteImageFile($filename, $folder = 'products') {
    if (empty($filename)) return true;
    $path = UPLOAD_DIR . '/' . $folder . '/' . $filename;
    if (file_exists($path)) return unlink($path);
    return true;
}

function getImageData($fieldName = 'image') {
    global $input;
    
    // ===== من $_FILES =====
    if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES[$fieldName];
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            error_log("❌ فشل قراءة الملف: " . $file['tmp_name']);
            return '';
        }
        return base64_encode($content);
    }
    
    // ===== من input =====
    if (isset($input[$fieldName]) && !empty($input[$fieldName])) {
        $data = $input[$fieldName];
        
        // بيانات Base64 من data:image
        if (is_string($data) && strpos($data, 'data:image') === 0) {
            $parts = explode(',', $data);
            return $parts[1] ?? '';
        }
        
        // Base64 خالص
        if (is_string($data) && preg_match('/^[a-zA-Z0-9\/\+]+=*$/', $data)) {
            return $data;
        }
        
        // تنظيف البيانات
        if (is_string($data)) {
            $clean = preg_replace('/^data:image\/[a-zA-Z]+;base64,/', '', $data);
            $clean = str_replace(' ', '+', $clean);
            if (base64_decode($clean, true) !== false) {
                return $clean;
            }
        }
    }
    
    return '';
}

function sendNotificationToAllUsers($title, $message, $type = 'info', $link = '') {
    $users = query("SELECT id FROM users");
    foreach ($users as $user) {
        execute("INSERT INTO notifications (userId, title, message, type, link, isRead, isPushSent) 
                 VALUES (:userId, :title, :message, :type, :link, 0, 0)", [
            ':userId' => $user['id'],
            ':title' => $title,
            ':message' => $message,
            ':type' => $type,
            ':link' => $link
        ]);
    }
    return true;
}

function sendNotification($userId, $title, $message, $type = 'info', $link = '') {
    execute("INSERT INTO notifications (userId, title, message, type, link, isRead, isPushSent) 
             VALUES (:userId, :title, :message, :type, :link, 0, 0)", [
        ':userId' => $userId,
        ':title' => $title,
        ':message' => $message,
        ':type' => $type,
        ':link' => $link
    ]);
    return getLastInsertId();
}

function getReadStatusText($msg) {
    if (!$msg['isRead'] || empty($msg['readAt'])) {
        return '<span style="color:#999;font-size:10px;">🟡 لم تُقرأ</span>';
    }
    
    $time = new DateTime($msg['readAt']);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $time->getTimestamp();
    $minutes = floor($diff / 60);
    
    if ($minutes < 1) {
        return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة الآن</span>';
    } elseif ($minutes < 5) {
        return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة منذ ' . $minutes . ' دقيقة</span>';
    } elseif ($minutes < 60) {
        return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة منذ ' . $minutes . ' دقائق</span>';
    } else {
        $hours = floor($minutes / 60);
        if ($hours < 24) {
            return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة منذ ' . $hours . ' ساعة</span>';
        } else {
            $days = floor($hours / 24);
            if ($days == 1) {
                return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة أمس</span>';
            } else {
                return '<span style="color:#2ECC71;font-size:10px;">✔️ تمت القراءة الساعة ' . $time->format('h:i A') . '</span>';
            }
        }
    }
}

function getUserStatus($userId) {
    $user = queryOne("SELECT isAdmin, lastActivity FROM users WHERE id = :id", [':id' => $userId]);
    if (!$user) return ['status' => 'offline', 'text' => '⚪ غير متصل'];
    
    if ($user['isAdmin'] == 1) {
        return ['status' => 'online', 'text' => '🟢 متصل الآن'];
    }
    
    if (empty($user['lastActivity'])) {
        return ['status' => 'offline', 'text' => '⚪ غير متصل'];
    }
    
    $time = new DateTime($user['lastActivity']);
    $now = new DateTime();
    $diff = $now->getTimestamp() - $time->getTimestamp();
    $minutes = floor($diff / 60);
    
    if ($minutes < 5) {
        return ['status' => 'online', 'text' => '🟢 متصل الآن'];
    } elseif ($minutes < 30) {
        return ['status' => 'away', 'text' => '🟡 غير نشط منذ ' . $minutes . ' دقيقة'];
    } else {
        return ['status' => 'offline', 'text' => '⚪ غير متصل'];
    }
}

function verifyGoogleToken($idToken) {
    $config = getGoogleOAuthConfig();
    if (!$config['enabled']) {
        error_log("Google OAuth is disabled");
        return null;
    }
    
    if (empty($idToken)) {
        return null;
    }
    
    $url = "https://oauth2.googleapis.com/tokeninfo?id_token=" . $idToken;
    
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            error_log("Google token verification failed: HTTP $httpCode");
            return null;
        }
    } else {
        $response = @file_get_contents($url);
        if (!$response) {
            error_log("Google token verification failed: file_get_contents failed");
            return null;
        }
    }
    
    $data = json_decode($response, true);
    if (!$data || isset($data['error'])) {
        error_log("Google token verification error: " . ($data['error'] ?? 'Unknown error'));
        return null;
    }
    
    if ($data['aud'] != $config['client_id']) {
        error_log("Google token audience mismatch: " . $data['aud'] . " vs " . $config['client_id']);
        return null;
    }
    
    if (isset($data['exp']) && $data['exp'] < time()) {
        error_log("Google token expired");
        return null;
    }
    
    return $data;
}

function loginWithGoogle($idToken) {
    if (empty($idToken)) {
        return ['success' => false, 'message' => 'لم يتم إرسال التوكن'];
    }
    
    $config = getGoogleOAuthConfig();
    if (!$config['enabled']) {
        return ['success' => false, 'message' => 'تسجيل الدخول عبر Google معطل حالياً'];
    }
    
    $userData = verifyGoogleToken($idToken);
    if (!$userData) {
        return ['success' => false, 'message' => 'توكن Google غير صالح أو منتهي الصلاحية'];
    }
    
    $email = $userData['email'] ?? '';
    $name = $userData['name'] ?? $userData['given_name'] ?? 'مستخدم Google';
    $googleId = $userData['sub'] ?? '';
    $avatar = $userData['picture'] ?? '';
    
    if (empty($email) || empty($googleId)) {
        return ['success' => false, 'message' => 'بيانات Google غير مكتملة'];
    }
    
    $user = queryOne("SELECT * FROM users WHERE email = :email OR google_id = :google_id", [
        ':email' => $email,
        ':google_id' => $googleId
    ]);
    
    if ($user) {
        $sql = "UPDATE users SET 
                google_id = :google_id, 
                lastActivity = datetime('now', 'localtime'),
                isVerified = 1";
        $params = [
            ':google_id' => $googleId,
            ':id' => $user['id']
        ];
        
        if (!empty($avatar) && empty($user['avatar_path'])) {
            $avatarContent = @file_get_contents($avatar);
            if ($avatarContent) {
                $avatarPath = saveImageFromBase64(base64_encode($avatarContent), UPLOAD_DIR . '/avatars', 200, 200, 70);
                if ($avatarPath) {
                    $sql .= ", avatar_path = :avatar_path";
                    $params[':avatar_path'] = $avatarPath;
                }
            }
        }
        
        $sql .= " WHERE id = :id";
        
        if (execute($sql, $params)) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['is_admin'] = $user['isAdmin'];
            $_SESSION['is_verified'] = 1;
            
            $updatedUser = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $user['id']]);
            return ['success' => true, 'message' => 'تم تسجيل الدخول', 'user' => $updatedUser];
        }
        
        return ['success' => false, 'message' => 'فشل تحديث بيانات المستخدم'];
    } else {
        $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $phone = '07' . random_int(10000000, 99999999);
        
        $sql = "INSERT INTO users (name, email, password, phone, google_id, isVerified, lastActivity) 
                VALUES (:name, :email, :password, :phone, :google_id, 1, datetime('now', 'localtime'))";
        
        if (execute($sql, [
            ':name' => $name,
            ':email' => $email,
            ':password' => $password,
            ':phone' => $phone,
            ':google_id' => $googleId
        ])) {
            $id = getLastInsertId();
            
            if (!empty($avatar)) {
                $avatarContent = @file_get_contents($avatar);
                if ($avatarContent) {
                    $avatarPath = saveImageFromBase64(base64_encode($avatarContent), UPLOAD_DIR . '/avatars', 200, 200, 70);
                    if ($avatarPath) {
                        execute("UPDATE users SET avatar_path = :avatar_path WHERE id = :id", [
                            ':avatar_path' => $avatarPath,
                            ':id' => $id
                        ]);
                    }
                }
            }
            
            $user = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $id]);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['is_admin'] = $user['isAdmin'];
            $_SESSION['is_verified'] = 1;
            
            sendNotification($id, "🎉 مرحباً بك", "تم إنشاء حسابك بواسطة Google", 'info', '/app');
            
            return ['success' => true, 'message' => 'تم إنشاء الحساب', 'user' => $user];
        }
    }
    
    return ['success' => false, 'message' => 'حدث خطأ أثناء التسجيل'];
}

// ============================================================
// معالجة الطلبات (جميع الإجراءات)
// ============================================================

$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($isAjax || isset($_GET['action']) || isset($_POST['action'])) {
    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');
    
    header('Content-Type: application/json');
    
    $input = [];
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    
    if (is_string($contentType) && strpos($contentType, 'application/json') !== false) {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $input = [];
        }
    } else {
        $input = $_POST;
        if (empty($input)) {
            $input = $_GET;
        }
    }
    
    if (!empty($_FILES)) {
        foreach ($_FILES as $key => $file) {
            if ($file['error'] === UPLOAD_ERR_OK) {
                $input[$key] = $file;
            }
        }
    }
    
    switch ($action) {
        case 'forgotPassword':
        case 'sendResetCode':
            $email = getVal('email');
            
            if (empty($email)) {
                response(false, 'الرجاء إدخال البريد الإلكتروني');
            }
            
            // 1. التحقق هل البريد مسجل في قاعدة البيانات أم لا
            $user = queryOne("SELECT id FROM users WHERE email = :email", [':email' => $email]);
            if (!$user) {
                response(false, 'البريد الإلكتروني غير مسجل لدينا');
            }
            
            // 2. توليد كود عشوائي من 6 أرقام
            $code = rand(100000, 999999);
            $expiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            // 3. حذف أي أكواد سابقة غير مستخدمة لهذا البريد
            execute("DELETE FROM password_resets WHERE email = :email", [':email' => $email]);
            
            // 4. حفظ الكود الجديد في جدول password_resets
            // (تأكد أن جدولك يحتوي على حقل used و expires_at أو قم بتعديله ليطابق جدولك)
            $saved = execute("INSERT INTO password_resets (email, code, used, expires_at) VALUES (:email, :code, 0, :expires)", [
                ':email' => $email,
                ':code' => $code,
                ':expires' => $expiresAt
            ]);
            
            if (!$saved) {
                response(false, 'حدث خطأ أثناء إنشاء كود التحقق، حاول مرة أخرى');
            }
            
            // 5. إرسال البريد الإلكتروني الذي أجبناه سابقاً
            $emailSent = sendVerificationEmail($email, $code);
            
            if ($emailSent) {
                response(true, 'تم إرسال كود التحقق إلى بريدك الإلكتروني بنجاح');
            } else {
                response(false, 'فشل إرسال البريد الإلكتروني، يرجى التحقق من إعدادات السيرفر');
            }
            break;
         case 'updateProfile':
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            if (!isset($_SESSION['user_id'])) {
                response(false, 'انتهت صلاحية الجلسة، يرجى تسجيل الدخول مرة أخرى');
            }
            
            $userId = $_SESSION['user_id'];
            $name = $_POST['username'] ?? '';
            $phone = $_POST['phone'] ?? '';
            
            if (empty($name) || empty($phone)) {
                response(false, 'الاسم ورقم الهاتف حقول إجبارية');
            }
            
            // التحقق من وجود المستخدم في قاعدة البيانات أولاً
            $currentUser = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $userId]);
            if (!$currentUser) {
                // إذا لم يتم العثور عليه، نقوم بتنظيف الجلسة لمنع الأخطاء المتكررة
                unset($_SESSION['user_id']);
                response(false, 'المستخدم غير مسجل في النظام، يرجى إعادة تسجيل الدخول');
            }
            
            // تنفيذ التحديث بأمان تام
            execute("UPDATE users SET name = :name, phone = :phone WHERE id = :id", [
                ':name' => $name,
                ':phone' => $phone,
                ':id' => $userId
            ]);
            
            response(true, 'تم حفظ التغييرات بنجاح');
            break;
        // ===== Google Login =====
        case 'googleLogin':
            $idToken = getVal('id_token');
            if (empty($idToken)) {
                response(false, 'لم يتم إرسال التوكن');
            }
            $result = loginWithGoogle($idToken);
            if ($result['success']) {
                $user = $result['user'];
                unset($user['password']);
                if ($user['avatar_path']) {
                    $user['avatar_url'] = SITE_URL . '/data/uploads/avatars/' . $user['avatar_path'];
                }
                response(true, $result['message'], $user);
            } else {
                response(false, $result['message']);
            }
            break;
        
        // ===== Register with OTP =====
        case 'registerWithOTP':
            $email = getVal('email');
            $phone = getVal('phone');
            $name = getVal('name');
            $password = getVal('password');
            
            if (empty($email) || empty($phone) || empty($name) || empty($password)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                response(false, 'البريد الإلكتروني غير صحيح');
            }
            
            if (!preg_match('/^07[0-9]{8,10}$/', $phone)) {
                response(false, 'رقم الهاتف يجب أن يبدأ بـ 07 ويتكون من 10-12 رقم');
            }
            
            if (queryOne("SELECT * FROM users WHERE email = :email", [':email' => $email])) {
                response(false, 'هذا البريد الإلكتروني مسجل بالفعل');
            }
            
            if (queryOne("SELECT * FROM users WHERE phone = :phone", [':phone' => $phone])) {
                response(false, 'رقم الهاتف هذا مسجل بالفعل');
            }
            
            $otp = generateOTP();
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            $_SESSION['temp_registration'] = [
                'name' => $name,
                'email' => $email,
                'phone' => $phone,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'otp' => $otp,
                'expires' => $expires
            ];
            
            if (sendVerificationEmail($email, $otp)) {
                response(true, 'تم إرسال رمز التحقق إلى بريدك الإلكتروني', ['email' => $email]);
            } else {
                response(false, 'فشل إرسال رمز التحقق، يرجى المحاولة مرة أخرى');
            }
            break;
        
        // ===== Verify OTP =====
        case 'verifyOTP':
            $otp = getVal('otp');
            if (empty($otp) || !isset($_SESSION['temp_registration'])) {
                response(false, 'لم يتم العثور على عملية تسجيل');
            }
            
            $temp = $_SESSION['temp_registration'];
            
            if ($temp['otp'] !== $otp) {
                response(false, 'رمز التحقق غير صحيح');
            }
            
            if (strtotime($temp['expires']) < time()) {
                unset($_SESSION['temp_registration']);
                response(false, 'انتهت صلاحية رمز التحقق، يرجى المحاولة مرة أخرى');
            }
            
            $sql = "INSERT INTO users (name, email, password, phone, balance, isAdmin, adminType, isVerified, lastActivity) 
                    VALUES (:name, :email, :password, :phone, 0, 0, 'user', 1, datetime('now', 'localtime'))";
            
            if (execute($sql, [
                ':name' => $temp['name'],
                ':email' => $temp['email'],
                ':password' => $temp['password'],
                ':phone' => $temp['phone']
            ])) {
                $id = getLastInsertId();
                $user = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $id]);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['is_admin'] = $user['isAdmin'];
                $_SESSION['is_verified'] = 1;
                
                unset($_SESSION['temp_registration']);
                unset($user['password']);
                
                sendNotification($id, "🎉 مرحباً بك", "أهلاً بك في متجر Tokmart", 'info', '/app');
                
                response(true, 'تم إنشاء الحساب بنجاح', $user);
            } else {
                response(false, 'فشل إنشاء الحساب');
            }
            break;
        
        // ===== Resend OTP =====
        case 'resendOTP':
            if (!isset($_SESSION['temp_registration'])) {
                response(false, 'لم يتم العثور على عملية تسجيل');
            }
            
            $temp = $_SESSION['temp_registration'];
            $newOtp = generateOTP();
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            $_SESSION['temp_registration']['otp'] = $newOtp;
            $_SESSION['temp_registration']['expires'] = $expires;
            
            if (sendVerificationEmail($temp['email'], $newOtp)) {
                response(true, 'تم إرسال رمز تحقق جديد');
            } else {
                response(false, 'فشل إرسال الرمز');
            }
            break;
        
        // ===== Login =====
        case 'login':
            $identifier = getVal('identifier');
            $password = getVal('password');
            
            $user = queryOne("SELECT * FROM users WHERE email = :identifier OR phone = :identifier", 
                           [':identifier' => $identifier]);
            
            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['is_admin'] = $user['isAdmin'];
                $_SESSION['admin_type'] = $user['adminType'];
                unset($user['password']);
                if ($user['avatar_path']) {
                    $user['avatar_url'] = SITE_URL . '/data/uploads/avatars/' . $user['avatar_path'];
                }
                execute("UPDATE users SET lastActivity = datetime('now', 'localtime') WHERE id = :id", 
                       [':id' => $user['id']]);
                response(true, 'تم تسجيل الدخول بنجاح', $user);
            } else {
                response(false, 'بيانات الدخول غير صحيحة');
            }
            break;
        
        // ===== Logout =====
        case 'logout':
            session_destroy();
            response(true, 'تم تسجيل الخروج بنجاح');
            break;
        
        // ===== Update User =====
        case 'updateUser':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            
            $id = intval(getVal('id', 0));
            $currentUserId = getSafeUserId();
            
            if ($id != $currentUserId) {
                response(false, 'لا يمكنك تعديل حساب مستخدم آخر');
            }
            
            $name = getVal('name');
            $phone = getVal('phone');
            $email = getVal('email');
            
            if (empty($name) || empty($phone) || empty($email)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            
            if (!preg_match('/^07[0-9]{8,10}$/', $phone)) {
                response(false, 'رقم الهاتف غير صحيح');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                response(false, 'البريد الإلكتروني غير صحيح');
            }
            
            $sql = "UPDATE users SET name = :name, phone = :phone, email = :email";
            $params = [':id' => $id, ':name' => $name, ':phone' => $phone, ':email' => $email];
            
            $avatarData = getImageData('avatar');
            if (!empty($avatarData)) {
                $oldUser = queryOne("SELECT avatar_path FROM users WHERE id = :id", [':id' => $id]);
                if ($oldUser && $oldUser['avatar_path']) {
                    deleteImageFile($oldUser['avatar_path'], 'avatars');
                }
                $avatarPath = saveImageFromBase64($avatarData, UPLOAD_DIR . '/avatars', 200, 200, 70);
                $sql .= ", avatar_path = :avatar_path";
                $params[':avatar_path'] = $avatarPath;
            }
            
            $currentPassword = getVal('currentPassword');
            $newPassword = getVal('newPassword');
            
            if (!empty($currentPassword) && !empty($newPassword)) {
                $user = queryOne("SELECT password FROM users WHERE id = :id", [':id' => $id]);
                if (!$user) {
                    response(false, 'المستخدم غير موجود');
                }
                
                if (!password_verify($currentPassword, $user['password'])) {
                    response(false, 'كلمة المرور الحالية غير صحيحة');
                }
                
                if (strlen($newPassword) < 6) {
                    response(false, 'كلمة المرور الجديدة يجب أن تكون 6 أحرف على الأقل');
                }
                
                $password = password_hash($newPassword, PASSWORD_DEFAULT);
                $sql .= ", password = :password";
                $params[':password'] = $password;
            }
            
            $sql .= " WHERE id = :id";
            
            if (execute($sql, $params)) {
                $user = queryOne("SELECT id, name, email, phone, balance, isAdmin, adminType, avatar_path, registeredAt 
                                FROM users WHERE id = :id", [':id' => $id]);
                if ($user) {
                    $user['avatar_url'] = $user['avatar_path'] ? SITE_URL . '/data/uploads/avatars/' . $user['avatar_path'] : '';
                }
                sendNotification($id, "✅ تحديث الحساب", "تم تحديث بيانات حسابك بنجاح", 'info', '/settings');
                response(true, 'تم تحديث المستخدم', $user);
            } else {
                response(false, 'فشل تحديث المستخدم');
            }
            break;
        
// ===== Add Product (with multiple images) =====
case 'addProduct':
    requireAdmin();
    
    // ===== التحقق من وجود بيانات =====
    $name = getVal('name');
    $nameEn = getVal('nameEn');
    $category = getVal('category');
    $price = floatval(getVal('price', 0));
    
    if (empty($name) || empty($nameEn) || empty($category) || $price <= 0) {
        response(false, 'جميع الحقول المطلوبة غير مكتملة: الاسم، الاسم بالإنجليزية، التصنيف، السعر');
        break;
    }
    
    // ===== معالجة الصورة الرئيسية =====
    $imageData = getImageData('image');
    $imagePath = '';
    if (!empty($imageData)) {
        $imagePath = saveImageFromBase64($imageData, UPLOAD_DIR . '/products', 400, 400, 70);
        if (empty($imagePath)) {
            response(false, 'فشل حفظ الصورة الرئيسية');
            break;
        }
    }
    
    // ===== معالجة الصور الإضافية =====
    $images = [];
    if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
        for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
            if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                $tmpFile = $_FILES['images']['tmp_name'][$i];
                if (!file_exists($tmpFile)) continue;
                
                $content = file_get_contents($tmpFile);
                if ($content === false) continue;
                
                $base64 = base64_encode($content);
                $imgPath = saveImageFromBase64($base64, UPLOAD_DIR . '/products', 400, 400, 70);
                if ($imgPath) {
                    $images[] = $imgPath;
                }
            }
        }
    }
    $imagesJson = json_encode($images);
    
    // ===== معالجة السعر القديم =====
    $oldPrice = getVal('oldPrice') ? floatval(getVal('oldPrice')) : null;
    if ($oldPrice !== null && $oldPrice <= 0) {
        $oldPrice = null;
    }
    
    // ===== معالجة الخيارات =====
    $isNew = getVal('isNew') === 'true' || getVal('isNew') === '1' ? 1 : 0;
    $isBestSeller = getVal('isBestSeller') === 'true' || getVal('isBestSeller') === '1' ? 1 : 0;
    $isFlashDeal = getVal('isFlashDeal') === 'true' || getVal('isFlashDeal') === '1' ? 1 : 0;
    $isFeatured = getVal('isFeatured') === 'true' || getVal('isFeatured') === '1' ? 1 : 0;
    
    // ===== إدراج المنتج =====
    $sql = "INSERT INTO products (
                name, nameEn, desc, descEn, category, brand, 
                price, oldPrice, image_path, images, 
                deliveryTime, rating, ratingCount, 
                isNew, isBestSeller, isFlashDeal, isFeatured
            ) VALUES (
                :name, :nameEn, :desc, :descEn, :category, :brand, 
                :price, :oldPrice, :image_path, :images, 
                :deliveryTime, :rating, :ratingCount, 
                :isNew, :isBestSeller, :isFlashDeal, :isFeatured
            )";
    
    $params = [
        ':name' => $name,
        ':nameEn' => $nameEn,
        ':desc' => getVal('desc'),
        ':descEn' => getVal('descEn'),
        ':category' => $category,
        ':brand' => getVal('brand'),
        ':price' => $price,
        ':oldPrice' => $oldPrice,
        ':image_path' => $imagePath,
        ':images' => $imagesJson,
        ':deliveryTime' => getVal('deliveryTime', 'خلال 24 ساعة'),
        ':rating' => floatval(getVal('rating', 4.5)),
        ':ratingCount' => intval(getVal('ratingCount', 0)),
        ':isNew' => $isNew,
        ':isBestSeller' => $isBestSeller,
        ':isFlashDeal' => $isFlashDeal,
        ':isFeatured' => $isFeatured
    ];
    
    // ===== تنفيذ الاستعلام =====
    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        
        if ($result) {
            $id = getLastInsertId();
            
            // ===== حساب نسبة الخصم =====
            if ($oldPrice && $oldPrice > $price) {
                $discount = round((($oldPrice - $price) / $oldPrice) * 100) . '%';
                execute("UPDATE products SET discount = :discount WHERE id = :id", 
                        [':discount' => $discount, ':id' => $id]);
            }
            
            // ===== إرسال إشعار =====
            sendNotificationToAllUsers("📦 منتج جديد", 'تم إضافة منتج جديد إلى المتجر', 'offer', '/product/' . $id);
            
            // ===== جلب المنتج وإرجاعه =====
            $product = queryOne("SELECT * FROM products WHERE id = :id", [':id' => $id]);
            if ($product) {
                $product['image_url'] = $product['image_path'] ? SITE_URL . '/data/uploads/products/' . $product['image_path'] : '';
                $product['images'] = json_decode($product['images'] ?? '[]', true);
            }
            
            response(true, 'تم إضافة المنتج بنجاح', $product);
        } else {
            response(false, 'فشل إضافة المنتج - خطأ في قاعدة البيانات');
        }
    } catch (Exception $e) {
        error_log("❌ خطأ في إضافة المنتج: " . $e->getMessage());
        response(false, 'خطأ في قاعدة البيانات: ' . $e->getMessage());
    }
    break;
        
        // ===== Update Product =====
        case 'updateProduct':
            requireAdmin();
            
            $id = intval(getVal('id', 0));
            
            $imageData = getImageData('image');
            $imagePath = null;
            if (!empty($imageData)) {
                $oldProduct = queryOne("SELECT image_path FROM products WHERE id = :id", [':id' => $id]);
                if ($oldProduct && $oldProduct['image_path']) {
                    deleteImageFile($oldProduct['image_path'], 'products');
                }
                $imagePath = saveImageFromBase64($imageData, UPLOAD_DIR . '/products', 400, 400, 70);
            }
            
            $existingImages = queryOne("SELECT images FROM products WHERE id = :id", [':id' => $id]);
            $currentImages = $existingImages ? json_decode($existingImages['images'], true) : [];
            
            $deleteImages = isset($input['delete_images']) ? $input['delete_images'] : [];
            if (is_array($deleteImages)) {
                foreach ($deleteImages as $imgToDelete) {
                    if (($key = array_search($imgToDelete, $currentImages)) !== false) {
                        deleteImageFile($imgToDelete, 'products');
                        unset($currentImages[$key]);
                    }
                }
                $currentImages = array_values($currentImages);
            }
            
            if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
                for ($i = 0; $i < count($_FILES['images']['name']); $i++) {
                    if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                        $file = [
                            'name' => $_FILES['images']['name'][$i],
                            'tmp_name' => $_FILES['images']['tmp_name'][$i],
                            'size' => $_FILES['images']['size'][$i],
                            'type' => $_FILES['images']['type'][$i]
                        ];
                        $content = file_get_contents($file['tmp_name']);
                        $base64 = base64_encode($content);
                        $imgPath = saveImageFromBase64($base64, UPLOAD_DIR . '/products', 400, 400, 70);
                        if ($imgPath) {
                            $currentImages[] = $imgPath;
                        }
                    }
                }
            }
            
            $imagesJson = json_encode($currentImages);
            
           $sql = "UPDATE products SET 
                name = :name, nameEn = :nameEn, desc = :desc, descEn = :descEn,
                category = :category, brand = :brand, price = :price, oldPrice = :oldPrice,
                images = :images, deliveryTime = :deliveryTime, rating = :rating, ratingCount = :ratingCount,
                isNew = :isNew, isBestSeller = :isBestSeller, isFlashDeal = :isFlashDeal,
                isFeatured = :isFeatured, discount = :discount";
        
        $price = floatval(getVal('price', 0));
        $oldPrice = getVal('oldPrice') ? floatval(getVal('oldPrice')) : null;
        
        $discount = null;
        if ($oldPrice && $oldPrice > $price) {
            $discount = round((($oldPrice - $price) / $oldPrice) * 100) . '%';
        } else {
            $oldPrice = null;
        }

        $params = [
            ':id' => $id,
            ':name' => getVal('name'),
            ':nameEn' => getVal('nameEn'),
            ':desc' => getVal('desc'),
            ':descEn' => getVal('descEn'),
            ':category' => getVal('category'),
            ':brand' => getVal('brand'),
            ':price' => $price,
            ':oldPrice' => $oldPrice,
            ':images' => $imagesJson,
            ':deliveryTime' => getVal('deliveryTime', 'خلال 24 ساعة'),
            ':rating' => floatval(getVal('rating', 4.5)),
            ':ratingCount' => intval(getVal('ratingCount', 0)),
            ':isNew' => getVal('isNew') === 'true' || getVal('isNew') === '1' ? 1 : 0,
            ':isBestSeller' => getVal('isBestSeller') === 'true' || getVal('isBestSeller') === '1' ? 1 : 0,
            ':isFlashDeal' => getVal('isFlashDeal') === 'true' || getVal('isFlashDeal') === '1' ? 1 : 0,
            ':isFeatured' => getVal('isFeatured') === 'true' || getVal('isFeatured') === '1' ? 1 : 0,
            ':discount' => $discount
        ];
        
        if ($imagePath !== null) {
            $sql .= ", image_path = :image_path";
            $params[':image_path'] = $imagePath;
        }
        
        $sql .= " WHERE id = :id";
        
        // --- تعديل هنا لالتقاط سبب الفشل بدقة ---
        try {
            $success = execute($sql, $params);
            if ($success) {
                sendNotificationToAllUsers("📦 تحديث منتج", 'تم تحديث منتج في المتجر', 'info', '/product/' . $id);
                response(true, 'تم تحديث المنتج بنجاح');
            } else {
                // محاولة جلب الخطأ من اتصال قاعدة البيانات إن وجد
                global $db; // أو متغير الاتصال حسب مشروعك
                $dbError = isset($db) && $db instanceof SQLite3 ? $db->lastErrorMsg() : 'Unknown Database Error';
                response(false, 'فشل تحديث المنتج. تفاصيل الخطأ: ' . $dbError);
            }
        } catch (Exception $e) {
            response(false, 'خطأ استثنائي: ' . $e->getMessage());
        }
        break;
            $params = [
                ':id' => $id,
                ':name' => getVal('name'),
                ':nameEn' => getVal('nameEn'),
                ':desc' => getVal('desc'),
                ':descEn' => getVal('descEn'),
                ':category' => getVal('category'),
                ':brand' => getVal('brand'),
                ':price' => floatval(getVal('price', 0)),
                ':oldPrice' => getVal('oldPrice') ? floatval(getVal('oldPrice')) : null,
                ':images' => $imagesJson,
                ':deliveryTime' => getVal('deliveryTime', 'خلال 24 ساعة'),
                ':rating' => floatval(getVal('rating', 4.5)),
                ':ratingCount' => intval(getVal('ratingCount', 0)),
                ':isNew' => getVal('isNew') === 'true' || getVal('isNew') === '1' ? 1 : 0,
                ':isBestSeller' => getVal('isBestSeller') === 'true' || getVal('isBestSeller') === '1' ? 1 : 0,
                ':isFlashDeal' => getVal('isFlashDeal') === 'true' || getVal('isFlashDeal') === '1' ? 1 : 0,
                ':isFeatured' => getVal('isFeatured') === 'true' || getVal('isFeatured') === '1' ? 1 : 0
            ];
            
            if ($imagePath !== null) {
                $sql .= ", image_path = :image_path";
                $params[':image_path'] = $imagePath;
            }
            
            $sql .= " WHERE id = :id";
            
            $price = floatval(getVal('price', 0));
            $oldPrice = getVal('oldPrice') ? floatval(getVal('oldPrice')) : null;
            if ($oldPrice && $oldPrice > $price) {
                $discount = round((($oldPrice - $price) / $oldPrice) * 100) . '%';
                $sql .= ", discount = :discount";
                $params[':discount'] = $discount;
            } else {
                $sql .= ", discount = NULL, oldPrice = NULL";
                $params[':oldPrice'] = null;
            }
            
            if (execute($sql, $params)) {
                sendNotificationToAllUsers("📦 تحديث منتج", 'تم تحديث منتج في المتجر', 'info', '/product/' . $id);
                response(true, 'تم تحديث المنتج بنجاح');
            } else {
                response(false, 'فشل تحديث المنتج');
            }
            break;
        
        // ===== Delete Product =====
        case 'deleteProduct':
            requireAdmin();
            
            $id = intval(getVal('id', 0));
            $product = queryOne("SELECT image_path, images FROM products WHERE id = :id", [':id' => $id]);
            if ($product) {
                if ($product['image_path']) {
                    deleteImageFile($product['image_path'], 'products');
                }
                $images = json_decode($product['images'] ?? '[]', true);
                foreach ($images as $img) {
                    deleteImageFile($img, 'products');
                }
            }
            if (execute("DELETE FROM products WHERE id = :id", [':id' => $id])) {
                response(true, 'تم حذف المنتج بنجاح');
            } else {
                response(false, 'فشل حذف المنتج');
            }
            break;
        
        // ===== Add Category =====
        case 'addCategory':
            requireAdmin();
            
            $id = getVal('id');
            
            if (queryOne("SELECT * FROM categories WHERE id = :id", [':id' => $id])) {
                response(false, 'هذا المعرف موجود بالفعل');
                break;
            }
            
            $iconImageData = getImageData('iconImage');
            $iconImagePath = '';
            if (!empty($iconImageData)) {
                $iconImagePath = saveImageFromBase64($iconImageData, UPLOAD_DIR . '/categories', 200, 200, 70);
            }
            
            $bannerImageData = getImageData('bannerImage');
            $bannerImagePath = '';
            if (!empty($bannerImageData)) {
                $bannerImagePath = saveImageFromBase64($bannerImageData, UPLOAD_DIR . '/categories', 1792, 1024, 80);
            }
            
            $sql = "INSERT INTO categories (id, name, nameEn, icon, icon_image_path, banner_image_path, sortOrder) 
                    VALUES (:id, :name, :nameEn, :icon, :icon_image_path, :banner_image_path, :sortOrder)";
            
            $params = [
                ':id' => $id,
                ':name' => getVal('name'),
                ':nameEn' => getVal('nameEn'),
                ':icon' => getVal('icon', 'fa-solid fa-tag'),
                ':icon_image_path' => $iconImagePath,
                ':banner_image_path' => $bannerImagePath,
                ':sortOrder' => intval(getVal('sortOrder', 0))
            ];
            
            if (execute($sql, $params)) {
                sendNotificationToAllUsers("📂 تصنيف جديد", 'تم إضافة تصنيف جديد: ' . getVal('name'), 'info', '/categories');
                response(true, 'تم إضافة التصنيف بنجاح');
            } else {
                response(false, 'فشل إضافة التصنيف');
            }
            break;
        
        // ===== Update Category =====
        case 'updateCategory':
            requireAdmin();
            
            $oldId = getVal('oldId');
            $newId = getVal('id');
            
            $category = queryOne("SELECT * FROM categories WHERE id = :id", [':id' => $oldId]);
            if (!$category) {
                response(false, 'التصنيف غير موجود');
                break;
            }
            
            $iconImageData = getImageData('iconImage');
            $iconImagePath = null;
            if (!empty($iconImageData)) {
                if ($category['icon_image_path']) {
                    deleteImageFile($category['icon_image_path'], 'categories');
                }
                $iconImagePath = saveImageFromBase64($iconImageData, UPLOAD_DIR . '/categories', 200, 200, 70);
            }
            
            $bannerImageData = getImageData('bannerImage');
            $bannerImagePath = null;
            if (!empty($bannerImageData)) {
                if ($category['banner_image_path']) {
                    deleteImageFile($category['banner_image_path'], 'categories');
                }
                $bannerImagePath = saveImageFromBase64($bannerImageData, UPLOAD_DIR . '/categories', 1792, 1024, 80);
            }
            
            $sql = "UPDATE categories SET id = :id, name = :name, nameEn = :nameEn, 
                    icon = :icon, sortOrder = :sortOrder";
            $params = [
                ':id' => $newId,
                ':name' => getVal('name'),
                ':nameEn' => getVal('nameEn'),
                ':icon' => getVal('icon', 'fa-solid fa-tag'),
                ':sortOrder' => intval(getVal('sortOrder', 0)),
                ':oldId' => $oldId
            ];
            
            if ($iconImagePath !== null) {
                $sql .= ", icon_image_path = :icon_image_path";
                $params[':icon_image_path'] = $iconImagePath;
            }
            
            if ($bannerImagePath !== null) {
                $sql .= ", banner_image_path = :banner_image_path";
                $params[':banner_image_path'] = $bannerImagePath;
            }
            
            $sql .= " WHERE id = :oldId";
            
            if (execute($sql, $params)) {
                sendNotificationToAllUsers("📂 تحديث تصنيف", 'تم تحديث التصنيف: ' . getVal('name'), 'info', '/categories');
                response(true, 'تم تحديث التصنيف بنجاح');
            } else {
                response(false, 'فشل تحديث التصنيف');
            }
            break;
        
        // ===== Delete Category =====
        case 'deleteCategory':
            requireAdmin();
            
            $id = getVal('id');
            $category = queryOne("SELECT icon_image_path, banner_image_path FROM categories WHERE id = :id", [':id' => $id]);
            if ($category) {
                if ($category['icon_image_path']) deleteImageFile($category['icon_image_path'], 'categories');
                if ($category['banner_image_path']) deleteImageFile($category['banner_image_path'], 'categories');
            }
            if (execute("DELETE FROM categories WHERE id = :id", [':id' => $id])) {
                response(true, 'تم حذف التصنيف بنجاح');
            } else {
                response(false, 'فشل حذف التصنيف');
            }
            break;
        
        // ===== Request Password Reset =====
        case 'requestPasswordReset':
            $email = getVal('email');
            
            if (empty($email)) {
                response(false, 'الرجاء إدخال البريد الإلكتروني');
            }
            
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                response(false, 'البريد الإلكتروني غير صحيح');
            }
            
            $user = queryOne("SELECT id FROM users WHERE email = :email", [':email' => $email]);
            if (!$user) {
                response(false, 'لا يوجد حساب مرتبط بهذا البريد الإلكتروني');
            }
            
            execute("DELETE FROM password_resets WHERE email = :email", [':email' => $email]);
            
            // استخدام دالة توليد الكود لديك (أو rand إذا لم تكن موجودة)
            $code = function_exists('generateResetCode') ? generateResetCode() : rand(100000, 999999);
            $expires = date('Y-m-d H:i:s', strtotime('+10 minutes'));
            
            execute("INSERT INTO password_resets (email, code, expires_at) VALUES (:email, :code, :expires_at)", [
                ':email' => $email,
                ':code' => $code,
                ':expires_at' => $expires
            ]);
            
            // استخدام دالة الإرسال المتوافقة مع مشروعك (sendVerificationEmail التي قمنا بتجهيزها)
            $emailSent = sendVerificationEmail($email, $code);
            
            // التعامل مع النتيجة بناءً على ما تُرجعه دالة الإرسال
            if ($emailSent) {
                response(true, 'تم إرسال كود التحقق إلى بريدك الإلكتروني');
            } else {
                response(false, 'فشل إرسال كود التحقق، يرجى المحاولة لاحقاً');
            }
            break;
        // ===== Verify Reset Code =====
        case 'verifyResetCode':
            $email = getVal('email');
            $code = getVal('code');
            
            if (empty($email) || empty($code)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            
            $record = queryOne("SELECT * FROM password_resets WHERE email = :email AND code = :code AND used = 0", [
                ':email' => $email,
                ':code' => $code
            ]);
            
            if (!$record) {
                response(false, 'الكود غير صحيح أو منتهي الصلاحية');
            }
            
            if (strtotime($record['expires_at']) < time()) {
                response(false, 'انتهت صلاحية الكود، يرجى طلب كود جديد');
            }
            
            execute("UPDATE password_resets SET used = 1 WHERE id = :id", [':id' => $record['id']]);
            
            response(true, 'تم التحقق من الكود بنجاح');
            break;
        
       case 'resetPassword':
            $email = getVal('email');
            $code = getVal('code');
            $newPassword = getVal('new_password');
            
            if (empty($email) || empty($code) || empty($newPassword)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            
            if (strlen($newPassword) < 6) {
                response(false, 'كلمة المرور يجب أن تكون 6 أحرف على الأقل');
            }
            
            // التحقق من الكود والبريد بغض النظر عن حقل used لضمان قبوله مباشرة
            $record = queryOne("SELECT * FROM password_resets WHERE email = :email AND code = :code", [
                ':email' => $email,
                ':code' => $code
            ]);
            
            if (!$record) {
                response(false, 'كود التحقق غير صحيح أو انتهت صلاحيته');
            }
            
            // تحديث كلمة المرور للمستخدم بنجاح
            $password = password_hash($newPassword, PASSWORD_DEFAULT);
            execute("UPDATE users SET password = :password WHERE email = :email", [
                ':password' => $password,
                ':email' => $email
            ]);
            
            // حذف الكود بعد استخدامه بنجاح لكي لا يُستخدم مرة أخرى
            execute("DELETE FROM password_resets WHERE email = :email", [':email' => $email]);
            
            // إرسال إشعار للمستخدم (اختياري)
            $user = queryOne("SELECT id FROM users WHERE email = :email", [':email' => $email]);
            if ($user && function_exists('sendNotification')) {
                sendNotification($user['id'], "🔐 تم تغيير كلمة المرور", "تم تغيير كلمة المرور الخاصة بك بنجاح", 'info', '/login');
            }
            
            response(true, 'تم تغيير كلمة المرور بنجاح');
            break;
        // ===== Save Google OAuth Settings =====
        case 'saveGoogleOAuth':
            requireAdmin();
            
            $clientId = getVal('client_id');
            $clientSecret = getVal('client_secret');
            $enabled = getVal('enabled') === 'true' || getVal('enabled') === '1' ? true : false;
            
            if (empty($clientId) || empty($clientSecret)) {
                response(false, 'الرجاء تعبئة جميع الحقول');
            }
            
            if (!preg_match('/^[0-9]+-[a-zA-Z0-9_]+\.apps\.googleusercontent\.com$/', $clientId)) {
                response(false, 'Client ID غير صحيح');
            }
            
            $data = [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'enabled' => $enabled
            ];
            
            $value = json_encode($data, JSON_UNESCAPED_UNICODE);
            if (execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('google_oauth', :value)", [':value' => $value])) {
                $_SESSION['google_client_id'] = $clientId;
                $_SESSION['google_client_secret'] = $clientSecret;
                response(true, 'تم حفظ إعدادات Google OAuth');
            } else {
                response(false, 'فشل حفظ الإعدادات');
            }
            break;
        
        // ===== Save SMTP Settings =====
        case 'saveSMTPSettings':
            requireAdmin();
            
            $smtpData = [
                'host' => getVal('host'),
                'port' => intval(getVal('port', 465)),
                'username' => getVal('username'),
                'password' => getVal('password'),
                'encryption' => getVal('encryption', 'ssl'),
                'from_email' => getVal('from_email'),
                'from_name' => getVal('from_name', 'Tokmart'),
                'enabled' => getVal('enabled') === 'true' || getVal('enabled') === '1' ? true : false
            ];
            
            $value = json_encode($smtpData, JSON_UNESCAPED_UNICODE);
            if (execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('smtp', :value)", [':value' => $value])) {
                response(true, 'تم حفظ إعدادات البريد');
            } else {
                response(false, 'فشل حفظ الإعدادات');
            }
            break;
        
    case 'addOrderWithTransfer':
    if (!validateSession()) {
        response(false, 'يرجى تسجيل الدخول');
    }
    
    $userId = intval(getVal('userId', 0));
    $currentUserId = getSafeUserId();
    if ($userId != $currentUserId) {
        response(false, 'لا يمكنك إنشاء طلب باسم مستخدم آخر');
        break;
    }
    
    $address = trim(getVal('address', ''));
    $phone = trim(getVal('phone', ''));
    $payment = trim(getVal('payment', ''));
    
    // ✅ معالجة items بشكل صحيح
    $itemsRaw = isset($input['items']) ? $input['items'] : '[]';
    $items = array();
    
    if (is_string($itemsRaw)) {
        if (trim($itemsRaw) !== '' && trim($itemsRaw) !== 'null') {
            $decoded = json_decode($itemsRaw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $items = is_array($decoded) ? $decoded : array();
            } else {
                // محاولة التنظيف
                $cleanRaw = stripslashes(trim($itemsRaw));
                $decoded = json_decode($cleanRaw, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $items = is_array($decoded) ? $decoded : array();
                }
            }
        }
    } elseif (is_array($itemsRaw)) {
        $items = $itemsRaw;
    }
    
    if (!is_array($items)) {
        $items = array();
    }
    
    // ✅ حساب المجموع
    $calculatedTotal = 0;
    foreach ($items as $item) {
        if (is_array($item)) {
            $price = floatval(isset($item['price']) ? $item['price'] : 0);
            $qty = intval(isset($item['qty']) ? $item['qty'] : 1);
            $calculatedTotal += $price * $qty;
        }
    }
    
    $total = floatval(getVal('total', 0));
    if ($total <= 0 && $calculatedTotal > 0) {
        $total = $calculatedTotal;
    }
    
    // ✅ التحقق من البيانات
    if (empty($address) || empty($phone)) {
        response(false, 'الرجاء تعبئة جميع الحقول');
        break;
    }
    
    if (empty($items) || !is_array($items) || count($items) === 0) {
        response(false, 'السلة فارغة أو بيانات المنتجات غير صحيحة');
        break;
    }
    
    if ($total <= 0) {
        response(false, 'المجموع غير صحيح');
        break;
    }
    
    // ✅ معالجة الدفع
    $transferImage = '';
    
    if ($payment == 'electronic') {
        $user = queryOne("SELECT balance FROM users WHERE id = :id", array(':id' => $userId));
        if (!$user) {
            response(false, 'المستخدم غير موجود');
            break;
        }
        if ($user['balance'] < $total) {
            response(false, 'رصيدك غير كافي، يرجى شحن الرصيد');
            break;
        }
    } elseif ($payment == 'transfer' || $payment == 'bank_transfer') {
        if (!isset($_FILES['transferImage']) || $_FILES['transferImage']['error'] !== UPLOAD_ERR_OK) {
            response(false, 'الرجاء رفع صورة التحويل');
            break;
        }
        $file = $_FILES['transferImage'];
        $allowed = array('image/png', 'image/jpeg', 'image/webp', 'image/gif');
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowed)) {
            response(false, 'صيغة الملف غير مدعومة');
            break;
        }
        if ($file['size'] > 5 * 1024 * 1024) {
            response(false, 'حجم الصورة كبير جداً (الحد الأقصى 5MB)');
            break;
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $ext;
        $uploadPath = UPLOAD_DIR . '/transfers/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
            response(false, 'فشل حفظ صورة التحويل');
            break;
        }
        $transferImage = $filename;
    }
    
    $transferAmount = floatval(getVal('transferAmount', 0));
    $accountNumber = trim(getVal('accountNumber', ''));
    $beneficiary = trim(getVal('beneficiary', ''));
    
    // ✅ إنشاء رقم الطلب
    $maxId = queryOne("SELECT MAX(id) as max FROM orders");
    $newId = intval(isset($maxId['max']) ? $maxId['max'] : 0) + 1;
    $orderId = 'ORD-' . str_pad($newId, 4, '0', STR_PAD_LEFT);
    
    // ✅ تحويل المنتجات إلى JSON
    $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
    
    // ✅ حفظ الطلب
    $sql = "INSERT INTO orders (orderId, userId, address, phone, payment, items, total, 
            transferImage, transferAmount, accountNumber, beneficiary, status, date, createdAt) 
            VALUES (:orderId, :userId, :address, :phone, :payment, :items, :total, 
            :transferImage, :transferAmount, :accountNumber, :beneficiary, 'pending', :date, datetime('now', 'localtime'))";
    
    $params = array(
        ':orderId' => $orderId,
        ':userId' => $userId,
        ':address' => $address,
        ':phone' => $phone,
        ':payment' => $payment,
        ':items' => $itemsJson,
        ':total' => $total,
        ':transferImage' => $transferImage,
        ':transferAmount' => $transferAmount,
        ':accountNumber' => $accountNumber,
        ':beneficiary' => $beneficiary,
        ':date' => date('Y-m-d')
    );
    
    // ✅ تنفيذ الاستعلام
    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $result = $stmt->execute();
        
        if ($result) {
            $id = $db->lastInsertRowID();
            
            // ✅ معالجة الدفع الإلكتروني
            if ($payment == 'electronic') {
                execute("UPDATE users SET balance = balance - :total WHERE id = :id", array(
                    ':total' => $total,
                    ':id' => $userId
                ));
                sendNotification($userId, "💰 خصم رصيد", "تم خصم " . number_format($total, 2) . " د.ع من رصيدك للطلب #$orderId", 'wallet', '/order/' . $id);
            }
            
            // ✅ إرسال إشعارات
            $paymentName = $payment == 'cash' ? 'الدفع عند الاستلام' : ($payment == 'electronic' ? 'الدفع الإلكتروني' : 'تحويل بنكي');
            sendNotificationToAllUsers("📋 طلب جديد: $orderId", "طريقة الدفع: $paymentName - المبلغ: " . number_format($total, 2) . " د.ع", 'order', '/admin?tab=orders');
            
            // ✅ جلب الطلب
            $order = queryOne("SELECT * FROM orders WHERE id = :id", array(':id' => $id));
            
            if ($order) {
                if (isset($order['items'])) {
                    $decodedItems = json_decode($order['items'], true);
                    if (is_array($decodedItems)) {
                        $order['items'] = $decodedItems;
                    } else {
                        $order['items'] = array();
                    }
                } else {
                    $order['items'] = array();
                }
                
                if (!empty($order['transferImage'])) {
                    $order['transferImage_url'] = SITE_URL . '/data/uploads/transfers/' . $order['transferImage'];
                }
            }
            
            response(true, 'تم إنشاء الطلب بنجاح', $order);
        } else {
            response(false, 'فشل إنشاء الطلب - خطأ في قاعدة البيانات');
        }
    } catch (Exception $e) {
        error_log("❌ خطأ في إنشاء الطلب: " . $e->getMessage());
        response(false, 'خطأ في قاعدة البيانات: ' . $e->getMessage());
    }
    break;
        
case 'updateOrderStatus':
    requireAdmin();
    
    $id = intval(getVal('id', 0));
    $status = getVal('status', 'pending');
    $order = queryOne("SELECT * FROM orders WHERE id = :id", [':id' => $id]);
    
    if (!$order) {
        response(false, 'الطلب غير موجود');
        break;
    }
    
    $oldStatus = $order['status'];
    $isRefunded = isset($order['refunded']) ? intval($order['refunded']) : 0;
    $payment = $order['payment'] ?? '';
    $userId = intval($order['userId']);
    $total = floatval($order['total']);
    
    // ✅ سجل للتصحيح
    error_log("📝 محاولة تحديث الطلب #{$order['orderId']}: oldStatus=$oldStatus, newStatus=$status, payment=$payment, refunded=$isRefunded, userId=$userId, total=$total");
    
    // ✅ شرط استرداد الرصيد
    $shouldRefund = false;
    $refundMessage = '';
    
    if ($status === 'cancelled' && $oldStatus !== 'cancelled' && $payment === 'electronic' && $isRefunded == 0) {
        $shouldRefund = true;
        $refundMessage = "✅ سيتم استرداد المبلغ";
        error_log("💰 سيتم استرداد المبلغ للطلب #{$order['orderId']}");
    } else {
        if ($status === 'cancelled' && $oldStatus !== 'cancelled') {
            if ($payment !== 'electronic') {
                $refundMessage = "⚠️ طريقة الدفع ليست إلكترونية، لا يوجد استرداد";
            } elseif ($isRefunded == 1) {
                $refundMessage = "⚠️ تم استرداد الرصيد سابقاً، لا يمكن الاسترداد مرة أخرى";
            }
            error_log("❌ لا يوجد استرداد: $refundMessage");
        }
    }
    
    // ✅ تحديث حالة الطلب أولاً
    if (execute("UPDATE orders SET status = :status WHERE id = :id", 
               [':status' => $status, ':id' => $id])) {
        
        // ✅ إذا كان هناك استرداد
        if ($shouldRefund && $total > 0 && $userId > 0) {
            // ✅ استرداد المبلغ إلى رصيد المستخدم
            $updateBalance = execute("UPDATE users SET balance = balance + :amount WHERE id = :id", [
                ':amount' => $total,
                ':id' => $userId
            ]);
            
            if ($updateBalance) {
                // ✅ تحديث حالة استرداد الرصيد
                execute("UPDATE orders SET refunded = 1 WHERE id = :id", [':id' => $id]);
                error_log("✅ تم استرداد $total د.ع للمستخدم $userId من الطلب #{$order['orderId']}");
                
                // ✅ إشعار استرداد الرصيد
                sendNotification($userId, "💰 تم استرداد الرصيد", "تم استرداد مبلغ " . number_format($total, 2) . " د.ع إلى رصيدك بسبب إلغاء الطلب #{$order['orderId']}", 'wallet', '/account');
            } else {
                error_log("❌ فشل استرداد المبلغ للمستخدم $userId");
            }
        }
        
        // ✅ إشعار تحديث حالة الطلب
        $statusText = [
            'pending' => 'قيد المعالجة', 
            'shipped' => 'تم الشحن', 
            'completed' => 'مكتمل', 
            'cancelled' => 'ملغي',
            'approved' => 'تم الموافقة',
            'rejected' => 'مرفوض'
        ];
        
        if ($userId > 0 && isset($statusText[$status])) {
            $message = "تم تحديث حالة طلبك #{$order['orderId']} إلى: " . $statusText[$status];
            if ($status == 'approved') {
                $message = "✅ تم الموافقة على طلبك #{$order['orderId']}";
            } elseif ($status == 'rejected') {
                $message = "❌ تم رفض طلبك #{$order['orderId']}";
            } elseif ($status == 'cancelled') {
                $message = "❌ تم إلغاء طلبك #{$order['orderId']}";
                if ($shouldRefund) {
                    $message .= "\n💰 تم استرداد المبلغ إلى رصيدك (مرة واحدة فقط)";
                } elseif ($payment === 'electronic' && $isRefunded == 0) {
                    // حالة استثنائية: إلكتروني ولكن لم يُسترد (قد يكون هناك خطأ)
                    error_log("⚠️ تنبيه: طلب إلكتروني ملغي ولكن لم يتم استرداد المبلغ! orderId={$order['orderId']}");
                }
            }
            sendNotification($userId, "📦 تحديث حالة الطلب", $message, 'order', '/order/' . $id);
        }
        
        response(true, 'تم تحديث حالة الطلب');
    } else {
        response(false, 'فشل تحديث حالة الطلب');
    }
    break;
        case 'getUserChatMessages':
    if (!validateSession()) {
        response(false, 'يرجى تسجيل الدخول');
    }
    
    $userId = intval(getVal('userId', getSafeUserId()));
    
    // جلب كافة رسائل المحادثة مرتبة تصاعدياً حسب الوقت لكي تظهر بالترتيب الصحيح
    $messages = query("SELECT * FROM chats WHERE userId = :userId ORDER BY createdAt ASC", [
        ':userId' => $userId
    ]);
    
    response(true, 'تم جلب الرسائل بنجاح', $messages);
    break;
        // ===== Get Data =====
       case 'getData':
    // إزالة شرط تسجيل الدخول للسماح بعرض المنتجات
    // if (!validateSession()) {
    //     response(false, 'يرجى تسجيل الدخول');
    // }
    
    // التحقق من الجلسة ولكن لا نمنع الوصول
    $isLoggedIn = isset($_SESSION['user_id']);
    $userId = $isLoggedIn ? $_SESSION['user_id'] : 0;
            
            $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
            $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
            $userId = getSafeUserId();
            
            $products = query("SELECT * FROM products ORDER BY id DESC LIMIT $limit OFFSET $offset");
            $categories = query("SELECT * FROM categories ORDER BY sortOrder ASC");
            
            // ✅ جلب جميع الطلبات (للمدير فقط)
if ($isLoggedIn && isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1) {
    // المدير: يشاهد جميع الطلبات
    $orders = query("SELECT * FROM orders ORDER BY id DESC LIMIT 50");
} else {
    // المستخدم العادي: يشاهد طلباته فقط
    $orders = query("SELECT * FROM orders WHERE userId = :userId ORDER BY id DESC LIMIT 10", [':userId' => $userId]);
}
            
            $users = query("SELECT id, name, email, phone, balance, isAdmin, adminType, avatar_path, registeredAt, lastActivity, isVerified FROM users");
            
            $payments = query("SELECT * FROM payments WHERE enabled = 1");
            
            $notifications = query("SELECT * FROM notifications WHERE userId = :userId ORDER BY id DESC LIMIT 20", [':userId' => $userId]);
            
            $chats = query("SELECT * FROM chats WHERE userId = :userId ORDER BY id DESC LIMIT 50", [':userId' => $userId]);
            
            $recharges = query("SELECT * FROM balance_recharges WHERE userId = :userId ORDER BY id DESC LIMIT 20", [':userId' => $userId]);
            
            $settings = [];
            $settingsData = query("SELECT * FROM settings");
            foreach ($settingsData as $row) {
                $settings[$row['key']] = json_decode($row['value'], true);
            }
            
            $unreadNotif = queryOne("SELECT COUNT(*) as count FROM notifications WHERE userId = :userId AND isRead = 0", [':userId' => $userId]);
            
            $flashDeals = query("SELECT * FROM products WHERE isFlashDeal = 1 AND oldPrice > price ORDER BY id DESC LIMIT 10");
            $bestSellers = query("SELECT * FROM products WHERE isBestSeller = 1 ORDER BY orderCount DESC LIMIT 10");
            $newArrivals = query("SELECT * FROM products WHERE isNew = 1 ORDER BY id DESC LIMIT 10");
            $featured = query("SELECT * FROM products WHERE isFeatured = 1 ORDER BY id DESC LIMIT 10");
            
            foreach (['products', 'flashDeals', 'bestSellers', 'newArrivals', 'featured'] as $key) {
                if (isset($$key)) {
                    foreach ($$key as &$p) {
                        $p['image_url'] = $p['image_path'] ? SITE_URL . '/data/uploads/products/' . $p['image_path'] : '';
                        $p['images'] = json_decode($p['images'] ?? '[]', true);
                    }
                }
            }
            
            foreach ($categories as &$c) {
                $c['icon_image_url'] = $c['icon_image_path'] ? SITE_URL . '/data/uploads/categories/' . $c['icon_image_path'] : '';
                $c['banner_image_url'] = $c['banner_image_path'] ? SITE_URL . '/data/uploads/categories/' . $c['banner_image_path'] : '';
                $c['products'] = query("SELECT * FROM products WHERE category = :category ORDER BY id DESC LIMIT 10", 
                    [':category' => $c['id']]);
                foreach ($c['products'] as &$p) {
                    $p['image_url'] = $p['image_path'] ? SITE_URL . '/data/uploads/products/' . $p['image_path'] : '';
                }
            }
            
            foreach ($users as &$u) {
                $u['avatar_url'] = $u['avatar_path'] ? SITE_URL . '/data/uploads/avatars/' . $u['avatar_path'] : '';
                $u['unread_chats'] = queryOne("SELECT COUNT(*) as count FROM chats WHERE userId = :userId AND isRead = 0", 
                    [':userId' => $u['id']])['count'] ?? 0;
                $status = getUserStatus($u['id']);
                $u['status'] = $status['status'];
                $u['status_text'] = $status['text'];
            }
            
            response(true, 'تم جلب البيانات', [
                'products' => $products,
                'categories' => $categories,
                'orders' => $orders,
                'users' => $users,
                'payments' => $payments,
                'notifications' => $notifications,
                'chats' => $chats,
                'recharges' => $recharges,
                'settings' => $settings,
                'siteUrl' => SITE_URL,
                'unreadCount' => $unreadNotif['count'] ?? 0,
                'flashDeals' => $flashDeals,
                'bestSellers' => $bestSellers,
                'newArrivals' => $newArrivals,
                'featured' => $featured,
                'totalProducts' => queryOne("SELECT COUNT(*) as count FROM products")['count'] ?? 0,
                'serverTime' => time(),
                'user' => queryOne("SELECT id, name, email, phone, balance, isAdmin, adminType, avatar_path FROM users WHERE id = :id", [':id' => $userId])
            ]);
            break;
        
        // ===== Export Data =====
        case 'exportData':
            requireAdmin();
            
            $data = [
                'products' => query("SELECT * FROM products"),
                'categories' => query("SELECT * FROM categories"),
                'orders' => query("SELECT * FROM orders"),
                'users' => query("SELECT id, name, email, phone, balance, isAdmin, adminType, avatar_path, registeredAt FROM users"),
                'payments' => query("SELECT * FROM payments"),
                'chats' => query("SELECT * FROM chats"),
                'exportedAt' => date('Y-m-d H:i:s')
            ];
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="Tokmart_data_' . date('Y-m-d') . '.json"');
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
            break;
        
        // ===== Delete User =====
        case 'deleteUser':
            requireAdmin();
            
            $id = intval(getVal('id', 0));
            if ($id == 1) {
                response(false, 'لا يمكن حذف المستخدم الرئيسي');
                break;
            }
            if (execute("DELETE FROM users WHERE id = :id", [':id' => $id])) {
                response(true, 'تم حذف المستخدم بنجاح');
            } else {
                response(false, 'فشل حذف المستخدم');
            }
            break;
        
        // ===== Get Admin Chats =====
        case 'getAdminChats':
            requireAdmin();
            
            $adminId = getSafeUserId();
            
            $chats = query("
                SELECT 
                    u.id,
                    u.name,
                    u.avatar_path,
                    u.isAdmin,
                    c.message as lastMessage,
                    c.createdAt as lastTime,
                    c.id as lastChatId,
                    c.messageType as lastMessageType,
                    c.fileData as lastFileData,
                    (
                        SELECT COUNT(*) 
                        FROM chats c2 
                        WHERE c2.userId = u.id 
                        AND c2.adminId = :adminId 
                        AND c2.sender = 'user' 
                        AND c2.isRead = 0
                    ) as unreadCount
                FROM users u
                LEFT JOIN (
                    SELECT userId, message, createdAt, id, adminId, messageType, fileData
                    FROM chats 
                    WHERE adminId = :adminId
                    ORDER BY id DESC 
                    LIMIT 1
                ) c ON c.userId = u.id AND c.adminId = :adminId
                WHERE u.id IN (
                    SELECT DISTINCT userId 
                    FROM chats 
                    WHERE adminId = :adminId
                )
                AND u.id != :adminId
                AND u.isAdmin = 0
                ORDER BY c.id DESC
            ", [':adminId' => $adminId]);
            
            if (empty($chats)) {
                $allUsers = query(
                    "SELECT id, name, avatar_path, isAdmin 
                     FROM users 
                     WHERE id != :adminId 
                     AND isAdmin = 0
                     ORDER BY id ASC",
                    [':adminId' => $adminId]
                );
                foreach ($allUsers as &$u) {
                    $u['lastMessage'] = 'لا توجد رسائل';
                    $u['lastTime'] = null;
                    $u['unreadCount'] = 0;
                    $u['lastMessageType'] = 'text';
                    $u['lastFileData'] = '';
                }
                $chats = $allUsers;
            }
            
            response(true, 'تم جلب المحادثات', $chats);
            break;
        
        // ===== Get Chat Messages =====
        case 'getChatMessages':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            
            $userId = intval(getVal('userId', 0));
            $adminId = intval(getVal('adminId', 0));
            $currentUserId = getSafeUserId();
            $isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
            
            if ($isAdmin) {
                if ($adminId != $currentUserId) {
                    response(false, 'غير مصرح لك بعرض هذه الرسائل');
                }
            } else {
                if ($userId != $currentUserId) {
                    response(false, 'غير مصرح لك بعرض هذه الرسائل');
                }
            }
            
            $messages = query(
                "SELECT * FROM chats 
                 WHERE userId = :userId AND adminId = :adminId
                 ORDER BY id ASC LIMIT 500",
                [':userId' => $userId, ':adminId' => $adminId]
            );
            
            $db->exec('BEGIN TRANSACTION');
            try {
                if ($isAdmin) {
                    execute(
                        "UPDATE chats SET isRead = 1, readAt = datetime('now', 'localtime') 
                         WHERE userId = :userId AND sender = 'user' AND isRead = 0",
                        [':userId' => $userId]
                    );
                } else {
                    execute(
                        "UPDATE chats SET isRead = 1, readAt = datetime('now', 'localtime') 
                         WHERE userId = :userId AND sender = 'admin' AND isRead = 0",
                        [':userId' => $userId]
                    );
                }
                $db->exec('COMMIT');
            } catch (Exception $e) {
                $db->exec('ROLLBACK');
                error_log("Error updating read status: " . $e->getMessage());
            }
            
            response(true, 'تم جلب الرسائل', $messages);
            break;
        
        // ===== Send Chat Message =====
        case 'sendChatMessage':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            
            $userId = intval(getVal('userId', 0));
            $adminId = intval(getVal('adminId', 0));
            $message = trim(getVal('message', ''));
            $messageType = getVal('messageType', 'text');
            $sender = getVal('sender', 'user');
            
            if (empty($message)) {
                response(false, 'الرسالة فارغة');
                break;
            }
            
            if (strlen($message) > 5000) {
                response(false, 'الرسالة طويلة جداً (الحد الأقصى 5000 حرف)');
                break;
            }
            
            $isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
            $currentUserId = getSafeUserId();
            
            if (!$isAdmin && $sender != 'user') {
                response(false, 'غير مصرح لك بإرسال رسائل كأدمن');
                break;
            }
            if ($isAdmin && $sender != 'admin') {
                response(false, 'غير مصرح لك بإرسال رسائل كمستخدم');
                break;
            }
            
            if (!$isAdmin) {
                if ($userId != $currentUserId) {
                    response(false, 'لا يمكنك إرسال رسائل باسم مستخدم آخر');
                    break;
                }
                if ($adminId == 0) {
                    $adminId = 1;
                }
            } else {
                if ($adminId != $currentUserId) {
                    response(false, 'لا يمكنك إرسال رسائل كأدمن آخر');
                    break;
                }
                if ($userId == 0) {
                    response(false, 'يرجى تحديد المستخدم المستلم');
                    break;
                }
            }
            
            if ($isAdmin) {
                $userExists = queryOne("SELECT id, isAdmin FROM users WHERE id = :id", [':id' => $userId]);
                if (!$userExists) {
                    response(false, 'المستخدم غير موجود');
                    break;
                }
                if ($userExists['isAdmin'] == 1) {
                    response(false, 'لا يمكن إرسال رسائل لأدمن آخر');
                    break;
                }
            }
            
            $adminExists = queryOne("SELECT id FROM users WHERE id = :id AND isAdmin = 1", [':id' => $adminId]);
            
            if (!$adminExists) {
                response(false, 'الأدمن غير موجود');
                break;
            }
            
            $db->exec('BEGIN TRANSACTION');
            try {
                $sql = "INSERT INTO chats (userId, adminId, message, messageType, sender, isRead, createdAt) 
                        VALUES (:userId, :adminId, :message, :messageType, :sender, 0, datetime('now', 'localtime'))";
                
                if (execute($sql, [
                    ':userId' => $userId,
                    ':adminId' => $adminId,
                    ':message' => $message,
                    ':messageType' => $messageType,
                    ':sender' => $sender
                ])) {
                    $id = getLastInsertId();
                    $msg = queryOne("SELECT * FROM chats WHERE id = :id", [':id' => $id]);
                    
                    if ($sender == 'user') {
                        $userName = $_SESSION['user_name'] ?? 'مستخدم';
                        sendNotification(
                            $adminId, 
                            "💬 رسالة جديدة من " . $userName, 
                            $message, 
                            'chat', 
                            '/admin?tab=chats'
                        );
                    } else {
                        sendNotification(
                            $userId, 
                            "💬 رد من الدعم الفني", 
                            $message, 
                            'chat', 
                            '/chat'
                        );
                    }
                    
                    execute("UPDATE users SET lastActivity = datetime('now', 'localtime') WHERE id = :id", 
                           [':id' => $currentUserId]);
                    
                    $db->exec('COMMIT');
                    response(true, 'تم إرسال الرسالة', $msg);
                } else {
                    $db->exec('ROLLBACK');
                    response(false, 'فشل إرسال الرسالة');
                }
            } catch (Exception $e) {
                $db->exec('ROLLBACK');
                error_log("Error sending message: " . $e->getMessage());
                response(false, 'حدث خطأ أثناء إرسال الرسالة');
            }
            break;
        
        // ===== Send Order Support Message =====
        case 'sendOrderSupportMessage':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            
            $userId = getSafeUserId();
            $orderId = getVal('orderId');
            $message = getVal('message');
            
            if (empty($orderId) || empty($message)) {
                response(false, 'بيانات غير مكتملة');
            }
            
            $order = queryOne("SELECT * FROM orders WHERE orderId = :orderId AND userId = :userId", [
                ':orderId' => $orderId,
                ':userId' => $userId
            ]);
            
            if (!$order) {
                response(false, 'الطلب غير موجود');
            }
            
            $adminId = 1;
            $sql = "INSERT INTO chats (userId, adminId, message, messageType, sender, isRead, createdAt) 
                    VALUES (:userId, :adminId, :message, 'text', 'user', 0, datetime('now', 'localtime'))";
            
            if (execute($sql, [
                ':userId' => $userId,
                ':adminId' => $adminId,
                ':message' => $message
            ])) {
                sendNotification($adminId, "💬 رسالة دعم حول الطلب", $message, 'chat', '/admin?tab=chats');
                response(true, 'تم إرسال رسالتك');
            } else {
                response(false, 'فشل إرسال الرسالة');
            }
            break;
        
        // ===== Request Recharge =====
        case 'requestRecharge':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            
            $userId = getSafeUserId();
            $amount = floatval(getVal('amount', 0));
            $paymentMethod = getVal('paymentMethod');
            $receiptImage = '';
            
            if (empty($paymentMethod)) {
                response(false, 'الرجاء اختيار طريقة الدفع');
            }
            if ($amount < 1000) {
                response(false, 'الحد الأدنى للشحن هو 1000 دينار عراقي');
            }
            
            
            if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['receipt'];
                $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
                $mime = mime_content_type($file['tmp_name']);
                if (!in_array($mime, $allowed)) {
                    response(false, 'صيغة الملف غير مدعومة');
                }
                if ($file['size'] > 5 * 1024 * 1024) {
                    response(false, 'حجم الصورة كبير جداً (الحد الأقصى 5MB)');
                }
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = uniqid() . '.' . $ext;
                $uploadPath = UPLOAD_DIR . '/receipts/' . $filename;
                if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    response(false, 'فشل حفظ صورة الإيصال');
                }
                $receiptImage = $filename;
            } else {
                response(false, 'الرجاء رفع صورة الإيصال');
            }
            
            $sql = "INSERT INTO balance_recharges (userId, amount, paymentMethod, receiptImage, status, createdAt) 
                    VALUES (:userId, :amount, :paymentMethod, :receiptImage, 'pending', datetime('now', 'localtime'))";
            
            if (execute($sql, [
                ':userId' => $userId,
                ':amount' => $amount,
                ':paymentMethod' => $paymentMethod,
                ':receiptImage' => $receiptImage
            ])) {
                $id = getLastInsertId();
                sendNotification(0, "💰 طلب شحن رصيد جديد", "المبلغ: " . number_format($amount, 2) . " د.ع", 'wallet', '/admin?tab=recharges');
                response(true, 'تم إرسال طلب الشحن بنجاح');
            } else {
                response(false, 'فشل إرسال الطلب');
            }
            break;
        
        // ===== Get Recharges =====
        case 'getRecharges':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            
            $userId = getSafeUserId();
            $recharges = query("SELECT * FROM balance_recharges WHERE userId = :userId ORDER BY id DESC", [':userId' => $userId]);
            response(true, 'تم جلب سجل الشحن', $recharges);
            break;
        
        // ===== Update Recharge Status =====
        case 'updateRechargeStatus':
            requireAdmin();
            
            $id = intval(getVal('id', 0));
            $status = getVal('status', 'pending');
            
            $recharge = queryOne("SELECT * FROM balance_recharges WHERE id = :id", [':id' => $id]);
            if (!$recharge) {
                response(false, 'طلب الشحن غير موجود');
            }
            
            execute("UPDATE balance_recharges SET status = :status, updatedAt = datetime('now', 'localtime') WHERE id = :id", [
                ':status' => $status,
                ':id' => $id
            ]);
            
            if ($status == 'approved') {
                execute("UPDATE users SET balance = balance + :amount WHERE id = :id", [
                    ':amount' => $recharge['amount'],
                    ':id' => $recharge['userId']
                ]);
                sendNotification($recharge['userId'], "💰 تم شحن الرصيد", "تم إضافة " . number_format($recharge['amount'], 2) . " د.ع إلى رصيدك", 'wallet', '/account');
            } elseif ($status == 'rejected') {
                sendNotification($recharge['userId'], "❌ تم رفض طلب الشحن", "تم رفض طلب شحن الرصيد الخاص بك", 'wallet', '/account');
            }
            
            response(true, 'تم تحديث حالة طلب الشحن');
            break;
        
        // ===== Mark All Notifications Read =====
        case 'markAllNotificationsRead':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            $userId = getSafeUserId();
            execute("UPDATE notifications SET isRead = 1 WHERE userId = :userId", [':userId' => $userId]);
            response(true, 'تم تحديث جميع الإشعارات كمقروءة');
            break;
        
        // ===== Delete All Notifications =====
        case 'deleteAllNotifications':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            $userId = getSafeUserId();
            execute("DELETE FROM notifications WHERE userId = :userId", [':userId' => $userId]);
            response(true, 'تم حذف جميع الإشعارات');
            break;
        
        // ===== Delete Notification =====
        case 'deleteNotification':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            $id = intval(getVal('id', 0));
            $userId = getSafeUserId();
            execute("DELETE FROM notifications WHERE id = :id AND userId = :userId", [':id' => $id, ':userId' => $userId]);
            response(true, 'تم حذف الإشعار');
            break;
        
        // ===== Mark Notification Read =====
        case 'markNotificationRead':
            if (!validateSession()) {
                response(false, 'يرجى تسجيل الدخول');
            }
            $id = intval(getVal('id', 0));
            $userId = getSafeUserId();
            execute("UPDATE notifications SET isRead = 1 WHERE id = :id AND userId = :userId", [':id' => $id, ':userId' => $userId]);
            response(true, 'تم تحديث الإشعار كمقروء');
            break;
        
        // ===== Add Payment =====
        case 'addPayment':
            requireAdmin();
            
            $id = getVal('id');
            $imageData = getImageData('image');
            $imagePath = '';
            if (!empty($imageData)) {
                $imagePath = saveImageFromBase64($imageData, UPLOAD_DIR . '/payments', 200, 200, 70);
            }
            
            if (queryOne("SELECT * FROM payments WHERE id = :id", [':id' => $id])) {
                response(false, 'هذا المعرف موجود بالفعل');
                break;
            }
            
            $sql = "INSERT INTO payments (id, name, nameEn, icon, image_path, enabled, isRechargeOnly, accountNumber, beneficiary) 
                    VALUES (:id, :name, :nameEn, :icon, :image_path, 1, :isRechargeOnly, :accountNumber, :beneficiary)";
            
            if (execute($sql, [
                ':id' => $id,
                ':name' => getVal('name'),
                ':nameEn' => getVal('nameEn'),
                ':icon' => getVal('icon', 'fa-solid fa-credit-card'),
                ':image_path' => $imagePath,
                ':isRechargeOnly' => getVal('isRechargeOnly') === 'true' || getVal('isRechargeOnly') === '1' ? 1 : 0,
                ':accountNumber' => getVal('accountNumber'),
                ':beneficiary' => getVal('beneficiary')
            ])) {
                response(true, 'تم إضافة طريقة الدفع');
            } else {
                response(false, 'فشل إضافة طريقة الدفع');
            }
            break;
        
        // ===== Update Payment =====
        case 'updatePayment':
            requireAdmin();
            
            $oldId = getVal('oldId');
            $imageData = getImageData('image');
            $imagePath = null;
            
            if (!empty($imageData)) {
                $oldPayment = queryOne("SELECT image_path FROM payments WHERE id = :id", [':id' => $oldId]);
                if ($oldPayment && $oldPayment['image_path']) {
                    deleteImageFile($oldPayment['image_path'], 'payments');
                }
                $imagePath = saveImageFromBase64($imageData, UPLOAD_DIR . '/payments', 200, 200, 70);
            }
            
            $sql = "UPDATE payments SET id = :id, name = :name, nameEn = :nameEn, 
                    icon = :icon, accountNumber = :accountNumber, beneficiary = :beneficiary, 
                    isRechargeOnly = :isRechargeOnly";
            
            $params = [
                ':id' => getVal('id'),
                ':name' => getVal('name'),
                ':nameEn' => getVal('nameEn'),
                ':icon' => getVal('icon', 'fa-solid fa-credit-card'),
                ':accountNumber' => getVal('accountNumber'),
                ':beneficiary' => getVal('beneficiary'),
                ':isRechargeOnly' => getVal('isRechargeOnly') === 'true' || getVal('isRechargeOnly') === '1' ? 1 : 0,
                ':oldId' => $oldId
            ];
            
            if ($imagePath !== null) {
                $sql .= ", image_path = :image_path";
                $params[':image_path'] = $imagePath;
            }
            
            $sql .= " WHERE id = :oldId";
            
            if (execute($sql, $params)) {
                response(true, 'تم تحديث طريقة الدفع');
            } else {
                response(false, 'فشل تحديث طريقة الدفع');
            }
            break;
        
        // ===== Delete Payment =====
        case 'deletePayment':
            requireAdmin();
            
            $id = getVal('id');
            if ($id == 'cash' || $id == 'electronic') {
                response(false, 'لا يمكن حذف طريقة الدفع الأساسية');
                break;
            }
            $payment = queryOne("SELECT image_path FROM payments WHERE id = :id", [':id' => $id]);
            if ($payment && $payment['image_path']) {
                deleteImageFile($payment['image_path'], 'payments');
            }
            if (execute("DELETE FROM payments WHERE id = :id", [':id' => $id])) {
                response(true, 'تم حذف طريقة الدفع');
            } else {
                response(false, 'فشل حذف طريقة الدفع');
            }
            break;
        
        // ===== Toggle Payment =====
        case 'togglePayment':
            requireAdmin();
            
            $id = getVal('id');
            $payment = queryOne("SELECT * FROM payments WHERE id = :id", [':id' => $id]);
            if ($payment) {
                $newStatus = $payment['enabled'] ? 0 : 1;
                if (execute("UPDATE payments SET enabled = :enabled WHERE id = :id", 
                           [':enabled' => $newStatus, ':id' => $id])) {
                    response(true, 'تم تحديث حالة طريقة الدفع');
                } else {
                    response(false, 'فشل تحديث حالة طريقة الدفع');
                }
            } else {
                response(false, 'طريقة الدفع غير موجودة');
            }
            break;
        
        // ===== Get Site Data =====
        case 'getSiteData':
            $siteSettings = queryOne("SELECT value FROM settings WHERE key = 'site'");
            $site = $siteSettings ? json_decode($siteSettings['value'], true) : ['name' => 'Tokmart', 'description' => '', 'logo' => '', 'slogan' => '🛍️ متجرك الإلكتروني المفضل'];
            if (!empty($site['logo'])) {
                $site['logo_url'] = SITE_URL . '/data/uploads/site/' . $site['logo'];
            }
            response(true, 'تم جلب بيانات الموقع', ['site' => $site]);
            break;
        
        // ===== Save Site Settings =====
        case 'saveSiteSettings':
            requireAdmin();
            
            $name = getVal('name', 'Tokmart');
            $description = getVal('description', '');
            $slogan = getVal('slogan', '🛍️ متجرك الإلكتروني المفضل');
            $logoData = getImageData('logo');
            $logoPath = null;
            
            if (!empty($logoData)) {
                $oldSettings = queryOne("SELECT value FROM settings WHERE key = 'site'");
                if ($oldSettings) {
                    $oldData = json_decode($oldSettings['value'], true);
                    if (!empty($oldData['logo'])) {
                        deleteImageFile($oldData['logo'], 'site');
                    }
                }
                $logoPath = saveImageFromBase64($logoData, UPLOAD_DIR . '/site', 200, 200, 70);
            }
            
            $siteData = [
                'name' => $name,
                'description' => $description,
                'logo' => $logoPath,
                'slogan' => $slogan
            ];
            $value = json_encode($siteData, JSON_UNESCAPED_UNICODE);
            
            if (execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('site', :value)", [':value' => $value])) {
                response(true, 'تم حفظ إعدادات الموقع', $siteData);
            } else {
                response(false, 'فشل حفظ الإعدادات');
            }
            break;
        
        // ===== Save Policy =====
        case 'savePolicy':
            requireAdmin();
            
            $type = getVal('type');
            $content = getVal('content');
            
            $siteSettings = queryOne("SELECT value FROM settings WHERE key = 'site'");
            $siteData = $siteSettings ? json_decode($siteSettings['value'], true) : [];
            
            if ($type == 'privacy') {
                $siteData['privacy_policy'] = $content;
            } elseif ($type == 'terms') {
                $siteData['terms_conditions'] = $content;
            } else {
                response(false, 'نوع غير معروف');
            }
            
            $value = json_encode($siteData, JSON_UNESCAPED_UNICODE);
            if (execute("INSERT OR REPLACE INTO settings (key, value) VALUES ('site', :value)", [':value' => $value])) {
                response(true, 'تم حفظ الإعدادات');
            } else {
                response(false, 'فشل حفظ الإعدادات');
            }
            break;
        
        default:
            response(false, 'إجراء غير معروف');
    }
    exit;
}

// ============================================================
// الحصول على إعدادات الموقع
// ============================================================
$siteSettings = queryOne("SELECT value FROM settings WHERE key = 'site'");
$siteData = $siteSettings ? json_decode($siteSettings['value'], true) : [
    'name' => 'Tokmart', 
    'description' => '', 
    'logo' => '', 
    'slogan' => '🛍️ متجرك الإلكتروني المفضل',
    'privacy_policy' => 'سياسة الخصوصية الخاصة بمتجر Tokmart...',
    'terms_conditions' => 'الشروط والأحكام الخاصة بمتجر Tokmart...',
    'app_version' => '1.0.0'
];
$siteName = $siteData['name'] ?? 'Tokmart';
$siteDescription = $siteData['description'] ?? '';
$siteLogo = $siteData['logo'] ?? '';
$siteSlogan = $siteData['slogan'] ?? '🛍️ متجرك الإلكتروني المفضل';
$siteLogoUrl = $siteLogo ? SITE_URL . '/data/uploads/site/' . $siteLogo : '';
$privacyPolicy = $siteData['privacy_policy'] ?? 'سياسة الخصوصية - Tokmart

نلتزم بحماية خصوصية بياناتك.

١. المعلومات التي نجمعها: الاسم، البريد، الهاتف، العنوان، بيانات الدفع، سجل التصفح.

٢. استخدام المعلومات: تنفيذ الطلبات، تحسين الخدمة، إرسال الإشعارات، الدعم الفني.

٣. مشاركة المعلومات: فقط مع شركات الشحن والجهات القانونية عند الضرورة.

٤. أمان البيانات: تشفير SSL، تخزين آمن، مراقبة دورية.

٥. حقوقك: الوصول، التعديل، حذف الحساب، إلغاء الاشتراك.

٦. ملفات تعريف الارتباط: لتحسين التجربة وتذكر التفضيلات.

٧. تحديثات السياسة: سيتم إعلامك بأي تغييرات جوهرية.

٨. التواصل: support@tokmart.com | الدردشة المباشرة 24/7.

آخر تحديث: يوليو 2026';
$termsConditions = $siteData['terms_conditions'] ?? ' الشروط والأحكام - Tokmart

باستخدامك للمنصة توافق على هذه الشروط.

١. الحساب: عمر 18+، معلومات صحيحة، مسؤولية الحفاظ على السرية.

٢. المنتجات: أوصاف دقيقة، أسعار بالدينار، تعديل الأسعار ممكن.

٣. الدفع: عند الاستلام، إلكتروني، تحويل بنكي. إثبات التحويل خلال 24 ساعة.

٤. الشحن: التوصيل للعنوان المسجل، إشعار برقم التتبع، مدة حسب المنطقة.

٥. الإلغاء: خلال 24 ساعة قبل الشحن. الاسترجاع خلال 7 أيام للعيوب.

٦. شحن الرصيد: حد أدنى 1000 د.ع، غير قابل للاسترداد النقدي.

٧. السلوك: الالتزام بالقوانين، عدم النشر المسيء أو الاحتيال.

٨. الملكية الفكرية: جميع الحقوق محفوظة، لا نسخ دون إذن.

٩. القانون: يخضع لقوانين جمهورية العراق.

١٠. التواصل: support@tokmart.com | الدردشة المباشرة.';
$appVersion = $siteData['app_version'] ?? '1.0.0';

$paymentMethods = query("SELECT * FROM payments WHERE enabled = 1 ORDER BY id ASC");
$rechargeMethods = query("SELECT * FROM payments WHERE enabled = 1 AND isRechargeOnly = 1 ORDER BY id ASC");

// ============================================================
// التوجيه والعناوين
// ============================================================

$route = isset($_GET['route']) ? $_GET['route'] : '';
$page = isset($_GET['page']) ? $_GET['page'] : '';

$protectedPages = ['account', 'orders', 'settings', 'cart', 'chat', 'notifications', 'favorites', 'recharge'];
if (in_array($page, $protectedPages) && !isset($_SESSION['user_id'])) {
    $page = 'home';
}

$pages = [
    'home' => ['title' => 'الرئيسية', 'file' => 'page-home'],
    'app' => ['title' => 'المتجر', 'file' => 'page-home'],
    'categories' => ['title' => 'التصنيفات', 'file' => 'page-categories'],
    'cart' => ['title' => 'سلة التسوق', 'file' => 'page-cart'],
    'account' => ['title' => 'الحساب', 'file' => 'page-account'],
    'orders' => ['title' => 'طلباتي', 'file' => 'ordersPage'],
    'settings' => ['title' => 'الإعدادات', 'file' => 'page-account-settings'],
    'admin' => ['title' => 'لوحة التحكم', 'file' => 'adminPage'],
    'product' => ['title' => 'تفاصيل المنتج', 'file' => 'productDetailPage'],
    'order' => ['title' => 'تفاصيل الطلب', 'file' => 'orderDetailPage'],
    'chat' => ['title' => 'الدردشة', 'file' => 'page-chat-support'],
    'notifications' => ['title' => 'الإشعارات', 'file' => 'page-notifications'],
    'favorites' => ['title' => 'المفضلة', 'file' => 'favoritesPage'],
    'recharge' => ['title' => 'شحن الرصيد', 'file' => 'rechargePage'],
    'forgot' => ['title' => 'نسيان كلمة المرور', 'file' => 'forgotPasswordPage']
];

$pageKey = $page ?: 'home';
if (!isset($pages[$pageKey])) {
    $pageKey = 'home';
}

$pageTitle = $siteName . ' - ' . ($pages[$pageKey]['title'] ?? 'المتجر');
$pageId = $pages[$pageKey]['file'] ?? 'page-home';

$isLoggedIn = isset($_SESSION['user_id']);
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
$isVerified = isset($_SESSION['is_verified']) && $_SESSION['is_verified'] == 1;

if ($isLoggedIn && isset($_SESSION['user_name'])) {
    $pageTitle = $pageTitle . ' - ' . $_SESSION['user_name'];
}

$paymentMethods = query("SELECT * FROM payments WHERE enabled = 1 ORDER BY id ASC");
$rechargeMethods = query("SELECT * FROM payments WHERE enabled = 1 AND isRechargeOnly = 1 ORDER BY id ASC");

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="theme-color" content="#058693">
    <title id="pageTitle"><?php echo htmlspecialchars($pageTitle); ?></title>
    
    <!-- Google OAuth -->
    <script src="https://accounts.google.com/gsi/client"></script>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Alyamama:wght@300..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    
    <style>
        /* ============================================================
           CSS - النسخة النهائية
           ============================================================
  
           
           */
/* تنسيق طبقة التغطية الخلفية */
.order-success-overlay {
    background-color: rgba(0, 0, 0, 0.6) !important;
    backdrop-filter: blur(4px);
    display: flex;
    align-items: center;
    justify-content: center;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 9999;
}

/* تكبير حجم بطاقة النجاح وتوسيعها */
.order-success-box {
    background-color: #ffffff !important;
    color: #333333 !important;
    border-radius: 18px !important;
    padding: 30px 24px !important;
    max-width: 380px !important;
    width: 90% !important;
    box-shadow: 0 15px 40px rgba(15, 61, 28, 0.3) !important;
    text-align: center;
    border: 1.5px solid rgba(15, 61, 28, 0.15) !important;
}

/* تكبير أيقونة علامة الصح وإضافة حركة نبض تفاعلية */
.order-success-box .success-icon {
    font-size: 56px !important;
    color: #0f3d1c !important;
    margin-bottom: 14px !important;
    animation: successPulse 1.5s infinite ease-in-out;
}

/* تأثير حركة النبض لعلامة الصح */
@keyframes successPulse {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.15);
    }
    100% {
        transform: scale(1);
    }
}

/* تكبير العنوان الرئيسي داخل البطاقة الكبيرة */
.order-success-box h3 {
    font-size: 18px !important;
    font-weight: 700 !important;
    color: #0f3d1c !important;
    margin-bottom: 8px !important;
}

/* النص الفرعي */
.order-success-box .order-sub {
    font-size: 13px !important;
    color: #555555 !important;
    margin-bottom: 18px !important;
}

/* تكبير صندوق تفاصيل الطلب الداخلي */
.order-success-box .order-details-card {
    background-color: #f8f9fa !important;
    border-radius: 10px !important;
    padding: 14px !important;
    font-size: 12px !important;
    margin-bottom: 14px !important;
    border: 1px solid #e5e5e5 !important;
    text-align: right;
}

.order-success-box .detail-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 6px;
}

.order-success-box .detail-row:last-child {
    margin-bottom: 0;
}

.order-success-box .detail-row .label {
    color: #666 !important;
}

.order-success-box .detail-row .value {
    color: #111 !important;
    font-weight: 600 !important;
}

/* تكبير المجموع الكلي */
.order-success-box .order-total {
    font-size: 14px !important;
    font-weight: 700 !important;
    display: flex;
    justify-content: space-between;
    padding: 8px 6px;
    border-top: 1px dashed #ccc;
    margin-bottom: 16px;
    color: #0f3d1c !important;
}

/* زر الإغلاق بحجم أكبر وأكثر وضوحاً */
.order-success-box .btn-close-success {
    background-color: #0f3d1c !important;
    color: #ffffff !important;
    border: none !important;
    border-radius: 10px !important;
    padding: 12px 20px !important;
    font-size: 14px !important;
    font-weight: 600 !important;
    width: 100% !important;
    cursor: pointer;
    transition: background 0.2s;
}

.order-success-box .btn-close-success:hover {
    background-color: #155227 !important;
}
    /* ===== RESET & ROOT ===== */
        * {
            font-family: "Alyamama", serif;
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-tap-highlight-color: transparent;
        }
        
        :root {
            --primary: #0f3d1c;
            --primary-light: #1b5e2b;
            --primary-dark: #072210;
            --secondary: #D8B07A;
            --bg: #F5F9FA;
            --bg-card: #FFFFFF;
            --bg2: #EDF4F5;
            --surface: #FFFFFF;
            --text: #193E45;
            --text-light: #4a7553;
            --border: rgba(15,61,28,.12);
            --text2: #193E45;
            --text3: #4a7553;
            --acSh: rgba(15,61,28,.06);
            --shadow: 0 8px 32px rgba(15,61,28,.10);
            --shadow-lg: 0 20px 60px rgba(15,61,28,.15);
            --gold: #D8B07A;
            --green: #2ECC71;
            --red: #E74C3C;
            --blue: #3498DB;
            --discount-color: #FF9A3D;
            --pink: #E91E63;
            --nav-bg: rgba(255,255,255,.92);
            --nav-shadow: 0 -4px 30px rgba(15,61,28,.06);
            --radius: 16px;
            --radius-sm: 12px;
            --ease-spring: cubic-bezier(0.34, 1.56, 0.64, 1);
            --ease-out: cubic-bezier(0.22, 1, 0.36, 1);
            --safe-area-top: env(safe-area-inset-top, 0px);
            --safe-area-bottom: env(safe-area-inset-bottom, 0px);
            --safe-area-left: env(safe-area-inset-left, 0px);
            --safe-area-right: env(safe-area-inset-right, 0px);
            
            /* Z-Index System */
            --z-base: 1;
            --z-content: 10;
            --z-header: 50;
            --z-bottom-nav: 100;
            --z-fab: 150;
            --z-overlay: 200;
            --z-modal: 250;
            --z-dialog: 300;
            --z-snackbar: 350;
            --z-loading: 400;
            --z-admin-header: 60;
            --z-admin-content: 20;
            --z-admin-modal: 350;
            --z-admin-overlay: 360;
        }
        
        html, body {
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            min-height: 100dvh;
            overflow: hidden;
            height: 100%;
            width: 100%;
            -webkit-font-smoothing: antialiased;
            position: relative;
            padding: 0;
            margin: 0;
        }
        
        #app {
            height: 100vh;
            overflow: hidden;
            position: fixed;
            inset: 0;
            display: flex;
            flex-direction: column;
            background: var(--bg);
            width: 100vw;
            width: 100dvw;
            padding-top: var(--safe-area-top);
            padding-bottom: var(--safe-area-bottom);
            z-index: var(--z-base);
        }
        /* ============================================================
           HEADER
           ============================================================ */
        header {
            position: relative;
            z-index: var(--z-header);
            background: var(--primary);
            border-bottom: none;
            flex-shrink: 0;
            box-shadow: 0 2px 20px rgba(5,134,147,.15);
            padding-top: var(--safe-area-top);
        }
        
        .topbar {
            max-width: 1200px;
            margin: 0 auto;
            padding: 12px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 64px;
        }
        
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logo {
            font-size: 24px;
            font-weight: 900;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            user-select: none;
        }
        
        .logo .logo-icon {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            background: rgba(255,255,255,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 20px;
        }
        
        /* ===== إخفاء النص ===== */
        .logo .logo-text {
            display: none !important;
        }
        
        .icon-btn {
            width: 42px;
            height: 42px;
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 12px;
            background: rgba(255,255,255,0.08);
            color: #FFFFFF;
            font-size: 16px;
            cursor: pointer;
            display: grid;
            place-items: center;
            transition: all 0.25s var(--ease-spring);
            position: relative;
            touch-action: manipulation;
        }
        
        .icon-btn .badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: var(--red);
            color: #fff;
            font-size: 9px;
            font-weight: 800;
            min-width: 18px;
            height: 18px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
        }
        
        .admin-icon {
            background: var(--secondary);
            color: #fff;
            border-color: var(--secondary) !important;
        }
        
       
        /* ============================================================
   SCROLL AREA
   ============================================================ */
.scroll-area {
    flex: 1;
    height: 100%;
    overflow-y: auto !important;
    overflow-x: hidden;
    -webkit-overflow-scrolling: touch;
    scroll-behavior: smooth;
    padding-bottom: 120px;
    position: relative;
    z-index: var(--z-content);
    background: var(--bg);
    touch-action: pan-y;
}

.scroll-area::-webkit-scrollbar {
    width: 3px;
}

.scroll-area::-webkit-scrollbar-thumb {
    background: var(--border);
    border-radius: 10px;
}
        /* ============================================================
           PAGES
           ============================================================ */
        .page {
            display: none;
            animation: pageFadeIn 0.4s var(--ease-out);
            padding: 0 18px;
            padding-bottom: 10px;
        }
        
        .page.active {
            display: block;
        }
        
        @keyframes pageFadeIn {
            0% { opacity: 0; transform: translateY(12px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        
        /* ============================================================
           BOTTOM NAVIGATION
           ============================================================ */
        .bottom-nav-wrapper {
            position: fixed;
            bottom: 16px;
            left: 16px;
            right: 16px;
            z-index: var(--z-bottom-nav);
            display: flex;
            justify-content: center;
            pointer-events: none;
            transition: all 0.4s var(--ease-out);
            padding-bottom: var(--safe-area-bottom);
        }
        
        .bottom-nav-wrapper.hidden {
            opacity: 0;
            transform: translateY(20px) scale(0.95);
            pointer-events: none;
        }
        
        .bottom-nav {
            pointer-events: auto;
            background: var(--nav-bg);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            border: 1px solid var(--border);
            border-radius: 28px;
            padding: 8px 12px;
            display: flex;
            justify-content: space-around;
            align-items: center;
            gap: 2px;
            box-shadow: var(--nav-shadow), 0 0 0 1px rgba(255,255,255,0.5);
            max-width: 500px;
            width: 100%;
            min-height: 68px;
        }
        
        .nav-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            background: none;
            border: none;
            color: var(--text3);
            font-size: 10px;
            font-weight: 600;
            cursor: pointer;
            padding: 6px 10px;
            transition: all 0.3s var(--ease-spring);
            border-radius: 16px;
            min-width: 52px;
            flex: 1;
            position: relative;
            touch-action: manipulation;
            text-decoration: none;
            height: 56px;
        }
        
        .nav-item:hover {
            color: var(--primary);
            background: var(--acSh);
        }
        
        .nav-item.active {
            color: var(--primary);
            background: var(--acSh);
        }
        
        .nav-item i {
            font-size: 22px;
            transition: all 0.3s var(--ease-spring);
            line-height: 1;
        }
        
        .nav-item.active i {
            transform: translateY(-2px) scale(1.1);
            color: var(--primary);
        }
        
        .nav-item span:last-child {
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 0.3px;
            opacity: 0.9;
        }
        
        .nav-item.active span:last-child {
            color: var(--primary);
        }
        
        .nav-item .cat-icon {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 38px;
            height: 38px;
            border-radius: 12px;
            background: transparent;
            transition: all 0.3s var(--ease-spring);
            border: 2px solid transparent;
            position: relative;
        }
        
        .nav-item .cat-icon i {
            font-size: 20px;
            color: var(--text3);
            transition: all 0.3s var(--ease-spring);
        }
        
        .nav-item.active .cat-icon {
            background: var(--primary);
            border-color: var(--primary);
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 4px 15px rgba(5,134,147,.25);
        }
        
        .nav-item.active .cat-icon i {
            color: #fff;
            transform: scale(1.05);
        }
        
        .nav-item:hover:not(.active) .cat-icon {
            background: var(--acSh);
        }
        
        .nav-item:hover:not(.active) .cat-icon i {
            color: var(--primary);
        }
        
        .nav-item .badge {
            position: absolute;
            top: 0px;
            right: 4px;
            background: var(--red);
            color: #fff;
            font-size: 9px;
            font-weight: 800;
            min-width: 18px;
            height: 18px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 0 5px;
            z-index: 2;
            border: 2px solid var(--nav-bg);
            box-shadow: 0 2px 8px rgba(231,76,60,.3);
            animation: badgePop 0.3s var(--ease-spring);
        }
        
        @keyframes badgePop {
            0% { transform: scale(0.5); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        
        @media(max-width:480px) {
            .bottom-nav {
                padding: 6px 8px;
                min-height: 58px;
                border-radius: 22px;
            }
            .nav-item {
                min-width: 40px;
                padding: 4px 6px;
                height: 48px;
                gap: 2px;
            }
            .nav-item i {
                font-size: 18px;
            }
            .nav-item span:last-child {
                font-size: 8px;
            }
            .nav-item .cat-icon {
                width: 32px;
                height: 32px;
            }
            .nav-item .cat-icon i {
                font-size: 16px;
            }
            .nav-item .badge {
                font-size: 8px;
                min-width: 16px;
                height: 16px;
                top: -2px;
                right: 0px;
            }
        }
        
        @media(min-width:768px) {
            .bottom-nav {
                padding: 10px 16px;
                min-height: 74px;
                border-radius: 32px;
            }
            .nav-item {
                min-width: 64px;
                padding: 8px 14px;
                height: 62px;
            }
            .nav-item i {
                font-size: 26px;
            }
            .nav-item span:last-child {
                font-size: 11px;
            }
            .nav-item .cat-icon {
                width: 44px;
                height: 44px;
            }
            .nav-item .cat-icon i {
                font-size: 22px;
            }
        }
        
        /* ============================================================
           أزرار الرجوع
           ============================================================ */
        .detail-header,
        .settings-page .detail-header,
        .product-detail-page .detail-header,
        .all-products-page .detail-header,
        .category-products-page .detail-header,
        .favorites-page .detail-header,
        .orders-page .detail-header,
        .order-detail-page .detail-header,
        .recharge-page .detail-header,
        .auth-page .detail-header,
        .chat-app-user .chat-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: var(--primary);
            color: #fff;
            position: sticky;
            top: 0;
            z-index: 10;
            flex-shrink: 0;
            min-height: 60px;
            box-shadow: 0 2px 20px rgba(5,134,147,.15);
            padding-top: calc(14px + var(--safe-area-top));
        }
        
        .detail-header h2,
        .settings-page .detail-header h2,
        .product-detail-page .detail-header h2,
        .all-products-page .detail-header h2,
        .category-products-page .detail-header h2,
        .favorites-page .detail-header h2,
        .orders-page .detail-header h2,
        .order-detail-page .detail-header h2,
        .recharge-page .detail-header h2,
        .auth-page .detail-header h2,
        .chat-app-user .chat-header .chat-info .name {
            font-size: 18px;
            font-weight: 800;
            color: #fff;
            margin: 0;
            flex: 1;
            text-shadow: 0 1px 4px rgba(0,0,0,0.1);
        }
        
        .back-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            min-width: 40px;
            min-height: 40px;
            border: none;
            border-radius: 12px;
            background: rgba(255,255,255,0.15);
            color: #FFFFFF;
            font-size: 18px;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            touch-action: manipulation;
            position: relative;
            backdrop-filter: blur(4px);
            -webkit-backdrop-filter: blur(4px);
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .back-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: scale(1.05);
            border-color: rgba(255,255,255,0.3);
        }
        
        .back-btn:active {
            transform: scale(0.92);
            background: rgba(255,255,255,0.3);
        }
        
        .back-btn i {
            font-size: 18px;
            transition: transform 0.3s var(--ease-spring);
        }
        
        .back-btn:hover i {
            transform: translateX(-2px);
        }
        
        .admin-page .admin-header .close-admin-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            min-width: 40px;
            min-height: 40px;
            border: none;
            border-radius: 12px;
            background: rgba(255,255,255,0.15);
            color: #FFFFFF;
            font-size: 20px;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            touch-action: manipulation;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .admin-page .admin-header .close-admin-btn:hover {
            background: rgba(255,255,255,0.25);
            transform: rotate(90deg);
        }
        
        .admin-page .admin-header .close-admin-btn:active {
            transform: rotate(90deg) scale(0.92);
        }
        
        /* ============================================================
           الإشعارات
           ============================================================ */
        .notifications-container {
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 4px 0;
        }
        
        .notification-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
            cursor: pointer;
            touch-action: pan-y;
            animation: notifSlideIn 0.4s var(--ease-out);
        }
        
        @keyframes notifSlideIn {
            0% { opacity: 0; transform: translateX(20px); }
            100% { opacity: 1; transform: translateX(0); }
        }
        
        .notification-item {
            animation: notifShine 5s ease-in-out infinite;
        }
        
        @keyframes notifShine {
            0% { background: var(--surface); }
            10% { background: var(--bg2); }
            20% { background: var(--surface); }
            100% { background: var(--surface); }
        }
        
        .notification-item.unread {
            border-right: 4px solid var(--primary);
            background: var(--bg2);
            position: relative;
        }
        
        .notification-item.unread::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 4px;
            height: 100%;
            background: var(--primary);
            animation: pulseBorder 2s ease-in-out infinite;
        }
        
        @keyframes pulseBorder {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }
        
        .notification-item.unread .unread-dot {
            display: block !important;
        }
        
        .unread-dot {
            display: none;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--primary);
            flex-shrink: 0;
            animation: pulseDot 1.5s ease-in-out infinite;
        }
        
        @keyframes pulseDot {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(0.5); opacity: 0.5; }
        }
        
        .notification-item .notif-icon {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 18px;
            flex-shrink: 0;
            transition: all 0.3s;
        }
        
        .notification-item .notif-icon.offer {
            background: rgba(216,176,122,.15);
            color: var(--secondary);
        }
        .notification-item .notif-icon.order {
            background: rgba(5,134,147,.15);
            color: var(--primary);
        }
        .notification-item .notif-icon.wallet {
            background: rgba(46,204,113,.15);
            color: var(--green);
        }
        .notification-item .notif-icon.info {
            background: rgba(52,152,219,.15);
            color: #3498DB;
        }
        .notification-item .notif-icon.chat {
            background: rgba(46,204,113,.15);
            color: var(--green);
        }
        
        .notification-item .notif-content {
            flex: 1;
            min-width: 0;
        }
        
        .notification-item .notif-content .notif-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 2px;
        }
        
        .notification-item .notif-content .notif-desc {
            font-size: 13px;
            color: var(--text3);
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .notification-item .notif-content .notif-time {
            font-size: 11px;
            color: var(--text3);
            margin-top: 4px;
            opacity: 0.6;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .notification-item.swiping {
            transform: translateX(var(--swipe-offset, 0px));
            transition: transform 0.1s ease-out;
        }
        
        .notification-item.swiped {
            transform: translateX(100%);
            opacity: 0;
            transition: all 0.4s var(--ease-spring);
            pointer-events: none;
        }
        
        .notification-item .swipe-hint {
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--red);
            font-size: 14px;
            font-weight: 700;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        
        .notification-item.swiping .swipe-hint {
            opacity: 1;
        }
        
        .notif-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border);
        }
        
        .notif-header .notif-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text2);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .notif-header .notif-title .count {
            font-size: 13px;
            font-weight: 600;
            color: var(--text3);
            background: var(--bg2);
            padding: 2px 12px;
            border-radius: 20px;
        }
        
        .notif-header .notif-actions {
            display: flex;
            gap: 8px;
        }
        
        .notif-header .notif-actions button {
            padding: 8px 16px;
            border: none;
            border-radius: 10px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .notif-header .notif-actions .btn-mark-all {
            background: var(--primary-light);
            color: #fff;
        }
        
        .notif-header .notif-actions .btn-delete-all {
            background: var(--red);
            color: #fff;
        }
        
        .notifications-empty {
            text-align: center;
            padding: 80px 20px;
            color: var(--text3);
        }
        
        .notifications-empty i {
            font-size: 56px;
            display: block;
            margin-bottom: 16px;
            opacity: 0.3;
        }
        
        .notifications-empty .title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text2);
        }
        
        .notifications-empty .sub {
            font-size: 14px;
            margin-top: 6px;
        }
        
        /* ============================================================
           PRODUCT CARD
           ============================================================ */
.products-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    padding: 8px;
}

.products-grid::-webkit-scrollbar {
    display: none;
}

.product-card {
    flex: 0 0 130px;
    width: 130px;
    height: 160px;
    min-width: 130px;
    max-width: 130px;
}
        
        .product-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px 8px 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            position: relative;
            overflow: visible;
            touch-action: manipulation;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 3px;
            width: 130px;
            height: 160px;
            min-height: 160px;
            max-height: 160px;
        }
        
        .product-card:active {
            transform: scale(0.96);
        }
        
        .product-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--primary-light);
        }
        
        .product-card .discount-chip {
            position: absolute;
            top: -6px;
            right: -4px;
            display: inline-flex;
            align-items: center;
            gap: 2px;
            background: var(--discount-color);
            color: #fff;
            padding: 1px 8px 1px 5px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.55rem;
            box-shadow: 0 2px 8px rgba(255,155,61,.3);
            z-index: 3;
            border: 1.5px solid var(--bg);
        }
        
        .product-card .discount-chip .percent {
            font-size: 0.65rem;
            font-weight: 900;
        }
        
       .product-card .product-image {
    width: 45px;
    height: 45px;
            border-radius: 10px;
            margin: 0 auto 4px;
            overflow: hidden;
            background: var(--acSh);
            display: grid;
            place-items: center;
            font-size: 20px;
            color: var(--primary);
            flex-shrink: 0;
        }
        
        .product-card .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .product-card h4 {
            font-size: 10px;
            font-weight: 700;
            margin: 1px 0 0px;
            color: var(--text);
            line-height: 1.2;
            height: 22px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            width: 100%;
            word-break: break-word;
        }
        
        .product-card .product-desc {
            font-size: 8px;
            color: var(--text3);
            margin-bottom: 1px;
            height: 14px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 1;
            -webkit-box-orient: vertical;
            width: 100%;
        }
        
        .product-card .brand-tag {
            font-size: 8px;
            color: var(--text3);
            margin-bottom: 1px;
            font-weight: 600;
            opacity: 0.7;
            width: 100%;
        }
        
        .product-card .price-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            flex-wrap: wrap;
        }
        
        .product-card .price-row .old-price {
            font-size: 9px;
            color: var(--text3);
            text-decoration: line-through;
        }
        
        .product-card .price-row .new-price {
            font-size: 11px;
            font-weight: 800;
            color: var(--red);
        }
        
        .product-card .price-row .normal-price {
            font-size: 11px;
            font-weight: 800;
            color: var(--primary);
        }
        
        /* ===== زر إضافة للسلة - محسن ===== */
        .product-card .add-btn {
            margin-top: 4px;
            padding: 6px 16px;
            border: none;
            border-radius: 8px;
            background: var(--primary-light);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            touch-action: manipulation;
            display: flex;
            align-items: center;
            gap: 5px;
            width: 100%;
            justify-content: center;
            max-width: 90px;
            margin-left: auto;
            margin-right: auto;
            position: relative;
            overflow: hidden;
        }
        
        .product-card .add-btn::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.2), transparent);
            opacity: 0;
            transition: opacity 0.3s;
        }
        
        .product-card .add-btn:hover::before {
            opacity: 1;
        }
        
        .product-card .add-btn:active {
            transform: scale(0.92);
        }
        
        .product-card .add-btn:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(5,134,147,.3);
        }
        
        .product-card .add-btn i {
            font-size: 11px;
            transition: transform 0.3s;
        }
        
        .product-card .add-btn:hover i {
            transform: translateX(-2px);
        }
        
        .product-card .add-btn .btn-text {
            font-size: 9px;
        }
        
        .product-card .favorite-icon {
            position: absolute;
            bottom: 6px;
            left: 6px;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--surface);
            border: 1px solid var(--border);
            display: grid;
            place-items: center;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            color: var(--text3);
            font-size: 9px;
            z-index: 2;
            touch-action: manipulation;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        
        .product-card .favorite-icon:active {
            transform: scale(0.85);
        }
        
        .product-card .favorite-icon.active {
            color: var(--pink);
            border-color: var(--pink);
            background: rgba(233,30,99,.1);
        }
        
        @media(max-width:480px) {
            .product-card {
                min-height: 140px;
                padding: 8px 6px 10px;
            }
            .product-card .product-image {
                width: 48px;
                height: 48px;
                font-size: 16px;
            }
            .product-card h4 {
                font-size: 9px;
                height: 18px;
            }
            .product-card .product-desc {
                font-size: 7px;
                height: 12px;
            }
            .product-card .price-row .new-price,
            .product-card .price-row .normal-price {
                font-size: 10px;
            }
            .product-card .price-row .old-price {
                font-size: 8px;
            }
            .product-card .add-btn {
                font-size: 7px;
                padding: 2px 10px;
            }
            .product-card .favorite-icon {
                width: 20px;
                height: 20px;
                font-size: 8px;
                bottom: 4px;
                left: 4px;
            }
            .products-grid {
                gap: 10px;
                padding: 10px;
            }
        }
        
        /* ============================================================
           CATEGORIES
           ============================================================ */
        .category-grid-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 16px 12px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            position: relative;
            overflow: hidden;
        }
        
        .category-grid-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .category-grid-item .cat-img {
            width: 60px;
            height: 60px;
            border-radius: 14px;
            background: var(--acSh);
            display: grid;
            place-items: center;
            margin: 0 auto 8px;
            font-size: 26px;
            color: var(--primary);
            overflow: hidden;
            transition: all 0.3s var(--ease-spring);
            border: 2px solid transparent;
        }
        
        .category-grid-item:hover .cat-img {
            border-color: var(--primary);
            transform: scale(1.05);
            box-shadow: 0 4px 20px rgba(5,134,147,.15);
        }
        
        .category-grid-item .cat-img img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .category-grid-item .cat-img i {
            font-size: 28px;
            transition: all 0.3s var(--ease-spring);
        }
        
        .category-grid-item:hover .cat-img i {
            transform: scale(1.1) rotate(-5deg);
            color: var(--primary);
        }
        
        .category-grid-item span {
            font-size: 13px;
            font-weight: 700;
            color: var(--text2);
            display: block;
            margin-top: 4px;
            transition: all 0.3s var(--ease-spring);
        }
        
        .category-grid-item:hover span {
            color: var(--primary);
        }
        
        .category-grid-item .product-count {
            font-size: 10px;
            color: var(--text3);
            font-weight: 600;
            margin-top: 2px;
        }
        
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin-top: 12px;
        }
        
        @media(min-width:480px) {
            .categories-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media(min-width:768px) {
            .categories-grid {
                grid-template-columns: repeat(4, 130px);
justify-content: center;
            }
        }
        
        /* ============================================================
           CATEGORY BANNERS
           ============================================================ */
        .category-banner-section {
            margin: 10px 0 14px;
            border-radius: var(--radius-sm);
            overflow: hidden;
            box-shadow: var(--shadow);
            background: var(--surface);
            border: 1px solid var(--border);
            transition: all 0.3s var(--ease-spring);
        }
        
        .category-banner-section:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .category-banner {
            position: relative;
            width: 100%;
            aspect-ratio: 16/5;
            overflow: hidden;
            cursor: pointer;
            background: var(--bg2);
            min-height: 120px;
        }
        
        .category-banner img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s var(--ease-out);
        }
        
        .category-banner:hover img {
            transform: scale(1.05);
        }
        
        .category-banner .banner-overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    padding: 16px 20px;
    /* ✅ تغيير اللون من #058693 إلى #0f3d1c */
    background: linear-gradient(transparent, rgba(15, 61, 28, 0.8));
    color: #fff;
    transition: all 0.3s var(--ease-spring);
}

.category-banner:hover .banner-overlay {
    /* ✅ تغيير اللون عند hover */
    background: linear-gradient(transparent, rgba(15, 61, 28, 0.9));
    padding: 20px 24px;
}

.category-banner .banner-overlay h3 {
    font-size: 20px;
    font-weight: 800;
    text-shadow: 0 2px 10px rgba(0,0,0,0.4);
    margin: 0;
}

.category-banner .banner-overlay p {
    font-size: 12px;
    opacity: 0.9;
    text-shadow: 0 1px 6px rgba(0,0,0,0.4);
    margin: 4px 0 0;
}
        /* ============================================================
           ORDER STATUS BADGE
           ============================================================ */
        .order-status-badge.pending {
            background: rgba(216,176,122,.15) !important;
            color: var(--secondary) !important;
        }
        .order-status-badge.shipped {
            background: rgba(5,134,147,.15) !important;
            color: var(--primary) !important;
        }
        .order-status-badge.completed {
            background: rgba(46,204,113,.15) !important;
            color: var(--green) !important;
        }
        .order-status-badge.cancelled {
            background: rgba(231,76,60,.15) !important;
            color: var(--red) !important;
        }
        .order-status-badge.approved {
            background: rgba(46,204,113,.15) !important;
            color: var(--green) !important;
        }
        .order-status-badge.rejected {
            background: rgba(231,76,60,.15) !important;
            color: var(--red) !important;
        }
        
        .order-status {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
        }
        
        /* ============================================================
           RECHARGE
           ============================================================ */
        .payment-card-container {
            position: relative;
            width: 100%;
            aspect-ratio: 16/9;
            border-radius: 16px;
            overflow: hidden;
            background: linear-gradient(135deg, #0F2B3D, #1A4A5A);
            margin-bottom: 16px;
            box-shadow: 0 8px 32px rgba(5,134,147,.2);
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .recharge-status.pending {
            background: rgba(216,176,122,.15) !important;
            color: var(--secondary) !important;
        }
        .recharge-status.approved {
            background: rgba(46,204,113,.15) !important;
            color: var(--green) !important;
        }
        .recharge-status.rejected {
            background: rgba(231,76,60,.15) !important;
            color: var(--red) !important;
        }
        
        .recharge-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 14px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .recharge-item:hover {
            border-color: var(--primary);
        }
        
        .payment-option {
            padding: 12px;
            border: 2px solid var(--border);
            border-radius: 12px;
            text-align: center;
            cursor: pointer;
            background: var(--surface);
            transition: all 0.3s;
        }
        
        .payment-option.active {
            border-color: var(--primary);
            background: var(--acSh);
            box-shadow: 0 0 0 3px rgba(5,134,147,.1);
        }
        
        .payment-option:hover:not(.active) {
            border-color: var(--primary-light);
            background: var(--bg2);
        }
        
        /* ============================================================
           SLIDING PAGES
           ============================================================ */
        .settings-page,
        .product-detail-page,
        .all-products-page,
        .category-products-page,
        .favorites-page,
        .orders-page,
        .order-detail-page,
        .recharge-page,
        .auth-page,
        .chat-app-user,
        .admin-page {
            background: var(--bg);
        }
        
        .settings-page,
        .product-detail-page,
        .all-products-page,
        .category-products-page,
        .favorites-page,
        .orders-page,
        .order-detail-page,
        .recharge-page,
        .auth-page,
        .chat-app-user {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: none;
            flex-direction: column;
            height: 100vh;
            height: 100dvh;
            width: 100vw;
            width: 100dvw;
            z-index: var(--z-modal);
            animation: slideUp 0.4s var(--ease-out);
            padding-top: var(--safe-area-top);
            padding-bottom: var(--safe-area-bottom);
            overflow: hidden;
        }
        
        .settings-page.open,
        .product-detail-page.open,
        .all-products-page.open,
        .category-products-page.open,
        .favorites-page.open,
        .orders-page.open,
        .order-detail-page.open,
        .recharge-page.open,
        .auth-page.open,
        .chat-app-user.open {
            display: flex !important;
        }
        
        @keyframes slideUp {
            0% { opacity: 0; transform: translateY(30px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        
        .settings-page .settings-page-body,
        .product-detail-page .detail-body,
        .all-products-page .products-grid,
        .category-products-page .products-grid,
        .favorites-page .favorites-grid,
        .orders-page .orders-list,
        .order-detail-page .order-detail-body,
        .recharge-page .recharge-body,
        .auth-page .auth-body,
        .chat-app-user .messages-container {
            background: var(--bg);
            padding: 16px;
            flex: 1;
            overflow-y: auto;
        }
        
        /* ============================================================
           ADMIN PAGE
           ============================================================ */
        .admin-page {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: none;
            flex-direction: column;
            background: var(--bg);
            height: 100vh;
            height: 100dvh;
            width: 100vw;
            width: 100dvw;
            z-index: var(--z-admin-modal);
            animation: slideUp 0.4s var(--ease-out);
            padding-top: var(--safe-area-top);
            padding-bottom: var(--safe-area-bottom);
            overflow: hidden;
        }
        
        .admin-page.open {
            display: flex !important;
        }
        
        .admin-page .admin-header {
            background: var(--primary);
            color: #fff;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: var(--z-admin-header);
            padding-top: calc(16px + var(--safe-area-top));
            flex-shrink: 0;
        }
        
        .admin-page .admin-body {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            padding-bottom: calc(20px + var(--safe-area-bottom));
            z-index: var(--z-admin-content);
        }
        
        .admin-page .admin-body .admin-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .admin-page .admin-body .admin-stats .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            text-align: center;
        }
        
        .admin-page .admin-body .admin-stats .stat-card .stat-number {
            font-size: 28px;
            font-weight: 900;
            color: var(--primary);
        }
        
        .admin-page .admin-body .admin-tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            overflow-x: auto;
            padding-bottom: 4px;
            flex-wrap: nowrap;
            -webkit-overflow-scrolling: touch;
        }
        
        .admin-page .admin-body .admin-tabs button {
            padding: 8px 16px;
            border: 2px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text2);
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            white-space: nowrap;
            flex-shrink: 0;
        }
        
        .admin-page .admin-body .admin-tabs button.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
        
        .admin-page .admin-body .admin-tab-content {
            display: none;
        }
        
        .admin-page .admin-body .admin-tab-content.active {
            display: block;
        }
        
        .admin-list-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 8px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .admin-list-item .item-info {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 120px;
        }
        
        .admin-list-item .item-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        
        .admin-list-item .item-actions button,
        .admin-list-item .item-actions select {
            padding: 4px 12px;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
        }
        
        .admin-list-item .item-actions .btn-edit {
            background: var(--blue);
            color: #fff;
        }
        
        .admin-list-item .item-actions .btn-delete {
            background: var(--red);
            color: #fff;
        }
        
        .admin-list-item .item-actions .btn-approve {
            background: var(--green);
            color: #fff;
        }
        
        .admin-list-item .item-actions .btn-reject {
            background: var(--red);
            color: #fff;
        }
        
        .admin-add-btn {
            width: 100%;
            padding: 12px;
            border: 2px dashed var(--border);
            border-radius: 12px;
            background: transparent;
            color: var(--text3);
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            margin-top: 8px;
        }
        
        /* ============================================================
           ADMIN SETTINGS - محسنة
           ============================================================ */
        .admin-settings-group {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 18px;
            box-shadow: var(--shadow);
            transition: all 0.3s var(--ease-spring);
        }
        
        .admin-settings-group:hover {
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }
        
        .admin-settings-group .group-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border);
        }
        
        .admin-settings-group .group-header h4 {
            font-size: 15px;
            font-weight: 800;
            color: var(--text2);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .admin-settings-group .group-header .group-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 16px;
            color: #fff;
            flex-shrink: 0;
        }
        
        .admin-settings-group .group-header .group-icon.google {
            background: linear-gradient(135deg, #EA4335, #C5221F);
        }
        .admin-settings-group .group-header .group-icon.smtp {
            background: linear-gradient(135deg, #058693, #046C76);
        }
        .admin-settings-group .group-header .group-icon.site {
            background: linear-gradient(135deg, #D8B07A, #C49A5E);
        }
        .admin-settings-group .group-header .group-icon.policy {
            background: linear-gradient(135deg, #3498DB, #2980B9);
        }
        
        .admin-settings-group .form-group {
            margin-bottom: 12px;
        }
        
        .admin-settings-group .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--text3);
            margin-bottom: 4px;
            letter-spacing: 0.3px;
        }
        
        .admin-settings-group .form-group input,
        .admin-settings-group .form-group textarea,
        .admin-settings-group .form-group select {
            width: 100%;
            padding: 10px 14px;
            border: 2px solid var(--border);
            border-radius: 10px;
            background: var(--bg2);
            color: var(--text);
            font-size: 13px;
            outline: none;
            transition: all 0.3s;
            font-family: inherit;
        }
        
        .admin-settings-group .form-group input:focus,
        .admin-settings-group .form-group textarea:focus,
        .admin-settings-group .form-group select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--acSh);
            background: var(--surface);
        }
        
        .admin-settings-group .form-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary);
            cursor: pointer;
        }
        
        .admin-settings-group .form-group .input-hint {
            font-size: 11px;
            color: var(--text3);
            margin-top: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-save-cfg {
            padding: 10px 24px;
            border: none;
            border-radius: 10px;
            background: var(--primary-light);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-save-cfg:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(5,134,147,.3);
        }
        
        .btn-save-cfg:active {
            transform: scale(0.96);
        }
        
        .btn-save-cfg:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        
        .settings-status {
            margin-top: 10px;
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 600;
            display: none;
        }
        
        .settings-status.success {
            display: block;
            background: rgba(46,204,113,.1);
            color: var(--green);
            border: 1px solid var(--green);
        }
        
        .settings-status.error {
            display: block;
            background: rgba(231,76,60,.1);
            color: var(--red);
            border: 1px solid var(--red);
        }
        
        .settings-info-box {
            background: var(--bg2);
            border-radius: 8px;
            padding: 12px 16px;
            margin-top: 8px;
            border: 1px solid var(--border);
            font-size: 12px;
            color: var(--text3);
            line-height: 1.8;
        }
        
        .settings-info-box code {
            background: var(--surface);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            color: var(--primary);
            border: 1px solid var(--border);
            font-family: monospace;
        }
        
        /* ============================================================
           ADMIN FORMS
           ============================================================ */
        .admin-product-form,
        .admin-category-form,
        .admin-payment-form {
            background: var(--bg);
            border-radius: var(--radius);
            padding: 24px;
            max-width: 600px;
            width: 95%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: formSlideIn 0.4s var(--ease-spring);
        }
        
        .admin-product-form .form-group input,
        .admin-product-form .form-group textarea,
        .admin-product-form .form-group select,
        .admin-category-form .form-group input,
        .admin-category-form .form-group textarea,
        .admin-payment-form .form-group input,
        .admin-payment-form .form-group textarea {
            background: var(--surface);
            border: 2px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
            padding: 10px 14px;
            width: 100%;
        }
        
        .admin-product-form .form-group input:focus,
        .admin-product-form .form-group textarea:focus,
        .admin-product-form .form-group select:focus,
        .admin-category-form .form-group input:focus,
        .admin-category-form .form-group textarea:focus,
        .admin-payment-form .form-group input:focus,
        .admin-payment-form .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--acSh);
        }
        
        .admin-product-form-overlay,
        .admin-category-form-overlay,
        .admin-payment-form-overlay {
            position: fixed;
            inset: 0;
            z-index: var(--z-admin-overlay);
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(8px);
            display: none;
            justify-content: center;
            align-items: center;
            animation: fadeInOverlay 0.3s var(--ease-out);
            padding: var(--safe-area-top) var(--safe-area-right) var(--safe-area-bottom) var(--safe-area-left);
        }
        
        .admin-product-form-overlay.open,
        .admin-category-form-overlay.open,
        .admin-payment-form-overlay.open {
            display: flex;
        }
        
        @keyframes fadeInOverlay {
            0% { opacity: 0; }
            100% { opacity: 1; }
        }
        
        /* ============================================================
           CHAT
           ============================================================ */
        .chat-app-user .messages-container,
        .admin-messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: var(--bg);
        }
        
        .message {
            display: flex;
            flex-direction: column;
            max-width: 85%;
            animation: messageSlideIn 0.3s var(--ease-out);
        }
        
        .message.sent {
            align-self: flex-end;
        }
        
        .message.received {
            align-self: flex-start;
        }
        
        @keyframes messageSlideIn {
            0% { opacity: 0; transform: translateY(10px) scale(0.95); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .message .message-content {
            padding: 10px 14px;
            border-radius: 16px;
            word-wrap: break-word;
            max-width: 100%;
            position: relative;
        }
        
        .message.sent .message-content {
            background: var(--primary);
            color: #fff;
            border-bottom-right-radius: 4px;
            box-shadow: 0 2px 8px rgba(5,134,147,.15);
        }
        
        .message.received .message-content {
            background: var(--surface);
            color: var(--text);
            border-bottom-left-radius: 4px;
            border: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0,0,0,.04);
        }
        
        .message .message-content .msg-text {
            font-size: 14px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-break: break-word;
            margin: 0;
        }
        
        .message.sent .message-content .msg-text {
            color: #fff;
        }
        
        .message.received .message-content .msg-text {
            color: var(--text);
        }
        
        .message .message-content .msg-footer {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 4px;
            font-size: 10px;
            opacity: 0.7;
        }
        
        .message.sent .message-content .msg-footer {
            color: rgba(255,255,255,0.8);
        }
        
        .message.received .message-content .msg-footer {
            color: var(--text3);
        }
        
        .message .message-content .msg-footer .msg-time {
            font-size: 10px;
        }
        
        .message .message-content .msg-footer .msg-read-status {
            font-size: 10px;
            display: flex;
            align-items: center;
            gap: 3px;
        }
        
        .admin-chat-container {
            background: var(--bg);
            border-radius: var(--radius);
            overflow: hidden;
            border: 1px solid var(--border);
            height: 500px;
            display: flex;
            flex-direction: column;
        }
        
        .admin-chat-header {
            background: var(--primary);
            color: #fff;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        
        .admin-chat-body {
            flex: 1;
            display: flex;
            overflow: hidden;
        }
        
        .admin-chat-sidebar {
            width: 250px;
            background: var(--surface);
            border-left: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        
        .admin-chat-sidebar .chat-user-list {
            flex: 1;
            overflow-y: auto;
            padding: 4px 0;
        }
        
        .admin-user-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            border-bottom: 1px solid var(--border);
            position: relative;
        }
        
        .admin-user-item.active {
            background: var(--acSh);
            border-right: 3px solid var(--primary);
        }
        
        .admin-user-item .avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--acSh);
            display: grid;
            place-items: center;
            font-size: 16px;
            color: var(--primary);
            flex-shrink: 0;
            overflow: hidden;
            position: relative;
        }
        
        .admin-user-item .info {
            flex: 1;
            min-width: 0;
        }
        
        .admin-user-item .info .name {
            font-weight: 700;
            font-size: 13px;
            color: var(--text);
        }
        
        .admin-user-item .info .last-msg {
            font-size: 11px;
            color: var(--text3);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .admin-user-item .info .time {
            font-size: 9px;
            color: var(--text3);
            opacity: 0.7;
        }
        
        .admin-user-item .unread {
            background: var(--red);
            color: #fff;
            font-size: 9px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            flex-shrink: 0;
            min-width: 18px;
            text-align: center;
        }
        
        .admin-messages-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--bg2);
        }
        
        .admin-chat-with {
            padding: 10px 16px;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        
        .admin-chat-with .user-info .avatar {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: var(--acSh);
            display: grid;
            place-items: center;
            font-size: 14px;
            color: var(--primary);
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .admin-messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 12px 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            background: var(--bg);
        }
        
        .admin-input-wrapper {
            display: flex;
            gap: 8px;
            padding: 8px 16px;
            padding-bottom: calc(8px + var(--safe-area-bottom));
            background: var(--surface);
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            align-items: center;
        }
        
        .admin-input-wrapper input[type="text"] {
            flex: 1;
            padding: 8px 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            background: var(--bg2);
            color: var(--text);
            font-size: 13px;
            outline: none;
            min-height: 36px;
        }
        
        .admin-input-wrapper input[type="text"]:focus {
            border-color: var(--primary);
        }
        
        .admin-input-wrapper .send-btn-admin {
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            background: var(--primary-light);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            height: 36px;
            white-space: nowrap;
        }
        
        .admin-input-wrapper .send-btn-admin:hover {
            background: var(--primary-dark);
        }
        
        .admin-input-wrapper .send-btn-admin:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .chat-app-user .chat-header {
            background: var(--primary);
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            flex-shrink: 0;
            min-height: 60px;
            box-shadow: 0 2px 20px rgba(5,134,147,.15);
            padding-top: calc(14px + var(--safe-area-top));
        }
        
        .chat-app-user .chat-header .chat-info {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .chat-app-user .chat-header .chat-info .avatar {
            width: 42px;
            height: 42px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: grid;
            place-items: center;
            color: #fff;
            font-size: 18px;
            flex-shrink: 0;
            border: 2px solid rgba(255,255,255,0.2);
        }
        
        .chat-app-user .chat-header .chat-info .details .name {
            font-size: 16px;
            font-weight: 800;
            color: #fff;
        }
        
        .chat-app-user .chat-header .chat-info .details .status {
            font-size: 11px;
            color: rgba(255,255,255,0.8);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .chat-app-user .chat-header .chat-info .details .status .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #2ECC71;
            animation: pulse 1.5s ease-in-out infinite;
        }
        
        .chat-app-user .chat-input-wrapper {
            display: flex;
            gap: 8px;
            padding: 8px 16px;
            padding-bottom: calc(8px + var(--safe-area-bottom));
            background: var(--surface);
            border-top: 1px solid var(--border);
            flex-shrink: 0;
            align-items: center;
        }
        
        .chat-app-user .chat-input-wrapper input[type="text"] {
            flex: 1;
            padding: 8px 12px;
            border: 2px solid var(--border);
            border-radius: 8px;
            background: var(--bg2);
            color: var(--text);
            font-size: 13px;
            outline: none;
            min-height: 36px;
        }
        
        .chat-app-user .chat-input-wrapper input[type="text"]:focus {
            border-color: var(--primary);
        }
        
        .chat-app-user .chat-input-wrapper .send-btn {
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            background: var(--primary-light);
            color: #fff;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            height: 36px;
            white-space: nowrap;
        }
        
        .chat-app-user .chat-input-wrapper .send-btn:hover {
            background: var(--primary-dark);
        }
        
        .chat-app-user .chat-input-wrapper .send-btn:active {
            transform: scale(0.95);
        }
        
        .new-msg-notification {
            position: sticky;
            bottom: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--primary);
            color: #fff;
            padding: 8px 20px;
            border-radius: 30px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            display: none;
            z-index: 10;
            box-shadow: var(--shadow-lg);
            border: 1px solid rgba(255,255,255,0.2);
            width: fit-content;
            margin: 0 auto;
            animation: notificationPop 0.4s var(--ease-spring);
        }
        
        .new-msg-notification.show {
            display: block;
        }
        
        @keyframes notificationPop {
            0% { opacity: 0; transform: translateX(-50%) scale(0.8); }
            100% { opacity: 1; transform: translateX(-50%) scale(1); }
        }
        
        /* ============================================================
           AUTH PAGES
           ============================================================ */
        .auth-body {
            background: var(--bg);
            padding: 24px 20px;
            flex: 1;
            overflow-y: auto;
        }
        
        .auth-body .form-group input {
            background: var(--surface);
            border: 2px solid var(--border);
            border-radius: 10px;
            color: var(--text);
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
            padding: 10px 14px;
            width: 100%;
        }
        
        .auth-body .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--acSh);
        }
        
        .auth-body .btn-submit {
            width: 100%;
            padding: 14px;
            border: none;
            border-radius: 12px;
            background: var(--primary-light);
            color: #fff;
            font-weight: 700;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
        }
        
        .auth-body .btn-submit:active {
            transform: scale(0.97);
        }
        
        .auth-body .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .auth-icon {
            text-align: center;
            font-size: 48px;
            color: var(--primary);
            margin-bottom: 12px;
        }
        
        /* ============================================================
           OTP
           ============================================================ */
        .otp-container {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin: 20px 0;
        }
        
        .otp-input {
            width: 50px;
            height: 60px;
            text-align: center;
            font-size: 24px;
            font-weight: 700;
            border: 2px solid var(--border);
            border-radius: 12px;
            background: var(--bg2);
            color: var(--text2);
            outline: none;
            transition: all 0.3s;
        }
        
        .otp-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px var(--acSh);
        }
        
        .otp-input.filled {
            border-color: var(--primary);
            background: var(--surface);
        }
        
        @media(max-width:480px) {
            .otp-input {
                width: 40px;
                height: 50px;
                font-size: 20px;
            }
            .otp-container {
                gap: 8px;
            }
        }
        
        /* ============================================================
           LOGOUT BOTTOM SHEET
           ============================================================ */
        .bottom-sheet-overlay {
            position: fixed;
            inset: 0;
            z-index: var(--z-overlay);
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            display: none;
            opacity: 0;
            transition: opacity 0.3s var(--ease-out);
        }
        
        .bottom-sheet-overlay.open {
            display: block;
            opacity: 1;
        }
        
        .logout-bottom-sheet {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            z-index: var(--z-dialog);
            background: var(--surface);
            border-radius: 24px 24px 0 0;
            padding: 24px 20px 32px;
            transform: translateY(100%);
            transition: transform 0.4s var(--ease-spring);
            box-shadow: 0 -10px 40px rgba(0,0,0,0.15);
            padding-bottom: calc(32px + var(--safe-area-bottom));
        }
        
        .logout-bottom-sheet.open {
            transform: translateY(0);
        }
        
        .logout-bottom-sheet .sheet-handle {
            width: 40px;
            height: 4px;
            background: var(--border);
            border-radius: 4px;
            margin: 0 auto 16px;
        }
        
        .logout-bottom-sheet .sheet-title {
            font-size: 18px;
            font-weight: 800;
            color: var(--text2);
            text-align: center;
            margin-bottom: 8px;
        }
        
        .logout-bottom-sheet .sheet-subtitle {
            font-size: 14px;
            color: var(--text3);
            text-align: center;
            margin-bottom: 20px;
        }
        
        .logout-bottom-sheet .sheet-actions {
            display: flex;
            gap: 12px;
        }
        
        .logout-bottom-sheet .sheet-actions .btn-cancel-sheet {
            flex: 1;
            padding: 14px;
            border: 2px solid var(--border);
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            background: var(--surface);
            color: var(--text2);
        }
        
        .logout-bottom-sheet .sheet-actions .btn-confirm {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s var(--ease-spring);
            background: var(--red);
            color: #fff;
        }
        
        .logout-bottom-sheet .sheet-actions .btn-confirm:hover {
            background: #C0392B;
            transform: scale(1.02);
        }
        
        /* ============================================================
           ORDER SUCCESS
           ============================================================ */
        .order-success-overlay {
            position: fixed;
            inset: 0;
            z-index: var(--z-admin-overlay);
            background: rgba(0,0,0,0.4);
            backdrop-filter: blur(8px);
            display: none;
            justify-content: center;
            align-items: center;
            animation: fadeInOverlay 0.3s var(--ease-out);
            padding: var(--safe-area-top) var(--safe-area-right) var(--safe-area-bottom) var(--safe-area-left);
        }
        
        .order-success-overlay.open {
            display: flex;
        }
        
        .order-success-box {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 30px 24px 24px;
            max-width: 480px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-lg);
            animation: formSlideIn 0.4s var(--ease-spring);
        }
        
        .order-success-box .order-details-card {
            background: var(--bg2);
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
        }
        
        /* ============================================================
           BALANCE ROW
           ============================================================ */
        .balance-row {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 18px;
            box-shadow: var(--shadow);
        }
        
        /* ============================================================
           POLICY SECTION
           ============================================================ */
        .policy-section {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 12px;
        }
        
        .policy-content.open {
            max-height: 500px !important;
        }
        
        /* ============================================================
           GOOGLE LOGIN
           ============================================================ */
        .g_id_signin {
            margin: 10px 0;
            display: flex;
            justify-content: center;
            min-height: 50px;
        }
        
        .g_id_signin > div {
            width: 100% !important;
            max-width: 320px !important;
        }
        
        /* ============================================================
         /* تصميم موحد ومنسق لجميع أنواع الإشعارات والتنبيهات المنبثقة */
.toast, 
.alert, 
.notification, 
.swal2-popup, 
div[class*="notification"], 
div[class*="toast"] {
    background-color: #0f3d1c !important; /* اللون الأخضر الداكن الجديد */
    color: #ffffff !important;           /* لون الخط أبيض وواضح */
    border-radius: 14px !important;      /* زوايا دائرية أكثر نعومة */
    padding: 16px 22px !important;
    box-shadow: 0 8px 24px rgba(15, 61, 28, 0.25) !important; /* ظل متناسق مع لون الهوية */
    font-size: 14px !important;
    font-weight: 500 !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important; /* إطار خفيف وداعم للتصميم */
}

/* تخصيص لون أيقونات الخطأ أو التحذير داخل الإشعار لتكون واضحة وجذابة */
.toast .fa-times-circle, 
.alert .error-icon, 
.swal2-icon-error {
    color: #ff6b6b !important;
}

/* تخصيص لون أيقونات النجاح داخل الإشعار */
.toast .fa-check-circle, 
.alert .success-icon, 
.swal2-icon-success {
    color: #4cd137 !important;
}
        /* ============================================================
           LIGHTBOX
           ============================================================ */
        #lightbox {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.92);
            z-index: var(--z-dialog);
            justify-content: center;
            align-items: center;
            flex-direction: column;
            gap: 20px;
            padding: var(--safe-area-top) var(--safe-area-right) var(--safe-area-bottom) var(--safe-area-left);
        }
        
        #lightbox.active {
            display: flex;
        }
        
        /* ============================================================
           RESPONSIVE
           ============================================================ */
        @media(max-width:480px) {
            .topbar {
                padding: 6px 12px;
                min-height: 48px;
            }
            .logo .logo-icon {
                width: 40px;
                height: 40px;
                font-size: 16px;
            }
            .icon-btn {
                width: 34px;
                height: 34px;
                font-size: 12px;
            }
            .admin-chat-sidebar {
                width: 180px;
            }
            .admin-chat-container {
                height: 400px;
            }
            .admin-page .admin-body .admin-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            .notification-item {
                padding: 12px;
                flex-wrap: wrap;
            }
            .notification-item .notif-actions {
                width: 100%;
                justify-content: flex-end;
                flex-direction: row;
            }
        }
        
        @media(max-width:768px) {
            .admin-chat-sidebar {
                width: 200px;
            }
            .admin-chat-container {
                height: 450px;
            }
        }
        
        @media(min-width:1024px) {
            .scroll-area {
                max-width: 1200px;
                margin: 0 auto;
                padding-left: 20px;
                padding-right: 20px;
            }
            .bottom-nav-wrapper {
                max-width: 1200px;
                left: 50%;
                right: auto;
                transform: translateX(-50%);
                width: 100%;
                padding-left: 20px;
                padding-right: 20px;
            }
            .admin-page .admin-body .admin-stats {
                grid-template-columns: repeat(4, 1fr);
            }
            .products-grid {
                grid-template-columns: repeat(3, 1fr) !important;
            }
            .categories-grid {
                grid-template-columns: repeat(3, 1fr) !important;
            }
        }
        
        /* ============================================================
           UTILITIES
           ============================================================ */
        .hidden {
            display: none !important;
        }
        .text-center {
            text-align: center;
        }
        .mt-8 {
            margin-top: 8px;
        }
        .mt-12 {
            margin-top: 12px;
        }
        .mt-16 {
            margin-top: 16px;
        }
        .gap-8 {
            gap: 8px;
        }
        .gap-12 {
            gap: 12px;
        }
        .flex {
            display: flex;
        }
        .flex-col {
            flex-direction: column;
        }
        .items-center {
            align-items: center;
        }
        .justify-between {
            justify-content: space-between;
        }
        .flex-1 {
            flex: 1;
        }
        .w-full {
            width: 100%;
        }
    html, body {
    height: 100%;
    margin: 0;
    overflow: auto;
    overscroll-behavior: none;
}

#app {
    height: 100vh;
    display: flex;
    flex-direction: column;
}

#mainContent {
    height: 100%;
    display: flex;
    flex-direction: column;
}

.scroll-area {
    flex: 1 1 auto !important;
    height: 0 !important;
    min-height: 0;
    overflow-y: scroll !important;
    -webkit-overflow-scrolling: touch !important;
    touch-action: pan-y !important;
}
.product-card .favorite-icon {
    ...
}

/* الصفحة الرئيسية فقط */
#page-home .products-grid {
    display: flex;
    overflow-x: auto;
    overflow-y: hidden;
    gap: 12px;
    scroll-behavior: smooth;
    -webkit-overflow-scrolling: touch;
}

#page-home .product-card {
    flex: 0 0 130px;
    width: 130px;
    min-width: 130px;
    height: 160px;
}


/* صفحة عرض كل المنتجات */
.all-products-page .products-grid,
.category-products-page .products-grid {
    display: grid !important;
    grid-template-columns: repeat(2, 1fr) !important;
    gap: 12px;
    overflow: visible !important;
}


.all-products-page .products-grid,
.category-products-page .products-grid,
.favorites-page .favorites-grid {
    display: grid !important;
    grid-template-columns: repeat(auto-fill, minmax(140px, 140px));
    justify-content: center;
    gap: 12px;
    padding: 12px;
    overflow: visible !important;
}


.all-products-page .product-card,
.category-products-page .product-card,
.favorites-page .product-card {
    width: 100% !important;
    min-width: 0 !important;
    max-width: none !important;
    height: 170px !important;
}
/* منتجات الصفحة الرئيسية فقط */
#page-home .products-grid {
    display: flex;
    overflow-x: auto;
    overflow-y: hidden;
    gap: 12px;
    padding: 12px;
}

#page-home .product-card {
    width: 100%;
    min-width: 0;
    max-width: none;
}
.favorites-grid {
    display: grid !important;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    padding: 16px;
}

.favorites-grid .product-card {
    width: 100%;
    min-width: 0;
}
/* تغيير خلفية ولون بطاقة عرض الإشعارات لتكون واضحة ومتناسقة */
.notifications-page .card, 
.notifications-list .card, 
.notification-card,
div[class*="notification"] {
    background-color: #ffffff !important; /* جعل خلفية البطاقة بيضاء مثلاً لتبرز بوضوح */
    color: #333333 !important;           /* لون النصوص داخل البطاقة ليصبح داكناً وواضَحاً */
    border: 1px solid #e0e0e0 !important; /* إطار خفيف وناعم للبطاقة */
    box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05) !important;
}

/* تنسيق النصوص والأيقونات داخل البطاقة عندما تكون الخلفية فاتحة */
.notifications-page .card p, 
.notifications-page .card h3,
.notifications-page .card span {
    color: #333333 !important;
}

/* إذا كنت ترغب في جعل لون البطاقة بدرجة أخرى غير الأبيض (مثلاً تدرج هادئ) استبدل الكود بالأعلى بالدرجة التي تريدها */
    </style>
</head>
<body>

<div id="app">
    <!-- ===== MAIN CONTENT ===== -->
    <div id="mainContent" class="visible">
        
        <!-- ===== HEADER ===== -->
        <header>
            <div class="topbar">
                <div class="topbar-left">
                    <button class="icon-btn" id="notifBtn" aria-label="الإشعارات">
                        <i class="fa-solid fa-bell"></i>
                        <span class="badge" id="notifBadge">0</span>
                    </button>
                   
                </div>
                <div class="topbar-right">
                    <div class="logo" onclick="switchPage('page-home')" role="button" tabindex="0">
                        <img class="logo-icon" id="siteLogo" src="" alt="Logo">
                        <!-- تم إزالة النص -->
                    </div>
                </div>
            </div>
        </header>

        <!-- ===== SCROLL AREA ===== -->
        <div class="scroll-area" id="scrollArea">
            
         <!-- Home -->
    <div class="page active" id="page-home">
        <div class="countdown-section" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:10px 14px;margin:10px 0 14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;box-shadow:var(--shadow);">
            <div class="label" style="font-size:12px;font-weight:700;color:var(--text2);display:flex;align-items:center;gap:6px;">
                <i class="fa-solid fa-clock" style="color:var(--secondary);font-size:14px;"></i>
                <span>عروض الخصم ينتهى خلال</span>
            </div>
            <div class="discount-timer" style="display: flex; gap: 10px; direction: ltr;">
                <span class="t-hours">02</span>:<span class="t-minutes">00</span>:<span class="t-seconds">00</span>
            </div>
        </div>
        
        <div id="categoryBannersContainer"></div>
    </div> <!-- نهاية صفحة الرئيسية -->

    <div style="height: 20px;"></div>
            <!-- Categories -->
            <div class="page" id="page-categories">
                <div class="category-search-wrapper" style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:16px;box-shadow:var(--shadow);">
                    <div style="display:flex;flex-direction:column;gap:10px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="category-title" style="font-size:16px;font-weight:800;color:var(--text2);white-space:nowrap;">
                                <i class="fa-solid fa-grid-2" style="color:var(--primary);"></i> 
                            </span>
                            <div class="category-search" style="flex:1;display:flex;align-items:center;gap:8px;background:var(--bg2);border:2px solid var(--border);border-radius:10px;padding:6px 12px;transition:border-color 0.3s;">
                                <i class="fa-solid fa-search" style="color:var(--text3);"></i>
                                <input type="text" id="categorySearchInput" placeholder="ابحث عن تصنيف أو منتج..." style="flex:1;border:none;background:transparent;outline:none;color:var(--text);font-size:13px;padding:6px 0;">
                                <button id="clearSearchBtn" style="background:none;border:none;color:var(--text3);cursor:pointer;padding:4px 8px;display:none;font-size:14px;" onclick="document.getElementById('categorySearchInput').value='';performCategorySearch();">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span style="font-size:12px;font-weight:600;color:var(--text3);"> السعر:</span>
                            <div style="display:flex;align-items:center;gap:6px;">
                                <span style="font-size:11px;color:var(--text3);">من</span>
                                <input type="number" id="minPriceInput" placeholder="0" min="0" step="1" 
                                       style="width:70px;padding:4px 8px;border:2px solid var(--border);border-radius:6px;background:var(--bg2);font-size:12px;outline:none;">
                                <span style="font-size:11px;color:var(--text3);">إلى</span>
                                <input type="number" id="maxPriceInput" placeholder="1000" min="0" step="1" 
                                       style="width:70px;padding:4px 8px;border:2px solid var(--border);border-radius:6px;background:var(--bg2);font-size:12px;outline:none;">
                                <span style="font-size:11px;color:var(--text3);">د.ع</span>
                            </div>
                            <button id="searchBtn" onclick="performCategorySearch()" style="padding:4px 14px;border:none;border-radius:8px;background:var(--primary-light);color:#fff;font-size:12px;font-weight:700;cursor:pointer;">
                                <i class="fa-solid fa-magnifying-glass"></i> بحث
                            </button>
                        </div>
                    </div>
                </div>
                <div class="categories-grid" id="categoriesGrid"></div>
            </div>

            <!-- Cart -->
            <div class="page" id="page-cart">
                <h2 style="font-size:18px;font-weight:800;margin-bottom:14px;color:var(--text2);">
                    <i class="" style="color:var(--primary);"></i> 
                    <span id="cartTitle"> </span>
                </h2>
                <div id="cartContent"></div>
                <div id="checkoutSection" style="display:none;"></div>
            </div>

            <!-- Account -->
            <div class="page" id="page-account">
                <h2 style="font-size:18px;font-weight:800;margin-bottom:14px;color:var(--text2);">
                    <i class="" style="color:var(--primary);"></i> 
                    <span id="accountTitle"></span>
                </h2>
                
                <div class="balance-row" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
                    <div class="balance-info" style="display:flex;align-items:center;gap:12px;">
                        <div class="bal-icon" style="width:40px;height:40px;border-radius:50%;background:var(--acSh);display:grid;place-items:center;font-size:18px;color:var(--primary);">
                            <i class="fa-solid fa-wallet"></i>
                        </div>
                        <div class="bal-text">
                            <span class="bal-label" style="font-size:11px;color:var(--text3);">رصيدك </span>
                            <span class="bal-amount" id="userBalance" style="font-size:20px;font-weight:900;color:var(--primary);">0.00 د.ع</span>
                        </div>
                    </div>
                    <button class="add-balance-btn" id="addBalanceBtn" style="padding:8px 20px;border:none;border-radius:6px;background:var(--primary-light);color:#fff;font-weight:400;font-size:13px;cursor:pointer;">
                        <i class="fa-solid fa-plus"></i> شحن الرصيد
                    </button>
                </div>

                <div class="auth-buttons" style="display:flex;gap:10px;margin:12px 0;flex-wrap:wrap;">
                    <button class="auth-btn active" id="loginBtn" style="flex:1;padding:12px;border:2px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text2);font-size:14px;font-weight:700;cursor:pointer;text-align:center;min-width:120px;">
                        🔑 تسجيل الدخول
                    </button>
                    <button class="auth-btn" id="registerBtn" style="flex:1;padding:12px;border:2px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text2);font-size:14px;font-weight:700;cursor:pointer;text-align:center;min-width:120px;">
                        📝 إنشاء حساب
                    </button>
                </div>
<!-- ===== قائمة الإعدادات - تصميم محسّن ===== -->

<!-- المفضلة -->
<div class="settings-item" id="favoritesMenuItem" style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px;background:var(--surface);border:1px solid var(--border);border-radius:14px;cursor:pointer;transition:all 0.3s var(--ease-spring);margin-bottom:8px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
    <div class="left" style="display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg, #E91E63, #C2185B);display:grid;place-items:center;font-size:18px;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(233,30,99,.25);">
            <i class="fa-solid fa-heart"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;color:var(--text2);">المفضلة</div>
            <div style="font-size:11px;color:var(--text3);">منتجاتك المفضلة</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
        <span id="favCount" style="font-size:12px;font-weight:600;color:var(--text3);background:var(--bg2);padding:2px 10px;border-radius:12px;"> </span>
        <div style="width:32px;height:32px;border-radius:50%;background:var(--bg2);display:grid;place-items:center;font-size:14px;color:var(--text3);transition:all 0.3s var(--ease-spring);">
            <i class="fa-solid fa-chevron-left"></i>
        </div>
    </div>
</div>

<!-- طلباتي -->
<div class="settings-item" id="ordersMenuItem" style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px;background:var(--surface);border:1px solid var(--border);border-radius:14px;cursor:pointer;transition:all 0.3s var(--ease-spring);margin-bottom:8px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
    <div class="left" style="display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg, #058693, #0AA6B5);display:grid;place-items:center;font-size:18px;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(5,134,147,.25);">
            <i class="fa-solid fa-box"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;color:var(--text2);">طلباتي</div>
            <div style="font-size:11px;color:var(--text3);">تتبع طلباتك</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
        <span id="orderCount" style="font-size:12px;font-weight:600;color:var(--text3);background:var(--bg2);padding:2px 10px;border-radius:12px;"> </span>
        <div style="width:32px;height:32px;border-radius:50%;background:var(--bg2);display:grid;place-items:center;font-size:14px;color:var(--text3);transition:all 0.3s var(--ease-spring);">
            <i class="fa-solid fa-chevron-left"></i>
        </div>
    </div>
</div>

<!-- شحن الرصيد -->
<div class="settings-item" id="rechargeMenuItem" style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px;background:var(--surface);border:1px solid var(--border);border-radius:14px;cursor:pointer;transition:all 0.3s var(--ease-spring);margin-bottom:8px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
    <div class="left" style="display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg, #D8B07A, #C49A5E);display:grid;place-items:center;font-size:18px;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(216,176,122,.25);">
            <i class="fa-solid fa-wallet"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;color:var(--text2);">شحن الرصيد</div>
            <div style="font-size:11px;color:var(--text3);">إضافة رصيد لحسابك</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
        <div style="width:32px;height:32px;border-radius:50%;background:var(--bg2);display:grid;place-items:center;font-size:14px;color:var(--text3);transition:all 0.3s var(--ease-spring);">
            <i class="fa-solid fa-chevron-left"></i>
        </div>
    </div>
</div>

<!-- إعدادات الحساب -->
<div class="settings-item" id="accountSettingsMenuItem" style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px;background:var(--surface);border:1px solid var(--border);border-radius:14px;cursor:pointer;transition:all 0.3s var(--ease-spring);margin-bottom:8px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
    <div class="left" style="display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg, #6C5CE7, #4834D4);display:grid;place-items:center;font-size:18px;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(108,92,231,.25);">
            <i class="fa-solid fa-gear"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;color:var(--text2);">إعدادات الحساب</div>
            <div style="font-size:11px;color:var(--text3);">تعديل الملف الشخصي</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
        <div style="width:32px;height:32px;border-radius:50%;background:var(--bg2);display:grid;place-items:center;font-size:14px;color:var(--text3);transition:all 0.3s var(--ease-spring);">
            <i class="fa-solid fa-chevron-left"></i>
        </div>
    </div>
</div>

<!-- الدردشة مع الدعم -->
<div class="settings-item" id="chatSupportMenuItem" style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px;background:var(--surface);border:1px solid var(--border);border-radius:14px;cursor:pointer;transition:all 0.3s var(--ease-spring);margin-bottom:8px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
    <div class="left" style="display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg, #00B894, #00A381);display:grid;place-items:center;font-size:18px;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(0,184,148,.25);">
            <i class="fa-solid fa-headset"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;color:var(--text2);">الدعم الفني</div>
            <div style="font-size:11px;color:var(--text3);">تواصل مع فريق الدعم</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:10px;background:#2ECC71;color:#fff;padding:2px 10px;border-radius:20px;font-weight:700;">متصل</span>
        <div style="width:32px;height:32px;border-radius:50%;background:var(--bg2);display:grid;place-items:center;font-size:14px;color:var(--text3);transition:all 0.3s var(--ease-spring);">
            <i class="fa-solid fa-chevron-left"></i>
        </div>
    </div>
</div>

<!-- لوحة الإدارة (للمدير فقط) -->
<div class="settings-item" id="adminPanelMenuItem" style="display:none;align-items:center;justify-content:space-between;padding:16px 18px;background:linear-gradient(135deg, #1a1a2e, #16213e);border:1px solid rgba(255,255,255,0.1);border-radius:14px;cursor:pointer;transition:all 0.3s var(--ease-spring);margin-bottom:8px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
    <div class="left" style="display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg, #D8B07A, #C49A5E);display:grid;place-items:center;font-size:18px;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(216,176,122,.25);">
            <i class="fa-solid fa-chart-simple"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;color:#fff;">لوحة الإدارة</div>
            <div style="font-size:11px;color:rgba(255,255,255,0.5);">📊 التحكم الكامل بالمتجر</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:10px;background:#D8B07A;color:#1a1a2e;padding:2px 10px;border-radius:20px;font-weight:700;">Admin</span>
        <div style="width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,0.1);display:grid;place-items:center;font-size:14px;color:rgba(255,255,255,0.5);transition:all 0.3s var(--ease-spring);">
            <i class="fa-solid fa-chevron-left"></i>
        </div>
    </div>
</div>

<!-- ===== إعدادات اللغة ===== -->
<div class="settings-item" id="languageMenuItem" style="display:flex;align-items:center;justify-content:space-between;padding:16px 18px;background:var(--surface);border:1px solid var(--border);border-radius:14px;cursor:pointer;transition:all 0.3s var(--ease-spring);margin-top:4px;box-shadow:0 2px 8px rgba(0,0,0,0.04);">
    <div class="left" style="display:flex;align-items:center;gap:14px;">
        <div style="width:42px;height:42px;border-radius:12px;background:linear-gradient(135deg, #058693, #0AA6B5);display:grid;place-items:center;font-size:18px;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(5,134,147,.25);">
            <i class="fa-solid fa-globe"></i>
        </div>
        <div>
            <div style="font-weight:700;font-size:15px;color:var(--text2);">اللغة</div>
            <div style="font-size:11px;color:var(--text3);">اختر لغة التطبيق المفضلة</div>
        </div>
    </div>
    <div style="display:flex;align-items:center;gap:10px;">
        <span id="currentLangDisplay" style="font-size:13px;font-weight:700;color:var(--primary);background:rgba(5,134,147,.08);padding:4px 14px;border-radius:20px;border:1px solid rgba(5,134,147,.15);">
            <i class="fa-solid fa-check" style="font-size:10px;color:var(--green);margin-left:4px;"></i>
            العربية
        </span>
        <div style="width:32px;height:32px;border-radius:50%;background:var(--bg2);display:grid;place-items:center;font-size:14px;color:var(--text3);transition:all 0.3s var(--ease-spring);">
            <i class="fa-solid fa-chevron-left"></i>
        </div>
    </div>
</div>
                <!-- Policy & Terms -->
                <div style="margin-top:20px;border-top:1px solid var(--border);padding-top:16px;">
                    <div class="policy-section">
                        <div class="policy-title" onclick="togglePolicy('privacy')" style="font-size:14px;font-weight:700;color:var(--text2);margin-bottom:6px;display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <i class="fa-solid fa-shield-halved" style="color:var(--primary);"></i>
                            سياسة الخصوصية
                            <i class="fa-solid fa-chevron-down" style="margin-right:auto;font-size:12px;color:var(--text3);"></i>
                        </div>
                        <div class="policy-content" id="privacyPolicyContent" style="font-size:13px;color:var(--text3);line-height:1.8;max-height:0;overflow:hidden;transition:max-height 0.4s var(--ease-out);">
                            <?php echo nl2br(htmlspecialchars($privacyPolicy)); ?>
                        </div>
                    </div>
                    
                    <div class="policy-section">
                        <div class="policy-title" onclick="togglePolicy('terms')" style="font-size:14px;font-weight:700;color:var(--text2);margin-bottom:6px;display:flex;align-items:center;gap:8px;cursor:pointer;">
                            <i class="fa-solid fa-file-contract" style="color:var(--secondary);"></i>
                            الشروط والأحكام
                            <i class="fa-solid fa-chevron-down" style="margin-right:auto;font-size:12px;color:var(--text3);"></i>
                        </div>
                        <div class="policy-content" id="termsPolicyContent" style="font-size:13px;color:var(--text3);line-height:1.8;max-height:0;overflow:hidden;transition:max-height 0.4s var(--ease-out);">
                            <?php echo nl2br(htmlspecialchars($termsConditions)); ?>
                        </div>
                    </div>
                    
                    <div class="app-version" style="text-align:center;padding:16px 0;color:var(--text3);font-size:12px;">
                   fastcrand.com     <i class="fa-solid fa-code"></i> الإصدار <span style="font-weight:700;color:var(--primary);"><?php echo $appVersion; ?></span>
                    </div>
                </div>

               
                </div>
            </div>
        </div>

        <!-- ============================================================
           SLIDING PAGES
           ============================================================ -->
        
        <!-- Product Detail -->
        <div class="product-detail-page" id="productDetailPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeProductDetail()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2 id="detailTitle">تفاصيل المنتج</h2>
            </div>
            <div class="detail-body" id="detailBody"></div>
        </div>

        <!-- Category Products -->
        <div class="category-products-page" id="categoryProductsPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeCategoryProducts()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2 id="catProductsTitle">المنتجات</h2>
            </div>
            <div class="products-grid" id="categoryProductsGrid"></div>
        </div>

        <!-- All Products -->
        <div class="all-products-page" id="allProductsPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeAllProducts()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2 id="allProductsTitle">جميع المنتجات</h2>
            </div>
            <div class="products-grid" id="allProductsGrid"></div>
        </div>

  <!-- Notifications -->
<div class="settings-page" id="page-notifications">
    <div class="detail-header">
        <button class="back-btn" onclick="closeSettingsPage('page-notifications')"><i class="fa-solid fa-arrow-right"></i></button>
        <h2> الإشعارات</h2>
    </div>
    <div class="settings-page-body">
        <!-- شريط الإجراءات والزر الملون -->
        <div class="d-flex justify-content-between align-items-center mb-3 px-1" id="notifications-actions">
            
        </div>
        <div id="notificationsList">
            <!-- سيتم حقن الإشعارات ديناميكياً هنا عبر جافاسكريبت -->
        </div>
    </div>
</div>
        <!-- Favorites -->
        <div class="favorites-page" id="favoritesPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeFavorites()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>❤️ المفضلة</h2>
            </div>
            <div class="favorites-grid" id="favoritesGrid"></div>
        </div>

        <!-- Orders -->
        <div class="orders-page" id="ordersPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeOrders()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>📦 طلباتي</h2>
            </div>
            <div class="orders-list" id="ordersList"></div>
        </div>

        <!-- Order Detail -->
        <div class="order-detail-page" id="orderDetailPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeOrderDetail()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2 id="orderDetailTitle">تفاصيل الطلب</h2>
            </div>
            <div class="order-detail-body" id="orderDetailBody"></div>
        </div>

        <!-- Recharge -->
        <div class="recharge-page" id="rechargePage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeRecharge()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>💰 شحن الرصيد</h2>
            </div>
            <div class="recharge-body" id="rechargeBody">
                <div id="rechargeForm"></div>
            </div>
        </div>

        <!-- Chat Support -->
        <div class="settings-page" id="page-chat-support">
            <div class="detail-header">
                <button class="back-btn" onclick="closeChatPage()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>💬 الدردشة مع الدعم</h2>
            </div>
            <div class="settings-page-body" id="chatSupportBody"></div>
        </div>

        <!-- Account Settings -->
        <div class="settings-page" id="page-account-settings">
            <div class="detail-header">
                <button class="back-btn" onclick="closeSettingsPage('page-account-settings')"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>⚙️ إعدادات الحساب</h2>
            </div>

<?php
// تأكد من تشغيل الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// التحقق من أن المستخدم مسجل الدخول وجلب بياناته من قاعدة البيانات
$user = null;
if (isset($_SESSION['user_id'])) {
    $userId = $_SESSION['user_id'];
    // جلب بيانات المستخدم الحالية
    $user = queryOne("SELECT * FROM users WHERE id = :id", [':id' => $userId]);
}

// إذا لم يتم العثور على المستخدم، يمكن توجيهه لتسجيل الدخول
if (!$user) {
    // header('Location: /');
    // exit;
}
?>

<form id="settingsForm" method="POST" enctype="multipart/form-data">
    
<!-- ===== نموذج إعدادات الحساب ===== -->
<form id="settingsForm" method="POST" enctype="multipart/form-data">

    <!-- الصورة الشخصية -->
    <div style="text-align:center;margin-bottom:25px;">
        <div style="position:relative;width:90px;height:90px;border-radius:50%;margin:0 auto;overflow:hidden;border:3px solid var(--primary);background:var(--bg2);box-shadow:0 4px 20px rgba(5,134,147,.15);">
            <?php if (!empty($user['avatar_path'])): ?>
                <img src="<?php echo SITE_URL . '/data/uploads/avatars/' . $user['avatar_path']; ?>" style="width:100%;height:100%;object-fit:cover;">
            <?php else: ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:38px;color:var(--text3);">
                    <i class="fa-regular fa-user"></i>
                </div>
            <?php endif; ?>
            <label for="avatarInput" style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.6);color:#fff;text-align:center;padding:5px;font-size:11px;cursor:pointer;transition:0.3s;">
                <i class="fa-solid fa-camera"></i> تغيير
            </label>
            <input type="file" id="avatarInput" name="avatar" accept="image/*" style="display:none;" onchange="previewAvatar(this)">
        </div>
        <div style="font-size:12px;color:var(--text3);margin-top:5px;">اضغط على الصورة لتغييرها</div>
    </div>

    <!-- اسم المستخدم -->
    <div style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--text2);margin-bottom:4px;">
            <i class="fa-regular fa-user" style="color:var(--primary);margin-left:5px;"></i> اسم المستخدم
        </label>
        <input type="text" name="username" value="<?php echo htmlspecialchars($user['name'] ?? ''); ?>" required
               style="width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);outline:none;font-size:14px;transition:0.3s;color:var(--text);">
    </div>
    
    <!-- رقم الهاتف -->
    <div style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--text2);margin-bottom:4px;">
            <i class="fa-solid fa-phone" style="color:var(--primary);margin-left:5px;"></i> رقم الهاتف <span style="color:var(--red);">*</span>
        </label>
        <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" placeholder="مثال: 07700000000" required
               style="width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);outline:none;font-size:14px;transition:0.3s;color:var(--text);">
    </div>
    
    <!-- البريد الإلكتروني -->
    <div style="margin-bottom:14px;">
        <label style="display:block;font-size:13px;font-weight:600;color:var(--text2);margin-bottom:4px;">
            <i class="fa-regular fa-envelope" style="color:var(--primary);margin-left:5px;"></i> البريد الإلكتروني
        </label>
        <input type="email" value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" disabled
               style="width:100%;padding:11px 14px;border:2px solid var(--border);border-radius:10px;background:#f0f0f0;outline:none;font-size:14px;color:#999;cursor:not-allowed;">
    </div>

    <!-- الفاصل -->
    <div style="display:flex;align-items:center;gap:12px;margin:22px 0 16px;">
        <div style="flex:1;height:1px;background:var(--border);"></div>
        <span style="font-size:12px;color:var(--text3);font-weight:600;white-space:nowrap;">
            <i class="fa-solid fa-lock" style="color:var(--primary);margin-left:4px;"></i> شكرا لثقتكم
        </span>
        <div style="flex:1;height:1px;background:var(--border);"></div>
    </div>

    <!-- كلمة المرور الحالية -->
    
    <!-- زر الحفظ -->
    <button type="submit" style="width:100%;padding:13px;border:none;border-radius:10px;background:var(--primary);color:#fff;font-size:15px;font-weight:700;cursor:pointer;transition:0.3s;display:flex;align-items:center;justify-content:center;gap:8px;">
        <i class="fa-regular fa-floppy-disk"></i> حفظ التغييرات
    </button>
</form>

<script>
// ===== معاينة الصورة =====
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const container = input.closest('div').querySelector('div:first-child');
            if (container) {
                container.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
            }
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// ===== إظهار/إخفاء كلمة المرور =====
function togglePassword(btn) {
    const input = btn.closest('div').querySelector('input');
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}

// ===== تأثير التركيز على الحقول =====
document.querySelectorAll('#settingsForm input:not([disabled])').forEach(function(input) {
    input.addEventListener('focus', function() {
        this.closest('div').style.borderColor = 'var(--primary)';
        this.closest('div').style.boxShadow = '0 0 0 3px rgba(5,134,147,.08)';
        if (this.closest('div').querySelector('button')) {
            this.closest('div').style.borderColor = 'var(--primary)';
        }
    });
    input.addEventListener('blur', function() {
        this.closest('div').style.borderColor = 'var(--border)';
        this.closest('div').style.boxShadow = 'none';
        if (this.closest('div').querySelector('button')) {
            this.closest('div').style.borderColor = 'var(--border)';
        }
    });
});

// ===== تأثير hover على زر الحفظ =====
document.querySelector('#settingsForm button[type="submit"]').addEventListener('mouseenter', function() {
    this.style.transform = 'translateY(-2px)';
    this.style.boxShadow = '0 6px 20px rgba(5,134,147,.3)';
});
document.querySelector('#settingsForm button[type="submit"]').addEventListener('mouseleave', function() {
    this.style.transform = 'translateY(0)';
    this.style.boxShadow = 'none';
});
</script>

<style>
/* ===== تحسين الحقول عند التركيز ===== */
#settingsForm input:not([disabled]):focus {
    border-color: var(--primary) !important;
    box-shadow: 0 0 0 3px rgba(5,134,147,.08);
    background: var(--surface);
}

/* ===== تحسين زر كلمة المرور ===== */
#settingsForm div:has(button) input:focus {
    border-color: transparent !important;
    box-shadow: none !important;
}

/* ===== تحسين الصورة الشخصية ===== */
#settingsForm div:has(img) {
    transition: 0.3s;
}
</style>

<!-- كود المعالجة والإرسال الخفي (AJAX) -->
<script>
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    formData.append('action', 'updateProfile');
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        alert(data.message);
        if(data.status) {
            location.reload();
        }
    })
    .catch(err => {
        alert('حدث خطأ أثناء الاتصال بالخادم.');
    });
});
</script>
                </div>
            </div>
        </div>
        <!-- Language Settings -->
        <div class="settings-page" id="page-language-settings">
            <div class="detail-header">
                <button class="back-btn" onclick="closeSettingsPage('page-language-settings')">
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
                <h2>🌐 اللغة</h2>
            </div>
            <div class="settings-page-body">
                <div style="display:flex;flex-direction:column;gap:12px;padding:8px 0;">
                    <div onclick="setLanguage('ar')" 
                         style="display:flex;align-items:center;gap:16px;padding:16px 20px;background:var(--surface);border:2px solid ${currentLang === 'ar' ? 'var(--primary)' : 'var(--border)'};border-radius:14px;cursor:pointer;transition:all 0.3s;">
                        <div style="width:48px;height:48px;border-radius:50%;background:var(--acSh);display:grid;place-items:center;font-size:24px;color:var(--primary);">
                            <i class="fa-solid fa-language"></i>
                        </div>
                        <div style="flex:1;">
                            <div style="font-weight:700;font-size:16px;">العربية</div>
                            <div style="font-size:12px;color:var(--text3);">اللغة العربية (Arabic)</div>
                        </div>
                        ${currentLang === 'ar' ? '<i class="fa-solid fa-circle-check" style="font-size:24px;color:var(--green);"></i>' : ''}
                    </div>
                    
                    <div onclick="setLanguage('en')" 
                         style="display:flex;align-items:center;gap:16px;padding:16px 20px;background:var(--surface);border:2px solid ${currentLang === 'en' ? 'var(--primary)' : 'var(--border)'};border-radius:14px;cursor:pointer;transition:all 0.3s;">
                        <div style="width:48px;height:48px;border-radius:50%;background:var(--acSh);display:grid;place-items:center;font-size:24px;color:var(--primary);">
                            <i class="fa-solid fa-language"></i>
                        </div>
                        <div style="flex:1;">
                            <div style="font-weight:700;font-size:16px;">English</div>
                            <div style="font-size:12px;color:var(--text3);">اللغة الإنجليزية (English)</div>
                        </div>
                        ${currentLang === 'en' ? '<i class="fa-solid fa-circle-check" style="font-size:24px;color:var(--green);"></i>' : ''}
                    </div>
                </div>
            </div>
        </div>

        <!-- Auth Pages -->
        <div class="auth-page" id="loginPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeLoginPage()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>🔑 تسجيل الدخول</h2>
            </div>
            <div class="auth-body">
                <div class="auth-icon"><i class="fa-solid fa-user-circle"></i></div>
                <h2 style="text-align:center;font-size:22px;font-weight:800;color:var(--text2);">مرحباً بعودتك</h2>
                <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:20px;">سجل دخولك للوصول إلى حسابك والمزايا الحصرية</p>
                
                <div id="googleLoginContainer"></div>
                
                <div style="text-align:center;margin:12px 0;color:var(--text3);font-size:13px;">أو</div>
                
                <form id="loginForm">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">البريد الإلكتروني أو رقم الهاتف</label>
                        <input type="text" id="loginIdentifier" placeholder="example@email.com أو 07700000000" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">كلمة المرور</label>
                        <input type="password" id="loginPassword" placeholder="••••••••" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div id="loginError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;"></div>
                    <button type="button" class="btn-submit" onclick="handleLogin()">تسجيل الدخول</button>
                    <div style="text-align:left;margin-top:8px;font-size:13px;">
                        <a onclick="closeLoginPage();openForgotPasswordPage();" style="color:var(--text3);cursor:pointer;text-decoration:underline;">
                            🔑 نسيت كلمة المرور؟
                        </a>
                    </div>
                </form>
                <div class="auth-switch" style="text-align:center;margin-top:16px;font-size:14px;color:var(--text3);">
                    ليس لديك حساب؟ <a id="switchToRegister" style="color:var(--primary);font-weight:700;cursor:pointer;">سجل الآن</a>
                </div>
            </div>
        </div>

        <div class="auth-page" id="registerPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeRegisterPage()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>📝 إنشاء حساب</h2>
            </div>
            <div class="auth-body">
                <div class="auth-icon"><i class="fa-solid fa-user-plus"></i></div>
                <h2 style="text-align:center;font-size:22px;font-weight:800;color:var(--text2);">انضم إلينا</h2>
                <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:20px;">سجل الآن للاستفادة من جميع المزايا والعروض</p>
                
                <div id="googleRegisterContainer"></div>
                
                <div style="text-align:center;margin:12px 0;color:var(--text3);font-size:13px;">أو</div>
                
                <form id="registerForm">
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">الاسم الكامل</label>
                        <input type="text" id="registerName" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">البريد الإلكتروني</label>
                        <input type="email" id="registerEmail" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">رقم الهاتف</label>
                        <input type="tel" id="registerPhone" placeholder="07XX XXX XXXX" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">كلمة المرور</label>
                        <input type="password" id="registerPassword" required style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    <div id="registerError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;"></div>
                    <button type="button" class="btn-submit" onclick="handleRegister()">إنشاء الحساب</button>
                </form>
                <div class="auth-switch" style="text-align:center;margin-top:16px;font-size:14px;color:var(--text3);">
                    لديك حساب؟ <a id="switchToLogin" style="color:var(--primary);font-weight:700;cursor:pointer;">سجل دخول</a>
                </div>
            </div>
        </div>

        <!-- Forgot Password -->
        <div class="auth-page" id="forgotPasswordPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeForgotPasswordPage()">
                    <i class="fa-solid fa-arrow-right"></i>
                </button>
                <h2>🔐 نسيان كلمة المرور</h2>
            </div>
            <div class="auth-body">
                <div class="auth-icon"><i class="fa-solid fa-key"></i></div>
                
                <!-- ===== الخطوة 1: إدخال البريد ===== -->
                <div id="resetStep1">
                    <h2 style="text-align:center;font-size:20px;font-weight:800;color:var(--text2);">استعادة كلمة المرور</h2>
                    <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:20px;">أدخل بريدك الإلكتروني وسنرسل لك كود التحقق</p>
                    
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                            <i class="fa-solid fa-envelope"></i> البريد الإلكتروني
                        </label>
                        <input type="email" id="resetEmail" placeholder="example@email.com" required 
                               style="width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    
                    <div id="resetError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                    
                    <button type="button" class="btn-submit" onclick="requestPasswordReset()" style="width:100%;padding:14px;border:none;border-radius:12px;background:var(--primary-light);color:#fff;font-weight:700;font-size:16px;cursor:pointer;">
                        <i class="fa-solid fa-paper-plane"></i> إرسال كود التحقق
                    </button>
                    
                    <div class="auth-switch" style="text-align:center;margin-top:16px;font-size:14px;color:var(--text3);">
                        تذكرت كلمة المرور؟ <a onclick="closeForgotPasswordPage();openLoginPage();" style="color:var(--primary);font-weight:700;cursor:pointer;">تسجيل الدخول</a>
                    </div>
                </div>
                
                <!-- ===== الخطوة 2: إدخال الكود ===== -->
                <div id="resetStep2" style="display:none;">
                    <h2 style="text-align:center;font-size:20px;font-weight:800;color:var(--text2);">✅ تحقق من بريدك</h2>
                    <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:8px;">أدخل رمز التحقق المكون من 6 أرقام المرسل إلى</p>
                    <p style="text-align:center;font-size:14px;font-weight:700;color:var(--text2);" id="resetEmailDisplay">example@email.com</p>
                    <p style="text-align:center;font-size:12px;color:var(--text3);margin:4px 0 16px;">⏰ ينتهي خلال 10 دقائق</p>
                    
                    <div class="otp-container" id="resetOtpContainer">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 0)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 1)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 2)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 3)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 4)">
                        <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="resetOtpInputHandler(this, 5)">
                    </div>
                    
                    <div id="resetCodeError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                    
                    <button type="button" class="btn-submit" onclick="verifyResetCode()" style="width:100%;padding:14px;border:none;border-radius:12px;background:var(--primary-light);color:#fff;font-weight:700;font-size:16px;cursor:pointer;">
                        <i class="fa-solid fa-check"></i> تحقق
                    </button>
                    
                    <div style="text-align:center;margin-top:16px;font-size:13px;color:var(--text3);">
                        لم يصل الكود؟ <a onclick="resendResetCode()" style="color:var(--primary);font-weight:700;cursor:pointer;">إعادة الإرسال</a>
                        <span id="resetResendTimer" style="color:var(--text3);font-size:12px;margin-right:4px;"></span>
                    </div>
                </div>
                
                <!-- ===== الخطوة 3: تعيين كلمة مرور جديدة ===== -->
                <div id="resetStep3" style="display:none;">
                    <h2 style="text-align:center;font-size:20px;font-weight:800;color:var(--text2);">🔑 كلمة مرور جديدة</h2>
                    <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:20px;">أدخل كلمة المرور الجديدة لحسابك</p>
                    
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                            <i class="fa-solid fa-lock"></i> كلمة المرور الجديدة
                        </label>
                        <input type="password" id="resetNewPassword" placeholder="••••••••" required 
                               style="width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                        <small style="color:var(--text3);font-size:11px;">يجب أن تكون 6 أحرف على الأقل</small>
                    </div>
                    
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                            <i class="fa-solid fa-lock"></i> تأكيد كلمة المرور
                        </label>
                        <input type="password" id="resetConfirmPassword" placeholder="••••••••" required 
                               style="width:100%;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
                    </div>
                    
                    <div id="resetPasswordError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                    
                    <button type="button" class="btn-submit" onclick="resetPassword()" style="width:100%;padding:14px;border:none;border-radius:12px;background:var(--green);color:#fff;font-weight:700;font-size:16px;cursor:pointer;">
                        <i class="fa-solid fa-check"></i> تغيير كلمة المرور
                    </button>
                </div>
            </div>
        </div>

        <!-- OTP Verification -->
        <div class="auth-page" id="otpVerificationPage">
            <div class="detail-header">
                <button class="back-btn" onclick="closeOTPPage()"><i class="fa-solid fa-arrow-right"></i></button>
                <h2>🔐 التحقق من البريد</h2>
            </div>
            <div class="auth-body">
                <div class="auth-icon"><i class="fa-solid fa-envelope-circle-check"></i></div>
                <h2 style="text-align:center;font-size:22px;font-weight:800;color:var(--text2);">تحقق من بريدك</h2>
                <p style="text-align:center;font-size:14px;color:var(--text3);margin-bottom:8px;">أدخل رمز التحقق المكون من 6 أرقام المرسل إلى</p>
                <p style="text-align:center;font-size:14px;font-weight:700;color:var(--text2);" id="otpEmailDisplay">example@email.com</p>
                <p style="text-align:center;font-size:12px;color:var(--text3);margin:4px 0 16px;">⏰ ينتهي خلال 10 دقائق</p>
                
                <div class="otp-container" id="otpContainer">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 0)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 1)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 2)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 3)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 4)">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" oninput="otpInputHandler(this, 5)">
                </div>
                
                <div id="otpError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                <div id="otpSuccess" style="color:var(--green);font-size:13px;margin-bottom:12px;display:none;text-align:center;"></div>
                
                <button type="button" class="btn-submit" onclick="verifyOTP()">تحقق</button>
                
                <div style="text-align:center;margin-top:16px;font-size:13px;color:var(--text3);">
                    لم يصل الكود؟ <a id="resendOTP" style="color:var(--primary);font-weight:700;cursor:pointer;" onclick="resendOTP()">إعادة الإرسال</a>
                    <span id="resendTimer" style="color:var(--text3);font-size:12px;margin-right:4px;"></span>
                </div>
            </div>
        </div>

        <!-- User Chat -->
        <div class="chat-app-user" id="chatPageUser">
            <div class="chat-header">
                <button class="back-btn" onclick="closeUserChat()"><i class="fa-solid fa-arrow-right"></i></button>
                <div class="chat-info">
                    <div class="avatar"><i class="fa-solid fa-headset"></i></div>
                    <div class="details">
                        <div class="name">الدعم الفني</div>
                        <div class="status"><span class="dot"></span> متصل الآن</div>
                    </div>
                </div>
            </div>

            <div class="messages-container" id="userMessagesContainer">
                <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;" id="emptyChatMessage">
                    <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                    ابدأ محادثتك مع الدعم الفني
                </div>
                <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
                    <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
                    رسائل جديدة <span id="newMsgCount">0</span>
                </div>
            </div>

            <div class="chat-input-wrapper">
                <input type="text" id="userChatInput" placeholder="اكتب رسالتك هنا..." onkeypress="if(event.key==='Enter') sendUserMessage()" autofocus>
                <button class="send-btn" onclick="sendUserMessage()">
                    <i class="fa-solid fa-paper-plane"></i> إرسال
                </button>
            </div>
        </div>

        <!-- ============================================================
           ADMIN PAGE
           ============================================================ -->
        <div class="admin-page" id="adminPage">
            <div class="admin-header">
                <h2>⚙️ لوحة التحكم</h2>
                <button class="close-admin-btn" id="closeAdminBtn"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="admin-body">
                <div class="admin-stats" id="adminStats">
                    <div class="stat-card">
                        <span class="stat-icon" style="font-size:24px;color:var(--primary);display:block;margin-bottom:4px;opacity:.5;"><i class="fa-solid fa-box"></i></span>
                        <div class="stat-number" id="statProducts">0</div>
                        <div class="stat-label" style="font-size:12px;color:var(--text3);">المنتجات</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="font-size:24px;color:var(--primary);display:block;margin-bottom:4px;opacity:.5;"><i class="fa-solid fa-tags"></i></span>
                        <div class="stat-number" id="statCategories">0</div>
                        <div class="stat-label" style="font-size:12px;color:var(--text3);">التصنيفات</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="font-size:24px;color:var(--primary);display:block;margin-bottom:4px;opacity:.5;"><i class="fa-solid fa-receipt"></i></span>
                        <div class="stat-number" id="statOrders">0</div>
                        <div class="stat-label" style="font-size:12px;color:var(--text3);">الطلبات</div>
                    </div>
                    <div class="stat-card">
                        <span class="stat-icon" style="font-size:24px;color:var(--primary);display:block;margin-bottom:4px;opacity:.5;"><i class="fa-solid fa-users"></i></span>
                        <div class="stat-number" id="statUsers">0</div>
                        <div class="stat-label" style="font-size:12px;color:var(--text3);">المستخدمين</div>
                    </div>
                </div>
                
                <div class="admin-tabs" id="adminTabs">
                    <button class="active" data-tab="tab-products">📦 المنتجات</button>
                    <button data-tab="tab-categories">📂 التصنيفات</button>
                    <button data-tab="tab-orders">📋 الطلبات</button>
                    <button data-tab="tab-payments">💳 الدفع</button>
                    <button data-tab="tab-users">👤 المستخدمين</button>
                    <button data-tab="tab-chats">💬 الدردشة</button>
                    <button data-tab="tab-recharges">💰 الشحن</button>
                    <button data-tab="tab-settings">⚙️ الإعدادات</button>
                </div>
                
                <div class="admin-tab-content active" id="tab-products">
                    <div id="adminProductsList"></div>
                    <button class="admin-add-btn" id="addProductBtn"><i class="fa-solid fa-plus"></i> إضافة منتج جديد</button>
                    <button class="admin-add-btn" onclick="exportData()" style="margin-top:12px;border-color:var(--green);color:var(--green);">
                        <i class="fa-solid fa-download"></i> حفظ البيانات
                    </button>
                </div>
                
                <div class="admin-tab-content" id="tab-categories">
                    <div id="adminCategoriesList"></div>
                    <button class="admin-add-btn" id="addCategoryBtn"><i class="fa-solid fa-plus"></i> إضافة تصنيف جديد</button>
                </div>
                
                <div class="admin-tab-content" id="tab-orders">
                    <div id="adminOrdersList"></div>
                </div>
                
                <div class="admin-tab-content" id="tab-payments">
                    <div id="adminPaymentsList"></div>
                    <button class="admin-add-btn" id="addPaymentBtn"><i class="fa-solid fa-plus"></i> إضافة طريقة دفع</button>
                </div>
                
                <div class="admin-tab-content" id="tab-users">
                    <div id="adminUsersList"></div>
                </div>
                
                <div class="admin-tab-content" id="tab-chats">
                    <div id="adminChatContainer" style="margin-top:10px;">
                        <div class="admin-chat-container">
                            <div class="admin-chat-header">
                                <h3 style="font-size:16px;font-weight:800;display:flex;align-items:center;gap:8px;">
                                    <i class="fa-solid fa-headset"></i> 💬 الدردشة
                                </h3>
                                <span class="admin-info" style="font-size:12px;opacity:0.8;">
                                    👤 مشرف: <?php echo $isLoggedIn ? $_SESSION['user_name'] : 'مدير الموقع'; ?>
                                </span>
                            </div>
                            <div class="admin-chat-body">
                                <div class="admin-chat-sidebar">
                                    <div class="sidebar-header" style="padding:12px 16px;border-bottom:1px solid var(--border);font-weight:700;font-size:13px;color:var(--text2);display:flex;align-items:center;justify-content:space-between;">
                                        <span><i class="fa-solid fa-users"></i> المستخدمين</span>
                                        <span id="adminUserCount" style="font-size:11px;color:var(--text3);font-weight:400;">0</span>
                                    </div>
                                    <div class="sidebar-search" style="padding:8px 12px;border-bottom:1px solid var(--border);">
                                        <input type="text" id="adminChatSearch" placeholder="🔍 بحث..." style="width:100%;padding:6px 10px;border:2px solid var(--border);border-radius:8px;background:var(--bg2);color:var(--text);font-size:12px;outline:none;">
                                    </div>
                                    <div class="chat-user-list" id="adminUserList">
                                        <div style="text-align:center;padding:20px;color:var(--text3);font-size:13px;">جاري التحميل...</div>
                                    </div>
                                </div>
                                <div class="admin-messages-area">
                                    <div class="admin-chat-with" style="padding:10px 16px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
                                        <div class="user-info" style="display:flex;align-items:center;gap:10px;">
                                            <div class="avatar" style="width:34px;height:34px;border-radius:50%;background:var(--acSh);display:grid;place-items:center;font-size:14px;color:var(--primary);overflow:hidden;">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                            <div>
                                                <div class="name" id="adminChatUserName" style="font-weight:700;font-size:13px;color:var(--text);">اختر مستخدم</div>
                                                <div class="status" id="adminChatUserStatus" style="font-size:10px;color:var(--green);">غير متصل</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="admin-messages-container" id="adminMessagesContainer">
                                        <div style="text-align:center;font-size:13px;color:var(--text3);padding:40px 0;" id="adminNoChatSelected">
                                            <i class="fa-solid fa-comments" style="font-size:40px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                                            اختر مستخدم من القائمة الجانبية لبدء المحادثة
                                        </div>
                                    </div>
                                    <div class="admin-input-wrapper">
                                        <input type="text" id="adminChatInput" placeholder="اكتب ردك هنا..." disabled>
                                        <button class="send-btn-admin" id="adminSendBtn" onclick="sendAdminMessage()" disabled>
                                            <i class="fa-solid fa-paper-plane"></i> رد
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="admin-tab-content" id="tab-recharges">
                    <div id="adminRechargesList"></div>
                </div>
                
                <div class="admin-tab-content" id="tab-settings">
                    <!-- ===== إعدادات الموقع ===== -->
                    <div class="admin-settings-group">
                        <div class="group-header">
                            <div class="group-icon site"><i class="fa-solid fa-globe"></i></div>
                            <h4>إعدادات الموقع</h4>
                        </div>
                        <div class="form-group">
                            <label>عنوان الموقع</label>
                            <input type="text" id="siteNameInput" value="<?php echo htmlspecialchars($siteName); ?>">
                        </div>
                        <div class="form-group">
                            <label>وصف الموقع</label>
                            <textarea id="siteDescriptionInput" rows="3"><?php echo htmlspecialchars($siteDescription); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>الشعار</label>
                            <input type="file" id="siteLogoInput" accept="image/*">
                            <div id="siteLogoPreview" style="width:80px;height:80px;border-radius:12px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;margin-top:8px;font-size:32px;color:var(--primary);">
                                <?php if ($siteLogoUrl): ?>
                                    <img src="<?php echo htmlspecialchars($siteLogoUrl); ?>" style="width:100%;height:100%;object-fit:cover;">
                                <?php else: ?>
                                    <i class="fa-solid fa-image"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <button class="btn-save-cfg" onclick="saveSiteSettings()">حفظ إعدادات الموقع</button>
                    </div>
                    
                    <!-- ===== سياسة الخصوصية ===== -->
                    <div class="admin-settings-group">
                        <div class="group-header">
                            <div class="group-icon policy"><i class="fa-solid fa-shield"></i></div>
                            <h4>سياسة الخصوصية</h4>
                        </div>
                        <textarea id="privacyPolicyInput" rows="4"><?php echo htmlspecialchars($privacyPolicy); ?></textarea>
                        <button class="btn-save-cfg" onclick="savePolicy('privacy')">حفظ سياسة الخصوصية</button>
                    </div>
                    
                    <!-- ===== الشروط والأحكام ===== -->
                    <div class="admin-settings-group">
                        <div class="group-header">
                            <div class="group-icon policy"><i class="fa-solid fa-file-contract"></i></div>
                            <h4>الشروط والأحكام</h4>
                        </div>
                        <textarea id="termsInput" rows="4"><?php echo htmlspecialchars($termsConditions); ?></textarea>
                        <button class="btn-save-cfg" onclick="savePolicy('terms')">حفظ الشروط والأحكام</button>
                    </div>
                    
                    <!-- ===== إعدادات Google OAuth ===== -->
                    <div class="admin-settings-group">
                        <div class="group-header">
                            <div class="group-icon google"><i class="fa-brands fa-google"></i></div>
                            <h4>تسجيل الدخول عبر Google</h4>
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" id="googleEnabled" <?php echo GOOGLE_ENABLED ? 'checked' : ''; ?>> تفعيل
                            </label>
                        </div>
                        <div class="form-group">
                            <label>Client ID</label>
                            <input type="text" id="googleClientId" value="<?php echo htmlspecialchars(GOOGLE_CLIENT_ID); ?>" style="direction:ltr;">
                            <div class="input-hint"><i class="fa-solid fa-circle-info"></i> من Google Cloud Console</div>
                        </div>
                        <div class="form-group">
                            <label>Client Secret</label>
                            <input type="password" id="googleClientSecret" value="<?php echo htmlspecialchars(GOOGLE_CLIENT_SECRET); ?>" style="direction:ltr;">
                            <div class="input-hint"><i class="fa-solid fa-lock"></i> مفتاح سري</div>
                        </div>
                        <div class="settings-info-box">
                            <strong>📌 كيفية الحصول على Client ID و Client Secret:</strong>
                            <ol>
                                <li>انتقل إلى <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:var(--primary);">Google Cloud Console</a></li>
                                <li>أنشئ مشروعاً جديداً أو اختر مشروعاً موجوداً</li>
                                <li>فعّل <strong>Google+ API</strong> و <strong>People API</strong></li>
                                <li>أنشئ <strong>OAuth 2.0 Client ID</strong> مع نوع <strong>Web application</strong></li>
                                <li>أضف <strong>Authorized JavaScript origins</strong>: <code><?php echo SITE_URL; ?></code></li>
                                <li>أضف <strong>Authorized redirect URIs</strong>: <code><?php echo SITE_URL; ?>/callback</code></li>
                            </ol>
                        </div>
                        <button class="btn-save-cfg" onclick="saveGoogleOAuthSettings()">حفظ إعدادات Google</button>
                        <div id="googleStatus" class="settings-status"></div>
                    </div>
                    
                    <!-- ===== إعدادات SMTP ===== -->
                    <div class="admin-settings-group">
                        <div class="group-header">
                            <div class="group-icon smtp"><i class="fa-solid fa-envelope"></i></div>
                            <h4>إعدادات البريد الإلكتروني (SMTP)</h4>
                        </div>
                        <div class="form-group">
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" id="smtpEnabled" checked> تفعيل
                            </label>
                        </div>
                        <div class="form-group">
                            <label>خادم SMTP</label>
                            <input type="text" id="smtpHost" value="smtp.hostinger.com">
                        </div>
                        <div class="form-group">
                            <label>المنفذ</label>
                            <input type="number" id="smtpPort" value="465">
                        </div>
                        <div class="form-group">
                            <label>نوع التشفير</label>
                            <select id="smtpEncryption">
                                <option value="ssl">ssl</option>
                                <option value="ssl">SSL</option>
                                <option value="">بدون تشفير</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>اسم المستخدم</label>
                            <input type="text" id="smtpUsername" value="support@fastcrand.com">
                        </div>
                        <div class="form-group">
                            <label>كلمة المرور</label>
                            <input type="password" id="smtpPassword" value="REDACTED_SMTP_PASSWORD">
                        </div>
                        <div class="form-group">
                            <label>بريد المرسل</label>
                            <input type="email" id="smtpFromEmail" value="support@fastcrand.com">
                        </div>
                        <div class="form-group">
                            <label>اسم المرسل</label>
                            <input type="text" id="smtpFromName" value="Tokmart">
                        </div>
                        <button class="btn-save-cfg" onclick="saveSMTPSettings()">حفظ إعدادات البريد</button>
                        <div id="smtpStatus" class="settings-status"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ============================================================
           OVERLAYS / MODALS
           ============================================================ -->
        
        <!-- Logout Bottom Sheet -->
        <div class="bottom-sheet-overlay" id="logoutSheetOverlay" onclick="closeLogoutSheet()"></div>
        <div class="logout-bottom-sheet" id="logoutBottomSheet">
            <div class="sheet-handle"></div>
            <div class="sheet-title">تسجيل الخروج</div>
            <div class="sheet-subtitle">هل تريد تسجيل الخروج من حسابك؟</div>
            <div class="sheet-actions">
                <button class="btn-cancel-sheet" onclick="closeLogoutSheet()">إلغاء</button>
                <button class="btn-confirm" onclick="performLogout()">تأكيد</button>
            </div>
        </div>

        <!-- Admin Forms -->
        <div class="admin-product-form-overlay" id="productFormOverlay">
            <div class="admin-product-form">
                <div class="form-title" id="productFormTitle">📦 إضافة منتج جديد</div>
                <form id="productForm" enctype="multipart/form-data">
                    <input type="hidden" id="editProductId">
                    
                    <div class="form-group">
                        <label>الصورة الرئيسية</label>
                        <input type="file" id="productImageFile" accept="image/*">
                        <div id="productImagePreview" style="width:120px;height:120px;border-radius:12px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;margin-top:8px;font-size:40px;color:var(--text3);border:2px dashed var(--border);">
                            <i class="fa-solid fa-image"></i>
                        </div>
                    </div>
                    
                    
                    
                    <!-- ===== الصور الموجودة ===== -->
                    <div id="existingImagesContainer" style="display:none;margin-top:8px;">
                        <label style="display:block;font-size:12px;font-weight:600;color:var(--text3);margin-bottom:6px;">الصور الحالية</label>
                        <div id="existingImagesList" style="display:flex;gap:10px;flex-wrap:wrap;"></div>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group">
                            <label>الاسم (بالعربية) *</label>
                            <input type="text" id="productNameAr" placeholder="مثل: هاتف ذكي" required>
                        </div>
                        <div class="form-group">
                            <label>الاسم (بالإنجليزية) *</label>
                            <input type="text" id="productNameEn" placeholder="مثل: Smartphone" required>
                            <small style="color:var(--text3);font-size:11px;">⚠️ هذا الحقل إلزامي</small>
                        </div>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group">
                            <label>الوصف (بالعربية)</label>
                            <textarea id="productDescAr" rows="3" placeholder="وصف تفصيلي للمنتج..."></textarea>
                        </div>
                        <div class="form-group">
                            <label>الوصف (بالإنجليزية)</label>
                            <textarea id="productDescEn" rows="3" placeholder="Product description..."></textarea>
                        </div>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group">
                            <label>التصنيف *</label>
                            <select id="productCategory" required>
                                <option value="">-- اختر تصنيف --</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>العلامة التجارية</label>
                            <input type="text" id="productBrand" placeholder="مثل: Apple, Samsung">
                        </div>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group">
                            <label>السعر *</label>
                            <input type="number" id="productPrice" step="0.01" min="0" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                            <label>السعر القديم (للخصم)</label>
                            <input type="number" id="productOldPrice" step="0.01" min="0" placeholder="0.00">
                            <small style="color:var(--text3);font-size:11px;">💡 إذا كان أكبر من السعر الحالي، سيظهر خصم تلقائي</small>
                        </div>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
                        <div class="form-group">
                            <label>وقت التوصيل</label>
                            <input type="text" id="productDelivery" value="خلال 24 ساعة">
                        </div>
                        <div class="form-group">
                            <label>التقييم</label>
                            <input type="number" id="productRating" step="0.1" min="0" max="5" value="4.5">
                        </div>
                        <div class="form-group">
                            <label>عدد التقييمات</label>
                            <input type="number" id="productRatingCount" min="0" value="0">
                        </div>
                    </div>
                    
                    
                    
                    <div id="productFormError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;padding:10px;background:rgba(231,76,60,0.1);border-radius:8px;border:1px solid var(--red);"></div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-cancel-form" id="cancelProductForm"><i class="fa-solid fa-xmark"></i> إلغاء</button>
                        <button type="button" class="btn-save" onclick="saveProduct()"><i class="fa-solid fa-check"></i> حفظ المنتج</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="admin-category-form-overlay" id="categoryFormOverlay">
            <div class="admin-category-form">
                <div class="form-title" id="categoryFormTitle">📂 إضافة تصنيف جديد</div>
                <form id="categoryForm" enctype="multipart/form-data">
                    <input type="hidden" id="editCategoryId">
                    
                    <div class="form-group">
                        <label>معرف التصنيف (ID) *</label>
                        <input type="text" id="categoryId" placeholder="مثل: electronics, fashion" required>
                        <small style="color:var(--text3);font-size:11px;">💡 استخدم أحرف إنجليزية صغيرة وبدون مسافات</small>
                    </div>
                    
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div class="form-group">
                            <label>الاسم (بالعربية) *</label>
                            <input type="text" id="categoryNameAr" placeholder="مثل: إلكترونيات" required>
                        </div>
                        <div class="form-group">
                            <label>الاسم (بالإنجليزية) *</label>
                            <input type="text" id="categoryNameEn" placeholder="مثل: Electronics" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>أيقونة التصنيف</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                            <input type="text" id="categoryIcon" value="fa-solid fa-tag" placeholder="fa-solid fa-tag" style="flex:1;min-width:200px;">
                            <div id="categoryIconPreview" style="width:48px;height:48px;border-radius:12px;background:var(--acSh);display:grid;place-items:center;font-size:24px;color:var(--primary);border:2px solid var(--border);">
                                <i class="fa-solid fa-tag"></i>
                            </div>
                        </div>
                        <small style="color:var(--text3);font-size:11px;">💡 اختر أيقونة من <a href="https://fontawesome.com/icons" target="_blank" style="color:var(--primary);">FontAwesome</a></small>
                    </div>
                    
                    <div class="form-group">
                        <label>صورة الأيقونة (اختياري)</label>
                        <input type="file" id="categoryIconImage" accept="image/*">
                        <div id="categoryIconPreviewImg" style="width:100px;height:100px;border-radius:12px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;margin-top:8px;font-size:40px;color:var(--text3);border:2px dashed var(--border);">
                            <i class="fa-solid fa-image"></i>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>صورة البانر (اختياري)</label>
                        <input type="file" id="categoryBannerImage" accept="image/*">
                        <div id="categoryBannerPreview" style="width:100%;height:150px;border-radius:12px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;margin-top:8px;font-size:40px;color:var(--text3);border:2px dashed var(--border);">
                            <i class="fa-solid fa-image"></i>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>ترتيب التصنيف</label>
                        <input type="number" id="categorySort" value="0" min="0">
                        <small style="color:var(--text3);font-size:11px;">💡 الأرقام الأصغر تظهر أولاً</small>
                    </div>
                    
                    <div id="categoryFormError" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none;padding:10px;background:rgba(231,76,60,0.1);border-radius:8px;border:1px solid var(--red);"></div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn-cancel-form" id="cancelCategoryForm"><i class="fa-solid fa-xmark"></i> إلغاء</button>
                        <button type="button" class="btn-save" onclick="saveCategory()"><i class="fa-solid fa-check"></i> حفظ التصنيف</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="admin-payment-form-overlay" id="paymentFormOverlay">
            <div class="admin-payment-form">
                <div class="form-title" id="paymentFormTitle">💳 إضافة طريقة دفع</div>
                <form id="paymentMethodForm">
                    <input type="hidden" id="editPaymentId">
                    <div class="form-group"><label>المعرف (ID)</label><input type="text" id="pmNameEn" placeholder="مثل: paypal"></div>
                    <div class="form-group"><label>الاسم (بالعربية)</label><input type="text" id="pmNameAr" placeholder="مثل: باي بال"></div>
                    <div class="form-group"><label>الاسم (بالإنجليزية)</label><input type="text" id="pmNameEn" placeholder="مثل: PayPal"></div>
                    <div class="form-group"><label>الأيقونة</label><input type="text" id="pmIcon" value="fa-solid fa-credit-card" placeholder="fa-solid fa-credit-card"></div>
                    <div class="form-group"><label>رقم الحساب</label><input type="text" id="pmAccountNumber" placeholder="رقم الحساب"></div>
                    <div class="form-group"><label>المستفيد</label><input type="text" id="pmBeneficiary" placeholder="اسم المستفيد"></div>
                    <div class="form-group"><label>للشحن فقط</label><input type="checkbox" id="pmIsRechargeOnly"></div>
                    <div class="form-group"><label>صورة</label><input type="file" id="pmImageFile" accept="image/*"><div id="pmImagePreview" style="width:100px;height:100px;border-radius:12px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;margin-top:8px;font-size:40px;color:var(--text3);border:2px dashed var(--border);"><i class="fa-solid fa-image"></i></div></div>
                    <div class="form-actions">
                        <button type="button" class="btn-cancel-form" onclick="closePaymentForm()"><i class="fa-solid fa-xmark"></i> إلغاء</button>
                        <button type="button" class="btn-save" onclick="savePaymentMethod()"><i class="fa-solid fa-check"></i> حفظ</button>
                    </div>
                </form>
            </div>
        </div>

<!-- Order/Action Success Card -->
<div class="order-success-overlay" id="orderSuccessOverlay">
 <div class="order-success-box">
 <div class="success-icon"><i class="fa-regular fa-circle-check"></i></div>
<h3>✅ تم تقديم طلبكم</h3>
 <p class="order-sub">شكرا لستخدامكم خدماتنا </p>
 <button class="btn-close-success" id="closeSuccessBtn" onclick="closeSuccessModal()">اغلاق</button>
 </div>
</div>
        <!-- ============================================================
           BOTTOM NAVIGATION
           ============================================================ -->
        <div class="bottom-nav-wrapper" id="bottomNav">
            <nav class="bottom-nav">
                <button class="nav-item active" data-page="page-home" id="navHome">
                    <i class="fa-solid fa-house"></i>
                    <span>الرئيسية</span>
                </button>
                <button class="nav-item" data-page="page-categories" id="navCategories">
                    <span class="cat-icon"><i class="fa-solid fa-layer-group"></i></span>
                    <span>التصنيفات</span>
                </button>
                <button class="nav-item" data-page="page-cart" id="navCart">
                    <i class="fa-solid fa-cart-shopping"></i>
                    <span>السلة</span>
                    <span class="badge" id="cartBadge" style="display:none;">0</span>
                </button>
                <button class="nav-item" data-page="page-account" id="navAccount">
                    <i class="fa-solid fa-user"></i>
                    <span>الحساب</span>
                </button>
            </nav>
        </div>

        <!-- ===== Toast ===== -->
        <div id="toast"></div>
        
        <!-- ===== Lightbox ===== -->
        <div id="lightbox" onclick="if(event.target===this) closeLightbox()">
            <button class="lightbox-close" onclick="closeLightbox()">✕</button>
            <button class="lightbox-download" onclick="downloadLightboxImage()"><i class="fa-solid fa-download"></i> تحميل</button>
            <div class="lightbox-image-container"><img id="lightboxImage" src="" alt="صورة مكبرة"></div>
        </div>
    </div>
</div>

<script>
// ============================================================
// جافا سكريبت - النسخة النهائية
// ============================================================

// ============================================================
// دعم الطلب - التواصل مع الدعم الفني
// ============================================================

/**
 * فتح الدردشة مع الدعم بخصوص طلب معين
 */
function openOrderSupportChat(orderId) {
    console.log('📞 فتح الدردشة للطلب:', orderId);
    
    // التأكد من تسجيل الدخول
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return;
    }
    
    // البحث عن الطلب
    const order = state.orders.find(function(o) {
        return o.id == orderId || o.orderId == orderId;
    });
    
    if (!order) {
        showToast('⚠️ الطلب غير موجود');
        return;
    }
    
    // حفظ رقم الطلب للرجوع إليه لاحقاً
    try {
        localStorage.setItem('last_order_id', orderId);
    } catch (e) {}
    
    // إغلاق صفحة تفاصيل الطلب
    const detailPage = document.getElementById('orderDetailPage');
    if (detailPage) {
        detailPage.classList.remove('open');
    }
    
    // فتح صفحة الدردشة
    const chatPage = document.getElementById('chatPageUser');
    if (!chatPage) {
        showToast('⚠️ صفحة الدردشة غير متوفرة');
        return;
    }
    
    // إظهار صفحة الدردشة
    chatPage.classList.add('open');
    document.body.style.overflow = 'hidden';
    state.isUserChatOpen = true;
    updateBottomNavVisibility();
    
    // إعادة تعيين المحادثة
    resetUserChat();
    
    // إضافة رسالة تمهيدية عن الطلب
    const orderNumber = order.orderId || order.id;
    const orderTotal = parseFloat(order.total || 0).toFixed(2);
    const orderDate = order.date || new Date(order.createdAt).toLocaleDateString('ar-EG');
    
    setTimeout(function() {
        const container = document.getElementById('userMessagesContainer');
        if (!container) return;
        
        // إزالة رسالة "ابدأ محادثتك"
        const emptyMsg = document.getElementById('emptyChatMessage');
        if (emptyMsg) emptyMsg.remove();
        
        // إزالة الإشعارات القديمة
        const notification = document.getElementById('newMsgNotification');
        if (notification) notification.remove();
        
        // إضافة رسالة النظام
        const systemMsg = document.createElement('div');
        systemMsg.className = 'message received';
        systemMsg.style.cssText = 'align-self:center;max-width:92%;margin:8px 0;';
        systemMsg.innerHTML = `
            <div class="message-content" style="background:var(--bg2);border-radius:16px;padding:14px 18px;border:1px solid var(--border);text-align:center;">
                <div class="msg-text" style="font-size:13px;color:var(--text2);">
                    <div style="font-size:28px;margin-bottom:8px;">💬</div>
                    <div style="font-weight:700;font-size:15px;color:var(--text);">الدعم الفني للطلب</div>
                    <div style="margin:6px 0;padding:8px;background:var(--surface);border-radius:8px;border:1px solid var(--border);">
                        <div style="font-weight:700;color:var(--primary);">#${orderNumber}</div>
                        <div style="font-size:12px;color:var(--text3);">💰 ${orderTotal} د.ع | 📅 ${orderDate}</div>
                        <div style="font-size:12px;color:var(--text3);">📌 الحالة: ${getOrderStatusText(order.status)}</div>
                    </div>
                    <div style="font-size:12px;color:var(--text3);margin-top:6px;">
                        <i class="fa-regular fa-clock"></i> فريق الدعم متصل الآن، اكتب رسالتك
                    </div>
                </div>
            </div>
        `;
        container.appendChild(systemMsg);
        
        // التركيز على حقل الإدخال
        const input = document.getElementById('userChatInput');
        if (input) {
            setTimeout(function() {
                input.focus();
                input.placeholder = 'اكتب رسالتك بخصوص الطلب #' + orderNumber + '...';
            }, 400);
        }
        
        // التمرير للأسفل
        container.scrollTop = container.scrollHeight;
    }, 400);
    
    // بدء تحديث الرسائل
    if (state.chatIntervals.user) {
        clearInterval(state.chatIntervals.user);
    }
    state.chatIntervals.user = setInterval(loadUserChatMessages, 5000);
    
    // تحميل رسائل المحادثة السابقة
    loadUserChatMessages();
    
    showToast('💬 تم فتح الدردشة مع الدعم');
}

/**
 * الحصول على نص حالة الطلب
 */
function getOrderStatusText(status) {
    const statusMap = {
        'pending': '⏳ قيد المعالجة',
        'shipped': '🚚 قيد التوصيل',
        'completed': '✅ مكتمل',
        'cancelled': '❌ ملغي',
        'approved': '✅ تم الموافقة',
        'rejected': '❌ مرفوض'
    };
    return statusMap[status] || status || 'غير معروف';
}

/**
 * إغلاق الدردشة والعودة إلى تفاصيل الطلب
 */
function closeChatAndReturn() {
    console.log('🔙 العودة من الدردشة');
    
    // إغلاق الدردشة
    const chatPage = document.getElementById('chatPageUser');
    if (chatPage) {
        chatPage.classList.remove('open');
    }
    
    document.body.style.overflow = '';
    state.isUserChatOpen = false;
    updateBottomNavVisibility();
    
    // إيقاف تحديث الرسائل
    if (state.chatIntervals.user) {
        clearInterval(state.chatIntervals.user);
        state.chatIntervals.user = null;
    }
    
    // العودة إلى تفاصيل الطلب
    const lastOrderId = localStorage.getItem('last_order_id');
    if (lastOrderId) {
        setTimeout(function() {
            // البحث عن الطلب في state.orders
            const order = state.orders.find(function(o) {
                return o.id == lastOrderId || o.orderId == lastOrderId;
            });
            if (order) {
                openOrderDetail(order.id);
                // مسح الـ ID بعد العودة
                localStorage.removeItem('last_order_id');
            } else {
                // إذا لم يتم العثور على الطلب، افتح صفحة الطلبات
                openOrders();
            }
        }, 300);
    } else {
        // العودة إلى الطلبات
        setTimeout(function() {
            openOrders();
        }, 300);
    }
}

/**
 * إرسال رسالة دعم بخصوص الطلب
 */
function sendOrderSupportMessage(orderId, message) {
    if (!orderId || !message) return;
    
    // إرسال إشعار للأدمن مع رقم الطلب
    callAPI('sendOrderSupportMessage', {
        orderId: orderId,
        message: message
    }, 'POST').then(function(response) {
        if (!response.success) {
            console.warn('⚠️ فشل إرسال إشعار الدعم:', response.message);
        }
    });
}

/**
 * دالة محسنة لإرسال رسالة المستخدم مع دعم الطلبات
 */
function sendUserMessage() {
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول');
        return;
    }
    
    const input = document.getElementById('userChatInput');
    if (!input) {
        showToast('⚠️ حقل الإدخال غير موجود');
        return;
    }
    
    const msg = input.value.trim();
    if (!msg) {
        showToast('⚠️ لا يمكن إرسال رسالة فارغة');
        return;
    }
    
    // تعطيل الإدخال مؤقتاً
    input.disabled = true;
    const sendBtn = document.querySelector('.send-btn');
    if (sendBtn) {
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
    }
    
    // محاولة استخراج رقم الطلب من الرسالة التمهيدية أو من localStorage
    let orderId = localStorage.getItem('last_order_id') || null;
    
    // إرسال الرسالة
    callAPI('sendChatMessage', {
        userId: state.user.id,
        adminId: 1,
        message: msg,
        messageType: 'text',
        sender: 'user'
    }, 'POST')
        .then(function(response) {
            // إعادة تفعيل الإدخال
            input.disabled = false;
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال';
            }
            
            if (response.success) {
                // إضافة الرسالة إلى الواجهة
                const container = document.getElementById('userMessagesContainer');
                if (container) {
                    // إزالة رسالة "لا توجد رسائل"
                    const emptyMsg = document.getElementById('emptyChatMessage');
                    if (emptyMsg) emptyMsg.remove();
                    
                    const msgDiv = document.createElement('div');
                    msgDiv.className = 'message sent';
                    msgDiv.style.cssText = 'align-self:flex-end;animation:messageSlideIn 0.3s var(--ease-out);';
                    const now = new Date();
                    const timeStr = now.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
                    
                    msgDiv.innerHTML = `
                        <div class="message-content" style="background:#0f3d1c;color:#fff;border-radius:16px;border-bottom-right-radius:4px;padding:10px 14px;max-width:100%;">
                            <div class="msg-text" style="font-size:14px;line-height:1.6;white-space:pre-wrap;word-break:break-word;margin:0;color:#fff;">${escapeHtml(msg)}</div>
                            <div class="msg-footer" style="display:flex;align-items:center;justify-content:flex-end;gap:8px;margin-top:4px;font-size:10px;opacity:0.7;color:rgba(255,255,255,0.8);">
                                <span class="msg-time">${timeStr}</span>
                            </div>
                        </div>
                    `;
                    container.appendChild(msgDiv);
                    container.scrollTop = container.scrollHeight;
                }
                
                input.value = '';
                input.placeholder = 'اكتب رسالتك...';
                showToast('✅ تم إرسال رسالتك');
                if (navigator.vibrate) navigator.vibrate(10);
                
                // إذا كان هناك طلب، أرسل إشعار دعم
                if (orderId) {
                    sendOrderSupportMessage(orderId, msg);
                }
            } else {
                showToast('❌ ' + (response.message || 'فشل إرسال الرسالة'));
                input.value = msg;
            }
        })
        .catch(function(error) {
            console.error('❌ خطأ في إرسال الرسالة:', error);
            input.disabled = false;
            if (sendBtn) {
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال';
            }
            showToast('❌ حدث خطأ في الاتصال');
            input.value = msg;
        });
}


// 1. الدالة الأساسية لتحليل واستخراج المنتجات بأمان من أي شكل بيانات
function getOrderItems(order) {
    let items = [];
    
    try {
        let rawItems = order.items || order.cart || order.products;
        
        if (rawItems) {
            if (typeof rawItems === 'string') {
                const parsed = JSON.parse(rawItems);
                items = Array.isArray(parsed) ? parsed : [parsed];
            } else if (Array.isArray(rawItems)) {
                items = rawItems;
            } else if (typeof rawItems === 'object' && rawItems !== null) {
                items = Object.values(rawItems);
            }
        }
        
        if (!Array.isArray(items)) {
            items = [];
        }
        
        // تنظيف العناصر والتأكد أنها كائنات صحيحة
        items = items.filter(function(item) {
            return item && typeof item === 'object';
        });

    } catch (e) {
        console.error('⚠️ خطأ في معالجة منتجات الطلب:', e);
        items = [];
    }
    
    return items;
}

// 2. دالة رسم وعرض المنتجات داخل واجهة الطلب
function renderOrderProducts(order) {
    let items = getOrderItems(order);
    
    if (!items || items.length === 0) {
        return `
            <div style="text-align:center;padding:20px;color:var(--text3);">
                <i class="fa-regular fa-box-open" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                لا توجد منتجات مسجلة في هذا الطلب
            </div>
        `;
    }
    
    return items.map(function(item, index) {
        const price = parseFloat(item.price) || 0;
        const qty = parseInt(item.qty) || 1;
        const total = price * qty;
        const imgSrc = item.image_url || item.image || '';
        
        return `
            <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:${index < items.length - 1 ? '1px solid var(--border)' : 'none'};">
                <div style="width:48px;height:48px;border-radius:10px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;flex-shrink:0;border:1px solid var(--border);">
                    ${imgSrc ? `<img src="${imgSrc}" style="width:100%;height:100%;object-fit:cover;">` : `<i class="fa-solid fa-box" style="color:var(--primary);font-size:20px;"></i>`}
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:13px;word-break:break-word;">${item.name || 'منتج غير معروف'}</div>
                    <div style="font-size:11px;color:var(--text3);">
                        الكمية: ${qty} × ${price.toFixed(2)} د.ع
                    </div>
                </div>
                <div style="font-weight:800;font-size:14px;color:var(--primary);white-space:nowrap;">
                    ${total.toFixed(2)} د.ع
                </div>
            </div>
        `;
    }).join('');
}

// 3. دالة فتح نافذة تفاصيل الطلب وربط البيانات
function openOrderDetail(order) {
    // افترض أن order هو كائن الطلب القادم من الاستعلام
    const body = document.getElementById('orderDetailBody');
    if (body) {
        body.innerHTML = renderOrderProducts(order);
    }
}



// ============================================================
// دالة إتمام الطلب وحفظ المنتجات في قاعدة البيانات
// ============================================================
function submitOrder() {
    // 1. التحقق من أن المستخدم مسجل الدخول وأن السلة ليست فارغة
    if (!state.isLoggedIn) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً لإتمام الطلب');
        return;
    }

    if (!state.cart || state.cart.length === 0) {
        showToast('⚠️ سلة التسوق فارغة');
        return;
    }

    // جلب حقول العنوان والهاتف وطريقة الدفع من الواجهة (تأكد من مطابقة الـ IDs مع ملف الـ HTML لديك)
    const addressInput = document.getElementById('addressInput');
    const phoneInput = document.getElementById('phoneInput');
    
    const address = addressInput ? addressInput.value.trim() : '';
    const phone = phoneInput ? phoneInput.value.trim() : '';
    
    // التحقق من تعبئة الحقول الأساسية
    if (!address || !phone) {
        showToast('⚠️ يرجى إدخال عنوان التوصيل ورقم الهاتف');
        return;
    }

    // تحديد طريقة الدفع المختارة (افتراضياً الدفع عند الاستلام إذا لم يتم تحديد غير ذلك)
    const paymentMethod = window.selectedPaymentMethod || 'cash';

    // 2. تجهيز بيانات الطلب مع تحويل مصفوفة المنتجات (state.cart) إلى نص JSON
    const orderData = {
        total: getCartTotal(),
        address: address,
        phone: phone,
        payment: paymentMethod,
        // 🔴 هذا هو السطر الأهم لحفظ المنتجات وعدم ظهور عبارة "لا توجد منتجات"
        items: JSON.stringify(state.cart) 
    };

    // إظهار مؤشر تحميل أو تعطيل الزر لتجنب التكرار (اختياري)
    showToast('⏳ جاري إرسال الطلب...');

    // 3. إرسال الطلب إلى السيرفر عبر الـ API
    fetch('api.php?action=create_order', {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json' 
        },
        body: JSON.stringify(orderData)
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data.success) {
            showToast('✅ تم إرسال الطلب بنجاح!');
            
            // تفريغ السلة محلياً بعد نجاح الطلب
            state.cart = [];
            if (typeof saveState === 'function') {
                saveState();
            }
            
            // تحديث عرض السلة في الواجهة إذا كانت الدالة موجودة
            if (typeof updateCartUI === 'function') {
                updateCartUI();
            }

            // إغلاق نافذة إتمام الطلب أو الانتقال لصفحة الطلبات
            if (typeof closeCheckoutModal === 'function') {
                closeCheckoutModal();
            }
            
            // فتح قائمة الطلبات أو الطلب الجديد إذا أردت
            if (typeof openOrdersPage === 'function') {
                openOrdersPage();
            }
        } else {
            showToast('❌ تعذر إتمام الطلب: ' + (data.message || 'خطأ غير معروف'));
        }
    })
    .catch(function(error) {
        console.error('Error submitting order:', error);
        showToast('❌ حدث خطأ في الاتصال بالخادم');
    });
}

const TokmartNotifications = {
    // جلب الإشعارات وعرضها في الحاوية المطلوبة
    load: function() {
        fetch('api.php?action=get_notifications')
            .then(response => response.json())
            .then(data => {
                const container = document.getElementById('notificationsList');
                if (!container) return;

                if (data.success && data.data && data.data.length > 0) {
                    let html = '';
                    data.data.forEach(notif => {
                        let unreadClass = notif.is_read == 0 ? 'notification-unread border-primary' : 'notification-read';
                        
                        html += `
                            <div class="notification-item ${unreadClass} p-3 mb-2 border rounded bg-white shadow-sm" data-id="${notif.id}">
                                <div class="d-flex justify-content-between align-items-center">
                                    <h6 class="mb-1 fw-bold">${this.escapeHtml(notif.title)}</h6>
                                    <small class="text-muted" style="font-size: 11px;">${notif.created_at}</small>
                                </div>
                                <p class="mb-0 text-secondary" style="font-size: 13px;">${this.escapeHtml(notif.message)}</p>
                            </div>
                        `;
                    });
                    container.innerHTML = html;
                } else {
                    container.innerHTML = `<div class="text-center text-muted py-4"><i class="fa-regular fa-bell-slash fa-2x mb-2"></i><p>لا توجد إشعارات جديدة</p></div>`;
                }
            })
            .catch(error => console.error('Error loading notifications:', error));
    },

    // تحديد جميع إشعارات المستخدم كمقروءة عند الضغط على الزر
    markAllAsRead: function() {
        fetch('api.php?action=mark_notifications_read', { method: 'POST' })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    this.load(); // إعادة تحميل القائمة لتحديث الشكل فوراً
                }
            })
            .catch(error => console.error('Error updating notifications:', error));
    },

    // حماية النصوص من الثغرات
    escapeHtml: function(text) {
        if (!text) return '';
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    },

    init: function() {
        this.load();
    }
};

// ربط الزر وتهيئة النظام تلقائياً عند تحميل الصفحة
document.addEventListener('DOMContentLoaded', () => {
    TokmartNotifications.init();
    
    // ربط زر "تحديد الكل كمقروء" بالدالة البرمجية تلقائياً
    const markBtn = document.querySelector('button[onclick*="markAllNotificationsAsRead"]');
    if (markBtn) {
        markBtn.setAttribute('onclick', 'TokmartNotifications.markAllAsRead()');
    }
});
// تشغيل الكود تلقائياً بمجرد تحميل الصفحة
document.addEventListener('DOMContentLoaded', () => {
    TokmartNotifications.init();
});
function loadNotifications() {
    fetch('api.php?action=get_notifications')
        .then(response => response.json())
        .then(data => {
            if(data.success) {
                let listHtml = '';
                data.data.forEach(notif => {
                    let unreadClass = notif.is_read == 0 ? 'bg-light' : '';
                    listHtml += `<li class="dropdown-item ${unreadClass}">
                        <strong>${notif.title}</strong>
                        <p>${notif.message}</p>
                        <small class="text-muted">${notif.created_at}</small>
                    </li>`;
                });
                document.getElementById('notification-list').innerHTML = listHtml;
            }
        });
}

// فتح وإغلاق النافذة المنبثقة في منتصف الشاشة
function toggleReviewModal(show) {
    const modal = document.getElementById('reviewModalOverlay');
    if (modal) {
        modal.style.display = show ? 'flex' : 'none';
        if (show) {
            // إعادة ضبط المحتوى ليظهر النموذج الطبيعي عند الفتح
            document.getElementById('reviewFormContent').style.display = 'block';
            document.getElementById('reviewSuccessContent').style.display = 'none';
            document.getElementById('userReviewText').value = '';
            setRatingStar(5); // افتراضياً 5 نجوم
        }
    }
}

// دالة لتحديد وإضاءة النجوم عند الاختيار
function setRatingStar(val) {
    document.getElementById('selectedRatingValue').value = val;
    const stars = document.querySelectorAll('#interactive-star-picker i');
    stars.forEach((star, index) => {
        if (index < val) {
            star.style.color = '#f39c12'; // أصفر للنجوم المحددة
        } else {
            star.style.color = '#ddd';  // رمادي للنجوم الباقية
        }
    });
}

// إرسال التقييم وعرض رسالة "شكراً على تقيمك" مع علامة الصح
function submitProductReview(productId) {
    const rating = document.getElementById('selectedRatingValue').value;
    const review = document.getElementById('userReviewText').value;

    // إخفاء حقول الإدخال وإظهار رسالة النجاح المنبثقة
    document.getElementById('reviewFormContent').style.display = 'none';
    document.getElementById('reviewSuccessContent').style.display = 'block';

    // إغلاق النافذة المنبثقة تلقائياً بعد ثانيتين ونصف
    setTimeout(() => {
        toggleReviewModal(false);
    }, 500);
}

document.addEventListener("DOMContentLoaded", function () {
    const TWO_HOURS = 2 * 60 * 60 * 1000; // ساعتان بالمللي ثانية
    let endTime = localStorage.getItem("tokmart_discount_end");
    let now = new Date().getTime();

    // التحقق من الوقت أو إنشاء وقت جديد للساعتين
    if (!endTime || now >= parseInt(endTime)) {
        endTime = now + TWO_HOURS;
        localStorage.setItem("tokmart_discount_end", endTime);
    }

    const timer = setInterval(function () {
        let currentTime = new Date().getTime();
        let distance = parseInt(endTime) - currentTime;

        // إذا انتهى العداد، يعود للعد من جديد تلقائياً
        if (distance <= 0) {
            endTime = new Date().getTime() + TWO_HOURS;
            localStorage.setItem("tokmart_discount_end", endTime);
            distance = TWO_HOURS;
        }

        // حساب الساعات، الدقائق، والثواني
        let hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        let minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        let seconds = Math.floor((distance % (1000 * 60)) / 1000);

        // تنسيق الأرقام لتصبح دائماً خانتين (مثل 02 بدلاً من 2)
        const format = (num) => String(num).padStart(2, '0');

        // جلب العناصر وتحديثها مباشرة
        const hoursEl = document.querySelector(".t-hours");
        const minutesEl = document.querySelector(".t-minutes");
        const secondsEl = document.querySelector(".t-seconds");

        if (hoursEl) hoursEl.innerText = format(hours);
        if (minutesEl) minutesEl.innerText = format(minutes);
        if (secondsEl) secondsEl.innerText = format(seconds);

    }, 1000);
});
document.addEventListener("DOMContentLoaded", function() {
    fetch('api.php?action=getProfileData') 
    .then(res => res.json())
    .then(data => {
        if(data.status && data.user) {
            document.querySelector('input[name="username"]').value = data.user.name || '';
            document.querySelector('input[name="phone"]').value = data.user.phone || '';
            document.querySelector('input[name="email"]').value = data.user.email || '';
        }
    }).catch(err => console.log(err));
});

function requestEmailChangeCode() {
    const newEmail = document.querySelector('input[name="email"]').value;
    if(!newEmail) {
        alert('يرجى كتابة البريد الإلكتروني الجديد أولاً');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'requestPasswordReset'); 
    formData.append('email', newEmail);
    
    fetch('', { method: 'POST', body: formData })
    .then(res => res.json())
    .then(data => {
        alert(data.message);
        if(data.status) {
            let codeInputHtml = prompt('تم إرسال كود التحقق إلى بريدك الجديد. أدخل الكود هنا:');
            if(codeInputHtml) {
                window.verifiedEmailCode = codeInputHtml;
            }
        }
    })
    .catch(err => {
        console.error(err);
        alert('حدث خطأ في الاتصال بالخادم');
    });
}
 
// ===== دوال Toast و API و State =====

const $ = function(id) { return document.getElementById(id); };
const $$ = function(sel) { return document.querySelectorAll(sel); };

let toastTimer = null;

function showToast(msg, duration) {
    duration = duration || 2200;
    const el = $('toast');
    if (!el) return;
    el.textContent = msg;
    el.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function() {
        el.classList.remove('show');
    }, duration);
}

function callAPI(action, data, method) {
    method = method || 'POST';
    return new Promise(function(resolve) {
        let url = window.location.pathname + '?action=' + action;
        const options = {
            method: method,
            headers: { 
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        if (data && data instanceof FormData) {
            options.body = data;
        } else if (data && method !== 'GET') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(data);
        } else if (data && method === 'GET') {
            url += '&' + new URLSearchParams(data).toString();
        }

        const controller = new AbortController();
        const timeoutId = setTimeout(function() {
            controller.abort();
        }, 30000);
        options.signal = controller.signal;

        fetch(url, options)
            .then(function(response) {
                clearTimeout(timeoutId);
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(function(result) { 
                resolve(result); 
            })
            .catch(function(err) {
                clearTimeout(timeoutId);
                console.error('API Error:', err);
                if (err.name === 'AbortError') {
                    resolve({ success: false, message: 'انتهت مهلة الاتصال، يرجى المحاولة مرة أخرى' });
                } else {
                    resolve({ success: false, message: 'خطأ في الاتصال بالخادم' });
                }
            });
    });
}

// ===== State =====
let state = {
    user: null,
    isLoggedIn: <?php echo $isLoggedIn ? 'true' : 'false'; ?>,
    isAdmin: <?php echo $isAdmin ? 'true' : 'false'; ?>,
    isVerified: <?php echo $isVerified ? 'true' : 'false'; ?>,
    balance: 0,
    cart: [],
    favorites: [],
    products: [],
    categories: [],
    orders: [],
    payments: <?php echo json_encode($paymentMethods); ?>,
    rechargeMethods: <?php echo json_encode($rechargeMethods); ?>,
    notifications: [],
    chats: [],
    users: [],
    recharges: [],
    flashDeals: [],
    bestSellers: [],
    newArrivals: [],
    featured: [],
    currentPage: 'page-home',
    selectedPayment: 'cash',
    selectedRechargeMethod: null,
    detailQty: 1,
    userMessages: [],
    lastMessageCount: 0,
    adminCurrentUserId: null,
    isUserChatOpen: false,
    isAdminChatOpen: false,
    chatIntervals: {
        user: null,
        admin: null
    },
    otpTimer: null,
    resendTimer: 60,
    rechargeAmount: 0,
    deleteImages: []
};

// ============================================================
// نظام الترجمة
// ============================================================

const translations = {
    ar: {
        'app_name': 'Tokmart',
        'home': 'الرئيسية',
        'categories': 'التصنيفات',
        'cart': 'السلة',
        'account': 'الحساب',
        'search': 'بحث',
        'save': 'حفظ',
        'cancel': 'إلغاء',
        'delete': 'حذف',
        'edit': 'تعديل',
        'add': 'إضافة',
        'close': 'إغلاق',
        'back': 'رجوع',
        'loading': 'جاري التحميل...',
        'no_data': 'لا توجد بيانات',
        'error': 'حدث خطأ',
        'success': 'تم بنجاح',
        'products': 'المنتجات',
        'product_name': 'اسم المنتج',
        'product_name_en': 'اسم المنتج (بالإنجليزية)',
        'product_desc': 'وصف المنتج',
        'product_desc_en': 'وصف المنتج (بالإنجليزية)',
        'price': 'السعر',
        'old_price': 'السعر القديم',
        'category': 'التصنيف',
        'brand': 'العلامة التجارية',
        'delivery_time': 'وقت التوصيل',
        'rating': 'التقييم',
        'rating_count': 'عدد التقييمات',
        'add_to_cart': 'أضف للسلة',
        'favorite': 'المفضلة',
        'discount': 'خصم',
        'new': 'جديد',
        'bestseller': 'الأكثر مبيعاً',
        'featured': 'مميز',
        'flash_deal': 'عرض خاص',
        'balance': 'الرصيد',
        'recharge': 'شحن الرصيد',
        'orders': 'طلباتي',
        'favorites': 'المفضلة',
        'settings': 'الإعدادات',
        'language': 'اللغة',
        'arabic': 'العربية',
        'english': 'الإنجليزية',
        'logout': 'تسجيل الخروج',
        'login': 'تسجيل الدخول',
        'register': 'إنشاء حساب',
        'profile': 'الملف الشخصي',
        'phone': 'رقم الهاتف',
        'email': 'البريد الإلكتروني',
        'password': 'كلمة المرور',
        'current_password': 'كلمة المرور الحالية',
        'new_password': 'كلمة المرور الجديدة',
        'avatar': 'الصورة الشخصية',
        'recharge_balance': 'شحن الرصيد',
        'amount': 'المبلغ',
        'payment_method': 'طريقة الدفع',
        'receipt': 'صورة الإيصال',
        'submit_request': 'تقديم الطلب',
        'payment_details': 'تفاصيل الدفع',
        'account_number': 'رقم الحساب',
        'beneficiary': 'المستفيد',
        'copy': 'نسخ',
        'copied': 'تم النسخ',
        'recharge_history': 'سجل الشحن',
        'pending': 'قيد المعالجة',
        'approved': 'تمت الموافقة',
        'rejected': 'مرفوض',
        'pay_now': 'إدفع الآن',
        'order_details': 'تفاصيل الطلب',
        'order_id': 'رقم الطلب',
        'order_date': 'تاريخ الطلب',
        'order_status': 'حالة الطلب',
        'total': 'المجموع',
        'shipping_address': 'عنوان التوصيل',
        'payment_type': 'طريقة الدفع',
        'transfer_image': 'صورة التحويل',
        'support': 'الدعم الفني',
        'contact_support': 'التواصل مع الدعم',
        'notifications': 'الإشعارات',
        'mark_all_read': 'كمقروء',
        'delete_all': 'حذف',
        'no_notifications': 'لا توجد إشعارات',
        'read': 'مقروء',
        'unread': 'غير مقروء',
        'admin_panel': 'لوحة التحكم',
        'dashboard': 'لوحة المعلومات',
        'manage_products': 'إدارة المنتجات',
        'manage_categories': 'إدارة التصنيفات',
        'manage_orders': 'إدارة الطلبات',
        'manage_users': 'إدارة المستخدمين',
        'manage_payments': 'إدارة الدفع',
        'manage_chats': 'إدارة الدردشة',
        'manage_recharges': 'إدارة الشحن',
        'settings': 'الإعدادات',
        'stats': 'الإحصائيات',
        'add_product': 'إضافة منتج',
        'add_category': 'إضافة تصنيف',
        'add_payment': 'إضافة طريقة دفع',
        'export_data': 'تصدير البيانات',
        'approve': 'موافقة',
        'reject': 'رفض',
        'view': 'عرض',
        'welcome': 'مرحباً بك',
        'login_success': 'تم تسجيل الدخول بنجاح',
        'logout_success': 'تم تسجيل الخروج بنجاح',
        'register_success': 'تم إنشاء الحساب بنجاح',
        'update_success': 'تم التحديث بنجاح',
        'delete_success': 'تم الحذف بنجاح',
        'copy_success': 'تم النسخ بنجاح',
        'recharge_success': 'تم إرسال طلب الشحن بنجاح',
        'order_success': 'تم إنشاء الطلب بنجاح',
        'cart_empty': 'السلة فارغة',
        'required_field': 'هذا الحقل مطلوب',
        'invalid_email': 'البريد الإلكتروني غير صحيح',
        'invalid_phone': 'رقم الهاتف غير صحيح',
        'password_mismatch': 'كلمة المرور غير متطابقة',
        'balance_insufficient': 'الرصيد غير كافي',
        'recharge_details': 'تفاصيل طلب الشحن',
        'notes': 'ملاحظات',
        'no_recharges': 'لا توجد طلبات شحن سابقة',
        'recharge_now': 'قم بتقديم طلب شحن لزيادة رصيدك',
        'pending_review': 'بانتظار المراجعة',
        'receipt': 'صورة الإيصال',
        'no_orders': 'لا توجد طلبات',
        'start_shopping': 'ابدأ التسوق',
        'login_to_view': 'سجل دخولك لمشاهدة',
        'explore_products': 'استكشاف المنتجات',
        'add_to_favorites': 'أضف إلى المفضلة',
        'remove_from_favorites': 'إزالة من المفضلة',
        'no_favorites': 'لا توجد منتجات في المفضلة',
        'favorites_empty': 'أضف منتجاتك المفضلة لتظهر هنا',
        'back_to_list': 'العودة للقائمة',
        'total_amount': 'المجموع الكلي',
        'order_number': 'رقم الطلب',
    },
    en: {
        'app_name': 'Tokmart',
        'home': 'Home',
        'categories': 'Categories',
        'cart': 'Cart',
        'account': 'Account',
        'search': 'Search',
        'save': 'Save',
        'cancel': 'Cancel',
        'delete': 'Delete',
        'edit': 'Edit',
        'add': 'Add',
        'close': 'Close',
        'back': 'Back',
        'loading': 'Loading...',
        'no_data': 'No data available',
        'error': 'An error occurred',
        'success': 'Success',
        'products': 'Products',
        'product_name': 'Product Name',
        'product_name_en': 'Product Name (English)',
        'product_desc': 'Product Description',
        'product_desc_en': 'Product Description (English)',
        'price': 'Price',
        'old_price': 'Old Price',
        'category': 'Category',
        'brand': 'Brand',
        'delivery_time': 'Delivery Time',
        'rating': 'Rating',
        'rating_count': 'Ratings',
        'add_to_cart': 'Add to Cart',
        'favorite': 'Favorite',
        'discount': 'Discount',
        'new': 'New',
        'bestseller': 'Bestseller',
        'featured': 'Featured',
        'flash_deal': 'Flash Deal',
        'balance': 'Balance',
        'recharge': 'Recharge',
        'orders': 'My Orders',
        'favorites': 'Favorites',
        'settings': 'Settings',
        'language': 'Language',
        'arabic': 'Arabic',
        'english': 'English',
        'logout': 'Logout',
        'login': 'Login',
        'register': 'Register',
        'profile': 'Profile',
        'phone': 'Phone Number',
        'email': 'Email',
        'password': 'Password',
        'current_password': 'Current Password',
        'new_password': 'New Password',
        'avatar': 'Profile Picture',
        'recharge_balance': 'Recharge Balance',
        'amount': 'Amount',
        'payment_method': 'Payment Method',
        'receipt': 'Receipt Image',
        'submit_request': 'Submit Request',
        'payment_details': 'Payment Details',
        'account_number': 'Account Number',
        'beneficiary': 'Beneficiary',
        'copy': 'Copy',
        'copied': 'Copied',
        'recharge_history': 'Recharge History',
        'pending': 'Pending',
        'approved': 'Approved',
        'rejected': 'Rejected',
        'pay_now': 'Pay Now',
        'order_details': 'Order Details',
        'order_id': 'Order ID',
        'order_date': 'Order Date',
        'order_status': 'Order Status',
        'total': 'Total',
        'shipping_address': 'Shipping Address',
        'payment_type': 'Payment Method',
        'transfer_image': 'Transfer Image',
        'support': 'Support',
        'contact_support': 'Contact Support',
        'notifications': 'Notifications',
        'mark_all_read': 'Mark All as Read',
        'delete_all': 'Delete All',
        'no_notifications': 'No notifications',
        'read': 'Read',
        'unread': 'Unread',
        'admin_panel': 'Admin Panel',
        'dashboard': 'Dashboard',
        'manage_products': 'Manage Products',
        'manage_categories': 'Manage Categories',
        'manage_orders': 'Manage Orders',
        'manage_users': 'Manage Users',
        'manage_payments': 'Manage Payments',
        'manage_chats': 'Manage Chats',
        'manage_recharges': 'Manage Recharges',
        'settings': 'Settings',
        'stats': 'Statistics',
        'add_product': 'Add Product',
        'add_category': 'Add Category',
        'add_payment': 'Add Payment Method',
        'export_data': 'Export Data',
        'approve': 'Approve',
        'reject': 'Reject',
        'view': 'View',
        'welcome': 'Welcome',
        'login_success': 'Login successful',
        'logout_success': 'Logout successful',
        'register_success': 'Account created successfully',
        'update_success': 'Updated successfully',
        'delete_success': 'Deleted successfully',
        'copy_success': 'Copied successfully',
        'recharge_success': 'Recharge request submitted',
        'order_success': 'Order created successfully',
        'cart_empty': 'Cart is empty',
        'required_field': 'This field is required',
        'invalid_email': 'Invalid email address',
        'invalid_phone': 'Invalid phone number',
        'password_mismatch': 'Password mismatch',
        'balance_insufficient': 'Insufficient balance',
        'recharge_details': 'Recharge Request Details',
        'notes': 'Notes',
        'no_recharges': 'No previous recharge requests',
        'recharge_now': 'Submit a recharge request to increase your balance',
        'pending_review': 'Pending Review',
        'receipt': 'Receipt Image',
        'no_orders': 'No orders found',
        'start_shopping': 'Start Shopping',
        'login_to_view': 'Login to view',
        'explore_products': 'Explore Products',
        'add_to_favorites': 'Add to Favorites',
        'remove_from_favorites': 'Remove from Favorites',
        'no_favorites': 'No favorites found',
        'favorites_empty': 'Add your favorite products here',
        'back_to_list': 'Back to List',
        'total_amount': 'Total Amount',
        'order_number': 'Order Number',
    }
};

let currentLang = localStorage.getItem('Tokmart_lang') || 'ar';

function t(key) {
    const lang = currentLang || 'ar';
    return translations[lang]?.[key] || translations['ar'][key] || key;
}

function setLanguage(lang) {
    if (lang !== 'ar' && lang !== 'en') return;
    currentLang = lang;
    localStorage.setItem('Tokmart_lang', lang);
    document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr';
    document.documentElement.lang = lang;
    updateAllTexts();
    showToast(t('update_success'));
}

function updateAllTexts() {
    document.querySelectorAll('[data-i18n]').forEach(function(el) {
        const key = el.getAttribute('data-i18n');
        el.textContent = t(key);
    });
    
    const titleEl = document.querySelector('title');
    if (titleEl) {
        titleEl.textContent = t('app_name') + ' - ' + (state.currentPage === 'page-home' ? t('home') : '');
    }
    
    const navItems = {
        'navHome': 'home',
        'navCategories': 'categories',
        'navCart': 'cart',
        'navAccount': 'account'
    };
    Object.keys(navItems).forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            const span = el.querySelector('span:last-child');
            if (span) span.textContent = t(navItems[id]);
        }
    });
    
    updateDynamicTexts();
}

function updateDynamicTexts() {
    if (state.isLoggedIn && state.user) {
        const loginBtn = document.getElementById('loginBtn');
        if (loginBtn) loginBtn.textContent = '👤 ' + state.user.name;
    }
    
    const pageTitles = {
        'page-home': t('home'),
        'page-categories': t('categories'),
        'page-cart': t('cart'),
        'page-account': t('account')
    };
    Object.keys(pageTitles).forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            const h2 = el.querySelector('h2');
            if (h2) {
                const icon = h2.querySelector('i');
                if (icon) {
                    h2.innerHTML = icon.outerHTML + ' ' + pageTitles[id];
                } else {
                    h2.textContent = pageTitles[id];
                }
            }
        }
    });
}

// ============================================================
// GOOGLE LOGIN
// ============================================================

let googleLoginInitialized = false;
let googleRegisterInitialized = false;
let googleInitAttempts = 0;
const MAX_GOOGLE_INIT_ATTEMPTS = 10;

function initGoogleLogin() {
    // الحصول على Client ID من الـ HTML
    const clientIdEl = document.getElementById('googleClientIdHidden');
    const clientId = clientIdEl ? clientIdEl.value : '<?php echo GOOGLE_CLIENT_ID; ?>';
    const googleEnabled = <?php echo json_encode(GOOGLE_ENABLED ?? true); ?>;
    
    if (!googleEnabled) {
        const loginContainer = document.getElementById('googleLoginContainer');
        const registerContainer = document.getElementById('googleRegisterContainer');
        if (loginContainer) {
            loginContainer.innerHTML = `
                <div style="text-align:center;padding:12px;color:var(--text3);font-size:13px;border:1px solid var(--border);border-radius:10px;background:var(--bg2);">
                    <i class="fa-brands fa-google" style="color:#EA4335;font-size:20px;display:block;margin-bottom:4px;"></i>
                    ⚠️ تسجيل الدخول عبر Google معطل حالياً
                </div>
            `;
        }
        if (registerContainer) {
            registerContainer.innerHTML = `
                <div style="text-align:center;padding:12px;color:var(--text3);font-size:13px;border:1px solid var(--border);border-radius:10px;background:var(--bg2);">
                    <i class="fa-brands fa-google" style="color:#EA4335;font-size:20px;display:block;margin-bottom:4px;"></i>
                    ⚠️ تسجيل الدخول عبر Google معطل حالياً
                </div>
            `;
        }
        return;
    }
    
    if (typeof window.google === 'undefined' || typeof window.google.accounts === 'undefined') {
        googleInitAttempts++;
        if (googleInitAttempts < MAX_GOOGLE_INIT_ATTEMPTS) {
            console.log('⏳ انتظار تحميل مكتبة Google... المحاولة ' + googleInitAttempts);
            setTimeout(initGoogleLogin, 800);
        } else {
            console.warn('⚠️ فشل تحميل مكتبة Google');
            showFallbackGoogleButtons();
        }
        return;
    }
    
    try {
        if (!googleLoginInitialized) {
            window.google.accounts.id.initialize({
                client_id: clientId,
                callback: handleGoogleLogin,
                auto_prompt: false,
                cancel_on_tap_outside: true,
                context: 'signin'
            });
            googleLoginInitialized = true;
            console.log('✅ Google Identity Services initialized with client ID:', clientId);
        }
        
        renderGoogleButton('googleLoginContainer', 'sign_in_with');
        renderGoogleButton('googleRegisterContainer', 'sign_up_with');
        
    } catch (e) {
        console.warn('⚠️ Google Login init error:', e);
        showFallbackGoogleButtons();
    }
}

function renderGoogleButton(containerId, buttonText) {
    const container = document.getElementById(containerId);
    if (!container) return;
    
    container.innerHTML = '';
    const buttonWrapper = document.createElement('div');
    buttonWrapper.style.cssText = 'display:flex;justify-content:center;width:100%;';
    const buttonDiv = document.createElement('div');
    buttonDiv.className = 'g_id_signin';
    buttonDiv.style.cssText = 'width:100%;max-width:320px;';
    buttonWrapper.appendChild(buttonDiv);
    container.appendChild(buttonWrapper);
    
    try {
        window.google.accounts.id.renderButton(
            buttonDiv,
            { 
                type: 'standard', 
                size: 'large', 
                theme: 'outline', 
                text: buttonText, 
                shape: 'rectangular', 
                logo_alignment: 'center',
                width: 320
            }
        );
        console.log('✅ Google button rendered:', containerId);
    } catch (e) {
        console.warn('⚠️ Error rendering Google button:', e);
        buttonDiv.innerHTML = `
            <button onclick="initGoogleLogin()" style="width:100%;padding:12px;border:2px solid #EA4335;border-radius:10px;background:#fff;color:#EA4335;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;">
                <i class="fa-brands fa-google" style="font-size:20px;"></i>
                ${buttonText === 'sign_in_with' ? 'تسجيل الدخول عبر Google' : 'إنشاء حساب عبر Google'}
            </button>
        `;
    }
}

function showFallbackGoogleButtons() {
    const containers = ['googleLoginContainer', 'googleRegisterContainer'];
    containers.forEach(function(id) {
        const container = document.getElementById(id);
        if (container) {
            container.innerHTML = `
                <div style="text-align:center;padding:12px;color:var(--text3);font-size:13px;border:1px solid var(--border);border-radius:10px;background:var(--bg2);">
                    <i class="fa-brands fa-google" style="color:#EA4335;font-size:20px;display:block;margin-bottom:4px;"></i>
                    <button onclick="initGoogleLogin()" style="padding:8px 20px;border:2px solid #EA4335;border-radius:8px;background:#fff;color:#EA4335;font-weight:700;font-size:13px;cursor:pointer;margin-top:6px;">
                        <i class="fa-brands fa-google"></i> محاولة تسجيل الدخول عبر Google
                    </button>
                    <div style="font-size:11px;margin-top:6px;">يرجى استخدام طرق التسجيل التقليدية</div>
                </div>
            `;
        }
    });
}

function handleGoogleLogin(response) {
    console.log('📱 Google login response received');
    const idToken = response.credential;
    if (!idToken) {
        showToast('⚠️ فشل تسجيل الدخول بواسطة Google - لم يتم استلام التوكن');
        return;
    }
    
    const googleBtns = document.querySelectorAll('.g_id_signin');
    googleBtns.forEach(function(btn) {
        btn.style.opacity = '0.5';
        btn.style.pointerEvents = 'none';
    });
    
    showToast('⏳ جاري التحقق من حساب Google...');
    
    callAPI('googleLogin', { id_token: idToken }, 'POST')
        .then(function(result) {
            googleBtns.forEach(function(btn) {
                btn.style.opacity = '1';
                btn.style.pointerEvents = 'auto';
            });
            
            if (result.success) {
                const user = result.data;
                state.isLoggedIn = true;
                state.user = user;
                state.balance = user.balance || 0;
                state.isAdmin = user.isAdmin || false;
                state.isVerified = user.isVerified || false;
                
                try {
                    localStorage.setItem('Tokmart_user', JSON.stringify({
                        id: user.id,
                        name: user.name,
                        email: user.email,
                        isAdmin: user.isAdmin,
                        isVerified: user.isVerified,
                        balance: user.balance,
                        avatar_path: user.avatar_path
                    }));
                } catch (e) {}
                
                updateBalanceDisplay();
                updateAccountUI();
                closeLoginPage();
                closeRegisterPage();
                renderAll();
                showToast('👋 مرحباً ' + user.name + ' (Google)');
                if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
                requestNotificationPermission();
            } else {
                showToast('❌ ' + (result.message || 'فشل تسجيل الدخول عبر Google'));
            }
        })
        .catch(function(error) {
            console.error('❌ Google login error:', error);
            googleBtns.forEach(function(btn) {
                btn.style.opacity = '1';
                btn.style.pointerEvents = 'auto';
            });
            showToast('❌ حدث خطأ في الاتصال، يرجى المحاولة مرة أخرى');
        });
}

// ============================================================
// OTP VERIFICATION
// ============================================================

let otpCode = '';
let otpResendTimer = null;
let otpResendSeconds = 60;

function handleRegister() {
    const name = $('registerName') ? $('registerName').value.trim() : '';
    const email = $('registerEmail') ? $('registerEmail').value.trim() : '';
    const phone = $('registerPhone') ? $('registerPhone').value.trim() : '';
    const password = $('registerPassword') ? $('registerPassword').value.trim() : '';
    const errorEl = $('registerError');
    
    if (!name || !email || !phone || !password) {
        if (errorEl) {
            errorEl.textContent = t('required_field');
            errorEl.style.display = 'block';
        }
        return;
    }
    
    if (!/^07[0-9]{8,10}$/.test(phone)) {
        if (errorEl) {
            errorEl.textContent = t('invalid_phone');
            errorEl.style.display = 'block';
        }
        return;
    }
    
    callAPI('registerWithOTP', {
        name: name,
        email: email,
        phone: phone,
        password: password
    }, 'POST').then(function(response) {
        if (response.success) {
            const emailDisplay = $('otpEmailDisplay');
            if (emailDisplay) emailDisplay.textContent = email;
            closeRegisterPage();
            openOTPPage();
            showToast('✅ تم إرسال رمز التحقق');
            startResendTimer();
        } else {
            if (errorEl) {
                errorEl.textContent = response.message || t('error');
                errorEl.style.display = 'block';
            }
        }
    });
}

function otpInputHandler(input, index) {
    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value.length === 1) {
        input.classList.add('filled');
        input.style.borderColor = 'var(--primary)';
        input.style.background = 'var(--surface)';
        const next = input.nextElementSibling;
        if (next && next.classList.contains('otp-input')) {
            next.focus();
        }
    } else {
        input.classList.remove('filled');
        input.style.borderColor = 'var(--border)';
        input.style.background = 'var(--bg2)';
    }
    
    const inputs = document.querySelectorAll('.otp-input');
    let code = '';
    inputs.forEach(function(inp) {
        code += inp.value;
    });
    otpCode = code;
    
    if (code.length === 6) {
        verifyOTP();
    }
}

function verifyOTP() {
    const errorEl = $('otpError');
    const successEl = $('otpSuccess');
    if (errorEl) errorEl.style.display = 'none';
    if (successEl) successEl.style.display = 'none';
    
    if (otpCode.length !== 6) {
        if (errorEl) {
            errorEl.textContent = 'الرجاء إدخال رمز التحقق الكامل (6 أرقام)';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const verifyBtn = document.querySelector('#otpVerificationPage .btn-submit');
    if (verifyBtn) {
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + t('loading');
        verifyBtn.style.opacity = '0.6';
    }
    
    callAPI('verifyOTP', { otp: otpCode }, 'POST').then(function(response) {
        if (verifyBtn) {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = 'تحقق';
            verifyBtn.style.opacity = '1';
        }
        
        if (response.success) {
            if (successEl) {
                successEl.textContent = '✅ تم التحقق بنجاح! جاري تسجيل الدخول...';
                successEl.style.display = 'block';
            }
            const user = response.data;
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = true;
            
            try {
                localStorage.setItem('Tokmart_user', JSON.stringify({
                    id: user.id,
                    name: user.name,
                    email: user.email,
                    isAdmin: user.isAdmin,
                    isVerified: user.isVerified,
                    balance: user.balance,
                    avatar_path: user.avatar_path
                }));
            } catch (e) {}
            
            updateBalanceDisplay();
            updateAccountUI();
            closeOTPPage();
            renderAll();
            showToast('🎉 مرحباً ' + user.name);
            if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
            requestNotificationPermission();
        } else {
            if (errorEl) {
                errorEl.textContent = response.message || 'رمز التحقق غير صحيح';
                errorEl.style.display = 'block';
            }
            document.querySelectorAll('.otp-input').forEach(function(inp) {
                inp.value = '';
                inp.classList.remove('filled');
                inp.style.borderColor = 'var(--border)';
                inp.style.background = 'var(--bg2)';
            });
            otpCode = '';
            document.querySelector('.otp-input').focus();
        }
    }).catch(function() {
        if (verifyBtn) {
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = 'تحقق';
            verifyBtn.style.opacity = '1';
        }
        if (errorEl) {
            errorEl.textContent = 'حدث خطأ في الاتصال بالخادم';
            errorEl.style.display = 'block';
        }
    });
}

function resendOTP() {
    if (otpResendSeconds > 0) return;
    
    callAPI('resendOTP', {}, 'POST').then(function(response) {
        if (response.success) {
            showToast('✅ تم إرسال رمز تحقق جديد');
            startResendTimer();
        } else {
            showToast('❌ ' + (response.message || 'فشل إرسال الرمز'));
        }
    });
}

function startResendTimer() {
    otpResendSeconds = 60;
    const timerEl = $('resendTimer');
    const resendEl = $('resendOTP');
    if (timerEl) timerEl.textContent = '(60 ثانية)';
    if (resendEl) {
        resendEl.style.pointerEvents = 'none';
        resendEl.style.opacity = '0.5';
    }
    
    clearInterval(otpResendTimer);
    otpResendTimer = setInterval(function() {
        otpResendSeconds--;
        if (timerEl) timerEl.textContent = '(' + otpResendSeconds + ' ثانية)';
        if (otpResendSeconds <= 0) {
            clearInterval(otpResendTimer);
            if (timerEl) timerEl.textContent = '';
            if (resendEl) {
                resendEl.style.pointerEvents = 'auto';
                resendEl.style.opacity = '1';
            }
        }
    }, 1000);
}

function openOTPPage() {
    const page = $('otpVerificationPage');
    if (page) {
        page.classList.add('open');
        document.body.style.overflow = 'hidden';
        updateBottomNavVisibility();
        document.querySelectorAll('.otp-input').forEach(function(inp) {
            inp.value = '';
            inp.classList.remove('filled');
            inp.style.borderColor = 'var(--border)';
            inp.style.background = 'var(--bg2)';
        });
        otpCode = '';
        setTimeout(function() {
            document.querySelector('.otp-input').focus();
        }, 300);
    }
}

function closeOTPPage() {
    const page = $('otpVerificationPage');
    if (page) {
        page.classList.remove('open');
        document.body.style.overflow = '';
        updateBottomNavVisibility();
        clearInterval(otpResendTimer);
    }
}

// ============================================================
// FORGOT PASSWORD
// ============================================================

let resetEmail = '';
let resetCode = '';
let resetResendTimer = null;
let resetResendSeconds = 60;

function openForgotPasswordPage() {
    const loginPage = $('loginPage');
    if (loginPage) loginPage.classList.remove('open');
    const registerPage = $('registerPage');
    if (registerPage) registerPage.classList.remove('open');
    
    openPage('forgotPasswordPage');
    resetStep1();
}

function closeForgotPasswordPage() {
    closePage('forgotPasswordPage');
    resetStep1();
}

function resetStep1() {
    const step1 = $('resetStep1');
    const step2 = $('resetStep2');
    const step3 = $('resetStep3');
    if (step1) step1.style.display = 'block';
    if (step2) step2.style.display = 'none';
    if (step3) step3.style.display = 'none';
    
    const errorEl = $('resetError');
    if (errorEl) errorEl.style.display = 'none';
}

function resetStep2() {
    const step1 = $('resetStep1');
    const step2 = $('resetStep2');
    const step3 = $('resetStep3');
    if (step1) step1.style.display = 'none';
    if (step2) step2.style.display = 'block';
    if (step3) step3.style.display = 'none';
    
    document.querySelectorAll('#resetOtpContainer .otp-input').forEach(function(inp) {
        inp.value = '';
        inp.classList.remove('filled');
        inp.style.borderColor = 'var(--border)';
        inp.style.background = 'var(--bg2)';
    });
    resetCode = '';
    setTimeout(function() {
        document.querySelector('#resetOtpContainer .otp-input').focus();
    }, 300);
}

function resetStep3() {
    const step1 = $('resetStep1');
    const step2 = $('resetStep2');
    const step3 = $('resetStep3');
    if (step1) step1.style.display = 'none';
    if (step2) step2.style.display = 'none';
    if (step3) step3.style.display = 'block';
}

function requestPasswordReset() {
    const email = $('resetEmail') ? $('resetEmail').value.trim() : '';
    const errorEl = $('resetError');
    
    if (!email) {
        if (errorEl) {
            errorEl.textContent = 'الرجاء إدخال البريد الإلكتروني';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        if (errorEl) {
            errorEl.textContent = 'البريد الإلكتروني غير صحيح';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const btn = document.querySelector('#resetStep1 .btn-submit');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...';
        btn.style.opacity = '0.6';
    }
    
    if (errorEl) errorEl.style.display = 'none';
    
    callAPI('requestPasswordReset', { email: email }, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال كود التحقق';
                btn.style.opacity = '1';
            }
            
            if (response.success) {
                resetEmail = email;
                const display = $('resetEmailDisplay');
                if (display) display.textContent = email;
                resetStep2();
                startResetResendTimer();
                showToast('✅ تم إرسال كود التحقق إلى بريدك');
            } else {
                if (errorEl) {
                    errorEl.textContent = response.message || 'فشل إرسال الكود';
                    errorEl.style.display = 'block';
                }
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> إرسال كود التحقق';
                btn.style.opacity = '1';
            }
            if (errorEl) {
                errorEl.textContent = 'حدث خطأ في الاتصال';
                errorEl.style.display = 'block';
            }
        });
}

function resetOtpInputHandler(input, index) {
    input.value = input.value.replace(/[^0-9]/g, '');
    if (input.value.length === 1) {
        input.classList.add('filled');
        input.style.borderColor = 'var(--primary)';
        input.style.background = 'var(--surface)';
        const next = input.nextElementSibling;
        if (next && next.classList.contains('otp-input')) {
            next.focus();
        }
    } else {
        input.classList.remove('filled');
        input.style.borderColor = 'var(--border)';
        input.style.background = 'var(--bg2)';
    }
    
    const inputs = document.querySelectorAll('#resetOtpContainer .otp-input');
    let code = '';
    inputs.forEach(function(inp) {
        code += inp.value;
    });
    resetCode = code;
    
    if (code.length === 6) {
        verifyResetCode();
    }
}

function verifyResetCode() {
    const errorEl = $('resetCodeError');
    if (errorEl) errorEl.style.display = 'none';
    
    if (resetCode.length !== 6) {
        if (errorEl) {
            errorEl.textContent = 'الرجاء إدخال الرمز الكامل (6 أرقام)';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const btn = document.querySelector('#resetStep2 .btn-submit');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري التحقق...';
        btn.style.opacity = '0.6';
    }
    
    callAPI('verifyResetCode', { email: resetEmail, code: resetCode }, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق';
                btn.style.opacity = '1';
            }
            
            if (response.success) {
                resetStep3();
                showToast('✅ تم التحقق من الكود بنجاح');
            } else {
                if (errorEl) {
                    errorEl.textContent = response.message || 'الكود غير صحيح';
                    errorEl.style.display = 'block';
                }
                document.querySelectorAll('#resetOtpContainer .otp-input').forEach(function(inp) {
                    inp.value = '';
                    inp.classList.remove('filled');
                    inp.style.borderColor = 'var(--border)';
                    inp.style.background = 'var(--bg2)';
                });
                resetCode = '';
                document.querySelector('#resetOtpContainer .otp-input').focus();
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق';
                btn.style.opacity = '1';
            }
            if (errorEl) {
                errorEl.textContent = 'حدث خطأ في الاتصال';
                errorEl.style.display = 'block';
            }
        });
}

function resendResetCode() {
    if (resetResendSeconds > 0) return;
    
    const btn = document.querySelector('#resetStep2 .btn-submit');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...';
        btn.style.opacity = '0.6';
    }
    
    callAPI('requestPasswordReset', { email: resetEmail }, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق';
                btn.style.opacity = '1';
            }
            
            if (response.success) {
                showToast('✅ تم إرسال كود جديد');
                startResetResendTimer();
            } else {
                showToast('❌ ' + (response.message || 'فشل إرسال الكود'));
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تحقق';
                btn.style.opacity = '1';
            }
            showToast('❌ حدث خطأ في الاتصال');
        });
}

function startResetResendTimer() {
    resetResendSeconds = 60;
    const timerEl = $('resetResendTimer');
    const resendEl = document.querySelector('#resetStep2 a[onclick="resendResetCode()"]');
    if (timerEl) timerEl.textContent = '(60 ثانية)';
    if (resendEl) {
        resendEl.style.pointerEvents = 'none';
        resendEl.style.opacity = '0.5';
    }
    
    clearInterval(resetResendTimer);
    resetResendTimer = setInterval(function() {
        resetResendSeconds--;
        if (timerEl) timerEl.textContent = '(' + resetResendSeconds + ' ثانية)';
        if (resetResendSeconds <= 0) {
            clearInterval(resetResendTimer);
            if (timerEl) timerEl.textContent = '';
            if (resendEl) {
                resendEl.style.pointerEvents = 'auto';
                resendEl.style.opacity = '1';
            }
        }
    }, 1000);
}

function resetPassword() {
    const newPassword = $('resetNewPassword') ? $('resetNewPassword').value : '';
    const confirmPassword = $('resetConfirmPassword') ? $('resetConfirmPassword').value : '';
    const errorEl = $('resetPasswordError');
    
    if (errorEl) errorEl.style.display = 'none';
    
    if (!newPassword || newPassword.length < 6) {
        if (errorEl) {
            errorEl.textContent = 'كلمة المرور يجب أن تكون 6 أحرف على الأقل';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    if (newPassword !== confirmPassword) {
        if (errorEl) {
            errorEl.textContent = 'كلمات المرور غير متطابقة';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const btn = document.querySelector('#resetStep3 .btn-submit');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري التغيير...';
        btn.style.opacity = '0.6';
    }
    
    callAPI('resetPassword', { 
        email: resetEmail, 
        code: resetCode, 
        new_password: newPassword 
    }, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تغيير كلمة المرور';
                btn.style.opacity = '1';
            }
            
            if (response.success) {
                showToast('✅ تم تغيير كلمة المرور بنجاح');
                closeForgotPasswordPage();
                setTimeout(function() {
                    openLoginPage();
                    const identifier = $('loginIdentifier');
                    if (identifier) identifier.value = resetEmail;
                    showToast('🔑 يمكنك الآن تسجيل الدخول بكلمة المرور الجديدة');
                }, 500);
            } else {
                if (errorEl) {
                    errorEl.textContent = response.message || 'فشل تغيير كلمة المرور';
                    errorEl.style.display = 'block';
                }
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-check"></i> تغيير كلمة المرور';
                btn.style.opacity = '1';
            }
            if (errorEl) {
                errorEl.textContent = 'حدث خطأ في الاتصال';
                errorEl.style.display = 'block';
            }
        });
}

// ============================================================
// REQUIRE LOGIN
// ============================================================

function requireLogin(callback) {
    if (!state.isLoggedIn) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return false;
    }
    if (typeof callback === 'function') {
        callback();
    }
    return true;
}

// ============================================================
// LOGOUT BOTTOM SHEET
// ============================================================

function openLogoutSheet() {
    const overlay = $('logoutSheetOverlay');
    const sheet = $('logoutBottomSheet');
    if (overlay) overlay.classList.add('open');
    if (sheet) sheet.classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeLogoutSheet() {
    const overlay = $('logoutSheetOverlay');
    const sheet = $('logoutBottomSheet');
    if (overlay) overlay.classList.remove('open');
    if (sheet) sheet.classList.remove('open');
    document.body.style.overflow = '';
}

// ============================================================
// NOTIFICATION PERMISSION
// ============================================================

function requestNotificationPermission() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }
}

// ============================================================
// NOTIFICATIONS
// ============================================================

function renderNotifications() {
    const container = $('notificationsList');
    if (!container) return;
    
    if (state.notifications.length === 0) {
        container.innerHTML = `
            <div class="notifications-empty">
                <i class="fa-regular fa-bell-slash"></i>
                <div class="title">لا توجد إشعارات</div>
                <div class="sub">ستظهر الإشعارات هنا عند توفرها</div>
            </div>
        `;
        return;
    }
    
    const sortedNotifs = [...state.notifications].sort(function(a, b) {
        return new Date(b.createdAt) - new Date(a.createdAt);
    });
    
    const unreadCount = sortedNotifs.filter(function(n) { return !n.isRead; }).length;
    
    let html = `
        <div class="notif-header">
            <div class="notif-title">
                <i class="fa-regular fa-bell" style="color:var(--primary);"></i>
                الإشعارات
                <span class="count">${sortedNotifs.length}</span>
                ${unreadCount > 0 ? `<span style="font-size:11px;color:var(--red);background:rgba(231,76,60,.1);padding:2px 10px;border-radius:20px;">${unreadCount} جديد</span>` : ''}
            </div>
            <div class="notif-actions">
                ${unreadCount > 0 ? `
                    <button class="btn-mark-all" onclick="markAllNotificationsRead()">
                        <i class="fa-solid fa-check-double"></i> كمقروء
                    </button>
                ` : ''}
                <button class="btn-delete-all" onclick="deleteAllNotifications()">
                    <i class="fa-solid fa-trash"></i> حذف
                </button>
            </div>
        </div>
        <div class="notifications-container">
    `;
    
    sortedNotifs.forEach(function(n) {
        const icons = { offer: 'fa-tag', order: 'fa-box', wallet: 'fa-wallet', info: 'fa-bell', chat: 'fa-comment' };
        const icon = icons[n.type] || 'fa-bell';
        const time = new Date(n.createdAt);
        const now = new Date();
        const diff = Math.floor((now - time) / 60000);
        let timeStr;
        if (diff < 1) timeStr = 'الآن';
        else if (diff < 60) timeStr = 'منذ ' + diff + ' دقيقة';
        else if (diff < 1440) timeStr = 'منذ ' + Math.floor(diff / 60) + ' ساعة';
        else timeStr = 'منذ ' + Math.floor(diff / 1440) + ' يوم';
        const isRead = n.isRead || false;
        const typeClass = n.type || 'info';
        
        html += `
            <div class="notification-item ${isRead ? '' : 'unread'}" 
                 data-id="${n.id}" 
                 onclick="toggleNotificationRead(${n.id})"
                 ontouchstart="handleSwipeStart(event, this)"
                 ontouchmove="handleSwipeMove(event, this)"
                 ontouchend="handleSwipeEnd(event, this)">
                <div class="unread-dot"></div>
                <div class="notif-icon ${typeClass}">
                    <i class="fa-solid ${icon}"></i>
                </div>
                <div class="notif-content">
                    <div class="notif-title">${n.title}</div>
                    <div class="notif-desc">${n.message}</div>
                    <div class="notif-time">
                        <i class="fa-regular fa-clock"></i> ${timeStr}
                    </div>
                </div>
                <div class="swipe-hint"><i class="fa-solid fa-trash"></i> اسحب للحذف</div>
            </div>
        `;
    });
    
    html += `</div>`;
    container.innerHTML = html;
}

function toggleNotificationRead(id) {
    const notification = state.notifications.find(function(n) { return n.id === id; });
    if (!notification) return;
    
    const newStatus = notification.isRead ? 0 : 1;
    notification.isRead = newStatus;
    updateNotifBadge();
    renderNotifications();
    
    if (newStatus === 1) {
        callAPI('markNotificationRead', { id: id }, 'POST').then(function(response) {
            if (!response.success) {
                notification.isRead = 0;
                updateNotifBadge();
                renderNotifications();
            }
        });
    }
}

function markAllNotificationsRead() {
    if (!requireLogin()) return;
    
    state.notifications.forEach(function(n) {
        n.isRead = 1;
    });
    updateNotifBadge();
    renderNotifications();
    
    callAPI('markAllNotificationsRead', {}, 'POST').then(function(response) {
        if (!response.success) {
            loadAllData(true);
        }
    });
}

function deleteAllNotifications() {
    if (!requireLogin()) return;
    
    state.notifications = [];
    updateNotifBadge();
    renderNotifications();
    
    callAPI('deleteAllNotifications', {}, 'POST').then(function(response) {
        if (!response.success) {
            loadAllData(true);
        }
    });
}

function deleteNotification(id) {
    if (!requireLogin()) return;
    
    const index = state.notifications.findIndex(function(n) { return n.id === id; });
    if (index !== -1) {
        state.notifications.splice(index, 1);
        updateNotifBadge();
        renderNotifications();
    }
    
    callAPI('deleteNotification', { id: id }, 'POST').then(function(response) {
        if (!response.success) {
            loadAllData(true);
        }
    });
}

function updateNotifBadge() {
    const unread = state.notifications.filter(function(n) { return !n.isRead; }).length;
    const badge = $('notifBadge');
    if (badge) {
        badge.textContent = unread;
        badge.style.display = unread > 0 ? 'flex' : 'none';
    }
}

// ===== دوال السحب للحذف =====
let swipeData = {
    startX: 0,
    currentX: 0,
    isSwiping: false,
    element: null
};

function handleSwipeStart(event, element) {
    const touch = event.touches[0];
    swipeData.startX = touch.clientX;
    swipeData.currentX = touch.clientX;
    swipeData.isSwiping = false;
    swipeData.element = element;
    element.classList.remove('swiped');
}

function handleSwipeMove(event, element) {
    const touch = event.touches[0];
    const diffX = touch.clientX - swipeData.startX;
    
    if (diffX < -20) {
        swipeData.isSwiping = true;
        const offset = Math.max(diffX, -150);
        element.style.setProperty('--swipe-offset', offset + 'px');
        element.classList.add('swiping');
        element.style.opacity = 1 - (Math.abs(offset) / 200);
    } else {
        element.classList.remove('swiping');
        element.style.setProperty('--swipe-offset', '0px');
        element.style.opacity = '1';
    }
}

function handleSwipeEnd(event, element) {
    const diffX = event.changedTouches[0].clientX - swipeData.startX;
    element.classList.remove('swiping');
    
    if (diffX < -80) {
        const id = parseInt(element.dataset.id);
        element.classList.add('swiped');
        setTimeout(function() {
            deleteNotification(id);
        }, 400);
    } else {
        element.style.setProperty('--swipe-offset', '0px');
        element.style.opacity = '1';
    }
}

// ===== دعم السحب بالفأرة =====
let mouseSwipeData = {
    startX: 0,
    isDragging: false,
    element: null
};

document.addEventListener('mousedown', function(e) {
    const element = e.target.closest('.notification-item');
    if (!element) return;
    
    mouseSwipeData.startX = e.clientX;
    mouseSwipeData.isDragging = false;
    mouseSwipeData.element = element;
    element.classList.remove('swiped');
});

document.addEventListener('mousemove', function(e) {
    if (!mouseSwipeData.element) return;
    
    const diffX = e.clientX - mouseSwipeData.startX;
    if (Math.abs(diffX) > 10) {
        mouseSwipeData.isDragging = true;
    }
    
    if (mouseSwipeData.isDragging && diffX < 0) {
        const offset = Math.max(diffX, -150);
        mouseSwipeData.element.style.setProperty('--swipe-offset', offset + 'px');
        mouseSwipeData.element.classList.add('swiping');
        mouseSwipeData.element.style.opacity = 1 - (Math.abs(offset) / 200);
    }
});

document.addEventListener('mouseup', function(e) {
    if (!mouseSwipeData.element) return;
    
    const diffX = e.clientX - mouseSwipeData.startX;
    mouseSwipeData.element.classList.remove('swiping');
    
    if (mouseSwipeData.isDragging && diffX < -80) {
        const id = parseInt(mouseSwipeData.element.dataset.id);
        mouseSwipeData.element.classList.add('swiped');
        setTimeout(function() {
            deleteNotification(id);
        }, 400);
    } else {
        mouseSwipeData.element.style.setProperty('--swipe-offset', '0px');
        mouseSwipeData.element.style.opacity = '1';
    }
    
    mouseSwipeData.isDragging = false;
    mouseSwipeData.element = null;
});

// ============================================================
// RECHARGE
// ============================================================

function openRechargePage() {
    if (!requireLogin()) return;
    openPage('rechargePage');
    renderRechargeForm();
    loadRechargeHistory();
}

function renderRechargeForm() {
    const container = $('rechargeForm');
    if (!container) return;
    
let html = `
    <div class="payment-card-container" style="background-image: url('https://i.ibb.co/FbQvCzn3/image.png'); background-size: cover; background-position: center; background-repeat: no-repeat; border-radius: 16px; overflow: hidden; position: relative; width: 100%; aspect-ratio: 16/9; margin-bottom: 16px; box-shadow: 0 8px 32px rgba(5,134,147,.2); border: 1px solid rgba(255,255,255,0.1);">
        <div style="position:absolute;inset:0;background:rgba(0,0,0,0.3);"></div>
        <div style="position:relative;z-index:1;padding:16px 20px;display:flex;flex-direction:column;justify-content:space-between;height:100%;color:#fff;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <div style="font-size:10px;opacity:0.8;letter-spacing:1px;font-weight:600;text-shadow:0 1px 4px rgba(0,0,0,0.3);">بطاقة الدفع سوبر كي</div>
                    <div style="font-size:18px;font-weight:800;margin-top:2px;text-shadow:0 1px 4px rgba(0,0,0,0.3);"> </div>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="font-size:10px;opacity:0.7;text-shadow:0 1px 4px rgba(0,0,0,0.3);">مدعوم من</span>
                    <i class="fa-brands fa-cc-visa" style="font-size:22px;opacity:0.9;text-shadow:0 1px 4px rgba(0,0,0,0.3);"></i>
                    <i class="fa-brands fa-cc-mastercard" style="font-size:22px;opacity:0.9;text-shadow:0 1px 4px rgba(0,0,0,0.3);"></i>
                </div>
            </div>
            <div style="text-align:center;padding:6px 0;">
                <div style="font-size:11px;opacity:0.8;font-weight:600;text-shadow:0 1px 4px rgba(0,0,0,0.3);">المبلغ المطلوب</div>
                <div style="font-size:32px;font-weight:900;letter-spacing:1px;text-shadow:0 2px 8px rgba(0,0,0,0.4);" id="cardAmountDisplay">0.00 د.ع</div>
                <div style="font-size:11px;opacity:0.6;margin-top:2px;text-shadow:0 1px 4px rgba(0,0,0,0.3);">أدخل المبلغ في الحقل أدناه</div>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:flex-end;font-size:10px;opacity:0.7;border-top:1px solid rgba(255,255,255,0.15);padding-top:8px;text-shadow:0 1px 4px rgba(0,0,0,0.3);">
                <div style="display:flex;align-items:center;gap:6px;">
                    <i class="fa-regular fa-clock"></i>
                    <span>معالجة فورية</span>
                </div>
                <div style="display:flex;align-items:center;gap:6px;">
                    <i class="fa-regular fa-shield"></i>
                    <span>مدفوعات آمنة</span>
                </div>
            </div>
        </div>
    </div>
    
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;box-shadow:var(--shadow);">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
            <i class="fa-solid fa-wallet" style="color:var(--primary);font-size:20px;"></i>
            <h3 style="font-size:16px;font-weight:800;color:var(--text2);margin:0;">طلب شحن الرصيد</h3>
        </div>
       
            
            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                    <i class="fa-solid fa-dollar-sign"></i> المبلغ <span style="color:var(--red);">*</span>
                </label>
                <div style="display:flex;align-items:center;gap:10px;">
                    <input type="number" id="rechargeAmount" step="100" min="1000" placeholder="1000" required 
                           style="flex:1;padding:12px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:16px;font-weight:700;outline:none;transition:border-color 0.3s;">
                    <span style="font-size:16px;font-weight:800;color:var(--primary);min-width:40px;">د.ع</span>
                </div>
                <small style="color:var(--text3);font-size:11px;">💡 الحد الأدنى <strong>1000</strong> دينار عراقي</small>
            </div>
            
            <button class="btn-submit" onclick="showPaymentDetails()" style="width:100%;padding:14px;border:none;border-radius:12px;background:var(--primary-light);color:#fff;font-weight:700;font-size:16px;cursor:pointer;transition:all 0.3s var(--ease-spring);display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:14px;">
                <i class="fa-solid fa-credit-card"></i> إدفع الآن
            </button>
        </div>
        
        <div id="paymentDetailsContainer" style="display:none;background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:14px;box-shadow:var(--shadow);animation:slideUp 0.4s var(--ease-out);">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                <i class="fa-solid fa-circle-info" style="color:var(--primary);font-size:18px;"></i>
                <h4 style="font-size:15px;font-weight:800;color:var(--text2);margin:0;">تفاصيل الدفع</h4>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:10px;border:1px solid var(--border);">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">رقم الحساب</div>
                        <div style="font-size:18px;font-weight:900;color:var(--text2);letter-spacing:1px;" id="accountNumberDisplay">7114152353</div>
                    </div>
                    <button onclick="copyAccountNumber()" style="padding:8px 14px;border:none;border-radius:8px;background:var(--primary-light);color:#fff;font-size:12px;font-weight:700;cursor:pointer;transition:all 0.3s;">
                        <i class="fa-regular fa-copy"></i> نسخ
                    </button>
                </div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="font-size:11px;color:var(--text3);font-weight:600;">ملاحضة</div>
                <div style="font-size:16px;font-weight:700;color:var(--text2);" id="beneficiaryDisplay">الحوالة تتم عبر سوبر كي حصرا بعد قم بتحويل وارفاق صوره للتحويل  وانتظر موافقة والاضافة خلال دقائق</div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">المبلغ المطلوب</div>
                        <div style="font-size:18px;font-weight:900;color:var(--primary);" id="paymentAmountDisplay">0 د.ع</div>
                    </div>
                    <i class="fa-solid fa-money-bill-wave" style="font-size:24px;color:var(--gold);opacity:0.5;"></i>
                </div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:12px;border:1px solid var(--border);">
                <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:6px;">
                    <i class="fa-regular fa-image"></i> صورة التحويل <span style="color:var(--red);">*</span>
                </label>
                <input type="file" id="rechargeReceipt" accept="image/*" required style="width:100%;padding:10px;border:2px solid var(--border);border-radius:8px;background:var(--surface);font-size:13px;cursor:pointer;">
                <div id="receiptPreview" style="width:100%;height:120px;border-radius:10px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;margin-top:8px;font-size:40px;color:var(--text3);border:2px dashed var(--border);transition:all 0.3s;">
                    <i class="fa-solid fa-image"></i>
                    <div style="font-size:12px;margin-top:4px;">اضغط لرفع الصورة</div>
                </div>
                <small style="color:var(--text3);font-size:11px;">📷 الصيغ المدعومة: PNG, JPG, WEBP, GIF (الحد الأقصى 5MB)</small>
            </div>
            
      <button class="btn-submit" id="rechargeSubmitBtn" onclick="submitRechargeRequest()" style="width:100%;padding:14px;border:none;border-radius:12px;background:var(--green);color:#fff;font-weight:700;font-size:16px;cursor:pointer;transition:all 0.3s var(--ease-spring);display:flex;align-items:center;justify-content:center;gap:10px;">
    <i class="fa-solid fa-paper-plane"></i> تقديم طلب الشحن
</button>
        </div>
        <div style="height: 120px;"></div>
        <div id="rechargeHistoryContainer" style="margin-top:20px;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;border-bottom:1px solid var(--border);padding-bottom:8px;">
                <h3 style="font-size:16px;font-weight:800;color:var(--text2);display:flex;align-items:center;gap:8px;margin:0;">
                    <i class="fa-solid fa-clock-rotate-left"></i> سجل الشحن
                </h3>
                <span id="historyCount" style="font-size:12px;color:var(--text3);font-weight:600;">0 طلب</span>
            </div>
            <div id="rechargeHistory"></div>
        </div>
    `;
    
    container.innerHTML = html;
    
    const amountInput = $('rechargeAmount');
    if (amountInput) {
        amountInput.addEventListener('input', function() {
            const val = parseFloat(this.value) || 0;
            const display = $('cardAmountDisplay');
            if (display) display.textContent = val > 0 ? val.toFixed(2) + ' د.ع' : '0.00 د.ع';
        });
    }
    
    const fileInput = $('rechargeReceipt');
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            const preview = $('receiptPreview');
            if (this.files && this.files[0] && preview) {
                if (this.files[0].size > 5 * 1024 * 1024) {
                    showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
                    this.value = '';
                    preview.innerHTML = '<i class="fa-solid fa-image"></i><div style="font-size:12px;margin-top:4px;">اضغط لرفع الصورة</div>';
                    preview.style.borderColor = 'var(--border)';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
                    preview.style.borderColor = 'var(--primary)';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
    
    loadRechargeHistory();
}

function showPaymentDetails() {
    const amountInput = $('rechargeAmount');
    const amount = parseFloat(amountInput ? amountInput.value : 0);
    
    if (isNaN(amount) || amount < 1000) {
        showToast('⚠️ الحد الأدنى للشحن هو 1000 دينار عراقي');
        if (amountInput) {
            amountInput.style.borderColor = 'var(--red)';
            setTimeout(function() { amountInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        return;
    }
    
    const container = $('paymentDetailsContainer');
    if (container) {
        container.style.display = 'block';
        container.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    
    const display = $('paymentAmountDisplay');
    if (display) display.textContent = amount.toFixed(2) + ' د.ع';
    state.rechargeAmount = amount;
}

function copyAccountNumber() {
    const accountEl = $('accountNumberDisplay');
    if (!accountEl) return;
    const text = accountEl.textContent.trim();
    
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            showToast('✅ تم نسخ رقم الحساب');
        }).catch(function() {
            copyTextFallback(text);
        });
    } else {
        copyTextFallback(text);
    }
}

function copyTextFallback(text) {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.style.position = 'fixed';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.select();
    try {
        document.execCommand('copy');
        showToast('✅ تم نسخ رقم الحساب');
    } catch (e) {
        showToast('❌ فشل النسخ، يرجى نسخ الرقم يدوياً');
    }
    document.body.removeChild(textarea);
}

function submitRechargeRequest() {
    console.log('🔄 بدء عملية تقديم طلب الشحن...');
    
    const amount = state.rechargeAmount || 0;
    const paymentMethod = 'bank_transfer';
    const fileInput = document.getElementById('rechargeReceipt');
    
    let hasError = false;
    
    // ===== التحقق من المبلغ =====
    if (amount < 1000) {
        showToast('⚠️ الحد الأدنى للشحن هو 1000 دينار عراقي');
        const amountInput = document.getElementById('rechargeAmount');
        if (amountInput) {
            amountInput.style.borderColor = 'var(--red)';
            setTimeout(function() { amountInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        hasError = true;
    }
    
    // ===== التحقق من الصورة =====
    if (!fileInput) {
        showToast('⚠️ حقل رفع الصورة غير موجود');
        hasError = true;
    } else if (!fileInput.files || !fileInput.files[0]) {
        showToast('⚠️ الرجاء رفع صورة الإيصال');
        fileInput.style.borderColor = 'var(--red)';
        setTimeout(function() { fileInput.style.borderColor = 'var(--border)'; }, 3000);
        hasError = true;
    } else {
        const fileSize = fileInput.files[0].size;
        if (fileSize > 5 * 1024 * 1024) {
            showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
            fileInput.value = '';
            const preview = document.getElementById('receiptPreview');
            if (preview) {
                preview.innerHTML = '<i class="fa-solid fa-image"></i><div style="font-size:12px;margin-top:4px;">اضغط لرفع الصورة</div>';
                preview.style.borderColor = 'var(--border)';
            }
            hasError = true;
        } else {
            console.log('📷 حجم الصورة:', fileSize, 'bytes');
        }
    }
    
    if (hasError) {
        console.log('❌ يوجد أخطاء في المدخلات، تم إيقاف الإرسال');
        return;
    }
    
    // ===== إنشاء FormData =====
    const formData = new FormData();
    formData.append('amount', amount);
    formData.append('paymentMethod', 'bank_transfer');
    formData.append('receipt', fileInput.files[0]);
    
    console.log('📦 البيانات المرسلة:', {
        amount: amount,
        paymentMethod: 'bank_transfer',
        receiptFile: fileInput.files[0].name
    });
    
    // ===== تعطيل زر التقديم =====
    const submitBtn = document.getElementById('rechargeSubmitBtn') || document.querySelector('#rechargeForm .btn-submit:last-child');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...';
        submitBtn.style.opacity = '0.6';
    }
    
    // ===== إرسال الطلب =====
    callAPI('requestRecharge', formData, 'POST')
        .then(function(response) {
            console.log('📨 استجابة الخادم:', response);
            
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> تقديم طلب الشحن';
                submitBtn.style.opacity = '1';
            }
            
            if (response.success) {
                showToast('✅ تم تقديم طلب الشحن بنجاح، في انتظار المراجعة');
                
                // ===== إعادة تعيين الحقول =====
                const amountInput = document.getElementById('rechargeAmount');
                if (amountInput) amountInput.value = '';
                
                const fileInput2 = document.getElementById('rechargeReceipt');
                if (fileInput2) fileInput2.value = '';
                
                const preview = document.getElementById('receiptPreview');
                if (preview) {
                    preview.innerHTML = '<i class="fa-solid fa-image"></i><div style="font-size:12px;margin-top:4px;">اضغط لرفع الصورة</div>';
                    preview.style.borderColor = 'var(--border)';
                }
                
                const details = document.getElementById('paymentDetailsContainer');
                if (details) details.style.display = 'none';
                
                const cardDisplay = document.getElementById('cardAmountDisplay');
                if (cardDisplay) cardDisplay.textContent = '0.00 د.ع';
                
                state.rechargeAmount = 0;
                
                // ===== تحديث البيانات =====
                loadRechargeHistory();
                loadAllData(true);
                updateBalanceDisplay();
                
            } else {
                showToast('❌ ' + (response.message || 'فشل إرسال طلب الشحن'));
            }
        })
        .catch(function(error) {
            console.error('❌ خطأ في الاتصال:', error);
            
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> تقديم طلب الشحن';
                submitBtn.style.opacity = '1';
            }
            showToast('❌ حدث خطأ في الاتصال بالخادم: ' + (error.message || ''));
        });
}

function loadRechargeHistory() {
    if (!state.isLoggedIn) return;
    callAPI('getRecharges', {}, 'GET').then(function(response) {
        if (response.success) {
            state.recharges = response.data || [];
            renderRechargeHistory();
        }
    });
}

function renderRechargeHistory() {
    const container = $('rechargeHistory');
    if (!container) return;
    const countEl = $('historyCount');
    
    if (state.recharges.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:30px 20px;color:var(--text3);">
                <i class="fa-regular fa-clock" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.3;"></i>
                <div style="font-size:14px;font-weight:600;">${t('no_recharges')}</div>
                <div style="font-size:12px;margin-top:4px;">${t('recharge_now')}</div>
            </div>
        `;
        if (countEl) countEl.textContent = '0 طلب';
        return;
    }
    
    if (countEl) countEl.textContent = state.recharges.length + ' طلب';
    
    container.innerHTML = state.recharges.map(function(r) {
        const statusMap = {
            pending: { text: '⏳ ' + t('pending_review'), color: 'var(--secondary)', bg: 'rgba(216,176,122,.15)', icon: 'fa-spinner fa-spin' },
            approved: { text: '✅ ' + t('approved'), color: 'var(--green)', bg: 'rgba(46,204,113,.15)', icon: 'fa-circle-check' },
            rejected: { text: '❌ ' + t('rejected'), color: 'var(--red)', bg: 'rgba(231,76,60,.15)', icon: 'fa-circle-xmark' }
        };
        const status = statusMap[r.status] || statusMap.pending;
        const date = new Date(r.createdAt);
        const dateStr = date.toLocaleDateString('ar-EG') + ' ' + date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
        
        return `
            <div class="recharge-item" onclick="openRechargeDetail(${r.id})" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:10px;cursor:pointer;transition:all 0.3s;">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                    <div style="flex:1;min-width:140px;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="font-size:18px;font-weight:900;color:var(--primary);">${parseFloat(r.amount).toFixed(2)} د.ع</span>
                            <span style="font-size:11px;color:var(--text3);">${r.paymentMethod || 'تحويل بنكي'}</span>
                        </div>
                        <div style="font-size:12px;color:var(--text3);margin-top:2px;">
                            <i class="fa-regular fa-calendar"></i> ${dateStr}
                        </div>
                        ${r.receiptImage ? `
                            <div style="font-size:11px;color:var(--primary);margin-top:4px;">
                                <i class="fa-regular fa-image"></i> ${t('receipt')}
                            </div>
                        ` : ''}
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700;background:${status.bg};color:${status.color};">
                            ${status.text}
                        </span>
                        <i class="fa-regular ${status.icon}" style="color:${status.color};font-size:16px;"></i>
                        <i class="fa-solid fa-chevron-left" style="color:var(--text3);font-size:14px;"></i>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// ============================================================
// RECHARGE DETAIL
// ============================================================
// ============================================================
// ✅ دالة عرض تفاصيل طلب الشحن (للمستخدم)
// ============================================================
function openRechargeDetail(rechargeId) {
    const recharge = state.recharges.find(function(r) { return r.id === rechargeId; });
    if (!recharge) {
        showToast('⚠️ طلب الشحن غير موجود');
        return;
    }
    
    const existingOverlay = document.querySelector('.recharge-detail-overlay');
    if (existingOverlay) {
        existingOverlay.remove();
    }
    
    const overlay = document.createElement('div');
    overlay.className = 'recharge-detail-overlay';
    overlay.style.cssText = `
        position: fixed;
        inset: 0;
        z-index: 400;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(8px);
        display: flex;
        justify-content: center;
        align-items: center;
        animation: fadeInOverlay 0.3s var(--ease-out);
        padding: 20px;
        padding-top: var(--safe-area-top);
        padding-bottom: var(--safe-area-bottom);
    `;
    
    const statusMap = {
        pending: { text: '⏳ بانتظار المراجعة', color: 'var(--secondary)', bg: 'rgba(216,176,122,.15)' },
        approved: { text: '✅ تمت الموافقة', color: 'var(--green)', bg: 'rgba(46,204,113,.15)' },
        rejected: { text: '❌ مرفوض', color: 'var(--red)', bg: 'rgba(231,76,60,.15)' }
    };
    const status = statusMap[recharge.status] || statusMap.pending;
    
    const date = new Date(recharge.createdAt);
    const dateStr = date.toLocaleDateString('ar-EG') + ' ' + date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    
    let receiptHtml = '';
    if (recharge.receiptImage) {
        const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
        const imgUrl = baseUrl + '/data/uploads/receipts/' + recharge.receiptImage;
        receiptHtml = `
            <div style="margin-top:12px;padding:12px;background:var(--bg2);border-radius:10px;border:1px solid var(--border);">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <i class="fa-solid fa-image" style="color:var(--primary);"></i>
                    <span style="font-weight:700;font-size:13px;">🖼️ صورة الإيصال</span>
                </div>
                <img src="${imgUrl}" onclick="event.stopPropagation();openLightbox('${imgUrl}')" 
                     style="width:100%;max-height:250px;border-radius:8px;cursor:pointer;object-fit:contain;border:1px solid var(--border);"
                     onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML += '<div style=\\'color:var(--red);padding:20px;text-align:center;background:var(--bg2);border-radius:8px;\\'><i class=\\'fa-solid fa-image\\' style=\\'font-size:48px;display:block;margin-bottom:8px;opacity:0.3;\\'></i>⚠️ تعذر تحميل الصورة<br><small style=\\'font-size:11px;color:var(--text3);\\'>يرجى المحاولة مرة أخرى</small></div>';">
            </div>
        `;
    }
    
    overlay.innerHTML = `
        <div class="recharge-detail-card" style="background:var(--surface);border-radius:16px;padding:24px;max-width:480px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);animation:slideUp 0.4s var(--ease-spring);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="font-size:18px;font-weight:800;color:var(--text2);display:flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-wallet" style="color:var(--primary);"></i>
                    تفاصيل طلب الشحن
                    <span style="font-size:12px;font-weight:400;color:var(--text3);">#${recharge.id}</span>
                </h3>
                <button onclick="closeRechargeDetail()" style="width:36px;height:36px;border:none;border-radius:50%;background:var(--bg2);color:var(--text3);font-size:18px;cursor:pointer;display:grid;place-items:center;transition:all 0.3s;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
                <div style="background:var(--bg2);border-radius:10px;padding:12px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">💰 المبلغ</div>
                    <div style="font-size:20px;font-weight:900;color:var(--primary);">${parseFloat(recharge.amount).toFixed(2)} د.ع</div>
                </div>
                <div style="background:var(--bg2);border-radius:10px;padding:12px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">📌 الحالة</div>
                    <span style="padding:4px 12px;border-radius:12px;font-size:13px;font-weight:700;background:${status.bg};color:${status.color};display:inline-block;margin-top:2px;">
                        ${status.text}
                    </span>
                </div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">📅 تاريخ الطلب</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text2);">${dateStr}</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">💳 طريقة الدفع</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text2);">${recharge.paymentMethod || 'تحويل بنكي'}</div>
                    </div>
                </div>
            </div>
            
            ${receiptHtml}
            
            ${recharge.notes ? `
                <div style="background:var(--bg2);border-radius:10px;padding:12px;margin-top:12px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">📝 ملاحظات</div>
                    <div style="font-size:14px;color:var(--text2);margin-top:4px;">${recharge.notes}</div>
                </div>
            ` : ''}
            
            <button onclick="closeRechargeDetail()" style="width:100%;padding:12px;border:2px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text2);font-weight:700;font-size:14px;cursor:pointer;margin-top:16px;transition:all 0.3s;">
                <i class="fa-solid fa-arrow-right"></i> إغلاق
            </button>
        </div>
    `;
    
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
}

// ============================================================
// ✅ دالة إغلاق تفاصيل طلب الشحن
// ============================================================
function closeRechargeDetail() {
    const overlay = document.querySelector('.recharge-detail-overlay');
    if (overlay) {
        overlay.remove();
        document.body.style.overflow = '';
        updateBottomNavVisibility();
    }
}

// ============================================================
// ✅ دالة عرض تفاصيل الطلب (للمستخدم والأدمن)
// ============================================================
function openOrderDetail(orderId) {
    if (!state.isLoggedIn) {
        showToast('يرجى تسجيل الدخول');
        return;
    }

    const getEl = (id) => document.getElementById(id);

    const order = state.orders.find(function(o) {
        return o.id == orderId || o.orderId == orderId;
    });

    if (!order) {
        showToast('⚠️ الطلب غير موجود');
        return;
    }

    const isAdmin = state.isAdmin;
    const isOwner = state.user && order.userId == state.user.id;

    if (!isAdmin && !isOwner) {
        showToast('⚠️ غير مصرح لك بعرض هذا الطلب');
        return;
    }

    document.querySelectorAll('.settings-page.open, .product-detail-page.open, .auth-page.open, .chat-app-user.open, .favorites-page.open, .orders-page.open, .recharge-page.open, .admin-page.open')
        .forEach(function(page) {
            if (page.id !== 'orderDetailPage') {
                page.classList.remove('open');
            }
        });

    const detailPage = getEl('orderDetailPage');
    if (detailPage) {
        detailPage.classList.add('open');
        document.body.style.overflow = 'hidden';
        updateBottomNavVisibility();
    }
    
    const titleEl = getEl('orderDetailTitle');
    if (titleEl) {
        titleEl.textContent = '📋 تفاصيل الطلب - ' + (order.orderId || '#' + order.id);
    }

    const statusLabels = {
        pending: '⏳ قيد المعالجة',
        shipped: '🚚 قيد التوصيل',
        completed: '✅ مكتمل',
        cancelled: '❌ ملغي',
        approved: '✅ تم الموافقة',
        rejected: '❌ مرفوض'
    };
    
    const statusClass = ['pending', 'shipped', 'completed', 'cancelled', 'approved', 'rejected'].includes(order.status) ? order.status : 'pending';
    
    const statusColors = {
        pending: { bg: '#fff3cd', text: '#856404' },
        shipped: { bg: '#cce5ff', text: '#004085' },
        completed: { bg: '#d4edda', text: '#155724' },
        cancelled: { bg: '#f8d7da', text: '#721c24' },
        approved: { bg: '#d4edda', text: '#155724' },
        rejected: { bg: '#f8d7da', text: '#721c24' }
    };
    const currentThemeColor = statusColors[statusClass] || { bg: '#e2e3e5', text: '#383d41' };
    const bgColor = currentThemeColor.bg;
    const textColor = currentThemeColor.text;

    let items = [];
    try {
        let rawItems = order.items || order.cart || order.products;
        if (rawItems) {
            if (typeof rawItems === 'string') {
                try {
                    items = JSON.parse(rawItems);
                } catch (parseErr) {
                    items = [];
                }
            } else if (Array.isArray(rawItems)) {
                items = rawItems;
            } else if (typeof rawItems === 'object' && rawItems !== null) {
                items = Object.values(rawItems);
            }
        }
    } catch (e) {
        items = [];
    }
    
    if (!Array.isArray(items)) {
        items = [];
    }

    const transferContainer = getEl('orderDetailTransferContainer');
    let transferHtml = '';
    if (order.transferImage) {
        const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
        const imgUrl = baseUrl + '/data/uploads/transfers/' + order.transferImage;
        
        transferHtml = `
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <i class="fa-solid fa-image" style="color:#0f3d1c;"></i>
                <span style="font-weight:700;font-size:14px;">🖼️ صورة التحويل</span>
            </div>
            <img src="${imgUrl}" onclick="openLightbox('${imgUrl}')"
                 style="max-width:100%;border-radius:8px;cursor:pointer;max-height:300px;object-fit:contain;border:1px solid var(--border);"
                 onerror="this.style.display='none'; this.parentElement.innerHTML += '<div style=\\'color:var(--red);text-align:center;padding:10px;\\'>⚠️ تعذر تحميل الصورة</div>';">
            
            ${order.transferAmount ? `<div style="margin-top:8px;font-size:13px;color:var(--text3);">💰 المبلغ المحول: <strong>${parseFloat(order.transferAmount || 0).toFixed(2)} د.ع</strong></div>` : ''}
            ${order.accountNumber ? `<div style="font-size:13px;color:var(--text3);">🏦 رقم الحساب: <strong>${order.accountNumber}</strong></div>` : ''}
            ${order.beneficiary ? `<div style="font-size:13px;color:var(--text3);">👤 المستفيد: <strong>${order.beneficiary}</strong></div>` : ''}
        </div>
        `;
    }
    if (transferContainer) {
        transferContainer.innerHTML = transferHtml;
    }

    let userInfoHtml = '';
    if (isAdmin && order.userId && state.users) {
        const targetUser = state.users.find(function(u) { return u.id == order.userId; });
        if (targetUser) {
            userInfoHtml = `
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                    <i class="fa-solid fa-user" style="color:var(--primary);"></i>
                    <span style="font-weight:700;font-size:14px;">👤 معلومات العميل</span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
                    <div><span style="color:var(--text3);">الاسم:</span> <strong>${targetUser.name || '-'}</strong></div>
                    <div><span style="color:var(--text3);">الهاتف:</span> <strong>${targetUser.phone || '-'}</strong></div>
                    <div style="grid-column:1/-1;"><span style="color:var(--text3);">البريد:</span> <strong>${targetUser.email || '-'}</strong></div>
                </div>
            </div>
            `;
        }
    }

    const body = getEl('orderDetailBody');
    if (!body) return;

    body.innerHTML = `
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:16px;box-shadow:var(--shadow);">
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(120px, 1fr));gap:12px;">
            <div>
                <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">📌 الحالة</div>
                <span class="order-status-badge ${statusClass}" style="display:inline-block;padding:6px 16px;border-radius:20px;font-size:13px;font-weight:700;background:${bgColor};color:${textColor};margin-top:4px;">
                    ${statusLabels[order.status] || order.status}
                </span>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">📅 التاريخ</div>
                <div style="font-weight:700;font-size:14px;margin-top:4px;">${order.date || (order.createdAt ? new Date(order.createdAt).toLocaleDateString('ar-EG') : '')}</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">💰 المجموع</div>
                <div style="font-size:20px;font-weight:900;color:var(--primary);margin-top:2px;">${parseFloat(order.total || 0).toFixed(2)} د.ع</div>
            </div>
            <div>
                <div style="font-size:11px;color:var(--text3);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">🆔 رقم الطلب</div>
                <div style="font-weight:700;font-size:13px;margin-top:4px;direction:ltr;">${order.orderId || order.id}</div>
            </div>
        </div>
    </div>

    ${userInfoHtml}

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <i class="fa-solid fa-location-dot" style="color:var(--primary);font-size:16px;"></i>
            <span style="font-weight:700;font-size:14px;">📍 معلومات التوصيل</span>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
            <div style="grid-column:1/-1;">
                <span style="color:var(--text3);">العنوان:</span>
                <div style="font-weight:600;margin-top:3px;padding:8px 12px;background:var(--bg2);border-radius:8px;word-break:break-word;">${order.address || 'غير محدد'}</div>
            </div>
            <div>
                <span style="color:var(--text3);">📞 الهاتف:</span>
                <div style="font-weight:600;margin-top:3px;">${order.phone || 'غير محدد'}</div>
            </div>
            <div>
                <span style="color:var(--text3);">💳 طريقة الدفع:</span>
                <div style="font-weight:600;margin-top:3px;">
                    ${order.payment === 'cash' ? '💰 الدفع عند الاستلام' : 
                      order.payment === 'electronic' ? '💳 الدفع الإلكتروني' : 
                      (order.payment === 'transfer' || order.payment === 'bank_transfer') ? '🏦 تحويل بنكي' : 
                      order.payment || 'غير محدد'}
                </div>
            </div>
        </div>
    </div>

    ${transferHtml}

    <div style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
            <i class="fa-solid fa-box" style="color:var(--primary);font-size:16px;"></i>
            <span style="font-weight:700;font-size:14px;">🛒 المنتجات (${items.length})</span>
        </div>
        ${items.length > 0 ? items.map(function(item, index) {
            if (!item) return '';
            let imgSrc = item.image_url || item.image || '';
            if (!imgSrc && state.products) {
                const productInState = state.products.find(p => p.id == item.id);
                if (productInState) imgSrc = productInState.image_url || productInState.image || '';
            }
            
            const imgHtml = imgSrc ? 
                `<img src="${imgSrc}" style="width:100%;height:100%;object-fit:cover;">` : 
                `<i class="fa-solid fa-box" style="color:var(--primary);"></i>`;

            return `
            <div style="display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:${index < items.length - 1 ? '1px solid var(--border)' : 'none'};">
                <div style="width:48px;height:48px;border-radius:10px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;font-size:20px;flex-shrink:0;border:1px solid var(--border);">
                    ${imgHtml}
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:700;font-size:13px;word-break:break-word;">${item.name || item.title || 'منتج'}</div>
                    <div style="font-size:11px;color:var(--text3);">
                        الكمية: ${item.qty || item.quantity || 1} × ${parseFloat(item.price || 0).toFixed(2)} د.ع
                    </div>
                </div>
                <div style="font-weight:800;font-size:14px;color:#0f3d1c;white-space:nowrap;">
                    ${(parseFloat(item.price || 0) * (item.qty || item.quantity || 1)).toFixed(2)} د.ع
                </div>
            </div>
            `;
        }).join('') : `
        <div style="text-align:center;padding:20px;color:var(--text3);">
            <i class="fa-regular fa-box-open" style="font-size:24px;display:block;margin-bottom:8px;"></i>
            لا توجد منتجات مسجلة في هذا الطلب
        </div>
        `}
        
        <div style="display:flex;justify-content:space-between;padding:12px 0 4px;border-top:2px solid #0f3d1c;margin-top:8px;">
            <span style="font-weight:700;font-size:15px;color:var(--text2);">المجموع الكلي</span>
            <span style="font-size:18px;font-weight:900;color:#0f3d1c;">${parseFloat(order.total || 0).toFixed(2)} د.ع</span>
        </div>
    </div>

    <div style="display:flex;flex-direction:column;gap:8px;padding-bottom:20px;">
     
        
        
        <button onclick="openOrderSupportChat('${order.orderId || order.id}')"
            style="width:100%;padding:14px;border:none;border-radius:12px;background:#0f3d1c;color:#fff;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;">
            <i class="fa-solid fa-headset"></i> التواصل مع الدعم
        </button>
        
        <button onclick="closeOrderDetail()"
            style="width:100%;padding:12px;border:2px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text2);font-weight:700;font-size:14px;cursor:pointer;">
            <i class="fa-solid fa-arrow-right"></i> العودة للقائمة
        </button>
    </div>
    `;
}

// ============================================================
// ✅ دالة إغلاق تفاصيل الطلب
// ============================================================
function closeOrderDetail() {
    const detailPage = document.getElementById('orderDetailPage');
    if (detailPage) {
        detailPage.classList.remove('open');
        document.body.style.overflow = '';
        updateBottomNavVisibility();
    }
}

// ============================================================
// ✅ دالة تحديث حالة الطلب من صفحة التفاصيل
// ============================================================
function updateOrderStatusFromDetail(orderId, status) {
    if (!confirm('هل أنت متأكد من تغيير حالة الطلب؟')) return;
    
    callAPI('updateOrderStatus', { id: orderId, status: status }, 'POST')
        .then(function(response) {
            if (response.success) {
                showToast('✅ تم تحديث حالة الطلب');
                loadAllData(true);
                setTimeout(function() {
                    openOrderDetail(orderId);
                }, 300);
            } else {
                showToast('❌ ' + (response.message || 'حدث خطأ'));
            }
        });
}

// ============================================================
// ✅ دالة عرض تفاصيل طلب الشحن للأدمن
// ============================================================
function openAdminRechargeDetail(rechargeId) {
    console.log('🔄 فتح تفاصيل طلب الشحن للأدمن:', rechargeId);
    
    const recharge = state.recharges.find(function(r) { return r.id === rechargeId; });
    if (!recharge) {
        showToast('⚠️ طلب الشحن غير موجود');
        return;
    }
    
    const user = state.users.find(function(u) { return u.id === recharge.userId; });
    
    const overlay = document.createElement('div');
    overlay.className = 'recharge-detail-overlay';
    overlay.style.cssText = `
        position: fixed;
        inset: 0;
        z-index: 400;
        background: rgba(0,0,0,0.5);
        backdrop-filter: blur(8px);
        display: flex;
        justify-content: center;
        align-items: center;
        animation: fadeInOverlay 0.3s var(--ease-out);
        padding: 20px;
        padding-top: var(--safe-area-top);
        padding-bottom: var(--safe-area-bottom);
    `;
    
    const statusMap = {
        pending: { text: '⏳ بانتظار المراجعة', color: 'var(--secondary)', bg: 'rgba(216,176,122,.15)' },
        approved: { text: '✅ تمت الموافقة', color: 'var(--green)', bg: 'rgba(46,204,113,.15)' },
        rejected: { text: '❌ مرفوض', color: 'var(--red)', bg: 'rgba(231,76,60,.15)' }
    };
    const status = statusMap[recharge.status] || statusMap.pending;
    
    const date = new Date(recharge.createdAt);
    const dateStr = date.toLocaleDateString('ar-EG') + ' ' + date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    
    let receiptHtml = '';
    if (recharge.receiptImage) {
        const baseUrl = window.location.origin + window.location.pathname.replace(/\/[^\/]*$/, '');
        const imgUrl = baseUrl + '/data/uploads/receipts/' + recharge.receiptImage;
        receiptHtml = `
            <div style="margin-top:12px;padding:12px;background:var(--bg2);border-radius:10px;border:1px solid var(--border);">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                    <i class="fa-solid fa-image" style="color:var(--primary);"></i>
                    <span style="font-weight:700;font-size:13px;">🖼️ صورة الإيصال</span>
                </div>
                <img src="${imgUrl}" onclick="event.stopPropagation();openLightbox('${imgUrl}')" 
                     style="width:100%;max-height:250px;border-radius:8px;cursor:pointer;object-fit:contain;border:1px solid var(--border);"
                     onerror="this.onerror=null; this.style.display='none'; this.parentElement.innerHTML += '<div style=\\'color:var(--red);padding:20px;text-align:center;background:var(--bg2);border-radius:8px;\\'><i class=\\'fa-solid fa-image\\' style=\\'font-size:48px;display:block;margin-bottom:8px;opacity:0.3;\\'></i>⚠️ تعذر تحميل الصورة<br><small style=\\'font-size:11px;color:var(--text3);\\'>يرجى المحاولة مرة أخرى</small></div>';">
            </div>
        `;
    } else {
        receiptHtml = `
            <div style="margin-top:12px;padding:12px;background:var(--bg2);border-radius:10px;border:1px solid var(--border);text-align:center;color:var(--text3);">
                <i class="fa-regular fa-image" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.3;"></i>
                لا توجد صورة إيصال مرفقة
            </div>
        `;
    }
    
    overlay.innerHTML = `
        <div class="recharge-detail-card" style="background:var(--surface);border-radius:16px;padding:24px;max-width:500px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:var(--shadow-lg);animation:slideUp 0.4s var(--ease-spring);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h3 style="font-size:18px;font-weight:800;color:var(--text2);display:flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-wallet" style="color:var(--primary);"></i>
                    تفاصيل طلب الشحن
                    <span style="font-size:12px;font-weight:400;color:var(--text3);">#${recharge.id}</span>
                </h3>
                <button onclick="closeRechargeDetail()" style="width:36px;height:36px;border:none;border-radius:50%;background:var(--bg2);color:var(--text3);font-size:18px;cursor:pointer;display:grid;place-items:center;transition:all 0.3s;">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:14px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="font-size:11px;color:var(--text3);font-weight:600;margin-bottom:6px;">👤 معلومات المستخدم</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;">
                    <div><span style="font-size:12px;color:var(--text3);">الاسم:</span> <span style="font-size:13px;font-weight:600;color:var(--text2);">${user ? user.name : 'غير معروف'}</span></div>
                    <div><span style="font-size:12px;color:var(--text3);">البريد:</span> <span style="font-size:13px;font-weight:600;color:var(--text2);">${user ? user.email : 'غير معروف'}</span></div>
                    <div><span style="font-size:12px;color:var(--text3);">الهاتف:</span> <span style="font-size:13px;font-weight:600;color:var(--text2);">${user ? user.phone : 'غير معروف'}</span></div>
                    <div><span style="font-size:12px;color:var(--text3);">الرصيد:</span> <span style="font-size:13px;font-weight:600;color:var(--text2);">${user ? (user.balance || 0).toFixed(2) : 'غير معروف'} د.ع</span></div>
                </div>
            </div>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div style="background:var(--bg2);border-radius:10px;padding:14px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">💰 المبلغ</div>
                    <div style="font-size:22px;font-weight:900;color:var(--primary);">${parseFloat(recharge.amount).toFixed(2)} د.ع</div>
                </div>
                <div style="background:var(--bg2);border-radius:10px;padding:14px;border:1px solid var(--border);">
                    <div style="font-size:11px;color:var(--text3);font-weight:600;">📌 الحالة</div>
                    <span style="padding:4px 12px;border-radius:12px;font-size:13px;font-weight:700;background:${status.bg};color:${status.color};display:inline-block;margin-top:2px;">
                        ${status.text}
                    </span>
                </div>
            </div>
            
            <div style="background:var(--bg2);border-radius:10px;padding:14px;margin-bottom:12px;border:1px solid var(--border);">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">📅 تاريخ الطلب</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text2);">${dateStr}</div>
                    </div>
                    <div>
                        <div style="font-size:11px;color:var(--text3);font-weight:600;">💳 طريقة الدفع</div>
                        <div style="font-size:14px;font-weight:600;color:var(--text2);">${recharge.paymentMethod || 'تحويل بنكي'}</div>
                    </div>
                </div>
            </div>
            
            ${receiptHtml}
            
            ${recharge.status === 'pending' ? `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:16px;">
                    <button onclick="event.stopPropagation();handleRechargeAction(${recharge.id}, 'approved')" 
                            style="padding:14px;border:none;border-radius:12px;background:var(--green);color:#fff;font-weight:700;font-size:14px;cursor:pointer;transition:all 0.3s;display:flex;align-items:center;justify-content:center;gap:8px;">
                        <i class="fa-solid fa-check"></i> موافقة
                    </button>
                    <button onclick="event.stopPropagation();handleRechargeAction(${recharge.id}, 'rejected')" 
                            style="padding:14px;border:none;border-radius:12px;background:var(--red);color:#fff;font-weight:700;font-size:14px;cursor:pointer;transition:all 0.3s;display:flex;align-items:center;justify-content:center;gap:8px;">
                        <i class="fa-solid fa-xmark"></i> رفض
                    </button>
                </div>
            ` : `
                <div style="margin-top:16px;padding:12px;background:var(--bg2);border-radius:10px;text-align:center;border:1px solid var(--border);">
                    <span style="font-size:13px;color:var(--text3);">
                        ${recharge.status === 'approved' ? '✅ تمت الموافقة على هذا الطلب' : '❌ تم رفض هذا الطلب'}
                    </span>
                </div>
            `}
            
            <button onclick="closeRechargeDetail()" style="width:100%;padding:12px;border:2px solid var(--border);border-radius:12px;background:var(--surface);color:var(--text2);font-weight:700;font-size:14px;cursor:pointer;margin-top:12px;transition:all 0.3s;">
                <i class="fa-solid fa-arrow-right"></i> إغلاق
            </button>
        </div>
    `;
    
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
}

// ============================================================
// ✅ دالة معالجة طلب الشحن (موافقة/رفض)
// ============================================================
function handleRechargeAction(id, action) {
    const actionText = action === 'approved' ? 'موافقة' : 'رفض';
    if (!confirm(`هل أنت متأكد من ${actionText} هذا الطلب؟`)) return;
    
    callAPI('updateRechargeStatus', { id: id, status: action }, 'POST')
        .then(function(response) {
            if (response.success) {
                showToast(`✅ تم ${actionText} طلب الشحن بنجاح`);
                closeRechargeDetail();
                loadAllData(true);
            } else {
                showToast('❌ ' + (response.message || 'حدث خطأ'));
            }
        });
}
// ============================================================
// POLICY FUNCTIONS
// ============================================================

function togglePolicy(type) {
    const content = $(type === 'privacy' ? 'privacyPolicyContent' : 'termsPolicyContent');
    if (content) {
        content.classList.toggle('open');
        if (content.classList.contains('open')) {
            content.style.maxHeight = content.scrollHeight + 'px';
        } else {
            content.style.maxHeight = '0';
        }
    }
}

function savePolicy(type) {
    const input = $(type === 'privacy' ? 'privacyPolicyInput' : 'termsInput');
    if (!input) return;
    const value = input.value.trim();
    callAPI('savePolicy', { type: type, content: value }, 'POST').then(function(response) {
        if (response.success) {
            showToast('✅ تم حفظ ' + (type === 'privacy' ? 'سياسة الخصوصية' : 'الشروط والأحكام'));
        } else {
            showToast('❌ ' + (response.message || 'فشل الحفظ'));
        }
    });
}

// ============================================================
// ACCOUNT FUNCTIONS
// ============================================================

function updateBalanceDisplay() {
    const el = $('userBalance');
    if (el) el.textContent = state.balance.toFixed(2) + ' د.ع';
}

function updateAccountUI() {
    const loginBtn = $('loginBtn');
    const registerBtn = $('registerBtn');
    const logoutWrapper = $('logoutWrapper');
    const adminBtn = $('adminBtn');
    const adminPanelMenuItem = $('adminPanelMenuItem');
    
    if (state.isLoggedIn && state.user) {
        if (loginBtn) loginBtn.textContent = '👤 ' + state.user.name;
        if (registerBtn) registerBtn.textContent = '🚪 تسجيل الخروج';
        if (loginBtn) loginBtn.classList.add('active');
        if (logoutWrapper) logoutWrapper.style.display = 'block';
        if (adminBtn) adminBtn.style.display = state.isAdmin ? 'flex' : 'none';
        if (adminPanelMenuItem) adminPanelMenuItem.style.display = state.isAdmin ? 'flex' : 'none';
    } else {
        if (loginBtn) loginBtn.textContent = '🔑 تسجيل الدخول';
        if (registerBtn) registerBtn.textContent = '📝 إنشاء حساب';
        if (loginBtn) loginBtn.classList.add('active');
        if (logoutWrapper) logoutWrapper.style.display = 'none';
        if (adminBtn) adminBtn.style.display = 'none';
        if (adminPanelMenuItem) adminPanelMenuItem.style.display = 'none';
    }
    
    updateLanguageDisplay();
}

function updateLanguageDisplay() {
    const display = $('currentLangDisplay');
    if (display) {
        display.textContent = currentLang === 'ar' ? 'العربية' : 'English';
    }
}

function openLoginPage() {
    openPage('loginPage');
    const errorEl = $('loginError');
    if (errorEl) errorEl.style.display = 'none';
    setTimeout(initGoogleLogin, 300);
}

function closeLoginPage() { closePage('loginPage'); }

function openRegisterPage() {
    openPage('registerPage');
    const errorEl = $('registerError');
    if (errorEl) errorEl.style.display = 'none';
    setTimeout(initGoogleLogin, 300);
}

function closeRegisterPage() { closePage('registerPage'); }

function handleLogin() {
    const identifier = $('loginIdentifier') ? $('loginIdentifier').value.trim() : '';
    const password = $('loginPassword') ? $('loginPassword').value.trim() : '';
    const errorEl = $('loginError');
    
    if (!identifier || !password) {
        if (errorEl) {
            errorEl.textContent = t('required_field');
            errorEl.style.display = 'block';
        }
        return;
    }
    
    const loginBtn = document.querySelector('#loginForm .btn-submit');
    if (loginBtn) {
        loginBtn.disabled = true;
        loginBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> ' + t('loading');
        loginBtn.style.opacity = '0.6';
    }
    
    callAPI('login', { identifier: identifier, password: password }, 'POST').then(function(response) {
        if (loginBtn) {
            loginBtn.disabled = false;
            loginBtn.innerHTML = t('login');
            loginBtn.style.opacity = '1';
        }
        
        if (response.success) {
            const user = response.data;
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = user.isVerified || false;
            
            try {
                localStorage.setItem('Tokmart_user', JSON.stringify({
                    id: user.id,
                    name: user.name,
                    email: user.email,
                    isAdmin: user.isAdmin,
                    isVerified: user.isVerified,
                    balance: user.balance,
                    avatar_path: user.avatar_path
                }));
            } catch (e) {}
            
            updateBalanceDisplay();
            updateAccountUI();
            closeLoginPage();
            renderAll();
            showToast('👋 مرحباً ' + user.name);
            if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
            requestNotificationPermission();
        } else {
            if (errorEl) {
                errorEl.textContent = response.message || 'بيانات الدخول غير صحيحة';
                errorEl.style.display = 'block';
            }
        }
    }).catch(function() {
        if (loginBtn) {
            loginBtn.disabled = false;
            loginBtn.innerHTML = t('login');
            loginBtn.style.opacity = '1';
        }
        if (errorEl) {
            errorEl.textContent = 'حدث خطأ في الاتصال بالخادم';
            errorEl.style.display = 'block';
        }
    });
}

function performLogout() {
    callAPI('logout', null, 'GET').then(function() {
        state.isLoggedIn = false;
        state.user = null;
        state.isAdmin = false;
        state.isVerified = false;
        state.balance = 0;
        
        try {
            localStorage.removeItem('Tokmart_user');
        } catch (e) {}
        
        updateBalanceDisplay();
        updateAccountUI();
        closeLogoutSheet();
        showToast('✅ تم تسجيل الخروج');
        renderAll();
        clearAllIntervals();
        switchPage('page-home');
    });
}

function saveAccountSettings() {
    if (!state.isLoggedIn || !state.user) { showToast('يرجى تسجيل الدخول'); return; }
    const formData = new FormData();
    formData.append('id', state.user.id);
    const nameEl = $('settingsUsername');
    const phoneEl = $('settingsPhone');
    const emailEl = $('settingsEmail');
    const currentPassEl = $('settingsCurrentPassword');
    const newPassEl = $('settingsNewPassword');
    const avatarEl = $('settingsAvatar');
    
    if (nameEl) formData.append('name', nameEl.value.trim());
    if (phoneEl) formData.append('phone', phoneEl.value.trim());
    if (emailEl) formData.append('email', emailEl.value.trim());
    if (currentPassEl) formData.append('currentPassword', currentPassEl.value);
    if (newPassEl) formData.append('newPassword', newPassEl.value);
    if (avatarEl && avatarEl.files[0]) formData.append('avatar', avatarEl.files[0]);
    
    callAPI('updateUser', formData, 'POST').then(function(response) {
        if (response.success) {
            state.user = response.data;
            state.balance = state.user.balance || 0;
            updateBalanceDisplay();
            updateAccountUI();
            showToast('✅ تم حفظ التغييرات');
            closePage('page-account-settings');
        } else {
            showToast(response.message || 'حدث خطأ');
        }
    });
}

// ============================================================
// CART AND PRODUCTS
// ============================================================

function addToCart(product) {
    const existing = state.cart.find(function(item) { return item.id === product.id; });
    if (existing) { existing.qty = (existing.qty || 1) + 1; } 
    else { state.cart.push({ ...product, qty: 1 }); }
    renderCart();
    updateCartBadge();
    showToast('✅ تمت الإضافة للسلة');
    if (navigator.vibrate) navigator.vibrate(10);
}

function removeFromCart(index) {
    const item = state.cart[index];
    if (item && item.qty > 1) { item.qty--; } 
    else { state.cart.splice(index, 1); }
    renderCart();
    updateCartBadge();
    if (navigator.vibrate) navigator.vibrate(5);
}

function getCartTotal() {
    return state.cart.reduce(function(sum, item) { return sum + (item.price || 0) * (item.qty || 1); }, 0);
}

function renderCart() {
    const container = $('cartContent');
    
    if (state.cart.length === 0) {
        if (container) {
            container.innerHTML = `
                <div class="cart-empty" style="text-align:center;padding:60px 20px;color:var(--text3);">
                    <i class="fa-regular fa-face-frown" style="font-size:72px;margin-bottom:16px;display:block;color:var(--primary);opacity:.3;"></i>
                    <h3 style="font-size:20px;font-weight:700;color:var(--text2);margin-bottom:6px;">${t('cart_empty')}</h3>
                    <p style="font-size:14px;color:var(--text3);margin-bottom:20px;">أضف منتجاتك الآن وابدأ التسوق</p>
                    <button class="cta-btn" onclick="switchPage('page-home')" style="background:var(--primary-light);border:none;color:#fff;padding:14px 40px;border-radius:14px;font-size:16px;font-weight:700;cursor:pointer;transition:all 0.3s;">
                        <i class="fa-solid fa-bag-shopping"></i> ابدأ التسوق
                    </button>
                </div>
            `;
        }
        const section = $('checkoutSection');
        if (section) section.style.display = 'none';
        updateCartBadge();
        return;
    }
    
    let html = '', total = 0;
    state.cart.forEach(function(item, index) {
        const price = item.price || 0;
        const qty = item.qty || 1;
        total += price * qty;
        const imgSrc = item.image_url || '';
        const imgHtml = imgSrc ? '<img src="' + imgSrc + '" loading="lazy" style="width:100%;height:100%;object-fit:cover;">' : '<i class="' + (item.icon || 'fa-solid fa-box') + '"></i>';
        html += `
            <div class="cart-item" style="display:flex;align-items:center;gap:12px;padding:14px;background:var(--surface);border:1px solid var(--border);border-radius:12px;margin-bottom:10px;">
                <div class="cart-img" style="width:50px;height:50px;border-radius:10px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;font-size:20px;color:var(--primary);flex-shrink:0;">${imgHtml}</div>
                <div class="info" style="flex:1;min-width:0;">
                    <h4 style="font-size:14px;font-weight:700;word-break:break-word;">${item.name}</h4>
                    <p style="font-size:12px;color:var(--text3);">الكمية: ${qty}</p>
                </div>
                <div class="price" style="font-weight:800;color:var(--primary);font-size:15px;white-space:nowrap;">${price.toFixed(2)} د.ع</div>
                <button class="remove-btn" onclick="removeFromCart(${index})" style="background:none;border:none;color:var(--red);cursor:pointer;font-size:18px;padding:8px;border-radius:10px;">
                    <i class="fa-regular fa-trash-can"></i>
                </button>
            </div>
        `;
    });
    html += `
        <div style="display:flex;justify-content:space-between;align-items:center;padding:16px 4px;border-top:1px solid var(--border);margin-top:8px;">
            <span style="font-weight:700;font-size:16px;color:var(--text2);">${t('total')}: <span style="color:var(--primary);font-size:18px;">${total.toFixed(2)} د.ع</span></span>
            <button class="icon-btn" onclick="clearCart()" style="color:var(--red);border-color:rgba(231,76,60,.3);width:42px;height:42px;border:1px solid var(--border);border-radius:12px;background:var(--surface);font-size:16px;cursor:pointer;display:grid;place-items:center;">
                <i class="fa-regular fa-trash-can"></i>
            </button>
        </div>
    `;
    if (container) container.innerHTML = html;
    updateCartBadge();
    renderCheckout();
}

function clearCart() {
    if (confirm('هل تريد تفريغ السلة؟')) {
        state.cart = [];
        renderCart();
        updateCartBadge();
        showToast('🗑️ تم تفريغ السلة');
    }
}

function updateCartBadge() {
    const badge = $('cartBadge');
    const count = state.cart.reduce(function(sum, item) { return sum + (item.qty || 1); }, 0);
    if (badge) {
        badge.style.display = count > 0 ? 'flex' : 'none';
        badge.textContent = count;
    }
}

// ============================================================
// ADMIN FUNCTIONS
// ============================================================

function openAdminPage() {
    if (!state.isAdmin) {
        showToast('⚠️ غير مصرح لك بالدخول');
        return;
    }
    
    document.querySelectorAll('.settings-page.open, .product-detail-page.open, .auth-page.open, .chat-app-user.open, .favorites-page.open, .orders-page.open, .order-detail-page.open, .recharge-page.open')
        .forEach(function(page) {
            page.classList.remove('open');
        });
    
    const page = $('adminPage');
    if (page) {
        page.classList.add('open');
        document.body.style.overflow = 'hidden';
        state.isAdminChatOpen = true;
        updateBottomNavVisibility();
        loadAdminUsers();
        renderAdminRecharges();
    }
}

function closeAdminPage() {
    const page = $('adminPage');
    if (page) {
        page.classList.remove('open');
        document.body.style.overflow = '';
        state.isAdminChatOpen = false;
        updateBottomNavVisibility();
        if (state.chatIntervals.admin) {
            clearInterval(state.chatIntervals.admin);
            state.chatIntervals.admin = null;
        }
        state.adminCurrentUserId = null;
    }
}

function renderAdminRecharges() {
    const container = $('adminRechargesList');
    if (!container) return;
    
    const recharges = state.recharges || [];
    if (recharges.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:30px;color:var(--text3);">
                <i class="fa-regular fa-clock" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.3;"></i>
                لا توجد طلبات شحن
            </div>
        `;
        return;
    }
    
    container.innerHTML = recharges.map(function(r) {
        const user = state.users.find(function(u) { return u.id === r.userId; });
        const statusText = { pending: '⏳ بانتظار المراجعة', approved: '✅ مقبول', rejected: '❌ مرفوض' }[r.status] || r.status;
        const date = new Date(r.createdAt);
        const dateStr = date.toLocaleDateString('ar-EG') + ' ' + date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
        
        return `
            <div class="admin-list-item" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:10px;cursor:pointer;" onclick="openAdminRechargeDetail(${r.id})">
                <div style="flex:1;min-width:150px;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="font-weight:700;font-size:14px;color:var(--text2);">#${r.id}</span>
                        <span style="font-size:13px;color:var(--text2);">👤 ${user ? user.name : 'غير معروف'}</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:12px;margin-top:4px;flex-wrap:wrap;">
                        <span style="font-size:16px;font-weight:900;color:var(--primary);">${parseFloat(r.amount).toFixed(2)} د.ع</span>
                        <span style="font-size:12px;color:var(--text3);">${r.paymentMethod || 'تحويل بنكي'}</span>
                        <span style="font-size:11px;color:var(--text3);">📅 ${dateStr}</span>
                    </div>
                    ${r.receiptImage ? `
                        <div style="font-size:11px;color:var(--primary);margin-top:4px;">
                            <i class="fa-regular fa-image"></i> 🖼️ يوجد إيصال
                        </div>
                    ` : ''}
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span style="padding:4px 12px;border-radius:12px;font-size:12px;font-weight:700;background:${r.status === 'pending' ? 'rgba(216,176,122,.15)' : r.status === 'approved' ? 'rgba(46,204,113,.15)' : 'rgba(231,76,60,.15)'};color:${r.status === 'pending' ? 'var(--secondary)' : r.status === 'approved' ? 'var(--green)' : 'var(--red)'};">
                        ${statusText}
                    </span>
                    <button onclick="event.stopPropagation();openAdminRechargeDetail(${r.id})" style="padding:6px 14px;border:none;border-radius:8px;background:var(--blue);color:#fff;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:4px;">
                        <i class="fa-regular fa-eye"></i> عرض
                    </button>
                    ${r.status === 'pending' ? `
                        <button class="btn-approve" onclick="event.stopPropagation();updateRechargeStatus(${r.id}, 'approved')" style="padding:6px 14px;border:none;border-radius:8px;background:var(--green);color:#fff;font-size:11px;font-weight:700;cursor:pointer;">
                            ✅ موافقة
                        </button>
                        <button class="btn-reject" onclick="event.stopPropagation();updateRechargeStatus(${r.id}, 'rejected')" style="padding:6px 14px;border:none;border-radius:8px;background:var(--red);color:#fff;font-size:11px;font-weight:700;cursor:pointer;">
                            ❌ رفض
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
}

function updateRechargeStatus(id, status) {
    if (!confirm('هل أنت متأكد من تحديث حالة طلب الشحن؟')) return;
    callAPI('updateRechargeStatus', { id: id, status: status }, 'POST').then(function(response) {
        if (response.success) {
            showToast('✅ تم تحديث حالة طلب الشحن');
            loadAllData(true);
        } else {
            showToast('❌ ' + (response.message || 'حدث خطأ'));
        }
    });
}

// ============================================================
// LOAD DATA AND RENDER
// ============================================================

const CACHE_DURATION = 5 * 60 * 1000;
const CACHE_KEY = 'Tokmart_ultra_cache';

function getCachedData() {
    try {
        const cached = localStorage.getItem(CACHE_KEY);
        if (!cached) return null;
        const data = JSON.parse(cached);
        if (Date.now() - data._timestamp < CACHE_DURATION) {
            return data;
        }
        return null;
    } catch (e) { return null; }
}

function setCachedData(data) {
    try {
        data._timestamp = Date.now();
        localStorage.setItem(CACHE_KEY, JSON.stringify(data));
    } catch (e) {}
}

function loadAllData(forceRefresh) {
    forceRefresh = forceRefresh || false;
    
    // ===== استعادة حالة المستخدم =====
    try {
        const savedUser = localStorage.getItem('Tokmart_user');
        if (savedUser && !state.isLoggedIn) {
            const user = JSON.parse(savedUser);
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = user.isVerified || false;
            updateBalanceDisplay();
            updateAccountUI();
        }
    } catch (e) {}
    
    // ===== عرض البيانات المخزنة فوراً (حتى للمستخدم غير المسجل) =====
    const cached = getCachedData();
    if (cached && !forceRefresh) {
        console.log('📦 عرض البيانات المخزنة فوراً');
        applyData(cached, true);
        setTimeout(function() {
            refreshDataInBackgroundSilent();
        }, 300);
        return;
    }
    
    // ===== تحميل البيانات من السيرفر =====
    console.log('🔄 تحميل البيانات من السيرفر...');
    callAPI('getData', null, 'GET').then(function(response) {
        if (response.success) {
            setCachedData(response.data);
            applyData(response.data, true);
        } else {
            const cached2 = getCachedData();
            if (cached2) {
                applyData(cached2, true);
            }
        }
    });
}
function refreshDataInBackgroundSilent() {
    setTimeout(function() {
        callAPI('getData', null, 'GET').then(function(response) {
            if (response.success) {
                setCachedData(response.data);
                applyData(response.data, true);
            }
        });
    }, 500);
}

function applyData(data, silent) {
    silent = silent || false;
    
    // ... الكود الموجود ...
    
    // ✅ معالجة الطلبات - تحويل items إلى مصفوفة
    if (data.orders) {
        state.orders = data.orders.map(function(order) {
            // ✅ تحويل items إلى مصفوفة إذا كانت نصية
            if (order.items && typeof order.items === 'string') {
                try {
                    const parsed = JSON.parse(order.items);
                    order.items = Array.isArray(parsed) ? parsed : [parsed];
                } catch (e) {
                    order.items = [];
                }
            }
            if (!Array.isArray(order.items)) {
                order.items = [];
            }
            return order;
        });
    }
    

    
    // ===== تحديث جميع البيانات =====
    state.products = data.products || [];
    state.categories = data.categories || [];
    state.orders = data.orders || [];
    if (data.payments) state.payments = data.payments;
    state.notifications = data.notifications || [];
    state.chats = data.chats || [];
    state.users = data.users || [];
    state.recharges = data.recharges || [];
    state.flashDeals = data.flashDeals || [];
    state.bestSellers = data.bestSellers || [];
    state.newArrivals = data.newArrivals || [];
    state.featured = data.featured || [];

    // ===== تحديث حالة المستخدم إذا كانت موجودة =====
    if (data.user) {
        state.user = data.user;
        state.isLoggedIn = true;
        state.balance = data.user.balance || 0;
        state.isAdmin = data.user.isAdmin || false;
        state.isVerified = data.user.isVerified || false;
        try {
            localStorage.setItem('Tokmart_user', JSON.stringify({
                id: data.user.id,
                name: data.user.name,
                email: data.user.email,
                isAdmin: data.user.isAdmin,
                isVerified: data.user.isVerified,
                balance: data.user.balance,
                avatar_path: data.user.avatar_path
            }));
        } catch (e) {}
    } else if (state.isLoggedIn && state.user) {
        const updated = state.users.find(function(u) { return u.id === state.user.id; });
        if (updated) {
            state.user = updated;
            state.balance = updated.balance || 0;
            state.isAdmin = updated.isAdmin || false;
            state.isVerified = updated.isVerified || false;
        }
    }

    // ===== عرض جميع البيانات (حتى بدون تسجيل دخول) =====
    renderAll();
    
    if (!silent) {
        showToast('✅ تم تحديث البيانات');
    }
}

function renderAll() {
    renderProducts();
    renderCategories();
    renderCategoryBanners();
    renderCart();
    renderFavorites();
    renderOrders();
    renderNotifications();
    renderAdmin();
    renderAdminRecharges();
    loadAdminChats();
    updateBalanceDisplay();
    updateAdminStats();
    updateNotifBadge();
    updateAccountUI();
}
// ============================================================
// PRODUCTS AND CATEGORIES
// ============================================================

function renderProducts() {}

function createProductCard(product) {
    const isFav = state.favorites.some(function(f) { return f.id === product.id; });
    const imgSrc = product.image_url || '';
    const imgHtml = imgSrc ? '<img src="' + imgSrc + '" loading="lazy" alt="' + product.name + '">' :
        '<i class="' + (product.icon || 'fa-solid fa-box') + '"></i>';
    const discountHtml = product.discount ?
        '<div class="discount-chip"><i class="fa-solid fa-tag"></i><span class="percent">' + product.discount + '</span></div>' : '';
    const priceDisplay = parseFloat(product.price).toFixed(2) + ' د.ع';
    const oldPriceDisplay = product.oldPrice ? parseFloat(product.oldPrice).toFixed(2) + ' د.ع' : '';

    return `
        <div class="product-card" data-id="${product.id}" onclick="openProductDetail(${product.id})">
            ${discountHtml}
            ${product.brand ? `<div class="brand-tag">${product.brand}</div>` : ''}
            <div class="product-image">${imgHtml}</div>
            <h4>${product.name}</h4>
            <div class="product-desc">${product.desc || ''}</div>
            <div class="price-row">
                ${oldPriceDisplay ? `<span class="old-price">${oldPriceDisplay}</span>` : ''}
                <span class="${oldPriceDisplay ? 'new-price' : 'normal-price'}">${priceDisplay}</span>
            </div>
            <button class="add-btn" data-id="${product.id}" onclick="event.stopPropagation();addToCartById(${product.id})">
                <i class="fa-solid fa-cart-plus"></i> أضف
            </button>
            <div class="favorite-icon ${isFav ? 'active' : ''}" data-id="${product.id}" onclick="event.stopPropagation();toggleFavorite(${product.id})">
                <i class="fa-solid fa-heart"></i>
            </div>
        </div>
    `;
}

function addToCartById(productId) {
    const product = state.products.find(function(p) { return p.id === productId; });
    if (product) addToCart(product);
}

function splitIntoRows(products) {
    const mid = Math.ceil(products.length / 2);
    return { row1: products.slice(0, mid), row2: products.slice(mid) };
}

function renderCategoryBanners() {
    const container = $('categoryBannersContainer');
    const categoriesWithBanner = state.categories.filter(function(c) { return c.banner_image_url; });
    
    if (!categoriesWithBanner.length) {
        container.innerHTML = `
            <div style="text-align:center;padding:30px;color:var(--text3);">
                <i class="fa-regular fa-folder-open" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                <div style="font-size:14px;font-weight:600;">لا توجد تصنيفات مع بانر</div>
                <div style="font-size:12px;color:var(--text3);margin-top:4px;">أضف صور بانر للتصنيفات من لوحة التحكم</div>
            </div>
        `;
        return;
    }

    container.innerHTML = categoriesWithBanner.map(function(c) {
        const products = state.products.filter(function(p) { return p.category === c.id; });
        const mid = Math.ceil(products.length / 2);
        const row1 = products.slice(0, mid);
        const row2 = products.slice(mid);
        
        const productHtml = products.length ? `
            <div class="category-products" style="padding:8px 0 10px;">
                <div class="section-header" style="display:flex;align-items:center;justify-content:space-between;margin:6px 10px 4px;padding:0 4px;">
                    <div class="title" style="display:flex;align-items:center;gap:8px;font-size:14px;font-weight:800;color:var(--text2);">
                        <span class="icon-bg" style="width:28px;height:28px;border-radius:50%;background:var(--acSh);display:grid;place-items:center;font-size:13px;color:var(--primary);">
                            <i class="${c.icon || 'fa-solid fa-box'}"></i>
                        </span>
                        <span>منتجات ${c.name}</span>
                    </div>
                    <span class="view-all" onclick="event.stopPropagation();openCategoryProducts('${c.id}')" style="font-size:11px;font-weight:700;color:var(--text3);cursor:pointer;transition:all 0.3s var(--ease-spring);display:flex;align-items:center;gap:3px;touch-action:manipulation;">
                        عرض الكل <i class="fa-solid fa-chevron-left"></i>
                    </span>
                </div>
                <div class="products-two-rows" style="display:flex;flex-direction:column;gap:8px;padding:4px 10px 6px 10px;">
                    <div class="products-row" style="display:flex;flex-wrap:nowrap;gap:10px;overflow-x:auto;padding:4px 4px 8px 4px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scroll-behavior:smooth;-webkit-user-select:none;user-select:none;cursor:grab;">
                        ${row1.map(function(p) { return createProductCard(p); }).join('')}
                    </div>
                    <div class="products-row" style="display:flex;flex-wrap:nowrap;gap:10px;overflow-x:auto;padding:4px 4px 8px 4px;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scroll-behavior:smooth;-webkit-user-select:none;user-select:none;cursor:grab;">
                        ${row2.map(function(p) { return createProductCard(p); }).join('')}
                    </div>
                </div>
            </div>
        ` : `
            <div style="padding:12px 16px;color:var(--text3);font-size:13px;text-align:center;">
                <i class="fa-regular fa-box-open" style="font-size:20px;display:block;margin-bottom:4px;opacity:0.3;"></i>
                لا توجد منتجات في هذا التصنيف
            </div>
        `;

        return `
            <div class="category-banner-section">
                <div class="category-banner" onclick="openCategoryProducts('${c.id}')">
                    <img src="${c.banner_image_url}" loading="lazy" alt="${c.name}">
                    <div class="banner-overlay">
                        <h3><i class="${c.icon || 'fa-solid fa-tag'}"></i> ${c.name}</h3>
                        <p>استكشف منتجات ${c.name}</p>
                    </div>
                </div>
                ${productHtml}
            </div>
        `;
    }).join('');
    setTimeout(initDragScroll, 100);
}

function renderCategories() {
    const grid = $('categoriesGrid');
    if (!state.categories.length) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;color:var(--text3);">لا توجد تصنيفات</div>';
        return;
    }
    
    grid.innerHTML = state.categories.map(function(c) {
        const productCount = state.products.filter(function(p) { return p.category === c.id; }).length;
        return `
            <div class="category-grid-item" onclick="openCategoryProducts('${c.id}')">
                <div class="cat-img">
                    ${c.icon_image_url ? 
                        '<img src="' + c.icon_image_url + '" loading="lazy" alt="' + c.name + '">' : 
                        '<i class="' + c.icon + '"></i>'
                    }
                </div>
                <span>${c.name}</span>
                <div class="product-count">${productCount} منتج</div>
            </div>
        `;
    }).join('');
}

function openCategoryProducts(categoryId) {
    const products = state.products.filter(function(p) { return p.category === categoryId; });
    
    const detailPage = $('productDetailPage');
    if (detailPage) detailPage.classList.remove('open');
    
    openPage('categoryProductsPage');
    const cat = state.categories.find(function(c) { return c.id === categoryId; });
    const titleEl = $('catProductsTitle');
    if (titleEl) titleEl.textContent = ' ' + (cat ? cat.name : 'المنتجات');
    const grid = $('categoryProductsGrid');
    if (grid) {
        if (products.length) {
            grid.innerHTML = products.map(function(p) { return createProductCard(p); }).join('');
        } else {
            grid.innerHTML = `
                <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text3);">
                    <i class="fa-regular fa-box-open" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                    <div style="font-size:16px;font-weight:600;">لا توجد منتجات في هذا التصنيف</div>
                    <div style="font-size:13px;margin-top:4px;">سيتم إضافة منتجات قريباً</div>
                </div>
            `;
        }
    }
}

function openProductDetail(productId) {
    const product = state.products.find(function(p) { return p.id === productId; });
    if (!product) { showToast('المنتج غير موجود'); return; }

    const openPages = document.querySelectorAll('.settings-page.open, .product-detail-page.open, .all-products-page.open, .category-products-page.open, .favorites-page.open, .orders-page.open, .order-detail-page.open, .recharge-page.open, .auth-page.open, .chat-app-user.open');
    openPages.forEach(function(page) {
        if (page.id !== 'productDetailPage') {
            page.classList.remove('open');
        }
    });

    const detailPage = $('productDetailPage');
    if (detailPage) {
        detailPage.classList.add('open');
        document.body.style.overflow = 'hidden';
        updateBottomNavVisibility();
    }

    state.detailQty = 1;
    const titleEl = $('detailTitle');
    if (titleEl) titleEl.textContent = product.name;

    const isFav = state.favorites.some(function(f) { return f.id === product.id; });
    const stars = '★'.repeat(Math.floor(product.rating || 0)) + '☆'.repeat(5 - Math.floor(product.rating || 0));
    const imgSrc = product.image_url || '';
    const body = $('detailBody');

   if (body) {
        body.innerHTML = `
            <div class="detail-image">${imgSrc ? '<img src="' + imgSrc + '" style="width:100%;max-width:100%;height:350px;object-fit:contain;display:block;margin:0 auto;border-radius:14px;border:2px solid var(--border);padding:6px;background:var(--surface);">' : '<i class="fa-solid fa-box" style="font-size:60px;color:var(--primary);"></i>'}</div>
            <div class="detail-name" style="font-size:1.25rem;font-weight:700;margin:12px 0 6px;color:var(--text);">${product.name}</div>
            <div class="detail-desc" style="color:var(--text2);font-size:0.9rem;line-height:1.5;margin-bottom:12px;">${product.desc || ''}</div>
            ${product.discount ? `<div style="display:inline-flex;align-items:center;gap:6px;background:var(--discount-color);color:#fff;padding:4px 14px 4px 10px;border-radius:30px;font-weight:700;font-size:.8rem;box-shadow:0 4px 12px rgba(255,155,61,.4);margin-bottom:10px;"><i class="fa-solid fa-tag"></i>${product.discount} خصم</div>` : ''}
            <div class="detail-price-row" style="margin-bottom:12px;">
                  ${product.oldPrice ? '<span class="detail-old-price" style="text-decoration:line-through;color:var(--text3);margin-left:8px;font-size:0.95rem;">$' + parseFloat(product.oldPrice).toFixed(2) + '</span>' : ''}
                  <span class="${product.oldPrice ? 'detail-new-price' : 'detail-normal-price'}" style="font-size:1.2rem;font-weight:800;color:var(--primary);">د.ع${parseFloat(product.price).toFixed(2)}</span>
            </div>
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:10px 14px;margin:8px 0 14px;display:flex;align-items:center;gap:10px;">
                <i class="fa-solid fa-truck-fast" style="color:var(--green);font-size:16px;"></i>
                <div style="font-size:13px;color:var(--text2);">🚚 <b>توصيل سريع:</b> ${product.deliveryTime || 'خلال 24 ساعة'}</div>
            </div>
            <div class="detail-qty" style="display:flex;align-items:center;justify-content:space-between;background:var(--surface);border:1px solid var(--border);padding:10px 14px;border-radius:12px;margin-bottom:14px;">
                <label style="font-size:13px;font-weight:700;color:var(--text2);">الكمية المطلوبة:</label>
                <div class="qty-control" style="display:flex;align-items:center;gap:10px;">
                    <button onclick="updateDetailQty(-1)" style="width:34px;height:34px;border-radius:50%;border:2px solid var(--border);background:var(--background);font-size:18px;cursor:pointer;display:grid;place-items:center;font-weight:bold;color:var(--text);">−</button>
                    <span class="qty-value" id="detailQtyValue" style="font-size:16px;font-weight:700;min-width:28px;text-align:center;">1</span>
                    <button onclick="updateDetailQty(1)" style="width:34px;height:34px;border-radius:50%;border:2px solid var(--border);background:var(--background);font-size:18px;cursor:pointer;display:grid;place-items:center;font-weight:bold;color:var(--text);">+</button>
                </div>
          </div>
          <div class="detail-actions" style="display:flex;gap:10px;margin-bottom:12px;">
              <button class="btn-add-cart" onclick="addDetailToCart(${product.id})" style="flex:1;background:var(--primary);color:#fff;border:none;padding:12px;border-radius:12px;font-weight:700;font-size:14px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 12px rgba(0,0,0,0.1);"><i class="fa-solid fa-cart-plus"></i> أضف للسلة</button>
              <button class="btn-fav-detail ${isFav ? 'active' : ''}" data-id="${product.id}" onclick="toggleFavorite(${product.id})" style="width:48px;height:48px;border-radius:12px;border:2px solid var(--border);background:var(--surface);cursor:pointer;display:grid;place-items:center;font-size:18px;color:${isFav ? '#e74c3c' : 'var(--text2)'};"><i class="fa-solid fa-heart"></i></button>
          </div>
          
            <!-- قسم عرض التقييمات وزر فتح النافذة المنبثقة -->
            <div class="detail-rating" style="background:var(--surface);border:1px solid var(--border);padding:10px 12px;border-radius:10px;display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <div class="stars" style="color:#f39c12;font-size:13px;">${stars}</div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <div class="text" style="font-size:12px;font-weight:700;color:var(--text2);">${product.rating || 0} / 5 (${product.ratingCount || 0})</div>
                    <button onclick="toggleReviewModal(true)" style="background:var(--background);border:1px solid var(--primary);color:var(--primary);padding:5px 10px;border-radius:6px;font-weight:700;font-size:11px;cursor:pointer;display:flex;align-items:center;gap:4px;">
                        <i class="fa-solid fa-pen" style="font-size:10px;"></i> تقييم
                    </button>
                </div>
          </div>

            <!-- بطاقة منبثقة في منتصف الشاشة (Modal Overlay) -->
            <div id="reviewModalOverlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;padding:20px;">
                <div style="background:var(--surface);border:1px solid var(--border);width:100%;max-width:320px;padding:20px;border-radius:16px;box-shadow:0 10px 25px rgba(0,0,0,0.2);text-align:center;position:relative;">
                    
                    <!-- محتوى النموذج قبل الإرسال -->
                    <div id="reviewFormContent">
                        <div style="font-size:15px;font-weight:700;color:var(--text);margin-bottom:12px;">تقييم المنتج</div>
                        
                        <!-- نجوم تفاعلية -->
                        <div id="interactive-star-picker" style="display:flex;gap:8px;font-size:24px;color:#f39c12;cursor:pointer;margin-bottom:14px;justify-content:center;">
                            <i class="fa-solid fa-star" onclick="setRatingStar(1)" data-value="1"></i>
                            <i class="fa-solid fa-star" onclick="setRatingStar(2)" data-value="2"></i>
                            <i class="fa-solid fa-star" onclick="setRatingStar(3)" data-value="3"></i>
                            <i class="fa-solid fa-star" onclick="setRatingStar(4)" data-value="4"></i>
                            <i class="fa-solid fa-star" onclick="setRatingStar(5)" data-value="5"></i>
                        </div>
                        <input type="hidden" id="selectedRatingValue" value="5">
                        
                        <textarea id="userReviewText" placeholder="اكتب تعليقك باختصار..." style="width:100%;background:var(--background);border:1px solid var(--border);border-radius:8px;padding:10px;font-size:12px;color:var(--text);resize:none;height:70px;margin-bottom:12px;"></textarea>
                        
                        <div style="display:flex;gap:8px;">
                            <button onclick="submitProductReview(${product.id})" style="flex:1;background:var(--primary);color:#fff;border:none;padding:9px;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;">إرسال</button>
                            <button onclick="toggleReviewModal(false)" style="background:var(--background);border:1px solid var(--border);color:var(--text2);padding:9px 14px;border-radius:8px;font-weight:700;font-size:12px;cursor:pointer;">إلغاء</button>
                        </div>
                    </div>

                    <!-- رسالة النجاح (تظهر بعد الإرسال) -->
                    <div id="reviewSuccessContent" style="display:none;padding:20px 0;">
                        <div style="width:50px;height:50px;background:#2ecc71;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px;margin:0 auto 12px;box-shadow:0 4px 12px rgba(46,204,113,0.3);">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <div style="font-size:16px;font-weight:700;color:var(--text);">شكراً على تقيمك!</div>
                    </div>

                </div>
            </div>

          <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border);">
              <button onclick="closeProductDetail()" style="width:100%;padding:10px;border:2px solid var(--border);border-radius:10px;background:var(--surface);color:var(--text2);font-weight:700;cursor:pointer;font-size:12px;transition:all 0.3s;display:flex;align-items:center;justify-content:center;gap:6px;">
                  <i class="fa-solid fa-arrow-right"></i> العودة
              </button>
          </div>
        `;
    }
}
function updateDetailQty(delta) {
    state.detailQty = Math.max(1, state.detailQty + delta);
    const el = $('detailQtyValue');
    if (el) el.textContent = state.detailQty;
    if (navigator.vibrate) navigator.vibrate(5);
}

function addDetailToCart(productId) {
    const product = state.products.find(function(p) { return p.id === productId; });
    if (!product) return;
    for (let i = 0; i < state.detailQty; i++) {
        addToCart(product);
    }
    closePage('productDetailPage');
}

function closeProductDetail() { 
    const detailPage = $('productDetailPage');
    if (detailPage) {
        detailPage.classList.remove('open');
        document.body.style.overflow = '';
        updateBottomNavVisibility();
    }
}

// ============================================================
// FAVORITES, ORDERS
// ============================================================

function openFavorites() {
    if (!requireLogin()) return;
    
    const detailPage = $('productDetailPage');
    if (detailPage) detailPage.classList.remove('open');
    
    openPage('favoritesPage');
    renderFavorites();
}

function renderFavorites() {
    const grid = $('favoritesGrid');
    if (!grid) return;
    
    if (!state.isLoggedIn) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text3);">
                <i class="fa-regular fa-heart" style="font-size:56px;display:block;margin-bottom:16px;color:var(--pink);opacity:0.4;"></i>
                <div style="font-size:18px;font-weight:700;color:var(--text2);">${t('login_to_view')} ${t('favorites')}</div>
                <div style="font-size:13px;margin-top:4px;">${t('favorites_empty')}</div>
                <button onclick="openLoginPage()" style="padding:12px 40px;border:none;border-radius:12px;background:var(--primary-light);color:#fff;font-size:14px;font-weight:700;cursor:pointer;margin-top:16px;transition:all 0.3s;">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> ${t('login')}
                </button>
            </div>
        `;
        return;
    }
    
    if (state.favorites.length === 0) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:80px 20px;color:var(--text3);">
                <i class="fa-regular fa-heart" style="font-size:56px;display:block;margin-bottom:16px;color:var(--pink);opacity:0.2;"></i>
                <div style="font-size:18px;font-weight:700;color:var(--text2);">${t('no_favorites')}</div>
                <div style="font-size:13px;margin-top:4px;">${t('favorites_empty')}</div>
                
                </button>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = state.favorites.map(function(p) { 
        return createProductCard(p); 
    }).join('');
}

function toggleFavorite(productId) {
    if (!state.isLoggedIn) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return;
    }
    const product = state.products.find(function(p) { return p.id === productId; });
    if (!product) return;
    const index = state.favorites.findIndex(function(f) { return f.id === productId; });
    if (index > -1) {
        state.favorites.splice(index, 1);
        showToast('تمت الإزالة من المفضلة');
    } else {
        state.favorites.push(product);
        showToast('❤️ تمت الإضافة للمفضلة');
        if (navigator.vibrate) navigator.vibrate(10);
    }
    localStorage.setItem('favorites', JSON.stringify(state.favorites));
    renderFavorites();
}

function loadFavorites() {
    try {
        const saved = localStorage.getItem('favorites');
        if (saved) state.favorites = JSON.parse(saved);
    } catch (e) { state.favorites = []; }
}

function openOrders() {
    if (!requireLogin()) return;
    
    const detailPage = $('orderDetailPage');
    if (detailPage) detailPage.classList.remove('open');
    
    openPage('ordersPage');
    renderOrders();
}

function renderOrders() {
    const container = $('ordersList');
    if (!container) return;
    
    if (!state.isLoggedIn) {
        container.innerHTML = `
            <div style="text-align:center;padding:60px 20px;color:var(--text3);">
                <i class="fa-regular fa-circle-user" style="font-size:56px;display:block;margin-bottom:16px;opacity:0.3;"></i>
                <div style="font-size:18px;font-weight:700;color:var(--text2);">${t('login_to_view')} ${t('orders')}</div>
                <div style="font-size:13px;margin-top:4px;">ستظهر طلباتك هنا بعد الشراء</div>
                <button onclick="openLoginPage()" style="padding:12px 40px;border:none;border-radius:12px;background:var(--primary-light);color:#fff;font-size:14px;font-weight:700;cursor:pointer;margin-top:16px;">
                    <i class="fa-solid fa-arrow-right-to-bracket"></i> ${t('login')}
                </button>
            </div>
        `;
        return;
    }
    
    const userOrders = state.orders.filter(function(o) { 
        return o.userId == state.user.id; 
    });
    
    if (userOrders.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:80px 20px;color:var(--text3);">
                <i class="fa-regular fa-receipt" style="font-size:56px;display:block;margin-bottom:16px;opacity:0.3;"></i>
                <div style="font-size:18px;font-weight:700;color:var(--text2);">${t('no_orders')}</div>
                <div style="font-size:13px;margin-top:4px;">قم بشراء منتجات لتظهر طلباتك هنا</div>
                <button onclick="switchPage('page-home')" style="padding:12px 40px;border:none;border-radius:12px;background:var(--primary-light);color:#fff;font-size:14px;font-weight:700;cursor:pointer;margin-top:16px;">
                    <i class="fa-solid fa-bag-shopping"></i> ${t('start_shopping')}
                </button>
            </div>
        `;
        return;
    }
    
    const sortedOrders = [...userOrders].sort(function(a, b) {
        return new Date(b.createdAt) - new Date(a.createdAt);
    });
    
    container.innerHTML = sortedOrders.map(function(order) {
        const statusClass = ['pending', 'shipped', 'completed', 'cancelled', 'approved', 'rejected'].includes(order.status) ? order.status : 'pending';
        const statusText = {
            pending: '⏳ قيد المعالجة',
            shipped: '🚚 قيد التوصيل',
            completed: '✅ مكتمل',
            cancelled: '❌ ملغي',
            approved: '✅ تم الموافقة',
            rejected: '❌ مرفوض'
        }[order.status] || '⏳ قيد المعالجة';
        
        let items = [];
        try {
            items = order.items ? (typeof order.items === 'string' ? JSON.parse(order.items) : order.items) : [];
        } catch (e) {
            items = [];
        }
        
        const bgColor = statusClass === 'completed' || statusClass === 'approved' ? 'rgba(46,204,113,.15)' : 
                       statusClass === 'pending' ? 'rgba(216,176,122,.15)' : 
                       statusClass === 'shipped' ? 'rgba(5,134,147,.15)' : 
                       statusClass === 'rejected' ? 'rgba(231,76,60,.15)' : 'rgba(231,76,60,.15)';
        const textColor = statusClass === 'completed' || statusClass === 'approved' ? 'var(--green)' : 
                         statusClass === 'pending' ? 'var(--secondary)' : 
                         statusClass === 'shipped' ? 'var(--primary)' : 
                         statusClass === 'rejected' ? 'var(--red)' : 'var(--red)';
        
        return `
            <div class="order-item" onclick="openOrderDetail('${order.id}')" 
                 style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:12px;cursor:pointer;transition:all 0.3s var(--ease-spring);box-shadow:var(--shadow);">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <div style="flex:1;min-width:150px;">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span style="font-weight:700;font-size:15px;color:var(--text2);">${order.orderId || order.id}</span>
                            <span style="font-size:12px;color:var(--text3);">📅 ${order.date || new Date(order.createdAt).toLocaleDateString('ar-EG')}</span>
                        </div>
                        <div style="font-size:13px;color:var(--text3);margin-top:3px;">
                            <i class="fa-solid fa-box"></i> ${items.length} منتج • 💰 ${parseFloat(order.total).toFixed(2)} د.ع
                        </div>
                        ${order.transferImage ? '<div style="font-size:11px;color:var(--primary);margin-top:2px;">🖼️ مع صورة تحويل</div>' : ''}
                    </div>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <span class="order-status ${statusClass}" style="padding:4px 14px;border-radius:20px;font-size:12px;font-weight:700;background:${bgColor};color:${textColor};">
                            ${statusText}
                        </span>
                        <i class="fa-solid fa-chevron-left" style="color:var(--text3);font-size:16px;"></i>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

// ============================================================
// ADMIN RENDER FUNCTIONS
// ============================================================

// ============================================================
// ✅ دالة renderAdmin المُصححة
// ============================================================
function renderAdmin() {
    renderAdminProducts();
    renderAdminCategories();
    renderAdminOrders();  // ✅ هذه الدالة الآن تستخدم نفس منطق استخراج المنتجات
    renderAdminPayments();
    renderAdminUsers();
    renderAdminRecharges();
    updateAdminStats();
    loadAdminChats();
}

function updateAdminStats() {
    const productsEl = $('statProducts');
    const categoriesEl = $('statCategories');
    const ordersEl = $('statOrders');
    const usersEl = $('statUsers');
    if (productsEl) productsEl.textContent = state.products.length;
    if (categoriesEl) categoriesEl.textContent = state.categories.length;
    if (ordersEl) ordersEl.textContent = state.orders.length;
    if (usersEl) usersEl.textContent = state.users.length;
}

function renderAdminProducts() {
    const container = $('adminProductsList');
    if (!container) return;
    if (state.products.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text3);">لا توجد منتجات</div>';
        return;
    }
    container.innerHTML = state.products.map(function(p) {
        return `
            <div class="admin-list-item">
                <div class="item-info"><div class="item-name">${p.name}</div><div class="item-sub">${parseFloat(p.price).toFixed(2)} د.ع • ${p.category || ''}</div></div>
                <div class="item-actions">
                    <button class="btn-edit" onclick="openProductForm(${p.id})">تعديل</button>
                    <button class="btn-delete" onclick="deleteProduct(${p.id})">حذف</button>
                </div>
            </div>
        `;
    }).join('');
}

function renderAdminCategories() {
    const container = $('adminCategoriesList');
    if (!container) return;
    if (state.categories.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text3);">لا توجد تصنيفات</div>';
        return;
    }
    container.innerHTML = state.categories.map(function(c) {
        return `
            <div class="admin-list-item">
                <div class="item-info"><div class="item-name">${c.name}</div><div class="item-sub">${c.id}</div></div>
                <div class="item-actions">
                    <button class="btn-edit" onclick="openCategoryForm('${c.id}')">تعديل</button>
                    <button class="btn-delete" onclick="deleteCategory('${c.id}')">حذف</button>
                </div>
            </div>
        `;
    }).join('');
}

function renderAdminOrders() {
    const container = $('adminOrdersList');
    if (!container) return;
    
    if (!state.orders || state.orders.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:30px;color:var(--text3);">
                <i class="fa-regular fa-receipt" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.3;"></i>
                لا توجد طلبات
            </div>
        `;
        return;
    }
    
    const statusLabels = { 
        pending: '⏳ قيد المعالجة', 
        shipped: '🚚 قيد التوصيل', 
        completed: '✅ مكتمل', 
        cancelled: '❌ ملغي',
           };
    
    const sortedOrders = [...state.orders].reverse();
    
    container.innerHTML = sortedOrders.map(function(order) {
        const user = state.users.find(function(u) { return u.id == order.userId; });
        
        // ✅ استخراج المنتجات بشكل صحيح من order.items
        let items = [];
        try {
            if (order.items) {
                if (typeof order.items === 'string') {
                    items = JSON.parse(order.items);
                } else if (Array.isArray(order.items)) {
                    items = order.items;
                } else if (typeof order.items === 'object') {
                    items = Object.values(order.items);
                }
            }
        } catch (e) {
            console.warn('⚠️ خطأ في تحليل items للطلب:', order.id, e);
            items = [];
        }
        
        if (!Array.isArray(items)) {
            items = [];
        }
        
        const itemsCount = items.length;
        
        // ✅ حساب المجموع بشكل دقيق
        let calculatedTotal = 0;
        items.forEach(function(item) {
            if (item && typeof item === 'object') {
                const price = parseFloat(item.price) || 0;
                const qty = parseInt(item.qty) || 1;
                calculatedTotal += price * qty;
            }
        });
        
        let displayTotal = parseFloat(order.total) || 0;
        if (displayTotal <= 0 && calculatedTotal > 0) {
            displayTotal = calculatedTotal;
        }
        
        const statusClass = ['pending', 'shipped', 'completed', 'cancelled', 'approved', 'rejected'].includes(order.status) ? order.status : 'pending';
        const bgColor = statusClass === 'completed' || statusClass === 'approved' ? 'rgba(46,204,113,.15)' : 
                       statusClass === 'pending' ? 'rgba(216,176,122,.15)' : 
                       statusClass === 'shipped' ? 'rgba(5,134,147,.15)' : 
                       statusClass === 'rejected' ? 'rgba(231,76,60,.15)' : 'rgba(231,76,60,.15)';
        const textColor = statusClass === 'completed' || statusClass === 'approved' ? 'var(--green)' : 
                         statusClass === 'pending' ? 'var(--secondary)' : 
                         statusClass === 'shipped' ? 'var(--primary)' : 
                         statusClass === 'rejected' ? 'var(--red)' : 'var(--red)';
        
        // ✅ عرض المنتجات في بطاقة الطلب
        let productsPreview = '';
        if (items.length > 0) {
            const previewItems = items.slice(0, 3);
            productsPreview = previewItems.map(function(item) {
                const itemName = item.name || item.title || 'منتج';
                const itemQty = item.qty || item.quantity || 1;
                return `<span style="font-size:11px;color:var(--text3);background:var(--bg2);padding:2px 8px;border-radius:4px;margin:2px;display:inline-block;">${itemName} × ${itemQty}</span>`;
            }).join(' ');
            if (items.length > 3) {
                productsPreview += `<span style="font-size:11px;color:var(--text3);"> +${items.length - 3} أخرى</span>`;
            }
        } else {
            productsPreview = `<span style="font-size:11px;color:var(--red);">⚠️ لا توجد منتجات</span>`;
        }
        
        return `
            <div class="admin-list-item" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:14px;margin-bottom:10px;cursor:pointer;" onclick="openOrderDetail('${order.id}')">
                <div style="flex:1;min-width:150px;">
                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="font-weight:700;font-size:14px;color:var(--text2);">${order.orderId || order.id}</span>
                        <span style="font-size:12px;color:var(--text3);">👤 ${user ? user.name : 'غير معروف'}</span>
                        <span style="font-size:12px;color:var(--text3);">📅 ${order.date || new Date(order.createdAt).toLocaleDateString('ar-EG')}</span>
                    </div>
                    <div style="font-size:13px;color:var(--text3);margin-top:2px;">
                        ${itemsCount} منتج • ${displayTotal.toFixed(2)} د.ع • ${order.payment || 'غير محدد'}
                    </div>
                    <div style="margin-top:4px;display:flex;flex-wrap:wrap;gap:4px;">
                        ${productsPreview}
                    </div>
                    ${order.transferImage ? '<div style="font-size:11px;color:var(--primary);margin-top:2px;">🖼️ مع صورة تحويل</div>' : ''}
                </div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <span class="order-status ${statusClass}" style="padding:4px 12px;border-radius:20px;font-size:11px;font-weight:700;background:${bgColor};color:${textColor};">
                        ${statusLabels[order.status] || order.status}
                    </span>
                    <button onclick="event.stopPropagation();openOrderDetail('${order.id}')" style="padding:6px 14px;border:none;border-radius:8px;background:var(--blue);color:#fff;font-size:11px;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:4px;">
                        <i class="fa-regular fa-eye"></i> عرض
                    </button>
                    <select onclick="event.stopPropagation();" onchange="updateOrderStatus(${order.id}, this.value)" style="padding:4px 10px;border:2px solid var(--border);border-radius:8px;font-size:11px;background:var(--surface);">
                        ${Object.keys(statusLabels).map(function(s) {
                            return '<option value="' + s + '" ' + (order.status === s ? 'selected' : '') + '>' + statusLabels[s] + '</option>';
                        }).join('')}
                    </select>
                    ${order.transferImage && order.status === 'pending' ? `
                        <button class="btn-approve" onclick="event.stopPropagation();updateOrderStatus(${order.id}, 'approved')" style="padding:6px 14px;border:none;border-radius:8px;background:var(--green);color:#fff;font-size:11px;font-weight:700;cursor:pointer;">
                            ✅ موافقة
                        </button>
                        <button class="btn-reject" onclick="event.stopPropagation();updateOrderStatus(${order.id}, 'rejected')" style="padding:6px 14px;border:none;border-radius:8px;background:var(--red);color:#fff;font-size:11px;font-weight:700;cursor:pointer;">
                            ❌ رفض
                        </button>
                    ` : ''}
                </div>
            </div>
        `;
    }).join('');
}
function renderAdminPayments() {
    const container = $('adminPaymentsList');
    if (!container) return;
    if (state.payments.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text3);">لا توجد طرق دفع</div>';
        return;
    }
    container.innerHTML = state.payments.map(function(pm) {
        return `
            <div class="admin-list-item">
                <div class="item-info">
                    <div class="item-name"><i class="${pm.icon}"></i> ${pm.name}</div>
                    <div class="item-sub">${pm.enabled ? 'مفعل' : 'غير مفعل'}${pm.accountNumber ? ' • رقم: ' + pm.accountNumber : ''}${pm.beneficiary ? ' • المستفيد: ' + pm.beneficiary : ''}</div>
                </div>
                <div class="item-actions">
                    <button class="btn-status" onclick="togglePayment('${pm.id}')">${pm.enabled ? 'تعطيل' : 'تفعيل'}</button>
                    <button class="btn-edit" onclick="openPaymentForm('${pm.id}')">تعديل</button>
                    ${pm.id !== 'cash' && pm.id !== 'electronic' ? '<button class="btn-delete" onclick="deletePayment(\'' + pm.id + '\')">حذف</button>' : ''}
                </div>
            </div>
        `;
    }).join('');
}

function renderAdminUsers() {
    const container = $('adminUsersList');
    if (!container) return;
    if (state.users.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:20px;color:var(--text3);">لا توجد مستخدمين</div>';
        return;
    }
    container.innerHTML = state.users.map(function(u) {
        return `
            <div class="admin-list-item">
                <div class="item-info"><div class="item-name">${u.name} ${u.isAdmin ? '⭐' : ''} ${u.isVerified ? '✅' : '⏳'}</div><div class="item-sub">${u.email} • رصيد: ${(u.balance || 0).toFixed(2)} د.ع</div></div>
                <div class="item-actions">
                    ${u.id !== 1 ? '<button class="btn-delete" onclick="deleteUser(' + u.id + ')">حذف</button>' : ''}
                </div>
            </div>
        `;
    }).join('');
}

// ============================================================
// ADMIN ACTIONS
// ============================================================

function deleteProduct(id) {
    if (!confirm('هل أنت متأكد من حذف هذا المنتج؟')) return;
    callAPI('deleteProduct', { id: id }, 'GET').then(function(response) {
        if (response.success) { showToast('✅ تم حذف المنتج'); loadAllData(true); } 
        else { showToast(response.message || 'حدث خطأ'); }
    });
}

function deleteCategory(id) {
    if (!confirm('هل أنت متأكد من حذف هذا التصنيف؟')) return;
    callAPI('deleteCategory', { id: id }, 'GET').then(function(response) {
        if (response.success) { showToast('✅ تم حذف التصنيف'); loadAllData(true); } 
        else { showToast(response.message || 'حدث خطأ'); }
    });
}

function updateOrderStatus(id, status) {
    callAPI('updateOrderStatus', { id: id, status: status }, 'POST').then(function(response) {
        if (response.success) { showToast('✅ تم تحديث حالة الطلب'); loadAllData(true); } 
        else { showToast(response.message || 'حدث خطأ'); }
    });
}

function togglePayment(id) {
    callAPI('togglePayment', { id: id }, 'POST').then(function(response) {
        if (response.success) { showToast('✅ تم تحديث حالة طريقة الدفع'); loadAllData(true); } 
        else { showToast(response.message || 'حدث خطأ'); }
    });
}

function deletePayment(id) {
    if (!confirm('هل أنت متأكد من حذف هذه الطريقة؟')) return;
    callAPI('deletePayment', { id: id }, 'GET').then(function(response) {
        if (response.success) { showToast('✅ تم حذف طريقة الدفع'); loadAllData(true); } 
        else { showToast(response.message || 'حدث خطأ'); }
    });
}

function deleteUser(id) {
    if (!confirm('هل أنت متأكد من حذف هذا المستخدم؟')) return;
    callAPI('deleteUser', { id: id }, 'GET').then(function(response) {
        if (response.success) { showToast('✅ تم حذف المستخدم'); loadAllData(true); } 
        else { showToast(response.message || 'حدث خطأ'); }
    });
}

// ============================================================
// ADMIN FORMS
// ============================================================

function openProductForm(id) {
    id = id || null;
    const overlay = $('productFormOverlay');
    if (!overlay) return;
    
    const titleEl = $('productFormTitle');
    if (titleEl) titleEl.textContent = id ? '✏️ تعديل المنتج' : '📦 إضافة منتج جديد';
    
    const editIdEl = $('editProductId');
    if (editIdEl) editIdEl.value = id || '';
    
    const catSelect = $('productCategory');
    if (catSelect) {
        catSelect.innerHTML = '<option value="">-- اختر تصنيف --</option>' + 
            state.categories.map(function(c) {
                return '<option value="' + c.id + '">' + c.name + ' (' + c.nameEn + ')</option>';
            }).join('');
    }
    
    const previewEl = $('productImagePreview');
    if (previewEl) {
        previewEl.innerHTML = '<i class="fa-solid fa-image" style="font-size:40px;color:var(--text3);"></i>';
        previewEl.style.borderColor = 'var(--border)';
    }
    const fileInput = $('productImageFile');
    if (fileInput) fileInput.value = '';
    
    // معرض الصور
    const imagesPreview = $('productImagesPreview');
    if (imagesPreview) imagesPreview.innerHTML = '';
    const existingContainer = $('existingImagesContainer');
    if (existingContainer) existingContainer.style.display = 'none';
    
    const errorEl = $('productFormError');
    if (errorEl) errorEl.style.display = 'none';
    
    if (id) {
        const product = state.products.find(function(p) { return p.id === id; });
        if (product) {
            if ($('productNameAr')) $('productNameAr').value = product.name || '';
            if ($('productNameEn')) $('productNameEn').value = product.nameEn || '';
            if ($('productDescAr')) $('productDescAr').value = product.desc || '';
            if ($('productDescEn')) $('productDescEn').value = product.descEn || '';
            if ($('productCategory')) $('productCategory').value = product.category || '';
            if ($('productBrand')) $('productBrand').value = product.brand || '';
            if ($('productPrice')) $('productPrice').value = product.price || '';
            if ($('productOldPrice')) $('productOldPrice').value = product.oldPrice || '';
            if ($('productDelivery')) $('productDelivery').value = product.deliveryTime || 'خلال 24 ساعة';
            if ($('productRating')) $('productRating').value = product.rating || 4.5;
            if ($('productRatingCount')) $('productRatingCount').value = product.ratingCount || 0;
            if ($('productIsNew')) $('productIsNew').checked = product.isNew || false;
            if ($('productIsBestSeller')) $('productIsBestSeller').checked = product.isBestSeller || false;
            if ($('productIsFlashDeal')) $('productIsFlashDeal').checked = product.isFlashDeal || false;
            if ($('productIsFeatured')) $('productIsFeatured').checked = product.isFeatured || false;
            
            if (product.image_url && previewEl) {
                previewEl.innerHTML = '<img src="' + product.image_url + '" style="width:100%;height:100%;object-fit:cover;">';
                previewEl.style.borderColor = 'var(--primary)';
            }
            
            // عرض الصور الموجودة
            if (product.images && product.images.length > 0) {
                renderExistingImages(product.images);
            }
            
            const saveBtn = document.querySelector('#productFormOverlay .btn-save');
            if (saveBtn) saveBtn.innerHTML = '<i class="fa-solid fa-pen"></i> تحديث المنتج';
        }
    } else {
        const form = $('productForm');
        if (form) form.reset();
        if ($('productIsNew')) $('productIsNew').checked = true;
        if ($('productDelivery')) $('productDelivery').value = 'خلال 24 ساعة';
        if ($('productRating')) $('productRating').value = 4.5;
        if ($('productRatingCount')) $('productRatingCount').value = 0;
        
        const saveBtn = document.querySelector('#productFormOverlay .btn-save');
        if (saveBtn) saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> حفظ المنتج';
    }
    
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
}

function renderExistingImages(images) {
    const container = $('existingImagesList');
    const wrapper = $('existingImagesContainer');
    if (!container || !wrapper) return;
    
    if (!images || images.length === 0) {
        wrapper.style.display = 'none';
        return;
    }
    
    wrapper.style.display = 'block';
    container.innerHTML = images.map(function(img, index) {
        const imgUrl = window.location.pathname + '/data/uploads/products/' + img;
        return `
            <div style="width:80px;height:80px;border-radius:8px;overflow:hidden;border:2px solid var(--border);position:relative;">
                <img src="${imgUrl}" style="width:100%;height:100%;object-fit:cover;">
                <span style="position:absolute;top:-4px;right:-4px;background:var(--red);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;cursor:pointer;" onclick="deleteExistingImage(${index})">×</span>
            </div>
        `;
    }).join('');
}

function deleteExistingImage(index) {
    if (!window.deleteImages) window.deleteImages = [];
    window.deleteImages.push(index);
    const product = state.products.find(function(p) { return p.id === parseInt($('editProductId').value); });
    if (product && product.images) {
        const images = product.images;
        const filtered = images.filter(function(_, i) { return i !== index; });
        renderExistingImages(filtered);
    }
}

function closeProductForm() {
    const overlay = $('productFormOverlay');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
    updateBottomNavVisibility();
}

function saveProduct() {
    const editId = $('editProductId') ? $('editProductId').value : '';
    const errorEl = $('productFormError');
    
    // ===== جلب البيانات من الحقول =====
    const nameAr = $('productNameAr') ? $('productNameAr').value.trim() : '';
    const nameEn = $('productNameEn') ? $('productNameEn').value.trim() : '';
    const descAr = $('productDescAr') ? $('productDescAr').value.trim() : '';
    const descEn = $('productDescEn') ? $('productDescEn').value.trim() : '';
    const category = $('productCategory') ? $('productCategory').value : '';
    const brand = $('productBrand') ? $('productBrand').value.trim() : '';
    const price = parseFloat($('productPrice') ? $('productPrice').value : 0);
    const oldPrice = $('productOldPrice') ? parseFloat($('productOldPrice').value) : null;
    const delivery = $('productDelivery') ? $('productDelivery').value.trim() : 'خلال 24 ساعة';
    const rating = parseFloat($('productRating') ? $('productRating').value : 4.5);
    const ratingCount = parseInt($('productRatingCount') ? $('productRatingCount').value : 0);
    const isNew = $('productIsNew') ? $('productIsNew').checked : false;
    const isBestSeller = $('productIsBestSeller') ? $('productIsBestSeller').checked : false;
    const isFlashDeal = $('productIsFlashDeal') ? $('productIsFlashDeal').checked : false;
    const isFeatured = $('productIsFeatured') ? $('productIsFeatured').checked : false;
    
    // ===== التحقق من الحقول المطلوبة =====
    if (!nameAr || !nameEn || !category || price <= 0) {
        if (errorEl) {
            errorEl.textContent = '⚠️ الرجاء تعبئة جميع الحقول المطلوبة: الاسم بالعربية، الاسم بالإنجليزية، التصنيف، والسعر';
            errorEl.style.display = 'block';
        }
        showToast('⚠️ الرجاء تعبئة جميع الحقول المطلوبة');
        return;
    }
    
    if (errorEl) errorEl.style.display = 'none';
    
    // ===== إنشاء FormData =====
    const formData = new FormData();
    
    // البيانات الأساسية
    formData.append('name', nameAr);
    formData.append('nameEn', nameEn);
    formData.append('desc', descAr);
    formData.append('descEn', descEn || descAr);
    formData.append('category', category);
    formData.append('brand', brand);
    formData.append('price', price);
    if (oldPrice && oldPrice > 0) {
        formData.append('oldPrice', oldPrice);
    }
    formData.append('deliveryTime', delivery);
    formData.append('rating', rating);
    formData.append('ratingCount', ratingCount);
    formData.append('isNew', isNew ? '1' : '0');
    formData.append('isBestSeller', isBestSeller ? '1' : '0');
    formData.append('isFlashDeal', isFlashDeal ? '1' : '0');
    formData.append('isFeatured', isFeatured ? '1' : '0');
    
    // ===== الصورة الرئيسية =====
    const imageFile = $('productImageFile');
    if (imageFile && imageFile.files && imageFile.files[0]) {
        // التحقق من حجم الصورة
        if (imageFile.files[0].size > 5 * 1024 * 1024) {
            showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
            return;
        }
        formData.append('image', imageFile.files[0]);
    }
    
    // ===== الصور الإضافية =====
    const imagesInput = $('productImages');
    if (imagesInput && imagesInput.files) {
        for (let i = 0; i < imagesInput.files.length; i++) {
            if (imagesInput.files[i].size > 5 * 1024 * 1024) {
                showToast('⚠️ حجم الصورة ' + (i+1) + ' كبير جداً (الحد الأقصى 5MB)');
                return;
            }
            formData.append('images[]', imagesInput.files[i]);
        }
    }
    
    // ===== إذا كان تعديل =====
    const action = editId ? 'updateProduct' : 'addProduct';
    if (editId) {
        formData.append('id', editId);
        console.log('📝 تعديل منتج رقم:', editId);
    }
    
    // ===== حذف الصور (في حالة التعديل) =====
    if (editId && window.deleteImages && window.deleteImages.length > 0) {
        const product = state.products.find(function(p) { return p.id === parseInt(editId); });
        if (product && product.images) {
            window.deleteImages.forEach(function(index) {
                if (product.images[index]) {
                    formData.append('delete_images[]', product.images[index]);
                }
            });
        }
        window.deleteImages = [];
    }
    
    // ===== عرض البيانات في Console للتصحيح =====
    console.log('📦 بيانات المنتج المرسلة:');
    for (let pair of formData.entries()) {
        if (pair[0] !== 'image' && pair[0] !== 'images[]') {
            console.log(pair[0] + ':', pair[1]);
        } else {
            console.log(pair[0] + ':', pair[1] ? '📷 صورة (ملف)' : 'لا توجد صورة');
        }
    }
    
    // ===== تعطيل زر الحفظ =====
    const saveBtn = document.querySelector('#productFormOverlay .btn-save');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الحفظ...';
        saveBtn.style.opacity = '0.6';
    }
    
    // ===== إرسال الطلب =====
    callAPI(action, formData, 'POST')
        .then(function(response) {
            console.log('📨 استجابة الخادم:', response);
            
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = editId ? '<i class="fa-solid fa-pen"></i> تحديث المنتج' : '<i class="fa-solid fa-check"></i> حفظ المنتج';
                saveBtn.style.opacity = '1';
            }
            
            if (response.success) {
                showToast(editId ? '✅ تم تحديث المنتج بنجاح' : '✅ تم إضافة المنتج بنجاح');
                closeProductForm();
                loadAllData(true);
            } else {
                if (errorEl) {
                    errorEl.textContent = '❌ ' + (response.message || 'حدث خطأ أثناء حفظ المنتج');
                    errorEl.style.display = 'block';
                }
                showToast('❌ ' + (response.message || 'فشل إضافة المنتج'));
            }
        })
        .catch(function(error) {
            console.error('❌ خطأ في الاتصال:', error);
            
            if (saveBtn) {
                saveBtn.disabled = false;
                saveBtn.innerHTML = editId ? '<i class="fa-solid fa-pen"></i> تحديث المنتج' : '<i class="fa-solid fa-check"></i> حفظ المنتج';
                saveBtn.style.opacity = '1';
            }
            
            if (errorEl) {
                errorEl.textContent = '❌ حدث خطأ في الاتصال بالخادم: ' + error.message;
                errorEl.style.display = 'block';
            }
            showToast('❌ حدث خطأ في الاتصال بالخادم');
        });
}

function openCategoryForm(id) {
    id = id || null;
    const overlay = $('categoryFormOverlay');
    if (!overlay) return;
    
    const titleEl = $('categoryFormTitle');
    if (titleEl) titleEl.textContent = id ? '✏️ تعديل التصنيف' : '📂 إضافة تصنيف جديد';
    
    const editIdEl = $('editCategoryId');
    if (editIdEl) editIdEl.value = id || '';
    
    const iconPreview = $('categoryIconPreviewImg');
    if (iconPreview) {
        iconPreview.innerHTML = '<i class="fa-solid fa-image" style="font-size:40px;color:var(--text3);"></i>';
        iconPreview.style.borderColor = 'var(--border)';
    }
    const bannerPreview = $('categoryBannerPreview');
    if (bannerPreview) {
        bannerPreview.innerHTML = '<i class="fa-solid fa-image" style="font-size:40px;color:var(--text3);"></i>';
        bannerPreview.style.borderColor = 'var(--border)';
    }
    
    const iconFile = $('categoryIconImage');
    if (iconFile) iconFile.value = '';
    const bannerFile = $('categoryBannerImage');
    if (bannerFile) bannerFile.value = '';
    
    const errorEl = $('categoryFormError');
    if (errorEl) errorEl.style.display = 'none';
    
    const iconPreviewEl = $('categoryIconPreview');
    if (iconPreviewEl) {
        iconPreviewEl.innerHTML = '<i class="fa-solid fa-tag"></i>';
    }
    
    if (id) {
        const cat = state.categories.find(function(c) { return c.id === id; });
        if (cat) {
            if ($('categoryId')) $('categoryId').value = cat.id || '';
            if ($('categoryNameAr')) $('categoryNameAr').value = cat.name || '';
            if ($('categoryNameEn')) $('categoryNameEn').value = cat.nameEn || '';
            if ($('categoryIcon')) $('categoryIcon').value = cat.icon || 'fa-solid fa-tag';
            if ($('categorySort')) $('categorySort').value = cat.sortOrder || 0;
            
            if (iconPreviewEl) {
                iconPreviewEl.innerHTML = '<i class="' + (cat.icon || 'fa-solid fa-tag') + '"></i>';
            }
            
            if (cat.icon_image_url && iconPreview) {
                iconPreview.innerHTML = '<img src="' + cat.icon_image_url + '" style="width:100%;height:100%;object-fit:cover;">';
                iconPreview.style.borderColor = 'var(--primary)';
            }
            if (cat.banner_image_url && bannerPreview) {
                bannerPreview.innerHTML = '<img src="' + cat.banner_image_url + '" style="width:100%;height:100%;object-fit:cover;">';
                bannerPreview.style.borderColor = 'var(--primary)';
            }
            
            const saveBtn = document.querySelector('#categoryFormOverlay .btn-save');
            if (saveBtn) saveBtn.innerHTML = '<i class="fa-solid fa-pen"></i> تحديث التصنيف';
        }
    } else {
        const form = $('categoryForm');
        if (form) form.reset();
        if ($('categoryIcon')) $('categoryIcon').value = 'fa-solid fa-tag';
        if ($('categorySort')) $('categorySort').value = 0;
        if (iconPreviewEl) {
            iconPreviewEl.innerHTML = '<i class="fa-solid fa-tag"></i>';
        }
        
        const saveBtn = document.querySelector('#categoryFormOverlay .btn-save');
        if (saveBtn) saveBtn.innerHTML = '<i class="fa-solid fa-check"></i> حفظ التصنيف';
    }
    
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
}

function closeCategoryForm() {
    const overlay = $('categoryFormOverlay');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
    updateBottomNavVisibility();
}

function saveCategory() {
    const editId = $('editCategoryId') ? $('editCategoryId').value : '';
    const errorEl = $('categoryFormError');
    
    const catId = $('categoryId') ? $('categoryId').value.trim().toLowerCase().replace(/[^a-z0-9\-_]/g, '') : '';
    const nameAr = $('categoryNameAr') ? $('categoryNameAr').value.trim() : '';
    const nameEn = $('categoryNameEn') ? $('categoryNameEn').value.trim() : '';
    const icon = $('categoryIcon') ? $('categoryIcon').value.trim() : 'fa-solid fa-tag';
    const sortOrder = parseInt($('categorySort') ? $('categorySort').value : 0);
    
    if (!catId || !nameAr || !nameEn) {
        if (errorEl) {
            errorEl.textContent = '⚠️ الرجاء تعبئة جميع الحقول المطلوبة: المعرف، الاسم بالعربية، والاسم بالإنجليزية';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    if (!/^[a-z0-9\-_]+$/.test(catId)) {
        if (errorEl) {
            errorEl.textContent = '⚠️ المعرف يجب أن يحتوي على أحرف إنجليزية صغيرة وأرقام وشرطة سفلية فقط';
            errorEl.style.display = 'block';
        }
        return;
    }
    
    if (errorEl) errorEl.style.display = 'none';
    
    const formData = new FormData();
    formData.append('id', catId);
    formData.append('name', nameAr);
    formData.append('nameEn', nameEn);
    formData.append('icon', icon);
    formData.append('sortOrder', sortOrder);
    
    const iconFile = $('categoryIconImage');
    if (iconFile && iconFile.files && iconFile.files[0]) {
        formData.append('iconImage', iconFile.files[0]);
    }
    const bannerFile = $('categoryBannerImage');
    if (bannerFile && bannerFile.files && bannerFile.files[0]) {
        formData.append('bannerImage', bannerFile.files[0]);
    }
    
    const action = editId ? 'updateCategory' : 'addCategory';
    if (editId) formData.append('oldId', editId);
    
    const saveBtn = document.querySelector('#categoryFormOverlay .btn-save');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الحفظ...';
        saveBtn.style.opacity = '0.6';
    }
    
    callAPI(action, formData, 'POST').then(function(response) {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = editId ? '<i class="fa-solid fa-pen"></i> تحديث التصنيف' : '<i class="fa-solid fa-check"></i> حفظ التصنيف';
            saveBtn.style.opacity = '1';
        }
        
        if (response.success) {
            showToast(editId ? '✅ تم تحديث التصنيف بنجاح' : '✅ تم إضافة التصنيف بنجاح');
            closeCategoryForm();
            loadAllData(true);
        } else {
            if (errorEl) {
                errorEl.textContent = '❌ ' + (response.message || 'حدث خطأ أثناء حفظ التصنيف');
                errorEl.style.display = 'block';
            }
        }
    }).catch(function() {
        if (saveBtn) {
            saveBtn.disabled = false;
            saveBtn.innerHTML = editId ? '<i class="fa-solid fa-pen"></i> تحديث التصنيف' : '<i class="fa-solid fa-check"></i> حفظ التصنيف';
            saveBtn.style.opacity = '1';
        }
        if (errorEl) {
            errorEl.textContent = '❌ حدث خطأ في الاتصال بالخادم';
            errorEl.style.display = 'block';
        }
    });
}

function openPaymentForm(id) {
    id = id || null;
    const overlay = $('paymentFormOverlay');
    if (!overlay) return;
    if ($('paymentFormTitle')) $('paymentFormTitle').textContent = id ? 'تعديل طريقة الدفع' : 'إضافة طريقة دفع';
    if ($('editPaymentId')) $('editPaymentId').value = id || '';
    if ($('pmImagePreview')) $('pmImagePreview').innerHTML = '<i class="fa-solid fa-image" style="font-size:40px;color:var(--text3);"></i>';
    if ($('pmImageFile')) $('pmImageFile').value = '';
    
    if (id) {
        const pm = state.payments.find(function(p) { return p.id === id; });
        if (pm) {
            if ($('pmNameAr')) $('pmNameAr').value = pm.name || '';
            if ($('pmNameEn')) $('pmNameEn').value = pm.nameEn || '';
            if ($('pmIcon')) $('pmIcon').value = pm.icon || 'fa-solid fa-credit-card';
            if ($('pmAccountNumber')) $('pmAccountNumber').value = pm.accountNumber || '';
            if ($('pmBeneficiary')) $('pmBeneficiary').value = pm.beneficiary || '';
            if ($('pmIsRechargeOnly')) $('pmIsRechargeOnly').checked = pm.isRechargeOnly || false;
            if ($('pmImagePreview') && pm.image_url) {
                $('pmImagePreview').innerHTML = '<img src="' + pm.image_url + '" style="width:100%;height:100%;object-fit:cover;">';
            }
        }
    } else {
        const form = $('paymentMethodForm');
        if (form) form.reset();
        if ($('pmIcon')) $('pmIcon').value = 'fa-solid fa-credit-card';
        if ($('pmIsRechargeOnly')) $('pmIsRechargeOnly').checked = false;
    }
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
}

function closePaymentForm() {
    const overlay = $('paymentFormOverlay');
    if (overlay) overlay.classList.remove('open');
    document.body.style.overflow = '';
    updateBottomNavVisibility();
}

function savePaymentMethod() {
    const editId = $('editPaymentId') ? $('editPaymentId').value : '';
    const formData = new FormData();
    
    if ($('pmNameEn')) formData.append('id', $('pmNameEn').value.trim().toLowerCase().replace(/[^a-z0-9]/g, ''));
    if ($('pmNameAr')) formData.append('name', $('pmNameAr').value.trim());
    if ($('pmNameEn')) formData.append('nameEn', $('pmNameEn').value.trim());
    if ($('pmIcon')) formData.append('icon', $('pmIcon').value.trim() || 'fa-solid fa-credit-card');
    if ($('pmAccountNumber')) formData.append('accountNumber', $('pmAccountNumber').value.trim());
    if ($('pmBeneficiary')) formData.append('beneficiary', $('pmBeneficiary').value.trim());
    if ($('pmIsRechargeOnly')) formData.append('isRechargeOnly', $('pmIsRechargeOnly').checked ? 'true' : 'false');
    if ($('pmImageFile') && $('pmImageFile').files[0]) formData.append('image', $('pmImageFile').files[0]);
    
    const action = editId ? 'updatePayment' : 'addPayment';
    if (editId) formData.append('oldId', editId);
    
    callAPI(action, formData, 'POST').then(function(response) {
        if (response.success) {
            showToast(editId ? '✅ تم تحديث طريقة الدفع' : '✅ تم إضافة طريقة الدفع');
            closePaymentForm();
            loadAllData(true);
        } else {
            showToast(response.message || 'حدث خطأ');
        }
    });
}

function exportData() {
    window.location.href = window.location.pathname + '?action=exportData';
}

// ============================================================
// CHAT FUNCTIONS
// ============================================================

function loadAdminChats() {
    if (!state.isAdmin) return;
    const userId = state.user ? state.user.id : 0;
    callAPI('getAdminChats', { adminId: userId }, 'GET').then(function(response) {
        if (response.success) {
            const filteredData = response.data ? response.data.filter(function(chat) {
                return chat.isAdmin != 1;
            }) : [];
            renderAdminUserList(filteredData);
        }
    });
}

function renderAdminUserList(chats) {
    const container = $('adminUserList');
    const countEl = $('adminUserCount');
    if (!container) return;
    
    const filteredChats = chats ? chats.filter(function(chat) {
        return chat.isAdmin != 1;
    }) : [];
    
    if (!filteredChats || filteredChats.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:30px 10px;color:var(--text3);font-size:13px;">
                <i class="fa-regular fa-comments" style="font-size:32px;display:block;margin-bottom:8px;opacity:0.3;"></i>
                لا توجد محادثات مع مستخدمين
            </div>
        `;
        if (countEl) countEl.textContent = '0';
        return;
    }
    
    if (countEl) countEl.textContent = filteredChats.length;
    
    container.innerHTML = filteredChats.map(function(chat) {
        const isActive = state.adminCurrentUserId === chat.id;
        const unread = chat.unreadCount || 0;
        const lastMsg = chat.lastMessage || 'لا توجد رسائل';
        const time = chat.lastTime ? new Date(chat.lastTime) : null;
        const timeStr = time ? time.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' }) : '';
        
        return `
            <div class="admin-user-item ${isActive ? 'active' : ''}" data-user="${chat.id}" onclick="openAdminChat(${chat.id})">
                <div class="avatar">
                    <i class="fa-solid fa-user"></i>
                    <span class="online-dot"></span>
                </div>
                <div class="info">
                    <div class="name">${escapeHtml(chat.name || 'مستخدم')}</div>
                    <div class="last-msg">${escapeHtml(lastMsg)}</div>
                    <div class="time">${timeStr}</div>
                </div>
                ${unread > 0 ? `<span class="unread">${unread}</span>` : ''}
            </div>
        `;
    }).join('');
}

function openAdminChat(userId) {
    state.adminCurrentUserId = userId;
    document.querySelectorAll('.admin-user-item').forEach(function(el) { el.classList.remove('active'); });
    const target = document.querySelector('.admin-user-item[data-user="' + userId + '"]');
    if (target) target.classList.add('active');
    
    // البحث الآمن عن المستخدم (من state.users أو من خلال نص عنصر الـ DOM كبديل)
    let userName = 'مستخدم';
    if (state.users && Array.isArray(state.users)) {
        const user = state.users.find(function(u) { return u.id === userId; });
        if (user && user.name) userName = user.name;
    }
    if (userName === 'مستخدم' && target) {
        const nameElDom = target.querySelector('.name');
        if (nameElDom) userName = nameElDom.textContent;
    }

    const nameEl = $('adminChatUserName');
    const statusEl = $('adminChatUserStatus');
    const inputEl = $('adminChatInput');
    const sendBtn = $('adminSendBtn');
    
    if (nameEl) nameEl.textContent = userName;
    
    // ضبط الحالة الافتراضية بأمان لتفادي توقف الكود
    const statusColors = {
        online: 'var(--green)',
        away: 'var(--secondary)',
        offline: 'var(--text3)'
    };
    const statusTexts = {
        online: '🟢 متصل الآن',
        away: '🟡 غير نشط',
        offline: '⚪ غير متصل'
    };
    
    if (statusEl) {
        let userStatus = 'offline';
        if (typeof getUserStatus === 'function') {
            const statusObj = getUserStatus(userId);
            if (statusObj && statusObj.status) userStatus = statusObj.status;
        }
        statusEl.textContent = statusTexts[userStatus] || '⚪ غير متصل';
        statusEl.style.color = statusColors[userStatus] || 'var(--text3)';
    }
    
    if (inputEl) inputEl.disabled = false;
    if (sendBtn) sendBtn.disabled = false;
    
    const noChat = $('adminNoChatSelected');
    if (noChat) noChat.style.display = 'none';
    
    loadAdminChatMessages(userId);
    
    if (state.chatIntervals.admin) clearInterval(state.chatIntervals.admin);
    state.chatIntervals.admin = setInterval(function() {
        if (state.adminCurrentUserId) loadAdminChatMessages(state.adminCurrentUserId);
        loadAdminChats();
        if (state.adminCurrentUserId && typeof updateChatUserStatus === 'function') {
            updateChatUserStatus(state.adminCurrentUserId);
        }
    }, 5000);
}
function loadAdminUsers() {
    console.log("Admin users loading...");
    // ضع هنا كود جلب المستخدمين إذا وجد، أو اتركها فارغة لتمنع ظهور الخطأ
}
function loadAdminChatMessages(userId) {
    if (!userId) return;
    const adminId = state.user ? state.user.id : 0;
    callAPI('getChatMessages', { userId: userId, adminId: adminId }, 'GET').then(function(response) {
        if (response.success) renderAdminMessages(response.data);
    });
}

function renderAdminMessages(messages) {
    const container = $('adminMessagesContainer');
    if (!container) return;
    const noChat = $('adminNoChatSelected');
    if (noChat) noChat.style.display = 'none';
    
    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;">
                <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                لا توجد رسائل بعد
                <div style="font-size:12px;margin-top:8px;color:var(--text3);">قم بإرسال أول رسالة للمستخدم</div>
            </div>
        `;
        return;
    }
    
    let html = '';
    messages.forEach(function(msg) {
        const isAdmin = msg.sender === 'admin';
        const timeStr = formatMessageTime(msg.createdAt);
        const text = msg.message || '';
        const readStatus = getReadStatusHtml(msg);
        
        html += `
            <div class="message ${isAdmin ? 'sent' : 'received'}">
                <div class="message-content">
                    <div class="msg-text">${escapeHtml(text)}</div>
                    <div class="msg-footer">
                        <span class="msg-time ${isAdmin ? 'sent-time' : 'received-time'}">${timeStr}</span>
                        ${!isAdmin ? `<span class="msg-read-status">${readStatus}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
    container.scrollTop = container.scrollHeight;
}

function sendAdminMessage() {
    const input = $('adminChatInput');
    if (!input || !input.value.trim() || !state.adminCurrentUserId) {
        showToast('⚠️ اختر مستخدم واكتب رسالة');
        return;
    }
    
    const msg = input.value.trim();
    if (!validateMessage(msg)) return;
    
    input.value = '';
    input.disabled = true;
    
    const adminId = state.user ? state.user.id : 0;
    callAPI('sendChatMessage', { 
        userId: state.adminCurrentUserId, 
        adminId: adminId, 
        message: msg, 
        messageType: 'text',
        sender: 'admin'
    }, 'POST').then(function(response) {
        if (response.success) {
            loadAdminChatMessages(state.adminCurrentUserId);
            loadAdminChats();
            showToast('✅ تم إرسال الرد');
            if (state.isUserChatOpen) loadUserChatMessages();
        } else {
            showToast('❌ ' + (response.message || 'فشل إرسال الرسالة'));
            input.value = msg;
        }
    });
    input.disabled = false;
    input.focus();
}

function updateChatUserStatus(userId) {
    const user = state.users.find(function(u) { return u.id === userId; });
    if (!user) return;
    
    const statusEl = document.getElementById('adminChatUserStatus');
    if (!statusEl) return;
    
    const status = getUserStatus(userId);
    const statusColors = {
        online: 'var(--green)',
        away: 'var(--secondary)',
        offline: 'var(--text3)'
    };
    const statusTexts = {
        online: '🟢 متصل الآن',
        away: '🟡 غير نشط',
        offline: '⚪ غير متصل'
    };
    
    statusEl.textContent = statusTexts[status.status] || '⚪ غير متصل';
    statusEl.style.color = statusColors[status.status] || 'var(--text3)';
}

// ============================================================
// USER CHAT FUNCTIONS
// ============================================================

function openUserChat() {
    if (!requireLogin()) return;
    
    document.querySelectorAll('.settings-page.open, .product-detail-page.open, .auth-page.open, .favorites-page.open, .orders-page.open, .order-detail-page.open, .recharge-page.open')
        .forEach(function(page) {
            page.classList.remove('open');
        });
    
    const chatPage = $('chatPageUser');
    if (chatPage) {
        chatPage.classList.add('open');
        document.body.style.overflow = 'hidden';
        state.isUserChatOpen = true;
        updateBottomNavVisibility();
        resetUserChat();
        loadUserChatMessages();
        clearAllIntervals();
        state.chatIntervals.user = setInterval(loadUserChatMessages, 5000);
        setTimeout(function() {
            const input = $('userChatInput');
            if (input) input.focus();
        }, 300);
    }
}

function closeUserChat() {
    const chatPage = $('chatPageUser');
    if (chatPage) {
        chatPage.classList.remove('open');
        document.body.style.overflow = '';
        state.isUserChatOpen = false;
        updateBottomNavVisibility();
        if (state.chatIntervals.user) {
            clearInterval(state.chatIntervals.user);
            state.chatIntervals.user = null;
        }
    }
}

function resetUserChat() {
    const container = $('userMessagesContainer');
    if (!container) return;
    container.innerHTML = `
        <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;" id="emptyChatMessage">
            <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
            ابدأ محادثتك مع الدعم الفني
        </div>
        <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
            <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
            رسائل جديدة <span id="newMsgCount">0</span>
        </div>
    `;
    state.userMessages = [];
    state.lastMessageCount = 0;
}

function loadUserChatMessages() {
    if (!state.isLoggedIn || !state.user) return;
    
    callAPI('getUserChatMessages', { userId: state.user.id }, 'GET').then(function(response) {
        if (response.success) {
            const messages = response.data || [];
            const prevCount = state.userMessages.length;
            state.userMessages = messages;
            renderUserMessages(messages);
            
            if (messages.length > prevCount) {
                const newMessages = messages.slice(prevCount);
                const hasNewFromAdmin = newMessages.some(function(m) { return m.sender === 'admin'; });
                if (hasNewFromAdmin) {
                    const adminMsgs = newMessages.filter(function(m) { return m.sender === 'admin'; });
                    showNewMsgNotification(adminMsgs.length);
                    if (navigator.vibrate) navigator.vibrate([10, 30, 10]);
                    updateUnreadBadge(adminMsgs.length);
                }
            }
        }
    });
}

function renderUserMessages(messages) {
    const container = $('userMessagesContainer');
    if (!container) return;
    
    const emptyMsg = $('emptyChatMessage');
    if (emptyMsg) emptyMsg.remove();
    const notification = $('newMsgNotification');
    if (notification) notification.remove();
    
    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;" id="emptyChatMessage">
                <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                ابدأ محادثتك مع الدعم الفني
                <div style="font-size:12px;margin-top:8px;color:var(--text3);">سيرد فريق الدعم عليك في أقرب وقت</div>
            </div>
            <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
                <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
                رسائل جديدة <span id="newMsgCount">0</span>
            </div>
        `;
        return;
    }
    
    let html = '';
    messages.forEach(function(msg) {
        const isSent = msg.sender === 'user';
        const timeStr = formatMessageTime(msg.createdAt);
        const text = msg.message || '';
        const readStatus = getReadStatusHtml(msg);
        
        html += `
            <div class="message ${isSent ? 'sent' : 'received'}">
                <div class="message-content">
                    <div class="msg-text">${escapeHtml(text)}</div>
                    <div class="msg-footer">
                        <span class="msg-time ${isSent ? 'sent-time' : 'received-time'}">${timeStr}</span>
                        ${isSent ? `<span class="msg-read-status">${readStatus}</span>` : ''}
                    </div>
                </div>
            </div>
        `;
    });
    
    html += `
        <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
            <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
            رسائل جديدة <span id="newMsgCount">0</span>
        </div>
    `;
    
    container.innerHTML = html;
    scrollToBottomUser();
}

function scrollToBottomUser() {
    const container = $('userMessagesContainer');
    if (container) {
        container.scrollTop = container.scrollHeight;
        hideNewMsgNotification();
    }
}

function showNewMsgNotification(count) {
    const notification = $('newMsgNotification');
    if (!notification) return;
    const countEl = $('newMsgCount');
    if (countEl) countEl.textContent = count;
    notification.classList.add('show');
}

function hideNewMsgNotification() {
    const notification = $('newMsgNotification');
    if (notification) notification.classList.remove('show');
}

function updateUnreadBadge(count) {
    const badge = $('notifBadge');
    if (badge) {
        const current = parseInt(badge.textContent) || 0;
        badge.textContent = current + count;
        badge.style.display = 'flex';
    }
}

function sendUserMessage() {
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول');
        return;
    }
    
    const input = $('userChatInput');
    if (!input) return;
    
    const msg = input.value.trim();
    if (!validateMessage(msg)) {
        return;
    }
    
    input.value = '';
    input.disabled = true;
    
    callAPI('sendChatMessage', {
        userId: state.user.id,
        adminId: 1,
        message: msg,
        messageType: 'text',
        sender: 'user'
    }, 'POST').then(function(response) {
        if (response.success) {
            const newMsg = response.data || {
                id: Date.now(),
                message: msg,
                sender: 'user',
                createdAt: new Date().toISOString(),
                isRead: 0,
                readAt: null
            };
            state.userMessages.push(newMsg);
            renderUserMessages(state.userMessages);
            showToast('✅ تم إرسال رسالتك');
            if (navigator.vibrate) navigator.vibrate(10);
        } else {
            showToast('❌ ' + (response.message || 'فشل إرسال الرسالة'));
            input.value = msg;
        }
    });
    
    input.disabled = false;
    input.focus();
}

function validateMessage(message) {
    if (!message || message.trim() === '') {
        showToast('⚠️ لا يمكن إرسال رسالة فارغة');
        return false;
    }
    return true;
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatMessageTime(timestamp) {
    try {
        if (!timestamp) return new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
        const date = new Date(timestamp);
        return date.toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    } catch (e) {
        return new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    }
}

// ============================================================
// ✅ دالة وقت القراءة (فارغة - لا تعرض أي شيء)
// ============================================================
function getReadStatusHtml(msg) {
    return '';
}

// ============================================================
// ✅ دالة عرض رسائل المستخدم (بدون وقت القراءة)
// ============================================================
function renderUserMessages(messages) {
    const container = $('userMessagesContainer');
    if (!container) return;
    
    const emptyMsg = $('emptyChatMessage');
    if (emptyMsg) emptyMsg.remove();
    const notification = $('newMsgNotification');
    if (notification) notification.remove();
    
    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;" id="emptyChatMessage">
                <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                ابدأ محادثتك مع الدعم الفني
                <div style="font-size:12px;margin-top:8px;color:var(--text3);">سيرد فريق الدعم عليك في أقرب وقت</div>
            </div>
            <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
                <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
                رسائل جديدة <span id="newMsgCount">0</span>
            </div>
        `;
        return;
    }
    
    let html = '';
    messages.forEach(function(msg) {
        const isSent = msg.sender === 'user';
        const text = msg.message || '';
        
        // ✅ عرض الرسالة فقط بدون وقت وبدون وقت قراءة
        html += `
            <div class="message ${isSent ? 'sent' : 'received'}">
                <div class="message-content">
                    <div class="msg-text">${escapeHtml(text)}</div>
                </div>
            </div>
        `;
    });
    
    html += `
        <div class="new-msg-notification" id="newMsgNotification" onclick="scrollToBottomUser()">
            <i class="fa-solid fa-circle" style="font-size:8px;color:var(--green);"></i>
            رسائل جديدة <span id="newMsgCount">0</span>
        </div>
    `;
    
    container.innerHTML = html;
    scrollToBottomUser();
}

// ============================================================
// ✅ دالة عرض رسائل الأدمن (بدون وقت القراءة)
// ============================================================
function renderAdminMessages(messages) {
    const container = $('adminMessagesContainer');
    if (!container) return;
    const noChat = $('adminNoChatSelected');
    if (noChat) noChat.style.display = 'none';
    
    if (!messages || messages.length === 0) {
        container.innerHTML = `
            <div style="text-align:center;padding:40px 20px;color:var(--text3);font-size:13px;">
                <i class="fa-regular fa-comment-dots" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                لا توجد رسائل بعد
                <div style="font-size:12px;margin-top:8px;color:var(--text3);">قم بإرسال أول رسالة للمستخدم</div>
            </div>
        `;
        return;
    }
    
    let html = '';
    messages.forEach(function(msg) {
        const isAdmin = msg.sender === 'admin';
        const text = msg.message || '';
        
        // ✅ عرض الرسالة فقط بدون وقت وبدون وقت قراءة
        html += `
            <div class="message ${isAdmin ? 'sent' : 'received'}">
                <div class="message-content">
                    <div class="msg-text">${escapeHtml(text)}</div>
                </div>
            </div>
        `;
    });
    
    container.innerHTML = html;
    container.scrollTop = container.scrollHeight;
}

// ============================================================
// ✅ دالة clearAllIntervals (كما هي)
// ============================================================
function clearAllIntervals() {
    Object.keys(state.chatIntervals).forEach(function(key) {
        if (state.chatIntervals[key]) {
            clearInterval(state.chatIntervals[key]);
            state.chatIntervals[key] = null;
        }
    });
}
function renderCheckout() {
    const section = $('checkoutSection');
    if (state.cart.length === 0 || !state.isLoggedIn) {
        section.style.display = 'none';
        return;
    }
    section.style.display = 'block';
    const total = getCartTotal();
    const paymentOptions = state.payments.filter(function(p) { return p.enabled && !p.isRechargeOnly; });
    
    let transferHtml = '';
    if (state.selectedPayment === 'transfer' || state.selectedPayment === 'bank_transfer') {
        const transferPayments = state.payments.filter(function(p) { 
            return p.enabled && (p.id === 'transfer' || p.id === 'bank_transfer'); 
        });
        if (transferPayments.length > 0) {
            const pm = transferPayments[0];
            transferHtml = `
                <div class="transfer-details" style="background:var(--bg2);border-radius:10px;padding:12px;margin:10px 0;border:1px solid var(--border);">
                    <div style="font-weight:700;font-size:14px;margin-bottom:8px;color:var(--text2);">
                        <i class="fa-solid fa-building-columns"></i> تفاصيل التحويل البنكي
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">💰 المبلغ المطلوب تحويله</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">${total.toFixed(2)} د.ع</span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">🏦 رقم الحساب</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);direction:ltr;">${escapeHtml(pm.accountNumber || '7114152353')}</span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">👤 المستفيد</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">${escapeHtml(pm.beneficiary || 'الحوالة تتم عبر سوبر كي حصرا بعد قم بتحويل وارفاق صوره للتحويل  وانتظر موافقة والاضافة خلال دقائق')}</span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span class="detail-label" style="color:var(--text3);">📝 المبلغ الذي حولته</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">
                            <input type="number" id="transferAmount" value="${total.toFixed(2)}" step="0.01" min="0" 
                                   style="width:120px;padding:4px 8px;border:2px solid var(--border);border-radius:6px;font-size:13px;">
                        </span>
                    </div>
                    <div class="detail-row" style="display:flex;justify-content:space-between;padding:4px 0;font-size:13px;border-bottom:none;">
                        <span class="detail-label" style="color:var(--text3);">🖼️ صورة التحويل</span>
                        <span class="detail-value" style="font-weight:600;color:var(--text2);">
                            <input type="file" id="transferImageInput" accept="image/*" style="font-size:12px;padding:4px;">
                            <div id="transferPreview" style="width:80px;height:80px;border-radius:8px;overflow:hidden;background:var(--acSh);display:grid;place-items:center;margin-top:4px;font-size:24px;color:var(--text3);border:1px dashed var(--border);">
                                <i class="fa-solid fa-image"></i>
                            </div>
                        </span>
                    </div>
                </div>
            `;
        }
    }
    
    section.innerHTML = `
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:16px;margin-top:14px;box-shadow:var(--shadow);">
            <h3 style="font-size:16px;font-weight:800;color:var(--text2);margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-receipt" style="color:var(--primary);"></i> إتمام الطلب
            </h3>
            
            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                    <i class="fa-solid fa-location-dot"></i> العنوان <span style="color:var(--red);">*</span>
                </label>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <input type="text" id="checkoutAddress" placeholder="أدخل عنوانك بالكامل" required 
                           style="flex:1;min-width:150px;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;transition:border-color 0.3s;">
                    <button type="button" onclick="getCurrentLocation()" 
                            style="padding:10px 14px;border:none;border-radius:10px;background:var(--primary-light);color:#fff;font-weight:700;font-size:12px;cursor:pointer;white-space:nowrap;transition:all 0.3s;display:flex;align-items:center;gap:6px;">
                        <i class="fa-solid fa-location-crosshairs"></i> <span>تحديد موقعي</span>
                    </button>
                </div>
                <div id="locationStatus" style="font-size:11px;color:var(--text3);margin-top:4px;display:none;">
                    <i class="fa-solid fa-spinner fa-spin"></i> جاري تحديد الموقع...
                </div>
            </div>
            
            <div class="form-group" style="margin-bottom:14px;">
                <label style="display:block;font-size:13px;font-weight:700;color:var(--text2);margin-bottom:4px;">
                    <i class="fa-solid fa-phone"></i> رقم المستلم <span style="color:var(--red);">*</span>
                </label>
                <input type="tel" id="checkoutPhone" placeholder="07XX XXX XXXX" required 
                       style="width:100%;padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--bg2);color:var(--text);font-size:14px;outline:none;">
            </div>
            
            <div style="font-size:13px;font-weight:700;margin-bottom:8px;color:var(--text2);">
                <i class="fa-solid fa-credit-card"></i> اختر طريقة الدفع
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:8px 0;">
                ${paymentOptions.map(function(pm) {
                    const isActive = state.selectedPayment === pm.id;
                    return `
                        <div class="payment-option ${isActive ? 'active' : ''}" data-payment="${pm.id}" onclick="selectPayment('${pm.id}')" 
                             style="padding:8px 10px;border:1.5px solid ${isActive ? '#0f3d1c' : 'var(--border)'};border-radius:10px;text-align:center;cursor:pointer;background:${isActive ? 'rgba(15,61,28,0.06)' : 'var(--surface)'};transition:all 0.3s;box-shadow:${isActive ? '0 0 0 2px rgba(15,61,28,0.1)' : 'none'};">
                            <i class="${pm.icon}" style="font-size:18px;display:block;margin-bottom:2px;color:${isActive ? '#0f3d1c' : 'var(--text3)'};"></i>
                            <div class="pm-name" style="font-size:11px;font-weight:600;color:${isActive ? '#0f3d1c' : 'var(--text2)'};">${pm.name}</div>
                            ${isActive ? '<div style="font-size:9px;color:#0f3d1c;margin-top:2px;">✅</div>' : ''}
                        </div>
                    `;
                }).join('')}
            </div>
            ${transferHtml}
            
            <div style="display:flex;justify-content:space-between;padding:10px 0;border-top:1px solid var(--border);margin:10px 0;">
                <span style="font-weight:700;color:var(--text2);">${t('total')}</span>
                <span style="font-size:20px;font-weight:900;color:var(--primary);">${total.toFixed(2)} د.ع</span>
            </div>
            
            ${state.selectedPayment === 'electronic' && state.balance < total ? `
                <div style="background:rgba(231,76,60,0.1);border:2px solid var(--red);border-radius:10px;padding:12px;margin:10px 0;text-align:center;">
                    <p style="color:var(--red);font-weight:700;">⚠️ رصيدك غير كافي!</p>
                    <button onclick="openRechargePage()" style="padding:8px 20px;border:none;border-radius:8px;background:var(--primary-light);color:#fff;font-weight:700;cursor:pointer;margin-top:8px;">
                        <i class="fa-solid fa-plus"></i> شحن الرصيد
                    </button>
                </div>
            ` : ''}
            
            <div class="checkout-actions" style="display:flex;gap:10px;margin-top:14px;">
                <button class="btn-complete" onclick="completeOrder()" style="flex:1;padding:12px;border:none;border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;background:var(--primary-light);color:#fff;transition:all 0.3s;">
                    <i class="fa-solid fa-check"></i> إتمام الطلب
                </button>
                <button class="btn-cancel" onclick="clearCart()" style="flex:1;padding:12px;border:2px solid var(--border);border-radius:12px;font-size:14px;font-weight:700;cursor:pointer;background:var(--surface);color:var(--text2);transition:all 0.3s;">
                    إلغاء
                </button>
            </div>
        </div>
    `;
    
    const transferInput = $('transferImageInput');
    if (transferInput) {
        transferInput.addEventListener('change', function() {
            const preview = $('transferPreview');
            if (this.files && this.files[0] && preview) {
                if (this.files[0].size > 5 * 1024 * 1024) {
                    showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
                    this.value = '';
                    preview.innerHTML = '<i class="fa-solid fa-image"></i>';
                    preview.style.borderColor = 'var(--border)';
                    return;
                }
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
                    preview.style.borderColor = 'var(--primary)';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }
}
function selectPayment(id) {
    state.selectedPayment = id;
    
    $$('.payment-option').forEach(function(el) {
        const isActive = el.dataset.payment === id;
        el.classList.toggle('active', isActive);
        if (isActive) {
            el.style.borderColor = 'var(--primary)';
            el.style.background = 'var(--acSh)';
            el.style.boxShadow = '0 0 0 3px rgba(5,134,147,.1)';
            let statusEl = el.querySelector('.pm-status');
            if (!statusEl) {
                statusEl = document.createElement('div');
                statusEl.className = 'pm-status';
                statusEl.style.cssText = 'font-size:10px;color:var(--green);margin-top:4px;';
                el.appendChild(statusEl);
            }
            statusEl.textContent = '✅ مختار';
        } else {
            el.style.borderColor = 'var(--border)';
            el.style.background = 'var(--surface)';
            el.style.boxShadow = 'none';
            const statusEl = el.querySelector('.pm-status');
            if (statusEl) statusEl.remove();
        }
    });
    
    renderCheckout();
}

function completeOrder() {
    console.log('🔄 بدء عملية إتمام الطلب...');
    
    // التحقق من تسجيل الدخول
    if (!state.isLoggedIn || !state.user) {
        showToast('⚠️ يرجى تسجيل الدخول أولاً');
        openLoginPage();
        return;
    }
    
    // التحقق من السلة
    if (!state.cart || state.cart.length === 0) {
        showToast('⚠️ السلة فارغة');
        return;
    }
    
    // جلب البيانات من الحقول
    const addressInput = document.getElementById('checkoutAddress');
    const phoneInput = document.getElementById('checkoutPhone');
    
    const address = addressInput ? addressInput.value.trim() : '';
    const phone = phoneInput ? phoneInput.value.trim() : '';
    
    // التحقق من الحقول
    if (!address) {
        showToast('⚠️ الرجاء إدخال عنوان التوصيل');
        if (addressInput) {
            addressInput.style.borderColor = 'var(--red)';
            setTimeout(function() { addressInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        return;
    }
    
    if (!phone) {
        showToast('⚠️ الرجاء إدخال رقم الهاتف');
        if (phoneInput) {
            phoneInput.style.borderColor = 'var(--red)';
            setTimeout(function() { phoneInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        return;
    }
    
    // التحقق من صحة رقم الهاتف
    if (!/^07[0-9]{8,10}$/.test(phone)) {
        showToast('⚠️ رقم الهاتف يجب أن يبدأ بـ 07 ويتكون من 10-12 رقم');
        if (phoneInput) {
            phoneInput.style.borderColor = 'var(--red)';
            setTimeout(function() { phoneInput.style.borderColor = 'var(--border)'; }, 3000);
        }
        return;
    }
    
    const total = getCartTotal();
    const payment = state.selectedPayment || 'cash';
    
    // التحقق من الرصيد للدفع الإلكتروني
    if (payment === 'electronic' && state.balance < total) {
        showToast('⚠️ رصيدك غير كافي، يرجى شحن الرصيد');
        return;
    }
    
    // التحقق من صورة التحويل للدفع البنكي
    let transferImage = null;
    let transferAmount = 0;
    let accountNumber = '';
    let beneficiary = '';
    
    if (payment === 'transfer' || payment === 'bank_transfer') {
        const fileInput = document.getElementById('transferImageInput');
        if (!fileInput || !fileInput.files || !fileInput.files[0]) {
            showToast('⚠️ الرجاء رفع صورة التحويل');
            if (fileInput) {
                fileInput.style.borderColor = 'var(--red)';
                setTimeout(function() { fileInput.style.borderColor = 'var(--border)'; }, 3000);
            }
            return;
        }
        
        if (fileInput.files[0].size > 5 * 1024 * 1024) {
            showToast('⚠️ حجم الصورة كبير جداً (الحد الأقصى 5MB)');
            fileInput.value = '';
            const preview = document.getElementById('transferPreview');
            if (preview) {
                preview.innerHTML = '<i class="fa-solid fa-image"></i>';
                preview.style.borderColor = 'var(--border)';
            }
            return;
        }
        
        const amountInput = document.getElementById('transferAmount');
        transferAmount = parseFloat(amountInput ? amountInput.value : 0);
        if (transferAmount <= 0) {
            showToast('⚠️ الرجاء إدخال المبلغ المحول');
            return;
        }
        transferImage = fileInput.files[0];
        
        // جلب تفاصيل الحساب البنكي
        const transferPayments = state.payments.filter(function(p) { 
            return p.enabled && (p.id === 'transfer' || p.id === 'bank_transfer'); 
        });
        if (transferPayments.length > 0) {
            accountNumber = transferPayments[0].accountNumber || '';
            beneficiary = transferPayments[0].beneficiary || '';
        }
    }
    
    // تجهيز بيانات المنتجات
    const cartItems = state.cart.map(function(item) { 
        return { 
            id: item.id,
            name: item.name,
            price: item.price,
            qty: item.qty || 1,
            image_url: item.image_url || ''
        }; 
    });
    
    console.log('📦 المنتجات المرسلة:', cartItems);
    
    // إنشاء FormData
    const formData = new FormData();
    formData.append('userId', state.user.id);
    formData.append('address', address);
    formData.append('phone', phone);
    formData.append('payment', payment);
    formData.append('items', JSON.stringify(cartItems));
    formData.append('total', total);
    
    if (transferImage) {
        formData.append('transferImage', transferImage);
        formData.append('transferAmount', transferAmount);
        formData.append('accountNumber', accountNumber);
        formData.append('beneficiary', beneficiary);
    }
    
    // تعطيل الزر
    const completeBtn = document.querySelector('.btn-complete');
    if (completeBtn) {
        completeBtn.disabled = true;
        completeBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري المعالجة...';
        completeBtn.style.opacity = '0.6';
        completeBtn.style.pointerEvents = 'none';
    }
    
    showToast('⏳ جاري إنشاء الطلب...');
    
    // إرسال الطلب
    callAPI('addOrderWithTransfer', formData, 'POST')
        .then(function(response) {
            console.log('📨 استجابة الخادم:', response);
            
            // إعادة تفعيل الزر
            if (completeBtn) {
                completeBtn.disabled = false;
                completeBtn.innerHTML = '<i class="fa-solid fa-check"></i> إتمام الطلب';
                completeBtn.style.opacity = '1';
                completeBtn.style.pointerEvents = 'auto';
            }
            
            if (response.success) {
                // خصم الرصيد للدفع الإلكتروني
                if (payment === 'electronic') {
                    state.balance -= total;
                    if (state.user) state.user.balance = state.balance;
                    updateBalanceDisplay();
                }
                
                // عرض رسالة النجاح
                showOrderSuccess(response.data);
                
                // تفريغ السلة
                state.cart = [];
                renderCart();
                updateCartBadge();
                
                // تحديث البيانات
                loadAllData(true);
                
                showToast('✅ تم إنشاء الطلب بنجاح');
                
                // اهتزاز للتأكيد
                if (navigator.vibrate) navigator.vibrate([10, 50, 10]);
                
            } else {
                showToast('❌ ' + (response.message || 'فشل إنشاء الطلب'));
            }
        })
        .catch(function(error) {
            console.error('❌ خطأ في إنشاء الطلب:', error);
            
            // إعادة تفعيل الزر
            if (completeBtn) {
                completeBtn.disabled = false;
                completeBtn.innerHTML = '<i class="fa-solid fa-check"></i> إتمام الطلب';
                completeBtn.style.opacity = '1';
                completeBtn.style.pointerEvents = 'auto';
            }
            
            showToast('❌ حدث خطأ في الاتصال: ' + (error.message || 'يرجى المحاولة مرة أخرى'));
        });
}

function showOrderSuccess(order) {
    console.log('📦 عرض تفاصيل الطلب الناجح:', order);
    
    const overlay = document.getElementById('orderSuccessOverlay');
    if (!overlay) {
        console.warn('⚠️ عنصر orderSuccessOverlay غير موجود');
        return;
    }
    
    // تحديث محتوى البطاقة
    const orderNumber = document.getElementById('orderNumber');
    const orderDate = document.getElementById('orderDate');
    const orderAddress = document.getElementById('orderAddress');
    const orderPhone = document.getElementById('orderPhone');
    const orderPayment = document.getElementById('orderPayment');
    const orderProductsList = document.getElementById('orderProductsList');
    const orderTotal = document.getElementById('orderTotal');
    
    if (orderNumber) orderNumber.textContent = order.orderId || order.id || 'غير محدد';
    if (orderDate) orderDate.textContent = order.date || new Date().toLocaleDateString('ar-EG');
    if (orderAddress) orderAddress.textContent = order.address || 'غير محدد';
    if (orderPhone) orderPhone.textContent = order.phone || 'غير محدد';
    
    if (orderPayment) {
        const paymentNames = {
            'cash': 'الدفع عند الاستلام',
            'electronic': 'الدفع الإلكتروني',
            'transfer': 'تحويل بنكي',
            'bank_transfer': 'تحويل بنكي'
        };
        orderPayment.textContent = paymentNames[order.payment] || order.payment || 'غير محدد';
    }
    
    // عرض المنتجات
    let items = [];
    try {
        if (order.items) {
            if (typeof order.items === 'string') {
                items = JSON.parse(order.items);
            } else if (Array.isArray(order.items)) {
                items = order.items;
            } else if (typeof order.items === 'object') {
                items = Object.values(order.items);
            }
        }
    } catch (e) {
        console.warn('⚠️ خطأ في تحليل items:', e);
        items = [];
    }
    
    if (!Array.isArray(items)) {
        items = [];
    }
    
    if (orderProductsList) {
        if (items.length > 0) {
            orderProductsList.innerHTML = items.map(function(item) {
                const itemName = item.name || 'منتج';
                const itemQty = item.qty || 1;
                const itemPrice = parseFloat(item.price) || 0;
                const totalPrice = itemPrice * itemQty;
                return `
                    <div class="product-item" style="display:flex;justify-content:space-between;padding:6px 0;font-size:13px;border-bottom:1px dashed var(--border);">
                        <span>${itemName} × ${itemQty}</span>
                        <span style="font-weight:700;color:var(--primary);">${totalPrice.toFixed(2)} د.ع</span>
                    </div>
                `;
            }).join('');
        } else {
            orderProductsList.innerHTML = '<div style="text-align:center;padding:10px;color:var(--text3);font-size:13px;">⚠️ لا توجد منتجات</div>';
        }
    }
    
    // عرض المجموع
    if (orderTotal) {
        const span = orderTotal.querySelector('span:last-child');
        if (span) {
            const totalAmount = parseFloat(order.total) || 0;
            span.textContent = totalAmount.toFixed(2) + ' د.ع';
        }
    }
    
    // إظهار النافذة
    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
    
    if (navigator.vibrate) navigator.vibrate([10, 50, 10]);
}
// ============================================================
// GET CURRENT LOCATION
// ============================================================

function getCurrentLocation() {
    const statusEl = document.getElementById('locationStatus');
    const btnText = document.querySelector('#getLocationBtn span');
    const addressInput = document.getElementById('checkoutAddress');
    
    if (!navigator.geolocation) {
        showToast('⚠️ متصفحك لا يدعم تحديد الموقع');
        return;
    }
    
    if (statusEl) {
        statusEl.style.display = 'block';
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري تحديد الموقع...';
        statusEl.style.color = 'var(--text3)';
    }
    if (btnText) btnText.textContent = 'جاري التحديد...';
    
    navigator.geolocation.getCurrentPosition(
        function(position) {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            console.log('📍 الموقع:', lat, lng);
            getAddressFromCoords(lat, lng);
        },
        function(error) {
            console.warn('⚠️ خطأ في تحديد الموقع:', error);
            let errorMsg = '';
            switch(error.code) {
                case error.PERMISSION_DENIED:
                    errorMsg = '❌ تم رفض صلاحية الوصول إلى الموقع';
                    break;
                case error.POSITION_UNAVAILABLE:
                    errorMsg = '❌ معلومات الموقع غير متوفرة';
                    break;
                case error.TIMEOUT:
                    errorMsg = '⏰ انتهت مهلة تحديد الموقع';
                    break;
                default:
                    errorMsg = '❌ حدث خطأ في تحديد الموقع';
            }
            
            if (statusEl) {
                statusEl.style.display = 'block';
                statusEl.innerHTML = errorMsg;
                statusEl.style.color = 'var(--red)';
                setTimeout(function() {
                    statusEl.style.display = 'none';
                }, 4000);
            }
            if (btnText) btnText.textContent = 'تحديد موقعي';
            showToast(errorMsg);
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 60000
        }
    );
}

function getAddressFromCoords(lat, lng) {
    const statusEl = document.getElementById('locationStatus');
    const btnText = document.querySelector('#getLocationBtn span');
    const addressInput = document.getElementById('checkoutAddress');
    
    if (statusEl) {
        statusEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري تحويل الموقع إلى عنوان...';
        statusEl.style.color = 'var(--text3)';
    }
    
    const url = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1&accept-language=ar`;
    
    fetch(url)
        .then(function(response) {
            if (!response.ok) throw new Error('فشل في جلب العنوان');
            return response.json();
        })
        .then(function(data) {
            console.log('📍 بيانات العنوان:', data);
            
            if (data && data.display_name) {
                const address = data.display_name;
                if (addressInput) {
                    addressInput.value = address;
                    addressInput.style.borderColor = 'var(--green)';
                }
                
                if (statusEl) {
                    statusEl.innerHTML = '✅ تم تحديد الموقع بنجاح';
                    statusEl.style.color = 'var(--green)';
                    setTimeout(function() {
                        statusEl.style.display = 'none';
                        addressInput.style.borderColor = 'var(--border)';
                    }, 3000);
                }
                
                showToast('✅ تم تحديد موقعك بنجاح');
            } else {
                throw new Error('لا يوجد عنوان لهذا الموقع');
            }
            
            if (btnText) btnText.textContent = 'تحديد موقعي';
        })
        .catch(function(error) {
            console.warn('⚠️ خطأ في تحويل الموقع:', error);
            
            const coordsAddress = `📍 ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
            if (addressInput) {
                addressInput.value = coordsAddress;
            }
            
            if (statusEl) {
                statusEl.innerHTML = '✅ تم تحديد الإحداثيات';
                statusEl.style.color = 'var(--green)';
                setTimeout(function() {
                    statusEl.style.display = 'none';
                }, 3000);
            }
            
            if (btnText) btnText.textContent = 'تحديد موقعي';
            showToast('✅ تم تحديد الإحداثيات');
        });
}

// ============================================================
// SEARCH
// ============================================================

function performCategorySearch() {
    const searchTerm = $('categorySearchInput') ? $('categorySearchInput').value.trim().toLowerCase() : '';
    const minPrice = parseFloat($('minPriceInput') ? $('minPriceInput').value : 0) || 0;
    const maxPrice = parseFloat($('maxPriceInput') ? $('maxPriceInput').value : 0) || 0;
    
    const grid = $('categoriesGrid');
    if (!grid) return;
    
    if (!searchTerm && !minPrice && !maxPrice) {
        grid.innerHTML = state.categories.map(function(c) {
            const productCount = state.products.filter(function(p) { return p.category === c.id; }).length;
            return `
                <div class="category-grid-item" onclick="openCategoryProducts('${c.id}')">
                    <div class="cat-img">
                        ${c.icon_image_url ? 
                            '<img src="' + c.icon_image_url + '" loading="lazy" alt="' + c.name + '">' : 
                            '<i class="' + c.icon + '"></i>'
                        }
                    </div>
                    <span>${c.name}</span>
                    <div class="product-count">${productCount} منتج</div>
                </div>
            `;
        }).join('');
        return;
    }
    
    let matchedCategories = state.categories.filter(function(c) {
        const nameMatch = c.name.toLowerCase().includes(searchTerm) || 
                         c.nameEn.toLowerCase().includes(searchTerm);
        
        const categoryProducts = state.products.filter(function(p) { 
            return p.category === c.id; 
        });
        
        const productNameMatch = categoryProducts.some(function(p) {
            return p.name.toLowerCase().includes(searchTerm) || 
                   (p.nameEn && p.nameEn.toLowerCase().includes(searchTerm));
        });
        
        let priceMatch = true;
        if (minPrice > 0 || maxPrice > 0) {
            priceMatch = categoryProducts.some(function(p) {
                const price = p.price || 0;
                let match = true;
                if (minPrice > 0 && price < minPrice) match = false;
                if (maxPrice > 0 && price > maxPrice) match = false;
                return match;
            });
        }
        
        return (nameMatch || productNameMatch) && priceMatch;
    });
    
    if (matchedCategories.length === 0) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:60px 20px;color:var(--text3);">
                <i class="fa-regular fa-search" style="font-size:48px;display:block;margin-bottom:12px;opacity:0.3;"></i>
                <div style="font-size:16px;font-weight:600;">لا توجد نتائج</div>
                <div style="font-size:13px;margin-top:4px;">جرب كلمات بحث مختلفة أو عدل نطاق السعر</div>
            </div>
        `;
        return;
    }
    
    grid.innerHTML = matchedCategories.map(function(c) {
        const productCount = state.products.filter(function(p) { return p.category === c.id; }).length;
        return `
            <div class="category-grid-item" onclick="openCategoryProducts('${c.id}')">
                <div class="cat-img">
                    ${c.icon_image_url ? 
                        '<img src="' + c.icon_image_url + '" loading="lazy" alt="' + c.name + '">' : 
                        '<i class="' + c.icon + '"></i>'
                    }
                </div>
                <span>${c.name}</span>
                <div class="product-count">${productCount} منتج</div>
                ${searchTerm ? '<div style="font-size:10px;color:var(--primary);margin-top:2px;">🔍 مطابق للبحث</div>' : ''}
            </div>
        `;
    }).join('');
}

function setupSearch() {
    const catSearch = $('categorySearchInput');
    const minPriceInput = $('minPriceInput');
    const maxPriceInput = $('maxPriceInput');
    const clearBtn = $('clearSearchBtn');
    
    if (catSearch) {
        catSearch.addEventListener('input', function() {
            performCategorySearch();
            if (clearBtn) {
                clearBtn.style.display = this.value.trim() ? 'block' : 'none';
            }
        });
    }
    
    if (minPriceInput) {
        minPriceInput.addEventListener('input', performCategorySearch);
    }
    
    if (maxPriceInput) {
        maxPriceInput.addEventListener('input', performCategorySearch);
    }
}

// ============================================================
// UPDATE COUNTDOWN, DRAG SCROLL
// ============================================================

function updateCountdown() {
    const target = new Date();
    target.setHours(target.getHours() + 2);
    const dist = target.getTime() - Date.now();
    if (dist < 0) {
        ['cdHours', 'cdMinutes', 'cdSeconds'].forEach(function(id) {
            const el = $(id);
            if (el) el.textContent = '00';
        });
        return;
    }
    const hoursEl = $('cdHours');
    const minutesEl = $('cdMinutes');
    const secondsEl = $('cdSeconds');
    if (hoursEl) hoursEl.textContent = String(Math.floor(dist / (1000 * 60 * 60))).padStart(2, '0');
    if (minutesEl) minutesEl.textContent = String(Math.floor((dist % (1000 * 60 * 60)) / (1000 * 60))).padStart(2, '0');
    if (secondsEl) secondsEl.textContent = String(Math.floor((dist % (1000 * 60)) / 1000)).padStart(2, '0');
}

function initDragScroll() {
    const scrollContainers = document.querySelectorAll('.products-row');
    scrollContainers.forEach(function(container) {
        let isDown = false;
        let startX;
        let scrollLeft;
        container.addEventListener('mousedown', function(e) {
            isDown = true;
            container.style.cursor = 'grabbing';
            startX = e.pageX - container.offsetLeft;
            scrollLeft = container.scrollLeft;
        });
        container.addEventListener('mouseleave', function() {
            isDown = false;
            container.style.cursor = 'grab';
        });
        container.addEventListener('mouseup', function() {
            isDown = false;
            container.style.cursor = 'grab';
        });
        container.addEventListener('mousemove', function(e) {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - container.offsetLeft;
            const walk = (x - startX) * 2;
            container.scrollLeft = scrollLeft - walk;
        });
        let touchStartX = 0;
        let touchScrollLeft = 0;
        container.addEventListener('touchstart', function(e) {
            touchStartX = e.touches[0].pageX;
            touchScrollLeft = container.scrollLeft;
        }, { passive: true });
        container.addEventListener('touchmove', function(e) {
            const touchX = e.touches[0].pageX;
            const diff = (touchStartX - touchX);
            container.scrollLeft = touchScrollLeft + diff;
        }, { passive: true });
    });
}

// ============================================================
// NAVIGATION FUNCTIONS
// ============================================================

function switchPage(pageId) {
    $$('.page').forEach(function(p) { p.classList.remove('active'); });
    const target = $(pageId);
    if (target) target.classList.add('active');

    $$('.nav-item').forEach(function(btn) {
        btn.classList.toggle('active', btn.dataset.page === pageId);
    });

    state.currentPage = pageId;
    updateBottomNavVisibility();
    updateCartBadge();
    window.scrollTo({ top: 0, behavior: 'smooth' });
    if (pageId === 'page-cart') renderCheckout();
}

function updateBottomNavVisibility() {
    const bottomNav = $('bottomNav');
    const hiddenPages = ['favoritesPage', 'ordersPage', 'loginPage', 'registerPage',
        'productDetailPage', 'allProductsPage', 'categoryProductsPage',
        'rechargePage', 'adminPage', 'orderDetailPage', 'page-notifications',
        'page-chat-support', 'page-account-settings', 'chatPageUser', 'otpVerificationPage',
        'forgotPasswordPage'
    ];
    const isHidden = hiddenPages.some(function(id) {
        const el = $(id);
        return el && (el.classList.contains('open') || el.style.display === 'flex');
    }) || !!document.querySelector('.settings-page.open');

    if (bottomNav) {
        bottomNav.classList.toggle('hidden', isHidden);
    }
}

function openPage(pageId) {
    const page = $(pageId);
    if (!page) return;
    
    const siblings = page.parentElement.querySelectorAll('.settings-page.open, .product-detail-page.open, .auth-page.open, .chat-app-user.open, .favorites-page.open, .orders-page.open, .order-detail-page.open, .recharge-page.open');
    siblings.forEach(function(sibling) {
        if (sibling !== page) {
            sibling.classList.remove('open');
        }
    });
    
    page.classList.add('open');
    document.body.style.overflow = 'hidden';
    updateBottomNavVisibility();
    void page.offsetWidth;
}

function closePage(pageId) {
    const page = $(pageId);
    if (!page) return;
    page.classList.remove('open');
    document.body.style.overflow = '';
    updateBottomNavVisibility();
}

function closeSettingsPage(id) { closePage(id); }
function closeChatPage() { closePage('page-chat-support'); }
function closeCategoryProducts() { closePage('categoryProductsPage'); }
function closeAllProducts() { closePage('allProductsPage'); }
function closeRecharge() { closePage('rechargePage'); }
function closeFavorites() { closePage('favoritesPage'); }
function closeOrders() { closePage('ordersPage'); }

// ============================================================
// LIGHTBOX
// ============================================================

let currentLightboxImage = '';

function openLightbox(src) {
    const lightbox = $('lightbox');
    const img = $('lightboxImage');
    if (!lightbox || !img) return;
    currentLightboxImage = src;
    img.src = src;
    lightbox.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeLightbox() {
    const lightbox = $('lightbox');
    if (!lightbox) return;
    lightbox.classList.remove('active');
    document.body.style.overflow = '';
}

function downloadLightboxImage() {
    if (!currentLightboxImage) return;
    const link = document.createElement('a');
    link.href = currentLightboxImage;
    link.download = currentLightboxImage.split('/').pop() || 'image.jpg';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// ============================================================
// SITE SETTINGS
// ============================================================

function loadSiteData() {
    callAPI('getSiteData', null, 'GET').then(function(response) {
        if (response.success) {
            const site = response.data.site || {};
            updateSiteUI(site);
        }
    });
}

function updateSiteUI(site) {
    const logo = site.logo_url || '';
    
    const logoEl = $('siteLogo');
    if (logo) {
        if (logoEl) { 
            logoEl.src = logo; 
            logoEl.style.display = 'block'; 
            logoEl.style.width = '50px';
            logoEl.style.height = '50px';
            logoEl.style.borderRadius = '50%';
            logoEl.style.objectFit = 'cover';
        }
    } else {
        if (logoEl) {
            logoEl.style.display = 'flex';
            logoEl.innerHTML = '<i class="fa-solid fa-store" style="font-size:24px;color:#fff;"></i>';
        }
    }
    
    const titleEl = document.querySelector('title');
    if (titleEl) {
        titleEl.textContent = site.name || 'Tokmart';
    }
}

function saveSiteSettings() {
    const formData = new FormData();
    const nameInput = $('siteNameInput');
    const descInput = $('siteDescriptionInput');
    const logoInput = $('siteLogoInput');
    
    formData.append('name', nameInput ? nameInput.value.trim() : 'Tokmart');
    formData.append('description', descInput ? descInput.value.trim() : '');
    
    if (logoInput && logoInput.files[0]) {
        formData.append('logo', logoInput.files[0]);
    }
    
    callAPI('saveSiteSettings', formData, 'POST').then(function(response) {
        if (response.success) {
            showToast('✅ تم حفظ إعدادات الموقع');
            loadSiteData();
        } else {
            showToast('❌ ' + (response.message || 'فشل حفظ الإعدادات'));
        }
    });
}

// ============================================================
// SAVE GOOGLE OAUTH SETTINGS
// ============================================================

function saveGoogleOAuthSettings() {
    const data = {
        client_id: $('googleClientId') ? $('googleClientId').value.trim() : '',
        client_secret: $('googleClientSecret') ? $('googleClientSecret').value : '',
        enabled: $('googleEnabled') ? $('googleEnabled').checked : true
    };
    
    if (!data.client_id) {
        showToast('⚠️ الرجاء إدخال Client ID');
        return;
    }
    
    if (!data.client_secret) {
        showToast('⚠️ الرجاء إدخال Client Secret');
        return;
    }
    
    const btn = document.querySelector('#tab-settings .admin-settings-group:has(#googleClientId) .btn-save-cfg');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الحفظ...';
        btn.style.opacity = '0.6';
    }
    
    callAPI('saveGoogleOAuth', data, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-save"></i> حفظ إعدادات Google';
                btn.style.opacity = '1';
            }
            
            const statusEl = $('googleStatus');
            if (statusEl) {
                statusEl.style.display = 'block';
                if (response.success) {
                    statusEl.className = 'settings-status success';
                    statusEl.innerHTML = '✅ ' + response.message;
                    
                    setTimeout(function() {
                        googleLoginInitialized = false;
                        googleRegisterInitialized = false;
                        initGoogleLogin();
                    }, 500);
                } else {
                    statusEl.className = 'settings-status error';
                    statusEl.innerHTML = '❌ ' + (response.message || 'فشل حفظ الإعدادات');
                }
                
                setTimeout(function() {
                    statusEl.style.display = 'none';
                }, 5000);
            }
            
            if (response.success) {
                showToast('✅ تم حفظ إعدادات Google');
            } else {
                showToast('❌ ' + (response.message || 'فشل حفظ الإعدادات'));
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-save"></i> حفظ إعدادات Google';
                btn.style.opacity = '1';
            }
            showToast('❌ حدث خطأ في الاتصال');
        });
}

// ============================================================
// SAVE SMTP SETTINGS
// ============================================================

function saveSMTPSettings() {
    const data = {
        host: $('smtpHost') ? $('smtpHost').value.trim() : '',
        port: parseInt($('smtpPort') ? $('smtpPort').value : 465),
        username: $('smtpUsername') ? $('smtpUsername').value.trim() : '',
        password: $('smtpPassword') ? $('smtpPassword').value : '',
        encryption: $('smtpEncryption') ? $('smtpEncryption').value : 'ssl',
        from_email: $('smtpFromEmail') ? $('smtpFromEmail').value.trim() : '',
        from_name: $('smtpFromName') ? $('smtpFromName').value.trim() : 'Tokmart',
        enabled: $('smtpEnabled') ? $('smtpEnabled').checked : true
    };
    
    const btn = document.querySelector('#tab-settings .admin-settings-group:has(#smtpHost) .btn-save-cfg');
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الحفظ...';
        btn.style.opacity = '0.6';
    }
    
    callAPI('saveSMTPSettings', data, 'POST')
        .then(function(response) {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-save"></i> حفظ إعدادات البريد';
                btn.style.opacity = '1';
            }
            
            const statusEl = $('smtpStatus');
            if (statusEl) {
                statusEl.style.display = 'block';
                if (response.success) {
                    statusEl.className = 'settings-status success';
                    statusEl.innerHTML = '✅ ' + response.message;
                } else {
                    statusEl.className = 'settings-status error';
                    statusEl.innerHTML = '❌ ' + (response.message || 'فشل حفظ الإعدادات');
                }
                
                setTimeout(function() {
                    statusEl.style.display = 'none';
                }, 5000);
            }
            
            if (response.success) {
                showToast('✅ تم حفظ إعدادات البريد');
            } else {
                showToast('❌ ' + (response.message || 'فشل حفظ الإعدادات'));
            }
        })
        .catch(function() {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-save"></i> حفظ إعدادات البريد';
                btn.style.opacity = '1';
            }
            showToast('❌ حدث خطأ في الاتصال');
        });
}
function testEmailSettings() {
    const email = prompt('📧 أدخل البريد الإلكتروني لاختبار الإرسال:', 'test@example.com');
    if (!email || !email.includes('@')) {
        showToast('⚠️ الرجاء إدخال بريد إلكتروني صحيح');
        return;
    }

    const btn = document.querySelector('#tab-settings .admin-settings-group:has(#smtpHost) .btn-save-cfg');
    const testBtn = document.getElementById('testEmailBtn');

    if (testBtn) {
        testBtn.disabled = true;
        testBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> جاري الإرسال...';
        testBtn.style.opacity = '0.6';
    }

    callAPI('testEmail', { email: email }, 'POST')
        .then(function(response) {
            if (testBtn) {
                testBtn.disabled = false;
                testBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> اختبار الإرسال';
                testBtn.style.opacity = '1';
            }

            const statusEl = $('smtpStatus');
            if (statusEl) {
                statusEl.style.display = 'block';
                if (response.success) {
                    statusEl.className = 'settings-status success';
                    statusEl.innerHTML = '✅ ' + response.message;
                    showToast('✅ تم إرسال بريد اختباري بنجاح إلى ' + email);
                } else {
                    statusEl.className = 'settings-status error';
                    statusEl.innerHTML = '❌ ' + (response.message || 'فشل إرسال البريد');
                    showToast('❌ ' + (response.message || 'فشل إرسال البريد'));
                }
                setTimeout(function() {
                    statusEl.style.display = 'none';
                }, 8000);
            }
        })
        .catch(function() {
            if (testBtn) {
                testBtn.disabled = false;
                testBtn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> اختبار الإرسال';
                testBtn.style.opacity = '1';
            }
            showToast('❌ حدث خطأ في الاتصال');
        });
}
// ============================================================
// INIT
// ============================================================

function init() {
    // ===== Google OAuth Hidden Fields =====
    const googleClientId = '<?php echo htmlspecialchars(GOOGLE_CLIENT_ID); ?>';
    const googleEnabled = <?php echo json_encode(GOOGLE_ENABLED ?? true); ?>;
    const hiddenId = document.createElement('input');
    hiddenId.type = 'hidden';
    hiddenId.id = 'googleClientIdHidden';
    hiddenId.value = googleClientId;
    document.body.appendChild(hiddenId);
    
    const hiddenEnabled = document.createElement('input');
    hiddenEnabled.type = 'hidden';
    hiddenEnabled.id = 'googleEnabledHidden';
    hiddenEnabled.value = googleEnabled ? 'true' : 'false';
    document.body.appendChild(hiddenEnabled);
    
    // ===== استعادة حالة المستخدم =====
    try {
        const savedUser = localStorage.getItem('Tokmart_user');
        if (savedUser) {
            const user = JSON.parse(savedUser);
            state.isLoggedIn = true;
            state.user = user;
            state.balance = user.balance || 0;
            state.isAdmin = user.isAdmin || false;
            state.isVerified = user.isVerified || false;
        }
    } catch (e) {
        state.isLoggedIn = false;
        state.user = null;
    }
    
    updateBalanceDisplay();
    updateAccountUI();
    
    loadFavorites();
    loadSiteData();
    loadAllData(false);
    setupSearch();
    updateCountdown();
    setInterval(updateCountdown, 1000);

    setTimeout(initGoogleLogin, 300);
    setTimeout(initGoogleLogin, 1000);
    setTimeout(initGoogleLogin, 2000);

    $$('.nav-item').forEach(function(btn) {
        btn.addEventListener('click', function() { switchPage(this.dataset.page); });
    });

    $$('.back-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const page = this.closest('.settings-page, .product-detail-page, .all-products-page, .category-products-page, .favorites-page, .orders-page, .order-detail-page, .recharge-page, .admin-page, .auth-page, .chat-app-user');
            if (page) {
                if (page.classList.contains('chat-app-user')) {
                    closeUserChat();
                } else if (page.classList.contains('admin-page')) {
                    closeAdminPage();
                } else {
                    page.classList.remove('open');
                    document.body.style.overflow = '';
                    updateBottomNavVisibility();
                }
            }
        });
    });

    const loginBtn = $('loginBtn');
    if (loginBtn) {
        loginBtn.addEventListener('click', function() {
            if (state.isLoggedIn) {
                openLogoutSheet();
                return;
            }
            $$('.auth-buttons .auth-btn').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            openLoginPage();
        });
    }

    const registerBtn = $('registerBtn');
    if (registerBtn) {
        registerBtn.addEventListener('click', function() {
            if (state.isLoggedIn) { openLogoutSheet(); return; }
            $$('.auth-buttons .auth-btn').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            openRegisterPage();
        });
    }

    const switchToRegister = $('switchToRegister');
    if (switchToRegister) {
        switchToRegister.addEventListener('click', function(e) {
            e.preventDefault();
            closeLoginPage();
            setTimeout(openRegisterPage, 300);
        });
    }

    const switchToLogin = $('switchToLogin');
    if (switchToLogin) {
        switchToLogin.addEventListener('click', function(e) {
            e.preventDefault();
            closeRegisterPage();
            setTimeout(openLoginPage, 300);
        });
    }

    const notifBtn = $('notifBtn');
    if (notifBtn) {
        notifBtn.addEventListener('click', function() {
            if (!requireLogin()) return;
            openPage('page-notifications');
            renderNotifications();
        });
    }

    const chatSupport = $('chatSupportMenuItem');
    if (chatSupport) {
        chatSupport.addEventListener('click', function() {
            if (!requireLogin()) return;
            openUserChat();
        });
    }

    const favoritesMenuItem = $('favoritesMenuItem');
    if (favoritesMenuItem) {
        favoritesMenuItem.addEventListener('click', function() {
            openFavorites();
        });
    }

    const ordersMenuItem = $('ordersMenuItem');
    if (ordersMenuItem) {
        ordersMenuItem.addEventListener('click', function() {
            openOrders();
        });
    }

    const rechargeMenuItem = $('rechargeMenuItem');
    if (rechargeMenuItem) {
        rechargeMenuItem.addEventListener('click', function() {
            if (!requireLogin()) return;
            openRechargePage();
        });
    }

    const settingsMenuItem = $('accountSettingsMenuItem');
    if (settingsMenuItem) {
        settingsMenuItem.addEventListener('click', function() {
            if (!requireLogin()) return;
            openPage('page-account-settings');
        });
    }

    const addBalanceBtn = $('addBalanceBtn');
    if (addBalanceBtn) {
        addBalanceBtn.addEventListener('click', function() {
            if (!requireLogin()) return;
            openRechargePage();
        });
    }

    const adminBtn = $('adminBtn');
    if (adminBtn) {
        adminBtn.addEventListener('click', function() {
            if (!state.isAdmin || !state.isLoggedIn) { showToast('غير مصرح لك بالدخول'); return; }
            openAdminPage();
        });
    }

    const adminPanelMenuItem = $('adminPanelMenuItem');
    if (adminPanelMenuItem) {
        adminPanelMenuItem.addEventListener('click', function() {
            if (!state.isAdmin || !state.isLoggedIn) { showToast('غير مصرح لك بالدخول'); return; }
            openAdminPage();
        });
    }

    const closeAdminBtn = $('closeAdminBtn');
    if (closeAdminBtn) {
        closeAdminBtn.addEventListener('click', function() { closeAdminPage(); });
    }

    $$('.admin-tabs button').forEach(function(btn) {
        btn.addEventListener('click', function() {
            $$('.admin-tabs button').forEach(function(b) { b.classList.remove('active'); });
            this.classList.add('active');
            $$('.admin-tab-content').forEach(function(tc) { tc.classList.remove('active'); });
            const target = $(this.dataset.tab);
            if (target) target.classList.add('active');
            if (this.dataset.tab === 'tab-chats') loadAdminChats();
            if (this.dataset.tab === 'tab-recharges') renderAdminRecharges();
        });
    });

    const addProductBtn = $('addProductBtn');
    if (addProductBtn) {
        addProductBtn.addEventListener('click', function() { openProductForm(); });
    }

    const cancelProductForm = $('cancelProductForm');
    if (cancelProductForm) {
        cancelProductForm.addEventListener('click', closeProductForm);
    }

    const addCategoryBtn = $('addCategoryBtn');
    if (addCategoryBtn) {
        addCategoryBtn.addEventListener('click', function() { openCategoryForm(); });
    }

    const cancelCategoryForm = $('cancelCategoryForm');
    if (cancelCategoryForm) {
        cancelCategoryForm.addEventListener('click', closeCategoryForm);
    }

    const addPaymentBtn = $('addPaymentBtn');
    if (addPaymentBtn) {
        addPaymentBtn.addEventListener('click', function() { openPaymentForm(); });
    }

    const closeSuccessBtn = $('closeSuccessBtn');
    if (closeSuccessBtn) {
        closeSuccessBtn.addEventListener('click', function() {
            closePage('orderSuccessOverlay');
        });
    }

    document.addEventListener('click', function(e) {
        const card = e.target.closest('.product-card');
        if (card && !e.target.closest('.add-btn') && !e.target.closest('.favorite-icon')) {
            const id = parseInt(card.dataset.id);
            const product = state.products.find(function(p) { return p.id === id; });
            if (product) openProductDetail(product.id);
        }
    });

    const adminSearch = $('adminChatSearch');
    if (adminSearch) {
        adminSearch.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('.admin-user-item').forEach(function(el) {
                const name = el.querySelector('.name') ? el.querySelector('.name').textContent : '';
                el.style.display = name.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }

    const userInput = $('userChatInput');
    if (userInput) {
        userInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                sendUserMessage();
            }
        });
    }

    const iconInput = $('categoryIcon');
    if (iconInput) {
        iconInput.addEventListener('input', function() {
            const preview = $('categoryIconPreview');
            if (preview) {
                const iconClass = this.value.trim() || 'fa-solid fa-tag';
                preview.innerHTML = '<i class="' + iconClass + '"></i>';
            }
        });
    }

    const iconImageInput = $('categoryIconImage');
    if (iconImageInput) {
        iconImageInput.addEventListener('change', function() {
            const preview = $('categoryIconPreviewImg');
            if (this.files && this.files[0] && preview) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
                    preview.style.borderColor = 'var(--primary)';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    const bannerImageInput = $('categoryBannerImage');
    if (bannerImageInput) {
        bannerImageInput.addEventListener('change', function() {
            const preview = $('categoryBannerPreview');
            if (this.files && this.files[0] && preview) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" style="width:100%;height:100%;object-fit:cover;">';
                    preview.style.borderColor = 'var(--primary)';
                };
                reader.readAsDataURL(this.files[0]);
            }
        });
    }

    const languageMenuItem = $('languageMenuItem');
    if (languageMenuItem) {
        languageMenuItem.addEventListener('click', function() {
            if (!requireLogin()) return;
            openPage('page-language-settings');
        });
    }

    // ===== معاينة الصور المتعددة =====
    document.addEventListener('change', function(e) {
        if (e.target.id === 'productImages') {
            const preview = $('productImagesPreview');
            if (!preview) return;
            preview.innerHTML = '';
            const files = e.target.files;
            for (let i = 0; i < files.length; i++) {
                const reader = new FileReader();
                reader.onload = function(event) {
                    const div = document.createElement('div');
                    div.style.cssText = 'width:80px;height:80px;border-radius:8px;overflow:hidden;border:2px solid var(--border);position:relative;';
                    div.innerHTML = `
                        <img src="${event.target.result}" style="width:100%;height:100%;object-fit:cover;">
                        <span style="position:absolute;top:-4px;right:-4px;background:var(--red);color:#fff;width:20px;height:20px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;cursor:pointer;" onclick="this.parentElement.remove()">×</span>
                    `;
                    preview.appendChild(div);
                };
                reader.readAsDataURL(files[i]);
            }
        }
    });

    // ===== إغلاق بطاقة تفاصيل الشحن بالضغط على الخلفية =====
    document.addEventListener('click', function(e) {
        const overlay = document.querySelector('.recharge-detail-overlay');
        if (overlay && e.target === overlay) {
            closeRechargeDetail();
        }
    });

    updateAccountUI();
    updateBalanceDisplay();
    updateNotifBadge();
    updateBottomNavVisibility();

    console.log('🚀 Tokmart Ultra-Smooth Native App Ready!');
    console.log('👑 Admin: admin@Tokmart.com / admin123');
    console.log('👤 مستخدم: ahmed@Tokmart.com / user123');
    console.log('👤 مستخدم: sara@Tokmart.com / user123');
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
document.addEventListener('touchmove', function(e) {
    const scroll = document.getElementById('scrollArea');

    if (scroll) {
        scroll.style.overflowY = 'auto';
    }
}, { passive: true });

</script>

</body>
</html>                 