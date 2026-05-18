self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.map((key) => caches.delete(key)))));
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
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

  event.waitUntil(self.registration.showNotification(notification.title || fallback.title, {
    body: notification.body || fallback.body,
    icon: '/icons/icon-192.png',
    badge: '/icons/apple-touch-icon-180.png',
    tag: notification.tag || extraData.tag || 'stuckbema-leverans',
    data: {
      url: extraData.url || '/',
      orderId: extraData.orderId || '',
    },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = event.notification.data?.url || '/';

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
