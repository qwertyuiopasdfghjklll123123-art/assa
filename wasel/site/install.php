<?php
/**
 * صفحة تنصيب وإعداد منصة واصل:
 *  - قبل التنصيب: تفحص السيرفر، وتنشئ حساب الأدمن، وتولّد المفاتيح السرية تلقائياً.
 *  - بعد التنصيب (للأدمن فقط): فحص النظام، تعديل الإعدادات، وتعليمات محرك واتساب.
 */

require __DIR__ . '/config.php';
date_default_timezone_set(APP_TIMEZONE);
require __DIR__ . '/lib/store.php';
require __DIR__ . '/lib/engine.php';
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/pages.php';
require __DIR__ . '/lib/tasks.php';
require __DIR__ . '/lib/wallet.php';
require __DIR__ . '/lib/backup.php';
require __DIR__ . '/lib/api.php';

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0755, true);
}
start_session();

// ---------------------------------------------------------------- أدوات

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

function read_cfg(): array
{
    $p = DATA_DIR . '/config.json';
    return is_file($p) ? (json_decode((string)file_get_contents($p), true) ?: []) : [];
}

function write_cfg(array $cfg): bool
{
    $p = DATA_DIR . '/config.json';
    $ok = @file_put_contents($p . '.tmp', json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
    return $ok && @rename($p . '.tmp', $p);
}

function write_engine_cfg(array $cfg): bool
{
    if (!is_dir(ENGINE_DIR)) {
        return false;
    }
    $data = [
        'ENGINE_SECRET' => $cfg['ENGINE_SECRET'],
        'PHP_HOOK_URL' => base_url() . '/engine_hook',
        'TIMEZONE' => $cfg['TIMEZONE'] ?? 'Asia/Baghdad',
        'PORT' => $cfg['ENGINE_PORT'] ?? 3000,
    ];
    return @file_put_contents(ENGINE_DIR . '/config.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false;
}

/** المسار النسبي من مجلد المستخدم الرئيسي (اللي تطلبه صفحة Setup Node.js App) */
function home_relative(string $abs): string
{
    if (preg_match('#^/home/[^/]+/(.+)$#', $abs, $m)) {
        return $m[1];
    }
    return $abs;
}

function exec_available(): bool
{
    if (!function_exists('exec')) {
        return false;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    return !in_array('exec', $disabled, true);
}

function http_get(string $url, int $timeout = 6): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => false]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, (string)$body, $err];
}

/** فحوصات السيرفر: [عنوان، حالة ok|warn|bad، شرح] */
function system_checks(): array
{
    $c = [];
    $c[] = ['نسخة PHP ' . PHP_VERSION, version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'bad',
        'لازم 8.0 أو أحدث - غيّرها من DirectAdmin ← Select PHP Version'];
    foreach (['curl' => 'bad', 'mbstring' => 'bad', 'json' => 'bad', 'openssl' => 'warn', 'zip' => 'warn', 'fileinfo' => 'warn'] as $ext => $level) {
        $c[] = ["امتداد PHP: $ext", extension_loaded($ext) ? 'ok' : $level, 'فعّله من Select PHP Version ← Extensions' . ($ext === 'zip' ? ' (يحتاجه رفع ملفات Excel)' : '')];
    }
    $testFile = DATA_DIR . '/.write_test';
    $writable = @file_put_contents($testFile, 'x') !== false;
    @unlink($testFile);
    $c[] = ['مجلد data قابل للكتابة', $writable ? 'ok' : 'bad', 'غيّر صلاحية مجلد data إلى 755 (أو 775) من مدير الملفات'];
    $c[] = ['مجلد wasel-engine موجود', is_dir(ENGINE_DIR) ? 'ok' : 'warn', 'لازم يكون مجلد wasel-engine داخل نفس المجلد مع index.php'];
    if (is_dir(ENGINE_DIR)) {
        $c[] = ['wasel-engine قابل للكتابة', is_writable(ENGINE_DIR) ? 'ok' : 'warn', 'غيّر صلاحيته إلى 755 حتى يحفظ المحرك جلسات واتساب'];
    }
    [$code, $body] = http_get(base_url() . '/manifest.json');
    $rewriteOk = $code === 200 && strpos($body, 'short_name') !== false;
    $c[] = ['ملف .htaccess وإعادة التوجيه', $rewriteOk ? 'ok' : 'warn',
        'تأكد أن ملف .htaccess انرفع (ملف مخفي) - بدونه روابط الموقع ما تشتغل' . ($code ? " (رد: $code)" : '')];
    return $c;
}

