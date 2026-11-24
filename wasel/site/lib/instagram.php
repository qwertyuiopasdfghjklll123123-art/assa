<?php
/**
 * إنستغرام: ربط أكثر من حساب (تسجيل دخول رسمي من إنستغرام - بدون كلمة السر)، عرض كل المحادثات
 * والطلبات وحفظها، صور وأسماء المستخدمين، والرد المباشر.
 *
 * يستخدم Instagram API with Instagram Login (الرسمي من Meta):
 * - الحساب لازم يكون «احترافي» (أعمال أو صانع محتوى) - مجاني من إعدادات إنستغرام.
 * - الأدمن يسوي تطبيق بـ developers.facebook.com ويحط App ID و App Secret بالإعدادات.
 * الرسائل الجديدة توصل فوراً عبر Webhook، وكل شي ينحفظ عندنا (حتى لو انحذف من إنستغرام).
 */

if (!defined('IG_AUTH_BASE')) {
    define('IG_AUTH_BASE', 'https://www.instagram.com');
}
if (!defined('IG_API_BASE')) {
    define('IG_API_BASE', 'https://api.instagram.com');
}
if (!defined('IG_GRAPH_BASE')) {
    define('IG_GRAPH_BASE', 'https://graph.instagram.com');
}
const IG_VER = 'v21.0';
const IG_SCOPES = 'instagram_business_basic,instagram_business_manage_messages';

// ---------------------------------------------------------------- الإعدادات والاشتراك
function ig_cfg(): array
{
    $s = settings();
    return ['app_id' => (string)($s['ig_app_id'] ?? ''), 'secret' => (string)($s['ig_app_secret'] ?? ''), 'verify' => (string)($s['ig_verify_token'] ?? '')];
}

function ig_configured(): bool
{
    $c = ig_cfg();
    return $c['app_id'] !== '' && $c['secret'] !== '';
}

/** المحادثات (واتساب وإنستغرام) تشتغل بالاشتراك: باقة فعّالة أو أدمن */
function chats_allowed(?array $u): bool
{
    return $u && (!empty($u['is_admin']) || effective_plan_active($u));
}

function require_chats(): int
{
    $id = require_login();
    if (!chats_allowed(user_by_id($id))) {
        json_err('قسم المحادثات يحتاج اشتراك فعّال - اشترك بباقة من قسم الباقات', 402, ['needs_plan' => true]);
    }
    return $id;
}

function ig_redirect_uri(): string
{
    return site_base_url() . '/ig/callback';
}

// ---------------------------------------------------------------- تشفير التوكن بالملفات
function ig_key(): string
{
    return hash('sha256', 'ig|' . ENGINE_SECRET . '|' . CRON_KEY . '|' . (ig_cfg()['secret']), true);
}

function ig_enc(string $plain): string
{
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', ig_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ct);
}

