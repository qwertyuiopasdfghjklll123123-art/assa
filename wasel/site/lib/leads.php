<?php
/**
 * استخراج أرقام الشركات والمحلات من خرائط Google (للأدمن فقط)
 * يستخدم Google Places API (New) - Text Search، مع تقسيم المدينة لمربعات حتى نتجاوز حد 60 نتيجة للبحث الواحد.
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

if (!defined('PLACES_ENDPOINT')) {
    define('PLACES_ENDPOINT', 'https://places.googleapis.com/v1/places:searchText');
}

function places_request(string $key, array $body, string $fields): array
{
    $ch = curl_init(PLACES_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Goog-Api-Key: ' . $key, 'X-Goog-FieldMask: ' . $fields],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$raw, true) ?: [];
    if ($code !== 200) {
        $msg = $j['error']['message'] ?? 'تعذر الاتصال بخرائط Google';
        if ($code === 403 || $code === 400 && stripos($msg, 'key') !== false) {
            $msg = 'مفتاح خرائط Google غير صالح أو Places API (New) غير مفعّلة بمشروعك';
        }
        throw new RuntimeException($msg);
    }
    return $j;
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

function leads_search(string $key, array $country, string $city, string $keyword, int $depth): array
{
    @set_time_limit(240);
    $fields = 'places.id,places.displayName,places.formattedAddress,places.nationalPhoneNumber,places.internationalPhoneNumber,'
        . 'places.rating,places.userRatingCount,places.googleMapsUri,places.primaryTypeDisplayName,nextPageToken';
    $base = ['languageCode' => 'ar', 'regionCode' => $country[1], 'pageSize' => 20];

    // 1) حدود المدينة
    $cells = [null];
    if ($depth > 1) {
        $g = places_request($key, ['textQuery' => "{$city}، {$country[0]}", 'languageCode' => 'ar', 'regionCode' => $country[1], 'pageSize' => 1], 'places.viewport');
        $vp = $g['places'][0]['viewport'] ?? null;
        if ($vp) {
            $cells = [];
            [$la1, $lo1, $la2, $lo2] = [$vp['low']['latitude'], $vp['low']['longitude'], $vp['high']['latitude'], $vp['high']['longitude']];
            $dla = ($la2 - $la1) / $depth;
            $dlo = ($lo2 - $lo1) / $depth;
            for ($i = 0; $i < $depth; $i++) {
                for ($k = 0; $k < $depth; $k++) {
                    $cells[] = ['rectangle' => [
                        'low' => ['latitude' => $la1 + $i * $dla, 'longitude' => $lo1 + $k * $dlo],
                        'high' => ['latitude' => $la1 + ($i + 1) * $dla, 'longitude' => $lo1 + ($k + 1) * $dlo],
                    ]];
                }
            }
        }
    }

    // 2) البحث بكل مربع (حتى 3 صفحات × 20 نتيجة)
    $out = [];
    $calls = 0;
    foreach ($cells as $cell) {
        $body = $base + ($cell ? ['textQuery' => $keyword, 'locationRestriction' => $cell] : ['textQuery' => "{$keyword} في {$city}، {$country[0]}"]);
        for ($page = 0; $page < 3; $page++) {
            $j = places_request($key, $body, $fields);
            $calls++;
            foreach ($j['places'] ?? [] as $p) {
                if (isset($out[$p['id']])) {
                    continue;
                }
                [$phone, $mobile] = lead_phone($p, $country);
                $out[$p['id']] = [
                    'name' => clean_text(mb_substr((string)($p['displayName']['text'] ?? ''), 0, 120)),
                    'phone' => $phone, 'mobile' => $mobile,
                    'address' => clean_text(mb_substr((string)($p['formattedAddress'] ?? ''), 0, 200)),
                    'type' => clean_text((string)($p['primaryTypeDisplayName']['text'] ?? '')),
                    'rating' => $p['rating'] ?? null, 'reviews' => (int)($p['userRatingCount'] ?? 0),
                    'map' => (string)($p['googleMapsUri'] ?? ''),
                ];
            }
            if (empty($j['nextPageToken'])) {
                break;
            }
            $body['pageToken'] = $j['nextPageToken'];
        }
    }
    return [array_values($out), $calls];
}

// ================================================================ البحث بالمتصفح (إضافة كروم أو متصفح السيرفر)

if (!defined('MAPS_SEARCH_BASE')) {
    define('MAPS_SEARCH_BASE', 'https://www.google.com/maps/search/');
}

function leads_maps_url(array $country, string $city, string $keyword): string
{
    return MAPS_SEARCH_BASE . rawurlencode("{$keyword} {$city} {$country[0]}") . '?hl=ar';
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

function leads_job(int $id): ?array
{
    foreach (store_get_doc('leads_jobs', ['jobs' => []])['jobs'] as $j) {
        if ((int)$j['id'] === $id) {
            return $j;
        }
    }
    return null;
}

function leads_job_save(array $job): void
{
    store_update_doc('leads_jobs', function ($d) use ($job) {
        $d['jobs'] = array_values(array_filter($d['jobs'], fn($j) => (int)$j['id'] !== (int)$job['id']));
        array_unshift($d['jobs'], $job);
        $d['jobs'] = array_slice($d['jobs'], 0, 10);
        return $d;
    }, ['jobs' => []]);
}

/** ينهي المهمة: يحفظ النتائج كبحث بسجل البحث ويرجع رقمه */
function leads_job_finish(array $job, array $raw, string $error = ''): array
{
    $country = lead_country($job['iso']) ?? LEAD_COUNTRIES[0];
    $results = leads_normalize($raw, $country);
    $job['status'] = $error !== '' && !$results ? 'error' : 'done';
    $job['error'] = $error;
    $job['found'] = count($results);
    if ($results) {
        $search = ['id' => (int)$job['id'], 'time' => date('Y-m-d H:i'), 'country' => $country[0], 'iso' => $country[1],
            'city' => $job['city'], 'keyword' => $job['keyword'], 'depth' => 0, 'calls' => 0, 'source' => $job['mode'],
            'count' => count($results), 'phones' => count(array_filter($results, fn($r) => $r['phone'] !== '')), 'results' => $results];
        store_update_doc('leads', function ($doc) use ($search) {
            $doc['searches'] = array_values(array_filter($doc['searches'], fn($s) => (int)$s['id'] !== (int)$search['id']));
            array_unshift($doc['searches'], $search);
            $doc['searches'] = array_slice($doc['searches'], 0, 15);
            return $doc;
        }, ['searches' => []]);
        $job['search_id'] = $search['id'];
        admin_log('leads_browser', "{$job['keyword']} / {$job['city']} ({$search['count']})");
    }
    leads_job_save($job);
    return $job;
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

function leads_routes(): array
{
    return [
        ['GET', '#^/api/admin/leads/server$#', function () {
            require_admin();
            $r = engine_json('GET', '/scrape/setup');
            json_out(['ok' => true, 'status' => $r['ok'] ?? false ? ($r['status'] ?? '') : 'engine', 'error' => $r['ok'] ?? false ? ($r['error'] ?? '') : ($r['error'] ?? ''),
                'version' => $r['version'] ?? '']);
        }],
        ['POST', '#^/api/admin/leads/server$#', function () {
            require_admin();
            $r = engine_json('POST', '/scrape/setup', ['check_only' => !empty(request_json()['check_only'])]);
            if (empty($r['ok'])) {
                json_err($r['error'] ?? 'تعذر الاتصال بالمحرك', 502);
            }
            admin_log('chrome_setup');
            json_out(['ok' => true, 'status' => $r['status'] ?? '']);
        }],
        ['POST', '#^/api/admin/leads/http$#', function () {
            require_admin();
            rate_limit_or_fail('leads_http', 15, 3600, 'وصلت حد البحث (15 بالساعة) - انتظر قليلاً حتى ما توقفك Google');
            $d = request_json();
            $country = lead_country((string)($d['country'] ?? ''));
            $city = clean_text(mb_substr(trim((string)($d['city'] ?? '')), 0, 60));
            $keyword = clean_text(mb_substr(trim((string)($d['keyword'] ?? '')), 0, 60));
            if (!$country || $city === '' || $keyword === '') {
                json_err('اختر الدولة واكتب المدينة ونوع النشاط');
            }
            $job = ['id' => (int)(microtime(true) * 1000) % 2000000000, 'mode' => 'http', 'iso' => $country[1], 'city' => $city, 'keyword' => $keyword,
                'max' => max(10, min(500, (int)($d['max'] ?? 200))), 'deep' => false, 'url' => '', 'status' => 'running', 'found' => 0, 'created' => time()];
            try {
                $raw = leads_http_search($country, $city, $keyword, $job['max']);
            } catch (RuntimeException $e) {
                json_err($e->getMessage(), 502);
            }
            $job = leads_job_finish($job, $raw);
            if (empty($job['search_id'])) {
                json_err('ما لكينا محلات بهذا البحث', 404);
            }
            json_out(['ok' => true, 'job' => $job]);
        }],
        ['POST', '#^/api/admin/leads/job$#', function () {
            require_admin();
            rate_limit_or_fail('leads_job', 30, 3600, 'وصلت حد البحث (30 بالساعة) - انتظر قليلاً');
            $d = request_json();
            $country = lead_country((string)($d['country'] ?? ''));
            $city = clean_text(mb_substr(trim((string)($d['city'] ?? '')), 0, 60));
            $keyword = clean_text(mb_substr(trim((string)($d['keyword'] ?? '')), 0, 60));
            $mode = ($d['mode'] ?? '') === 'server' ? 'server' : 'ext';
            if (!$country || $city === '' || $keyword === '') {
                json_err('اختر الدولة واكتب المدينة ونوع النشاط');
            }
            $job = ['id' => (int)(microtime(true) * 1000) % 2000000000, 'mode' => $mode, 'iso' => $country[1], 'city' => $city, 'keyword' => $keyword,
                'max' => max(10, min(1000, (int)($d['max'] ?? 200))), 'deep' => !isset($d['deep']) || !empty($d['deep']),
                'url' => leads_maps_url($country, $city, $keyword), 'status' => 'running', 'found' => 0, 'created' => time()];
            if ($mode === 'server') {
                $r = engine_json('POST', '/scrape', ['id' => 'j' . $job['id'], 'url' => $job['url'], 'max' => $job['max'], 'deep' => $job['deep']]);
                if (empty($r['ok'])) {
                    json_err($r['error'] ?? 'تعذر بدء البحث بالسيرفر', 502);
                }
            }
            leads_job_save($job);
            json_out(['ok' => true, 'job' => $job]);
        }],
        ['GET', '#^/api/admin/leads/job/(\d+)$#', function ($jid) {
            require_admin();
            $job = leads_job((int)$jid);
            if (!$job) {
                json_err('المهمة غير موجودة', 404);
            }
            if ($job['mode'] === 'server' && $job['status'] === 'running') {
                $r = engine_json('GET', '/scrape/j' . $job['id']);
                if (empty($r['ok'])) {
                    $job = leads_job_finish($job, [], $r['error'] ?? 'انقطع الاتصال بالمحرك');
                } elseif ($r['status'] === 'running') {
                    $job['found'] = (int)($r['found'] ?? 0);
                    $job['stage'] = (string)($r['stage'] ?? '');
                    $job['current'] = clean_text(mb_substr((string)($r['current'] ?? ''), 0, 120));
                    $job['done'] = (int)($r['done'] ?? 0);
                    $job['total'] = (int)($r['total'] ?? 0);
                } else {
                    $job = leads_job_finish($job, (array)($r['results'] ?? []), $r['status'] === 'error' ? (string)($r['error'] ?? 'فشل البحث') : '');
                }
            }
            json_out(['ok' => true, 'job' => $job]);
        }],
        // الإضافة ترسل النتائج عبر صفحة الأدمن نفسها (بنفس جلسة الدخول)
        ['POST', '#^/api/admin/leads/job/(\d+)/push$#', function ($jid) {
            require_admin();
            $job = leads_job((int)$jid);
            if (!$job || $job['mode'] !== 'ext') {
                json_err('المهمة غير موجودة', 404);
            }
            $d = request_json();
            $job = leads_job_finish($job, is_array($d['results'] ?? null) ? $d['results'] : [], mb_substr((string)($d['error'] ?? ''), 0, 200));
            json_out(['ok' => true, 'job' => $job]);
        }],
        ['POST', '#^/api/admin/leads/job/(\d+)/stop$#', function ($jid) {
            require_admin();
            $job = leads_job((int)$jid);
            if ($job && $job['mode'] === 'server') {
                engine_json('POST', '/scrape/j' . $job['id'] . '/stop', []);
            }
            json_out(['ok' => true]);
        }],
        ['GET', '#^/api/admin/leads/extension\.zip$#', function () {
            require_admin();
            $f = __DIR__ . '/../tools/wasel-maps-extension.zip';
            if (!is_file($f)) {
                json_err('ملف الإضافة غير موجود', 404);
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="wasel-maps-extension.zip"');
            header('Content-Length: ' . filesize($f));
            readfile($f);
            exit;
        }],
        ['GET', '#^/api/admin/leads$#', function () {
            require_admin();
            $doc = store_get_doc('leads', ['searches' => []]);
            $jobs = array_values(array_filter(store_get_doc('leads_jobs', ['jobs' => []])['jobs'], fn($j) => $j['status'] === 'running' && time() - (int)$j['created'] < 7200));
            json_out(['ok' => true, 'key_set' => settings()['maps_api_key'] !== '', 'jobs' => $jobs,
                'countries' => array_map(fn($c) => ['iso' => $c[1], 'name' => $c[0], 'cc' => $c[2]], LEAD_COUNTRIES),
                'searches' => array_map(fn($s) => array_diff_key($s, ['results' => 1]), $doc['searches'])]);
        }],
        ['GET', '#^/api/admin/leads/(\d+)$#', function ($sid) {
            require_admin();
            foreach (store_get_doc('leads', ['searches' => []])['searches'] as $s) {
                if ((int)$s['id'] === (int)$sid) {
                    json_out(['ok' => true, 'search' => $s]);
                }
            }
            json_err('البحث غير موجود', 404);
        }],
        ['DELETE', '#^/api/admin/leads/(\d+)$#', function ($sid) {
            require_admin();
            store_update_doc('leads', function ($d) use ($sid) {
                $d['searches'] = array_values(array_filter($d['searches'], fn($s) => (int)$s['id'] !== (int)$sid));
                return $d;
            }, ['searches' => []]);
            json_out(['ok' => true]);
        }],
        ['POST', '#^/api/admin/leads/search$#', function () {
            require_admin();
            rate_limit_or_fail('leads', 20, 3600, 'وصلت حد البحث (20 بالساعة) - انتظر قليلاً');
            $d = request_json();
            $key = settings()['maps_api_key'];
            if ($key === '') {
                json_err('أضف مفتاح خرائط Google أولاً');
            }
            $country = lead_country((string)($d['country'] ?? ''));
            $city = clean_text(mb_substr(trim((string)($d['city'] ?? '')), 0, 60));
            $keyword = clean_text(mb_substr(trim((string)($d['keyword'] ?? '')), 0, 60));
            $depth = max(1, min(3, (int)($d['depth'] ?? 2)));
            if (!$country || $city === '' || $keyword === '') {
                json_err('اختر الدولة واكتب المدينة ونوع النشاط');
            }
            try {
                [$results, $calls] = leads_search($key, $country, $city, $keyword, $depth);
            } catch (RuntimeException $e) {
                json_err($e->getMessage(), 502);
            }
            $search = ['id' => (int)(microtime(true) * 1000) % 2000000000, 'time' => date('Y-m-d H:i'), 'country' => $country[0], 'iso' => $country[1],
                'city' => $city, 'keyword' => $keyword, 'depth' => $depth, 'calls' => $calls,
                'count' => count($results), 'phones' => count(array_filter($results, fn($r) => $r['phone'] !== '')), 'results' => $results];
            store_update_doc('leads', function ($doc) use ($search) {
                array_unshift($doc['searches'], $search);
                $doc['searches'] = array_slice($doc['searches'], 0, 15);
                return $doc;
            }, ['searches' => []]);
            admin_log('leads_search', "{$keyword} / {$city} ({$search['count']})");
            json_out(['ok' => true, 'search' => $search]);
        }],
    ];
}
