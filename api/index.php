<?php
/**
 * Sufra REST-ish API — single endpoint, JSON in / JSON out.
 *
 *   POST api/index.php   { "action": "db.list", "col": "products" }
 *   Header  X-Auth-Token: <token>   (returned by auth.signin / auth.signup)
 *
 * All permission checks live here: customers can only see and change their own data,
 * the admin can do everything. Order prices are recomputed on the server.
 */

declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/whatsapp.php';
require __DIR__ . '/webpush.php';
$CFG = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

set_exception_handler(function (Throwable $e) {
    $code = $e instanceof ApiError ? $e->status : 500;
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $e instanceof ApiError ? $e->getMessage() : 'server-error'], JSON_UNESCAPED_UNICODE);
    if (!$e instanceof ApiError) error_log('[sufra] ' . $e);
});

final class ApiError extends Exception
{
    public function __construct(string $code, public int $status = 400)
    {
        parent::__construct($code);
    }
}

function ok(mixed $data = null): never
{
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$db = new JsonDB($CFG['data_dir']);

/* ------------------------------------------------------------------ first run */

if (!$db->exists('users')) {
    $seed = json_decode((string) file_get_contents(__DIR__ . '/seed.json'), true);
    $now = now_ms();
    foreach ($seed as $col => $rows) {
        $db->mutate($col, function () use ($rows, $now) {
            foreach ($rows as &$r) if (array_key_exists('createdAt', $r)) $r['createdAt'] = $now;
            return $rows;
        });
    }
    $adminId = 'admin';
    $email = strtolower($CFG['admin_email']);
    $db->merge('users', $adminId, [
        'name' => $CFG['admin_name'], 'email' => $email, 'login' => $email, 'phone' => '', 'role' => 'admin',
        'points' => 0, 'totalOrders' => 0, 'totalSpent' => 0, 'createdAt' => $now,
    ]);
    $db->merge('credentials', $email, ['uid' => $adminId, 'hash' => password_hash($CFG['admin_password'], PASSWORD_DEFAULT)]);
    $db->mutate('orders', fn($r) => $r);
}

/* ------------------------------------------------------------------ request */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new ApiError('method-not-allowed', 405);

$isUpload = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
$in = $isUpload ? $_POST : json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) throw new ApiError('bad-request');
$action = (string) ($in['action'] ?? '');

/* ------------------------------------------------------------------ session */

function token_from_request(): string
{
    $t = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    return preg_match('/^[a-f0-9]{64}$/', $t) ? $t : '';
}

$token = token_from_request();
$me = null; // ['uid' => ..., 'email' => ..., 'profile' => [...]]
if ($token) {
    $s = $db->get('sessions', hash('sha256', $token));
    if ($s && $s['expires'] > now_ms()) {
        $profile = $db->get('users', $s['uid']);
        if ($profile && empty($profile['deleted']) && empty($profile['blocked'])) {
            $me = ['uid' => $s['uid'], 'email' => ($profile['email'] ?? '') ?: ($profile['phone'] ?? ''), 'profile' => $profile];
        }
    }
}
$isAdmin = ($me['profile']['role'] ?? '') === 'admin';

function require_user(): array
{
    global $me;
    if (!$me) throw new ApiError('unauthenticated', 401);
    return $me;
}
function require_admin(): void
{
    global $isAdmin;
    require_user();
    if (!$isAdmin) throw new ApiError('permission-denied', 403);
}

function start_session(JsonDB $db, string $uid, int $days): string
{
    $token = bin2hex(random_bytes(32));
    $db->merge('sessions', hash('sha256', $token), ['uid' => $uid, 'expires' => now_ms() + $days * 86400000]);
    return $token;
}

function clean_email(mixed $e): string
{
    $e = strtolower(trim((string) $e));
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) throw new ApiError('auth/invalid-email');
    return $e;
}

/** The credentials key of a profile: the phone (customers) or the e-mail (admin). */
function login_key(array $profile): string
{
    return (string) ($profile['login'] ?? $profile['email'] ?? '');
}

