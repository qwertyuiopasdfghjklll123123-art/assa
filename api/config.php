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

    // Country code added to local numbers (07xx… → 9647xx…). 964 = Iraq.
    'default_country_code' => '964',

    // ── WhatsApp verification codes (sign-up and password reset) ──
    'whatsapp' => [
        // 'none' | 'meta' | 'ultramsg'  — see api/whatsapp.php for details.
        'provider' => 'none',

        // WhatsApp Cloud API (Meta Business → WhatsApp → API Setup)
        'meta_token' => '',
        'meta_phone_number_id' => '',
        'meta_template' => 'otp_code',   // an approved "Authentication" template
        'meta_language' => 'ar',

        // UltraMsg (ultramsg.com → your instance)
        'ultramsg_instance' => '',
        'ultramsg_token' => '',
    ],

    // ── Sign in with Google ──
    // Google Cloud Console → APIs & Services → Credentials → OAuth client ID (type: Web application).
    // Add your site (https://your-domain) to "Authorized JavaScript origins", then paste the Client ID here.
    'google_client_id' => '',

    // TESTING ONLY: when no provider is configured, show the code on screen instead of sending it.
    // Set to false before going live!
    'otp_debug' => true,
];
