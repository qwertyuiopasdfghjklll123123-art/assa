<?php
/**
 * Copy this file to config.php and fill in your real values, OR just run
 * install.php and let it generate config.php for you automatically.
 */
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'tokmart',
        'user' => 'tokmart_user',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    // Random per-install secret, reserved for future signing needs (e.g. CSRF
    // tokens). install.php generates this with random_bytes(32) - if you write
    // config.php by hand, generate your own instead of reusing this placeholder.
    'app_secret' => 'change-this-to-a-random-64-char-hex-string',
    // Leave '' for an app installed at the domain root (https://example.com/).
    // Set to '/tokmart' if installed under a subdirectory (https://example.com/tokmart/).
    'base_path' => '',
];
