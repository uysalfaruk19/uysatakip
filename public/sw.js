/* UYSA ERP Service Worker v121 — Cache-busting update
   Strategy: Network-First for HTML, Cache-First for static assets
   This version forces old cache deletion and immediate activation */
const CACHE_NAME = "uysa-erp-v121";

self.addEventListener("install", event => {
  console.log('[SW-v121] Installing, skip waiting...');
  self.skipWaiting(); // Hemen aktif ol, eski SW'yi bekleme
});

self.addEventListener("activate", event => {
  console.log('[SW-v121] Activating, clearing old caches...');
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => {
        console.log('[SW-v121] Deleting old cache:', k);
        return caches.delete(k);
      }))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", event => {
  const url = event.request.url;

  // Skip non-GET
  if (event.request.method !== 'GET') return;
  if (!url.startsWith('http')) return;

  // API calls: network only
  if (url.includes('/api/') || url.includes('/auth/') || url.includes('/login')) {
    event.respondWith(fetch(event.request));
    return;
  }

  // HTML pages (including /): ALWAYS network-first
  const isHtml = event.request.mode === 'navigate' ||
                 url.endsWith('.html') ||
                 event.request.headers.get('accept')?.includes('text/html');

  if (isHtml) {
    event.respondWith(
      fetch(event.request).then(response => {
        if (response && response.status === 200) {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => {
        return caches.match(event.request).then(cached => cached || caches.match('/'));
      })
    );
    return;
  }

  // Static assets (CSS, JS, images, fonts): cache-first
  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) return cached;
      return fetch(event.request).then(response => {
        if (response && response.status === 200 && response.type === 'basic') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => new Response('', { status: 503 }));
    })
  );
});
