(() => {
  'use strict';

const core = window.StuckbemaCore || {};
const byId = core.byId || ((id) => document.getElementById(id));
const qsa = core.qsa || ((selector, root = document) => Array.from(root?.querySelectorAll?.(selector) || []));
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

const STORAGE_KEY = 'stuckbema-leveranser-v3';
const LEGACY_STORAGE_KEY = 'stuckbema-leveranser-v2';
const SERVER_MIGRATION_KEY = 'stuckbema-server-migration-v2';

const state = {
  rows: [],
  permissions: {}
};

const userDisplay = byId('userDisplay');
const logoutBtn = byId('logoutBtn');

const form = byId('deliveryForm');
const editIdInput = byId('editId');
const adressInput = byId('adress');
const teleInput = byId('tele');
const mottagareInput = byId('mottagare');
const recipientEmailInput = byId('recipientEmail');
const desiredDeliveryDateInput = byId('desiredDeliveryDate');
const desiredDeliveryTimeInput = byId('desiredDeliveryTime');
const priorityInput = byId('priority');
const assignedDriverInput = byId('assignedDriverId');
const ovrigtInput = byId('ovrigt');
const internalCommentInput = byId('internalComment');
const itemsContainer = byId('itemsContainer');
const addItemBtn = byId('addItemBtn');
const saveBtn = byId('saveBtn');
const resetBtn = byId('resetBtn');
const clearBtn = byId('clearBtn');
const pdfBtn = byId('pdfBtn');
const printBtn = byId('printBtn');
const adminPanelBtn = byId('adminPanelBtn');
const searchInput = byId('searchInput');
const deliveryList = byId('deliveryList');
const emptyState = byId('emptyState');
const rowCount = byId('rowCount');
const pendingCount = byId('pendingCount');
const deliveredCount = byId('deliveredCount');
const articleCount = byId('articleCount');
const allCount = byId('allCount');
const articleOverview = byId('articleOverview');
const notificationBtn = byId('notificationBtn');
const notificationPanel = byId('notificationPanel');
const notificationPanelCloseBtn = byId('notificationPanelCloseBtn');
const notificationEnableBtn = byId('notificationEnableBtn');
const notificationDisableBtn = byId('notificationDisableBtn');
const notificationTestBtn = byId('notificationTestBtn');
const notificationMessage = byId('notificationMessage');
const notificationStatusText = byId('notificationStatusText');
const notificationEnabledText = byId('notificationEnabledText');
const notificationPlatformText = byId('notificationPlatformText');
const notificationPermissionText = byId('notificationPermissionText');
const notificationProviderText = byId('notificationProviderText');
const visibilityBtn = byId('visibilityBtn');
const workOrderSuggestions = byId('workOrderSuggestions');
const filterButtons = qsa('[data-filter]');
const categoryButtons = qsa('[data-category]');

const itemRowTemplate = byId('itemRowTemplate');
const deliveryCardTemplate = byId('deliveryCardTemplate');
const appConfig = window.STUCKBEMA_APP_CONFIG || {};

const dropdown = {
  root: byId('mainDropdown'),
  button: byId('dropBtn'),
  menu: byId('mainMenu')
};

const viewState = {
  filter: 'all',
  selectedCategory: 'ongoing'
};

const trackingState = {
  watchId: null,
  activeOrderId: null,
  lastSentAt: 0,
  lastPosition: null,
  pendingInitialPosition: null
};

let selectedRecipientSuggestion = null;

init();

async function init() {
  await prepareRuntimeEnvironment();

  if (!auth.isLoggedIn()) {
    window.location.href = 'login.html';
    return;
  }

  displayUserInfo();
  applyRoleBasedAccess();
  await loadRows();
  bindEvents();
  resetForm();
  syncVisibilityButton();
  await window.StuckbemaPush?.initNotifications?.().catch(() => {});
  await syncNotificationButton();
  renderRows();
  registerServiceWorker();
}

async function prepareRuntimeEnvironment() {
  if (shouldDisableServiceWorker()) {
    await resetLocalServiceWorker();
  }
}

function displayUserInfo() {
  const user = auth.getCurrentUser();
  if (user) {
    userDisplay.textContent = `${user.firstName} ${user.lastName}`;
  }

  lifecycle.on(logoutBtn, 'click', handleLogout);
}

async function handleLogout() {
  if (!window.confirm('Är du säker på att du vill logga ut?')) {
    return;
  }

  try {
    await window.StuckbemaPush?.unregisterPushDevice?.();
    await auth.logout();
  } catch (error) {
    console.error('Logout-fel:', error);
  } finally {
    window.location.href = 'login.html';
  }
}

function applyRoleBasedAccess() {
  state.permissions = auth.getPermissions();

  if (!state.permissions.canCreateOrders) {
    form.hidden = true;
  }

  clearBtn.hidden = !state.permissions.canClearOrders;
  pdfBtn.hidden = !state.permissions.canExportOrders;
  printBtn.hidden = !state.permissions.canExportOrders;
  adminPanelBtn.hidden = !state.permissions.canOpenAdminPanel;
  notificationBtn.hidden = !state.permissions.canReceiveNotifications;
}

function bindEvents() {
  lifecycle.on(form, 'submit', handleSubmit);
  lifecycle.on(resetBtn, 'click', resetForm);
  lifecycle.on(clearBtn, 'click', clearAllRows);
  lifecycle.on(pdfBtn, 'click', createPdf);
  lifecycle.on(printBtn, 'click', () => window.print());
  lifecycle.on(adminPanelBtn, 'click', () => window.StuckbemaAdminPanel?.open());
  lifecycle.on(searchInput, 'input', renderRows);
  bindFieldAutocomplete();
  lifecycle.on(addItemBtn, 'click', () => addItemRow({}, true));
  lifecycle.on(deliveryList, 'click', handleDeliveryListClick);
  lifecycle.on(notificationBtn, 'click', openNotificationPanel);
  lifecycle.on(notificationPanelCloseBtn, 'click', closeNotificationPanel);
  lifecycle.on(notificationEnableBtn, 'click', requestNotificationPermission);
  lifecycle.on(notificationDisableBtn, 'click', disableNotifications);
  lifecycle.on(notificationTestBtn, 'click', sendNotificationTest);
  lifecycle.on(window, 'stuckbema:notifications-state', (event) => updateNotificationUi(event.detail || {}));
  lifecycle.on(visibilityBtn, 'click', toggleVisibility);
  lifecycle.on(itemsContainer, 'input', handleItemsInput);
  lifecycle.on(window, 'pagehide', () => {
    stopTracking();
    lifecycle.cleanup();
  }, { once: true });

  filterButtons.forEach((button) => {
    lifecycle.on(button, 'click', () => {
      viewState.filter = button.dataset.filter || 'all';
      syncFilterButtons();
      renderRows();
    });
  });

  categoryButtons.forEach((button) => {
    lifecycle.on(button, 'click', () => {
      viewState.selectedCategory = button.dataset.category || 'ongoing';
      syncCategoryButtons();
      renderRows();
    });
  });

  if (dropdown.button && dropdown.root) {
    lifecycle.on(dropdown.button, 'click', (event) => {
      event.stopPropagation();
      setDropdownOpen(!dropdown.root.classList.contains('active'));
    });

    lifecycle.on(dropdown.menu, 'click', () => setDropdownOpen(false));

    lifecycle.on(document, 'click', (event) => {
      if (!dropdown.root.contains(event.target)) {
        setDropdownOpen(false);
      }
    });

    lifecycle.on(document, 'keydown', (event) => {
      if (event.key === 'Escape') {
        setDropdownOpen(false);
      }
    });
  }
}

async function handleSubmit(event) {
  event.preventDefault();

  const payload = buildDeliveryPayload();
  if (!payload) {
    return;
  }

  const existingId = editIdInput.value;

  try {
    setFormLoading(form, true);

    if (existingId) {
      const updatedOrder = await apiJson(`/api/orders/${encodeURIComponent(existingId)}`, {
        method: 'PUT',
        body: JSON.stringify(payload)
      });
      const existingIndex = state.rows.findIndex((row) => row.id === existingId);

      if (existingIndex >= 0) {
        state.rows[existingIndex] = normalizeStoredRow(updatedOrder);
      } else {
        state.rows.unshift(normalizeStoredRow(updatedOrder));
      }
    } else {
      const createdOrder = await apiJson('/api/orders', {
        method: 'POST',
        body: JSON.stringify(payload)
      });
      state.rows.unshift(normalizeStoredRow(createdOrder));
    }

    state.rows = state.rows.filter(Boolean);
    persistRows();
    renderRows();
    notifyUser('Leverans uppdaterad', `${payload.mottagare} - ${buildItemsSummary(payload.items)}`);
    resetForm();
  } catch (error) {
    window.alert(error.message || 'Kunde inte spara leveransen till Server.');
  } finally {
    setFormLoading(form, false);
  }
}

function buildDeliveryPayload() {
  const payload = {
    adress: normalizeText(adressInput.value),
    tele: normalizePhone(teleInput.value),
    mottagare: normalizeText(mottagareInput.value),
    recipientEmail: normalizeText(recipientEmailInput.value),
    desiredDeliveryDate: normalizeText(desiredDeliveryDateInput.value),
    desiredDeliveryTime: normalizeText(desiredDeliveryTimeInput.value),
    priority: normalizeText(priorityInput.value),
    assignedDriverId: normalizeText(assignedDriverInput.value),
    notes: normalizeText(ovrigtInput.value),
    ovrigt: normalizeText(ovrigtInput.value),
    internalComment: normalizeText(internalCommentInput.value),
    items: collectItems()
  };

  if (!payload.adress || !payload.tele || !payload.mottagare || !payload.desiredDeliveryDate || !payload.desiredDeliveryTime) {
    window.alert('Fyll i mottagare, telefon, adress, önskat datum och önskad tid innan du sparar.');
    return null;
  }

  if (!payload.items) {
    window.alert('Lägg till minst en komplett artikelrad med både antal och artikel.');
    return null;
  }

  return payload;
}

function collectItems() {
  const itemRows = Array.from(itemsContainer.querySelectorAll('.item-row'));
  const items = [];
  let hasInvalidRow = false;

  itemRows.forEach((rowElement) => {
    const antal = normalizeText(rowElement.querySelector('.item-antal')?.value);
    const artikel = normalizeText(rowElement.querySelector('.item-artikel')?.value);
    const workOrderNumber = normalizeText(rowElement.querySelector('.item-work-order')?.value);
    const isEmpty = !antal && !artikel && !workOrderNumber;
    const isValid = Boolean(antal && artikel);

    rowElement.classList.toggle('has-error', !isEmpty && !isValid);

    if (isEmpty) {
      return;
    }

    if (!isValid) {
      hasInvalidRow = true;
      return;
    }

    items.push({ antal, artikel, workOrderNumber });
  });

  if (hasInvalidRow || !items.length) {
    return null;
  }

  return items;
}

function resetForm() {
  form.reset();
  editIdInput.value = '';
  selectedRecipientSuggestion = null;
  saveBtn.textContent = 'Spara leverans';
  itemsContainer.replaceChildren();
  addItemRow();

  if (!form.hidden) {
    adressInput.focus();
  }
}

function addItemRow(item = {}, focusField = false) {
  const fragment = itemRowTemplate.content.cloneNode(true);
  const rowElement = fragment.querySelector('.item-row');
  const antalField = rowElement.querySelector('.item-antal');
  const artikelField = rowElement.querySelector('.item-artikel');
  const workOrderField = rowElement.querySelector('.item-work-order');
  const removeButton = rowElement.querySelector('.remove-item-btn');

  antalField.value = normalizeText(item.antal);
  artikelField.value = normalizeText(item.artikel || item.modell);
  workOrderField.value = normalizeText(item.workOrderNumber || item.work_order_number);

  removeButton.addEventListener('click', () => removeItemRow(rowElement));
  itemsContainer.appendChild(rowElement);
  updateItemRowControls();

  if (focusField) {
    artikelField.focus();
  }
}

function removeItemRow(rowElement) {
  const itemRows = itemsContainer.querySelectorAll('.item-row');
  if (itemRows.length <= 1) {
    rowElement.querySelector('.item-antal').value = '';
    rowElement.querySelector('.item-artikel').value = '';
    rowElement.querySelector('.item-work-order').value = '';
    rowElement.classList.remove('has-error');
    return;
  }

  rowElement.remove();
  updateItemRowControls();
}

function updateItemRowControls() {
  const itemRows = Array.from(itemsContainer.querySelectorAll('.item-row'));
  const shouldDisableRemove = itemRows.length <= 1;

  itemRows.forEach((rowElement) => {
    const removeButton = rowElement.querySelector('.remove-item-btn');
    removeButton.disabled = shouldDisableRemove;
  });
}

function handleDeliveryListClick(event) {
  const actionButton = event.target.closest('[data-action]');
  if (!actionButton) {
    return;
  }

  const card = actionButton.closest('.delivery-card');
  const rowId = card?.dataset.rowId;
  if (!rowId) {
    return;
  }

  const action = actionButton.dataset.action;
  if (action === 'edit' && state.permissions.canUpdateOrders) {
    editRow(rowId);
  } else if (action === 'deliver' && state.permissions.canMarkOrdersDelivered) {
    markDelivered(rowId);
  } else if (action === 'start' && state.permissions.canMarkOrdersDelivered) {
    const row = state.rows.find((item) => item.id === rowId);
    if (row?.liveTrackingEnabled || row?.trackingEnabled) {
      stopDelivery(rowId);
    } else {
      startDelivery(rowId);
    }
  } else if (action === 'map') {
    openTrackingMap(rowId);
  } else if (action === 'resend' && state.permissions.canMarkOrdersDelivered) {
    resendTrackingSms(rowId);
  } else if (action === 'delete' && state.permissions.canDeleteOrders) {
    deleteRow(rowId);
  }
}

function editRow(id) {
  const row = state.rows.find((item) => item.id === id);
  if (!row) {
    return;
  }

  editIdInput.value = row.id;
  adressInput.value = row.adress;
  teleInput.value = row.tele;
  mottagareInput.value = row.mottagare;
  selectedRecipientSuggestion = null;
  recipientEmailInput.value = row.recipientEmail || '';
  desiredDeliveryDateInput.value = row.desiredDeliveryDate || '';
  desiredDeliveryTimeInput.value = row.desiredDeliveryTime || '';
  priorityInput.value = row.priority || '';
  assignedDriverInput.value = row.assignedDriverId || row.driverId || '';
  ovrigtInput.value = row.ovrigt;
  internalCommentInput.value = row.internalComment || '';
  saveBtn.textContent = 'Spara ändring';

  itemsContainer.replaceChildren();
  row.items.forEach((item) => addItemRow(item));
  updateItemRowControls();

  window.scrollTo({ top: 0, behavior: 'smooth' });
  adressInput.focus();
}

async function deleteRow(id) {
  if (!window.confirm('Ta bort den här leveransen?')) {
    return;
  }

  try {
    await apiJson(`/api/orders/${encodeURIComponent(id)}`, { method: 'DELETE' });
    state.rows = state.rows.filter((item) => item.id !== id);
    persistRows();
    renderRows();

    if (editIdInput.value === id) {
      resetForm();
    }
  } catch (error) {
    window.alert(error.message || 'Kunde inte ta bort leveransen från Server.');
  }
}

async function markDelivered(id) {
  const row = state.rows.find((item) => item.id === id);
  if (!row) {
    return;
  }

  const note = window.prompt(
    'Ange leveransinfo manuellt. Exempel: Levererat till port, kvitterat av Anders.',
    row.deliveredNote || ''
  );

  if (note === null) {
    return;
  }

  try {
    const updatedOrder = await apiJson(`/api/orders/${encodeURIComponent(id)}/delivered`, {
      method: 'PATCH',
      body: JSON.stringify({ deliveredNote: normalizeText(note) })
    });
    const existingIndex = state.rows.findIndex((item) => item.id === id);

    if (existingIndex >= 0) {
      state.rows[existingIndex] = normalizeStoredRow(updatedOrder);
    }

    if (trackingState.activeOrderId === id) {
      stopTracking();
    }

    state.rows = state.rows.filter(Boolean);
    persistRows();
    renderRows();
    notifyUser('Leverans markerad levererad', `${row.mottagare} - ${row.adress}`);
  } catch (error) {
    window.alert(error.message || 'Kunde inte markera leveransen som levererad i Server.');
  }
}

async function startDelivery(id) {
  const row = state.rows.find((item) => item.id === id);
  if (!row) {
    return;
  }

  const user = auth.getCurrentUser();
  if (user?.visibility !== 'online') {
    window.alert('Du måste vara Online för att starta leverans.');
    return;
  }

  if (!normalizeText(row.tele)) {
    window.alert('Mottagarnummer saknas.');
    return;
  }

  try {
    const initialPosition = await getCurrentPositionForTracking();
    trackingState.pendingInitialPosition = initialPosition;
    const result = await apiJson(`/api/orders/${encodeURIComponent(id)}/start`, {
      method: 'POST',
      body: JSON.stringify({})
    });
    const updatedOrder = normalizeStoredRow(result.order);
    const existingIndex = state.rows.findIndex((item) => item.id === id);

    if (existingIndex >= 0 && updatedOrder) {
      state.rows[existingIndex] = updatedOrder;
    }

    persistRows();
    renderRows();
    await startTracking(id);
    await sendTrackedPosition(id, initialPosition, true);

    const isAlreadyTracking = result.sms?.reason === 'already_tracking';
    const smsText = result.sms?.sent
      ? 'SMS skickat till mottagaren.'
      : isAlreadyTracking
        ? 'Leveransen var redan live. Inget nytt SMS skickades.'
        : 'SMS-provider saknas. Öppnar SMS-fallback så du kan skicka länken manuellt.';
    if (!result.sms?.sent && !isAlreadyTracking && updatedOrder?.trackingUrl) {
      openSmsFallback(updatedOrder);
    }
    window.alert(`${smsText}\n${updatedOrder?.trackingUrl || ''}`.trim());
  } catch (error) {
    window.alert(error.message || 'Platsbehörighet krävs för live-spårning.');
  }
}

async function stopDelivery(id) {
  try {
    stopTracking();
    const result = await apiJson(`/api/orders/${encodeURIComponent(id)}/stop`, {
      method: 'POST',
      body: JSON.stringify({})
    });
    const updatedOrder = normalizeStoredRow(result.order);
    const existingIndex = state.rows.findIndex((item) => item.id === id);
    if (existingIndex >= 0 && updatedOrder) {
      state.rows[existingIndex] = updatedOrder;
    }
    persistRows();
    renderRows();
  } catch (error) {
    window.alert(error.message || 'Kunde inte stoppa leverans.');
  }
}

async function resendTrackingSms(id) {
  const row = state.rows.find((item) => item.id === id);
  if (!row?.trackingUrl) {
    window.alert('Trackinglänk saknas. Starta leveransen först.');
    return;
  }
  try {
    const result = await apiJson(`/api/orders/${encodeURIComponent(id)}/resend-tracking-sms`, {
      method: 'POST',
      body: JSON.stringify({})
    });
    const updatedOrder = normalizeStoredRow(result.order);
    const existingIndex = state.rows.findIndex((item) => item.id === id);
    if (existingIndex >= 0 && updatedOrder) {
      state.rows[existingIndex] = updatedOrder;
    }
    persistRows();
    renderRows();
    if (!result.sms?.sent) {
      openSmsFallback(updatedOrder || row);
    }
    window.alert(result.sms?.sent ? 'Spårningslänk skickad.' : 'SMS-provider saknas. SMS-fallback öppnad.');
  } catch (error) {
    window.alert(error.message || 'Kunde inte skicka spårningslänk.');
  }
}

function openTrackingMap(id) {
  const row = state.rows.find((item) => item.id === id);
  if (!row?.trackingUrl) {
    window.alert('Trackinglänk saknas. Starta leveransen först.');
    return;
  }
  window.open(row.trackingUrl, '_blank', 'noopener,noreferrer');
}

function openSmsFallback(row) {
  const phone = normalizePhone(row.tele || row.recipientPhone);
  const message = `Din leverans är nu på väg till dig. Följ bilen live i realtid här: ${row.trackingUrl}`;
  if (!phone || !row.trackingUrl) {
    window.prompt('Kopiera spårningslänken:', row.trackingUrl || '');
    return;
  }
  window.location.href = `sms:${encodeURIComponent(phone)}?&body=${encodeURIComponent(message)}`;
}

function getCurrentPositionForTracking() {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) {
      reject(new Error('GPS stöds inte i den här enheten eller webbläsaren.'));
      return;
    }
    navigator.geolocation.getCurrentPosition(resolve, () => {
      reject(new Error('Platsbehörighet krävs för live-spårning.'));
    }, {
      enableHighAccuracy: true,
      timeout: 12000,
      maximumAge: 3000
    });
  });
}

