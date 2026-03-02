const CACHE_NAME = "uysa-erp-v116";
const urlsToCache = ["/", "/manifest.json"];

self.addEventListener("install", event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => cache.addAll(urlsToCache))
  );
});

self.addEventListener("activate", event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    )
  );
});

self.addEventListener("fetch", event => {
  event.respondWith(
    caches.match(event.request).then(response => {
      if (response) return response;
      return fetch(event.request).then(r => {
        if (!r || r.status !== 200 || r.type !== "basic") return r;
        const rc = r.clone();
        caches.open(CACHE_NAME).then(c => c.put(event.request, rc));
        return r;
      }).catch(() => caches.match("/"));
    })
  );
});