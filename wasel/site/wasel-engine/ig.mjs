// إنستغرام بتسجيل الدخول (اسم مستخدم + كلمة سر) - بدون API رسمي.
// -------------------------------------------------------------------
// تقليل المخاطر:
// - كلمة السر ما تنحفظ أبداً: تنستخدم وقت الدخول بس (وتبقى بالذاكرة 10 دقايق لو إنستغرام طلب رمز تحقق).
// - الجلسة (الكوكيز) تنحفظ مشفّرة AES-256-GCM، وكل حساب إله «جهاز» ثابت حتى ما يبين دخول من جهاز جديد كل مرة.
// - فحص هادي: صندوق الرسائل كل 90-120 ثانية (عشوائي)، والطلبات كل 5 دقايق.
// - أي تحذير من إنستغرام (spam / 429 / checkpoint) = توقف تلقائي 30 دقيقة، وتسجيل الخروج = إشعار لصاحبه.
// - الإرسال بفاصل عشوائي 4-9 ثواني، وحد يومي لكل حساب.
// - بروكسي اختياري لكل حساب (يفضّل بروكسي سكني بنفس بلد الحساب).
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';

const POLL_MIN = 90000, POLL_JITTER = 30000, PENDING_EVERY = 5 * 60000, PAUSE_ON_WARN = 30 * 60000;
const SEND_GAP_MIN = 4000, SEND_GAP_JITTER = 5000, DAILY_SEND_CAP = 150, PENDING_TTL = 10 * 60000;
const TEST = Boolean(process.env.IG_MOCK); // للاختبار فقط: بدون انتظار
const GAP = (ms) => (TEST ? 50 : ms);

