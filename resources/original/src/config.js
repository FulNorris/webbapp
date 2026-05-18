(() => {
  'use strict';

  window.STUCKBEMA_APP_CONFIG = Object.freeze({
    version: '20260505-01',
    assetVersion: '20260505-01',
    appName: 'Stuckbema Leveransdokument',

    data: Object.freeze({
      development: Object.freeze({
        browserDataSource: 'api'
      }),
      production: Object.freeze({
        browserDataSource: 'api'
      })
    }),

    api: Object.freeze({
      defaultBaseUrl: 'http://localhost:3001',
      productionBaseUrl: 'https://app.encrypted.wtf',
      requestTimeoutMs: 10000
    }),

    notifications: Object.freeze({
      enabled: true,
      provider: 'role'
    }),

    serviceWorker: Object.freeze({
      enabled: true,
      disableOnLocalOrigins: false
    })
  });
})();