function engine_check(): array
{
    if (ENGINE_SECRET === '') {
        return ['bad', 'لم يُنصّب بعد'];
    }
    [$code, $body, $err] = http_get(ENGINE_URL . '/', 6);
    if ($code === 0) {
        return ['bad', 'لا يوجد رد من ' . ENGINE_URL . ($err ? " ($err)" : '')];
    }
    if (strpos($body, 'wasel-engine') === false) {
        return ['bad', "الرابط يرد ($code) لكن مو محرك واصل - تأكد من رابط التطبيق وأنك ضغطت Start"];
    }
    $st = engine_json('GET', '/health', null, 6);
    if (($st['_status'] ?? 0) === 401) {
        return ['warn', 'المحرك شغّال لكن المفتاح السري مختلف - اضغط «إعادة كتابة إعدادات المحرك» ثم Restart للتطبيق'];
    }
    if (!empty($st['ok']) && (int)($st['version'] ?? 1) < REQUIRED_ENGINE_VERSION) {
        return ['warn', 'المحرك شغّال لكن نسخته قديمة - ارفع مجلد wasel-engine الجديد (عدا مجلد data) واضغط Restart من Setup Node.js App'];
    }
    if (!empty($st['ok'])) {
        return ['ok', 'المحرك شغّال ومتصل (' . (int)$st['accounts'] . ' حساب واتساب)'];
    }
    return ['warn', 'رد غير متوقع من المحرك'];
}

// ---------------------------------------------------------------- معالجة الطلبات

$isAdmin = APP_INSTALLED && !empty($_SESSION['is_admin']) && uid() && user_by_id(uid());
$errors = [];
$notice = '';
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['install_csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $errors[] = 'انتهت صلاحية الصفحة، حدّثها وحاول مرة ثانية';
    $action = '';
}
if (empty($_SESSION['install_csrf'])) {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(16));
}

