import json, subprocess, time
SP='/tmp/claude-0/-home-user-assa/0c01bcf9-d4fb-5bf4-8b94-41757e48d704/scratchpad'
exec(open(SP+'/test6.py').read().split("print('\\n— الحد اليومي")[0])
ACC = '899435d9'
edit('users', lambda d: d['rows'][str(UID)].update(plan_active=True, plan_ends_at=None, plan_id=1))
def raw(path, cookie='cu'):
    return subprocess.run(['curl', '-s', '-o', '/dev/null', '-w', '%{http_code} %{content_type}', '-b', f'{G}/{cookie}', 'http://localhost:8080' + path], capture_output=True, text=True).stdout
def form(path, fields, cookie='cu'):
    cmd = ['curl', '-s', '-H', 'X-Requested-With: wasel', '-b', f'{G}/{cookie}']
    for k, v in fields.items(): cmd += ['--form-string', f'{k}={v}']
    return json.loads(subprocess.run(cmd + ['http://localhost:8080' + path], capture_output=True, text=True).stdout or '{}')
elog = lambda key: [json.loads(l) for l in open(SP + '/eng6.log') if key in l]
pushes = lambda: [json.loads(l) for l in open(SP + '/gymt/push.log') if l.strip()]
print('— القوالب')
check('استبيان بخيار واحد ينرفض', 'خيارين' in curl('cu', '/api/polls', body={'kind': 'survey', 'question': 'تحب؟', 'options': [{'label': 'نعم'}]}).get('error', ''))
check('زر واحد حقيقي مقبول (مثل زر «المزيد»)', bool(curl('cu', '/api/polls', body={'kind': 'buttons', 'name': 'زر واحد', 'question': 'تحب تعرف أكثر؟', 'options': [{'label': 'أخبرني بالمزيد', 'action': 'url', 'url': 'https://botiyeai.site'}]}).get('id')))
check('زر رابط بدون رابط ينرفض', 'رابط صحيح' in curl('cu', '/api/polls', body={'kind': 'buttons', 'question': 'q', 'options': [{'label': 'افتح', 'action': 'url', 'url': 'بدون'}]}).get('error', ''))
check('زر «رسالة» بدون نص ينرفض', 'اكتب الرسالة' in curl('cu', '/api/polls', body={'kind': 'buttons', 'question': 'q', 'options': [{'label': 'المزيد', 'action': 'message'}, {'label': 'لا'}]}).get('error', ''))
r = curl('cu', '/api/polls', body={'kind': 'rating', 'name': 'تقييم الخدمة', 'question': 'شلون تقيّم خدمتنا؟', 'thanks': 'شكراً لتقييمك 🌹'})
RATE = r.get('id'); check('تقييم انحفظ', bool(RATE), r)
r = curl('cu', '/api/polls', body={'kind': 'buttons', 'name': 'عرض الشتاء', 'question': 'تحب تعرف أكثر؟', 'options': [
    {'label': 'المزيد', 'action': 'message', 'text': 'العرض: خصم 30% لنهاية الشهر'}, {'label': 'قيّمنا', 'action': 'poll', 'next': RATE}, {'label': 'لا شكراً', 'action': 'none'}, {'label': 'لا شكراً'}]})
