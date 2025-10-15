const CACHE_NAME = 'my-performance-cache-v3';
const BASE_SCOPE = (self.registration && self.registration.scope) ? self.registration.scope.replace(/\/+$/, '') : '';
const OFFLINE_URL = withBase('offline.html');

function withBase(path) {
  if (!path.startsWith('/')) {
    path = '/' + path;
  }
  return `${BASE_SCOPE}${path}`;
}

const PRECACHE_URLS = [
  withBase('index.php'),
  OFFLINE_URL,
  withBase('assets/css/material.css'),
  withBase('assets/css/styles.css'),
  withBase('assets/js/app.js')
];

function isSameOrigin(url) {
  try {
    const parsed = typeof url === 'string' ? new URL(url, self.location.href) : url;
    return parsed.origin === self.location.origin;
  } catch (err) {
    return false;
  }
}

function shouldCacheResponse(request, response) {
  if (!response || request.method !== 'GET') {
    return false;
  }
  if (!isSameOrigin(request.url)) {
    return false;
  }
  if (!response.ok || response.status >= 400) {
    return false;
  }
  if (response.type !== 'basic' && response.type !== 'cors') {
    return false;
  }
  return true;
}

async function putInCache(request, response) {
  if (!shouldCacheResponse(request, response)) {
    return;
  }
  const cache = await caches.open(CACHE_NAME);
  try {
    await cache.put(request, response);
  } catch (err) {
    // Ignore storage errors (quota exceeded, opaque responses, etc.)
  }
}

async function cacheFirst(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  if (cached) {
    return cached;
  }
  const response = await fetch(request);
  await putInCache(request, response.clone());
  return response;
}

async function staleWhileRevalidate(event) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(event.request);
  const fetchPromise = fetch(event.request)
    .then(async (response) => {
      await putInCache(event.request, response.clone());
      return response;
    })
    .catch(() => null);

  if (cached) {
    event.waitUntil(fetchPromise.then(() => undefined));
    return cached;
  }

  const networkResponse = await fetchPromise;
  if (networkResponse) {
    return networkResponse;
  }
  if (event.request.mode === 'navigate') {
    const fallback = await cache.match(OFFLINE_URL);
    if (fallback) {
      return fallback;
    }
  }
  throw new Error('Network request failed and no cache entry available.');
}

async function networkFirst(event) {
  try {
    const response = await fetch(event.request);
    await putInCache(event.request, response.clone());
    return response;
  } catch (err) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(event.request);
    if (cached) {
      return cached;
    }
    throw err;
  }
}

async function handleNavigationRequest(event) {
  try {
    const response = await fetch(event.request);
    await putInCache(event.request, response.clone());
    return response;
  } catch (err) {
    const cache = await caches.open(CACHE_NAME);
    const cached = await cache.match(event.request);
    if (cached) {
      return cached;
    }
    const offline = await cache.match(OFFLINE_URL);
    if (offline) {
      return offline;
    }
    const fallback = await cache.match(withBase('index.php'));
    if (fallback) {
      return fallback;
    }
    throw err;
  }
}

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
  const { request } = event;
  if (request.method !== 'GET') {
    return;
  }

  const requestURL = new URL(request.url);
  if (requestURL.origin !== self.location.origin) {
    return;
  }

  if (!requestURL.href.startsWith(BASE_SCOPE)) {
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(handleNavigationRequest(event));
    return;
  }

  const destination = request.destination;
  if (['style', 'script', 'font'].includes(destination) || /\.(?:css|js|woff2?|ttf|eot)$/i.test(requestURL.pathname)) {
    event.respondWith(staleWhileRevalidate(event));
    return;
  }

  if (destination === 'image' || /\.(?:png|jpe?g|gif|svg|webp|ico)$/i.test(requestURL.pathname)) {
    event.respondWith(cacheFirst(request));
    return;
  }

  const acceptHeader = request.headers.get('accept') || '';
  if (acceptHeader.includes('application/json')) {
    event.respondWith(networkFirst(event));
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) {
        return cached;
      }
      return fetch(request).then(async (response) => {
        await putInCache(request, response.clone());
        return response;
      });
    })
  );
});
