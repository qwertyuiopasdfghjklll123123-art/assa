<?php
// تشغيل المهام المجدولة من سطر الأوامر (Cron Jobs بلوحة DirectAdmin) كل 5 دقائق:
//   /usr/local/bin/php /home/USER/domains/DOMAIN/public_html/cron.php
// أو بدلها عبر رابط:  curl -s "https://DOMAIN/cron?key=CRON_KEY"
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
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
echo json_encode(run_scheduled_tasks(), JSON_UNESCAPED_UNICODE), "\n";
