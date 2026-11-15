'use strict';
/* واصل - تطبيق الصفحة الواحدة. المستخدم يبقى على /app، والأدمن لكل صفحة رابط /app/<القسم>. */

const B = window.BOOT;
let ME = B.me;
let SET = B.settings;
const ADMIN = !!B.is_admin;
const view = document.getElementById('view');
const S = { page: null, params: {}, timers: [], lastEvent: 0, installPrompt: null, accounts: null };

// ================================================================ أدوات
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = n => Number(n || 0).toLocaleString('en-US');
const iqd = n => `<span class="num">${fmt(n)}</span> د.ع`;
const $ = s => view.querySelector(s);
const $$ = s => [...view.querySelectorAll(s)];

async function api(url, opt = {}) {
  const init = { method: opt.method || (opt.body !== undefined ? 'POST' : 'GET'), headers: {} };
  if (opt.body instanceof FormData) init.body = opt.body;
  else if (opt.body !== undefined) { init.body = JSON.stringify(opt.body); init.headers['Content-Type'] = 'application/json'; }
  try {
    const r = await fetch(url, init);
    if (r.status === 401) { location.href = '/'; return { ok: false }; }
    const ct = r.headers.get('content-type') || '';
    if (!ct.includes('json')) return { ok: r.ok, status: r.status };
    const d = await r.json();
    if (Array.isArray(d)) return d;
    if (d.ok === undefined) d.ok = r.ok;
    return d;
  } catch (e) { return { ok: false, error: 'تعذر الاتصال - تأكد من الإنترنت' }; }
}

let toastT;
function toast(msg, kind = '') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast show ' + kind;
  clearTimeout(toastT); toastT = setTimeout(() => t.className = 'toast ' + kind, 2800);
}
function busy(btn, on) { if (btn) { btn.classList.toggle('loading', on); btn.disabled = on; } }
/** زر يحتاج ضغطتين للتأكيد (بدل نافذة منبثقة) */
function confirmTap(btn, fn, label = 'اضغط مرة ثانية للتأكيد') {
  if (btn.dataset.armed) { delete btn.dataset.armed; btn.innerHTML = btn.dataset.orig; fn(); return; }
  btn.dataset.armed = 1; btn.dataset.orig = btn.innerHTML; btn.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> ${label}`;
  setTimeout(() => { if (btn.dataset.armed) { delete btn.dataset.armed; btn.innerHTML = btn.dataset.orig; } }, 3500);
}
function every(ms, fn) { const t = setInterval(fn, ms); S.timers.push(t); return t; }
function head(title, back = true, extra = '') {
  return `<div class="page-head">${back ? `<button class="back" onclick="goBack()"><i class="fa-solid fa-arrow-right"></i></button>` : ''}<h2>${title}</h2>${extra}</div>`;
}
function empty(icon, text, btn = '') { return `<div class="empty"><i class="${icon}"></i>${text}${btn ? `<div style="margin-top:12px">${btn}</div>` : ''}</div>`; }
function rowItem({ icon, ic = 'ic-blue', title, sub = '', end = '<span class="chev"><i class="fa-solid fa-chevron-left"></i></span>', onclick = '', img = '' }) {
  return `<div class="row" role="button" tabindex="0" ${onclick ? `onclick="${onclick}"` : ''}><span class="row-ic ${ic}">${img ? `<img src="${esc(img)}" alt="">` : `<i class="${icon}"></i>`}</span>
    <span class="row-body"><span class="row-title">${title}</span>${sub ? `<span class="row-sub">${sub}</span>` : ''}</span><span class="row-end">${end}</span></div>`;
}
function fileToDataUrl(file) { return new Promise((res, rej) => { const r = new FileReader(); r.onload = () => res(r.result); r.onerror = rej; r.readAsDataURL(file); }); }
function copy(text) { navigator.clipboard?.writeText(text).then(() => toast('تم النسخ', 'ok')).catch(() => toast(text)); }
function planText() {
  if (ME.plan_active) return `<span class="pill pill-green"><i class="fa-solid fa-crown"></i> مشترك · ${ME.plan_days_left === null ? 'دائم' : ME.plan_days_left + ' يوم'}</span>`;
  if (ME.trial_active) return `<span class="pill pill-orange"><i class="fa-solid fa-gift"></i> تجربة · ${ME.trial_days_left} يوم</span>`;
  if (SET.message_price > 0) return `<span class="pill pill-blue"><i class="fa-solid fa-coins"></i> الدفع لكل رسالة</span>`;
  return `<span class="pill pill-red"><i class="fa-solid fa-lock"></i> غير مشترك</span>`;
}
async function refreshMe() {
  const r = await api('/api/me');
  if (r.ok) { ME = r.me; SET = r.settings; document.getElementById('balChip').textContent = fmt(ME.balance); }
}

// ================================================================ التنقل
const PAGES = {
  home: { nav: 'home', slug: '' }, campaign: { nav: 'campaign', slug: 'campaign' }, accounts: { nav: 'accounts', slug: 'numbers' }, acc: { nav: 'accounts', slug: 'number' },
  wallet: { nav: 'wallet', slug: 'packages' }, pay: { nav: 'wallet', slug: 'pay' }, account: { nav: 'account', slug: 'me' }, notifications: { nav: 'account', slug: 'notifications' },
  subscribers: { nav: 'account', slug: 'clients' }, apikeys: { nav: 'account', slug: 'api' }, tx: { nav: 'wallet', slug: 'transactions' }, profile: { nav: 'account', slug: 'profile' },
  admin: { nav: 'account', admin: true, slug: 'dashboard' }, users: { nav: 'account', admin: true, slug: 'users' }, recharges: { nav: 'account', admin: true, slug: 'recharges' },
  payments: { nav: 'account', admin: true, slug: 'payments' }, plansAdmin: { nav: 'account', admin: true, slug: 'plans' }, settings: { nav: 'account', admin: true, slug: 'settings' },
  security: { nav: 'account', admin: true, slug: 'security' }, backup: { nav: 'account', admin: true, slug: 'backup' }, announce: { nav: 'account', admin: true, slug: 'announce' },
};
const SLUG2PAGE = Object.fromEntries(Object.entries(PAGES).map(([k, v]) => [v.slug ?? k, k]));

function go(page, params = {}, push = true) {
  if (!PAGES[page] || (PAGES[page].admin && !ADMIN)) page = 'home';
  S.timers.forEach(clearInterval); S.timers = [];
  S.page = page; S.params = params;
  document.querySelectorAll('#bnav [data-p]').forEach(b => b.classList.toggle('on', b.dataset.p === PAGES[page].nav));
  if (push) {
    const slug = PAGES[page].slug ?? page;
    const qs = params.id ? '?id=' + encodeURIComponent(params.id) : '';
    // المستخدم العادي: الرابط يبقى /app دائماً. الأدمن: كل صفحة برابطها (/users، /settings...)
    const url = ADMIN ? (slug ? '/' + slug : '/app') + qs : '/app';
    history.pushState({ page, params }, '', url);
  }
  view.innerHTML = '';
  window.scrollTo(0, 0);
  const wrap = document.createElement('div');
  wrap.className = 'page';
  view.appendChild(wrap);
  Promise.resolve(R[page](wrap, params)).catch(e => { console.error(e); wrap.innerHTML = empty('fa-solid fa-bug', 'صار خطأ بعرض الصفحة'); });
}
function goBack() { if (history.state && history.length > 1) history.back(); else go('home'); }
window.addEventListener('popstate', e => { const st = e.state || { page: 'home', params: {} }; go(st.page, st.params || {}, false); });
document.getElementById('bnav').addEventListener('click', e => { const b = e.target.closest('[data-p]'); if (b) go(b.dataset.p); });
window.addEventListener('scroll', () => document.getElementById('topbar').classList.toggle('scrolled', scrollY > 4), { passive: true });
const isStandalone = () => matchMedia('(display-mode: standalone)').matches || navigator.standalone;
const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
function openInstall(force = false) {
  if (isStandalone()) { toast('التطبيق مثبّت أصلاً', 'ok'); return; }
  if (!force) { try { if (Date.now() - (+localStorage.getItem('pwaLater') || 0) < 3 * 86400000) return; } catch (e) {} }
  document.getElementById('pwaText').textContent = isIOS ? 'من سفاري: اضغط زر المشاركة ⬆️ ثم «إضافة إلى الشاشة الرئيسية»' : (S.installPrompt ? 'افتحه بلمسة من شاشتك الرئيسية مثل أي تطبيق' : 'من قائمة المتصفح ⋮ اختر «تثبيت التطبيق» أو «إضافة للشاشة الرئيسية»');
  document.getElementById('pwaInstall').classList.toggle('hidden', !S.installPrompt);
  document.getElementById('pwaBg').classList.add('show'); document.getElementById('pwaSheet').classList.add('show');
}
function closeInstall(later) {
  document.getElementById('pwaBg').classList.remove('show'); document.getElementById('pwaSheet').classList.remove('show');
  if (later) { try { localStorage.setItem('pwaLater', Date.now()); } catch (e) {} }
}
async function installApp() { if (S.installPrompt) { S.installPrompt.prompt(); const c = await S.installPrompt.userChoice; S.installPrompt = null; closeInstall(c.outcome !== 'accepted'); if (c.outcome === 'accepted') toast('تم تثبيت التطبيق', 'ok'); } else openInstall(true); }
document.getElementById('pwaInstall').onclick = installApp;
window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); S.installPrompt = e; setTimeout(() => openInstall(), 2500); });
if (isIOS && !isStandalone()) setTimeout(() => openInstall(), 4000);
// سحب النافذة للأسفل يغلقها
(() => { const sh = document.getElementById('pwaSheet'); let y0 = null;
  sh.addEventListener('touchstart', e => { y0 = e.touches[0].clientY; }, { passive: true });
  sh.addEventListener('touchend', e => { if (y0 !== null && e.changedTouches[0].clientY - y0 > 70) closeInstall(true); y0 = null; }, { passive: true }); })();

// ================================================================ الصفحات
const R = {};

// ---------------------------------------------------------------- الرئيسية
R.home = async (el) => {
  const first = (ME.name || '؟').trim()[0] || '؟';
  const planLine = ME.plan_active ? `<i class="fa-solid fa-gem"></i> ${esc(ME.plan_name || 'مشترك')} · ${ME.plan_days_left === null ? 'دائم' : ME.plan_days_left + ' يوم'}`
    : ME.trial_active ? `<i class="fa-solid fa-gift"></i> تجربة مجانية · ${ME.trial_days_left} يوم` : SET.message_price > 0 ? '<i class="fa-solid fa-coins"></i> الدفع حسب الرسائل' : '<i class="fa-solid fa-lock"></i> بدون باقة';
  el.innerHTML = `
    <div class="hero fade-in">
      <div class="greet">
        <div class="avatar">${esc(first)}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:12.5px;opacity:.85">أهلاً بعودتك 👋</div>
          <div style="font-weight:800;font-size:19px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(ME.name || 'صديقنا')}</div>
        </div>
        <span class="pill pill-white" onclick="go('wallet')" style="cursor:pointer">${planLine}</span>
      </div>
      <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-top:18px">
        <div><div class="hero-label">رصيدك</div><div class="hero-amount"><span class="num">${fmt(ME.balance)}</span> <span style="font-size:15px;font-weight:600">د.ع</span></div></div>
        <button class="btn btn-white btn-sm" onclick="go('wallet')"><i class="fa-solid fa-plus"></i> شحن</button>
      </div>
      <div class="hero-stats" id="heroStats"><div><b>—</b><span>أرقام متصلة</span></div><div><b>—</b><span>رسائل مرسلة</span></div><div><b>—</b><span>نسبة النجاح</span></div></div>
    </div>
    <div class="section-head">ابدأ من هنا</div>
    <div class="bento stagger">
      <div class="tile tile-teal wide" onclick="go('campaign')"><span class="t-ic"><i class="fa-solid fa-paper-plane"></i></span><div style="flex:1"><b style="margin:0">حملة جديدة</b><small>أرسل رسالتك لآلاف الأرقام بضغطة</small></div><i class="fa-solid fa-arrow-left"></i><i class="fa-solid fa-paper-plane big-ic"></i></div>
      <div class="tile tile-emerald" onclick="go('accounts')"><span class="t-ic"><i class="fa-brands fa-whatsapp"></i></span><div><b>أرقامي</b><small>ربط بالباركود أو الرمز</small></div><i class="fa-brands fa-whatsapp big-ic"></i></div>
      <div class="tile tile-amber" onclick="go('wallet')"><span class="t-ic"><i class="fa-solid fa-gem"></i></span><div><b>الباقات</b><small>اشترك أو اشحن رصيد</small></div><i class="fa-solid fa-gem big-ic"></i></div>
      <div class="tile tile-violet" onclick="go('subscribers')"><span class="t-ic"><i class="fa-solid fa-user-clock"></i></span><div><b>مشتركيني</b><small>تذكير تلقائي بالانتهاء</small></div><i class="fa-solid fa-user-clock big-ic"></i></div>
      <div class="tile tile-sky" onclick="go('apikeys')"><span class="t-ic"><i class="fa-solid fa-code"></i></span><div><b>ربط API</b><small>رسائل من موقعك</small></div><i class="fa-solid fa-code big-ic"></i></div>
      ${ADMIN ? `<div class="tile tile-coral wide" onclick="go('admin')"><span class="t-ic"><i class="fa-solid fa-gauge-high"></i></span><div style="flex:1"><b style="margin:0">لوحة الإدارة</b><small>المستخدمين، الشحن، الباقات والأمان</small></div><i class="fa-solid fa-arrow-left"></i><i class="fa-solid fa-shield-halved big-ic"></i></div>` : ''}
    </div>
    <div id="running"></div>
    <div class="section-head">آخر الحملات</div>
    <div class="list mini-list" id="recent"><div style="padding:14px"><div class="skel" style="height:44px"></div></div></div>`;
  const [st, run, recent] = await Promise.all([api('/dashboard/stats'), api('/dashboard/running_campaigns'), api('/dashboard/campaigns')]);
  if (st.accounts_total !== undefined) {
    el.querySelector('#heroStats').innerHTML = `<div><b class="num">${st.accounts_connected}/${st.accounts_total}</b><span>أرقام متصلة</span></div>
      <div><b class="num">${fmt(st.messages_sent)}</b><span>رسائل مرسلة</span></div><div><b class="num">${st.success_rate}%</b><span>نسبة النجاح</span></div>`;
  }
  if (Array.isArray(run) && run.length) {
    el.querySelector('#running').innerHTML = `<div class="section-head">حملات جارية <span class="pill pill-green">${run.length}</span></div>` + run.map(c => {
      const done = c.sent + c.failed, pct = c.total ? Math.round(done / c.total * 100) : 0;
      return `<div class="card" onclick="go('campaign',{acc:'${esc(c.account_id)}'})" style="cursor:pointer">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px"><b>${esc(c.account_name)}</b>${c.paused ? '<span class="pill pill-orange">موقوفة</span>' : '<span class="pill pill-green"><span class="status-dot on"></span> جارية</span>'}</div>
        <div class="progress"><span style="width:${pct}%"></span></div>
        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text3);margin-top:6px"><span class="num">${fmt(done)} / ${fmt(c.total)}</span><span class="num">${pct}%</span></div></div>`;
    }).join('');
  }
  el.querySelector('#recent').innerHTML = Array.isArray(recent) && recent.length ? recent.slice(0, 6).map(h => rowItem({
    icon: h.failed ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-check-double', ic: h.failed ? 'ic-orange' : 'ic-green',
    title: `${esc(h.account_name)} <span class="pill pill-gray num">${fmt(h.sent)}/${fmt(h.total)}</span>`, sub: `${esc(h.time)} · ${esc((h.text || '').slice(0, 40))}`, end: '',
  })).join('') : empty('fa-regular fa-paper-plane', 'ما أرسلت أي حملة بعد', `<button class="btn btn-primary btn-sm" onclick="go('campaign')">أرسل أول حملة</button>`);
};

