<?php
/**
 * الأزرار والاستبيانات والتقييم.
 * - «أزرار»: أزرار واتساب حقيقية قابلة للضغط. كل زر إمّا يفتح رابط (cta_url) أو عند الضغط
 *   يرسل رسالة/استبيان ثاني (quick_reply). المحرك يبلّغنا بالضغطة (poll_vote) فنرجّع شنو يرسل.
 * - «استبيان/تقييم»: تصويت واتساب (عدّة خيارات) لأنها فعلاً تصويت نجمع نتائجه.
 */

const POLL_KINDS = ['buttons' => 'أزرار', 'survey' => 'استبيان', 'rating' => 'تقييم'];
const RATING_OPTIONS = ['⭐⭐⭐⭐⭐ ممتاز', '⭐⭐⭐⭐ جيد جداً', '⭐⭐⭐ جيد', '⭐⭐ مقبول', '⭐ سيء'];

function poll_tpl_of(int $owner, $id): ?array
{
    $t = store_find('poll_tpls', (int)$id);
    return $t && (int)$t['owner'] === $owner ? $t : null;
}

/** الحمولة اللي تنرسل للمحرك */
function poll_payload(array $t): array
{
    $kind = $t['kind'] ?? 'buttons';
    if ($kind === 'buttons') {
        $buttons = array_map(fn($o) => [
            'text' => $o['label'],
            'action' => $o['action'] ?? 'none',
            'url' => ($o['action'] ?? '') === 'url' ? ($o['url'] ?? '') : '',
        ], $t['options']);
        return ['kind' => 'buttons', 'question' => $t['question'], 'footer' => '',
            'buttons' => array_values($buttons), 'options' => array_column($t['options'], 'label'), 'tpl' => (int)$t['id']];
    }
    $opts = $kind === 'rating' ? RATING_OPTIONS : array_column($t['options'], 'label');
    return ['kind' => $kind, 'question' => $t['question'], 'options' => array_values($opts),
        'multi' => $kind === 'survey' && !empty($t['multi']), 'tpl' => (int)$t['id']];
}

/** يقرأ قالب من الطلب ويتحقق منه */
function poll_clean(array $d, int $owner): array
{
    $kind = isset(POLL_KINDS[$d['kind'] ?? '']) ? $d['kind'] : 'buttons';
    $q = trim(clean_text(mb_substr((string)($d['question'] ?? ''), 0, 255)));
    if ($q === '') {
        json_err('اكتب السؤال أو النص اللي يطلع فوق الأزرار');
    }
    $opts = [];
    if ($kind !== 'rating') {
        // واتساب يعرض حتى 3 أزرار حقيقية، أما التصويت (استبيان) فحتى 12 خيار
        $max = $kind === 'buttons' ? 3 : 12;
        $seen = [];
        foreach (array_slice((array)($d['options'] ?? []), 0, $max) as $o) {
            $label = trim(clean_text(mb_substr((string)($o['label'] ?? ''), 0, $kind === 'buttons' ? 25 : 100)));
            if ($label === '' || isset($seen[$label])) {
                continue;
            }
            $seen[$label] = 1;
            // زر الرابط (url) متاح للأزرار الحقيقية بس؛ الاستبيان تصويت فقط
            $allowed = $kind === 'buttons' ? ['none', 'message', 'poll', 'url'] : ['none', 'message', 'poll'];
            $action = in_array($o['action'] ?? '', $allowed, true) ? $o['action'] : 'none';
            $text = mb_substr(trim((string)($o['text'] ?? '')), 0, 2000);
            $next = (int)($o['next'] ?? 0);
            $url = trim((string)($o['url'] ?? ''));
            if ($action === 'url') {
                if (!preg_match('~^https?://\S+~i', $url)) {
                    json_err("حط رابط صحيح (يبدأ بـ http) للزر «{$label}»");
                }
                $url = mb_substr($url, 0, 2048);
            }
            if ($action === 'message' && $text === '') {
                json_err("اكتب الرسالة اللي تنرسل لما يضغط «{$label}»");
            }
            if ($action === 'poll' && (!$next || !poll_tpl_of($owner, $next) || $next === (int)($d['id'] ?? 0))) {
                json_err("اختار الاستبيان اللي ينرسل لما يضغط «{$label}»");
            }
            $opts[] = ['label' => $label, 'action' => $action, 'text' => $action === 'message' ? $text : '',
                'next' => $action === 'poll' ? $next : 0, 'url' => $action === 'url' ? $url : ''];
        }
        // الأزرار الحقيقية تكفي بزر واحد (مثل زر «المزيد»)؛ التصويت يحتاج خيارين
        $min = $kind === 'buttons' ? 1 : 2;
        if (count($opts) < $min) {
            json_err($kind === 'buttons' ? 'حط زر واحد على الأقل' : 'لازم خيارين على الأقل (واتساب يطلب هيج)');
        }
    }
    return ['kind' => $kind, 'question' => $q, 'options' => $opts, 'multi' => $kind === 'survey' && !empty($d['multi']),
        'thanks' => mb_substr(trim((string)($d['thanks'] ?? '')), 0, 1000), 'name' => trim(clean_text(mb_substr((string)($d['name'] ?? ''), 0, 60)))];
}

