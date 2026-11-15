<?php
// ─────────────────────────────────────────────────────────────
//  Server settings — edit before uploading to your hosting.
// ─────────────────────────────────────────────────────────────
return [
    // The first admin account, created automatically on the very first request.
    // CHANGE the password before going live (or change it later from the admin panel).
    'admin_email'    => 'admin@sufra.app',
    'admin_password' => 'admin123',
    'admin_name'     => 'مدير النظام',

    // Where the JSON database lives. Keep it outside public_html if your host allows it,
    // e.g. dirname(__DIR__, 2) . '/sufra-data'. The default folder is protected by .htaccess.
    'data_dir' => __DIR__ . '/data',

    // Uploaded product / branch images (must be publicly reachable).
    'upload_dir' => dirname(__DIR__) . '/uploads',
    'upload_url' => 'uploads',

    // Login sessions stay valid for this many days.
    'session_days' => 60,
];
