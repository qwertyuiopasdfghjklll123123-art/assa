// Service Worker لمنصة واصل:
// - يحمّل ملفات التطبيق كلها بصمت أول ما ينفتح الموقع (بدون أي إشعار)، فالفتح الجاي يصير فوري.
// - الصفحات والبيانات تنجلب دائماً من الإنترنت (حتى الرصيد والحملات تكون محدّثة).
// - إذا ما فيه إنترنت تظهر صفحة «ما فيه اتصال» بدل صفحة خطأ المتصفح.
const V = '__V__';
const CACHE = 'wasel-' + V;
const PRECACHE = [
  '/offline',
  '/assets/ui.css?v=' + V, '/assets/app.js?v=' + V, '/assets/logo.webp?v=' + V, '/assets/logo-gold.webp?v=' + V, '/assets/logo-violet.webp?v=' + V, '/assets/logo-rose.webp?v=' + V,
  '/assets/vendor/fonts.css?v=' + V, '/assets/vendor/fa.css?v=' + V,
  '/assets/vendor/fa-solid.woff2?v=' + V, '/assets/vendor/fa-regular.woff2?v=' + V, '/assets/vendor/fa-brands.woff2?v=' + V,
  ...[400, 500, 600, 700].flatMap(w => ['/assets/vendor/plex-arabic-' + w + '.woff2?v=' + V, '/assets/vendor/plex-latin-' + w + '.woff2?v=' + V]),
  '/pwa-192.png',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => Promise.allSettled(PRECACHE.map(u => c.add(new Request(u, { cache: 'reload' }))))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => e.waitUntil(
  caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith('wasel-') && k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())
));

const isStatic = u => u.origin === location.origin && /^\/(assets\/|pwa-|pm-image\/|branding\/logo|dev-photo)/.test(u.pathname);

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const u = new URL(req.url);

  // فتح صفحة: من الإنترنت، وإذا ما فيه اتصال نعرض صفحة «ما فيه إنترنت»
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(async () => (await caches.match('/offline')) || new Response('<h1 dir="rtl">ما فيه اتصال بالإنترنت</h1>', { headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
    return;
  }
  if (!isStatic(u)) return; // البيانات (API) دائماً من الإنترنت

  // الملفات الثابتة: من الذاكرة فوراً، وتتحدث بالخلفية
  e.respondWith(caches.open(CACHE).then(async c => {
    const hit = await c.match(req);
    const net = fetch(req).then(r => { if (r.ok) c.put(req, r.clone()); return r; }).catch(() => hit);
    if (hit && u.searchParams.has('v')) return hit; // نسخة ثابتة ما تتغير
    return hit || net;
  }));
});

// إشعارات الجوال: السيرفر يرسل تنبيه فارغ، وإحنا ناخذ النص من صندوق الإشعارات
self.addEventListener('push', e => {
  e.waitUntil((async () => {
    let msgs = [];
    try {
      const sub = await self.registration.pushManager.getSubscription();
      const r = await fetch('/api/push/inbox', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'wasel' }, body: JSON.stringify({ endpoint: sub ? sub.endpoint : '' }) });
      msgs = (await r.json()).messages || [];
    } catch (err) {}
    if (!msgs.length) msgs = [{ title: 'واصل', body: 'عندك تنبيه جديد', tag: 'wasel', url: '/app' }];
    for (const m of msgs) await self.registration.showNotification(m.title, { body: m.body, tag: m.tag + '-' + (m.t || Date.now()), icon: '/pwa-192.png', badge: '/pwa-192.png', vibrate: [200, 100, 200], dir: 'rtl', lang: 'ar', data: { url: m.url || '/app' } });
  })());
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/app';
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then(cs => { for (const c of cs) if ('focus' in c) return c.focus(); return clients.openWindow(url); }));
});
