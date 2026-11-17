<?php
/**
 * المهام المجدولة (تذكيرات الاشتراكات وانتهاء خطط المستخدمين)، رابط المحرك (engine_hook)،
 * وواجهة API الخارجية لإرسال رموز التحقق.
 */

require_once __DIR__ . '/handlers.php';

/** إرسال رسالة باسم مستخدم: يخصم سعر الرسالة (لو ما عنده اشتراك) ويرجعه لو فشل الإرسال */
function user_send(int $owner, string $accId, string $phone, string $text): array
{
    [$ok, $charged] = charge_message($owner);
    if (!$ok) {
        return [false, no_balance_error()];
    }
    [$sent, $err] = engine_send($accId, $phone, $text);
    if (!$sent) {
        refund_message($owner, $charged);
    }
    return [$sent, $err];
}

// ================================================================ رسائل الاشتراكات

function send_subscription_start_message(int $subId): void
{
    $s = store_find('subscribers', $subId);
    if (!$s) {
        return;
    }
    $acc = find_account_for_owner((int)$s['owner']);
    if (!$acc) {
        return;
    }
    $tpl = subscription_settings((int)$s['owner'])['start_message'];
    [$ok, $err] = user_send((int)$s['owner'], $acc['id'], $s['phone'], fill_subscription_message($tpl, $s['name'], $s['duration_days']));
    if ($ok) {
        add_event((int)$s['owner'], $acc['name'], 'تم إشعار مشترك جديد', $s['name'] . ': تم إرسال رسالة بداية الاشتراك', 'info');
    } else {
        error_log("[اشتراكات] فشل إرسال رسالة البداية لـ {$s['name']}: {$err}");
    }
}

/** رسالة "بدأ/انتهى اشتراكك" لمستخدم المنصة نفسه (من حساب مرسل الرموز) */
function send_admin_plan_message(int $userId, bool $started, ?int $days): void
{
    $u = user_by_id($userId);
    if (!$u || empty($u['phone'])) {
        return;
    }
    $acc = find_otp_sender();
    if (!$acc) {
        return;
    }
    $name = $u['name'] ?: $u['phone'];
    $text = fill_subscription_message($started ? DEFAULT_START_MESSAGE : DEFAULT_EXPIRED_MESSAGE, $name, $days ?? '');
    [$ok] = engine_send($acc['id'], $u['phone'], $text);
    if ($ok) {
        $label = $started ? 'بدء' : 'انتهاء';
        add_event((int)$acc['owner'], $acc['name'], 'إشعار اشتراك مستخدم', "{$name}: تم إرسال رسالة {$label} الاشتراك", 'info');
    }
}

// ================================================================ المهام المجدولة

/**
 * تُشغَّل من Cron (كل 5-10 دقائق) أو من المحرك كل 10 دقائق. آمنة للتكرار: كل رسالة
 * تنبعث مرة وحدة بس (علامات reminder_sent / expired_sent).
 */