async function toggleVisibility() {
  const user = auth.getCurrentUser();
  const nextVisibility = user?.visibility === 'online' ? 'offline' : 'online';

  try {
    const result = await apiJson('/api/drivers/visibility', {
      method: 'POST',
      body: JSON.stringify({ visibility: nextVisibility })
    });

    auth.setUser(result.user);
    syncVisibilityButton();

    if (nextVisibility === 'offline') {
      stopTracking();
      state.rows = state.rows.map((row) => row.driverId === result.user.id ? { ...row, trackingEnabled: false } : row);
      persistRows();
      renderRows();
      window.alert('Du är Offline. Kunden kan inte se liveposition.');
    }
  } catch (error) {
    window.alert(error.message || 'Kunde inte ändra Online/Offline-status.');
  }
}

function syncVisibilityButton() {
  if (!visibilityBtn) {
    return;
  }

  const status = visibilityBtn.querySelector('strong');
  const user = auth.getCurrentUser();
  const isOnline = user?.visibility === 'online';

  visibilityBtn.classList.toggle('is-online', isOnline);
  visibilityBtn.setAttribute('aria-pressed', String(isOnline));
  if (status) {
    status.textContent = isOnline ? 'Online' : 'Offline';
  }
}

async function startTracking(orderId) {
  stopTracking();

  if (!('geolocation' in navigator)) {
    window.alert('GPS stöds inte i den här enheten eller webbläsaren.');
    return;
  }

  trackingState.activeOrderId = orderId;
  trackingState.lastSentAt = 0;
  trackingState.lastPosition = null;
  trackingState.watchId = navigator.geolocation.watchPosition(
    (position) => sendTrackedPosition(orderId, position),
    (error) => console.error('GPS-fel:', error),
    {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 5000
    }
  );
}

