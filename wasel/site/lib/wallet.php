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

// ================================================================ حدود الباقات (عدد الأرقام + الرسائل اليومية)

/** الباقة الفعّالة للمستخدم (صف الباقة) أو null */
function user_plan(?array $u): ?array
{
    if (!$u || !effective_plan_active($u)) {
        return null;
    }
    if (!empty($u['plan_id']) && ($p = store_find('plans', (int)$u['plan_id']))) {
        return $p;
    }
    $name = (string)($u['plan_name'] ?? '');
    return $name !== '' ? store_first('plans', fn($p) => ($p['name'] ?? '') === $name) : null;
}

/** حدود المستخدم: numbers = أقصى أرقام واتساب، daily = أقصى رسائل باليوم (0 = بدون حد) */
function user_limits(?array $u): array
{
    if (!$u || !empty($u['is_admin'])) {
        return ['kind' => 'admin', 'numbers' => 0, 'daily' => 0];
    }
    if (effective_plan_active($u)) {
        $p = user_plan($u);
        return ['kind' => 'plan', 'numbers' => max(0, (int)($p['max_numbers'] ?? 0)), 'daily' => max(0, (int)($p['daily_messages'] ?? 0))];
    }
    if (user_has_access($u)) { // الفترة المجانية
        $s = settings();
        return ['kind' => 'trial', 'numbers' => max(0, (int)($s['trial_max_numbers'] ?? 1)), 'daily' => max(0, (int)($s['trial_daily_messages'] ?? 20))];
    }
    return ['kind' => 'payg', 'numbers' => 0, 'daily' => 0];
}

function daily_used(int $uid): int
{
    $r = store_get_doc('msg_daily', [])[(string)$uid] ?? null;
    return $r && ($r['date'] ?? '') === date('Y-m-d') ? (int)$r['n'] : 0;
}

/** يحجز رسالة من حد اليوم (ذري). يرجع false لو خلص الحد */
function daily_take(int $uid, int $limit): bool
{
    $ok = false;
    store_update_doc('msg_daily', function ($d) use ($uid, $limit, &$ok) {
        $k = (string)$uid;
        $n = ($d[$k]['date'] ?? '') === date('Y-m-d') ? (int)$d[$k]['n'] : 0;
        if ($n < $limit) {
            $ok = true;
            $d[$k] = ['date' => date('Y-m-d'), 'n' => $n + 1];
        }
        // تنظيف الأيام القديمة
        foreach ($d as $kk => $v) {
            if (($v['date'] ?? '') !== date('Y-m-d')) {
                unset($d[$kk]);
            }
        }
        return $d;
    });
    return $ok;
}

/** يرجع رسالة لحد اليوم (لما يفشل الإرسال) */
function daily_give(int $uid): void
{
    store_update_doc('msg_daily', function ($d) use ($uid) {
        $k = (string)$uid;
        if (($d[$k]['date'] ?? '') === date('Y-m-d') && (int)$d[$k]['n'] > 0) {
            $d[$k]['n'] = (int)$d[$k]['n'] - 1;
        }
        return $d;
    });
}

function daily_limit_error(array $lim): string
{
    return $lim['kind'] === 'trial'
        ? 'وصلت حد الفترة المجانية (' . $lim['daily'] . ' رسالة باليوم) - يرجع يتجدد باچر، أو اشترك بباقة حتى ترسل أكثر'
        : 'وصلت حد باقتك اليومي (' . $lim['daily'] . ' رسالة) - يرجع يتجدد باچر';
}

function numbers_limit_error(array $lim): string
{
    return $lim['kind'] === 'trial'
        ? 'الفترة المجانية تسمح برقم واتساب واحد بس - اشترك بباقة حتى تربط أرقام أكثر'
        : 'باقتك تسمح بـ ' . $lim['numbers'] . ' أرقام واتساب كحد أقصى - احذف رقم أو رقّي باقتك';
}

