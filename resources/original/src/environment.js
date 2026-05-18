(() => {
  'use strict';

  const config = window.STUCKBEMA_APP_CONFIG || {};

  function isLocalHost(hostname) {
    return ['localhost', '127.0.0.1', '0.0.0.0'].includes(hostname);
  }

  function getApiBaseUrl() {
    const api = config.api || {};
    const hostname = window.location.hostname;
    const productionBaseUrl = api.productionBaseUrl || 'https://app.encrypted.wtf';

    if (window.location.origin === productionBaseUrl) {
      return productionBaseUrl;
    }

    if (window.location.protocol === 'https:' && hostname === 'app.encrypted.wtf') {
      return productionBaseUrl;
    }

    if (isLocalHost(hostname)) {
      return api.defaultBaseUrl || 'http://localhost:3001';
    }

    if (window.location.protocol === 'https:') {
      return window.location.origin;
    }

    return `http://${hostname}:3001`;
  }

  const runtime = Object.freeze({
    mode: window.location.protocol === 'https:' ? 'production' : 'development',
    platform: 'browser',
    dataSource: 'api',
    apiBaseUrl: getApiBaseUrl(),
    requestTimeoutMs: config.api?.requestTimeoutMs || 10000
  });

  window.STUCKBEMA_RUNTIME = runtime;

  window.StuckbemaEnvironment = Object.freeze({
    getRuntime() {
      return runtime;
    }
  });
})();