function run_scheduled_tasks(): array
{
    $lock = fopen(DATA_DIR . '/cron.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['ok' => true, 'skipped' => 'already running'];
    }
    set_time_limit(600);
    update_settings(['last_cron' => time()]);
    $report = ['ok' => true, 'plans_expired' => 0, 'reminders' => 0, 'expired_msgs' => 0];
    try {
        // 1) انتهاء خطط مستخدمي المنصة اللي فعّلها الأدمن بمدة محددة
        foreach (store_all('users') as $u) {
            $ends = parse_dt($u['plan_ends_at'] ?? null);
            if (!empty($u['plan_active']) && $ends !== null && $ends <= time()) {
                set_plan_active((int)$u['id'], false);
                send_admin_plan_message((int)$u['id'], false, null);
                $report['plans_expired']++;
            }
        }

        // 2) تذكير/انتهاء اشتراكات عملاء كل مستخدم
        $due = store_where('subscribers', fn($s) => empty($s['reminder_sent']) || empty($s['expired_sent']));
        foreach ($due as $s) {
            $exp = subscriber_expiry($s);
            if ($exp === null) {
                continue;
            }
            $left = days_until($exp);
            $cfg = subscription_settings((int)$s['owner']);
            if (empty($s['expired_sent']) && $left <= 0) {
                $acc = find_account_for_owner((int)$s['owner']);
                if (!$acc) {
                    continue;
                }
                [$ok] = user_send((int)$s['owner'], $acc['id'], $s['phone'], fill_subscription_message($cfg['expired_message'], $s['name'], 0));
                if ($ok) {
                    store_update('subscribers', $s['id'], fn($r) => array_merge($r, ['expired_sent' => true, 'reminder_sent' => true]));
                    add_event((int)$s['owner'], $acc['name'], 'انتهى اشتراك مستخدم', $s['name'] . ': تم إرسال رسالة انتهاء الاشتراك', 'warning');
                    $report['expired_msgs']++;
                }
            } elseif (empty($s['reminder_sent']) && $left > 0 && $left <= $cfg['reminder_days_before']) {
                $acc = find_account_for_owner((int)$s['owner']);
                if (!$acc) {
                    continue;
                }
                [$ok] = user_send((int)$s['owner'], $acc['id'], $s['phone'], fill_subscription_message($cfg['reminder_message'], $s['name'], $left));
                if ($ok) {
                    store_update('subscribers', $s['id'], fn($r) => array_merge($r, ['reminder_sent' => true]));
                    add_event((int)$s['owner'], $acc['name'], 'تذكير انتهاء اشتراك', $s['name'] . ": يبقى {$left} يوم", 'info');
                    $report['reminders']++;
                }
            }
        }

        cleanup_bootstrap();

        // نسخة احتياطية يومية لتيليجرام (لو مفعّلة)
        $s = settings();
        if (!empty($s['tg_auto_backup']) && (empty($s['tg_last_backup']) || strtotime($s['tg_last_backup']) < time() - 86400)) {
            [$okB] = tg_send_backup();
            $report['telegram_backup'] = $okB;
        }

        // 3) تنظيف جلسات PHP القديمة جداً (أكثر من سنة)
        foreach (glob(DATA_DIR . '/sessions/sess_*') ?: [] as $f) {
            if (filemtime($f) < time() - 366 * 86400) {
                @unlink($f);
            }
        }

        // 4) نبضة للمحرك (تبقيه صاحي لو الاستضافة توقف التطبيقات الخاملة)
        [$st] = engine_request('GET', '/health', null, 10);
        $report['engine'] = $st === 200 ? 'online' : 'offline';
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $report;
}

/** صفحة تنصيب المهام المجدولة: تحاول تضيف Cron تلقائياً، وإلا تشرح البديل */
function cron_setup_page(): void
{
    $php = PHP_BINDIR . '/php';
    $cmd = '*/5 * * * * ' . $php . ' ' . dirname(__DIR__) . '/cron.php >/dev/null 2>&1';
    $done = false;
    $msg = '';
    $canExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);
    if ($canExec) {
        @exec('crontab -l 2>/dev/null', $lines, $rc);
        $lines = $lines ?? [];
        if (array_filter($lines, fn($l) => strpos($l, 'cron.php') !== false && strpos($l, dirname(__DIR__)) !== false)) {
            $done = true;
            $msg = 'المهام المجدولة منصّبة مسبقاً ✅';
        } else {
            $lines[] = $cmd;
            $tmp = tempnam(sys_get_temp_dir(), 'cr');
            file_put_contents($tmp, implode("\n", $lines) . "\n");
            @exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc2);
            @unlink($tmp);
            if ($rc2 === 0) {
                $done = true;
                $msg = 'تم تنصيب المهام المجدولة بنجاح ✅ (كل 5 دقائق)';
            }
        }
    }
    $report = run_scheduled_tasks();
    update_settings(['cron_installed' => $done ? 'crontab' : 'visits']);
    $url = site_base_url() . '/cron?key=' . CRON_KEY;
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>المهام المجدولة</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#f3f7f8;color:#0d2a30;margin:0;padding:24px}.c{max-width:520px;margin:auto;background:#fff;border-radius:24px;padding:24px;box-shadow:0 10px 30px rgba(3,79,92,.08)}h1{font-size:20px;color:#04788b}.ok{background:#e7f8f1;color:#0b6b4b;padding:12px;border-radius:14px}.w{background:#fff6e5;color:#8a5a06;padding:12px;border-radius:14px}code{display:block;direction:ltr;background:#05343c;color:#bdeef5;padding:10px;border-radius:10px;word-break:break-all;margin:8px 0;user-select:all}li{margin-bottom:8px}</style></head><body><div class="c">'
        . '<h1>المهام المجدولة (Cron)</h1>'
        . ($done ? '<p class="ok">' . htmlspecialchars($msg) . '</p>'
            : '<p class="w">الاستضافة ما تسمح بالتنصيب التلقائي، لكن فعّلنا التشغيل التلقائي مع زيارات الموقع ✅. للأفضل اختر طريقة من هذي:</p>'
              . '<ol><li>من DirectAdmin ← Cron Jobs، أضف مهمة كل 5 دقائق (<b>*/5</b>) بهذا الأمر:<code>' . htmlspecialchars($php . ' ' . dirname(__DIR__) . '/cron.php') . '</code></li>'
              . '<li>أو مجاناً من موقع <b>cron-job.org</b>: أنشئ مهمة كل 5 دقائق على هذا الرابط:<code>' . htmlspecialchars($url) . '</code></li></ol>')
        . '<p>آخر تشغيل: ' . date('Y-m-d H:i') . ' — المحرك: ' . htmlspecialchars((string)($report['engine'] ?? '-')) . '</p>'
        . '<p><a href="/app">رجوع للمنصة</a></p></div></body></html>';
    exit;
}

