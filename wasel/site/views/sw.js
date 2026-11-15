// Service Worker لمنصة واصل: يجعل الموقع قابل للتثبيت كتطبيق (PWA)، ويخزّن الملفات الثابتة فقط.
// الصفحات والبيانات تنجلب دائماً من الإنترنت.
const CACHE = 'wasel-static-v5';
self.addEventListener('install', e => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(
  caches.keys().then(keys => Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())
));
self.addEventListener('fetch', e => {
  const u = new URL(e.request.url);
  if (e.request.method !== 'GET') return;
  const isStatic = (u.origin === location.origin && /^\/(assets\/|pwa-|pm-image\/|branding\/logo)/.test(u.pathname))
    || /fonts\.(googleapis|gstatic)\.com|cdnjs\.cloudflare\.com/.test(u.host);
  if (!isStatic) return;
  e.respondWith(caches.open(CACHE).then(async c => {
    const hit = await c.match(e.request);
    const net = fetch(e.request).then(r => { if (r.ok) c.put(e.request, r.clone()); return r; }).catch(() => hit);
    return hit || net;
  }));
});
self.addEventListener('notificationclick', e => { e.notification.close(); e.waitUntil(clients.openWindow('/app')); });
