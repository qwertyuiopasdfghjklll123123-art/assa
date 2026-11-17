<?php
/**
 * سحب أرقام المحلات والشركات من خرائط Google («بحث سريع» بدون متصفح) - لكل المستخدمين
 * الأدمن يحدد عدد محاولات البحث اليومية لكل مستخدم وأقصى عدد نتائج للبحث الواحد.
 */

/** الدول: [الاسم، رمز ISO، رمز الاتصال، طول الرقم الدولي، بادئة الموبايل] */
const LEAD_COUNTRIES = [
    ['العراق', 'IQ', '964', 13, '9647'], ['السعودية', 'SA', '966', 12, '9665'], ['الإمارات', 'AE', '971', 12, '9715'],
    ['الكويت', 'KW', '965', 11, ''], ['قطر', 'QA', '974', 11, ''], ['البحرين', 'BH', '973', 11, ''],
    ['عُمان', 'OM', '968', 11, '9689'], ['الأردن', 'JO', '962', 12, '9627'], ['سوريا', 'SY', '963', 12, '9639'],
    ['لبنان', 'LB', '961', 0, ''], ['مصر', 'EG', '20', 12, '201'], ['ليبيا', 'LY', '218', 12, '2189'],
    ['تونس', 'TN', '216', 11, ''], ['الجزائر', 'DZ', '213', 12, ''], ['المغرب', 'MA', '212', 12, ''],
    ['اليمن', 'YE', '967', 12, '9677'], ['السودان', 'SD', '249', 12, ''], ['فلسطين', 'PS', '970', 12, '9705'],
    ['تركيا', 'TR', '90', 12, '905'], ['إيران', 'IR', '98', 12, '989'],
];

function lead_country(string $iso): ?array
{
    foreach (LEAD_COUNTRIES as $c) {
        if ($c[1] === $iso) {
            return $c;
        }
    }
    return null;
}

/** يحوّل رقم الخرائط لصيغة دولية أرقام فقط، ويحدد هل هو موبايل (يشتغل عليه واتساب غالباً) */
function lead_phone(array $p, array $country): array
{
    $intl = preg_replace('/\D/', '', (string)($p['internationalPhoneNumber'] ?? ''));
    if ($intl === '' && !empty($p['nationalPhoneNumber'])) {
        $intl = apply_country_code(preg_replace('/\D/', '', $p['nationalPhoneNumber']), $country[2]);
    }
    $mobile = null;
    if ($intl !== '' && $country[4] !== '' && strpos($intl, $country[2]) === 0) {
        $mobile = strpos($intl, $country[4]) === 0 && ($country[3] === 0 || strlen($intl) === $country[3]);
    }
    return [$intl, $mobile];
}

/** يحوّل نتائج المتصفح الخام لنفس صيغة نتائج API (رقم دولي + موبايل/أرضي) وينظّفها */
function leads_normalize(array $raw, array $country): array
{
    $out = [];
    foreach (array_slice($raw, 0, 1000) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $p = preg_replace('/[^\d+]/', '', (string)($r['phone'] ?? ''));
        if ($p !== '' && ($p[0] === '+' || strpos($p, '00') === 0)) {
            $p = ltrim(preg_replace('/^00/', '', ltrim($p, '+')), '+');
        } elseif ($p !== '') {
            $p = apply_country_code($p, $country[2]);
        }
        $p = preg_replace('/\D/', '', $p);
        if ($p !== '' && (strlen($p) < 8 || strlen($p) > 15)) {
            $p = '';
        }
        [, $mobile] = lead_phone(['internationalPhoneNumber' => $p], $country);
        $map = (string)($r['map'] ?? '');
        $web = (string)($r['website'] ?? '');
        $key = $p !== '' ? 'p' . $p : 'n' . md5((string)($r['name'] ?? '') . ($r['address'] ?? ''));
        if (isset($out[$key])) {
            continue;
        }
        $out[$key] = [
            'name' => clean_text(mb_substr((string)($r['name'] ?? ''), 0, 120)),
            'phone' => $p, 'mobile' => $p === '' ? null : $mobile,
            'address' => clean_text(mb_substr((string)($r['address'] ?? ''), 0, 200)),
            'type' => clean_text(mb_substr((string)($r['category'] ?? ''), 0, 60)),
            'rating' => is_numeric($r['rating'] ?? null) ? round((float)$r['rating'], 1) : null,
            'reviews' => (int)($r['reviews'] ?? 0),
            'map' => preg_match('#^https://(www\.)?google\.[a-z.]+/maps/#', $map) ? $map : '',
            'website' => preg_match('#^https?://#', $web) ? mb_substr($web, 0, 300) : '',
        ];
    }
    return array_values(array_filter($out, fn($x) => $x['name'] !== ''));
}