function ig_dec(string $blob): string
{
    $b = base64_decode($blob, true);
    if ($b === false || strlen($b) < 29) {
        return '';
    }
    return (string)openssl_decrypt(substr($b, 28), 'aes-256-gcm', ig_key(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
}

// ---------------------------------------------------------------- طلبات API
function ig_http(string $method, string $url, ?array $form = null, ?string $token = null, ?array $json = null): array
{
    $ch = curl_init($url);
    $h = ['Accept: application/json'];
    if ($token) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    if ($json !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    } elseif ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $h]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $d = json_decode((string)$raw, true);
    if (!is_array($d)) {
        $d = ['error' => ['message' => $raw === false ? 'تعذر الاتصال بإنستغرام' : 'رد غير متوقع من إنستغرام']];
    }
    $d['_code'] = $code;
    return $d;
}

function ig_err(array $r): string
{
    $m = (string)($r['error']['message'] ?? $r['error_message'] ?? 'خطأ من إنستغرام');
    if (str_contains($m, 'outside of allowed window') || (int)($r['error']['code'] ?? 0) === 10) {
        return 'إنستغرام يسمح بالرد خلال 24 ساعة من آخر رسالة من الشخص بس';
    }
    return mb_substr($m, 0, 200);
}

function ig_graph(array $acc, string $path, array $q = [], string $method = 'GET', ?array $json = null): array
{
    $tok = ig_dec((string)$acc['token']);
    $url = IG_GRAPH_BASE . '/' . IG_VER . '/' . ltrim($path, '/') . ($q ? '?' . http_build_query($q) : '');
    return ig_http($method, $url, null, $tok, $json);
}

// ---------------------------------------------------------------- الحسابات
function ig_acc(int $owner, $id): array
{
    $a = store_find('ig_accounts', (int)$id);
    if (!$a || (int)$a['owner'] !== $owner) {
        json_err('الحساب غير موجود', 404);
    }
    return $a;
}

function ig_public_acc(array $a): array
{
    return ['id' => $a['id'], 'ig_id' => $a['ig_id'], 'username' => $a['username'], 'name' => $a['name'] ?? '', 'type' => $a['account_type'] ?? '',
        'followers' => $a['followers'] ?? null, 'pic' => !empty($a['picture']) ? '/api/ig/' . $a['id'] . '/pic?u=me' : null,
        'last_sync' => $a['last_sync'] ?? null, 'expires' => $a['token_expires'] ?? null, 'unread' => ig_unread_count((int)$a['id'])];
}

// ---------------------------------------------------------------- تخزين المحادثات (ملف لكل حساب)
function ig_doc(int $accId): array
{
    return store_get_doc('igc_' . $accId, ['convs' => [], 'msgs' => []]);
}

function ig_doc_update(int $accId, callable $fn): array
{
    return store_update_doc('igc_' . $accId, $fn, ['convs' => [], 'msgs' => []]);
}

function ig_unread_count(int $accId): int
{
    $n = 0;
    foreach (ig_doc($accId)['convs'] as $c) {
        $n += (int)($c['unread'] ?? 0);
    }
    return $n;
}

/** نص مختصر للمرفقات */
function ig_msg_text(array $m): array
{
    $text = (string)($m['message'] ?? $m['text'] ?? '');
    $att = $m['attachments']['data'][0] ?? $m['attachments'][0] ?? null;
    $type = 'text';
    $url = null;
    if ($att) {
        $type = isset($att['image_data']) || ($att['type'] ?? '') === 'image' ? 'image' : (isset($att['video_data']) || ($att['type'] ?? '') === 'video' ? 'video' : (($att['type'] ?? '') === 'audio' ? 'audio' : 'file'));
        $url = $att['image_data']['url'] ?? $att['video_data']['url'] ?? $att['payload']['url'] ?? $att['file_url'] ?? null;
    }
    if ($url !== null && !preg_match('#^https://#i', (string)$url) && !defined('IG_ALLOW_HTTP')) {
        $url = null; // روابط https بس (حماية)
    }
    if (!empty($m['story']) || isset($m['reply_to']['story'])) {
        $type = $text === '' ? 'story' : $type;
    }
    return [$text, $type, $url];
}

/** يضيف رسالة لمحادثة (بالمفتاح = رقم الشخص IGSID) - بدون تكرار */
function ig_add_msg(array &$doc, string $peer, array $msg, array $peerInfo = [], bool $countUnread = false): void
{
    $c = $doc['convs'][$peer] ?? ['peer' => $peer, 'username' => '', 'name' => '', 'pic' => '', 'ts' => 0, 'unread' => 0, 'last' => null, 'request' => false, 'incoming' => false];
    foreach (['username', 'name', 'pic', 'conv_id'] as $k) {
        if (!empty($peerInfo[$k])) {
            $c[$k] = $peerInfo[$k];
        }
    }
    if (array_key_exists('request', $peerInfo)) {
        $c['request'] = (bool)$peerInfo['request'];
    }
    $list = $doc['msgs'][$peer] ?? [];
    foreach ($list as $x) {
        if ($x['id'] === $msg['id']) {
            $doc['convs'][$peer] = $c;
            return;
        }
    }
    $list[] = $msg;
    usort($list, fn($a, $b) => $a['ts'] <=> $b['ts']);
    $doc['msgs'][$peer] = array_slice($list, -300); // آخر 300 رسالة لكل محادثة
    if (!$msg['me']) {
        $c['incoming'] = true;
        if ($countUnread) {
            $c['unread'] = (int)$c['unread'] + 1;
        }
    } else {
        $c['unread'] = 0;
    }
    if ($msg['ts'] >= (int)$c['ts']) {
        $c['ts'] = $msg['ts'];
        $c['last'] = ['me' => $msg['me'], 'type' => $msg['type'], 'text' => mb_substr($msg['text'], 0, 120)];
    }
    $doc['convs'][$peer] = $c;
}

// ---------------------------------------------------------------- المزامنة (من API)
/** يجيب المحادثات وآخر رسائلها + الطلبات (pending) */
function ig_sync(array $acc, int $maxConvs = 100): array
{
    $me = (string)$acc['ig_id'];
    $seen = 0;
    $err = null;
    $doc = ig_doc((int)$acc['id']);
    foreach (['' => false, 'pending' => true] as $folder => $isReq) {
        $q = ['platform' => 'instagram', 'fields' => 'id,updated_time,participants,messages.limit(25){id,created_time,from,to,message,attachments}', 'limit' => 25];
        if ($folder) {
            $q['folder'] = $folder;
        }
        $path = 'me/conversations';
        for ($page = 0; $page < 8 && $seen < $maxConvs; $page++) {
            $r = ig_graph($acc, $path, $q);
            if (!empty($r['error'])) {
                if (!$folder) {
                    $err = ig_err($r);
                }
                break; // مجلد الطلبات ممكن ما يكون مدعوم بكل الحسابات - نكمّل
            }
            foreach ($r['data'] ?? [] as $cv) {
                $seen++;
                $peer = null;
                foreach ($cv['participants']['data'] ?? [] as $p) {
                    if ((string)($p['id'] ?? '') !== $me && ($p['username'] ?? '') !== $acc['username']) {
                        $peer = $p;
                    }
                }
                if (!$peer || empty($peer['id'])) {
                    continue;
                }
                $info = ['username' => $peer['username'] ?? '', 'conv_id' => $cv['id'], 'request' => $isReq];
                $msgs = $cv['messages']['data'] ?? [];
                if (!$msgs) {
                    $doc['convs'][$peer['id']] = array_merge($doc['convs'][$peer['id']] ?? ['peer' => $peer['id'], 'ts' => strtotime($cv['updated_time'] ?? 'now'), 'unread' => 0, 'last' => null, 'incoming' => false, 'name' => '', 'pic' => ''], $info);
                }
                foreach ($msgs as $m) {
                    [$text, $type, $url] = ig_msg_text($m);
                    ig_add_msg($doc, (string)$peer['id'], ['id' => (string)$m['id'], 'me' => (string)($m['from']['id'] ?? '') === $me || ($m['from']['username'] ?? '') === $acc['username'],
                        'ts' => strtotime($m['created_time'] ?? 'now') ?: time(), 'type' => $type, 'text' => $text, 'url' => $url], $info);
                }
            }
            $next = $r['paging']['cursors']['after'] ?? null;
            if (!$next || empty($r['paging']['next'])) {
                break;
            }
            $q['after'] = $next;
        }
    }
    // الأسماء والصور للي ما عندنا صورتهم (حد 40 بكل مزامنة)
    $n = 0;
    foreach ($doc['convs'] as $pid => &$c) {
        if ($n >= 40 || (!empty($c['pic_at']) && time() - $c['pic_at'] < 3 * 86400)) {
            continue;
        }
        $n++;
        $p = ig_graph($acc, (string)$pid, ['fields' => 'name,username,profile_pic']);
        $c['pic_at'] = time();
        if (empty($p['error'])) {
            $c['name'] = clean_text(mb_substr((string)($p['name'] ?? ''), 0, 60)) ?: ($c['name'] ?? '');
            $c['username'] = (string)($p['username'] ?? $c['username'] ?? '');
            $c['pic'] = (string)($p['profile_pic'] ?? '');
        }
    }
    unset($c);
    ig_doc_update((int)$acc['id'], function (array $d) use ($doc) {
        // ندمج (حتى ما نخسر رسائل وصلت بالـ Webhook أثناء المزامنة)
        foreach ($doc['convs'] as $pid => $c) {
            foreach ($doc['msgs'][$pid] ?? [] as $m) {
                ig_add_msg($d, (string)$pid, $m, $c);
            }
            $d['convs'][$pid] = array_merge($d['convs'][$pid] ?? [], array_intersect_key($c, array_flip(['peer', 'username', 'name', 'pic', 'pic_at', 'conv_id', 'request'])))
                + ['peer' => $pid, 'ts' => $c['ts'] ?? 0, 'unread' => 0, 'last' => $c['last'] ?? null, 'incoming' => $c['incoming'] ?? false];
        }
        return $d;
    });
    store_update('ig_accounts', (int)$acc['id'], fn($a) => array_merge($a, ['last_sync' => now_str()]));
    return ['ok' => $err === null, 'error' => $err, 'convs' => $seen];
}

/** نجدد التوكن قبل ما ينتهي (60 يوم) - من Cron */
function ig_refresh_tokens(): int
{
    $n = 0;
    foreach (store_all('ig_accounts') as $a) {
        if (empty($a['token_expires']) || strtotime($a['token_expires']) - time() > 15 * 86400) {
            continue;
        }
        $r = ig_http('GET', IG_GRAPH_BASE . '/refresh_access_token?' . http_build_query(['grant_type' => 'ig_refresh_token', 'access_token' => ig_dec((string)$a['token'])]));
        if (!empty($r['access_token'])) {
            store_update('ig_accounts', (int)$a['id'], fn($x) => array_merge($x, ['token' => ig_enc($r['access_token']), 'token_expires' => date('Y-m-d H:i', time() + (int)($r['expires_in'] ?? 5184000))]));
            $n++;
        } elseif (strtotime($a['token_expires']) < time()) {
            add_event((int)$a['owner'], null, 'انتهى ربط إنستغرام @' . $a['username'], 'اربط الحساب من جديد من حسابي ← إنستغرام', 'warning', 'ig');
        }
    }
    return $n;
}

// ---------------------------------------------------------------- Webhook: الرسائل توصل فوراً
function ig_webhook(): void
{
    $cfg = ig_cfg();
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['hub_mode'] ?? '') === 'subscribe' && $cfg['verify'] !== '' && hash_equals($cfg['verify'], (string)($_GET['hub_verify_token'] ?? ''))) {
            header('Content-Type: text/plain');
            echo (string)($_GET['hub_challenge'] ?? '');
            exit;
        }
        http_response_code(403);
        exit;
    }
    $raw = (string)file_get_contents('php://input');
    $sig = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    if ($cfg['secret'] === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $cfg['secret']), $sig)) {
        http_response_code(403);
        exit;
    }
    $d = json_decode($raw, true) ?: [];
    $byIg = [];
    foreach (store_all('ig_accounts') as $a) {
        $byIg[(string)$a['ig_id']][] = $a;
    }
    foreach ($d['entry'] ?? [] as $e) {
        foreach ($byIg[(string)($e['id'] ?? '')] ?? [] as $acc) {
            $notify = [];
            ig_doc_update((int)$acc['id'], function (array $doc) use ($e, $acc, &$notify) {
                foreach ($e['messaging'] ?? [] as $ev) {
                    $me = (string)$acc['ig_id'];
                    $from = (string)($ev['sender']['id'] ?? '');
                    $to = (string)($ev['recipient']['id'] ?? '');
                    $peer = $from === $me ? $to : $from;
                    if ($peer === '') {
                        continue;
                    }
                    if (isset($ev['read'])) {
                        // شاف رسالتنا
                        if (isset($doc['convs'][$peer])) {
                            $doc['convs'][$peer]['seen_at'] = (int)(($ev['timestamp'] ?? time() * 1000) / 1000);
                        }
                        continue;
                    }
                    $m = $ev['message'] ?? null;
                    if (!$m || !empty($m['is_deleted']) || !empty($m['is_echo']) && $from !== $me) {
                        continue;
                    }
                    [$text, $type, $url] = ig_msg_text($m);
                    ig_add_msg($doc, $peer, ['id' => (string)($m['mid'] ?? uniqid('w', true)), 'me' => $from === $me, 'ts' => (int)(($ev['timestamp'] ?? time() * 1000) / 1000), 'type' => $type, 'text' => $text, 'url' => $url], [], $from !== $me);
                    if ($from !== $me) {
                        $notify[] = [$peer, $text ?: ['image' => '📷 صورة', 'video' => '🎥 فيديو', 'audio' => '🎤 صوت', 'story' => '📖 رد على ستوري'][$type] ?? 'مرفق'];
                    }
                }
                return $doc;
            });
            if ($notify) {
                $doc = ig_doc((int)$acc['id']);
                [$peer, $txt] = $notify[count($notify) - 1];
                $c = $doc['convs'][$peer] ?? [];
                $who = ($c['username'] ?? '') !== '' ? '@' . $c['username'] : ($c['name'] ?? 'شخص');
                if (notify_prefs(user_by_id((int)$acc['owner']))['replies']) {
                    push_user((int)$acc['owner'], '📸 ' . $who . (count($notify) > 1 ? ' (+' . (count($notify) - 1) . ')' : ''), mb_substr($txt, 0, 160) . ' · @' . $acc['username'], '/app', 'ig');
                }
            }
        }
    }
    json_out(['ok' => true]);
}

