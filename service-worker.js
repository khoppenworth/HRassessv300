const CACHE_NAME = 'my-performance-cache-v2';
const BASE_SCOPE = (self.registration && self.registration.scope) ? self.registration.scope.replace(/\/+$/, '') : '';

function withBase(path) {
  if (!path.startsWith('/')) {
    path = '/' + path;
  }
  return `${BASE_SCOPE}${path}`;
}

const PRECACHE_URLS = [
  withBase('index.php'),
  withBase('assets/css/material.css'),
  withBase('assets/css/styles.css'),
  withBase('assets/js/app.js')
];

async function precacheStaticAssets() {
  const cache = await caches.open(CACHE_NAME);
  await Promise.all(
    PRECACHE_URLS.map(async (url) => {
      try {
        const response = await fetch(url, { cache: 'no-store' });
        if (response && response.ok) {
          await cache.put(url, response.clone());
        }
      } catch (err) {
        // Ignore failures so one protected resource does not break install
      }
    })
  );
}

self.addEventListener('install', (event) => {
  event.waitUntil(precacheStaticAssets());
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }

  const requestURL = new URL(event.request.url);
  if (requestURL.origin !== self.location.origin) {
    return;
  }

  const isNavigation = event.request.mode === 'navigate';
  if (isNavigation && !requestURL.href.startsWith(BASE_SCOPE)) {
    return;
  }
  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(event.request)
        .then((response) => {
          if (!isNavigation && response && response.ok && event.request.url.startsWith(self.location.origin)) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
          }
          return response;
        })
        .catch((err) => {
          if (isNavigation) {
            return caches.match(withBase('index.php'));
          }
          throw err;
        });
    })
  );
});