function stopTracking() {
  if (trackingState.watchId !== null && 'geolocation' in navigator) {
    navigator.geolocation.clearWatch(trackingState.watchId);
  }

  trackingState.watchId = null;
  trackingState.activeOrderId = null;
  trackingState.lastSentAt = 0;
  trackingState.lastPosition = null;
}

async function sendTrackedPosition(orderId, position, force = false) {
  const now = Date.now();
  const coords = position.coords;
  const nextPosition = {
    lat: coords.latitude,
    lng: coords.longitude
  };

  if (!force && now - trackingState.lastSentAt < 4000 && !hasMovedEnough(trackingState.lastPosition, nextPosition)) {
    return;
  }

  trackingState.lastSentAt = now;
  trackingState.lastPosition = nextPosition;

  try {
    const result = await apiJson(`/api/orders/${encodeURIComponent(orderId)}/location`, {
      method: 'POST',
      body: JSON.stringify({
        lat: coords.latitude,
        lng: coords.longitude,
        accuracy: coords.accuracy,
        speed: coords.speed,
        heading: coords.heading,
        trackingSessionId: state.rows.find((row) => row.id === orderId)?.trackingSessionId || null,
        timestamp: new Date().toISOString()
      })
    });

    const updatedOrder = normalizeStoredRow(result.order);
    const existingIndex = state.rows.findIndex((row) => row.id === orderId);
    if (existingIndex >= 0 && updatedOrder) {
      state.rows[existingIndex] = updatedOrder;
      persistRows();
      renderRows();
    }
  } catch (error) {
    console.error('Kunde inte spara GPS-position:', error);
  }
}