function verify_otp(JsonDB $db, string $phone, string $purpose, string $code): void
{
    $row = $db->get('otps', $phone);
    if (!$row || $row['purpose'] !== $purpose || $row['expires'] < now_ms()) throw new ApiError('otp/expired');
    if ($row['attempts'] >= 5) {
        $db->delete('otps', $phone);
        throw new ApiError('otp/too-many-attempts');
    }
    if (!password_verify(trim($code), $row['hash'])) {
        $db->merge('otps', $phone, ['attempts' => $row['attempts'] + 1]);
        throw new ApiError('otp/invalid');
    }
    $db->delete('otps', $phone);
}

/** Sends the response now, then keeps running (used to deliver push notifications). */
function ok_then(mixed $data, callable $after): never
{
    ignore_user_abort(true);
    $json = json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    else { @ob_end_flush(); flush(); }
    try { $after(); } catch (Throwable $e) { error_log('[sufra] ' . $e); }
    exit;
}

function push_notification(JsonDB $db, array $cfg, array $n): void
{
    $subs = array_values(array_filter($db->all('push_subs'), fn($s) => ($n['userId'] ?? '') === 'all' || $s['uid'] === ($n['userId'] ?? '')));
    if (!$subs) return;
    $msg = ['title' => (string) ($n['title'] ?? ''), 'body' => (string) ($n['body'] ?? ''),
        'url' => !empty($n['orderId']) ? 'orders/' : (!empty($n['link']) ? ltrim((string) $n['link'], '/') : 'notifications/'),
        'tag' => !empty($n['orderId']) ? 'order-' . $n['orderId'] : 'n-' . ($n['type'] ?? 'general')];
    $gone = webpush_send_many($db, $subs, $msg, 'mailto:' . $cfg['admin_email']);
    foreach ($gone as $endpoint) $db->delete('push_subs', sha1($endpoint));
}

function str_field(mixed $v, int $max = 500): string
{
    return mb_substr(trim((string) $v), 0, $max);
}

/* ------------------------------------------------------------------ permissions */

const PUBLIC_READ = ['config', 'branches', 'categories', 'products'];
const COLLECTIONS = ['config', 'branches', 'categories', 'products', 'users', 'orders', 'notifications'];

function check_col(string $col): void
{
    if (!in_array($col, COLLECTIONS, true)) throw new ApiError('bad-collection');
}

function can_read(string $col, array $doc, ?array $me, bool $isAdmin): bool
{
    if ($isAdmin || in_array($col, PUBLIC_READ, true)) return true;
    if (!$me) return false;
    return match ($col) {
        'users' => ($doc['id'] ?? '') === $me['uid'],
        'orders' => ($doc['userId'] ?? '') === $me['uid'],
        'notifications' => in_array($doc['userId'] ?? '', [$me['uid'], 'all'], true),
        default => false,
    };
}

function matches(array $doc, ?array $f): bool
{
    if (!$f) return true;
    $v = $doc[$f['field']] ?? null;
    return ($f['op'] ?? '==') === 'in' ? in_array($v, (array) $f['value'], true) : $v === $f['value'];
}

/* ------------------------------------------------------------------ orders */

