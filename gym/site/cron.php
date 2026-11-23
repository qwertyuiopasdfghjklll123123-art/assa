<?php
/**
 * Cron (كل دقيقة): تنبيهات الوجبات + نهاية الراحة لو التطبيق مسكّر.
 * بالاستضافة:  * * * * *  php /path/to/site/cron.php
 * أو من رابط:  https://موقعك/cron?key=المفتاح  (المفتاح بصفحة «حسابي» للأول مستخدم)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/push.php';
require __DIR__ . '/lib/api.php';
date_default_timezone_set('Asia/Baghdad');
config();
echo json_encode(run_due()) . PHP_EOL;
