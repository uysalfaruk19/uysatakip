/* UYSA ERP Service Worker v120 - Sprint 3
   Strategy: Network-First with offline fallback
   Cache: Static assets (CSS, JS, fonts) → Cache-First
          HTML pages → Network-First
          API calls  → Network-Only
*/
const CACHE_VERSION = 'uysa-v120';
const STATIC_CACHE  = 'uysa-static-v120';
const DYNAMIC_CACHE = 'uysa-dynamic-v120';
const OFFLINE_URL   = '/offline.html';

const STATIC_ASSETS = [
  '/',
  '/style_v119.css',
  '/sw_v120.js',
  'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js'
];

const CACHE_FIRST_EXTS = /\.(css|js|woff2?|ttf|eot|svg|png|jpg|jpeg|webp|ico)$/i;
const NETWORK_ONLY_PATTERNS = [/\/api\//, /\/auth\//, /\/login/];

// ── Install ──────────────────────────────────────────────
self.addEventListener('install', function(event) {
  console.log('[SW-v120] Installing...');
  event.waitUntil(
    caches.open(STATIC_CACHE).then(function(cache) {
      return cache.addAll(STATIC_ASSETS.filter(function(u) {
        return !u.startsWith('http') || u.includes('jsdelivr');
      }));
    }).then(function() {
      return self.skipWaiting();
    }).catch(function(err) {
      console.warn('[SW-v120] Install cache error (non-fatal):', err);
      return self.skipWaiting();
    })
  );
});

// ── Activate (cleanup old caches) ─────────────────────────
self.addEventListener('activate', function(event) {
  console.log('[SW-v120] Activating...');
  event.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(keys.map(function(key) {
        if (key !== STATIC_CACHE && key !== DYNAMIC_CACHE) {
          console.log('[SW-v120] Deleting old cache:', key);
          return caches.delete(key);
        }
      }));
    }).then(function() {
      return self.clients.claim();
    })
  );
});

// ── Fetch Strategy ─────────────────────────────────────────
self.addEventListener('fetch', function(event) {
  var url = event.request.url;
  var method = event.request.method;
  
  // Skip non-GET requests
  if (method !== 'GET') return;
  
  // Skip chrome-extension and other non-http
  if (!url.startsWith('http')) return;
  
  // Network-only for API calls
  var isApi = NETWORK_ONLY_PATTERNS.some(function(p) { return p.test(url); });
  if (isApi) {
    event.respondWith(fetch(event.request));
    return;
  }
  
  // Cache-first for static assets (CSS, JS, fonts, images)
  if (CACHE_FIRST_EXTS.test(url)) {
    event.respondWith(
      caches.match(event.request).then(function(cached) {
        if (cached) return cached;
        return fetch(event.request).then(function(response) {
          if (response && response.status === 200) {
            var clone = response.clone();
            caches.open(STATIC_CACHE).then(function(cache) {
              cache.put(event.request, clone);
            });
          }
          return response;
        }).catch(function() {
          return new Response('/* offline */', { headers: { 'Content-Type': 'text/css' } });
        });
      })
    );
    return;
  }
  
  // Network-first for HTML pages
  event.respondWith(
    fetch(event.request).then(function(response) {
      if (response && response.status === 200) {
        var clone = response.clone();
        caches.open(DYNAMIC_CACHE).then(function(cache) {
          cache.put(event.request, clone);
        });
      }
      return response;
    }).catch(function() {
      // Offline fallback: serve cached HTML
      return caches.match(event.request).then(function(cached) {
        if (cached) return cached;
        return caches.match('/').then(function(root) {
          if (root) return root;
          return new Response(
            '<!DOCTYPE html><html><body><h2>UYSA ERP - Çevrimdışı</h2><p>İnternet bağlantısı yok. Lütfen bağlantınızı kontrol edin.</p></body></html>',
            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
          );
        });
      });
    })
  );
});

// ── Background Sync (offline queue) ───────────────────────
self.addEventListener('sync', function(event) {
  if (event.tag === 'uysa-sync') {
    console.log('[SW-v120] Background sync triggered');
  }
});

// ── Push Notifications ─────────────────────────────────────
self.addEventListener('push', function(event) {
  var data = event.data ? event.data.json() : {};
  var title = data.title || 'UYSA ERP';
  var options = {
    body: data.body || 'Yeni bildirim',
    icon: '/favicon.ico',
    badge: '/favicon.ico',
    tag: 'uysa-notification'
  };
  event.waitUntil(self.registration.showNotification(title, options));
});

console.log('[SW-v120] Sprint3-K: Network-first + Cache-first strategy aktif');
