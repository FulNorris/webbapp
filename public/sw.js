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
  const tag = notification.tag || extraData.tag || 'stuckbema-leverans';

  event.waitUntil(self.registration.showNotification(notification.title || fallback.title, {
    body: notification.body || fallback.body,
    icon: '/icons/icon-192.png',
    badge: '/icons/apple-touch-icon-180.png',
    tag,
    renotify: tag !== 'stuckbema-leverans',
    requireInteraction: Boolean(notification.requireInteraction || extraData.requireInteraction),
    timestamp: Date.now(),
    data: {
      url: extraData.url || '/',
      orderId: extraData.orderId || '',
      purchaseId: extraData.purchaseId || '',
      type: extraData.type || '',
    },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  let targetUrl = '/';
  try {
    const requestedUrl = new URL(event.notification.data?.url || '/', self.location.origin);
    if (requestedUrl.origin === self.location.origin) {
      targetUrl = requestedUrl.href;
    }
  } catch {
    targetUrl = '/';
  }

  event.waitUntil((async () => {
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      const clientUrl = new URL(client.url);
      if (clientUrl.origin === self.location.origin && 'focus' in client) {
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