// ---------------------------------------------------------------- المسارات
function ig_routes(): array
{
    return [
        // الحالة: مسموح؟ مضبوط؟ الحسابات المربوطة
        ['GET', '#^/api/ig$#', function () {
            $id = require_login();
            $u = user_by_id($id);
            $accs = array_values(array_filter(store_all('ig_accounts'), fn($a) => (int)$a['owner'] === $id));
            json_out(['ok' => true, 'allowed' => chats_allowed($u), 'configured' => ig_configured(), 'accounts' => array_map('ig_public_acc', $accs),
                'admin' => !empty($u['is_admin']) ? ['redirect_uri' => ig_redirect_uri(), 'webhook' => site_base_url() . '/ig/webhook', 'verify_token' => ig_cfg()['verify']] : null]);
        }],
        // بدء الربط: تحويل لصفحة دخول إنستغرام الرسمية
        ['GET', '#^/ig/connect$#', function () {
            $id = require_login();
            if (!chats_allowed(user_by_id($id))) {
                redirect_to('/app#wallet');
            }
            if (!ig_configured()) {
                redirect_to('/app#ig');
            }
            if (count(array_filter(store_all('ig_accounts'), fn($a) => (int)$a['owner'] === $id)) >= 10) {
                redirect_to('/app#ig');
            }
            $_SESSION['ig_state'] = bin2hex(random_bytes(16));
            redirect_to(IG_AUTH_BASE . '/oauth/authorize?' . http_build_query(['client_id' => ig_cfg()['app_id'], 'redirect_uri' => ig_redirect_uri(),
                'response_type' => 'code', 'scope' => IG_SCOPES, 'state' => $_SESSION['ig_state'], 'enable_fb_login' => 0, 'force_authentication' => 1]));
        }],
        // رجوع من إنستغرام: نبدّل الرمز بتوكن طويل (60 يوم) ونحفظ الحساب
        ['GET', '#^/ig/callback$#', function () {
            $id = require_login();
            $state = (string)($_SESSION['ig_state'] ?? '');
            unset($_SESSION['ig_state']);
            if ($state === '' || !hash_equals($state, (string)($_GET['state'] ?? '')) || empty($_GET['code'])) {
                add_event($id, null, 'ما انربط حساب إنستغرام', !empty($_GET['error']) ? 'ألغيت الربط من إنستغرام' : 'انتهت صلاحية الطلب - جرب مرة ثانية', 'warning', 'ig');
                redirect_to('/app#ig');
            }
            $cfg = ig_cfg();
            $r = ig_http('POST', IG_API_BASE . '/oauth/access_token', ['client_id' => $cfg['app_id'], 'client_secret' => $cfg['secret'], 'grant_type' => 'authorization_code',
                'redirect_uri' => ig_redirect_uri(), 'code' => preg_replace('/#_$/', '', (string)$_GET['code'])]);
            $short = $r['access_token'] ?? ($r['data'][0]['access_token'] ?? null);
            if (!$short) {
                add_event($id, null, 'ما انربط حساب إنستغرام', ig_err($r), 'warning', 'ig');
                redirect_to('/app#ig');
            }
            $l = ig_http('GET', IG_GRAPH_BASE . '/access_token?' . http_build_query(['grant_type' => 'ig_exchange_token', 'client_secret' => $cfg['secret'], 'access_token' => $short]));
            $tok = $l['access_token'] ?? $short;
            $exp = date('Y-m-d H:i', time() + (int)($l['expires_in'] ?? 3600));
            $tmp = ['token' => ig_enc($tok)];
            $me = ig_graph($tmp, 'me', ['fields' => 'user_id,username,name,profile_picture_url,account_type,followers_count']);
            if (!empty($me['error']) || empty($me['username'])) {
                add_event($id, null, 'ما انربط حساب إنستغرام', ig_err($me), 'warning', 'ig');
                redirect_to('/app#ig');
            }
            $igId = (string)($me['user_id'] ?? $me['id']);
            $row = ['owner' => $id, 'ig_id' => $igId, 'username' => (string)$me['username'], 'name' => clean_text(mb_substr((string)($me['name'] ?? ''), 0, 60)),
                'picture' => (string)($me['profile_picture_url'] ?? ''), 'account_type' => (string)($me['account_type'] ?? ''), 'followers' => $me['followers_count'] ?? null,
                'token' => ig_enc($tok), 'token_expires' => $exp, 'created_at' => now_str()];
            $old = store_first('ig_accounts', fn($a) => (int)$a['owner'] === $id && (string)$a['ig_id'] === $igId);
            $accId = $old ? (int)$old['id'] : (int)store_insert('ig_accounts', $row);
            if ($old) {
                store_update('ig_accounts', $accId, fn($a) => array_merge($a, $row));
            }
            // نشترك بالرسائل الفورية (Webhook)
            ig_graph($row, 'me/subscribed_apps', ['subscribed_fields' => 'messages,message_reactions,messaging_seen'], 'POST');
            add_event($id, null, 'انربط إنستغرام @' . $row['username'], 'جاري جلب المحادثات...', 'success', 'ig');
            ig_sync(store_find('ig_accounts', $accId), 60);
            redirect_to('/app#ig');
        }],
        ['POST', '#^/api/ig/(\d+)/delete$#', function ($aid) {
            $id = require_login();
            ig_acc($id, $aid);
            store_delete('ig_accounts', (int)$aid);
            @unlink(DATA_DIR . '/igc_' . (int)$aid . '.json');
            json_out(['ok' => true]);
        }],
        ['POST', '#^/api/ig/(\d+)/sync$#', function ($aid) {
            $id = require_chats();
            json_out(ig_sync(ig_acc($id, $aid)));
        }],
        // المحادثات (من المحفوظ عندنا)
        ['GET', '#^/api/ig/(\d+)/convs$#', function ($aid) {
            $id = require_chats();
            $acc = ig_acc($id, $aid);
            $doc = ig_doc((int)$acc['id']);
            $list = array_values($doc['convs']);
            usort($list, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
            json_out(['ok' => true, 'account' => ig_public_acc($acc), 'convs' => array_map(fn($c) => ['peer' => (string)$c['peer'], 'username' => $c['username'] ?? '', 'name' => $c['name'] ?? '',
                'pic' => !empty($c['pic']) ? '/api/ig/' . $acc['id'] . '/pic?u=' . rawurlencode((string)$c['peer']) : null, 'ts' => (int)($c['ts'] ?? 0), 'unread' => (int)($c['unread'] ?? 0),
                'last' => $c['last'] ?? null, 'request' => !empty($c['request']), 'incoming' => !empty($c['incoming']), 'seen' => !empty($c['seen_at']) && !empty($c['last']['me']) && $c['seen_at'] >= ($c['ts'] ?? 0)], array_slice($list, 0, 1500))]);
        }],
        // رسائل محادثة + نعلّمها مقروءة عندنا
        ['GET', '#^/api/ig/(\d+)/conv/(\d+)$#', function ($aid, $peer) {
            $id = require_chats();
            $acc = ig_acc($id, $aid);
            $since = (int)($_GET['since'] ?? 0);
            $doc = ig_doc_update((int)$acc['id'], function (array $d) use ($peer) {
                if (isset($d['convs'][$peer])) {
                    $d['convs'][$peer]['unread'] = 0;
                }
                return $d;
            });
            $c = $doc['convs'][$peer] ?? json_err('المحادثة غير موجودة', 404);
            json_out(['ok' => true, 'conv' => ['peer' => (string)$peer, 'username' => $c['username'] ?? '', 'name' => $c['name'] ?? '', 'request' => !empty($c['request']),
                'pic' => !empty($c['pic']) ? '/api/ig/' . $acc['id'] . '/pic?u=' . $peer : null, 'seen_at' => (int)($c['seen_at'] ?? 0)],
                'messages' => array_values(array_filter($doc['msgs'][$peer] ?? [], fn($m) => $m['ts'] >= $since))]);
        }],
        // رد مباشر
        ['POST', '#^/api/ig/(\d+)/send$#', function ($aid) {
            $id = require_chats();
            $acc = ig_acc($id, $aid);
            $d = request_json();
            $peer = preg_replace('/\D/', '', (string)($d['peer'] ?? ''));
            $text = trim((string)($d['text'] ?? ''));
            if ($peer === '' || $text === '') {
                json_err('اكتب رسالة');
            }
            if (mb_strlen($text) > 1000) {
                json_err('الحد 1000 حرف (حد إنستغرام)');
            }
            $r = ig_graph($acc, 'me/messages', [], 'POST', ['recipient' => ['id' => $peer], 'message' => ['text' => $text]]);
            if (!empty($r['error']) || empty($r['message_id'])) {
                json_err(ig_err($r));
            }
            ig_doc_update((int)$acc['id'], function (array $doc) use ($peer, $r, $text) {
                ig_add_msg($doc, $peer, ['id' => (string)$r['message_id'], 'me' => true, 'ts' => time(), 'type' => 'text', 'text' => $text, 'url' => null]);
                return $doc;
            });
            json_out(['ok' => true, 'id' => $r['message_id']]);
        }],
        // الصور (حسابنا أو الأشخاص) - نحفظها عندنا 3 أيام لأن روابط إنستغرام تنتهي
        ['GET', '#^/api/ig/(\d+)/pic$#', function ($aid) {
            $id = require_login();
            $acc = ig_acc($id, $aid);
            $u = (string)($_GET['u'] ?? '');
            $url = $u === 'me' ? ($acc['picture'] ?? '') : (ig_doc((int)$acc['id'])['convs'][preg_replace('/\D/', '', $u)]['pic'] ?? '');
            if (!preg_match('#^https://#', (string)$url) && !defined('IG_ALLOW_HTTP')) {
                http_response_code(204);
                exit;
            }
            $dir = DATA_DIR . '/ig_pics';
            if (!is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $f = $dir . '/' . sha1($url);
            if (!is_file($f) || time() - filemtime($f) > 3 * 86400) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3]);
                $img = curl_exec($ch);
                $ok = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
                curl_close($ch);
                if (!$ok || !$img || strlen($img) > 3 * 1024 * 1024 || !preg_match('#^image/#', (string)finfo_buffer(finfo_open(FILEINFO_MIME_TYPE), $img))) {
                    http_response_code(204);
                    exit;
                }
                file_put_contents($f, $img);
            }
            header('Content-Type: ' . finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f));
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            readfile($f);
            exit;
        }],
        ['GET', '#^/ig/webhook$#', 'ig_webhook'],
        ['POST', '#^/ig/webhook$#', 'ig_webhook'],
    ];
}
