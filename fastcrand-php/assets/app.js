/* FastCrand — منطق الواجهة */
'use strict';
const { data: INITIAL_DATA, endpoint: ENDPOINT, csrf: CSRF_TOKEN } = window.FC;
let DATA = null;

// تهريب النصوص قبل إدخالها في HTML لمنع XSS
function esc(v){
  return String(v === undefined || v === null ? '' : v)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
// وسيط آمن لاستدعاء دالة داخل onclick
function jsArg(v){ return esc(JSON.stringify(String(v === undefined || v === null ? '' : v))); }
// قبول روابط http/https فقط
function safeHref(u){
  u = String(u || '').trim();
  return /^https?:\/\//i.test(u) ? u : '';
}
// مسار ملف مرفوع آمن
function upl(name){
  name = String(name || '');
  return /^[A-Za-z0-9._-]+$/.test(name) ? 'uploads/' + encodeURIComponent(name) : '';
}
function openExternal(u){
  const href = safeHref(u);
  if(!href) return false;
  const w = window.open(href, '_blank', 'noopener,noreferrer');
  if(w) w.opener = null;
  return true;
}
const CL = 'ar';

const COLORS = {
  blue: 'var(--gradient-primary)',
  pink: 'linear-gradient(135deg,#ff6b8a,#e84a70)',
  cyan: 'linear-gradient(135deg,#f26a00,#d05a00)',
  gold: 'linear-gradient(135deg,#ffa64d,#d08030)',
  green: 'linear-gradient(135deg,#22c55e,#16a34a)',
  purple: 'linear-gradient(135deg,#8b5cf6,#7c3aed)'
};

const LANGS = {
  ar: {
    dir: 'rtl', label: 'العربية',
    about: 'من نحن', works: 'تطبيقاتنا وأعمالنا',
    all: 'عرض الكل ←', articles: 'مقالاتنا', skills: 'مهاراتنا', contact: 'تواصل معنا',
    read: 'اقرأ المزيد', min: 'دقائق قراءة', avail: 'نعمل الآن',
    back: 'رجوع', install: 'تثبيت', downloads: 'تنزيل', view_all_btn: 'عرض جميع التطبيقات والمواقع',
    downloads_total: 'إجمالي التحميلات', user_rating: 'تقييم المستخدمين', security_check: 'فحص الحماية',
    safe: 'آمن', direct_install_hint: 'تنزيل ملف APK مباشر وسريع لجميع أجهزة Android',
    app_details: '📱 تفاصيل وتثبيت التطبيق', all_apps_title: '🚀 جميع تطبيقات ومشاريع الشركة',
    art_details: '📄 تفاصيل المقال', about_app: 'عن التطبيق', app_screens: 'واجهات وشاشات التطبيق:',
    app_features: 'المميزات الرئيسية:', intro: '📌 مقدمة', article_content: '📖 محتوى المقال',
    team: 'فريق FastCrand', download_apk_btn: 'تحميل للأندرويد (تنزيل APK)',
    dl_android_btn: 'تحميل للأندرويد (APK)',
    dl_play_store: 'تحميل من سوق بلاي',
    dl_ios_btn: 'تحميل للآيفون (iOS)',
    pwa_title: '🍏 خطوات تثبيت الموقع كتطبيق على الآيفون (iOS)',
    pwa_step1_title: 'الخطوة 1: فتح الرابط في Safari',
    pwa_step1_desc: 'اضغط على زر "متابعة وفتح الموقع في Safari" بالأسفل لفتح الرابط مباشرة.',
    pwa_step2_title: 'الخطوة 2: زر المشاركة في الآيفون',
    pwa_step2_desc: 'اضغط على زر المشاركة (Share Icon ⎋) في أسفل شاشة المتصفح.',
    pwa_step3_title: 'الخطوة 3: إضافة إلى الشاشة الرئيسية',
    pwa_step3_desc: 'قم بالتمرير للأسفل واختر "إضافة إلى الشاشة الرئيسية" (Add to Home Screen ➕) ليتم تثبيته كتطبيق فوري على هاتفك.',
    pwa_continue_btn: 'متابعة وفتح الموقع للآيفون ←',
    reviews_title: '⭐ التقييمات',
    admin_rating_badge: 'تقييم المنصة',
    add_review_title: 'أضف تقييمك ورأيك في التطبيق:',
    rate_after_dl_title: '⭐ شاركنا تقييمك بعد التحميل:',
    your_name: 'اسمك الكريم',
    your_comment: 'اكتب تجربتك أو تعليقك...',
    submit_review: 'إرسال التقييم الآن',
    skip_review: 'إغلاق ومتابعة',
    review_success: 'شكراً لك! تم إرسال تقييمك بنجاح.',
    rate_app: 'تقييم التطبيق',
    submit_rate_btn: 'إرسال التقييم',
    mute_rate_btn: 'منع العرض',
    mute_rate_success: 'تم منع عرض بطاقة التقييم.',
    get_demo: 'ابدأ الآن',
    demo_header: 'ابدأ الآن',
    demo_title: 'ابدأ الآن',
    demo_desc: 'املأ بياناتك وسيقوم الدعم الفني بتجهيز وإرسال نموذج تجريبي وعرض مخصص لمشروعك عبر الواتساب فوراً.',
    demo_success_title: 'تم إرسال طلبك قيد التدقيق',
    demo_success_desc: 'سيتواصل معك فريق الدعم قريباً عبر الواتساب لإعطائك النظام أو النموذج التجريبي لعملك.',
    share_app_btn: 'مشاركة رابط التطبيق',
    share_copied: 'تم نسخ رابط التطبيق بنجاح!',
    your_fullname: 'الاسم الكامل',
    your_whatsapp: 'رقم الواتساب (بدون رمز الدولة)',
    project_idea: 'شلون المشروع اللي بتحب تشتغله؟ (فكرة ومتطلبات مشروعك)',
    send_demo_req: 'إرسال الطلب الآن',
    ig_active: 'الحساب نشط الآن', ig_disabled: 'الحساب معطل حالياً', ig_last_updated: '🕐 آخر تحديث:',
    visitors_label: 'عدد زوار الموقع:',
    wip: 'قيد التطوير', soon: 'قريباً', wip_title: 'هذا المشروع قيد التطوير حالياً',
    wip_desc: 'نعمل عليه الآن وسيتوفر للتحميل قريباً.', progress_lbl: 'نسبة الإنجاز',
    f_all: 'الكل', f_live: 'المنشورة', f_wip: 'قيد التطوير', apps_word: 'تطبيق',
    live_group: 'تطبيقات متاحة', wip_group: 'مشاريع تحت العمل'
  }
};

// إزالة index.php من شريط العنوان تلقائياً
(function cleanIndexPhpFromUrl(){
  if(window.location.pathname.toLowerCase().endsWith('index.php')){
    const cleanPath = window.location.pathname.replace(/\/index\.php$/i, '/') || '/';
    window.history.replaceState(null, '', cleanPath + window.location.search + window.location.hash);
  }
})();

function openAdmin(preferredTab){
  if(preferredTab){
    switchAdminTab(preferredTab);
  } else {
    try {
      const saved = sessionStorage.getItem('fc_admin_active_tab');
      if(saved && document.getElementById('adminTab_' + saved)){
        switchAdminTab(saved);
      }
    } catch(e){}
  }
  openOverlay('adminOverlay');
  if(window.history && window.history.pushState){
    window.history.pushState(null, '', '?admin=panel');
  }
}

function switchAdminTab(tabKey){
  const validTabs = ['apps', 'settings', 'telegram', 'articles'];
  if(!validTabs.includes(tabKey)) tabKey = 'apps';

  document.querySelectorAll('.admin-tab-btn').forEach(btn => btn.classList.remove('active'));
  document.querySelectorAll('.admin-tab-pane').forEach(pane => pane.classList.remove('active'));

  const btn = document.getElementById('tabBtn_' + tabKey);
  const pane = document.getElementById('adminTab_' + tabKey);

  if(btn) btn.classList.add('active');
  if(pane) pane.classList.add('active');

  try {
    sessionStorage.setItem('fc_admin_active_tab', tabKey);
  } catch(e){}
}

function resetAppForm(){
  switchAdminTab('apps');
  document.getElementById('form_app_id').value = '';
  document.getElementById('form_app_title').value = '';
  document.getElementById('form_app_category').value = '';
  document.getElementById('form_app_web_url').value = '';
  document.getElementById('form_app_downloads').value = 1000;
  document.getElementById('form_app_rating').value = 4.9;
  document.getElementById('form_app_version').value = '1.0.0';
  document.getElementById('form_app_color').value = 'blue';
  document.getElementById('form_app_status').value = 'live';
  document.getElementById('form_app_progress').value = 0;
  document.getElementById('form_app_desc').value = '';
  document.getElementById('form_app_features').value = '';
  resetAndroidOptions();
  document.getElementById('appFormHeading').textContent = 'نشر تطبيق APK جديد باللغتين';
  document.getElementById('appSubmitBtnText').textContent = 'حفظ ونشر التطبيق';
  document.getElementById('appFormBox').scrollIntoView({ behavior: 'smooth' });
}

function resetArticleForm(){
  switchAdminTab('articles');
  document.getElementById('form_art_id').value = '';
  document.getElementById('form_art_title').value = '';
  document.getElementById('form_art_badge').value = 'تطوير';
  document.getElementById('form_art_read_time').value = 4;
  document.getElementById('form_art_author').value = 'سارة علي';
  document.getElementById('form_art_date').value = new Date().toISOString().slice(0, 10);
  document.getElementById('form_art_desc').value = '';
  document.getElementById('form_art_full').value = '';
  const previewBox = document.getElementById('author_photo_preview_box');
  if(previewBox) previewBox.style.display = 'none';
  document.getElementById('artFormHeading').textContent = 'نشر مقال جديد باللغتين';
  document.getElementById('artSubmitBtnText').textContent = 'حفظ ونشر المقال';
  document.getElementById('artFormBox').scrollIntoView({ behavior: 'smooth' });
}

function openWhatsAppContact(){
  if(DATA && DATA.whatsapp_target){
    openExternal(DATA.whatsapp_target);
  }
}

async function testTelegramConnection(){
  const token = document.getElementById('admin_tg_token')?.value.trim() || '';
  const chatId = document.getElementById('admin_tg_chat_id')?.value.trim() || '';
  const resultEl = document.getElementById('telegramTestResult');
  if(!token || !chatId){
    alert('يرجى كتابة توكن البوت والآيدي أولاً قبل الاختبار.');
    return;
  }
  if(resultEl){
    resultEl.innerHTML = '<i class="fas fa-spinner fa-spin" style="color:var(--blue)"></i> جارٍ فحص الاتصال بتيليجرام...';
    resultEl.style.color = 'var(--text)';
  }
  const fd = new FormData();
  fd.append('action', 'test_telegram_bot');
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('token', token);
  fd.append('chat_id', chatId);
  try {
    const res = await fetch(ENDPOINT, { method:'POST', body:fd, credentials:'same-origin' });
    const data = await res.json();
    if(resultEl){
      if(data && data.success){
        resultEl.innerHTML = '<i class="fas fa-check-circle" style="color:#22c55e"></i> ' + esc(data.message);
        resultEl.style.color = '#22c55e';
      } else {
        resultEl.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#ef4444"></i> ' + esc((data && data.message) || 'تعذر الاتصال');
        resultEl.style.color = '#ef4444';
      }
    }
  } catch(e){
    if(resultEl){
      resultEl.innerHTML = '<i class="fas fa-exclamation-circle" style="color:#ef4444"></i> حدث خطأ: ' + esc(e.message);
      resultEl.style.color = '#ef4444';
    }
  }
}

function updateFloatingButtonsState(){
  const appOverlayOpen = document.getElementById('appOverlay')?.classList.contains('open');
  const articleOverlayOpen = document.getElementById('articleOverlay')?.classList.contains('open');
  const allAppsOverlayOpen = document.getElementById('allAppsOverlay')?.classList.contains('open');
  const pwaOverlayOpen = document.getElementById('pwaStepsOverlay')?.classList.contains('open');
  const demoModalOpen = document.getElementById('demoPresentationOverlay')?.classList.contains('open');
  const anyOpen = document.querySelectorAll('.page-overlay.open').length > 0 || demoModalOpen;
  document.body.classList.toggle('has-overlay', !!anyOpen);
  const demoBtn = document.getElementById('floatingMainDemoBtn');
  const backBtn = document.getElementById('floatingAppBackBtn');

  if(appOverlayOpen || articleOverlayOpen || allAppsOverlayOpen || demoModalOpen || pwaOverlayOpen){
    if(backBtn) backBtn.style.display = 'inline-flex';
    if(demoBtn) demoBtn.style.display = 'none';
  } else if(!anyOpen){
    if(demoBtn) {
      demoBtn.style.display = 'inline-flex';
      updateDemoButtonScrollPosition();
    }
    if(backBtn) backBtn.style.display = 'none';
  } else {
    if(demoBtn) demoBtn.style.display = 'none';
    if(backBtn) backBtn.style.display = 'none';
  }
}

function openOverlay(id, content){
  const overlay = document.getElementById(id);
  if(!overlay) return;
  if(content){
    const targetEl = document.getElementById(
      id === 'articleOverlay' ? 'articleDetailContent' :
      (id === 'appOverlay' ? 'appDetailContent' :
      (id === 'allAppsOverlay' ? 'allAppsListContent' :
      (id === 'pwaStepsOverlay' ? 'pwaStepsContent' : null)))
    );
    if(targetEl) targetEl.innerHTML = content;
  }
  overlay.classList.add('open');
  overlay.style.display = 'block';
  overlay.scrollTop = 0;
  document.body.style.overflow = 'hidden';

  updateFloatingButtonsState();
}

function closeOverlay(id){
  if(id === 'demoPresentationOverlay'){
    closeDemoPresentationModal();
    return;
  }
  const overlay = document.getElementById(id);
  if(!overlay) return;
  if(id === 'appOverlay'){
    closeBottomRatePopup();
  }
  overlay.classList.remove('open');
  overlay.style.display = 'none';
  
  const anyOpen = document.querySelectorAll('.page-overlay.open').length > 0;
  if(!anyOpen){
    document.body.style.overflow = '';
  }

  if(id === 'adminOverlay' || id === 'appOverlay' || id === 'articleOverlay'){
    if(window.history && window.history.replaceState){
      const cleanPath = window.location.pathname.replace(/\/index\.php$/i, '/') || './';
      window.history.replaceState(null, '', cleanPath);
    }
  }

  updateFloatingButtonsState();
}


function toggleTheme(){
  const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
  const next = isDark ? 'light' : 'dark';
  const root = document.documentElement;
  root.classList.add('theme-switching');
  if(next === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  else document.documentElement.removeAttribute('data-theme');
  try{ localStorage.setItem('theme', next); }catch(e){}
  requestAnimationFrame(() => requestAnimationFrame(() => root.classList.remove('theme-switching')));
}
(function(){
  try{
    if(localStorage.getItem('theme') === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  }catch(e){}
})();

function getAppSeq(appId){
  if(!DATA || !DATA.works) return 1;
  const idx = DATA.works.findIndex(w => w.id === appId);
  return idx >= 0 ? (idx + 1) : 1;
}

function getAppBySeq(seq){
  if(!DATA || !DATA.works || !DATA.works.length) return null;
  const idx = parseInt(seq, 10) - 1;
  return (idx >= 0 && idx < DATA.works.length) ? DATA.works[idx] : null;
}

async function loadData(){
  try{
    DATA = INITIAL_DATA; // البيانات مضمّنة في الصفحة: لا طلب شبكة إضافي
    render();

    // فتح التطبيق فوراً عند الدخول عبر رابط المشاركة التسلسلي (مثال: ?app1 أو ?app2)
    const searchStr = window.location.search || '';
    const hashStr = window.location.hash || '';
    let targetApp = null;

    const m = searchStr.match(/[?&]app(\d+)/i) || hashStr.match(/#app(\d+)/i);
    if(m){
      targetApp = getAppBySeq(m[1]);
    } else {
      const urlParams = new URLSearchParams(searchStr);
      const qVal = urlParams.get('app');
      if(qVal){
        const num = parseInt(qVal.replace(/\D/g, ''), 10);
        if(!isNaN(num) && num > 0){
          targetApp = getAppBySeq(num);
        }
        if(!targetApp){
          targetApp = DATA.works.find(w => w.id === qVal);
        }
      }
    }

    if(targetApp){
      setTimeout(() => {
        showAppDetail(targetApp.id);
      }, 150);
    }
  }catch(e){
    console.error(e);
    const main = document.getElementById('mainApp');
    if(main) main.innerHTML = '<div style="text-align:center;padding:50px;color:var(--muted);font-weight:700;"><i class="fas fa-triangle-exclamation"></i> ' +
      'تعذر تحميل المحتوى، يرجى تحديث الصفحة.' + '</div>';
  }
}

function render(){
  if(!DATA) return;
  const P = DATA.profile, W = DATA.works, A = DATA.articles, S = DATA.skills, SC = DATA.social;
  const L = LANGS[CL];
  const visitorsCount = Number(DATA.total_visitors || 12540).toLocaleString();

  const av = upl(P.photo) ? `<img src="${upl(P.photo)}" alt="${esc(P.name)}">` : `<i class="fas fa-code"></i>`;

  const half = Math.ceil(W.length / 2);
  const row1Apps = W.slice(0, half);
  const row2Apps = W.slice(half);

  const makeCard = (w) => {
    const iconContent = upl(w.icon) ? `<img src="${upl(w.icon)}" alt="" loading="lazy">` : `<i class="${esc(w.icon_class || 'fas fa-mobile-screen')}"></i>`;
    const title = w.title;

    return `
      <div class="app-card-item" onclick="showAppDetail(${jsArg(w.id)})">
        <div class="app-card-icon" style="background:${COLORS[w.color] || COLORS.blue}">${iconContent}</div>
        <div class="app-card-details">
          <h4>${esc(title)}</h4>
          <div class="app-card-meta">
            ${w.status === 'wip'
              ? `<span class="wip-badge">🚧 ${L.wip} ${Number(w.progress) || 0}%</span>`
              : `<span class="badge"><i class="fas fa-download"></i> ${Number(w.downloads || 0).toLocaleString()}</span>`}
          </div>
        </div>
        ${w.status === 'wip'
          ? `<button type="button" class="app-btn-action is-wip"><i class="fas fa-hammer"></i> ${L.soon}</button>`
          : `<button type="button" class="app-btn-action"><i class="fas fa-download"></i> ${L.install}</button>`}
      </div>
    `;
  };

  const roleText = (P.role || '');
  const aboutText = (P.about || '');

  let h = `
    <!-- ==================== القسم الأول: البطاقة التعريفية + التطبيقات ==================== -->
    <div class="site-section is-focused" id="section-1" onclick="focusSection('section-1')">
      <!-- البطاقة التعريفية -->
      <div class="prof">
        <div class="p-row">
          <div class="p-av">${av}</div>
          <div class="p-info">
            <h2>${esc(P.name || 'FastCrand')}</h2>
            <p>${esc(roleText)}</p>
            ${P.available ? `<div class="online"><span class="dot"></span> ${L.avail}</div>` : ''}
          </div>
        </div>
      </div>

      <!-- قسم التطبيقات بحركتين متعاكستين تلقائياً -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-mobile-screen-button" style="color:var(--cyan)"></i> <span>${L.works}</span></h3>
          <span class="al" onclick="showAllAppsModal()">${L.all}</span>
        </div>

        <div class="apps-marquee-container">
          <div class="marquee-track-row">
            <div class="marquee-track-inner animate-forward">
              ${row1Apps.map(w => makeCard(w)).join('')}
            </div>
            <div class="marquee-track-inner animate-forward" aria-hidden="true">
              ${row1Apps.map(w => makeCard(w)).join('')}
            </div>
          </div>

          <div class="marquee-track-row">
            <div class="marquee-track-inner animate-reverse">
              ${row2Apps.map(w => makeCard(w)).join('')}
            </div>
            <div class="marquee-track-inner animate-reverse" aria-hidden="true">
              ${row2Apps.map(w => makeCard(w)).join('')}
            </div>
          </div>
        </div>

        <button type="button" class="btn-view-all-apps" onclick="showAllAppsModal()">
          <i class="fas fa-layer-group"></i> ${L.view_all_btn} (${W.length})
        </button>
      </div>
    </div>

    <!-- ==================== القسم الثاني: من نحن + المقالات ==================== -->
    <div class="site-section is-blurred" id="section-2" onclick="focusSection('section-2')">
      <!-- من نحن -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-user" style="color:var(--blue)"></i> <span>${L.about}</span></h3>
        </div>
        <div class="abt" style="white-space:pre-line;">${esc(aboutText)}</div>
      </div>

      <!-- المقالات -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-newspaper" style="color:var(--gold)"></i> <span>${L.articles}</span></h3>
        </div>
        <div class="art-list">
          ${A.map(a => {
            const aTitle = a.title;
            const aDesc = a.description;
            const aBadge = a.badge;

            return `
              <div class="b-art" onclick="showArticleDetail(${jsArg(a.id)})">
                <div class="b-art-top">
                  <span class="b-art-badge"><i class="fas fa-star"></i> ${esc(aBadge)}</span>
                  <span class="b-art-date"><i class="far fa-calendar"></i> ${esc(a.date)}</span>
                </div>
                <h4>${esc(aTitle)}</h4>
                <p>${esc(aDesc)}</p>
                <div class="b-art-footer">
                  <span class="b-art-read">${L.read} <i class="fas fa-arrow-left"></i></span>
                  <span class="b-art-time"><i class="far fa-clock"></i> ${Number(a.read_time) || 1} ${L.min}</span>
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
    </div>

    <!-- ==================== القسم الثالث: المهارات + التواصل معنا + عداد الزوار ==================== -->
    <div class="site-section is-blurred" id="section-3" onclick="focusSection('section-3')">
      <!-- المهارات -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-bolt" style="color:var(--pink)"></i> <span>${L.skills}</span></h3>
        </div>
        <div class="sk-grid" id="skillsGridBox">
          ${S.map((s, idx) => `
            <div class="sk-card" id="skCard_${idx}" data-target-percent="${s.percentage}">
              <div class="sk-ico" style="background:${s.color}"><i class="${s.icon}"></i></div>
              <div class="sk-info">
                <div class="sk-name-row">
                  <span class="sk-name">${s.name}</span>
                  <span class="sk-percent" id="skNum_${idx}">0%</span>
                </div>
                <div class="sk-bar"><div class="sk-fill" id="skFill_${idx}" style="width:0%"></div></div>
              </div>
            </div>
          `).join('')}
        </div>
      </div>

      <!-- تواصل معنا -->
      <div class="sec">
        <div class="sh">
          <h3><i class="fas fa-paper-plane" style="color:var(--blue)"></i> <span>${L.contact}</span></h3>
        </div>
        <div class="ct-card" id="contactCardBox">
          <div class="ct-collision-wrapper" id="ctCollisionWrap">
            <!-- شظايا وانفجار وموجة صدمة تصادم الأزرار -->
            <div class="ct-explosion-burst">
              <div class="ct-shockwave"></div>
              <div class="ct-flash"></div>
              <div class="ct-sparks">
                <span class="spk spk1"></span>
                <span class="spk spk2"></span>
                <span class="spk spk3"></span>
                <span class="spk spk4"></span>
                <span class="spk spk5"></span>
                <span class="spk spk6"></span>
                <span class="spk spk7"></span>
                <span class="spk spk8"></span>
              </div>
            </div>

            <div class="ct-icons">
              <button type="button" class="ct-btn ct-btn-whatsapp ct-btn-collide-right" onclick="openWhatsAppContact()"><i class="fab fa-whatsapp"></i> <span>${'واتساب'}</span></button>
              <button type="button" class="ct-btn ct-btn-instagram ct-btn-collide-left" onclick="showInstagramStatus()"><i class="fab fa-instagram"></i> <span>${'إنستغرام'}</span></button>
            </div>
          </div>
        </div>
      </div>

      <!-- عداد زوار الموقع الفعلي الذي يبدأ من زر "بدء العد" -->
      <div class="visitors-footer">
        <div class="visitors-pill">
          <i class="fas fa-chart-line"></i>
          <span>${L.visitors_label}</span>
          <span class="num">${visitorsCount}</span>
        </div>
      </div>
    </div>
  `;

  document.getElementById('mainApp').innerHTML = h;

  // تشغيل التتبع التلقائي للتغبيش عند التمرير (Smart Scroll Spy)
  setupScrollBlurObserver();
}

// إدارة تركيز الأقسام وتأثير التغبيش والأنيميشن
let skillsTimeouts = [];
let skillsIntervals = [];
let isSkillsCurrentlyActive = false;
let currentFocusedSectionId = 'section-1';

// حالة تتبع أنيميشن القسم الثالث لضمان عدم حدوث أي حركة عجيبة أو إعادة تشغيل أثناء التصفح
let section3AnimationPlayed = false;
let section3OffscreenReady = true;

function resetSkillsAnimation(){
  skillsTimeouts.forEach(t => clearTimeout(t));
  skillsIntervals.forEach(i => clearInterval(i));
  skillsTimeouts = [];
  skillsIntervals = [];
  isSkillsCurrentlyActive = false;

  const cards = document.querySelectorAll('.sk-card');
  cards.forEach((card, idx) => {
    card.classList.remove('popped');
    const fillEl = document.getElementById(`skFill_${idx}`);
    const numEl = document.getElementById(`skNum_${idx}`);
    if(fillEl){
      fillEl.style.transition = 'none';
      fillEl.style.width = '0%';
      void fillEl.offsetWidth; // Force reflow
      fillEl.style.transition = '';
    }
    if(numEl){
      numEl.textContent = '0%';
    }
  });
}

function triggerSkillsAnimation(force = false){
  const cards = document.querySelectorAll('.sk-card');
  if(!cards.length) return;
  if(isSkillsCurrentlyActive && !force) return;

  resetSkillsAnimation();
  isSkillsCurrentlyActive = true;

  cards.forEach((card, idx) => {
    // انبثاق متتالي وسريع لواحدة تلو الأخرى مع زيادة النسبة من الصفر
    const t = setTimeout(() => {
      card.classList.add('popped');
      const targetPercent = parseInt(card.getAttribute('data-target-percent') || '0', 10);
      const fillEl = document.getElementById(`skFill_${idx}`);
      const numEl = document.getElementById(`skNum_${idx}`);

      if(fillEl){
        fillEl.style.width = targetPercent + '%';
      }

      if(numEl){
        let current = 0;
        const totalDuration = 650;
        const steps = Math.max(targetPercent, 1);
        const intervalTime = Math.max(8, Math.floor(totalDuration / steps));
        const numTimer = setInterval(() => {
          current++;
          if(current >= targetPercent){
            current = targetPercent;
            clearInterval(numTimer);
          }
          numEl.textContent = current + '%';
        }, intervalTime);
        skillsIntervals.push(numTimer);
      }
    }, idx * 75);
    skillsTimeouts.push(t);
  });
}

// تشغيل تصادم وانفجار أزرار التواصل عند النزول إليها
let contactCollisionActive = false;
function triggerContactCollision(force = false){
  const wrap = document.getElementById('ctCollisionWrap');
  const card = document.getElementById('contactCardBox');
  if(!wrap) return;
  if(contactCollisionActive && !force) return;

  contactCollisionActive = true;

  wrap.classList.remove('ct-collision-active');
  if(card) card.classList.remove('impact-shake');

  void wrap.offsetWidth; // Force reflow

  wrap.classList.add('ct-collision-active');
  if(card) card.classList.add('impact-shake');

  setTimeout(() => {
    try {
      if(navigator.vibrate) navigator.vibrate([35, 25, 45]);
    } catch(e){}
  }, 400);
}

function resetContactCollision(){
  contactCollisionActive = false;
  const wrap = document.getElementById('ctCollisionWrap');
  const card = document.getElementById('contactCardBox');
  if(wrap) wrap.classList.remove('ct-collision-active');
  if(card) card.classList.remove('impact-shake');
}

// تشغيل أنيميشن القسم الثالث فقط عند النزول إليه بعد أن كان خارج الشاشة تماماً
function runSection3AnimationsIfNeeded(){
  if(section3OffscreenReady && !section3AnimationPlayed){
    section3AnimationPlayed = true;
    section3OffscreenReady = false;
    triggerSkillsAnimation(true);
    triggerContactCollision(true);
  }
}

// إعادة تهيئة وتصفير أنيميشن القسم الثالث فقط عندما يخرج تماماً عن أسفل الشاشة (في قسم المقالات أو الأول)
function checkSection3OffscreenReset(r3, windowH){
  // إذا أصبح القسم الثالث خارج الشاشة لأسفل تماماً
  if(r3.top >= windowH + 40){
    if(section3AnimationPlayed){
      section3AnimationPlayed = false;
      section3OffscreenReady = true;
      resetSkillsAnimation();
      resetContactCollision();
    }
  }
}

// دالة التركيز على قسم معين وإزالة التغبيش عنه وتغبيش الأقسام الأخرى
function focusSection(focusedId){
  if(focusedId !== currentFocusedSectionId){
    currentFocusedSectionId = focusedId;

    const sections = ['section-1', 'section-2', 'section-3'];
    sections.forEach(id => {
      const el = document.getElementById(id);
      if(el){
        if(id === focusedId){
          el.classList.add('is-focused');
          el.classList.remove('is-blurred');
        } else {
          el.classList.remove('is-focused');
          el.classList.add('is-blurred');
        }
      }
    });

    // تشغيل الأنيميشن للقسم الثالث إذا حان وقته
    if(focusedId === 'section-3'){
      runSection3AnimationsIfNeeded();
    }
  }
}

// حركة زر "ابدأ الآن" ببطء وسلاسة نحو الجانب المعاكس (اليسار) مع التمرير للأسفل
function updateDemoButtonScrollPosition(){
  const demoBtn = document.getElementById('floatingMainDemoBtn');
  if(!demoBtn || demoBtn.style.display === 'none') return;
  if(demoBtn.classList.contains('launching-rocket')) return;
  const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
  if(maxScroll <= 15){
    demoBtn.style.setProperty('--current-x', '0px');
    demoBtn.style.transform = 'translateX(0px)';
    return;
  }
  const scrollY = window.scrollY || window.pageYOffset;
  const progress = Math.min(Math.max(scrollY / maxScroll, 0), 1);
  const btnWidth = demoBtn.offsetWidth || 140;
  const padding = window.innerWidth > 768 ? 30 : 18;
  const travelDistance = Math.max(0, window.innerWidth - btnWidth - (padding * 2));
  const moveX = progress * travelDistance;
  demoBtn.style.setProperty('--current-x', `-${moveX}px`);
  demoBtn.style.transform = `translateX(-${moveX}px)`;
}

// مراقبة التمرير لتحديد القسم النشط وإزالة التغبيش عنه تلقائياً عند الصعود والنزول
let lastScrollYPos = 0;
let scrollBlurHandler = null;
function setupScrollBlurObserver(){
  const sec1 = document.getElementById('section-1');
  const sec2 = document.getElementById('section-2');
  const sec3 = document.getElementById('section-3');
  if(!sec1 || !sec2 || !sec3) return;

  lastScrollYPos = window.scrollY || window.pageYOffset || 0;

  const onScroll = () => {
    updateDemoButtonScrollPosition();

    const scrollY = window.scrollY || window.pageYOffset || 0;
    const windowH = window.innerHeight;
    const docH = document.documentElement.scrollHeight;
    const isScrollingUp = scrollY < lastScrollYPos;
    lastScrollYPos = scrollY;

    const r1 = sec1.getBoundingClientRect();
    const r2 = sec2.getBoundingClientRect();
    const r3 = sec3.getBoundingClientRect();

    // فحص إمكانية إعادة تهيئة أنيميشن القسم الثالث في الخلفية فقط إذا كان خارج الشاشة بالكامل لأسفل
    checkSection3OffscreenReset(r3, windowH);

    // 1. عند التواجد في أعلى الصفحة تماماً: القسم الأول دائماً واضح ونشط
    if(scrollY <= 60){
      focusSection('section-1');
      return;
    }

    // 2. إذا وصل التمرير إلى نهاية الصفحة تماماً: قسم المهارات والتواصل
    if(scrollY + windowH >= docH - 45){
      focusSection('section-3');
      return;
    }

    let targetSectionId = currentFocusedSectionId;

    if(currentFocusedSectionId === 'section-1'){
      // النزول من القسم الأول إلى قسم (من نحن والمقالات)
      if(r2.top < windowH * 0.42){
        targetSectionId = 'section-2';
      }
    } else if(currentFocusedSectionId === 'section-2'){
      // الصعود من قسم المقالات إلى القسم الأول (البطاقة والتطبيقات والأعمال)
      if(r2.top > windowH * 0.48 || scrollY <= 120){
        targetSectionId = 'section-1';
      }
      // النزول من قسم المقالات إلى قسم (المهارات وتواصل معنا)
      else if(r3.top < windowH * 0.45){
        targetSectionId = 'section-3';
      }
    } else if(currentFocusedSectionId === 'section-3'){
      // الصعود من قسم (المهارات وتواصل معنا) إلى قسم (من نحن والمقالات) ليكون واضحاً وغير مغبش
      if(r3.top > windowH * 0.55){
        targetSectionId = 'section-2';
      }
      // في حال الصعود السريع والمباشر نحو القسم الأول
      if(r2.top > windowH * 0.55 || scrollY <= 120){
        targetSectionId = 'section-1';
      }
    }

    if(targetSectionId && targetSectionId !== currentFocusedSectionId){
      focusSection(targetSectionId);
    }
  };

  // إزالة المستمع السابق فعلياً (كان يتراكم مستمع جديد مع كل render / تغيير لغة)
  if(scrollBlurHandler) window.removeEventListener('scroll', scrollBlurHandler);
  let ticking = false;
  scrollBlurHandler = () => {
    if(ticking) return;
    ticking = true;
    requestAnimationFrame(() => { ticking = false; onScroll(); });
  };
  window.addEventListener('scroll', scrollBlurHandler, { passive: true });
  // تشغيل فحص أولي لضمان ظهور القسم الأول بوضوح
  onScroll();
}

// إدارة خيارات تثبيت الأندرويد (حصرية متبادلة بين رابط سوق بلاي وملف الـ APK)
function handleAndroidOptionChange(source){
  const urlInput = document.getElementById('form_app_android_url');
  const fileInput = document.getElementById('form_app_apk_file');
  const existingApkInput = document.getElementById('form_app_existing_apk');
  const existingBadge = document.getElementById('existingApkBadge');
  const hintUrl = document.getElementById('hintUrlStatus');
  const hintApk = document.getElementById('hintApkStatus');

  if(source === 'url'){
    const hasUrl = urlInput && urlInput.value.trim() !== '';
    if(hasUrl){
      if(fileInput){
        fileInput.value = '';
        fileInput.disabled = true;
      }
      if(existingApkInput) existingApkInput.value = '';
      if(existingBadge) existingBadge.style.display = 'none';
      if(hintApk) hintApk.textContent = '❌ معطل: تم إدخال رابط سوق بلاي';
      if(hintUrl) hintUrl.textContent = '✅ نشط: سيتم نقل الزائر إلى سوق بلاي';
    } else {
      if(fileInput) fileInput.disabled = false;
      if(hintApk) hintApk.textContent = '';
      if(hintUrl) hintUrl.textContent = '';
    }
  } else if(source === 'file'){
    const hasFile = fileInput && fileInput.files && fileInput.files.length > 0;
    if(hasFile){
      if(urlInput){
        urlInput.value = '';
        urlInput.disabled = true;
      }
      if(existingApkInput) existingApkInput.value = '';
      if(existingBadge) existingBadge.style.display = 'none';
      if(hintUrl) hintUrl.textContent = '❌ معطل: تم اختيار ملف APK مباشر';
      if(hintApk) hintApk.textContent = '✅ نشط: سيتم تنزيل ملف APK مباشرة';
    } else {
      if(urlInput) urlInput.disabled = false;
      if(hintUrl) hintUrl.textContent = '';
      if(hintApk) hintApk.textContent = '';
    }
  }
}

function removeExistingApk(){
  const existingApkInput = document.getElementById('form_app_existing_apk');
  const existingBadge = document.getElementById('existingApkBadge');
  const urlInput = document.getElementById('form_app_android_url');
  const fileInput = document.getElementById('form_app_apk_file');
  const hintUrl = document.getElementById('hintUrlStatus');
  const hintApk = document.getElementById('hintApkStatus');

  if(existingApkInput) existingApkInput.value = '';
  if(existingBadge) existingBadge.style.display = 'none';
  if(urlInput) urlInput.disabled = false;
  if(fileInput) fileInput.disabled = false;
  if(hintUrl) hintUrl.textContent = '';
  if(hintApk) hintApk.textContent = '';
}

function resetAndroidOptions(){
  const urlInput = document.getElementById('form_app_android_url');
  const fileInput = document.getElementById('form_app_apk_file');
  const existingApkInput = document.getElementById('form_app_existing_apk');
  const existingBadge = document.getElementById('existingApkBadge');
  const hintUrl = document.getElementById('hintUrlStatus');
  const hintApk = document.getElementById('hintApkStatus');

  if(urlInput){
    urlInput.value = '';
    urlInput.disabled = false;
  }
  if(fileInput){
    fileInput.value = '';
    fileInput.disabled = false;
  }
  if(existingApkInput) existingApkInput.value = '';
  if(existingBadge) existingBadge.style.display = 'none';
  if(hintUrl) hintUrl.textContent = '';
  if(hintApk) hintApk.textContent = '';
}

// تعديل تطبيق من لوحة المدير
function editApp(ap){
  switchAdminTab('apps');
  document.getElementById('form_app_id').value = ap.id || '';
  document.getElementById('form_app_title').value = ap.title || '';
  document.getElementById('form_app_category').value = ap.category || '';
  document.getElementById('form_app_web_url').value = ap.web_url || '';
  document.getElementById('form_app_downloads').value = ap.downloads !== undefined ? ap.downloads : 0;
  document.getElementById('form_app_rating').value = ap.rating !== undefined ? ap.rating : 4.9;
  document.getElementById('form_app_version').value = ap.version || '1.0.0';
  document.getElementById('form_app_color').value = ap.color || 'blue';
  document.getElementById('form_app_status').value = ap.status === 'wip' ? 'wip' : 'live';
  document.getElementById('form_app_progress').value = ap.progress !== undefined ? ap.progress : 0;
  document.getElementById('form_app_desc').value = ap.description || '';
  document.getElementById('form_app_features').value = (ap.features || []).join('\n');

  // تعبئة خيارات الأندرويد وإدارة الحصرية
  resetAndroidOptions();
  const urlInput = document.getElementById('form_app_android_url');
  const fileInput = document.getElementById('form_app_apk_file');
  const existingApkInput = document.getElementById('form_app_existing_apk');
  const existingBadge = document.getElementById('existingApkBadge');
  const existingName = document.getElementById('existingApkName');
  const hintUrl = document.getElementById('hintUrlStatus');
  const hintApk = document.getElementById('hintApkStatus');

  if(ap.android_url && ap.android_url.trim() !== ''){
    if(urlInput) urlInput.value = ap.android_url;
    handleAndroidOptionChange('url');
  } else if(ap.apk_file && ap.apk_file.trim() !== ''){
    if(existingApkInput) existingApkInput.value = ap.apk_file;
    if(existingName) existingName.textContent = ap.apk_file;
    if(existingBadge) existingBadge.style.display = 'flex';
    if(urlInput) urlInput.disabled = true;
    if(fileInput) fileInput.disabled = false;
    if(hintUrl) hintUrl.textContent = '❌ معطل: يوجد ملف APK مرفق';
    if(hintApk) hintApk.textContent = '✅ نشط: سيتم تنزيل ملف APK مباشرة';
  }
  
  document.getElementById('appFormHeading').textContent = 'تعديل التطبيق: ' + (ap.title || '');
  document.getElementById('appSubmitBtnText').textContent = 'حفظ التعديلات على التطبيق';
  document.getElementById('appFormBox').scrollIntoView({ behavior: 'smooth' });
}

// تعديل مقال من لوحة المدير
function editArticle(ar){
  switchAdminTab('articles');
  document.getElementById('form_art_id').value = ar.id || '';
  document.getElementById('form_art_title').value = ar.title || '';
  document.getElementById('form_art_badge').value = ar.badge || 'تطوير';
  document.getElementById('form_art_read_time').value = ar.read_time || 4;
  document.getElementById('form_art_author').value = ar.author || 'سارة علي';
  document.getElementById('form_art_date').value = ar.date || new Date().toISOString().slice(0, 10);
  document.getElementById('form_art_desc').value = ar.description || '';
  document.getElementById('form_art_full').value = ar.full || '';
  
  const previewBox = document.getElementById('author_photo_preview_box');
  const previewImg = document.getElementById('author_photo_preview_img');
  if(previewBox && previewImg){
    if(ar.author_photo){
      previewImg.src = 'uploads/' + ar.author_photo;
      previewBox.style.display = 'flex';
    } else {
      previewBox.style.display = 'none';
      previewImg.src = '';
    }
  }

  document.getElementById('artFormHeading').textContent = 'تعديل المقال: ' + (ar.title || '');
  document.getElementById('artSubmitBtnText').textContent = 'حفظ التعديلات على المقال';
  document.getElementById('artFormBox').scrollIntoView({ behavior: 'smooth' });
}

// عرض جميع التطبيقات: ترويسة + فلاتر + مجموعتان (متاحة / قيد التطوير)
let allAppsFilter = 'all';
const GLOW = { blue:'255,122,26', cyan:'242,106,0', gold:'255,166,77', pink:'255,107,138', green:'34,197,94', purple:'139,92,246' };
function allAppsCardHtml(w, i){
  const L = LANGS[CL];
  const wip = w.status === 'wip';
  const iconContent = upl(w.icon) ? `<img src="${upl(w.icon)}" alt="" loading="lazy">` : `<i class="${esc(w.icon_class || 'fas fa-mobile-screen')}"></i>`;
  const title = w.title;
  const cat = (w.category || '');
  const pct = Math.max(0, Math.min(100, Number(w.progress) || 0));
  return `
    <div class="app-square-card${wip ? ' is-wip' : ''}" style="--i:${i};--glow:${GLOW[w.color] || GLOW.blue}" onclick="closeOverlay('allAppsOverlay'); showAppDetail(${jsArg(w.id)})">
      <span class="glass-shine"></span>
      <div class="app-square-card-icon" style="background:${COLORS[w.color] || COLORS.blue}">${iconContent}</div>
      <div class="app-square-card-info">
        <h4>${esc(title)}</h4>
        ${cat ? `<div class="app-square-card-cat">${esc(cat)}</div>` : ''}
        ${wip
          ? `<span class="wip-badge">🚧 ${L.wip}</span><div class="wip-progress"><span style="width:${pct}%"></span></div>`
          : `<div class="app-square-card-meta"><span><i class="fas fa-download"></i> ${Number(w.downloads || 0).toLocaleString()}</span><span><i class="fas fa-star" style="color:var(--gold)"></i> ${Number(w.rating !== undefined ? w.rating : 4.9).toFixed(1)}</span></div>`}
      </div>
      <button type="button" class="app-square-card-btn${wip ? ' is-wip' : ''}">
        <i class="fas ${wip ? 'fa-hammer' : 'fa-download'}"></i>
        <span>${wip ? L.soon : L.install}</span>
      </button>
    </div>`;
}
function renderAllApps(){
  if(!DATA || !DATA.works) return '';
  const L = LANGS[CL];
  const live = DATA.works.filter(w => w.status !== 'wip');
  const wip = DATA.works.filter(w => w.status === 'wip');
  const hasWip = wip.length > 0;
  let html = `<div class="glass-blobs" aria-hidden="true"><i></i><i></i><i></i></div><div class="apps-all-head">
    <h3>${'جميع التطبيقات والمشاريع'} <small>(${DATA.works.length})</small></h3>
    ${hasWip ? `<div class="apps-filter">
      <button type="button" class="${allAppsFilter === 'all' ? 'active' : ''}" onclick="setAllAppsFilter('all')">${L.f_all}</button>
      <button type="button" class="${allAppsFilter === 'live' ? 'active' : ''}" onclick="setAllAppsFilter('live')">${L.f_live} (${live.length})</button>
      <button type="button" class="${allAppsFilter === 'wip' ? 'active' : ''}" onclick="setAllAppsFilter('wip')">🚧 ${L.f_wip} (${wip.length})</button>
    </div>` : ''}
  </div>`;
  let idx = 0;
  const group = (title, icon, list) => list.length ? `
    <div class="apps-group-title"><i class="${icon}" style="color:var(--blue)"></i> ${title} <span class="cnt">${list.length}</span></div>
    <div class="all-apps-square-grid">${list.map(w => allAppsCardHtml(w, idx++)).join('')}</div>` : '';
  if(allAppsFilter !== 'wip') html += group(L.live_group, 'fas fa-mobile-screen-button', live);
  if(allAppsFilter !== 'live') html += group(L.wip_group, 'fas fa-hammer', wip);
  return html;
}
function setAllAppsFilter(f){
  allAppsFilter = f;
  const box = document.getElementById('allAppsListContent');
  if(box) box.innerHTML = renderAllApps();
}
function showAllAppsModal(){
  if(!DATA || !DATA.works) return;
  allAppsFilter = 'all';
  openOverlay('allAppsOverlay', renderAllApps());
}

// بطاقة تقييم صغيرة منبثقة بأسفل الشاشة تظهر عند الدخول على التطبيق
let currentRatingAppId = null;
let bottomSelectedRating = 5;

function openBottomRatePopup(appId){
  currentRatingAppId = appId;
  // إزالة أي قفل عام قديم من التخزين المحلي لضمان ظهور بطاقة التقييم في التطبيقات
  try {
    localStorage.removeItem('mute_all_ratings');
    if(sessionStorage.getItem('mute_rate_' + appId) === 'true'){
      return;
    }
  } catch(e){}

  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app) return;
  const L = LANGS[CL];
  const title = app.title;

  const titleEl = document.getElementById('bottomRateAppTitle');
  if(titleEl){
    titleEl.textContent = 'تقييم ' + title;
  }
  const submitLbl = document.getElementById('lblSubmitRate');
  if(submitLbl) submitLbl.textContent = L.submit_rate_btn || 'إرسال التقييم';
  const muteLbl = document.getElementById('lblMuteRate');
  if(muteLbl) muteLbl.textContent = L.mute_rate_btn || 'منع العرض';

  setBottomReviewStars(5);

  const el = document.getElementById('bottomRatePopup');
  if(el){
    el.style.display = 'block';
    setTimeout(() => {
      el.classList.add('visible');
    }, 60);
  }
}

function closeBottomRatePopup(){
  const el = document.getElementById('bottomRatePopup');
  if(el){
    el.classList.remove('visible');
    setTimeout(() => {
      el.style.display = 'none';
    }, 350);
  }
}

function muteBottomRatePopup(){
  try {
    if(currentRatingAppId){
      sessionStorage.setItem('mute_rate_' + currentRatingAppId, 'true');
    }
  } catch(e){}
  // إغلاق فوري بدون أي رسالة تأكيد للمنع
  closeBottomRatePopup();
}

function setBottomReviewStars(stars){
  bottomSelectedRating = stars;
  const starsContainer = document.getElementById('bottomStarsBox');
  if(!starsContainer) return;
  const starEls = starsContainer.querySelectorAll('i');
  starEls.forEach((el, idx) => {
    if(idx < stars){
      el.classList.add('active');
      el.classList.remove('far');
      el.classList.add('fas');
    } else {
      el.classList.remove('active');
      el.classList.remove('fas');
      el.classList.add('far');
    }
  });
}

async function submitBottomRate(){
  if(!currentRatingAppId) return;
  const fd = new FormData();
  fd.append('action', 'add_user_review');
  fd.append('app_id', currentRatingAppId);
  fd.append('author_name', 'مستخدم');
  fd.append('stars', bottomSelectedRating);
  fd.append('comment', '');

  try {
    const res = await fetch(ENDPOINT, { method: 'POST', body: fd, credentials:'same-origin' });
    const data = await res.json();
    if(data && data.success){
      // إغلاق بطاقة التقييم مباشرة بدون أي رسالة إتمام التقييم حسب طلب المستخدم
      closeBottomRatePopup();
      if(!DATA.reviews) DATA.reviews = {};
      if(!DATA.reviews[currentRatingAppId]) DATA.reviews[currentRatingAppId] = [];
      DATA.reviews[currentRatingAppId].unshift(data.review);
    } else {
      closeBottomRatePopup();
    }
  } catch(e){
    closeBottomRatePopup();
  }
}

let successCountdownTimer = null;
let successCountdownInterval = null;

function startSuccessCountdown(){
  clearTimeout(successCountdownTimer);
  clearInterval(successCountdownInterval);

  let secondsLeft = 3;
  const numEl = document.getElementById('demoCountdownNum');
  const barEl = document.getElementById('demoCountdownBar');
  
  if(numEl) numEl.textContent = '3';
  if(barEl){
    barEl.style.animation = 'none';
    void barEl.offsetWidth; // trigger reflow
    barEl.style.animation = 'countdownFillBar 3s linear forwards';
  }

  // إعادة إطلاق أنيميشن سقوط الصح وحركة النصوص يميناً ويساراً
  const checkEl = document.querySelector('.success-check-drop');
  const titleEl = document.getElementById('lblDemoSuccessTitle');
  const descEl = document.getElementById('lblDemoSuccessDesc');
  const cntWrap = document.querySelector('.success-countdown-wrapper');
  [checkEl, titleEl, descEl, cntWrap].forEach(el => {
    if(el){
      el.style.animation = 'none';
      void el.offsetWidth;
      el.style.animation = '';
    }
  });

  successCountdownInterval = setInterval(() => {
    secondsLeft--;
    if(numEl && secondsLeft >= 0){
      numEl.textContent = secondsLeft;
    }
    if(secondsLeft <= 0){
      clearInterval(successCountdownInterval);
    }
  }, 1000);

  successCountdownTimer = setTimeout(() => {
    closeDemoPresentationModal();
  }, 3000);
}

function openDemoPresentationModal(){
  clearTimeout(successCountdownTimer);
  clearInterval(successCountdownInterval);

  const btn = document.getElementById('floatingMainDemoBtn');
  const modal = document.getElementById('demoPresentationOverlay');
  const card = modal ? modal.querySelector('.modal-blurred-card') : null;
  const formState = document.getElementById('demoFormState');
  const successState = document.getElementById('demoSuccessState');

  if(card){
    card.classList.remove('success-frameless');
  }
  if(formState) formState.style.display = 'block';
  if(successState) successState.style.display = 'none';

  // انطلاق زر ابدأ الآن كصاروخ ناري متصاعد مباشرة من موقعه الحالي أينما كان
  if(btn){
    let currentX = 0;
    try {
      const st = window.getComputedStyle(btn);
      const tf = st.transform;
      if(tf && tf !== 'none'){
        if(window.DOMMatrixReadOnly){
          const m = new DOMMatrixReadOnly(tf);
          currentX = m.m41 || 0;
        } else if(window.WebKitCSSMatrix){
          const m = new WebKitCSSMatrix(tf);
          currentX = m.m41 || 0;
        }
      }
    } catch(e){}
    btn.style.setProperty('--current-x', `${currentX}px`);
    btn.classList.add('launching-rocket');
    try {
      if(navigator.vibrate) navigator.vibrate([40, 40, 70]);
    } catch(e){}
  }

  // بناء وظهور الصفحة الخاصة بتقديم الطلب قالب قالب في 1.5 ثانية
  setTimeout(() => {
    if(modal){
      modal.classList.add('open');
      modal.style.display = 'flex';
      document.body.style.overflow = 'hidden';
      updateFloatingButtonsState();
      if(card){
        card.classList.remove('building-active');
        void card.offsetWidth; // Force reflow
        card.classList.add('building-active');
      }
      try {
        if(navigator.vibrate) navigator.vibrate([15, 260, 15, 260, 15, 260, 25]);
      } catch(e){}
    }
  }, 260);
}

function closeDemoPresentationModal(){
  clearTimeout(successCountdownTimer);
  clearInterval(successCountdownInterval);

  const modal = document.getElementById('demoPresentationOverlay');
  const card = modal ? modal.querySelector('.modal-blurred-card') : null;
  const btn = document.getElementById('floatingMainDemoBtn');

  if(modal){
    modal.classList.remove('open');
    modal.style.display = 'none';
  }
  if(card){
    card.classList.remove('building-active');
    card.classList.remove('success-frameless');
  }

  updateFloatingButtonsState();

  const anyOtherOpen = document.querySelectorAll('.page-overlay.open').length > 0;
  if(!anyOtherOpen){
    document.body.style.overflow = '';
  }

  // إعادة زر الصاروخ إلى مكانه الحالي بسلاسة دون القفز للمكان الأساسي
  if(btn){
    btn.classList.remove('launching-rocket');
    btn.style.opacity = '0';
    const currentX = btn.style.getPropertyValue('--current-x') || '0px';
    btn.style.transform = `translateX(${currentX}) translateY(24px)`;
    setTimeout(() => {
      btn.style.transition = 'transform 0.4s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.3s ease';
      btn.style.opacity = '1';
      btn.style.transform = `translateX(${currentX})`;
      setTimeout(() => {
        btn.style.transition = '';
        updateDemoButtonScrollPosition();
      }, 450);
    }, 60);
  }
}

function handleDemoBackdropClick(e){
  if(e.target.id === 'demoPresentationOverlay'){
    closeDemoPresentationModal();
  }
}

// منع أي سحب للأعلى أو للأسفل في بطاقة تقديم الطلب
(function preventDemoModalDrag(){
  const demoModal = document.getElementById('demoPresentationOverlay');
  if(demoModal){
    demoModal.addEventListener('touchmove', (e) => {
      if(!e.target.closest('textarea')){
        e.preventDefault();
      }
    }, { passive: false });
  }
})();

function showToastNotification(msg, isSuccess = true){
  let toast = document.getElementById('siteGlobalToast');
  if(!toast){
    toast = document.createElement('div');
    toast.id = 'siteGlobalToast';
    toast.className = 'site-global-toast';
    document.body.appendChild(toast);
  }
  toast.innerHTML = `<i class="${isSuccess ? 'fas fa-circle-check' : 'fas fa-info-circle'}"></i> <span>${esc(msg)}</span>`;
  toast.classList.add('visible');
  clearTimeout(toast._timer);
  toast._timer = setTimeout(() => {
    toast.classList.remove('visible');
  }, 3200);
}

async function shareApp(appId){
  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app) return;
  const title = app.title;
  const L = LANGS[CL];
  const seq = getAppSeq(app.id);

  const origin = window.location.origin;
  const path = window.location.pathname.replace(/\/index\.php$/i, '/');
  // رابط التطبيق هو فقط app + رقم تسلسل التطبيق (مثال: ?app1)
  const shareUrl = `${origin}${path}?app${seq}`;

  const shareData = {
    title: `${title} - FastCrand`,
    text: `تحميل تطبيق ${title}:`,
    url: shareUrl
  };

  if(navigator.share && navigator.canShare && navigator.canShare(shareData)){
    try {
      await navigator.share(shareData);
      return;
    } catch(err) {
      if(err.name !== 'AbortError'){
        console.error(err);
      } else {
        return;
      }
    }
  }

  try {
    if(navigator.clipboard && navigator.clipboard.writeText){
      await navigator.clipboard.writeText(shareUrl);
    } else {
      const tempInput = document.createElement('input');
      tempInput.value = shareUrl;
      document.body.appendChild(tempInput);
      tempInput.select();
      document.execCommand('copy');
      document.body.removeChild(tempInput);
    }
    showToastNotification(L.share_copied || 'تم نسخ رابط التطبيق بنجاح! جاهز للمشاركة.');
  } catch(e){
    prompt('انسخ رابط التطبيق:', shareUrl);
  }
}

async function submitDemoPresentation(event){
  if(event) event.preventDefault();
  const nameInput = document.getElementById('demo_name');
  const whatsappInput = document.getElementById('demo_whatsapp');
  const projectInput = document.getElementById('demo_project_details');
  const submitBtn = document.getElementById('btnSubmitDemo');
  const L = LANGS[CL];

  const name = nameInput ? nameInput.value.trim() : '';
  const whatsapp = whatsappInput ? whatsappInput.value.trim() : '';
  const project = projectInput ? projectInput.value.trim() : '';

  if(!name || !whatsapp || !project){
    alert('يرجى ملء جميع الحقول المطلوبة.');
    return;
  }

  if(submitBtn){
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + 'جارٍ الإرسال...';
  }

  let cleanWhatsapp = whatsapp.replace(/^\+/, '').replace(/^00/, '').trim();

  const fd = new FormData();
  fd.append('action', 'request_demo_presentation');
  fd.append('name', name);
  fd.append('whatsapp', cleanWhatsapp);
  fd.append('project_details', project);
  fd.append('website', document.getElementById('demo_website')?.value || '');

  try {
    const res = await fetch(ENDPOINT + '?action=request_demo_presentation', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    });
    const text = await res.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch(parseErr) {
      console.error('Non-JSON response from server:', text);
      throw new Error('Invalid server response');
    }

    if(data && data.success){
      const form = document.getElementById('demoPresentationForm');
      if(form) form.reset();
      
      // إظهار رسالة النجاح بدون بطاقة وبدون زر مع تساقط الصح وحركة النصوص والعداد التنازلي
      const card = document.querySelector('#demoPresentationOverlay .modal-blurred-card');
      const formState = document.getElementById('demoFormState');
      const successState = document.getElementById('demoSuccessState');
      if(card) card.classList.add('success-frameless');
      if(formState) formState.style.display = 'none';
      if(successState){
        successState.style.display = 'block';
        startSuccessCountdown();
      }
    } else {
      alert((data && data.message) ? data.message : 'تعذر إرسال الطلب، يرجى المحاولة لاحقاً.');
    }
  } catch(err){
    console.error(err);
    alert('حدث خطأ في الاتصال، يرجى المحاولة مرة أخرى.');
  } finally {
    if(submitBtn){
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fas fa-paper-plane"></i> <span id="lblSubmitDemoText">' + (L.send_demo_req || 'إرسال الطلب الآن') + '</span>';
    }
  }
}

// تحميل للأندرويد: فتح سوق بلاي أو تنزيل APK مباشر
function triggerAndroidDownload(appId){
  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app || app.status === 'wip') return;

  // تحديث عداد التحميلات محلياً
  app.downloads = (parseInt(app.downloads) || 0) + 1;
  const statBoxNum = document.querySelector('#appDetailContent .app-stat-box .num');
  if(statBoxNum){
    statBoxNum.innerHTML = `<i class="fas fa-download"></i> ` + Number(app.downloads).toLocaleString();
  }

  // إذا كان التطبيق محدد له رابط سوق بلاي / خارجي
  if(safeHref(app.android_url)){
    // إرسال طلب في الخلفية لتحديث العداد على السيرفر
    fetch(ENDPOINT + '?increment_download=' + encodeURIComponent(appId), { credentials:'same-origin' }).catch(()=>{});
    // فتح رابط سوق بلاي في تبويب جديد
    openExternal(app.android_url);
  } else if(!app.has_apk){
    // لا يوجد ملف APK ولا رابط متجر: فتح نسخة الويب إن وجدت بدلاً من تنزيل ملف تالف
    app.downloads = Math.max(0, (parseInt(app.downloads) || 1) - 1);
    if(statBoxNum) statBoxNum.innerHTML = `<i class="fas fa-download"></i> ` + Number(app.downloads).toLocaleString();
    if(!openExternal(app.web_url)){
      showToastNotification('نسخة الأندرويد ستتوفر قريباً.', false);
    }
  } else {
    // بدء التنزيل المباشر لحزمة APK وتحديث العداد بالسيرفر
    const dlLink = document.createElement('a');
    dlLink.href = ENDPOINT + '?download_app=' + encodeURIComponent(appId);
    dlLink.setAttribute('download', '');
    document.body.appendChild(dlLink);
    dlLink.click();
    document.body.removeChild(dlLink);
  }
}

// عرض خطوات تثبيت الموقع كتطبيق على الآيفون (iOS عبر Safari)
function openIosPwaSteps(appId){
  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app) return;
  const L = LANGS[CL];
  const webUrl = safeHref(app.web_url) || '#';
  const title = app.title;

  const html = `
    <!-- ترويسة تعليمات الآيفون المتساقطة -->
    <div class="pwa-step-drop pwa-drop-header" style="text-align:center;margin-bottom:18px;padding:16px 14px;background:linear-gradient(135deg, rgba(255,122,26,0.08), transparent);border-radius:20px;border:1.5px solid var(--border);box-shadow:var(--shadow);">
      <div style="width:52px;height:52px;border-radius:16px;background:var(--gradient-primary);display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:1.6rem;margin-bottom:8px;box-shadow:0 6px 18px rgba(255,122,26,0.35);">
        <i class="fab fa-apple"></i>
      </div>
      <h3 style="font-size:1.15rem;font-weight:900;margin-bottom:4px;color:var(--text);">${L.pwa_title}</h3>
      <p style="font-size:0.8rem;color:var(--muted);font-weight:700;">${esc(title)}</p>
    </div>

    <!-- خطوة 1 تتساقط أولاً -->
    <div class="pwa-step-card pwa-step-drop pwa-drop-1">
      <div class="pwa-step-num">1</div>
      <div class="pwa-step-body">
        <h4>${L.pwa_step1_title}</h4>
        <p>${L.pwa_step1_desc}</p>
      </div>
    </div>

    <!-- خطوة 2 تتساقط ثانياً -->
    <div class="pwa-step-card pwa-step-drop pwa-drop-2">
      <div class="pwa-step-num">2</div>
      <div class="pwa-step-body">
        <h4>${L.pwa_step2_title}</h4>
        <p>${L.pwa_step2_desc}</p>
      </div>
    </div>

    <!-- خطوة 3 تتساقط ثالثاً -->
    <div class="pwa-step-card pwa-step-drop pwa-drop-3">
      <div class="pwa-step-num">3</div>
      <div class="pwa-step-body">
        <h4>${L.pwa_step3_title}</h4>
        <p>${L.pwa_step3_desc}</p>
      </div>
    </div>

    <!-- زر المتابعة يتساقط في النهاية -->
    <div class="pwa-step-drop pwa-drop-4" style="margin-top:20px;display:flex;flex-direction:column;gap:10px;">
      <a href="${webUrl !== '#' ? esc(webUrl) : 'javascript:void(0)'}" ${webUrl !== '#' ? 'target="_blank" rel="noopener noreferrer"' : ''} class="ct-btn" style="width:100%;padding:14px;font-size:0.92rem;border-radius:16px;box-shadow:0 8px 25px rgba(255,122,26,0.35);">
        <i class="fas fa-arrow-up-right-from-square"></i> ${L.pwa_continue_btn}
      </a>
    </div>
  `;

  openOverlay('pwaStepsOverlay', html);
}

// زر رجوع تفاعلي مع تأثير انضغاط واهتزاز سلس
function interactiveGoBack(){
  const btn = document.getElementById('floatingAppBackBtn');
  const doClose = () => {
    if(document.getElementById('demoPresentationOverlay')?.classList.contains('open')){
      closeDemoPresentationModal();
    } else if(document.getElementById('pwaStepsOverlay')?.classList.contains('open')){
      closeOverlay('pwaStepsOverlay');
    } else if(document.getElementById('articleOverlay')?.classList.contains('open')){
      closeOverlay('articleOverlay');
    } else if(document.getElementById('allAppsOverlay')?.classList.contains('open')){
      closeOverlay('allAppsOverlay');
    } else if(document.getElementById('appOverlay')?.classList.contains('open')){
      closeOverlay('appOverlay');
    } else {
      const openOverlays = document.querySelectorAll('.page-overlay.open');
      openOverlays.forEach(o => closeOverlay(o.id));
    }
  };

  if(btn){
    btn.classList.add('is-clicked');
    try {
      if(navigator.vibrate) navigator.vibrate([20, 30]);
    } catch(e){}
    setTimeout(() => {
      btn.classList.remove('is-clicked');
      doClose();
    }, 140);
  } else {
    doClose();
  }
}

// نافذة تفاصيل التطبيق والتثبيت الداخلي (التقييم يظهر فقط في بطاقة الإحصائيات في البداية)
function showAppDetail(appId){
  const app = (DATA && DATA.works) ? DATA.works.find(w => w.id === appId) : null;
  if(!app) return;
  const L = LANGS[CL];
  const iconHtml = upl(app.icon) ? `<img src="${upl(app.icon)}" alt="">` : `<i class="${esc(app.icon_class || 'fas fa-mobile-screen')}"></i>`;
  const title = app.title;
  const desc = app.description;
  const cat = (app.category || 'App');
  const isWip = app.status === 'wip';
  const wipPct = Math.max(0, Math.min(100, Number(app.progress) || 0));
  const ratingValue = (app.rating !== undefined) ? Number(app.rating).toFixed(1) : '4.9';

  let screenshotsHtml = '';
  if(app.screenshots && app.screenshots.length > 0){
    screenshotsHtml = `<div style="margin:16px 0;"><h4 style="font-size:0.85rem;margin-bottom:8px;font-weight:800;"><i class="fas fa-images" style="color:var(--blue)"></i> ${L.app_screens}</h4><div class="app-screenshots-scroll">` +
    app.screenshots.filter(s => upl(s)).map(s => `<img src="${upl(s)}" class="app-screenshot-thumb" alt="screenshot" loading="lazy">`).join('') +
    `</div></div>`;
  }

  const rawFeatures = app.features || [];
  let featsHtml = '';
  if(rawFeatures && rawFeatures.length > 0){
    featsHtml = `<div style="margin:16px 0;"><h4 style="font-size:0.85rem;margin-bottom:8px;font-weight:800;"><i class="fas fa-check-double" style="color:var(--blue)"></i> ${L.app_features}</h4>` +
    rawFeatures.map(f => `<div class="app-feat-item"><i class="fas fa-circle-check" style="color:var(--blue)"></i> <span>${esc(f)}</span></div>`).join('') +
    `</div>`;
  }

  const content = `
    <div class="app-detail-head">
      <div class="app-detail-icon" style="background:${COLORS[app.color] || COLORS.blue}">${iconHtml}</div>
      <div class="app-detail-meta">
        <h2>${esc(title)}</h2>
        <div style="font-size:0.75rem;color:var(--muted);font-weight:700;">
          <span style="color:var(--blue);">${esc(cat)}</span> · v${esc(app.version || '1.0.0')}
        </div>
      </div>
    </div>

    <!-- بطاقة التقييمات والإحصائيات فقط هنا في البداية بعد الاسم واللوجو -->
    <div class="app-stat-bar">
      <div class="app-stat-box">
        <div class="num"><i class="fas fa-download"></i> ${Number(app.downloads || 0).toLocaleString()}</div>
        <div class="lbl">${L.downloads_total}</div>
      </div>
      <div class="app-stat-box">
        <div class="num"><i class="fas fa-star" style="color:var(--gold)"></i> ${ratingValue}</div>
        <div class="lbl">${L.user_rating}</div>
      </div>
      <div class="app-stat-box">
        <div class="num"><i class="fas fa-shield-halved" style="color:#22c55e"></i> ${L.safe}</div>
        <div class="lbl">${L.security_check}</div>
      </div>
    </div>

    <!-- زرا التحميل: أندرويد (سوق بلاي أو APK)، وآيفون يعرض خطوات التثبيت عبر Safari -->
    ${isWip ? `
    <div class="wip-box">
      <div class="ico">🚧</div>
      <h4>${L.wip_title}</h4>
      <p>${L.wip_desc}</p>
      <div class="wip-progress"><span style="width:${wipPct}%"></span></div>
      <div class="pct">${L.progress_lbl}: ${wipPct}%</div>
    </div>` : `
    <div class="download-action-grid">
      <button type="button" class="btn-dl-android" onclick="triggerAndroidDownload(${jsArg(app.id)})">
        <i class="${safeHref(app.android_url) ? 'fab fa-google-play' : 'fab fa-android'}" style="font-size:0.95rem;"></i>
        <span>${safeHref(app.android_url) ? L.dl_play_store : L.dl_android_btn}</span>
      </button>

      <button type="button" class="btn-dl-ios" onclick="openIosPwaSteps(${jsArg(app.id)})" style="border:none;cursor:pointer;">
        <i class="fab fa-apple" style="font-size:0.95rem;"></i>
        <span>${L.dl_ios_btn}</span>
      </button>
    </div>`}

    <!-- زر مشاركة رابط التطبيق المباشر -->
    <button type="button" class="btn-share-app" onclick="shareApp(${jsArg(app.id)})">
      <i class="fas fa-share-nodes"></i>
      <span>${L.share_app_btn || 'مشاركة رابط التطبيق'}</span>
    </button>

    <div class="abt" style="margin-bottom:14px;">
      <h4 style="font-size:0.9rem;font-weight:800;margin-bottom:6px;color:var(--text);"><i class="fas fa-circle-info"></i> ${L.about_app}</h4>
      <p style="white-space:pre-line;">${esc(desc)}</p>
    </div>

    ${screenshotsHtml}
    ${featsHtml}
  `;

  openOverlay('appOverlay', content);
  const seq = getAppSeq(app.id);
  if(window.history && window.history.replaceState){
    history.replaceState({ appId: app.id, seq }, '', `?app${seq}`);
  }
  if(!isWip){
    setTimeout(() => {
      openBottomRatePopup(app.id);
    }, 250);
  }
}

// عرض المقال مع اسم المستخدم (سارة علي مثلاً) وصورته والتاريخ الحقيقي
function showArticleDetail(artId){
  const a = (DATA && DATA.articles) ? DATA.articles.find(item => item.id === artId) : null;
  if(!a) return;
  const L = LANGS[CL];
  const aTitle = a.title;
  const aDesc = a.description;
  const aBadge = a.badge;
  const aFull = (a.full || '');
  const aAuthor = (a.author || 'سارة علي');
  const aPhoto = upl(a.author_photo) ? `<img src="${upl(a.author_photo)}" alt="${esc(aAuthor)}">` : `<i class="fas fa-user"></i>`;
  const aRealDate = a.date || new Date().toISOString().slice(0, 10);

  const content = `
    <h2 style="font-size:1.3rem;font-weight:900;margin-bottom:8px;">${esc(aTitle)}</h2>
    <div style="font-size:0.72rem;color:var(--muted);margin-bottom:16px;display:flex;gap:12px;font-weight:700;">
      <span><i class="far fa-calendar"></i> ${esc(aRealDate)}</span>
      <span><i class="far fa-clock"></i> ${Number(a.read_time) || 1} ${L.min}</span>
      <span style="color:var(--blue);">${esc(aBadge)}</span>
    </div>

    <div class="abt" style="margin-bottom:12px;">
      <h4 style="font-size:0.88rem;font-weight:800;margin-bottom:6px;color:var(--text);">${L.intro}</h4>
      <p>${esc(aDesc)}</p>
    </div>

    <div class="abt" style="margin-bottom:12px;">
      <h4 style="font-size:0.88rem;font-weight:800;margin-bottom:6px;color:var(--text);">${L.article_content}</h4>
      <p style="line-height:1.8;white-space:pre-line;">${esc(aFull || aDesc)}</p>
    </div>

    <!-- بطاقة الكاتب/المستخدم مع صورته وتاريخ النشر الحقيقي -->
    <div class="author-box">
      <div class="author-avatar">${aPhoto}</div>
      <div style="font-size:0.82rem;">
        <strong style="color:var(--text);font-size:0.9rem;">${esc(aAuthor)}</strong>
        <div style="color:var(--muted);font-size:0.7rem;margin-top:2px;">
          <span><i class="far fa-calendar-check" style="color:var(--blue);"></i> ${'تاريخ النشر:'} ${esc(aRealDate)}</span>
        </div>
      </div>
    </div>
  `;
  openOverlay('articleOverlay', content);
}

function showInstagramStatus(){
  if(!DATA) return;
  const L = LANGS[CL];
  const meta = DATA.instagram_meta || {};
  const isAct = meta.status === 'active';
  const msg = (meta.message || '');

  const html = `
    <div class="instagram-card">
      <div class="ig-logo"><i class="fab fa-instagram"></i></div>
      <h2 style="font-size:1.4rem;font-weight:900;margin-bottom:8px;">@${esc(meta.user || 'fastcrand_official')}</h2>
      ${isAct ? `
        <div style="display:inline-block;padding:6px 20px;border-radius:100px;background:#22c55e;color:#fff;font-weight:800;margin:12px 0;"><i class="fas fa-circle-check"></i> ${L.ig_active}</div>
        <p style="color:var(--muted);font-size:0.9rem;line-height:1.7;">${'يمكنك متابعة أحدث أخبارنا ومشاريعنا فوراً.'}</p>
      ` : `
        <div class="ig-status"><i class="fas fa-circle-exclamation"></i> ${L.ig_disabled}</div>
        <p style="color:var(--muted);font-size:0.9rem;line-height:1.8;white-space:pre-line;">${esc(msg)}</p>
      `}
      <p style="font-size:0.75rem;color:var(--muted);margin-top:16px;">${L.ig_last_updated} ${new Date().toISOString().slice(0, 10)}</p>
    </div>
  `;

  document.getElementById('instagramModalBody').innerHTML = html;
  openOverlay('instagramOverlay');
}

// منع نسخ وتحديد نصوص الموقع مع استثناء حقول الإدخال لتظل قابلة للكتابة والتعديل
document.addEventListener('copy', e => {
  if (!e.target.closest('input, textarea')) {
    e.preventDefault();
  }
});
document.addEventListener('cut', e => {
  if (!e.target.closest('input, textarea')) {
    e.preventDefault();
  }
});
document.addEventListener('contextmenu', e => {
  if (!e.target.closest('input, textarea')) {
    e.preventDefault();
  }
});
document.addEventListener('selectstart', e => {
  if (!e.target.closest('input, textarea')) {
    e.preventDefault();
  }
});

loadData();
