<?php
/**
 * المحفظة (الرصيد بالدينار)، طرق الدفع، طلبات الشحن، والدفع التلقائي (آسياسيل + Binance Pay).
 * منقولة ومبسّطة من نظام الدفع بموقع SMM.
 */

const PM_TYPES = ['asiacell' => 'آسياسيل', 'binance' => 'Binance Pay', 'superkey' => 'سوبر كي', 'manual' => 'تحويل يدوي'];
const ASIACELL_TIERS = [1000, 2000, 3000, 4000, 5000, 6000, 7000, 8000, 9000, 10000];

// ================================================================ الرصيد

/** يضيف (أو يخصم بقيمة سالبة) مبلغ لرصيد المستخدم ويسجّل حركة. يرجع الرصيد الجديد أو null لو ما يكفي */
function wallet_add(int $userId, int $amount, string $type, string $note = '', bool $allowNegative = false): ?int
{
    $newBalance = null;
    store_update('users', $userId, function ($u) use ($amount, $allowNegative, &$newBalance) {
        $bal = (int)($u['balance'] ?? 0) + $amount;
        if ($bal < 0 && !$allowNegative) {
            return $u;
        }
        $u['balance'] = $bal;
        $newBalance = $bal;
        return $u;
    });
    if ($newBalance === null) {
        return null;
    }
    store_insert('wallet_tx', [
        'user_id' => $userId, 'amount' => $amount, 'type' => $type, 'note' => clean_text($note),
        'balance_after' => $newBalance, 'created_at' => now_str(),
    ]);
    return $newBalance;
}

function wallet_balance(int $userId): int
{
    return (int)(user_by_id($userId)['balance'] ?? 0);
}

/**
 * تكلفة إرسال رسالة واحدة للمستخدم: صفر لو عنده اشتراك فعّال أو فترة تجريبية،
 * وإلا سعر الرسالة اللي يحدده الأدمن (يُخصم من الرصيد).
 */
function message_cost_for(?array $u): int
{
    if (!$u || !empty($u['is_admin']) || user_has_access($u)) {
        return 0;
    }
    return message_price();
}

/** يقدر يرسل؟ (اشتراك/تجربة، أو الدفع لكل رسالة مفعّل ورصيده يكفي رسالة وحدة على الأقل) */
function can_send(?array $u): bool
{
    if (!$u || !empty($u['banned'])) {
        return false;
    }
    if (!empty($u['is_admin']) || user_has_access($u)) {
        return true;
    }
    $price = message_price();
    return $price > 0 && (int)($u['balance'] ?? 0) >= $price;
}

/** خصم رسالة وحدة. يرجع [ok, charged] */
function charge_message(int $userId, string $note = 'رسالة واتساب'): array
{
    $u = user_by_id($userId);
    if (!$u || !empty($u['banned'])) {
        return [false, 0];
    }
    if (!empty($u['is_admin']) || user_has_access($u)) {
        return [true, 0];
    }
    $cost = message_price();
    if ($cost === 0) {
        return [false, 0];
    }
    $bal = wallet_add($userId, -$cost, 'message', $note);
    return [$bal !== null, $bal !== null ? $cost : 0];
}

function refund_message(int $userId, int $amount, string $note = 'استرجاع رسالة فشلت'): void
{
    if ($amount > 0) {
        wallet_add($userId, $amount, 'refund', $note);
    }
}

function no_balance_error(): string
{
    return 'رصيدك ما يكفي - اشحن رصيدك أو فعّل اشتراك من قسم الشحن';
}

// ================================================================ الباقات

const PLAN_COLORS = ['teal', 'amber', 'violet', 'coral', 'emerald', 'sky'];

