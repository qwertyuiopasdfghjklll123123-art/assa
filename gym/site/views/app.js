/* تمريني - الواجهة (تطبيق صفحة وحدة) */
'use strict';
const B = window.BOOT || {};
let ME = B.user, D = B.data, LIB = null, EX = {};
const VAPID = B.vapid;
const view = document.getElementById('view');
const $ = s => document.querySelector(s), $$ = s => [...document.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const S = { page: 'home', params: {}, timers: [] };
const WD = ['الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
const WEEK_ORDER = [6, 0, 1, 2, 3, 4, 5]; // السبت أول الأسبوع
const GOALS = { build: 'بناء عضل', cut: 'تنشيف', fit: 'لياقة' };

// ---------------------------------------------------------------- أدوات
async function api(url, opt = {}) {
  const init = { method: opt.body !== undefined ? 'POST' : 'GET', headers: { 'X-Requested-With': 'tamreeni' }, credentials: 'same-origin' };
  if (opt.body instanceof FormData) init.body = opt.body;
  else if (opt.body !== undefined) { init.body = JSON.stringify(opt.body); init.headers['Content-Type'] = 'application/json'; }
  try {
    const r = await fetch(url, init);
    if (r.status === 401 && ME) { ME = null; render(); return { ok: false, error: 'سجّل دخولك' }; }
    return await r.json();
  } catch (e) { return { ok: false, offline: true, error: 'ما فيه إنترنت - جرب بعدين' }; }
}
let toastT;
function toast(msg, type = '') {
  const t = $('#toast'); t.textContent = msg; t.className = 'toast show ' + type;
  clearTimeout(toastT); toastT = setTimeout(() => t.classList.remove('show'), 2800);
}
const fmtMin = s => { s = Math.max(0, Math.round(s)); return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0'); };
const todayStr = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const head = (title, back = true, end = '') => `<div class="page-head">${back ? `<button class="back" onclick="goBack()"><i class="fa-solid fa-arrow-right"></i></button>` : ''}<h2>${title}</h2>${end}</div>`;
function cacheState() { try { localStorage.setItem('tmr_state', JSON.stringify({ user: ME, data: D })); } catch (e) {} }
/** يحفظ قسم من البيانات بالسيرفر (ويحدّث النسخة المحلية فوراً) */
async function save(section, value, quiet = false) {
  D[section] = value; cacheState();
  const r = await api('/api/save', { body: { section, value } });
  if (r.ok) { D[section] = r.value; cacheState(); if (!quiet) toast('انحفظ ✓', 'ok'); }
  else if (!quiet) toast(r.error || 'ما انحفظ', 'err');
  return r;
}
function sheet(html, onOpen) {
  $('#shBody').innerHTML = html; $('#sh').classList.add('show'); $('#shBg').classList.add('show');
  if (onOpen) onOpen($('#shBody'));
}
function closeSheet() { $('#sh').classList.remove('show'); $('#shBg').classList.remove('show'); }
$('#shBg').onclick = closeSheet;
function confirmSheet(text, okLabel = 'تأكيد', danger = true) {
  return new Promise(res => {
    sheet(`<h3 style="margin:0 0 8px">${text}</h3><div class="row-actions" style="margin-top:18px"><button class="btn btn-ghost" id="cfNo">إلغاء</button><button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" id="cfYes">${okLabel}</button></div>`, b => {
      b.querySelector('#cfNo').onclick = () => { closeSheet(); res(false); };
      b.querySelector('#cfYes').onclick = () => { closeSheet(); res(true); };
    });
  });
}

// ---------------------------------------------------------------- مكتبة التمارين
const haveEq = ex => ex.equip.every(e => e === 'bodyweight' || (D.equipment || []).includes(e));
const muscleName = m => LIB.muscles[m] || m;
const repsList = it => { const a = String(it.reps).split(/[-×x ]+/).filter(Boolean).map(Number).filter(n => n > 0); const out = []; for (let i = 0; i < it.sets; i++) out.push(a[i] ?? a[a.length - 1] ?? 10); return out; };
const unit = ex => ex.id === 'plank' ? 'ثانية' : 'عدّة';
const mediaUrl = ex => D.media?.[ex.id] ? '/media/' + D.media[ex.id] : null;
/** صندوق الرسم المتحرك (أو صورة/فيديو المستخدم لو رفع) */
function animBox(ex, cls = '', opts = {}) {
  const m = mediaUrl(ex);
  if (m && !opts.forceAnim) return `<div class="an-box ${cls}">${/\.(mp4|webm)$/.test(m) ? `<video class="own" src="${esc(m)}" autoplay loop muted playsinline></video>` : `<img class="own" src="${esc(m)}" alt="">`}</div>`;
  return `<div class="an-box ${cls}" data-anim="${esc(ex.id)}" ${opts.static ? 'data-static="1"' : ''}></div>`;
}
function mountAnims(root = view) {
  root.querySelectorAll('[data-anim]').forEach(el => { if (el._m) return; el._m = 1; const ex = EX[el.dataset.anim]; if (ex) Anim.mount(el, ex, { static: !!el.dataset.static, offset: Math.random() * 2000 }); });
}

// ---------------------------------------------------------------- الإشعارات
let REG = null;
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').then(r => { REG = r; }).catch(() => {});
const notifOK = () => 'Notification' in window && Notification.permission === 'granted';
async function notifyLocal(title, body, tag = 'tmr') {
  if (D?.settings?.vibrate && navigator.vibrate) navigator.vibrate([300, 120, 300, 120, 500]);
  if (D?.settings?.sound) beep();
  if (notifOK() && (document.hidden || tag !== 'rest')) {
    try { const r = REG || await navigator.serviceWorker.ready; await r.showNotification(title, { body, tag, icon: '/icon-192.png', badge: '/icon-192.png', vibrate: [300, 120, 300], renotify: true, dir: 'rtl', lang: 'ar' }); return; } catch (e) {}
  }
  if (!document.hidden) toast(title + ' - ' + body, 'ok');
}
let AC = null;
function beep() {
  try {
    AC = AC || new (window.AudioContext || window.webkitAudioContext)();
    [0, 0.25, 0.5].forEach((t, i) => { const o = AC.createOscillator(), g = AC.createGain(); o.frequency.value = i === 2 ? 1180 : 880; o.connect(g); g.connect(AC.destination); g.gain.setValueAtTime(0.0001, AC.currentTime + t); g.gain.exponentialRampToValueAtTime(0.35, AC.currentTime + t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, AC.currentTime + t + 0.2); o.start(AC.currentTime + t); o.stop(AC.currentTime + t + 0.22); });
  } catch (e) {}
}
const b64u = s => { const p = '='.repeat((4 - s.length % 4) % 4); const r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from([...r].map(c => c.charCodeAt(0))); };
async function enablePush() {
  if (!('Notification' in window) || !('serviceWorker' in navigator)) { toast('متصفحك ما يدعم الإشعارات' + (/iphone|ipad/i.test(navigator.userAgent) ? ' - ثبّت التطبيق على الشاشة الرئيسية أول' : ''), 'err'); return false; }
  const p = await Notification.requestPermission();
  if (p !== 'granted') { toast('لازم تسمح بالإشعارات من إعدادات المتصفح', 'err'); return false; }
  try {
    const reg = REG || await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64u(VAPID) });
    const r = await api('/api/push/subscribe', { body: { endpoint: sub.endpoint } });
    if (!r.ok) throw new Error(r.error);
    localStorage.setItem('tmr_push', sub.endpoint);
    toast('تفعّلت الإشعارات ✓', 'ok'); return true;
  } catch (e) {
    toast('الإشعارات المحلية شغّالة، بس إشعارات السيرفر ما اشتغلت: ' + (e.message || e), 'err'); return true;
  }
}
const pushOn = () => notifOK() && !!localStorage.getItem('tmr_push');
// تنبيه الوجبات وهو فاتح التطبيق (لو إشعارات السيرفر مو مفعّلة)
function mealWatch() {
  if (!ME || !D || !D.settings?.meals_on || pushOn()) return;
  const now = new Date(), hm = now.getHours() * 60 + now.getMinutes(), day = todayStr();
  for (const m of D.meals || []) {
    if (!m.on) continue;
    const [h, mi] = m.time.split(':').map(Number), mm = h * 60 + mi, k = 'tmr_meal_' + m.id;
    if (hm >= mm && hm - mm <= 10 && localStorage.getItem(k) !== day) { localStorage.setItem(k, day); notifyLocal('🍽️ وقت ' + m.name, m.note || 'لا تنسى وجبتك', 'meal'); }
  }
}
setInterval(mealWatch, 30000);

// ---------------------------------------------------------------- التنقل
const PAGES = { home: 'home', program: 'program', library: 'library', meals: 'meals', me: 'me', ex: 'library', session: 'home', day: 'program', history: 'me', equipment: 'me' };
function go(page, params = {}, push = true) {
  if (!PAGES[page]) page = 'home';
  S.timers.forEach(clearInterval); S.timers = [];
  S.page = page; S.params = params;
  document.body.classList.toggle('in-session', page === 'session');
  $$('#bnav [data-p]').forEach(b => b.classList.toggle('on', b.dataset.p === PAGES[page]));
  if (push) history.pushState({ page, params }, '', '#' + page);
  view.innerHTML = ''; window.scrollTo(0, 0);
  const w = document.createElement('div'); w.className = 'page'; view.appendChild(w);
  Promise.resolve(R[page](w, params)).then(() => mountAnims(w)).catch(e => { console.error(e); w.innerHTML = `<div class="card">صار خطأ بعرض الصفحة</div>`; });
}
function goBack() { if (history.state && history.length > 1) history.back(); else go('home'); }
window.addEventListener('popstate', e => { const st = e.state || { page: 'home', params: {} }; if (S.page === 'session' && st.page !== 'session' && SES) { /* يرجع للجلسة لاحقاً */ } go(st.page, st.params || {}, false); });
$('#bnav').addEventListener('click', e => { const b = e.target.closest('[data-p]'); if (b) go(b.dataset.p); });
window.addEventListener('scroll', () => $('#topbar').classList.toggle('scrolled', scrollY > 4), { passive: true });
const R = {};

// ---------------------------------------------------------------- الدخول
R.auth = (el) => {
  let mode = 'login';
  const draw = () => {
    el.innerHTML = `<div class="auth fade-in">
      <img class="logo-big" src="/icon-192.png" alt=""><h1>تمريني</h1><p class="sub">جدول تمارينك ووجباتك بمكان واحد، مع تنبيهات ورسوم متحركة لكل تمرين</p>
      <div class="card">
        <div class="seg" style="margin-bottom:14px"><button class="${mode === 'login' ? 'on' : ''}" data-m="login"><b>تسجيل دخول</b></button><button class="${mode === 'reg' ? 'on' : ''}" data-m="reg"><b>حساب جديد</b></button></div>
        ${mode === 'reg' ? `<div class="field"><label>اسمك</label><input class="input" id="aName" placeholder="مثلاً: علي"></div>` : ''}
        <div class="field"><label>اسم المستخدم (إنكليزي)</label><input class="input" id="aUser" dir="ltr" autocomplete="username" autocapitalize="off" placeholder="ali"></div>
        <div class="field"><label>كلمة السر</label><input class="input" id="aPass" type="password" dir="ltr" autocomplete="${mode === 'reg' ? 'new-password' : 'current-password'}"></div>
        <button class="btn btn-primary btn-block" id="aGo">${mode === 'reg' ? '<i class="fa-solid fa-user-plus"></i> إنشاء الحساب' : '<i class="fa-solid fa-right-to-bracket"></i> دخول'}</button>
      </div></div>`;
    el.querySelectorAll('[data-m]').forEach(b => b.onclick = () => { mode = b.dataset.m; draw(); });
    el.querySelector('#aGo').onclick = async () => {
      const body = { username: $('#aUser').value, password: $('#aPass').value, name: $('#aName')?.value };
      const r = await api(mode === 'reg' ? '/api/register' : '/api/login', { body });
      if (!r.ok) return toast(r.error, 'err');
      ME = r.user; D = r.data; cacheState(); render();
      toast(mode === 'reg' ? 'أهلاً ' + ME.name + ' 💪 جهزنالك برنامج تكدر تعدّله' : 'أهلاً ' + ME.name, 'ok');
    };
    el.querySelector('#aPass').onkeydown = e => { if (e.key === 'Enter') el.querySelector('#aGo').click(); };
  };
  draw();
};

// ---------------------------------------------------------------- اليوم
const dayOf = wd => D.week?.[String(wd)] || '';
const doneOn = date => (D.log || []).some(l => l.date === date);
function estMin(day) { return Math.round((day?.items || []).reduce((s, it) => s + it.sets * (40 + it.rest), 0) / 60); }
function exRow(it, i, done = false) {
  const ex = EX[it.ex]; if (!ex) return '';
  return `<div class="ex-row ${done ? 'done' : ''}" onclick="go('ex',{id:'${ex.id}'})">
    <div class="thumb">${animBox(ex, '', { static: true })}</div>
    <div class="body"><b>${i + 1}. ${esc(ex.name)}</b><small>${ex.muscles.map(muscleName).join('، ')}${it.note ? ' · ' + esc(it.note) : ''}</small></div>
    <div class="sr">${done ? '<i class="fa-solid fa-circle-check"></i>' : `${it.sets}×${esc(it.reps)}`}<small>${done ? 'خلص' : 'مجموعة × ' + unit(ex)}</small></div></div>`;
}
R.home = (el, p) => {
  const now = new Date(), wd = p.wd ?? now.getDay(), isToday = wd === now.getDay();
  const key = dayOf(wd), day = D.days?.[key];
  const done = isToday && doneOn(todayStr());
  const ses = SES && SES.date === todayStr();
  const nm = nextMeal();
  const h = now.getHours(), greet = h < 12 ? 'صباح الخير' : h < 18 ? 'مساء الخير' : 'مساء النور';
  el.innerHTML = `
    <div class="greet" style="display:flex;justify-content:space-between;align-items:center;margin:4px 2px 14px"><div><small style="color:var(--text3);font-size:12.5px">${greet}</small><h2 style="font-size:23px;font-weight:800">${esc(ME.name)} 💪</h2></div>
      <span class="pill pill-blue">${streak()} <i class="fa-solid fa-fire"></i> أيام متتالية</span></div>
    <div class="week-strip">${WEEK_ORDER.map((d, idx) => { const date = new Date(now); date.setDate(now.getDate() - ((now.getDay() + 1) % 7) + idx); return `<div class="wd ${d === wd ? 'today' : ''} ${doneOn(dateStr(date)) ? 'done' : ''}" data-wd="${d}"><span>${WD[d].replace('ال', '').slice(0, 3)}</span><b>${dayOf(d) || '—'}</b><span class="dot"></span></div>`; }).join('')}</div>
    <div style="height:14px"></div>
    ${day ? `<div class="day-hero shine">
        <i class="fa-solid fa-dumbbell big-ic"></i>
        <small>${isToday ? 'تمرين اليوم' : 'تمرين يوم ' + WD[wd]} · ${key}</small>
        <h2>${esc(day.title)}</h2>
        <div class="meta"><span><i class="fa-solid fa-list-check"></i> ${day.items.length} تمارين</span><span><i class="fa-regular fa-clock"></i> ~${estMin(day)} دقيقة</span><span><i class="fa-solid fa-layer-group"></i> ${day.items.reduce((s, i) => s + i.sets, 0)} مجموعة</span></div>
        ${isToday ? (done ? `<button class="btn btn-block" onclick="go('history')"><i class="fa-solid fa-trophy"></i> خلّصت تمرين اليوم - عاشت إيدك</button>`
          : `<button class="btn btn-block" id="startBtn"><i class="fa-solid fa-play"></i> ${ses ? 'كمّل التمرين' : 'ابدأ التمرين'}</button>`) : `<button class="btn btn-block" id="startBtn"><i class="fa-solid fa-play"></i> ابدأه هسه</button>`}
      </div>
      <div class="section-head">التمارين <a onclick="go('day',{key:'${key}'})">تعديل</a></div>
      ${day.items.map((it, i) => exRow(it, i, done)).join('') || '<div class="card">ما فيه تمارين بهذا اليوم - أضف من البرنامج</div>'}`
    : `<div class="day-hero" style="background:linear-gradient(140deg,#64748b,#334155)"><i class="fa-solid fa-bed big-ic"></i><small>${isToday ? 'اليوم' : WD[wd]}</small><h2>يوم راحة 😴</h2>
        <div class="meta">العضلات تكبر وقت الراحة - نام زين واكل بروتين</div><button class="btn btn-block" onclick="go('program')"><i class="fa-solid fa-calendar-week"></i> تعديل أيام الأسبوع</button></div>`}
    ${nm ? `<div class="section-head">الوجبة الجاية <a onclick="go('meals')">كل الوجبات</a></div>
      <div class="card next-meal" onclick="go('meals')"><span class="ic"><i class="fa-solid fa-utensils"></i></span><div style="flex:1;min-width:0"><b>${esc(nm.name)}</b><div class="row-sub">${esc(nm.note)}</div></div><b class="num" style="color:var(--orange);font-size:18px">${nm.time}</b></div>` : ''}
    ${!pushOn() ? `<div class="card notif-card" style="margin-top:12px"><span class="row-ic ic-orange"><i class="fa-solid fa-bell"></i></span><div style="flex:1;min-width:0"><b>فعّل التنبيهات</b><div class="row-sub">نهاية الراحة، إكمال التمرين، ووقت كل وجبة</div></div><button class="btn btn-primary btn-sm" id="enPush">تفعيل</button></div>` : ''}`;
  el.querySelectorAll('[data-wd]').forEach(b => b.onclick = () => go('home', { wd: +b.dataset.wd }, false));
  const sb = el.querySelector('#startBtn'); if (sb) sb.onclick = () => startSession(key);
  const ep = el.querySelector('#enPush'); if (ep) ep.onclick = async () => { if (await enablePush()) go('home', p, false); };
};
function dateStr(d) { return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
function streak() {
  const days = new Set((D.log || []).map(l => l.date)); let n = 0; const d = new Date();
  if (!days.has(dateStr(d))) d.setDate(d.getDate() - 1);
  for (let i = 0; i < 400; i++) {
    if (days.has(dateStr(d))) n++;
    else if (dayOf(d.getDay())) break; // يوم تمرين وما تمرّن = ينقطع (أيام الراحة ما تقطع)
    d.setDate(d.getDate() - 1);
  }
  return n;
}
function nextMeal() {
  const ms = (D.meals || []).filter(m => m.on); if (!ms.length) return null;
  const n = new Date(), hm = String(n.getHours()).padStart(2, '0') + ':' + String(n.getMinutes()).padStart(2, '0');
  return ms.find(m => m.time >= hm) || ms[0];
}

// ---------------------------------------------------------------- جلسة التمرين
let SES = null, REST = null, WAKE = null;
try { SES = JSON.parse(localStorage.getItem('tmr_ses') || 'null'); } catch (e) {}
function sesSave(remote = false) { try { localStorage.setItem('tmr_ses', JSON.stringify(SES)); } catch (e) {} if (remote) api('/api/save', { body: { section: 'session', value: SES } }); }
function startSession(key) {
  const day = D.days[key]; if (!day || !day.items.length) return toast('ما فيه تمارين بهذا اليوم', 'err');
  if (!SES || SES.key !== key || SES.date !== todayStr()) {
    SES = { key, date: todayStr(), title: day.title, i: 0, done: {}, w: {}, start: Date.now() };
    sesSave(true);
  }
  if (!notifOK() && 'Notification' in window && Notification.permission === 'default') Notification.requestPermission();
  go('session');
}
async function wake(on) {
  try { if (on && 'wakeLock' in navigator && !WAKE) { WAKE = await navigator.wakeLock.request('screen'); WAKE.addEventListener('release', () => { WAKE = null; }); } if (!on && WAKE) { await WAKE.release(); WAKE = null; } } catch (e) {}
}
document.addEventListener('visibilitychange', () => { if (!document.hidden && S.page === 'session') wake(true); });
R.session = (el) => {
  if (!SES) return go('home');
  const day = D.days[SES.key]; if (!day) { SES = null; sesSave(); return go('home'); }
  wake(true);
  $('#sesClock').classList.remove('hidden');
  const clock = () => { $('#sesClockV').textContent = fmtMin((Date.now() - SES.start) / 1000); };
  clock(); S.timers.push(setInterval(clock, 1000));
  const draw = () => {
    const it = day.items[SES.i], ex = EX[it.ex], reps = repsList(it), done = SES.done[SES.i] || [];
    const total = day.items.reduce((s, x) => s + x.sets, 0), doneAll = Object.values(SES.done).reduce((s, a) => s + a.length, 0);
    const cur = reps.findIndex((_, k) => !done.includes(k));
    const missing = ex.equip.filter(e => e !== 'bodyweight' && !(D.equipment || []).includes(e));
    const lastW = SES.w[SES.i]?.[cur] ?? D.last?.[ex.id] ?? '';
    el.innerHTML = `
      <div class="ses-top"><button class="icon-btn" id="sQuit" aria-label="إنهاء"><i class="fa-solid fa-xmark"></i></button><div class="prog"><span style="width:${Math.round(doneAll / total * 100)}%"></span></div><b class="num" style="font-size:13px">${doneAll}/${total}</b></div>
      <div class="ses-ex">
        <div style="position:relative">${animBox(ex, 'big')}<div class="an-tag">${ex.muscles.map(m => `<span>${muscleName(m)}</span>`).join('')}</div></div>
        <div style="display:flex;align-items:center;gap:8px;margin-top:14px"><span class="pill pill-gray">تمرين ${SES.i + 1} من ${day.items.length}</span>${it.note ? `<span class="pill pill-orange">${esc(it.note)}</span>` : ''}</div>
        <h2 style="margin-top:8px">${esc(ex.name)}</h2>
        <div class="ses-works">${esc(ex.works)}</div>
        ${missing.length ? `<div class="note warn" style="margin-bottom:10px"><i class="fa-solid fa-triangle-exclamation"></i> يحتاج ${missing.map(e => LIB.equipment[e][0]).join('، ')} - مو موجود بقاعتك؟ <a onclick="altSheet('${ex.id}', true)" style="font-weight:800;cursor:pointer">بدّله</a></div>` : ''}
        <details class="card" style="padding:12px 14px"><summary style="cursor:pointer;font-weight:700"><i class="fa-solid fa-circle-info" style="color:var(--primary)"></i> طريقة الأداء</summary><ol class="how">${ex.how.map(h => `<li>${esc(h)}</li>`).join('')}</ol>${ex.tip ? `<div class="hint"><i class="fa-solid fa-lightbulb"></i> ${esc(ex.tip)}</div>` : ''}</details>
        <div class="sets">${reps.map((r, k) => `<div class="set ${done.includes(k) ? 'ok' : k === cur ? 'cur' : ''}"><span class="n">${done.includes(k) ? '<i class="fa-solid fa-check"></i>' : k + 1}</span>
          <span class="reps">${r} ${unit(ex)}<small>المجموعة ${k + 1} · راحة ${fmtMin(it.rest)}</small></span>
          ${ex.equip.includes('bodyweight') && ex.equip.length === 1 ? '' : `<input inputmode="decimal" placeholder="كغم" data-w="${k}" value="${esc(SES.w[SES.i]?.[k] ?? (k === cur ? lastW : ''))}">`}</div>`).join('')}</div>
        ${cur >= 0 ? `<button class="btn btn-primary btn-block done-btn shine" id="sDone" style="margin-top:14px"><i class="fa-solid fa-check"></i> أكملت المجموعة ${cur + 1}</button>`
          : `<div class="ok-note" style="margin-top:14px;text-align:center"><i class="fa-solid fa-circle-check"></i> خلّصت هذا التمرين</div>`}
        <div class="row-actions" style="margin-top:10px;gap:8px">
          <button class="btn btn-ghost" id="sPrev" ${SES.i === 0 ? 'disabled' : ''}><i class="fa-solid fa-chevron-right"></i> السابق</button>
          <button class="btn btn-ghost" id="sNext">${SES.i === day.items.length - 1 ? '<i class="fa-solid fa-flag-checkered"></i> إنهاء' : 'التالي <i class="fa-solid fa-chevron-left"></i>'}</button>
        </div>
      </div>`;
    mountAnims(el);
    el.querySelectorAll('[data-w]').forEach(i => i.onchange = () => { (SES.w[SES.i] = SES.w[SES.i] || {})[i.dataset.w] = i.value.trim(); sesSave(); });
    const db = el.querySelector('#sDone');
    if (db) db.onclick = () => {
      const w = el.querySelector(`[data-w="${cur}"]`)?.value.trim() || '';
      if (w) (SES.w[SES.i] = SES.w[SES.i] || {})[cur] = w;
      (SES.done[SES.i] = SES.done[SES.i] || []).push(cur);
      sesSave(true);
      api('/api/set', { body: { ex: ex.id, weight: w } });
      if (w) (D.last = D.last || {})[ex.id] = w;
      if (navigator.vibrate) navigator.vibrate(60);
      const exDone = (SES.done[SES.i] || []).length >= it.sets;
      const allDone = day.items.every((x, j) => (SES.done[j] || []).length >= x.sets);
      if (allDone) return finishSession();
      if (exDone) {
        notifyLocal('✅ أكملت: ' + ex.name, `${it.sets} مجموعات - عاشت إيدك`, 'exdone');
        const nxt = day.items.findIndex((x, j) => j > SES.i && (SES.done[j] || []).length < x.sets);
        SES.i = nxt >= 0 ? nxt : day.items.findIndex((x, j) => (SES.done[j] || []).length < x.sets);
        sesSave(true);
        const nex = EX[day.items[SES.i].ex];
        startRest(it.rest, `التمرين الجاي: <b>${esc(nex.name)}</b>`, 'التمرين الجاي: ' + nex.name);
      } else {
        startRest(it.rest, `المجموعة الجاية: <b>${cur + 2} من ${it.sets}</b> - ${reps[cur + 1]} ${unit(ex)}`, `المجموعة ${cur + 2} من ${ex.name}`);
      }
      draw();
    };
    el.querySelector('#sPrev').onclick = () => { if (SES.i > 0) { SES.i--; sesSave(); draw(); scrollTo(0, 0); } };
    el.querySelector('#sNext').onclick = async () => {
      if (SES.i < day.items.length - 1) { SES.i++; sesSave(); draw(); scrollTo(0, 0); return; }
      if (await confirmSheet('تنهي تمرين اليوم؟ المجموعات اللي خلّصتها تنحفظ', 'إنهاء التمرين', false)) finishSession();
    };
    el.querySelector('#sQuit').onclick = async () => {
      if (await confirmSheet('تطلع من التمرين؟ تكدر ترجع تكمّله اليوم من نفس المكان', 'اطلع', false)) { leaveSession(); go('home'); }
    };
  };
  S.drawSession = draw;
  draw();
};
function leaveSession() { wake(false); $('#sesClock').classList.add('hidden'); stopRest(true); }
// ---- مؤقت الراحة
function startRest(sec, nextHtml, nextText) {
  stopRest(false);
  const end = Date.now() + sec * 1000;
  REST = { end, total: sec, text: nextText };
  $('#restNext').innerHTML = nextHtml; $('#rest').classList.add('show');
  api('/api/rest', { body: { seconds: sec, next: nextText } }); // احتياط: إشعار من السيرفر لو التطبيق انسكّر
  const tick = () => {
    if (!REST) return;
    const left = (REST.end - Date.now()) / 1000;
    $('#restV').textContent = fmtMin(left);
    $('#restArc').style.strokeDashoffset = String(628.3 * (1 - Math.max(0, left) / REST.total));
    if (left <= 0) { const t = REST.text; stopRest(true); notifyLocal('⏱️ خلصت الراحة', t + ' - يلا 💪', 'rest'); }
  };
  tick(); REST.iv = setInterval(tick, 250);
}
function stopRest(cancelServer) {
  if (!REST) return;
  clearInterval(REST.iv); REST = null; $('#rest').classList.remove('show');
  if (cancelServer) api('/api/rest', { body: { seconds: 0 } });
}
$('#restSkip').onclick = () => stopRest(true);
$('#restPlus').onclick = () => { if (REST) { REST.end += 15000; REST.total += 15; api('/api/rest', { body: { seconds: Math.round((REST.end - Date.now()) / 1000), next: REST.text } }); } };
$('#restMinus').onclick = () => { if (REST) { REST.end -= 15000; api('/api/rest', { body: { seconds: Math.max(1, Math.round((REST.end - Date.now()) / 1000)), next: REST.text } }); } };
async function finishSession() {
  stopRest(true); leaveSession();
  const day = D.days[SES.key];
  const sets = Object.values(SES.done).reduce((s, a) => s + a.length, 0), exs = day.items.filter((x, j) => (SES.done[j] || []).length >= x.sets).length;
  const entry = { date: SES.date, day: SES.key, title: SES.title, mins: Math.round((Date.now() - SES.start) / 60000), sets, exs };
  const r = await api('/api/finish', { body: entry });
  if (!r.ok) { try { localStorage.setItem('tmr_pending_finish', JSON.stringify(entry)); } catch (e) {} }
  (D.log = D.log || []).push(r.entry || entry); cacheState();
  SES = null; sesSave();
  notifyLocal('🏆 أكملت تمرين اليوم', `${entry.title} - ${entry.exs} تمارين، ${entry.sets} مجموعة بـ ${entry.mins} دقيقة`, 'finish');
  go('finish', entry);
}
R.finish = (el, e) => {
  el.innerHTML = `<div class="finish"><div class="trophy"><i class="fa-solid fa-trophy"></i></div><h2>عاشت إيدك! 💪</h2><p style="color:var(--text2);margin-top:6px">خلّصت تمرين ${esc(e.title || '')}</p>
    <div class="stats three" style="margin-top:20px"><div class="stat"><span class="stat-v num">${e.exs || 0}</span><span class="stat-l">تمارين</span></div><div class="stat"><span class="stat-v num">${e.sets || 0}</span><span class="stat-l">مجموعة</span></div><div class="stat"><span class="stat-v num">${e.mins || 0}</span><span class="stat-l">دقيقة</span></div></div>
    <div class="card" style="margin-top:14px;text-align:right"><b><i class="fa-solid fa-utensils" style="color:var(--orange)"></i> لا تنسى وجبة بعد التمرين</b><div class="row-sub">بروتين + كاربوهيدرات خلال ساعة حتى تستفيد العضلة</div></div>
    <button class="btn btn-primary btn-block" style="margin-top:16px" onclick="go('home')"><i class="fa-solid fa-house"></i> الرئيسية</button></div>`;
  const c = document.createElement('div'); c.className = 'confetti';
  c.innerHTML = Array.from({ length: 60 }, () => `<i style="left:${Math.random() * 100}%;background:hsl(${Math.random() * 360} 85% 60%);animation-delay:${Math.random() * 0.8}s;animation-duration:${2 + Math.random() * 1.5}s"></i>`).join('');
  document.body.appendChild(c); setTimeout(() => c.remove(), 4500);
};
PAGES.finish = 'home';

// ---------------------------------------------------------------- تفاصيل تمرين
R.ex = (el, p) => {
  const ex = EX[p.id]; if (!ex) return go('library');
  const missing = ex.equip.filter(e => e !== 'bodyweight' && !(D.equipment || []).includes(e));
  const alts = altsOf(ex).slice(0, 4);
  el.innerHTML = head(esc(ex.name)) + `
    <div style="position:relative">${animBox(ex, 'big')}<div class="an-tag">${ex.muscles.map(m => `<span>${muscleName(m)}</span>`).join('')}</div></div>
    ${mediaUrl(ex) ? `<div style="margin-top:8px">${animBox(ex, '', { forceAnim: true })}</div>` : ''}
    <div class="card" style="margin-top:12px"><div class="card-title"><i class="fa-solid fa-bullseye"></i> على شنو يشتغل؟</div><div style="font-size:14px;line-height:1.9">${esc(ex.works)}</div>
      <div class="chips-wrap" style="margin-top:10px">${ex.muscles.map(m => `<span class="muscle-chip"><i class="fa-solid fa-fire"></i> ${muscleName(m)}</span>`).join('')}${(ex.secondary || []).map(m => `<span class="muscle-chip sec">${muscleName(m)}</span>`).join('')}</div></div>
    <div class="card"><div class="card-title"><i class="fa-solid fa-dumbbell"></i> الأجهزة</div><div class="chips-wrap">${ex.equip.map(e => `<span class="eq-chip ${missing.includes(e) ? 'miss' : ''}"><i class="${LIB.equipment[e][1]}"></i> ${LIB.equipment[e][0]}${missing.includes(e) ? ' (مو بقاعتك)' : ''}</span>`).join('')}</div></div>
    <div class="card"><div class="card-title"><i class="fa-solid fa-list-ol"></i> طريقة الأداء</div><ol class="how">${ex.how.map(h => `<li>${esc(h)}</li>`).join('')}</ol>${ex.tip ? `<div class="note"><i class="fa-solid fa-lightbulb"></i> ${esc(ex.tip)}</div>` : ''}</div>
    <div class="card"><div class="card-title"><i class="fa-solid fa-photo-film"></i> صورتك أو GIF مالك</div><div class="hint" style="margin:0 0 10px">صوّر نفسك أو الجهاز بقاعتك، أو ارفع GIF - يطلع بدل الرسم وقت التمرين</div>
      <div class="input-row"><label class="btn btn-soft btn-sm" style="flex:1"><input type="file" accept="image/*,video/mp4,video/webm" id="mUp" hidden><i class="fa-solid fa-upload"></i> ${mediaUrl(ex) ? 'تغيير' : 'رفع صورة/GIF'}</label>${mediaUrl(ex) ? '<button class="btn btn-red btn-sm" id="mDel"><i class="fa-solid fa-trash"></i> حذف</button>' : ''}</div></div>
    ${alts.length ? `<div class="section-head">بدائل ${missing.length ? 'متوفرة بقاعتك' : 'لنفس العضلة'}</div>${alts.map(a => `<div class="ex-row" onclick="go('ex',{id:'${a.id}'})"><div class="thumb">${animBox(a, '', { static: true })}</div><div class="body"><b>${esc(a.name)}</b><small>${a.equip.map(e => LIB.equipment[e][0]).join(' + ')}</small></div></div>`).join('')}` : ''}
    <button class="btn btn-primary btn-block" style="margin-top:14px" id="addTo"><i class="fa-solid fa-plus"></i> أضفه لبرنامجي</button>`;
  el.querySelector('#addTo').onclick = () => pickDay(ex.id);
  el.querySelector('#mUp').onchange = async e => {
    const f = e.target.files[0]; if (!f) return;
    if (f.size > 8 * 1024 * 1024) return toast('الحد 8 ميغابايت', 'err');
    const fd = new FormData(); fd.append('file', f); toast('جاري الرفع...');
    const r = await api('/api/media/' + ex.id, { body: fd });
    if (!r.ok) return toast(r.error, 'err');
    (D.media = D.media || {})[ex.id] = r.file; cacheState(); toast('انرفع ✓', 'ok'); go('ex', p, false);
  };
  const md = el.querySelector('#mDel'); if (md) md.onclick = async () => { await api('/api/media/' + ex.id + '/delete', { body: {} }); delete D.media[ex.id]; cacheState(); go('ex', p, false); };
};
function altsOf(ex) {
  return LIB.exercises.filter(a => a.id !== ex.id && haveEq(a) && (a.muscles.some(m => ex.muscles.includes(m)) || a.group === ex.group))
    .sort((a, b) => b.muscles.filter(m => ex.muscles.includes(m)).length - a.muscles.filter(m => ex.muscles.includes(m)).length);
}
/** بدّل تمرين بالبرنامج ببديل متوفر */
function altSheet(id, inSession = false) {
  const ex = EX[id], alts = altsOf(ex).slice(0, 6);
  sheet(`<h3 style="margin:0 0 12px">بدائل ${esc(ex.name)}</h3>${alts.map(a => `<div class="ex-row" data-alt="${a.id}"><div class="thumb">${animBox(a, '', { static: true })}</div><div class="body"><b>${esc(a.name)}</b><small>${a.equip.map(e => LIB.equipment[e][0]).join(' + ')}</small></div></div>`).join('') || '<div class="hint">ما لقيت بديل بأجهزتك</div>'}`, b => {
    mountAnims(b);
    b.querySelectorAll('[data-alt]').forEach(r => r.onclick = () => {
      const day = D.days[SES?.key || S.params.key]; if (!day) return;
      const it = inSession ? day.items[SES.i] : day.items.find(x => x.ex === id); if (!it) return;
      it.ex = r.dataset.alt; save('days', D.days); closeSheet(); if (inSession && S.drawSession) S.drawSession();
    });
  });
}
function pickDay(exId) {
  const keys = Object.keys(D.days || {});
  sheet(`<h3 style="margin:0 0 12px">أضف ${esc(EX[exId].name)} ليوم</h3><div class="list">${keys.map(k => `<div class="row" data-k="${k}"><span class="row-ic ic-teal"><b style="font-size:12px">${k}</b></span><span class="row-body"><span class="row-title">${esc(D.days[k].title)}</span><span class="row-sub">${D.days[k].items.length} تمارين</span></span></div>`).join('')}</div>`, b => {
    b.querySelectorAll('[data-k]').forEach(r => r.onclick = () => {
      D.days[r.dataset.k].items.push({ ex: exId, sets: 4, reps: '10', rest: D.settings?.rest || 90, note: '' });
      save('days', D.days); closeSheet();
    });
  });
}

// ---------------------------------------------------------------- مكتبة التمارين
R.library = (el, p) => {
  let grp = p.g || 'all', only = p.only ?? true, q = '';
  const draw = () => {
    const list = LIB.exercises.filter(x => (grp === 'all' || x.group === grp) && (!only || haveEq(x)) && (!q || x.name.includes(q)));
    el.innerHTML = head('التمارين', false, `<span class="pill pill-blue">${LIB.exercises.length} تمرين</span>`) + `
      <input class="input" id="lq" placeholder="ابحث عن تمرين..." value="${esc(q)}" style="margin-bottom:10px">
      <div class="chips" style="margin-bottom:10px;flex-wrap:nowrap;overflow-x:auto"><button class="chip ${grp === 'all' ? 'on' : ''}" data-g="all">الكل</button>${Object.entries(LIB.groups).map(([k, v]) => `<button class="chip ${grp === k ? 'on' : ''}" data-g="${k}">${v}</button>`).join('')}</div>
      <label style="display:flex;align-items:center;gap:10px;margin:4px 2px 12px;font-size:13.5px"><span class="switch"><input type="checkbox" id="lOnly" ${only ? 'checked' : ''}><span></span></span> المتوفر بأجهزة قاعتي بس <a onclick="go('equipment')" style="margin-right:auto;font-weight:700;color:var(--primary);cursor:pointer">أجهزتي</a></label>
      <div class="lib-grid">${list.map(x => `<div class="lib-card ${haveEq(x) ? '' : 'na'}" onclick="go('ex',{id:'${x.id}'})">${animBox(x)}<div class="info"><b>${esc(x.name)}</b><small>${x.muscles.map(muscleName).join('، ')}</small></div></div>`).join('') || '<div class="hint">ما فيه تمارين بهذا الفلتر</div>'}</div>`;
    el.querySelectorAll('[data-g]').forEach(b => b.onclick = () => { grp = b.dataset.g; draw(); mountAnims(el); });
    el.querySelector('#lOnly').onchange = e => { only = e.target.checked; draw(); mountAnims(el); };
    const qi = el.querySelector('#lq'); qi.oninput = () => { q = qi.value.trim(); const pos = qi.selectionStart; draw(); mountAnims(el); const n = el.querySelector('#lq'); n.focus(); n.setSelectionRange(pos, pos); };
  };
  draw();
};

// ---------------------------------------------------------------- البرنامج
R.program = (el) => {
  const keys = Object.keys(D.days || {});
  el.innerHTML = head('برنامجي', false) + `
    <div class="section-head">أيام الأسبوع <small style="color:var(--text3);font-weight:500">شنو تتمرن كل يوم</small></div>
    <div class="card week-grid">${WEEK_ORDER.map(d => `<div class="wrow"><b>${WD[d]}</b><select class="select" data-wd="${d}" style="height:44px"><option value="">😴 راحة</option>${keys.map(k => `<option value="${k}" ${dayOf(d) === k ? 'selected' : ''}>${k} - ${esc(D.days[k].title)}</option>`).join('')}</select></div>`).join('')}</div>
    <div class="section-head">أيام التمرين <a id="addDay">+ يوم جديد</a></div>
    ${keys.map(k => { const d = D.days[k]; return `<div class="card day-card" onclick="go('day',{key:'${k}'})" style="cursor:pointer"><div class="card-title"><span class="dk">${k}</span> ${esc(d.title)}<span class="spacer"></span><span class="pill pill-gray">${d.items.length} تمارين · ~${estMin(d)} د</span></div>
      <div class="chips-wrap">${d.items.slice(0, 8).map(it => EX[it.ex] ? `<span class="eq-chip">${esc(EX[it.ex].name)} <b class="num" style="direction:ltr">${it.sets}×${esc(it.reps)}</b></span>` : '').join('')}</div></div>`; }).join('')}`;
  el.querySelectorAll('[data-wd]').forEach(s => s.onchange = () => { const w = { ...D.week, [s.dataset.wd]: s.value }; save('week', w); });
  el.querySelector('#addDay').onclick = () => {
    const n = [1, 2, 3, 4, 5, 6, 7].find(i => !D.days['D' + i]); if (!n) return toast('الحد 7 أيام', 'err');
    D.days['D' + n] = { title: 'يوم ' + n, items: [] }; save('days', D.days, true).then(() => go('day', { key: 'D' + n }));
  };
};
R.day = (el, p) => {
  const k = p.key, d = D.days?.[k]; if (!d) return go('program');
  const draw = () => {
    el.innerHTML = head(`<span style="direction:ltr;color:var(--primary)">${k}</span> ${esc(d.title)}`) + `
      <div class="field"><label>اسم اليوم</label><input class="input" id="dT" value="${esc(d.title)}" maxlength="40"></div>
      <div class="card">${d.items.map((it, i) => { const ex = EX[it.ex]; if (!ex) return ''; const miss = !haveEq(ex); return `<div class="day-item"><div class="thumb">${animBox(ex, '', { static: true })}</div>
        <div class="nm" onclick="editItem('${k}',${i})" style="cursor:pointer">${esc(ex.name)} ${miss ? '<i class="fa-solid fa-triangle-exclamation" style="color:var(--orange)" title="مو متوفر بقاعتك"></i>' : ''}<small><b class="num" style="direction:ltr;display:inline-block">${it.sets}×${esc(it.reps)}</b> · راحة ${fmtMin(it.rest)}${it.note ? ' · ' + esc(it.note) : ''}</small></div>
        <div class="ctl"><button class="icon-btn" data-up="${i}" ${i === 0 ? 'disabled' : ''}><i class="fa-solid fa-arrow-up"></i></button><button class="icon-btn" data-ed="${i}"><i class="fa-solid fa-pen"></i></button><button class="icon-btn red" data-del="${i}"><i class="fa-solid fa-trash"></i></button></div></div>`; }).join('') || '<div class="hint">ما فيه تمارين - أضف من تحت</div>'}</div>
      <button class="btn btn-primary btn-block" id="dAdd"><i class="fa-solid fa-plus"></i> أضف تمرين</button>
      <div class="row-actions" style="margin-top:10px;gap:8px"><button class="btn btn-ghost" id="dStart"><i class="fa-solid fa-play"></i> ابدأه هسه</button><button class="btn btn-red" id="dDel"><i class="fa-solid fa-trash"></i> حذف اليوم</button></div>`;
    mountAnims(el);
    el.querySelector('#dT').onchange = e => { d.title = e.target.value.trim() || k; save('days', D.days); };
    el.querySelectorAll('[data-up]').forEach(b => b.onclick = () => { const i = +b.dataset.up; [d.items[i - 1], d.items[i]] = [d.items[i], d.items[i - 1]]; save('days', D.days, true); draw(); });
    el.querySelectorAll('[data-ed]').forEach(b => b.onclick = () => editItem(k, +b.dataset.ed));
    el.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { if (await confirmSheet('تحذف ' + EX[d.items[+b.dataset.del].ex].name + '؟', 'حذف')) { d.items.splice(+b.dataset.del, 1); save('days', D.days, true); draw(); } });
    el.querySelector('#dAdd').onclick = () => pickExercise(k, draw);
    el.querySelector('#dStart').onclick = () => startSession(k);
    el.querySelector('#dDel').onclick = async () => {
      if (!await confirmSheet(`تحذف ${k} (${esc(d.title)})؟`, 'حذف')) return;
      delete D.days[k]; const w = { ...D.week }; for (const x in w) if (w[x] === k) w[x] = '';
      await save('days', D.days, true); await save('week', w, true); go('program');
    };
  };
  R.day.redraw = draw;
  draw();
};
function editItem(k, i) {
  const it = D.days[k].items[i], ex = EX[it.ex];
  sheet(`<h3 style="margin:0 0 14px">${esc(ex.name)}</h3>
    <div class="input-row"><div class="field" style="flex:1"><label>المجموعات</label><input class="input num" id="eS" type="number" min="1" max="10" value="${it.sets}"></div>
      <div class="field" style="flex:1.4"><label>العدّات (مثل 12-10-8-6)</label><input class="input num" id="eR" dir="ltr" value="${esc(it.reps)}"></div></div>
    <div class="field"><label>الراحة بين المجموعات</label><div class="seg" id="eRest">${[30, 45, 60, 90, 120, 180].map(s => `<button data-s="${s}" class="${it.rest === s ? 'on' : ''}"><b>${s < 60 ? s + 'ث' : fmtMin(s)}</b></button>`).join('')}</div></div>
    <div class="field"><label>ملاحظة</label><input class="input" id="eN" value="${esc(it.note)}" placeholder="مثلاً: سوبر سِت ويا التمرين الجاي"></div>
    <div class="row-actions" style="gap:8px"><button class="btn btn-ghost" id="eAlt"><i class="fa-solid fa-shuffle"></i> بديل</button><button class="btn btn-primary" id="eOk" style="flex:2">حفظ</button></div>`, b => {
    let rest = it.rest;
    b.querySelectorAll('[data-s]').forEach(x => x.onclick = () => { rest = +x.dataset.s; b.querySelectorAll('[data-s]').forEach(y => y.classList.toggle('on', y === x)); });
    b.querySelector('#eAlt').onclick = () => { closeSheet(); S.params.key = k; setTimeout(() => altSheet(it.ex), 300); };
    b.querySelector('#eOk').onclick = () => {
      Object.assign(it, { sets: Math.max(1, Math.min(10, +b.querySelector('#eS').value || 4)), reps: b.querySelector('#eR').value.trim() || '10', rest, note: b.querySelector('#eN').value.trim() });
      save('days', D.days); closeSheet(); if (S.page === 'day' && R.day.redraw) R.day.redraw();
    };
  });
}
function pickExercise(k, after) {
  let grp = 'all';
  const draw = b => {
    const list = LIB.exercises.filter(x => grp === 'all' || x.group === grp).sort((a, c) => haveEq(c) - haveEq(a));
    b.innerHTML = `<h3 style="margin:0 0 10px">اختار تمرين</h3><div class="chips" style="flex-wrap:nowrap;overflow-x:auto;margin-bottom:10px"><button class="chip ${grp === 'all' ? 'on' : ''}" data-g="all">الكل</button>${Object.entries(LIB.groups).map(([g, v]) => `<button class="chip ${grp === g ? 'on' : ''}" data-g="${g}">${v}</button>`).join('')}</div>
      <div style="max-height:56vh;overflow:auto">${list.map(x => `<div class="ex-row ${haveEq(x) ? '' : 'done'}" data-x="${x.id}" style="box-shadow:none"><div class="thumb">${animBox(x, '', { static: true })}</div><div class="body"><b>${esc(x.name)}</b><small>${haveEq(x) ? x.muscles.map(muscleName).join('، ') : '⚠️ يحتاج ' + x.equip.filter(e => e !== 'bodyweight' && !D.equipment.includes(e)).map(e => LIB.equipment[e][0]).join('، ')}</small></div></div>`).join('')}</div>`;
    mountAnims(b);
    b.querySelectorAll('[data-g]').forEach(c => c.onclick = () => { grp = c.dataset.g; draw(b); });
    b.querySelectorAll('[data-x]').forEach(r => r.onclick = () => { D.days[k].items.push({ ex: r.dataset.x, sets: 4, reps: '10', rest: D.settings?.rest || 90, note: '' }); save('days', D.days, true); closeSheet(); after(); toast('انضاف ✓', 'ok'); });
  };
  sheet('', draw);
}

// ---------------------------------------------------------------- الوجبات
R.meals = (el) => {
  const nm = nextMeal();
  el.innerHTML = head('وجباتي', false, `<button class="btn btn-primary btn-sm" id="mAdd"><i class="fa-solid fa-plus"></i> وجبة</button>`) + `
    <div class="card" style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-orange"><i class="fa-solid fa-bell"></i></span><div style="flex:1;min-width:0"><b>تنبيه بوقت كل وجبة</b><div class="row-sub">${pushOn() ? 'يوصلك إشعار حتى لو التطبيق مسكّر' : 'فعّل الإشعارات من «حسابي» حتى يوصلك والتطبيق مسكّر'}</div></div><label class="switch"><input type="checkbox" id="mOn" ${D.settings.meals_on ? 'checked' : ''}><span></span></label></div>
    <div class="card" style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-cyan"><i class="fa-solid fa-glass-water"></i></span><div style="flex:1;min-width:0"><b>تذكير شرب الماي</b><div class="row-sub">كل ساعتين من 9 الصبح لـ 11 بالليل</div></div><label class="switch"><input type="checkbox" id="wOn" ${D.settings.water_on ? 'checked' : ''}><span></span></label></div>
    <div class="section-head">جدول اليوم</div>
    ${(D.meals || []).map((m, i) => `<div class="meal ${nm && nm.id === m.id ? 'next' : ''}" data-i="${i}" style="${m.on ? '' : 'opacity:.5'}"><div class="tm">${m.time}<small>${+m.time.slice(0, 2) < 12 ? 'صباحاً' : 'مساءً'}</small></div>
      <div class="bd"><b>${esc(m.name)}</b>${nm && nm.id === m.id ? ' <span class="pill pill-orange">الجاية</span>' : ''}<small>${esc(m.note)}</small></div><label class="switch" onclick="event.stopPropagation()"><input type="checkbox" data-on="${i}" ${m.on ? 'checked' : ''}><span></span></label></div>`).join('') || '<div class="card">ما فيه وجبات - أضف وجبة</div>'}`;
  el.querySelector('#mOn').onchange = e => save('settings', { ...D.settings, meals_on: e.target.checked });
  el.querySelector('#wOn').onchange = e => save('settings', { ...D.settings, water_on: e.target.checked });
  el.querySelectorAll('[data-on]').forEach(c => c.onchange = () => { D.meals[+c.dataset.on].on = c.checked; save('meals', D.meals, true).then(() => go('meals', {}, false)); });
  el.querySelectorAll('[data-i]').forEach(r => r.onclick = () => editMeal(+r.dataset.i));
  el.querySelector('#mAdd').onclick = () => editMeal(-1);
};
function editMeal(i) {
  const m = i >= 0 ? D.meals[i] : { name: '', time: '12:00', note: '', on: true };
  sheet(`<h3 style="margin:0 0 14px">${i >= 0 ? 'تعديل الوجبة' : 'وجبة جديدة'}</h3>
    <div class="input-row"><div class="field" style="flex:1.3"><label>الاسم</label><input class="input" id="mN" value="${esc(m.name)}" placeholder="الغداء"></div><div class="field" style="flex:1"><label>الوقت</label><input class="input num" id="mT" type="time" value="${m.time}"></div></div>
    <div class="field"><label>شنو تاكل؟</label><input class="input" id="mD" value="${esc(m.note)}" placeholder="رز + دجاج + سلطة"></div>
    <div class="row-actions" style="gap:8px">${i >= 0 ? '<button class="btn btn-red" id="mX"><i class="fa-solid fa-trash"></i></button>' : ''}<button class="btn btn-primary" id="mOk" style="flex:2">حفظ</button></div>`, b => {
    b.querySelector('#mOk').onclick = () => {
      const v = { id: m.id, name: b.querySelector('#mN').value.trim() || 'وجبة', time: b.querySelector('#mT').value || '12:00', note: b.querySelector('#mD').value.trim(), on: m.on };
      const list = [...D.meals]; if (i >= 0) list[i] = v; else list.push(v);
      save('meals', list).then(() => { closeSheet(); go('meals', {}, false); });
    };
    const x = b.querySelector('#mX'); if (x) x.onclick = () => { const list = D.meals.filter((_, j) => j !== i); save('meals', list).then(() => { closeSheet(); go('meals', {}, false); }); };
  });
}

// ---------------------------------------------------------------- حسابي
R.me = (el) => {
  const pr = D.profile || {}, st = D.settings || {}, logs = D.log || [];
  const month = todayStr().slice(0, 7), mCount = logs.filter(l => l.date.startsWith(month)).length;
  const ui = (() => { try { return JSON.parse(localStorage.getItem('tmr_ui') || '{}'); } catch (e) { return {}; } })();
  el.innerHTML = head('حسابي', false) + `
    <div class="card" style="display:flex;align-items:center;gap:12px"><div class="avatar" style="width:52px;height:52px;border-radius:50%;background:var(--btn);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:20px">${esc((ME.name || '؟')[0])}</div>
      <div style="flex:1"><b style="font-size:17px">${esc(ME.name)}</b><div class="row-sub" dir="ltr" style="text-align:right">@${esc(ME.username)}</div></div></div>
    <div class="stats three" style="margin-top:12px"><div class="stat"><span class="stat-v num">${logs.length}</span><span class="stat-l">تمرين مكتمل</span></div><div class="stat"><span class="stat-v num">${mCount}</span><span class="stat-l">هذا الشهر</span></div><div class="stat"><span class="stat-v num">${streak()}</span><span class="stat-l">أيام متتالية</span></div></div>
    <div class="section-head">معلوماتي</div>
    <div class="card"><div class="input-row"><div class="field" style="flex:1"><label>الوزن (كغم)</label><input class="input num" id="pW" inputmode="decimal" value="${esc(pr.weight)}"></div><div class="field" style="flex:1"><label>الطول (سم)</label><input class="input num" id="pH" inputmode="numeric" value="${esc(pr.height)}"></div><div class="field" style="flex:1"><label>العمر</label><input class="input num" id="pA" inputmode="numeric" value="${esc(pr.age)}"></div></div>
      <div class="seg" id="pG">${Object.entries(GOALS).map(([k, v]) => `<button data-g="${k}" class="${(pr.goal || 'build') === k ? 'on' : ''}"><b>${v}</b></button>`).join('')}</div>
      ${bmi(pr) ? `<div class="hint" style="margin-top:8px">مؤشر كتلة الجسم: <b class="num">${bmi(pr)}</b></div>` : ''}</div>
    <div class="section-head">الإعدادات</div>
    <div class="list">
      <div class="row" onclick="go('equipment')"><span class="row-ic ic-purple"><i class="fa-solid fa-dumbbell"></i></span><span class="row-body"><span class="row-title">أجهزة قاعتي</span><span class="row-sub">${(D.equipment || []).length - 1} أجهزة - نعرض التمارين المتوفرة بس</span></span><span class="row-end"><span class="chev"><i class="fa-solid fa-chevron-left"></i></span></span></div>
      <div class="row" onclick="go('history')"><span class="row-ic ic-green"><i class="fa-solid fa-clock-rotate-left"></i></span><span class="row-body"><span class="row-title">سجل التمارين</span><span class="row-sub">كل التمارين اللي خلّصتها</span></span><span class="row-end"><span class="chev"><i class="fa-solid fa-chevron-left"></i></span></span></div>
    </div>
    <div class="card" style="margin-top:12px">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px"><span class="row-ic ic-orange"><i class="fa-solid fa-bell"></i></span><div style="flex:1;min-width:0"><b>الإشعارات</b><div class="row-sub">${pushOn() ? '✅ مفعّلة على هذا الجهاز' : notifOK() ? 'محلية بس - فعّل إشعارات السيرفر' : 'مو مفعّلة'}</div></div>
        ${pushOn() ? '<button class="btn btn-soft btn-sm" id="nTest">تجربة</button>' : '<button class="btn btn-primary btn-sm" id="nOn">تفعيل</button>'}</div>
      ${/iphone|ipad/i.test(navigator.userAgent) && !matchMedia('(display-mode: standalone)').matches ? '<div class="note warn" style="margin-bottom:10px">بالآيفون: ثبّت التطبيق أول (زر المشاركة ⬆️ ← إضافة إلى الشاشة الرئيسية) حتى تشتغل الإشعارات</div>' : ''}
      <label style="display:flex;align-items:center;gap:10px;padding:8px 0"><span style="flex:1">صوت نهاية الراحة</span><span class="switch"><input type="checkbox" id="sSnd" ${st.sound ? 'checked' : ''}><span></span></span></label>
      <label style="display:flex;align-items:center;gap:10px;padding:8px 0"><span style="flex:1">اهتزاز</span><span class="switch"><input type="checkbox" id="sVib" ${st.vibrate ? 'checked' : ''}><span></span></span></label>
      <div class="field" style="margin-top:6px"><label>الراحة الافتراضية للتمارين الجديدة</label><div class="seg" id="sRest">${[45, 60, 90, 120].map(s => `<button data-s="${s}" class="${st.rest === s ? 'on' : ''}"><b>${s < 60 ? s + 'ث' : fmtMin(s)}</b></button>`).join('')}</div></div>
      <div class="field"><label>المظهر</label><div class="seg" id="sMode">${[['light', 'نهاري'], ['dark', 'داكن'], ['auto', 'تلقائي']].map(([k, v]) => `<button data-m="${k}" class="${(ui.mode || 'light') === k ? 'on' : ''}"><b>${v}</b></button>`).join('')}</div></div>
    </div>
    <div class="list" style="margin-top:12px">
      <div class="row" id="instBtn"><span class="row-ic ic-teal"><i class="fa-solid fa-mobile-screen"></i></span><span class="row-body"><span class="row-title">ثبّت التطبيق على جوالك</span><span class="row-sub">يفتح مثل التطبيقات ويشتغل بدون إنترنت</span></span></div>
      <div class="row" id="pwBtn"><span class="row-ic ic-gray"><i class="fa-solid fa-key"></i></span><span class="row-body"><span class="row-title">تغيير كلمة السر</span></span></div>
    </div>
    <button class="btn btn-red btn-block" style="margin-top:16px" id="lo"><i class="fa-solid fa-right-from-bracket"></i> تسجيل خروج</button>`;
  const prof = () => save('profile', { weight: $('#pW').value.trim(), height: $('#pH').value.trim(), age: $('#pA').value.trim(), goal: $('#pG .on')?.dataset.g || 'build' });
  ['#pW', '#pH', '#pA'].forEach(s => el.querySelector(s).onchange = prof);
  el.querySelectorAll('#pG [data-g]').forEach(b => b.onclick = () => { el.querySelectorAll('#pG button').forEach(x => x.classList.toggle('on', x === b)); prof(); });
  el.querySelector('#sSnd').onchange = e => { save('settings', { ...D.settings, sound: e.target.checked }); if (e.target.checked) beep(); };
  el.querySelector('#sVib').onchange = e => { save('settings', { ...D.settings, vibrate: e.target.checked }); if (e.target.checked && navigator.vibrate) navigator.vibrate(200); };
  el.querySelectorAll('#sRest [data-s]').forEach(b => b.onclick = () => { el.querySelectorAll('#sRest button').forEach(x => x.classList.toggle('on', x === b)); save('settings', { ...D.settings, rest: +b.dataset.s }); });
  el.querySelectorAll('#sMode [data-m]').forEach(b => b.onclick = () => { el.querySelectorAll('#sMode button').forEach(x => x.classList.toggle('on', x === b)); setMode(b.dataset.m); });
  const no = el.querySelector('#nOn'); if (no) no.onclick = async () => { if (await enablePush()) go('me', {}, false); };
  const nt = el.querySelector('#nTest'); if (nt) nt.onclick = async () => { const r = await api('/api/push/test', { body: {} }); toast(r.sent ? 'انرسل - راح يوصلك خلال ثواني' : 'ما انرسل - جرب تفعّل مرة ثانية', r.sent ? 'ok' : 'err'); };
  el.querySelector('#instBtn').onclick = () => {
    if (matchMedia('(display-mode: standalone)').matches) return toast('التطبيق مثبّت ✓', 'ok');
    if (S.installPrompt) { S.installPrompt.prompt(); return; }
    toast(/iphone|ipad/i.test(navigator.userAgent) ? 'من سفاري: زر المشاركة ⬆️ ← إضافة إلى الشاشة الرئيسية' : 'من قائمة المتصفح ⋮ اختار «تثبيت التطبيق»');
  };
  el.querySelector('#pwBtn').onclick = () => sheet(`<h3 style="margin:0 0 14px">تغيير كلمة السر</h3><div class="field"><label>الحالية</label><input class="input" type="password" id="o1" dir="ltr"></div><div class="field"><label>الجديدة</label><input class="input" type="password" id="o2" dir="ltr"></div><button class="btn btn-primary btn-block" id="oOk">حفظ</button>`, b => {
    b.querySelector('#oOk').onclick = async () => { const r = await api('/api/password', { body: { old: b.querySelector('#o1').value, new: b.querySelector('#o2').value } }); toast(r.ok ? 'تغيّرت ✓' : r.error, r.ok ? 'ok' : 'err'); if (r.ok) closeSheet(); };
  });
  el.querySelector('#lo').onclick = async () => { if (!await confirmSheet('تسجّل خروج؟', 'خروج')) return; await api('/api/logout', { body: {} }); ME = null; D = null; localStorage.removeItem('tmr_state'); localStorage.removeItem('tmr_ses'); SES = null; render(); };
};
const bmi = p => { const w = parseFloat(p.weight), h = parseFloat(p.height) / 100; return w > 0 && h > 0 ? (w / (h * h)).toFixed(1) : ''; };
function setMode(m) { try { localStorage.setItem('tmr_ui', JSON.stringify({ mode: m })); } catch (e) {} const d = m === 'dark' || (m === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches); document.documentElement.setAttribute('data-mode', d ? 'dark' : 'light'); }
R.equipment = (el) => {
  const eq = new Set(D.equipment || []);
  el.innerHTML = head('أجهزة قاعتي') + `<div class="note" style="margin-bottom:12px">علّم الأجهزة الموجودة بقاعتك - نعرضلك التمارين اللي تكدر تسويها، ونقترح بدائل للتمارين اللي تحتاج أجهزة مو موجودة</div>
    <div class="list">${Object.entries(LIB.equipment).map(([k, [n, ic]]) => `<label class="row"><span class="row-ic ic-teal"><i class="${ic}"></i></span><span class="row-body"><span class="row-title">${n}</span><span class="row-sub">${LIB.exercises.filter(x => x.equip.includes(k)).length} تمرين يستخدمه</span></span><span class="row-end"><span class="switch"><input type="checkbox" data-e="${k}" ${eq.has(k) || k === 'bodyweight' ? 'checked' : ''} ${k === 'bodyweight' ? 'disabled' : ''}><span></span></span></span></label>`).join('')}</div>
    <div class="hint" style="text-align:center;margin-top:10px" id="eqCount"></div>`;
  const cnt = () => { el.querySelector('#eqCount').textContent = `تكدر تسوي ${LIB.exercises.filter(haveEq).length} تمرين من ${LIB.exercises.length} بأجهزتك`; };
  cnt();
  el.querySelectorAll('[data-e]').forEach(c => c.onchange = () => { c.checked ? eq.add(c.dataset.e) : eq.delete(c.dataset.e); save('equipment', [...eq], true).then(cnt); D.equipment = [...eq]; cnt(); });
};
R.history = (el) => {
  const logs = [...(D.log || [])].reverse();
  el.innerHTML = head('سجل التمارين') + (logs.length ? `<div class="card">${logs.map(l => `<div class="hist-row"><span class="row-ic ic-green" style="width:38px;height:38px"><i class="fa-solid fa-check"></i></span><b>${esc(l.title || l.day)}<div class="row-sub">${l.date} · ${WD[new Date(l.date + 'T12:00').getDay()]}</div></b><span class="num" style="font-size:12px;color:var(--text3);text-align:left">${l.exs} تمارين<br>${l.mins} دقيقة</span></div>`).join('')}</div>`
    : `<div class="card" style="text-align:center;padding:30px"><i class="fa-solid fa-dumbbell" style="font-size:40px;color:var(--primary)"></i><p style="margin-top:10px">لسه ما خلّصت أي تمرين - يلا ابدأ اليوم!</p></div>`);
};

// ---------------------------------------------------------------- التشغيل
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); S.installPrompt = e; });
async function render() {
  document.body.classList.toggle('no-user', !ME);
  if (!ME) { view.innerHTML = ''; const w = document.createElement('div'); w.className = 'page'; view.appendChild(w); R.auth(w); return; }
  const h = (location.hash || '').slice(1);
  if (SES && SES.date === todayStr() && h === 'session') return go('session', {}, false);
  go(PAGES[h] && !['ex', 'day', 'session', 'finish'].includes(h) ? h : 'home', {}, false);
  history.replaceState({ page: S.page, params: {} }, '', '#' + S.page);
}
(async function boot() {
  try {
    const r = await fetch('/assets/exercises.json?v=__V__'); LIB = await r.json();
  } catch (e) { view.innerHTML = '<div class="card">تعذر تحميل التمارين - تأكد من الإنترنت</div>'; return; }
  LIB.exercises.forEach(x => { EX[x.id] = x; });
  if (!ME) { try { const c = JSON.parse(localStorage.getItem('tmr_state') || 'null'); if (c && !navigator.onLine) { ME = c.user; D = c.data; } } catch (e) {} }
  if (ME && D) {
    cacheState();
    if (!SES && D.session && D.session.date === todayStr()) { SES = D.session; sesSave(); } // كمّل من جهاز ثاني
    if (SES && SES.date !== todayStr()) { SES = null; sesSave(); }
    const pf = localStorage.getItem('tmr_pending_finish');
    if (pf) { const r = await api('/api/finish', { body: JSON.parse(pf) }); if (r.ok) localStorage.removeItem('tmr_pending_finish'); }
  }
  render();
  mealWatch();
})();