/** خصم رسالة وحدة. يرجع [ok, charged, error] */
function charge_message(int $userId, string $note = 'رسالة واتساب'): array
{
    $u = user_by_id($userId);
    if (!$u || !empty($u['banned'])) {
        return [false, 0];
    }
    $lim = user_limits($u);
    if ($lim['daily'] > 0 && !daily_take($userId, $lim['daily'])) {
        return [false, 0, daily_limit_error($lim)];
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
        ['name' => 'الباقة الشهرية', 'price' => plan_price(), 'days' => plan_days(), 'color' => 'teal', 'popular' => false, 'max_numbers' => 3, 'daily_messages' => 0,
            'features' => "إرسال غير محدود\nرد آلي بالكلمات المفتاحية"],
        ['name' => 'باقة 3 أشهر', 'price' => (int)round(plan_price() * 2.6), 'days' => 90, 'color' => 'amber', 'popular' => true,
            'features' => "كل مزايا الشهرية\nتوفير 13%\nرد آلي بالذكاء الاصطناعي"],
        ['name' => 'الباقة السنوية', 'price' => plan_price() * 9, 'days' => 365, 'color' => 'violet', 'popular' => false,
            'features' => "كل المزايا\nتوفير 25%\nأولوية بالدعم"],
    ];
    foreach ($defaults as $i => $d) {
        store_insert('plans', $d + ['active' => true, 'sort' => $i + 1, 'created_at' => now_str()]);
    }
}

/** مرة وحدة: الباقة الشهرية الموجودة تاخذ حد 3 أرقام */
function migrate_plan_limits(): void
{
    if (!empty(settings()['plan_limits_v1'])) {
        return;
    }
    foreach (store_all('plans') as $p) {
        if (!array_key_exists('max_numbers', $p)) {
            $monthly = (int)$p['days'] <= 31;
            store_update('plans', $p['id'], fn($x) => array_merge($x, ['max_numbers' => $monthly ? 3 : 0, 'daily_messages' => 0,
                'features' => $monthly ? trim(preg_replace("/^رقم واتساب واحد أو أكثر\\R?/mu", '', (string)($x['features'] ?? ''))) : ($x['features'] ?? '')]));
        }
    }
    update_settings(['plan_limits_v1' => true]);
}

function plan_limit_lines(array $p): array
{
    $n = (int)($p['max_numbers'] ?? 0);
    $d = (int)($p['daily_messages'] ?? 0);
    return [$n > 0 ? ($n === 1 ? 'رقم واتساب واحد' : "حتى {$n} أرقام واتساب") : 'أرقام واتساب بدون حد',
        $d > 0 ? "{$d} رسالة يومياً" : 'رسائل بدون حد يومي'];
}

function plans(bool $activeOnly): array
{
    ensure_default_plans();
    migrate_plan_limits();
    $rows = store_all('plans');
    if ($activeOnly) {
        $rows = array_values(array_filter($rows, fn($p) => !empty($p['active'])));
    }
    usort($rows, fn($a, $b) => ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0) ?: $a['id'] <=> $b['id']);
    return array_map(fn($p) => $p + ['max_numbers' => 0, 'daily_messages' => 0, 'limit_lines' => plan_limit_lines($p),
        'features_list' => array_values(array_filter(array_map('trim', explode("\n", (string)($p['features'] ?? '')))))], $rows);
}

/** شراء/تجديد باقة من الرصيد (لو عنده نفس الباقة فعّالة تنضاف المدة فوقها) */
function buy_plan(int $userId, ?int $planId = null, string $coupon = ''): array
{
    $plan = $planId ? store_find('plans', $planId) : (plans(true)[0] ?? null);
    if (!$plan || empty($plan['active'])) {
        return [false, 'الباقة غير متاحة'];
    }
    $price = (int)$plan['price'];
    $cp = null;
    if (trim($coupon) !== '') {
        [$cok, $cerr, $cp, $final] = coupon_check($coupon, $plan, $userId);
        if (!$cok) {
            return [false, $cerr];
        }
        $price = $final;
    }
    $days = max(1, (int)$plan['days']);
    $bal = $price > 0 ? wallet_add($userId, -$price, 'plan', $plan['name'] . " ({$days} يوم)" . ($cp ? ' - كود ' . $cp['code'] : '')) : wallet_balance($userId);
    if ($bal === null) {
        return [false, 'رصيدك ما يكفي لهذي الباقة (' . number_format($price) . ' د.ع)'];
    }
    if ($cp) {
        coupon_use((int)$cp['id'], $userId);
    }
    $u = user_by_id($userId);
    $from = effective_plan_active($u) && ($e = parse_dt($u['plan_ends_at'] ?? null)) ? $e : time();
    update_user($userId, ['plan_active' => true, 'plan_ends_at' => date('Y-m-d H:i', $from + $days * 86400), 'plan_name' => $plan['name'], 'plan_id' => (int)$plan['id']]);
    add_event($userId, null, 'تم تفعيل باقتك', $plan['name'] . " لمدة {$days} يوم", 'success');
    return [true, null];
}

