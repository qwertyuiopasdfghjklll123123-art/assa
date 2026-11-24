<?php
/**
 * إشعارات الجوال (Web Push) بدون مكتبات:
 * مفاتيح VAPID تتولد أول مرة، السيرفر يرسل تنبيه فارغ، والتطبيق (sw.js) يسأل /api/push/inbox عن نص الإشعار.
 * تشتغل على أندرويد مباشرة، وعلى آيفون بعد تثبيت التطبيق على الشاشة الرئيسية (iOS 16.4+).
 */

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function push_cfg(): array
{
    $c = store_get_doc('push_cfg');
    if (empty($c['private'])) {
        $c = store_update_doc('push_cfg', function (array $d) {
            if (empty($d['private'])) {
                $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
                openssl_pkey_export($key, $pem);
                $e = openssl_pkey_get_details($key)['ec'];
                $d['private'] = $pem;
                $d['public'] = b64url("\x04" . str_pad($e['x'], 32, "\0", STR_PAD_LEFT) . str_pad($e['y'], 32, "\0", STR_PAD_LEFT));
            }
            return $d;
        });
    }
    return $c;
}

function push_jwt(string $endpoint): string
{
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $mail = settings()['admin_email'] ?? '';
    $data = b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . ($mail ?: 'admin@localhost')]));
    openssl_sign($data, $der, openssl_pkey_get_private(push_cfg()['private']), OPENSSL_ALGO_SHA256);
    // DER -> r||s (64 بايت)
    $pos = 2;
    $raw = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $raw .= str_pad(ltrim(substr($der, $pos + 2, $len), "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $data . '.' . b64url($raw);
}

function push_send_one(string $endpoint): int
{
    if (!preg_match('#^https://#', $endpoint) && !defined('PUSH_ALLOW_HTTP')) {
        return 0;
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['TTL: 3600', 'Urgency: high', 'Content-Length: 0', 'Authorization: vapid t=' . push_jwt($endpoint) . ', k=' . push_cfg()['public']]]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}

const NOTIFY_DEFAULTS = ['replies' => true, 'reads' => true, 'votes' => true, 'all_msgs' => false, 'campaigns' => true];

function notify_prefs(?array $u): array
{
    return array_merge(NOTIFY_DEFAULTS, array_intersect_key((array)($u['notify'] ?? []), NOTIFY_DEFAULTS));
}

/** يرسل إشعار لجوالات المستخدم (كل الأجهزة اللي فعّلت الإشعارات) */
function push_user(int $uid, string $title, string $body, string $url = '/app', string $tag = 'wasel'): int
{
    $subs = array_filter(store_get_doc('push_subs'), fn($s) => (int)($s['uid'] ?? 0) === $uid);
    if (!$subs) {
        return 0;
    }
    $msg = ['title' => mb_substr($title, 0, 80), 'body' => mb_substr($body, 0, 240), 'url' => $url, 'tag' => $tag, 't' => time()];
    store_update_doc('push_inbox', function (array $d) use ($subs, $msg) {
        foreach (array_keys($subs) as $id) {
            $box = array_filter($d[$id] ?? [], fn($m) => time() - ($m['t'] ?? 0) < 6 * 3600);
            $box[] = $msg;
            $d[$id] = array_slice(array_values($box), -8);
        }
        return $d;
    });
    $sent = 0;
    $dead = [];
    foreach ($subs as $id => $s) {
        $code = push_send_one($s['endpoint']);
        if ($code >= 200 && $code < 300) {
            $sent++;
        } elseif (in_array($code, [404, 410], true)) {
            $dead[] = $id;
        }
    }
    if ($dead) {
        store_update_doc('push_subs', function (array $d) use ($dead) {
            foreach ($dead as $id) {
                unset($d[$id]);
            }
            return $d;
        });
    }
    return $sent;
}

function push_sub_id(string $endpoint): string
{
    return substr(hash('sha256', $endpoint), 0, 24);
}

function push_routes(): array
{
    return [
        ['GET', '#^/api/push/key$#', function () {
            require_login();
            json_out(['ok' => true, 'key' => push_cfg()['public'], 'prefs' => notify_prefs(user_by_id(uid()))]);
        }],
        ['POST', '#^/api/push/subscribe$#', function () {
            $id = require_login();
            $ep = (string)(request_json()['endpoint'] ?? '');
            if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?/#i', $ep) || strlen($ep) > 700) {
                json_err('اشتراك غير صالح');
            }
            store_update_doc('push_subs', function (array $d) use ($id, $ep) {
                $d[push_sub_id($ep)] = ['uid' => $id, 'endpoint' => $ep, 'at' => now_str()];
                // حد 10 أجهزة لكل مستخدم
                $mine = array_keys(array_filter($d, fn($s) => (int)$s['uid'] === $id));
                foreach (array_slice($mine, 0, max(0, count($mine) - 10)) as $old) {
                    unset($d[$old]);
                }
                return $d;
            });
            json_out(['ok' => true]);
        }],
        ['POST', '#^/api/push/unsubscribe$#', function () {
            $id = require_login();
            $sid = push_sub_id((string)(request_json()['endpoint'] ?? ''));
            store_update_doc('push_subs', function (array $d) use ($sid, $id) {
                if ((int)($d[$sid]['uid'] ?? 0) === $id) {
                    unset($d[$sid]);
                }
                return $d;
            });
            json_out(['ok' => true]);
        }],
        ['POST', '#^/api/push/test$#', function () {
            $id = require_login();
            json_out(['ok' => true, 'sent' => push_user($id, '✅ الإشعارات شغّالة', 'راح يوصلك إشعار لما أحد يرد أو يشوف رسالتك أو يضغط زر', '/app')]);
        }],
        ['POST', '#^/api/push/prefs$#', function () {
            $id = require_login();
            $d = request_json();
            $p = [];
            foreach (NOTIFY_DEFAULTS as $k => $def) {
                $p[$k] = array_key_exists($k, $d) ? !empty($d[$k]) : notify_prefs(user_by_id($id))[$k];
            }
            update_user($id, ['notify' => $p]);
            json_out(['ok' => true, 'prefs' => $p]);
        }],
        // عامل الخدمة ياخذ نص الإشعار (بالـ endpoint السري - بدون جلسة)
        ['POST', '#^/api/push/inbox$#', function () {
            $sid = push_sub_id((string)(request_json()['endpoint'] ?? ''));
            $msgs = [];
            store_update_doc('push_inbox', function (array $d) use ($sid, &$msgs) {
                $msgs = $d[$sid] ?? [];
                unset($d[$sid]);
                return $d;
            });
            json_out(['ok' => true, 'messages' => array_values($msgs)]);
        }],
    ];
}