/** المحرك: شخص ضغط خيار. نسجله ونرجّع الرد (رسالة/استبيان ثاني/شكر) */
function poll_on_vote(array $d): array
{
    $owner = (int)($d['owner'] ?? 0);
    $t = poll_tpl_of($owner, $d['tpl'] ?? 0);
    $option = clean_text(mb_substr((string)($d['option'] ?? ''), 0, 100));
    $name = clean_text(mb_substr((string)($d['name'] ?? ''), 0, 60));
    $phone = preg_replace('/\D/', '', (string)($d['phone'] ?? ''));
    store_insert('poll_votes', ['owner' => $owner, 'tpl' => $t ? (int)$t['id'] : 0, 'account_id' => (string)($d['account_id'] ?? ''),
        'jid' => (string)($d['jid'] ?? ''), 'phone' => $phone, 'name' => $name, 'option' => $option, 'at' => now_str()]);
    $who = $name ?: ($phone ? '+' . $phone : 'شخص');
    $title = $t ? ($t['name'] ?: $t['question']) : 'استبيان';
    add_event($owner, null, '🗳️ ' . $who . ' اختار «' . $option . '»', mb_substr($title, 0, 80), 'success', $t ? 'polls' : null);
    if (notify_prefs(user_by_id($owner))['votes']) {
        push_user($owner, '🗳️ ' . $who . ' ضغط «' . $option . '»', mb_substr($title, 0, 100), '/app#polls', 'vote');
    }
    if (!$t) {
        return [];
    }
    $reply = [];
    foreach ($t['options'] as $o) {
        if ($o['label'] !== $option) {
            continue;
        }
        if ($o['action'] === 'message') {
            $reply['text'] = with_trial_footer($owner, $o['text']);
        } elseif ($o['action'] === 'poll' && ($n = poll_tpl_of($owner, $o['next']))) {
            $reply['poll'] = poll_payload($n);
        }
    }
    if (!$reply && $t['thanks'] !== '') {
        $reply['text'] = $t['thanks'];
    }
    return $reply ? ['reply' => $reply] : [];
}

/** نتائج قالب: عدد كل خيار + آخر المصوتين */
function poll_results(array $t): array
{
    $votes = store_where('poll_votes', fn($v) => (int)$v['tpl'] === (int)$t['id']);
    usort($votes, fn($a, $b) => strcmp($b['at'], $a['at']));
    $opts = poll_payload($t)['options'];
    $counts = array_fill_keys($opts, 0);
    $people = [];
    foreach ($votes as $v) {
        $counts[$v['option']] = ($counts[$v['option']] ?? 0) + 1;
        $people[$v['jid'] ?: $v['phone']] = 1;
    }
    $avg = null;
    if ($t['kind'] === 'rating' && $votes) {
        $sum = 0;
        foreach ($votes as $v) {
            $sum += 5 - (int)array_search($v['option'], RATING_OPTIONS, true);
        }
        $avg = round($sum / count($votes), 1);
    }
    return ['counts' => $counts, 'total' => count($votes), 'people' => count($people), 'avg' => $avg,
        'recent' => array_map(fn($v) => ['name' => $v['name'], 'phone' => $v['phone'], 'option' => $v['option'], 'at' => $v['at']], array_slice($votes, 0, 200))];
}

