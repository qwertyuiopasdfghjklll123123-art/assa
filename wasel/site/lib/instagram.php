<?php
/**
 * إنستغرام بتسجيل الدخول (اسم المستخدم + كلمة السر) - بدون API ولا App ID.
 * الشغل كله بالمحرك (wasel-engine/ig.mjs): يمسك الجلسة، يفحص الرسائل بهدوء، ويرسل.
 * هنا: الاشتراك، ملكية الحسابات، حد محاولات الدخول، والإشعارات.
 * كلمة السر تمر للمحرك وقت الدخول بس - ما تنحفظ لا هنا ولا هناك.
 */

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

/** ربط الحساب بصاحبه (المعرف هو نفسه بالمحرك) */
function ig_link(int $owner, string $code): array
{
    $l = store_get_doc('ig_links')[$code] ?? null;
    if (!$l || (int)$l['owner'] !== $owner) {
        json_err('الحساب غير موجود', 404);
    }
    return $l;
}

function ig_engine(string $method, string $path, $body = null, int $timeout = 30): array
{
    $r = engine_json($method, $path, $body, $timeout);
    $st = $r['_status'] ?? 503;
    unset($r['_status']);
    if ($st !== 200) {
        json_out($r + ['ok' => false], $st ?: 503);
    }
    return $r;
}

/** حد محاولات الدخول: 6 بالساعة لكل مستخدم (كثرة المحاولات تعرّض الحساب للقفل من إنستغرام) */
function ig_login_throttle(int $uid): void
{
    $now = time();
    $d = store_update_doc('ig_login_rl', function (array $d) use ($uid, $now) {
        $d[$uid] = array_values(array_filter($d[$uid] ?? [], fn($t) => $now - $t < 3600));
        $d[$uid][] = $now;
        return $d;
    });
    if (count($d[$uid]) > 6) {
        json_err('محاولات دخول كثيرة - انتظر ساعة حتى ما يقفل إنستغرام الحساب', 429);
    }
}

function ig_pub_acc(array $a): array
{
    $a['pic'] = !empty($a['has_pic']) ? '/api/ig/' . $a['id'] . '/pic?u=me' : null;
    unset($a['owner'], $a['has_pic']);
    return $a;
}

