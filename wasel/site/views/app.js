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
  const init = { method: opt.method || (opt.body !== undefined ? 'POST' : 'GET'), headers: { 'X-Requested-With': 'wasel' } };
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
  } catch (e) {
    if (!navigator.onLine) setOffline(true);
    return { ok: false, error: 'تعذر الاتصال - تأكد من الإنترنت' };
  }
}

// شاشة «ما فيه إنترنت» داخل التطبيق: تظهر لما ينقطع الاتصال، وتختفي وتحدّث الصفحة لما يرجع
function setOffline(on) {
  let o = document.getElementById('offlineScr');
  if (on && !o) {
    document.body.insertAdjacentHTML('beforeend', `<div id="offlineScr" class="offline-scr"><div class="off-box">
      <div class="off-ic"><i class="fa-solid fa-wifi"></i><span></span></div>
      <h2>ما فيه اتصال بالإنترنت</h2>
      <p>تأكد من الواي فاي أو بيانات الجوال - التطبيق يرجع لوحده أول ما يرجع الاتصال</p>
      <button class="btn btn-primary btn-block" onclick="location.reload()"><i class="fa-solid fa-rotate-right"></i> إعادة المحاولة</button>
      <div class="off-st"><span class="dot"></span> ننتظر رجوع الاتصال...</div></div></div>`);
    requestAnimationFrame(() => document.getElementById('offlineScr').classList.add('show'));
  } else if (!on && o) {
    o.classList.remove('show'); setTimeout(() => o.remove(), 350);
    if (typeof S !== 'undefined' && S.page) go(S.page, S.params || {}, false);
    toast('رجع الاتصال بالإنترنت', 'ok');
  }
}
window.addEventListener('offline', () => setOffline(true));
window.addEventListener('online', () => setOffline(false));

let toastT;
function toast(msg, kind = '') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.className = 'toast show ' + kind;
  clearTimeout(toastT); toastT = setTimeout(() => t.className = 'toast ' + kind, 2800);
}
function busy(btn, on) {
  if (!btn) return;
  btn.classList.toggle('loading', on); btn.disabled = on;
  clearTimeout(btn._bt);
  // أمان: الزر ما يبقى معلّق لو تأخر الرد
  if (on) btn._bt = setTimeout(() => { btn.classList.remove('loading'); btn.disabled = false; }, 20000);
}
/** نافذة تأكيد تطلع من الأسفل - ترجع true لو أكّد */
function confirmSheet({ title = 'هل أنت متأكد؟', text = '', ok = 'تأكيد', icon = 'fa-solid fa-triangle-exclamation', danger = true } = {}) {
  return new Promise(res => {
    const bg = document.getElementById('cfBg'), sh = document.getElementById('cfSheet');
    document.getElementById('cfTitle').textContent = title;
    document.getElementById('cfText').textContent = text;
    const ic = document.getElementById('cfIc'); ic.className = 'confirm-ic ' + (danger ? 'ic-red' : 'ic-teal'); ic.innerHTML = `<i class="${icon}"></i>`;
    const yes = document.getElementById('cfYes'), no = document.getElementById('cfNo');
    yes.textContent = ok; yes.className = 'btn ' + (danger ? 'btn-danger' : 'btn-primary');
    const close = v => { bg.classList.remove('show'); sh.classList.remove('show'); yes.onclick = no.onclick = bg.onclick = null; res(v); };
    yes.onclick = () => close(true); no.onclick = () => close(false); bg.onclick = () => close(false);
    bg.classList.add('show'); sh.classList.add('show');
  });
}
/** زر يحتاج تأكيد: يفتح نافذة التأكيد السفلية */
async function confirmTap(btn, fn, label = '', opts = {}) {
  const action = (btn?.textContent || '').trim();
  const ok = await confirmSheet({ title: opts.title || 'تأكيد الإجراء', text: opts.text || label || (action ? action + '؟' : ''), ok: opts.ok || action || 'تأكيد', icon: opts.icon, danger: opts.danger !== false });
  if (ok) fn();
}
// ---------------- اختيار رمز الدولة (بطاقة صغيرة لامعة + قائمة بحث من الأسفل)
const ccLabel = c => (B.country_codes.find(x => x[0] === c) || [c, '+' + c])[1];
function ccButton(id, val = B.cc) {
  return `<button type="button" class="cc-pick" id="${id}" data-cc="${esc(val)}"><span class="num">${esc(ccLabel(val))}</span><i class="fa-solid fa-chevron-down"></i></button>`;
}
function ccSheet(current) {
  return new Promise(res => {
    let bg = document.getElementById('ccBg'), sh = document.getElementById('ccSh');
    if (!sh) {
      document.body.insertAdjacentHTML('beforeend', `<div class="sheet-bg" id="ccBg"></div><div class="sheet cc-sheet" id="ccSh"><div class="sheet-handle"></div><h3 style="margin:0 0 12px">اختر رمز الدولة</h3><input class="input" id="ccQ" placeholder="ابحث بالرمز... مثل 964"><div class="cc-list" id="ccList"></div></div>`);
      bg = document.getElementById('ccBg'); sh = document.getElementById('ccSh');
    }
    const list = document.getElementById('ccList'), q = document.getElementById('ccQ');
    const draw = () => { const t = q.value.replace(/\D/g, ''); list.innerHTML = B.country_codes.filter(([c]) => !t || c.startsWith(t)).map(([c, l]) => `<button class="cc-item ${c === current ? 'on' : ''}" data-c="${c}"><span class="num">${esc(l)}</span>${c === current ? '<i class="fa-solid fa-check"></i>' : ''}</button>`).join(''); };
    const close = v => { bg.classList.remove('show'); sh.classList.remove('show'); res(v); };
    q.value = ''; draw(); q.oninput = draw;
    list.onclick = e => { const b = e.target.closest('[data-c]'); if (b) close(b.dataset.c); };
    bg.onclick = () => close(null);
    bg.classList.add('show'); sh.classList.add('show');
  });
}
document.addEventListener('click', async e => {
  const b = e.target.closest('.cc-pick'); if (!b) return;
  const c = await ccSheet(b.dataset.cc);
  if (c) { b.dataset.cc = c; b.querySelector('span').textContent = ccLabel(c); b.dispatchEvent(new Event('change', { bubbles: true })); }
});

function every(ms, fn) { const t = setInterval(fn, ms); S.timers.push(t); return t; }
function head(title, back = true, extra = '') {
  return `<div class="page-head">${back ? `<button class="back" onclick="goBack()"><i class="fa-solid fa-arrow-right"></i></button>` : ''}<h2>${title}</h2>${extra}</div>`;
}
const ILL_PLANE = `<svg viewBox="0 0 220 150" class="ill" aria-hidden="true"><defs><linearGradient id="ig" x1="0" x2="1"><stop offset="0" stop-color="var(--ill1)"/><stop offset="1" stop-color="var(--ill2)"/></linearGradient></defs>
  <ellipse cx="110" cy="132" rx="74" ry="9" fill="var(--ill-shadow)"/><circle cx="110" cy="70" r="56" fill="var(--ill-bg)"/>
  <path d="M40 104 C70 96 78 70 108 72" fill="none" stroke="var(--ill2)" stroke-width="2.5" stroke-dasharray="4 6" stroke-linecap="round" opacity=".7"/>
  <g transform="translate(104 34) rotate(8)"><path d="M0 34 L62 0 L46 64 L30 44 Z" fill="url(#ig)"/><path d="M30 44 L62 0 L22 40 Z" fill="#fff" opacity=".35"/><path d="M22 40 L30 44 L26 58 Z" fill="var(--ill1)" opacity=".8"/></g>
  <g fill="#fff"><ellipse cx="58" cy="44" rx="16" ry="8"/><ellipse cx="68" cy="40" rx="11" ry="9"/><ellipse cx="170" cy="96" rx="14" ry="7"/><ellipse cx="160" cy="93" rx="9" ry="8"/></g>
  <circle cx="176" cy="38" r="4" fill="var(--ill2)" opacity=".6"/><circle cx="44" cy="84" r="3" fill="var(--ill1)" opacity=".5"/></svg>`;
const ILL_BASE = `<svg viewBox="0 0 220 150" class="ill" aria-hidden="true"><ellipse cx="110" cy="132" rx="70" ry="9" fill="var(--ill-shadow)"/><circle cx="110" cy="66" r="56" fill="var(--ill-bg)"/>
  <g fill="#fff"><ellipse cx="52" cy="50" rx="15" ry="7"/><ellipse cx="62" cy="46" rx="10" ry="8"/><ellipse cx="172" cy="98" rx="13" ry="7"/><ellipse cx="163" cy="95" rx="8" ry="7"/></g>
  <circle cx="178" cy="36" r="4" fill="var(--ill2)" opacity=".6"/><circle cx="40" cy="92" r="3" fill="var(--ill1)" opacity=".5"/><circle cx="150" cy="22" r="2.5" fill="var(--ill1)" opacity=".4"/></svg>`;
/** حالة فارغة برسمة توضيحية بدل الفراغ */
function empty(icon, text, btn = '') {
  const art = /paper-plane/.test(icon) ? ILL_PLANE : `<div class="ill-wrap">${ILL_BASE}<span class="ill-badge"><i class="${icon}"></i></span></div>`;
  return `<div class="empty fade-in">${art}<b>${text}</b>${btn ? `<div style="margin-top:12px">${btn}</div>` : ''}</div>`;
}
function rowItem({ icon, ic = 'ic-blue', title, sub = '', end = '<span class="chev"><i class="fa-solid fa-chevron-left"></i></span>', onclick = '', img = '' }) {
  return `<div class="row" role="button" tabindex="0" ${onclick ? `onclick="${onclick}"` : ''}><span class="row-ic ${ic}">${icon ? `<i class="${icon}"></i>` : ''}${img ? `<img src="${esc(img)}" alt="" class="ri-img" loading="lazy" onerror="this.remove()">` : ''}</span>
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
  subscribers: { nav: 'account', slug: 'clients' }, reports: { nav: 'campaign', slug: 'reports' }, apikeys: { nav: 'account', slug: 'api' }, tx: { nav: 'wallet', slug: 'transactions' }, profile: { nav: 'account', slug: 'profile' },
  admin: { nav: 'account', admin: true, slug: 'dashboard' }, users: { nav: 'account', admin: true, slug: 'users' }, recharges: { nav: 'account', admin: true, slug: 'recharges' },
  payments: { nav: 'account', admin: true, slug: 'payments' }, plansAdmin: { nav: 'account', admin: true, slug: 'plans' }, settings: { nav: 'account', admin: true, slug: 'settings' },
  security: { nav: 'account', admin: true, slug: 'security' }, backup: { nav: 'account', admin: true, slug: 'backup' }, announce: { nav: 'account', admin: true, slug: 'announce' },
  leads: { nav: 'account', slug: 'leads' }, report: { nav: 'campaign', slug: 'report' },
  coupons: { nav: 'account', admin: true, slug: 'coupons' }, appearance: { nav: 'account', slug: 'appearance' },
  inbox: { nav: 'accounts', slug: 'inbox' }, chat: { nav: 'accounts', slug: 'chat' }, ibcast: { nav: 'accounts', slug: 'broadcast' },
  polls: { nav: 'account', slug: 'polls' }, poll: { nav: 'account', slug: 'poll' }, pushset: { nav: 'account', slug: 'pushset' },
};
const SLUG2PAGE = Object.fromEntries(Object.entries(PAGES).map(([k, v]) => [v.slug ?? k, k]));

function go(page, params = {}, push = true) {
  if (!PAGES[page] || (PAGES[page].admin && !ADMIN)) page = 'home';
  S.timers.forEach(clearInterval); S.timers = [];
  S.page = page; S.params = params;
  document.body.classList.remove('in-chat', 'ib-selecting');
  document.querySelectorAll('.portal').forEach(x => x.remove());
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
  const h = new Date().getHours();
  const hello = h < 12 ? 'صباح الخير' : h < 18 ? 'يومك سعيد' : 'مساء الخير';
  const planLbl = ME.plan_active ? esc(ME.plan_name || 'مشترك') : ME.trial_active ? 'تجربة مجانية' : SET.message_price > 0 ? 'الدفع حسب الرسائل' : 'بدون باقة';
  const planDays = ME.plan_active ? (ME.plan_days_left === null ? 'اشتراك دائم' : 'باقي ' + ME.plan_days_left + ' يوم') : ME.trial_active ? 'باقي ' + ME.trial_days_left + ' يوم' : '';
  el.innerHTML = `
    <div class="greet fade-in"><div><small>${hello}</small><h2>${esc(ME.name || 'صديقنا')}</h2></div><div class="avatar">${esc(first)}</div></div>
    ${SET.leads_enabled || ADMIN ? `<div class="promo shine fade-in" onclick="go('leads')">
      <div class="promo-art"><i class="fa-solid fa-map-location-dot"></i><span class="pin p1"><i class="fa-solid fa-location-dot"></i></span><span class="pin p2"><i class="fa-solid fa-store"></i></span></div>
      <div class="promo-txt"><span class="promo-tag"><i class="fa-solid fa-bullhorn"></i> جديد</span><b>اسحب أرقام المحلات واعمل حملة إعلانية لمنتجك</b><small>اختر المدينة ونوع النشاط ونجيبلك أرقامهم من الخرائط</small></div>
      <span class="promo-go"><i class="fa-solid fa-arrow-left"></i></span></div>` : ''}
    <div class="hero shine fade-in" onclick="go('wallet')" style="cursor:pointer">
      <div class="hc-top"><span class="pill pill-white"><i class="fa-solid fa-gem"></i> ${planLbl}</span><span style="opacity:.8">${planDays}</span></div>
      <div style="margin-top:12px"><div class="hero-label">الرصيد</div><div class="hero-amount"><span class="num">${fmt(ME.balance)}</span> <span style="font-size:15px;font-weight:600;opacity:.85">د.ع</span></div></div>
      <div class="hero-stats" id="heroStats"><div><b>—</b><span>أرقام متصلة</span></div><div><b>—</b><span>رسالة مرسلة</span></div><div><b>—</b><span>نجاح</span></div></div>
    </div>
    <div class="qa stagger" style="margin-top:18px">
      <button class="qa-item" onclick="go('campaign')"><span class="qa-ic c1"><i class="fa-solid fa-paper-plane"></i></span>حملة</button>
      <button class="qa-item" onclick="go('accounts')"><span class="qa-ic c2"><i class="fa-brands fa-whatsapp"></i></span>أرقامي</button>
      <button class="qa-item" onclick="go('reports')"><span class="qa-ic c3"><i class="fa-solid fa-chart-pie"></i></span>التقارير</button>
      <button class="qa-item" onclick="go('apikeys')"><span class="qa-ic c4"><i class="fa-solid fa-code"></i></span>API</button>
    </div>
    ${ADMIN ? `<div class="list" style="margin-top:18px">${rowItem({ icon: 'fa-solid fa-gauge-high', ic: 'ic-pink', title: 'لوحة الإدارة', sub: 'المستخدمين، الشحن، الباقات والأمان', onclick: "go('admin')" })}</div>` : ''}
    <div id="running"></div>
    <div class="section-head">آخر الحملات <a onclick="go('reports')">عرض الكل</a></div>
    <div id="recent"><div class="card"><div class="skel" style="height:60px"></div></div></div>`;
  const [st, run, recent] = await Promise.all([api('/dashboard/stats'), api('/dashboard/running_campaigns'), api('/dashboard/campaigns')]);
  if (st.accounts_total !== undefined) {
    el.querySelector('#heroStats').innerHTML = `<div><b class="num">${st.accounts_connected}/${st.accounts_total}</b><span>أرقام متصلة</span></div>
      <div><b class="num">${fmt(st.messages_sent)}</b><span>رسالة مرسلة</span></div><div><b class="num">${st.success_rate}%</b><span>نجاح</span></div>`;
  }
  if (Array.isArray(run) && run.length) {
    el.querySelector('#running').innerHTML = `<div class="section-head">حملات جارية <span class="pill pill-green">${run.length}</span></div>` + run.map(c => {
      const done = c.sent + c.failed, pct = c.total ? Math.round(done / c.total * 100) : 0;
      return `<div class="card fade-in" onclick="go('campaign',{acc:'${esc(c.account_id)}'})" style="cursor:pointer">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px"><b>${esc(c.account_name)}</b>${c.paused ? '<span class="pill pill-orange">موقوفة</span>' : '<span class="pill pill-green"><span class="status-dot on"></span> جارية</span>'}</div>
        <div class="progress"><span style="width:${pct}%"></span></div>
        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text3);margin-top:6px"><span class="num">${fmt(done)} / ${fmt(c.total)}</span><span class="num">${pct}%</span></div></div>`;
    }).join('');
  }
  el.querySelector('#recent').innerHTML = Array.isArray(recent) && recent.length
    ? recent.slice(0, 3).map(reportCard).join('')
    : `<div class="card">${empty('fa-solid fa-paper-plane', 'ما أرسلت أي حملة بعد', `<button class="btn btn-primary btn-sm" onclick="go('campaign')"><i class="fa-solid fa-plus"></i> حملة جديدة</button>`).replace('<b>', '<b>').replace('</b>', '</b><small style="display:block;margin-bottom:2px">أرسل أول رسالة لعملائك خلال دقيقة</small>')}</div>`;
};

// ---------------------------------------------------------------- تقارير الحملات
function reportCard(x) {
  const readOnly = Math.max(0, (x.read || 0) - (x.replied || 0));
  return `<div class="card fade-in rep-card" style="margin-bottom:12px">
    <div style="display:flex;align-items:center;gap:14px;cursor:pointer" onclick="go('report',{id:${x.id}})">
      <div class="ring" style="--p:${x.success_rate}"><b class="num">${x.success_rate}%</b></div>
      <div style="flex:1;min-width:0"><b>${esc(x.account_name)}</b>${x.drip_batch ? ` <span class="pill pill-blue">دفعة يومية ${x.drip_batch}${x.drip_topup ? ' · تكملة' : ''}</span>` : ''}<div class="row-sub">${esc(x.time)} · ${esc((x.text || '').slice(0, 40)) || '—'}</div>
        <div class="row-sub"><span class="num">${fmt(x.sent)}</span> من <span class="num">${fmt(x.total)}</span> وصلت بنجاح${x.failed ? ` · <span style="color:var(--red)">فشل ${fmt(x.failed)}</span>` : ''}</div></div>
      <span class="chev"><i class="fa-solid fa-chevron-left"></i></span>
    </div>
    ${x.tracked ? `<div class="rep-bars ${x.has_lists ? 'tap' : ''}">
      <div ${x.has_lists ? `onclick="repNumbers(${x.id},'unread')"` : ''}><b class="num" style="color:#0891b2">${fmt(x.delivered)}</b><span>استلموا</span></div>
      <div ${x.has_lists ? `onclick="repNumbers(${x.id},'read_only')"` : ''}><b class="num" style="color:#7c3aed">${fmt(x.read)}</b><span>شاهدوا</span></div>
      <div ${x.has_lists ? `onclick="repNumbers(${x.id},'replied')"` : ''}><b class="num" style="color:#16a34a">${fmt(x.replied)}</b><span>ردّوا</span></div>
      <div ${x.has_lists ? `onclick="repNumbers(${x.id},'read_only')"` : ''}><b class="num" style="color:#d97706">${fmt(readOnly)}</b><span>شاهدوا بدون رد</span></div>
    </div>${x.has_lists ? `<button class="btn btn-soft btn-sm btn-block" style="margin-top:10px" onclick="repNumbers(${x.id},'replied')"><i class="fa-solid fa-list-check"></i> مين رد ومين ما رد؟</button>` : ''}` : (x.failed && x.has_lists ? `<button class="btn btn-soft btn-sm btn-block" style="margin-top:10px" onclick="repNumbers(${x.id},'failed')"><i class="fa-solid fa-list"></i> الأرقام الفاشلة</button>` : '')}
  </div>`;
}
// قوائم أرقام التقرير: كل رقم بقائمة وحدة (اللي رد ينشال من «ما ردّوا»)
async function repNumbers(hid, tab) {
  const r = await api(`/dashboard/campaigns/${hid}/numbers`);
  if (!r.ok) { toast(r.error || 'تعذر جلب الأرقام', 'err'); return; }
  const L = r.lists, TABS = [['replied', 'ردّوا', '#16a34a', 'fa-solid fa-reply'], ['read_only', 'شاهدوا بدون رد', '#d97706', 'fa-solid fa-eye'], ['unread', 'وصلت وما شاهدوا', '#0891b2', 'fa-solid fa-check-double'], ['pending', 'ما وصلت بعد', '#64748b', 'fa-solid fa-clock'], ['failed', 'فشلت', '#dc2626', 'fa-solid fa-xmark']];
  document.getElementById('repSheet')?.remove(); document.getElementById('repBg')?.remove();
  document.body.insertAdjacentHTML('beforeend', `<div class="sheet-bg" id="repBg"></div><div class="sheet rep-sheet" id="repSheet"><div class="sheet-handle"></div>
    <h3 style="margin:0 0 4px">أرقام الحملة</h3><div class="hint" style="margin-bottom:10px">كل رقم يظهر بقائمة وحدة: اللي رد ينشال تلقائياً من «شاهدوا بدون رد» و«ما شاهدوا»</div>
    <div class="rep-tabs" id="repTabs">${TABS.map(([k, t, c]) => `<button data-k="${k}" style="--c:${c}">${t}<b class="num">${fmt((L[k] || []).length)}</b></button>`).join('')}</div>
    <div id="repBody"></div></div>`);
  const bg = document.getElementById('repBg'), sh = document.getElementById('repSheet');
  const close = () => { bg.classList.remove('show'); sh.classList.remove('show'); setTimeout(() => { bg.remove(); sh.remove(); }, 400); };
  bg.onclick = close;
  const draw = k => {
    tab = k; const nums = L[k] || [], [, t, c, ic] = TABS.find(x => x[0] === k);
    sh.querySelectorAll('#repTabs button').forEach(b => b.classList.toggle('on', b.dataset.k === k));
    sh.querySelector('#repBody').innerHTML = nums.length ? `
      <div class="input-row" style="margin:10px 0">
        <button class="btn btn-primary btn-sm" style="flex:1.5" id="repCamp"><i class="fa-solid fa-paper-plane"></i> أرسل لهم حملة</button>
        <button class="btn btn-soft btn-sm" style="flex:1" id="repCopy"><i class="fa-regular fa-copy"></i> نسخ</button>
        <button class="btn btn-soft btn-sm" style="flex:1" id="repCsv"><i class="fa-solid fa-file-csv"></i> Excel</button></div>
      <div class="rep-list">${nums.slice(0, 500).map(n => `<div><i class="${ic}" style="color:${c}"></i><span class="num" dir="ltr">+${esc(n)}</span><a href="https://wa.me/${esc(n)}" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i></a></div>`).join('')}</div>
      ${nums.length > 500 ? `<div class="hint" style="text-align:center;margin-top:8px">يظهر أول 500 - النسخ والملف فيهم الكل (${fmt(nums.length)})</div>` : ''}`
      : empty(ic, k === 'replied' ? 'ما رد أحد لحد الآن' : 'ما فيه أرقام بهذي القائمة');
    if (!nums.length) return;
    sh.querySelector('#repCamp').onclick = () => { S.pendingNumbers = nums.slice(); close(); go('campaign'); };
    sh.querySelector('#repCopy').onclick = () => copy(nums.join('\n'));
    sh.querySelector('#repCsv').onclick = () => { const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob(['\ufeff' + t + '\r\n' + nums.join('\r\n')], { type: 'text/csv' })); a.download = `${t}.csv`; a.click(); };
  };
  sh.querySelectorAll('#repTabs button').forEach(b => b.onclick = () => draw(b.dataset.k));
  draw(tab);
  requestAnimationFrame(() => { bg.classList.add('show'); sh.classList.add('show'); });
}
// ---------------------------------------------------------------- تفاصيل حملة كاملة
R.report = async (el, p) => {
  if (!p.id) { go('reports', {}, false); return; }
  el.innerHTML = head('تفاصيل الحملة') + '<div class="card"><div class="skel" style="height:200px"></div></div>';
  const r = await api('/dashboard/campaigns/' + p.id);
  if (!r.ok) { el.innerHTML = head('تفاصيل الحملة') + `<div class="card">${empty('fa-solid fa-file-circle-xmark', r.error || 'التقرير غير موجود')}</div>`; return; }
  const x = r.report;
  const NA = '<span class="na">غير مسجّل</span>';
  const statusTxt = x.cancelled > 0 ? `<span class="pill pill-orange">توقفت - ${fmt(x.cancelled)} ما انرسلت</span>` : `<span class="pill pill-green">اكتملت</span>`;
  const dur = (() => { const a = Date.parse((x.time || '').replace(' ', 'T')), b = Date.parse((x.finished_at || '').replace(' ', 'T')); if (!a || !b || b < a) return '—'; const m = Math.round((b - a) / 60000); return m < 60 ? `${m} دقيقة` : `${Math.floor(m / 60)} ساعة ${m % 60 ? (m % 60) + ' دقيقة' : ''}`; })();
  const bar = (k, label, n, color, ic) => `<div class="fn-row" ${x.has_lists ? `onclick="repNumbers(${x.id},'${k}')"` : ''}>
    <span class="row-ic" style="background:color-mix(in srgb,${color} 12%,#fff);color:${color}"><i class="${ic}"></i></span>
    <div style="flex:1;min-width:0"><div class="fn-top"><b>${label}</b><span class="num">${fmt(n)}${x.total ? ` · ${Math.round(n / x.total * 100)}%` : ''}</span></div>
      <div class="progress"><span style="width:${x.total ? Math.min(100, n / x.total * 100) : 0}%;background:${color}"></span></div></div>
    ${x.has_lists ? '<span class="chev"><i class="fa-solid fa-chevron-left"></i></span>' : ''}</div>`;
  const readOnly = Math.max(0, (x.read || 0) - (x.replied || 0));
  el.innerHTML = head('تفاصيل الحملة') + `
    <div class="hero fade-in"><div style="display:flex;align-items:center;gap:14px">
      <div class="ring big" style="--p:${x.success_rate}"><b class="num">${x.success_rate}%</b></div>
      <div style="flex:1;min-width:0"><div class="hero-label">نسبة النجاح</div><div style="font-size:19px;font-weight:800">${esc(x.account_name)}</div>
        <div class="hero-sub"><span class="num">${fmt(x.sent)}</span> من <span class="num">${fmt(x.total)}</span> رقم وصلتهم الرسالة</div></div></div></div>
    ${x.legacy ? `<div class="note" style="margin-top:12px"><i class="fa-solid fa-circle-info"></i> هذي حملة قبل التحديث - بعض التفاصيل (وقت الانتهاء، الفاصل، المرفق) ما كانت تنسجل. الحملات الجديدة تظهر كاملة.</div>` : ''}
    <div class="section-head">المعلومات</div>
    <div class="card info-grid">
      <div><small><i class="fa-solid fa-play"></i> بدأت</small><b class="num">${x.time ? esc(x.time) : NA}</b></div>
      <div><small><i class="fa-solid fa-flag-checkered"></i> انتهت</small><b class="num">${x.finished_at ? esc(x.finished_at) : NA}</b></div>
      <div><small><i class="fa-solid fa-hourglass-half"></i> المدة</small><b>${dur === '—' ? NA : dur}</b></div>
      <div><small><i class="fa-solid fa-stopwatch"></i> الفاصل بين الرسائل</small><b>${x.delay ? x.delay + ' ثانية' : NA}</b></div>
      <div><small><i class="fa-brands fa-whatsapp"></i> أرسلت من</small><b>${esc(x.account_name)}</b></div>
      <div><small><i class="fa-solid fa-address-book"></i> عدد الأرقام</small><b class="num">${fmt(x.total)}</b></div>
      <div><small><i class="fa-solid fa-circle-half-stroke"></i> الحالة</small><b>${statusTxt}</b></div>
      <div><small><i class="fa-solid fa-calendar-day"></i> النوع</small><b>${x.drip_batch ? 'دفعة يومية رقم ' + x.drip_batch + (x.drip_topup ? ' (تكملة بدل الفاشلة)' : '') : 'حملة عادية'}</b></div>
    </div>
    <div class="section-head">الرسالة <a onclick="copy(${esc(JSON.stringify(x.text_full || ''))})">نسخ</a></div>
    <div class="card"><div class="msg-full selectable">${x.text_full ? esc(x.text_full).replace(/\n/g, '<br>') : (x.media_name ? '<i>بدون نص - مرفق فقط</i>' : '—')}</div>
      ${x.media_name ? `<div class="note" style="margin-top:10px"><i class="fa-solid fa-paperclip"></i> مرفق: ${esc(x.media_name)}</div>` : ''}
      <button class="btn btn-soft btn-sm btn-block" style="margin-top:10px" id="reuse"><i class="fa-solid fa-rotate"></i> استخدم نفس الرسالة بحملة جديدة</button></div>
    <div class="section-head">النتائج ${x.has_lists ? '<small style="font-weight:500;color:var(--text3)">اضغط لعرض الأرقام</small>' : ''}</div>
    <div class="card funnel">
      ${bar('unread', 'وصلت بنجاح', x.sent, '#0891b2', 'fa-solid fa-paper-plane')}
      ${x.tracked ? bar('unread', 'استلموا', x.delivered, '#0ea5e9', 'fa-solid fa-check-double') + bar('read_only', 'شاهدوا', x.read, '#7c3aed', 'fa-solid fa-eye') + bar('replied', 'ردّوا', x.replied, '#16a34a', 'fa-solid fa-reply') + bar('read_only', 'شاهدوا بدون رد', readOnly, '#d97706', 'fa-solid fa-eye-slash') : ''}
      ${bar('failed', 'فشلت', x.failed, '#dc2626', 'fa-solid fa-xmark')}
      ${x.failed ? `<div class="fail-why">${x.failed_invalid ? `<span><i class="fa-solid fa-ban"></i> ${fmt(x.failed_invalid)} رقم ما عليه واتساب${x.skipped_invalid ? ' (انشالوا قبل البدء)' : ''}</span>` : ''}${x.failed - x.failed_invalid > 0 ? `<span><i class="fa-solid fa-rotate-right"></i> ${fmt(x.failed - x.failed_invalid)} خطأ مؤقت${x.drip_batch ? ' (تنعاد بالدفعة الجاية)' : ''}</span>` : ''}</div>` : ''}
      ${x.cancelled ? bar('pending', 'ما انرسلت (إيقاف/إلغاء)', x.cancelled, '#64748b', 'fa-solid fa-pause') : ''}
    </div>
    ${x.tracked ? `<div class="note" style="margin-top:12px">الأرقام تتحدث تلقائياً لمدة 7 أيام بعد الحملة. «شاهدوا» تعتمد على إيصالات القراءة.</div>` : ''}`;
  el.querySelector('#reuse').onclick = () => { S.pendingText = x.text_full || ''; go('campaign', { acc: x.account_id }); };
};

