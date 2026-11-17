<?php
/**
 * أكواد الخصم على الاشتراك بالباقات.
 * الأدمن ينشئ كود (نسبة % أو مبلغ ثابت)، ممكن يحدده بباقات معينة، عدد استخدامات، تاريخ انتهاء،
 * وكل مستخدم يستخدمه مرة وحدة. الخصم ينطبق لحظة تفعيل الباقة (من الرصيد أو بعد الشحن المرتبط بالباقة).
 */

function coupon_norm(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', $code));
}

function coupon_find(string $code): ?array
{
    $code = coupon_norm($code);
    return $code === '' ? null : store_first('coupons', fn($c) => ($c['code'] ?? '') === $code);
}

/** يفحص الكود لباقة ومستخدم. يرجع [ok, error, coupon, finalPrice, discount] */
function coupon_check(string $code, array $plan, int $uid): array
{
    $c = coupon_find($code);
    $price = (int)$plan['price'];
    if (!$c || empty($c['active'])) {
        return [false, 'كود الخصم غير صحيح', null, $price, 0];
    }
    if (!empty($c['expires']) && strtotime($c['expires'] . ' 23:59:59') < time()) {
        return [false, 'كود الخصم منتهي', null, $price, 0];
    }
    if ((int)($c['max_uses'] ?? 0) > 0 && (int)($c['uses'] ?? 0) >= (int)$c['max_uses']) {
        return [false, 'كود الخصم خلص عدد استخداماته', null, $price, 0];
    }
    if (in_array($uid, array_map('intval', $c['used_by'] ?? []), true)) {
        return [false, 'استخدمت هذا الكود قبل', null, $price, 0];
    }
    $plans = array_map('intval', $c['plan_ids'] ?? []);
    if ($plans && !in_array((int)$plan['id'], $plans, true)) {
        return [false, 'هذا الكود ما يشتغل على هذي الباقة', null, $price, 0];
    }
    $disc = $c['type'] === 'percent' ? (int)round($price * min(100, max(0, (int)$c['value'])) / 100) : min($price, max(0, (int)$c['value']));
    return [true, null, $c, max(0, $price - $disc), $disc];
}

/** يسجّل استخدام الكود (ذري - ما يتجاوز الحد حتى لو استخدموه بنفس اللحظة) */
function coupon_use(int $couponId, int $uid): bool
{
    $ok = false;
    store_update('coupons', $couponId, function ($c) use ($uid, &$ok) {
        $used = array_map('intval', $c['used_by'] ?? []);
        if (in_array($uid, $used, true) || ((int)($c['max_uses'] ?? 0) > 0 && (int)($c['uses'] ?? 0) >= (int)$c['max_uses'])) {
            return $c;
        }
        $ok = true;
        $used[] = $uid;
        return array_merge($c, ['uses' => (int)($c['uses'] ?? 0) + 1, 'used_by' => $used]);
    });
    return $ok;
}

function coupon_public(array $c): array
{
    return ['id' => $c['id'], 'code' => $c['code'], 'type' => $c['type'], 'value' => (int)$c['value'], 'plan_ids' => array_map('intval', $c['plan_ids'] ?? []),
        'max_uses' => (int)($c['max_uses'] ?? 0), 'uses' => (int)($c['uses'] ?? 0), 'expires' => $c['expires'] ?? '', 'active' => !empty($c['active']),
        'created_at' => $c['created_at'] ?? ''];
}

function coupon_routes(): array
{
    return [
        // المستخدم: يجرب الكود على باقة ويشوف السعر بعد الخصم
        ['POST', '#^/api/coupon/check$#', function () {
            $uid = require_login();
            rate_limit_or_fail('coupon_check', 20, 600, 'جربت أكواد كثيرة - انتظر شوي');
            $d = request_json();
            $plan = store_find('plans', (int)($d['plan_id'] ?? 0));
            if (!$plan || empty($plan['active'])) {
                json_err('الباقة غير متاحة');
            }
            [$ok, $err, $c, $final, $disc] = coupon_check((string)($d['code'] ?? ''), $plan, $uid);
            if (!$ok) {
                security_log('coupon_fail', coupon_norm((string)($d['code'] ?? '')));
                json_err($err);
            }
            json_out(['ok' => true, 'code' => $c['code'], 'price' => (int)$plan['price'], 'discount' => $disc, 'final' => $final,
                'label' => $c['type'] === 'percent' ? 'خصم ' . (int)$c['value'] . '%' : 'خصم ' . number_format((int)$c['value']) . ' د.ع']);
        }],
        ['GET', '#^/api/admin/coupons$#', function () {
            require_admin();
            json_out(['ok' => true, 'coupons' => array_map('coupon_public', array_reverse(store_all('coupons'))),
                'plans' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name']], plans(false))]);
        }],
        ['POST', '#^/api/admin/coupons(?:/(\d+))?$#', function ($cid = null) {
            require_admin();
            $d = request_json();
            $old = $cid ? store_find('coupons', (int)$cid) : null;
            if ($cid && !$old) {
                json_err('الكود غير موجود', 404);
            }
            if ($old && array_keys($d) === ['active']) { // تفعيل/إيقاف بس
                store_update('coupons', (int)$cid, fn($c) => array_merge($c, ['active' => !empty($d['active'])]));
                json_out(['ok' => true]);
            }
            $code = coupon_norm((string)($d['code'] ?? ($old['code'] ?? '')));
            if (strlen($code) < 3 || strlen($code) > 30) {
                json_err('الكود لازم يكون من 3 إلى 30 حرف إنجليزي أو رقم');
            }
            $dup = coupon_find($code);
            if ($dup && (int)$dup['id'] !== (int)($old['id'] ?? 0)) {
                json_err('هذا الكود موجود مسبقاً');
            }
            $type = ($d['type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';
            $value = (int)($d['value'] ?? 0);
            if ($value <= 0 || ($type === 'percent' && $value > 100)) {
                json_err($type === 'percent' ? 'النسبة لازم تكون من 1 إلى 100' : 'اكتب مبلغ الخصم');
            }
            $exp = trim((string)($d['expires'] ?? ''));
            if ($exp !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp)) {
                json_err('تاريخ الانتهاء غير صحيح');
            }
            $row = ['code' => $code, 'type' => $type, 'value' => $value,
                'plan_ids' => array_values(array_unique(array_map('intval', (array)($d['plan_ids'] ?? [])))),
                'max_uses' => max(0, (int)($d['max_uses'] ?? 0)), 'expires' => $exp, 'active' => !isset($d['active']) || !empty($d['active'])];
            $old ? store_update('coupons', (int)$cid, fn($c) => array_merge($c, $row))
                : store_insert('coupons', $row + ['uses' => 0, 'used_by' => [], 'created_at' => now_str()]);
            admin_log('coupon_save', $code);
            json_out(['ok' => true]);
        }],
        ['DELETE', '#^/api/admin/coupons/(\d+)$#', function ($cid) {
            require_admin();
            store_delete('coupons', (int)$cid);
            json_out(['ok' => true]);
        }],
    ];
}