if ($action === 'install' && !APP_INSTALLED) {
    $siteName = trim((string)($_POST['site_name'] ?? ''));
    $name = trim((string)($_POST['admin_name'] ?? ''));
    $email = strtolower(trim((string)($_POST['admin_email'] ?? '')));
    $pass = (string)($_POST['admin_password'] ?? '');
    if ($name === '') {
        $errors[] = 'اكتب اسم الأدمن';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'اكتب بريد إلكتروني صحيح';
    }
    if (mb_strlen($pass) < 6) {
        $errors[] = 'كلمة المرور لازم تكون 6 أحرف أو أكثر';
    }
    if (!is_writable(DATA_DIR)) {
        $errors[] = 'مجلد data غير قابل للكتابة - غيّر صلاحيته إلى 755';
    }
    if (!$errors) {
        $cfg = [
            'installed' => true,
            'GOOGLE_CLIENT_ID' => trim((string)($_POST['google_client_id'] ?? '')),
            'ENGINE_URL' => rtrim(trim((string)($_POST['engine_url'] ?? '')) ?: base_url() . '/engine', '/'),
            'ENGINE_SECRET' => bin2hex(random_bytes(24)),
            'ENGINE_PORT' => random_int(20000, 39999),
            'CRON_KEY' => bin2hex(random_bytes(12)),
            'MASTER_ADMIN_PHONE' => '',
            'MASTER_ADMIN_CODE' => '',
            'TIMEZONE' => 'Asia/Baghdad',
        ];
        if (!write_cfg($cfg)) {
            $errors[] = 'تعذر حفظ data/config.json - تأكد من صلاحيات مجلد data';
        } else {
            write_engine_cfg($cfg);
            $user = user_by('email', $email) ?? create_user([
                'email' => $email, 'name' => clean_text($name), 'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            ], true);
            // الأدمن عنده اشتراك دائم (بدون فترة تجربة)
            update_user((int)$user['id'], ['is_admin' => true, 'plan_active' => true, 'plan_ends_at' => null]);
            update_settings(array_filter([
                'site_name' => $siteName !== '' ? clean_text(mb_substr($siteName, 0, 60)) : null,
                'admin_email' => $email,
                'whatsapp_login' => true,
            ], fn($v) => $v !== null));
            ensure_default_payment_methods();
            set_session_user(user_by_id((int)$user['id']));
            header('Location: install.php?done=1');
            exit;
        }
    }
}

if ($isAdmin && $action === 'save') {
    $cfg = read_cfg();
    $cfg['GOOGLE_CLIENT_ID'] = trim((string)($_POST['google_client_id'] ?? ''));
    $cfg['ENGINE_URL'] = rtrim(trim((string)($_POST['engine_url'] ?? '')), '/') ?: base_url() . '/engine';
    $cfg['MASTER_ADMIN_PHONE'] = digits_only($_POST['master_phone'] ?? '');
    $cfg['MASTER_ADMIN_CODE'] = trim((string)($_POST['master_code'] ?? ''));
    if (write_cfg($cfg)) {
        header('Location: install.php?saved=1');
        exit;
    }
    $errors[] = 'تعذر الحفظ';
}

if ($isAdmin && $action === 'engine_cfg') {
    $notice = write_engine_cfg(read_cfg())
        ? 'تمت كتابة wasel-engine/config.json - الآن اضغط Restart للتطبيق من صفحة Setup Node.js App'
        : 'تعذرت الكتابة داخل مجلد wasel-engine - تأكد من وجوده وصلاحياته';
}

if ($isAdmin && $action === 'start_engine') {
    if (!exec_available()) {
        $notice = 'الاستضافة لا تسمح بتشغيل الأوامر من PHP - شغّل المحرك من صفحة Setup Node.js App';
    } else {
        write_engine_cfg(read_cfg());
        @exec('/bin/bash ' . escapeshellarg(ENGINE_DIR . '/start.sh') . ' > /dev/null 2>&1 &');
        sleep(4);
        $notice = 'تم إرسال أمر التشغيل - انتظر دقيقة ثم حدّث الصفحة';
    }
}

if ($action === 'admin_login' && APP_INSTALLED && !$isAdmin) {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $u = user_by('email', $email);
    if (!login_throttle()) {
        $errors[] = 'محاولات كثيرة، حاول بعد 15 دقيقة';
    } elseif (!$u || empty($u['is_admin']) || empty($u['password_hash']) || !password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
        login_throttle(true);
        log_login($u['id'] ?? null, 'password', false, $email);
        $errors[] = 'البريد أو كلمة المرور غير صحيحة';
    } else {
        set_session_user($u);
        log_login((int)$u['id'], 'password', true, $email);
        header('Location: install.php');
        exit;
    }
}

if (isset($_GET['done'])) {
    $notice = 'تم التنصيب بنجاح! حسابك صار أدمن المنصة. كمّل الخطوات بالأسفل لتشغيل واتساب.';
}
if (isset($_GET['saved'])) {
    $notice = 'تم حفظ الإعدادات';
}

$cfg = read_cfg();
$checks = system_checks();
$hasBad = (bool)array_filter($checks, fn($c) => $c[1] === 'bad');
$engineRel = home_relative(ENGINE_DIR);
$engineUrlDefault = base_url() . '/engine';
$host = $_SERVER['HTTP_HOST'] ?? 'example.com';
$basePath = trim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$appUrlPath = ($basePath ? $basePath . '/' : '') . 'engine';
$phpBin = PHP_BINDIR . '/php';
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>تنصيب منصة واصل</title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  :root { --bg:#F5F7FA; --card:#fff; --ink:#1A2E35; --muted:#4A6A78; --gold:#058693; --gold2:#046C76; --line:rgba(5,134,147,.15);
          --ok:#15803d; --okbg:#dcfce7; --warn:#a16207; --warnbg:#fef9c3; --bad:#b91c1c; --badbg:#fee2e2; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); font-family:'IBM Plex Sans Arabic',system-ui,sans-serif; line-height:1.7; }
  .wrap { max-width:760px; margin:0 auto; padding:24px 16px 60px; }
  h1 { font-size:26px; margin:6px 0 2px; color:var(--gold2); }
  h2 { font-size:18px; margin:0 0 12px; display:flex; align-items:center; gap:8px; }
  .num { width:28px; height:28px; border-radius:50%; background:var(--gold); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:14px; flex:none; }
  .sub { color:var(--muted); margin:0 0 20px; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:16px; padding:20px; margin-bottom:16px; box-shadow:0 4px 18px rgba(5,134,147,.05); }
  .row { display:flex; justify-content:space-between; gap:10px; padding:8px 0; border-bottom:1px dashed var(--line); align-items:flex-start; }
  .row:last-child { border-bottom:0; }
  .row small { display:block; color:var(--muted); font-size:12px; }
  .pill { padding:2px 10px; border-radius:20px; font-size:12px; font-weight:700; white-space:nowrap; }
  .ok { background:var(--okbg); color:var(--ok); } .warn { background:var(--warnbg); color:var(--warn); } .bad { background:var(--badbg); color:var(--bad); }
  label { display:block; font-weight:600; margin:12px 0 4px; font-size:14px; }
  input { width:100%; padding:11px 12px; border:1px solid var(--line); border-radius:10px; font:inherit; background:#F8FAFB; }
  input[dir=ltr] { text-align:left; }
  .hint { font-size:12px; color:var(--muted); margin-top:3px; }
  button, .btn { display:inline-block; border:0; border-radius:12px; padding:12px 20px; font:inherit; font-weight:700; cursor:pointer;
           background:linear-gradient(180deg,var(--gold),var(--gold2)); color:#fff; text-decoration:none; margin-top:14px; }
  .btn.alt, button.alt { background:#fff; color:var(--gold2); border:1px solid var(--gold); }
  .msg { padding:12px 14px; border-radius:12px; margin-bottom:16px; font-weight:600; }
  .kv { background:#F1F5F7; border-radius:10px; padding:10px 12px; margin:6px 0; }
  .kv b { color:var(--muted); font-weight:600; font-size:13px; display:block; }
  code { direction:ltr; unicode-bidi:embed; display:inline-block; background:#0f2a33; color:#d5f5f8; padding:3px 8px; border-radius:6px; font-size:13px; word-break:break-all; user-select:all; }
  ol { padding-inline-start:20px; margin:0; } li { margin-bottom:8px; }
  .actions form { display:inline; }
</style>
</head>
<body>
<div class="wrap">
  <h1>تنصيب منصة واصل</h1>
  <p class="sub"><?= APP_INSTALLED ? 'فحص النظام والإعدادات (للأدمن)' : 'خطوة وحدة وتصير المنصة جاهزة' ?></p>

  <?php foreach ($errors as $e): ?><div class="msg bad"><?= h($e) ?></div><?php endforeach; ?>
  <?php if ($notice): ?><div class="msg ok"><?= h($notice) ?></div><?php endif; ?>

  <?php if (APP_INSTALLED && !$isAdmin): ?>
    <div class="card">
      <h2>المنصة منصّبة</h2>
      <p>هذه الصفحة للأدمن فقط. سجّل دخولك بكوكل (ببريد الأدمن) من الموقع، أو استخدم دخول الطوارئ بكلمة المرور اللي حددتها وقت التنصيب:</p>
      <form method="post">
        <input type="hidden" name="action" value="admin_login">
        <input type="hidden" name="csrf" value="<?= h($_SESSION['install_csrf']) ?>">
        <label>بريد الأدمن</label><input name="email" type="email" dir="ltr" required>
        <label>كلمة المرور</label><input name="password" type="password" dir="ltr" required>
        <button type="submit">دخول الأدمن</button>
        <a class="btn alt" href="./">الذهاب للموقع</a>
      </form>
    </div>
  <?php else: ?>

  <div class="card">
    <h2><span class="num">1</span> فحص السيرفر</h2>
    <?php foreach ($checks as [$title, $st, $help]): ?>
      <div class="row">
        <div><?= h($title) ?><?php if ($st !== 'ok'): ?><small><?= h($help) ?></small><?php endif; ?></div>
        <span class="pill <?= $st ?>"><?= $st === 'ok' ? 'سليم' : ($st === 'warn' ? 'تنبيه' : 'مشكلة') ?></span>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!APP_INSTALLED): ?>
  <form method="post" class="card">
    <h2><span class="num">2</span> إنشاء حساب الأدمن</h2>
    <input type="hidden" name="action" value="install">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['install_csrf']) ?>">
    <label>اسم الموقع</label>
    <input name="site_name" placeholder="مثال: واصل" value="<?= h($_POST['site_name'] ?? '') ?>">
    <label>اسمك</label>
    <input name="admin_name" required value="<?= h($_POST['admin_name'] ?? '') ?>">
    <label>بريد الأدمن (حساب كوكل اللي راح تدخل بيه)</label>
    <input name="admin_email" type="email" dir="ltr" required value="<?= h($_POST['admin_email'] ?? '') ?>">
    <label>كلمة مرور الطوارئ</label>
    <input name="admin_password" type="password" dir="ltr" minlength="6" required>
    <div class="hint">للدخول من صفحة install.php لو صار خلل بكوكل - الدخول العادي يكون بزر كوكل</div>
    <label>Google Client ID (اختياري - تقدر تضيفه بعدين)</label>
    <input name="google_client_id" dir="ltr" placeholder="xxxx.apps.googleusercontent.com" value="<?= h($_POST['google_client_id'] ?? '') ?>">
    <label>رابط محرك واتساب</label>
    <input name="engine_url" dir="ltr" value="<?= h($_POST['engine_url'] ?? $engineUrlDefault) ?>">
    <div class="hint">اتركه كما هو - هذا الرابط اللي راح تحطه بصفحة Setup Node.js App</div>
    <button type="submit" <?= $hasBad ? 'disabled style="opacity:.5"' : '' ?>>تنصيب الآن</button>
    <?php if ($hasBad): ?><div class="hint" style="color:var(--bad)">صلّح المشاكل باللون الأحمر بالأعلى أولاً ثم حدّث الصفحة</div><?php endif; ?>
  </form>
  <?php else: ?>

  <?php [$est, $emsg] = engine_check(); ?>
  <div class="card">
    <h2><span class="num">2</span> محرك واتساب (Setup Node.js App)</h2>
    <div class="row"><div>حالة المحرك<small><?= h($emsg) ?></small></div><span class="pill <?= $est ?>"><?= $est === 'ok' ? 'شغّال' : ($est === 'warn' ? 'تنبيه' : 'متوقف') ?></span></div>
    <?php if ($est !== 'ok'): ?>
    <p style="margin-top:14px">بلوحة DirectAdmin ابحث عن <b>Setup Node.js App</b> ← <b>Create Application</b> واكتب هذي القيم بالضبط:</p>
    <div class="kv"><b>Node.js version</b>أعلى نسخة موجودة (20 أو أحدث)</div>
    <div class="kv"><b>Application mode</b><code>Production</code></div>
    <div class="kv"><b>Application root</b><code><?= h($engineRel) ?></code></div>
    <div class="kv"><b>Application URL</b><code><?= h($host) ?></code> ثم بالمربع اللي جنبه: <code><?= h($appUrlPath) ?></code></div>
    <div class="kv"><b>Application startup file</b><code>app.js</code></div>
    <ol style="margin-top:12px">
      <li>اضغط <b>Create</b>.</li>
      <li>انزل لتحت واضغط <b>Run NPM Install</b> وانتظر لحد ما يخلص (دقيقة أو دقيقتين).</li>
      <li>اضغط <b>Restart</b> فوق.</li>
      <li>ارجع لهذي الصفحة وحدّثها - لازم تصير الحالة «شغّال».</li>
    </ol>
    <?php endif; ?>
    <div class="actions">
      <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['install_csrf']) ?>"><input type="hidden" name="action" value="engine_cfg"><button class="alt">إعادة كتابة إعدادات المحرك</button></form>
      <?php if (exec_available() && $est !== 'ok'): ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= h($_SESSION['install_csrf']) ?>"><input type="hidden" name="action" value="start_engine"><button class="alt">تشغيل بدون Node.js App (تجريبي)</button></form>
      <?php endif; ?>
      <a class="btn alt" href="install.php">تحديث الفحص</a>
    </div>
  </div>

  <div class="card">
    <h2><span class="num">3</span> المهام المجدولة (Cron Jobs)</h2>
    <p>بلوحة DirectAdmin ← <b>Cron Jobs</b>، أضف مهمة: Minute <code>*/5</code> والباقي <code>*</code>، والأمر:</p>
    <code><?= h($phpBin . ' ' . __DIR__ . '/cron.php') ?></code>
    <p class="hint">لو ما اشتغل، استخدم بدله: <code>curl -s "<?= h(base_url() . '/cron?key=' . CRON_KEY) ?>"</code></p>
    <p style="margin-top:10px">أو الأسهل: افتح هذا الرابط مرة وحدة وراح يتنصّب تلقائياً:</p>
    <a class="btn" href="<?= h(base_url() . '/cron/setup?key=' . CRON_KEY) ?>" target="_blank">تنصيب Cron تلقائياً</a>
  </div>

  <form method="post" class="card">
    <h2><span class="num">4</span> الإعدادات</h2>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="csrf" value="<?= h($_SESSION['install_csrf']) ?>">
    <label>Google Client ID</label>
    <input name="google_client_id" dir="ltr" value="<?= h($cfg['GOOGLE_CLIENT_ID'] ?? '') ?>" placeholder="xxxx.apps.googleusercontent.com">
    <div class="hint">من console.cloud.google.com ← Credentials ← OAuth client ID (Web) - وبـ Authorized JavaScript origins حط: <code><?= h(preg_replace('#^(https?://[^/]+).*$#', '$1', base_url())) ?></code></div>
    <label>رابط محرك واتساب</label>
    <input name="engine_url" dir="ltr" value="<?= h($cfg['ENGINE_URL'] ?? $engineUrlDefault) ?>">
    <label>رقم أدمن ثابت (اختياري)</label>
    <input name="master_phone" dir="ltr" value="<?= h($cfg['MASTER_ADMIN_PHONE'] ?? '') ?>" placeholder="9647xxxxxxxxx">
    <label>رمز الأدمن الثابت (اختياري)</label>
    <input name="master_code" dir="ltr" value="<?= h($cfg['MASTER_ADMIN_CODE'] ?? '') ?>">
    <button type="submit">حفظ</button>
  </form>

  <div class="card">
    <h2><span class="num">5</span> ابدأ</h2>
    <ol>
      <li>ادخل المنصة ← «حساباتي» ← أضف حساب ← امسح رمز QR من واتساب جوالك (الأجهزة المرتبطة).</li>
      <li>فعّل «مرسل رموز التحقق» على الحساب حتى يقدر الناس يدخلون برقم واتساب.</li>
    </ol>
    <a class="btn" href="app">الدخول للمنصة</a>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