// ---------------------------------------------------------------- المظهر (ستايل، داكن/نهاري، خط، حجم)
const UI_FONTS = [['plex', 'IBM Plex', 'الافتراضي'], ['alexandria', 'Alexandria', 'عصري وعريض'], ['readex-pro', 'Readex Pro', 'واضح ومريح'], ['cairo', 'Cairo', 'كلاسيكي مشهور'],
  ['tajawal', 'Tajawal', 'خفيف ونظيف'], ['almarai', 'Almarai', 'ناعم ومقروء'], ['noto-kufi-arabic', 'Noto Kufi', 'كوفي رسمي'], ['reem-kufi', 'Reem Kufi', 'كوفي مزخرف'],
  ['el-messiri', 'El Messiri', 'أنيق وفخم'], ['marhey', 'Marhey', 'مرح ومدوّر'], ['rakkas', 'Rakkas', 'عناوين قوية (خط الشعار)']];
const UI_STYLES = [['teal', 'فيروزي زجاجي', 'الستايل الأصلي'], ['gold', 'ذهبي وكحلي', 'بألوان شعار واصل'], ['violet', 'بنفسجي ليلي', 'هادي وفخم'], ['rose', 'وردي مرجاني', 'دافي وحيوي']];
const UI_TC = { teal: ['#f3f7f8', '#0b181b'], gold: ['#f7f4ec', '#0a1517'], violet: ['#f6f4fc', '#110e1b'], rose: ['#fcf4f5', '#170e11'] };
const UI_DEF = { style: '', mode: 'light', font: 'plex', size: 'md' }; // style '' = الأساسي اللي يحدده الأدمن
const uiDefStyle = () => (UI_TC[SET.default_style] ? SET.default_style : 'teal');
/** اختيار المستخدم (style '' يعني يتبع الأساسي) */
function uiGet() { const h = document.documentElement; return { style: ME?.ui ? (ME.ui.style || '') : (h.dataset.style || ''), mode: h.dataset.uiMode || 'light', font: h.dataset.font || 'plex', size: h.dataset.size || 'md' }; }
function uiFontCss(f) { return `/assets/vendor/font-${f}.css?v=__V__`; }
function uiLoadFont(f, id) {
  if (f === 'plex') return;
  const sel = id ? '#' + id : `link[data-uif="${f}"]`;
  if (document.querySelector(sel) || document.querySelector(`link[data-uif="${f}"]`)) return;
  const l = document.createElement('link'); l.rel = 'stylesheet'; l.href = uiFontCss(f); l.dataset.uif = f; document.head.appendChild(l);
}
/** يطبّق المظهر فوراً (بدون تحديث) ويحفظه بالمتصفح، وبالسيرفر إذا save=true */
function uiApply(p, save = true) {
  p = Object.assign(uiGet(), p);
  if (ME) ME.ui = Object.assign({}, p);
  const h = document.documentElement;
  const dark = p.mode === 'dark' || (p.mode === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches);
  const st = UI_TC[p.style] ? p.style : uiDefStyle();
  uiLoadFont(p.font);
  h.dataset.style = st; h.dataset.uiMode = p.mode; h.dataset.mode = dark ? 'dark' : 'light'; h.dataset.font = p.font; h.dataset.size = p.size;
  let m = document.querySelector('meta[name=theme-color]'); if (!m) { m = document.createElement('meta'); m.name = 'theme-color'; document.head.appendChild(m); } m.content = UI_TC[st][dark ? 1 : 0];
  try { localStorage.setItem('wasel_ui', JSON.stringify(Object.assign({}, p, { style: st }))); } catch (e) {}
  if (save) { clearTimeout(S.uiT); S.uiT = setTimeout(() => api('/api/me/ui', { method: 'POST', body: p }).then(r => { if (r.ok && ME) ME.ui = r.ui; }), 350); }
  return p;
}
// الوضع التلقائي يتبع الجهاز حتى لو تغيّر وهو فاتح
try { matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => { if (uiGet().mode === 'auto') uiApply({}, false); }); } catch (e) {}

R.appearance = async (el) => {
  const draw = () => {
    const p = uiGet(), cur = UI_TC[p.style] ? p.style : uiDefStyle(), def = uiDefStyle();
    const segBtns = (key, opts) => opts.map(([v, t, ic]) => `<button data-k="${key}" data-v="${v}" class="${p[key] === v ? 'on' : ''}">${ic ? `<i class="${ic}"></i> ` : ''}<b>${t}</b></button>`).join('');
    el.innerHTML = head('المظهر') + `
      <div class="section-head">الستايل</div>
      <div class="ui-styles">
        ${UI_STYLES.map(([v, t, s]) => `
        <button class="ui-style ${cur === v ? 'on' : ''}" data-k="style" data-v="${v}">
          <span class="ui-prev ui-prev-${v}"><span class="up-hero"><img src="/assets/logo${v === 'teal' ? '' : '-' + v}.webp?v=__V__" alt=""></span><span class="up-l"></span><span class="up-l s"></span><span class="up-btn"></span></span>
          <b>${t}</b><small>${s}</small><span class="ui-check"><i class="fa-solid fa-check"></i></span>${v === def ? '<span class="ui-def">الأساسي</span>' : ''}
        </button>`).join('')}
      </div>
      ${p.style === '' ? '<div class="hint" style="margin-top:8px">تستخدم الستايل الأساسي للموقع حالياً.</div>' : ''}
      ${ADMIN ? `<div class="card" style="margin-top:12px"><div style="display:flex;align-items:center;gap:10px;margin-bottom:10px"><span class="row-ic ic-indigo"><i class="fa-solid fa-shield"></i></span>
        <div style="flex:1"><b>الستايل الأساسي للموقع</b><div class="row-sub">يظهر لكل المستخدمين والزوار اللي ما اختاروا ستايل (أدمن فقط)</div></div></div>
        <div class="seg ui-seg" id="uiDefSeg">${UI_STYLES.map(([v, t]) => `<button data-d="${v}" class="${def === v ? 'on' : ''}"><b>${t.split(' ')[0]}</b></button>`).join('')}</div></div>` : ''}
      <div class="section-head">الوضع</div>
      <div class="card"><div class="seg ui-seg">${segBtns('mode', [['light', 'نهاري', 'fa-solid fa-sun'], ['dark', 'داكن', 'fa-solid fa-moon'], ['auto', 'تلقائي', 'fa-solid fa-circle-half-stroke']])}</div>
        <div class="hint" style="margin-top:8px">«تلقائي» يتبع إعداد جهازك (نهاري بالنهار وداكن بالليل إذا جهازك مضبوط هيج).</div></div>
      <div class="section-head">حجم الخط</div>
      <div class="card"><div class="seg ui-seg">${segBtns('size', [['sm', 'صغير'], ['md', 'عادي'], ['lg', 'كبير'], ['xl', 'كبير جداً']])}</div>
        <div class="ui-size-demo">مرحباً بك في واصل 👋 هذا مثال على حجم الكتابة</div></div>
      <div class="section-head">خط الموقع <span class="pill pill-gray">${UI_FONTS.length} خطوط</span></div>
      <div class="list ui-fonts">
        ${UI_FONTS.map(([id, name, desc]) => `
        <div class="row ui-font ${p.font === id ? 'on' : ''}" role="button" tabindex="0" data-k="font" data-v="${id}">
          <span class="row-body"><span class="row-title" style="font-family:${id === 'plex' ? "'IBM Plex Sans Arabic'" : `'W-${id}','IBM Plex Sans Arabic'`};font-size:17px">واصل للرسائل الذكية 123</span>
          <span class="row-sub">${name} · ${desc}</span></span>
          <span class="row-end"><span class="ui-radio"></span></span>
        </div>`).join('')}
      </div>
      <button class="btn btn-ghost btn-block" style="margin-top:16px" id="uiReset"><i class="fa-solid fa-rotate-left"></i> رجوع للمظهر الافتراضي</button>
      <div class="hint" style="text-align:center;margin-top:10px">يتحفظ اختيارك بحسابك ويبقى نفسه حتى بعد التحديث أو من جهاز ثاني.</div>`;
    el.querySelectorAll('[data-k]').forEach(b => b.onclick = () => { uiApply({ [b.dataset.k]: b.dataset.v }); draw(); });
    el.querySelectorAll('[data-d]').forEach(b => b.onclick = async () => {
      const r = await api('/api/admin/settings', { body: { default_style: b.dataset.d } });
      if (!r.ok) return toast(r.error || 'ما انحفظ', 'err');
      SET.default_style = b.dataset.d; uiApply({}, false); draw(); toast('صار الستايل الأساسي: ' + b.textContent.trim(), 'ok');
    });
    el.querySelector('#uiReset').onclick = () => { uiApply(UI_DEF); draw(); toast('رجع المظهر الافتراضي', 'ok'); };
  };
  UI_FONTS.forEach(([id]) => uiLoadFont(id)); // نحمّل كل الخطوط هنا بس حتى تبين المعاينة
  draw();
};

// ---------------------------------------------------------------- الصور الشخصية + علامات القراءة
const avUrl = (acc, jid) => `/api/avatar/${encodeURIComponent(acc)}?jid=${encodeURIComponent(jid)}`;
/** دائرة الشخص: صورته من واتساب لو موجودة، وإلا أول حرف من اسمه */
function avatarHtml(acc, c, cls = 'ib-av') {
  const h = [...String(c.jid || c.id || '')].reduce((s, x) => s + x.charCodeAt(0), 0) % 360;
  const init = c.name ? esc([...String(c.name).trim()][0] || '؟') : '<i class="fa-solid fa-user"></i>';
  return `<span class="${cls}" style="--h:${h}">${init}<img src="${avUrl(acc, c.jid || 'me')}" alt="" loading="lazy" onerror="this.remove()"></span>`;
}
/** ✓ انرسلت، ✓✓ وصلت، ✓✓ زرقاء قرأها */
function ticks(st) {
  if (!st) return '';
  if (st >= 4) return '<i class="fa-solid fa-check-double tick read" title="قرأها"></i>';
  if (st >= 3) return '<i class="fa-solid fa-check-double tick" title="وصلت"></i>';
  return '<i class="fa-solid fa-check tick" title="انرسلت"></i>';
}

// ---------------------------------------------------------------- إشعارات الجوال
const b64u = s => { const p = '='.repeat((4 - s.length % 4) % 4); const r = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from([...r].map(c => c.charCodeAt(0))); };
async function pushEnable() {
  if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
    toast(/iphone|ipad/i.test(navigator.userAgent) ? 'بالآيفون: ثبّت التطبيق على الشاشة الرئيسية أول، وافتحه منها' : 'متصفحك ما يدعم الإشعارات', 'err'); return false;
  }
  if (await Notification.requestPermission() !== 'granted') { toast('لازم تسمح بالإشعارات من إعدادات المتصفح', 'err'); return false; }
  const k = await api('/api/push/key'); if (!k.ok) { toast(k.error, 'err'); return false; }
  try {
    const reg = await navigator.serviceWorker.ready;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64u(k.key) });
    const r = await api('/api/push/subscribe', { body: { endpoint: sub.endpoint } });
    if (!r.ok) throw new Error(r.error);
    try { localStorage.setItem('wasel_push', '1'); } catch (e) {}
    toast('تفعّلت إشعارات الجوال ✓', 'ok'); return true;
  } catch (e) { toast('تعذر التفعيل: ' + (e.message || e), 'err'); return false; }
}
const pushOn = () => 'Notification' in window && Notification.permission === 'granted' && (() => { try { return localStorage.getItem('wasel_push') === '1'; } catch (e) { return false; } })();
R.pushset = async (el) => {
  el.innerHTML = head('إشعارات الجوال') + '<div class="card"><div class="skel" style="height:160px"></div></div>';
  const k = await api('/api/push/key');
  const P = k.prefs || {};
  const sw = (key, title, sub, ic) => `<div class="row"><span class="row-ic ${ic[1]}"><i class="${ic[0]}"></i></span><span class="row-body"><span class="row-title">${title}</span><span class="row-sub">${sub}</span></span><span class="row-end"><label class="switch"><input type="checkbox" data-p="${key}" ${P[key] ? 'checked' : ''}><span></span></label></span></div>`;
  el.innerHTML = head('إشعارات الجوال') + `
    <div class="hero shine"><div style="display:flex;align-items:center;gap:14px"><span class="hero-icon"><i class="fa-solid fa-bell"></i></span>
      <div><div style="font-weight:800;font-size:17px">${pushOn() ? 'الإشعارات مفعّلة على هذا الجهاز' : 'فعّل الإشعارات على جوالك'}</div><div class="hero-sub">توصلك حتى لو التطبيق مسكّر</div></div></div></div>
    ${/iphone|ipad/i.test(navigator.userAgent) && !matchMedia('(display-mode: standalone)').matches ? '<div class="note warn" style="margin-top:12px">بالآيفون: ثبّت واصل على الشاشة الرئيسية أول (زر المشاركة ⬆️ ← إضافة إلى الشاشة الرئيسية)، وافتحه منها وفعّل الإشعارات</div>' : ''}
    <div class="input-row" style="margin-top:12px;gap:8px"><button class="btn btn-primary" style="flex:2" id="psOn"><i class="fa-solid fa-bell"></i> ${pushOn() ? 'إعادة التفعيل' : 'تفعيل الإشعارات'}</button><button class="btn btn-soft" style="flex:1" id="psTest">تجربة</button></div>
    <div class="section-head">شنو يوصلني؟</div>
    <div class="list">
      ${sw('replies', 'شخص رد عليّ', 'لما يرد شخص راسلته (حملة أو محادثة) خلال آخر 7 أيام', ['fa-solid fa-reply', 'ic-green'])}
      ${sw('reads', 'شخص شاف رسالتي وما رد', 'قرأ رسالتك (✓✓ زرقاء) وما كتب شي - مرة وحدة لكل شخص كل 6 ساعات', ['fa-solid fa-eye', 'ic-blue'])}
      ${sw('votes', 'شخص ضغط زر أو جاوب استبيان', 'مع اسمه والخيار اللي اختاره', ['fa-solid fa-square-poll-vertical', 'ic-purple'])}
      ${sw('all_msgs', 'كل رسالة جديدة توصل', 'حتى من ناس ما راسلتهم (ممكن تكون كثيرة)', ['fa-solid fa-comments', 'ic-orange'])}
    </div>`;
  el.querySelectorAll('[data-p]').forEach(c => c.onchange = async () => { const r = await api('/api/push/prefs', { body: { [c.dataset.p]: c.checked } }); toast(r.ok ? 'انحفظ' : r.error, r.ok ? 'ok' : 'err'); });
  el.querySelector('#psOn').onclick = async () => { if (await pushEnable()) go('pushset', {}, false); };
  el.querySelector('#psTest').onclick = async () => { const r = await api('/api/push/test', { body: {} }); toast(r.sent ? 'انرسل - يوصلك خلال ثواني' : 'ما فيه جهاز مفعّل - اضغط «تفعيل» أول', r.sent ? 'ok' : 'err'); };
};

// ---------------------------------------------------------------- الأزرار والاستبيانات والتقييم
const PK = { buttons: ['fa-solid fa-hand-pointer', 'أزرار', 'ic-teal'], survey: ['fa-solid fa-square-poll-vertical', 'استبيان', 'ic-purple'], rating: ['fa-solid fa-star', 'تقييم', 'ic-orange'] };
async function loadPolls() { const r = await api('/api/polls'); S.polls = r.ok ? r.polls : []; S.ratingOpts = r.rating_options || []; return S.polls; }
/** قائمة اختيار قالب (للحملة/الرسالة الجماعية/المحادثة) */
function pollSelect(id, polls) {
  return `<select class="select" id="${id}"><option value="">بدون</option>${polls.map(p => `<option value="${p.id}">${PK[p.kind][1]}: ${esc(p.name || p.question)}</option>`).join('')}</select>`;
}
R.polls = async (el) => {
  el.innerHTML = head('الأزرار والاستبيانات', true, `<button class="btn btn-primary btn-sm" onclick="go('poll',{})"><i class="fa-solid fa-plus"></i> جديد</button>`) + '<div class="card"><div class="skel" style="height:120px"></div></div>';
  const polls = await loadPolls();
  el.innerHTML = head('الأزرار والاستبيانات', true, `<button class="btn btn-primary btn-sm" onclick="go('poll',{})"><i class="fa-solid fa-plus"></i> جديد</button>`) + `
    <div class="note" style="margin-bottom:12px"><i class="fa-solid fa-circle-info"></i> تنرسل بواتساب كخيارات ينضغط عليها. لما الشخص يضغط زر (مثل «المزيد») ينرسله تلقائياً الرد اللي تحدده: رسالة، استبيان ثاني، أو تقييم - ويوصلك إشعار باسمه واختياره.</div>
    ${polls.length ? polls.map(p => `<div class="card poll-card" onclick="go('poll',{id:${p.id},view:1})" style="cursor:pointer">
      <div style="display:flex;align-items:center;gap:12px"><span class="row-ic ${PK[p.kind][2]}"><i class="${PK[p.kind][0]}"></i></span>
        <div style="flex:1;min-width:0"><b>${esc(p.name || p.question)}</b><div class="row-sub">${PK[p.kind][1]} · ${p.preview.length} خيارات</div></div>
        <span class="pill ${p.votes ? 'pill-green' : 'pill-gray'} num">${fmt(p.votes)} ضغطة</span></div>
      <div class="poll-prev">${p.preview.slice(0, 6).map(o => `<span>${esc(o)}</span>`).join('')}</div></div>`).join('')
    : `<div class="card">${empty('fa-solid fa-hand-pointer', 'ما سويت أزرار أو استبيانات بعد', `<button class="btn btn-primary" onclick="go('poll',{})"><i class="fa-solid fa-plus"></i> سوّي أول واحد</button>`)}</div>`}`;
};
R.poll = async (el, p) => {
  const polls = S.polls || await loadPolls();
  let t = { kind: 'buttons', name: '', question: '', multi: false, thanks: '', options: [{ label: 'المزيد', action: 'message', text: '' }, { label: 'لا شكراً', action: 'none', text: '' }] }, res = null;
  if (p.id) { const r = await api('/api/polls/' + p.id); if (!r.ok) return go('polls'); t = r.poll; res = r.results; }
  if (p.view && res) {
    // ---- النتائج
    const max = Math.max(1, ...Object.values(res.counts));
    el.innerHTML = head(esc(t.name || t.question), true, `<button class="btn btn-soft btn-sm" onclick="go('poll',{id:${t.id}})"><i class="fa-solid fa-pen"></i> تعديل</button>`) + `
      <div class="stats three"><div class="stat"><span class="stat-v num">${fmt(res.total)}</span><span class="stat-l">ضغطة</span></div><div class="stat"><span class="stat-v num">${fmt(res.people)}</span><span class="stat-l">شخص</span></div>
        <div class="stat"><span class="stat-v num">${res.avg != null ? res.avg + ' ⭐' : PK[t.kind][1]}</span><span class="stat-l">${res.avg != null ? 'معدل التقييم' : 'النوع'}</span></div></div>
      <div class="card" style="margin-top:12px"><div class="card-title"><i class="fa-solid fa-chart-simple"></i> ${esc(t.question)}</div>
        ${Object.entries(res.counts).map(([o, n]) => `<div class="pr-row"><div class="pr-top"><span>${esc(o)}</span><b class="num">${fmt(n)}</b></div><div class="progress"><span style="width:${Math.round(n / max * 100)}%"></span></div></div>`).join('')}</div>
      <div class="section-head">مين ضغط؟</div>
      <div class="card">${res.recent.length ? res.recent.map(v => `<div class="kv"><span>${esc(v.name || (v.phone ? '+' + v.phone : 'شخص'))} <small class="num" style="color:var(--text3)">${esc(v.at)}</small></span><b>${esc(v.option)}</b></div>`).join('') : '<div class="hint">لسه ما أحد ضغط - أرسله ويّا حملة أو من المحادثات</div>'}</div>
      <div class="input-row" style="margin-top:12px;gap:8px"><button class="btn btn-primary" style="flex:1" onclick="go('campaign',{})"><i class="fa-solid fa-paper-plane"></i> أرسله بحملة</button><button class="btn btn-soft" style="flex:1" onclick="go('inbox')"><i class="fa-solid fa-comments"></i> من المحادثات</button></div>`;
    return;
  }
  // ---- المحرر
  const others = polls.filter(x => x.id !== t.id);
  const draw = () => {
    el.innerHTML = head(t.id ? 'تعديل' : 'أزرار / استبيان جديد') + `
      <div class="seg" id="pKind" style="margin-bottom:12px">${Object.entries(PK).map(([k, v]) => `<button data-k="${k}" class="${t.kind === k ? 'on' : ''}"><i class="${v[0]}"></i><b>${v[1]}</b></button>`).join('')}</div>
      <div class="card">
        <div class="field"><label>اسم داخلي (لك بس)</label><input class="input" id="pName" value="${esc(t.name)}" placeholder="مثلاً: أزرار عرض الشتاء"></div>
        <div class="field"><label>${t.kind === 'rating' ? 'سؤال التقييم' : t.kind === 'survey' ? 'سؤال الاستبيان' : 'النص فوق الأزرار'}</label><input class="input" id="pQ" maxlength="255" value="${esc(t.question)}" placeholder="${t.kind === 'rating' ? 'شلون تقيّم خدمتنا؟' : t.kind === 'survey' ? 'شنو أكثر شي يهمك؟' : 'تحب تعرف أكثر؟'}"></div>
        ${t.kind === 'survey' ? `<label style="display:flex;align-items:center;gap:10px;margin:4px 0 10px"><span class="switch"><input type="checkbox" id="pMulti" ${t.multi ? 'checked' : ''}><span></span></span> يكدر يختار أكثر من خيار</label>` : ''}
      </div>
      ${t.kind === 'rating' ? `<div class="card"><div class="card-title"><i class="fa-solid fa-star"></i> الخيارات (ثابتة)</div><div class="poll-prev">${(S.ratingOpts || []).map(o => `<span>${esc(o)}</span>`).join('')}</div></div>`
      : `<div class="section-head">${t.kind === 'survey' ? 'الخيارات' : 'الأزرار'} <a id="pAdd">+ إضافة</a></div>
        ${t.kind === 'buttons' ? '<div class="hint" style="margin:-4px 2px 10px">أزرار حقيقية تنضغط - كل زر إمّا يفتح رابط أو يرسل رسالة/استبيان ثاني. (واتساب يسمح لحد 3 أزرار)</div>' : ''}
        ${t.options.map((o, i) => `<div class="card opt-card">
          <div style="display:flex;gap:8px;align-items:center"><span class="opt-n num">${i + 1}</span><input class="input" data-l="${i}" value="${esc(o.label)}" maxlength="${t.kind === 'buttons' ? 25 : 100}" placeholder="اسم الزر - مثلاً: المزيد"><button class="icon-btn red" data-x="${i}" ${t.options.length <= (t.kind === 'buttons' ? 1 : 2) ? 'disabled' : ''}><i class="fa-solid fa-trash"></i></button></div>
          <div class="field" style="margin:10px 0 0"><label>لما يضغطه:</label><select class="select" data-a="${i}">${t.kind === 'buttons' ? `<option value="url" ${o.action === 'url' ? 'selected' : ''}>🔗 يفتح رابط (لينك)</option>` : ''}<option value="message" ${o.action === 'message' ? 'selected' : ''}>💬 يرسله رسالة محددة</option><option value="poll" ${o.action === 'poll' ? 'selected' : ''} ${others.length ? '' : 'disabled'}>🗳️ يرسله استبيان أو تقييم${others.length ? '' : ' (سوّي واحد ثاني أول)'}</option><option value="none" ${o.action === 'none' ? 'selected' : ''}>بس يتسجل (بدون رد)</option></select></div>
          ${o.action === 'url' ? `<input class="input" data-u="${i}" dir="ltr" style="margin-top:8px;text-align:left" value="${esc(o.url || '')}" placeholder="https://example.com">` : ''}
          ${o.action === 'message' ? `<textarea class="textarea" data-t="${i}" style="margin-top:8px;min-height:80px" placeholder="الرسالة اللي توصله...">${esc(o.text)}</textarea>` : ''}
          ${o.action === 'poll' ? `<select class="select" data-n="${i}" style="margin-top:8px">${others.map(x => `<option value="${x.id}" ${+o.next === x.id ? 'selected' : ''}>${PK[x.kind][1]}: ${esc(x.name || x.question)}</option>`).join('')}</select>` : ''}
        </div>`).join('')}`}
      <div class="card"><div class="field" style="margin:0"><label>رسالة شكر (اختياري) - تنرسل لما يختار خيار ما إله رد</label><textarea class="textarea" id="pThanks" style="min-height:70px" placeholder="شكراً لرأيك 🌹">${esc(t.thanks)}</textarea></div></div>
      <button class="btn btn-primary btn-block" id="pSave"><i class="fa-solid fa-check"></i> حفظ</button>
      ${t.id ? '<button class="btn btn-red btn-block" style="margin-top:10px" id="pDel"><i class="fa-solid fa-trash"></i> حذف</button>' : ''}`;
    const sync = () => {
      t.name = $('#pName').value; t.question = $('#pQ').value; t.thanks = $('#pThanks').value; t.multi = !!$('#pMulti')?.checked;
      el.querySelectorAll('[data-l]').forEach(i => { t.options[+i.dataset.l].label = i.value; });
      el.querySelectorAll('[data-t]').forEach(i => { t.options[+i.dataset.t].text = i.value; });
      el.querySelectorAll('[data-u]').forEach(i => { t.options[+i.dataset.u].url = i.value; });
      el.querySelectorAll('[data-n]').forEach(i => { t.options[+i.dataset.n].next = +i.value; });
    };
    el.querySelectorAll('#pKind [data-k]').forEach(b => b.onclick = () => { sync(); t.kind = b.dataset.k; if (t.kind !== 'buttons') t.options.forEach(o => { if (o.action === 'url') o.action = 'none'; }); draw(); });
    el.querySelectorAll('[data-a]').forEach(s => s.onchange = () => { sync(); const o = t.options[+s.dataset.a]; o.action = s.value; if (s.value === 'poll' && !o.next && others[0]) o.next = others[0].id; draw(); });
    el.querySelectorAll('[data-x]').forEach(b => b.onclick = () => { sync(); t.options.splice(+b.dataset.x, 1); draw(); });
    const add = $('#pAdd'); if (add) add.onclick = () => { sync(); const max = t.kind === 'buttons' ? 3 : 12; if (t.options.length >= max) return toast(t.kind === 'buttons' ? 'واتساب يسمح لحد 3 أزرار' : 'الحد 12 خيار', 'err'); t.options.push({ label: '', action: t.kind === 'buttons' ? 'url' : 'none', text: '', url: '' }); draw(); };
    $('#pSave').onclick = async e => {
      sync(); busy(e.target.closest('button'), true);
      const r = await api('/api/polls', { body: t }); busy(e.target.closest('button'), false);
      if (!r.ok) return toast(r.error, 'err');
      toast('انحفظ ✓', 'ok'); S.polls = null; go('poll', { id: r.id, view: 1 }, false);
    };
    const del = $('#pDel'); if (del) del.onclick = e => confirmTap(e.target.closest('button'), async () => { await api(`/api/polls/${t.id}/delete`, { body: {} }); S.polls = null; go('polls'); });
  };
  draw();
};

