/* تمريني - عامل الخدمة: يشتغل بدون إنترنت + الإشعارات */
const V = '__V__', CACHE = 'tmr-' + V;
const PRE = ['/', '/icon-192.png', ...['app.js', 'anim.js', 'ui.css', 'gym.css', 'exercises.json', 'vendor/fonts.css', 'vendor/fa.css', 'vendor/fa-solid.woff2', 'vendor/fa-regular.woff2',
  'vendor/plex-arabic-400.woff2', 'vendor/plex-arabic-700.woff2', 'vendor/plex-latin-400.woff2', 'vendor/plex-latin-700.woff2'].map(f => '/assets/' + f + '?v=' + V)];
self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => Promise.all(PRE.map(u => c.add(u).catch(() => {})))).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const r = e.request, u = new URL(r.url);
  if (r.method !== 'GET' || u.origin !== location.origin || u.pathname.startsWith('/api/') || u.pathname === '/cron') return;
  if (r.mode === 'navigate') {
    // الصفحة: من الشبكة أول، وبدون إنترنت من النسخة المحفوظة
    e.respondWith(fetch(r).then(res => { const c = res.clone(); caches.open(CACHE).then(x => x.put('/', c)); return res; }).catch(() => caches.match('/')));
    return;
  }
  if (u.pathname.startsWith('/assets/') || u.pathname.startsWith('/media/') || /\.png$/.test(u.pathname)) {
    e.respondWith(caches.match(r).then(hit => hit || fetch(r).then(res => { if (res.ok) { const c = res.clone(); caches.open(CACHE).then(x => x.put(r, c)); } return res; })));
  }
});
// إشعار من السيرفر: ناخذ نصه من «صندوق الإشعارات»
self.addEventListener('push', e => {
  e.waitUntil((async () => {
    let msgs = [];
    try {
      const sub = await self.registration.pushManager.getSubscription();
      const r = await fetch('/api/push/inbox', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub ? sub.endpoint : '' }) });
      msgs = (await r.json()).messages || [];
    } catch (err) {}
    if (!msgs.length) msgs = [{ title: 'تمريني', body: 'عندك تنبيه جديد', tag: 'tmr', url: '/' }];
    for (const m of msgs) {
      await self.registration.showNotification(m.title, { body: m.body, tag: m.tag, icon: '/icon-192.png', badge: '/icon-192.png', vibrate: [300, 120, 300], renotify: true, dir: 'rtl', lang: 'ar', data: { url: m.url || '/' } });
    }
  })());
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(cs => {
    for (const c of cs) { if ('focus' in c) return c.focus(); }
    return self.clients.openWindow(url);
  }));
});
