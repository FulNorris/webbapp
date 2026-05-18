const APP_VERSION = '20260505-01';
const CACHE_NAME = `stuckbema-leverans-cache-${APP_VERSION}`;
const INDEX_FALLBACK = `./index.html?v=${APP_VERSION}`;
const LOGIN_FALLBACK = `./login.html?v=${APP_VERSION}`;
const APP_SHELL = [
  './',
  INDEX_FALLBACK,
  LOGIN_FALLBACK,
  `./style.css?v=${APP_VERSION}`,
  `./login.css?v=${APP_VERSION}`,
  `./app.js?v=${APP_VERSION}`,
  `./login.js?v=${APP_VERSION}`,
  `./environment.js?v=${APP_VERSION}`,
  `./auth.js?v=${APP_VERSION}`,
  `./push.js?v=${APP_VERSION}`,
  `./admin.js?v=${APP_VERSION}`,
  `./config.js?v=${APP_VERSION}`,
  `./manifest.webmanifest?v=${APP_VERSION}`,
  './assets/logo.png',
  `./icons/apple-touch-icon-180.png?v=${APP_VERSION}`,
  './icons/icon-192.png',
  './icons/icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)));
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }

  const requestUrl = new URL(event.request.url);
  const isSameOrigin = requestUrl.origin === self.location.origin;
  const isShellAsset = isSameOrigin && ['script', 'style', 'manifest', 'image'].includes(event.request.destination);

  if (event.request.mode === 'navigate') {
    const fallbackDocument = requestUrl.pathname.endsWith('/login.html') ? LOGIN_FALLBACK : INDEX_FALLBACK;

    event.respondWith(
      fetch(event.request)
        .then((response) => {
          if (!response.ok) {
            return response;
          }

          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy)).catch(() => {});
          return response;
        })
        .catch(async () => {
          const cachedRequest = await caches.match(event.request);
          if (cachedRequest) {
            return cachedRequest;
          }

          return caches.match(fallbackDocument);
        })
    );
    return;
  }

  if (!isSameOrigin) {
    return;
  }

  if (isShellAsset) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          if (!response.ok) {
            return response;
          }

          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy)).catch(() => {});
          return response;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      if (cached) {
        return cached;
      }

      return fetch(event.request)
        .then((response) => {
          if (!response.ok) {
            return response;
          }

          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, copy)).catch(() => {});
          return response;
        })
        .catch(() => {
          if (event.request.destination === 'document') {
            return caches.match(INDEX_FALLBACK);
          }

          return Response.error();
        });
    })
  );
});

self.addEventListener('push', (event) => {
  const fallback = { title: 'Stuckbema Leverans', body: 'Leveransstatus uppdaterad.' };
  let data = fallback;

  try {
    data = event.data ? event.data.json() : fallback;
  } catch {
    data = event.data ? { ...fallback, body: event.data.text() } : fallback;
  }

  const notification = data.notification || data;
  const extraData = data.data || data;

  event.waitUntil(
    self.registration.showNotification(notification.title || fallback.title, {
      body: notification.body || fallback.body,
      icon: './icons/icon-192.png',
      badge: './icons/apple-touch-icon-180.png',
      tag: notification.tag || extraData.tag || 'stuckbema-leverans',
      data: {
        url: extraData.url || './',
        orderId: extraData.orderId || ''
      }
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data?.url || './';
  event.waitUntil((async () => {
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      if ('focus' in client) {
        await client.focus();
        if ('navigate' in client) {
          return client.navigate(targetUrl);
        }
        return client;
      }
    }
    return clients.openWindow(targetUrl);
  })());
});