function ensure_default_plans(): void
{
    if (store_all('plans')) {
        return;
    }
    $defaults = [
        ['name' => 'الباقة الشهرية', 'price' => plan_price(), 'days' => plan_days(), 'color' => 'teal', 'popular' => false,
            'features' => "إرسال غير محدود\nرقم واتساب واحد أو أكثر\nرد آلي بالكلمات المفتاحية"],
        ['name' => 'باقة 3 أشهر', 'price' => (int)round(plan_price() * 2.6), 'days' => 90, 'color' => 'amber', 'popular' => true,
            'features' => "كل مزايا الشهرية\nتوفير 13%\nرد آلي بالذكاء الاصطناعي"],
        ['name' => 'الباقة السنوية', 'price' => plan_price() * 9, 'days' => 365, 'color' => 'violet', 'popular' => false,
            'features' => "كل المزايا\nتوفير 25%\nأولوية بالدعم"],
    ];
    foreach ($defaults as $i => $d) {
        store_insert('plans', $d + ['active' => true, 'sort' => $i + 1, 'created_at' => now_str()]);
    }
}

function plans(bool $activeOnly): array
{
    ensure_default_plans();
    $rows = store_all('plans');
    if ($activeOnly) {
        $rows = array_values(array_filter($rows, fn($p) => !empty($p['active'])));
    }
    usort($rows, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']);
    return array_map(fn($p) => $p + ['features_list' => array_values(array_filter(array_map('trim', explode("\n", (string)($p['features'] ?? '')))))], $rows);
}

/** شراء/تجديد باقة من الرصيد (لو عنده نفس الباقة فعّالة تنضاف المدة فوقها) */
function buy_plan(int $userId, ?int $planId = null): array
{
    $plan = $planId ? store_find('plans', $planId) : (plans(true)[0] ?? null);
    if (!$plan || empty($plan['active'])) {
        return [false, 'الباقة غير متاحة'];
    }
    $price = (int)$plan['price'];
    $days = max(1, (int)$plan['days']);
    $bal = wallet_add($userId, -$price, 'plan', $plan['name'] . " ({$days} يوم)");
    if ($bal === null) {
        return [false, 'رصيدك ما يكفي لهذي الباقة (' . number_format($price) . ' د.ع)'];
    }
    $u = user_by_id($userId);
    $from = effective_plan_active($u) && ($e = parse_dt($u['plan_ends_at'] ?? null)) ? $e : time();
    update_user($userId, ['plan_active' => true, 'plan_ends_at' => date('Y-m-d H:i', $from + $days * 86400), 'plan_name' => $plan['name']]);
    add_event($userId, null, 'تم تفعيل باقتك', $plan['name'] . " لمدة {$days} يوم", 'success');
    return [true, null];
}

/** بعد شحن مرتبط بباقة: نفعّلها تلقائياً لو الرصيد صار يكفي */
function apply_recharge_plan(int $userId, $planId): void
{
    if ($planId) {
        [$ok, $err] = buy_plan($userId, (int)$planId);
        if (!$ok) {
            add_event($userId, null, 'ما تفعّلت الباقة', 'انضاف المبلغ لرصيدك، بس ' . $err . ' - تقدر تكمل وتفعّلها من الباقات', 'warning');
        }
    }
}

// ================================================================ طرق الدفع

function pm_dir(string $sub): string
{
    $d = DATA_DIR . '/' . $sub;
    if (!is_dir($d)) {
        @mkdir($d, 0755, true);
    }
    return $d;
}

/** يحفظ صورة data-URL (شعار طريقة دفع / إيصال) ويرجع اسم الملف، أو null */
function save_data_image(string $dataUrl, string $sub, int $maxBytes = 3 * 1024 * 1024): ?string
{
    if (!preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#s', $dataUrl, $m)) {
        return null;
    }
    $raw = base64_decode($m[2], true);
    if ($raw === false || strlen($raw) > $maxBytes || @getimagesizefromstring($raw) === false) {
        return null;
    }
    $name = bin2hex(random_bytes(8)) . '.' . ($m[1] === 'jpeg' ? 'jpg' : $m[1]);
    file_put_contents(pm_dir($sub) . '/' . $name, $raw);
    return $name;
}

function save_uploaded_image(array $f, string $sub): ?string
{
    if (($f['error'] ?? 1) !== UPLOAD_ERR_OK || $f['size'] > 6 * 1024 * 1024) {
        return null;
    }
    $info = @getimagesize($f['tmp_name']);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
    if (!$ext) {
        return null;
    }
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    move_uploaded_file($f['tmp_name'], pm_dir($sub) . '/' . $name);
    return $name;
}

function serve_image(string $sub, string $name, bool $publicCache = true): void
{
    if (!preg_match('/^[a-f0-9]{16}\.(png|jpg|gif|webp)$/', $name) || !is_file($p = pm_dir($sub) . '/' . $name)) {
        http_response_code(404);
        return;
    }
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    header('Content-Type: ' . ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'][$ext]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . ($publicCache ? 'public, max-age=86400' : 'private, max-age=300'));
    readfile($p);
}

/** طرق الدفع الافتراضية أول مرة (معطّلة لحد ما الأدمن يضبطها) */
function ensure_default_payment_methods(): void
{
    if (store_all('payment_methods')) {
        return;
    }
    $defaults = [
        ['type' => 'asiacell', 'name' => 'آسياسيل', 'note' => 'شحن تلقائي فوري برصيد خطك'],
        ['type' => 'binance', 'name' => 'Binance Pay', 'note' => 'تحقق تلقائي من رقم العملية'],
        ['type' => 'superkey', 'name' => 'سوبر كي', 'note' => 'حوّل المبلغ وارفع صورة الإيصال'],
        ['type' => 'manual', 'name' => 'تحويل يدوي', 'note' => 'زين كاش / ماستر كارد - ارفع صورة الإيصال'],
    ];
    foreach ($defaults as $i => $d) {
        store_insert('payment_methods', $d + [
            'number' => '', 'image' => null, 'active' => false, 'sort' => $i + 1, 'min_amount' => 1000,
            'extras' => $d['type'] === 'asiacell' ? ['tiers' => array_map(fn($v) => ['iqd' => $v, 'bonus' => 0], ASIACELL_TIERS), 'max_transfer' => 10000] : [],
            'created_at' => now_str(),
        ]);
    }
}

function payment_methods(bool $activeOnly): array
{
    ensure_default_payment_methods();
    $rows = store_all('payment_methods');
    if ($activeOnly) {
        $rows = array_values(array_filter($rows, fn($m) => !empty($m['active'])));
    }
    usort($rows, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']);
    return $rows;
}

/** الحقول اللي يشوفها المستخدم (بدون مفاتيح Binance السرية) */
function pm_public(array $m): array
{
    $ex = $m['extras'] ?? [];
    return [
        'id' => $m['id'], 'type' => $m['type'], 'name' => $m['name'], 'number' => $m['number'],
        'note' => $m['note'], 'image' => $m['image'] ? '/pm-image/' . $m['image'] : null,
        'min_amount' => (int)($m['min_amount'] ?? 0),
        'tiers' => $m['type'] === 'asiacell' ? ($ex['tiers'] ?? []) : null,
        'qr' => !empty($ex['qr']) ? '/pm-image/' . $ex['qr'] : null,
        'usd_rate' => usd_rate(),
    ];
}

// ================================================================ Binance Pay (تحقق تلقائي من رقم العملية)
if (!defined('BINANCE_API_BASE')) {
    define('BINANCE_API_BASE', 'https://api.binance.com');
}
if (!defined('ASIACELL_API_BASE')) {
    define('ASIACELL_API_BASE', 'https://odpapp.asiacell.com');
}

function verify_binance_order(array $pm, string $orderId, float $expectedUsd): array
{
    $ex = $pm['extras'] ?? [];
    $key = trim((string)($ex['api_key'] ?? ''));
    $secret = trim((string)($ex['api_secret'] ?? ''));
    if ($key === '' || $secret === '') {
        return [false, 'لم يتم إعداد مفاتيح Binance من الإدارة بعد.'];
    }
    $now = (int)(microtime(true) * 1000);
    $qs = http_build_query(['timestamp' => $now, 'startTime' => $now - 7 * 86400000, 'endTime' => $now]);
    $url = BINANCE_API_BASE . '/sapi/v1/pay/transactions?' . $qs . '&signature=' . hash_hmac('sha256', $qs, $secret);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => ['X-MBX-APIKEY: ' . $key]]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = $resp ? json_decode($resp, true) : null;
    if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
        return [false, 'استجابة غير متوقعة من Binance - تأكد من مفاتيح API (صلاحية Pay).'];
    }
    foreach ($data['data'] as $row) {
        if ((string)($row['orderId'] ?? '') !== $orderId) {
            continue;
        }
        $ccy = strtoupper((string)($row['currency'] ?? ''));
        if (!in_array($ccy, ['USDT', 'USD', 'USDC', 'FDUSD'], true)) {
            return [false, "عملة العملية غير مدعومة ($ccy) - أرسل USDT"];
        }
        // (19) لازم تكون دفعة واردة (موجبة) - الدفعات اللي أرسلها صاحب الحساب تنرفض
        $amount = (float)($row['amount'] ?? 0);
        if ($amount <= 0) {
            return [false, 'هذي العملية مو دفعة واردة'];
        }
        if (abs($amount - $expectedUsd) > 0.0001) {
            return [false, 'المبلغ غير مطابق: المتوقع $' . $expectedUsd . ' والمستلم $' . $amount];
        }
        return [true, $row];
    }
    return [false, 'ما لقينا عملية بهذا الرقم خلال آخر 7 أيام.'];
}

// ================================================================ آسياسيل (تحويل رصيد تلقائي برمز SMS)

final class AsiaCellAPI
{
    public $deviceId;
    public $apiKey;
    public $accessToken;
    public $pid;
    public $transferPid;

    public function __construct(array $state = [])
    {
        $this->deviceId = $state['deviceId'] ?? sprintf('%s-%s-4%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), substr(bin2hex(random_bytes(2)), 1), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
        $this->apiKey = $state['apiKey'] ?? bin2hex(random_bytes(16));
        $this->accessToken = $state['accessToken'] ?? null;
        $this->pid = $state['pid'] ?? null;
        $this->transferPid = $state['transferPid'] ?? null;
    }

    public function state(): array
    {
        return ['deviceId' => $this->deviceId, 'apiKey' => $this->apiKey, 'accessToken' => $this->accessToken, 'pid' => $this->pid, 'transferPid' => $this->transferPid];
    }

    private function post(string $url, array $payload): ?array
    {
        $h = [
            'User-Agent: okhttp/5.0.0-alpha.2', 'X-OS-Version: 11', 'X-ODP-APP-VERSION: 4.3.7', 'X-FROM-APP: odp',
            'X-ODP-CHANNEL: mobile', 'X-SCREEN-TYPE: false', 'Content-Type: application/json; charset=UTF-8',
            'X-Device-Type: [Android][realme][RMX3834 11][TIRAMISU][HMS][4.3.7:90000325]',
            'X-ODP-API-KEY: ' . $this->apiKey, 'DeviceID: ' . $this->deviceId,
        ];
        if ($this->accessToken) {
            $h[] = 'Authorization: Bearer ' . $this->accessToken;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_HTTPHEADER => $h,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => '']);
        $resp = curl_exec($ch);
        curl_close($ch);
        $d = $resp ? json_decode($resp, true) : null;
        return is_array($d) ? $d : null;
    }

    private static function msg(array $d, string $fallback): string
    {
        foreach (['message', 'error', 'errorMessage', 'desc'] as $k) {
            if (!empty($d[$k]) && is_string($d[$k])) {
                return $d[$k];
            }
        }
        if (!empty($d['nextAction'])) {
            parse_str((string)parse_url((string)$d['nextAction'], PHP_URL_QUERY), $q);
            if (!empty($q['msg'])) {
                return (string)$q['msg'];
            }
        }
        return $fallback;
    }

    private static function pid(array $d): ?string
    {
        $pid = $d['PID'] ?? $d['pid'] ?? null;
        if (!$pid && !empty($d['nextUrl']) && preg_match('/PID=([a-zA-Z0-9\-]+)/', (string)$d['nextUrl'], $m)) {
            $pid = $m[1];
        }
        return $pid ? (string)$pid : null;
    }

    public function login(string $phone): array
    {
        $d = $this->post(ASIACELL_API_BASE . '/api/v1/login?lang=ar', ['captchaCode' => '', 'username' => $phone]);
        if (!$d) {
            return [false, 'لا يوجد رد من آسياسيل، حاول لاحقاً'];
        }
        if (($d['success'] ?? null) === false || !($pid = self::pid($d))) {
            return [false, self::msg($d, 'تعذر إرسال رمز التحقق لهذا الرقم')];
        }
        $this->pid = $pid;
        return [true, null];
    }

    public function verifySms(string $code): array
    {
        $d = $this->post(ASIACELL_API_BASE . '/api/v1/smsvalidation?lang=ar', ['PID' => $this->pid, 'passcode' => $code, 'token' => '']);
        if (!$d) {
            return [false, 'لا يوجد رد من آسياسيل، حاول لاحقاً'];
        }
        $token = $d['access_token'] ?? $d['accessToken'] ?? $d['token'] ?? null;
        if (($d['success'] ?? null) === false || !$token) {
            return [false, self::msg($d, 'رمز التحقق غير صحيح')];
        }
        $this->accessToken = $token;
        return [true, null];
    }

    public function startTransfer(int $amount, string $receiver): array
    {
        $d = $this->post(ASIACELL_API_BASE . '/api/v1/credit-transfer/start?lang=ar', ['amount' => $amount, 'receiverMsisdn' => $receiver]);
        if (!$d) {
            return [false, 'لا يوجد رد من آسياسيل، حاول لاحقاً'];
        }
        if (!($pid = self::pid($d))) {
            return [false, self::msg($d, 'تعذر بدء التحويل')];
        }
        $this->transferPid = $pid;
        return [true, null];
    }

    public function confirmTransfer(string $code): array
    {
        $d = $this->post(ASIACELL_API_BASE . '/api/v1/credit-transfer/do-transfer?lang=ar', ['PID' => $this->transferPid, 'passcode' => $code]);
        if (!$d) {
            return [false, 'لا يوجد رد من آسياسيل، حاول لاحقاً'];
        }
        return !empty($d['success']) ? [true, null] : [false, self::msg($d, 'فشل تأكيد التحويل')];
    }
}

function asiacell_flow(int $uid, ?array $set = null, bool $clear = false): ?array
{
    $out = null;
    store_update_doc('asiacell_flows', function ($d) use ($uid, $set, $clear, &$out) {
        if ($clear) {
            unset($d[(string)$uid]);
        } elseif ($set !== null) {
            $d[(string)$uid] = $set + ['updated' => time()];
        }
        $f = $d[(string)$uid] ?? null;
        $out = ($f && time() - ($f['updated'] ?? 0) < 1800) ? $f : null;
        return $d;
    });
    return $out;
}

/** يسجّل عملية شحن ناجحة/معلقة */
function record_recharge(int $uid, array $pm, int $amountIqd, string $status, array $extra = []): int
{
    return store_insert('recharges', array_merge([
        'user_id' => $uid, 'method_id' => $pm['id'], 'method_name' => $pm['name'], 'method_type' => $pm['type'],
        'amount' => $amountIqd, 'status' => $status, 'auto' => $status === 'approved', 'receipt' => null,
        'ref' => '', 'orig_amount' => null, 'currency' => 'IQD', 'admin_note' => '', 'created_at' => now_str(),
    ], $extra));
}

function notify_admins(string $title, string $body, string $kind = 'info'): void
{
    foreach (store_where('users', fn($u) => !empty($u['is_admin'])) as $a) {
        add_event((int)$a['id'], null, $title, $body, $kind);
    }
}
