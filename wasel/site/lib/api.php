<?php
/**
 * مسارات الواجهة الجديدة: الحساب، المحفظة والشحن، ولوحة الأدمن (المستخدمين، طلبات الشحن، طرق الدفع،
 * الإعدادات، الأمان، النسخ الاحتياطي). ترجع مصفوفة مسارات تُدمج مع مسارات index.php.
 */

function me_payload(int $id): array
{
    $u = user_by_id($id);
    if (!empty($u['is_admin'])) {
        remember_public_url();
    }
    $trialEnds = parse_dt($u['trial_ends_at'] ?? null);
    $planEnds = parse_dt($u['plan_ends_at'] ?? null);
    $active = effective_plan_active($u);
    return [
        'id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'phone' => $u['phone'],
        'is_admin' => !empty($u['is_admin']), 'balance' => (int)($u['balance'] ?? 0),
        'plan_active' => $active, 'plan_ends_at' => $active ? $u['plan_ends_at'] : null, 'plan_name' => $active ? ($u['plan_name'] ?? PLAN_NAME) : null,
        'plan_days_left' => $active ? ($planEnds ? max(0, (int)ceil(($planEnds - time()) / 86400)) : null) : 0,
        'trial_active' => !$active && $trialEnds && $trialEnds > time(),
        'trial_days_left' => !$active && $trialEnds && $trialEnds > time() ? (int)ceil(($trialEnds - time()) / 86400) : 0,
        'can_send' => can_send($u), 'chats_allowed' => chats_allowed($u), 'created_at' => $u['created_at'], 'ui' => ui_clean($u['ui'] ?? []),
        'limits' => (function () use ($u, $id) {
            $l = user_limits($u);
            return $l + ['numbers_used' => count(wa_accounts_of($id)), 'daily_used' => $l['daily'] > 0 ? daily_used($id) : 0];
        })(),
        'login' => !empty($u['google_sub']) ? 'google' : (empty($u['phone']) && !empty($u['email']) ? 'email' : 'whatsapp'),
    ];
}

function public_settings(): array
{
    $s = settings();
    return [
        'plan_name' => PLAN_NAME, 'plan_price' => plan_price(), 'plan_days' => plan_days(),
        'message_price' => message_price(), 'usd_rate' => usd_rate(), 'trial_days' => trial_days(),
        'support_number' => $s['whatsapp_support_number'] ?: WHATSAPP_PAY_NUMBER,
        'leads_enabled' => !isset($s['leads_enabled']) || !empty($s['leads_enabled']),
        'default_style' => ui_default_style(), 'trial_footer' => trial_footer(),
        'dev' => $s['dev_phone'] !== '' ? ['name' => $s['dev_name'] ?: 'المطوّر', 'title' => $s['dev_title'] ?: 'مطوّر المنصة',
            'phone' => $s['dev_phone'], 'photo' => !empty($s['dev_photo']) && is_file(DATA_DIR . '/dev.bin') ? '/dev-photo?v=' . (int)filemtime(DATA_DIR . '/dev.bin') : null] : null,
    ];
}

/** يقرأ صورة من رفع ملف أو data-URL */
function incoming_image(string $field, string $sub): ?string
{
    if (!empty($_FILES[$field]['name'])) {
        return save_uploaded_image($_FILES[$field], $sub);
    }
    $d = request_json()[$field] ?? ($_POST[$field] ?? '');
    return is_string($d) && $d !== '' ? save_data_image($d, $sub) : null;
}