// ================================================================ بحث سريع بدون متصفح (السيرفر يسأل خرائط Google مباشرة)
// يشتغل من الجوال ومع أي استضافة. Google ما توفّر هذا رسمياً، فالشكل ممكن يتغير.

if (!defined('GMAPS_HTTP_ENDPOINT')) {
    define('GMAPS_HTTP_ENDPOINT', 'https://www.google.com/search');
}
/** مراكز الدول تقريباً (لو ما لكينا إحداثيات المدينة) */
const LEAD_CENTERS = ['IQ' => [33.3, 44.4], 'SA' => [24.7, 46.7], 'AE' => [25.2, 55.3], 'KW' => [29.37, 47.98], 'QA' => [25.29, 51.53],
    'BH' => [26.22, 50.58], 'OM' => [23.6, 58.4], 'JO' => [31.95, 35.93], 'SY' => [33.51, 36.29], 'LB' => [33.89, 35.5], 'EG' => [30.04, 31.24],
    'LY' => [32.89, 13.19], 'TN' => [36.8, 10.18], 'DZ' => [36.75, 3.06], 'MA' => [33.57, -7.59], 'YE' => [15.37, 44.19], 'SD' => [15.5, 32.56],
    'PS' => [31.9, 35.2], 'TR' => [41.01, 28.98], 'IR' => [35.69, 51.39]];

function gmaps_pb(float $lat, float $lng, int $offset, bool $full): string
{
    $core = '!4m12!1m3!1d40000!2d' . $lng . '!3d' . $lat . '!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!7i20!8i' . $offset . '!10b1';
    if (!$full) {
        return $core;
    }
    return $core . '!12m6!1m1!18b1!2m3!5m1!6e2!20e3!10b1!16b1!19m4!2m3!1i360!2i120!4i8'
        . '!20m48!2m2!1i203!2i100!3m2!2i4!5b1!6m6!1m2!1i86!2i86!1m2!1i408!2i240!7m33!1m3!1e1!2b0!3e3!1m3!1e2!2b1!3e2!1m3!1e2!2b0!3e3'
        . '!1m3!1e8!2b0!3e3!1m3!1e10!2b0!3e3!1m3!1e10!2b1!3e2!1m3!1e9!2b1!3e2!1m3!1e10!2b0!3e3!1m3!1e10!2b1!3e2!1m3!1e10!2b0!3e4!2b1!4b1!9b0'
        . '!22m3!1s!7e81!17s!24m55!1m21!13m8!2b1!3b1!4b1!6i1!8b1!9b1!14b1!20b1!18m11!3b1!4b1!5b1!6b1!9b1!12b1!13b1!14b1!15b1!17b1!20b1'
        . '!2b1!5m5!2b1!3b1!5b1!6b1!7b1!10m1!8e3!11m1!3e1!14m1!3b1!17b1!20m2!1e3!1e6!24b1!25b1!26b1!29b1!30m1!2b1!36b1!39m3!2m2!2i1!3i1'
        . '!43b1!52b1!54m1!1b1!55b1!56m2!1b1!3b1!65m5!3m4!1m3!1m2!1i224!2i298!71b1!72m4!1m2!3b1!5b1!4b1!89b1!103b1!113b1'
        . '!26m4!2m3!1i80!2i92!4i8!34m17!2b1!3b1!4b1!6b1!8m5!1b1!3b1!4b1!5b1!6b1!9b1!12b1!14b1!20b1!23b1!25b1!26b1!37m1!1e81!42b1!47m0'
        . '!49m5!3b1!6m1!1b1!7m1!1e3!50m4!2e2!3m2!1b1!3b1!67m2!7b1!10b1!69i654';
}