function hasMovedEnough(previous, next) {
  if (!previous || !next) {
    return true;
  }

  const latDelta = Math.abs(previous.lat - next.lat);
  const lngDelta = Math.abs(previous.lng - next.lng);
  return latDelta > 0.0001 || lngDelta > 0.0001;
}

async function clearAllRows() {
  if (!state.rows.length) {
    return;
  }

  if (!window.confirm('Rensa hela leveranslistan? Detta går inte att ångra.')) {
    return;
  }

  try {
    await apiJson('/api/orders', { method: 'DELETE' });
    state.rows = [];
    persistRows();
    renderRows();
    resetForm();
  } catch (error) {
    window.alert(error.message || 'Kunde inte rensa leveranser i Server.');
  }
}

function renderRows() {
  const filteredRows = getFilteredRows();
  const fragment = document.createDocumentFragment();
  updateOverview();
  syncCategoryButtons();

  if (viewState.selectedCategory === 'articles') {
    deliveryList.replaceChildren();
    articleOverview.hidden = false;
    articleOverview.replaceChildren(buildArticleOverview());
    emptyState.style.display = articleOverview.childElementCount ? 'none' : 'block';
    emptyState.textContent = 'Inga artiklar hittades.';
    rowCount.textContent = 'Artikelöversikt';
    return;
  }

  articleOverview.hidden = true;
  emptyState.textContent = viewState.selectedCategory === 'delivered'
    ? 'Inga levererade leveranser'
    : viewState.selectedCategory === 'ongoing'
      ? 'Inga pågående leveranser'
      : 'Inga leveranser ännu.';

  filteredRows.forEach((row) => {
    const cardFragment = deliveryCardTemplate.content.cloneNode(true);
    const card = cardFragment.querySelector('.delivery-card');

    card.dataset.rowId = row.id;

    const statusField = card.querySelector('[data-field="status"]');
    const summaryField = card.querySelector('[data-field="summary"]');
    const mottagareField = card.querySelector('[data-field="mottagare"]');
    const adressField = card.querySelector('[data-field="adress"]');
    const teleField = card.querySelector('[data-field="tele"]');
    const deliveryWindowField = card.querySelector('[data-field="deliveryWindow"]');
    const ovrigtField = card.querySelector('[data-field="ovrigt"]');
    const internalCommentField = card.querySelector('[data-field="internalComment"]');
    const itemsField = card.querySelector('[data-field="items"]');
    const trackingField = card.querySelector('[data-field="tracking"]');
    const trackField = card.querySelector('[data-field="track"]');
    const datesField = card.querySelector('[data-field="dates"]');
    const noteField = card.querySelector('[data-field="note"]');

    statusField.classList.add(row.status === 'delivered' ? 'delivered' : row.status === 'ongoing' ? 'ongoing' : row.status === 'paused' ? 'paused' : row.status === 'cancelled' ? 'cancelled' : 'pending');
    statusField.textContent = getStatusLabel(row.status);

    summaryField.textContent = buildItemsSummary(row.items);
    mottagareField.textContent = row.mottagare;
    adressField.textContent = row.adress;
    teleField.textContent = row.tele;
    deliveryWindowField.textContent = buildDeliveryWindow(row);
    trackingField.textContent = buildTrackingLabel(row);

    if (row.ovrigt) {
      ovrigtField.textContent = `Övrigt: ${row.ovrigt}`;
      ovrigtField.hidden = false;
    } else {
      ovrigtField.hidden = true;
    }

    if (row.internalComment) {
      internalCommentField.textContent = `Intern kommentar: ${row.internalComment}`;
      internalCommentField.hidden = false;
    } else {
      internalCommentField.hidden = true;
    }

    itemsField.replaceChildren(buildItemsList(row.items));
    trackField.replaceChildren(buildStatusTrack(row));
    datesField.replaceChildren(buildDatesDetails(row));

    if (row.deliveredNote) {
      noteField.textContent = `Leveransinfo: ${row.deliveredNote}`;
      noteField.classList.remove('is-empty');
    } else {
      noteField.textContent = '';
      noteField.classList.add('is-empty');
    }

    const dialValue = row.tele.replace(/[^\d+]/g, '');
    card.querySelector('.map-link').href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(row.adress)}`;
    card.querySelector('.call-link').href = `tel:${dialValue}`;
    card.querySelector('.sms-link').href = `sms:${dialValue}`;

    card.querySelector('.edit-btn').hidden = !state.permissions.canUpdateOrders;
    const startButton = card.querySelector('.start-btn');
    startButton.hidden = !state.permissions.canMarkOrdersDelivered || row.status === 'delivered';
    startButton.textContent = row.liveTrackingEnabled || row.trackingEnabled ? 'Stoppa leverans' : 'Starta leverans';
    card.querySelector('.map-btn').hidden = !row.trackingUrl;
    card.querySelector('.resend-btn').hidden = !state.permissions.canMarkOrdersDelivered || !row.trackingUrl || row.status === 'delivered';
    card.querySelector('.deliver-btn').hidden = !state.permissions.canMarkOrdersDelivered || row.status === 'delivered';
    card.querySelector('.delete-btn').hidden = !state.permissions.canDeleteOrders;
    card.querySelector('.card-actions').hidden = !state.permissions.canUpdateOrders &&
      !state.permissions.canMarkOrdersDelivered &&
      !state.permissions.canDeleteOrders;

    fragment.appendChild(cardFragment);
  });

  deliveryList.replaceChildren(fragment);
  emptyState.style.display = filteredRows.length ? 'none' : 'block';
  rowCount.textContent = buildRowCountLabel(filteredRows.length, state.rows.length);
}

function buildItemsList(items) {
  const fragment = document.createDocumentFragment();

  items.forEach((item) => {
    const listItem = document.createElement('li');
    listItem.className = 'item-pill';

    const qty = document.createElement('span');
    qty.className = 'item-pill-qty';
    qty.textContent = item.antal;

    const name = document.createElement('span');
    name.className = 'item-pill-name';
    name.textContent = item.workOrderNumber ? `${item.artikel} · AO ${item.workOrderNumber}` : item.artikel;

    listItem.append(qty, name);
    fragment.appendChild(listItem);
  });

  return fragment;
}

function buildArticleOverview() {
  const fragment = document.createDocumentFragment();
  const byArticle = new Map();

  state.rows.forEach((row) => {
    row.items.forEach((item) => {
      const key = item.artikel;
      const current = byArticle.get(key) || { artikel: key, rows: 0, quantities: [] };
      current.rows += 1;
      current.quantities.push(item.antal);
      byArticle.set(key, current);
    });
  });

  [...byArticle.values()].sort((a, b) => a.artikel.localeCompare(b.artikel, 'sv')).forEach((entry) => {
    const item = document.createElement('article');
    item.className = 'article-summary-card';
    const title = document.createElement('h3');
    const meta = document.createElement('p');
    title.textContent = entry.artikel;
    meta.textContent = `${entry.rows} leveransrader · Antal: ${entry.quantities.join(', ')}`;
    item.append(title, meta);
    fragment.appendChild(item);
  });

  return fragment;
}

function getStatusLabel(status) {
  return {
    created: 'Pågående',
    ongoing: 'Live aktiv',
    paused: 'Pausad',
    delivered: 'Levererad',
    cancelled: 'Avbruten'
  }[status] || 'Pågående';
}

function buildDeliveryWindow(row) {
  const date = row.desiredDeliveryDate || 'datum saknas';
  const time = row.desiredDeliveryTime || 'tid saknas';
  const priority = row.priority ? ` · Prioritet: ${row.priority}` : '';
  return `Önskad leverans: ${date} ${time}${priority}`;
}

function buildStatusTrack(row) {
  const fragment = document.createDocumentFragment();
  const steps = [
    { label: 'Registrerad', active: true },
    { label: 'På väg', active: row.status === 'ongoing' || row.status === 'paused' || row.status === 'delivered' },
    { label: 'Levererad', active: row.status === 'delivered' }
  ];

  steps.forEach((step) => {
    const stepElement = document.createElement('div');
    stepElement.className = `track-step${step.active ? ' is-active' : ''}`;

    const dot = document.createElement('span');
    dot.className = 'track-dot';

    const label = document.createElement('span');
    label.textContent = step.label;

    stepElement.append(dot, label);
    fragment.appendChild(stepElement);
  });

  return fragment;
}

function buildDatesDetails(row) {
  const fragment = document.createDocumentFragment();
  const lines = [
    `Skapad: ${formatDateTime(row.createdAt)}`,
    row.startedAt ? `Startad: ${formatDateTime(row.startedAt)}` : 'Startad: —',
    row.lastStoppedAt ? `Stoppad: ${formatDateTime(row.lastStoppedAt)}` : 'Stoppad: —',
    row.updatedHistory.length
      ? `Ändringar: ${row.updatedHistory.map(formatDateTime).join(' | ')}`
      : 'Ändringar: —',
    row.deliveredAt
      ? `Levererad: ${formatDateTime(row.deliveredAt)}`
      : 'Levererad: —'
  ];

  lines.forEach((text) => {
    const line = document.createElement('div');
    line.textContent = text;
    fragment.appendChild(line);
  });

  return fragment;
}

function getFilteredRows() {
  const query = normalizeText(searchInput.value).toLowerCase();
  return state.rows.filter((row) => {
    if (viewState.selectedCategory === 'ongoing' && row.status === 'delivered') {
      return false;
    }

    if (viewState.selectedCategory === 'delivered' && row.status !== 'delivered') {
      return false;
    }

    if (viewState.filter === 'pending' && row.deliveredAt) {
      return false;
    }

    if (viewState.filter === 'delivered' && !row.deliveredAt) {
      return false;
    }

    return !query || buildSearchBlob(row).includes(query);
  });
}

function buildSearchBlob(row) {
  return [
    row.adress,
    row.tele,
    row.mottagare,
    row.ovrigt,
    row.notes,
    row.desiredDeliveryDate,
    row.desiredDeliveryTime,
    row.internalComment,
    row.deliveredNote,
    row.trackingUrl,
    row.externalWorkOrderNumber,
    row.createdAt,
    ...row.updatedHistory,
    ...row.items.map((item) => `${item.antal} ${item.artikel} ${item.workOrderNumber || ''}`)
  ]
    .join(' ')
    .toLowerCase();
}

function buildItemsSummary(items) {
  return `${items.length} ${items.length === 1 ? 'artikel' : 'artiklar'}`;
}

function buildTrackingLabel(row) {
  if (row.status === 'delivered') {
    return 'Live-spårning avslutad.';
  }

  if ((row.trackingEnabled || row.liveTrackingEnabled) && row.trackingUrl) {
    return `Live-spårning aktiv: ${row.trackingUrl}`;
  }

  if (row.status === 'paused') {
    return 'Live-spårning pausad. Senaste position visas för mottagaren.';
  }

  return '';
}

function handleItemsInput(event) {
  const field = event.target.closest('.item-work-order');
  if (!field) {
    return;
  }

  const query = normalizeText(field.value);
  if (query.length < 1) {
    return;
  }

  queueWorkOrderSuggestions(query);
}

let workOrderSuggestionTimer = null;

function queueWorkOrderSuggestions(query) {
  clearTimeout(workOrderSuggestionTimer);
  workOrderSuggestionTimer = lifecycle.timeout(() => fetchWorkOrderSuggestions(query), 250);
}

async function fetchWorkOrderSuggestions(query) {
  if (!workOrderSuggestions) {
    return;
  }

  try {
    const suggestions = await apiJson(`/api/work-orders/suggestions?q=${encodeURIComponent(query)}`);
    workOrderSuggestions.replaceChildren(
      ...suggestions.map((suggestion) => {
        const option = document.createElement('option');
        option.value = suggestion;
        return option;
      })
    );
  } catch (error) {
    console.error('Kunde inte hämta arbetsorderförslag:', error);
  }
}

function buildRowCountLabel(filteredCount, totalCount) {
  const rowLabel = (count) => `${count} ${count === 1 ? 'leverans' : 'leveranser'}`;
  if (filteredCount === totalCount) {
    return rowLabel(filteredCount);
  }

  return `Visar ${rowLabel(filteredCount)} av ${rowLabel(totalCount)}`;
}

function updateOverview() {
  const pending = state.rows.filter((row) => row.status !== 'delivered').length;
  const delivered = state.rows.filter((row) => row.status === 'delivered').length;
  const articles = state.rows.reduce((sum, row) => sum + row.items.length, 0);

  pendingCount.textContent = pending;
  deliveredCount.textContent = delivered;
  articleCount.textContent = articles;
  allCount.textContent = state.rows.length;
}

function syncCategoryButtons() {
  categoryButtons.forEach((button) => {
    const isActive = button.dataset.category === viewState.selectedCategory;
    button.classList.toggle('is-active', isActive);
    button.setAttribute('aria-pressed', String(isActive));
  });
}

function syncFilterButtons() {
  filterButtons.forEach((button) => {
    const isActive = button.dataset.filter === viewState.filter;
    button.classList.toggle('is-active', isActive);
    button.setAttribute('aria-pressed', String(isActive));
  });
}

function setDropdownOpen(isOpen) {
  if (!dropdown.root || !dropdown.button) {
    return;
  }

  dropdown.root.classList.toggle('active', isOpen);
  dropdown.button.setAttribute('aria-expanded', String(isOpen));
}

function normalizeText(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function normalizePhone(value) {
  return normalizeText(value).replace(/[\s-]/g, '');
}

function getRuntimeApiBaseUrl() {
  return String(window.StuckbemaEnvironment?.getRuntime?.().apiBaseUrl || window.location.origin).replace(/\/+$/, '');
}

function normalizeStatus(value, deliveredAt = null) {
  if (deliveredAt) return 'delivered';
  const status = normalizeText(value).toLowerCase();
  if (['ongoing', 'started', 'på_väg', 'pa_vag'].includes(status)) return 'ongoing';
  if (['paused', 'pausad'].includes(status)) return 'paused';
  if (['delivered', 'levererad'].includes(status)) return 'delivered';
  if (['cancelled', 'canceled', 'avbruten'].includes(status)) return 'cancelled';
  return 'created';
}

function detachSelectedRecipientIfManualValueChanged() {
  if (selectedRecipientSuggestion) {
    const selectedName = normalizeText(selectedRecipientSuggestion.name);
    const selectedPhone = normalizePhone(selectedRecipientSuggestion.phone);
    const selectedEmail = normalizeText(selectedRecipientSuggestion.email).toLowerCase();
    const currentName = normalizeText(mottagareInput.value);
    const currentPhone = normalizePhone(teleInput.value);
    const currentEmail = normalizeText(recipientEmailInput.value).toLowerCase();
    if (currentName !== selectedName || currentPhone !== selectedPhone || (selectedEmail && currentEmail !== selectedEmail)) {
      selectedRecipientSuggestion = null;
    }
  }
}

function bindFieldAutocomplete() {
  const factory = window.StuckbemaFieldAutocomplete?.createFieldAutocomplete;
  if (!factory) {
    return;
  }

  const apiBaseUrl = getRuntimeApiBaseUrl();
  const namesEndpoint = `${apiBaseUrl}/api/customers/suggest/names`;
  const phonesEndpoint = `${apiBaseUrl}/api/customers/suggest/phones`;
  const emailsEndpoint = `${apiBaseUrl}/api/customers/suggest/emails`;
  const driversEndpoint = `${apiBaseUrl}/api/search/drivers`;
  const renderSuggestionValue = (result) => ({ label: result.value || result.label || '', meta: '' });

  [mottagareInput, teleInput, recipientEmailInput].forEach((input) => {
    input.addEventListener('input', detachSelectedRecipientIfManualValueChanged);
  });

  factory({
    input: mottagareInput,
    fieldType: 'name',
    endpoint: namesEndpoint,
    minChars: 2,
    renderItem: renderSuggestionValue,
    onSelect(person) {
      selectedRecipientSuggestion = person;
      mottagareInput.value = person.name || person.value || '';
    }
  });

  factory({
    input: teleInput,
    fieldType: 'phone',
    endpoint: phonesEndpoint,
    minChars: 2,
    renderItem: renderSuggestionValue,
    onSelect(person) {
      selectedRecipientSuggestion = person;
      teleInput.value = normalizePhone(person.phone || person.value || '');
    }
  });

  factory({
    input: recipientEmailInput,
    fieldType: 'email',
    endpoint: emailsEndpoint,
    minChars: 2,
    renderItem: renderSuggestionValue,
    onSelect(person) {
      selectedRecipientSuggestion = person;
      recipientEmailInput.value = person.email || person.value || '';
    }
  });

  factory({
    input: assignedDriverInput,
    fieldType: 'driver',
    endpoint: driversEndpoint,
    minChars: 1,
    onSelect(driver) {
      assignedDriverInput.value = driver.id || driver.value || driver.name || '';
    }
  });
}

async function apiJson(endpoint, options = {}) {
  const response = await auth.fetch(endpoint, options);
  const text = await response.text();
  let data = null;

  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = { error: text };
    }
  }

  if (!response.ok) {
    throw new Error(data?.error || `API-fel ${response.status}`);
  }

  return data;
}

function setFormLoading(targetForm, isLoading) {
  const controls = targetForm.querySelectorAll('button, input, textarea, select');
  controls.forEach((control) => {
    control.disabled = isLoading;
  });

  saveBtn.textContent = isLoading ? 'Sparar...' : (editIdInput.value ? 'Spara ändring' : 'Spara leverans');
}

function formatDateTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return '—';
  }

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  const hours = String(date.getHours()).padStart(2, '0');
  const minutes = String(date.getMinutes()).padStart(2, '0');
  return `${year}-${month}-${day} ${hours}:${minutes}`;
}

function persistRows() {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state.rows));
}

async function loadRows() {
  const localRows = loadRowsFromLocalStorage();

  try {
    const serverRows = await apiJson('/api/orders');
    state.rows = Array.isArray(serverRows) ? serverRows.map(normalizeStoredRow).filter(Boolean) : [];

    if (!state.rows.length && localRows.length && state.permissions.canCreateOrders && !localStorage.getItem(SERVER_MIGRATION_KEY)) {
      state.rows = await migrateLocalRowsToServer(localRows);
      localStorage.setItem(SERVER_MIGRATION_KEY, new Date().toISOString());
    }

    persistRows();
    localStorage.removeItem(LEGACY_STORAGE_KEY);
  } catch (error) {
    console.error('Server sync-fel:', error);
    state.rows = localRows;

    if (state.rows.length) {
      window.alert('Kunde inte hämta leveranser från Server. Visar lokal cache tills servern är nåbar igen.');
    } else {
      window.alert(error.message || 'Kunde inte hämta leveranser från Server.');
    }
  }
}

function loadRowsFromLocalStorage() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY) || localStorage.getItem(LEGACY_STORAGE_KEY);
    const rows = raw ? JSON.parse(raw) : [];
    return Array.isArray(rows) ? rows.map(normalizeStoredRow).filter(Boolean) : [];
  } catch {
    return [];
  }
}

async function migrateLocalRowsToServer(localRows) {
  const migratedRows = [];

  for (const row of localRows) {
    const migratedRow = await apiJson('/api/orders', {
      method: 'POST',
      body: JSON.stringify(row)
    });

    const normalizedRow = normalizeStoredRow(migratedRow);
    if (normalizedRow) {
      migratedRows.push(normalizedRow);
    }
  }

  return migratedRows;
}

function normalizeStoredRow(row) {
  if (!row || typeof row !== 'object') {
    return null;
  }

  const items = normalizeStoredItems(row);
  const normalized = {
    id: typeof row.id === 'string' && row.id ? row.id : createId(),
    adress: normalizeText(row.adress || row.deliveryAddress),
    tele: normalizePhone(row.tele || row.recipientPhone),
    mottagare: normalizeText(row.mottagare || row.recipientName),
    recipientEmail: normalizeText(row.recipientEmail || row.recipient_email),
    notes: normalizeText(row.notes || row.otherInfo || row.ovrigt || row.ovrligt || row.misc),
    ovrigt: normalizeText(row.notes || row.otherInfo || row.ovrigt || row.ovrligt || row.misc),
    desiredDeliveryDate: normalizeDateOnly(row.desiredDeliveryDate || row.desired_delivery_date),
    desiredDeliveryTime: normalizeTimeValue(row.desiredDeliveryTime || row.desired_delivery_time),
    internalComment: normalizeText(row.internalComment || row.internal_comment),
    priority: normalizeText(row.priority),
    items,
    createdAt: normalizeDateValue(row.createdAt) || new Date().toISOString(),
    updatedHistory: Array.isArray(row.updatedHistory) ? row.updatedHistory.map(normalizeDateValue).filter(Boolean) : [],
    deliveredAt: normalizeDateValue(row.deliveredAt),
    deliveredNote: normalizeText(row.deliveredNote),
    driverId: row.driverId || null,
    trackingEnabled: Boolean(row.trackingEnabled),
    liveTrackingEnabled: Boolean(row.liveTrackingEnabled ?? row.trackingEnabled),
    trackingToken: row.trackingToken || null,
    trackingSessionId: row.trackingSessionId || null,
    trackingUrl: row.trackingUrl || null,
    startedAt: normalizeDateValue(row.startedAt),
    lastStoppedAt: normalizeDateValue(row.lastStoppedAt),
    currentLocation: row.currentLocation || null,
    liveLocation: row.liveLocation || row.currentLocation || null,
    externalWorkOrderNumber: row.externalWorkOrderNumber || null,
    assignedDriverId: row.assignedDriverId || null,
    status: normalizeStatus(row.status, row.deliveredAt),
    smsSentAt: normalizeDateValue(row.smsSentAt),
    smsStatus: normalizeText(row.smsStatus),
    smsError: normalizeText(row.smsError),
    createdBy: row.createdBy || null,
    updatedBy: row.updatedBy || null,
    deliveredBy: row.deliveredBy || null
  };

  if (!normalized.adress || !normalized.tele || !normalized.mottagare || !normalized.items.length) {
    return null;
  }

  return normalized;
}

function normalizeStoredItems(row) {
  if (Array.isArray(row.items)) {
    return row.items.map(normalizeStoredItem).filter(Boolean);
  }

  const legacyItem = normalizeStoredItem({
    antal: row.antal,
    artikel: row.artikel || row.modell
  });

  return legacyItem ? [legacyItem] : [];
}

function normalizeStoredItem(item) {
  if (!item || typeof item !== 'object') {
    return null;
  }

  const antal = normalizeText(item.antal);
  const artikel = normalizeText(item.artikel || item.modell);
  const workOrderNumber = normalizeText(item.workOrderNumber || item.work_order_number);

  if (!antal || !artikel) {
    return null;
  }

  return { antal, artikel, workOrderNumber };
}

function normalizeDateValue(value) {
  if (!value) {
    return null;
  }

  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function normalizeDateOnly(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return normalizeText(value);
  }
  return date.toISOString().slice(0, 10);
}

function normalizeTimeValue(value) {
  return normalizeText(value).slice(0, 5);
}

function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || window.location.protocol === 'file:') {
    return;
  }

  if (shouldDisableServiceWorker()) {
    return;
  }

  const version = String(appConfig.assetVersion || Date.now());
  navigator.serviceWorker
    .register(`./sw.js?v=${encodeURIComponent(version)}`, { scope: './' })
    .catch((error) => console.error('Kunde inte registrera service worker:', error));
}

function shouldDisableServiceWorker() {
  if (!appConfig.serviceWorker?.enabled) {
    return true;
  }

  if (window.location.protocol === 'file:') {
    return true;
  }

  if (!appConfig.serviceWorker?.disableOnLocalOrigins) {
    return false;
  }

  const host = window.location.hostname;
  return host === 'localhost' || host === '127.0.0.1';
}

async function resetLocalServiceWorker() {
  try {
    await core.resetLocalServiceWorkerState?.();
  } catch (error) {
    console.error('Kunde inte rensa lokal service worker-state:', error);
  }
}

async function requestNotificationPermission() {
  if (!state.permissions.canReceiveNotifications || !window.StuckbemaPush) {
    return;
  }

  const status = notificationBtn?.querySelector('strong');
  if (status) {
    status.textContent = '...';
  }

  try {
    setNotificationMessage('Aktiverar notiser...', '');
    await window.StuckbemaPush.registerPushDevice();
    setNotificationMessage('Notiser aktiverade.', 'success');
    notifyUser('Notiser aktiverade', 'Du kan nu ta emot leveransnotiser.');
  } catch (error) {
    console.error('Kunde inte aktivera notiser:', error);
    setNotificationMessage(error.userMessage || error.message || 'Kunde inte aktivera notiser.', 'error');
  }

  await syncNotificationButton();
}

async function disableNotifications() {
  try {
    setNotificationMessage('Stänger av notiser...', '');
    await window.StuckbemaPush?.unregisterPushDevice?.();
    setNotificationMessage('Notiser avstängda.', 'success');
  } catch (error) {
    console.error('Kunde inte stänga av notiser:', error);
    setNotificationMessage(error.userMessage || error.message || 'Kunde inte stänga av notiser.', 'error');
  }
  await syncNotificationButton();
}

async function sendNotificationTest() {
  try {
    setNotificationMessage('Skickar testnotis...', '');
    const result = await window.StuckbemaPush?.sendTestNotification?.();
    setNotificationMessage(`Testnotis skickad. Skickade: ${result?.result?.sent || 0}.`, 'success');
  } catch (error) {
    console.error('Kunde inte skicka testnotis:', error);
    setNotificationMessage(error.userMessage || error.message || 'Kunde inte skicka testnotis.', 'error');
  }
  await syncNotificationButton();
}

async function openNotificationPanel() {
  if (!notificationPanel) return;
  notificationPanel.hidden = false;
  await syncNotificationButton();
}

function closeNotificationPanel() {
  if (notificationPanel) {
    notificationPanel.hidden = true;
  }
}

function setNotificationMessage(message, type = '') {
  if (!notificationMessage) return;
  notificationMessage.textContent = message;
  notificationMessage.dataset.type = type;
}

function updateNotificationUi(pushState = {}) {
  const enabled = pushState.enabled === true;
  const supported = pushState.supported !== false;
  const configured = pushState.configured === true && Boolean(pushState.publicKey);
  if (notificationStatusText) {
    if (!supported) {
      notificationStatusText.textContent = 'Push stöds inte i denna miljö.';
    } else if (pushState.permission === 'denied') {
      notificationStatusText.textContent = 'Notiser är blockerade. Ändra behörigheten i webbläsarens inställningar.';
    } else if (!configured) {
      notificationStatusText.textContent = pushState.message || 'Push-notiser är inte aktiverade på servern ännu.';
    } else {
      notificationStatusText.textContent = enabled ? 'Notiser är aktiva.' : 'Notiser är inte aktiverade på denna enhet.';
    }
  }
  if (notificationEnabledText) notificationEnabledText.textContent = enabled ? 'På' : 'Av';
  if (notificationPlatformText) notificationPlatformText.textContent = pushState.platform || 'Okänd';
  if (notificationPermissionText) notificationPermissionText.textContent = pushState.permission || 'Okänd';
  if (notificationProviderText) notificationProviderText.textContent = pushState.provider || 'Okänd';
  if (notificationEnableBtn) notificationEnableBtn.disabled = !supported || !configured || pushState.permission === 'denied';
  if (notificationDisableBtn) notificationDisableBtn.disabled = !enabled;
  if (notificationTestBtn) notificationTestBtn.disabled = !enabled || !configured;
}

async function syncNotificationButton() {
  if (!notificationBtn || !state.permissions.canReceiveNotifications) {
    return;
  }

  let pushState = {};
  try {
    pushState = await window.StuckbemaPush?.syncNotificationState?.() || {};
  } catch (error) {
    console.error('Kunde inte synka notisstatus:', error);
  }

  updateNotificationUi(pushState);
  notificationBtn.hidden = false;
  const status = notificationBtn.querySelector('strong');
  if (status) {
    if (pushState.permission === 'denied') {
      status.textContent = 'Blockerad';
    } else if (pushState.configured === false || !pushState.publicKey) {
      status.textContent = 'Ej aktiv';
    } else if (pushState.enabled || window.StuckbemaPush?.hasPushSubscription?.()) {
      status.textContent = 'På';
    } else if (pushState.supported === false) {
      status.textContent = 'Saknas';
    } else {
      status.textContent = 'Av';
    }
  }

  notificationBtn.classList.toggle('is-active', pushState.enabled === true);
}

function notifyUser(title, body) {
  if (!('Notification' in window) || Notification.permission !== 'granted') {
    return;
  }

  try {
    new Notification(title, {
      body,
      icon: './icons/icon-192.png',
      tag: 'stuckbema-leverans'
    });
  } catch (error) {
    console.error('Kunde inte visa lokal notis:', error);
  }
}

function createPdf() {
  const rowsForExport = getFilteredRows();
  if (!rowsForExport.length) {
    window.alert('Det finns inga leveranser att skapa PDF av.');
    return;
  }

  const pdfBytes = buildPdf(rowsForExport);
  const blob = new Blob([pdfBytes], { type: 'application/pdf' });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement('a');
  anchor.href = url;
  anchor.download = `stuckbema-leveransordrar-${dateStamp()}.pdf`;
  document.body.appendChild(anchor);
  anchor.click();
  anchor.remove();
  lifecycle.timeout(() => URL.revokeObjectURL(url), 0);
}

function dateStamp() {
  const now = new Date();
  return [
    now.getFullYear(),
    String(now.getMonth() + 1).padStart(2, '0'),
    String(now.getDate()).padStart(2, '0'),
    '-',
    String(now.getHours()).padStart(2, '0'),
    String(now.getMinutes()).padStart(2, '0')
  ].join('');
}

function buildPdf(rows) {
  const pageWidth = 595;
  const pageHeight = 842;
  const left = 40;
  const top = 800;
  const bottom = 50;
  const lineHeight = 14;
  const pages = [];
  let lines = [];
  let y = top;

  const pushLine = (text, font = 'F1', size = 10) => {
    const wrapped = wrapText(text, font === 'F2' ? 72 : 88);
    wrapped.forEach((entry) => {
      if (y < bottom) {
        pages.push(lines.join('\n'));
        lines = [];
        y = top;
      }

      lines.push(`BT /${font} ${size} Tf 1 0 0 1 ${left} ${y} Tm (${escapePdfText(entry)}) Tj ET`);
      y -= lineHeight + (size > 12 ? 4 : 0);
    });
  };

  pushLine('Stuckbema Leveransdokument', 'F2', 18);
  y -= 4;
  pushLine(`Skapad PDF: ${formatDateTime(new Date().toISOString())}`);
  y -= 8;

  rows.forEach((row, index) => {
    pushLine(`Leverans ${index + 1}`, 'F2', 13);
    pushLine(`Mottagare: ${row.mottagare}`);
    pushLine(`Adress: ${row.adress}`);
    pushLine(`Tele: ${row.tele}`);
    pushLine(`Önskad leverans: ${row.desiredDeliveryDate || '—'} ${row.desiredDeliveryTime || ''}`);
    pushLine(`Övrigt: ${row.ovrigt || '—'}`);
    pushLine(`Intern kommentar: ${row.internalComment || '—'}`);
    pushLine('Artiklar:');
    row.items.forEach((item) => {
      pushLine(`- ${item.antal} x ${item.artikel}`);
    });
    pushLine(`Status: ${getStatusLabel(row.status)}`);
    pushLine(`Leveransinfo: ${row.deliveredNote || '—'}`);
    pushLine(`Skapad: ${formatDateTime(row.createdAt)}`);
    pushLine(`Ändringar: ${row.updatedHistory.length ? row.updatedHistory.map(formatDateTime).join(' | ') : '—'}`);
    pushLine(`Levererad tid: ${row.deliveredAt ? formatDateTime(row.deliveredAt) : '—'}`);
    y -= 6;
    pushLine('--------------------------------------------------------------------------');
    y -= 6;
  });

  if (lines.length) {
    pages.push(lines.join('\n'));
  }

  return assemblePdf(pages, pageWidth, pageHeight);
}

function wrapText(text, maxChars) {
  const words = normalizeText(text).split(/\s+/).filter(Boolean);
  if (!words.length) {
    return [''];
  }

  const result = [];
  let current = '';

  words.forEach((word) => {
    if (word.length > maxChars) {
      if (current) {
        result.push(current);
        current = '';
      }

      for (let index = 0; index < word.length; index += maxChars) {
        result.push(word.slice(index, index + maxChars));
      }

      return;
    }

    const next = current ? `${current} ${word}` : word;
    if (next.length > maxChars) {
      result.push(current);
      current = word;
    } else {
      current = next;
    }
  });

  if (current) {
    result.push(current);
  }

  return result;
}

function escapePdfText(text) {
  const encoded = encodeWinAnsi(text);
  let output = '';

  for (let index = 0; index < encoded.length; index += 1) {
    const char = encoded[index];
    const code = encoded.charCodeAt(index);

    if (char === '\\' || char === '(' || char === ')') {
      output += `\\${char}`;
    } else if (code < 32 || code > 126) {
      output += `\\${code.toString(8).padStart(3, '0')}`;
    } else {
      output += char;
    }
  }

  return output;
}

function encodeWinAnsi(text) {
  const map = {
    '€': 128,
    '‚': 130,
    'ƒ': 131,
    '„': 132,
    '…': 133,
    '†': 134,
    '‡': 135,
    'ˆ': 136,
    '‰': 137,
    'Š': 138,
    '‹': 139,
    'Œ': 140,
    'Ž': 142,
    '‘': 145,
    '’': 146,
    '“': 147,
    '”': 148,
    '•': 149,
    '–': 150,
    '—': 151,
    '˜': 152,
    '™': 153,
    'š': 154,
    '›': 155,
    'œ': 156,
    'ž': 158,
    'Ÿ': 159
  };

  let output = '';

  for (const char of String(text)) {
    const code = char.charCodeAt(0);
    if (map[char] !== undefined) {
      output += String.fromCharCode(map[char]);
    } else if (code <= 255) {
      output += String.fromCharCode(code);
    } else {
      output += '?';
    }
  }

  return output;
}

function assemblePdf(pageContents, pageWidth, pageHeight) {
  const objects = [];
  const pageObjectNumbers = [];
  let currentObject = 5;

  pageContents.forEach((content) => {
    const contentObj = currentObject;
    const pageObj = currentObject + 1;
    currentObject += 2;

    objects[contentObj] = `<< /Length ${content.length} >>\nstream\n${content}\nendstream`;
    objects[pageObj] = `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageWidth} ${pageHeight}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ${contentObj} 0 R >>`;
    pageObjectNumbers.push(pageObj);
  });

  objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
  objects[2] = `<< /Type /Pages /Count ${pageObjectNumbers.length} /Kids [${pageObjectNumbers.map((number) => `${number} 0 R`).join(' ')}] >>`;
  objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
  objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

  let pdf = '%PDF-1.4\n%ÿÿÿÿ\n';
  const offsets = [0];

  for (let index = 1; index < objects.length; index += 1) {
    if (!objects[index]) {
      continue;
    }

    offsets[index] = pdf.length;
    pdf += `${index} 0 obj\n${objects[index]}\nendobj\n`;
  }

  const xrefOffset = pdf.length;
  pdf += `xref\n0 ${objects.length}\n`;
  pdf += '0000000000 65535 f \n';

  for (let index = 1; index < objects.length; index += 1) {
    if (!objects[index]) {
      continue;
    }

    pdf += `${String(offsets[index]).padStart(10, '0')} 00000 n \n`;
  }

  pdf += `trailer\n<< /Size ${objects.length} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;

  const bytes = new Uint8Array(pdf.length);
  for (let index = 0; index < pdf.length; index += 1) {
    bytes[index] = pdf.charCodeAt(index) & 255;
  }

  return bytes;
}

function createId() {
  if (globalThis.crypto?.randomUUID) {
    return globalThis.crypto.randomUUID();
  }

  if (globalThis.crypto?.getRandomValues) {
    const buffer = new Uint8Array(16);
    globalThis.crypto.getRandomValues(buffer);
    return Array.from(buffer, (byte) => byte.toString(16).padStart(2, '0')).join('');
  }

  return `row-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
})();
