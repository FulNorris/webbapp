(() => {
  'use strict';

  const STATE_KEY = 'stuckbema_push_state';
  const ENDPOINT_KEY = 'stuckbema_push_subscription_endpoint';
  const SERVER_NOT_CONFIGURED_MESSAGE = 'Push-notiser är inte aktiverade på servern ännu.';

  function readState() {
    try {
      return JSON.parse(localStorage.getItem(STATE_KEY) || '{}');
    } catch {
      return {};
    }
  }

  function writeState(patch) {
    const next = { ...readState(), ...patch, updatedAt: new Date().toISOString() };
    localStorage.setItem(STATE_KEY, JSON.stringify(next));
    return next;
  }

  function isWebPushSupported() {
    return 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
  }

  function base64UrlToUint8Array(value) {
    const padding = '='.repeat((4 - (value.length % 4)) % 4);
    const base64 = `${value}${padding}`.replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    return Uint8Array.from([...rawData].map((char) => char.charCodeAt(0)));
  }

  async function fetchConfig() {
    const response = await auth.fetch('/api/push/config');
    const config = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(config.error || 'Kunde inte hämta notiskonfiguration.');
    }
    return config;
  }

  async function getPermissionStatus() {
    if ('Notification' in window) {
      return Notification.permission;
    }

    return 'unsupported';
  }

  async function getNotificationSupport() {
    const config = await fetchConfig().catch((error) => ({ error: error.message }));
    const publicKey = config.publicKey || config.webPush?.publicKey || '';
    const configured = Boolean(config.configured || config.webPush?.enabled);
    const supported = isWebPushSupported();

    return {
      supported,
      platform: 'web',
      provider: 'webpush',
      permission: await getPermissionStatus(),
      config,
      configured,
      publicKey,
      webPushEnabled: configured,
      message: config.error || (!configured ? SERVER_NOT_CONFIGURED_MESSAGE : '')
    };
  }

  function createUserError(message) {
    const error = new Error(message);
    error.userMessage = message;
    return error;
  }

  async function requestNotificationPermission(existingSupport = null) {
    const support = existingSupport || await getNotificationSupport();
    if (!support.supported) {
      throw createUserError('Denna webbläsare stödjer inte push-notiser.');
    }

    if (!support.configured || !support.publicKey) {
      writeState({
        enabled: false,
        supported: support.supported,
        configured: false,
        platform: 'web',
        provider: 'webpush',
        permission: support.permission,
        message: SERVER_NOT_CONFIGURED_MESSAGE
      });
      throw createUserError(SERVER_NOT_CONFIGURED_MESSAGE);
    }

    if (Notification.permission === 'default') {
      await Notification.requestPermission();
    }
    writeState({ enabled: Notification.permission === 'granted', permission: Notification.permission });
    return Notification.permission;
  }

  function buildDevicePayload(extra = {}) {
    return {
      userAgent: navigator.userAgent,
      deviceName: navigator.platform || '',
      appVersion: window.STUCKBEMA_APP_CONFIG?.version || '',
      ...extra
    };
  }

  async function registerWebPushDevice(existingSupport = null) {
    const support = existingSupport || await getNotificationSupport();
    if (!support.configured || !support.publicKey) {
      throw createUserError(SERVER_NOT_CONFIGURED_MESSAGE);
    }
    if (!isWebPushSupported()) {
      throw createUserError('Denna webbläsare stödjer inte Web Push.');
    }
    if (Notification.permission !== 'granted') {
      throw createUserError('Notisbehörighet saknas.');
    }

    const registration = await navigator.serviceWorker.ready;
    const existing = await registration.pushManager.getSubscription();
    const subscription = existing || await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: base64UrlToUint8Array(support.publicKey)
    });

    const response = await auth.fetch('/api/push/subscribe', {
      method: 'POST',
      body: JSON.stringify(buildDevicePayload({
        subscription: subscription.toJSON(),
        platform: 'web',
        provider: 'webpush',
        permission: Notification.permission
      }))
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data.error || 'Kunde inte spara push-prenumeration.');
    }

    localStorage.setItem(ENDPOINT_KEY, subscription.endpoint);
    writeState({ enabled: true, platform: 'web', provider: 'webpush', permission: Notification.permission });
    return syncNotificationState();
  }

  async function registerPushDevice() {
    const support = await getNotificationSupport();
    const permission = await requestNotificationPermission(support);
    if (permission !== 'granted') {
      throw createUserError(permission === 'denied'
        ? 'Notiser är blockerade. Ändra behörigheten i webbläsarens inställningar.'
        : 'Notisbehörighet nekades.');
    }

    return registerWebPushDevice(support);
  }

  async function unregisterPushDevice() {
    const payload = {
      endpoint: localStorage.getItem(ENDPOINT_KEY) || undefined
    };

    if (isWebPushSupported()) {
      const registration = await navigator.serviceWorker.ready.catch(() => null);
      const subscription = await registration?.pushManager?.getSubscription?.();
      if (subscription) {
        payload.endpoint = payload.endpoint || subscription.endpoint;
        await subscription.unsubscribe().catch(() => {});
      }
    }

    if (payload.endpoint) {
      await auth.fetch('/api/notifications/subscription', {
        method: 'DELETE',
        body: JSON.stringify(payload)
      }).catch(() => {});
    }

    localStorage.removeItem(ENDPOINT_KEY);
    writeState({ enabled: false });
    return syncNotificationState();
  }

  async function sendTestNotification() {
    const response = await auth.fetch('/api/push/test', { method: 'POST' });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw createUserError(data.error || 'Kunde inte skicka testnotis.');
    }
    return data;
  }

  function handleForegroundNotification(notification = {}) {
    const title = notification.title || notification.notification?.title || 'Stuckbema Leverans';
    const body = notification.body || notification.notification?.body || '';
    if ('Notification' in window && Notification.permission === 'granted' && !document.hidden) {
      new Notification(title, {
        body,
        icon: './icons/icon-192.png',
        data: notification.data || {}
      });
    }
  }

  function handleNotificationClick(event = {}) {
    const data = event.notification?.data || event.data || event;
    const url = data.url || (data.orderId ? `./index.html?order=${encodeURIComponent(data.orderId)}` : './index.html');
    window.location.href = url;
  }

  async function syncNotificationState() {
    const support = await getNotificationSupport();
    let serverSubscriptions = [];
    try {
      const response = await auth.fetch('/api/notifications/subscriptions/me');
      if (response.ok) serverSubscriptions = await response.json();
    } catch (error) {
      console.error('Kunde inte hämta serverns notisprenumerationer:', error);
    }

    const state = writeState({
      supported: support.supported,
      platform: support.platform,
      provider: support.provider,
      permission: support.permission,
      configured: support.configured,
      publicKey: support.publicKey,
      message: support.message,
      serverSubscriptions: serverSubscriptions.length,
      enabled: serverSubscriptions.some((subscription) => subscription.enabled)
    });

    window.dispatchEvent(new window.CustomEvent('stuckbema:notifications-state', { detail: state }));
    return state;
  }

  async function initNotifications() {
    return syncNotificationState();
  }

  function hasPushSubscription() {
    return readState().enabled === true;
  }

  window.StuckbemaPush = Object.freeze({
    initNotifications,
    getNotificationSupport,
    requestNotificationPermission,
    registerPushDevice,
    unregisterPushDevice,
    sendTestNotification,
    handleForegroundNotification,
    handleNotificationClick,
    syncNotificationState,
    requestPushSubscription: registerPushDevice,
    hasPushSubscription
  });
})();