// ---------------------------------------------------------------- الاشتراك: المحادثات (واتساب وإنستغرام) للمشتركين
function chatsLocked(el, title) {
  if (ME.chats_allowed) return false;
  el.innerHTML = head(title) + `<div class="card lock-card"><div class="lock-ic"><i class="fa-solid fa-lock"></i></div>
    <h3>${title} للمشتركين</h3><p>اشترك بباقة حتى تشوف كل محادثاتك وترد عليها من مكان واحد، ويوصلك إشعار لكل رد.</p>
    <button class="btn btn-primary btn-block" onclick="go('wallet')"><i class="fa-solid fa-gem"></i> الباقات</button></div>`;
  return true;
}

// ---------------------------------------------------------------- المحادثات (صندوق واتساب)
const $d = s => document.querySelector(s); // للعناصر الثابتة المضافة للـ body
const IB = { sel: new Set(), selMode: false, filter: 'all', q: '', acc: null, chats: [] };
const ibTime = ts => {
  if (!ts) return '';
  const d = new Date(ts * 1000), n = new Date(), p = x => String(x).padStart(2, '0');
  if (d.toDateString() === n.toDateString()) return p(d.getHours()) + ':' + p(d.getMinutes());
  const y = new Date(n); y.setDate(n.getDate() - 1);
  if (d.toDateString() === y.toDateString()) return 'أمس';
  return d.getDate() + '/' + (d.getMonth() + 1);
};
const ibName = c => c.name || (c.phone ? '+' + c.phone : 'جهة اتصال');
const IB_TYPE = { image: ['fa-solid fa-image', 'صورة'], video: ['fa-solid fa-video', 'فيديو'], voice: ['fa-solid fa-microphone', 'رسالة صوتية'], audio: ['fa-solid fa-music', 'صوت'], document: ['fa-solid fa-file', 'ملف'], sticker: ['fa-regular fa-face-smile', 'ملصق'], location: ['fa-solid fa-location-dot', 'موقع'], contact: ['fa-solid fa-address-card', 'جهة اتصال'], poll: ['fa-solid fa-square-poll-vertical', 'تصويت'] };
function ibPreview(l) {
  if (!l) return '';
  const t = IB_TYPE[l.type];
  return (l.me ? ticks(l.st || 1) + ' ' : '') + (t ? `<i class="${t[0]}"></i> ` : '') + esc(l.text || l.file || (t ? t[1] : ''));
}

R.inbox = async (el, p) => {
  if (chatsLocked(el, 'محادثات واتساب')) return;
  el.innerHTML = head('المحادثات') + '<div class="card"><div class="skel" style="height:220px"></div></div>';
  const accs = await loadAccounts();
  if (!accs.length) { el.innerHTML = head('المحادثات') + `<div class="card">${empty('fa-brands fa-whatsapp', 'اربط رقم واتساب أولاً حتى تشوف محادثاته', `<button class="btn btn-wa" onclick="go('accounts')"><i class="fa-solid fa-link"></i> ربط رقم</button>`)}</div>`; return; }
  if (p.acc) IB.acc = p.acc;
  if (!accs.some(a => a.id === IB.acc)) { IB.acc = (accs.find(a => a.logged_in) || accs[0]).id; IB.sel.clear(); }
  const acc = () => accs.find(a => a.id === IB.acc);
  el.innerHTML = head('المحادثات', true, `<button class="btn btn-primary btn-sm" id="ibNew"><i class="fa-solid fa-pen-to-square"></i> جديدة</button>`) + `
    ${accs.length > 1 ? `<div class="chips" style="margin-bottom:10px;flex-wrap:nowrap;overflow-x:auto" id="ibAccs">${accs.map(a => `<button class="chip ${a.id === IB.acc ? 'on' : ''}" data-id="${esc(a.id)}"><span class="status-dot ${a.logged_in ? 'on' : ''}"></span> ${esc(a.name)}</button>`).join('')}</div>` : ''}
    <div id="ibNewBox"></div>
    <div class="ib-search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" id="ibQ" placeholder="ابحث بالاسم أو الرقم أو الرسالة..." value="${esc(IB.q)}"></div>
    <div class="ib-bar">
      <div class="chips" id="ibF">${[['all', 'الكل'], ['unread', 'غير مقروءة'], ['seen', 'شافوا وما ردوا'], ['unseen', 'ما شافوا'], ['incoming', 'راسلوني'], ['mine', 'راسلتهم أنا بس']].map(([k, v]) => `<button class="chip ${IB.filter === k ? 'on' : ''}" data-f="${k}">${v}<b class="num ib-cnt" data-c="${k}"></b></button>`).join('')}</div>
    </div>
    <div class="ib-sel ${IB.selMode ? 'on' : ''}" id="ibSelBar">
      <button class="btn btn-soft btn-sm" id="ibSelMode"><i class="fa-regular fa-square-check"></i> <span>${IB.selMode ? 'إلغاء التحديد' : 'تحديد'}</span></button>
      <button class="btn btn-soft btn-sm ${IB.selMode ? '' : 'hidden'}" id="ibAll"><i class="fa-solid fa-check-double"></i> تحديد الكل</button>
      <span class="spacer"></span><span class="pill pill-blue num ${IB.selMode ? '' : 'hidden'}" id="ibSelN">0</span>
    </div>
    <div id="ibList"><div class="card"><div class="skel" style="height:200px"></div></div></div>`;
  document.body.insertAdjacentHTML('beforeend', `<div class="ib-send-bar portal hidden" id="ibSendBar"><button class="btn btn-primary btn-block shine" id="ibSend"><i class="fa-solid fa-paper-plane"></i> <span>إرسال رسالة للمحددين</span></button></div>`);
  const filtered = () => {
    const q = IB.q.toLowerCase();
    return IB.chats.filter(c => (IB.filter === 'all' || (IB.filter === 'unread' && c.unread) || (IB.filter === 'incoming' && c.incoming) || (IB.filter === 'mine' && !c.incoming)
        || (IB.filter === 'seen' && c.last?.me && c.last.st >= 4) || (IB.filter === 'unseen' && c.last?.me && (c.last.st || 0) < 4))
      && (!q || ibName(c).toLowerCase().includes(q) || (c.phone || '').includes(q.replace(/\D/g, '') || '§') || (c.last?.text || '').toLowerCase().includes(q)));
  };
  const drawSel = () => {
    const n = IB.sel.size;
    $('#ibSelN').textContent = fmt(n) + ' محدد';
    $d('#ibSendBar').classList.toggle('hidden', !(IB.selMode && n));
    document.body.classList.toggle('ib-selecting', !!(IB.selMode && n));
    $d('#ibSend span').textContent = `إرسال رسالة لـ ${fmt(n)} محادثة`;
  };
  const draw = () => {
    const list = filtered(), box = $('#ibList'); if (!box) return;
    const counts = { all: IB.chats.length, unread: IB.chats.filter(c => c.unread).length, incoming: IB.chats.filter(c => c.incoming).length, mine: IB.chats.filter(c => !c.incoming).length,
      seen: IB.chats.filter(c => c.last?.me && c.last.st >= 4).length, unseen: IB.chats.filter(c => c.last?.me && (c.last.st || 0) < 4).length };
    $$('.ib-cnt').forEach(b => { b.textContent = counts[b.dataset.c] ? ' ' + fmt(counts[b.dataset.c]) : ''; });
    if (!IB.chats.length) {
      box.innerHTML = `<div class="card">${empty('fa-regular fa-comments', 'ما فيه محادثات لحد الآن')}<div class="hint" style="text-align:center;line-height:1.9">المحادثات تنضاف تلقائياً من لحظة تحديث المحرك: كل رسالة توصلك أو ترسلها (حتى من جوالك).<br>إذا ربطت الرقم من جديد ينسحب آخر سجل محادثاتك.</div></div>`;
      return;
    }
    box.innerHTML = `<div class="list ib-list">${list.slice(0, 400).map(c => `<div class="row ib-row ${IB.sel.has(c.jid) ? 'picked' : ''}" data-j="${esc(c.jid)}">
        ${IB.selMode ? `<span class="ib-check"><i class="fa-solid fa-check"></i></span>` : ''}
        ${avatarHtml(IB.acc, c)}
        <span class="row-body"><span class="row-title">${esc(ibName(c))}</span><span class="row-sub ib-prev">${ibPreview(c.last)}</span></span>
        <span class="ib-end"><small class="${c.unread ? 'un' : ''}">${ibTime(c.ts)}</small>${c.unread ? `<b class="ib-badge num">${c.unread}</b>` : ''}</span></div>`).join('') || '<div class="hint" style="padding:16px;text-align:center">ما فيه نتائج</div>'}</div>
      ${list.length > 400 ? `<div class="hint" style="text-align:center">يظهر أول 400 - استخدم البحث</div>` : ''}`;
    drawSel();
  };
  async function load() {
    const r = await api(`/api/inbox/${IB.acc}`);
    if (!r.ok) { if (!IB.chats.length) $('#ibList').innerHTML = `<div class="card">${empty('fa-solid fa-plug-circle-xmark', r.error || 'تعذر جلب المحادثات')}</div>`; return; }
    const sig = JSON.stringify(r.chats || []);
    if (sig === IB.sig && $('#ibList .ib-list')) return; // ما تغير شي: ما نعيد الرسم (حتى الصور ما ترمش)
    IB.sig = sig; IB.chats = r.chats || [];
    draw();
  }
  $('#ibList').addEventListener('click', e => {
    const row = e.target.closest('[data-j]'); if (!row) return;
    const j = row.dataset.j;
    if (IB.selMode) { IB.sel.has(j) ? IB.sel.delete(j) : IB.sel.add(j); row.classList.toggle('picked'); drawSel(); return; }
    go('chat', { acc: IB.acc, jid: j });
  });
  $('#ibQ').addEventListener('input', e => { IB.q = e.target.value.trim(); draw(); });
  $('#ibF').addEventListener('click', e => { const b = e.target.closest('[data-f]'); if (!b) return; IB.filter = b.dataset.f; $$('#ibF .chip').forEach(x => x.classList.toggle('on', x === b)); draw(); });
  $('#ibSelMode').onclick = () => { IB.selMode = !IB.selMode; if (!IB.selMode) IB.sel.clear(); $('#ibSelBar').classList.toggle('on', IB.selMode); $('#ibSelMode span').textContent = IB.selMode ? 'إلغاء التحديد' : 'تحديد'; $('#ibAll').classList.toggle('hidden', !IB.selMode); $('#ibSelN').classList.toggle('hidden', !IB.selMode); draw(); };
  $('#ibAll').onclick = () => { const l = filtered(), all = l.every(c => IB.sel.has(c.jid)); l.forEach(c => all ? IB.sel.delete(c.jid) : IB.sel.add(c.jid)); draw(); };
  $d('#ibSend').onclick = () => go('ibcast', { acc: IB.acc });
  const accChips = $('#ibAccs'); if (accChips) accChips.addEventListener('click', e => { const c = e.target.closest('[data-id]'); if (!c) return; IB.acc = c.dataset.id; IB.sel.clear(); IB.chats = []; go('inbox', { acc: IB.acc }, false); });
  $('#ibNew').onclick = () => {
    const box = $('#ibNewBox');
    if (box.innerHTML) { box.innerHTML = ''; return; }
    box.innerHTML = `<div class="card fade-in"><div class="card-title"><i class="fa-solid fa-user-plus"></i> مراسلة رقم جديد</div>
      <div style="display:flex;gap:8px">${ccButton('ibCc')}<input class="input num" id="ibPh" inputmode="tel" dir="ltr" placeholder="07701234567" style="flex:1"></div>
      <button class="btn btn-primary btn-block" style="margin-top:10px" id="ibGo"><i class="fa-solid fa-comment"></i> افتح المحادثة</button></div>`;
    $('#ibGo').onclick = () => { const ph = $('#ibPh').value.replace(/\D/g, ''); if (ph.length < 7) return toast('اكتب رقم صحيح', 'err'); go('chat', { acc: IB.acc, phone: ph, cc: $('#ibCc').dataset.cc }); };
    $('#ibPh').focus();
  };
  if (!acc()?.logged_in) $('#ibList').insertAdjacentHTML('beforebegin', `<div class="note warn" style="margin-bottom:10px"><i class="fa-solid fa-plug-circle-xmark"></i> الرقم غير متصل - تشوف المحادثات المحفوظة بس، وما توصل رسائل جديدة لحد ما تربطه</div>`);
  await load();
  S.timers.push(setInterval(load, 6000));
};

R.chat = async (el, p) => {
  if (chatsLocked(el, 'محادثات واتساب')) return;
  if (!p.acc || (!p.jid && !p.phone)) return go('inbox', {}, false);
  document.body.classList.add('in-chat');
  let jid = p.jid || null, lastTs = 0, chat = null, msgs = [];
  const seen = new Set();
  el.innerHTML = `<div class="ch-head"><button class="back" onclick="goBack()"><i class="fa-solid fa-arrow-right"></i></button>
      <span id="chAv">${avatarHtml(p.acc, { jid: p.jid || 'none@s.whatsapp.net', name: '' })}</span><div style="flex:1;min-width:0"><b id="chName">${p.phone ? '+' + esc(p.phone) : '...'}</b><div class="row-sub"><span class="num" id="chPhone" dir="ltr"></span> <span id="chSeen" class="ch-seen"></span></div></div>
      <a class="icon-btn" id="chWa" target="_blank" rel="noopener" title="فتح بواتساب" style="display:none"><i class="fa-brands fa-whatsapp"></i></a></div>
    <div class="ch-msgs" id="chMsgs">${jid ? '<div class="skel" style="height:120px;border-radius:18px"></div>' : '<div class="hint" style="text-align:center;padding:30px">اكتب أول رسالة لهذا الرقم 👇</div>'}</div>
`;
  document.body.insertAdjacentHTML('beforeend', `<div class="ch-compose portal"><div id="chFile" class="ch-file hidden"></div>
      <div id="chPolls" class="ch-polls hidden"></div>
      <div class="ch-row"><button class="ch-btn" id="chPollBtn" title="أزرار / استبيان"><i class="fa-solid fa-hand-pointer"></i></button><label class="ch-btn"><input type="file" id="chAtt" hidden><i class="fa-solid fa-paperclip"></i></label>
        <textarea id="chTxt" rows="1" placeholder="اكتب رسالة..."></textarea>
        <button class="ch-send" id="chSend" aria-label="إرسال"><i class="fa-solid fa-paper-plane"></i></button></div></div>`);
  const box = $('#chMsgs');
  const bubble = m => {
    const t = IB_TYPE[m.type], src = `/api/inbox/${encodeURIComponent(p.acc)}/media?jid=${encodeURIComponent(jid)}&mid=${encodeURIComponent(m.id)}`;
    let body = '';
    if (m.type === 'image' || m.type === 'sticker' || m.type === 'video') {
      body = `<div class="ch-media" data-src="${esc(src)}" data-kind="${m.type}">${m.thumb ? `<img src="data:image/jpeg;base64,${esc(m.thumb)}" alt="">` : `<span class="ch-ph"><i class="${t[0]}"></i></span>`}<span class="ch-play"><i class="fa-solid ${m.type === 'video' ? 'fa-play' : 'fa-expand'}"></i></span></div>`;
    } else if (m.type === 'voice' || m.type === 'audio') {
      body = `<button class="ch-audio" data-src="${esc(src)}"><i class="fa-solid fa-play"></i> ${t[1]}${m.secs ? ` <span class="num">${Math.floor(m.secs / 60)}:${String(m.secs % 60).padStart(2, '0')}</span>` : ''}</button>`;
    } else if (m.type === 'document') {
      body = `<a class="ch-doc" href="${esc(src)}" target="_blank" rel="noopener"><i class="fa-solid fa-file-arrow-down"></i> ${esc(m.file || 'ملف')}</a>`;
    } else if (m.type === 'location') {
      body = `<a class="ch-doc" href="https://maps.google.com/?q=${Number(m.lat)},${Number(m.lng)}" target="_blank" rel="noopener"><i class="fa-solid fa-location-dot"></i> ${esc(m.text || 'موقع')}</a>`;
    } else if (t && m.type !== 'text') body = `<span class="hint"><i class="${t[0]}"></i> ${t[1]}</span>`;
    const txt = m.text && !(m.type === 'location') ? `<div class="ch-text">${esc(m.text)}</div>` : '';
    const d = new Date(m.ts * 1000);
    return `<div class="ch-b ${m.me ? 'me' : 'them'}">${body}${txt}<span class="ch-t num">${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}${m.me ? ' ' + ticks(m._tmp ? 0 : (m.st || 1)) + (m._tmp ? ' <i class="fa-regular fa-clock"></i>' : '') : ''}</span>${m.react ? `<span class="ch-react">${esc(m.react)}</span>` : ''}</div>`;
  };
  const dayLbl = ts => { const d = new Date(ts * 1000), n = new Date(); if (d.toDateString() === n.toDateString()) return 'اليوم'; const y = new Date(n); y.setDate(n.getDate() - 1); if (d.toDateString() === y.toDateString()) return 'أمس'; return d.toLocaleDateString('ar-IQ', { day: 'numeric', month: 'long', year: 'numeric' }); };
  const render = () => {
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 80 || !box.dataset.r;
    let out = '', last = '';
    for (const m of msgs) { const dl = dayLbl(m.ts); if (dl !== last) { out += `<div class="ch-day"><span>${dl}</span></div>`; last = dl; } out += bubble(m); }
    box.innerHTML = out || '<div class="hint" style="text-align:center;padding:30px">ما فيه رسائل محفوظة لهذي المحادثة - اكتب رسالة 👇</div>';
    box.dataset.r = 1;
    if (atBottom) box.scrollTop = box.scrollHeight;
  };
  const head2 = () => {
    if (!chat) return;
    $('#chName').textContent = ibName(chat);
    $('#chPhone').textContent = chat.phone ? '+' + chat.phone : '';
    if (!$('#chAv').dataset.done) { $('#chAv').innerHTML = avatarHtml(p.acc, chat); $('#chAv').dataset.done = 1; }
    $('#chSeen').innerHTML = chat.last?.me ? (chat.last.st >= 4 ? '<i class="fa-solid fa-eye"></i> شاف آخر رسالة' + (chat.incoming && !chat.unread ? '' : '') : '<i class="fa-regular fa-eye-slash"></i> لسه ما شاف آخر رسالة') : '';
    if (chat.phone) { const a = $('#chWa'); a.href = 'https://wa.me/' + chat.phone; a.style.display = ''; }
  };
  async function load(first) {
    if (!jid || S.page !== 'chat') return;
    // نجيب آخر يوم كامل حتى تتحدث علامات القراءة للرسائل القريبة
    const r = await api(`/api/inbox/${encodeURIComponent(p.acc)}/chat?jid=${encodeURIComponent(jid)}&since=${first ? 0 : Math.max(0, lastTs - 86400)}${first ? '&read=1' : ''}`);
    if (!r.ok) { if (first) box.innerHTML = `<div class="hint" style="text-align:center;padding:30px">${esc(r.error || 'تعذر التحميل')}</div>`; return; }
    chat = r.chat; head2();
    let added = false;
    for (const m of r.messages || []) { if (seen.has(m.id)) { const o = msgs.find(x => x.id === m.id); if (o && (m.st || 0) > (o.st || 0)) { o.st = m.st; added = true; } continue; } seen.add(m.id); msgs.push(m); added = true; }
    msgs = msgs.filter(m => !m._tmp || !msgs.some(x => !x._tmp && x.me && x.text === m.text && Math.abs(x.ts - m.ts) < 120));
    msgs.sort((a, b) => a.ts - b.ts);
    if (msgs.length) lastTs = Math.max(lastTs, msgs[msgs.length - 1].ts - 1);
    if (added || first) render();
  }
  // الوسائط تنحمل لما تضغط (حتى ما نستهلك نت)
  box.addEventListener('click', e => {
    const md = e.target.closest('.ch-media');
    if (md && !md.dataset.loaded) {
      md.dataset.loaded = 1; md.classList.add('loading');
      if (md.dataset.kind === 'video') md.innerHTML = `<video src="${md.dataset.src}" controls autoplay playsinline></video>`;
      else { const im = new Image(); im.onload = () => { md.classList.remove('loading'); md.innerHTML = ''; md.appendChild(im); md.classList.add('full'); }; im.onerror = () => { md.classList.remove('loading'); toast('تعذر تحميل الصورة - تأكد إن الرقم متصل', 'err'); delete md.dataset.loaded; }; im.src = md.dataset.src; }
      return;
    }
    const au = e.target.closest('.ch-audio');
    if (au) { au.outerHTML = `<audio src="${au.dataset.src}" controls autoplay></audio>`; }
  });
  let file = null;
  $d('#chAtt').onchange = e => { file = e.target.files[0] || null; const f = $d('#chFile'); f.classList.toggle('hidden', !file); if (file) f.innerHTML = `<i class="fa-solid fa-paperclip"></i> ${esc(file.name)} <span class="num">(${(file.size / 1048576).toFixed(1)}MB)</span> <button class="icon-btn" id="chFx"><i class="fa-solid fa-xmark"></i></button>`; const x = $d('#chFx'); if (x) x.onclick = () => { file = null; $d('#chAtt').value = ''; f.classList.add('hidden'); }; };
  const ta = $d('#chTxt');
  ta.addEventListener('input', () => { ta.style.height = 'auto'; ta.style.height = Math.min(140, ta.scrollHeight) + 'px'; });
  ta.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !('ontouchstart' in window)) { e.preventDefault(); $d('#chSend').click(); } });
  $d('#chSend').onclick = async () => {
    const text = ta.value.trim();
    if (!text && !file) return;
    if (file && file.size > 40 * 1048576) return toast('حجم الملف كبير (الحد 40 ميغابايت)', 'err');
    const fd = new FormData(); fd.append('text', text);
    if (jid) fd.append('jid', jid); else { fd.append('phone', p.phone); fd.append('country_code', p.cc || ''); }
    if (file) fd.append('media_file', file);
    const tmp = { id: 'tmp' + Date.now(), _tmp: 1, me: true, ts: Math.floor(Date.now() / 1000), type: file ? 'document' : 'text', text, file: file?.name };
    msgs.push(tmp); render(); box.scrollTop = box.scrollHeight;
    ta.value = ''; ta.style.height = 'auto'; const sentFile = file; file = null; $d('#chAtt').value = ''; $d('#chFile').classList.add('hidden');
    busy($d('#chSend'), true);
    const r = await api(`/api/inbox/${encodeURIComponent(p.acc)}/send`, { body: fd });
    busy($d('#chSend'), false);
    if (!r.ok) { msgs = msgs.filter(m => m !== tmp); render(); ta.value = text; file = sentFile; toast(r.error || 'ما انرسلت', 'err'); return; }
    if (!jid) { jid = r.jid; history.replaceState({ page: 'chat', params: { acc: p.acc, jid } }, '', location.href); }
    setTimeout(() => load(false), 900);
  };
  // زر الأزرار/الاستبيان: نختار قالب وينرسل
  $d('#chPollBtn').onclick = async () => {
    const box = $d('#chPolls');
    if (!box.classList.contains('hidden')) { box.classList.add('hidden'); return; }
    const polls = S.polls || await loadPolls();
    box.innerHTML = polls.length ? `<div class="ch-polls-h">أرسل أزرار أو استبيان <a onclick="go('polls')">إدارة</a></div>` + polls.map(x => `<button class="ch-poll" data-t="${x.id}"><i class="${PK[x.kind][0]}"></i> <span>${esc(x.name || x.question)}</span><small>${PK[x.kind][1]}</small></button>`).join('')
      : `<div class="hint" style="padding:8px">ما عندك قوالب. <a onclick="go('poll',{})" style="font-weight:800;color:var(--primary)">سوّي أزرار أو استبيان</a></div>`;
    box.classList.remove('hidden');
    box.querySelectorAll('[data-t]').forEach(b => b.onclick = async () => {
      box.classList.add('hidden');
      const body = { tpl: +b.dataset.t, text: ta.value.trim() };
      if (jid) body.jid = jid; else { body.phone = p.phone; body.country_code = p.cc || ''; }
      busy($d('#chPollBtn'), true);
      const r = await api(`/api/inbox/${encodeURIComponent(p.acc)}/poll`, { body });
      busy($d('#chPollBtn'), false);
      if (!r.ok) return toast(r.error || 'ما انرسل', 'err');
      ta.value = ''; toast('انرسل ✓ - لما يضغط زر يوصلك إشعار', 'ok');
      if (!jid) { jid = r.jid; history.replaceState({ page: 'chat', params: { acc: p.acc, jid } }, '', location.href); }
      setTimeout(() => load(false), 900);
    });
  };
  if (jid) await load(true); else ta.focus();
  S.timers.push(setInterval(() => load(false), 4000));
};

