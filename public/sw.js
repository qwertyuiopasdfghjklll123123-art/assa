/* Sufra service worker: caches the app shell so it opens instantly and works offline.
   API calls (/api/) always go to the network — orders and points must be live. */
const CACHE = 'sufra-v2'

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(['./', 'manifest.webmanifest', 'icons/icon-192.png'])).then(() => self.skipWaiting()))
})

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()),
  )
})

self.addEventListener('fetch', (e) => {
  const req = e.request
  const url = new URL(req.url)
  if (req.method !== 'GET' || url.origin !== location.origin || url.pathname.includes('/api/')) return

  // Hashed build assets never change: cache first.
  if (url.pathname.includes('/_next/static/') || url.pathname.includes('/icons/')) {
    e.respondWith(caches.match(req).then((hit) => hit || fetch(req).then((res) => put(req, res))))
    return
  }
  // Pages, images and the rest: network first, fall back to cache when offline.
  e.respondWith(
    fetch(req)
      .then((res) => put(req, res))
      .catch(() => caches.match(req).then((hit) => hit || caches.match('./'))),
  )
})

function put(req, res) {
  if (res.ok) {
    const copy = res.clone()
    caches.open(CACHE).then((c) => c.put(req, copy))
  }
  return res
}

/* ---------- push notifications (sent by the PHP server) ---------- */
self.addEventListener('push', (e) => {
  let data = {}
  try {
    data = e.data ? e.data.json() : {}
  } catch {
    data = { title: e.data && e.data.text() }
  }
  const base = self.registration.scope
  e.waitUntil(
    self.registration.showNotification(data.title || 'إشعار جديد', {
      body: data.body || '',
      icon: base + 'icons/icon-192.png',
      badge: base + 'icons/icon-192.png',
      tag: data.tag,
      renotify: true,
      dir: 'rtl',
      lang: 'ar',
      data: { url: base + (data.url || 'notifications/') },
    }),
  )
})

self.addEventListener('notificationclick', (e) => {
  e.notification.close()
  const url = (e.notification.data && e.notification.data.url) || self.registration.scope
  e.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const c of list) {
        if ('focus' in c) {
          c.navigate(url)
          return c.focus()
        }
      }
      return self.clients.openWindow(url)
    }),
  )
})
