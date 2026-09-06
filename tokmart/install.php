<?php
/**
 * First-run setup wizard: collects the MySQL connection, imports schema.sql,
 * and creates the store's admin account. Locks itself out once config/config.php
 * exists, so it can never be used to re-run against an already-installed site.
 */

require_once __DIR__ . '/includes/bootstrap.php';

$configFile = __DIR__ . '/config/config.php';
if (file_exists($configFile)) {
    header('Location: ' . APP_BASE_PATH . '/index.php');
    exit;
}

$errors = [];
$done = false;

$form = [
    'db_host' => $_POST['db_host'] ?? 'localhost',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? '',
    'db_user' => $_POST['db_user'] ?? '',
    'db_pass' => $_POST['db_pass'] ?? '',
    'site_name' => $_POST['site_name'] ?? 'Tokmart',
    'admin_name' => $_POST['admin_name'] ?? '',
    'admin_email' => $_POST['admin_email'] ?? '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($form['db_host']);
    $dbPort = (int)$form['db_port'] ?: 3306;
    $dbName = trim($form['db_name']);
    $dbUser = trim($form['db_user']);
    $dbPass = $form['db_pass'];
    $siteName = trim($form['site_name']) ?: 'Tokmart';
    $adminName = trim($form['admin_name']);
    $adminEmail = trim($form['admin_email']);
    $adminPassword = $_POST['admin_password'] ?? '';
    $adminPasswordConfirm = $_POST['admin_password_confirm'] ?? '';

    if ($dbHost === '' || $dbName === '' || $dbUser === '') {
        $errors[] = 'الرجاء تعبئة جميع بيانات الاتصال بقاعدة البيانات (الخادم، الاسم، المستخدم)';
    }
    if ($adminName === '' || $adminEmail === '') {
        $errors[] = 'الرجاء تعبئة اسم المدير وبريده الإلكتروني';
    }
    if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'البريد الإلكتروني للمدير غير صحيح';
    }
    if (strlen($adminPassword) < 6) {
        $errors[] = 'كلمة مرور المدير يجب أن تكون 6 أحرف على الأقل';
    }
    if ($adminPassword !== $adminPasswordConfirm) {
        $errors[] = 'كلمة المرور وتأكيدها غير متطابقين';
    }

    $pdo = null;
    if (empty($errors)) {
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $safeDbName = str_replace('`', '', $dbName);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$safeDbName}`");
        } catch (Throwable $e) {
            $errors[] = 'تعذر الاتصال بخادم قاعدة البيانات: ' . $e->getMessage();
            $pdo = null;
        }
    }

    if ($pdo && empty($errors)) {
        try {
            $schemaSql = file_get_contents(__DIR__ . '/database/schema.sql');
            // Strip full-line comments before splitting on ";" - otherwise a
            // comment line sitting right before a statement (with no blank
            // statement/semicolon between them) gets fused onto it, and the
            // combined chunk starts with "--" so the whole statement (not just
            // the comment) is wrongly skipped as "just a comment".
            $lines = array_filter(explode("\n", $schemaSql), function ($line) {
                return !str_starts_with(trim($line), '--');
            });
            $statements = array_filter(array_map('trim', explode(';', implode("\n", $lines))));
            foreach ($statements as $statement) {
                if ($statement === '') continue;
                $pdo->exec($statement);
            }
        } catch (Throwable $e) {
            $errors[] = 'فشل إنشاء جداول قاعدة البيانات: ' . $e->getMessage();
        }
    }

    if ($pdo && empty($errors)) {
        try {
            $userCount = (int)$pdo->query("SELECT COUNT(*) as c FROM users")->fetch(PDO::FETCH_ASSOC)['c'];

            if ($userCount === 0) {
                $hash = password_hash($adminPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, balance, isAdmin, adminType, isVerified, lastActivity)
                                       VALUES (:name, :email, :password, 0, 1, 'super_admin', 1, NOW())");
                $stmt->execute([':name' => $adminName, ':email' => $adminEmail, ':password' => $hash]);
                $adminId = (int)$pdo->lastInsertId();

                $pdo->exec("INSERT INTO categories (id, name, nameEn, icon) VALUES
                    ('general', 'عام', 'General', 'fa-solid fa-tag'),
                    ('electronics', 'إلكترونيات', 'Electronics', 'fa-solid fa-microchip'),
                    ('fashion', 'أزياء', 'Fashion', 'fa-solid fa-vest')");

                $pdo->exec("INSERT INTO payments (id, name, nameEn, icon, enabled, isRechargeOnly, accountNumber, beneficiary) VALUES
                    ('cash', 'الدفع عند الاستلام', 'Cash on Delivery', 'fa-solid fa-hand-holding-dollar', 1, 0, '', ''),
                    ('electronic', 'الدفع الإلكتروني', 'Electronic Payment', 'fa-solid fa-credit-card', 1, 0, '', ''),
                    ('bank_transfer', 'تحويل بنكي', 'Bank Transfer', 'fa-solid fa-building-columns', 1, 1, '', '')");

                $siteData = json_encode(['name' => $siteName], JSON_UNESCAPED_UNICODE);
                $stmt = $pdo->prepare("INSERT INTO settings (`key`, value) VALUES ('site', :value)");
                $stmt->execute([':value' => $siteData]);
            } else {
                $existingAdmin = $pdo->query("SELECT id FROM users WHERE email = " . $pdo->quote($adminEmail))->fetch(PDO::FETCH_ASSOC);
                $adminId = $existingAdmin ? (int)$existingAdmin['id'] : 1;
            }

            $configContent = "<?php\n" .
                "// Generated by install.php on " . date('Y-m-d H:i:s') . "\n" .
                "return [\n" .
                "    'db' => [\n" .
                "        'host' => " . var_export($dbHost, true) . ",\n" .
                "        'port' => " . var_export($dbPort, true) . ",\n" .
                "        'name' => " . var_export($dbName, true) . ",\n" .
                "        'user' => " . var_export($dbUser, true) . ",\n" .
                "        'pass' => " . var_export($dbPass, true) . ",\n" .
                "        'charset' => 'utf8mb4',\n" .
                "    ],\n" .
                "    'app_secret' => " . var_export(bin2hex(random_bytes(32)), true) . ",\n" .
                "    'base_path' => " . var_export(guessBasePath(), true) . ",\n" .
                "];\n";

            if (!is_dir(__DIR__ . '/config')) {
                mkdir(__DIR__ . '/config', 0755, true);
            }
            if (file_put_contents($configFile, $configContent) === false) {
                $errors[] = 'تعذر كتابة ملف الإعدادات config/config.php - تحقق من صلاحيات الكتابة';
            } else {
                $_SESSION['user_id'] = $adminId;
                $_SESSION['user_name'] = $adminName;
                $_SESSION['is_admin'] = 1;
                $_SESSION['is_verified'] = 1;
                $done = true;
            }
        } catch (Throwable $e) {
            $errors[] = 'فشل إعداد حساب المدير: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعداد المتجر - Tokmart</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        * { font-family: 'IBM Plex Sans Arabic', system-ui, sans-serif; margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #0f3d1c; --primary-light: #1b5e2b; --bg: #F5F9FA; --surface: #FFFFFF;
            --text: #193E45; --text3: #4a7553; --border: rgba(15,61,28,.12); --red: #E74C3C;
            --shadow: 0 8px 32px rgba(15,61,28,.10); --radius: 16px;
        }
        body { background: var(--bg); color: var(--text); min-height: 100vh; padding: 30px 16px; }
        .wizard { max-width: 640px; margin: 0 auto; }
        .wizard-header { text-align: center; margin-bottom: 24px; }
        .wizard-header i { font-size: 42px; color: var(--primary); margin-bottom: 10px; display: block; }
        .wizard-header h1 { font-size: 22px; font-weight: 800; }
        .wizard-header p { font-size: 13px; color: var(--text3); margin-top: 6px; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 24px; margin-bottom: 18px; }
        .card h3 { font-size: 15px; font-weight: 800; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; color: var(--primary); }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 13px; font-weight: 700; margin-bottom: 5px; }
        .form-group input {
            width: 100%; padding: 11px 14px; border: 2px solid var(--border); border-radius: 10px;
            background: #F7FAFA; color: var(--text); font-size: 14px; font-family: inherit; outline: none;
        }
        .form-group input:focus { border-color: var(--primary); }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .errors { background: rgba(231,76,60,.1); border: 1px solid var(--red); color: var(--red); border-radius: 10px; padding: 14px; margin-bottom: 18px; font-size: 13px; }
        .errors ul { padding-inline-start: 18px; }
        .btn-submit {
            width: 100%; padding: 14px; border: none; border-radius: 12px; background: var(--primary-light);
            color: #fff; font-weight: 700; font-size: 16px; cursor: pointer; font-family: inherit;
        }
        .success-box { text-align: center; padding: 20px 0; }
        .success-box i { font-size: 56px; color: #2ECC71; margin-bottom: 14px; display: block; }
        .success-box h2 { font-size: 20px; margin-bottom: 8px; }
        .success-box p { color: var(--text3); font-size: 14px; margin-bottom: 20px; }
        .success-box a { display: inline-block; padding: 12px 28px; background: var(--primary-light); color: #fff; text-decoration: none; border-radius: 10px; font-weight: 700; }
    </style>
</head>
<body>
<div class="wizard">
    <div class="wizard-header">
        <i class="fa-solid fa-store"></i>
        <h1>إعداد متجر Tokmart</h1>
        <p>خطوة واحدة لإعداد قاعدة البيانات وحساب المدير</p>
    </div>

    <?php if ($done): ?>
    <div class="card">
        <div class="success-box">
            <i class="fa-solid fa-circle-check"></i>
            <h2>تم الإعداد بنجاح!</h2>
            <p>تم إنشاء قاعدة البيانات وحساب المدير الخاص بك. يمكنك الآن الدخول إلى لوحة التحكم.</p>
            <a href="<?php echo APP_BASE_PATH; ?>/admin">الدخول إلى لوحة التحكم <i class="fa-solid fa-arrow-left"></i></a>
        </div>
    </div>
    <?php else: ?>

    <?php if (!empty($errors)): ?>
    <div class="errors">
        <ul><?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>

    <form method="POST">
        <div class="card">
            <h3><i class="fa-solid fa-database"></i> بيانات الاتصال بقاعدة البيانات (MySQL)</h3>
            <div class="form-row">
                <div class="form-group"><label>الخادم (Host)</label><input type="text" name="db_host" value="<?php echo htmlspecialchars($form['db_host']); ?>" required></div>
                <div class="form-group"><label>المنفذ (Port)</label><input type="number" name="db_port" value="<?php echo htmlspecialchars($form['db_port']); ?>" required></div>
            </div>
            <div class="form-group"><label>اسم قاعدة البيانات</label><input type="text" name="db_name" value="<?php echo htmlspecialchars($form['db_name']); ?>" required></div>
            <div class="form-row">
                <div class="form-group"><label>مستخدم قاعدة البيانات</label><input type="text" name="db_user" value="<?php echo htmlspecialchars($form['db_user']); ?>" required></div>
                <div class="form-group"><label>كلمة المرور</label><input type="password" name="db_pass"></div>
            </div>
        </div>

        <div class="card">
            <h3><i class="fa-solid fa-store"></i> اسم المتجر</h3>
            <div class="form-group"><input type="text" name="site_name" value="<?php echo htmlspecialchars($form['site_name']); ?>" placeholder="Tokmart"></div>
        </div>

        <div class="card">
            <h3><i class="fa-solid fa-user-shield"></i> حساب المدير</h3>
            <div class="form-group"><label>الاسم</label><input type="text" name="admin_name" value="<?php echo htmlspecialchars($form['admin_name']); ?>" required></div>
            <div class="form-group"><label>البريد الإلكتروني</label><input type="email" name="admin_email" value="<?php echo htmlspecialchars($form['admin_email']); ?>" required></div>
            <div class="form-row">
                <div class="form-group"><label>كلمة المرور</label><input type="password" name="admin_password" required minlength="6"></div>
                <div class="form-group"><label>تأكيد كلمة المرور</label><input type="password" name="admin_password_confirm" required minlength="6"></div>
            </div>
        </div>

        <button type="submit" class="btn-submit"><i class="fa-solid fa-check"></i> إتمام الإعداد</button>
    </form>
    <?php endif; ?>
</div>
</body>
</html>
