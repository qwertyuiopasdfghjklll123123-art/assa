<?php
/**
 * المحادثات: صاحب الحساب يشوف محادثات رقمه بواتساب، يرد مباشرة، يراسل رقم جديد،
 * ويحدد مجموعة محادثات ويرسلهم رسالة وحدة (تمشي كحملة بفاصل آمن).
 * كل مستخدم يشوف محادثات أرقامه هو بس (owned_account).
 */

/** يتأكد إن المستخدم يكدر يرسل N رسالة من هذا الرقم (اشتراك/رصيد، حد الأرقام، حد اليوم) */
function inbox_send_guard(int $uid, string $accId, int $count): void
{
    $u = user_by_id($uid);
    if (!can_send($u)) {
        json_err(message_price() > 0 ? no_balance_error() : 'انتهت فترتك التجريبية - فعّل اشتراكك من قسم الباقات', 402);
    }
    $lim = user_limits($u);
    if ($lim['numbers'] > 0) {
        $mine = wa_accounts_of($uid);
        usort($mine, fn($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));
        if (!in_array($accId, array_slice(array_column($mine, 'id'), 0, $lim['numbers']), true)) {
            json_err(numbers_limit_error($lim) . ' - أرسل من أرقامك الأولى', 403);
        }
    }
    if ($lim['daily'] > 0) {
        $left = max(0, $lim['daily'] - daily_used($uid));
        if ($left < $count) {
            json_err($left ? "باقي لك اليوم {$left} رسالة من {$lim['daily']} - حدد {$left} محادثات أو أقل" : daily_limit_error($lim), 429);
        }
    }
}

function inbox_acc(string $accId): array
{
    require_login();
    $acc = owned_account($accId);
    if (!$acc) {
        json_err('الحساب غير موجود', 404);
    }
    return $acc;
}

function inbox_valid_jid(string $j): bool
{
    return (bool)preg_match('/^\d{5,20}(:\d+)?@(s\.whatsapp\.net|lid)$/', $j);
}

/** يقرأ الطلب (JSON أو form مع ملف) */
function inbox_input(): array
{
    return !empty($_POST) || !empty($_FILES) ? $_POST : request_json();
}

