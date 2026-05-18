(() => {
  'use strict';

  const noop = () => {};
  const appCachePrefix = 'stuckbema-leverans-cache-';

  function normalizeText(value) {
    return String(value || '').replace(/\s+/g, ' ').trim();
  }

  function normalizePhone(value) {
    return normalizeText(value).replace(/[\s-]/g, '');
  }

  function normalizeBaseUrl(value) {
    return normalizeText(value).replace(/\/+$/, '');
  }

  function byId(id, options = {}) {
    const element = document.getElementById(id);
    if (!element && options.required) {
      console.warn(`[stuckbema] Saknar DOM-element: #${id}`);
    }
    return element;
  }

  function qs(selector, root = document) {
    return root?.querySelector?.(selector) || null;
  }

  function qsa(selector, root = document) {
    return Array.from(root?.querySelectorAll?.(selector) || []);
  }

  function ready(callback) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', callback, { once: true });
      return;
    }

    window.queueMicrotask(callback);
  }

  function createEventScope() {
    const cleanupStack = [];

    return {
      on(target, type, handler, options) {
        if (!target?.addEventListener || !type || !handler) {
          return noop;
        }

        target.addEventListener(type, handler, options);
        const off = () => target.removeEventListener(type, handler, options);
        cleanupStack.push(off);
        return off;
      },

      timeout(handler, delay, ...args) {
        let cleanup = null;
        const id = window.setTimeout(() => {
          if (cleanup) {
            const index = cleanupStack.indexOf(cleanup);
            if (index >= 0) cleanupStack.splice(index, 1);
          }
          handler(...args);
        }, delay);
        cleanup = () => window.clearTimeout(id);
        cleanupStack.push(cleanup);
        return id;
      },

      interval(handler, delay, ...args) {
        const id = window.setInterval(handler, delay, ...args);
        cleanupStack.push(() => window.clearInterval(id));
        return id;
      },

      cleanup() {
        while (cleanupStack.length) {
          const cleanup = cleanupStack.pop();
          try {
            cleanup();
          } catch (error) {
            console.warn('[stuckbema] Kunde inte städa event/timer:', error);
          }
        }
      }
    };
  }

  async function unregisterServiceWorkers() {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
      return 0;
    }

    const registrations = await navigator.serviceWorker.getRegistrations();
    await Promise.all(registrations.map((registration) => registration.unregister()));
    return registrations.length;
  }

  async function deleteCachesByPrefix(prefix = appCachePrefix) {
    if (typeof window === 'undefined' || !('caches' in window)) {
      return 0;
    }

    const keys = await caches.keys();
    const matchingKeys = keys.filter((key) => key.startsWith(prefix));
    await Promise.all(matchingKeys.map((key) => caches.delete(key)));
    return matchingKeys.length;
  }

  async function resetLocalServiceWorkerState() {
    const [registrations, caches] = await Promise.all([
      unregisterServiceWorkers(),
      deleteCachesByPrefix()
    ]);

    return { registrations, caches };
  }

  function setText(element, value) {
    if (element) {
      element.textContent = value == null ? '' : String(value);
    }
  }

  function setHidden(element, isHidden) {
    if (element) {
      element.hidden = Boolean(isHidden);
    }
  }

  window.StuckbemaCore = Object.freeze({
    byId,
    qs,
    qsa,
    ready,
    noop,
    setText,
    setHidden,
    normalizeText,
    normalizePhone,
    normalizeBaseUrl,
    unregisterServiceWorkers,
    deleteCachesByPrefix,
    resetLocalServiceWorkerState,
    createEventScope
  });
})();