// ================================================================ رابط المحرك → PHP

function handle_engine_hook(): void
{
    $secret = (string)($_SERVER['HTTP_X_ENGINE_SECRET'] ?? '');
    if (ENGINE_SECRET === '' || !hash_equals(ENGINE_SECRET, $secret)) {
        json_err('unauthorized', 401);
    }
    $d = request_json();
    switch ($d['type'] ?? '') {
        case 'event':
            add_event(isset($d['owner']) ? (int)$d['owner'] : null, $d['account'] ?? null,
                (string)($d['title'] ?? ''), (string)($d['body'] ?? ''), (string)($d['kind'] ?? 'info'));
            json_out(['ok' => true]);
            // no break
        case 'history':
            $e = $d['entry'] ?? [];
            $accId = (string)($d['account_id'] ?? '');
            store_insert('history', [
                'owner' => (int)($d['owner'] ?? 0), 'account_id' => $accId,
                'account_name' => clean_text($d['account_name'] ?? ''),
                'time' => (string)($e['time'] ?? now_str()), 'total' => (int)($e['total'] ?? 0),
                'sent' => (int)($e['sent'] ?? 0), 'failed' => (int)($e['failed'] ?? 0),
                'text' => (string)($e['text'] ?? ''),
                'campaign_id' => (string)($e['campaign_id'] ?? ''),
                'delivered' => (int)($e['delivered'] ?? 0), 'read' => (int)($e['read'] ?? 0), 'replied' => (int)($e['replied'] ?? 0),
                'lists' => track_lists($e['lists'] ?? []), 'failed_numbers' => digit_list($e['failed_numbers'] ?? []),
            ]);
            // آخر 20 حملة لكل حساب فقط
            store_update_all('history', function (array &$rows) use ($accId) {
                $ids = array_keys(array_filter($rows, fn($h) => $h['account_id'] === $accId));
                sort($ids, SORT_NUMERIC);
                while (count($ids) > HISTORY_PER_ACCOUNT) {
                    unset($rows[(string)array_shift($ids)]);
                }
            });
            json_out(['ok' => true]);
            // no break
        case 'ai_reply':
            $owner = user_by_id((int)($d['owner'] ?? 0));
            if (!can_send($owner)) {
                json_out(['reply' => null]);
            }
            $acc = wa_account((string)($d['account_id'] ?? ''));
            if (!$acc || empty($acc['auto_reply_ai_enabled'])) {
                json_out(['reply' => null]);
            }
            json_out(['reply' => ai_reply((string)($d['message'] ?? ''), (array)($d['history'] ?? []), $acc['ai_profile'] ?? [], clean_text($d['customer'] ?? ''))]);
            // no break
        case 'history_update':
            // تحديث إحصائيات حملة بعد انتهائها (وصلت / قُرئت / رد)
            $cid = (string)($d['campaign_id'] ?? '');
            if ($cid !== '') {
                store_update_all('history', function (array &$rows) use ($cid, $d) {
                    foreach ($rows as $k => $h) {
                        if (($h['campaign_id'] ?? '') === $cid) {
                            $rows[$k]['delivered'] = max((int)($h['delivered'] ?? 0), (int)($d['delivered'] ?? 0));
                            $rows[$k]['read'] = max((int)($h['read'] ?? 0), (int)($d['read'] ?? 0));
                            $rows[$k]['replied'] = max((int)($h['replied'] ?? 0), (int)($d['replied'] ?? 0));
                            if (!empty($d['lists'])) {
                                $rows[$k]['lists'] = track_lists($d['lists']);
                            }
                        }
                    }
                });
            }
            json_out(['ok' => true]);
            // no break
        case 'charge':
            // المحرك يطلب خصم رسالة قبل إرسالها (حملات + رد آلي)
            [$ok, $charged] = charge_message((int)($d['owner'] ?? 0), (string)($d['note'] ?? 'رسالة واتساب'));
            json_out(['ok' => $ok, 'charged' => $charged, 'error' => $ok ? null : no_balance_error()]);
            // no break
        case 'refund':
            refund_message((int)($d['owner'] ?? 0), (int)($d['amount'] ?? 0));
            json_out(['ok' => true]);
            // no break
        case 'tick':
            json_out(run_scheduled_tasks());
            // no break
        default:
            json_err('نوع غير معروف');
    }
}

