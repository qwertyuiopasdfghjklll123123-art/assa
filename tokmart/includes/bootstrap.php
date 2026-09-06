<?php
/**
 * App bootstrap: session, constants, config loading, and the require chain
 * for every other include. Every entry point (index.php, api/index.php,
 * admin/*.php, install.php) starts by requiring this file.
 */

ini_set('session.cookie_lifetime', 60 * 60 * 24 * 365);
ini_set('session.gc_maxlifetime', 60 * 60 * 24 * 365);
ini_set('session.gc_probability', 0);
ini_set('session.gc_divisor', 100);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('DEBUG_MODE', false);
define('APP_ROOT', dirname(__DIR__));
define('DATA_DIR', APP_ROOT . '/data');
define('UPLOAD_DIR', DATA_DIR . '/uploads');

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
define('SITE_URL', $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Best-effort guess at the base URL path, used only before install.php has
// run (to build the redirect to it) or if an old/hand-written config.php
// doesn't have 'base_path' saved. Once installed, the reliable value below -
// computed once by install.php itself, from its own known location - wins.
function guessBasePath(): string {
    $scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $appRootNormalized = str_replace('\\', '/', APP_ROOT);
    $relativeToRoot = str_starts_with($scriptFile, $appRootNormalized)
        ? substr($scriptFile, strlen($appRootNormalized))
        : '';
    $basePath = $relativeToRoot !== '' && str_ends_with($scriptName, $relativeToRoot)
        ? substr($scriptName, 0, strlen($scriptName) - strlen($relativeToRoot))
        : '';
    return rtrim($basePath, '/');
}

// Derived from the newest CSS/JS file's mtime rather than a fixed string, so
// a redeploy that changes any asset automatically busts every returning
// visitor's browser cache instead of them silently keeping the old file
// under the same "?v=" URL until someone remembers to bump a version number.
function computeAssetVersion(): string {
    $files = array_merge(
        glob(APP_ROOT . '/assets/css/*.css') ?: [],
        glob(APP_ROOT . '/assets/js/*.js') ?: []
    );
    $mtimes = array_map('filemtime', $files);
    return $mtimes ? (string)max($mtimes) : '1';
}
define('CACHE_VERSION', computeAssetVersion());

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    // Not installed yet: send everything except the installer itself there.
    define('APP_BASE_PATH', guessBasePath());
    $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($current !== 'install.php') {
        header('Location: ' . APP_BASE_PATH . '/install.php');
        exit;
    }
    return;
}

$appConfig = require $configFile;
define('APP_BASE_PATH', array_key_exists('base_path', $appConfig) ? rtrim($appConfig['base_path'], '/') : guessBasePath());

require_once __DIR__ . '/db.php';
$pdo = db_connect($appConfig['db']);

define('APP_SECRET', $appConfig['app_secret'] ?? '');

foreach ([DATA_DIR, UPLOAD_DIR, UPLOAD_DIR . '/products', UPLOAD_DIR . '/categories',
          UPLOAD_DIR . '/avatars', UPLOAD_DIR . '/site', UPLOAD_DIR . '/chats',
          UPLOAD_DIR . '/transfers', UPLOAD_DIR . '/receipts', UPLOAD_DIR . '/payments'] as $folder) {
    if (!is_dir($folder)) {
        mkdir($folder, 0755, true);
    }
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/uploads.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/google_oauth.php';
require_once __DIR__ . '/settings.php';