/** Rebuilds an order from the catalogue so the customer cannot tamper with prices. */
function build_order(JsonDB $db, array $me, array $in): array
{
    $settings = $db->get('config', 'settings') ?? [];
    $products = $db->all('products');
    $branch = $db->get('branches', (string) ($in['branchId'] ?? ''));
    if (!$branch) throw new ApiError('bad-branch');
    if (empty($branch['open'])) throw new ApiError('branch-closed');

    $items = [];
    foreach ((array) ($in['items'] ?? []) as $it) {
        $p = $products[(string) ($it['productId'] ?? '')] ?? null;
        $qty = max(0, min(50, (int) ($it['qty'] ?? 0)));
        if (!$p || !$qty) continue;
        if (empty($p['available'])) throw new ApiError('product-unavailable');
        $items[] = ['productId' => (string) $it['productId'], 'name' => $p['name'], 'price' => (float) $p['price'], 'qty' => $qty,
            'image' => $p['image'] ?? '', 'emoji' => $p['emoji'] ?? ''];
    }
    if (!$items) throw new ApiError('empty-order');

    $type = ($in['type'] ?? '') === 'pickup' ? 'pickup' : 'delivery';
    $subtotal = array_sum(array_map(fn($i) => $i['price'] * $i['qty'], $items));
    if ($subtotal < (float) ($settings['minOrder'] ?? 0)) throw new ApiError('min-order');
    $deliveryFee = $type === 'delivery' ? (float) ($settings['deliveryFee'] ?? 0) : 0;
    $pointValue = max(0, (float) ($settings['pointValue'] ?? 0));
    $userPoints = (int) ($me['profile']['points'] ?? 0);
    $pointsUsed = 0;
    if (!empty($in['pointsUsed']) && $pointValue > 0) {
        $pointsUsed = min($userPoints, (int) floor(($subtotal + $deliveryFee) / $pointValue), max(0, (int) $in['pointsUsed']));
    }
    $discount = $pointsUsed * $pointValue;
    $total = max(0, $subtotal + $deliveryFee - $discount);
    $earned = $pointValue > 0 ? (int) floor($total * (float) ($settings['loyaltyPercent'] ?? 0) / 100 / $pointValue) : 0;

    $address = str_field($in['address'] ?? '');
    $phone = str_field($in['userPhone'] ?? '', 30);
    if ($phone === '') throw new ApiError('phone-required');
    if ($type === 'delivery' && $address === '') throw new ApiError('address-required');
    $loc = $in['location'] ?? null;
    $location = is_array($loc) && is_numeric($loc['lat'] ?? null) && is_numeric($loc['lng'] ?? null)
        ? ['lat' => (float) $loc['lat'], 'lng' => (float) $loc['lng']] : null;

    $now = now_ms();
    return [
        'userId' => $me['uid'], 'userName' => $me['profile']['name'] ?? '', 'userPhone' => $phone,
        'branchId' => $branch['id'] ?? (string) $in['branchId'], 'branchName' => $branch['name'],
        'items' => $items, 'subtotal' => $subtotal, 'deliveryFee' => $deliveryFee, 'discount' => $discount,
        'pointsUsed' => $pointsUsed, 'total' => $total, 'pointsEarned' => $earned,
        'type' => $type, 'address' => $type === 'delivery' ? $address : '', 'location' => $type === 'delivery' ? $location : null,
        'notes' => str_field($in['notes'] ?? ''), 'status' => 'pending',
        'history' => [['status' => 'pending', 'at' => $now]], 'createdAt' => $now, 'updatedAt' => $now,
    ];
}

/* ------------------------------------------------------------------ actions */