function poll_routes(): array
{
    return [
        ['GET', '#^/api/polls$#', function () {
            $id = require_login();
            $rows = store_where('poll_tpls', fn($t) => (int)$t['owner'] === $id);
            usort($rows, fn($a, $b) => (int)$b['id'] - (int)$a['id']);
            $votes = store_where('poll_votes', fn($v) => (int)$v['owner'] === $id);
            $cnt = [];
            foreach ($votes as $v) {
                $cnt[(int)$v['tpl']] = ($cnt[(int)$v['tpl']] ?? 0) + 1;
            }
            json_out(['ok' => true, 'polls' => array_map(fn($t) => $t + ['votes' => $cnt[(int)$t['id']] ?? 0, 'preview' => poll_payload($t)['options']], $rows), 'rating_options' => RATING_OPTIONS]);
        }],
        ['GET', '#^/api/polls/(\d+)$#', function ($pid) {
            $id = require_login();
            $t = poll_tpl_of($id, $pid) ?? json_err('غير موجود', 404);
            json_out(['ok' => true, 'poll' => $t, 'results' => poll_results($t)]);
        }],
        ['POST', '#^/api/polls$#', function () {
            $id = require_login();
            $d = request_json();
            $v = poll_clean($d, $id);
            if (!empty($d['id'])) {
                poll_tpl_of($id, $d['id']) ?? json_err('غير موجود', 404);
                store_update('poll_tpls', (int)$d['id'], fn($r) => array_merge($r, $v));
                json_out(['ok' => true, 'id' => (int)$d['id']]);
            }
            if (count(store_where('poll_tpls', fn($t) => (int)$t['owner'] === $id)) >= 50) {
                json_err('الحد 50 قالب');
            }
            json_out(['ok' => true, 'id' => store_insert('poll_tpls', $v + ['owner' => $id, 'created_at' => now_str()])]);
        }],
        ['POST', '#^/api/polls/(\d+)/delete$#', function ($pid) {
            $id = require_login();
            poll_tpl_of($id, $pid) ?? json_err('غير موجود', 404);
            store_delete('poll_tpls', (int)$pid);
            json_out(['ok' => true]);
        }],
        // إرسال أزرار/استبيان لمحادثة أو رقم من «المحادثات»
        ['POST', '#^/api/inbox/([\w-]+)/poll$#', function ($accId) {
            inbox_acc($accId);
            $uid = uid();
            $d = request_json();
            $t = poll_tpl_of($uid, $d['tpl'] ?? 0) ?? json_err('اختار قالب الأزرار/الاستبيان');
            $payload = ['poll' => poll_payload($t)];
            if (!empty($d['jid'])) {
                if (!inbox_valid_jid((string)$d['jid'])) {
                    json_err('محادثة غير صالحة');
                }
                $payload['jid'] = (string)$d['jid'];
            } else {
                $payload['phone'] = apply_country_code(preg_replace('/\D/', '', (string)($d['phone'] ?? '')), valid_country_code((string)($d['country_code'] ?? '')) ? (string)$d['country_code'] : default_country_code());
            }
            if (trim((string)($d['text'] ?? '')) !== '') {
                $payload['text'] = with_trial_footer($uid, trim((string)$d['text']));
            }
            inbox_send_guard($uid, $accId, 1);
            $r = engine_json('POST', '/accounts/' . rawurlencode($accId) . '/poll/send', $payload, 90);
            $st = $r['_status'] ?? 503;
            unset($r['_status']);
            json_out($r, $st === 200 ? 200 : $st);
        }],
        // الصورة الشخصية (للمحادثة أو للرقم نفسه)
        ['GET', '#^/api/avatar/([\w-]+)$#', function ($accId) {
            inbox_acc($accId);
            $jid = (string)($_GET['jid'] ?? '');
            if ($jid !== 'me' && !inbox_valid_jid($jid)) {
                http_response_code(204);
                exit;
            }
            [$st, $body] = engine_request('GET', '/accounts/' . rawurlencode($accId) . '/avatar?' . http_build_query(['jid' => $jid]), null, 20);
            if ($st !== 200 || !is_string($body) || $body === '') {
                // ما عنده صورة (أو مخفية): بدون محتوى، والواجهة تعرض أول حرف من اسمه
                header('Cache-Control: private, max-age=3600');
                http_response_code(204);
                exit;
            }
            header('Content-Type: image/jpeg');
            header('Cache-Control: private, max-age=86400');
            header('X-Content-Type-Options: nosniff');
            echo $body;
            exit;
        }],
    ];
}

/** الاستبيان المختار مع الحملة (من الفورم) */
function poll_from_post(int $uid): ?array
{
    $tid = (int)($_POST['poll_tpl'] ?? 0);
    if (!$tid) {
        return null;
    }
    $t = poll_tpl_of($uid, $tid);
    if (!$t) {
        json_err('قالب الأزرار/الاستبيان غير موجود');
    }
    return poll_payload($t);
}