/** يرجع [نص الرد، رمز HTTP] */
function gmaps_fetch(string $q, string $iso, float $lat, float $lng, int $offset, bool $full): array
{
    $url = GMAPS_HTTP_ENDPOINT . '?' . http_build_query(['tbm' => 'map', 'authuser' => 0, 'hl' => 'ar', 'gl' => strtolower($iso), 'q' => $q, 'tch' => 1, 'ech' => 1])
        . '&pb=' . gmaps_pb($lat, $lng, $offset, $full);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => true, CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
            'Accept-Language: ar,en;q=0.8', 'Accept: */*', 'Referer: https://www.google.com/maps',
            'Cookie: CONSENT=YES+cb; SOCS=CAESHAgBEhJnd3NfMjAyMzA4MTAtMF9SQzIaAmVuIAEaBgiAo_CmBg',
        ],
    ]);
    $body = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    if (strpos($final, '/sorry/') !== false || stripos($body, 'unusual traffic') !== false || $code === 429) {
        throw new RuntimeException('Google وقفت البحث مؤقتاً من سيرفرك (تحقق «لست روبوت») - جرّب بعد ساعة أو استخدم «متصفح جهازي» من الكمبيوتر');
    }
    if ($code !== 200 || $body === '') {
        throw new RuntimeException('تعذر الوصول لخرائط Google من السيرفر (رمز ' . $code . ')');
    }
    return [$body, $code];
}

/** يفك رد Google (يبدأ بـ )]}' وأحياناً JSON داخل JSON) */
function gmaps_decode(string $body)
{
    $clean = function (string $t) {
        $t = trim($t);
        $t = preg_replace('#/\*""\*/\s*$#', '', $t);
        if (strpos($t, ")]}'") === 0) {
            $t = substr($t, 4);
        }
        return json_decode(trim($t), true);
    };
    $j = $clean($body);
    if (is_array($j) && isset($j['d']) && is_string($j['d'])) {
        $j = $clean($j['d']);
    }
    return $j;
}

function gmaps_first_string($node, string $re, int $depth = 4): string
{
    if (is_string($node)) {
        return preg_match($re, $node) ? $node : '';
    }
    if (!is_array($node) || $depth <= 0) {
        return '';
    }
    foreach ($node as $v) {
        $r = gmaps_first_string($v, $re, $depth - 1);
        if ($r !== '') {
            return $r;
        }
    }
    return '';
}

/** يدور بكل الرد على مصفوفات «محل» (فيها معرّف 0x..:0x.. بالخانة 10 والاسم بالخانة 11) */
function gmaps_collect($node, array &$out, int $depth = 0): void
{
    if (!is_array($node) || $depth > 14) {
        return;
    }
    if (isset($node[10], $node[11]) && is_string($node[10]) && is_string($node[11]) && preg_match('/^0x[0-9a-f]+:0x[0-9a-f]+$/i', $node[10])) {
        $fid = $node[10];
        if (!isset($out[$fid])) {
            $phone = gmaps_first_string($node[178] ?? null, '/^\+?[\d][\d\s\-()]{6,}\d$/');
            if ($phone === '') {
                $phone = gmaps_first_string($node[3] ?? null, '/^\+?[\d][\d\s\-()]{6,}\d$/', 3);
            }
            $addr = is_string($node[39] ?? null) ? $node[39] : (is_array($node[2] ?? null) ? implode('، ', array_filter($node[2], 'is_string')) : '');
            $web = gmaps_first_string($node[7] ?? null, '#^https?://#', 2);
            $cat = is_array($node[13] ?? null) ? (string)($node[13][0] ?? '') : '';
            $rating = is_array($node[4] ?? null) && is_numeric($node[4][7] ?? null) ? (float)$node[4][7] : null;
            $reviews = is_array($node[4] ?? null) && is_numeric($node[4][8] ?? null) ? (int)$node[4][8] : 0;
            $pid = is_string($node[78] ?? null) ? $node[78] : '';
            $out[$fid] = ['name' => $node[11], 'phone' => $phone, 'address' => $addr, 'website' => strpos($web, 'google.') === false ? $web : '',
                'category' => $cat, 'rating' => $rating, 'reviews' => $reviews,
                'map' => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($node[11]) . ($pid !== '' ? '&query_place_id=' . rawurlencode($pid) : ''),
                'lat' => is_array($node[9] ?? null) ? ($node[9][2] ?? null) : null, 'lng' => is_array($node[9] ?? null) ? ($node[9][3] ?? null) : null];
        }
        return;
    }
    foreach ($node as $v) {
        if (is_array($v)) {
            gmaps_collect($v, $out, $depth + 1);
        }
    }
}