function api_routes(): array
{
    return [
        // ------------------------------------------------ الحساب
        ['GET', '#^/api/me$#', function () {
            json_out(['ok' => true, 'me' => me_payload(require_login()), 'settings' => public_settings()]);
        }],
        ['POST', '#^/api/me$#', function () {
            $id = require_login();
            $name = clean_text(mb_substr(trim((string)(request_json()['name'] ?? '')), 0, 60));
            if ($name === '') {
                json_err('اكتب اسمك');
            }
            update_user($id, ['name' => $name]);
            $_SESSION['name'] = $name;
            json_out(['ok' => true]);
        }],
        ['GET', '#^/api/wallet/tx$#', function () {
            $id = require_login();
            $rows = array_reverse(store_where('wallet_tx', fn($t) => (int)$t['user_id'] === $id));
            json_out(['ok' => true, 'items' => array_slice($rows, 0, 100)]);
        }],
        ['GET', '#^/api/plans$#', function () {
            require_login();
            json_out(['ok' => true, 'plans' => plans(true)]);
        }],
        ['POST', '#^/api/plan/buy$#', function () {
            $id = require_login();
            $d = request_json();
            [$ok, $err] = buy_plan($id, (int)($d['plan_id'] ?? 0) ?: null, (string)($d['coupon'] ?? ''));
            $ok ? json_out(['ok' => true, 'me' => me_payload($id)]) : json_err($err);
        }],

        // ------------------------------------------------ الشحن
        ['GET', '#^/api/payment-methods$#', function () {
            require_login();
            json_out(['ok' => true, 'methods' => array_map('pm_public', payment_methods(true))]);
        }],
        ['GET', '#^/pm-image/([a-f0-9]{16}\.(?:png|jpg|gif|webp))$#', fn($n) => serve_image('pm', $n)],
        ['GET', '#^/api/recharge/history$#', function () {
            $id = require_login();
            json_out(['ok' => true, 'items' => array_slice(array_reverse(store_where('recharges', fn($r) => (int)$r['user_id'] === $id)), 0, 50)]);
        }],
        ['POST', '#^/api/recharge/manual$#', function () {
            $id = require_login();
            rate_limit_or_fail('recharge_manual', 6, 3600);
            $pm = store_find('payment_methods', (int)($_POST['method_id'] ?? 0));
            if (!$pm || empty($pm['active']) || !in_array($pm['type'], ['manual', 'superkey'], true)) {
                json_err('طريقة الدفع غير متاحة');
            }
            $amount = (int)($_POST['amount'] ?? 0);
            if ($amount > 50000000) {
                json_err('المبلغ غير منطقي');
            }
            if ($amount < max(1, (int)($pm['min_amount'] ?? 0))) {
                json_err('أقل مبلغ للشحن ' . number_format(max(1, (int)$pm['min_amount'])) . ' د.ع');
            }
            $receipt = !empty($_FILES['receipt']['name']) ? save_uploaded_image($_FILES['receipt'], 'receipts') : null;
            if (!$receipt) {
                json_err('ارفع صورة إيصال التحويل');
            }
            $pending = count(store_where('recharges', fn($r) => (int)$r['user_id'] === $id && $r['status'] === 'pending'));
            if ($pending >= 3) {
                json_err('عندك 3 طلبات شحن قيد المراجعة - انتظر مراجعتها');
            }
            $planId = (int)($_POST['plan_id'] ?? 0) ?: null;
            record_recharge($id, $pm, $amount, 'pending', [
                'receipt' => $receipt, 'ref' => clean_text(mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 80)),
                'plan_id' => $planId, 'plan_name' => $planId ? (store_find('plans', $planId)['name'] ?? null) : null,
                'coupon' => $planId ? coupon_norm((string)($_POST['coupon'] ?? '')) : '',
            ]);
            add_event($id, null, 'طلب شحن قيد المراجعة', number_format($amount) . ' د.ع عبر ' . $pm['name'], 'info');
            notify_admins('طلب شحن جديد', number_format($amount) . ' د.ع عبر ' . $pm['name']);
            json_out(['ok' => true, 'msg' => 'تم إرسال طلب الشحن - راح يتراجع ويضاف لرصيدك قريباً']);
        }],
        ['POST', '#^/api/recharge/binance$#', function () {
            $id = require_login();
            rate_limit_or_fail('binance', 8, 600);
            $d = request_json();
            $pm = store_first('payment_methods', fn($m) => $m['type'] === 'binance' && !empty($m['active']));
            if (!$pm) {
                json_err('Binance Pay غير متاح حالياً');
            }
            $usd = round((float)($d['amount_usd'] ?? 0), 2);
            $order = trim((string)($d['order_id'] ?? ''));
            if ($usd <= 0 || $usd > 10000) {
                json_err('أدخل المبلغ بالدولار');
            }
            if (!preg_match('/^\d{6,30}$/', $order)) {
                json_err('أدخل رقم العملية (Order ID) بشكل صحيح');
            }
            // قفل حتى ما ينستخدم نفس رقم العملية بطلبين بنفس اللحظة
            $lk = fopen(DATA_DIR . '/binance.lock', 'c');
            flock($lk, LOCK_EX);
            if (store_first('recharges', fn($r) => ($r['ref'] ?? '') === $order && $r['method_type'] === 'binance')) {
                flock($lk, LOCK_UN);
                json_err('رقم العملية هذا مستخدم مسبقاً');
            }
            [$ok, $res] = verify_binance_order($pm, $order, $usd);
            if (!$ok) {
                flock($lk, LOCK_UN);
                json_err($res);
            }
            $iqd = (int)round($usd * usd_rate());
            record_recharge($id, $pm, $iqd, 'approved', ['ref' => $order, 'orig_amount' => $usd, 'currency' => 'USD']);
            flock($lk, LOCK_UN);
            $bal = wallet_add($id, $iqd, 'recharge', "Binance Pay \${$usd}");
            $planRes = apply_recharge_plan($id, $d['plan_id'] ?? null, (string)($d['coupon'] ?? ''));
            $bal = wallet_balance($id);
            add_event($id, null, 'تم شحن رصيدك', number_format($iqd) . ' د.ع عبر Binance Pay', 'success');
            notify_admins('شحن تلقائي', number_format($iqd) . " د.ع عبر Binance (\${$usd})", 'success');
            json_out(['ok' => true, 'msg' => 'تم التحقق وإضافة ' . number_format($iqd) . ' د.ع لرصيدك', 'balance' => $bal, 'plan' => $planRes]);
        }],
        ['POST', '#^/api/recharge/asiacell/(start|verify|confirm|cancel)$#', 'handle_asiacell'],

        // ------------------------------------------------ الأدمن: إحصائيات ومستخدمين
        ['GET', '#^/api/admin/stats$#', function () {
            require_admin();
            $users = store_all('users');
            $rech = store_all('recharges');
            $today = date('Y-m-d');
            $live = engine_accounts();
            json_out(['ok' => true,
                'users' => count($users),
                'users_today' => count(array_filter($users, fn($u) => strpos((string)$u['created_at'], $today) === 0)),
                'active_plans' => count(array_filter($users, 'effective_plan_active')),
                'trials' => count(array_filter($users, fn($u) => !effective_plan_active($u) && user_has_access($u))),
                'balances' => array_sum(array_map(fn($u) => (int)($u['balance'] ?? 0), $users)),
                'pending_recharges' => count(array_filter($rech, fn($r) => $r['status'] === 'pending')),
                'revenue' => array_sum(array_map(fn($r) => $r['status'] === 'approved' ? (int)$r['amount'] : 0, $rech)),
                'revenue_today' => array_sum(array_map(fn($r) => $r['status'] === 'approved' && strpos($r['created_at'], $today) === 0 ? (int)$r['amount'] : 0, $rech)),
                'wa_accounts' => count(store_all('wa_accounts')),
                'wa_connected' => count(array_filter($live, fn($a) => !empty($a['logged_in']))),
                'engine' => ($h = engine_json('GET', '/health', null, 4))['ok'] ?? false,
                'engine_outdated' => !empty($h['ok']) && (int)($h['version'] ?? 1) < REQUIRED_ENGINE_VERSION,
            ]);
        }],
        ['GET', '#^/api/admin/users$#', function () {
            require_admin();
            $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
            $rows = [];
            foreach (array_reverse(store_all('users')) as $u) {
                $hay = mb_strtolower(($u['name'] ?? '') . ' ' . ($u['email'] ?? '') . ' ' . ($u['phone'] ?? ''));
                if ($q !== '' && mb_strpos($hay, $q) === false) {
                    continue;
                }
                $rows[] = me_payload((int)$u['id']) + ['banned' => !empty($u['banned']), 'last_login_at' => $u['last_login_at'] ?? null, 'last_ip' => $u['last_ip'] ?? null,
                    'wa_count' => count(wa_accounts_of((int)$u['id']))];
            }
            json_out(['ok' => true, 'users' => $rows]);
        }],
        ['POST', '#^/api/admin/users/(\d+)$#', function ($uidT) {
            $admin = require_admin();
            $uidT = (int)$uidT;
            $u = user_by_id($uidT);
            if (!$u) {
                json_err('مستخدم غير موجود', 404);
            }
            $d = request_json();
            $action = (string)($d['action'] ?? '');
            $val = (int)($d['value'] ?? 0);
            admin_log('user_' . $action, '#' . $uidT . ' value=' . $val);
            switch ($action) {
                case 'balance_add':
                    if ($val === 0) {
                        json_err('أدخل مبلغ');
                    }
                    wallet_add($uidT, $val, 'admin', $val > 0 ? 'إضافة من الإدارة' : 'خصم من الإدارة', true);
                    add_event($uidT, null, $val > 0 ? 'تمت إضافة رصيد' : 'تم خصم رصيد', number_format(abs($val)) . ' د.ع من الإدارة', $val > 0 ? 'success' : 'warning');
                    break;
                case 'plan_days':
                    if ($val > 0) {
                        activate_plan_for_days($uidT, $val);
                    } else {
                        set_plan_active($uidT, false);
                    }
                    break;
                case 'trial_days':
                    update_user($uidT, ['trial_ends_at' => date('Y-m-d H:i', time() + max(0, $val) * 86400), 'trial_ended_notified' => false]);
                    break;
                case 'ban':
                case 'unban':
                    if ($uidT === $admin) {
                        json_err('ما تقدر تحظر نفسك');
                    }
                    update_user($uidT, ['banned' => $action === 'ban']);
                    break;
                case 'admin':
                case 'unadmin':
                    if ($uidT === $admin) {
                        json_err('ما تقدر تغيّر صلاحياتك');
                    }
                    update_user($uidT, ['is_admin' => $action === 'admin']);
                    break;
                default:
                    json_err('إجراء غير معروف');
            }
            json_out(['ok' => true, 'user' => me_payload($uidT) + ['banned' => !empty(user_by_id($uidT)['banned'])]]);
        }],

        // ------------------------------------------------ الأدمن: طلبات الشحن
        ['GET', '#^/api/admin/recharges$#', function () {
            require_admin();
            $status = (string)($_GET['status'] ?? 'pending');
            $rows = array_reverse(store_where('recharges', fn($r) => $status === 'all' || $r['status'] === $status));
            foreach ($rows as &$r) {
                $u = user_by_id((int)$r['user_id']);
                $r['user_name'] = $u['name'] ?? '';
                $r['user_contact'] = $u ? ($u['email'] ?: $u['phone']) : '';
                $r['receipt_url'] = $r['receipt'] ? '/api/admin/receipt/' . $r['receipt'] : null;
            }
            json_out(['ok' => true, 'items' => array_slice($rows, 0, 200)]);
        }],
        ['GET', '#^/api/admin/receipt/([a-f0-9]{16}\.(?:png|jpg|gif|webp))$#', function ($n) {
            require_admin();
            serve_image('receipts', $n, false);
        }],
        ['POST', '#^/api/admin/recharges/(\d+)/(approve|reject)$#', function ($rid, $act) {
            require_admin();
            $d = request_json();
            $r = store_find('recharges', $rid);
            if (!$r || $r['status'] !== 'pending') {
                json_err('الطلب غير موجود أو تمت مراجعته');
            }
            $amount = isset($d['amount']) && (int)$d['amount'] > 0 ? min(50000000, (int)$d['amount']) : (int)$r['amount'];
            $note = clean_text(mb_substr(trim((string)($d['note'] ?? '')), 0, 200));
            // تغيير الحالة يصير مرة وحدة فقط (لو انضغط «قبول» مرتين أو أدمنين بنفس اللحظة)
            $changed = false;
            store_update('recharges', $rid, function ($x) use (&$changed, $act, $amount, $note) {
                if ($x['status'] !== 'pending') {
                    return $x;
                }
                $changed = true;
                return array_merge($x, ['status' => $act === 'approve' ? 'approved' : 'rejected', 'amount' => $amount, 'admin_note' => $note,
                    'reviewed_at' => now_str(), 'reviewed_by' => uid()]);
            });
            if (!$changed) {
                json_err('الطلب تمت مراجعته مسبقاً');
            }
            admin_log('recharge_' . $act, '#' . $rid . ' amount=' . $amount);
            if ($act === 'approve') {
                wallet_add((int)$r['user_id'], $amount, 'recharge', $r['method_name']);
                apply_recharge_plan((int)$r['user_id'], $r['plan_id'] ?? null, (string)($r['coupon'] ?? ''));
                add_event((int)$r['user_id'], null, 'تم شحن رصيدك', number_format($amount) . ' د.ع عبر ' . $r['method_name'], 'success');
            } else {
                add_event((int)$r['user_id'], null, 'تم رفض طلب الشحن', $note ?: 'تواصل مع الدعم للتفاصيل', 'warning');
            }
            json_out(['ok' => true]);
        }],

        // ------------------------------------------------ الأدمن: الباقات
        ['GET', '#^/api/admin/plans$#', function () {
            require_admin();
            json_out(['ok' => true, 'plans' => plans(false), 'colors' => PLAN_COLORS]);
        }],
        ['POST', '#^/api/admin/plans(?:/(\d+))?$#', function ($pid = null) {
            require_admin();
            $d = request_json();
            $old = $pid ? store_find('plans', $pid) : null;
            if ($pid && !$old) {
                json_err('الباقة غير موجودة', 404);
            }
            $row = [
                'name' => clean_text(mb_substr(trim((string)($d['name'] ?? ($old['name'] ?? 'باقة جديدة'))), 0, 40)) ?: 'باقة',
                'price' => max(0, (int)($d['price'] ?? ($old['price'] ?? 0))),
                'days' => max(1, (int)($d['days'] ?? ($old['days'] ?? 30))),
                'features' => clean_text(mb_substr((string)($d['features'] ?? ($old['features'] ?? '')), 0, 600)),
                'color' => in_array($d['color'] ?? '', PLAN_COLORS, true) ? $d['color'] : ($old['color'] ?? 'teal'),
                'popular' => array_key_exists('popular', $d) ? !empty($d['popular']) : !empty($old['popular']),
                'active' => array_key_exists('active', $d) ? !empty($d['active']) : ($old['active'] ?? true),
                'sort' => (int)($d['sort'] ?? ($old['sort'] ?? 99)),
                'max_numbers' => max(0, min(100, (int)($d['max_numbers'] ?? ($old['max_numbers'] ?? 0)))),
                'daily_messages' => max(0, min(100000, (int)($d['daily_messages'] ?? ($old['daily_messages'] ?? 0)))),
            ];
            $old ? store_update('plans', $pid, fn($p) => array_merge($p, $row)) : store_insert('plans', $row + ['created_at' => now_str()]);
            json_out(['ok' => true]);
        }],
        ['DELETE', '#^/api/admin/plans/(\d+)$#', function ($pid) {
            require_admin();
            store_delete('plans', $pid);
            json_out(['ok' => true]);
        }],

        // ------------------------------------------------ الأدمن: طرق الدفع
        ['GET', '#^/api/admin/payment-methods$#', function () {
            require_admin();
            $rows = array_map(function ($m) {
                $p = pm_public($m) + ['active' => !empty($m['active']), 'sort' => $m['sort'] ?? 0];
                $ex = $m['extras'] ?? [];
                $p['has_api_key'] = !empty($ex['api_key']);
                $p['max_transfer'] = (int)($ex['max_transfer'] ?? 10000);
                return $p;
            }, payment_methods(false));
            json_out(['ok' => true, 'methods' => $rows, 'types' => PM_TYPES]);
        }],
        ['POST', '#^/api/admin/payment-methods(?:/(\d+))?$#', function ($pmId = null) {
            require_admin();
            $d = request_json();
            $existing = $pmId ? store_find('payment_methods', $pmId) : null;
            if ($pmId && !$existing) {
                json_err('طريقة غير موجودة', 404);
            }
            $type = $existing['type'] ?? (string)($d['type'] ?? 'manual');
            if (!isset(PM_TYPES[$type])) {
                json_err('نوع غير معروف');
            }
            $ex = $existing['extras'] ?? [];
            if ($type === 'binance') {
                foreach (['api_key', 'api_secret'] as $k) {
                    if (trim((string)($d[$k] ?? '')) !== '') {
                        $ex[$k] = trim((string)$d[$k]);
                    }
                }
                if (!empty($d['qr'])) {
                    $ex['qr'] = save_data_image((string)$d['qr'], 'pm') ?? ($ex['qr'] ?? null);
                }
            }
            if ($type === 'asiacell') {
                $tiers = [];
                foreach ((array)($d['tiers'] ?? []) as $t) {
                    $iqd = (int)($t['iqd'] ?? 0);
                    if (in_array($iqd, ASIACELL_TIERS, true)) {
                        $tiers[] = ['iqd' => $iqd, 'bonus' => max(0, (int)($t['bonus'] ?? 0))];
                    }
                }
                if ($tiers) {
                    $ex['tiers'] = $tiers;
                }
                $ex['max_transfer'] = max(1000, (int)($d['max_transfer'] ?? ($ex['max_transfer'] ?? 10000)));
            }
            $row = [
                'type' => $type,
                'name' => clean_text(mb_substr(trim((string)($d['name'] ?? ($existing['name'] ?? PM_TYPES[$type]))), 0, 40)) ?: PM_TYPES[$type],
                'number' => clean_text(mb_substr(trim((string)($d['number'] ?? ($existing['number'] ?? ''))), 0, 80)),
                'note' => clean_text(mb_substr(trim((string)($d['note'] ?? ($existing['note'] ?? ''))), 0, 300)),
                'active' => array_key_exists('active', $d) ? !empty($d['active']) : !empty($existing['active']),
                'min_amount' => max(0, (int)($d['min_amount'] ?? ($existing['min_amount'] ?? 1000))),
                'sort' => (int)($d['sort'] ?? ($existing['sort'] ?? 99)),
                'extras' => $ex,
                'image' => $existing['image'] ?? null,
            ];
            if (!empty($d['image'])) {
                $img = save_data_image((string)$d['image'], 'pm');
                if (!$img) {
                    json_err('صورة الشعار غير صالحة');
                }
                $row['image'] = $img;
            } elseif (!empty($d['remove_image'])) {
                $row['image'] = null;
            }
            if ($existing) {
                store_update('payment_methods', $pmId, fn($m) => array_merge($m, $row));
            } else {
                store_insert('payment_methods', $row + ['created_at' => now_str()]);
            }
            json_out(['ok' => true]);
        }],
        ['DELETE', '#^/api/admin/payment-methods/(\d+)$#', function ($pmId) {
            require_admin();
            store_delete('payment_methods', $pmId);
            json_out(['ok' => true]);
        }],

        // ------------------------------------------------ الأدمن: الإعدادات
        ['GET', '#^/api/admin/settings$#', function () {
            require_admin();
            $s = settings();
            [, $hasLogo] = branding_context();
            json_out(['ok' => true, 'settings' => [
                'site_name' => $s['site_name'], 'has_logo' => $hasLogo,
                'default_country_code' => default_country_code(),
                'plan_price' => plan_price(), 'plan_days' => plan_days(), 'trial_days' => trial_days(),
                'message_price' => message_price(), 'usd_rate' => usd_rate(),
                'whatsapp_support_number' => $s['whatsapp_support_number'],
                'whatsapp_login' => !empty($s['whatsapp_login']),
                'admin_email' => $s['admin_email'],
                'tg_token_set' => $s['tg_token'] !== '', 'tg_chat_id' => $s['tg_chat_id'],
                'tg_auto_backup' => !empty($s['tg_auto_backup']), 'tg_last_backup' => $s['tg_last_backup'],
                'ai_key_set' => $s['ai_api_key'] !== '', 'ai_knowledge_base' => $s['ai_knowledge_base'],
                'leads_enabled' => !empty($s['leads_enabled']), 'leads_daily' => (int)$s['leads_daily'], 'leads_max' => (int)$s['leads_max'],
                'trial_max_numbers' => (int)$s['trial_max_numbers'], 'trial_daily_messages' => (int)$s['trial_daily_messages'],
                'trial_footer' => trim((string)$s['trial_footer']) ?: TRIAL_FOOTER_DEFAULT, 'site_url' => $s['site_url'], 'public_url' => site_public_url(),
                'trial_footer_preview' => trial_footer(),
                'google_client_id' => GOOGLE_CLIENT_ID,
                'dev_name' => $s['dev_name'], 'dev_title' => $s['dev_title'], 'dev_phone' => $s['dev_phone'], 'dev_photo' => !empty($s['dev_photo']),
                'cron_url' => site_base_url() . '/cron/setup?key=' . CRON_KEY, 'last_cron' => $s['last_cron'] ? date('Y-m-d H:i', (int)$s['last_cron']) : null,
            ]]);
        }],
        ['POST', '#^/api/admin/settings$#', function () {
            require_admin();
            $d = request_json();
            $f = [];
            $ints = ['plan_price' => 'plan_price_iqd', 'plan_days' => 'plan_days', 'trial_days' => 'trial_days', 'message_price' => 'message_price'];
            foreach ($ints as $in => $key) {
                if (array_key_exists($in, $d)) {
                    $f[$key] = max(0, (int)$d[$in]);
                }
            }
            if (isset($f['plan_days'])) {
                $f['plan_days'] = max(1, $f['plan_days']);
            }
            if (array_key_exists('usd_rate', $d)) {
                $f['usd_rate'] = max(1, (float)$d['usd_rate']);
            }
            if (array_key_exists('site_name', $d)) {
                $f['site_name'] = clean_text(mb_substr(trim((string)$d['site_name']), 0, 60));
            }
            if (array_key_exists('default_country_code', $d) && valid_country_code((string)$d['default_country_code'])) {
                $f['default_country_code'] = (string)$d['default_country_code'];
            }
            if (array_key_exists('whatsapp_support_number', $d)) {
                $f['whatsapp_support_number'] = digits_only($d['whatsapp_support_number']);
                $f['whatsapp_subscribe_number'] = $f['whatsapp_support_number'];
            }
            if (array_key_exists('whatsapp_login', $d)) {
                $f['whatsapp_login'] = !empty($d['whatsapp_login']);
            }
            if (array_key_exists('admin_email', $d)) {
                $em = strtolower(trim((string)$d['admin_email']));
                if ($em !== '' && !filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    json_err('بريد الأدمن غير صحيح');
                }
                $f['admin_email'] = $em;
            }
            if (trim((string)($d['tg_token'] ?? '')) !== '') {
                $f['tg_token'] = trim((string)$d['tg_token']);
            }
            if (array_key_exists('tg_chat_id', $d)) {
                $f['tg_chat_id'] = trim((string)$d['tg_chat_id']);
            }
            if (array_key_exists('tg_auto_backup', $d)) {
                $f['tg_auto_backup'] = !empty($d['tg_auto_backup']);
            }
            if (array_key_exists('trial_max_numbers', $d)) {
                $f['trial_max_numbers'] = max(0, min(100, (int)$d['trial_max_numbers']));
            }
            if (array_key_exists('trial_footer', $d)) {
                $t = trim(str_replace("\r", '', (string)$d['trial_footer']));
                $f['trial_footer'] = $t === TRIAL_FOOTER_DEFAULT ? '' : mb_substr($t, 0, 300);
            }
            if (array_key_exists('site_url', $d)) {
                $u = rtrim(trim((string)$d['site_url']), '/');
                if ($u !== '' && !preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/[\w./-]*)?$#i', $u)) {
                    json_err('رابط الموقع لازم يبدأ بـ https:// مثل https://botiyeai.site');
                }
                $f['site_url'] = $u;
            }
            if (array_key_exists('default_style', $d)) {
                $f['default_style'] = isset(UI_STYLES[$d['default_style']]) ? $d['default_style'] : 'teal';
            }
            if (array_key_exists('trial_daily_messages', $d)) {
                $f['trial_daily_messages'] = max(0, min(100000, (int)$d['trial_daily_messages']));
            }
            if (array_key_exists('leads_enabled', $d)) {
                $f['leads_enabled'] = !empty($d['leads_enabled']);
            }
            if (array_key_exists('leads_daily', $d)) {
                $f['leads_daily'] = max(0, min(1000, (int)$d['leads_daily']));
            }
            if (array_key_exists('leads_max', $d)) {
                $f['leads_max'] = max(10, min(500, (int)$d['leads_max']));
            }
            if (trim((string)($d['ai_api_key'] ?? '')) !== '') {
                $f['ai_api_key'] = trim((string)$d['ai_api_key']);
            }
            if (array_key_exists('ai_knowledge_base', $d)) {
                $f['ai_knowledge_base'] = (string)$d['ai_knowledge_base'];
            }
            if (array_key_exists('logo', $d)) {
                if ($d['logo']) {
                    if (!preg_match('#^data:(image/(png|jpeg|gif|webp));base64,(.+)$#s', (string)$d['logo'], $m) || strlen($d['logo']) > MAX_LOGO_B64_CHARS) {
                        json_err('الشعار لازم يكون صورة PNG/JPG/WEBP أقل من 2 ميغابايت');
                    }
                    file_put_contents(logo_path(), base64_decode($m[3]));
                    $f['has_logo'] = true;
                    $f['logo_mime'] = $m[1];
                } else {
                    @unlink(logo_path());
                    $f['has_logo'] = false;
                }
                @unlink(DATA_DIR . '/pwa-192.png');
                @unlink(DATA_DIR . '/pwa-512.png');
            }
            foreach (['dev_name' => 40, 'dev_title' => 60] as $k => $max) {
                if (array_key_exists($k, $d)) {
                    $f[$k] = clean_text(mb_substr(trim((string)$d[$k]), 0, $max));
                }
            }
            if (array_key_exists('dev_phone', $d)) {
                $f['dev_phone'] = digits_only($d['dev_phone']);
            }
            if (array_key_exists('dev_photo', $d)) {
                if ($d['dev_photo'] && preg_match('#^data:image/(png|jpeg|webp|gif);base64,(.+)$#s', (string)$d['dev_photo'], $m) && strlen($m[2]) < 3000000) {
                    $raw = base64_decode($m[2], true);
                    if ($raw !== false && @getimagesizefromstring($raw)) {
                        file_put_contents(DATA_DIR . '/dev.bin', $raw);
                        $f['dev_photo'] = true;
                    }
                } elseif (!$d['dev_photo']) {
                    @unlink(DATA_DIR . '/dev.bin');
                    $f['dev_photo'] = false;
                }
            }
            if (array_key_exists('google_client_id', $d)) {
                $cfgPath = DATA_DIR . '/config.json';
                $cfg = json_decode((string)@file_get_contents($cfgPath), true) ?: [];
                $cfg['GOOGLE_CLIENT_ID'] = trim((string)$d['google_client_id']);
                file_put_contents($cfgPath, json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
            update_settings($f);
            admin_log('settings_saved', implode(',', array_keys($f)));
            json_out(['ok' => true]);
        }],

        // ------------------------------------------------ الأدمن: الأمان
        ['GET', '#^/api/admin/security$#', function () {
            require_admin();
            $log = array_reverse(store_all('login_log'));
            $names = [];
            foreach (store_all('users') as $u) {
                $names[$u['id']] = $u['name'] ?: ($u['email'] ?: $u['phone']);
            }
            foreach ($log as &$l) {
                $l['user'] = $l['user_id'] ? ($names[$l['user_id']] ?? '#' . $l['user_id']) : null;
            }
            $fails = array_filter($log, fn($l) => !$l['ok'] && strtotime($l['time']) > time() - 86400);
            json_out(['ok' => true, 'log' => array_slice($log, 0, 100),
                'failed_24h' => count($fails),
                'banned' => array_values(array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'] ?: ($u['email'] ?: $u['phone'])], store_where('users', fn($u) => !empty($u['banned'])))),
                'admins' => array_values(array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'] ?: ($u['email'] ?: $u['phone'])], store_where('users', fn($u) => !empty($u['is_admin'])))),
                'whatsapp_login' => !empty(settings()['whatsapp_login']),
                'https' => is_https(), 'force_https' => !empty(settings()['force_https']),
                'events' => array_slice(array_reverse(store_all('security_log')), 0, 60),
                'data_protected' => true,
            ]);
        }],
        ['POST', '#^/api/admin/security$#', function () {
            require_admin();
            $act = (string)(request_json()['action'] ?? '');
            admin_log('security_' . $act);
            if ($act === 'force_https') {
                $on = !empty(request_json()['on']);
                if ($on && !is_https()) {
                    json_err('افتح لوحة الإدارة عبر https:// أولاً حتى تتأكد أن الشهادة شغّالة');
                }
                update_settings(['force_https' => $on]);
                json_out(['ok' => true]);
            }
            if ($act === 'logout_all') {
                $epoch = (int)(settings()['session_epoch'] ?? 1) + 1;
                update_settings(['session_epoch' => $epoch]);
                $_SESSION['epoch'] = $epoch; // الأدمن الحالي يبقى داخل
                json_out(['ok' => true, 'msg' => 'تم إنهاء جلسات كل المستخدمين']);
            }
            if ($act === 'clear_log') {
                store_update_all('login_log', function (array &$rows) {
                    $rows = [];
                });
                json_out(['ok' => true]);
            }
            json_err('إجراء غير معروف');
        }],

        // ------------------------------------------------ الأدمن: النسخ الاحتياطي (JSON + تيليجرام)
        ['GET', '#^/api/admin/backup/download$#', function () {
            require_admin();
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="wasel-backup-' . date('Ymd-His') . '.json"');
            echo backup_json();
        }],
        ['POST', '#^/api/admin/backup/import$#', function () {
            require_admin();
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                json_err('اختر ملف JSON');
            }
            if ($f['size'] > 40 * 1024 * 1024) {
                json_err('الملف كبير جداً');
            }
            admin_log('backup_import', $f['name']);
            [$ok, $m] = backup_import((string)file_get_contents($f['tmp_name']));
            $ok ? json_out(['ok' => true, 'msg' => $m]) : json_err($m);
        }],
        ['POST', '#^/api/admin/backup/telegram$#', function () {
            require_admin();
            [$ok, $m] = tg_send_backup();
            $ok ? json_out(['ok' => true, 'msg' => $m]) : json_err($m);
        }],
        // ------------------------------------------------ الخدمات (تصدير/استيراد كل خدمة بملف)
        ['GET', '#^/api/admin/services$#', function () {
            require_admin();
            json_out(['ok' => true, 'services' => svc_list()]);
        }],
        ['GET', '#^/api/admin/service/([a-z]+)/download$#', function ($key) {
            require_admin();
            if (!isset(SERVICE_DEFS[$key])) {
                json_err('خدمة غير معروفة', 404);
            }
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="wasel-' . $key . '-' . date('Ymd-His') . '.json"');
            echo svc_export_json($key);
        }],
        ['POST', '#^/api/admin/service/import$#', function () {
            require_admin();
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                json_err('اختر ملف JSON');
            }
            if ($f['size'] > 20 * 1024 * 1024) {
                json_err('الملف كبير جداً');
            }
            $content = (string)file_get_contents($f['tmp_name']);
            if (!svc_detect($content)) {
                json_err('الملف مو خدمة صالحة من واصل (للنسخة الكاملة استخدم زر استيراد النسخة)');
            }
            $mode = (($_POST['mode'] ?? '') === 'replace') ? 'replace' : 'merge';
            admin_log('service_import', $f['name']);
            [$ok, $m] = svc_import($content, $mode);
            $ok ? json_out(['ok' => true, 'msg' => $m]) : json_err($m);
        }],
        ['POST', '#^/api/admin/service/([a-z]+)/telegram$#', function ($key) {
            require_admin();
            [$ok, $m] = $key === 'all' ? tg_send_all_services() : tg_send_service($key);
            $ok ? json_out(['ok' => true, 'msg' => $m]) : json_err($m);
        }],
        ['POST', '#^/api/admin/telegram/webhook$#', function () {
            require_admin();
            $url = tg_webhook_url(site_base_url());
            $r = tg_api('setWebhook', ['url' => $url, 'allowed_updates' => json_encode(['message'])]);
            !empty($r['ok']) ? json_out(['ok' => true, 'msg' => 'تم ربط البوت - أرسل له /backup أو ملف JSON']) : json_err('فشل الربط: ' . ($r['description'] ?? 'تأكد من التوكن'));
        }],
        ['POST', '#^/telegram/webhook/([a-f0-9]{24})$#', function ($key) {
            if (!hash_equals((string)(settings()['tg_webhook_key'] ?? ''), $key)) {
                json_err('unauthorized', 401);
            }
            $u = request_json();
            json_out_then(['ok' => true], fn() => tg_handle_update($u));
        }],

        // ------------------------------------------------ ربط حساب واتساب برمز (بدل الباركود)
        ['POST', '#^/accounts/([\w-]+)/pair$#', function ($accId) {
            require_login();
            $acc = owned_account($accId);
            if (!$acc) {
                json_err('حساب غير موجود', 404);
            }
            $phone = digits_only(request_json()['phone'] ?? '');
            if (strlen($phone) < 10) {
                json_err('اكتب رقم واتساب كامل مع رمز الدولة');
            }
            if (!isset(engine_accounts()[$accId])) {
                engine_request('POST', '/accounts', ['id' => $accId, 'owner' => (int)$acc['owner'], 'name' => $acc['name']], 8);
            }
            $r = engine_json('POST', '/accounts/' . rawurlencode($accId) . '/pair', ['phone' => $phone], 40);
            $st = $r['_status'] ?? 503;
            unset($r['_status']);
            json_out($r, $st === 200 ? 200 : 400);
        }],
    ];
}

