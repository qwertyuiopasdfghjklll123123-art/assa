<?php
/**
 * PWA service worker. A .php file (not .js) so the cache name can track
 * CACHE_VERSION - the browser only cares about the Content-Type and the
 * registration path, not the file extension, and a root-level script gets
 * the widest possible scope regardless of name.
 */

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');

$cacheName = 'tokmart-' . CACHE_VERSION;
?>
const CACHE_NAME = <?php echo json_encode($cacheName); ?>;

self.addEventListener('install', function() {
    self.skipWaiting();
});

self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys()
            .then(function(keys) {
                return Promise.all(keys.filter(function(k) { return k !== CACHE_NAME; }).map(function(k) { return caches.delete(k); }));
            })
            .then(function() { return self.clients.claim(); })
    );
});

self.addEventListener('fetch', function(event) {
    const req = event.request;
    const url = new URL(req.url);

    // Only cache same-origin GETs for the storefront itself - never the API
    // (always needs fresh data) and never the admin panel (never offline-safe).
    if (req.method !== 'GET' || url.origin !== self.location.origin ||
        url.pathname.indexOf('/api/') !== -1 || url.pathname.indexOf('/admin') !== -1) {
        return;
    }

    event.respondWith(
        caches.match(req).then(function(cached) {
            const fetchPromise = fetch(req).then(function(response) {
                if (response && response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then(function(cache) { cache.put(req, copy); });
                }
                return response;
            }).catch(function() { return cached; });
            return cached || fetchPromise;
        })
    );
});