// ---------------------------------------------------------------- الحملات
async function loadAccounts() { const a = await api('/accounts'); S.accounts = Array.isArray(a) ? a : []; return S.accounts; }

R.campaign = async (el, p) => {
  el.innerHTML = head('حملة جديدة', false) + '<div class="card"><div class="skel" style="height:120px"></div></div>';
  const accs = await loadAccounts();
  if (!accs.length) {
    el.innerHTML = head('حملة جديدة', false) + `<div class="card">${empty('fa-brands fa-whatsapp', 'اربط رقم واتساب أولاً حتى ترسل منه', `<button class="btn btn-wa" onclick="go('accounts')"><i class="fa-solid fa-link"></i> ربط رقم</button>`)}</div>`;
    return;
  }
  const sel = accs.find(a => a.id === p.acc) || accs.find(a => a.logged_in) || accs[0];
  const ccOpts = B.country_codes.map(([c, l]) => `<option value="${c}" ${c === B.cc ? 'selected' : ''}>${esc(l)}</option>`).join('');
  const payPerMsg = !ME.plan_active && !ME.trial_active && SET.message_price > 0 && !ADMIN;
  el.innerHTML = head('حملة جديدة', false, `<span class="pill pill-blue">${accs.filter(a => a.logged_in).length}/${accs.length} متصل</span>`) + `
    <div class="chips" id="accChips" style="margin-bottom:12px">${accs.map(a => `<button class="chip ${a.id === sel.id ? 'on' : ''}" data-id="${esc(a.id)}"><span class="status-dot ${a.logged_in ? 'on' : ''}"></span> ${esc(a.name)}</button>`).join('')}</div>
    <div id="cStatus"></div>
    <div class="card" id="cForm">
      <div class="card-title"><i class="fa-solid fa-address-book"></i> الأرقام <span class="spacer"></span><span class="pill pill-blue" id="numCount">0 رقم</span></div>
      <textarea class="textarea" id="numbers" placeholder="رقم بكل سطر أو مفصولة بفواصل&#10;07701234567&#10;9647709876543" dir="ltr" style="text-align:left"></textarea>
      <label class="file-drop" style="margin-top:8px"><input type="file" id="numFile" accept=".xlsx,.csv,.txt"><span class="row-ic ic-green"><i class="fa-solid fa-file-excel"></i></span><span style="flex:1"><b style="font-size:13.5px">استيراد من Excel أو CSV</b><span class="row-sub" id="numFileName">الأرقام بالعمود الأول</span></span></label>
      <div class="field" style="margin-top:12px"><label>رمز الدولة للأرقام المحلية</label><select class="select" id="cc">${ccOpts}</select></div>
      <div class="card-title" style="margin-top:6px"><i class="fa-solid fa-message"></i> الرسالة</div>
      <textarea class="textarea" id="text" placeholder="اكتب رسالتك هنا..."></textarea>
      <label class="file-drop" style="margin-top:8px"><input type="file" id="media" accept="image/*,video/*,audio/*,application/pdf,.doc,.docx,.xlsx,.zip"><span class="row-ic ic-purple"><i class="fa-solid fa-paperclip"></i></span><span style="flex:1"><b style="font-size:13.5px">إرفاق صورة / فيديو / ملف</b><span class="row-sub" id="mediaName">اختياري - ينرسل والنص تعليق عليه</span></span></label>
      <div class="input-row" style="margin-top:12px">
        <div class="field" style="flex:1"><label>الفاصل بين الرسائل (ثانية)</label><input class="input num" id="delay" type="number" min="1" value="15"></div>
        <div class="field" style="flex:1.3"><label>جدولة (اختياري)</label><input class="input" id="sendAt" type="datetime-local"></div>
      </div>
      <div class="note warn hidden" id="costNote"></div>
      <button class="btn btn-primary btn-block" id="sendBtn" style="margin-top:12px"><i class="fa-solid fa-paper-plane"></i> إرسال الحملة</button>
    </div>
    <div class="section-head">سجل حملات هذا الرقم</div>
    <div class="list mini-list" id="hist"></div>`;

  let accId = sel.id;
  const numbersEl = $('#numbers');
  const count = () => {
    const n = new Set(numbersEl.value.split(/[\n,،;\s]+/).map(x => x.replace(/\D/g, '')).filter(x => x.length >= 8)).size;
    $('#numCount').textContent = fmt(n) + ' رقم';
    const note = $('#costNote');
    if (payPerMsg && n) {
      const cost = n * SET.message_price;
      note.classList.remove('hidden');
      note.innerHTML = `التكلفة التقديرية: ${fmt(n)} × ${fmt(SET.message_price)} = <b>${fmt(cost)} د.ع</b> — رصيدك ${fmt(ME.balance)} د.ع` + (cost > ME.balance ? ` <a onclick="go('wallet')" style="cursor:pointer;font-weight:800">اشحن رصيدك</a>` : '');
    } else note.classList.add('hidden');
    return n;
  };
  numbersEl.addEventListener('input', count);
  $('#numFile').addEventListener('change', async e => {
    const f = e.target.files[0]; if (!f) return;
    $('#numFileName').textContent = f.name;
    const fd = new FormData(); fd.append('numbers_file', f);
    const r = await api('/campaign/preview_numbers', { body: fd });
    if (!r.ok) { toast(r.error || 'تعذرت قراءة الملف', 'err'); return; }
    numbersEl.value = (numbersEl.value.trim() ? numbersEl.value.trim() + '\n' : '') + r.numbers.join('\n');
    toast(`تمت إضافة ${fmt(r.numbers.length)} رقم`, 'ok'); count();
  });
  $('#media').addEventListener('change', e => { const f = e.target.files[0]; $('#mediaName').textContent = f ? `${f.name} (${(f.size / 1048576).toFixed(1)}MB)` : 'اختياري'; });
  $('#accChips').addEventListener('click', e => {
    const c = e.target.closest('.chip'); if (!c) return;
    accId = c.dataset.id; $$('#accChips .chip').forEach(x => x.classList.toggle('on', x === c)); loadStatus(); loadHist();
  });
  $('#sendBtn').addEventListener('click', async () => {
    if (!count()) { toast('أضف أرقام أولاً', 'err'); return; }
    const fd = new FormData();
    fd.append('numbers_text', numbersEl.value); fd.append('country_code', $('#cc').value); fd.append('text', $('#text').value);
    fd.append('delay', $('#delay').value || 15);
    if ($('#sendAt').value) fd.append('send_at', $('#sendAt').value);
    if ($('#media').files[0]) fd.append('media_file', $('#media').files[0]);
    busy($('#sendBtn'), true);
    const r = await api(`/accounts/${accId}/campaign`, { body: fd });
    busy($('#sendBtn'), false);
    if (!r.ok) { toast(r.error || 'تعذر بدء الحملة', 'err'); if (r.needs_subscription) setTimeout(() => go('wallet'), 1200); return; }
    toast(r.scheduled_for ? 'تمت جدولة الحملة: ' + r.scheduled_for : `بدأت الحملة على ${fmt(r.total)} رقم`, 'ok');
    numbersEl.value = ''; count(); loadStatus();
  });

  async function loadStatus() {
    const s = await api(`/accounts/${accId}/campaign_status`);
    const box = el.querySelector('#cStatus'); if (!box) return;
    if (!s.running && !s.paused) { box.innerHTML = ''; return; }
    const done = s.sent + s.failed, pct = s.total ? Math.round(done / s.total * 100) : 0;
    const pend = s.pending_numbers || [];
    box.innerHTML = `<div class="card fade-in" style="margin-bottom:12px;border-color:var(--primary)">
      <div class="card-title"><i class="fa-solid fa-satellite-dish"></i> ${s.scheduled_for ? 'حملة مجدولة' : s.paused ? 'الحملة موقوفة مؤقتاً' : 'الحملة جارية الآن'}<span class="spacer"></span>${s.scheduled_for ? `<span class="pill pill-blue num">${esc(s.scheduled_for)}</span>` : `<span class="pill pill-gray num">${pct}%</span>`}</div>
      <div class="progress"><span style="width:${pct}%"></span></div>
      <div class="stats three" style="margin-top:12px">
        <div class="stat"><span class="stat-v num" style="color:var(--green)">${fmt(s.sent)}</span><span class="stat-l">نجحت</span></div>
        <div class="stat"><span class="stat-v num" style="color:var(--red)">${fmt(s.failed)}</span><span class="stat-l">فشلت</span></div>
        <div class="stat"><span class="stat-v num">${fmt(pend.length)}</span><span class="stat-l">متبقية</span></div>
      </div>
      <div class="input-row" style="margin-top:12px">
        ${s.paused ? `<button class="btn btn-green btn-sm" style="flex:1" data-act="resume"><i class="fa-solid fa-play"></i> استئناف</button>` : `<button class="btn btn-soft btn-sm" style="flex:1" data-act="stop"><i class="fa-solid fa-pause"></i> إيقاف مؤقت</button>`}
        <button class="btn btn-red btn-sm" style="flex:1" data-act="cancel"><i class="fa-solid fa-xmark"></i> إلغاء نهائي</button>
      </div>
      ${pend.length ? `<details style="margin-top:10px"><summary style="cursor:pointer;font-size:13px;font-weight:700;color:var(--text2)">الأرقام المتبقية (${fmt(pend.length)})</summary>
        <div class="chips" style="margin-top:8px">${pend.slice(0, 40).map(n => `<span class="chip num" style="padding:5px 10px">${esc(n)} <i class="fa-solid fa-xmark" data-ex="${esc(n)}" style="cursor:pointer;color:var(--red);margin-right:4px"></i></span>`).join('')}</div></details>` : ''}
      ${s.failed_numbers?.length ? `<details style="margin-top:8px"><summary style="cursor:pointer;font-size:13px;font-weight:700;color:var(--red)">الأرقام الفاشلة (${s.failed_numbers.length})</summary><div class="code-block" style="margin-top:8px">${esc(s.failed_numbers.join('\n'))}</div></details>` : ''}
    </div>`;
    box.querySelectorAll('[data-act]').forEach(b => b.onclick = () => {
      const act = b.dataset.act;
      const run = async () => { busy(b, true); const r = await api(`/accounts/${accId}/campaign/${act}`, { body: {} }); busy(b, false); if (!r.ok) toast(r.error, 'err'); setTimeout(loadStatus, 600); };
      act === 'cancel' ? confirmTap(b, run) : run();
    });
    box.querySelectorAll('[data-ex]').forEach(x => x.onclick = async () => { await api(`/accounts/${accId}/campaign/exclude`, { body: { number: x.dataset.ex } }); loadStatus(); });
  }
  async function loadHist() {
    const h = await api(`/accounts/${accId}/campaigns`);
    const box = el.querySelector('#hist'); if (!box) return;
    box.innerHTML = Array.isArray(h) && h.length ? h.map(x => rowItem({
      icon: x.failed ? 'fa-solid fa-triangle-exclamation' : 'fa-solid fa-check-double', ic: x.failed ? 'ic-orange' : 'ic-green',
      title: `<span class="num">${fmt(x.sent)} / ${fmt(x.total)}</span> ${x.failed ? `<span class="pill pill-red">فشل ${fmt(x.failed)}</span>` : ''}`,
      sub: `${esc(x.time)} · ${esc((x.text || '').slice(0, 50))}`, end: '',
    })).join('') : empty('fa-regular fa-clock', 'ما فيه حملات سابقة لهذا الرقم');
  }
  loadStatus(); loadHist(); every(3500, loadStatus);
};

// ---------------------------------------------------------------- أرقام واتساب
R.accounts = async (el) => {
  el.innerHTML = head('أرقام واتساب', false) + '<div class="list"><div style="padding:14px"><div class="skel" style="height:44px"></div></div></div>';
  const accs = await loadAccounts();
  el.innerHTML = head('أرقام واتساب', false, `<span class="pill pill-green">${accs.filter(a => a.logged_in).length} متصل</span>`) + `
    <div class="list stagger">${accs.length ? accs.map(a => rowItem({
      icon: 'fa-brands fa-whatsapp', ic: a.logged_in ? 'ic-wa' : 'ic-red', title: `${esc(a.name)} ${a.otp_sender ? '<span class="pill pill-blue">مرسل الرموز</span>' : ''}`,
      sub: `<span class="status-dot ${a.logged_in ? 'on' : ''}"></span> ${a.logged_in ? 'متصل وجاهز للإرسال' : 'غير متصل - اضغط للربط'}`, onclick: `go('acc',{id:'${esc(a.id)}'})`,
    })).join('') : empty('fa-brands fa-whatsapp', 'ما ربطت أي رقم بعد')}</div>
    <div class="section-head">ربط رقم جديد</div>
    <div class="card">
      <div class="field"><label>اسم الحساب (للتمييز)</label><input class="input" id="accName" placeholder="مثال: رقم المبيعات"></div>
      <button class="btn btn-wa btn-block" id="addBtn"><i class="fa-solid fa-link"></i> متابعة للربط</button>
      <div class="hint" style="text-align:center;margin-top:8px">تقدر تربط بمسح الباركود أو بإدخال رمز ربط بالأجهزة المرتبطة</div>
    </div>`;
  $('#addBtn').onclick = async () => {
    busy($('#addBtn'), true);
    const r = await api('/accounts', { body: { name: $('#accName').value.trim() } });
    busy($('#addBtn'), false);
    if (!r.id) { toast(r.error || 'تعذر إنشاء الحساب', 'err'); return; }
    go('acc', { id: r.id });
  };
};

