(() => {
  'use strict';

const core = window.StuckbemaCore || {};
const lifecycle = core.createEventScope?.() || {
  on(target, type, handler, options) {
    target?.addEventListener?.(type, handler, options);
    return () => target?.removeEventListener?.(type, handler, options);
  },
  timeout(handler, delay, ...args) {
    return window.setTimeout(handler, delay, ...args);
  },
  cleanup() {}
};
const byId = core.byId || ((id) => document.getElementById(id));
const qs = core.qs || ((selector, root = document) => root?.querySelector?.(selector) || null);

const statusElement = byId('trackingStatus');
const mapElement = byId('trackingMap');
const mapFrameElement = qs('.tracking-map-frame') || mapElement;
const addressElement = byId('trackingAddress');
const updatedElement = byId('trackingUpdated');
const mapsLink = byId('trackingMapsLink');
const followVehicleBtn = byId('followVehicleBtn');
const followStatusElement = byId('trackingFollowStatus');

const FALLBACK_CENTER = [59.3293, 18.0686];
const FALLBACK_ZOOM = 6;
const VEHICLE_ZOOM = 16;

let map = null;
let tileLayer = null;
let vehicleMarker = null;
let accuracyCircle = null;
let autoCenter = true;
let eventSource = null;
let reconnectTimer = null;
let resizeObserver = null;
let resizeHandlersInstalled = false;
let lastVehicleLatLng = null;
let hasRenderedFirstPosition = false;

function normalizeBaseUrl(value) {
  return core.normalizeBaseUrl?.(value) || String(value || '').trim().replace(/\/+$/, '');
}

function isDevelopmentMode() {
  return ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname) ||
    new URLSearchParams(window.location.search).has('debugMap');
}

function debugMap(message, data = undefined) {
  if (!isDevelopmentMode()) return;
  if (data === undefined) {
    console.info(`[tracking-map] ${message}`);
  } else {
    console.info(`[tracking-map] ${message}`, data);
  }
}

function resolveApiBaseUrl() {
  const runtime = window.StuckbemaEnvironment?.getRuntime?.();
  if (runtime?.apiBaseUrl) return normalizeBaseUrl(runtime.apiBaseUrl);
  const apiConfig = window.STUCKBEMA_APP_CONFIG?.api || {};
  if (window.location.hostname && !window.StuckbemaEnvironment?.isLocalHostname?.(window.location.hostname)) {
    return normalizeBaseUrl(`${window.location.protocol}//${window.location.hostname}:3001`);
  }
  return normalizeBaseUrl(apiConfig.defaultBaseUrl || 'http://localhost:3001');
}

function getToken() {
  const params = new URLSearchParams(window.location.search);
  const queryToken = params.get('token');
  if (queryToken) return queryToken;
  const parts = window.location.pathname.split('/').filter(Boolean);
  return parts[0] === 'track' ? parts[1] : '';
}

function getValidLatLng(location) {
  if (!location) return null;
  const lat = Number(location.latitude ?? location.lat);
  const lng = Number(location.longitude ?? location.lng);
  const isValid = Number.isFinite(lat) &&
    Number.isFinite(lng) &&
    lat >= -90 &&
    lat <= 90 &&
    lng >= -180 &&
    lng <= 180;

  if (!isValid) {
    debugMap('Ogiltiga koordinater ignorerades', { lat, lng, location });
    return null;
  }

  return [lat, lng];
}

async function fetchTracking() {
  const token = getToken();
  if (!token) throw new Error('Trackinglänken saknar token.');
  const response = await fetch(`${resolveApiBaseUrl()}/api/track/${encodeURIComponent(token)}`);
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data.error || 'Kunde inte hämta trackingdata.');
  return data;
}

function scheduleMapInvalidate(reason = 'layout') {
  if (!map) return;

  const run = () => {
    requestAnimationFrame(() => {
      const rect = mapFrameElement.getBoundingClientRect();
      debugMap(`invalidateSize: ${reason}`, {
        width: Math.round(rect.width),
        height: Math.round(rect.height)
      });
      map.invalidateSize(false);
    });
  };

  run();
  lifecycle.timeout(run, 100);
  lifecycle.timeout(run, 300);
  lifecycle.timeout(run, 800);
}

function installMapResizeHandlers() {
  if (resizeHandlersInstalled || !map) return;
  resizeHandlersInstalled = true;

  const run = () => scheduleMapInvalidate('resize');
  lifecycle.on(window, 'resize', run, { passive: true });
  lifecycle.on(window, 'orientationchange', run, { passive: true });
  lifecycle.on(window.visualViewport, 'resize', run, { passive: true });

  if ('ResizeObserver' in window) {
    resizeObserver = new ResizeObserver(() => scheduleMapInvalidate('container-resize'));
    resizeObserver.observe(mapFrameElement);
  }

  lifecycle.on(window, 'pagehide', () => {
    if (reconnectTimer) {
      clearTimeout(reconnectTimer);
      reconnectTimer = null;
    }
    eventSource?.close();
    resizeObserver?.disconnect();
    lifecycle.cleanup();
  }, { once: true });
}

