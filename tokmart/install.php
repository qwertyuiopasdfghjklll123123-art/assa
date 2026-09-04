<?php
/**
 * First-run installer: creates config/config.php, imports the MySQL schema,
 * seeds default categories/payment methods, and creates the first admin account.
 * Locks itself once config/config.php exists.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configFile = __DIR__ . '/config/config.php';
$alreadyInstalled = file_exists($configFile);

$errors = [];
$success = false;

if (!$alreadyInstalled && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? '');
    $dbPort = intval($_POST['db_port'] ?? 3306);
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = (string)($_POST['db_pass'] ?? '');

    $siteName = trim($_POST['site_name'] ?? 'Tokmart');

    $adminName = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPhone = trim($_POST['admin_phone'] ?? '');
    $adminPassword = (string)($_POST['admin_password'] ?? '');
    $adminPasswordConfirm = (string)($_POST['admin_password_confirm'] ?? '');

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'الرجاء تعبئة بيانات الاتصال بقاعدة البيانات (الخادم، اسم القاعدة، المستخدم).';
    }
    if ($adminName === '' || $adminEmail === '' || $adminPhone === '') {
        $errors[] = 'الرجاء تعبئة بيانات حساب المدير كاملة.';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني للمدير غير صحيح.';
    }
    if (!preg_match('/^07[0-9]{8,10}$/', $adminPhone)) {
        $errors[] = 'رقم هاتف المدير يجب أن يبدأ بـ 07 ويتكون من 10-12 رقماً.';
    }
    if (strlen($adminPassword) < 8) {
        $errors[] = 'كلمة مرور المدير يجب أن تكون 8 أحرف على الأقل.';
    }
    if ($adminPassword !== $adminPasswordConfirm) {
        $errors[] = 'كلمتا المرور غير متطابقتين.';
    }

    $pdo = null;
    if (empty($errors)) {
        try {
            $dsn = "mysql:host=$dbHost;port=$dbPort;charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbName`");
        } catch (Throwable $e) {
            $errors[] = 'فشل الاتصال بقاعدة البيانات: ' . $e->getMessage();
        }
    }

    if (empty($errors) && $pdo) {
        try {
            $schemaSql = file_get_contents(__DIR__ . '/database/schema.sql');
            foreach (array_filter(array_map('trim', explode(';', $schemaSql))) as $statement) {
                if ($statement === '' || str_starts_with($statement, '--') || str_starts_with(strtoupper($statement), 'SET ')) {
                    continue;
                }
                $pdo->exec($statement);
            }
        } catch (Throwable $e) {
            $errors[] = 'فشل إنشاء الجداول: ' . $e->getMessage();
        }
    }

    if (empty($errors) && $pdo) {
        try {
            $existingAdmin = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
            if ($existingAdmin > 0) {
                $errors[] = 'قاعدة البيانات تحتوي بيانات مستخدمين بالفعل. إذا كنت تقوم بترحيل بيانات موجودة، أنشئ config/config.php يدوياً بدلاً من هذا المعالج.';
            }
        } catch (Throwable $e) {
            $errors[] = 'تعذر التحقق من جدول المستخدمين: ' . $e->getMessage();
        }
    }

    if (empty($errors) && $pdo) {
        try {
            $pdo->beginTransaction();

            $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password, phone, balance, isAdmin, adminType, isVerified, lastActivity)
                 VALUES (:name, :email, :password, :phone, 0, 1, 'super_admin', 1, NOW())"
            );
            $stmt->execute([
                ':name' => $adminName, ':email' => $adminEmail,
                ':password' => $hash, ':phone' => $adminPhone,
            ]);

            $siteData = json_encode([
                'name' => $siteName ?: 'Tokmart',
                'description' => 'متجر إلكتروني متكامل يوفر أفضل المنتجات بأفضل الأسعار',
                'slogan' => '🛍️ متجرك الإلكتروني المفضل',
                'logo' => '',
                'privacy_policy' => 'سياسة الخصوصية الخاصة بمتجر ' . ($siteName ?: 'Tokmart') . '...',
                'terms_conditions' => 'الشروط والأحكام الخاصة بمتجر ' . ($siteName ?: 'Tokmart') . '...',
                'app_version' => '2.0.0',
            ], JSON_UNESCAPED_UNICODE);
            $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('site', :v)")->execute([':v' => $siteData]);

            $googleData = json_encode(['client_id' => '', 'client_secret' => '', 'enabled' => false]);
            $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('google_oauth', :v)")->execute([':v' => $googleData]);

            $smtpData = json_encode([
                'host' => '', 'port' => 465, 'username' => '', 'password' => '',
                'encryption' => 'ssl', 'from_email' => '', 'from_name' => $siteName ?: 'Tokmart', 'enabled' => false,
            ]);
            $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('smtp', :v)")->execute([':v' => $smtpData]);

            $pdo->exec("INSERT INTO payments (id, name, nameEn, icon, enabled, isRechargeOnly, accountNumber, beneficiary) VALUES
                ('cash', 'الدفع عند الاستلام', 'Cash on Delivery', 'fa-solid fa-hand-holding-dollar', 1, 0, '', ''),
                ('electronic', 'الدفع الإلكتروني', 'Electronic Payment', 'fa-solid fa-credit-card', 1, 0, '', ''),
                ('bank_transfer', 'تحويل بنكي', 'Bank Transfer', 'fa-solid fa-university', 1, 1, '', '')");

            $pdo->exec("INSERT INTO categories (id, name, nameEn, icon, sortOrder) VALUES
                ('general', 'عام', 'General', 'fa-solid fa-tag', 0),
                ('electronics', 'إلكترونيات', 'Electronics', 'fa-solid fa-microchip', 1),
                ('fashion', 'أزياء', 'Fashion', 'fa-solid fa-vest', 2)");

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'فشل تجهيز البيانات الافتراضية: ' . $e->getMessage();
        }
    }

    if (empty($errors)) {
        $appSecret = bin2hex(random_bytes(32));
        // install.php always lives at the true application root, so this is a
        // reliable, one-time way to learn the base URL path the app is served
        // under (e.g. '' at a domain root, '/tokmart' in a subdirectory) -
        // far more robust than re-deriving it from SCRIPT_FILENAME on every
        // request, which can misbehave under symlinks, proxies, or unusual
        // server configs and silently breaks every asset/API URL on the site.
        $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
        $configContent = "<?php\n\nreturn [\n"
            . "    'db' => [\n"
            . "        'host'    => " . var_export($dbHost, true) . ",\n"
            . "        'port'    => " . var_export($dbPort, true) . ",\n"
            . "        'name'    => " . var_export($dbName, true) . ",\n"
            . "        'user'    => " . var_export($dbUser, true) . ",\n"
            . "        'pass'    => " . var_export($dbPass, true) . ",\n"
            . "        'charset' => 'utf8mb4',\n"
            . "    ],\n"
            . "    'base_path'  => " . var_export($basePath, true) . ",\n"
            . "    'app_secret' => " . var_export($appSecret, true) . ",\n"
            . "];\n";

        if (!is_dir(__DIR__ . '/config')) {
            mkdir(__DIR__ . '/config', 0755, true);
        }

        if (file_put_contents($configFile, $configContent) === false) {
            $errors[] = 'تعذر كتابة ملف الإعدادات config/config.php. تحقق من صلاحيات الكتابة على المجلد.';
        } else {
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تثبيت Tokmart</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700;800&family=Tajawal:wght@400;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'IBM Plex Sans Arabic', 'Tajawal', system-ui, sans-serif; background: #F5F9FA; color: #193E45; direction: rtl; padding: 40px 16px; }
.wrap { max-width: 640px; margin: 0 auto; }
.logo { text-align: center; margin-bottom: 24px; }
.logo i { font-size: 40px; color: #0f3d1c; }
.logo h1 { font-size: 22px; margin-top: 8px; }
.box { background: #fff; border-radius: 16px; padding: 30px; box-shadow: 0 8px 32px rgba(15,61,28,.1); margin-bottom: 20px; }
.box h2 { font-size: 16px; margin-bottom: 16px; color: #0f3d1c; display: flex; align-items: center; gap: 8px; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width:560px) { .row { grid-template-columns: 1fr; } }
.field { margin-bottom: 14px; }
.field label { display: block; font-size: 12px; font-weight: 700; color: #4a7553; margin-bottom: 5px; }
.field input { width: 100%; padding: 10px 12px; border: 2px solid rgba(15,61,28,.12); border-radius: 8px; background: #EDF4F5; font-size: 13px; outline: none; }
.field input:focus { border-color: #0f3d1c; background: #fff; }
.hint { font-size: 11px; color: #4a7553; margin-top: 4px; }
button { width: 100%; padding: 14px; border: none; border-radius: 10px; background: #0f3d1c; color: #fff; font-weight: 800; font-size: 15px; cursor: pointer; }
button:hover { background: #1b5e2b; }
.alert { padding: 14px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
.alert-error { background: rgba(231,76,60,.1); color: #c0392b; border: 1px solid rgba(231,76,60,.3); }
.alert-success { background: rgba(46,204,113,.1); color: #1e8449; border: 1px solid rgba(46,204,113,.3); }
.alert ul { margin: 6px 18px 0; }
a.btn-link { display: inline-block; margin-top: 14px; color: #0f3d1c; font-weight: 700; text-decoration: none; }
</style>
</head>
<body>
<div class="wrap">
    <div class="logo"><i class="fa-solid fa-store"></i><h1>تثبيت متجر Tokmart</h1></div>

    <?php if ($alreadyInstalled): ?>
        <div class="box">
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> الموقع مثبّت بالفعل.</div>
            <p style="font-size:13px;color:#4a7553;margin-bottom:14px;">
                إذا كنت تريد إعادة التثبيت من جديد (سيُعاد إنشاء الجداول والبيانات)، احذف الملف
                <code>config/config.php</code> يدوياً عبر الاستضافة ثم أعد تحميل هذه الصفحة.
            </p>
            <a class="btn-link" href="index.php"><i class="fa-solid fa-arrow-left"></i> الذهاب إلى الموقع</a>
        </div>
    <?php elseif ($success): ?>
        <div class="box">
            <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> تم التثبيت بنجاح! تم إنشاء حساب المدير وقاعدة البيانات.</div>
            <a class="btn-link" href="admin/index.php"><i class="fa-solid fa-gauge"></i> الدخول إلى لوحة التحكم</a><br>
            <a class="btn-link" href="index.php"><i class="fa-solid fa-store"></i> زيارة المتجر</a>
        </div>
    <?php else: ?>
        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <strong><i class="fa-solid fa-triangle-exclamation"></i> تعذر إتمام التثبيت:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="box">
                <h2><i class="fa-solid fa-database"></i> بيانات قاعدة البيانات (MySQL)</h2>
                <div class="row">
                    <div class="field"><label>الخادم (Host)</label><input type="text" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>" required></div>
                    <div class="field"><label>المنفذ (Port)</label><input type="number" name="db_port" value="<?php echo htmlspecialchars($_POST['db_port'] ?? '3306'); ?>"></div>
                </div>
                <div class="field"><label>اسم قاعدة البيانات</label><input type="text" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>" required></div>
                <div class="row">
                    <div class="field"><label>مستخدم قاعدة البيانات</label><input type="text" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" required></div>
                    <div class="field"><label>كلمة المرور</label><input type="password" name="db_pass"></div>
                </div>
                <p class="hint">سيتم إنشاء قاعدة البيانات تلقائياً إن لم تكن موجودة (يتطلب صلاحية CREATE)، وإنشاء جميع الجداول اللازمة.</p>
            </div>

            <div class="box">
                <h2><i class="fa-solid fa-shop"></i> اسم المتجر</h2>
                <div class="field"><label>اسم الموقع</label><input type="text" name="site_name" value="<?php echo htmlspecialchars($_POST['site_name'] ?? 'Tokmart'); ?>"></div>
            </div>

            <div class="box">
                <h2><i class="fa-solid fa-user-shield"></i> حساب المدير الرئيسي</h2>
                <div class="row">
                    <div class="field"><label>الاسم الكامل</label><input type="text" name="admin_name" value="<?php echo htmlspecialchars($_POST['admin_name'] ?? ''); ?>" required></div>
                    <div class="field"><label>البريد الإلكتروني</label><input type="email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>" required></div>
                </div>
                <div class="field"><label>رقم الهاتف (يبدأ بـ 07)</label><input type="text" name="admin_phone" value="<?php echo htmlspecialchars($_POST['admin_phone'] ?? ''); ?>" placeholder="07700000000" required></div>
                <div class="row">
                    <div class="field"><label>كلمة المرور (8 أحرف على الأقل)</label><input type="password" name="admin_password" required></div>
                    <div class="field"><label>تأكيد كلمة المرور</label><input type="password" name="admin_password_confirm" required></div>
                </div>
            </div>

            <button type="submit"><i class="fa-solid fa-download"></i> تثبيت الآن</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
