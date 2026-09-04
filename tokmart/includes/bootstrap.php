<?php
/**
 * Shared bootstrap: session, config, DB connection, upload folders.
 * Every entry point (index.php, admin/*.php, api/index.php) requires this first.
 */

ini_set('session.cookie_lifetime', 60 * 60 * 24 * 365);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 365);
ini_set('session.gc_probability', 0);
ini_set('session.gc_divisor', 100);
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DEBUG_MODE', false);
define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('UPLOAD_DIR', DATA_DIR . '/uploads');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
define('SITE_URL', $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// The base URL path the app is deployed under (e.g. '' at domain root, '/tokmart'
// in a subdirectory). Computed from the *application root*, not from whichever
// entry point handled this request - app.php, api/index.php and admin/*.php all
// live at different depths, so dirname(SCRIPT_NAME) alone would give a different
// (wrong) answer depending on which one was hit.
$scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
$appRootNormalized = str_replace('\\', '/', APP_ROOT);
$relativeToRoot = str_starts_with($scriptFile, $appRootNormalized)
    ? substr($scriptFile, strlen($appRootNormalized))
    : '';
$basePath = $relativeToRoot !== '' && str_ends_with($scriptName, $relativeToRoot)
    ? substr($scriptName, 0, strlen($scriptName) - strlen($relativeToRoot))
    : '';
define('APP_BASE_PATH', rtrim($basePath, '/'));
define('CACHE_VERSION', '2.0.0');

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    // Not installed yet: send everything except the installer itself there.
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($current !== 'install.php') {
        header('Location: ' . APP_BASE_PATH . '/install.php');
        exit;
    }
    return;
}

$appConfig = require $configFile;

require_once __DIR__ . '/db.php';
$pdo = db_connect($appConfig['db']);

$folders = [
    DATA_DIR,
    UPLOAD_DIR,
    UPLOAD_DIR . '/products',
    UPLOAD_DIR . '/categories',
    UPLOAD_DIR . '/avatars',
    UPLOAD_DIR . '/site',
    UPLOAD_DIR . '/chats',
    UPLOAD_DIR . '/transfers',
    UPLOAD_DIR . '/receipts',
];
foreach ($folders as $folder) {
    if (!file_exists($folder)) {
        @mkdir($folder, 0777, true);
    }
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/google_oauth.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';

$googleConfig = getGoogleOAuthConfig();
define('GOOGLE_CLIENT_ID', $googleConfig['client_id'] ?? '');
define('GOOGLE_CLIENT_SECRET', $googleConfig['client_secret'] ?? '');
define('GOOGLE_ENABLED', $googleConfig['enabled'] ?? false);