function leads_http_search(array $country, string $city, string $keyword, int $max): array
{
    @set_time_limit(300);
    // 1) إحداثيات المدينة
    [$lat, $lng] = LEAD_CENTERS[$country[1]] ?? [33.3, 44.4];
    try {
        [$b] = gmaps_fetch("{$city} {$country[0]}", $country[1], $lat, $lng, 0, false);
        $tmp = [];
        gmaps_collect(gmaps_decode($b), $tmp);
        foreach ($tmp as $t) {
            if (is_numeric($t['lat']) && is_numeric($t['lng'])) {
                [$lat, $lng] = [(float)$t['lat'], (float)$t['lng']];
                break;
            }
        }
    } catch (RuntimeException $e) {
        throw $e;
    }
    // 2) الصفحات (20 نتيجة لكل صفحة)
    $q = "{$keyword} {$city} {$country[0]}";
    $found = [];
    $full = true;
    for ($offset = 0, $page = 0; $page < 30 && count($found) < $max; $offset += 20, $page++) {
        [$body] = gmaps_fetch($q, $country[1], $lat, $lng, $offset, $full);
        $before = count($found);
        gmaps_collect(gmaps_decode($body), $found);
        if ($page === 0 && count($found) === 0 && $full) {
            $full = false; // نجرب الصيغة المختصرة
            $offset -= 20;
            continue;
        }
        if (count($found) === $before) {
            break;
        }
        usleep(random_int(600000, 1300000));
    }
    if (!$found) {
        @file_put_contents(DATA_DIR . '/leads_debug.txt', substr($body ?? '', 0, 4000));
        throw new RuntimeException('Google ما رجّعت نتائج لهذا البحث - جرّب كلمة ثانية أو اسم المدينة بصيغة ثانية');
    }
    return array_slice(array_values($found), 0, $max);
}

// ================================================================ بحوث كل مستخدم + الحد اليومي

function leads_doc_name(int $uid): string
{
    return 'leads_u' . $uid;
}

/** بحوث المستخدم (آخر 30) - الأدمن ياخذ البحوث القديمة اللي كانت عامة */
function leads_searches(int $uid): array
{
    $doc = store_get_doc(leads_doc_name($uid), ['searches' => []]);
    if (!empty($_SESSION['is_admin']) && is_file(DATA_DIR . '/leads.json')) {
        $old = store_get_doc('leads', ['searches' => []])['searches'];
        @unlink(DATA_DIR . '/leads.json');
        if ($old) {
            $doc = store_update_doc(leads_doc_name($uid), function ($d) use ($old) {
                $d['searches'] = array_slice(array_merge($d['searches'], $old), 0, 30);
                return $d;
            }, ['searches' => []]);
        }
    }
    return $doc['searches'];
}

function leads_settings(): array
{
    $s = settings();
    return ['enabled' => !isset($s['leads_enabled']) || !empty($s['leads_enabled']),
        'daily' => max(0, (int)($s['leads_daily'] ?? 5)), 'max' => max(10, min(500, (int)($s['leads_max'] ?? 200)))];
}

/** محاولات اليوم المستخدمة */
function leads_used_today(int $uid): int
{
    $q = store_get_doc('leads_quota', [])[(string)$uid] ?? null;
    return $q && ($q['date'] ?? '') === date('Y-m-d') ? (int)$q['used'] : 0;
}

function leads_quota_info(int $uid): array
{
    $ls = leads_settings();
    $admin = !empty($_SESSION['is_admin']);
    $used = leads_used_today($uid);
    return ['enabled' => $ls['enabled'] || $admin, 'unlimited' => $admin, 'daily' => $ls['daily'], 'used' => $used,
        'left' => $admin ? null : max(0, $ls['daily'] - $used), 'max' => $admin ? 500 : $ls['max']];
}