R.ibcast = async (el, p) => {
  if (chatsLocked(el, 'محادثات واتساب')) return;
  const jids = [...IB.sel];
  if (!jids.length) return go('inbox', { acc: p.acc });
  const names = jids.map(j => IB.chats.find(c => c.jid === j)).filter(Boolean);
  el.innerHTML = head('رسالة جماعية') + `
    <div class="card"><div class="card-title"><i class="fa-solid fa-users"></i> المستلمين <span class="spacer"></span><span class="pill pill-blue num">${fmt(jids.length)}</span></div>
      <div class="chips">${names.slice(0, 30).map(c => `<span class="chip" style="cursor:default">${esc(ibName(c))}</span>`).join('')}${names.length > 30 ? `<span class="chip">+${fmt(names.length - 30)}</span>` : ''}</div></div>
    <div class="card"><div class="card-title"><i class="fa-solid fa-message"></i> الرسالة</div>
      <textarea class="textarea" id="bcT" placeholder="اكتب رسالتك..." style="min-height:120px"></textarea>
      <label class="file-drop" style="margin-top:10px"><input type="file" id="bcF"><span class="row-ic ic-purple"><i class="fa-solid fa-paperclip"></i></span><span style="flex:1;min-width:0"><b style="font-size:13px">إرفاق صورة / فيديو / ملف</b><span class="row-sub" id="bcFn">اختياري</span></span></label>
      <div class="field" style="margin-top:12px"><label><i class="fa-solid fa-hand-pointer"></i> أزرار أو استبيان بعد الرسالة (اختياري)</label><span id="bcPollWrap"><div class="skel" style="height:44px"></div></span></div>
      <div class="field" style="margin-top:12px"><label>الفاصل بين كل رسالة (أأمن لرقمك من الحظر)</label><div class="seg" id="bcD">${[5, 10, 15, 30].map(s => `<button data-s="${s}" class="${s === 10 ? 'on' : ''}"><b class="num">${s} ث</b></button>`).join('')}</div></div>
      <div class="hint" id="bcEta"></div></div>
    <button class="btn btn-primary btn-block shine" id="bcGo"><i class="fa-solid fa-paper-plane"></i> إرسال لـ ${fmt(jids.length)} محادثة</button>`;
  let delay = 10;
  loadPolls().then(ps => { const w = $('#bcPollWrap'); if (w) w.innerHTML = pollSelect('bcPoll', ps) + (ps.length ? '' : `<div class="hint"><a onclick="go('poll',{})" style="font-weight:800;color:var(--primary);cursor:pointer">سوّي أزرار أو استبيان</a> - مثلاً زر «المزيد» يرسل تفاصيل تلقائياً</div>`); });
  const eta = () => { const s = jids.length * delay; $('#bcEta').textContent = `تقريباً ${s < 60 ? s + ' ثانية' : Math.ceil(s / 60) + ' دقيقة'} - تتابع التقدم من صفحة الحملات`; };
  eta();
  $('#bcD').addEventListener('click', e => { const b = e.target.closest('[data-s]'); if (!b) return; delay = +b.dataset.s; $$('#bcD button').forEach(x => x.classList.toggle('on', x === b)); eta(); });
  $('#bcF').onchange = e => { const f = e.target.files[0]; $('#bcFn').textContent = f ? `${f.name} (${(f.size / 1048576).toFixed(1)}MB)` : 'اختياري'; };
  $('#bcGo').onclick = async e => {
    const text = $('#bcT').value.trim(), f = $('#bcF').files[0];
    if (!text && !f) return toast('اكتب رسالة أو أرفق ملف', 'err');
    if (!await confirmSheet({ title: 'تأكيد الإرسال', text: `راح تنرسل الرسالة لـ ${fmt(jids.length)} محادثة، وحدة كل ${delay} ثانية`, ok: 'إرسال', icon: 'fa-solid fa-paper-plane', danger: false })) return;
    const fd = new FormData(); fd.append('jids', JSON.stringify(jids)); fd.append('text', text); fd.append('delay', delay); if (f) fd.append('media_file', f);
    if ($('#bcPoll')?.value) fd.append('poll_tpl', $('#bcPoll').value);
    busy(e.target.closest('button'), true);
    const r = await api(`/api/inbox/${encodeURIComponent(p.acc)}/broadcast`, { body: fd });
    busy(e.target.closest('button'), false);
    if (!r.ok) return toast(r.error || 'تعذر الإرسال', 'err');
    IB.sel.clear(); IB.selMode = false;
    toast(`بدأ الإرسال لـ ${fmt(r.total || jids.length)} محادثة`, 'ok');
    go('campaign', { acc: p.acc });
  };
};

// ---------------------------------------------------------------- أكواد الخصم (أدمن)
R.coupons = async (el) => {
  el.innerHTML = head('أكواد الخصم') + '<div id="cpl"><div class="card"><div class="skel" style="height:80px"></div></div></div>';
  const r = await api('/api/admin/coupons');
  const plans = r.plans || [];
  const form = (c = {}) => `<div class="card" id="cpForm">
    <div class="card-title"><i class="fa-solid fa-ticket"></i> ${c.id ? 'تعديل الكود' : 'كود جديد'}</div>
    <div class="input-row"><div class="field" style="flex:1.3"><label>الكود</label><input class="input" id="fCode" dir="ltr" style="text-transform:uppercase" placeholder="RAMADAN25" value="${esc(c.code || '')}"></div>
      <div class="field" style="flex:1"><label>النوع</label><select class="input" id="fType"><option value="percent" ${c.type !== 'fixed' ? 'selected' : ''}>نسبة %</option><option value="fixed" ${c.type === 'fixed' ? 'selected' : ''}>مبلغ ثابت</option></select></div></div>
    <div class="input-row"><div class="field" style="flex:1"><label>قيمة الخصم</label><input class="input num" id="fVal" type="number" min="1" value="${c.value || ''}"></div>
      <div class="field" style="flex:1"><label>عدد الاستخدامات</label><input class="input num" id="fMax" type="number" min="0" value="${c.max_uses ?? 0}"><div class="hint">0 = بدون حد</div></div></div>
    <div class="field"><label>ينتهي بتاريخ (اختياري)</label><input class="input" id="fExp" type="date" value="${esc(c.expires || '')}"></div>
    <div class="field"><label>الباقات المشمولة</label><div class="chips" id="fPlans">${plans.map(pl => `<button class="chip ${(c.plan_ids || []).includes(pl.id) ? 'on' : ''}" data-pl="${pl.id}">${esc(pl.name)}</button>`).join('')}</div><div class="hint">بدون تحديد = كل الباقات. كل مستخدم يستخدم الكود مرة وحدة.</div></div>
    <div class="input-row"><button class="btn btn-primary" style="flex:2" id="fSave"><i class="fa-solid fa-check"></i> حفظ</button>${c.id ? `<button class="btn btn-ghost" style="flex:1" id="fCancel">إلغاء</button>` : ''}</div></div>`;
  const draw = (edit) => {
    const list = r.coupons || [];
    el.querySelector('#cpl').innerHTML = form(edit) + `<div class="section-head">الأكواد <span class="pill pill-blue">${list.length}</span></div>` + (list.length ? list.map(c => {
      const expired = c.expires && new Date(c.expires + 'T23:59:59') < new Date(), full = c.max_uses && c.uses >= c.max_uses;
      return `<div class="card coupon-item ${!c.active || expired || full ? 'off' : ''}" data-id="${c.id}">
        <div style="display:flex;align-items:center;gap:12px">
          <span class="cp-code num">${esc(c.code)}</span>
          <div style="flex:1;min-width:0"><b>${c.type === 'percent' ? c.value + '%' : fmt(c.value) + ' د.ع'}</b>
            <div class="row-sub">استُخدم <b class="num">${fmt(c.uses)}</b>${c.max_uses ? ' من ' + fmt(c.max_uses) : ''}${c.expires ? ' · ينتهي ' + esc(c.expires) : ''}${expired ? ' · <span style="color:var(--red)">منتهي</span>' : ''}${c.plan_ids.length ? ' · ' + c.plan_ids.map(id => esc((plans.find(p => p.id === id) || {}).name || '')).join('، ') : ' · كل الباقات'}</div></div>
          <label class="switch"><input type="checkbox" data-act ${c.active ? 'checked' : ''}><span></span></label></div>
        <div class="input-row" style="margin-top:10px"><button class="btn btn-soft btn-xs" style="flex:1" data-edit><i class="fa-solid fa-pen"></i> تعديل</button><button class="btn btn-soft btn-xs" style="flex:1" data-cp><i class="fa-regular fa-copy"></i> نسخ</button><button class="btn btn-soft btn-xs" style="flex:1;color:var(--red)" data-del><i class="fa-solid fa-trash"></i> حذف</button></div></div>`;
    }).join('') : `<div class="card">${empty('fa-solid fa-ticket', 'ما فيه أكواد بعد')}</div>`);
    el.querySelectorAll('#fPlans [data-pl]').forEach(b => b.onclick = () => b.classList.toggle('on'));
    el.querySelector('#fSave').onclick = async e => {
      const body = { code: el.querySelector('#fCode').value, type: el.querySelector('#fType').value, value: +el.querySelector('#fVal').value, max_uses: +el.querySelector('#fMax').value,
        expires: el.querySelector('#fExp').value, plan_ids: [...el.querySelectorAll('#fPlans .on')].map(b => +b.dataset.pl) };
      busy(e.target.closest('button'), true); const s2 = await api('/api/admin/coupons' + (edit && edit.id ? '/' + edit.id : ''), { body }); busy(e.target.closest('button'), false);
      if (!s2.ok) { toast(s2.error, 'err'); return; }
      toast('تم حفظ الكود', 'ok'); go('coupons', {}, false);
    };
    if (el.querySelector('#fCancel')) el.querySelector('#fCancel').onclick = () => draw();
    el.querySelectorAll('.coupon-item').forEach(card => {
      const c = list.find(x => String(x.id) === card.dataset.id);
      card.querySelector('[data-act]').onchange = async e => { await api('/api/admin/coupons/' + c.id, { body: { active: e.target.checked } }); toast(e.target.checked ? 'تم التفعيل' : 'تم الإيقاف', 'ok'); };
      card.querySelector('[data-edit]').onclick = () => { draw(c); scrollTo({ top: 0, behavior: 'smooth' }); };
      card.querySelector('[data-cp]').onclick = () => copy(c.code);
      card.querySelector('[data-del]').onclick = ev => confirmTap(ev.target.closest('button'), async () => { await api('/api/admin/coupons/' + c.id, { method: 'DELETE' }); go('coupons', {}, false); }, 'الكود راح ينحذف نهائياً', { title: 'حذف الكود', ok: 'حذف' });
    });
  };
  draw();
};