switch ($action) {
    /* ---------- auth: customers continue with WhatsApp (phone + code) or Google */
    case 'auth.config':
        ok([
            'googleClientId' => (string) ($CFG['google_client_id'] ?? ''),
            'whatsapp' => ($CFG['whatsapp']['provider'] ?? 'none') !== 'none' || !empty($CFG['otp_debug']),
        ]);

    case 'otp.send': {
        $phone = normalize_phone((string) ($in['phone'] ?? ''), $CFG['default_country_code']);
        if (strlen($phone) < 10 || strlen($phone) > 15) throw new ApiError('auth/invalid-phone');
        $prev = $db->get('otps', $phone);
        if ($prev && now_ms() - $prev['sentAt'] < 60000) throw new ApiError('otp/too-soon', 429);
        $cred = $db->get('credentials', $phone);
        $existing = $cred ? $db->get('users', $cred['uid']) : null;
        $exists = $existing && empty($existing['deleted']);

        $code = (string) random_int(100000, 999999);
        $db->merge('otps', $phone, ['hash' => password_hash($code, PASSWORD_DEFAULT), 'purpose' => 'auth',
            'expires' => now_ms() + 10 * 60000, 'attempts' => 0, 'sentAt' => now_ms()]);
        $settings = $db->get('config', 'settings') ?? [];
        $sent = whatsapp_send_code($CFG, $phone, $code, (string) ($settings['restaurantName'] ?? 'التطبيق'));
        if (!$sent) {
            if (!empty($CFG['otp_debug'])) ok(['phone' => $phone, 'exists' => $exists, 'debugCode' => $code]);
            $db->delete('otps', $phone);
            throw new ApiError(($CFG['whatsapp']['provider'] ?? 'none') === 'none' ? 'otp/not-configured' : 'otp/send-failed', 503);
        }
        ok(['phone' => $phone, 'exists' => $exists]);
    }

    /** Signs in with a WhatsApp code; creates the account if the number is new. */
    case 'auth.whatsapp': {
        $phone = normalize_phone((string) ($in['phone'] ?? ''), $CFG['default_country_code']);
        $cred = $db->get('credentials', $phone);
        $profile = $cred ? $db->get('users', $cred['uid']) : null;
        $isNew = !$profile || !empty($profile['deleted']);
        $name = str_field($in['name'] ?? '', 80);
        if ($isNew && $name === '') throw new ApiError('auth/name-required');
        verify_otp($db, $phone, 'auth', (string) ($in['code'] ?? ''));

        if (!$isNew) {
            if (!empty($profile['blocked'])) throw new ApiError('auth/user-blocked', 403);
            ok(['token' => start_session($db, $cred['uid'], $CFG['session_days']), 'uid' => $cred['uid'], 'email' => '+' . $phone, 'isNew' => false]);
        }
        $uid = new_id();
        $db->merge('credentials', $phone, ['uid' => $uid, 'hash' => null, 'method' => 'whatsapp']);
        $db->merge('users', $uid, [
            'name' => $name, 'email' => '', 'phone' => '+' . $phone, 'login' => $phone, 'loginMethod' => 'whatsapp', 'role' => 'user',
            'points' => 0, 'totalOrders' => 0, 'totalSpent' => 0, 'createdAt' => now_ms(),
        ]);
        ok(['token' => start_session($db, $uid, $CFG['session_days']), 'uid' => $uid, 'email' => '+' . $phone, 'isNew' => true]);
    }

    /** Creates an account on the site with e-mail + password. */
    case 'auth.signupEmail': {
        $email = clean_email($in['email'] ?? '');
        $password = (string) ($in['password'] ?? '');
        $name = str_field($in['name'] ?? '', 80);
        if ($name === '') throw new ApiError('auth/name-required');
        if (mb_strlen($password) < 6) throw new ApiError('auth/weak-password');
        $uid = new_id();
        $created = $db->mutate('credentials', function (array $rows, &$res) use ($email, $password, $uid) {
            if (isset($rows[$email])) { $res = false; return $rows; }
            $rows[$email] = ['uid' => $uid, 'hash' => password_hash($password, PASSWORD_DEFAULT), 'method' => 'email'];
            $res = true;
            return $rows;
        });
        if (!$created) throw new ApiError('auth/email-already-in-use');
        $db->merge('users', $uid, [
            'name' => $name, 'email' => $email, 'phone' => '', 'login' => $email, 'loginMethod' => 'email', 'role' => 'user',
            'points' => 0, 'totalOrders' => 0, 'totalSpent' => 0, 'createdAt' => now_ms(),
        ]);
        ok(['token' => start_session($db, $uid, $CFG['session_days']), 'uid' => $uid, 'email' => $email]);
    }

    /** Signs in with a Google access token; creates the account on first use. */
    case 'auth.google': {
        $clientId = (string) ($CFG['google_client_id'] ?? '');
        if ($clientId === '') throw new ApiError('auth/google-not-configured', 503);
        $token = (string) ($in['accessToken'] ?? '');
        if ($token === '') throw new ApiError('bad-request');
        $info = http_get_json('https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($token));
        if (!$info || (($info['aud'] ?? '') !== $clientId && ($info['azp'] ?? '') !== $clientId)) throw new ApiError('auth/google-invalid', 401);
        $me_g = http_get_json('https://www.googleapis.com/oauth2/v3/userinfo', ['Authorization: Bearer ' . $token]);
        $sub = (string) ($me_g['sub'] ?? '');
        if ($sub === '' || $sub !== (string) ($info['sub'] ?? $sub)) throw new ApiError('auth/google-invalid', 401);
        $key = 'g:' . $sub;
        $cred = $db->get('credentials', $key);
        $profile = $cred ? $db->get('users', $cred['uid']) : null;
        if ($profile && empty($profile['deleted'])) {
            if (!empty($profile['blocked'])) throw new ApiError('auth/user-blocked', 403);
            ok(['token' => start_session($db, $cred['uid'], $CFG['session_days']), 'uid' => $cred['uid'], 'email' => $profile['email'], 'isNew' => false]);
        }
        $uid = new_id();
        $email = strtolower((string) ($me_g['email'] ?? ''));
        $db->merge('credentials', $key, ['uid' => $uid, 'hash' => null, 'method' => 'google']);
        $db->merge('users', $uid, [
            'name' => str_field($me_g['name'] ?? '', 80) ?: explode('@', $email)[0], 'email' => $email, 'phone' => '',
            'avatar' => (string) ($me_g['picture'] ?? ''), 'login' => $key, 'loginMethod' => 'google', 'role' => 'user',
            'points' => 0, 'totalOrders' => 0, 'totalSpent' => 0, 'createdAt' => now_ms(),
        ]);
        ok(['token' => start_session($db, $uid, $CFG['session_days']), 'uid' => $uid, 'email' => $email, 'isNew' => true]);
    }

    case 'auth.signin': {
        $login = trim((string) ($in['login'] ?? $in['email'] ?? ''));
        $key = str_contains($login, '@') ? strtolower($login) : normalize_phone($login, $CFG['default_country_code']);
        $cred = $key !== '' ? $db->get('credentials', $key) : null;
        if (!$cred || empty($cred['hash']) || !password_verify((string) ($in['password'] ?? ''), $cred['hash'])) {
            usleep(400000); // slow down guessing
            throw new ApiError('auth/invalid-credential', 401);
        }
        $profile = $db->get('users', $cred['uid']);
        if (!$profile || !empty($profile['deleted'])) throw new ApiError('auth/invalid-credential', 401);
        if (!empty($profile['blocked'])) throw new ApiError('auth/user-blocked', 403);
        ok(['token' => start_session($db, $cred['uid'], $CFG['session_days']), 'uid' => $cred['uid'], 'email' => $profile['email'] ?: $profile['phone']]);
    }

    case 'auth.me':
        ok($me ? ['uid' => $me['uid'], 'email' => $me['email']] : null);

    case 'auth.signout':
        if ($token) $db->delete('sessions', hash('sha256', $token));
        ok();

    case 'auth.delete': {
        $u = require_user();
        $db->merge('users', $u['uid'], ['deleted' => true, 'deletedAt' => now_ms()]);
        $db->delete('credentials', login_key($u['profile']));
        $db->mutate('sessions', fn($rows) => array_filter($rows, fn($s) => $s['uid'] !== $u['uid']));
        $db->mutate('push_subs', fn($rows) => array_filter($rows, fn($s) => $s['uid'] !== $u['uid']));
        ok();
    }

    case 'auth.setPassword': { // admin resets a customer's password
        require_admin();
        $uid = (string) ($in['uid'] ?? '');
        $user = $db->get('users', $uid);
        $password = (string) ($in['password'] ?? '');
        if (!$user || !empty($user['deleted'])) throw new ApiError('not-found', 404);
        if (mb_strlen($password) < 6) throw new ApiError('auth/weak-password');
        $db->merge('credentials', login_key($user), ['uid' => $uid, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
        $db->mutate('sessions', fn($rows) => array_filter($rows, fn($s) => $s['uid'] !== $uid));
        ok();
    }

    case 'auth.changePassword': {
        $u = require_user();
        $key = login_key($u['profile']);
        $cred = $db->get('credentials', $key);
        if (!$cred || empty($cred['hash']) || !password_verify((string) ($in['current'] ?? ''), $cred['hash'])) throw new ApiError('auth/wrong-password');
        if (mb_strlen((string) ($in['password'] ?? '')) < 6) throw new ApiError('auth/weak-password');
        $db->merge('credentials', $key, ['hash' => password_hash((string) $in['password'], PASSWORD_DEFAULT)]);
        ok();
    }

    /* ---------- push notifications to the phone */
    case 'push.key':
        ok(vapid_keys($db)['public']);

    case 'push.subscribe': {
        $u = require_user();
        $sub = $in['subscription'] ?? null;
        $endpoint = (string) ($sub['endpoint'] ?? '');
        if (!preg_match('#^https://#', $endpoint) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) throw new ApiError('bad-subscription');
        $db->merge('push_subs', sha1($endpoint), ['uid' => $u['uid'], 'endpoint' => $endpoint,
            'p256dh' => (string) $sub['keys']['p256dh'], 'auth' => (string) $sub['keys']['auth'], 'createdAt' => now_ms()]);
        ok();
    }

    case 'push.unsubscribe':
        $db->delete('push_subs', sha1((string) ($in['endpoint'] ?? '')));
        ok();

    /* ---------- reads */
    case 'db.list': {
        $col = (string) ($in['col'] ?? '');
        check_col($col);
        $filter = is_array($in['filter'] ?? null) ? $in['filter'] : null;
        $out = [];
        foreach ($db->all($col) as $id => $doc) {
            $doc['id'] = (string) $id;
            if (matches($doc, $filter) && can_read($col, $doc, $me, $isAdmin)) $out[] = $doc;
        }
        ok($out);
    }

    case 'db.get': {
        $col = (string) ($in['col'] ?? '');
        check_col($col);
        $id = (string) ($in['id'] ?? '');
        $doc = $db->get($col, $id);
        if ($doc) $doc['id'] = $id;
        ok($doc && can_read($col, $doc, $me, $isAdmin) ? $doc : null);
    }

    /* ---------- writes */
    case 'db.add':
    case 'db.set': {
        $col = (string) ($in['col'] ?? '');
        check_col($col);
        $data = is_array($in['data'] ?? null) ? $in['data'] : [];
        unset($data['id']);
        $id = $action === 'db.add' ? new_id() : (string) ($in['id'] ?? '');
        if ($id === '' || strlen($id) > 64) throw new ApiError('bad-id');

        if ($col === 'orders' && $action === 'db.add') {
            $u = require_user();
            $order = build_order($db, $u, $data);
            $db->merge('orders', $id, $order);
            $patch = ['phone' => $order['userPhone']];
            if ($order['address'] !== '') $patch['address'] = $order['address'];
            if ($order['location']) $patch['location'] = $order['location'];
            if ($order['pointsUsed'] > 0) $patch['points'] = max(0, (int) $u['profile']['points'] - $order['pointsUsed']);
            $db->merge('users', $u['uid'], $patch);
            ok($id);
        }

        if ($isAdmin) {
            $db->merge($col, $id, $data);
            if ($col === 'notifications' && $action === 'db.add') ok_then($id, fn() => push_notification($db, $CFG, $data));
            ok($id);
        }
        $u = require_user();

        if ($col === 'orders' && $action === 'db.set') {
            // A customer may only cancel their own pending order that used no points.
            $o = $db->get('orders', $id);
            if (!$o || $o['userId'] !== $u['uid'] || $o['status'] !== 'pending' || !empty($o['pointsUsed'])
                || ($data['status'] ?? '') !== 'cancelled') throw new ApiError('permission-denied', 403);
            $now = now_ms();
            $db->merge('orders', $id, ['status' => 'cancelled', 'updatedAt' => $now,
                'history' => array_merge($o['history'] ?? [], [['status' => 'cancelled', 'at' => $now]])]);
            ok($id);
        }

        if ($col === 'users' && $action === 'db.set' && $id === $u['uid']) {
            $allowed = [];
            foreach (['name' => 80, 'phone' => 30, 'address' => 300] as $k => $max) {
                if (array_key_exists($k, $data)) $allowed[$k] = str_field($data[$k], $max);
            }
            if (isset($data['notifSeenAt'])) $allowed['notifSeenAt'] = (int) $data['notifSeenAt'];
            if (is_array($data['location'] ?? null) && is_numeric($data['location']['lat'] ?? null)) {
                $allowed['location'] = ['lat' => (float) $data['location']['lat'], 'lng' => (float) $data['location']['lng']];
            }
            if (isset($allowed['name']) && $allowed['name'] === '') unset($allowed['name']);
            $db->merge('users', $id, $allowed);
            ok($id);
        }

        throw new ApiError('permission-denied', 403);
    }

    case 'db.remove': {
        require_admin();
        $col = (string) ($in['col'] ?? '');
        check_col($col);
        $db->delete($col, (string) ($in['id'] ?? ''));
        ok();
    }

    /* ---------- uploads (admin) */
    case 'upload': {
        require_admin();
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new ApiError('upload-failed');
        if ($f['size'] > 5 * 1024 * 1024) throw new ApiError('file-too-large');
        $info = @getimagesize($f['tmp_name']);
        $ext = match ($info['mime'] ?? '') { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => null };
        if (!$ext) throw new ApiError('bad-image');
        $folder = preg_replace('/[^a-z]/', '', (string) ($in['folder'] ?? 'misc')) ?: 'misc';
        $dir = $CFG['upload_dir'] . '/' . $folder;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $name = new_id() . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) throw new ApiError('upload-failed');
        $base = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/');
        ok($base . '/' . $CFG['upload_url'] . "/$folder/$name");
    }

    default:
        throw new ApiError('unknown-action', 404);
}