function site_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ================================================================ آسياسيل: الخطوات

function handle_asiacell($step): void
{
    $uid = require_login();
    $lk = fopen(DATA_DIR . '/asiacell_' . $uid . '.lock', 'c');
    if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) {
        json_err('فيه عملية آسياسيل شغالة - انتظر ثواني', 429);
    }
    $d = request_json();
    $pm = store_first('payment_methods', fn($m) => $m['type'] === 'asiacell' && !empty($m['active']));
    if (!$pm) {
        json_err('آسياسيل غير متاح حالياً');
    }
    $receiver = digits_only($pm['number']);
    if ($receiver === '') {
        json_err('لم يتم إعداد رقم آسياسيل المستلم من الإدارة');
    }
    $flow = asiacell_flow($uid);

    if ($step === 'cancel') {
        if ($flow && ($flow['paid'] ?? 0) > 0) {
            $part = (int)$flow['paid'];
            record_recharge($uid, $pm, $part, 'approved', ['ref' => 'asiacell-partial']);
            wallet_add($uid, $part, 'recharge', 'آسياسيل (جزئي)');
        }
        asiacell_flow($uid, null, true);
        json_out(['ok' => true]);
    }

    if ($step === 'start') {
        rate_limit_or_fail('asiacell_start', 5, 900);
        if ($flow && ($flow['paid'] ?? 0) > 0) {
            json_err('عندك عملية آسياسيل غير مكتملة - أكملها أو ألغها');
        }
        $phone = digits_only($d['phone'] ?? '');
        if (preg_match('/^9647/', $phone)) {
            $phone = '0' . substr($phone, 3);
        }
        if (!preg_match('/^07[789]\d{8}$/', $phone)) {
            json_err('رقم آسياسيل لازم يكون بصيغة 077xxxxxxxx');
        }
        $amount = (int)($d['amount'] ?? 0);
        $tier = null;
        foreach (($pm['extras']['tiers'] ?? []) as $t) {
            if ((int)$t['iqd'] === $amount) {
                $tier = $t;
            }
        }
        if (!$tier) {
            json_err('اختر أحد المبالغ المتاحة');
        }
        $api = new AsiaCellAPI();
        [$ok, $err] = $api->login($phone);
        if (!$ok) {
            json_err($err);
        }
        asiacell_flow($uid, ['api' => $api->state(), 'phone' => $phone, 'total' => $amount, 'bonus' => (int)$tier['bonus'],
            'paid' => 0, 'chunk' => 0, 'max' => (int)($pm['extras']['max_transfer'] ?? 10000), 'step' => 'sms', 'plan_id' => (int)($d['plan_id'] ?? 0) ?: null,
            'coupon' => coupon_norm((string)($d['coupon'] ?? ''))]);
        json_out(['ok' => true, 'msg' => 'وصلك رمز SMS على رقمك - اكتبه هنا']);
    }

    if (!$flow) {
        json_err('انتهت صلاحية العملية، ابدأ من جديد');
    }
    rate_limit_or_fail('asiacell_code', 12, 900);
    $code = digits_only($d['code'] ?? '');
    if (!preg_match('/^\d{4,6}$/', $code)) {
        json_err('الرمز لازم يكون من 4 إلى 6 أرقام');
    }
    $api = new AsiaCellAPI($flow['api']);

    if ($step === 'verify') {
        if ($flow['step'] !== 'sms') {
            json_err('خطوة غير صحيحة');
        }
        [$ok, $err] = $api->verifySms($code);
        if (!$ok) {
            json_err($err);
        }
        $chunk = min($flow['max'], $flow['total'] - $flow['paid']);
        [$ok, $err] = $api->startTransfer($chunk, $receiver);
        if (!$ok) {
            json_err($err);
        }
        $flow['api'] = $api->state();
        $flow['chunk'] = $chunk;
        $flow['step'] = 'confirm';
        asiacell_flow($uid, $flow);
        json_out(['ok' => true, 'msg' => 'وصلك رمز تأكيد التحويل (' . number_format($chunk) . ' د.ع) - اكتبه هنا']);
    }

    // confirm
    if ($flow['step'] !== 'confirm') {
        json_err('خطوة غير صحيحة');
    }
    [$ok, $err] = $api->confirmTransfer($code);
    if (!$ok) {
        json_err($err);
    }
    $flow['paid'] += $flow['chunk'];
    if ($flow['paid'] < $flow['total']) {
        $next = min($flow['max'], $flow['total'] - $flow['paid']);
        [$ok, $err] = $api->startTransfer($next, $receiver);
        if (!$ok) {
            $part = (int)$flow['paid'];
            record_recharge($uid, $pm, $part, 'approved', ['ref' => 'asiacell-partial']);
            wallet_add($uid, $part, 'recharge', 'آسياسيل (جزئي)');
            asiacell_flow($uid, null, true);
            json_err('تم تحويل ' . number_format($part) . ' د.ع وأضيفت لرصيدك، لكن تعذر إكمال الباقي: ' . $err);
        }
        $flow['api'] = $api->state();
        $flow['chunk'] = $next;
        asiacell_flow($uid, $flow);
        json_out(['ok' => true, 'done' => false, 'msg' => 'تم تحويل ' . number_format($flow['paid']) . ' د.ع - وصلك رمز للدفعة التالية (' . number_format($next) . ' د.ع)']);
    }
    $credit = (int)$flow['total'] + (int)$flow['bonus'];
    record_recharge($uid, $pm, $credit, 'approved', ['ref' => 'asiacell:' . $flow['phone']]);
    wallet_add($uid, $credit, 'recharge', 'آسياسيل' . ($flow['bonus'] ? ' + هدية ' . number_format($flow['bonus']) : ''));
    add_event($uid, null, 'تم شحن رصيدك', number_format($credit) . ' د.ع عبر آسياسيل', 'success');
    $planRes = apply_recharge_plan($uid, $flow['plan_id'] ?? null, (string)($flow['coupon'] ?? ''));
    $bal = wallet_balance($uid);
    asiacell_flow($uid, null, true);
    json_out(['ok' => true, 'done' => true, 'balance' => $bal, 'msg' => 'تم شحن ' . number_format($credit) . ' د.ع لرصيدك', 'plan' => $planRes]);
}