R.reports = async (el) => {
  el.innerHTML = head('تقارير الحملات') + '<div class="card"><div class="skel" style="height:90px"></div></div>';
  const rows = await api('/dashboard/campaigns');
  const list = Array.isArray(rows) ? rows : [];
  const tot = list.reduce((a, x) => ({ sent: a.sent + x.sent, total: a.total + x.total, read: a.read + (x.read || 0), replied: a.replied + (x.replied || 0) }), { sent: 0, total: 0, read: 0, replied: 0 });
  el.innerHTML = head('تقارير الحملات') + (list.length ? `
    <div class="hero shine fade-in"><div class="hero-label">ملخص آخر ${list.length} حملة</div>
      <div class="hero-stats">
        <div><b class="num">${tot.total ? Math.round(tot.sent / tot.total * 100) : 0}%</b><span>نسبة النجاح</span></div>
        <div><b class="num">${fmt(tot.read)}</b><span>شاهدوا</span></div>
        <div><b class="num">${fmt(tot.replied)}</b><span>ردّوا</span></div>
      </div></div>
    <div class="note" style="margin:14px 0">«شاهدوا» تعتمد على إيصالات القراءة؛ اللي مطفّي إيصالات القراءة بواتساب ما ينحسب كمشاهدة إلا إذا رد. الإحصائيات تتحدث تلقائياً لمدة 7 أيام بعد الحملة.</div>
    ${list.map(reportCard).join('')}` : `<div class="card">${empty('fa-solid fa-paper-plane', 'ما فيه حملات بعد', `<button class="btn btn-primary btn-sm" onclick="go('campaign')">أرسل أول حملة</button>`)}</div>`);
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
  const payPerMsg = !ME.plan_active && !ME.trial_active && SET.message_price > 0 && !ADMIN;
  el.innerHTML = head('حملة جديدة', false, `<span class="pill pill-blue">${accs.filter(a => a.logged_in).length}/${accs.length} متصل</span>`) + `
    <div class="chips" id="accChips" style="margin-bottom:12px">${accs.map(a => `<button class="chip ${a.id === sel.id ? 'on' : ''}" data-id="${esc(a.id)}"><span class="status-dot ${a.logged_in ? 'on' : ''}"></span> ${esc(a.name)}</button>`).join('')}</div>
    <div id="cStatus"></div>
    <div class="card" id="cForm">
      <div class="card-title"><i class="fa-solid fa-address-book"></i> الأرقام <span class="spacer"></span><span class="pill pill-blue" id="numCount">0 رقم</span></div>
      <textarea class="textarea" id="numbers" placeholder="رقم بكل سطر أو مفصولة بفواصل&#10;07701234567&#10;9647709876543" dir="ltr" style="text-align:left"></textarea>
      <div class="num-stats" id="numStats">
        <span><b class="num" id="nsAll">0</b> مكتوب</span><span class="ok"><b class="num" id="nsOk">0</b> صالح</span>
        <span class="dup"><b class="num" id="nsDup">0</b> مكرر</span><span class="bad"><b class="num" id="nsBad">0</b> ناقص/خطأ</span>
      </div>
      <div class="num-tools">
        <button class="btn btn-soft btn-sm" id="rmDup" disabled><i class="fa-solid fa-clone"></i> حذف المكرر</button>
        <button class="btn btn-soft btn-sm" id="rmBad" disabled><i class="fa-solid fa-eraser"></i> حذف الناقص</button>
        <button class="btn btn-soft btn-sm" id="rmAll" disabled><i class="fa-solid fa-trash-can"></i> مسح</button>
      </div>
      <div id="ccTools" class="cc-tools hidden">
        <div class="cc-tools-h"><b><i class="fa-solid fa-globe"></i> الأرقام حسب رمز الدولة</b><small>حدد رمز أو أكثر</small></div>
        <div class="chips" id="ccChips"></div>
        <div class="input-row hidden" id="ccActs" style="margin-top:8px">
          <button class="btn btn-soft btn-sm" style="flex:1" data-cca="keep"><i class="fa-solid fa-filter"></i> إبقاء فقط</button>
          <button class="btn btn-soft btn-sm" style="flex:1" data-cca="strip"><i class="fa-solid fa-delete-left"></i> إزالة الرمز</button>
          <button class="btn btn-soft btn-sm" style="flex:1;color:var(--red)" data-cca="drop"><i class="fa-solid fa-trash-can"></i> حذف</button>
        </div>
      </div>
      <div class="input-row" style="margin-top:8px">
        <label class="file-drop" style="flex:1"><input type="file" id="numFile" accept=".xlsx,.csv,.txt,.vcf"><span class="row-ic ic-green"><i class="fa-solid fa-file-excel"></i></span><span style="flex:1;min-width:0"><b style="font-size:13px">Excel / CSV</b><span class="row-sub" id="numFileName">الأرقام بالعمود الأول</span></span></label>
        <button class="file-drop" id="contactsBtn" style="flex:1"><span class="row-ic ic-blue"><i class="fa-solid fa-address-book"></i></span><span style="flex:1;min-width:0;text-align:right"><b style="font-size:13px">جهات الاتصال</b><span class="row-sub">من جوالك أو ملف vcf</span></span></button>
        <input type="file" id="vcfFile" accept=".vcf,text/vcard,text/x-vcard" hidden>
      </div>
      <div class="field" style="margin-top:12px;display:flex;align-items:center;justify-content:space-between;gap:10px"><label style="margin:0">رمز الدولة للأرقام المحلية</label>${ccButton('cc')}</div>
      <div class="card-title" style="margin-top:6px"><i class="fa-solid fa-message"></i> الرسالة</div>
      <textarea class="textarea" id="text" placeholder="اكتب رسالتك هنا... (اختياري إذا أرفقت صورة أو ملف)"></textarea>
      ${ME.trial_active && !ME.plan_active && !ADMIN ? `<div class="trial-foot"><div class="tf-h"><i class="fa-solid fa-link"></i> بالفترة المجانية ينضاف هذا تلقائياً بآخر كل رسالة:</div><pre>${esc(SET.trial_footer || '')}</pre>
        <a onclick="go('wallet')">اشترك بباقة حتى تنشال <i class="fa-solid fa-chevron-left"></i></a></div>` : ''}
      <label class="file-drop" style="margin-top:8px"><input type="file" id="media" accept="image/*,video/*,audio/*,application/pdf,.doc,.docx,.xlsx,.zip"><span class="row-ic ic-purple"><i class="fa-solid fa-paperclip"></i></span><span style="flex:1"><b style="font-size:13.5px">إرفاق صورة / فيديو / ملف</b><span class="row-sub" id="mediaName">اختياري - ينرسل والنص تعليق عليه</span></span></label>
      <div class="input-row" style="margin-top:12px">
        <div class="field" style="flex:1"><label>الفاصل بين الرسائل (ثانية)</label><input class="input num" id="delay" type="number" min="1" value="15"></div>
        <div class="field" style="flex:1.3"><label>جدولة (اختياري)</label><input class="input" id="sendAt" type="datetime-local"></div>
      </div>
      <div class="drip-card" id="dripCard">
        <div style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-indigo"><i class="fa-solid fa-calendar-day"></i></span>
          <div style="flex:1;min-width:0"><b>إرسال <input class="drip-n num" id="dripN" type="number" min="5" max="1000" value="40"> رقم يومياً</b><div class="row-sub" id="dripSub">يتفعّل لما تكون الأرقام أكثر من 40</div></div>
          <label class="switch"><input type="checkbox" id="dripOn" disabled><span></span></label></div>
        <div id="dripMore" class="hidden">
          <div class="drip-time"><i class="fa-regular fa-clock"></i><span>تبدأ الدفعة كل يوم الساعة</span><input class="input num" type="time" id="dripTime"></div>
          <div class="seg drip-mode" id="dripMode"><button data-m="same" class="on"><b>نفس العدد كل يوم</b></button><button data-m="custom"><b>عدد مختلف لكل يوم</b></button></div>
          <div id="dripCustom" class="hidden">
            <div class="chips" id="dripPresets" style="margin:8px 0"></div>
            <div id="dripDays" class="drip-days"></div>
            <div class="hint" id="dripSum"></div>
          </div>
        </div>
      </div>
      <div class="card poll-attach"><div class="card-title"><i class="fa-solid fa-hand-pointer"></i> أزرار أو استبيان بعد الرسالة <span class="spacer"></span><a onclick="go('polls')" style="font-size:12px;cursor:pointer">إدارة</a></div><span id="cPollWrap"></span>
        <div class="hint">مثلاً زر «المزيد»: لما يضغطه ينرسله شرح تلقائياً، ويوصلك إشعار باسمه</div></div>
      <div class="note warn hidden" id="costNote"></div>
      <div id="dailyNote"></div>
      <button class="btn btn-primary btn-block" id="sendBtn" style="margin-top:12px"><i class="fa-solid fa-paper-plane"></i> إرسال الحملة</button>
    </div>
    <div id="drips"></div>
    <div class="section-head">تقارير حملات هذا الرقم <a onclick="go('reports')">كل التقارير</a></div>
    <div id="hist"></div>`;

  let accId = sel.id;
  loadPolls().then(ps => { const w = $('#cPollWrap'); if (w) w.innerHTML = ps.length ? pollSelect('cPoll', ps) : `<div class="hint"><a onclick="go('poll',{})" style="font-weight:800;color:var(--primary);cursor:pointer">+ سوّي أول أزرار أو استبيان</a></div>`; });
  const numbersEl = $('#numbers');
  const count = () => {
    const a = analyzeNumbers(numbersEl.value, $('#cc').dataset.cc);
    const n = a.valid;
    $('#numCount').textContent = fmt(n) + ' رقم';
    $('#nsAll').textContent = fmt(a.total); $('#nsOk').textContent = fmt(a.valid); $('#nsDup').textContent = fmt(a.dup); $('#nsBad').textContent = fmt(a.bad);
    $('#rmDup').disabled = !a.dup; $('#rmBad').disabled = !a.bad; $('#rmAll').disabled = !a.total;
    $('#rmDup').innerHTML = `<i class="fa-solid fa-clone"></i> حذف المكرر${a.dup ? ` (${fmt(a.dup)})` : ''}`;
    drawCcTools(a);
    const dn = Math.max(5, +$('#dripN').value || 40), on = n > dn;
    $('#dripOn').disabled = !on; if (!on) $('#dripOn').checked = false;
    $('#dripCard').classList.toggle('on', $('#dripOn').checked);
    $('#dripMore').classList.toggle('hidden', !$('#dripOn').checked);
    drawDripDays(n, dn);
    $('#dripSub').innerHTML = !on ? `يتفعّل لما تكون الأرقام أكثر من ${fmt(dn)}` : $('#dripOn').checked
      ? (dripMode === 'custom' ? `جدول مخصص - تخلص خلال <b class="num">${fmt(dripPlan(n, dn).length)}</b> يوم` : `${fmt(Math.min(n, dn))} الآن، و${fmt(dn)} كل 24 ساعة من نفس الرقم - تخلص خلال <b class="num">${fmt(Math.ceil(n / dn))}</b> يوم`)
      : `فعّله حتى ترسل ${fmt(dn)} الآن والباقي على دفعات يومية (أأمن لرقمك من الحظر)`;
    $('#rmBad').innerHTML = `<i class="fa-solid fa-eraser"></i> حذف الناقص${a.bad ? ` (${fmt(a.bad)})` : ''}`;
    const note = $('#costNote');
    if (payPerMsg && n) {
      const cost = n * SET.message_price;
      note.classList.remove('hidden');
      note.innerHTML = `التكلفة التقديرية: ${fmt(n)} × ${fmt(SET.message_price)} = <b>${fmt(cost)} د.ع</b> — رصيدك ${fmt(ME.balance)} د.ع` + (cost > ME.balance ? ` <a onclick="go('wallet')" style="cursor:pointer;font-weight:800">اشحن رصيدك</a>` : '');
    } else note.classList.add('hidden');
    return n;
  };
  numbersEl.addEventListener('input', count);
  $('#dripOn').onchange = count; $('#dripN').oninput = count;
  // وقت الدفعة اليومي: افتراضياً نفس وقت الآن (أو وقت الجدولة إذا محدد)
  const syncDripTime = () => { const v = $('#sendAt').value; const d = v ? new Date(v) : new Date(); if (!$('#dripTime').dataset.touched) $('#dripTime').value = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0'); };
  syncDripTime(); $('#sendAt').addEventListener('change', syncDripTime);
  $('#dripTime').addEventListener('input', e => { e.target.dataset.touched = 1; });
  // ---- جدول الدفعات المخصص: لكل يوم عدد (0 = كل الباقي)
  let dripMode = 'same', sched = [];
  const DMAX = (ME.limits || {}).daily || 0;
  const dayName = i => i === 0 ? 'اليوم (الآن)' : i === 1 ? 'باچر' : `اليوم ${i + 1}`;
  // يحسب توزيع الأرقام على الأيام حسب الجدول
  const dripPlan = (n, dn) => {
    const out = []; let left = n;
    for (let i = 0; left > 0 && i < 60; i++) {
      let v = sched.length ? (sched[i] ?? sched[sched.length - 1]) : dn;
      if (DMAX) v = v === 0 ? DMAX : Math.min(v, DMAX);
      const take = v === 0 ? left : Math.min(v, left);
      out.push(take); left -= take;
    }
    return out;
  };
  function drawDripDays(n, dn) {
    if (dripMode !== 'custom' || !$('#dripOn').checked) return;
    if (!sched.length) sched = [dn, dn];
    const pl = dripPlan(n, dn);
    $('#dripPresets').innerHTML = [[`اليوم ${dn} والباقي باچر`, [dn, 0]], [`${dn} ثم ${dn * 2}`, [dn, dn * 2]], ['يتضاعف كل يوم', [dn, dn * 2, dn * 4, dn * 8]]]
      .filter(([, v]) => !DMAX || v.every(x => x && x <= DMAX)).map(([t, v], i) => `<button class="chip" data-pi="${i}" data-v="${v.join(',')}">${t}</button>`).join('');
    $('#dripDays').innerHTML = pl.slice(0, 14).map((take, i) => `<div class="dd-row"><span class="dd-day">${dayName(i)}</span>
      <input class="input num" type="number" min="0" data-day="${i}" value="${sched[i] ?? ''}" placeholder="${sched[sched.length - 1] || 'الباقي'}">
      <span class="dd-take num">${fmt(take)} رقم</span></div>`).join('') + (pl.length > 14 ? `<div class="hint">... و${fmt(pl.length - 14)} يوم بعد</div>` : '');
    $('#dripSum').innerHTML = `اكتب <b>0</b> بأي يوم = يرسل كل الباقي. الأيام اللي ما حددتها تاخذ آخر عدد كتبته.${DMAX ? ` حدك اليومي ${fmt(DMAX)}.` : ''}`;
  }
  $('#dripMode').addEventListener('click', e => { const b = e.target.closest('[data-m]'); if (!b) return; dripMode = b.dataset.m; $$('#dripMode [data-m]').forEach(x => x.classList.toggle('on', x === b)); $('#dripCustom').classList.toggle('hidden', dripMode !== 'custom'); count(); });
  $('#dripPresets').addEventListener('click', e => { const b = e.target.closest('[data-v]'); if (!b) return; sched = b.dataset.v.split(',').map(Number); count(); });
  $('#dripDays').addEventListener('change', e => {
    const i = +e.target.dataset.day; if (isNaN(i)) return;
    const v = e.target.value === '' ? null : Math.max(0, +e.target.value);
    while (sched.length <= i) sched.push(sched[sched.length - 1] ?? 0);
    sched[i] = v ?? sched[i];
    count();
  });
  // حد الرسائل اليومي (الفترة المجانية / الباقة)
  const DL = ME.limits || {};
  if (DL.daily > 0) {
    const left = Math.max(0, DL.daily - (DL.daily_used || 0));
    $('#dripN').value = DL.daily; $('#dripN').max = DL.daily;
    $('#dailyNote').innerHTML = `<div class="daily-bar ${left ? '' : 'out'}"><div><b>${DL.kind === 'trial' ? 'الفترة المجانية' : 'باقتك'}: ${fmt(DL.daily)} رسالة يومياً</b><small>باقي لك اليوم <b class="num">${fmt(left)}</b> رسالة${left ? ' - للأرقام الأكثر فعّل «إرسال يومياً»' : ' - يتجدد باچر'}</small></div>
      <div class="progress"><span style="width:${Math.min(100, (DL.daily_used || 0) / DL.daily * 100)}%"></span></div>${DL.kind === 'trial' ? `<button class="btn btn-soft btn-xs" onclick="go('wallet')"><i class="fa-solid fa-gem"></i> اشترك</button>` : ''}</div>`;
  }
  // ---- أدوات رمز الدولة: تجميع الأرقام المكتوبة مع رمز دولة
  let ccSel = new Set();
  const CODES = B.country_codes.map(x => x[0]).sort((a, b) => b.length - a.length);
  const codeOf = raw => {
    const t = String(raw).trim(), dg = t.replace(/\D/g, '');
    const plus = /^(\+|00)/.test(t), d = dg.replace(/^00/, '');
    const c = CODES.find(x => d.startsWith(x));
    if (!c) return null;
    if (plus) return c;
    // بدون + نعتبره مكتوب برمز إذا طوله يطابق الرقم الدولي لهذي الدولة
    return NUM_LEN[c] ? (d.length === NUM_LEN[c] ? c : null) : (d.length >= 11 ? c : null);
  };
  function drawCcTools(a) {
    const groups = {}; let local = 0;
    a.items.forEach(it => { const c = codeOf(it.raw); if (c) groups[c] = (groups[c] || 0) + 1; else local++; });
    const keys = Object.keys(groups).sort((x, y) => groups[y] - groups[x]);
    ccSel = new Set([...ccSel].filter(c => groups[c] || (c === 'local' && local)));
    $('#ccTools').classList.toggle('hidden', !keys.length);
    $('#ccChips').innerHTML = keys.map(c => `<button class="chip ${ccSel.has(c) ? 'on' : ''}" data-cc="${c}"><span class="num">${esc(ccLabel(c))}</span> <b class="num">${fmt(groups[c])}</b></button>`).join('')
      + (local ? `<button class="chip ${ccSel.has('local') ? 'on' : ''}" data-cc="local"><i class="fa-solid fa-phone"></i> بدون رمز <b class="num">${fmt(local)}</b></button>` : '');
    $('#ccActs').classList.toggle('hidden', !ccSel.size);
    $('#ccActs [data-cca="strip"]').disabled = ccSel.size === 1 && ccSel.has('local');
  }
  $('#ccChips').addEventListener('click', e => { const b = e.target.closest('[data-cc]'); if (!b) return; const c = b.dataset.cc; ccSel.has(c) ? ccSel.delete(c) : ccSel.add(c); count(); });
  $('#ccActs').addEventListener('click', e => {
    const b = e.target.closest('[data-cca]'); if (!b || !ccSel.size) return;
    const act = b.dataset.cca, a = analyzeNumbers(numbersEl.value, $('#cc').dataset.cc);
    const picked = it => ccSel.has(codeOf(it.raw) || 'local');
    let changed = 0;
    const lines = a.items.flatMap(it => {
      const sel = picked(it);
      if (act === 'keep') { if (!sel) changed++; return sel ? [it.raw] : []; }
      if (act === 'drop') { if (sel) changed++; return sel ? [] : [it.raw]; }
      const c = codeOf(it.raw);
      if (!sel || !c) return [it.raw];
      changed++;
      return ['0' + it.raw.replace(/\D/g, '').replace(/^00/, '').slice(c.length).replace(/^0+/, '')];
    });
    numbersEl.value = lines.join('\n'); ccSel = new Set(); count();
    toast({ keep: `انحذف ${fmt(changed)} رقم مو من الرموز المحددة`, drop: `انحذف ${fmt(changed)} رقم`, strip: `انشال الرمز من ${fmt(changed)} رقم` }[act], 'ok');
  });
  new MutationObserver(count).observe($('#cc'), { attributes: true, attributeFilter: ['data-cc'] });
  const rewrite = (keep, msg) => {
    const a = analyzeNumbers(numbersEl.value, $('#cc').dataset.cc), seen = new Set(); let removed = 0;
    const lines = a.items.filter(it => { const ok = keep(it, seen); if (!ok) removed++; else seen.add(it.n); return ok; }).map(it => it.raw);
    numbersEl.value = lines.join('\n'); count(); toast(`${msg}: ${fmt(removed)} رقم`, 'ok');
  };
  $('#rmDup').onclick = () => rewrite((it, seen) => !seen.has(it.n), 'انحذف المكرر');
  $('#rmBad').onclick = () => rewrite(it => it.ok, 'انحذف الناقص والخطأ');
  $('#rmAll').onclick = e => confirmTap(e.target.closest('button'), () => { numbersEl.value = ''; count(); }, 'راح تنمسح كل الأرقام المكتوبة', { title: 'مسح الأرقام', ok: 'مسح' });
  if (S.pendingText) { $('#text').value = S.pendingText; S.pendingText = null; }
  if (S.pendingNumbers) { numbersEl.value = S.pendingNumbers.join('\n'); S.pendingNumbers = null; setTimeout(() => toast(`تمت إضافة ${fmt(numbersEl.value.split('\n').length)} رقم`, 'ok'), 300); }
  const addNums = (arr, label) => {
    const clean = arr.map(x => String(x).replace(/[^\d+]/g, '').replace(/^\+/, '').replace(/^00/, '')).filter(x => x.length >= 8);
    numbersEl.value = (numbersEl.value.trim() ? numbersEl.value.trim() + '\n' : '') + clean.join('\n');
    toast(`تمت إضافة ${fmt(clean.length)} رقم ${label}`, 'ok'); count();
  };
  const parseVcf = txt => (txt.match(/^TEL[^:]*:(.+)$/gmi) || []).map(l => l.split(':').slice(1).join(':').trim());
  $('#contactsBtn').addEventListener('click', async () => {
    // متصفحات أندرويد (كروم) تدعم اختيار جهات الاتصال مباشرة
    if ('contacts' in navigator && 'select' in navigator.contacts) {
      try {
        const picked = await navigator.contacts.select(['tel', 'name'], { multiple: true });
        addNums(picked.flatMap(c => c.tel || []), 'من جهات الاتصال');
        return;
      } catch (e) { /* ألغى المستخدم أو غير مدعوم */ }
    }
    toast('صدّر جهات الاتصال كملف vcf من تطبيق جهات الاتصال ثم اختره');
    $('#vcfFile').click();
  });
  $('#vcfFile').addEventListener('change', async e => { const f = e.target.files[0]; if (f) addNums(parseVcf(await f.text()), 'من الملف'); e.target.value = ''; });
  $('#numFile').addEventListener('change', async e => {
    const f = e.target.files[0]; if (!f) return;
    if (/\.vcf$/i.test(f.name)) { addNums(parseVcf(await f.text()), 'من الملف'); return; }
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
    accId = c.dataset.id; $$('#accChips .chip').forEach(x => x.classList.toggle('on', x === c)); loadStatus(); loadHist(); loadDrips();
  });
  count();
  $('#sendBtn').addEventListener('click', async () => {
    if (!count()) { toast('أضف أرقام صالحة أولاً', 'err'); return; }
    if (!$('#text').value.trim() && !$('#media').files[0]) { toast('اكتب رسالة أو أرفق صورة/ملف', 'err'); return; }
    const fd = new FormData();
    fd.append('numbers_text', numbersEl.value); fd.append('country_code', $('#cc').dataset.cc); fd.append('text', $('#text').value);
    fd.append('delay', $('#delay').value || 15);
    if ($('#dripOn').checked) {
      fd.append('drip', '1'); fd.append('drip_batch', Math.max(1, +$('#dripN').value || 40)); fd.append('drip_time', $('#dripTime').value);
      if (dripMode === 'custom' && sched.length) fd.append('drip_schedule', JSON.stringify(sched));
    }
    if ($('#sendAt').value) fd.append('send_at', $('#sendAt').value);
    if ($('#cPoll')?.value) fd.append('poll_tpl', $('#cPoll').value);
    if ($('#media').files[0]) fd.append('media_file', $('#media').files[0]);
    busy($('#sendBtn'), true);
    const r = await api(`/accounts/${accId}/campaign`, { body: fd });
    busy($('#sendBtn'), false);
    if (!r.ok) {
      toast(r.error || 'تعذر بدء الحملة', 'err');
      if (r.needs_subscription) setTimeout(() => go('wallet'), 1200);
      if (r.daily_left > 0 && !$('#dripOn').disabled) { $('#dripN').value = r.daily_left; $('#dripOn').checked = true; count(); }
      return;
    }
    if (DL.daily > 0) { DL.daily_used = (DL.daily_used || 0) + Math.min(r.total || 0, DL.daily); }
    toast(r.drip ? `بدأت أول دفعة (${fmt(r.drip.batch)} رقم) - الباقي ${fmt(r.drip.total - r.drip.batch)} على ${fmt(r.drip.days - 1)} يوم، كل يوم الساعة ${r.drip.time}` : r.scheduled_for ? 'تمت جدولة الحملة: ' + r.scheduled_for : `بدأت الحملة على ${fmt(r.total)} رقم - الأرقام اللي ما عليها واتساب تنشال تلقائياً`, 'ok');
    numbersEl.value = ''; $('#dripOn').checked = false; count(); loadStatus(); loadDrips();
  });

  async function loadStatus() {
    const s = await api(`/accounts/${accId}/campaign_status`);
    const box = el.querySelector('#cStatus'); if (!box) return;
    if (!s.running && !s.paused) { box.innerHTML = ''; return; }
    const done = s.sent + s.failed, pct = s.total ? Math.round(done / s.total * 100) : 0;
    const pend = s.pending_numbers || [];
    box.innerHTML = `<div class="card fade-in" style="margin-bottom:12px;border-color:var(--primary)">
      ${s.checking ? `<div class="note" style="margin-bottom:10px"><i class="fa-solid fa-filter"></i> نفحص الأرقام قبل البدء... <b class="num">${fmt(s.checking.done)}/${fmt(s.checking.total)}</b> - اللي ما عليه واتساب ينشال</div>` : s.skipped_invalid ? `<div class="note" style="margin-bottom:10px"><i class="fa-solid fa-ban"></i> انشال <b class="num">${fmt(s.skipped_invalid)}</b> رقم ما عليه واتساب قبل البدء</div>` : ''}
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
    box.classList.remove('list', 'mini-list');
    box.innerHTML = Array.isArray(h) && h.length ? h.map(reportCard).join('') : `<div class="card">${empty('fa-regular fa-clock', 'ما فيه حملات سابقة لهذا الرقم')}</div>`;
  }
  async function loadDrips() {
    const r = await api('/api/drips'); const box = el.querySelector('#drips'); if (!box || !r.ok) return;
    const mine = r.drips.filter(x => x.account_id === accId);
    box.innerHTML = mine.length ? `<div class="section-head">دفعات يومية مجدولة <span class="pill pill-blue">${mine.length}</span></div>` + mine.map(x => `<div class="card drip-item" style="margin-bottom:10px">
      <div style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-indigo"><i class="fa-solid fa-calendar-day"></i></span>
        <div style="flex:1;min-width:0"><b>${esc(x.text || 'حملة')}</b><div class="row-sub">باقي <b class="num">${fmt(x.left)}</b> من ${fmt(x.total)} · ${x.custom ? 'جدول مخصص' : fmt(x.batch) + ' يومياً'}${x.anchor ? ` · كل يوم <b class="num">${esc(x.anchor)}</b>` : ''} · الجاية <b class="num">${fmt(x.next_batch)}</b> رقم <span class="num">${esc(x.next_at)}</span></div></div>
        <button class="btn btn-soft btn-xs" style="color:var(--red)" data-dc="${x.id}"><i class="fa-solid fa-xmark"></i> إلغاء</button></div>
      <div class="progress" style="margin-top:10px"><span style="width:${Math.round((x.total - x.left) / x.total * 100)}%"></span></div>
      <div class="drip-today">
        <span><i class="fa-solid fa-circle-check" style="color:var(--green)"></i> اليوم نجح <b class="num">${fmt(x.today_ok)}</b> من <b class="num">${fmt(x.today_size)}</b>${x.today_running ? ' <small>(جارِ الإرسال)</small>' : ''}</span>
        ${x.retry ? `<span><i class="fa-solid fa-rotate-right" style="color:#d97706"></i> <b class="num">${fmt(x.retry)}</b> فشلت وتنعاد باچر</span>` : ''}
        ${x.invalid ? `<span><i class="fa-solid fa-ban" style="color:var(--red)"></i> <b class="num">${fmt(x.invalid)}</b> بدون واتساب</span>` : ''}
      </div>
      <div class="hint" style="margin-top:6px">الرسائل الفاشلة ما تنحسب من حصة اليوم - نكمّل الناقص من الأرقام الباقية بنفس اليوم</div></div>`).join('') : '';
    box.querySelectorAll('[data-dc]').forEach(b => b.onclick = () => confirmTap(b, async () => { await api('/api/drips/' + b.dataset.dc, { method: 'DELETE' }); loadDrips(); }, 'الدفعات الباقية ما راح تنرسل', { title: 'إلغاء الدفعات', ok: 'إلغاء الدفعات' }));
  }
  loadStatus(); loadHist(); loadDrips(); every(3500, loadStatus);
};

// ---------------------------------------------------------------- تحليل الأرقام (مكرر / ناقص)
// الطول الدولي الصحيح لكل رمز دولة (0 = متغير)
const NUM_LEN = { 964: 13, 966: 12, 971: 12, 965: 11, 974: 11, 973: 11, 968: 11, 962: 12, 963: 12, 961: 0, 20: 12, 218: 12, 216: 11, 213: 12, 212: 12, 967: 12, 249: 12, 970: 12, 90: 12, 98: 12, 1: 11, 44: 12, 49: 0, 33: 11 };
function normNumber(raw, cc) {
  let d = String(raw).replace(/\D/g, '');
  if (!d) return '';
  if (/^\s*\+/.test(String(raw))) return d; // مكتوب برمز دولي
  if (d.startsWith('00')) d = d.slice(2);
  else if (cc && !d.startsWith(cc)) d = cc + d.replace(/^0+/, '');
  return d;
}
function numberOk(d) {
  if (d.length < 8 || d.length > 15) return false;
  const cc = Object.keys(NUM_LEN).sort((a, b) => b.length - a.length).find(c => d.startsWith(c));
  if (!cc) return d.length >= 10;
  if (cc === '964' && !/^9647\d{9}$/.test(d) && !/^964[1-6]\d{7,8}$/.test(d)) return false;
  return NUM_LEN[cc] ? d.length === NUM_LEN[cc] : d.length >= 10;
}
function analyzeNumbers(text, cc) {
  const items = String(text).split(/[\n,،;]+/).map(x => x.trim()).filter(Boolean).flatMap(x => /\d\s+\d{4,}/.test(x) && x.replace(/\D/g, '').length > 15 ? x.split(/\s+/) : [x])
    .map(raw => { const n = normNumber(raw, cc); return { raw, n, ok: numberOk(n) }; });
  const seen = new Set(); let dup = 0, bad = 0, valid = 0;
  items.forEach(it => { if (!it.ok) { bad++; return; } if (seen.has(it.n)) { dup++; return; } seen.add(it.n); valid++; });
  return { items, total: items.length, dup, bad, valid };
}

// ---------------------------------------------------------------- استخراج أرقام من الخرائط (أدمن)
// ---------------------------------------------------------------- سحب أرقام من الخرائط (بحث سريع - لكل المستخدمين)
R.leads = async (el, p) => {
  el.innerHTML = head('سحب أرقام من الخرائط') + '<div class="card"><div class="skel" style="height:160px"></div></div>';
  const d = await api('/api/leads');
  if (!d.ok) { el.innerHTML = head('سحب أرقام من الخرائط') + `<div class="card">${empty('fa-solid fa-lock', d.error || 'تعذر التحميل')}</div>`; return; }
  const Q = d.quota;
  const last = (() => { try { return JSON.parse(localStorage.getItem('wasel_leads_form') || '{}'); } catch (e) { return {}; } })();
  const maxOpts = [50, 100, 200, 500].filter(v => v <= Q.max);
  let max = maxOpts.includes(last.max) ? last.max : Math.min(100, Q.max), skipOld = last.skip_old !== false;
  const quotaPill = () => Q.unlimited ? `<span class="pill pill-blue"><i class="fa-solid fa-infinity"></i> بدون حد</span>` : `<span class="pill ${Q.left ? 'pill-green' : 'pill-red'} num">${fmt(Q.left)}/${fmt(Q.daily)} محاولة اليوم</span>`;
  el.innerHTML = head('سحب أرقام من الخرائط', true, quotaPill()) + `
    <div class="hero shine"><div style="display:flex;align-items:center;gap:14px"><span class="hero-icon"><i class="fa-solid fa-map-location-dot"></i></span>
      <div><div style="font-weight:800;font-size:17px">أرقام المحلات والشركات من خرائط Google</div><div class="hero-sub">اختر المدينة ونوع النشاط، ونجيب الاسم والرقم والعنوان والتقييم - وبعدها أرسل لهم حملة بضغطة</div></div></div></div>
    ${!Q.enabled ? `<div class="note warn" style="margin-top:12px"><i class="fa-solid fa-circle-pause"></i> سحب الأرقام متوقف حالياً من الإدارة</div>` : ''}
    <div class="card" style="margin-top:12px">
      <div class="field"><label>الدولة</label><select class="input" id="lc">${d.countries.map(c => `<option value="${c.iso}" ${c.iso === (last.country || 'IQ') ? 'selected' : ''}>${esc(c.name)} (+${c.cc})</option>`).join('')}</select></div>
      <div class="input-row">
        <div class="field" style="flex:1"><label>المدينة / المنطقة</label><input class="input" id="lcity" placeholder="مثال: البصرة" value="${esc(last.city || '')}"></div>
        <div class="field" style="flex:1"><label>نوع النشاط / المنتج</label><input class="input" id="lkw" placeholder="مثال: سيراميك" value="${esc(last.keyword || '')}"></div>
      </div>
      <div class="chips" id="lsug" style="margin:-2px 0 10px">${['سيراميك', 'مطاعم', 'صالونات', 'صيدليات', 'موبايلات', 'أثاث', 'ملابس', 'مواد بناء', 'عيادات', 'مكاتب عقار'].map(k => `<button class="chip" data-k="${k}">${k}</button>`).join('')}</div>
      <div class="field"><label>أقصى عدد محلات</label><div class="seg" id="lmax">${maxOpts.map(v => `<button data-v="${v}" class="${v === max ? 'on' : ''}"><b class="num">${v}</b></button>`).join('')}</div></div>
      <label class="kv" style="cursor:pointer;margin-bottom:6px"><span>استبعد الأرقام اللي سحبتها قبل<small class="row-sub" style="display:block">ما يتكرر عندك نفس الرقم بأكثر من بحث</small></span><label class="switch"><input type="checkbox" id="lskip" ${skipOld ? 'checked' : ''}><span></span></label></label>
      <div class="hint" style="margin-bottom:10px"><i class="fa-solid fa-circle-info"></i> إذا بحثت عن نفس الشي مرة ثانية نفتحلك النتائج المحفوظة بدون ما تنحسب محاولة.</div>
      <button class="btn btn-primary btn-block shine" id="lgo" ${Q.enabled && (Q.unlimited || Q.left > 0) ? '' : 'disabled'}><i class="fa-solid fa-magnifying-glass-location"></i> ابحث واسحب الأرقام</button>
      ${Q.enabled && !Q.unlimited && Q.left <= 0 ? `<div class="hint" style="text-align:center;margin-top:8px">خلصت محاولات اليوم - ترجع تتجدد باچر</div>` : ''}
    </div>
    <div id="lprog" style="margin-top:12px"></div>
    <div id="lres"></div>
    <div class="section-head">بحوثي السابقة</div>
    <div class="list mini-list" id="lhist">${d.searches.length ? d.searches.map(s => rowItem({
      icon: 'fa-solid fa-location-dot', ic: 'ic-green', title: `${esc(s.keyword)} - ${esc(s.city)}`,
      sub: `<span class="num">${esc(s.time)}</span> · ${esc(s.country)} · ${fmt(s.phones)} رقم`, onclick: `go('leads',{id:${s.id}})`,
    })).join('') : empty('fa-solid fa-map', 'ما سويت أي بحث بعد')}</div>`;

  el.querySelectorAll('#lmax button').forEach(b => b.onclick = () => { max = +b.dataset.v; el.querySelectorAll('#lmax button').forEach(x => x.classList.toggle('on', x === b)); });
  el.querySelectorAll('#lsug [data-k]').forEach(b => b.onclick = () => { $('#lkw').value = b.dataset.k; });
  $('#lskip').onchange = e => { skipOld = e.target.checked; };
  const search = async (force, btn) => {
    const body = { country: $('#lc').value, city: $('#lcity').value.trim(), keyword: $('#lkw').value.trim(), max, skip_old: skipOld, force };
    if (!body.city || !body.keyword) { toast('اكتب المدينة ونوع النشاط', 'err'); return; }
    try { localStorage.setItem('wasel_leads_form', JSON.stringify(body)); } catch (er) { }
    busy(btn, true);
    $('#lprog').innerHTML = `<div class="card lead-prog" style="text-align:center"><div class="spinner"></div><b style="display:block;margin-top:10px">نبحث بخرائط Google...</b><div class="hint">ياخذ من 10 ثواني لدقيقة حسب العدد - لا تسد الصفحة</div></div>`;
    const r = await api('/api/leads/search', { body });
    busy(btn, false); $('#lprog').innerHTML = '';
    if (!r.ok) { $('#lprog').innerHTML = `<div class="note warn"><i class="fa-solid fa-circle-exclamation"></i> ${esc(r.error || 'تعذر البحث')}</div>`; return; }
    if (r.repeated) {
      toast('هذا البحث سويته قبل - فتحنا نتائجه المحفوظة بدون ما تنحسب محاولة', 'ok');
      go('leads', { id: r.search.id, repeated: 1 }); return;
    }
    toast(`تم: ${fmt(r.search.phones)} رقم${r.search.skipped ? ` (استبعدنا ${fmt(r.search.skipped)} سحبتها قبل)` : ''}`, 'ok');
    go('leads', { id: r.search.id });
  };
  $('#lgo').onclick = e => search(false, e.target.closest('button'));
  if (p.id) {
    const r = await api('/api/leads/' + p.id);
    if (r.ok) {
      if (p.repeated) $('#lprog').innerHTML = `<div class="note"><i class="fa-solid fa-clock-rotate-left"></i> هذي نتائج بحثك السابق (${esc(r.search.time)}).
        ${Q.unlimited || Q.left > 0 ? `<button class="btn btn-soft btn-sm" style="margin-top:8px" id="lforce"><i class="fa-solid fa-rotate"></i> ابحث من جديد (تنحسب محاولة)</button>` : ''}</div>`;
      if ($('#lforce')) $('#lforce').onclick = e => search(true, e.target.closest('button'));
      showResults(r.search);
    }
  }
  function showResults(s) {
    let onlyMobile = s.iso === 'IQ', q = '';
    const box = $('#lres');
    const list = () => s.results.filter(x => x.phone && (!onlyMobile || x.mobile !== false) && (!q || (x.name + x.address).includes(q)));
    const draw = () => {
      const L = list(), withPhone = s.results.filter(x => x.phone).length;
      box.innerHTML = `<div class="section-head">النتائج: ${esc(s.keyword)} - ${esc(s.city)} <span class="pill pill-green num">${fmt(L.length)} رقم</span></div>
        <div class="stats three">
          <div class="stat"><span class="stat-v num">${fmt(s.count)}</span><span class="stat-l">محل وشركة</span></div>
          <div class="stat"><span class="stat-v num" style="color:var(--green)">${fmt(withPhone)}</span><span class="stat-l">عندهم رقم</span></div>
          <div class="stat"><span class="stat-v num" style="color:var(--primary)">${fmt(s.results.filter(x => x.mobile).length)}</span><span class="stat-l">موبايل</span></div>
        </div>
        <div class="card" style="margin-top:12px">
          <input class="input" id="lq" placeholder="فلترة بالاسم أو العنوان..." value="${esc(q)}">
          <label class="kv" style="margin-top:8px;cursor:pointer"><span>أرقام الموبايل فقط (اللي عليها واتساب)</span><label class="switch"><input type="checkbox" id="lmob" ${onlyMobile ? 'checked' : ''}><span></span></label></label>
          <div class="input-row" style="margin-top:10px">
            <button class="btn btn-primary btn-sm" style="flex:1.4" id="toCamp" ${L.length ? '' : 'disabled'}><i class="fa-solid fa-paper-plane"></i> أرسل لهم حملة</button>
            <button class="btn btn-soft btn-sm" style="flex:1" id="csv" ${L.length ? '' : 'disabled'}><i class="fa-solid fa-file-csv"></i> Excel</button>
            <button class="btn btn-soft btn-sm" style="flex:1" id="cp" ${L.length ? '' : 'disabled'}><i class="fa-regular fa-copy"></i> نسخ</button>
          </div>
          ${s.id ? `<button class="btn btn-ghost btn-sm btn-block" style="margin-top:8px;color:var(--red)" id="ldel"><i class="fa-solid fa-trash-can"></i> حذف هذا البحث</button>` : ''}
        </div>
        <div class="list stagger" style="margin-top:12px">${L.length ? L.slice(0, 300).map(x => `<div class="row lead-row">
          <span class="row-ic ${x.mobile ? 'ic-wa' : 'ic-gray'}"><i class="fa-${x.mobile ? 'brands fa-whatsapp' : 'solid fa-phone'}"></i></span>
          <div class="row-body"><div class="row-title">${esc(x.name)}</div>
            <div class="row-sub"><span class="num" dir="ltr">+${esc(x.phone)}</span>${x.rating ? ` · <i class="fa-solid fa-star" style="color:#f5a524"></i> ${x.rating}` : ''}${x.type ? ' · ' + esc(x.type) : ''}</div>
            <div class="row-sub">${esc(x.address)}</div>${x.website ? `<div class="row-sub" dir="ltr" style="text-align:right"><i class="fa-solid fa-globe"></i> ${esc(x.website.replace(/^https?:\/\/(www\.)?/, '').slice(0, 40))}</div>` : ''}</div>
          ${x.map ? `<a class="btn btn-soft btn-xs" href="${esc(x.map)}" target="_blank" rel="noopener"><i class="fa-solid fa-location-arrow"></i></a>` : ''}</div>`).join('')
          + (L.length > 300 ? `<div class="hint" style="text-align:center;padding:10px">يظهر أول 300 - الكل ينضاف للحملة والملف</div>` : '')
          : empty('fa-solid fa-map-pin', 'ما فيه أرقام بهذا الفلتر')}</div>`;
      $('#lq').oninput = e => { q = e.target.value.trim(); const pos = e.target.selectionStart; draw(); const i = $('#lq'); i.focus(); i.setSelectionRange(pos, pos); };
      $('#lmob').onchange = e => { onlyMobile = e.target.checked; draw(); };
      const nums = () => [...new Set(list().map(x => x.phone))];
      $('#toCamp').onclick = () => { S.pendingNumbers = nums(); go('campaign'); };
      $('#cp').onclick = () => copy(nums().join('\n'));
      $('#csv').onclick = () => {
        const rows = [['الاسم', 'الرقم', 'موبايل', 'العنوان', 'النوع', 'التقييم', 'الموقع الإلكتروني', 'الخريطة'], ...list().map(x => [x.name, x.phone, x.mobile ? 'نعم' : x.mobile === false ? 'لا' : '', x.address, x.type, x.rating ?? '', x.website || '', x.map])];
        const csv = '﻿' + rows.map(r => r.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\r\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = `${s.keyword}-${s.city}.csv`; a.click();
      };
      if ($('#ldel')) $('#ldel').onclick = e => confirmTap(e.target.closest('button'), async () => { await api('/api/leads/' + s.id, { method: 'DELETE' }); go('leads', {}, false); }, 'راح ينحذف هذا البحث ونتائجه', { title: 'حذف البحث', ok: 'حذف' });
    };
    draw();
    box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
};

// ---------------------------------------------------------------- أرقام واتساب
R.accounts = async (el) => {
  el.innerHTML = head('أرقام واتساب', false) + '<div class="list"><div style="padding:14px"><div class="skel" style="height:44px"></div></div></div>';
  const accs = await loadAccounts();
  const L = ME.limits || {}, full = L.numbers > 0 && accs.length >= L.numbers;
  const limPill = L.numbers > 0 ? `<span class="pill ${full ? 'pill-orange' : 'pill-blue'} num">${fmt(accs.length)}/${fmt(L.numbers)} أرقام</span>` : `<span class="pill pill-green">${accs.filter(a => a.logged_in).length} متصل</span>`;
  el.innerHTML = head('أرقام واتساب', false, limPill) + `
    <div class="list stagger">${accs.length ? accs.map(a => rowItem({
      icon: 'fa-brands fa-whatsapp', ic: a.logged_in ? 'ic-wa' : 'ic-red', img: a.logged_in ? avUrl(a.id, 'me') : '', title: `${esc(a.name)} ${a.otp_sender ? '<span class="pill pill-blue">مرسل الرموز</span>' : ''}`,
      sub: `<span class="status-dot ${a.logged_in ? 'on' : ''}"></span> ${a.logged_in ? (a.wa_name ? esc(a.wa_name) + ' · ' : '') + (a.phone ? '<span class="num" dir="ltr">+' + esc(a.phone) + '</span>' : 'متصل وجاهز للإرسال') : 'غير متصل - اضغط للربط'}`, onclick: `go('acc',{id:'${esc(a.id)}'})`,
    })).join('') : empty('fa-brands fa-whatsapp', 'ما ربطت أي رقم بعد')}</div>
    <div class="section-head">ربط رقم جديد</div>
    ${full ? `<div class="card limit-card"><span class="row-ic ic-orange"><i class="fa-solid fa-lock"></i></span>
      <div style="flex:1"><b>وصلت حد الأرقام (${fmt(L.numbers)})</b><div class="row-sub" style="white-space:normal">${L.kind === 'trial' ? 'الفترة المجانية تسمح برقم واتساب واحد. اشترك بباقة حتى تربط أرقام أكثر.' : 'باقتك تسمح بـ ' + fmt(L.numbers) + ' أرقام. احذف رقم أو رقّي باقتك.'}</div></div>
      <button class="btn btn-primary btn-sm" onclick="go('wallet')"><i class="fa-solid fa-gem"></i> الباقات</button></div>` : `
    <div class="card">
      ${L.numbers > 0 ? `<div class="note" style="margin-bottom:10px"><i class="fa-solid fa-circle-info"></i> ${L.kind === 'trial' ? 'الفترة المجانية' : 'باقتك'}: حتى ${fmt(L.numbers)} ${L.numbers === 1 ? 'رقم' : 'أرقام'} واتساب - باقي ${fmt(L.numbers - accs.length)}</div>` : ''}
      <div class="field"><label>اسم الحساب (للتمييز)</label><input class="input" id="accName" placeholder="مثال: رقم المبيعات"></div>
      <button class="btn btn-wa btn-block" id="addBtn"><i class="fa-solid fa-link"></i> متابعة للربط</button>
      <div class="hint" style="text-align:center;margin-top:8px">تقدر تربط بمسح الباركود أو بإدخال رمز ربط بالأجهزة المرتبطة</div>
    </div>`}`;
  if (full) return;
  $('#addBtn').onclick = async () => {
    busy($('#addBtn'), true);
    const r = await api('/accounts', { body: { name: $('#accName').value.trim() } });
    busy($('#addBtn'), false);
    if (!r.id) { toast(r.error || 'تعذر إنشاء الحساب', 'err'); if (r.limit) setTimeout(() => go('wallet'), 1500); return; }
    if (ME.limits) ME.limits.numbers_used++;
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
  $('#delBtn').onclick = e => confirmTap(e.target.closest('button'), async () => { await api(`/accounts/${a.id}/logout`, { body: {} }); toast('تم حذف الحساب', 'ok'); go('accounts'); });
  if (ADMIN) $('#otpSw').onchange = async e => { const r = await api(`/accounts/${a.id}/otp_sender`, { body: { enabled: e.target.checked } }); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); };

  let mode = 'qr', connected = a.logged_in;
  const link = el.querySelector('#link');
  function renderLink() {
    if (connected) {
      link.innerHTML = `<div class="hero" style="background:linear-gradient(135deg,#065f46,#10b981)"><div style="display:flex;align-items:center;gap:12px">${avatarHtml(a.id, { jid: 'me', name: a.wa_name || a.name }, 'ib-av hero-av')}<div><div style="font-weight:800;font-size:17px">${esc(a.wa_name || 'الرقم متصل')}</div><div class="hero-sub">${a.phone ? '<span class="num" dir="ltr">+' + esc(a.phone) + '</span> · ' : ''}متصل وجاهز للإرسال والرد</div></div></div></div>
        <div class="input-row" style="margin-top:12px;gap:8px"><button class="btn btn-primary" style="flex:1" onclick="go('inbox',{acc:'${esc(a.id)}'})"><i class="fa-solid fa-comments"></i> المحادثات</button>
        <button class="btn btn-soft" style="flex:1" onclick="go('campaign',{acc:'${esc(a.id)}'})"><i class="fa-solid fa-paper-plane"></i> حملة</button></div>`;
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
          <div class="field"><label>رقم واتساب اللي تريد تربطه</label><div class="input-row">${ccButton('pairCc')}<input class="input num" id="pairPhone" inputmode="numeric" placeholder="7701234567" style="direction:ltr;text-align:left;flex:1"></div></div>
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
      let ph = link.querySelector('#pairPhone').value.replace(/\D/g, '').replace(/^0+/, '');
      const pcc = link.querySelector('#pairCc').dataset.cc;
      if (!ph.startsWith(pcc) || ph.length < 11) ph = pcc + ph;
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
  const prof = Object.assign({}, cfg.ai_profile || {});
  const AI_HINTS = { business_name: 'مثال: متجر الأمل للإلكترونيات', business_type: 'مثال: متجر موبايلات وإكسسوارات', description: 'شنو تقدم؟ شنو يميزك؟ أي معلومات يحتاجها العميل...',
    products: 'كل منتج أو خدمة بسطر مع سعره، مثال:\nآيفون 15 - 1,250,000 د.ع\nشاحن سريع - 25,000 د.ع', hours: 'مثال: يومياً من 10 صباحاً لـ 10 مساءً', location: 'مثال: بغداد - الكرادة، توصيل لكل المحافظات 5,000',
    contact: 'مثال: الطلب برسالة هنا أو اتصال على 0770...', tone: 'مثال: ودود ومختصر', rules: 'مثال: إذا سأل عن خصم قل عندنا خصم 10% للطلبات فوق 100 ألف', forbidden: 'مثال: لا تعطي أرقام شخصية ولا تتكلم بالسياسة' };
  const ar = el.querySelector('#ar');
  function renderAr() {
    ar.innerHTML = `
      <div style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-green"><i class="fa-solid fa-robot"></i></span><div style="flex:1"><b>تفعيل الرد الآلي</b><div class="row-sub">يرد على الرسائل الواردة تلقائياً</div></div><label class="switch"><input type="checkbox" id="arOn" ${cfg.enabled ? 'checked' : ''}><span></span></label></div>
      <div style="display:flex;align-items:center;gap:12px;margin-top:12px"><span class="row-ic ic-purple"><i class="fa-solid fa-wand-magic-sparkles"></i></span><div style="flex:1"><b>الرد بالذكاء الاصطناعي</b><div class="row-sub">${cfg.ai_available ? 'يفهم المحادثة ويرد حسب معلومات نشاطك' : 'غير متاح حالياً - تفعّله إدارة المنصة'}</div></div><label class="switch"><input type="checkbox" id="arAi" ${cfg.ai_enabled ? 'checked' : ''} ${cfg.ai_available ? '' : 'disabled'}><span></span></label></div>
      <div id="aiCard" class="${cfg.ai_enabled ? '' : 'hidden'}" style="margin-top:14px;padding:16px;border-radius:20px;background:rgba(124,58,237,.05);border:1px solid rgba(124,58,237,.12)">
        <div class="card-title" style="margin-bottom:6px"><i class="fa-solid fa-clipboard-list" style="background:rgba(124,58,237,.1);color:#7c3aed"></i> بطاقة تعليمات المساعد الذكي</div>
        <div class="hint" style="margin:0 0 12px">كل ما كانت المعلومات أدق، كان الرد أذكى. الحقول اللي عليها * إلزامية للتفعيل.</div>
        ${Object.entries(cfg.ai_fields || {}).map(([k, f]) => `<div class="field"><label>${esc(f.label)}</label>${f.max > 300
          ? `<textarea class="textarea" data-ai="${k}" maxlength="${f.max}" placeholder="${esc(AI_HINTS[k] || '')}" style="min-height:${k === 'products' ? 130 : 90}px">${esc(prof[k] || '')}</textarea>`
          : `<input class="input" data-ai="${k}" maxlength="${f.max}" placeholder="${esc(AI_HINTS[k] || '')}" value="${esc(prof[k] || '')}">`}</div>`).join('')}
      </div>
      <div class="divider">الكلمات المفتاحية</div>
      ${rules.map((r, i) => `<div class="input-row" style="margin-bottom:8px"><input class="input" data-k="${i}" value="${esc(r.keyword)}" placeholder="إذا احتوت" style="flex:1"><input class="input" data-r="${i}" value="${esc(r.reply)}" placeholder="الرد" style="flex:1.6"><button class="btn btn-red btn-sm" data-del="${i}" style="height:48px"><i class="fa-solid fa-trash"></i></button></div>`).join('')}
      <button class="btn btn-ghost btn-block btn-sm" id="arAdd"><i class="fa-solid fa-plus"></i> إضافة كلمة</button>
      <button class="btn btn-primary btn-block" id="arSave" style="margin-top:10px">حفظ الرد الآلي</button>`;
    const sync = () => {
      ar.querySelectorAll('[data-k]').forEach(i => rules[i.dataset.k].keyword = i.value); ar.querySelectorAll('[data-r]').forEach(i => rules[i.dataset.r].reply = i.value);
      ar.querySelectorAll('[data-ai]').forEach(i => prof[i.dataset.ai] = i.value);
    };
    ar.querySelector('#arAi').onchange = e => {
      ar.querySelector('#aiCard').classList.toggle('hidden', !e.target.checked);
      if (e.target.checked) { ar.querySelector('#aiCard').classList.add('fade-in'); ar.querySelector('#aiCard').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
    };
    ar.querySelector('#arAdd').onclick = () => { sync(); rules.push({ keyword: '', reply: '' }); cfg.enabled = ar.querySelector('#arOn').checked; cfg.ai_enabled = ar.querySelector('#arAi').checked; cfg.ai_profile = prof; renderAr(); };
    ar.querySelectorAll('[data-del]').forEach(b => b.onclick = () => { sync(); rules.splice(+b.dataset.del, 1); renderAr(); });
    ar.querySelector('#arSave').onclick = async e => {
      sync(); busy(e.target.closest('button'), true);
      const btn = e.target.closest('button');
      const r = await api(`/accounts/${a.id}/auto_reply`, { body: { enabled: ar.querySelector('#arOn').checked, ai_enabled: ar.querySelector('#arAi').checked, rules, ai_profile: prof } });
      busy(btn, false); toast(r.ok ? 'تم حفظ الرد الآلي' : r.error, r.ok ? 'ok' : 'err');
      if (!r.ok && r.missing) ar.querySelectorAll('[data-ai]').forEach(i => { if (!i.value.trim() && /\*/.test(i.previousElementSibling?.textContent || '')) i.style.borderColor = 'var(--red)'; });
    };
  }
  renderAr();
};

// ---------------------------------------------------------------- الشحن
const PM_ICON = { asiacell: ['fa-solid fa-sim-card', 'ic-red'], binance: ['fa-brands fa-bitcoin', 'ic-orange'], superkey: ['fa-solid fa-credit-card', 'ic-purple'], manual: ['fa-solid fa-building-columns', 'ic-cyan'] };
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
    const cp = S.coupon && String(S.coupon.plan_id) === String(chosen.id) ? S.coupon : null;
    const price = cp ? cp.final : chosen.price;
    const enough = ME.balance >= price;
    el.innerHTML = head(esc(chosen.name)) + `
      <div class="plan solid shine fade-in" style="cursor:default">
        <div class="plan-top"><span class="plan-name">${esc(chosen.name)}</span><span class="pill pill-white">${fmt(chosen.days)} يوم</span></div>
        <div class="plan-price">${cp ? `<s class="num old-price">${fmt(chosen.price)}</s> ` : ''}<span class="num">${fmt(price)}</span> <small>د.ع</small></div>
        <ul>${(chosen.limit_lines || []).concat(chosen.features_list).map(f => `<li>${esc(f)}</li>`).join('')}</ul>
      </div>
      <div class="card coupon-card">
        ${cp ? `<div class="coupon-on"><span class="row-ic ic-green"><i class="fa-solid fa-ticket"></i></span><div style="flex:1"><b class="num">${esc(cp.code)}</b><div class="row-sub">${esc(cp.label)} - وفّرت ${fmt(cp.discount)} د.ع</div></div><button class="btn btn-soft btn-xs" id="cpOff"><i class="fa-solid fa-xmark"></i> إزالة</button></div>`
        : `<div class="coupon-row"><i class="fa-solid fa-ticket"></i><input class="input" id="cpIn" placeholder="عندك كود خصم؟" dir="ltr" style="text-transform:uppercase"><button class="btn btn-primary btn-sm" id="cpGo">تطبيق</button></div>`}
      </div>
      <div class="stepper"><div class="st done"><b>1</b>اختر الباقة</div><div class="st ${enough ? 'done' : ''}"><b>2</b>ادفع</div><div class="st"><b>3</b>تتفعّل فوراً</div></div>
      ${enough ? `<div class="card" style="margin-top:14px"><div class="card-title"><i class="fa-solid fa-wallet"></i> الدفع من رصيدك <span class="spacer"></span><span class="pill pill-green num">${fmt(ME.balance)} د.ع</span></div>
        <button class="btn btn-primary btn-block" id="buyNow"><i class="fa-solid fa-gem"></i> تفعيل الباقة الآن</button></div>`
        : `<div class="note warn" style="margin-top:14px">رصيدك ${fmt(ME.balance)} د.ع - ادفع ${fmt(price - ME.balance)} د.ع أو أكثر بإحدى الطرق، والباقة تتفعّل تلقائياً أول ما يوصل المبلغ.</div>`}
      <div class="section-head">${enough ? 'أو ادفع بطريقة ثانية' : 'اختر طريقة الدفع'}</div>${methodsList(chosen.id)}`;
    const ci = el.querySelector('#cpGo');
    if (ci) ci.onclick = async e => {
      const code = el.querySelector('#cpIn').value.trim(); if (!code) { toast('اكتب الكود', 'err'); return; }
      busy(e.target.closest('button'), true); const r = await api('/api/coupon/check', { body: { code, plan_id: chosen.id } }); busy(e.target.closest('button'), false);
      if (!r.ok) { toast(r.error, 'err'); return; }
      S.coupon = { plan_id: chosen.id, code: r.code, final: r.final, discount: r.discount, label: r.label };
      toast(`${r.label} - السعر صار ${fmt(r.final)} د.ع`, 'ok'); go('wallet', { plan: chosen.id }, false);
    };
    const co = el.querySelector('#cpOff'); if (co) co.onclick = () => { S.coupon = null; go('wallet', { plan: chosen.id }, false); };
    const b = el.querySelector('#buyNow');
    if (b) b.onclick = () => confirmTap(b, async () => {
      busy(b, true); const r = await api('/api/plan/buy', { body: { plan_id: chosen.id, coupon: cp ? cp.code : '' } }); busy(b, false);
      if (!r.ok) { toast(r.error, 'err'); return; }
      S.coupon = null;
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
    <div class="stagger">${S.plans.map((pl, i) => `<div class="plan ${pl.popular ? 'featured shine' : ''} ${ME.plan_active && ME.plan_name === pl.name ? 'current' : ''}" onclick="go('wallet',{plan:${pl.id}})">
      <div class="plan-top"><div style="display:flex;align-items:center;gap:10px"><span class="plan-ic"><i class="fa-solid ${['fa-paper-plane', 'fa-rocket', 'fa-crown', 'fa-gem'][i % 4]}"></i></span><div><span class="plan-name">${esc(pl.name)}</span><div class="plan-days">${fmt(pl.days)} يوم</div></div></div>${pl.popular ? '<span class="plan-badge"><i class="fa-solid fa-star"></i> الأكثر طلباً</span>' : ME.plan_active && ME.plan_name === pl.name ? '<span class="pill pill-green">باقتك</span>' : ''}</div>
      <div class="plan-price"><span class="num">${fmt(pl.price)}</span> <small>د.ع</small>${pl.days >= 60 ? `<span class="plan-per">≈ ${fmt(Math.round(pl.price / pl.days * 30))} د.ع شهرياً</span>` : ''}</div>
      <div class="plan-sep"></div>
      <ul>${(pl.limit_lines || []).concat(pl.features_list).map(f => `<li>${esc(f)}</li>`).join('')}</ul>
      <span class="btn ${pl.popular ? 'btn-white' : 'btn-primary'}">${ME.plan_active && ME.plan_name === pl.name ? 'تجديد الباقة' : 'اشترك الآن'} <i class="fa-solid fa-arrow-left"></i></span></div>`).join('') || `<div class="card">${empty('fa-solid fa-gem', 'ما فيه باقات حالياً')}</div>`}</div>
    ${SET.message_price > 0 ? `<div class="section-head">أو ادفع حسب استخدامك</div>
    <div class="card" style="display:flex;gap:14px;align-items:center"><span class="row-ic" style="width:54px;height:54px;font-size:22px"><i class="fa-solid fa-coins"></i></span>
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
  const cp = plan && S.coupon && String(S.coupon.plan_id) === String(plan.id) ? S.coupon : null;
  const planPrice = plan ? (cp ? cp.final : plan.price) : 0;
  const need = plan ? Math.max(0, planPrice - ME.balance) : 0;
  el.innerHTML = head(p.rest ? 'تسديد الباقي' : 'الدفع عبر ' + esc(m.name)) + top + (plan ? `<div class="note" style="margin-top:12px"><i class="fa-solid fa-gem"></i> الدفع لـ <b>${esc(plan.name)}</b>${cp ? ` (بعد كود <b class="num">${esc(cp.code)}</b>)` : ''} - تتفعّل تلقائياً بعد وصول المبلغ (${fmt(planPrice)} د.ع)</div>` : '') + '<div id="payBody" style="margin-top:12px"></div>';
  const body = el.querySelector('#payBody');
  const coupon = cp ? cp.code : '';
  // بعد دفع جزء من سعر الباقة: صفحة «تسديد الباقي»
  const showRemaining = (res, paidMsg) => {
    const remaining = res.remaining || 0, paid = Math.max(0, planPrice - remaining);
    body.innerHTML = `<div class="card fade-in rest-card">
      <div style="text-align:center"><span class="row-ic ic-green" style="width:58px;height:58px;font-size:24px;margin:4px auto 10px"><i class="fa-solid fa-check"></i></span>
        <b style="font-size:16px">${esc(paidMsg || 'تم الدفع')}</b></div>
      <div class="rest-prog"><div class="rp-top"><span>مدفوع <b class="num">${fmt(paid)}</b></span><span>من <b class="num">${fmt(planPrice)}</b> د.ع</span></div>
        <div class="progress"><span style="width:${Math.min(100, paid / planPrice * 100)}%"></span></div></div>
      <div class="rest-due"><small>باقي لتفعيل ${esc(plan.name)}</small><b class="num">${fmt(remaining)}</b><small>د.ع</small></div>
      <button class="btn btn-primary btn-block shine" id="payRest"><i class="fa-solid fa-wallet"></i> سدّد الباقي ${fmt(remaining)} د.ع</button>
      <button class="btn btn-ghost btn-block" style="margin-top:8px" onclick="go('wallet',{plan:${plan.id}})">ادفع الباقي بطريقة ثانية</button>
      <div class="hint" style="text-align:center;margin-top:8px">المبلغ اللي دفعته محفوظ برصيدك، والباقة تتفعّل تلقائياً أول ما يكتمل</div></div>`;
    body.querySelector('#payRest').onclick = () => go('pay', { id: m.id, plan: plan.id, rest: 1 }, false);
  };

  if (m.type === 'manual' || m.type === 'superkey') {
    const quick = [5000, 10000, 25000, 50000];
    body.innerHTML = `<div class="card">
      <div class="numbered"><b>1</b><span>حوّل المبلغ للرقم التالي</span></div>
      <div class="copy-box"><span class="v">${esc(m.number || '—')}</span><button class="btn btn-soft btn-xs" onclick="copy('${esc(m.number)}')"><i class="fa-regular fa-copy"></i> نسخ</button></div>
      <div class="numbered" style="margin-top:14px"><b>2</b><span>${plan ? 'حوّل المبلغ المحدد بالضبط وارفع صورة الإيصال' : 'اكتب المبلغ اللي حوّلته وارفع صورة الإيصال'}</span></div>
      ${plan ? `<div class="fixed-amt"><span>المبلغ المطلوب</span><b class="num">${fmt(need)}</b><small>د.ع</small><input type="hidden" id="amt" value="${need}"></div>`
      : `<input class="input big num" id="amt" type="number" inputmode="numeric" placeholder="0" min="${m.min_amount}">
      <div class="chips" style="margin-top:8px;justify-content:center">${quick.map(q => `<button class="chip num" data-q="${q}">${fmt(q)}</button>`).join('')}</div>`}
      <div class="field" style="margin-top:12px"><label>رقم العملية / ملاحظة (اختياري)</label><input class="input" id="ref"></div>
      <label class="file-drop"><input type="file" id="rc" accept="image/*"><span class="row-ic ic-blue"><i class="fa-solid fa-receipt"></i></span><span style="flex:1"><b style="font-size:13.5px">صورة الإيصال</b><span class="row-sub" id="rcName">اضغط لاختيار صورة</span></span></label>
      <img id="rcPrev" class="receipt hidden" style="margin-top:10px">
      <div class="numbered" style="margin-top:14px"><b>3</b><span>نراجع الطلب ونضيف الرصيد لحسابك</span></div>
      <button class="btn btn-primary btn-block" id="submit"><i class="fa-solid fa-paper-plane"></i> إرسال طلب الشحن</button>
      ${m.min_amount && !plan ? `<div class="hint" style="text-align:center">أقل مبلغ ${fmt(m.min_amount)} د.ع</div>` : ''}</div>`;
    body.querySelectorAll('[data-q]').forEach(c => c.onclick = () => { body.querySelector('#amt').value = c.dataset.q; body.querySelectorAll('[data-q]').forEach(x => x.classList.toggle('on', x === c)); });
    body.querySelector('#rc').onchange = async e => { const f = e.target.files[0]; if (!f) return; body.querySelector('#rcName').textContent = f.name; const im = body.querySelector('#rcPrev'); im.src = await fileToDataUrl(f); im.classList.remove('hidden'); };
    body.querySelector('#submit').onclick = async e => {
      const fd = new FormData();
      fd.append('method_id', m.id); fd.append('amount', body.querySelector('#amt').value); fd.append('ref', body.querySelector('#ref').value);
      if (plan) { fd.append('plan_id', plan.id); fd.append('coupon', coupon); }
      if (body.querySelector('#rc').files[0]) fd.append('receipt', body.querySelector('#rc').files[0]);
      busy(e.target.closest('button'), true); const r = await api('/api/recharge/manual', { body: fd }); busy(e.target.closest('button'), false);
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
      ${plan ? `<div class="fixed-amt"><span>أرسل بالضبط</span><b class="num">$${(Math.ceil(need / m.usd_rate * 100) / 100).toFixed(2)}</b><small>USDT</small><input type="hidden" id="usd" value="${Math.ceil(need / m.usd_rate * 100) / 100}"></div>`
      : `<input class="input big num" id="usd" type="number" step="0.01" inputmode="decimal" placeholder="$0.00">`}
      <div class="hint" style="text-align:center" id="eq">سعر الصرف: 1$ = ${fmt(m.usd_rate)} د.ع</div>
      <div class="field" style="margin-top:10px"><label>رقم العملية Order ID</label><input class="input num" id="oid" inputmode="numeric" style="direction:ltr;text-align:left"></div>
      <button class="btn btn-primary btn-block" id="verify"><i class="fa-solid fa-shield-check"></i> تحقق وأضف الرصيد</button>
      <div class="hint" style="text-align:center;margin-top:6px">يتم التحقق تلقائياً خلال ثواني</div></div>`;
    if (!plan) body.querySelector('#usd').oninput = e => { const v = parseFloat(e.target.value) || 0; body.querySelector('#eq').textContent = v ? `= ${fmt(Math.round(v * m.usd_rate))} د.ع تنضاف لرصيدك` : `سعر الصرف: 1$ = ${fmt(m.usd_rate)} د.ع`; };
    body.querySelector('#verify').onclick = async e => {
      busy(e.target.closest('button'), true);
      const r = await api('/api/recharge/binance', { body: { amount_usd: body.querySelector('#usd').value, order_id: body.querySelector('#oid').value.trim(), plan_id: plan ? plan.id : null, coupon } });
      busy(e.target.closest('button'), false);
      if (!r.ok) { toast(r.error, 'err'); return; }
      await refreshMe();
      if (plan && r.plan && !r.plan.done && r.plan.remaining > 0) { showRemaining(r.plan, r.msg); return; }
      if (plan && r.plan && r.plan.done) S.coupon = null;
      toast(r.msg, 'ok'); go('home');
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
      const tierNote = () => {
        const n = body.querySelector('#tierNote'); if (!n || !plan) return;
        n.innerHTML = amount >= need ? `<i class="fa-solid fa-circle-check"></i> هذا المبلغ يكفي - الباقة تتفعّل مباشرة بعد التحويل`
          : `<i class="fa-solid fa-circle-info"></i> راح تدفع ${fmt(amount)} الآن، وبعدها تظهر لك صفحة لتسديد الباقي ${fmt(need - amount)} د.ع`;
        n.className = 'note ' + (amount >= need ? 'ok-note' : 'warn');
      };
      body.querySelectorAll('.tier').forEach(t => t.onclick = () => { amount = +t.dataset.a; body.querySelectorAll('.tier').forEach(x => x.classList.toggle('on', x === t)); tierNote(); });
      if (plan) {
        const opts = (m.tiers || []).map(t => +t.iqd).sort((a, b) => a - b);
        amount = opts.find(v => v >= need) || opts[opts.length - 1];
        body.querySelectorAll('.tier').forEach(x => x.classList.toggle('on', +x.dataset.a === amount));
        body.querySelector('.tier-grid').insertAdjacentHTML('beforebegin', `<div class="fixed-amt" style="margin-bottom:10px"><span>${p.rest ? 'الباقي' : 'المطلوب'}</span><b class="num">${fmt(need)}</b><small>د.ع</small></div>`);
        body.querySelector('.tier-grid').insertAdjacentHTML('afterend', `<div id="tierNote" style="margin-top:8px"></div>`);
        tierNote();
      }
      body.querySelector('#start').onclick = async e => {
        if (!amount) { toast('اختر المبلغ', 'err'); return; }
        busy(e.target.closest('button'), true); const r = await api('/api/recharge/asiacell/start', { body: { phone: body.querySelector('#ph').value, amount, plan_id: plan ? plan.id : null, coupon } }); busy(e.target.closest('button'), false);
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
        busy(e.target.closest('button'), true); const r = await api('/api/recharge/asiacell/' + step, { body: { code: body.querySelector('#code').value } }); busy(e.target.closest('button'), false);
        if (!r.ok) { toast(r.error, 'err'); return; }
        if (step === 'verify' || r.done === false) { codeStep('confirm', r.msg); return; }
        await refreshMe();
        if (plan && r.plan && !r.plan.done && r.plan.remaining > 0) { showRemaining(r.plan, r.msg); return; }
        if (plan && r.plan && r.plan.done) S.coupon = null;
        body.innerHTML = `<div class="card fade-in" style="text-align:center"><span class="row-ic ic-green" style="width:64px;height:64px;font-size:28px;margin:6px auto 12px"><i class="fa-solid fa-check"></i></span><b style="font-size:17px">${esc(r.msg)}</b>${plan && r.plan && r.plan.done ? `<div class="note ok-note" style="margin-top:10px"><i class="fa-solid fa-gem"></i> تفعّلت ${esc(plan.name)}</div>` : ''}<button class="btn btn-primary btn-block" style="margin-top:14px" onclick="go('wallet')">رجوع للباقات</button></div>`;
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
  const first = (ME.name || '؟').trim()[0] || '؟';
  const contact = ME.email || (ME.phone ? '+' + ME.phone : '');
  const dev = SET.dev;
  el.innerHTML = `
    <div class="card" style="display:flex;align-items:center;gap:12px">
      <div class="avatar" style="background:var(--btn);color:#fff;position:relative;box-shadow:0 8px 18px var(--primary-glow)">${esc(first)}</div>
      <div style="flex:1;min-width:0"><b style="font-size:16.5px">${esc(ME.name || 'بدون اسم')}</b>
        <div class="row-sub num" style="text-align:right">${esc(contact)}</div>
        <div style="margin-top:5px;display:flex;gap:6px;flex-wrap:wrap"><span class="pill pill-gray"><i class="fa-${ME.login === 'google' ? 'brands fa-google' : ME.login === 'email' ? 'solid fa-envelope' : 'brands fa-whatsapp'}"></i> ${ME.login === 'google' ? 'Google' : ME.login === 'email' ? 'بريد' : 'واتساب'}</span>${ADMIN ? '<span class="pill pill-blue"><i class="fa-solid fa-shield"></i> أدمن</span>' : ''}</div></div>
      <button class="chev" onclick="go('profile')" style="width:38px;height:38px"><i class="fa-solid fa-pen"></i></button>
    </div>
    <div class="stats three stagger" style="margin-top:12px">
      <div class="stat" onclick="go('wallet')" style="cursor:pointer"><span class="stat-ic ic-teal"><i class="fa-solid fa-wallet"></i></span><span class="stat-v num">${fmt(ME.balance)}</span><span class="stat-l">الرصيد (د.ع)</span></div>
      <div class="stat" onclick="go('wallet')" style="cursor:pointer"><span class="stat-ic ic-orange"><i class="fa-solid fa-crown"></i></span><span class="stat-v">${ME.plan_active ? (ME.plan_days_left === null ? 'دائم' : ME.plan_days_left + ' يوم') : ME.trial_active ? ME.trial_days_left + ' يوم' : '—'}</span><span class="stat-l">${ME.plan_active ? esc(ME.plan_name || 'الباقة') : ME.trial_active ? 'التجربة' : 'بدون باقة'}</span></div>
      <div class="stat" onclick="go('reports')" style="cursor:pointer"><span class="stat-ic ic-green"><i class="fa-solid fa-paper-plane"></i></span><span class="stat-v num" id="msgCount">…</span><span class="stat-l">رسائل مرسلة</span></div>
    </div>
    <div class="section-head">حسابي</div>
    <div class="list stagger">
      ${rowItem({ icon: 'fa-solid fa-comments', ic: 'ic-wa', title: 'محادثات واتساب' + (ME.chats_allowed ? '' : ' <i class="fa-solid fa-lock" style="font-size:11px;color:var(--text3)"></i>'), sub: 'شوف محادثات رقمك ورد عليها، وراسل مجموعة دفعة وحدة', onclick: "go('inbox')" })}
      ${rowItem({ icon: 'fa-solid fa-hand-pointer', ic: 'ic-teal', title: 'الأزرار والاستبيانات', sub: 'أزرار تنضغط بواتساب، استبيانات وتقييم، ونتائجها', onclick: "go('polls')" })}
      ${rowItem({ icon: 'fa-solid fa-bell', ic: 'ic-orange', title: 'إشعارات الجوال', sub: 'لما أحد يرد أو يشوف رسالتك أو يضغط زر', onclick: "go('pushset')" })}
      ${rowItem({ icon: 'fa-solid fa-chart-pie', ic: 'ic-purple', title: 'تقارير الحملات', sub: 'النجاح، المشاهدة والردود', onclick: "go('reports')" })}
      ${SET.leads_enabled || ADMIN ? rowItem({ icon: 'fa-solid fa-map-location-dot', ic: 'ic-green', title: 'سحب أرقام من الخرائط', sub: 'أرقام المحلات والشركات حسب المدينة والنشاط', onclick: "go('leads')" }) : ''}
      ${rowItem({ icon: 'fa-solid fa-palette', ic: 'ic-pink', title: 'المظهر', sub: 'الستايل، الوضع الداكن، الخط وحجمه', onclick: "go('appearance')" })}
      ${rowItem({ icon: 'fa-solid fa-code', ic: 'ic-orange', title: 'ربط API', sub: 'أرسل رسائل ورموز تحقق من موقعك', onclick: "go('apikeys')" })}
      ${rowItem({ icon: 'fa-solid fa-clock-rotate-left', ic: 'ic-cyan', title: 'السجل المالي', sub: 'حركات الرصيد وطلبات الشحن', onclick: "go('tx')" })}
      ${rowItem({ icon: 'fa-solid fa-user-shield', ic: 'ic-indigo', title: 'الخصوصية والأمان', sub: 'كيف نحمي بياناتك وشروط الاستخدام', onclick: "window.open('/privacy','_blank')" })}
      ${rowItem({ icon: 'fa-solid fa-headset', ic: 'ic-wa', title: 'الدعم الفني', sub: 'تواصل معنا على واتساب', onclick: `window.open('https://wa.me/${esc(SET.support_number)}','_blank')` })}
    </div>
    ${ADMIN ? `<div class="section-head">الإدارة <span class="pill pill-blue">أدمن</span></div>
    <div class="list stagger">
      ${rowItem({ icon: 'fa-solid fa-gauge-high', ic: 'ic-pink', title: 'لوحة الإدارة', sub: 'نظرة عامة على المنصة', onclick: "go('admin')" })}
      ${rowItem({ icon: 'fa-solid fa-users', ic: 'ic-blue', title: 'المستخدمين', sub: 'الاشتراكات، الفترات المجانية، الأرصدة', onclick: "go('users')" })}
      ${rowItem({ icon: 'fa-solid fa-list-check', ic: 'ic-green', title: 'طلبات الشحن', sub: 'مراجعة التحويلات اليدوية', end: `<span id="pendBadge"></span><span class="chev"><i class="fa-solid fa-chevron-left"></i></span>`, onclick: "go('recharges')" })}
      ${rowItem({ icon: 'fa-solid fa-gem', ic: 'ic-purple', title: 'الباقات', sub: 'الأسعار والمدد والمزايا', onclick: "go('plansAdmin')" })}
      ${rowItem({ icon: 'fa-solid fa-credit-card', ic: 'ic-orange', title: 'طرق الدفع', sub: 'آسياسيل، Binance، سوبر كي، اليدوي', onclick: "go('payments')" })}
      ${rowItem({ icon: 'fa-solid fa-sliders', ic: 'ic-indigo', title: 'الإعدادات', sub: 'الأسعار، الشعار، المطور، Cron', onclick: "go('settings')" })}
      ${rowItem({ icon: 'fa-solid fa-shield-halved', ic: 'ic-red', title: 'مركز الأمان', sub: 'سجل الدخول والحظر والجلسات', onclick: "go('security')" })}
      ${rowItem({ icon: 'fa-brands fa-telegram', ic: 'ic-cyan', title: 'النسخ الاحتياطي', sub: 'تصدير واستيراد JSON عبر بوت تيليجرام', onclick: "go('backup')" })}
      ${rowItem({ icon: 'fa-solid fa-ticket', ic: 'ic-pink', title: 'أكواد الخصم', sub: 'خصم على الاشتراك بالباقات', onclick: "go('coupons')" })}
      ${rowItem({ icon: 'fa-solid fa-bullhorn', ic: 'ic-wa', title: 'إعلان جماعي', sub: 'رسالة واتساب لكل المستخدمين', onclick: "go('announce')" })}
    </div>` : ''}
    ${dev ? `<div class="section-head">المطوّر</div>
    <div class="dev-card shine fade-in">
      ${dev.photo ? `<img class="dev-photo glow" src="${esc(dev.photo)}" alt="">` : `<span class="dev-photo glow">${esc((dev.name || 'م')[0])}</span>`}
      <div style="min-width:0"><small>${esc(dev.title)}</small><b>${esc(dev.name)}</b></div>
      <a class="dev-wa" href="https://wa.me/${esc(dev.phone)}" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp" style="font-size:18px"></i> تواصل</a>
    </div>` : ''}
    <button class="btn btn-red btn-block" style="margin-top:18px" id="logout"><i class="fa-solid fa-right-from-bracket"></i> تسجيل الخروج</button>`;
  el.querySelector('#logout').onclick = async () => {
    if (!await confirmSheet({ title: 'تسجيل الخروج', text: 'راح تطلع من حسابك على هذا الجهاز. تقدر ترجع تدخل بأي وقت.', ok: 'تسجيل الخروج', icon: 'fa-solid fa-right-from-bracket' })) return;
    const f = document.createElement('form'); f.method = 'POST'; f.action = '/logout'; document.body.appendChild(f); f.submit();
  };
  // البيانات الثانوية تنجلب بعد العرض (حتى الصفحة تفتح فوراً)
  api('/dashboard/stats').then(st => { const m = el.querySelector('#msgCount'); if (m) m.textContent = fmt(st.messages_sent || 0); });
  if (ADMIN) api('/api/admin/stats').then(s => { const b = el.querySelector('#pendBadge'); if (b && s.pending_recharges) b.innerHTML = `<span class="pill pill-red">${s.pending_recharges}</span>`; });
  refreshMe();
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
  el.querySelector('#save').onclick = async e => { busy(e.target.closest('button'), true); const r = await api('/api/me', { body: { name: el.querySelector('#nm').value } }); busy(e.target.closest('button'), false); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); refreshMe(); };
  el.querySelector('#wipe').onclick = e => confirmTap(e.target.closest('button'), async () => { const r = await api('/settings/delete_my_data', { body: {} }); toast(`تم حذف ${r.deleted || 0} حساب`, 'ok'); });
};

R.notifications = async (el) => {
  el.innerHTML = head('الإشعارات', true, `<button class="btn btn-ghost btn-sm" id="bulkBtn"></button>`) + `<div class="swipe-hint"><i class="fa-solid fa-hand-pointer"></i> اسحب الإشعار يمين أو يسار لحذفه</div><div id="nl"><div class="card"><div class="skel" style="height:70px"></div></div></div>`;
  const KIND = { success: ['fa-solid fa-circle-check', 'ic-green'], warning: ['fa-solid fa-triangle-exclamation', 'ic-orange'], info: ['fa-solid fa-bell', 'ic-blue'], announce: ['fa-solid fa-bullhorn', 'ic-pink'] };
  const LINKS = { inbox: 'المحادثات', polls: 'الأزرار والاستبيانات', wallet: 'الباقات', campaign: 'حملة جديدة', leads: 'سحب الأرقام', accounts: 'أرقامي', reports: 'التقارير', apikeys: 'ربط API' };
  let list = [];
  const bulk = el.querySelector('#bulkBtn');
  function setBulk() {
    const anyUnread = list.some(e => !e.read);
    bulk.className = 'btn btn-sm ' + (anyUnread ? 'btn-ghost' : 'btn-red') + (list.length ? '' : ' hidden');
    bulk.innerHTML = anyUnread ? '<i class="fa-solid fa-check-double"></i> قراءة الكل' : '<i class="fa-solid fa-trash"></i> حذف الكل';
  }
  function render() {
    const box = el.querySelector('#nl');
    setBulk();
    el.querySelector('.swipe-hint').classList.toggle('hidden', !list.length);
    if (!list.length) { box.innerHTML = `<div class="card">${empty('fa-regular fa-bell', 'ما فيه إشعارات')}</div>`; return; }
    const card = e => { const [ic, cls] = KIND[e.kind] || KIND.info; return `<div class="notif-wrap" data-id="${e.id}"><div class="notif-bg"></div><div class="notif ${e.read ? '' : 'unread'}">
      <span class="row-ic ${cls}"><i class="${ic}"></i></span>
      <div class="n-body"><div class="n-head"><span class="n-title">${esc(e.title)}</span><span class="n-time num">${esc(e.time)}</span></div>
        <div class="n-text">${e.account ? `<b>${esc(e.account)}</b> · ` : ''}${esc(e.body)}</div>
        ${e.link && LINKS[e.link] ? `<button class="btn btn-soft btn-xs n-link" data-go="${esc(e.link)}">${LINKS[e.link]} <i class="fa-solid fa-arrow-left"></i></button>` : ''}</div></div></div>`; };
    const unread = list.filter(e => !e.read), read = list.filter(e => e.read);
    box.innerHTML = (unread.length ? `<div class="group-label">جديدة · ${unread.length}</div>` + unread.map(card).join('') : '')
      + (read.length ? `<div class="group-label">سابقة</div>` + read.map(card).join('') : '');
    box.querySelectorAll('.notif-wrap').forEach(bindSwipe);
    box.querySelectorAll('[data-go]').forEach(b => b.onclick = e => { e.stopPropagation(); go(b.dataset.go); });
  }
  function dropNotif(id) {
    list = list.filter(x => x.id != id);
    api('/events/' + id, { method: 'DELETE' });
    setTimeout(render, 260);
  }
  function markRead(id) {
    const e = list.find(x => x.id == id); if (!e || e.read) return;
    e.read = true; api('/events/' + id + '/read', { body: {} }); render();
  }
  // سحب لليمين أو اليسار لحذف الإشعار، وضغطة عادية تعلّمه مقروء
  function bindSwipe(w) {
    const card = w.querySelector('.notif'); let x0 = null, dx = 0, y0 = 0, horiz = null;
    const start = (x, y) => { x0 = x; y0 = y; dx = 0; horiz = null; card.style.transition = 'none'; };
    const move = (x, y, ev) => {
      if (x0 === null) return;
      dx = x - x0; const dy = y - y0;
      if (horiz === null && (Math.abs(dx) > 8 || Math.abs(dy) > 8)) horiz = Math.abs(dx) > Math.abs(dy);
      if (!horiz) return;
      if (ev && ev.cancelable) ev.preventDefault();
      card.style.transform = `translateX(${dx}px)`; card.style.opacity = 1 - Math.min(Math.abs(dx) / 260, .6);
      w.querySelector('.notif-bg').style.opacity = Math.min(Math.abs(dx) / 90, 1);
    };
    const end = () => {
      if (x0 === null) return;
      card.style.transition = 'transform .25s, opacity .25s';
      if (horiz && Math.abs(dx) > 90) {
        card.style.transform = `translateX(${dx > 0 ? 120 : -120}%)`; card.style.opacity = 0;
        w.style.transition = 'max-height .3s .15s, margin .3s .15s'; w.style.maxHeight = '0px'; w.style.marginTop = '0';
        dropNotif(w.dataset.id);
      } else { card.style.transform = ''; card.style.opacity = ''; w.querySelector('.notif-bg').style.opacity = 0; if (!horiz) markRead(w.dataset.id); }
      x0 = null;
    };
    w.addEventListener('touchstart', e => start(e.touches[0].clientX, e.touches[0].clientY), { passive: true });
    w.addEventListener('touchmove', e => move(e.touches[0].clientX, e.touches[0].clientY, e), { passive: false });
    w.addEventListener('touchend', end);
    w.addEventListener('mousedown', e => start(e.clientX, e.clientY));
    w.addEventListener('mousemove', e => move(e.clientX, e.clientY));
    w.addEventListener('mouseup', end);
    w.addEventListener('mouseleave', end);
  }
  bulk.onclick = async () => {
    if (list.some(e => !e.read)) {
      list.forEach(e => e.read = true); render(); document.getElementById('bellDot').classList.add('hidden');
      await api('/events/read_all', { body: {} });
    } else if (await confirmSheet({ title: 'حذف كل الإشعارات', text: `راح ينحذف ${list.length} إشعار نهائياً`, ok: 'حذف الكل', icon: 'fa-solid fa-trash' })) {
      const ids = list.map(e => e.id); list = []; render();
      for (const id of ids) api('/events/' + id, { method: 'DELETE' });
    }
  };
  const ev = await api('/events?since=0');
  list = (Array.isArray(ev) ? ev : []).reverse();
  render();
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
    busy(e.target.closest('button'), true);
    const r = await api('/subscribers', { body: { name: el.querySelector('#sn').value, phone: el.querySelector('#sp').value, subscribed_at: el.querySelector('#sd').value, duration_days: el.querySelector('#sdur').value } });
    busy(e.target.closest('button'), false);
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
    busy(e.target.closest('button'), true);
    const r = await api('/subscription_settings', { body: { default_duration_days: el.querySelector('#c1').value, reminder_days_before: el.querySelector('#c2').value, start_message: el.querySelector('#c3').value, reminder_message: el.querySelector('#c4').value, expired_message: el.querySelector('#c5').value } });
    busy(e.target.closest('button'), false); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err');
  };
  loadList();
};

// ---------------------------------------------------------------- مفاتيح API
R.apikeys = async (el) => {
  el.innerHTML = head('ربط API') + '<div class="card"><div class="skel" style="height:120px"></div></div>';
  const [d, accs] = await Promise.all([api('/api_keys'), loadAccounts()]);
  const origin = location.origin;
  const url = origin + '/api/v1/send';
  const ex = {
    curl: `curl -X POST ${url} \\\n  -H "X-API-Key: wsl_xxxxxxxx" \\\n  -H "Content-Type: application/json" \\\n  -d '{"phone":"9647701234567","code":"482913"}'`,
    php: `<?php\n$ch = curl_init('${url}');\ncurl_setopt_array($ch, [\n  CURLOPT_POST => true,\n  CURLOPT_RETURNTRANSFER => true,\n  CURLOPT_HTTPHEADER => [\n    'X-API-Key: wsl_xxxxxxxx',\n    'Content-Type: application/json',\n  ],\n  CURLOPT_POSTFIELDS => json_encode([\n    'phone' => '9647701234567',\n    'code'  => '482913',\n  ]),\n]);\n$res = json_decode(curl_exec($ch), true);\nif (!empty($res['ok'])) { /* تم الإرسال */ }`,
    js: `// Node.js 18+ (من السيرفر حقك - مو من المتصفح)\nconst res = await fetch('${url}', {\n  method: 'POST',\n  headers: {\n    'X-API-Key': process.env.WASEL_API_KEY,\n    'Content-Type': 'application/json',\n  },\n  body: JSON.stringify({ phone: '9647701234567', code: '482913' }),\n});\nconst data = await res.json();\nif (data.ok) console.log('تم الإرسال');`,
    py: `import os, requests\n\nr = requests.post(\n    '${url}',\n    headers={'X-API-Key': os.environ['WASEL_API_KEY']},\n    json={'phone': '9647701234567', 'message': 'طلبك جاهز للاستلام ✅'},\n    timeout=60,\n)\nprint(r.json())`,
  };
  el.innerHTML = head('ربط API') + `
    <div class="hero shine fade-in"><div style="display:flex;align-items:center;gap:14px">
      <span class="hero-icon"><i class="fa-solid fa-plug"></i></span>
      <div><div style="font-weight:800;font-size:18px">اربط موقعك بواتساب</div><div class="hero-sub">أرسل رموز تحقق وإشعارات طلبات من موقعك أو تطبيقك عبر رقمك المربوط</div></div></div></div>

    <div class="section-head">شلون يشتغل؟</div>
    <div class="card stagger">
      <div class="numbered"><b>1</b><span><b>اربط رقم واتساب</b> من قسم «أرقامي» وتأكد إنه متصل.</span></div>
      <div class="numbered"><b>2</b><span><b>ولّد مفتاح API</b> من الأسفل واختار الرقم اللي تنرسل منه الرسائل. المفتاح يظهر مرة وحدة فقط.</span></div>
      <div class="numbered"><b>3</b><span><b>احفظ المفتاح بسيرفر موقعك</b> (متغير بيئة) - لا تحطه بكود الواجهة أو الجافاسكربت اللي يشتغل بالمتصفح.</span></div>
      <div class="numbered"><b>4</b><span><b>أرسل طلب POST</b> فيه رقم المستلم والرمز أو الرسالة، ويوصل للعميل على واتساب خلال ثواني.</span></div>
    </div>

    <div class="section-head">مفتاح جديد</div>
    <div class="card">
      <div class="field"><label>رقم واتساب اللي ينرسل منه</label><select class="select" id="kacc">${accs.filter(a => a.logged_in).map(a => `<option value="${esc(a.id)}">${esc(a.name)}</option>`).join('') || '<option value="">اربط رقم متصل أولاً</option>'}</select></div>
      <div class="field"><label>اسم المفتاح (مثل: متجري)</label><input class="input" id="klabel" maxlength="60"></div>
      <button class="btn btn-primary btn-block shine" id="kgen"><i class="fa-solid fa-key"></i> توليد مفتاح</button>
      <div id="knew"></div>
    </div>

    <div class="section-head">مفاتيحك</div>
    <div class="list mini-list">${(d.keys || []).length ? d.keys.map(k => `<div class="row"><span class="row-ic ${k.revoked ? 'ic-red' : 'ic-orange'}"><i class="fa-solid fa-key"></i></span><span class="row-body"><span class="row-title">${esc(k.label || 'بدون اسم')} ${k.revoked ? '<span class="pill pill-red">ملغى</span>' : k.account_connected ? '<span class="pill pill-green">يعمل</span>' : '<span class="pill pill-orange">الرقم غير متصل</span>'}</span><span class="row-sub num" style="text-align:right">${esc(k.key_prefix)}… · آخر استخدام ${esc(k.last_used_at || '—')}</span></span>${k.revoked ? '' : `<span class="row-end"><button class="btn btn-red btn-xs" data-rev="${k.id}">إلغاء</button></span>`}</div>`).join('') : empty('fa-solid fa-key', 'ما فيه مفاتيح بعد')}</div>

    <div class="section-head">الطلب</div>
    <div class="card">
      <div class="kv"><span>الرابط</span><b class="num selectable" style="font-size:12px">POST ${esc(url)}</b></div>
      <div class="kv"><span>المصادقة</span><b class="num" style="font-size:12px">X-API-Key: مفتاحك</b></div>
      <div class="kv"><span>أو</span><b class="num" style="font-size:12px">Authorization: Bearer مفتاحك</b></div>
      <div class="kv"><span>نوع البيانات</span><b class="num" style="font-size:12px">application/json</b></div>
      <div class="api-fields">
        <div><code>phone</code><span class="pill pill-red">إلزامي</span><p>رقم المستلم مع رمز الدولة بدون + أو أصفار (مثل 9647701234567)</p></div>
        <div><code>code</code><span class="pill pill-gray">اختياري</span><p>رمز تحقق؛ تنرسل رسالة جاهزة: «رمز التحقق الخاص بك: 482913 - صالح لمدة 10 دقائق»</p></div>
        <div><code>message</code><span class="pill pill-gray">اختياري</span><p>نص رسالة كاملة بدل الرمز (لإشعارات الطلبات وغيرها). لازم ترسل code أو message.</p></div>
      </div>
    </div>

    <div class="section-head">أمثلة جاهزة</div>
    <div class="card">
      <div class="tabs" id="exTabs"><button class="tab on" data-x="curl">cURL</button><button class="tab" data-x="php">PHP</button><button class="tab" data-x="js">Node.js</button><button class="tab" data-x="py">Python</button></div>
      <div class="code-block" id="exCode"></div>
      <button class="btn btn-soft btn-sm btn-block" id="exCopy" style="margin-top:10px"><i class="fa-regular fa-copy"></i> نسخ المثال</button>
    </div>

    <div class="section-head">الردود</div>
    <div class="card api-codes">
      <div><span class="pill pill-green num">200</span><p><code>{"ok":true}</code> انرسلت الرسالة</p></div>
      <div><span class="pill pill-orange num">400</span><p>الرقم ناقص، أو ما أرسلت code/message، أو رقمك غير متصل</p></div>
      <div><span class="pill pill-red num">401</span><p>المفتاح مفقود أو غلط أو ملغى</p></div>
      <div><span class="pill pill-orange num">402</span><p>ما عندك باقة فعّالة ورصيدك ما يكفي</p></div>
      <div><span class="pill pill-red num">429</span><p>تعديت الحد: 20 طلب بالدقيقة لكل مفتاح</p></div>
      <div><span class="pill pill-red num">502</span><p>فشل الإرسال (مثلاً الرقم ما يستخدم واتساب)</p></div>
      <div class="hint" style="margin-top:6px">كل الردود JSON وفيها <code>error</code> بالعربي يشرح السبب.</div>
    </div>

    <div class="section-head">نصائح أمان</div>
    <div class="card stagger">
      <div class="numbered"><b><i class="fa-solid fa-lock"></i></b><span>المفتاح مثل كلمة السر: لا ترسله لأحد ولا ترفعه على GitHub.</span></div>
      <div class="numbered"><b><i class="fa-solid fa-server"></i></b><span>اطلب الـ API من سيرفرك فقط؛ الطلبات من المتصفح تكشف المفتاح.</span></div>
      <div class="numbered"><b><i class="fa-solid fa-rotate"></i></b><span>إذا تسرّب المفتاح ألغه فوراً من «مفاتيحك» وولّد واحد جديد.</span></div>
      <div class="numbered"><b><i class="fa-solid fa-coins"></i></b><span>${SET.message_price > 0 ? `بدون باقة تنخصم ${fmt(SET.message_price)} د.ع لكل رسالة من رصيدك.` : 'الإرسال يحتاج باقة فعّالة.'}</span></div>
    </div>`;
  const code = el.querySelector('#exCode');
  let cur = 'curl';
  const show = x => { cur = x; code.textContent = ex[x]; el.querySelectorAll('#exTabs .tab').forEach(t => t.classList.toggle('on', t.dataset.x === x)); };
  el.querySelectorAll('#exTabs .tab').forEach(t => t.onclick = () => show(t.dataset.x));
  el.querySelector('#exCopy').onclick = () => copy(ex[cur]);
  show('curl');
  el.querySelector('#kgen').onclick = async e => {
    const btn = e.target.closest('button');
    busy(btn, true);
    const r = await api('/api_keys', { body: { account_id: el.querySelector('#kacc').value, label: el.querySelector('#klabel').value } });
    busy(btn, false);
    if (!r.ok) { toast(r.error, 'err'); return; }
    el.querySelector('#knew').innerHTML = `<div class="note ok fade-in" style="margin-top:12px"><b>انسخ المفتاح الآن - ما راح يظهر مرة ثانية:</b><div class="copy-box" style="margin-top:8px"><span class="v" style="font-size:13px">${esc(r.key)}</span><button class="btn btn-soft btn-xs" onclick="copy('${esc(r.key)}')">نسخ</button></div></div>`;
  };
  el.querySelectorAll('[data-rev]').forEach(b => b.onclick = () => confirmTap(b, async () => { await api('/api_keys/' + b.dataset.rev, { method: 'DELETE' }); go('apikeys', {}, false); }, 'المواقع المربوطة بهذا المفتاح راح توقف فوراً', { title: 'إلغاء المفتاح', ok: 'إلغاء المفتاح' }));
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
        busy(e.target.closest('button'), true); const r = await api('/api/admin/payment-methods/' + id, { body }); busy(e.target.closest('button'), false);
        toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); if (r.ok) load();
      };
      c.querySelector('[data-del]').onclick = e => confirmTap(e.target.closest('button'), async () => { await api('/api/admin/payment-methods/' + id, { method: 'DELETE' }); load(); }, 'تأكيد');
    });
    el.querySelector('#addPm').onclick = async () => { const r = await api('/api/admin/payment-methods', { body: { type: el.querySelector('#newType').value, active: false } }); if (r.ok) { toast('تمت الإضافة - اضبطها وفعّلها', 'ok'); load(); } };
  }
  load();
};

