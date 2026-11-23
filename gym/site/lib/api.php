<?php
/**
 * مسارات الـ API: الحساب، البيانات (البرنامج، الأسبوع، الوجبات...)، السجل، الإشعارات، رفع الصور.
 */
declare(strict_types=1);

const EQUIPMENT = ['barbell', 'dumbbell', 'bench', 'cable', 'ez_bar', 'machine_chest', 'leg_machine', 'leg_press', 'pullup_bar', 'dip_bars', 'smith', 'bodyweight'];
const MEDIA_TYPES = ['image/gif' => 'gif', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/webm' => 'webm'];

function public_user(array $u): array
{
    return ['id' => $u['id'], 'name' => $u['name'], 'username' => $u['username']];
}

/** يرجع كل بيانات المستخدم للواجهة */
function state_for(int $uid): array
{
    $doc = user_doc($uid);
    unset($doc['sent'], $doc['rest_push']);
    $doc['log'] = array_slice($doc['log'] ?? [], -120);
    return ['ok' => true, 'user' => public_user(user_find($uid)), 'data' => $doc, 'vapid' => config()['vapid_public']];
}

// ---------------------------------------------------------------- تنظيف كل قسم قبل الحفظ
function valid_time(string $t): bool
{
    return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
}

function clean_section(string $sec, $v)
{
    $ids = array_flip(exercise_ids());
    switch ($sec) {
        case 'profile':
            $v = (array)$v;
            return ['weight' => clean((string)($v['weight'] ?? ''), 8), 'height' => clean((string)($v['height'] ?? ''), 8), 'age' => clean((string)($v['age'] ?? ''), 4),
                'goal' => in_array($v['goal'] ?? '', ['build', 'cut', 'fit'], true) ? $v['goal'] : 'build'];
        case 'settings':
            $v = (array)$v;
            $tz = in_array($v['tz'] ?? '', DateTimeZone::listIdentifiers(), true) ? $v['tz'] : 'Asia/Baghdad';
            return ['tz' => $tz, 'rest' => max(10, min(600, (int)($v['rest'] ?? 90))), 'sound' => !empty($v['sound']), 'vibrate' => !empty($v['vibrate']),
                'meals_on' => !empty($v['meals_on']), 'water_on' => !empty($v['water_on'])];
        case 'equipment':
            return array_values(array_unique(array_merge(['bodyweight'], array_intersect((array)$v, EQUIPMENT))));
        case 'days':
            $out = [];
            foreach (array_slice((array)$v, 0, 7, true) as $key => $day) {
                if (!preg_match('/^D[1-7]$/', (string)$key)) {
                    continue;
                }
                $items = [];
                foreach (array_slice((array)($day['items'] ?? []), 0, 20) as $it) {
                    if (!isset($ids[$it['ex'] ?? ''])) {
                        continue;
                    }
                    $reps = preg_replace('/[^0-9\-×x ]/u', '', (string)($it['reps'] ?? '10'));
                    $items[] = ['ex' => $it['ex'], 'sets' => max(1, min(10, (int)($it['sets'] ?? 4))), 'reps' => mb_substr($reps ?: '10', 0, 20),
                        'rest' => max(10, min(600, (int)($it['rest'] ?? 90))), 'note' => clean((string)($it['note'] ?? ''), 120)];
                }
                $out[$key] = ['title' => clean((string)($day['title'] ?? $key), 40) ?: $key, 'items' => $items];
            }
            return $out;
        case 'week':
            $out = [];
            for ($i = 0; $i < 7; $i++) {
                $d = (string)(((array)$v)[(string)$i] ?? '');
                $out[(string)$i] = preg_match('/^D[1-7]$/', $d) ? $d : '';
            }
            return $out;
        case 'meals':
            $out = [];
            foreach (array_slice((array)$v, 0, 12) as $m) {
                if (!valid_time((string)($m['time'] ?? ''))) {
                    continue;
                }
                $id = preg_match('/^[a-z0-9]{1,12}$/', (string)($m['id'] ?? '')) ? $m['id'] : 'm' . bin2hex(random_bytes(3));
                $out[] = ['id' => $id, 'name' => clean((string)($m['name'] ?? 'وجبة'), 30) ?: 'وجبة', 'time' => $m['time'], 'note' => clean((string)($m['note'] ?? ''), 120), 'on' => !empty($m['on'])];
            }
            usort($out, fn($a, $b) => strcmp($a['time'], $b['time']));
            return $out;
        case 'session':
            if ($v === null) {
                return null;
            }
            $j = json_encode($v);
            return strlen((string)$j) < 20000 ? $v : null;
    }
    json_err('قسم غير معروف');
}

function api_routes(): array
{
    return [
        // ---------------------------------------------------------------- الحساب
        ['POST', '#^/api/register$#', function () {
            require_same_origin();
            login_throttle();
            $d = request_json();
            $name = clean((string)($d['name'] ?? ''), 40);
            $user = strtolower(trim((string)($d['username'] ?? '')));
            $pass = (string)($d['password'] ?? '');
            if ($name === '' || !preg_match('/^[a-z0-9_.]{3,24}$/', $user)) {
                json_err('اكتب اسمك، واسم مستخدم إنكليزي (3-24 حرف أو رقم)');
            }
            if (mb_strlen($pass) < 6) {
                json_err('كلمة السر لازم 6 أحرف أو أكثر');
            }
            $uid = 0;
            db_update('users', function (array $users) use ($name, $user, $pass, &$uid) {
                foreach ($users as $u) {
                    if ($u['username'] === $user) {
                        return $users;
                    }
                }
                $uid = $users ? max(array_map('intval', array_keys($users))) + 1 : 1;
                $users[(string)$uid] = ['id' => $uid, 'name' => $name, 'username' => $user, 'pass' => password_hash($pass, PASSWORD_DEFAULT), 'created' => date('Y-m-d H:i')];
                return $users;
            });
            if (!$uid) {
                json_err('اسم المستخدم مأخوذ - اختار غيره');
            }
            start_session();
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            json_out(state_for($uid));
        }],
        ['POST', '#^/api/login$#', function () {
            require_same_origin();
            login_throttle();
            $d = request_json();
            $user = strtolower(trim((string)($d['username'] ?? '')));
            foreach (db_read('users') as $u) {
                if ($u['username'] === $user && password_verify((string)($d['password'] ?? ''), $u['pass'])) {
                    start_session();
                    session_regenerate_id(true);
                    $_SESSION['uid'] = (int)$u['id'];
                    json_out(state_for((int)$u['id']));
                }
            }
            login_throttle(true);
            json_err('اسم المستخدم أو كلمة السر غلط');
        }],
        ['POST', '#^/api/logout$#', function () {
            require_same_origin();
            start_session();
            $_SESSION = [];
            session_destroy();
            json_out(['ok' => true]);
        }],
        ['POST', '#^/api/password$#', function () {
            require_same_origin();
            $uid = require_login();
            $d = request_json();
            $u = user_find($uid);
            if (!password_verify((string)($d['old'] ?? ''), $u['pass'])) {
                json_err('كلمة السر الحالية غلط');
            }
            if (mb_strlen((string)($d['new'] ?? '')) < 6) {
                json_err('كلمة السر الجديدة لازم 6 أحرف أو أكثر');
            }
            db_update('users', function (array $users) use ($uid, $d) {
                $users[(string)$uid]['pass'] = password_hash((string)$d['new'], PASSWORD_DEFAULT);
                return $users;
            });
            json_out(['ok' => true]);
        }],

        // ---------------------------------------------------------------- البيانات
        ['GET', '#^/api/state$#', function () {
            json_out(state_for(require_login()));
        }],
        // حفظ قسم: profile / settings / equipment / days / week / meals / session
        ['POST', '#^/api/save$#', function () {
            require_same_origin();
            $uid = require_login();
            $d = request_json();
            $sec = (string)($d['section'] ?? '');
            $val = clean_section($sec, $d['value'] ?? null);
            user_doc_update($uid, function (array $doc) use ($sec, $val) {
                $doc[$sec] = $val;
                return $doc;
            });
            json_out(['ok' => true, 'value' => $val]);
        }],
        // تأكيد إكمال مجموعة (نحفظ الوزن حتى نقترحه المرة الجاية)
        ['POST', '#^/api/set$#', function () {
            require_same_origin();
            $uid = require_login();
            $d = request_json();
            $ex = (string)($d['ex'] ?? '');
            if (!in_array($ex, exercise_ids(), true)) {
                json_err('تمرين غير معروف');
            }
            $w = clean((string)($d['weight'] ?? ''), 8);
            user_doc_update($uid, function (array $doc) use ($ex, $w) {
                if ($w !== '') {
                    $doc['last'][$ex] = $w;
                }
                return $doc;
            });
            json_out(['ok' => true]);
        }],
        // خلص تمرين اليوم: نحفظه بالسجل
        ['POST', '#^/api/finish$#', function () {
            require_same_origin();
            $uid = require_login();
            $d = request_json();
            $entry = ['date' => preg_match('/^\d{4}-\d\d-\d\d$/', (string)($d['date'] ?? '')) ? $d['date'] : date('Y-m-d'),
                'day' => preg_match('/^D[1-7]$/', (string)($d['day'] ?? '')) ? $d['day'] : '', 'title' => clean((string)($d['title'] ?? ''), 40),
                'mins' => max(0, min(600, (int)($d['mins'] ?? 0))), 'sets' => max(0, min(200, (int)($d['sets'] ?? 0))), 'exs' => max(0, min(30, (int)($d['exs'] ?? 0))),
                'at' => date('Y-m-d H:i')];
            user_doc_update($uid, function (array $doc) use ($entry) {
                $doc['log'][] = $entry;
                $doc['log'] = array_slice($doc['log'], -500);
                $doc['session'] = null;
                $doc['rest_push'] = null;
                return $doc;
            });
            json_out(['ok' => true, 'entry' => $entry]);
        }],

        // ---------------------------------------------------------------- الراحة: احتياط بالإشعار لو التطبيق انسكّر
        ['POST', '#^/api/rest$#', function () {
            require_same_origin();
            $uid = require_login();
            $d = request_json();
            $sec = (int)($d['seconds'] ?? 0);
            $val = $sec > 0 ? ['at' => time() + min(900, $sec), 'next' => clean((string)($d['next'] ?? ''), 100)] : null;
            user_doc_update($uid, function (array $doc) use ($val) {
                $doc['rest_push'] = $val;
                return $doc;
            });
            json_out(['ok' => true]);
        }],

        // ---------------------------------------------------------------- الإشعارات
        ['POST', '#^/api/push/subscribe$#', function () {
            require_same_origin();
            $uid = require_login();
            $ep = (string)(request_json()['endpoint'] ?? '');
            if (!preg_match('#^https://[a-z0-9.-]+(:\d+)?/#i', $ep) || strlen($ep) > 600) {
                json_err('اشتراك غير صالح');
            }
            db_update('push_subs', function (array $d) use ($uid, $ep) {
                $d[sub_id($ep)] = ['uid' => $uid, 'endpoint' => $ep, 'at' => date('Y-m-d H:i')];
                return $d;
            });
            json_out(['ok' => true]);
        }],
        ['POST', '#^/api/push/unsubscribe$#', function () {
            require_same_origin();
            $uid = require_login();
            $id = sub_id((string)(request_json()['endpoint'] ?? ''));
            db_update('push_subs', function (array $d) use ($id, $uid) {
                if ((int)($d[$id]['uid'] ?? 0) === $uid) {
                    unset($d[$id]);
                }
                return $d;
            });
            json_out(['ok' => true]);
        }],
        ['POST', '#^/api/push/test$#', function () {
            require_same_origin();
            $uid = require_login();
            $n = notify_user($uid, '✅ الإشعارات شغّالة', 'راح توصلك تنبيهات الوجبات ونهاية الراحة حتى لو التطبيق مسكّر', 'test');
            json_out(['ok' => true, 'sent' => $n]);
        }],
        // عامل الخدمة (sw.js) ياخذ نص الإشعار - بدون جلسة، بس بالـ endpoint السري
        ['POST', '#^/api/push/inbox$#', function () {
            json_out(['ok' => true, 'messages' => push_inbox_take((string)(request_json()['endpoint'] ?? ''))]);
        }],

        // ---------------------------------------------------------------- صورة/GIF لتمرين (يرفعها المستخدم)
        ['POST', '#^/api/media/([a-z0-9_]+)$#', function ($ex) {
            require_same_origin();
            $uid = require_login();
            if (!in_array($ex, exercise_ids(), true)) {
                json_err('تمرين غير معروف');
            }
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                json_err('ما وصل الملف');
            }
            if ($f['size'] > 8 * 1024 * 1024) {
                json_err('الحد 8 ميغابايت');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
            if (!isset(MEDIA_TYPES[$mime])) {
                json_err('الملف لازم صورة (GIF/PNG/JPG/WEBP) أو فيديو قصير (MP4/WEBM)');
            }
            $dir = DATA_DIR . '/media';
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            $name = $uid . '_' . $ex . '_' . bin2hex(random_bytes(6)) . '.' . MEDIA_TYPES[$mime];
            move_uploaded_file($f['tmp_name'], $dir . '/' . $name);
            $old = null;
            user_doc_update($uid, function (array $doc) use ($ex, $name, &$old) {
                $old = $doc['media'][$ex] ?? null;
                $doc['media'][$ex] = $name;
                return $doc;
            });
            if ($old) {
                @unlink($dir . '/' . basename($old));
            }
            json_out(['ok' => true, 'file' => $name]);
        }],
        ['POST', '#^/api/media/([a-z0-9_]+)/delete$#', function ($ex) {
            require_same_origin();
            $uid = require_login();
            $old = null;
            user_doc_update($uid, function (array $doc) use ($ex, &$old) {
                $old = $doc['media'][$ex] ?? null;
                unset($doc['media'][$ex]);
                return $doc;
            });
            if ($old) {
                @unlink(DATA_DIR . '/media/' . basename($old));
            }
            json_out(['ok' => true]);
        }],
        ['GET', '#^/media/(\d+)_([a-z0-9_]+)_([a-f0-9]{12})\.(gif|png|jpg|webp|mp4|webm)$#', function ($owner, $ex, $rnd, $ext) {
            $uid = current_uid();
            $file = DATA_DIR . '/media/' . "{$owner}_{$ex}_{$rnd}.{$ext}";
            if ($uid !== (int)$owner || !is_file($file)) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: ' . array_search($ext, MEDIA_TYPES, true));
            header('Cache-Control: private, max-age=31536000, immutable');
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }],
    ];
}