// ================================================================ API خارجي (إرسال رمز تحقق)

function api_rate_ok(string $keyHash): bool
{
    $ok = true;
    store_update_doc('ratelimit', function ($d) use ($keyHash, &$ok) {
        $now = time();
        $times = array_values(array_filter($d[$keyHash] ?? [], fn($t) => $now - $t < 60));
        if (count($times) >= API_RATE_LIMIT_PER_MIN) {
            $ok = false;
        } else {
            $times[] = $now;
        }
        $d[$keyHash] = $times;
        return $d;
    });
    return $ok;
}

function handle_api_send(): void
{
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $raw = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($raw === '' && stripos($auth, 'Bearer ') === 0) {
        $raw = trim(substr($auth, 7));
    }
    if ($raw === '') {
        json_err('مفتاح API مفقود - أرسله بترويسة X-API-Key أو Authorization: Bearer <key>', 401);
    }
    $hash = hash('sha256', $raw);
    $key = store_first('api_keys', fn($k) => hash_equals($k['key_hash'], $hash) && empty($k['revoked']));
    if (!$key) {
        json_err('مفتاح API غير صالح أو ملغى', 401);
    }
    if (!api_rate_ok($hash)) {
        json_err('تجاوزت الحد المسموح من الطلبات (20 بالدقيقة)، حاول بعد قليل', 429);
    }
    if (!can_send(user_by_id((int)$key['owner']))) {
        json_err('اشتراك صاحب المفتاح غير فعّال ورصيده ما يكفي', 402);
    }
    $acc = wa_account($key['account_id']);
    if (!$acc || (int)$acc['owner'] !== (int)$key['owner'] || !engine_logged_in($acc['id'])) {
        json_err('حساب الواتساب المرتبط بهذا المفتاح غير متصل حالياً');
    }
    $d = request_json();
    $phone = digits_only($d['phone'] ?? '');
    if ($phone === '') {
        json_err('أرسل رقم هاتف صالح بحقل phone');
    }
    $code = trim((string)($d['code'] ?? ''));
    $message = trim((string)($d['message'] ?? ''));
    if ($message === '') {
        if ($code === '') {
            json_err('أرسل code أو message بجسم الطلب');
        }
        $message = "رمز التحقق الخاص بك: {$code}\nصالح لمدة 10 دقائق.";
    }
    store_update('api_keys', $key['id'], fn($k) => array_merge($k, ['last_used_at' => now_str()]));
    [$ok, $err] = user_send((int)$key['owner'], $acc['id'], $phone, $message);
    $ok ? json_out(['ok' => true]) : json_err('فشل الإرسال: ' . $err, $err === no_balance_error() ? 402 : 502);
}

/** قائمة أرقام نظيفة (أرقام فقط، بدون تكرار، بحد أقصى) */
function digit_list($a, int $max = 20000): array
{
    if (!is_array($a)) {
        return [];
    }
    $out = [];
    foreach ($a as $n) {
        $d = preg_replace('/\D/', '', (string)$n);
        if ($d !== '' && strlen($d) <= 20) {
            $out[$d] = 1;
        }
        if (count($out) >= $max) {
            break;
        }
    }
    return array_map('strval', array_keys($out)); // مفاتيح المصفوفة تتحول لأرقام - نرجعها نصوص
}

/** قوائم التتبع الحصرية: ردّوا / شاهدوا بدون رد / وصلت وما شاهدوا / ما وصلت بعد */
function track_lists($l): array
{
    $l = is_array($l) ? $l : [];
    $out = [];
    $seen = [];
    foreach (['replied', 'read_only', 'unread', 'pending'] as $k) {
        // كل رقم بقائمة وحدة بس، بالأولوية: رد > شاهد > وصل > ما وصل
        $out[$k] = array_values(array_filter(digit_list($l[$k] ?? []), function ($d) use (&$seen) {
            if (isset($seen[$d])) {
                return false;
            }
            return $seen[$d] = true;
        }));
    }
    return $out;
}