R.acc = async (el, p) => {
  const accs = await loadAccounts();
  const a = accs.find(x => x.id === p.id);
  if (!a) { el.innerHTML = head('الحساب') + `<div class="card">${empty('fa-solid fa-circle-question', 'الحساب غير موجود')}</div>`; return; }
  el.innerHTML = head(esc(a.name)) + `<div id="link"></div>
    <div class="section-head">الرد الآلي</div><div class="card" id="ar"><div class="skel" style="height:60px"></div></div>
    ${ADMIN ? `<div class="section-head">إعدادات المنصة</div><div class="card"><div style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-blue"><i class="fa-solid fa-key"></i></span><div style="flex:1"><b>مرسل رموز التحقق</b><div class="row-sub">رموز الدخول برقم واتساب تنرسل من هذا الحساب</div></div><label class="switch"><input type="checkbox" id="otpSw" ${a.otp_sender ? 'checked' : ''}><span></span></label></div></div>` : ''}
    <div class="section-head">منطقة الخطر</div>
    <button class="btn btn-red btn-block" id="delBtn"><i class="fa-solid fa-trash"></i> حذف الحساب وفصل الرقم</button>`;
  $('#delBtn').onclick = e => confirmTap(e.currentTarget, async () => { await api(`/accounts/${a.id}/logout`, { body: {} }); toast('تم حذف الحساب', 'ok'); go('accounts'); });
  if (ADMIN) $('#otpSw').onchange = async e => { const r = await api(`/accounts/${a.id}/otp_sender`, { body: { enabled: e.target.checked } }); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); };

  let mode = 'qr', connected = a.logged_in;
  const link = el.querySelector('#link');
  function renderLink() {
    if (connected) {
      link.innerHTML = `<div class="hero" style="background:linear-gradient(135deg,#065f46,#10b981)"><div style="display:flex;align-items:center;gap:12px"><span class="hero-icon"><i class="fa-brands fa-whatsapp"></i></span><div><div style="font-weight:800;font-size:17px">الرقم متصل</div><div class="hero-sub">جاهز لإرسال الحملات والرد الآلي</div></div></div></div>
        <button class="btn btn-primary btn-block" style="margin-top:12px" onclick="go('campaign',{acc:'${esc(a.id)}'})"><i class="fa-solid fa-paper-plane"></i> إرسال حملة من هذا الرقم</button>`;
      return;
    }
    link.innerHTML = `<div class="card">
      <div class="tabs"><button class="tab ${mode === 'qr' ? 'on' : ''}" data-m="qr"><i class="fa-solid fa-qrcode"></i> الباركود</button><button class="tab ${mode === 'code' ? 'on' : ''}" data-m="code"><i class="fa-solid fa-hashtag"></i> رمز الربط</button></div>
      ${mode === 'qr' ? `
        <div class="qr-frame" style="position:relative;min-height:258px;align-items:center"><div id="qrSpin" class="skel" style="position:absolute;inset:14px;border-radius:12px"></div><img id="qr" alt="" style="opacity:0;transition:opacity .3s" src="/accounts/${a.id}/qr?t=${Date.now()}"></div>
        <div id="qrWait" class="hint" style="text-align:center;margin-top:6px">جارِ تجهيز الباركود...</div>
        <div style="margin-top:12px">
          <div class="numbered"><b>1</b><span>افتح واتساب بجوالك</span></div>
          <div class="numbered"><b>2</b><span>الإعدادات ← <b>الأجهزة المرتبطة</b> ← ربط جهاز</span></div>
          <div class="numbered"><b>3</b><span>وجّه الكاميرا على الباركود</span></div>
        </div>` : `
        <div id="pairBox">
          <div class="field"><label>رقم واتساب اللي تريد تربطه (مع رمز الدولة)</label><input class="input num" id="pairPhone" inputmode="numeric" placeholder="${B.cc}7701234567" style="direction:ltr;text-align:left"></div>
          <button class="btn btn-primary btn-block" id="pairBtn"><i class="fa-solid fa-hashtag"></i> توليد رمز الربط</button>
        </div>
        <div style="margin-top:12px">
          <div class="numbered"><b>1</b><span>افتح واتساب ← الإعدادات ← <b>الأجهزة المرتبطة</b></span></div>
          <div class="numbered"><b>2</b><span>ربط جهاز ← <b>الربط برقم الهاتف بدلاً من ذلك</b></span></div>
          <div class="numbered"><b>3</b><span>اكتب الرمز اللي يطلع هنا (خلال دقيقة)</span></div>
        </div>`}
    </div>`;
    link.querySelectorAll('.tab').forEach(t => t.onclick = () => { mode = t.dataset.m; renderLink(); });
    const qr = link.querySelector('#qr');
    if (qr) {
      qr.onload = () => { if (qr.naturalWidth > 10) { qr.style.opacity = 1; const sp = link.querySelector('#qrSpin'); if (sp) sp.remove(); link.querySelector('#qrWait').textContent = 'الباركود يتحدث تلقائياً'; } };
      qr.onerror = () => { qr.style.opacity = 0; };
    }
    const pb = link.querySelector('#pairBtn');
    if (pb) pb.onclick = async () => {
      let ph = link.querySelector('#pairPhone').value.replace(/\D/g, '');
      if (ph.startsWith('0')) ph = B.cc + ph.slice(1);
      busy(pb, true);
      const r = await api(`/accounts/${a.id}/pair`, { body: { phone: ph } });
      busy(pb, false);
      if (!r.ok) { toast(r.error || 'تعذر توليد الرمز', 'err'); return; }
      link.querySelector('#pairBox').innerHTML = `<div class="pair-code fade-in">${esc(r.code)}</div><button class="btn btn-soft btn-block" style="margin-top:8px" onclick="copy('${esc(r.code.replace('-', ''))}')"><i class="fa-regular fa-copy"></i> نسخ الرمز</button>`;
    };
  }
  renderLink();
  every(3000, async () => {
    if (!connected && mode === 'qr') { const q = link.querySelector('#qr'); if (q) q.src = `/accounts/${a.id}/qr?t=${Date.now()}`; }
    const s = await api(`/accounts/${a.id}/status`);
    if (!!s.logged_in !== connected) { connected = !!s.logged_in; if (connected) toast('تم ربط الرقم بنجاح', 'ok'); renderLink(); }
  });

  // الرد الآلي
  const cfg = await api(`/accounts/${a.id}/auto_reply`);
  const rules = cfg.rules || [];
  const ar = el.querySelector('#ar');
  function renderAr() {
    ar.innerHTML = `
      <div style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-green"><i class="fa-solid fa-robot"></i></span><div style="flex:1"><b>تفعيل الرد الآلي</b><div class="row-sub">يرد على الرسائل الواردة تلقائياً</div></div><label class="switch"><input type="checkbox" id="arOn" ${cfg.enabled ? 'checked' : ''}><span></span></label></div>
      <div style="display:flex;align-items:center;gap:12px;margin-top:12px"><span class="row-ic ic-purple"><i class="fa-solid fa-wand-magic-sparkles"></i></span><div style="flex:1"><b>الرد بالذكاء الاصطناعي</b><div class="row-sub">لو ما طابقت أي كلمة مفتاحية</div></div><label class="switch"><input type="checkbox" id="arAi" ${cfg.ai_enabled ? 'checked' : ''}><span></span></label></div>
      <div class="divider">الكلمات المفتاحية</div>
      ${rules.map((r, i) => `<div class="input-row" style="margin-bottom:8px"><input class="input" data-k="${i}" value="${esc(r.keyword)}" placeholder="إذا احتوت" style="flex:1"><input class="input" data-r="${i}" value="${esc(r.reply)}" placeholder="الرد" style="flex:1.6"><button class="btn btn-red btn-sm" data-del="${i}" style="height:48px"><i class="fa-solid fa-trash"></i></button></div>`).join('')}
      <button class="btn btn-ghost btn-block btn-sm" id="arAdd"><i class="fa-solid fa-plus"></i> إضافة كلمة</button>
      <button class="btn btn-primary btn-block" id="arSave" style="margin-top:10px">حفظ الرد الآلي</button>`;
    const sync = () => { ar.querySelectorAll('[data-k]').forEach(i => rules[i.dataset.k].keyword = i.value); ar.querySelectorAll('[data-r]').forEach(i => rules[i.dataset.r].reply = i.value); };
    ar.querySelector('#arAdd').onclick = () => { sync(); rules.push({ keyword: '', reply: '' }); cfg.enabled = ar.querySelector('#arOn').checked; cfg.ai_enabled = ar.querySelector('#arAi').checked; renderAr(); };
    ar.querySelectorAll('[data-del]').forEach(b => b.onclick = () => { sync(); rules.splice(+b.dataset.del, 1); renderAr(); });
    ar.querySelector('#arSave').onclick = async e => {
      sync(); busy(e.currentTarget, true);
      const r = await api(`/accounts/${a.id}/auto_reply`, { body: { enabled: ar.querySelector('#arOn').checked, ai_enabled: ar.querySelector('#arAi').checked, rules } });
      busy(e.currentTarget, false); toast(r.ok ? 'تم حفظ الرد الآلي' : r.error, r.ok ? 'ok' : 'err');
    };
  }
  renderAr();
};

// ---------------------------------------------------------------- الشحن
const PM_ICON = { asiacell: ['fa-solid fa-sim-card', 'ic-red'], binance: ['fa-brands fa-bitcoin', 'ic-orange'], superkey: ['fa-solid fa-credit-card', 'ic-purple'], manual: ['fa-solid fa-building-columns', 'ic-cyan'] };
const PLAN_GRAD = { teal: 'tile-teal', amber: 'tile-amber', violet: 'tile-violet', coral: 'tile-coral', emerald: 'tile-emerald', sky: 'tile-sky' };
R.wallet = async (el, p) => {
  await refreshMe();
  const [pr, mr] = await Promise.all([api('/api/plans'), api('/api/payment-methods')]);
  S.plans = pr.plans || []; S.methods = mr.methods || [];
  const chosen = p.plan ? S.plans.find(x => String(x.id) === String(p.plan)) : null;
  const methodsList = (planId) => S.methods.length ? `<div class="list stagger">${S.methods.map(m => {
    const [icon, ic] = PM_ICON[m.type] || PM_ICON.manual;
    const tag = m.type === 'asiacell' || m.type === 'binance' ? '<span class="pill pill-green">فوري</span>' : '<span class="pill pill-gray">مراجعة</span>';
    return rowItem({ icon, ic, img: m.image, title: `${esc(m.name)} ${tag}`, sub: esc(m.note || ''), onclick: `go('pay',{id:${m.id}${planId ? ',plan:' + planId : ''}})` });
  }).join('')}</div>` : `<div class="card">${empty('fa-solid fa-credit-card', 'ما فيه طرق دفع مفعّلة حالياً - تواصل مع الدعم')}</div>`;

  // صفحة دفع باقة محددة
  if (chosen) {
    const enough = ME.balance >= chosen.price;
    el.innerHTML = head(esc(chosen.name)) + `
      <div class="plan ${PLAN_GRAD[chosen.color] || 'tile-teal'}" style="cursor:default">
        <div class="plan-top"><span class="plan-name">${esc(chosen.name)}</span><span class="pill pill-white">${fmt(chosen.days)} يوم</span></div>
        <div class="plan-price"><span class="num">${fmt(chosen.price)}</span> <small>د.ع</small></div>
        <ul>${chosen.features_list.map(f => `<li>${esc(f)}</li>`).join('')}</ul>
      </div>
      <div class="stepper"><div class="st done"><b>1</b>اختر الباقة</div><div class="st ${enough ? 'done' : ''}"><b>2</b>ادفع</div><div class="st"><b>3</b>تتفعّل فوراً</div></div>
      ${enough ? `<div class="card" style="margin-top:14px"><div class="card-title"><i class="fa-solid fa-wallet"></i> الدفع من رصيدك <span class="spacer"></span><span class="pill pill-green num">${fmt(ME.balance)} د.ع</span></div>
        <button class="btn btn-primary btn-block" id="buyNow"><i class="fa-solid fa-gem"></i> تفعيل الباقة الآن</button></div>`
        : `<div class="note warn" style="margin-top:14px">رصيدك ${fmt(ME.balance)} د.ع - ادفع ${fmt(chosen.price - ME.balance)} د.ع أو أكثر بإحدى الطرق، والباقة تتفعّل تلقائياً أول ما يوصل المبلغ.</div>`}
      <div class="section-head">${enough ? 'أو ادفع بطريقة ثانية' : 'اختر طريقة الدفع'}</div>${methodsList(chosen.id)}`;
    const b = el.querySelector('#buyNow');
    if (b) b.onclick = () => confirmTap(b, async () => {
      busy(b, true); const r = await api('/api/plan/buy', { body: { plan_id: chosen.id } }); busy(b, false);
      if (!r.ok) { toast(r.error, 'err'); return; }
      ME = r.me; document.getElementById('balChip').textContent = fmt(ME.balance); toast('تم تفعيل ' + chosen.name, 'ok'); go('wallet', {}, false);
    }, 'اضغط للتأكيد');
    return;
  }

  el.innerHTML = head('الباقات', false, `<button class="btn btn-ghost btn-sm" onclick="go('tx')"><i class="fa-solid fa-clock-rotate-left"></i> السجل</button>`) + `
    <div class="hero fade-in"><div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
      <div><div class="hero-label">باقتك الحالية</div>
        <div style="font-size:22px;font-weight:800;margin-top:2px">${ME.plan_active ? esc(ME.plan_name || 'مشترك') : ME.trial_active ? 'تجربة مجانية' : 'بدون باقة'}</div>
        <div class="hero-sub">${ME.plan_active ? (ME.plan_days_left === null ? 'اشتراك دائم' : 'باقي ' + ME.plan_days_left + ' يوم') : ME.trial_active ? 'باقي ' + ME.trial_days_left + ' يوم' : 'اختر باقة أو اشحن رصيد للإرسال'}</div></div>
      <div style="text-align:left"><div class="hero-label">الرصيد</div><div style="font-size:22px;font-weight:800" class="num">${fmt(ME.balance)}</div><div class="hero-sub">د.ع</div></div></div></div>
    <div class="section-head">اختر باقتك</div>
    <div class="stagger">${S.plans.map(pl => `<div class="plan ${PLAN_GRAD[pl.color] || 'tile-teal'} ${ME.plan_active && ME.plan_name === pl.name ? 'current' : ''}" onclick="go('wallet',{plan:${pl.id}})">
      <div class="plan-top"><span class="plan-name">${esc(pl.name)}</span>${pl.popular ? '<span class="plan-badge"><i class="fa-solid fa-fire"></i> الأكثر طلباً</span>' : `<span class="pill pill-white">${fmt(pl.days)} يوم</span>`}</div>
      <div class="plan-price"><span class="num">${fmt(pl.price)}</span> <small>د.ع / ${fmt(pl.days)} يوم</small></div>
      <ul>${pl.features_list.map(f => `<li>${esc(f)}</li>`).join('')}</ul>
      <span class="btn btn-white">${ME.plan_active && ME.plan_name === pl.name ? 'تجديد الباقة' : 'اشترك الآن'} <i class="fa-solid fa-arrow-left"></i></span></div>`).join('') || `<div class="card">${empty('fa-solid fa-gem', 'ما فيه باقات حالياً')}</div>`}</div>
    ${SET.message_price > 0 ? `<div class="section-head">أو ادفع حسب استخدامك</div>
    <div class="card" style="display:flex;gap:14px;align-items:center"><span class="row-ic ic-orange" style="width:54px;height:54px;font-size:22px"><i class="fa-solid fa-coins"></i></span>
      <div style="flex:1"><b>شحن رصيد بدون باقة</b><div class="row-sub" style="white-space:normal">كل رسالة تنخصم من رصيدك بـ <b class="num">${fmt(SET.message_price)}</b> د.ع فقط</div></div></div>
    <div class="section-head">طرق شحن الرصيد</div>${methodsList(null)}` : `<div class="section-head">شحن الرصيد</div>${methodsList(null)}`}`;
};

R.pay = async (el, p) => {
  if (!S.methods) { const r = await api('/api/payment-methods'); S.methods = r.methods || []; }
  const m = S.methods.find(x => String(x.id) === String(p.id));
  if (!m) { go('wallet'); return; }
  const [icon, ic] = PM_ICON[m.type] || PM_ICON.manual;
  const top = `<div class="card" style="display:flex;align-items:center;gap:12px"><span class="row-ic ${ic}" style="width:56px;height:56px;font-size:24px">${m.image ? `<img src="${esc(m.image)}">` : `<i class="${icon}"></i>`}</span>
    <div style="flex:1"><b style="font-size:17px">${esc(m.name)}</b><div class="row-sub" style="white-space:normal">${esc(m.note || '')}</div></div></div>`;
  const plan = p.plan && S.plans ? S.plans.find(x => String(x.id) === String(p.plan)) : null;
  const need = plan ? Math.max(0, plan.price - ME.balance) : 0;
  el.innerHTML = head('الدفع عبر ' + esc(m.name)) + top + (plan ? `<div class="note" style="margin-top:12px"><i class="fa-solid fa-gem"></i> الدفع لـ <b>${esc(plan.name)}</b> - تتفعّل تلقائياً بعد وصول المبلغ (${fmt(plan.price)} د.ع)</div>` : '') + '<div id="payBody" style="margin-top:12px"></div>';
  const body = el.querySelector('#payBody');

  if (m.type === 'manual' || m.type === 'superkey') {
    const quick = [5000, 10000, 25000, 50000];
    body.innerHTML = `<div class="card">
      <div class="numbered"><b>1</b><span>حوّل المبلغ للرقم التالي</span></div>
      <div class="copy-box"><span class="v">${esc(m.number || '—')}</span><button class="btn btn-soft btn-xs" onclick="copy('${esc(m.number)}')"><i class="fa-regular fa-copy"></i> نسخ</button></div>
      <div class="numbered" style="margin-top:14px"><b>2</b><span>اكتب المبلغ اللي حوّلته وارفع صورة الإيصال</span></div>
      <input class="input big num" id="amt" type="number" inputmode="numeric" placeholder="0" min="${m.min_amount}" value="${need || ''}">
      <div class="chips" style="margin-top:8px;justify-content:center">${quick.map(q => `<button class="chip num" data-q="${q}">${fmt(q)}</button>`).join('')}</div>
      <div class="field" style="margin-top:12px"><label>رقم العملية / ملاحظة (اختياري)</label><input class="input" id="ref"></div>
      <label class="file-drop"><input type="file" id="rc" accept="image/*"><span class="row-ic ic-blue"><i class="fa-solid fa-receipt"></i></span><span style="flex:1"><b style="font-size:13.5px">صورة الإيصال</b><span class="row-sub" id="rcName">اضغط لاختيار صورة</span></span></label>
      <img id="rcPrev" class="receipt hidden" style="margin-top:10px">
      <div class="numbered" style="margin-top:14px"><b>3</b><span>نراجع الطلب ونضيف الرصيد لحسابك</span></div>
      <button class="btn btn-primary btn-block" id="submit"><i class="fa-solid fa-paper-plane"></i> إرسال طلب الشحن</button>
      ${m.min_amount ? `<div class="hint" style="text-align:center">أقل مبلغ ${fmt(m.min_amount)} د.ع</div>` : ''}</div>`;
    body.querySelectorAll('[data-q]').forEach(c => c.onclick = () => { body.querySelector('#amt').value = c.dataset.q; body.querySelectorAll('[data-q]').forEach(x => x.classList.toggle('on', x === c)); });
    body.querySelector('#rc').onchange = async e => { const f = e.target.files[0]; if (!f) return; body.querySelector('#rcName').textContent = f.name; const im = body.querySelector('#rcPrev'); im.src = await fileToDataUrl(f); im.classList.remove('hidden'); };
    body.querySelector('#submit').onclick = async e => {
      const fd = new FormData();
      fd.append('method_id', m.id); fd.append('amount', body.querySelector('#amt').value); fd.append('ref', body.querySelector('#ref').value);
      if (plan) fd.append('plan_id', plan.id);
      if (body.querySelector('#rc').files[0]) fd.append('receipt', body.querySelector('#rc').files[0]);
      busy(e.currentTarget, true); const r = await api('/api/recharge/manual', { body: fd }); busy(e.currentTarget, false);
      if (!r.ok) { toast(r.error, 'err'); return; }
      body.innerHTML = `<div class="card fade-in" style="text-align:center"><span class="row-ic ic-green" style="width:64px;height:64px;font-size:28px;margin:6px auto 12px"><i class="fa-solid fa-check"></i></span><b style="font-size:17px">تم إرسال طلبك</b><p style="color:var(--text3);font-size:13px;margin-top:6px">${esc(r.msg)}</p><button class="btn btn-soft btn-block" style="margin-top:14px" onclick="go('tx',{tab:'recharges'})">متابعة الطلب</button></div>`;
    };
  }

  if (m.type === 'binance') {
    body.innerHTML = `<div class="card">
      <div class="numbered"><b>1</b><span>أرسل المبلغ بعملة <b>USDT</b> عبر Binance Pay لهذا المعرّف</span></div>
      <div class="copy-box"><span class="v">${esc(m.number || '—')}</span><button class="btn btn-soft btn-xs" onclick="copy('${esc(m.number)}')"><i class="fa-regular fa-copy"></i> نسخ</button></div>
      ${m.qr ? `<div class="qr-frame" style="margin-top:10px"><img src="${esc(m.qr)}" style="width:200px;height:200px;object-fit:contain"></div>` : ''}
      <div class="numbered" style="margin-top:14px"><b>2</b><span>اكتب المبلغ بالدولار ورقم العملية (Order ID)</span></div>
      <input class="input big num" id="usd" type="number" step="0.01" inputmode="decimal" placeholder="$0.00" value="${need ? (Math.ceil(need / m.usd_rate * 100) / 100) : ''}">
      <div class="hint" style="text-align:center" id="eq">سعر الصرف: 1$ = ${fmt(m.usd_rate)} د.ع</div>
      <div class="field" style="margin-top:10px"><label>رقم العملية Order ID</label><input class="input num" id="oid" inputmode="numeric" style="direction:ltr;text-align:left"></div>
      <button class="btn btn-primary btn-block" id="verify"><i class="fa-solid fa-shield-check"></i> تحقق وأضف الرصيد</button>
      <div class="hint" style="text-align:center;margin-top:6px">يتم التحقق تلقائياً خلال ثواني</div></div>`;
    body.querySelector('#usd').oninput = e => { const v = parseFloat(e.target.value) || 0; body.querySelector('#eq').textContent = v ? `= ${fmt(Math.round(v * m.usd_rate))} د.ع تنضاف لرصيدك` : `سعر الصرف: 1$ = ${fmt(m.usd_rate)} د.ع`; };
    body.querySelector('#verify').onclick = async e => {
      busy(e.currentTarget, true);
      const r = await api('/api/recharge/binance', { body: { amount_usd: body.querySelector('#usd').value, order_id: body.querySelector('#oid').value.trim(), plan_id: plan ? plan.id : null } });
      busy(e.currentTarget, false);
      if (!r.ok) { toast(r.error, 'err'); return; }
      toast(r.msg, 'ok'); await refreshMe(); go('home');
    };
  }

  if (m.type === 'asiacell') {
    let amount = null;
    const tiers = (m.tiers || []).map(t => `<button class="tier" data-a="${t.iqd}"><span class="num">${fmt(t.iqd)}</span>${t.bonus ? `<small>+${fmt(t.bonus)} هدية</small>` : '<small style="color:var(--text3)">د.ع</small>'}</button>`).join('');
    const step1 = () => {
      body.innerHTML = `<div class="card">
        <div class="numbered"><b>1</b><span>اختر المبلغ اللي تريد تحوّله من رصيد خطك</span></div>
        <div class="tier-grid">${tiers}</div>
        <div class="numbered" style="margin-top:14px"><b>2</b><span>اكتب رقم آسياسيل اللي راح تحوّل منه</span></div>
        <input class="input num" id="ph" inputmode="numeric" placeholder="07701234567" style="direction:ltr;text-align:left;font-size:17px">
        <button class="btn btn-primary btn-block" id="start" style="margin-top:12px"><i class="fa-solid fa-message"></i> إرسال رمز التحقق</button>
        <div class="note" style="margin-top:12px">راح يوصلك رمز SMS من آسياسيل، وبعدها رمز ثاني لتأكيد التحويل. الرصيد ينضاف تلقائياً بعد التأكيد.</div></div>`;
      body.querySelectorAll('.tier').forEach(t => t.onclick = () => { amount = +t.dataset.a; body.querySelectorAll('.tier').forEach(x => x.classList.toggle('on', x === t)); });
      body.querySelector('#start').onclick = async e => {
        if (!amount) { toast('اختر المبلغ', 'err'); return; }
        busy(e.currentTarget, true); const r = await api('/api/recharge/asiacell/start', { body: { phone: body.querySelector('#ph').value, amount, plan_id: plan ? plan.id : null } }); busy(e.currentTarget, false);
        if (!r.ok) { toast(r.error, 'err'); return; }
        codeStep('verify', r.msg);
      };
    };
    const codeStep = (step, msg) => {
      body.innerHTML = `<div class="card fade-in">
        <div class="note ok" style="margin-bottom:12px">${esc(msg)}</div>
        <input class="input big num" id="code" inputmode="numeric" maxlength="6" placeholder="••••••">
        <button class="btn btn-primary btn-block" id="go" style="margin-top:12px"><i class="fa-solid fa-check"></i> ${step === 'verify' ? 'تحقق' : 'تأكيد التحويل'}</button>
        <button class="btn btn-ghost btn-block" id="cancel" style="margin-top:8px">إلغاء العملية</button></div>`;
      body.querySelector('#go').onclick = async e => {
        busy(e.currentTarget, true); const r = await api('/api/recharge/asiacell/' + step, { body: { code: body.querySelector('#code').value } }); busy(e.currentTarget, false);
        if (!r.ok) { toast(r.error, 'err'); return; }
        if (step === 'verify' || r.done === false) { codeStep('confirm', r.msg); return; }
        await refreshMe();
        body.innerHTML = `<div class="card fade-in" style="text-align:center"><span class="row-ic ic-green" style="width:64px;height:64px;font-size:28px;margin:6px auto 12px"><i class="fa-solid fa-check"></i></span><b style="font-size:17px">${esc(r.msg)}</b><button class="btn btn-primary btn-block" style="margin-top:14px" onclick="go('wallet')">رجوع للشحن</button></div>`;
      };
      body.querySelector('#cancel').onclick = async () => { await api('/api/recharge/asiacell/cancel', { body: {} }); await refreshMe(); step1(); };
    };
    step1();
  }
};

R.tx = async (el, p) => {
  let tab = p.tab || 'tx';
  el.innerHTML = head('السجل المالي') + `<div class="tabs"><button class="tab" data-t="tx">حركات الرصيد</button><button class="tab" data-t="recharges">طلبات الشحن</button></div><div id="txList"></div>`;
  const TYPES = { recharge: ['fa-solid fa-arrow-down', 'ic-green', 'شحن'], message: ['fa-solid fa-paper-plane', 'ic-blue', 'رسالة'], plan: ['fa-solid fa-crown', 'ic-orange', 'اشتراك'], admin: ['fa-solid fa-user-shield', 'ic-purple', 'الإدارة'], refund: ['fa-solid fa-rotate-left', 'ic-cyan', 'استرجاع'] };
  const ST = { pending: ['pill-orange', 'قيد المراجعة'], approved: ['pill-green', 'مقبول'], rejected: ['pill-red', 'مرفوض'] };
  async function load() {
    el.querySelectorAll('.tab').forEach(t => t.classList.toggle('on', t.dataset.t === tab));
    const box = el.querySelector('#txList');
    box.innerHTML = '<div class="card"><div class="skel" style="height:80px"></div></div>';
    if (tab === 'tx') {
      const r = await api('/api/wallet/tx');
      // نجمع رسائل الحملات المتتالية حتى ما تطول القائمة
      const items = [];
      (r.items || []).forEach(t => { const last = items[items.length - 1]; if (last && t.type === 'message' && last.type === 'message' && last.note === t.note && last.created_at.slice(0, 13) === t.created_at.slice(0, 13)) { last.amount += t.amount; last.n++; } else items.push({ ...t, n: 1 }); });
      box.innerHTML = items.length ? '<div class="list mini-list">' + items.map(t => {
        const [ic, cls, lbl] = TYPES[t.type] || TYPES.admin;
        return rowItem({ icon: ic, ic: cls, title: `${lbl}${t.n > 1 ? ` ×${t.n}` : ''} <span class="row-sub" style="display:inline">${esc(t.note)}</span>`, sub: esc(t.created_at), end: `<b class="num" style="color:${t.amount >= 0 ? 'var(--green)' : 'var(--red)'}">${t.amount >= 0 ? '+' : ''}${fmt(t.amount)}</b>` });
      }).join('') + '</div>' : `<div class="card">${empty('fa-solid fa-receipt', 'ما فيه حركات بعد')}</div>`;
    } else {
      const r = await api('/api/recharge/history');
      box.innerHTML = (r.items || []).length ? '<div class="list mini-list">' + r.items.map(x => {
        const [cls, lbl] = ST[x.status] || ST.pending;
        return rowItem({ icon: 'fa-solid fa-wallet', ic: 'ic-blue', title: `<span class="num">${fmt(x.amount)}</span> د.ع <span class="pill ${cls}">${lbl}</span>`, sub: `${esc(x.method_name)} · ${esc(x.created_at)}${x.admin_note ? ' · ' + esc(x.admin_note) : ''}`, end: '' });
      }).join('') + '</div>' : `<div class="card">${empty('fa-solid fa-wallet', 'ما فيه طلبات شحن')}</div>`;
    }
  }
  el.querySelectorAll('.tab').forEach(t => t.onclick = () => { tab = t.dataset.t; load(); });
  load();
};

// ---------------------------------------------------------------- حسابي
R.account = async (el) => {
  await refreshMe();
  const first = (ME.name || '؟').trim()[0] || '؟';
  const contact = ME.email || (ME.phone ? '+' + ME.phone : '');
  const st = await api('/dashboard/stats');
  let pending = 0;
  if (ADMIN) { const s = await api('/api/admin/stats'); pending = s.pending_recharges || 0; }
  el.innerHTML = `
    <div class="card" style="display:flex;align-items:center;gap:12px">
      <div class="avatar" style="background:var(--hero);color:#fff;position:relative;box-shadow:0 8px 18px var(--primary-glow)">${esc(first)}</div>
      <div style="flex:1;min-width:0"><b style="font-size:16.5px">${esc(ME.name || 'بدون اسم')}</b>
        <div class="row-sub num" style="text-align:right">${esc(contact)}</div>
        <div style="margin-top:5px;display:flex;gap:6px;flex-wrap:wrap"><span class="pill pill-gray"><i class="fa-${ME.login === 'google' ? 'brands fa-google' : ME.login === 'email' ? 'solid fa-envelope' : 'brands fa-whatsapp'}"></i> ${ME.login === 'google' ? 'Google' : ME.login === 'email' ? 'بريد' : 'واتساب'}</span>${ADMIN ? '<span class="pill pill-blue"><i class="fa-solid fa-shield"></i> أدمن</span>' : ''}</div></div>
      <button class="chev" onclick="go('profile')" style="width:38px;height:38px"><i class="fa-solid fa-pen"></i></button>
    </div>
    <div class="stats three stagger" style="margin-top:12px">
      <div class="stat" onclick="go('wallet')"><span class="stat-ic ic-blue"><i class="fa-solid fa-wallet"></i></span><span class="stat-v num">${fmt(ME.balance)}</span><span class="stat-l">الرصيد (د.ع)</span></div>
      <div class="stat" onclick="go('wallet')"><span class="stat-ic ic-orange"><i class="fa-solid fa-crown"></i></span><span class="stat-v">${ME.plan_active ? (ME.plan_days_left === null ? 'دائم' : ME.plan_days_left + ' يوم') : ME.trial_active ? ME.trial_days_left + ' يوم' : '—'}</span><span class="stat-l">${ME.plan_active ? esc(ME.plan_name || 'الباقة') : ME.trial_active ? 'التجربة' : 'بدون باقة'}</span></div>
      <div class="stat" onclick="go('home')"><span class="stat-ic ic-green"><i class="fa-solid fa-paper-plane"></i></span><span class="stat-v num">${fmt(st.messages_sent || 0)}</span><span class="stat-l">رسائل مرسلة</span></div>
    </div>
    <div class="section-head">حسابي</div>
    <div class="list stagger">
      ${rowItem({ icon: 'fa-solid fa-user-clock', ic: 'ic-purple', title: 'اشتراكات عملائي', sub: 'تذكير تلقائي قبل انتهاء الاشتراك', onclick: "go('subscribers')" })}
      ${rowItem({ icon: 'fa-solid fa-code', ic: 'ic-orange', title: 'ربط API', sub: 'أرسل رسائل ورموز تحقق من موقعك', onclick: "go('apikeys')" })}
      ${rowItem({ icon: 'fa-solid fa-clock-rotate-left', ic: 'ic-cyan', title: 'السجل المالي', sub: 'حركات الرصيد وطلبات الشحن', onclick: "go('tx')" })}
      ${rowItem({ icon: 'fa-solid fa-bell', ic: 'ic-red', title: 'الإشعارات', onclick: "go('notifications')" })}
    </div>
    <div class="section-head">التطبيق</div>
    <div class="list">
      ${rowItem({ icon: 'fa-solid fa-mobile-screen-button', ic: 'ic-blue', title: 'تثبيت التطبيق', sub: 'أضفه لشاشتك الرئيسية', onclick: 'installApp()' })}
      ${rowItem({ icon: 'fa-solid fa-circle-half-stroke', ic: 'ic-purple', title: 'الوضع الليلي', end: `<label class="switch" onclick="event.stopPropagation()"><input type="checkbox" id="darkSw" ${document.documentElement.dataset.theme === 'dark' ? 'checked' : ''}><span></span></label>` })}
      ${rowItem({ icon: 'fa-brands fa-whatsapp', ic: 'ic-wa', title: 'الدعم الفني', sub: 'تواصل معنا على واتساب', onclick: `window.open('https://wa.me/${esc(SET.support_number)}','_blank')` })}
    </div>
    ${ADMIN ? `<div class="section-head">الإدارة <span class="pill pill-blue">أدمن</span></div>
    <div class="list stagger">
      ${rowItem({ icon: 'fa-solid fa-gauge-high', ic: 'ic-pink', title: 'لوحة الإدارة', sub: 'نظرة عامة على المنصة', onclick: "go('admin')" })}
      ${rowItem({ icon: 'fa-solid fa-users', ic: 'ic-blue', title: 'المستخدمين', sub: 'الاشتراكات، الفترات المجانية، الأرصدة', onclick: "go('users')" })}
      ${rowItem({ icon: 'fa-solid fa-list-check', ic: 'ic-green', title: 'طلبات الشحن', sub: 'مراجعة التحويلات اليدوية', end: `${pending ? `<span class="pill pill-red">${pending}</span>` : ''}<span class="chev"><i class="fa-solid fa-chevron-left"></i></span>`, onclick: "go('recharges')" })}
      ${rowItem({ icon: 'fa-solid fa-gem', ic: 'ic-purple', title: 'الباقات', sub: 'الأسعار والمدد والمزايا والألوان', onclick: "go('plansAdmin')" })}
      ${rowItem({ icon: 'fa-solid fa-credit-card', ic: 'ic-orange', title: 'طرق الدفع', sub: 'آسياسيل، Binance، سوبر كي، اليدوي', onclick: "go('payments')" })}
      ${rowItem({ icon: 'fa-solid fa-sliders', ic: 'ic-purple', title: 'الإعدادات', sub: 'الأسعار، الشعار، تسجيل الدخول', onclick: "go('settings')" })}
      ${rowItem({ icon: 'fa-solid fa-shield-halved', ic: 'ic-red', title: 'مركز الأمان', sub: 'سجل الدخول والحظر والجلسات', onclick: "go('security')" })}
      ${rowItem({ icon: 'fa-brands fa-telegram', ic: 'ic-cyan', title: 'النسخ الاحتياطي', sub: 'تصدير واستيراد JSON عبر بوت تيليجرام', onclick: "go('backup')" })}
      ${rowItem({ icon: 'fa-solid fa-bullhorn', ic: 'ic-wa', title: 'إعلان جماعي', sub: 'رسالة واتساب لكل المستخدمين', onclick: "go('announce')" })}
    </div>` : ''}
    <button class="btn btn-red btn-block" style="margin-top:18px" id="logout"><i class="fa-solid fa-right-from-bracket"></i> تسجيل الخروج</button>`;
  el.querySelector('#darkSw').onchange = e => { const d = e.target.checked ? 'dark' : ''; document.documentElement.dataset.theme = d; try { localStorage.setItem('theme', d); } catch (x) {} };
  el.querySelector('#logout').onclick = e => confirmTap(e.currentTarget, () => {
    const f = document.createElement('form'); f.method = 'POST'; f.action = '/logout'; document.body.appendChild(f); f.submit();
  }, 'اضغط مرة ثانية للخروج');
};

R.profile = async (el) => {
  el.innerHTML = head('الملف الشخصي') + `<div class="card">
    <div class="field"><label>الاسم</label><input class="input" id="nm" value="${esc(ME.name || '')}"></div>
    <div class="kv"><span>البريد</span><b class="num">${esc(ME.email || '—')}</b></div>
    <div class="kv"><span>رقم واتساب</span><b class="num">${ME.phone ? '+' + esc(ME.phone) : '—'}</b></div>
    <div class="kv"><span>عضو منذ</span><b class="num">${esc(ME.created_at)}</b></div>
    <button class="btn btn-primary btn-block" id="save" style="margin-top:12px">حفظ</button></div>
    <div class="section-head">منطقة الخطر</div>
    <button class="btn btn-red btn-block" id="wipe"><i class="fa-solid fa-trash"></i> حذف كل أرقام واتساب المربوطة وسجلها</button>`;
  el.querySelector('#save').onclick = async e => { busy(e.currentTarget, true); const r = await api('/api/me', { body: { name: el.querySelector('#nm').value } }); busy(e.currentTarget, false); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); refreshMe(); };
  el.querySelector('#wipe').onclick = e => confirmTap(e.currentTarget, async () => { const r = await api('/settings/delete_my_data', { body: {} }); toast(`تم حذف ${r.deleted || 0} حساب`, 'ok'); });
};

R.notifications = async (el) => {
  el.innerHTML = head('الإشعارات', true, `<button class="btn btn-ghost btn-sm" id="readAll"><i class="fa-solid fa-check-double"></i> قراءة الكل</button>`) + '<div id="nl"></div>';
  const KIND = { success: ['fa-solid fa-circle-check', 'ic-green'], warning: ['fa-solid fa-triangle-exclamation', 'ic-orange'], info: ['fa-solid fa-circle-info', 'ic-blue'] };
  async function load() {
    const ev = await api('/events?since=0');
    const list = (Array.isArray(ev) ? ev : []).reverse();
    el.querySelector('#nl').innerHTML = list.length ? '<div class="list mini-list">' + list.map(e => {
      const [ic, cls] = KIND[e.kind] || KIND.info;
      return `<div class="row" style="${e.read ? 'opacity:.65' : ''}"><span class="row-ic ${cls}"><i class="${ic}"></i></span><span class="row-body"><span class="row-title">${esc(e.title)}</span><span class="row-sub" style="white-space:normal">${e.account ? esc(e.account) + ': ' : ''}${esc(e.body)}</span></span><span class="row-end"><small class="num">${esc(e.time)}</small><button class="chev" data-del="${e.id}"><i class="fa-solid fa-xmark"></i></button></span></div>`;
    }).join('') + '</div>' : `<div class="card">${empty('fa-regular fa-bell', 'ما فيه إشعارات')}</div>`;
    el.querySelectorAll('[data-del]').forEach(b => b.onclick = async () => { await api('/events/' + b.dataset.del, { method: 'DELETE' }); load(); });
  }
  el.querySelector('#readAll').onclick = async () => { await api('/events/read_all', { body: {} }); document.getElementById('bellDot').classList.add('hidden'); load(); };
  await load();
  api('/events/read_all', { body: {} }); document.getElementById('bellDot').classList.add('hidden');
};

// ---------------------------------------------------------------- اشتراكات العملاء
R.subscribers = async (el) => {
  el.innerHTML = head('اشتراكات عملائي') + `
    <div class="note" style="margin-bottom:12px">سجّل عملاءك (مثلاً مشتركين الإنترنت أو النادي) والمنصة ترسلهم رسالة عند بدء الاشتراك، وتذكير قبل انتهائه، ورسالة يوم الانتهاء — تلقائياً من رقمك المتصل.</div>
    <div class="card"><div class="card-title"><i class="fa-solid fa-user-plus"></i> إضافة مشترك</div>
      <div class="input-row"><div class="field" style="flex:1"><label>الاسم</label><input class="input" id="sn"></div><div class="field" style="flex:1"><label>رقم واتساب</label><input class="input num" id="sp" inputmode="numeric" style="direction:ltr;text-align:left" placeholder="9647701234567"></div></div>
      <div class="input-row"><div class="field" style="flex:1"><label>تاريخ البداية</label><input class="input" id="sd" type="date"></div><div class="field" style="flex:1"><label>المدة (يوم)</label><input class="input num" id="sdur" type="number" placeholder="30"></div></div>
      <button class="btn btn-primary btn-block" id="sadd"><i class="fa-solid fa-plus"></i> إضافة</button></div>
    <div class="section-head">المشتركين <span class="pill pill-blue" id="scount">0</span></div>
    <div id="slist"></div>
    <div class="section-head">إعدادات الرسائل</div>
    <div class="card" id="sset"><div class="skel" style="height:80px"></div></div>`;
  el.querySelector('#sd').value = new Date().toISOString().slice(0, 10);
  async function loadList() {
    const r = await api('/subscribers');
    const list = Array.isArray(r) ? r : [];
    el.querySelector('#scount').textContent = list.length;
    el.querySelector('#slist').innerHTML = list.length ? '<div class="list mini-list">' + list.map(s => {
      const d = s.days_left, cls = d === null ? 'pill-gray' : d <= 0 ? 'pill-red' : d <= 3 ? 'pill-orange' : 'pill-green';
      return `<div class="row" style="flex-wrap:wrap"><span class="row-ic ic-purple"><i class="fa-solid fa-user"></i></span><span class="row-body"><span class="row-title">${esc(s.name)} <span class="pill ${cls}">${d === null ? '—' : d <= 0 ? 'منتهي' : d + ' يوم'}</span></span><span class="row-sub num" style="text-align:right">${esc(s.phone)} · ينتهي ${esc(s.expiry || '')}</span></span>
        <span class="row-end"><button class="btn btn-soft btn-xs" data-renew="${s.id}"><i class="fa-solid fa-rotate"></i> تجديد</button><button class="btn btn-red btn-xs" data-del="${s.id}"><i class="fa-solid fa-trash"></i></button></span></div>`;
    }).join('') + '</div>' : `<div class="card">${empty('fa-solid fa-users', 'ما أضفت مشتركين بعد')}</div>`;
    el.querySelectorAll('[data-renew]').forEach(b => b.onclick = () => confirmTap(b, async () => { const r = await api(`/subscribers/${b.dataset.renew}/renew`, { body: {} }); toast(r.ok ? 'تم التجديد من اليوم' : r.error, r.ok ? 'ok' : 'err'); loadList(); }, 'تأكيد التجديد'));
    el.querySelectorAll('[data-del]').forEach(b => b.onclick = () => confirmTap(b, async () => { await api(`/subscribers/${b.dataset.del}/delete`, { body: {} }); loadList(); }, 'تأكيد'));
  }
  el.querySelector('#sadd').onclick = async e => {
    busy(e.currentTarget, true);
    const r = await api('/subscribers', { body: { name: el.querySelector('#sn').value, phone: el.querySelector('#sp').value, subscribed_at: el.querySelector('#sd').value, duration_days: el.querySelector('#sdur').value } });
    busy(e.currentTarget, false);
    if (!r.ok) { toast(r.error, 'err'); return; }
    toast('تمت الإضافة وإرسال رسالة البداية', 'ok'); el.querySelector('#sn').value = ''; el.querySelector('#sp').value = ''; loadList();
  };
  const c = await api('/subscription_settings');
  el.querySelector('#sset').innerHTML = `
    <div class="input-row"><div class="field" style="flex:1"><label>المدة الافتراضية (يوم)</label><input class="input num" id="c1" type="number" value="${c.default_duration_days}"></div><div class="field" style="flex:1"><label>التذكير قبل (يوم)</label><input class="input num" id="c2" type="number" value="${c.reminder_days_before}"></div></div>
    <div class="field"><label>رسالة بداية الاشتراك</label><textarea class="textarea" id="c3" style="min-height:70px">${esc(c.start_message)}</textarea></div>
    <div class="field"><label>رسالة التذكير</label><textarea class="textarea" id="c4" style="min-height:70px">${esc(c.reminder_message)}</textarea></div>
    <div class="field"><label>رسالة الانتهاء</label><textarea class="textarea" id="c5" style="min-height:70px">${esc(c.expired_message)}</textarea></div>
    <div class="hint" style="margin-bottom:10px">استخدم {name} لاسم المشترك و {days} لعدد الأيام</div>
    <button class="btn btn-primary btn-block" id="csave">حفظ الإعدادات</button>`;
  el.querySelector('#csave').onclick = async e => {
    busy(e.currentTarget, true);
    const r = await api('/subscription_settings', { body: { default_duration_days: el.querySelector('#c1').value, reminder_days_before: el.querySelector('#c2').value, start_message: el.querySelector('#c3').value, reminder_message: el.querySelector('#c4').value, expired_message: el.querySelector('#c5').value } });
    busy(e.currentTarget, false); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err');
  };
  loadList();
};

// ---------------------------------------------------------------- مفاتيح API
R.apikeys = async (el) => {
  el.innerHTML = head('ربط API') + '<div id="ak"><div class="card"><div class="skel" style="height:80px"></div></div></div>';
  const [d, accs] = await Promise.all([api('/api_keys'), loadAccounts()]);
  const origin = location.origin;
  el.querySelector('#ak').innerHTML = `
    <div class="card"><div class="card-title"><i class="fa-solid fa-key"></i> مفتاح جديد</div>
      <div class="field"><label>رقم واتساب اللي ينرسل منه</label><select class="select" id="kacc">${accs.filter(a => a.logged_in).map(a => `<option value="${esc(a.id)}">${esc(a.name)}</option>`).join('') || '<option value="">اربط رقم متصل أولاً</option>'}</select></div>
      <div class="field"><label>اسم المفتاح (مثل: موقعي)</label><input class="input" id="klabel"></div>
      <button class="btn btn-primary btn-block" id="kgen"><i class="fa-solid fa-plus"></i> توليد مفتاح</button>
      <div id="knew"></div></div>
    <div class="section-head">مفاتيحك</div>
    <div class="list mini-list">${(d.keys || []).length ? d.keys.map(k => `<div class="row"><span class="row-ic ${k.revoked ? 'ic-red' : 'ic-orange'}"><i class="fa-solid fa-key"></i></span><span class="row-body"><span class="row-title">${esc(k.label || 'بدون اسم')} ${k.revoked ? '<span class="pill pill-red">ملغى</span>' : k.account_connected ? '<span class="pill pill-green">يعمل</span>' : '<span class="pill pill-orange">الرقم غير متصل</span>'}</span><span class="row-sub num" style="text-align:right">${esc(k.key_prefix)}… · آخر استخدام ${esc(k.last_used_at || '—')}</span></span>${k.revoked ? '' : `<span class="row-end"><button class="btn btn-red btn-xs" data-rev="${k.id}">إلغاء</button></span>`}</div>`).join('') : empty('fa-solid fa-key', 'ما فيه مفاتيح')}</div>
    <div class="section-head">طريقة الاستخدام</div>
    <div class="card"><p style="font-size:13px;color:var(--text2);margin-bottom:10px">أرسل طلب POST من موقعك (رمز تحقق أو رسالة):</p>
      <div class="code-block">curl -X POST ${esc(origin)}/api/v1/send \\
  -H "X-API-Key: wsl_xxxxxxxx" \\
  -H "Content-Type: application/json" \\
  -d '{"phone":"9647701234567","code":"123456"}'

# أو رسالة كاملة:
  -d '{"phone":"9647701234567","message":"أهلاً بك"}'</div>
      <div class="hint">الحد: 20 طلب بالدقيقة لكل مفتاح. ${SET.message_price > 0 ? `بدون اشتراك تنخصم ${fmt(SET.message_price)} د.ع لكل رسالة.` : ''}</div></div>`;
  el.querySelector('#kgen').onclick = async e => {
    busy(e.currentTarget, true);
    const r = await api('/api_keys', { body: { account_id: el.querySelector('#kacc').value, label: el.querySelector('#klabel').value } });
    busy(e.currentTarget, false);
    if (!r.ok) { toast(r.error, 'err'); return; }
    el.querySelector('#knew').innerHTML = `<div class="note ok fade-in" style="margin-top:12px"><b>انسخ المفتاح الآن - ما راح يظهر مرة ثانية:</b><div class="copy-box" style="margin-top:8px"><span class="v" style="font-size:13px">${esc(r.key)}</span><button class="btn btn-soft btn-xs" onclick="copy('${esc(r.key)}')">نسخ</button></div></div>`;
  };
  el.querySelectorAll('[data-rev]').forEach(b => b.onclick = () => confirmTap(b, async () => { await api('/api_keys/' + b.dataset.rev, { method: 'DELETE' }); go('apikeys', {}, false); }, 'تأكيد'));
};

// ================================================================ الإدارة
R.admin = async (el) => {
  el.innerHTML = head('لوحة الإدارة') + '<div class="stats"><div class="stat"><div class="skel" style="height:60px"></div></div><div class="stat"><div class="skel" style="height:60px"></div></div></div>';
  const s = await api('/api/admin/stats');
  const card = (ic, cls, v, l, page) => `<div class="stat" ${page ? `onclick="go('${page}')" style="cursor:pointer"` : ''}><span class="stat-ic ${cls}"><i class="${ic}"></i></span><span class="stat-v num">${v}</span><span class="stat-l">${l}</span></div>`;
  el.innerHTML = head('لوحة الإدارة') + `
    <div class="hero"><div style="display:flex;justify-content:space-between;align-items:center"><div><div class="hero-label">إيرادات الشحن</div><div class="hero-amount num">${fmt(s.revenue)}</div><div class="hero-sub">اليوم: ${fmt(s.revenue_today)} د.ع</div></div><span class="hero-icon"><i class="fa-solid fa-chart-line"></i></span></div></div>
    <div class="stats stagger" style="margin-top:12px">
      ${card('fa-solid fa-users', 'ic-blue', fmt(s.users), `المستخدمين (+${s.users_today} اليوم)`, 'users')}
      ${card('fa-solid fa-list-check', 'ic-red', fmt(s.pending_recharges), 'طلبات شحن معلّقة', 'recharges')}
      ${card('fa-solid fa-crown', 'ic-orange', fmt(s.active_plans), 'اشتراكات فعّالة', 'users')}
      ${card('fa-solid fa-gift', 'ic-purple', fmt(s.trials), 'بالفترة التجريبية', 'users')}
      ${card('fa-brands fa-whatsapp', 'ic-wa', `${s.wa_connected}/${s.wa_accounts}`, 'أرقام واتساب متصلة')}
      ${card('fa-solid fa-coins', 'ic-cyan', fmt(s.balances), 'مجموع أرصدة المستخدمين')}
    </div>
    <div class="card" style="margin-top:12px;display:flex;align-items:center;gap:12px"><span class="row-ic ${s.engine ? 'ic-green' : 'ic-red'}"><i class="fa-solid fa-server"></i></span><div style="flex:1"><b>محرك واتساب</b><div class="row-sub" style="white-space:normal">${s.engine_outdated ? '⚠️ نسخة قديمة - ارفع مجلد wasel-engine الجديد واضغط Restart' : s.engine ? 'شغّال ومتصل' : 'غير متصل - افتح صفحة install.php لتشغيله'}</div></div><a class="btn btn-ghost btn-sm" href="/install.php">الفحص</a></div>
    <div class="section-head">أقسام الإدارة</div>
    <div class="qa stagger">
      <button class="qa-item" onclick="go('plansAdmin')"><span class="row-ic ic-purple"><i class="fa-solid fa-gem"></i></span>الباقات</button>
      <button class="qa-item" onclick="go('payments')"><span class="row-ic ic-orange"><i class="fa-solid fa-credit-card"></i></span>طرق الدفع</button>
      <button class="qa-item" onclick="go('settings')"><span class="row-ic ic-purple"><i class="fa-solid fa-sliders"></i></span>الإعدادات</button>
      <button class="qa-item" onclick="go('security')"><span class="row-ic ic-red"><i class="fa-solid fa-shield-halved"></i></span>الأمان</button>
    </div>`;
};

R.users = async (el) => {
  el.innerHTML = head('المستخدمين') + `<input class="input" id="q" placeholder="بحث بالاسم أو البريد أو الرقم..."><div id="ul" style="margin-top:12px"></div>`;
  let t;
  async function load() {
    const r = await api('/api/admin/users?q=' + encodeURIComponent(el.querySelector('#q').value));
    const users = r.users || [];
    el.querySelector('#ul').innerHTML = users.length ? users.map(u => {
      const plan = u.plan_active ? `<span class="pill pill-green">مشترك ${u.plan_days_left === null ? 'دائم' : u.plan_days_left + ' يوم'}</span>` : u.trial_active ? `<span class="pill pill-orange">تجربة ${u.trial_days_left} يوم</span>` : '<span class="pill pill-gray">بدون اشتراك</span>';
      return `<div class="card" data-u="${u.id}">
        <div style="display:flex;align-items:center;gap:12px;cursor:pointer" data-open>
          <span class="row-ic ${u.banned ? 'ic-red' : u.is_admin ? 'ic-pink' : 'ic-blue'}"><i class="fa-solid ${u.banned ? 'fa-ban' : u.is_admin ? 'fa-user-shield' : 'fa-user'}"></i></span>
          <div style="flex:1;min-width:0"><b>${esc(u.name || 'بدون اسم')}</b> ${u.banned ? '<span class="pill pill-red">محظور</span>' : ''}<div class="row-sub num" style="text-align:right">${esc(u.email || (u.phone ? '+' + u.phone : ''))}</div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:5px">${plan}<span class="pill pill-blue num">${fmt(u.balance)} د.ع</span><span class="pill pill-gray">${u.wa_count} أرقام</span></div></div>
          <span class="chev"><i class="fa-solid fa-chevron-down"></i></span></div>
        <div class="hidden" data-panel style="margin-top:12px;border-top:1px dashed var(--line2);padding-top:12px">
          <div class="kv"><span>آخر دخول</span><b class="num">${esc(u.last_login_at || '—')} ${u.last_ip ? '· ' + esc(u.last_ip) : ''}</b></div>
          <div class="kv"><span>تاريخ التسجيل</span><b class="num">${esc(u.created_at)}</b></div>
          <div class="input-row" style="margin-top:10px"><input class="input num" data-v="bal" type="number" placeholder="مبلغ (سالب للخصم)"><button class="btn btn-soft" data-a="balance_add" style="height:48px">الرصيد</button></div>
          <div class="input-row" style="margin-top:8px"><input class="input num" data-v="plan" type="number" placeholder="أيام الاشتراك (0 للإلغاء)"><button class="btn btn-soft" data-a="plan_days" style="height:48px">اشتراك</button></div>
          <div class="input-row" style="margin-top:8px"><input class="input num" data-v="trial" type="number" placeholder="أيام تجربة مجانية من اليوم"><button class="btn btn-soft" data-a="trial_days" style="height:48px">تجربة</button></div>
          <div class="input-row" style="margin-top:10px">
            <button class="btn ${u.banned ? 'btn-green' : 'btn-red'} btn-sm" style="flex:1" data-a="${u.banned ? 'unban' : 'ban'}">${u.banned ? 'رفع الحظر' : 'حظر'}</button>
            <button class="btn btn-ghost btn-sm" style="flex:1" data-a="${u.is_admin ? 'unadmin' : 'admin'}">${u.is_admin ? 'إزالة الأدمن' : 'جعله أدمن'}</button></div>
        </div></div>`;
    }).join('') : `<div class="card">${empty('fa-solid fa-users', 'ما فيه نتائج')}</div>`;
    el.querySelectorAll('[data-u]').forEach(c => {
      c.querySelector('[data-open]').onclick = () => c.querySelector('[data-panel]').classList.toggle('hidden');
      c.querySelectorAll('[data-a]').forEach(b => b.onclick = () => {
        const a = b.dataset.a, map = { balance_add: 'bal', plan_days: 'plan', trial_days: 'trial' };
        const value = map[a] ? c.querySelector(`[data-v="${map[a]}"]`).value : 0;
        if (map[a] && value === '') { toast('اكتب القيمة', 'err'); return; }
        const run = async () => { busy(b, true); const r = await api('/api/admin/users/' + c.dataset.u, { body: { action: a, value } }); busy(b, false); toast(r.ok ? 'تم' : r.error, r.ok ? 'ok' : 'err'); if (r.ok) load(); };
        ['ban', 'admin', 'unadmin'].includes(a) ? confirmTap(b, run, 'تأكيد') : run();
      });
    });
  }
  el.querySelector('#q').oninput = () => { clearTimeout(t); t = setTimeout(load, 300); };
  load();
};

R.recharges = async (el, p) => {
  let status = p.status || 'pending';
  el.innerHTML = head('طلبات الشحن') + `<div class="tabs"><button class="tab" data-s="pending">معلّقة</button><button class="tab" data-s="approved">مقبولة</button><button class="tab" data-s="rejected">مرفوضة</button></div><div id="rl"></div>`;
  async function load() {
    el.querySelectorAll('.tab').forEach(t => t.classList.toggle('on', t.dataset.s === status));
    const r = await api('/api/admin/recharges?status=' + status);
    const items = r.items || [];
    el.querySelector('#rl').innerHTML = items.length ? items.map(x => `<div class="card fade-in" data-r="${x.id}">
      <div style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-green"><i class="fa-solid fa-wallet"></i></span>
        <div style="flex:1;min-width:0"><b class="num">${fmt(x.amount)} د.ع</b> <span class="pill pill-gray">${esc(x.method_name)}</span>${x.auto ? ' <span class="pill pill-blue">تلقائي</span>' : ''}
        <div class="row-sub">${esc(x.user_name)} · <span class="num">${esc(x.user_contact)}</span></div><div class="row-sub num" style="text-align:right">${esc(x.created_at)}${x.ref ? ' · ' + esc(x.ref) : ''}${x.orig_amount ? ` · $${esc(x.orig_amount)}` : ''}</div></div></div>
      ${x.receipt_url ? `<img class="receipt" style="margin-top:10px" src="${esc(x.receipt_url)}" onclick="this.classList.toggle('big')">` : ''}
      ${x.status === 'pending' ? `<div class="input-row" style="margin-top:10px"><input class="input num" data-amt type="number" value="${x.amount}" title="المبلغ المضاف"><input class="input" data-note placeholder="ملاحظة (اختياري)"></div>
        <div class="input-row" style="margin-top:8px"><button class="btn btn-green btn-sm" style="flex:1" data-act="approve"><i class="fa-solid fa-check"></i> قبول وإضافة</button><button class="btn btn-red btn-sm" style="flex:1" data-act="reject"><i class="fa-solid fa-xmark"></i> رفض</button></div>`
        : x.admin_note ? `<div class="hint">ملاحظة: ${esc(x.admin_note)}</div>` : ''}</div>`).join('') : `<div class="card">${empty('fa-solid fa-inbox', 'ما فيه طلبات')}</div>`;
    el.querySelectorAll('[data-r]').forEach(c => c.querySelectorAll('[data-act]').forEach(b => b.onclick = () => {
      const run = async () => {
        busy(b, true);
        const r = await api(`/api/admin/recharges/${c.dataset.r}/${b.dataset.act}`, { body: { amount: c.querySelector('[data-amt]').value, note: c.querySelector('[data-note]').value } });
        busy(b, false); toast(r.ok ? 'تم' : r.error, r.ok ? 'ok' : 'err'); if (r.ok) load();
      };
      b.dataset.act === 'reject' ? confirmTap(b, run, 'تأكيد الرفض') : run();
    }));
  }
  el.querySelectorAll('.tab').forEach(t => t.onclick = () => { status = t.dataset.s; load(); });
  load();
};

R.payments = async (el) => {
  el.innerHTML = head('طرق الدفع') + '<div id="pl"></div>';
  async function load() {
    const r = await api('/api/admin/payment-methods');
    const types = r.types || {};
    el.querySelector('#pl').innerHTML = (r.methods || []).map(m => {
      const [icon, ic] = PM_ICON[m.type] || PM_ICON.manual;
      return `<div class="card" data-pm="${m.id}" data-type="${m.type}">
        <div style="display:flex;align-items:center;gap:12px;cursor:pointer" data-open>
          <span class="row-ic ${ic}">${m.image ? `<img src="${esc(m.image)}">` : `<i class="${icon}"></i>`}</span>
          <div style="flex:1"><b>${esc(m.name)}</b> <span class="pill pill-gray">${esc(types[m.type] || m.type)}</span><div class="row-sub">${esc(m.number || 'لم يُضبط الرقم')}</div></div>
          <label class="switch" onclick="event.stopPropagation()"><input type="checkbox" data-active ${m.active ? 'checked' : ''}><span></span></label></div>
        <div class="hidden" data-panel style="margin-top:14px">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px"><span class="row-ic ${ic}" style="width:64px;height:64px" data-prev>${m.image ? `<img src="${esc(m.image)}">` : `<i class="${icon}"></i>`}</span>
            <label class="btn btn-soft btn-sm"><input type="file" accept="image/*" data-logo hidden><i class="fa-solid fa-image"></i> تغيير الشعار</label>
            ${m.image ? '<button class="btn btn-red btn-sm" data-rmlogo>حذف</button>' : ''}</div>
          <div class="field"><label>الاسم</label><input class="input" data-f="name" value="${esc(m.name)}"></div>
          <div class="field"><label>${m.type === 'binance' ? 'Binance Pay ID' : m.type === 'asiacell' ? 'رقم آسياسيل المستلم' : 'رقم الحساب / البطاقة'}</label><input class="input num" data-f="number" value="${esc(m.number)}" style="direction:ltr;text-align:left"></div>
          <div class="field"><label>وصف يظهر للمستخدم</label><input class="input" data-f="note" value="${esc(m.note || '')}"></div>
          <div class="input-row"><div class="field" style="flex:1"><label>أقل مبلغ (د.ع)</label><input class="input num" data-f="min_amount" type="number" value="${m.min_amount}"></div><div class="field" style="flex:1"><label>الترتيب</label><input class="input num" data-f="sort" type="number" value="${m.sort}"></div></div>
          ${m.type === 'binance' ? `<div class="field"><label>API Key ${m.has_api_key ? '<span class="pill pill-green">محفوظ</span>' : ''}</label><input class="input" data-f="api_key" placeholder="اتركه فارغ لإبقاء الحالي" style="direction:ltr"></div>
            <div class="field"><label>API Secret</label><input class="input" data-f="api_secret" type="password" placeholder="اتركه فارغ لإبقاء الحالي" style="direction:ltr"></div>
            <label class="file-drop" style="margin-bottom:12px"><input type="file" accept="image/*" data-qr><span class="row-ic ic-orange"><i class="fa-solid fa-qrcode"></i></span><span>صورة QR للدفع (اختياري)</span></label>
            <div class="hint" style="margin-bottom:10px">المفاتيح من Binance ← API Management بصلاحية قراءة فقط (Enable Reading)</div>` : ''}
          ${m.type === 'asiacell' ? `<div class="field"><label>هدية لكل مبلغ (د.ع)</label><div class="tier-grid">${(m.tiers || []).map(t => `<div class="tier" style="padding:8px"><span class="num" style="font-size:13px">${fmt(t.iqd)}</span><input class="input num" data-tier="${t.iqd}" type="number" value="${t.bonus}" style="height:36px;margin-top:4px;text-align:center"></div>`).join('')}</div></div>
            <div class="field"><label>أقصى تحويل بالدفعة الواحدة</label><input class="input num" data-f="max_transfer" type="number" value="${m.max_transfer}"></div>` : ''}
          <div class="input-row"><button class="btn btn-primary btn-sm" style="flex:2" data-save>حفظ</button><button class="btn btn-red btn-sm" style="flex:1" data-del><i class="fa-solid fa-trash"></i></button></div>
        </div></div>`;
    }).join('') + `<div class="section-head">إضافة طريقة دفع</div><div class="card"><div class="input-row"><select class="select" id="newType" style="flex:1">${Object.entries(types).map(([k, v]) => `<option value="${k}">${esc(v)}</option>`).join('')}</select><button class="btn btn-primary" id="addPm"><i class="fa-solid fa-plus"></i> إضافة</button></div></div>`;
    el.querySelectorAll('[data-pm]').forEach(c => {
      const id = c.dataset.pm; let logo = null, qr = null, rmLogo = false;
      c.querySelector('[data-open]').onclick = () => c.querySelector('[data-panel]').classList.toggle('hidden');
      c.querySelector('[data-active]').onchange = async e => { const r = await api('/api/admin/payment-methods/' + id, { body: { active: e.target.checked } }); toast(r.ok ? (e.target.checked ? 'تم التفعيل' : 'تم الإيقاف') : r.error, r.ok ? 'ok' : 'err'); };
      c.querySelector('[data-logo]').onchange = async e => { const f = e.target.files[0]; if (!f) return; logo = await fileToDataUrl(f); c.querySelector('[data-prev]').innerHTML = `<img src="${logo}">`; };
      const rm = c.querySelector('[data-rmlogo]'); if (rm) rm.onclick = () => { rmLogo = true; c.querySelector('[data-prev]').innerHTML = '<i class="fa-solid fa-image"></i>'; };
      const q = c.querySelector('[data-qr]'); if (q) q.onchange = async e => { qr = e.target.files[0] ? await fileToDataUrl(e.target.files[0]) : null; toast('تم اختيار صورة QR'); };
      c.querySelector('[data-save]').onclick = async e => {
        const body = {}; c.querySelectorAll('[data-f]').forEach(i => body[i.dataset.f] = i.value);
        const tiers = [...c.querySelectorAll('[data-tier]')].map(i => ({ iqd: +i.dataset.tier, bonus: +i.value || 0 }));
        if (tiers.length) body.tiers = tiers;
        if (logo) body.image = logo; if (rmLogo) body.remove_image = true; if (qr) body.qr = qr;
        busy(e.currentTarget, true); const r = await api('/api/admin/payment-methods/' + id, { body }); busy(e.currentTarget, false);
        toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); if (r.ok) load();
      };
      c.querySelector('[data-del]').onclick = e => confirmTap(e.currentTarget, async () => { await api('/api/admin/payment-methods/' + id, { method: 'DELETE' }); load(); }, 'تأكيد');
    });
    el.querySelector('#addPm').onclick = async () => { const r = await api('/api/admin/payment-methods', { body: { type: el.querySelector('#newType').value, active: false } }); if (r.ok) { toast('تمت الإضافة - اضبطها وفعّلها', 'ok'); load(); } };
  }
  load();
};

R.settings = async (el) => {
  el.innerHTML = head('الإعدادات') + '<div class="card"><div class="skel" style="height:200px"></div></div>';
  const { settings: s } = await api('/api/admin/settings');
  const cc = B.country_codes.map(([c, l]) => `<option value="${c}" ${c === s.default_country_code ? 'selected' : ''}>${esc(l)}</option>`).join('');
  const f = (id, label, val, type = 'text', hint = '') => `<div class="field"><label>${label}</label><input class="input ${type === 'number' ? 'num' : ''}" data-k="${id}" type="${type}" value="${esc(val ?? '')}" ${type !== 'text' || /email|client|token|chat/.test(id) ? 'style="direction:ltr;text-align:left"' : ''}>${hint ? `<div class="hint">${hint}</div>` : ''}</div>`;
  el.innerHTML = head('الإعدادات') + `
    <div class="section-head">الأسعار والفترات</div>
    <div class="card">
      <div class="note" style="margin-bottom:14px">أسعار ومدد الاشتراك تتعدّل من قسم <a onclick="go('plansAdmin')" style="cursor:pointer;font-weight:800">الباقات</a></div>
      <div class="input-row">${f('trial_days', 'الفترة المجانية (يوم)', s.trial_days, 'number', '0 = بدون تجربة')}${f('message_price', 'سعر الرسالة (د.ع)', s.message_price, 'number', '0 = الإرسال للمشتركين فقط')}</div>
      ${f('usd_rate', 'سعر الدولار بالدينار (لـ Binance)', s.usd_rate, 'number', 'مثال: 1500 يعني 1$ = 1500 د.ع')}
    </div>
    <div class="section-head">الهوية</div>
    <div class="card">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px"><span class="row-ic" style="width:64px;height:64px" id="logoPrev">${s.has_logo ? `<img src="/branding/logo?t=${Date.now()}">` : '<i class="fa-solid fa-image"></i>'}</span>
        <label class="btn btn-soft btn-sm"><input type="file" accept="image/*" id="logoIn" hidden><i class="fa-solid fa-upload"></i> رفع شعار</label>${s.has_logo ? '<button class="btn btn-red btn-sm" id="logoRm">حذف</button>' : ''}</div>
      <div class="hint" style="margin-bottom:12px">الشعار يظهر بالموقع ويصير أيقونة التطبيق (PWA)</div>
      ${f('site_name', 'اسم الموقع', s.site_name)}
      <div class="field"><label>رمز الدولة الافتراضي</label><select class="select" data-k="default_country_code">${cc}</select></div>
      ${f('whatsapp_support_number', 'رقم واتساب الدعم', s.whatsapp_support_number)}
    </div>
    <div class="section-head">تسجيل الدخول</div>
    <div class="card">
      ${f('google_client_id', 'Google Client ID', s.google_client_id, 'text', 'من Google Cloud Console ← Credentials ← OAuth Client (Web). أضف رابط موقعك في Authorized JavaScript origins')}
      ${f('admin_email', 'بريد الأدمن (حساب كوكل)', s.admin_email, 'email', 'من يدخل بهذا البريد عبر كوكل يصير أدمن تلقائياً')}
      <div style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-wa"><i class="fa-brands fa-whatsapp"></i></span><div style="flex:1"><b>تسجيل الدخول برقم واتساب</b><div class="row-sub">يحتاج رقم مفعّل كمرسل رموز</div></div><label class="switch"><input type="checkbox" data-k="whatsapp_login" ${s.whatsapp_login ? 'checked' : ''}><span></span></label></div>
    </div>
    <div class="section-head">الذكاء الاصطناعي (DeepSeek)</div>
    <div class="card">
      ${f('ai_api_key', 'مفتاح DeepSeek ' + (s.ai_key_set ? '(محفوظ)' : ''), '', 'password')}
      <div class="field"><label>معلومات المتجر/الخدمة للرد الذكي</label><textarea class="textarea" data-k="ai_knowledge_base">${esc(s.ai_knowledge_base)}</textarea></div>
    </div>
    <button class="btn btn-primary btn-block" id="saveSet" style="margin-top:14px"><i class="fa-solid fa-check"></i> حفظ كل الإعدادات</button>`;
  let logo;
  el.querySelector('#logoIn').onchange = async e => { const fl = e.target.files[0]; if (!fl) return; logo = await fileToDataUrl(fl); el.querySelector('#logoPrev').innerHTML = `<img src="${logo}">`; };
  const rm = el.querySelector('#logoRm'); if (rm) rm.onclick = () => { logo = ''; el.querySelector('#logoPrev').innerHTML = '<i class="fa-solid fa-image"></i>'; };
  el.querySelector('#saveSet').onclick = async e => {
    const body = {};
    el.querySelectorAll('[data-k]').forEach(i => body[i.dataset.k] = i.type === 'checkbox' ? i.checked : i.value);
    if (logo !== undefined) body.logo = logo;
    busy(e.currentTarget, true); const r = await api('/api/admin/settings', { body }); busy(e.currentTarget, false);
    toast(r.ok ? 'تم حفظ الإعدادات' : r.error, r.ok ? 'ok' : 'err');
    if (r.ok) { await refreshMe(); if (body.site_name) document.getElementById('brandName').textContent = body.site_name; }
  };
};

R.security = async (el) => {
  el.innerHTML = head('مركز الأمان') + '<div class="card"><div class="skel" style="height:120px"></div></div>';
  const s = await api('/api/admin/security');
  const M = { google: 'fa-brands fa-google', whatsapp: 'fa-brands fa-whatsapp', password: 'fa-solid fa-key' };
  el.innerHTML = head('مركز الأمان') + `
    <div class="stats stagger">
      <div class="stat"><span class="stat-ic ${s.failed_24h ? 'ic-red' : 'ic-green'}"><i class="fa-solid fa-user-lock"></i></span><span class="stat-v num">${s.failed_24h}</span><span class="stat-l">محاولات دخول فاشلة (24 ساعة)</span></div>
      <div class="stat"><span class="stat-ic ${s.https ? 'ic-green' : 'ic-red'}"><i class="fa-solid fa-lock"></i></span><span class="stat-v">${s.https ? 'HTTPS' : 'HTTP'}</span><span class="stat-l">${s.https ? 'الاتصال مشفّر' : 'فعّل SSL من الاستضافة'}</span></div>
      <div class="stat"><span class="stat-ic ic-pink"><i class="fa-solid fa-user-shield"></i></span><span class="stat-v num">${s.admins.length}</span><span class="stat-l">${s.admins.map(a => esc(a.name)).join('، ')}</span></div>
      <div class="stat"><span class="stat-ic ic-orange"><i class="fa-solid fa-ban"></i></span><span class="stat-v num">${s.banned.length}</span><span class="stat-l">مستخدمين محظورين</span></div>
    </div>
    <div class="section-head">إجراءات</div>
    <div class="list">
      ${rowItem({ icon: 'fa-solid fa-right-from-bracket', ic: 'ic-red', title: 'إنهاء جلسات كل المستخدمين', sub: 'الكل يحتاج يسجل دخول من جديد (أنت تبقى)', end: '<button class="btn btn-red btn-xs" id="lo">تنفيذ</button>' })}
      ${rowItem({ icon: 'fa-solid fa-server', ic: 'ic-blue', title: 'فحص السيرفر والمحرك', sub: 'صفحة install.php', onclick: "location.href='/install.php'" })}
      ${rowItem({ icon: 'fa-solid fa-broom', ic: 'ic-orange', title: 'مسح سجل الدخول', end: '<button class="btn btn-ghost btn-xs" id="cl">مسح</button>' })}
    </div>
    <div class="note" style="margin-top:12px"><b>الحماية المفعّلة:</b> ملفات البيانات محجوبة عن المتصفح، رموز التحقق محدودة بـ 5 محاولات، تأخير 45 ثانية بين طلبات الرمز، مفاتيح API مخزنة كبصمة SHA-256، حد 20 طلب/دقيقة لكل مفتاح، والجلسات تنتهي فوراً عند الحظر.</div>
    <div class="section-head">سجل الدخول</div>
    <div class="list mini-list">${s.log.length ? s.log.map(l => rowItem({
      icon: M[l.method] || 'fa-solid fa-right-to-bracket', ic: l.ok ? 'ic-green' : 'ic-red',
      title: `${esc(l.user || l.note || 'غير معروف')} ${l.ok ? '' : '<span class="pill pill-red">فشل</span>'}`, sub: `<span class="num">${esc(l.time)} · ${esc(l.ip)}</span> · ${esc((l.ua || '').slice(0, 40))}`, end: '',
    })).join('') : empty('fa-solid fa-list', 'السجل فارغ')}</div>`;
  el.querySelector('#lo').onclick = e => { e.stopPropagation(); confirmTap(e.currentTarget, async () => { const r = await api('/api/admin/security', { body: { action: 'logout_all' } }); toast(r.msg || r.error, r.ok ? 'ok' : 'err'); }, 'تأكيد'); };
  el.querySelector('#cl').onclick = e => { e.stopPropagation(); confirmTap(e.currentTarget, async () => { await api('/api/admin/security', { body: { action: 'clear_log' } }); go('security', {}, false); }, 'تأكيد'); };
};

R.backup = async (el) => {
  const { settings: s } = await api('/api/admin/settings');
  el.innerHTML = head('النسخ الاحتياطي') + `
    <div class="list">
      ${rowItem({ icon: 'fa-solid fa-download', ic: 'ic-blue', title: 'تنزيل نسخة JSON', sub: 'كل المستخدمين والأرصدة والإعدادات', onclick: "location.href='/api/admin/backup/download'" })}
      <label class="row"><input type="file" accept=".json,application/json" id="imp" hidden><span class="row-ic ic-orange"><i class="fa-solid fa-upload"></i></span><span class="row-body"><span class="row-title">استيراد نسخة JSON</span><span class="row-sub">يستبدل البيانات الحالية (تنحفظ نسخة قبلها)</span></span><span class="row-end"><span class="chev"><i class="fa-solid fa-chevron-left"></i></span></span></label>
    </div>
    <div class="section-head">بوت تيليجرام</div>
    <div class="card">
      <div class="numbered"><b>1</b><span>أنشئ بوت من <b>@BotFather</b> وانسخ التوكن</span></div>
      <div class="numbered"><b>2</b><span>أرسل أي رسالة للبوت، وخذ رقم محادثتك من <b>@userinfobot</b></span></div>
      <div class="field"><label>توكن البوت ${s.tg_token_set ? '<span class="pill pill-green">محفوظ</span>' : ''}</label><input class="input" id="tgt" placeholder="123456:ABC..." style="direction:ltr"></div>
      <div class="field"><label>رقم المحادثة (Chat ID)</label><input class="input num" id="tgc" value="${esc(s.tg_chat_id)}" style="direction:ltr;text-align:left"></div>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px"><span class="row-ic ic-cyan"><i class="fa-solid fa-clock"></i></span><div style="flex:1"><b>نسخة تلقائية يومية</b><div class="row-sub">آخر نسخة: ${esc(s.tg_last_backup || '—')}</div></div><label class="switch"><input type="checkbox" id="tga" ${s.tg_auto_backup ? 'checked' : ''}><span></span></label></div>
      <button class="btn btn-primary btn-block" id="tgs">حفظ</button>
      <div class="input-row" style="margin-top:8px"><button class="btn btn-soft btn-sm" style="flex:1" id="tgn"><i class="fa-brands fa-telegram"></i> إرسال نسخة الآن</button><button class="btn btn-ghost btn-sm" style="flex:1" id="tgw"><i class="fa-solid fa-link"></i> تفعيل الاستيراد</button></div>
      <div class="note" style="margin-top:12px">بعد «تفعيل الاستيراد»: أرسل <b>/backup</b> للبوت لتستلم نسخة، أو أرسل له ملف JSON حتى يستورده للموقع مباشرة (من محادثتك فقط).</div>
    </div>`;
  el.querySelector('#imp').onchange = async e => {
    const fl = e.target.files[0]; if (!fl) return;
    const fd = new FormData(); fd.append('file', fl);
    const r = await api('/api/admin/backup/import', { body: fd });
    toast(r.ok ? r.msg : r.error, r.ok ? 'ok' : 'err');
  };
  el.querySelector('#tgs').onclick = async e => { busy(e.currentTarget, true); const r = await api('/api/admin/settings', { body: { tg_token: el.querySelector('#tgt').value, tg_chat_id: el.querySelector('#tgc').value, tg_auto_backup: el.querySelector('#tga').checked } }); busy(e.currentTarget, false); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); };
  el.querySelector('#tgn').onclick = async e => { busy(e.currentTarget, true); const r = await api('/api/admin/backup/telegram', { body: {} }); busy(e.currentTarget, false); toast(r.ok ? r.msg : r.error, r.ok ? 'ok' : 'err'); };
  el.querySelector('#tgw').onclick = async e => { busy(e.currentTarget, true); const r = await api('/api/admin/telegram/webhook', { body: {} }); busy(e.currentTarget, false); toast(r.ok ? r.msg : r.error, r.ok ? 'ok' : 'err'); };
};

R.plansAdmin = async (el) => {
  el.innerHTML = head('إدارة الباقات') + '<div id="pa"></div>';
  async function load() {
    const r = await api('/api/admin/plans');
    const colors = r.colors || [];
    const CN = { teal: 'فيروزي', amber: 'ذهبي', violet: 'بنفسجي', coral: 'مرجاني', emerald: 'أخضر', sky: 'سماوي' };
    el.querySelector('#pa').innerHTML = (r.plans || []).map(pl => `<div class="card" data-pl="${pl.id}">
      <div style="display:flex;align-items:center;gap:12px;cursor:pointer" data-open>
        <span class="row-ic" style="background:var(--g-${pl.color});color:#fff"><i class="fa-solid fa-gem"></i></span>
        <div style="flex:1"><b>${esc(pl.name)}</b> ${pl.popular ? '<span class="pill pill-orange">الأكثر طلباً</span>' : ''}<div class="row-sub num" style="text-align:right">${fmt(pl.price)} د.ع · ${fmt(pl.days)} يوم</div></div>
        <label class="switch" onclick="event.stopPropagation()"><input type="checkbox" data-active ${pl.active ? 'checked' : ''}><span></span></label></div>
      <div class="hidden" data-panel style="margin-top:14px">
        <div class="field"><label>اسم الباقة</label><input class="input" data-f="name" value="${esc(pl.name)}"></div>
        <div class="input-row"><div class="field" style="flex:1"><label>السعر (د.ع)</label><input class="input num" type="number" data-f="price" value="${pl.price}"></div><div class="field" style="flex:1"><label>المدة (يوم)</label><input class="input num" type="number" data-f="days" value="${pl.days}"></div></div>
        <div class="field"><label>المزايا (كل ميزة بسطر)</label><textarea class="textarea" data-f="features" style="min-height:90px">${esc(pl.features || '')}</textarea></div>
        <div class="field"><label>اللون</label><div class="chips">${colors.map(c => `<button class="chip ${c === pl.color ? 'on' : ''}" data-color="${c}"><span style="width:14px;height:14px;border-radius:50%;background:var(--g-${c})"></span>${CN[c] || c}</button>`).join('')}</div></div>
        <div class="input-row" style="align-items:center"><div class="field" style="flex:1"><label>الترتيب</label><input class="input num" type="number" data-f="sort" value="${pl.sort}"></div>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700"><label class="switch"><input type="checkbox" data-popular ${pl.popular ? 'checked' : ''}><span></span></label> الأكثر طلباً</label></div>
        <div class="input-row"><button class="btn btn-primary btn-sm" style="flex:2" data-save>حفظ</button><button class="btn btn-red btn-sm" style="flex:1" data-del><i class="fa-solid fa-trash"></i></button></div>
      </div></div>`).join('') + `<button class="btn btn-primary btn-block" id="addPl" style="margin-top:14px"><i class="fa-solid fa-plus"></i> إضافة باقة</button>`;
    el.querySelectorAll('[data-pl]').forEach(c => {
      const id = c.dataset.pl; let color = null;
      c.querySelector('[data-open]').onclick = () => c.querySelector('[data-panel]').classList.toggle('hidden');
      c.querySelector('[data-active]').onchange = async e => { await api('/api/admin/plans/' + id, { body: { active: e.target.checked } }); toast(e.target.checked ? 'الباقة ظاهرة للمستخدمين' : 'تم إخفاء الباقة', 'ok'); };
      c.querySelectorAll('[data-color]').forEach(b => b.onclick = () => { color = b.dataset.color; c.querySelectorAll('[data-color]').forEach(x => x.classList.toggle('on', x === b)); });
      c.querySelector('[data-save]').onclick = async e => {
        const body = { popular: c.querySelector('[data-popular]').checked }; c.querySelectorAll('[data-f]').forEach(i => body[i.dataset.f] = i.value); if (color) body.color = color;
        busy(e.currentTarget, true); const r = await api('/api/admin/plans/' + id, { body }); busy(e.currentTarget, false); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); if (r.ok) load();
      };
      c.querySelector('[data-del]').onclick = e => confirmTap(e.currentTarget, async () => { await api('/api/admin/plans/' + id, { method: 'DELETE' }); load(); }, 'تأكيد');
    });
    el.querySelector('#addPl').onclick = async () => { await api('/api/admin/plans', { body: { name: 'باقة جديدة', price: 10000, days: 30, color: 'sky', active: false } }); toast('تمت الإضافة - عدّلها وفعّلها', 'ok'); load(); };
  }
  load();
};

R.announce = async (el) => {
  const accs = (await loadAccounts()).filter(a => a.logged_in);
  el.innerHTML = head('إعلان جماعي') + `<div class="card">
    <div class="note" style="margin-bottom:12px">يرسل رسالة واتساب لكل مستخدمي المنصة اللي مسجلين برقم واتساب.</div>
    <div class="field"><label>الإرسال من</label><select class="select" id="aa">${accs.map(a => `<option value="${esc(a.id)}">${esc(a.name)}</option>`).join('') || '<option value="">ما عندك رقم متصل</option>'}</select></div>
    <div class="field"><label>نص الإعلان</label><textarea class="textarea" id="at"></textarea></div>
    <div class="field"><label>الفاصل بين الرسائل (ثانية)</label><input class="input num" id="ad" type="number" value="15"></div>
    <button class="btn btn-primary btn-block" id="as"><i class="fa-solid fa-bullhorn"></i> إرسال الإعلان</button></div>`;
  el.querySelector('#as').onclick = async e => {
    const fd = new FormData(); fd.append('text', el.querySelector('#at').value); fd.append('delay', el.querySelector('#ad').value);
    busy(e.currentTarget, true); const r = await api('/admin/announcement/' + el.querySelector('#aa').value, { body: fd }); busy(e.currentTarget, false);
    toast(r.ok ? `بدأ الإرسال لـ ${r.total} مستخدم` : r.error, r.ok ? 'ok' : 'err');
  };
};

// ================================================================ الإشعارات بالخلفية
async function pollEvents() {
  const ev = await api('/events?since=' + S.lastEvent);
  if (!Array.isArray(ev) || !ev.length) return;
  const first = S.lastEvent === 0;
  S.lastEvent = Math.max(...ev.map(e => e.id));
  if (ev.some(e => !e.read)) document.getElementById('bellDot').classList.remove('hidden');
  if (!first) {
    ev.forEach(e => { toast(e.title + ' — ' + e.body, e.kind === 'warning' ? 'err' : 'ok'); if (Notification?.permission === 'granted') navigator.serviceWorker?.ready.then(r => r.showNotification(e.title, { body: e.body, icon: '/pwa-192.png' })).catch(() => {}); });
    if (ev.some(e => /رصيد|شحن|اشتراك/.test(e.title))) refreshMe();
  }
}

// ================================================================ الإقلاع
try { if (localStorage.getItem('theme') === 'dark') document.documentElement.dataset.theme = 'dark'; } catch (e) {}
document.getElementById('balChip').textContent = fmt(ME.balance);
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
setTimeout(() => { if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission().catch(() => {}); }, 4000);
pollEvents(); setInterval(pollEvents, 8000); setInterval(refreshMe, 30000);
const startPage = ADMIN && B.section ? (SLUG2PAGE[B.section] || 'home') : 'home';
const startParams = Object.fromEntries(new URLSearchParams(location.search));
history.replaceState({ page: startPage, params: startParams }, '', location.pathname + location.search);
go(startPage, startParams, false);