function ig_routes(): array
{
    return [
        ['GET', '#^/api/ig$#', function () {
            $id = require_login();
            $allowed = chats_allowed(user_by_id($id));
            $accs = [];
            if ($allowed) {
                $r = engine_json('GET', '/ig/list?owner=' . $id, null, 8);
                $links = store_get_doc('ig_links');
                foreach ($r['accounts'] ?? [] as $a) {
                    if ((int)($links[$a['id']]['owner'] ?? 0) === $id) {
                        $accs[] = ig_pub_acc($a);
                    }
                }
                if (($r['_status'] ?? 0) !== 200) {
                    json_out(['ok' => true, 'allowed' => true, 'accounts' => [], 'engine_error' => $r['error'] ?? 'المحرك غير متاح']);
                }
            }
            json_out(['ok' => true, 'allowed' => $allowed, 'accounts' => $accs]);
        }],
        // تسجيل الدخول: إما يتصل، أو يطلب رمز تحقق (2fa / challenge)
        ['POST', '#^/api/ig/login$#', function () {
            $id = require_chats();
            $d = request_json();
            $username = strtolower(ltrim(trim((string)($d['username'] ?? '')), '@'));
            $password = (string)($d['password'] ?? '');
            if (!preg_match('/^[a-z0-9._]{1,30}$/', $username) || $password === '') {
                json_err('اكتب اسم المستخدم وكلمة السر');
            }
            $proxy = trim((string)($d['proxy'] ?? ''));
            if ($proxy !== '' && !preg_match('#^(https?|socks5h?)://[^\s]{3,300}$#i', $proxy)) {
                json_err('البروكسي لازم يكون مثل http://user:pass@host:port');
            }
            $links = store_get_doc('ig_links');
            $code = null;
            foreach ($links as $c => $l) {
                if ((int)$l['owner'] === $id && $l['username'] === $username) {
                    $code = $c; // نفس الحساب: نعيد الدخول بنفس المعرف (نفس الجهاز)
                }
            }
            if (!$code && count(array_filter($links, fn($l) => (int)$l['owner'] === $id)) >= 10) {
                json_err('الحد 10 حسابات إنستغرام');
            }
            ig_login_throttle($id);
            $code = $code ?: bin2hex(random_bytes(6));
            $r = ig_engine('POST', '/ig/login', ['id' => $code, 'owner' => $id, 'username' => $username, 'password' => $password, 'proxy' => $proxy], 90);
            if (!empty($r['ok']) || !empty($r['need'])) {
                store_update_doc('ig_links', function (array $l) use ($code, $id, $username) {
                    $l[$code] = ['owner' => $id, 'username' => $username, 'at' => now_str()];
                    return $l;
                });
            }
            if (!empty($r['account'])) {
                $r['account'] = ig_pub_acc($r['account']);
            }
            json_out($r);
        }],
        ['POST', '#^/api/ig/([a-f0-9]{8,32})/verify$#', function ($code) {
            $id = require_chats();
            ig_link($id, $code);
            $r = ig_engine('POST', '/ig/' . $code . '/verify', ['code' => preg_replace('/\D/', '', (string)(request_json()['code'] ?? ''))], 90);
            if (!empty($r['account'])) {
                $r['account'] = ig_pub_acc($r['account']);
            }
            json_out($r);
        }],
        ['POST', '#^/api/ig/([a-f0-9]{8,32})/delete$#', function ($code) {
            $id = require_login();
            ig_link($id, $code);
            engine_json('POST', '/ig/' . $code . '/logout', []);
            store_update_doc('ig_links', function (array $l) use ($code) {
                unset($l[$code]);
                return $l;
            });
            json_out(['ok' => true]);
        }],
        ['POST', '#^/api/ig/([a-f0-9]{8,32})/sync$#', function ($code) {
            $id = require_chats();
            ig_link($id, $code);
            json_out(ig_engine('POST', '/ig/' . $code . '/sync', [], 60));
        }],
        ['GET', '#^/api/ig/([a-f0-9]{8,32})/convs$#', function ($code) {
            $id = require_chats();
            ig_link($id, $code);
            $r = ig_engine('GET', '/ig/' . $code . '/threads', null, 10);
            $r['account'] = ig_pub_acc($r['account']);
            $r['convs'] = array_map(function ($t) use ($code) {
                $t['pic'] = !empty($t['has_pic']) ? '/api/ig/' . $code . '/pic?u=' . rawurlencode((string)$t['pk']) : null;
                unset($t['has_pic']);
                return $t;
            }, $r['threads'] ?? []);
            unset($r['threads']);
            json_out($r);
        }],
        ['GET', '#^/api/ig/([a-f0-9]{8,32})/conv/([\w-]{1,64})$#', function ($code, $tid) {
            $id = require_chats();
            ig_link($id, $code);
            $q = http_build_query(['since' => (int)($_GET['since'] ?? 0), 'fresh' => !empty($_GET['fresh']) ? 1 : 0]);
            $r = ig_engine('GET', '/ig/' . $code . '/thread/' . $tid . '?' . $q, null, 40);
            $r['thread']['pic'] = !empty($r['thread']['has_pic']) ? '/api/ig/' . $code . '/pic?u=' . rawurlencode((string)$r['thread']['pk']) : null;
            unset($r['thread']['has_pic']);
            json_out($r);
        }],
        ['POST', '#^/api/ig/([a-f0-9]{8,32})/send$#', function ($code) {
            $id = require_chats();
            ig_link($id, $code);
            $d = request_json();
            $text = trim((string)($d['text'] ?? ''));
            if ($text === '' || mb_strlen($text) > 1000) {
                json_err($text === '' ? 'اكتب رسالة' : 'الحد 1000 حرف');
            }
            json_out(ig_engine('POST', '/ig/' . $code . '/send', ['tid' => preg_replace('/[^\w-]/', '', (string)($d['tid'] ?? '')), 'text' => $text], 60));
        }],
        ['POST', '#^/api/ig/([a-f0-9]{8,32})/approve$#', function ($code) {
            $id = require_chats();
            ig_link($id, $code);
            json_out(ig_engine('POST', '/ig/' . $code . '/approve', ['tid' => preg_replace('/[^\w-]/', '', (string)(request_json()['tid'] ?? ''))], 40));
        }],
        ['GET', '#^/api/ig/([a-f0-9]{8,32})/pic$#', function ($code) {
            $id = require_login();
            ig_link($id, $code);
            [$st, $body] = engine_request('GET', '/ig/' . $code . '/pic?' . http_build_query(['u' => preg_replace('/[^\w]/', '', (string)($_GET['u'] ?? ''))]), null, 15);
            if ($st !== 200 || !is_string($body) || $body === '') {
                header('Cache-Control: private, max-age=3600');
                http_response_code(204);
                exit;
            }
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            echo $body;
            exit;
        }],
    ];
}

/** المحرك: رسائل إنستغرام جديدة -> إشعار للجوال + جرس التطبيق */
function ig_on_messages(array $d): void
{
    $owner = (int)($d['owner'] ?? 0);
    $u = user_by_id($owner);
    if (!$u) {
        return;
    }
    $items = array_slice((array)($d['items'] ?? []), 0, 20);
    if (!$items) {
        return;
    }
    $acc = '@' . clean_text(mb_substr((string)($d['username'] ?? ''), 0, 40));
    $who = fn($i) => clean_text(mb_substr((string)($i['peer'] ?? 'شخص'), 0, 40)) ?: 'شخص';
    if (count($items) === 1) {
        $t = '📸 ' . $who($items[0]) . ' · ' . $acc;
        $b = clean_text(mb_substr((string)($items[0]['text'] ?? ''), 0, 160));
    } else {
        $t = '📸 ' . count($items) . ' رسائل إنستغرام · ' . $acc;
        $b = implode('، ', array_slice(array_unique(array_map($who, $items)), 0, 6));
    }
    add_event($owner, $acc, $t, $b, 'info', 'ig');
    if (notify_prefs($u)['replies'] || notify_prefs($u)['all_msgs']) {
        push_user($owner, $t, $b, '/app', 'ig');
    }
}
