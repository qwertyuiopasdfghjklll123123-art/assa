<?php
/**
 * إعدادات منصة واصل.
 * ما تحتاج تعدّل هذا الملف: صفحة التنصيب (install.php) تحفظ الإعدادات بملف data/config.json
 * وتقدر تغيّرها بأي وقت من نفس الصفحة (بعد تسجيل الدخول كأدمن).
 */

define('DATA_DIR', __DIR__ . '/data');
define('ENGINE_DIR', __DIR__ . '/wasel-engine');

$__cfg = [];
if (is_file(DATA_DIR . '/config.json')) {
    $__cfg = json_decode((string)file_get_contents(DATA_DIR . '/config.json'), true) ?: [];
}

// هل اكتمل التنصيب؟
define('APP_INSTALLED', !empty($__cfg['installed']));

// تسجيل الدخول بكوكل (اختياري) - Client ID من Google Cloud Console
define('GOOGLE_CLIENT_ID', (string)($__cfg['GOOGLE_CLIENT_ID'] ?? ''));

// محرك واتساب (Node.js)
define('ENGINE_URL', rtrim((string)($__cfg['ENGINE_URL'] ?? 'http://127.0.0.1:3000'), '/'));
define('ENGINE_SECRET', (string)($__cfg['ENGINE_SECRET'] ?? ''));
define('ENGINE_PORT', (int)($__cfg['ENGINE_PORT'] ?? 3000));

// مفتاح رابط المهام المجدولة https://example.com/cron?key=...
define('CRON_KEY', (string)($__cfg['CRON_KEY'] ?? ''));

// حساب أدمن ثابت يدخل برقم + رمز ثابت (اتركهم فارغين لتعطيل الميزة)
define('MASTER_ADMIN_PHONE', (string)($__cfg['MASTER_ADMIN_PHONE'] ?? ''));
define('MASTER_ADMIN_CODE', (string)($__cfg['MASTER_ADMIN_CODE'] ?? ''));

define('APP_TIMEZONE', (string)($__cfg['TIMEZONE'] ?? 'Asia/Baghdad'));
unset($__cfg);