function initMap(location) {
  if (map || !window.L) return;

  const latLng = getValidLatLng(location) || FALLBACK_CENTER;
  const zoom = getValidLatLng(location) ? VEHICLE_ZOOM : FALLBACK_ZOOM;
  map = L.map(mapElement, {
    zoomControl: true,
    preferCanvas: false
  }).setView(latLng, zoom);

  tileLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
  }).addTo(map);

  tileLayer.on('tileerror', (event) => {
    if (isDevelopmentMode()) {
      console.error('[tracking-map] tileerror', event);
    }
  });

  map.on('dragstart zoomstart', () => {
    autoCenter = false;
    setFollowStatus('Manuell kartvy');
  });

  installMapResizeHandlers();
  scheduleMapInvalidate('initial-render');
}

function renderTracking(data) {
  const location = data.liveLocation || data.currentLocation;
  const latLng = getValidLatLng(location);
  const isDelivered = data.status === 'delivered' || data.deliveredAt;
  const isOld = location?.updatedAt && Date.now() - new Date(location.updatedAt).getTime() > 60000;

  initMap(latLng ? location : null);

  if (isDelivered) {
    statusElement.textContent = 'Leveransen är levererad.';
  } else if (!data.liveTrackingEnabled && latLng) {
    statusElement.textContent = 'Föraren är offline eller live-spårning är pausad. Senaste position visas.';
  } else if (latLng) {
    statusElement.textContent = isOld ? 'Positionen är äldre än 60 sekunder.' : 'Din leverans är på väg.';
  } else {
    statusElement.textContent = 'Väntar på första GPS-position...';
  }

  addressElement.textContent = data.deliveryAddress ? `Adress: ${data.deliveryAddress}` : '';
  updatedElement.textContent = location?.updatedAt
    ? `Senast uppdaterad: ${formatDateTime(location.updatedAt)}`
    : '';

  if (!latLng) {
    mapsLink.hidden = true;
    scheduleMapInvalidate('no-position');
    return;
  }

  lastVehicleLatLng = latLng;
  updateVehiclePosition(latLng, location);

  if (!hasRenderedFirstPosition) {
    hasRenderedFirstPosition = true;
    scheduleMapInvalidate('first-position');
  }

  if (autoCenter && map) {
    map.flyTo(latLng, Math.max(map.getZoom(), VEHICLE_ZOOM), { animate: true, duration: 0.6 });
    setFollowStatus('Följer bilen');
  }

  mapsLink.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${latLng[0]},${latLng[1]}`)}`;
  mapsLink.hidden = false;
}

function updateVehiclePosition(latLng, location) {
  if (!window.L || !map) return;

  if (!vehicleMarker) {
    vehicleMarker = L.marker(latLng).addTo(map).bindPopup('Leveransbil');
  } else {
    vehicleMarker.setLatLng(latLng);
  }

  if (accuracyCircle) {
    accuracyCircle.setLatLng(latLng).setRadius(Number(location.accuracy) || 0);
  } else {
    accuracyCircle = L.circle(latLng, {
      radius: Number(location.accuracy) || 0,
      color: '#1d4ed8',
      fillColor: '#60a5fa',
      fillOpacity: 0.16,
      weight: 1
    }).addTo(map);
  }
}

function startStream() {
  const token = getToken();
  if (!token || !window.EventSource || eventSource) return false;
  if (reconnectTimer) {
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }
  eventSource = new EventSource(`${resolveApiBaseUrl()}/api/track/${encodeURIComponent(token)}/stream`);
  eventSource.addEventListener('tracking', (event) => {
    try {
      renderTracking(JSON.parse(event.data));
    } catch (error) {
      debugMap('Ogiltig tracking-stream ignorerades', error);
    }
  });
  eventSource.onerror = () => {
    eventSource?.close();
    eventSource = null;
    if (!reconnectTimer) {
      reconnectTimer = lifecycle.timeout(() => {
        reconnectTimer = null;
        refreshTracking();
      }, 5000);
    }
  };
  return true;
}

function formatDateTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  return date.toLocaleString('sv-SE', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  });
}

async function refreshTracking() {
  try {
    renderTracking(await fetchTracking());
    if (!eventSource) startStream();
  } catch (error) {
    initMap(null);
    statusElement.textContent = error.message || 'Trackinglänken är ogiltig eller har gått ut.';
    mapsLink.hidden = true;
  }
}

function setFollowStatus(message) {
  if (followStatusElement) {
    followStatusElement.textContent = message;
  }
}

lifecycle.on(followVehicleBtn, 'click', () => {
  autoCenter = true;
  scheduleMapInvalidate('follow-button');

  if (!lastVehicleLatLng || !map) {
    setFollowStatus('Ingen aktuell position finns ännu.');
    return;
  }

  map.flyTo(lastVehicleLatLng, Math.max(map.getZoom(), VEHICLE_ZOOM), { animate: true, duration: 0.6 });
  setFollowStatus('Följer bilen');
});

refreshTracking();
})();