BTN = r.get('id'); check('أزرار انحفظت (المكرر انشال)', bool(BTN) and len(curl('cu', f'/api/polls/{BTN}')['poll']['options']) == 3, r)
check('قالب مستخدم ثاني ما ينشاف', curl('cj', f'/api/polls/{BTN}').get('ok') is not True)
print('\n— الإرسال')
r = curl('cu', f'/api/inbox/{ACC}/poll', body={'jid': '9647701111111@s.whatsapp.net', 'tpl': BTN, 'text': 'هلا 👋'})
ps = elog('poll_send')
check('من المحادثة: الأزرار وصلت للمحرك بخياراتها', r.get('ok') and ps and ps[-1]['poll']['options'] == ['المزيد', 'قيّمنا', 'لا شكراً'] and ps[-1]['poll']['tpl'] == BTN and ps[-1]['text'] == 'هلا 👋', (r, ps))
n0 = len(calls())
r = curl('cu', f'/accounts/{ACC}/campaign', form={'numbers_text': '07710000001', 'country_code': '964', 'text': 'عرض', 'poll_tpl': str(RATE)})
time.sleep(0.6); c = calls()[n0:]
check('مع الحملة: التقييم (5 نجوم) ينرسل بعد الرسالة', r.get('ok') and c and c[-1]['poll'] and len(c[-1]['poll']['options']) == 5, (r, c[-1] if c else None))
r = form(f'/api/inbox/{ACC}/broadcast', {'jids': json.dumps(['9647701111111@s.whatsapp.net']), 'text': 'x', 'poll_tpl': str(BTN)})
time.sleep(0.6); c = calls()
check('مع الرسالة الجماعية', r.get('ok') and c[-1]['poll']['tpl'] == BTN, c[-1])
check('قالب مو مالتك ينرفض', curl('cu', f'/accounts/{ACC}/campaign', form={'numbers_text': '07710000001', 'country_code': '964', 'text': 'x', 'poll_tpl': '99999'}).get('ok') is not True)
print('\n— الضغط على زر')
subs = json.load(open(D + '/push_subs.json')) if __import__('os').path.exists(D + '/push_subs.json') else {}
import hashlib
ep = 'http://localhost:8091/push/u2'
r = curl('cu', '/api/push/subscribe', body={'endpoint': 'javascript:x'}); check('اشتراك غير صالح ينرفض', r.get('ok') is not True)
d = json.load(open(D + '/push_subs.json')) if __import__('os').path.exists(D + '/push_subs.json') else {}
d[hashlib.sha256(ep.encode()).hexdigest()[:24]] = {'uid': UID, 'endpoint': ep, 'at': ''}
json.dump(d, open(D + '/push_subs.json', 'w'))
r = hook({'type': 'poll_vote', 'owner': UID, 'account_id': ACC, 'tpl': BTN, 'jid': '9647701111111@s.whatsapp.net', 'name': 'أحمد علي', 'phone': '9647701111111', 'option': 'المزيد'})
check('«المزيد» يرجّع الرسالة المحددة', r.get('reply', {}).get('text') == 'العرض: خصم 30% لنهاية الشهر', r)
r = hook({'type': 'poll_vote', 'owner': UID, 'account_id': ACC, 'tpl': BTN, 'jid': '9647701111111@s.whatsapp.net', 'name': 'أحمد علي', 'option': 'قيّمنا'})
check('«قيّمنا» يرجّع استبيان التقييم', r.get('reply', {}).get('poll', {}).get('tpl') == RATE, r)
r = hook({'type': 'poll_vote', 'owner': UID, 'account_id': ACC, 'tpl': RATE, 'jid': '9647701111111@s.whatsapp.net', 'name': 'أحمد علي', 'option': '⭐⭐⭐⭐ جيد جداً'})
check('التقييم يرجّع رسالة الشكر', r.get('reply', {}).get('text') == 'شكراً لتقييمك 🌹', r)
hook({'type': 'poll_vote', 'owner': UID, 'account_id': ACC, 'tpl': RATE, 'jid': '9647702222222@s.whatsapp.net', 'name': 'سارة', 'option': '⭐⭐⭐⭐⭐ ممتاز'})
r = hook({'type': 'poll_vote', 'owner': UID, 'account_id': ACC, 'tpl': BTN, 'jid': 'x@s.whatsapp.net', 'option': 'لا شكراً'})
check('زر بدون رد: يتسجل بس', 'reply' not in r, r)
r = hook({'type': 'poll_vote', 'owner': 999, 'tpl': BTN, 'option': 'المزيد'})
check('قالب مستخدم ثاني: ما يرجّع رد', 'reply' not in r, r)
res = curl('cu', f'/api/polls/{RATE}')['results']
check('نتائج التقييم: 2 شخص، المعدل 4.5', res['total'] == 2 and res['avg'] == 4.5 and res['counts']['⭐⭐⭐⭐⭐ ممتاز'] == 1, res)
check('قائمة القوالب فيها عدد الضغطات', {p['id']: p['votes'] for p in curl('cu', '/api/polls')['polls']}.get(BTN) == 3)
pl = pushes()
check('وصل إشعار للجوال على كل ضغطة', len([x for x in pl if x['url'] == '/push/u2']) >= 4, pl)
m = json.loads(subprocess.run(['curl', '-s', '-H', 'Content-Type: application/json', '-H', 'X-Requested-With: wasel', '-d', json.dumps({'endpoint': ep}), 'http://localhost:8080/api/push/inbox'], capture_output=True, text=True).stdout)
check('نص الإشعار فيه الاسم والزر', any('أحمد علي' in x['title'] and 'المزيد' in x['title'] for x in m['messages']), m)
print('\n— ردود ومشاهدات')
n0 = len(pushes())
hook({'type': 'activity', 'owner': UID, 'account_id': ACC, 'account_name': 'المبيعات', 'replies': [{'jid': 'a', 'name': 'أحمد', 'text': 'شكد السعر؟', 'reply': True}, {'jid': 'b', 'name': 'غريب', 'text': 'هلا', 'reply': False}], 'reads': [{'jid': 'c', 'name': 'سارة', 'text': 'عرض'}]})
m = json.loads(subprocess.run(['curl', '-s', '-H', 'X-Requested-With: wasel', '-H', 'Content-Type: application/json', '-d', json.dumps({'endpoint': ep}), 'http://localhost:8080/api/push/inbox'], capture_output=True, text=True).stdout)['messages']
titles = [x['title'] for x in m]
check('إشعار «أحمد رد عليك» (والغريب ما ينحسب افتراضياً)', any('أحمد رد عليك' in t for t in titles) and not any('غريب' in (x['title'] + x['body']) for x in m), titles)
check('إشعار «سارة شافت رسالتك وما ردت»', any('سارة' in t and 'شاف' in t for t in titles), titles)
r = curl('cu', '/api/push/prefs', body={'reads': False, 'all_msgs': True})
check('حفظ الإعدادات', r.get('ok') and r['prefs']['reads'] is False and r['prefs']['all_msgs'] is True, r)
hook({'type': 'activity', 'owner': UID, 'account_id': ACC, 'replies': [{'jid': 'b', 'name': 'غريب', 'text': 'هلا', 'reply': False}], 'reads': [{'jid': 'c', 'name': 'سارة'}]})
m = json.loads(subprocess.run(['curl', '-s', '-H', 'X-Requested-With: wasel', '-H', 'Content-Type: application/json', '-d', json.dumps({'endpoint': ep}), 'http://localhost:8080/api/push/inbox'], capture_output=True, text=True).stdout)['messages']
check('بعد التعديل: كل رسالة توصل، والمشاهدات لا', len(m) == 1 and 'غريب' in m[0]['title'], [x['title'] for x in m])
ev = curl('cu', '/api/events') if False else None
print('\n— الصور الشخصية')
x = raw(f'/api/avatar/{ACC}?jid=9647702222222@s.whatsapp.net'); check('صورة موجودة = 200 صورة', x.startswith('200 image/jpeg'), x)
x = raw(f'/api/avatar/{ACC}?jid=9647701111111@s.whatsapp.net'); check('بدون صورة = 404', x.startswith('204'), x)
x = raw(f'/api/avatar/{ACC}?jid=me'); check('صورة الرقم نفسه', x.startswith('200'), x)
x = raw(f'/api/avatar/{ACC}?jid=../etc'); check('jid غلط ينرفض', x.startswith('204'), x)
x = raw('/api/avatar/zzzz9999?jid=me'); check('رقم مو مالتك ينرفض', not x.startswith('200'), x)
print(f'\nالنتيجة: {len(OK)} نجح، {len(BAD)} فشل', BAD if BAD else '')
