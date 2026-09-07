<?php
/**
 * PWA manifest, generated on the fly so it tracks the admin's site name/logo
 * instead of going stale like a static manifest.json would.
 */

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$site = getSiteSettings();
$name = $site['name'] ?: 'Tokmart';

echo json_encode([
    'name' => $name,
    'short_name' => mb_substr($name, 0, 12),
    'description' => $site['description'] ?: $site['slogan'],
    'start_url' => APP_BASE_PATH . '/app',
    'scope' => APP_BASE_PATH . '/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'dir' => 'rtl',
    'lang' => 'ar',
    'background_color' => '#F5F9FA',
    'theme_color' => '#0f3d1c',
    'icons' => [
        ['src' => APP_BASE_PATH . '/icon.php?size=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ['src' => APP_BASE_PATH . '/icon.php?size=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
