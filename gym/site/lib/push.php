<?php
/**
 * الإشعارات (Web Push) بدون مكتبات:
 * - مفاتيح VAPID (P-256) تتولد أول مرة وتنحفظ بـ data/config.json
 * - نرسل "دفعة فارغة" (بدون تشفير محتوى) للمتصفح، وهو يسأل الموقع عن نص الإشعار من «صندوق الإشعارات»
 *   (هذي الطريقة تشتغل على أندرويد وآيفون (تطبيق مثبّت) وكل المتصفحات)
 */
declare(strict_types=1);

function b64url(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_dec(string $s): string
{
    return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** يولد مفتاحين: الخاص (PEM) والعام (65 بايت base64url للمتصفح) */
function vapid_generate(): array
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_pkey_export($key, $pem);
    $d = openssl_pkey_get_details($key)['ec'];
    $pub = "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    return [$pem, b64url($pub)];
}

/** توقيع ES256 بصيغة JWT (توقيع DER من openssl نحوله لـ r||s بطول 64) */
function es256_der_to_raw(string $der): string
{
    $pos = 2; // SEQUENCE + الطول (التوقيع دائماً أقل من 128 بايت)
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $int = ltrim($int, "\0");
        $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function vapid_jwt(string $endpoint): string
{
    $u = parse_url($endpoint);
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $sub = 'mailto:' . (config()['contact'] ?? 'admin@localhost');
    $data = b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' . b64url(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub]));
    openssl_sign($data, $der, openssl_pkey_get_private(config()['vapid_private']), OPENSSL_ALGO_SHA256);
    return $data . '.' . b64url(es256_der_to_raw($der));
}

/** يرسل تنبيه للمتصفح. يرجع رمز HTTP (201 = وصل، 404/410 = الاشتراك انتهى) */
function push_send(string $endpoint, int $ttl = 600): int
{
    if (!preg_match('#^https://#', $endpoint) && !defined('PUSH_ALLOW_HTTP')) {
        return 0;
    }
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['TTL: ' . $ttl, 'Urgency: high', 'Content-Length: 0',
            'Authorization: vapid t=' . vapid_jwt($endpoint) . ', k=' . config()['vapid_public']],
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return $code;
}

function sub_id(string $endpoint): string
{
    return substr(hash('sha256', $endpoint), 0, 24);
}

/** يرسل إشعار لكل أجهزة المستخدم (ويحفظ نصه بالصندوق حتى المتصفح ياخذه) */
function notify_user(int $uid, string $title, string $body, string $tag = 'general', string $url = '/'): int
{
    $subs = array_filter(db_read('push_subs'), fn($s) => (int)$s['uid'] === $uid);
    if (!$subs) {
        return 0;
    }
    $msg = ['title' => $title, 'body' => $body, 'tag' => $tag, 'url' => $url, 't' => time()];
    db_update('push_inbox', function (array $d) use ($subs, $msg) {
        foreach ($subs as $s) {
            $id = sub_id($s['endpoint']);
            $box = array_filter($d[$id] ?? [], fn($m) => time() - ($m['t'] ?? 0) < 3600); // الرسائل الأقدم من ساعة تنحذف
            $box[] = $msg;
            $d[$id] = array_slice(array_values($box), -10);
        }
        return $d;
    });
    $sent = 0;
    $dead = [];
    foreach ($subs as $id => $s) {
        $code = push_send($s['endpoint']);
        if ($code >= 200 && $code < 300) {
            $sent++;
        } elseif (in_array($code, [404, 410], true)) {
            $dead[] = $id;
        }
    }
    if ($dead) {
        db_update('push_subs', function (array $d) use ($dead) {
            foreach ($dead as $id) {
                unset($d[$id]);
            }
            return $d;
        });
    }
    return $sent;
}

/** المتصفح يسأل: شنو نص الإشعار؟ (بالـ endpoint مالته - سرّي وما ينخمن) */
function push_inbox_take(string $endpoint): array
{
    $id = sub_id($endpoint);
    $msgs = [];
    db_update('push_inbox', function (array $d) use ($id, &$msgs) {
        $msgs = $d[$id] ?? [];
        unset($d[$id]);
        return $d;
    });
    return array_values($msgs);
}

// ================================================================ المهام الدورية (كل دقيقة)
/** تنبيهات الوجبات + نهاية الراحة (لو التطبيق مسكّر) */
function run_due(): array
{
    $done = ['meals' => 0, 'rest' => 0];
    $users = db_read('users');
    foreach ($users as $uid => $u) {
        $uid = (int)$uid;
        $doc = user_doc($uid);
        $tz = $doc['settings']['tz'] ?? 'Asia/Baghdad';
        try {
            $now = new DateTime('now', new DateTimeZone($tz));
        } catch (Throwable $e) {
            $now = new DateTime('now', new DateTimeZone('Asia/Baghdad'));
        }
        $today = $now->format('Y-m-d');
        $hm = (int)$now->format('H') * 60 + (int)$now->format('i');
        $due = [];
        // الوجبات: ننبّه بوقتها (أو لحد 10 دقايق بعده لو Cron تأخر)، ومرة وحدة باليوم
        if (!empty($doc['settings']['meals_on'])) {
            foreach ($doc['meals'] as $m) {
                if (empty($m['on']) || !preg_match('/^(\d\d):(\d\d)$/', (string)$m['time'], $t)) {
                    continue;
                }
                $mm = (int)$t[1] * 60 + (int)$t[2];
                if ($hm >= $mm && $hm - $mm <= 10 && (($doc['sent'][$m['id']] ?? '') !== $today)) {
                    $due[] = $m;
                }
            }
        }
        // ماي: كل ساعتين من 9 الصبح لـ 11 بالليل
        $water = !empty($doc['settings']['water_on']) && $hm >= 540 && $hm <= 1380 && (int)$now->format('i') < 10 && ((int)$now->format('H') - 9) % 2 === 0
            && (($doc['sent']['water'] ?? '') !== $today . $now->format('H'));
        // الراحة: لو المستخدم مسكّر التطبيق ووقت الراحة خلص
        $rest = $doc['rest_push'] ?? null;
        $restDue = $rest && time() >= (int)$rest['at'] && time() - (int)$rest['at'] < 600;
        if (!$due && !$water && !$restDue && !($rest && !$restDue && time() >= (int)$rest['at'])) {
            continue;
        }
        user_doc_update($uid, function (array $d) use ($due, $today, $water, $now, $rest) {
            foreach ($due as $m) {
                $d['sent'][$m['id']] = $today;
            }
            if ($water) {
                $d['sent']['water'] = $today . $now->format('H');
            }
            if ($rest && time() >= (int)$rest['at']) {
                $d['rest_push'] = null;
            }
            return $d;
        });
        foreach ($due as $m) {
            notify_user($uid, '🍽️ وقت ' . $m['name'], ($m['note'] ?: 'لا تنسى وجبتك') . ' - ' . $m['time'], 'meal-' . $m['id'], '/#meals');
            $done['meals']++;
        }
        if ($water) {
            notify_user($uid, '💧 اشرب ماي', 'كوب ماي هسه يساعد عضلاتك على الاستشفاء', 'water', '/');
        }
        if ($restDue) {
            notify_user($uid, '⏱️ خلصت الراحة', $rest['next'] ?: 'يلا المجموعة الجاية 💪', 'rest', '/#session');
            $done['rest']++;
        }
    }
    return $done;
}

/** نشغّل المهام مع أي طلب كل 30 ثانية (احتياط لو Cron مو منصّب) */
function tick_maybe(): void
{
    $f = DATA_DIR . '/tick.lock';
    $fh = @fopen($f, 'c+');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) {
        return;
    }
    $last = (int)stream_get_contents($fh);
    if (time() - $last >= 30) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string)time());
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        register_shutdown_function(function () {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            run_due();
        });
        return;
    }
    flock($fh, LOCK_UN);
    fclose($fh);
}