function leads_routes(): array
{
    return [
        ['GET', '#^/api/leads$#', function () {
            $uid = require_login();
            json_out(['ok' => true, 'quota' => leads_quota_info($uid),
                'countries' => array_map(fn($c) => ['iso' => $c[1], 'name' => $c[0], 'cc' => $c[2]], LEAD_COUNTRIES),
                'searches' => array_map(fn($s) => array_diff_key($s, ['results' => 1]), leads_searches($uid))]);
        }],
        ['GET', '#^/api/leads/(\d+)$#', function ($sid) {
            $uid = require_login();
            foreach (leads_searches($uid) as $s) {
                if ((int)$s['id'] === (int)$sid) {
                    json_out(['ok' => true, 'search' => $s]);
                }
            }
            json_err('البحث غير موجود', 404);
        }],
        ['DELETE', '#^/api/leads/(\d+)$#', function ($sid) {
            $uid = require_login();
            store_update_doc(leads_doc_name($uid), function ($d) use ($sid) {
                $d['searches'] = array_values(array_filter($d['searches'], fn($s) => (int)$s['id'] !== (int)$sid));
                return $d;
            }, ['searches' => []]);
            json_out(['ok' => true]);
        }],
        ['POST', '#^/api/leads/search$#', function () {
            $uid = require_login();
            $quota = leads_quota_info($uid);
            if (!$quota['enabled']) {
                json_err('سحب الأرقام متوقف حالياً من الإدارة', 403);
            }
            $d = request_json();
            $country = lead_country((string)($d['country'] ?? ''));
            $city = clean_text(mb_substr(trim((string)($d['city'] ?? '')), 0, 60));
            $keyword = clean_text(mb_substr(trim((string)($d['keyword'] ?? '')), 0, 60));
            if (!$country || $city === '' || $keyword === '') {
                json_err('اختر الدولة واكتب المدينة ونوع النشاط');
            }
            $max = max(10, min($quota['max'], (int)($d['max'] ?? 100)));
            $skipOld = !empty($d['skip_old']);
            $norm = fn($t) => mb_strtolower(preg_replace('/\s+/u', ' ', trim($t)));
            $searches = leads_searches($uid);
            // نفس البحث سوّيته قبل؟ نرجعه بدون ما ينحسب محاولة
            if (empty($d['force'])) {
                foreach ($searches as $s) {
                    if (($s['iso'] ?? '') === $country[1] && $norm($s['city']) === $norm($city) && $norm($s['keyword']) === $norm($keyword)) {
                        json_out(['ok' => true, 'repeated' => true, 'search' => array_diff_key($s, ['results' => 1])]);
                    }
                }
            }
            if (!$quota['unlimited'] && $quota['left'] <= 0) {
                json_err('خلصت محاولات البحث لليوم (' . $quota['daily'] . ') - ترجع تتجدد باچر', 429);
            }
            rate_limit_or_fail('leads_http', 20, 3600, 'بحثت كثير خلال ساعة - انتظر شوي حتى ما توقفنا Google');
            try {
                $raw = leads_http_search($country, $city, $keyword, $max);
            } catch (RuntimeException $e) {
                json_err($e->getMessage(), 502);
            }
            $results = leads_normalize($raw, $country);
            // استبعاد الأرقام اللي سحبها المستخدم قبل
            $skipped = 0;
            if ($skipOld) {
                $old = [];
                foreach ($searches as $s) {
                    foreach ($s['results'] ?? [] as $r) {
                        if ($r['phone'] !== '') {
                            $old[$r['phone']] = 1;
                        }
                    }
                }
                $before = count($results);
                $results = array_values(array_filter($results, fn($r) => $r['phone'] === '' || !isset($old[$r['phone']])));
                $skipped = $before - count($results);
            }
            // المحاولة تنحسب بس لما البحث ينجح
            store_update_doc('leads_quota', function ($q) use ($uid) {
                $k = (string)$uid;
                $cur = $q[$k] ?? null;
                $q[$k] = ['date' => date('Y-m-d'), 'used' => ($cur && $cur['date'] === date('Y-m-d') ? (int)$cur['used'] : 0) + 1];
                return $q;
            });
            $search = ['id' => (int)(microtime(true) * 1000) % 2000000000, 'time' => date('Y-m-d H:i'), 'country' => $country[0], 'iso' => $country[1],
                'city' => $city, 'keyword' => $keyword, 'source' => 'http', 'skipped' => $skipped,
                'count' => count($results), 'phones' => count(array_filter($results, fn($r) => $r['phone'] !== '')), 'results' => $results];
            store_update_doc(leads_doc_name($uid), function ($doc) use ($search) {
                $doc['searches'] = array_values(array_filter($doc['searches'], fn($s) => !(($s['iso'] ?? '') === $search['iso'] && $s['city'] === $search['city'] && $s['keyword'] === $search['keyword'])));
                array_unshift($doc['searches'], $search);
                $doc['searches'] = array_slice($doc['searches'], 0, 30);
                return $doc;
            }, ['searches' => []]);
            json_out(['ok' => true, 'search' => array_diff_key($search, ['results' => 1]), 'quota' => leads_quota_info($uid)]);
        }],
    ];
}