function inbox_routes(): array
{
    return [
        // قائمة المحادثات
        ['GET', '#^/api/inbox/([\w-]+)$#', function ($accId) {
            inbox_acc($accId);
            $r = engine_json('GET', '/accounts/' . rawurlencode($accId) . '/inbox', null, 10);
            $st = $r['_status'] ?? 503;
            unset($r['_status']);
            json_out($r, $st === 200 ? 200 : $st);
        }],
        // رسائل محادثة (since = الجديدة بس، read = علّمها مقروءة)
        ['GET', '#^/api/inbox/([\w-]+)/chat$#', function ($accId) {
            inbox_acc($accId);
            $jid = (string)($_GET['jid'] ?? '');
            if (!inbox_valid_jid($jid)) {
                json_err('محادثة غير صالحة');
            }
            $q = http_build_query(['jid' => $jid, 'since' => (int)($_GET['since'] ?? 0), 'read' => !empty($_GET['read']) ? '1' : '0']);
            $r = engine_json('GET', '/accounts/' . rawurlencode($accId) . '/inbox/chat?' . $q, null, 10);
            $st = $r['_status'] ?? 503;
            unset($r['_status']);
            json_out($r, $st === 200 ? 200 : $st);
        }],
        // صورة/صوت/فيديو/ملف من رسالة
        ['GET', '#^/api/inbox/([\w-]+)/media$#', function ($accId) {
            inbox_acc($accId);
            $jid = (string)($_GET['jid'] ?? '');
            $mid = (string)($_GET['mid'] ?? '');
            if (!inbox_valid_jid($jid) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $mid)) {
                json_err('طلب غير صالح');
            }
            [$st, $body] = engine_request('GET', '/accounts/' . rawurlencode($accId) . '/inbox/media?' . http_build_query(['jid' => $jid, 'mid' => $mid]), null, 60);
            if ($st !== 200 || !is_string($body)) {
                json_err(is_array($body) ? ($body['error'] ?? 'تعذر التحميل') : 'تعذر التحميل - تأكد إن الرقم متصل', 404);
            }
            $f = finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $body) ?: 'application/octet-stream';
            // صور/صوت/فيديو تنعرض، وأي شي ثاني ينزل كملف (حماية من ملفات HTML وغيرها)
            $inline = (bool)preg_match('#^(image/(jpeg|png|gif|webp)|audio/|video/)#', $f);
            header('Content-Type: ' . ($inline ? $f : 'application/octet-stream'));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment; filename="file"'));
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            echo $body;
            exit;
        }],
        // رد على محادثة أو مراسلة رقم جديد
        ['POST', '#^/api/inbox/([\w-]+)/send$#', function ($accId) {
            inbox_acc($accId);
            $uid = uid();
            $d = inbox_input();
            $text = trim((string)($d['text'] ?? ''));
            if (mb_strlen($text) > 4000) {
                json_err('الرسالة طويلة جداً (الحد 4000 حرف)');
            }
            $payload = [];
            $jid = (string)($d['jid'] ?? '');
            if ($jid !== '') {
                if (!inbox_valid_jid($jid)) {
                    json_err('محادثة غير صالحة');
                }
                $payload['jid'] = $jid;
            } else {
                $cc = valid_country_code((string)($d['country_code'] ?? '')) ? (string)$d['country_code'] : default_country_code();
                $phone = apply_country_code(preg_replace('/\D/', '', (string)($d['phone'] ?? '')), $cc);
                if (strlen($phone) < 8) {
                    json_err('اكتب رقم صحيح');
                }
                $payload['phone'] = $phone;
            }
            $media = read_upload_media();
            if ($text === '' && !$media) {
                json_err('اكتب رسالة أو أرفق ملف');
            }
            inbox_send_guard($uid, $accId, 1);
            $payload['text'] = with_trial_footer($uid, $text);
            $payload['media'] = $media;
            $r = engine_json('POST', '/accounts/' . rawurlencode($accId) . '/inbox/send', $payload, 90);
            $st = $r['_status'] ?? 503;
            unset($r['_status']);
            json_out($r, $st === 200 ? 200 : $st);
        }],
        // رسالة وحدة لمجموعة محادثات محددة (تمشي كحملة بفاصل حتى ما ينحظر الرقم)
        ['POST', '#^/api/inbox/([\w-]+)/broadcast$#', function ($accId) {
            inbox_acc($accId);
            $uid = uid();
            $d = inbox_input();
            $jids = $d['jids'] ?? [];
            if (is_string($jids)) {
                $jids = json_decode($jids, true) ?: [];
            }
            $jids = array_values(array_unique(array_filter(array_map('strval', (array)$jids), 'inbox_valid_jid')));
            if (!$jids) {
                json_err('حدد محادثة وحدة على الأقل');
            }
            if (count($jids) > 3000) {
                json_err('الحد 3000 محادثة بالمرة');
            }
            $text = trim((string)($d['text'] ?? ''));
            $media = read_upload_media();
            if ($text === '' && !$media) {
                json_err('اكتب رسالة أو أرفق ملف');
            }
            if (mb_strlen($text) > 4000) {
                json_err('الرسالة طويلة جداً (الحد 4000 حرف)');
            }
            inbox_send_guard($uid, $accId, count($jids));
            $delay = max(3, min(600, (int)($d['delay'] ?? DEFAULT_DELAY)));
            // ما نحتاج فحص واتساب: هذول محادثات موجودة أصلاً
            $r = engine_send_campaign($accId, $jids, with_trial_footer($uid, $text), $media, $delay, DEFAULT_MEDIA_DELAY, null, 'inbox', false);
            $st = $r['_status'] ?? 503;
            unset($r['_status']);
            json_out($r, $st === 200 ? 200 : ($st === 0 ? 503 : $st));
        }],
    ];
}