/** بعد شحن مرتبط بباقة: نفعّلها تلقائياً لو الرصيد صار يكفي */
/** سعر الباقة النهائي للمستخدم (بعد كود الخصم إذا صالح) */
function plan_final_price(array $plan, int $userId, string $coupon = ''): int
{
    if (trim($coupon) !== '') {
        [$ok, , , $final] = coupon_check($coupon, $plan, $userId);
        if ($ok) {
            return $final;
        }
    }
    return (int)$plan['price'];
}

/** بعد شحن مرتبط بباقة: نفعّلها لو الرصيد صار يكفي، وإلا نرجع المبلغ الباقي. يرجع ['done' => bool, 'remaining' => int] */
function apply_recharge_plan(int $userId, $planId, string $coupon = ''): array
{
    if (!$planId || !($plan = store_find('plans', (int)$planId))) {
        return ['done' => false, 'remaining' => 0];
    }
    [$ok, $err] = buy_plan($userId, (int)$planId, $coupon);
    if (!$ok && $coupon !== '') {
        [$ok, $err] = buy_plan($userId, (int)$planId); // الكود صار غير صالح: نجرب بالسعر الكامل
    }
    if ($ok) {
        return ['done' => true, 'remaining' => 0];
    }
    $remaining = max(0, plan_final_price($plan, $userId, $coupon) - wallet_balance($userId));
    add_event($userId, null, 'باقي مبلغ لتفعيل الباقة', 'انضاف المبلغ لرصيدك - باقي ' . number_format($remaining) . ' د.ع وتتفعّل «' . $plan['name'] . '» تلقائياً', 'warning');
    return ['done' => false, 'remaining' => $remaining, 'plan_id' => (int)$planId];
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

// ================================================================ رابط الموقع برسائل الفترة المجانية
const TRIAL_FOOTER_DEFAULT = "━━━━━━━━━━\n📲 أُرسلت مجاناً عبر {site}\n{url}";

/** رابط الموقع العام: اللي حدده الأدمن، وإلا اللي انحفظ من آخر زيارة أدمن (ما ناخذه من طلبات المستخدمين حتى ما أحد يزوّره) */
function site_public_url(): string
{
    $s = settings();
    foreach ([$s['site_url'] ?? '', $s['public_url'] ?? '', ($s['domain'] ?? '') !== '' ? 'https://' . $s['domain'] : ''] as $u) {
        if (trim((string)$u) !== '') {
            return rtrim(trim((string)$u), '/');
        }
    }
    return '';
}

/** يحفظ رابط الموقع من طلب أدمن مسجّل (مرة وحدة لما يتغير) */
function remember_public_url(): void
{
    if (empty($_SERVER['HTTP_HOST']) || is_local_host()) {
        return;
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $u = ($https ? 'https' : 'http') . '://' . strtolower((string)$_SERVER['HTTP_HOST']);
    if (preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#', $u) && (settings()['public_url'] ?? '') !== $u) {
        update_settings(['public_url' => $u]);
    }
}

/** المستخدم بالفترة المجانية؟ (مو أدمن، ما عنده باقة، وتجربته فعّالة) */
function is_trial_user(?array $u): bool
{
    if (!$u || !empty($u['is_admin']) || effective_plan_active($u)) {
        return false;
    }
    $t = parse_dt($u['trial_ends_at'] ?? null);
    return $t !== null && time() < $t;
}

function trial_footer(): string
{
    $s = settings();
    $tpl = trim((string)($s['trial_footer'] ?? '')) ?: TRIAL_FOOTER_DEFAULT;
    $url = site_public_url();
    $out = strtr($tpl, ['{site}' => ($s['site_name'] ?? '') ?: 'واصل', '{url}' => $url]);
    if ($url !== '' && !str_contains($out, $url)) {
        $out .= "\n" . $url; // الرابط إجباري حتى لو الأدمن شاله من النص
    }
    return trim($out);
}

/** يضيف رابط الموقع لنص الرسالة إذا صاحبها بالفترة المجانية */
function with_trial_footer(int $uid, string $text): string
{
    if (!is_trial_user(user_by_id($uid))) {
        return $text;
    }
    $f = trial_footer();
    return trim($text) === '' ? $f : rtrim($text) . "\n\n" . $f;
}