export function setupInstagram({ CFG, hook, routes, sleep }) {
  const DIR = path.join(CFG.dataDir, 'ig');
  fs.mkdirSync(DIR, { recursive: true });
  const ACC_FILE = path.join(DIR, 'accounts.json');
  let lib = null;
  async function loadLib() {
    if (lib) return lib;
    if (process.env.IG_MOCK) lib = await import(process.env.IG_MOCK); // للاختبار فقط
    else { const m = await import('instagram-private-api'); lib = m.default || m; }
    return lib;
  }
  const newClient = async () => { const L = await loadLib(); return L.makeClient ? L.makeClient() : new L.IgApiClient(); };

  // ---------------------------------------------------------------- تشفير الجلسة
  const key = crypto.createHash('sha256').update('wasel-ig|' + CFG.secret).digest();
  const enc = (s) => { const iv = crypto.randomBytes(12); const c = crypto.createCipheriv('aes-256-gcm', key, iv); const d = Buffer.concat([c.update(s, 'utf8'), c.final()]); return Buffer.concat([iv, c.getAuthTag(), d]).toString('base64'); };
  const dec = (b) => { const x = Buffer.from(b, 'base64'); const d = crypto.createDecipheriv('aes-256-gcm', key, x.subarray(0, 12)); d.setAuthTag(x.subarray(12, 28)); return Buffer.concat([d.update(x.subarray(28)), d.final()]).toString('utf8'); };
  const safeId = (id) => String(id).replace(/[^a-zA-Z0-9_-]/g, '');
  const sessFile = (id) => path.join(DIR, safeId(id) + '.session');
  const chatFile = (id) => path.join(DIR, safeId(id) + '.chats.json');

  // ---------------------------------------------------------------- الحسابات
  let accounts = {};
  try { accounts = JSON.parse(fs.readFileSync(ACC_FILE, 'utf8')); } catch { accounts = {}; }
  const saveAccounts = () => { fs.writeFileSync(ACC_FILE + '.tmp', JSON.stringify(accounts)); fs.renameSync(ACC_FILE + '.tmp', ACC_FILE); };
  const clients = new Map();   // id -> IgApiClient
  const pending = new Map();   // id -> {ig, kind, info, username, owner, proxy, exp}
  const timers = new Map();
  const chats = new Map();
  const sendChain = new Map();
  const pub = (a) => ({ id: a.id, owner: a.owner, username: a.username, full_name: a.full_name || '', pk: a.pk, followers: a.followers ?? null, status: a.status, paused_until: a.paused_until || 0,
    last_poll: a.last_poll || 0, has_pic: Boolean(a.pic), proxy: Boolean(a.proxy), sent_today: (a.sent_day === today() ? a.sent_n : 0) || 0, unread: Object.values(chatsOf(a.id).threads).reduce((s, t) => s + (t.unread || 0), 0) });
  const today = () => new Date().toISOString().slice(0, 10);

  function chatsOf(id) {
    if (!chats.has(id)) { let d = { threads: {}, msgs: {} }; try { d = { ...d, ...JSON.parse(fs.readFileSync(chatFile(id), 'utf8')) }; } catch { /* جديد */ } chats.set(id, d); }
    return chats.get(id);
  }
  const dirty = new Set();
  setInterval(() => {
    for (const id of dirty) { const d = chats.get(id); if (!d || !accounts[id]) continue; try { fs.writeFileSync(chatFile(id) + '.tmp', JSON.stringify(d)); fs.renameSync(chatFile(id) + '.tmp', chatFile(id)); } catch { /* ignore */ } }
    dirty.clear();
  }, 3000);

  async function persistSession(id, ig) {
    try { const s = await ig.state.serialize(); delete s.constants; fs.writeFileSync(sessFile(id), enc(JSON.stringify(s))); } catch { /* ignore */ }
  }
  async function clientFor(id) {
    if (clients.has(id)) return clients.get(id);
    const a = accounts[id];
    if (!a) throw new Error('الحساب غير موجود');
    const ig = await newClient();
    ig.state.generateDevice(a.username); // نفس «الجهاز» دائماً لنفس الحساب
    if (a.proxy) ig.state.proxyUrl = a.proxy;
    await ig.state.deserialize(dec(fs.readFileSync(sessFile(id), 'utf8')));
    let t = null;
    ig.request.end$.subscribe(() => { clearTimeout(t); t = setTimeout(() => persistSession(id, ig), 1500); });
    clients.set(id, ig);
    return ig;
  }

  // ---------------------------------------------------------------- قراءة الرسائل
  const tsOf = (t) => Math.floor(Number(t || 0) / 1e6) || Math.floor(Date.now() / 1000);
  function parseItem(it, myPk) {
    const me = String(it.user_id) === String(myPk);
    const t = it.item_type;
    let type = 'text', text = '', url = null;
    const img = (m) => m?.image_versions2?.candidates?.[0]?.url || null;
    if (t === 'text') text = it.text || '';
    else if (t === 'link') text = it.link?.text || '';
    else if (t === 'like') text = it.like || '❤️';
    else if (t === 'media') { type = it.media?.media_type === 2 ? 'video' : 'image'; url = it.media?.media_type === 2 ? (it.media?.video_versions?.[0]?.url || img(it.media)) : img(it.media); }
    else if (t === 'raven_media' || t === 'visual_media') { type = 'image'; text = '📸 صورة مؤقتة'; }
    else if (t === 'voice_media') { type = 'audio'; url = it.voice_media?.media?.audio?.audio_src || null; }
    else if (t === 'animated_media') { type = 'image'; url = it.animated_media?.images?.fixed_height?.url || null; }
    else if (t === 'reel_share') { type = 'story'; text = it.reel_share?.text || ''; }
    else if (t === 'story_share') { type = 'story'; text = it.story_share?.message || ''; }
    else if (t === 'media_share' || t === 'clip' || t === 'felix_share') { type = 'post'; text = '📌 شارك منشور'; url = img(it.media_share || it.clip?.clip || it.felix_share?.video); }
    else if (t === 'action_log') return null;
    else { type = 'other'; text = t ? `(${t})` : ''; }
    if (url && !/^https:\/\//.test(url) && !process.env.IG_MOCK) url = null;
    return { id: String(it.item_id), me, ts: tsOf(it.timestamp), type, text: String(text).slice(0, 4000), url };
  }
  function peerOf(th, myPk) {
    const u = (th.users || []).find((x) => String(x.pk) !== String(myPk)) || (th.users || [])[0] || {};
    return { pk: String(u.pk || ''), username: u.username || '', full_name: u.full_name || '', pic: u.profile_pic_url || '' };
  }
  /** يدمج محادثة من إنستغرام - يرجع الرسائل الواردة الجديدة */
  function mergeThread(id, th, { request = false, initial = false } = {}) {
    const a = accounts[id], d = chatsOf(id), tid = String(th.thread_id);
    const old = d.threads[tid];
    const peer = peerOf(th, a.pk);
    const list = d.msgs[tid] || [];
    const known = new Set(list.map((m) => m.id));
    const fresh = [];
    for (const it of th.items || []) {
      if (known.has(String(it.item_id))) continue;
      const m = parseItem(it, a.pk);
      if (!m) continue;
      list.push(m); known.add(m.id);
      if (!m.me && old && !initial) fresh.push(m);
    }
    list.sort((x, y) => x.ts - y.ts);
    d.msgs[tid] = list.slice(-300); // آخر 300 رسالة محفوظة لكل محادثة
    const lastMsg = d.msgs[tid][d.msgs[tid].length - 1];
    const seenTs = Math.max(0, ...Object.entries(th.last_seen_at || {}).filter(([pk]) => pk !== String(a.pk)).map(([, v]) => tsOf(v?.timestamp)));
    d.threads[tid] = {
      tid, peer, title: th.thread_title || '', group: (th.users || []).length > 1, request: request || Boolean(th.pending),
      ts: lastMsg ? lastMsg.ts : (old?.ts || tsOf(th.last_activity_at)), last: lastMsg ? { me: lastMsg.me, type: lastMsg.type, text: lastMsg.text.slice(0, 120) } : (old?.last || null),
      unread: (old?.unread || 0) + fresh.length || (initial && th.read_state === 1 ? 1 : (old?.unread || 0)),
      incoming: d.msgs[tid].some((m) => !m.me), seen_ts: seenTs || old?.seen_ts || 0, oldest_cursor: th.oldest_cursor || old?.oldest_cursor || null,
    };
    if (old && request === false && old.request && !th.pending) d.threads[tid].request = false;
    dirty.add(id);
    return fresh.map((m) => ({ tid, peer: peer.username ? '@' + peer.username : peer.full_name, text: m.text || ({ image: '📷 صورة', video: '🎥 فيديو', audio: '🎤 صوت', story: '📖 رد على ستوري', post: '📌 منشور' })[m.type] || 'رسالة' }));
  }

  // ---------------------------------------------------------------- الفحص الدوري (هادي)
  async function poll(id, { initial = false, withPending = false } = {}) {
    const a = accounts[id];
    if (!a || a.status !== 'connected' || (a.paused_until || 0) > Date.now()) return;
    const ig = await clientFor(id);
    const fresh = [];
    try {
      const inbox = await ig.feed.directInbox().items();
      for (const th of inbox) fresh.push(...mergeThread(id, th, { initial }));
      if (withPending || initial || Date.now() - (a.last_pending || 0) > PENDING_EVERY) {
        const pend = await ig.feed.directPending().items();
        for (const th of pend) fresh.push(...mergeThread(id, th, { request: true, initial }));
        a.last_pending = Date.now();
      }
      a.last_poll = Date.now(); a.error = null; saveAccounts();
    } catch (e) { return handleError(id, e); }
    if (fresh.length && a.owner != null) hook({ type: 'ig_msg', owner: a.owner, account_id: id, username: a.username, items: fresh.slice(0, 20) });
  }
  function handleError(id, e) {
    const a = accounts[id], name = e?.name || '', msg = String(e?.message || e);
    console.log(`[إنستغرام] ${a?.username}: ${name} ${msg.slice(0, 120)}`);
    if (!a) return;
    if (/LoginRequired|login_required|CookieNotFound/i.test(name + msg)) {
      a.status = 'needs_login'; clients.delete(id); stopPolling(id);
      hook({ type: 'event', owner: a.owner, account: '@' + a.username, title: 'إنستغرام سجّل خروج @' + a.username, body: 'سجّل دخول من جديد من حسابي ← إنستغرام', kind: 'warning' });
    } else if (/Checkpoint|challenge|Spam|feedback_required|429|Too Many|please wait/i.test(name + msg)) {
      a.paused_until = Date.now() + PAUSE_ON_WARN; // نهدّي 30 دقيقة
      hook({ type: 'event', owner: a.owner, account: '@' + a.username, title: 'إنستغرام طلب تهدئة @' + a.username, body: 'وقفنا الفحص 30 دقيقة تلقائياً حتى نحمي الحساب', kind: 'warning' });
    }
    a.error = msg.slice(0, 200); saveAccounts();
    const le = loginError(e);
    logError(a.owner, a.username, 'run', { code: le.code, error: le.code === 'unknown' ? 'خطأ من إنستغرام: ' + msg.slice(0, 150) : le.error, detail: le.detail });
    return { ok: false, error: le.code === 'unknown' ? msg.slice(0, 200) : le.error };
  }
  function schedule(id) {
    stopPolling(id);
    const t = setTimeout(async () => { try { await poll(id); } catch (e) { console.log('[إنستغرام]', e.message); } if (accounts[id]?.status === 'connected') schedule(id); }, POLL_MIN + Math.floor(Math.random() * POLL_JITTER));
    timers.set(id, t);
  }
  function stopPolling(id) { clearTimeout(timers.get(id)); timers.delete(id); }

  // ---------------------------------------------------------------- تسجيل الدخول (+ رمز التحقق)
  async function finishLogin(id, ig, { owner, username, proxy }) {
    const me = await ig.account.currentUser();
    let followers = null;
    try { followers = (await ig.user.info(me.pk)).follower_count ?? null; } catch { /* اختياري */ }
    accounts[id] = { ...(accounts[id] || {}), id, owner, username: me.username || username, pk: String(me.pk), full_name: me.full_name || '', pic: me.profile_pic_url || '', followers, proxy: proxy || null, status: 'connected', paused_until: 0, created: accounts[id]?.created || Date.now() };
    saveAccounts();
    await persistSession(id, ig);
    let t = null;
    ig.request.end$.subscribe(() => { clearTimeout(t); t = setTimeout(() => persistSession(id, ig), 1500); });
    clients.set(id, ig);
    pending.delete(id);
    await poll(id, { initial: true }).catch(() => {});
    schedule(id);
    return { ok: true, id, status: 'connected', account: pub(accounts[id]) };
  }
  /** خطأ الدخول: رسالة واضحة + نوع (للحد ولسجل الأخطاء) + تفاصيل تقنية (بدون كلمة السر) */
  function loginError(e, password = '') {
    const n = e?.name || '', m = String(e?.message || e || ''), all = n + ' ' + m;
    const detail = (n ? n + ': ' : '') + m.split(password || '\u0000').join('***').slice(0, 400);
    const R = (code, error) => ({ ok: false, code, error, detail });
    if (n === 'IgLoginBadPasswordError') return R('bad_password', 'كلمة السر غلط. إذا متأكد منها: افتح تطبيق إنستغرام بجوالك، وإذا طلع «هل هذا أنت؟» اضغط «هذا أنا» وجرب مرة ثانية');
    if (n === 'IgLoginInvalidUserError') return R('invalid_user', 'اسم المستخدم (أو الإيميل/الرقم) غير موجود بإنستغرام');
    if (n === 'IgChallengeWrongCodeError') return R('wrong_code', 'الرمز غلط - تأكد منه وجرب مرة ثانية');
    if (n === 'IgCheckpointError' || /checkpoint|challenge/i.test(all)) return R('checkpoint', 'إنستغرام يريد تأكيد من التطبيق: افتح إنستغرام بجوالك ووافق على تسجيل الدخول، وبعدها جرب');
    if (/Spam|429|Too Many|please wait|few minutes/i.test(all)) return R('wait', 'إنستغرام طلب الانتظار شوية - جرب بعد 10-15 دقيقة (لا تكرر المحاولة هسه)');
    if (/ECONNREFUSED|ETIMEDOUT|ENOTFOUND|ECONNRESET|EAI_AGAIN|socket hang up|tunneling|proxy/i.test(all)) return R('network', 'السيرفر ما كدر يوصل لإنستغرام' + (/proxy|tunneling/i.test(all) ? ' - تأكد من البروكسي' : ' - ممكن الاستضافة تمنع الاتصال، جرب ببروكسي'));
    if (/Cannot find (package|module)/i.test(all)) return R('setup', 'مكتبة إنستغرام مو منصّبة بالمحرك - من Setup Node.js App اضغط Run NPM Install ثم Restart');
    return R('unknown', 'تعذر تسجيل الدخول: ' + m.slice(0, 150));
  }
  /** نسجل كل خطأ بسجل الموقع (حتى تنفهم المشكلة) */
  function logError(owner, username, stage, r) {
    if (owner == null) return;
    hook({ type: 'ig_error', owner, username, stage, code: r.code || 'unknown', error: r.error, detail: r.detail || '' });
  }
  /** نوع الخطأ: بالكلاس لو موجود، وإلا بالاسم */
  const isErr = (L, e, name) => (typeof L?.[name] === 'function' && e instanceof L[name]) || e?.name === name || e?.constructor?.name === name;
  async function login(args) {
    let L;
    try { L = await loadLib(); } catch (e) { const r = loginError(e); logError(args.owner, args.username, 'login', r); return r; }
    const r = await loginInner(L, args);
    if (!r.ok && !r.need) logError(args.owner, args.username, 'login', r);
    return r;
  }
  async function loginInner(L, { id, owner, username, password, proxy }) {
    const ig = await newClient();
    ig.state.generateDevice(username);
    if (proxy) ig.state.proxyUrl = proxy;
    try {
      await ig.simulate?.preLoginFlow?.().catch(() => {});
      await ig.account.login(username, password);
      return await finishLogin(id, ig, { owner, username, proxy });
    } catch (e) {
      if (isErr(L, e, 'IgLoginTwoFactorRequiredError')) {
        const info = e.response?.body?.two_factor_info || {};
        pending.set(id, { ig, kind: '2fa', info, owner, username, proxy, exp: Date.now() + PENDING_TTL });
        return { ok: false, need: '2fa', id, via: info.totp_two_factor_on ? 'app' : 'sms', hint: info.obfuscated_phone_number || '' };
      }
      if (isErr(L, e, 'IgCheckpointError')) {
        try { await ig.challenge.auto(true); } catch (e2) { return loginError(e2, password); }
        pending.set(id, { ig, kind: 'challenge', owner, username, proxy, exp: Date.now() + PENDING_TTL });
        const cp = ig.state.challenge?.step_data?.contact_point || '';
        return { ok: false, need: 'challenge', id, via: /@/.test(cp) ? 'email' : 'sms', hint: cp };
      }
      return loginError(e, password);
    }
  }
  async function verify(id, code) {
    const p = pending.get(id);
    if (!p || p.exp < Date.now()) { pending.delete(id); return { ok: false, error: 'انتهت مهلة الرمز - سجّل دخول من جديد' }; }
    try {
      if (p.kind === '2fa') {
        await p.ig.account.twoFactorLogin({ username: p.username, verificationCode: String(code).replace(/\D/g, ''), twoFactorIdentifier: p.info.two_factor_identifier, verificationMethod: p.info.totp_two_factor_on ? '0' : '1', trustThisDevice: '1' });
      } else {
        await p.ig.challenge.sendSecurityCode(String(code).replace(/\D/g, ''));
      }
      return await finishLogin(id, p.ig, p);
    } catch (e) { const r = loginError(e); logError(p.owner, p.username, 'verify', r); return r; }
  }
  // تنظيف تسجيلات الدخول المعلقة
  setInterval(() => { for (const [id, p] of pending) if (p.exp < Date.now()) pending.delete(id); }, 60000);

  // ---------------------------------------------------------------- الإرسال (بفاصل وحد يومي)
  function queueSend(id, fn) {
    const prev = sendChain.get(id) || Promise.resolve();
    const job = prev.then(async () => { const r = await fn(); await sleep(GAP(SEND_GAP_MIN + Math.floor(Math.random() * SEND_GAP_JITTER))); return r; });
    sendChain.set(id, job.catch(() => {}));
    return job;
  }

  // ---------------------------------------------------------------- الصور (نحفظها 3 أيام)
  const PIC_DIR = path.join(DIR, 'pics');
  fs.mkdirSync(PIC_DIR, { recursive: true });
  async function picOf(url) {
    if (!url) return null;
    const f = path.join(PIC_DIR, crypto.createHash('sha1').update(url).digest('hex'));
    try { const st = fs.statSync(f); if (Date.now() - st.mtimeMs < 3 * 86400000) return st.size ? fs.readFileSync(f) : null; } catch { /* مو محفوظة */ }
    try { const r = await fetch(url, { signal: AbortSignal.timeout(10000) }); if (!r.ok) throw new Error(); const b = Buffer.from(await r.arrayBuffer()); fs.writeFileSync(f, b); return b; } catch { fs.writeFileSync(f, ''); return null; }
  }

  // ---------------------------------------------------------------- المسارات
  const A = (m) => accounts[m[1]];
  routes.unshift(
    ['GET', /^\/ig\/list$/, async (_m, _b, q) => [200, { ok: true, accounts: Object.values(accounts).filter((a) => String(a.owner) === String(q.get('owner'))).map(pub) }]],
    ['POST', /^\/ig\/login$/, async (_m, b) => {
      const id = /^[a-f0-9]{8,32}$/.test(b.id || '') ? b.id : crypto.randomBytes(6).toString('hex');
      const username = String(b.username || '').trim().replace(/^@/, '').toLowerCase();
      if (!/^[a-z0-9._@+-]{1,80}$/.test(username) || !b.password) return [400, { ok: false, code: 'input', error: 'اكتب اسم المستخدم (أو الإيميل/الرقم) وكلمة السر' }];
      const proxy = /^(https?|socks5h?):\/\/[^\s]+$/i.test(b.proxy || '') ? b.proxy : null;
      return [200, await login({ id, owner: b.owner, username, password: String(b.password), proxy })];
    }],
    ['POST', /^\/ig\/([\w-]+)\/verify$/, async (m, b) => [200, await verify(m[1], b.code || '')]],
    ['POST', /^\/ig\/([\w-]+)\/logout$/, async (m) => {
      stopPolling(m[1]); clients.delete(m[1]); pending.delete(m[1]); chats.delete(m[1]);
      delete accounts[m[1]]; saveAccounts();
      for (const f of [sessFile(m[1]), chatFile(m[1])]) fs.rmSync(f, { force: true });
      return [200, { ok: true }];
    }],
    ['POST', /^\/ig\/([\w-]+)\/sync$/, async (m) => {
      const a = A(m); if (!a) return [404, { ok: false, error: 'الحساب غير موجود' }];
      if (a.status !== 'connected') return [400, { ok: false, error: 'سجّل دخول للحساب من جديد' }];
      if ((a.paused_until || 0) > Date.now()) return [429, { ok: false, error: 'الحساب بفترة تهدئة - جرب بعد ' + Math.ceil((a.paused_until - Date.now()) / 60000) + ' دقيقة' }];
      if (Date.now() - (a.last_poll || 0) < GAP(20000)) return [200, { ok: true, threads: Object.keys(chatsOf(m[1]).threads).length }]; // ما نكرر خلال 20 ثانية
      const r = await poll(m[1], { withPending: true });
      return r && r.ok === false ? [400, r] : [200, { ok: true, threads: Object.keys(chatsOf(m[1]).threads).length }];
    }],
    ['GET', /^\/ig\/([\w-]+)\/threads$/, async (m) => {
      const a = A(m); if (!a) return [404, { ok: false, error: 'الحساب غير موجود' }];
      const d = chatsOf(m[1]);
      const list = Object.values(d.threads).sort((x, y) => y.ts - x.ts).slice(0, 1500)
        .map((t) => ({ tid: t.tid, username: t.peer.username, name: t.title || t.peer.full_name, pk: t.peer.pk, has_pic: Boolean(t.peer.pic), ts: t.ts, unread: t.unread || 0, last: t.last, request: t.request, incoming: t.incoming, group: t.group, seen: Boolean(t.last?.me && t.seen_ts >= t.ts) }));
      return [200, { ok: true, account: pub(a), threads: list }];
    }],
    ['GET', /^\/ig\/([\w-]+)\/thread\/([\w-]+)$/, async (m, _b, q) => {
      const a = A(m); if (!a) return [404, { ok: false, error: 'الحساب غير موجود' }];
      const d = chatsOf(m[1]), t = d.threads[m[2]];
      if (!t) return [404, { ok: false, error: 'المحادثة غير موجودة' }];
      // لما تنفتح: نجيب الجديد (مرة كل 20 ثانية بالأكثر) ونعلّمها مقروءة
      if (q.get('fresh') === '1' && a.status === 'connected' && (a.paused_until || 0) < Date.now() && Date.now() - (t.fetched || 0) > GAP(20000)) {
        t.fetched = Date.now();
        try {
          const ig = await clientFor(m[1]);
          const items = await ig.feed.directThread({ thread_id: m[2], oldest_cursor: undefined }).items();
          mergeThread(m[1], { thread_id: m[2], users: [{ pk: t.peer.pk, username: t.peer.username, full_name: t.peer.full_name, profile_pic_url: t.peer.pic }], items, pending: t.request }, { request: t.request });
          const lastIn = [...(d.msgs[m[2]] || [])].reverse().find((x) => !x.me);
          if (lastIn && t.unread && !t.request) ig.entity.directThread(m[2]).markItemSeen(lastIn.id).catch(() => {});
        } catch (e) { handleError(m[1], e); }
      }
      if (t.unread) { t.unread = 0; dirty.add(m[1]); }
      const since = Number(q.get('since') || 0);
      return [200, { ok: true, thread: { tid: t.tid, username: t.peer.username, name: t.title || t.peer.full_name, pk: t.peer.pk, has_pic: Boolean(t.peer.pic), request: t.request, seen_ts: t.seen_ts || 0 }, messages: (d.msgs[m[2]] || []).filter((x) => x.ts >= since) }];
    }],
    ['POST', /^\/ig\/([\w-]+)\/send$/, async (m, b) => {
      const a = A(m); if (!a) return [404, { ok: false, error: 'الحساب غير موجود' }];
      if (a.status !== 'connected') return [400, { ok: false, error: 'سجّل دخول للحساب من جديد' }];
      if ((a.paused_until || 0) > Date.now()) return [429, { ok: false, error: 'الحساب بفترة تهدئة لحمايته - جرب بعد ' + Math.ceil((a.paused_until - Date.now()) / 60000) + ' دقيقة' }];
      if (a.sent_day !== today()) { a.sent_day = today(); a.sent_n = 0; }
      if (a.sent_n >= DAILY_SEND_CAP) return [429, { ok: false, error: `وصلت حد اليوم (${DAILY_SEND_CAP} رسالة) لهذا الحساب - حماية من الحظر` }];
      const text = String(b.text || '').trim().slice(0, 1000);
      const t = chatsOf(m[1]).threads[b.tid];
      if (!text || !t) return [400, { ok: false, error: 'اكتب رسالة' }];
      try {
        const r = await queueSend(m[1], async () => (await clientFor(m[1])).entity.directThread(String(b.tid)).broadcastText(text));
        a.sent_n += 1; saveAccounts();
        const d = chatsOf(m[1]); const mid = String(r?.item_id || r?.payload?.item_id || 'loc' + Date.now());
        (d.msgs[b.tid] = d.msgs[b.tid] || []).push({ id: mid, me: true, ts: Math.floor(Date.now() / 1000), type: 'text', text, url: null });
        t.ts = Math.floor(Date.now() / 1000); t.last = { me: true, type: 'text', text: text.slice(0, 120) }; t.unread = 0; dirty.add(m[1]);
        return [200, { ok: true, id: mid }];
      } catch (e) { const r = handleError(m[1], e); return [400, { ok: false, error: r?.error || 'ما انرسلت' }]; }
    }],
    ['POST', /^\/ig\/([\w-]+)\/approve$/, async (m, b) => {
      const a = A(m); if (!a || a.status !== 'connected') return [400, { ok: false, error: 'الحساب غير متصل' }];
      const t = chatsOf(m[1]).threads[b.tid]; if (!t) return [404, { ok: false, error: 'المحادثة غير موجودة' }];
      try { await (await clientFor(m[1])).directThread.approve(String(b.tid)); t.request = false; dirty.add(m[1]); return [200, { ok: true }]; } catch (e) { const r = handleError(m[1], e); return [400, { ok: false, error: r?.error || 'تعذر القبول' }]; }
    }],
    ['GET', /^\/ig\/([\w-]+)\/pic$/, async (m, _b, q) => {
      const a = A(m); if (!a) return [404, { ok: false }];
      const u = String(q.get('u') || '');
      const url = u === 'me' ? a.pic : Object.values(chatsOf(m[1]).threads).find((t) => t.peer.pk === u)?.peer.pic;
      const buf = await picOf(url);
      return buf ? [200, buf, { 'Content-Type': 'image/jpeg', 'Cache-Control': 'private, max-age=86400' }] : [404, { ok: false }];
    }],
  );

  // بعد إعادة تشغيل المحرك: نرجّع الحسابات المتصلة (بدون تسجيل دخول جديد - من الجلسة المحفوظة) بفواصل
  Object.values(accounts).forEach((a, i) => { if (a.status === 'connected') setTimeout(() => schedule(a.id), 5000 + i * 15000); });
  return { accounts };
}
