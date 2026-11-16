<?php
/**
 * عرض صفحتي الواجهة من views/frontend.html (نفس ملف الواجهة القديم، مقسوم بعلامات صريحة):
 * - صفحة الترحيب/تسجيل الدخول (قبل الدخول)
 * - التطبيق (بعد الدخول)
 */

const ICON_SEND_ARROW = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4 20-7z"/></svg>';
const ICON_LOCK_SM = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 018 0v3"/></svg>';
const ICON_BACK = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5L8 12l7 7"/><path d="M8 12h12"/></svg>';
const ICON_CHECK = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg>';
const ICON_SEND = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12l16-8-6 16-3-6-7-2Z"/><path d="M11 14l5-5"/></svg>';
const ICON_USERS = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c.5-3.1 2.4-5 5.5-5s5 1.9 5.5 5"/><circle cx="17" cy="9" r="2.5"/><path d="M15 15c2.6.2 4.3 1.5 5 4"/></svg>';
const ICON_TREND = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 20V11"/><path d="M10 20V6"/><path d="M15 20v-9"/><path d="M20 20V3"/></svg>';
const ICON_BOLT = '<svg width="22" height="22" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3.4" stroke-linecap="round" stroke-linejoin="round"><path d="M25 3L9 27h12l-2 18 20-27H27z"/></svg>';
const ICON_CHART = '<svg width="22" height="22" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round"><path d="M8 39V26"/><path d="M20 39V16"/><path d="M32 39V21"/><path d="M44 39V9"/><path d="M6 44h38"/><path d="M7 18l10-7 10 4 13-9"/></svg>';
const ICON_TARGET = '<svg width="22" height="22" viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="24" cy="24" r="17"/><circle cx="24" cy="24" r="8"/><path d="M24 24l14-14"/><path d="M30 10h8v8"/></svg>';
const ICON_WHATSAPP = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20.5 11.5a8.5 8.5 0 0 1-12.6 7.4L3.5 20l1.2-4.2A8.5 8.5 0 1 1 20.5 11.5Z"/><path d="M8.5 8.2c.3-.4.6-.4.9-.2l1.1 1.7c.2.3.1.6-.1.9l-.6.6c.7 1.4 1.8 2.4 3.2 3.1l.6-.7c.2-.2.6-.3.9-.1l1.7 1c.3.2.3.6.1.9-.4.7-1 1.1-1.8 1-3.7-.5-7.2-3.8-7.9-7.6-.1-.2.1-.5.2-.6l1.7-.9Z"/></svg>';

const FONT_LINKS = '<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">';
const FONT_STACK = "'IBM Plex Sans Arabic','Cairo','Tajawal',system-ui,sans-serif";
const PAGE_TRANSITION_CSS = 'body.leaving { animation: pageFadeOut .13s ease forwards; }
@keyframes pageFadeOut { to { opacity:0; transform:scale(.98); } }';
const PAGE_TRANSITION_JS = <<<'JS'
(function() {
  function leaveAndGo(action) {
    if (document.body.classList.contains('leaving')) return;
    document.body.classList.add('leaving');
    setTimeout(action, 130);
  }
  window.leaveAndGo = leaveAndGo;
  document.addEventListener('click', function(e) {
    var a = e.target.closest('a[href^="/"]');
    if (!a || a.target === '_blank' || e.metaKey || e.ctrlKey || e.button !== 0) return;
    var href = a.getAttribute('href');
    if (!href || href === '#') return;
    e.preventDefault();
    leaveAndGo(function() { window.location.href = href; });
  });
  document.querySelectorAll('form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      leaveAndGo(function() { form.submit(); });
    });
  });
})();
JS;

const FRONTEND_WELCOME_START = '<!-- ===== WASEL:WELCOME_PAGE START (لا تحذف هذا السطر - يستخدمه webapp.py لتحديد بداية القسم) ===== -->';
const FRONTEND_WELCOME_END = '<!-- ===== WASEL:WELCOME_PAGE END ===== -->';
const FRONTEND_APP_START = '<!-- ===== WASEL:APP_PAGE START (لا تحذف هذا السطر - يستخدمه webapp.py لتحديد بداية القسم) ===== -->';
const FRONTEND_APP_END = '<!-- ===== WASEL:APP_PAGE END ===== -->';

