const CACHE_NAME = 'my-performance-cache-v1';
const BASE_SCOPE = (self.registration && self.registration.scope) ? self.registration.scope.replace(/\/+$/, '') : '';

function withBase(path) {
  if (!path.startsWith('/')) {
    path = '/' + path;
  }
  return `${BASE_SCOPE}${path}`;
}

const OFFLINE_URLS = [
  withBase(''),
  withBase('dashboard.php'),
  withBase('submit_assessment.php'),
  withBase('my_performance.php'),
  withBase('profile.php'),
  withBase('assets/css/material.css'),
  withBase('assets/css/styles.css'),
  withBase('assets/js/app.js')
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(OFFLINE_URLS))
  );
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
  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(event.request)
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy));
          return response;
        })
        .catch(() => caches.match(withBase('')));
    })
  );
});