R.settings = async (el) => {
  el.innerHTML = head('الإعدادات') + '<div class="card"><div class="skel" style="height:200px"></div></div>';
  const { settings: s } = await api('/api/admin/settings');
  const f = (id, label, val, type = 'text', hint = '') => `<div class="field"><label>${label}</label><input class="input ${type === 'number' ? 'num' : ''}" data-k="${id}" type="${type}" value="${esc(val ?? '')}" ${type !== 'text' || /email|client|token|chat/.test(id) ? 'style="direction:ltr;text-align:left"' : ''}>${hint ? `<div class="hint">${hint}</div>` : ''}</div>`;
  el.innerHTML = head('الإعدادات') + `
    <div class="section-head">الأسعار والفترات</div>
    <div class="card">
      <div class="note" style="margin-bottom:14px">أسعار ومدد الاشتراك تتعدّل من قسم <a onclick="go('plansAdmin')" style="cursor:pointer;font-weight:800">الباقات</a></div>
      <div class="input-row">${f('trial_days', 'الفترة المجانية (يوم)', s.trial_days, 'number', '0 = بدون تجربة')}${f('message_price', 'سعر الرسالة (د.ع)', s.message_price, 'number', '0 = الإرسال للمشتركين فقط')}</div>
      <div class="input-row">${f('trial_max_numbers', 'أرقام واتساب بالفترة المجانية', s.trial_max_numbers, 'number', '0 = بدون حد')}${f('trial_daily_messages', 'رسائل يومياً بالفترة المجانية', s.trial_daily_messages, 'number', '0 = بدون حد')}</div>
      ${f('usd_rate', 'سعر الدولار بالدينار (لـ Binance)', s.usd_rate, 'number', 'مثال: 1500 يعني 1$ = 1500 د.ع')}
    </div>
    <div class="section-head">رابط الموقع برسائل الفترة المجانية</div>
    <div class="card">
      <div class="note" style="margin-bottom:12px">كل رسالة يرسلها مستخدم بالفترة المجانية (حملات، دفعات يومية، API، رسائل المشتركين) ينضاف بآخرها هذا النص <b>إجبارياً</b>. المشتركين بباقة ما ينضافلهم.</div>
      <div class="field"><label>النص ({site} = اسم الموقع، {url} = الرابط)</label><textarea class="textarea" data-k="trial_footer" style="min-height:90px">${esc(s.trial_footer)}</textarea></div>
      ${f('site_url', 'رابط الموقع', s.site_url, 'url', 'فارغ = يستخدم الرابط اللي تفتح منه لوحة الأدمن: ' + esc(s.public_url || 'ما انحفظ بعد'))}
      <div class="hint" style="margin-bottom:6px">شكلها بالرسالة:</div><pre class="tf-prev">${esc(s.trial_footer_preview)}</pre>
    </div>
    <div class="section-head">الهوية</div>
    <div class="card">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px"><span class="row-ic" style="width:64px;height:64px" id="logoPrev">${s.has_logo ? `<img src="/branding/logo?t=${Date.now()}">` : '<i class="fa-solid fa-image"></i>'}</span>
        <label class="btn btn-soft btn-sm"><input type="file" accept="image/*" id="logoIn" hidden><i class="fa-solid fa-upload"></i> رفع شعار</label>${s.has_logo ? '<button class="btn btn-red btn-sm" id="logoRm">حذف</button>' : ''}</div>
      <div class="hint" style="margin-bottom:12px">الشعار يظهر بالموقع ويصير أيقونة التطبيق (PWA)</div>
      ${f('site_name', 'اسم الموقع', s.site_name)}
      <div class="field" style="display:flex;align-items:center;justify-content:space-between"><label style="margin:0">رمز الدولة الافتراضي</label>${ccButton('defCc', s.default_country_code)}</div>
      ${f('whatsapp_support_number', 'رقم واتساب الدعم', s.whatsapp_support_number)}
    </div>
    <div class="section-head">تسجيل الدخول</div>
    <div class="card">
      ${f('google_client_id', 'Google Client ID', s.google_client_id, 'text', 'من Google Cloud Console ← Credentials ← OAuth Client (Web). أضف رابط موقعك في Authorized JavaScript origins')}
      ${f('admin_email', 'بريد الأدمن (حساب كوكل)', s.admin_email, 'email', 'من يدخل بهذا البريد عبر كوكل يصير أدمن تلقائياً')}
      <div style="display:flex;align-items:center;gap:12px"><span class="row-ic ic-wa"><i class="fa-brands fa-whatsapp"></i></span><div style="flex:1"><b>تسجيل الدخول برقم واتساب</b><div class="row-sub">يحتاج رقم مفعّل كمرسل رموز</div></div><label class="switch"><input type="checkbox" data-k="whatsapp_login" ${s.whatsapp_login ? 'checked' : ''}><span></span></label></div>
    </div>
    <div class="section-head">بطاقة المطوّر (تظهر بقسم حسابي)</div>
    <div class="card">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px"><span class="dev-photo glow" id="devPrev" style="width:58px;height:58px">${s.dev_photo ? `<img src="/dev-photo?t=${Date.now()}" style="width:100%;height:100%;border-radius:50%;object-fit:cover">` : '<i class="fa-solid fa-user"></i>'}</span>
        <label class="btn btn-soft btn-sm"><input type="file" accept="image/*" id="devIn" hidden><i class="fa-solid fa-camera"></i> صورة المطور</label></div>
      <div class="input-row">${f('dev_name', 'اسم المطور', s.dev_name)}${f('dev_title', 'الوصف', s.dev_title, 'text', 'مثال: مطوّر المنصة')}</div>
      ${f('dev_phone', 'رقم واتساب المطور', s.dev_phone, 'text', 'اتركه فارغ لإخفاء البطاقة')}
    </div>
    <div class="section-head">المهام المجدولة (Cron)</div>
    <div class="card">
      <div class="kv"><span>آخر تشغيل</span><b class="num">${esc(s.last_cron || 'لم يعمل بعد')}</b></div>
      <div class="hint" style="margin:8px 0 10px">افتح الرابط مرة وحدة بالمتصفح حتى يتنصّب Cron تلقائياً (أو يعطيك البديل لو الاستضافة ما تسمح)</div>
      <div class="copy-box"><span class="v" style="font-size:12px">${esc(s.cron_url)}</span><button class="btn btn-soft btn-xs" onclick="copy('${esc(s.cron_url)}')">نسخ</button></div>
      <a class="btn btn-primary btn-block shine" style="margin-top:10px" href="${esc(s.cron_url)}" target="_blank"><i class="fa-solid fa-clock"></i> تنصيب Cron الآن</a>
    </div>
    <div class="section-head">سحب الأرقام من الخرائط</div>
    <div class="card">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px"><span class="row-ic ic-green"><i class="fa-solid fa-map-location-dot"></i></span><div style="flex:1"><b>تفعيل سحب الأرقام للمستخدمين</b><div class="row-sub">الأدمن يقدر يسحب دائماً بدون حد</div></div><label class="switch"><input type="checkbox" data-k="leads_enabled" ${s.leads_enabled ? 'checked' : ''}><span></span></label></div>
      <div class="input-row">
        <div style="flex:1">${f('leads_daily', 'محاولات البحث لكل مستخدم باليوم', s.leads_daily, 'number')}</div>
        <div style="flex:1">${f('leads_max', 'أقصى عدد نتائج للبحث', s.leads_max, 'number', 'من 10 إلى 500')}</div>
      </div>
    </div>
    <div class="section-head">الذكاء الاصطناعي (DeepSeek)</div>
    <div class="card">
      ${f('ai_api_key', 'مفتاح DeepSeek ' + (s.ai_key_set ? '(محفوظ)' : ''), '', 'password')}

      <div class="field"><label>معلومات المتجر/الخدمة للرد الذكي</label><textarea class="textarea" data-k="ai_knowledge_base">${esc(s.ai_knowledge_base)}</textarea></div>
    </div>
    <button class="btn btn-primary btn-block" id="saveSet" style="margin-top:14px"><i class="fa-solid fa-check"></i> حفظ كل الإعدادات</button>`;
  let logo, devPhoto;
  el.querySelector('#devIn').onchange = async e => { const fl = e.target.files[0]; if (!fl) return; devPhoto = await fileToDataUrl(fl); el.querySelector('#devPrev').innerHTML = `<img src="${devPhoto}" style="width:100%;height:100%;border-radius:50%;object-fit:cover">`; };
  el.querySelector('#logoIn').onchange = async e => { const fl = e.target.files[0]; if (!fl) return; logo = await fileToDataUrl(fl); el.querySelector('#logoPrev').innerHTML = `<img src="${logo}">`; };
  const rm = el.querySelector('#logoRm'); if (rm) rm.onclick = () => { logo = ''; el.querySelector('#logoPrev').innerHTML = '<i class="fa-solid fa-image"></i>'; };
  el.querySelector('#saveSet').onclick = async e => {
    const body = {};
    el.querySelectorAll('[data-k]').forEach(i => body[i.dataset.k] = i.type === 'checkbox' ? i.checked : i.value);
    body.default_country_code = el.querySelector('#defCc').dataset.cc;
    if (logo !== undefined) body.logo = logo;
    if (devPhoto) body.dev_photo = devPhoto;
    busy(e.target.closest('button'), true); const r = await api('/api/admin/settings', { body }); busy(e.target.closest('button'), false);
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
      ${rowItem({ icon: 'fa-solid fa-lock', ic: 'ic-green', title: 'HTTPS إجباري', sub: s.https ? 'يحوّل كل زيارة http إلى https تلقائياً' : 'افتح اللوحة عبر https أولاً لتفعيله', end: `<label class="switch" onclick="event.stopPropagation()"><input type="checkbox" id="fh" ${s.force_https ? 'checked' : ''}><span></span></label>` })}
      ${rowItem({ icon: 'fa-solid fa-right-from-bracket', ic: 'ic-red', title: 'إنهاء جلسات كل المستخدمين', sub: 'الكل يحتاج يسجل دخول من جديد (أنت تبقى)', end: '<button class="btn btn-red btn-xs" id="lo">تنفيذ</button>' })}
      ${rowItem({ icon: 'fa-solid fa-server', ic: 'ic-blue', title: 'فحص السيرفر والمحرك', sub: 'صفحة install.php', onclick: "location.href='/install.php'" })}
      ${rowItem({ icon: 'fa-solid fa-broom', ic: 'ic-orange', title: 'مسح سجل الدخول', end: '<button class="btn btn-ghost btn-xs" id="cl">مسح</button>' })}
    </div>
    <div class="note" style="margin-top:12px"><b>الحماية المفعّلة:</b> ملفات البيانات محجوبة عن المتصفح، رموز التحقق محدودة بـ 5 محاولات، تأخير 45 ثانية بين طلبات الرمز، مفاتيح API مخزنة كبصمة SHA-256، حد 20 طلب/دقيقة لكل مفتاح، والجلسات تنتهي فوراً عند الحظر.</div>
    <div class="section-head">أحداث الأمان وعمليات الأدمن</div>
    <div class="list mini-list">${(s.events || []).length ? s.events.slice(0, 30).map(ev => rowItem({
      icon: ev.action.startsWith('admin:') ? 'fa-solid fa-user-gear' : 'fa-solid fa-shield-virus', ic: ev.action.startsWith('admin:') ? 'ic-indigo' : 'ic-orange',
      title: esc(({ csrf_blocked: 'طلب مزوّر انمنع', rate_limited: 'تجاوز حد الطلبات', suspicious_logins: 'محاولات دخول مشبوهة', install_denied: 'محاولة فتح صفحة التنصيب' })[ev.action] || ev.action.replace('admin:', 'أدمن: ')),
      sub: `<span class="num">${esc(ev.time)} · ${esc(ev.ip)}</span> ${ev.detail ? '· ' + esc(ev.detail) : ''}`, end: '',
    })).join('') : empty('fa-solid fa-shield-halved', 'ما فيه أحداث')}</div>
    <div class="section-head">سجل الدخول</div>
    <div class="list mini-list">${s.log.length ? s.log.map(l => rowItem({
      icon: M[l.method] || 'fa-solid fa-right-to-bracket', ic: l.ok ? 'ic-green' : 'ic-red',
      title: `${esc(l.user || l.note || 'غير معروف')} ${l.ok ? '' : '<span class="pill pill-red">فشل</span>'}`, sub: `<span class="num">${esc(l.time)} · ${esc(l.ip)}</span> · ${esc((l.ua || '').slice(0, 40))}`, end: '',
    })).join('') : empty('fa-solid fa-list', 'السجل فارغ')}</div>`;
  el.querySelector('#fh').onchange = async e => {
    const r = await api('/api/admin/security', { body: { action: 'force_https', on: e.target.checked } });
    if (!r.ok) { e.target.checked = !e.target.checked; toast(r.error, 'err'); } else toast(e.target.checked ? 'تم تفعيل HTTPS الإجباري' : 'تم الإيقاف', 'ok');
  };
  el.querySelector('#lo').onclick = e => { e.stopPropagation(); confirmTap(e.target.closest('button'), async () => { const r = await api('/api/admin/security', { body: { action: 'logout_all' } }); toast(r.msg || r.error, r.ok ? 'ok' : 'err'); }, 'تأكيد'); };
  el.querySelector('#cl').onclick = e => { e.stopPropagation(); confirmTap(e.target.closest('button'), async () => { await api('/api/admin/security', { body: { action: 'clear_log' } }); go('security', {}, false); }, 'تأكيد'); };
};

R.backup = async (el) => {
  const { settings: s } = await api('/api/admin/settings');
  el.innerHTML = head('النسخ الاحتياطي') + `
    <div class="list">
      ${rowItem({ icon: 'fa-solid fa-download', ic: 'ic-blue', title: 'تنزيل نسخة JSON', sub: 'كل المستخدمين والأرصدة والإعدادات', onclick: "location.href='/api/admin/backup/download'" })}
      <label class="row"><input type="file" accept=".json,application/json" id="imp" hidden><span class="row-ic ic-orange"><i class="fa-solid fa-upload"></i></span><span class="row-body"><span class="row-title">استيراد نسخة JSON</span><span class="row-sub">يستبدل البيانات الحالية (تنحفظ نسخة قبلها)</span></span><span class="row-end"><span class="chev"><i class="fa-solid fa-chevron-left"></i></span></span></label>
    </div>
    <div class="section-head">الخدمات (كل خدمة بملف منفصل)</div>
    <div class="card" id="svcBox"><div class="skel" style="height:120px"></div></div>
    <div class="section-head">بوت تيليجرام</div>
    <div class="card">
      <div class="numbered"><b>1</b><span>أنشئ بوت من <b>@BotFather</b> وانسخ التوكن</span></div>
      <div class="numbered"><b>2</b><span>أرسل أي رسالة للبوت، وخذ رقم محادثتك من <b>@userinfobot</b></span></div>
      <div class="field"><label>توكن البوت ${s.tg_token_set ? '<span class="pill pill-green">محفوظ</span>' : ''}</label><input class="input" id="tgt" placeholder="123456:ABC..." style="direction:ltr"></div>
      <div class="field"><label>رقم المحادثة (Chat ID)</label><input class="input num" id="tgc" value="${esc(s.tg_chat_id)}" style="direction:ltr;text-align:left"></div>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px"><span class="row-ic ic-cyan"><i class="fa-solid fa-clock"></i></span><div style="flex:1"><b>نسخة تلقائية يومية</b><div class="row-sub">آخر نسخة: ${esc(s.tg_last_backup || '—')}</div></div><label class="switch"><input type="checkbox" id="tga" ${s.tg_auto_backup ? 'checked' : ''}><span></span></label></div>
      <button class="btn btn-primary btn-block" id="tgs">حفظ</button>
      <div class="input-row" style="margin-top:8px"><button class="btn btn-soft btn-sm" style="flex:1" id="tgn"><i class="fa-brands fa-telegram"></i> إرسال نسخة الآن</button><button class="btn btn-ghost btn-sm" style="flex:1" id="tgw"><i class="fa-solid fa-link"></i> تفعيل الاستيراد</button></div>
      <div class="note" style="margin-top:12px">بعد «تفعيل الاستيراد»: أرسل <b>/services</b> للبوت تطلعلك أزرار الخدمات (تنزّل كل خدمة بملف)، أو <b>/backup</b> لنسخة كاملة. وأي ملف JSON ترسله للبوت يستورده تلقائياً - خدمة كانت أو نسخة كاملة (من محادثتك فقط).</div>
    </div>`;
  el.querySelector('#imp').onchange = async e => {
    const fl = e.target.files[0]; if (!fl) return;
    const fd = new FormData(); fd.append('file', fl);
    const r = await api('/api/admin/backup/import', { body: fd });
    toast(r.ok ? r.msg : r.error, r.ok ? 'ok' : 'err');
  };
  // ---- الخدمات: تصدير/استيراد كل خدمة بملف (تنزيل، استيراد، إرسال للبوت)
  const SVC_IC = { plans: 'fa-solid fa-gem', payments: 'fa-solid fa-credit-card', coupons: 'fa-solid fa-ticket', polls: 'fa-solid fa-hand-pointer' };
  async function loadSvc() {
    const r = await api('/api/admin/services'); const box = el.querySelector('#svcBox'); if (!box || !r.ok) return;
    box.innerHTML = `<div class="note" style="margin-bottom:10px"><i class="fa-solid fa-circle-info"></i> كل خدمة تنزّل كملف JSON لحالها. الاستيراد يدمج بالمعرف (يحدّث الموجود ويضيف الجديد) بدون ما يمسح الباقي.</div>
      ${r.services.map(sv => `<div class="svc-row"><span class="row-ic ic-teal"><i class="${SVC_IC[sv.key] || 'fa-solid fa-cube'}"></i></span>
        <div style="flex:1;min-width:0"><b>${esc(sv.label)}</b><div class="row-sub num">${fmt(sv.count)} عنصر</div></div>
        <div class="svc-acts"><a class="icon-btn" href="/api/admin/service/${sv.key}/download" title="تنزيل"><i class="fa-solid fa-download"></i></a>
          <label class="icon-btn" title="استيراد"><input type="file" accept=".json,application/json" data-impsvc hidden><i class="fa-solid fa-upload"></i></label>
          <button class="icon-btn" data-tgsvc="${sv.key}" title="إرسال للبوت"><i class="fa-brands fa-telegram"></i></button></div></div>`).join('')}
      <div class="input-row" style="margin-top:12px;gap:8px"><a class="btn btn-soft btn-sm" style="flex:1" href="/api/admin/service/plans/download" onclick="['payments','coupons','polls'].forEach((k,i)=>setTimeout(()=>{const a=document.createElement('a');a.href='/api/admin/service/'+k+'/download';a.click();},400*(i+1)))"><i class="fa-solid fa-download"></i> تنزيل الكل</a>
        <button class="btn btn-soft btn-sm" style="flex:1" id="svcTgAll"><i class="fa-brands fa-telegram"></i> إرسال الكل للبوت</button></div>`;
    box.querySelectorAll('[data-impsvc]').forEach(inp => inp.onchange = async e => {
      const fl = e.target.files[0]; if (!fl) return;
      const fd = new FormData(); fd.append('file', fl);
      const rr = await api('/api/admin/service/import', { body: fd });
      toast(rr.ok ? rr.msg : rr.error, rr.ok ? 'ok' : 'err'); e.target.value = ''; if (rr.ok) loadSvc();
    });
    box.querySelectorAll('[data-tgsvc]').forEach(b => b.onclick = async () => { busy(b, true); const rr = await api(`/api/admin/service/${b.dataset.tgsvc}/telegram`, { body: {} }); busy(b, false); toast(rr.ok ? 'انرسلت للبوت ✓' : rr.error, rr.ok ? 'ok' : 'err'); });
    const all = el.querySelector('#svcTgAll'); if (all) all.onclick = async () => { busy(all, true); const rr = await api('/api/admin/service/all/telegram', { body: {} }); busy(all, false); toast(rr.ok ? rr.msg : rr.error, rr.ok ? 'ok' : 'err'); };
  }
  loadSvc();
  el.querySelector('#tgs').onclick = async e => { busy(e.target.closest('button'), true); const r = await api('/api/admin/settings', { body: { tg_token: el.querySelector('#tgt').value, tg_chat_id: el.querySelector('#tgc').value, tg_auto_backup: el.querySelector('#tga').checked } }); busy(e.target.closest('button'), false); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); };
  el.querySelector('#tgn').onclick = async e => { busy(e.target.closest('button'), true); const r = await api('/api/admin/backup/telegram', { body: {} }); busy(e.target.closest('button'), false); toast(r.ok ? r.msg : r.error, r.ok ? 'ok' : 'err'); };
  el.querySelector('#tgw').onclick = async e => { busy(e.target.closest('button'), true); const r = await api('/api/admin/telegram/webhook', { body: {} }); busy(e.target.closest('button'), false); toast(r.ok ? r.msg : r.error, r.ok ? 'ok' : 'err'); };
};

R.plansAdmin = async (el) => {
  el.innerHTML = head('إدارة الباقات') + '<div id="pa"></div>';
  async function load() {
    const r = await api('/api/admin/plans');
    const colors = r.colors || [];
    const CN = { teal: 'فيروزي', amber: 'ذهبي', violet: 'بنفسجي', coral: 'مرجاني', emerald: 'أخضر', sky: 'سماوي' };
    el.querySelector('#pa').innerHTML = (r.plans || []).map(pl => `<div class="card" data-pl="${pl.id}">
      <div style="display:flex;align-items:center;gap:12px;cursor:pointer" data-open>
        <span class="row-ic"><i class="fa-solid fa-gem"></i></span>
        <div style="flex:1"><b>${esc(pl.name)}</b> ${pl.popular ? '<span class="pill pill-orange">الأكثر طلباً</span>' : ''}<div class="row-sub num" style="text-align:right">${fmt(pl.price)} د.ع · ${fmt(pl.days)} يوم · ${esc((pl.limit_lines || [])[0] || '')}</div></div>
        <label class="switch" onclick="event.stopPropagation()"><input type="checkbox" data-active ${pl.active ? 'checked' : ''}><span></span></label></div>
      <div class="hidden" data-panel style="margin-top:14px">
        <div class="field"><label>اسم الباقة</label><input class="input" data-f="name" value="${esc(pl.name)}"></div>
        <div class="input-row"><div class="field" style="flex:1"><label>السعر (د.ع)</label><input class="input num" type="number" data-f="price" value="${pl.price}"></div><div class="field" style="flex:1"><label>المدة (يوم)</label><input class="input num" type="number" data-f="days" value="${pl.days}"></div></div>
        <div class="input-row"><div class="field" style="flex:1"><label>عدد أرقام واتساب</label><input class="input num" type="number" min="0" data-f="max_numbers" value="${pl.max_numbers || 0}"><div class="hint">0 = بدون حد</div></div><div class="field" style="flex:1"><label>رسائل باليوم</label><input class="input num" type="number" min="0" data-f="daily_messages" value="${pl.daily_messages || 0}"><div class="hint">0 = بدون حد</div></div></div>
        <div class="field"><label>المزايا (كل ميزة بسطر)</label><textarea class="textarea" data-f="features" style="min-height:90px">${esc(pl.features || '')}</textarea></div>
        <div class="input-row" style="align-items:center"><div class="field" style="flex:1"><label>الترتيب</label><input class="input num" type="number" data-f="sort" value="${pl.sort}"></div>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700"><label class="switch"><input type="checkbox" data-popular ${pl.popular ? 'checked' : ''}><span></span></label> مميّزة (الأكثر طلباً)</label></div>
        <div class="input-row"><button class="btn btn-primary btn-sm" style="flex:2" data-save>حفظ</button><button class="btn btn-red btn-sm" style="flex:1" data-del><i class="fa-solid fa-trash"></i></button></div>
      </div></div>`).join('') + `<button class="btn btn-primary btn-block" id="addPl" style="margin-top:14px"><i class="fa-solid fa-plus"></i> إضافة باقة</button>`;
    el.querySelectorAll('[data-pl]').forEach(c => {
      const id = c.dataset.pl; let color = null;
      c.querySelector('[data-open]').onclick = () => c.querySelector('[data-panel]').classList.toggle('hidden');
      c.querySelector('[data-active]').onchange = async e => { await api('/api/admin/plans/' + id, { body: { active: e.target.checked } }); toast(e.target.checked ? 'الباقة ظاهرة للمستخدمين' : 'تم إخفاء الباقة', 'ok'); };
      c.querySelectorAll('[data-color]').forEach(b => b.onclick = () => { color = b.dataset.color; c.querySelectorAll('[data-color]').forEach(x => x.classList.toggle('on', x === b)); });
      c.querySelector('[data-save]').onclick = async e => {
        const body = { popular: c.querySelector('[data-popular]').checked }; c.querySelectorAll('[data-f]').forEach(i => body[i.dataset.f] = i.value); if (color) body.color = color;
        busy(e.target.closest('button'), true); const r = await api('/api/admin/plans/' + id, { body }); busy(e.target.closest('button'), false); toast(r.ok ? 'تم الحفظ' : r.error, r.ok ? 'ok' : 'err'); if (r.ok) load();
      };
      c.querySelector('[data-del]').onclick = e => confirmTap(e.target.closest('button'), async () => { await api('/api/admin/plans/' + id, { method: 'DELETE' }); load(); }, 'تأكيد');
    });
    el.querySelector('#addPl').onclick = async () => { await api('/api/admin/plans', { body: { name: 'باقة جديدة', price: 10000, days: 30, color: 'sky', active: false } }); toast('تمت الإضافة - عدّلها وفعّلها', 'ok'); load(); };
  }
  load();
};

R.announce = async (el) => {
  const accs = (await loadAccounts()).filter(a => a.logged_in);
  let tab = 'app';
  el.innerHTML = head('إعلان جماعي') + `<div class="tabs"><button class="tab on" data-t="app"><i class="fa-solid fa-bell"></i> إشعار بالتطبيق</button><button class="tab" data-t="wa"><i class="fa-brands fa-whatsapp"></i> رسالة واتساب</button></div><div id="anBox"></div>`;
  const draw = () => {
    el.querySelectorAll('.tab').forEach(t => t.classList.toggle('on', t.dataset.t === tab));
    const box = el.querySelector('#anBox');
    if (tab === 'app') {
      box.innerHTML = `<div class="card">
        <div class="note" style="margin-bottom:12px"><i class="fa-solid fa-bell"></i> يوصل كإشعار داخل التطبيق لكل المستخدمين اللي تختارهم (ويطلع إشعار على جوالهم إذا سامحين بالإشعارات).</div>
        <div class="field"><label>العنوان</label><input class="input" id="nt" maxlength="80" placeholder="مثال: عرض خاص 🎉"></div>
        <div class="field"><label>نص الإعلان</label><textarea class="textarea" id="nb" maxlength="500" placeholder="مثال: خصم 25% على الباقة الشهرية بكود RAMADAN25"></textarea></div>
        <div class="field"><label>يوصل لـ</label><div class="chips" id="nAud">${[['all', 'الكل'], ['plan', 'المشتركين'], ['trial', 'الفترة المجانية'], ['free', 'بدون باقة']].map(([v, t], i) => `<button class="chip ${i ? '' : 'on'}" data-v="${v}">${t}</button>`).join('')}</div></div>
        <div class="field"><label>زر داخل الإشعار (اختياري)</label><select class="input" id="nl"><option value="">بدون زر</option><option value="wallet">الباقات</option><option value="campaign">حملة جديدة</option><option value="leads">سحب الأرقام</option><option value="accounts">أرقامي</option><option value="reports">التقارير</option></select></div>
        <div class="n-preview" id="nPrev"></div>
        <button class="btn btn-primary btn-block" id="ns" style="margin-top:12px"><i class="fa-solid fa-paper-plane"></i> نشر الإعلان</button></div>`;
      let aud = 'all';
      const prev = () => { box.querySelector('#nPrev').innerHTML = `<div class="notif unread"><span class="row-ic ic-pink"><i class="fa-solid fa-bullhorn"></i></span><div class="n-body"><div class="n-head"><span class="n-title">${esc(box.querySelector('#nt').value || 'العنوان')}</span><span class="n-time">الآن</span></div><div class="n-text">${esc(box.querySelector('#nb').value || 'نص الإعلان يظهر هنا')}</div></div></div>`; };
      box.querySelectorAll('#nAud [data-v]').forEach(b => b.onclick = () => { aud = b.dataset.v; box.querySelectorAll('#nAud [data-v]').forEach(x => x.classList.toggle('on', x === b)); });
      box.querySelector('#nt').oninput = prev; box.querySelector('#nb').oninput = prev; prev();
      box.querySelector('#ns').onclick = e => confirmTap(e.target.closest('button'), async () => {
        const btn = box.querySelector('#ns'); busy(btn, true);
        const r = await api('/api/admin/notify', { body: { title: box.querySelector('#nt').value, body: box.querySelector('#nb').value, audience: aud, link: box.querySelector('#nl').value } });
        busy(btn, false);
        if (!r.ok) { toast(r.error, 'err'); return; }
        toast(`انرسل الإعلان لـ ${fmt(r.total)} مستخدم`, 'ok'); box.querySelector('#nt').value = ''; box.querySelector('#nb').value = ''; prev();
      }, 'الإعلان راح يوصل لكل المستخدمين المحددين', { title: 'نشر الإعلان', ok: 'نشر' });
    } else {
      box.innerHTML = `<div class="card">
        <div class="note" style="margin-bottom:12px">يرسل رسالة واتساب لكل مستخدمي المنصة اللي مسجلين برقم واتساب.</div>
        <div class="field"><label>الإرسال من</label><select class="select" id="aa">${accs.map(a => `<option value="${esc(a.id)}">${esc(a.name)}</option>`).join('') || '<option value="">ما عندك رقم متصل</option>'}</select></div>
        <div class="field"><label>نص الإعلان</label><textarea class="textarea" id="at"></textarea></div>
        <div class="field"><label>الفاصل بين الرسائل (ثانية)</label><input class="input num" id="ad" type="number" value="15"></div>
        <button class="btn btn-primary btn-block" id="as"><i class="fa-solid fa-bullhorn"></i> إرسال الإعلان</button></div>`;
      box.querySelector('#as').onclick = async e => {
        const fd = new FormData(); fd.append('text', box.querySelector('#at').value); fd.append('delay', box.querySelector('#ad').value);
        busy(e.target.closest('button'), true); const r = await api('/admin/announcement/' + box.querySelector('#aa').value, { body: fd }); busy(e.target.closest('button'), false);
        toast(r.ok ? `بدأ الإرسال لـ ${r.total} مستخدم` : r.error, r.ok ? 'ok' : 'err');
      };
    }
  };
  el.querySelectorAll('.tab').forEach(t => t.onclick = () => { tab = t.dataset.t; draw(); });
  draw();
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


// منع التكبير بالأصابع ومنع نسخ النصوص (عدا حقول الكتابة)
['gesturestart', 'gesturechange'].forEach(t => document.addEventListener(t, e => e.preventDefault()));
let lastTouch = 0;
document.addEventListener('touchend', e => { const n = Date.now(); if (n - lastTouch < 300 && !e.target.closest('input,textarea')) e.preventDefault(); lastTouch = n; }, { passive: false });
const editable = t => t && t.closest && t.closest('input,textarea,[contenteditable],.selectable,.code-block,.copy-box');
['copy', 'cut'].forEach(t => document.addEventListener(t, e => { if (!editable(e.target) && !editable(document.getSelection()?.anchorNode?.parentElement)) e.preventDefault(); }));
document.addEventListener('contextmenu', e => { if (!editable(e.target)) e.preventDefault(); });
document.addEventListener('wheel', e => { if (e.ctrlKey) e.preventDefault(); }, { passive: false });

// ================================================================ الإقلاع
try { localStorage.removeItem('theme'); } catch (e) {}
document.getElementById('balChip').textContent = fmt(ME.balance);
if ('serviceWorker' in navigator) navigator.serviceWorker.register('/sw.js').catch(() => {});
setTimeout(() => { if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission().catch(() => {}); }, 4000);
pollEvents(); setInterval(pollEvents, 8000); setInterval(refreshMe, 30000);
const startPage = ADMIN && B.section ? (SLUG2PAGE[B.section] || 'home') : 'home';
const startParams = Object.fromEntries(new URLSearchParams(location.search));
history.replaceState({ page: startPage, params: startParams }, '', location.pathname + location.search);
go(startPage, startParams, false);