function logo_svg(int $size = 32): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 100 100" fill="none" aria-label="Wasel">
      <path d="M50 9C27 9 9 26 9 47.5c0 8 2.6 15.2 7.2 21.2L10 88l19-5.8c6.2 3.5 13.3 5.5 21 5.5 23 0 41-17 41-40.2S73 9 50 9Z" stroke="#fff" stroke-width="6"/>
      <path d="M35 25c-3 0-5 2-5 5 0 16 12 28 28 33 4 1 7-2 8-5l1-4-10-5-4 4c-5-2-9-6-11-11l4-4-5-10c-1-2-3-3-6-3Z" fill="#fff"/>
    </svg>';
}

/** روابط صفحات التطبيق (للأدمن): لازم تطابق PAGES بملف app.js */
const APP_SLUGS = ['campaign', 'reports', 'numbers', 'number', 'packages', 'pay', 'me', 'notifications', 'clients', 'api', 'transactions',
    'profile', 'dashboard', 'users', 'recharges', 'payments', 'plans', 'settings', 'security', 'backup', 'announce'];

function view_file(string $name): string
{
    return (string)file_get_contents(__DIR__ . '/../views/' . $name);
}

function logo_html(int $size, string $class = ''): string
{
    [$siteName, $hasLogo] = branding_context();
    if ($hasLogo) {
        return '<img src="/branding/logo?v=' . (int)@filemtime(logo_path()) . '" alt="' . $siteName . '" class="' . $class . '" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:contain">';
    }
    return '<img src="/assets/logo.png" alt="' . $siteName . '" class="' . $class . '" style="width:' . $size . 'px;height:' . $size . 'px;object-fit:contain">';
}

function js_json($v): string
{
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

function render_welcome_page(): string
{
    [$siteName] = branding_context();
    $cc = default_country_code();
    $ccLabel = '+' . $cc;
    foreach (COUNTRY_CODES as [$c, $label]) {
        if ($c === $cc) {
            $ccLabel = $label;
        }
    }
    $boot = [
        'site' => html_entity_decode($siteName), 'cc' => $cc, 'cc_label' => $ccLabel,
        'google' => GOOGLE_CLIENT_ID, 'whatsapp' => !empty(settings()['whatsapp_login']) || users_count() === 0,
        'trial_days' => trial_days(), 'message_price' => message_price(), 'plan_price' => plan_price(),
    ];
    return strtr(view_file('welcome.html'), [
        '__SITE_NAME__' => $siteName,
        '__LOGO_BIG__' => logo_html(84, 'hero-logo'),
        '__LOGO_SM__' => logo_html(56, 'login-logo'),
        '__GOOGLE_SCRIPT__' => GOOGLE_CLIENT_ID !== '' ? '<script src="https://accounts.google.com/gsi/client" async defer></script>' : '',
        '__BOOT_JSON__' => js_json($boot),
    ]);
}

function render_app_page(string $section = ''): string
{
    [$siteName, $hasLogo] = branding_context();
    $boot = [
        'site' => html_entity_decode($siteName), 'has_logo' => $hasLogo,
        'me' => me_payload(uid()), 'settings' => public_settings(),
        'cc' => default_country_code(), 'country_codes' => COUNTRY_CODES,
        'section' => $section, 'is_admin' => !empty($_SESSION['is_admin']),
    ];
    return strtr(view_file('app.html'), [
        '__SITE_NAME__' => $siteName,
        '__LOGO_SM__' => logo_html(34, 'top-logo'),
        '__BOOT_JSON__' => js_json($boot),
    ]);
}

/** أيقونات PWA بصيغة PNG: من شعار الموقع لو موجود (عبر GD)، وإلا الأيقونة الافتراضية */
function serve_pwa_icon($size): void
{
    $size = (int)$size === 512 ? 512 : 192;
    $cache = DATA_DIR . "/pwa-{$size}.png";
    $default = __DIR__ . "/../views/pwa-{$size}.png";
    [, $hasLogo] = branding_context();
    if ($hasLogo && !is_file($cache) && function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring((string)file_get_contents(logo_path()));
        if ($src) {
            $dst = imagecreatetruecolor($size, $size);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            $pad = (int)($size * .12);
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(($size - 2 * $pad) / $w, ($size - 2 * $pad) / $h);
            $nw = (int)($w * $scale);
            $nh = (int)($h * $scale);
            imagecopyresampled($dst, $src, (int)(($size - $nw) / 2), (int)(($size - $nh) / 2), 0, 0, $nw, $nh, $w, $h);
            imagepng($dst, $cache);
        }
    }
    $file = $hasLogo && is_file($cache) ? $cache : $default;
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    readfile($file);
}
