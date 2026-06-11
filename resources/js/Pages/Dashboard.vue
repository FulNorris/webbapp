<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
  user: { type: Object, required: true },
  orders: { type: Array, default: () => [] },
  users: { type: Array, default: () => [] },
  purchases: { type: Array, default: () => [] },
  drivers: { type: Array, default: () => [] },
  recipients: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
  settings: { type: Object, required: true },
  roles: { type: Array, default: () => [] },
  permissions: { type: Object, default: () => ({}) },
  messages: { type: Object, default: () => ({ inbox: [], outbox: [], deleted: [], unreadCount: 0 }) },
  admin: {
    type: Object,
    default: () => ({
      roleMatrix: [],
      logs: [],
      workOrders: [],
      articles: [],
      customers: [],
      drivers: [],
      trackingLinks: [],
      pushSubscriptions: [],
      systemStatus: {},
      apiEndpoints: [],
    }),
  },
  stats: { type: Object, required: true },
  push: { type: Object, default: () => ({ enabled: false, publicKey: null, subscriptionCount: 0 }) },
});

const page = usePage();
const activeTab = ref('orders');
const deliveryFilter = ref('active');
const deliveryOverviewTab = ref('overview');
const orderDialog = ref(false);
const workOrderDetailDialog = ref(false);
const selectedWorkOrderOrderId = ref(null);
const purchaseDialog = ref(false);
const userDialog = ref(false);
const settingsDialog = ref(false);
const profileDialog = ref(false);
const adminTab = ref('overview');
const editingOrderId = ref(null);
const editingPurchaseId = ref(null);
const editingUserId = ref(null);
const tempPasswordVisible = ref(false);
const topMenuOpen = ref(false);
const topMenuRef = ref(null);
const notificationMenuOpen = ref(false);
const notificationMenuRef = ref(null);
const logSearch = ref('');
const logStatus = ref('');
const logModule = ref('');
const hiddenLogKeys = ref([]);
const adminDetailDialog = ref(false);
const adminDetail = ref(null);
const adminWorkOrderSubTab = ref('external');
const selectedExternalWorkOrders = ref([]);
const selectedInternalWorkOrders = ref([]);
const workOrderDeleteDialog = ref(false);
const pendingWorkOrderDeleteType = ref('external');
const messageTab = ref('inbox');
const profileUserSearch = ref('');
const selectedProfileUserId = ref(props.user.id);
const recipientAutocompleteOpen = ref(false);
const recipientAutocompleteRef = ref(null);
const purchaseRecipientAutocompleteOpen = ref(false);
const purchaseRecipientAutocompleteRef = ref(null);
const pushState = ref({
  enabled: false,
  supported: false,
  permission: typeof Notification === 'undefined' ? 'unsupported' : Notification.permission,
  busy: false,
});
const visibilityState = ref(props.user.visibility || 'offline');
const activeTrackingOrderId = ref(null);
const locationWatchId = ref(null);
const locationIntervalId = ref(null);
const latestTrackingPosition = ref(null);
const lastLocationSentAt = ref(0);
const lastLocation = ref(null);
const visibilityLocationActive = ref(false);
const locationRequestInFlight = ref(false);

const statusLabels = {
  created: 'Skapad',
  assigned: 'Tilldelad',
  ongoing: 'Pågår',
  paused: 'Pausad',
  packed: 'Packad',
  delivered: 'Levererad',
  cancelled: 'Avbruten',
};

const purchaseStatusLabels = {
  planned: 'Planerad',
  ordered: 'Beställd',
  received: 'Mottagen',
  cancelled: 'Avbruten',
};

const purchaseStatusOptions = [
  { label: 'Planerad', value: 'planned' },
  { label: 'Beställd', value: 'ordered' },
  { label: 'Mottagen', value: 'received' },
  { label: 'Avbruten', value: 'cancelled' },
];

const roleOptions = computed(() => props.roles.map((role) => (typeof role === 'string' ? { label: role, value: role } : role)));
const adminUserSort = ref({ field: 'name', direction: 'asc' });
const adminUserSearch = ref('');
const mainUserSearch = ref('');
const canOpenAdmin = computed(() => ['users.view', 'settings.view', 'logs.view', 'system.view_status'].some((permission) => can(permission)));
const canCreateDeliveryPdf = computed(() => ['firmatecknare', 'admin', 'arbetsledare', 'personal', 'forare'].includes(props.user.roleKey || props.user.role));
const canMessageAllUsers = computed(() => can('system.full_access'));
const messageRecipientOptions = computed(() => {
  const users = props.users
    .filter((user) => user.id !== props.user.id && user.active)
    .map((user) => ({
      ...user,
      name: user.name || user.email || 'Namnlös användare',
    }));

  return canMessageAllUsers.value
    ? [{ id: '__all__', name: 'Alla aktiva användare', email: '', roleLabel: 'Massutskick' }, ...users]
    : users;
});
const timeOptions = Array.from({ length: 24 * 60 }, (_, index) => {
  const hours = String(Math.floor(index / 60)).padStart(2, '0');
  const minutes = String(index % 60).padStart(2, '0');
  const value = `${hours}:${minutes}`;
  return { label: value, value };
});
const availablePermissionOptions = computed(() => {
  const permissions = new Set();
  (props.admin.roleMatrix || []).forEach((role) => {
    Object.keys(role.permissions || {}).forEach((permission) => permissions.add(permission));
    (role.allowedPermissions || []).forEach((permission) => permissions.add(permission));
  });

  return Array.from(permissions)
    .sort((a, b) => a.localeCompare(b, 'sv'))
    .map((permission) => ({ label: permission, value: permission }));
});
const pdfMenuItems = computed(() => [
  { label: 'Arbetsorder - aktiva', icon: 'pi pi-th-large', view: 'work-orders', status: 'active' },
  { label: 'Arbetsorder - alla', icon: 'pi pi-list', view: 'work-orders', status: 'all' },
  { label: 'Leveranser - aktiva', icon: 'pi pi-truck', view: 'deliveries', status: 'active' },
  { label: 'Leveranser - levererade', icon: 'pi pi-check-circle', view: 'deliveries', status: 'delivered' },
  { label: 'Leveranser - alla', icon: 'pi pi-box', view: 'deliveries', status: 'all' },
]);
const productLookup = computed(() => {
  const lookup = new Map();
  props.products.forEach((product) => {
    const sku = String(product.sku || '').trim();
    const title = String(product.title || '').trim();
    if (!sku && !title) return;

    for (const candidate of productKeyCandidates(sku)) {
      if (!lookup.has(candidate) || productHasImage(product)) {
        lookup.set(candidate, product);
      }
    }

    for (const candidate of [normalizeKey(title), compactProductKey(title)]) {
      if (!candidate) continue;
      if (!lookup.has(candidate) || productHasImage(product)) {
        lookup.set(candidate, product);
      }
    }
  });
  return lookup;
});
const recipientSuggestions = computed(() => {
  const suggestions = new Map();

  props.recipients.forEach((recipient) => {
    const name = String(recipient.name || recipient.value || '').trim();
    if (!name || suggestions.has(normalizeKey(name))) return;
    suggestions.set(normalizeKey(name), {
      id: recipient.id || name,
      name,
      value: name,
      phone: recipient.phone || '',
      address: recipient.address || '',
      source: recipient.sourceLabel || recipient.source || 'system',
    });
  });

  return Array.from(suggestions.values()).sort((a, b) => a.value.localeCompare(b.value, 'sv'));
});
const sortedAdminUsers = computed(() => {
  const { field, direction } = adminUserSort.value;
  const multiplier = direction === 'desc' ? -1 : 1;
  const query = normalizeKey(adminUserSearch.value);

  return props.users.filter((user) => {
    if (!query) return true;

    return [
      user.name,
      user.email,
      user.phone,
      user.roleLabel,
      user.role,
    ].some((value) => normalizeKey(value).includes(query));
  }).sort((a, b) => {
    const aValue = adminUserSortValue(a, field);
    const bValue = adminUserSortValue(b, field);

    if (typeof aValue === 'number' && typeof bValue === 'number') {
      return (aValue - bValue) * multiplier;
    }

    return String(aValue || '').localeCompare(String(bValue || ''), 'sv', { sensitivity: 'base' }) * multiplier;
  });
});
const filteredMainUsers = computed(() => {
  const query = normalizeKey(mainUserSearch.value);

  return props.users.filter((user) => {
    if (!query) return true;

    return [
      user.name,
      user.email,
      user.phone,
      user.roleLabel,
      user.role,
      user.visibility,
      user.active ? 'aktiv ja' : 'inaktiv nej',
    ].some((value) => normalizeKey(value).includes(query));
  });
});
const editingOrder = computed(() => props.orders.find((order) => order.id === editingOrderId.value) || null);
const canUndoDeliveredOrder = computed(() => Boolean(editingOrder.value && editingOrder.value.status === 'delivered' && can('deliveries.update_status')));
const selectedWorkOrderOrder = computed(() => props.orders.find((order) => order.id === selectedWorkOrderOrderId.value) || null);
const selectedWorkOrderCard = computed(() => (selectedWorkOrderOrder.value ? buildDeliveryCard(selectedWorkOrderOrder.value) : null));
const selectedWorkOrderPurchases = computed(() => {
  const numbers = deliveryWorkOrderNumbers(selectedWorkOrderOrder.value);
  if (!numbers.length) return [];

  const lookup = new Set(numbers.map((number) => normalizeKey(number)));

  return props.purchases.filter((purchase) => lookup.has(normalizeKey(purchase.workOrderNumber)));
});
const activePurchases = computed(() => props.purchases.filter((purchase) => purchase.status !== 'received'));
const receivedPurchases = computed(() => props.purchases.filter((purchase) => purchase.status === 'received'));

const visibleRecipientSuggestions = computed(() => {
  const query = normalizeKey(orderForm.mottagare);
  if (!query) return [];

  return recipientSuggestions.value
    .filter((suggestion) => normalizeKey(suggestion.value).startsWith(query) || normalizeKey(suggestion.value).includes(query))
    .slice(0, 10);
});
const visiblePurchaseRecipientSuggestions = computed(() => {
  const query = normalizeKey(purchaseForm.recipient_name);
  if (!query) return [];

  return recipientSuggestions.value
    .filter((suggestion) => normalizeKey(suggestion.value).startsWith(query) || normalizeKey(suggestion.value).includes(query))
    .slice(0, 10);
});
const purchaseWorkOrderNumbers = computed(() => {
  const numbers = new Set();

  (props.admin.workOrders || []).forEach((workOrder) => {
    if (workOrder.hiddenAt) return;

    const number = String(workOrder.workOrderNumber || '').trim();
    if (number) numbers.add(number);
  });

  props.orders.forEach((order) => {
    const internalNumber = String(order.internalWorkOrderNumber || '').trim();
    if (internalNumber) numbers.add(internalNumber);

    (order.items || []).forEach((item) => {
      const itemNumber = String(item.workOrderNumber || '').trim();
      if (itemNumber) numbers.add(itemNumber);
    });
  });

  props.purchases.forEach((purchase) => {
    const number = String(purchase.workOrderNumber || '').trim();
    if (number) numbers.add(number);
  });

  return Array.from(numbers).sort((a, b) => a.localeCompare(b, 'sv', { numeric: true, sensitivity: 'base' }));
});
const addressSuggestions = computed(() => {
  const suggestions = new Map();
  props.orders.forEach((order) => {
    const address = (order.adress || '').trim();
    if (!address) return;
    suggestions.set(normalizeKey(address), address);
  });
  return Array.from(suggestions.values()).sort((a, b) => a.localeCompare(b, 'sv'));
});
const pushLabel = computed(() => {
  if (!props.push.enabled) return 'Push ej aktiv';
  if (!pushState.value.supported) return 'Push saknas';
  if (pushState.value.permission === 'denied') return 'Push blockerad';
  return pushState.value.enabled ? 'Push aktiv' : 'Aktivera push';
});
const activeDeliveryStatuses = ['created', 'assigned', 'ongoing', 'paused', 'packed'];
const deliverySlotStartMinutes = 7 * 60;
const deliverySlotStepMinutes = 30;
const filteredOrders = computed(() => {
  if (deliveryFilter.value === 'active') {
    return props.orders.filter((order) => activeDeliveryStatuses.includes(order.status));
  }

  if (deliveryFilter.value === 'delivered') {
    return props.orders.filter((order) => order.status === 'delivered');
  }

  return props.orders;
});
const deliveryCards = computed(() => filteredOrders.value.map(buildDeliveryCard));
const deliveryFilterLabel = computed(() => {
  if (deliveryFilter.value === 'active') return 'Aktiva leveranser';
  if (deliveryFilter.value === 'delivered') return 'Levererade leveranser';
  return 'Alla leveranser';
});
const visibilityOnline = computed(() => visibilityState.value === 'online');
const visibleAdminLogs = computed(() => {
  const hidden = new Set(hiddenLogKeys.value);
  return (props.admin.logs || []).filter((row) => !hidden.has(logRowKey(row)));
});
const hiddenLogCount = computed(() => Math.max(0, (props.admin.logs || []).length - visibleAdminLogs.value.length));
const logStatusOptions = computed(() => Array.from(new Set(visibleAdminLogs.value.map((row) => row.status).filter(Boolean)))
  .sort((a, b) => a.localeCompare(b, 'sv'))
  .map((status) => ({ label: status, value: status })));
const logModuleOptions = computed(() => Array.from(new Set(visibleAdminLogs.value.map((row) => row.module).filter(Boolean)))
  .sort((a, b) => a.localeCompare(b, 'sv'))
  .map((module) => ({ label: module, value: module })));
const filteredLogs = computed(() => {
  const query = normalizeKey(logSearch.value);

  return visibleAdminLogs.value.filter((row) => {
    const matchesQuery = !query || normalizeKey(Object.values(row).join(' ')).includes(query);
    const matchesStatus = !logStatus.value || row.status === logStatus.value;
    const matchesModule = !logModule.value || row.module === logModule.value;
    return matchesQuery && matchesStatus && matchesModule;
  });
});
const filteredProfileUsers = computed(() => {
  const query = normalizeKey(profileUserSearch.value);

  return props.users
    .filter((user) => {
      if (!query) return true;

      return normalizeKey([
        user.name,
        user.email,
        user.phone,
        user.roleLabel,
      ].filter(Boolean).join(' ')).includes(query);
    })
    .sort((a, b) => String(a.name || a.email || '').localeCompare(String(b.name || b.email || ''), 'sv'));
});
const selectedProfileUser = computed(() => (
  props.users.find((user) => user.id === selectedProfileUserId.value)
  || props.users.find((user) => user.id === props.user.id)
  || props.user
));
const externalWorkOrderRows = computed(() => (props.admin.workOrders || []).filter((row) => row.sourceType === 'external'));
const internalWorkOrderRows = computed(() => (props.admin.workOrders || []).filter((row) => row.sourceType === 'internal'));
const selectedWorkOrderRowsForDelete = computed(() => (
  pendingWorkOrderDeleteType.value === 'internal' ? selectedInternalWorkOrders.value : selectedExternalWorkOrders.value
));
const canDeleteWorkOrders = computed(() => can('work_orders.delete') && ['firmatecknare', 'admin'].includes(props.user.roleKey || props.user.role));
const notificationItems = computed(() => {
  const inbox = props.messages?.inbox || [];
  const unread = inbox.filter((message) => !message.readAt);

  return (unread.length ? unread : inbox)
    .slice(0, 6)
    .map((message) => ({
      ...message,
      title: message.subject || 'Meddelande',
      text: message.body || '',
      from: message.senderName || 'System',
      isUnread: !message.readAt,
    }));
});
const notificationCount = computed(() => Number(props.messages?.unreadCount || 0));

const flash = computed(() => page.props.flash || {});
watch(
  () => flash.value.tempPassword,
  (password) => {
    if (password) tempPasswordVisible.value = true;
  },
  { immediate: true },
);

const orderForm = useForm(blankOrder());
const purchaseForm = useForm(blankPurchase());
const purchaseSearch = ref({
  query: '',
  results: [],
  errors: [],
  loading: false,
  searched: false,
  message: '',
});
const purchaseSearchLocation = ref({
  lat: null,
  lng: null,
  requested: false,
});
const purchaseSearchTimer = ref(null);
const purchaseSearchAbort = ref(null);
const userForm = useForm(blankUser());
const profileForm = useForm({
  _token: page.props.csrfToken,
  email: props.user.email || '',
  phone: props.user.phone || '',
  profileImage: null,
});
const messageForm = useForm({
  _token: page.props.csrfToken,
  recipientId: '',
  subject: '',
  body: '',
});
const settingsForm = useForm({
  _token: page.props.csrfToken,
  appTitle: props.settings.appTitle,
  companyName: props.settings.companyName,
  deliveryTitle: props.settings.deliveryTitle,
  supportEmail: props.settings.supportEmail || '',
  supportPhone: props.settings.supportPhone || '',
  orderNumberPrefix: props.settings.orderNumberPrefix,
  adminMessage: props.settings.adminMessage || '',
  allowPushNotifications: props.settings.allowPushNotifications,
});

const invalidOrderItem = computed(() => orderForm.items.find((item) => item.workOrderMatchWarning));
const deliveryTimeConflict = computed(() => {
  const date = String(orderForm.desiredDeliveryDate || '').trim();
  const time = String(orderForm.desiredDeliveryTime || '').trim();
  if (!date || !time) return null;

  return props.orders.find((order) => (
    order.id !== editingOrderId.value
    && String(order.desiredDeliveryDate || '').trim() === date
    && String(order.desiredDeliveryTime || '').trim() === time
    && order.status !== 'cancelled'
  )) || null;
});
const cannotSaveOrder = computed(() => orderForm.processing || Boolean(invalidOrderItem.value));
const workOrderArticleCache = new Map();
const workOrderArticleTimers = new WeakMap();
const productPreviewCache = new Map();
const productPreviewTimers = new WeakMap();

watch(
  () => props.products,
  () => {
    productPreviewCache.clear();
    orderForm.items.forEach(updateProductPreview);
  },
);

function orderItemError(index, field) {
  return orderForm.errors[`items.${index}.${field}`] || '';
}

onMounted(() => {
  activateTabFromUrl();
  loadHiddenLogKeys();
  syncPushState();
  document.addEventListener('pointerdown', handleDocumentPointerDown);
});

onBeforeUnmount(() => {
  stopLocationWatch();
  orderForm.items.forEach(clearProductPreviewTimer);
  clearPurchaseSearch();
  document.removeEventListener('pointerdown', handleDocumentPointerDown);
});

function pushSupported() {
  return 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
}

async function syncPushState() {
  pushState.value.supported = pushSupported();
  pushState.value.permission = pushState.value.supported ? Notification.permission : 'unsupported';

  if (!pushState.value.supported || !props.push.enabled) {
    pushState.value.enabled = false;
    return;
  }

  const registration = await navigator.serviceWorker.getRegistration('/').catch(() => null);
  const subscription = await registration?.pushManager?.getSubscription?.();
  pushState.value.enabled = Boolean(subscription);
}

async function registerPushServiceWorker() {
  const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' });
  return navigator.serviceWorker.ready.then(() => registration);
}

function activateTabFromUrl() {
  const requestedTab = new URLSearchParams(window.location.search).get('tab');
  if (requestedTab === 'purchases') {
    activeTab.value = 'purchases';
  } else if (requestedTab === 'orders') {
    activeTab.value = 'orders';
  } else if (requestedTab === 'users' && can('users.view')) {
    activeTab.value = 'users';
  }
}

function urlBase64ToUint8Array(value) {
  const padding = '='.repeat((4 - (value.length % 4)) % 4);
  const base64 = `${value}${padding}`.replace(/-/g, '+').replace(/_/g, '/');
  const raw = window.atob(base64);
  const output = new Uint8Array(raw.length);
  for (let index = 0; index < raw.length; index += 1) {
    output[index] = raw.charCodeAt(index);
  }
  return output;
}

function subscriptionPayload(subscription) {
  const json = subscription.toJSON();
  return {
    endpoint: json.endpoint,
    expirationTime: json.expirationTime,
    keys: json.keys,
    permission: Notification.permission,
    userAgent: navigator.userAgent,
  };
}

function normalizeKey(value) {
  return String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLocaleLowerCase('sv-SE');
}

function compactProductKey(value) {
  return normalizeKey(value).replace(/[^a-z0-9]/g, '');
}

function productHasImage(product) {
  return Boolean(product?.imageUrl);
}

function productKeyCandidates(value) {
  const raw = String(value || '').trim();
  const key = normalizeKey(raw);
  const compact = compactProductKey(raw);
  const firstToken = normalizeKey(raw.split(/[\s-]/)[0] || raw);
  const candidates = [key, firstToken, compact];
  const tlMatch = compact.match(/^tlp?(\d+)/);

  if (tlMatch) {
    const digits = tlMatch[1];
    const listNumber = digits.slice(0, 2);
    candidates.push(`sl${digits}`, `sl${listNumber}`, `tl${digits}`);

    if (compact.startsWith('tlp')) {
      candidates.push(`tlp${digits}`, `rp7-${digits}0`, `rp7${digits}0`);
    }
  }

  return [...new Set(candidates.filter(Boolean))];
}

function logRowKey(row) {
  // The time is included so a repeated error with a new timestamp is visible again after a local clear.
  return [
    row.time,
    row.user,
    row.role,
    row.event,
    row.module,
    row.ip,
    row.status,
    row.details,
  ].map((value) => String(value ?? '')).join('|');
}

function adminCellText(value) {
  if (value === null || value === undefined || value === '') return '-';
  if (typeof value === 'boolean') return value ? 'Ja' : 'Nej';
  if (Array.isArray(value)) return value.join(', ');
  if (typeof value === 'object') return JSON.stringify(value, null, 2);
  return String(value);
}

function adminDetailRows(row) {
  if (!row || typeof row !== 'object') return [];
  return Object.entries(row).map(([key, value]) => ({
    key,
    value: adminCellText(value),
  }));
}

function openAdminDetail(section, field, row, value = null) {
  const resolvedValue = value ?? row?.[field];
  adminDetail.value = {
    section,
    field,
    value: adminCellText(resolvedValue),
    rows: adminDetailRows(row),
  };
  adminDetailDialog.value = true;
}

function openAdminSystemDetail(label, value) {
  adminDetail.value = {
    section: 'Systemstatus',
    field: label,
    value: adminCellText(value),
    rows: Object.entries(props.admin.systemStatus || {}).map(([key, item]) => ({
      key,
      value: adminCellText(item),
    })),
  };
  adminDetailDialog.value = true;
}

function loadHiddenLogKeys() {
  try {
    const stored = window.localStorage.getItem('stuckbema.hidden-admin-log-keys');
    hiddenLogKeys.value = stored ? JSON.parse(stored).filter(Boolean) : [];
  } catch {
    hiddenLogKeys.value = [];
  }
}

function persistHiddenLogKeys() {
  try {
    window.localStorage.setItem('stuckbema.hidden-admin-log-keys', JSON.stringify(hiddenLogKeys.value));
  } catch {
    // Local storage can be disabled; the clear still works for the current page state.
  }
}

function applyRecipientSuggestion() {
  const match = recipientSuggestions.value.find((suggestion) => normalizeKey(suggestion.value) === normalizeKey(orderForm.mottagare));
  if (!match) return;

  if (!orderForm.tele && match.phone) orderForm.tele = match.phone;
}

function handleRecipientInput() {
  recipientAutocompleteOpen.value = String(orderForm.mottagare || '').trim().length > 0;
}

function handleRecipientFocus() {
  recipientAutocompleteOpen.value = String(orderForm.mottagare || '').trim().length > 0;
}

function handleRecipientBlur() {
  window.setTimeout(() => {
    applyRecipientSuggestion();
    recipientAutocompleteOpen.value = false;
  }, 140);
}

function selectRecipientSuggestion(suggestion) {
  orderForm.mottagare = suggestion.value;
  orderForm.tele = suggestion.phone || '';
  if (suggestion.address && !orderForm.adress) {
    orderForm.adress = suggestion.address;
  }
  recipientAutocompleteOpen.value = false;
}

function applyPurchaseRecipientSuggestion() {
  const match = recipientSuggestions.value.find((suggestion) => normalizeKey(suggestion.value) === normalizeKey(purchaseForm.recipient_name));
  if (!match) return;

  if (!purchaseForm.recipient_phone && match.phone) purchaseForm.recipient_phone = match.phone;
  if (!purchaseForm.delivery_address && match.address) purchaseForm.delivery_address = match.address;
}

function handlePurchaseRecipientInput() {
  purchaseRecipientAutocompleteOpen.value = String(purchaseForm.recipient_name || '').trim().length > 0;
}

function handlePurchaseRecipientFocus() {
  purchaseRecipientAutocompleteOpen.value = String(purchaseForm.recipient_name || '').trim().length > 0;
}

function handlePurchaseRecipientBlur() {
  window.setTimeout(() => {
    applyPurchaseRecipientSuggestion();
    purchaseRecipientAutocompleteOpen.value = false;
  }, 140);
}

function selectPurchaseRecipientSuggestion(suggestion) {
  purchaseForm.recipient_name = suggestion.value;
  purchaseForm.recipient_phone = suggestion.phone || '';
  if (suggestion.address && !purchaseForm.delivery_address) {
    purchaseForm.delivery_address = suggestion.address;
  }
  purchaseRecipientAutocompleteOpen.value = false;
}

function purchaseWorkOrderMatch(value) {
  const key = normalizeKey(value);
  if (!key) return null;

  const order = props.orders.find((order) => {
    if (normalizeKey(order.internalWorkOrderNumber) === key) return true;

    return (order.items || []).some((item) => normalizeKey(item.workOrderNumber) === key);
  });

  if (order) {
    return {
      recipientName: order.mottagare,
      recipientPhone: order.tele,
      deliveryAddress: order.adress,
    };
  }

  const workOrder = (props.admin.workOrders || []).find((row) => !row.hiddenAt && normalizeKey(row.workOrderNumber) === key);
  if (!workOrder) return null;

  return {
    recipientName: workOrder.recipientName,
    recipientPhone: workOrder.recipientPhone,
    deliveryAddress: workOrder.deliveryAddress,
  };
}

function applyPurchaseWorkOrderDetails() {
  const match = purchaseWorkOrderMatch(purchaseForm.work_order_number);
  if (!match) return;

  if (!purchaseForm.recipient_name && match.recipientName) {
    purchaseForm.recipient_name = match.recipientName;
  }

  if (!purchaseForm.recipient_phone && match.recipientPhone) {
    purchaseForm.recipient_phone = match.recipientPhone;
  }

  if (!purchaseForm.delivery_address && match.deliveryAddress) {
    purchaseForm.delivery_address = match.deliveryAddress;
  }
}

function handlePurchaseWorkOrderInput() {
  purchaseForm.clearErrors('work_order_number');
  applyPurchaseWorkOrderDetails();
}

function handleDocumentPointerDown(event) {
  if (recipientAutocompleteRef.value && !recipientAutocompleteRef.value.contains(event.target)) {
    recipientAutocompleteOpen.value = false;
  }

  if (purchaseRecipientAutocompleteRef.value && !purchaseRecipientAutocompleteRef.value.contains(event.target)) {
    purchaseRecipientAutocompleteOpen.value = false;
  }

  if (topMenuRef.value && !topMenuRef.value.contains(event.target)) {
    topMenuOpen.value = false;
  }

  if (notificationMenuRef.value && !notificationMenuRef.value.contains(event.target)) {
    notificationMenuOpen.value = false;
  }
}

function can(permission) {
  return props.permissions?.['system.full_access'] === true || props.permissions?.[permission] === true;
}

function workOrderSelectionFor(type) {
  return type === 'internal' ? selectedInternalWorkOrders.value : selectedExternalWorkOrders.value;
}

function openWorkOrderDeleteDialog(type) {
  pendingWorkOrderDeleteType.value = type;
  if (!workOrderSelectionFor(type).length) return;

  workOrderDeleteDialog.value = true;
}

function closeWorkOrderDeleteDialog() {
  workOrderDeleteDialog.value = false;
}

function submitWorkOrderDelete(mode) {
  const rows = selectedWorkOrderRowsForDelete.value;
  if (!rows.length) return;

  router.post('/work-orders/bulk-delete', {
    _token: page.props.csrfToken,
    mode,
    internalIds: rows.filter((row) => row.sourceType === 'internal').map((row) => row.id),
    externalIds: rows.filter((row) => row.sourceType === 'external').map((row) => row.id || row.workOrderNumber),
  }, {
    preserveScroll: true,
    onSuccess: () => {
      selectedExternalWorkOrders.value = [];
      selectedInternalWorkOrders.value = [];
      workOrderDeleteDialog.value = false;
    },
  });
}

function roleLabel(user) {
  return user?.roleLabel || roleOptions.value.find((role) => role.value === user?.role)?.label || user?.role || '';
}

function adminUserSortValue(user, field) {
  if (field === 'role') return roleLabel(user);
  if (field === 'status') return user?.active ? 1 : 0;
  if (field === 'createdAt') return user?.createdAt ? Date.parse(user.createdAt) || 0 : 0;

  return user?.[field] || '';
}

function setAdminUserSort(field) {
  adminUserSort.value = {
    field,
    direction: adminUserSort.value.field === field && adminUserSort.value.direction === 'asc' ? 'desc' : 'asc',
  };
}

function adminUserSortIcon(field) {
  if (adminUserSort.value.field !== field) return 'pi pi-sort-alt';

  return adminUserSort.value.direction === 'asc' ? 'pi pi-sort-amount-up-alt' : 'pi pi-sort-amount-down';
}

function openNativePicker(event) {
  const input = event?.currentTarget?.querySelector?.('input') || event?.target;
  input?.focus?.();

  try {
    input?.showPicker?.();
  } catch {
    // Some browsers only allow showPicker during direct pointer interaction.
  }
}

function cleanPhone(value) {
  return String(value || '').replace(/[^\d+]/g, '');
}

function phoneHref(value) {
  const phone = cleanPhone(value);
  return phone ? `tel:${phone}` : null;
}

function smsHref(value) {
  const phone = cleanPhone(value);
  return phone ? `sms:${phone}` : null;
}

function mailHref(value) {
  const email = String(value || '').trim();
  return email ? `mailto:${email}` : null;
}

function mapsHref(address) {
  const query = encodeURIComponent(String(address || '').trim());
  if (!query) return null;

  return `https://www.google.com/maps/search/?api=1&query=${query}`;
}

function deliveryWorkOrderNumber(order) {
  const internalNumber = String(order?.internalWorkOrderNumber || '').trim();
  if (internalNumber) return internalNumber;

  const item = (order?.items || []).find((row) => String(row.workOrderNumber || '').trim());

  return item ? String(item.workOrderNumber || '').trim() : '';
}

function deliveryWorkOrderNumbers(order) {
  if (!order) return [];

  const numbers = new Set();
  const internalNumber = String(order.internalWorkOrderNumber || '').trim();
  if (internalNumber) numbers.add(internalNumber);

  (order.items || []).forEach((item) => {
    const number = String(item.workOrderNumber || '').trim();
    if (number) numbers.add(number);
  });

  return Array.from(numbers);
}

function deliveryWorkOrderLabel(order) {
  const number = deliveryWorkOrderNumber(order);
  if (!number) return '';

  return String(order?.internalWorkOrderNumber || '').trim() ? `Intern arbetsorder: ${number}` : `Arbetsorder: ${number}`;
}

function openWorkOrderDetail(order) {
  if (!deliveryWorkOrderNumber(order)) return;

  selectedWorkOrderOrderId.value = order.id;
  workOrderDetailDialog.value = true;
}

function localDateString(date = new Date()) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function minutesToTime(minutes) {
  const normalized = ((minutes % (24 * 60)) + (24 * 60)) % (24 * 60);
  const hours = String(Math.floor(normalized / 60)).padStart(2, '0');
  const mins = String(normalized % 60).padStart(2, '0');
  return `${hours}:${mins}`;
}

function timeToMinutes(time) {
  const match = String(time || '').match(/^(\d{1,2}):(\d{2})$/);
  if (!match) return null;

  const hours = Number(match[1]);
  const minutes = Number(match[2]);
  if (!Number.isInteger(hours) || !Number.isInteger(minutes) || hours > 23 || minutes > 59) return null;

  return hours * 60 + minutes;
}

function nextDeliverySlot() {
  let date = localDateString();
  let minute = deliverySlotStartMinutes;

  for (let dayOffset = 0; dayOffset < 14; dayOffset += 1) {
    const currentDate = new Date();
    currentDate.setDate(currentDate.getDate() + dayOffset);
    date = localDateString(currentDate);
    const booked = new Set(
      props.orders
        .filter((order) => String(order.desiredDeliveryDate || '').trim() === date && order.status !== 'cancelled')
        .map((order) => timeToMinutes(order.desiredDeliveryTime))
        .filter((value) => value !== null),
    );

    for (minute = deliverySlotStartMinutes; minute < 24 * 60; minute += deliverySlotStepMinutes) {
      if (!booked.has(minute)) {
        return { date, time: minutesToTime(minute) };
      }
    }
  }

  return { date, time: minutesToTime(deliverySlotStartMinutes) };
}

function toggleTopMenu(event) {
  event?.stopPropagation?.();
  topMenuOpen.value = !topMenuOpen.value;
}

function deliveriesPdfUrl(view = 'work-orders', status = 'active', period = 'all') {
  const params = new URLSearchParams({ view, status, period });
  return `/deliveries/pdf?${params.toString()}`;
}

function handlePdfMenuClick(event) {
  if (!canCreateDeliveryPdf.value) {
    event.preventDefault();
    return;
  }

  topMenuOpen.value = false;
}

function parseItemQuantity(value) {
  const match = String(value || '').match(/\d+(?:[,.]\d+)?/);
  return match ? Math.max(0, Math.ceil(Number(match[0].replace(',', '.')) || 0)) : 0;
}

function itemProductSource(item) {
  return String(
    item?.selectedArticle?.article_number
      || item?.product_sku
      || item?.product?.sku
      || item?.artikel
      || item?.article
      || '',
  ).trim();
}

function resolveProductForSource(source, fallback = null) {
  const cacheKey = normalizeKey(source);
  if (!cacheKey) return fallback;
  if (productPreviewCache.has(cacheKey)) return productPreviewCache.get(cacheKey);

  let matchedProduct = productHasImage(fallback) ? fallback : null;
  let fallbackProduct = fallback || null;
  const candidates = productKeyCandidates(source);

  for (const candidate of candidates) {
    const product = productLookup.value.get(candidate);
    if (!product) continue;
    if (productHasImage(product)) {
      matchedProduct = product;
      break;
    }
    fallbackProduct ||= product;
  }

  const resolvedProduct = matchedProduct || fallbackProduct;
  productPreviewCache.set(cacheKey, resolvedProduct || null);
  return resolvedProduct;
}

function productForItem(item) {
  if (!item?.product && !item?.product_sku && !item?.selectedArticle?.article_number && !item?.artikel && !item?.article) {
    return null;
  }

  return resolveProductForSource(itemProductSource(item), item?.product || null);
}

function updateProductPreview(item) {
  const source = itemProductSource(item);
  item.productPreviewSource = source;
  item.productPreview = resolveProductForSource(source, item.product || null);
}

function scheduleProductPreviewUpdate(item) {
  const timer = productPreviewTimers.get(item);
  if (timer) window.clearTimeout(timer);

  const source = itemProductSource(item);
  item.productPreviewSource = source;
  if (source.length < 2) {
    item.productPreview = null;
    return;
  }

  const exactProduct = resolveProductForSource(source, item.product || null);
  if (exactProduct && normalizeKey(exactProduct.sku) === normalizeKey(source)) {
    item.productPreview = exactProduct;
    return;
  }

  item.productPreview = null;
}

function manualProductSuggestions(query) {
  const normalizedQuery = normalizeKey(query);
  const compactQuery = compactProductKey(query);
  if (normalizedQuery.length < 1 && compactQuery.length < 1) {
    return [];
  }

  return props.products
    .map((product) => {
      const sku = String(product.sku || '').trim();
      const title = String(product.title || '').trim();
      const normalizedSku = normalizeKey(sku);
      const normalizedTitle = normalizeKey(title);
      const compactSku = compactProductKey(sku);
      const compactTitle = compactProductKey(title);
      let score = 0;

      if (normalizedSku === normalizedQuery || compactSku === compactQuery) score = 100;
      else if (normalizedSku.startsWith(normalizedQuery) || compactSku.startsWith(compactQuery)) score = 90;
      else if (normalizedTitle === normalizedQuery || compactTitle === compactQuery) score = 85;
      else if (normalizedTitle.startsWith(normalizedQuery) || compactTitle.startsWith(compactQuery)) score = 75;
      else if (normalizedSku.includes(normalizedQuery) || compactSku.includes(compactQuery)) score = 65;
      else if (normalizedTitle.includes(normalizedQuery) || compactTitle.includes(compactQuery)) score = 50;

      return { product, score };
    })
    .filter((row) => row.score > 0)
    .sort((a, b) => {
      if (b.score !== a.score) return b.score - a.score;
      if (productHasImage(b.product) !== productHasImage(a.product)) return productHasImage(b.product) ? 1 : -1;
      return String(a.product.sku || '').localeCompare(String(b.product.sku || ''), 'sv');
    })
    .slice(0, 12)
    .map((row) => row.product);
}

function updateManualProductDropdown(item, open = true) {
  if (usesWorkOrderArticleDropdown(item)) {
    item.manualProductOptions = [];
    item.manualProductDropdownOpen = false;
    return;
  }

  item.manualProductOptions = manualProductSuggestions(item.artikel);
  item.manualProductDropdownOpen = open && item.manualProductOptions.length > 0;
}

function onManualArticleInput(item) {
  item.product = null;
  scheduleProductPreviewUpdate(item);
  updateManualProductDropdown(item, true);
}

function openManualProductDropdown(item) {
  updateManualProductDropdown(item, true);
}

function closeManualProductDropdown(item) {
  window.setTimeout(() => {
    item.manualProductDropdownOpen = false;
  }, 140);
}

function selectManualProduct(item, product) {
  item.artikel = product?.sku || item.artikel;
  item.product = product || null;
  item.productPreview = product || null;
  item.productPreviewSource = product?.sku || '';
  item.manualProductDropdownOpen = false;
  item.manualProductOptions = [];
}

function clearProductPreviewTimer(item) {
  const timer = productPreviewTimers.get(item);
  if (timer) window.clearTimeout(timer);
  productPreviewTimers.delete(item);
}

async function lookupWorkOrderForItem(item) {
  if (item.isInternalWorkOrder) {
    resetWorkOrderArticleState(item, { keepArticleId: true });
    return;
  }

  const number = String(item.workOrderNumber || '').trim();
  if (!number) {
    resetWorkOrderArticleState(item);
    return;
  }

  try {
    item.loadingArticles = true;
    item.workOrderMatchWarning = '';
    item.workOrderArticleInfo = '';
    item.articleOptions = [];
    item.selectedArticle = null;
    item.workOrderArticleId = null;
    const requestedNumber = number;
    const payload = workOrderArticleCache.has(number)
      ? workOrderArticleCache.get(number)
      : await fetchWorkOrderArticles(number);

    if (String(item.workOrderNumber || '').trim() !== requestedNumber) {
      return;
    }

    applyWorkOrderArticles(item, payload);
  } catch {
    item.workOrderMatchWarning = 'Kunde inte hämta artiklar för arbetsordern.';
  } finally {
    item.loadingArticles = false;
  }
}

async function fetchWorkOrderArticles(number) {
  const response = await window.fetch(`/work-orders/${encodeURIComponent(number)}/articles`, {
      headers: { Accept: 'application/json' },
    });
  const payload = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(payload.message || 'Kunde inte hämta artiklar för arbetsordern.');
  }

  workOrderArticleCache.set(number, payload);
  return payload;
}

function scheduleWorkOrderArticleLookup(item) {
  if (item.isInternalWorkOrder) {
    resetWorkOrderArticleState(item, { keepArticleId: true });
    return;
  }

  const timer = workOrderArticleTimers.get(item);
  if (timer) window.clearTimeout(timer);
  workOrderArticleTimers.set(item, window.setTimeout(() => lookupWorkOrderForItem(item), 350));
}

function usesWorkOrderArticleDropdown(item) {
  return String(item?.workOrderNumber || '').trim() !== '' && !item?.isInternalWorkOrder;
}


function applyWorkOrderArticles(item, payload) {
  const options = payload.items || [];
  item.articleOptions = options;
  const workOrderAddress = String(payload.work_order?.address || payload.work_order?.workplace || '').trim();
  if (workOrderAddress && !String(orderForm.adress || '').trim()) {
    orderForm.adress = workOrderAddress;
  }

  if (options.length === 0) {
    item.workOrderMatchWarning = 'Arbetsordern hittades, men inga artiklar finns registrerade.';
    return;
  }

  if (options.length === 1) {
    selectWorkOrderArticle(item, options[0]);
    item.workOrderArticleInfo = '1 artikel hittades och har valts automatiskt.';
    return;
  }

  const currentArticleId = Number(item.workOrderArticleId || item.selectedArticle?.id || item.selectedArticle?.order_item_id || 0);
  const match = options.find((option) => {
    const optionId = Number(option.id || option.order_item_id || 0);
    return (currentArticleId > 0 && optionId === currentArticleId)
      || normalizeKey(option.article_number) === normalizeKey(item.artikel);
  });
  if (match) {
    selectWorkOrderArticle(item, match);
    item.workOrderArticleInfo = '';
    return;
  }

  item.workOrderMatchWarning = item.selectedArticle
    ? ''
    : `${options.length} artiklar finns på arbetsordern. Välj artikel i listan.`;
}

function resetWorkOrderArticleState(item, options = {}) {
  item.articleOptions = [];
  item.selectedArticle = null;
  if (!options.keepArticleId) {
    item.workOrderArticleId = null;
  }
  item.workOrderArticleInfo = '';
  item.workOrderMatchWarning = '';
  item.loadingArticles = false;
}

function selectWorkOrderArticle(item, article) {
  item.selectedArticle = article;
  item.workOrderArticleId = article?.id || article?.order_item_id || null;
  item.artikel = article?.article_number || article?.artikel || '';
  item.product = article?.product || item.product || null;
  if (!item.antal && Number(article?.remaining_quantity || 0) > 0) {
    item.antal = formatQuantity(article.remaining_quantity);
  }
  item.workOrderMatchWarning = '';
  updateProductPreview(item);
}

function onWorkOrderArticleSelected(item) {
  selectWorkOrderArticle(item, item.selectedArticle);
}

function formatQuantity(value) {
  return String(value).replace('.', ',');
}

function blankOrderItem() {
  return {
    artikel: '',
    antal: '',
    workOrderNumber: '',
    workOrderArticleId: null,
    isInternalWorkOrder: false,
    selectedArticle: null,
    product: null,
    productPreview: null,
    productPreviewSource: '',
    manualProductOptions: [],
    manualProductDropdownOpen: false,
    articleOptions: [],
    loadingArticles: false,
    workOrderMatchWarning: '',
    workOrderArticleInfo: '',
  };
}

function buildDeliveryCard(order) {
  const productRows = buildDeliveryProductRows(order);
  const primaryProductRow = productRows.find((row) => row.imageUrl) || productRows[0] || null;

  return {
    order,
    productRows,
    productSummary: deliveryItemSummary(order),
    deliveryDate: deliveryDateText(order),
    primaryImageUrl: primaryProductRow?.imageUrl || '',
    primaryImageAlt: primaryProductRow?.imageAlt || primaryProductRow?.article || order.mottagare || 'Artikelbild',
  };
}

function deliveryItemSummary(order) {
  const items = order.items || [];
  if (!items.length) return order.id || 'Leverans';

  const requested = items.reduce((sum, item) => sum + itemProgress(item).requested, 0);
  return `${items.length} artiklar${requested ? ` · ${requested} st` : ''}`;
}

function buildDeliveryProductRows(order) {
  return (order.items || []).map((item, index) => {
    const product = productForItem(item);
    const progress = itemProgress(item);
    const article = String(item.artikel || item.article || 'Artikel').trim();
    const title = product ? deliveryProductTitle(product, item, article) : article;
    const quantityText = progress.requested
      ? `${progress.requested} st`
      : String(item.antal || item.requestedQuantity || '').trim();
    const deliveredText = progress.delivered ? `${progress.delivered} levererat` : '';

    return {
      key: item.id || `${order.id || 'order'}-${index}-${article}`,
      article,
      title,
      quantityText,
      deliveredText,
      imageUrl: product?.imageUrl || '',
      imageAlt: product?.title || article,
    };
  });
}

function deliveryProductTitle(product, item, article) {
  const rawTitle = String(
    product?.title
      || item.product_title
      || item.title
      || item.name
      || article,
  ).trim();

  const title = rawTitle.replace(/\s*-\s*Gipsstuckaturer\.se\s*$/i, '').trim();

  if (normalizeKey(article) === 'D1' && (!title || normalizeKey(title) === 'D1')) {
    return 'Dörröverstycke - D1';
  }

  return title || article;
}

function deliveryDateText(order) {
  const desired = [order.desiredDeliveryDate, order.desiredDeliveryTime].filter(Boolean).join(' ');
  return desired || formatDate(order.createdAt) || 'Ej planerad';
}

function itemProgress(item) {
  const requested = Number(item.requestedQuantity ?? parseItemQuantity(item.antal));
  const delivered = Number(item.deliveredQuantity ?? parseItemQuantity(item.deliveredAntal));
  return {
    requested,
    delivered,
    remaining: Math.max(0, Number(item.remainingQuantity ?? requested - delivered)),
    complete: requested > 0 && delivered >= requested,
  };
}

function showDeliveryFilter(filter) {
  deliveryFilter.value = filter;
  activeTab.value = 'orders';
}

function showUsersTab() {
  if (!can('users.view')) return;
  activeTab.value = 'users';
}

async function togglePushNotifications() {
  if (!props.push.enabled || pushState.value.busy) return;

  if (pushState.value.enabled) {
    pushState.value.busy = true;
    router.post('/push/test', { _token: page.props.csrfToken }, {
      preserveScroll: true,
      onFinish: () => { pushState.value.busy = false; },
    });
    return;
  }

  pushState.value.busy = true;
  try {
    if (!pushSupported()) {
      window.alert('Den här webbläsaren stöder inte Web Push. På iPhone/iPad måste appen installeras på hemskärmen och notiser godkännas där.');
      pushState.value.busy = false;
      return;
    }

    if (!props.push.publicKey) {
      window.alert('Pushnotiser saknar publik VAPID-nyckel på servern.');
      pushState.value.busy = false;
      return;
    }

    const permission = Notification.permission === 'default'
      ? await Notification.requestPermission()
      : Notification.permission;
    pushState.value.permission = permission;

    if (permission !== 'granted') {
      window.alert('Pushnotiser är inte tillåtna i webbläsaren.');
      pushState.value.busy = false;
      return;
    }

    const registration = await registerPushServiceWorker();
    const existing = await registration.pushManager.getSubscription();
    const subscription = existing || await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(props.push.publicKey),
    });

    router.post('/push/subscription', { ...subscriptionPayload(subscription), _token: page.props.csrfToken }, {
      preserveScroll: true,
      onSuccess: () => {
        pushState.value.enabled = true;
        router.post('/push/test', { _token: page.props.csrfToken }, { preserveScroll: true });
      },
      onError: () => {
        window.alert('Kunde inte spara push-prenumerationen på servern.');
      },
      onFinish: () => {
        pushState.value.busy = false;
      },
    });
  } catch (error) {
    window.alert(error.message || 'Kunde inte aktivera pushnotiser.');
    pushState.value.busy = false;
  }
}

function getCurrentPositionForTracking() {
  return new Promise((resolve, reject) => {
    if (!('geolocation' in navigator)) {
      reject(new Error('GPS stöds inte i denna webbläsare.'));
      return;
    }

    navigator.geolocation.getCurrentPosition(resolve, () => reject(new Error('Platsbehörighet krävs för live-spårning.')), {
      enableHighAccuracy: true,
      timeout: 12000,
      maximumAge: 3000,
    });
  });
}

async function startOrder(order) {
  if (!visibilityOnline.value) {
    window.alert('Slå på Synlighet först. Du måste vara Online för live-spårning.');
    return;
  }

  let position;
  try {
    position = await getCurrentPositionForTracking();
  } catch (error) {
    window.alert(error.message || 'Platsbehörighet krävs för live-spårning.');
    return;
  }

  router.patch(`/orders/${encodeURIComponent(order.id)}/status`, {
    status: 'ongoing',
    lat: position.coords.latitude,
    lng: position.coords.longitude,
    accuracy: position.coords.accuracy,
    speed: position.coords.speed,
    heading: position.coords.heading,
  }, {
    preserveScroll: true,
    onSuccess: () => {
      startLocationWatch(order.id, position);
      sendTrackedPosition(order.id, position, true);
    },
  });
}

function startLocationWatch(orderId, initialPosition = null) {
  stopLocationWatch();
  activeTrackingOrderId.value = orderId;
  visibilityLocationActive.value = false;
  lastLocationSentAt.value = 0;
  lastLocation.value = null;
  latestTrackingPosition.value = initialPosition;

  locationWatchId.value = navigator.geolocation.watchPosition(
    (position) => {
      latestTrackingPosition.value = position;
      sendTrackedPosition(orderId, position);
    },
    () => {},
    {
      enableHighAccuracy: true,
      timeout: 8000,
      maximumAge: 1000,
    },
  );

  locationIntervalId.value = window.setInterval(() => {
    if (!activeTrackingOrderId.value) return;
    if (latestTrackingPosition.value) {
      sendTrackedPosition(orderId, latestTrackingPosition.value, true);
      return;
    }

    navigator.geolocation.getCurrentPosition((position) => {
      latestTrackingPosition.value = position;
      sendTrackedPosition(orderId, position, true);
    }, () => {}, {
      enableHighAccuracy: true,
      timeout: 8000,
      maximumAge: 1000,
    });
  }, 1000);
}

function startVisibilityLocationWatch(initialPosition = null) {
  stopLocationWatch();
  visibilityLocationActive.value = true;
  lastLocationSentAt.value = 0;
  lastLocation.value = null;
  latestTrackingPosition.value = initialPosition;

  if (initialPosition) {
    sendVisibilityPosition(initialPosition, true);
  }

  locationWatchId.value = navigator.geolocation.watchPosition(
    (position) => {
      latestTrackingPosition.value = position;
      sendVisibilityPosition(position);
    },
    () => {},
    {
      enableHighAccuracy: true,
      timeout: 8000,
      maximumAge: 1000,
    },
  );

  locationIntervalId.value = window.setInterval(() => {
    if (!visibilityLocationActive.value || !visibilityOnline.value) return;
    if (latestTrackingPosition.value) {
      sendVisibilityPosition(latestTrackingPosition.value, true);
      return;
    }

    navigator.geolocation.getCurrentPosition((position) => {
      latestTrackingPosition.value = position;
      sendVisibilityPosition(position, true);
    }, () => {}, {
      enableHighAccuracy: true,
      timeout: 8000,
      maximumAge: 1000,
    });
  }, 1000);
}

function stopLocationWatch() {
  if (locationWatchId.value !== null && 'geolocation' in navigator) {
    navigator.geolocation.clearWatch(locationWatchId.value);
  }

  if (locationIntervalId.value !== null) {
    window.clearInterval(locationIntervalId.value);
  }

  locationWatchId.value = null;
  locationIntervalId.value = null;
  activeTrackingOrderId.value = null;
  visibilityLocationActive.value = false;
  locationRequestInFlight.value = false;
  lastLocationSentAt.value = 0;
  lastLocation.value = null;
  latestTrackingPosition.value = null;
}

function sendTrackedPosition(orderId, position, force = false) {
  const now = Date.now();
  const next = {
    lat: position.coords.latitude,
    lng: position.coords.longitude,
  };

  if (!force && now - lastLocationSentAt.value < 1000 && !hasMovedEnough(lastLocation.value, next)) {
    return;
  }

  lastLocationSentAt.value = now;
  lastLocation.value = next;

  const form = new FormData();
  form.append('_token', page.props.csrfToken);
  form.append('lat', String(next.lat));
  form.append('lng', String(next.lng));
  if (position.coords.accuracy !== null) form.append('accuracy', String(position.coords.accuracy));
  if (position.coords.speed !== null) form.append('speed', String(position.coords.speed));
  if (position.coords.heading !== null) form.append('heading', String(position.coords.heading));

  postLocation(`/orders/${encodeURIComponent(orderId)}/location`, form);
}

function sendVisibilityPosition(position, force = false) {
  if (!visibilityOnline.value || !visibilityLocationActive.value) return;

  const now = Date.now();
  const next = {
    lat: position.coords.latitude,
    lng: position.coords.longitude,
  };

  if (!force && now - lastLocationSentAt.value < 1000 && !hasMovedEnough(lastLocation.value, next)) {
    return;
  }

  lastLocationSentAt.value = now;
  lastLocation.value = next;

  const form = new FormData();
  form.append('_token', page.props.csrfToken);
  form.append('lat', String(next.lat));
  form.append('lng', String(next.lng));
  if (position.coords.accuracy !== null) form.append('accuracy', String(position.coords.accuracy));
  if (position.coords.speed !== null) form.append('speed', String(position.coords.speed));
  if (position.coords.heading !== null) form.append('heading', String(position.coords.heading));

  postLocation('/visibility/location', form);
}

function postLocation(url, form) {
  if (locationRequestInFlight.value) return;
  locationRequestInFlight.value = true;

  if (navigator.sendBeacon && navigator.sendBeacon(url, form)) {
    window.setTimeout(() => { locationRequestInFlight.value = false; }, 250);
    return;
  }

  window.fetch(url, {
    method: 'POST',
    body: form,
    keepalive: true,
    headers: { Accept: 'application/json' },
  }).finally(() => {
    locationRequestInFlight.value = false;
  });
}

function hasMovedEnough(previous, next) {
  if (!previous) return true;
  return distanceMeters(previous, next) >= 3;
}

function distanceMeters(a, b) {
  const radius = 6371000;
  const toRad = (value) => (value * Math.PI) / 180;
  const dLat = toRad(b.lat - a.lat);
  const dLng = toRad(b.lng - a.lng);
  const lat1 = toRad(a.lat);
  const lat2 = toRad(b.lat);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
  return 2 * radius * Math.asin(Math.sqrt(h));
}

async function toggleVisibility() {
  const nextVisibility = visibilityOnline.value ? 'offline' : 'online';

  let initialPosition = null;
  if (nextVisibility === 'online') {
    try {
      initialPosition = await getCurrentPositionForTracking();
    } catch (error) {
      window.alert(error.message || 'Platsbehörighet krävs för live-spårning.');
      return;
    }
  }

  router.patch('/visibility', { visibility: nextVisibility, _token: page.props.csrfToken }, {
    preserveScroll: true,
    onSuccess: () => {
      visibilityState.value = nextVisibility;
      if (nextVisibility === 'offline') {
        stopLocationWatch();
      } else {
        startVisibilityLocationWatch(initialPosition);
      }
    },
    onError: () => {
      window.alert('Kunde inte uppdatera synlighet.');
    },
  });
}

function blankOrder() {
  const slot = nextDeliverySlot();

  return {
    adress: '',
    tele: '',
    mottagare: '',
    desiredDeliveryDate: slot.date,
    desiredDeliveryTime: slot.time,
    notes: '',
    internalComment: '',
    items: [blankOrderItem()],
  };
}

function blankPurchase() {
  return {
    quantity: 1,
    item_name: '',
    store_name: '',
    supplier: '',
    store: '',
    city: 'Stockholm',
    sku: '',
    product_url: '',
    image_url: '',
    maps_label: '',
    maps_url: '',
    unit_price: '',
    currency: 'SEK',
    vat_rate: 25,
    availability_at_selection: '',
    selected_at: '',
    fetched_at: '',
    delivery_address: '',
    recipient_name: '',
    recipient_phone: '',
    work_order_number: '',
    status: 'planned',
    notes: '',
  };
}

function blankUser() {
  return {
    _token: page.props.csrfToken,
    email: '',
    firstName: '',
    lastName: '',
    phone: '',
    role: 'personal',
    active: true,
    permissionAllow: [],
    permissionDeny: [],
  };
}

function openProfileDialog(tab = 'profile') {
  profileForm.defaults({
    _token: page.props.csrfToken,
    email: props.user.email || '',
    phone: props.user.phone || '',
    profileImage: null,
  });
  profileForm.reset();
  messageForm.reset();
  messageTab.value = tab === 'messages' ? 'inbox' : tab;
  selectedProfileUserId.value = props.user.id;
  profileDialog.value = true;
}

function toggleNotificationMenu() {
  notificationMenuOpen.value = !notificationMenuOpen.value;
  if (notificationMenuOpen.value) {
    topMenuOpen.value = false;
  }
}

function openNotificationsInbox() {
  notificationMenuOpen.value = false;
  openProfileDialog('messages');
}

function openNotificationMessage(message) {
  if (!message) return;

  if (!message.readAt) {
    markMessageRead(message);
  }

  openNotificationsInbox();
}

function openUserProfile(user) {
  selectedProfileUserId.value = user.id;
  messageTab.value = 'profiles';
  profileDialog.value = true;
}

function selectProfileUser(user) {
  if (!user?.id) return;
  selectedProfileUserId.value = user.id;
}

function profileMenuClass(value) {
  return ['profile-side-button', { 'is-active': messageTab.value === value }];
}

function onProfileImageChange(event) {
  profileForm.profileImage = event.target.files?.[0] || null;
}

function saveProfile() {
  profileForm._token = page.props.csrfToken;
  profileForm.post('/profile', {
    preserveScroll: true,
    forceFormData: true,
    onSuccess: () => {
      profileForm.profileImage = null;
    },
  });
}

function deleteProfileImage() {
  if (!window.confirm('Ta bort profilbilden?')) return;
  router.delete('/profile/image', {
    data: { _token: page.props.csrfToken },
    preserveScroll: true,
  });
}

function sendMessage() {
  messageForm._token = page.props.csrfToken;
  messageForm.post('/messages', {
    preserveScroll: true,
    onSuccess: () => {
      messageForm.reset();
      messageTab.value = 'outbox';
    },
  });
}

function markMessageRead(message) {
  router.patch(`/messages/${encodeURIComponent(message.id)}/read`, { _token: page.props.csrfToken }, { preserveScroll: true });
}

function deleteMessage(message) {
  router.delete(`/messages/${encodeURIComponent(message.id)}`, {
    data: { _token: page.props.csrfToken },
    preserveScroll: true,
  });
}

function restoreMessage(message) {
  router.patch(`/messages/${encodeURIComponent(message.id)}/restore`, { _token: page.props.csrfToken }, { preserveScroll: true });
}

function messageRowsForTab(tab) {
  return props.messages?.[tab] || [];
}

function packedDeliveryMessage(message) {
  const subject = String(message?.subject || '');
  const body = String(message?.body || '');
  if (!subject.toLowerCase().includes('packad') || !body.includes('Packat i bilen')) {
    return null;
  }

  const lines = body.split(/\r?\n/).map((line) => line.trim());
  const fieldValue = (label) => {
    const prefix = `${label}:`;
    const row = lines.find((line) => line.startsWith(prefix));
    return row ? row.slice(prefix.length).trim() : '';
  };
  const itemHeaderIndex = lines.findIndex((line) => line === 'Nr | Artikel | Antal | Enhet | Arbetsorder');
  const items = [];

  if (itemHeaderIndex >= 0) {
    for (const line of lines.slice(itemHeaderIndex + 1)) {
      if (!line || line === 'Tack för att du är redo att ta emot leveransen.') break;
      if (!line.includes('|')) continue;

      const parts = line.split('|').map((part) => part.trim());
      if (parts.length < 5) continue;

      items.push({
        number: parts[0],
        article: parts[1],
        quantity: parts[2],
        unit: parts[3],
        workOrder: parts[4],
      });
    }
  }

  return {
    recipient: fieldValue('Mottagare'),
    address: fieldValue('Adress'),
    deliveryTime: fieldValue('Beräknad leveranstid'),
    orderId: fieldValue('Leverans-ID'),
    items,
    footer: lines.find((line) => line === 'Tack för att du är redo att ta emot leveransen.') || '',
  };
}

function openCreateOrder() {
  editingOrderId.value = null;
  orderForm.defaults(blankOrder());
  orderForm.reset();
  workOrderArticleCache.clear();
  orderDialog.value = true;
}

function openEditOrder(order) {
  editingOrderId.value = order.id;
  orderForm.defaults({
    adress: order.adress || '',
    tele: order.tele || '',
    mottagare: order.mottagare || '',
    desiredDeliveryDate: order.desiredDeliveryDate || '',
    desiredDeliveryTime: order.desiredDeliveryTime || '',
    notes: order.notes || '',
    internalComment: order.internalComment || '',
    items: order.items?.length ? order.items.map((item) => {
      const orderItem = {
        ...blankOrderItem(),
        artikel: item.artikel || '',
        antal: item.antal || '',
        workOrderNumber: item.workOrderNumber || '',
        workOrderArticleId: item.workOrderArticleId || item.arbetsorderRadId || null,
        isInternalWorkOrder: Boolean(item.isInternalWorkOrder),
        workOrderMatchWarning: item.workOrderMatchWarning || '',
        product: item.product || productForItem(item) || null,
        manualProductOptions: [],
        manualProductDropdownOpen: false,
      };
      updateProductPreview(orderItem);
      return orderItem;
    }) : [blankOrderItem()],
  });
  orderForm.reset();
  workOrderArticleCache.clear();
  orderDialog.value = true;
  nextTick(() => {
    orderForm.items.forEach((item) => {
      if (String(item.workOrderNumber || '').trim() && !item.isInternalWorkOrder) {
        lookupWorkOrderForItem(item);
      }
    });
  });
}

function saveOrder() {
  if (invalidOrderItem.value) {
    orderForm.setError('items', invalidOrderItem.value.workOrderMatchWarning);
    return;
  }

  const options = {
    preserveScroll: true,
    onSuccess: () => {
      orderDialog.value = false;
      orderForm.reset();
      workOrderArticleCache.clear();
    },
  };

  if (editingOrderId.value) {
    orderForm.transform(orderPayload).put(`/orders/${encodeURIComponent(editingOrderId.value)}`, options);
  } else {
    orderForm.transform(orderPayload).post('/orders', options);
  }
}

function orderPayload(data) {
  return {
    ...data,
    items: data.items.map((item) => ({
      artikel: item.artikel,
      antal: item.antal,
      workOrderNumber: item.workOrderNumber,
      order_item_id: item.workOrderArticleId || item.selectedArticle?.id || null,
      isInternalWorkOrder: item.isInternalWorkOrder,
      article_number: item.selectedArticle?.article_number || item.artikel,
      product_sku: item.selectedArticle?.product_sku || item.product?.sku || null,
    })),
  };
}

function addItem() {
  orderForm.items.push(blankOrderItem());
}

function removeItem(index) {
  if (orderForm.items.length === 1) {
    clearProductPreviewTimer(orderForm.items[0]);
    orderForm.items[0] = blankOrderItem();
    return;
  }
  clearProductPreviewTimer(orderForm.items[index]);
  orderForm.items.splice(index, 1);
}

function setStatus(order, status) {
  router.patch(`/orders/${encodeURIComponent(order.id)}/status`, { status }, {
    preserveScroll: true,
    onSuccess: () => {
      if (['paused', 'delivered', 'cancelled'].includes(status) && activeTrackingOrderId.value === order.id) {
        stopLocationWatch();
      }
    },
  });
}

function undoDeliveredOrder() {
  const order = editingOrder.value;
  if (!order) return;

  if (!window.confirm(`Sätta tillbaka leveransen till ${order.mottagare || 'mottagaren'} från levererad?`)) {
    return;
  }

  const targetStatus = order.packedAt ? 'packed' : 'created';
  router.patch(`/orders/${encodeURIComponent(order.id)}/status`, { status: targetStatus }, {
    preserveScroll: true,
    onSuccess: () => {
      orderDialog.value = false;
      if (activeTrackingOrderId.value === order.id) {
        stopLocationWatch();
      }
    },
  });
}

function undoDeliveredOrderFromDetail(order) {
  if (!order || order.status !== 'delivered') return;

  if (!window.confirm(`Sätta tillbaka leveransen till ${order.mottagare || 'mottagaren'} från levererad?`)) {
    return;
  }

  setStatus(order, order.packedAt ? 'packed' : 'created');
}

function deleteOrder(order) {
  if (!window.confirm(`Ta bort leveransen till ${order.mottagare}?`)) return;
  router.delete(`/orders/${encodeURIComponent(order.id)}`, { preserveScroll: true });
  if (selectedWorkOrderOrderId.value === order.id) {
    workOrderDetailDialog.value = false;
    selectedWorkOrderOrderId.value = null;
  }
}

function openCreatePurchase() {
  editingPurchaseId.value = null;
  purchaseForm.defaults(blankPurchase());
  purchaseForm.reset();
  resetPurchaseSearch();
  purchaseDialog.value = true;
}

function openEditPurchase(purchase) {
  editingPurchaseId.value = purchase.id;
  purchaseForm.defaults({
    quantity: purchase.quantity || 1,
    item_name: purchase.itemName || '',
    store_name: purchase.storeName || '',
    supplier: purchase.supplier || '',
    store: purchase.store || '',
    city: purchase.city || 'Stockholm',
    sku: purchase.sku || '',
    product_url: purchase.productUrl || '',
    image_url: purchase.imageUrl || '',
    maps_label: purchase.mapsLabel || '',
    maps_url: purchase.mapsUrl || '',
    unit_price: purchase.unitPrice || '',
    currency: purchase.currency || 'SEK',
    vat_rate: purchase.vatRate || 25,
    availability_at_selection: purchase.availabilityAtSelection || '',
    selected_at: purchase.selectedAt || '',
    fetched_at: purchase.fetchedAt || '',
    delivery_address: purchase.deliveryAddress || '',
    recipient_name: purchase.recipientName || '',
    recipient_phone: purchase.recipientPhone || '',
    work_order_number: purchase.workOrderNumber || '',
    status: purchase.status || 'planned',
    notes: purchase.notes || '',
  });
  purchaseForm.reset();
  resetPurchaseSearch();
  purchaseDialog.value = true;
}

function savePurchase() {
  purchaseForm.quantity = Math.max(1, Number(purchaseForm.quantity) || 1);

  const options = {
    preserveScroll: true,
    onSuccess: () => {
      purchaseDialog.value = false;
      purchaseForm.reset();
    },
  };

  if (editingPurchaseId.value) {
    purchaseForm.put(`/purchases/${encodeURIComponent(editingPurchaseId.value)}`, options);
  } else {
    purchaseForm.post('/purchases', options);
  }
}

function resetPurchaseSearch() {
  clearPurchaseSearch();
  purchaseSearch.value = {
    query: purchaseSearchQueryLabel(),
    results: [],
    errors: [],
    loading: false,
    searched: false,
    message: '',
  };
}

function clearPurchaseSearch() {
  if (purchaseSearchTimer.value) {
    window.clearTimeout(purchaseSearchTimer.value);
    purchaseSearchTimer.value = null;
  }

  if (purchaseSearchAbort.value) {
    purchaseSearchAbort.value.abort();
    purchaseSearchAbort.value = null;
  }
}

function schedulePurchaseSearch() {
  purchaseForm.clearErrors('item_name');
  purchaseForm.clearErrors('sku');
  purchaseSearch.value.query = purchaseSearchQueryLabel();

  if (purchaseSearchTimer.value) {
    window.clearTimeout(purchaseSearchTimer.value);
  }

  const query = String(purchaseForm.item_name || '').trim();
  const articleNumber = String(purchaseForm.sku || '').trim();
  if (query.length < 2 && articleNumber.length < 2) {
    if (purchaseSearchAbort.value) {
      purchaseSearchAbort.value.abort();
      purchaseSearchAbort.value = null;
    }
    purchaseSearch.value.results = [];
    purchaseSearch.value.errors = [];
    purchaseSearch.value.loading = false;
    purchaseSearch.value.searched = false;
    purchaseSearch.value.message = '';
    return;
  }

  purchaseSearchTimer.value = window.setTimeout(() => searchPurchases(query, articleNumber), 500);
}

async function searchPurchases(query, articleNumber = '') {
  if (purchaseSearchAbort.value) {
    purchaseSearchAbort.value.abort();
  }

  const controller = new AbortController();
  purchaseSearchAbort.value = controller;
  purchaseSearch.value.loading = true;
  purchaseSearch.value.searched = true;
  purchaseSearch.value.message = articleNumber
    ? 'Söker exakt artikeldata, pris, varuhus och lagerstatus hos godkända leverantörer...'
    : 'Söker priser, varuhus och lagerstatus hos godkända leverantörer...';

  try {
    const location = await purchaseLocationForSearch();
    const params = new URLSearchParams();
    params.set('q', query || articleNumber);
    if (articleNumber) {
      params.set('article_number', articleNumber);
    }
    if (location?.lat !== null && location?.lng !== null) {
      params.set('lat', String(location.lat));
      params.set('lng', String(location.lng));
    }

    const response = await window.fetch(`/api/purchase/search?${params.toString()}`, {
      headers: { Accept: 'application/json' },
      signal: controller.signal,
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
      throw new Error(payload.message || 'Sökningen misslyckades.');
    }

    purchaseSearch.value.results = payload.results || [];
    purchaseSearch.value.errors = payload.errors || [];
    purchaseSearch.value.message = payload.errors?.length
      ? 'Vissa butiker kunde inte kontrolleras just nu.'
      : '';
  } catch (error) {
    if (error.name === 'AbortError') {
      return;
    }

    purchaseSearch.value.results = [];
    purchaseSearch.value.errors = [];
    purchaseSearch.value.message = 'Kunde inte söka i butikerna just nu.';
  } finally {
    if (purchaseSearchAbort.value === controller) {
      purchaseSearchAbort.value = null;
      purchaseSearch.value.loading = false;
    }
  }
}

function selectPurchaseSearchResult(result) {
  const selectedAt = new Date().toISOString();
  const stockText = purchaseStockText(result);
  purchaseForm.item_name = result.title || '';
  purchaseForm.store_name = [result.supplier, result.store].filter(Boolean).join(' ');
  purchaseForm.supplier = result.supplier || '';
  purchaseForm.store = result.store || '';
  purchaseForm.city = result.city || 'Stockholm';
  purchaseForm.sku = result.sku || result.articleNumber || purchaseForm.sku || '';
  purchaseForm.product_url = result.productUrl || '';
  purchaseForm.image_url = result.imageUrl || '';
  purchaseForm.maps_label = result.mapsLabel || '';
  purchaseForm.maps_url = result.mapsUrl || '';
  purchaseForm.unit_price = result.price ?? '';
  purchaseForm.currency = result.currency || 'SEK';
  purchaseForm.vat_rate = 25;
  purchaseForm.availability_at_selection = [result.availability, stockText].filter(Boolean).join(' · ');
  purchaseForm.selected_at = selectedAt;
  purchaseForm.fetched_at = result.fetchedAt || selectedAt;
  purchaseSearch.value.results = [];
  purchaseSearch.value.message = '';
  purchaseSearch.value.searched = false;
}

function purchaseLocationForSearch() {
  if (purchaseSearchLocation.value.lat !== null && purchaseSearchLocation.value.lng !== null) {
    return Promise.resolve(purchaseSearchLocation.value);
  }

  if (purchaseSearchLocation.value.requested || !navigator.geolocation) {
    return Promise.resolve({ lat: null, lng: null });
  }

  purchaseSearchLocation.value.requested = true;

  return new Promise((resolve) => {
    const done = (location) => {
      purchaseSearchLocation.value = {
        ...purchaseSearchLocation.value,
        ...location,
      };
      resolve(location);
    };

    navigator.geolocation.getCurrentPosition(
      (position) => {
        done({
          lat: Number(position.coords.latitude.toFixed(6)),
          lng: Number(position.coords.longitude.toFixed(6)),
          requested: true,
        });
      },
      () => done({ lat: null, lng: null, requested: true }),
      {
        enableHighAccuracy: false,
        maximumAge: 10 * 60 * 1000,
        timeout: 1200,
      },
    );
  });
}

function purchaseSearchQueryLabel() {
  const query = String(purchaseForm.item_name || '').trim();
  const articleNumber = String(purchaseForm.sku || '').trim();

  if (query && articleNumber) {
    return `${query} · ${articleNumber}`;
  }

  return query || articleNumber;
}

function formatMoney(value, currency = 'SEK') {
  if (value === null || value === undefined || value === '' || Number.isNaN(Number(value))) {
    return 'Pris saknas';
  }

  return new Intl.NumberFormat('sv-SE', {
    style: 'currency',
    currency: currency || 'SEK',
    maximumFractionDigits: 2,
  }).format(Number(value));
}

function purchaseStockText(result) {
  if (result?.stockText) {
    return result.stockText;
  }

  const availability = String(result?.availability || '').toLowerCase();
  if (availability.includes('i lager') && !availability.includes('ej')) {
    return `Antal ej angivet för ${[result?.supplier, result?.store].filter(Boolean).join(' ')}`;
  }

  return '';
}

function purchaseStockLocations(result) {
  if (!Array.isArray(result?.stockLocations) || !result.stockLocations.length) {
    return '';
  }

  const stockText = String(result?.stockText || '');
  if (stockText && result.stockLocations.some((location) => stockText.includes(location))) {
    return '';
  }

  return `Varuhus med lager: ${result.stockLocations.join(', ')}`;
}

function purchaseGrossTotal(quantity, unitPrice, vatRate = 25) {
  const qty = Math.max(1, Number(quantity) || 1);
  const price = Number(unitPrice);
  if (!Number.isFinite(price)) return null;

  return qty * price * (1 + (Number(vatRate) || 0) / 100);
}

function markPurchaseOrdered(purchase) {
  router.patch(`/purchases/${encodeURIComponent(purchase.id)}/ordered`, { _token: page.props.csrfToken }, { preserveScroll: true });
}

function markPurchaseReceived(purchase) {
  router.patch(`/purchases/${encodeURIComponent(purchase.id)}/received`, { _token: page.props.csrfToken }, { preserveScroll: true });
}

function deletePurchase(purchase) {
  if (!window.confirm(`Ta bort inköpet "${purchase.itemName}"?`)) return;
  router.delete(`/purchases/${encodeURIComponent(purchase.id)}`, {
    data: { _token: page.props.csrfToken },
    preserveScroll: true,
  });
}

function openCreateUser() {
  editingUserId.value = null;
  userForm.defaults(blankUser());
  userForm.reset();
  userDialog.value = true;
}

function openEditUser(user) {
  editingUserId.value = user.id;
  userForm.defaults({
    _token: page.props.csrfToken,
    email: user.email || '',
    firstName: user.firstName || '',
    lastName: user.lastName || '',
    phone: user.phone || '',
    role: user.roleKey || user.role || 'personal',
    active: user.active,
    permissionAllow: [...(user.permissionAllow || [])],
    permissionDeny: [...(user.permissionDeny || [])],
  });
  userForm.reset();
  userDialog.value = true;
}

function saveUser() {
  userForm._token = page.props.csrfToken;

  const options = {
    preserveScroll: true,
    onSuccess: () => {
      userDialog.value = false;
      userForm.reset();
    },
  };

  if (editingUserId.value) {
    userForm.put(`/users/${encodeURIComponent(editingUserId.value)}`, options);
  } else {
    userForm.post('/users', options);
  }
}

function resetPassword(user) {
  if (!window.confirm(`Återställ lösenord för ${user.name || user.email}?`)) return;
  router.patch(`/users/${encodeURIComponent(user.id)}/password`, { _token: page.props.csrfToken }, { preserveScroll: true });
}

function deleteUser(user) {
  if (!window.confirm(`Ta bort användaren ${user.name || user.email}?`)) return;
  router.delete(`/users/${encodeURIComponent(user.id)}`, {
    data: { _token: page.props.csrfToken },
    preserveScroll: true,
  });
}

function saveSettings() {
  settingsForm._token = page.props.csrfToken;

  settingsForm.put('/settings', {
    preserveScroll: true,
    onSuccess: () => { adminTab.value = 'settings'; },
  });
}

function exportLogs() {
  const headers = ['Datum/tid', 'Användare', 'Roll', 'Händelse', 'Modul', 'IP-adress', 'Status', 'Detaljer'];
  const rows = filteredLogs.value.map((row) => [
    row.time,
    row.user,
    row.role,
    row.event,
    row.module,
    row.ip,
    row.status,
    row.details,
  ]);
  const csv = [headers, ...rows].map((row) => row.map(csvValue).join(';')).join('\n');
  const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = `systemloggar-${new Date().toISOString().slice(0, 10)}.csv`;
  link.click();
  URL.revokeObjectURL(url);
}

function clearFrontendLogs() {
  const currentKeys = (props.admin.logs || []).map(logRowKey);
  if (!currentKeys.length) return;
  if (!window.confirm('Rensa loggar från denna vy? Backendloggarna sparas och tas inte bort.')) return;

  hiddenLogKeys.value = Array.from(new Set([...hiddenLogKeys.value, ...currentKeys]));
  logSearch.value = '';
  logStatus.value = '';
  logModule.value = '';
  persistHiddenLogKeys();
}

function csvValue(value) {
  return `"${String(value ?? '').replace(/"/g, '""')}"`;
}

function statusSeverity(status) {
  return {
    created: 'info',
    assigned: 'info',
    ongoing: 'success',
    paused: 'warn',
    delivered: 'secondary',
    cancelled: 'danger',
  }[status] || 'info';
}

function purchaseStatusSeverity(status) {
  return {
    planned: 'info',
    ordered: 'warn',
    received: 'success',
    cancelled: 'danger',
  }[status] || 'info';
}

function formatDate(value) {
  if (!value) return '';
  return new Intl.DateTimeFormat('sv-SE', {
    dateStyle: 'short',
    timeStyle: value.includes(':') ? 'short' : undefined,
  }).format(new Date(value));
}
</script>

<template>
  <Head :title="settings.appTitle" />

  <div class="app-shell app-shell-dashboard">
    <header class="topbar app-header">
      <div ref="topMenuRef" class="topbar-menu">
        <Button
          label="Meny"
          icon="pi pi-bars"
          severity="secondary"
          outlined
          aria-haspopup="true"
          aria-controls="topbar_menu"
          :aria-expanded="topMenuOpen"
          @click="toggleTopMenu"
        />
        <div
          v-if="topMenuOpen"
          id="topbar_menu"
          class="custom-menu-card"
          role="menu"
          aria-label="Navigationsmeny"
        >
          <div class="custom-menu-active-indicator" aria-hidden="true"></div>
          <header class="custom-menu-header">
            <span class="custom-menu-hamburger" aria-hidden="true">
              <i class="pi pi-bars"></i>
            </span>
            <strong>Meny</strong>
          </header>

          <section class="custom-submenu-row" aria-label="Undermeny Skapa PDF">
            <span class="custom-submenu-icon" aria-hidden="true">
              <i class="pi pi-file-pdf"></i>
            </span>
            <span class="custom-submenu-title">Skapa PDF</span>
            <i class="pi pi-chevron-down custom-submenu-chevron" aria-hidden="true"></i>
          </section>

          <section class="custom-nested-area" aria-label="Sidomeny under Skapa PDF">
            <span class="custom-menu-connector" aria-hidden="true"></span>
            <a
              v-for="item in pdfMenuItems"
              :key="`${item.view}-${item.status}`"
              :href="deliveriesPdfUrl(item.view, item.status)"
              target="_blank"
              rel="noopener noreferrer"
              class="custom-menu-item"
              role="menuitem"
              :aria-disabled="!canCreateDeliveryPdf"
              @click="handlePdfMenuClick"
            >
              <span class="custom-menu-item-icon" aria-hidden="true">
                <i :class="item.icon"></i>
              </span>
              <span class="custom-menu-item-text">{{ item.label }}</span>
            </a>
          </section>
        </div>
      </div>
      <div class="topbar-actions">
        <Button
          :label="visibilityOnline ? 'Synlighet Online' : 'Synlighet Offline'"
          :icon="visibilityOnline ? 'pi pi-eye' : 'pi pi-eye-slash'"
          :severity="visibilityOnline ? 'success' : 'secondary'"
          :outlined="!visibilityOnline"
          @click="toggleVisibility"
        />
        <div ref="notificationMenuRef" class="notification-menu">
          <button
            type="button"
            class="notification-trigger"
            aria-haspopup="menu"
            aria-controls="notification_menu"
            :aria-expanded="notificationMenuOpen"
            :aria-label="notificationCount ? `${notificationCount} olästa notiser` : 'Inga olästa notiser'"
            @click="toggleNotificationMenu"
          >
            <i class="pi pi-bell" aria-hidden="true"></i>
            <span v-if="notificationCount" class="notification-badge">{{ notificationCount > 99 ? '99+' : notificationCount }}</span>
          </button>
          <div
            v-if="notificationMenuOpen"
            id="notification_menu"
            class="notification-dropdown"
            role="menu"
            aria-label="Notiser"
          >
            <header class="notification-dropdown-header">
              <strong>Notiser</strong>
              <span>{{ notificationCount }} olästa</span>
            </header>
            <div v-if="notificationItems.length" class="notification-list">
              <button
                v-for="message in notificationItems"
                :key="message.id"
                type="button"
                :class="['notification-item', { 'is-unread': message.isUnread }]"
                role="menuitem"
                @click="openNotificationMessage(message)"
              >
                <span class="notification-item-icon">
                  <i :class="message.isUnread ? 'pi pi-envelope' : 'pi pi-envelope-open'"></i>
                </span>
                <span class="notification-item-copy">
                  <strong>{{ message.title }}</strong>
                  <small>Från: {{ message.from }}</small>
                  <span>{{ message.text }}</span>
                  <time v-if="message.createdAt">{{ message.createdAt }}</time>
                </span>
              </button>
            </div>
            <p v-else class="notification-empty">Inga notiser just nu.</p>
            <button type="button" class="notification-all-button" @click="openNotificationsInbox">
              Öppna inkorgen
            </button>
          </div>
        </div>
        <Button
          :label="pushLabel"
          icon="pi pi-bell"
          :severity="pushState.enabled ? 'success' : 'secondary'"
          :outlined="!pushState.enabled"
          :loading="pushState.busy"
          :disabled="!props.push.enabled || pushState.permission === 'denied'"
          @click="togglePushNotifications"
        />
        <Button
          :label="props.messages.unreadCount ? `Meddelanden ${props.messages.unreadCount}` : 'Profil'"
          :icon="props.user.photoUrl ? 'pi pi-user' : 'pi pi-user-edit'"
          severity="secondary"
          outlined
          @click="openProfileDialog(props.messages.unreadCount ? 'messages' : 'profile')"
        />
        <Button as="a" href="/live-map" label="Livekarta" icon="pi pi-map" severity="secondary" outlined />
        <Button v-if="canOpenAdmin" label="Inställningar" icon="pi pi-cog" severity="secondary" outlined @click="settingsDialog = true" />
        <form method="post" action="/logout">
          <input type="hidden" name="_token" :value="page.props.csrfToken">
          <Button type="submit" label="Logga ut" icon="pi pi-sign-out" severity="secondary" />
        </form>
      </div>
    </header>

    <main class="main app-main app-content">
      <Message v-if="flash.success" severity="success" :closable="false" class="mb-3">
        {{ flash.success }}
      </Message>
      <Message v-if="flash.error" severity="error" :closable="false" class="mb-3">
        {{ flash.error }}
      </Message>

      <Tabs v-model:value="activeTab" class="dashboard-tabs">
        <TabList>
          <Tab value="orders">Leveranslista</Tab>
          <Tab v-if="can('purchases.view')" value="purchases">Inköp</Tab>
          <Tab v-if="can('users.view')" value="users">Användare</Tab>
          <Tab value="deliveryOverview">Leveranser</Tab>
        </TabList>
        <TabPanels>
          <TabPanel value="deliveryOverview">
            <section class="panel delivery-overview-panel">
              <div class="deliveries-panel-header">
                <div class="toolbar-title">
                  <span class="toolbar-marker"><i class="pi pi-chart-bar"></i></span>
                  <span class="toolbar-copy">
                    <strong>Leveranser</strong>
                    <span>Översikt och statistik</span>
                  </span>
                </div>
              </div>

              <div class="delivery-overview-tabs" role="tablist" aria-label="Leveransöversikt undermeny">
                <button
                  type="button"
                  :class="['delivery-overview-tab', { 'is-active': deliveryOverviewTab === 'overview' }]"
                  role="tab"
                  :aria-selected="deliveryOverviewTab === 'overview'"
                  @click="deliveryOverviewTab = 'overview'"
                >
                  Översikt
                </button>
                <button
                  v-if="can('purchases.view')"
                  type="button"
                  :class="['delivery-overview-tab', { 'is-active': deliveryOverviewTab === 'receivedPurchases' }]"
                  role="tab"
                  :aria-selected="deliveryOverviewTab === 'receivedPurchases'"
                  @click="deliveryOverviewTab = 'receivedPurchases'"
                >
                  Inköp
                  <span>{{ receivedPurchases.length }}</span>
                </button>
              </div>

              <div v-if="deliveryOverviewTab === 'overview'" class="delivery-overview-content">
                <section class="stat-grid delivery-overview-grid" aria-label="Leveransöversikt">
                  <button
                    type="button"
                    :class="['stat', 'stat-deliveries', { 'is-active': activeTab === 'orders' && deliveryFilter === 'active' }]"
                    aria-label="Visa aktiva leveranser"
                    @click="showDeliveryFilter('active')"
                  >
                    <div class="stat-icon"><i class="pi pi-box"></i></div>
                    <div>
                      <div class="stat-label">Leveranser</div>
                      <div class="stat-value">{{ stats.activeOrders }}</div>
                    </div>
                  </button>
                  <button
                    type="button"
                    :class="['stat', 'stat-active', { 'is-active': activeTab === 'orders' && deliveryFilter === 'all' }]"
                    aria-label="Visa alla leveranser"
                    @click="showDeliveryFilter('all')"
                  >
                    <div class="stat-icon"><i class="pi pi-bolt"></i></div>
                    <div>
                      <div class="stat-label">Alla</div>
                      <div class="stat-value">{{ stats.ordersTotal }}</div>
                    </div>
                  </button>
                  <button
                    type="button"
                    :class="['stat', 'stat-completed', { 'is-active': activeTab === 'orders' && deliveryFilter === 'delivered' }]"
                    aria-label="Visa levererade leveranser"
                    @click="showDeliveryFilter('delivered')"
                  >
                    <div class="stat-icon"><i class="pi pi-check-circle"></i></div>
                    <div>
                      <div class="stat-label">Levererade</div>
                      <div class="stat-value">{{ stats.deliveredOrders }}</div>
                    </div>
                  </button>
                  <button
                    type="button"
                    :class="['stat', 'stat-users', { 'is-active': activeTab === 'users' }]"
                    :disabled="!can('users.view')"
                    aria-label="Visa användare"
                    @click="showUsersTab"
                  >
                    <div class="stat-icon"><i class="pi pi-users"></i></div>
                    <div>
                      <div class="stat-label">Användare</div>
                      <div class="stat-value">{{ stats.usersTotal }}</div>
                    </div>
                  </button>
                </section>
              </div>

              <div v-else class="delivery-overview-content">
                <div v-if="!receivedPurchases.length" class="purchases-empty-state">
                  <i class="pi pi-check-circle"></i>
                  <h3>Inga mottagna inköp</h3>
                  <p>Inköp som markeras som mottagna flyttas hit.</p>
                </div>
                <div v-else class="purchases-list">
                  <article v-for="purchase in receivedPurchases" :key="purchase.id" class="purchase-row">
                    <div class="purchase-quantity" :title="`${purchase.quantity} st`">
                      <img
                        v-if="purchase.thumbnailUrl || purchase.imageUrl"
                        :src="purchase.thumbnailUrl || purchase.imageUrl"
                        :alt="purchase.itemName || 'Inköpsbild'"
                        width="44"
                        height="44"
                        loading="lazy"
                        decoding="async"
                      >
                      <span>{{ purchase.quantity }} st</span>
                    </div>
                    <div class="purchase-item" :title="purchase.itemName">
                      <strong>{{ purchase.itemName }}</strong>
                      <small v-if="purchase.sku">Art.nr {{ purchase.sku }}</small>
                    </div>
                    <div class="purchase-store" :title="purchase.storeName || 'Butik saknas'">
                      <span>{{ purchase.storeName || 'Butik saknas' }}</span>
                      <a v-if="purchase.mapsUrl" :href="purchase.mapsUrl" target="_blank" rel="noopener noreferrer">
                        {{ purchase.mapsLabel || 'Kör med Google Maps' }}
                      </a>
                    </div>
                    <div class="purchase-delivery-address" :title="purchase.deliveryAddress || 'Leveransadress saknas'">
                      <span>{{ purchase.availabilityAtSelection || purchase.deliveryAddress || 'Lagerstatus saknas' }}</span>
                      <a v-if="purchase.productUrl" :href="purchase.productUrl" target="_blank" rel="noopener noreferrer">Öppna produkt</a>
                    </div>
                    <div class="purchase-recipient" :title="purchase.recipientName || 'Mottagare saknas'">
                      <span>{{ formatMoney(purchase.unitPrice, purchase.currency) }}</span>
                      <small v-if="purchase.totalGross">{{ formatMoney(purchase.totalGross, purchase.currency) }} inkl. moms</small>
                      <small v-if="purchase.workOrderNumber">Arbetsorder: {{ purchase.workOrderNumber }}</small>
                      <small v-if="purchase.recipientName">Mottagare: {{ purchase.recipientName }}</small>
                    </div>
                    <div class="purchase-status">
                      <Tag value="Mottagen" severity="success" />
                    </div>
                    <div class="purchase-actions">
                      <Button v-if="can('purchases.update')" icon="pi pi-pencil" text rounded aria-label="Redigera" title="Redigera" @click="openEditPurchase(purchase)" />
                      <Button v-if="can('purchases.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Radera" title="Radera" @click="deletePurchase(purchase)" />
                    </div>
                  </article>
                </div>
              </div>
            </section>
          </TabPanel>

          <TabPanel value="orders">
            <section class="panel deliveries-panel">
              <div class="deliveries-panel-header">
                <div class="toolbar-title">
                  <span class="toolbar-marker"><i class="pi pi-truck"></i></span>
	                  <span class="toolbar-copy">
	                    <strong>Leveranser</strong>
	                    <span>{{ deliveryFilterLabel }}</span>
	                  </span>
                </div>
                <span class="toolbar-count deliveries-summary">
                  <strong>{{ deliveryCards.length }}</strong>
                  <span>st</span>
                </span>
                <Button v-if="can('deliveries.create')" class="new-delivery-button" label="Ny leverans" icon="pi pi-plus" @click="openCreateOrder" />
              </div>

              <div class="deliveries-list-wrapper">
                <div v-if="!deliveryCards.length" class="deliveries-empty">
                  Inga {{ deliveryFilterLabel.toLowerCase() }} hittades.
                </div>
                <div v-else class="deliveries-list">
                  <article v-for="card in deliveryCards" :key="card.order.id" class="delivery-row">
                    <div class="delivery-media">
                      <a v-if="card.primaryImageUrl" class="delivery-media-link" :href="card.primaryImageUrl" target="_blank" rel="noopener noreferrer" title="Öppna artikelbild">
                        <img :src="card.primaryImageUrl" :alt="card.primaryImageAlt" width="64" height="64" loading="lazy" decoding="async" fetchpriority="low">
                      </a>
                      <span v-else class="delivery-media-icon">
                        <i class="pi pi-box"></i>
                      </span>
                    </div>

                    <div class="delivery-main" :title="`${card.order.adress || 'Adress saknas'} ${card.order.mottagare || ''} ${card.productSummary}`">
                      <a
                        v-if="mapsHref(card.order.adress)"
                        class="delivery-main-address"
                        :href="mapsHref(card.order.adress)"
                        target="_blank"
                        rel="noopener noreferrer"
                        :title="`Öppna ${card.order.adress} i Google Maps`"
                      >
                        <i class="pi pi-map-marker"></i>
                        <strong>{{ card.order.adress }}</strong>
                      </a>
                      <strong v-else class="delivery-main-address is-missing">
                        <i class="pi pi-map-marker"></i>
                        <span>{{ card.order.mottagare || 'Adress saknas' }}</span>
                      </strong>
                      <span class="delivery-main-meta">
                        <span class="delivery-main-recipient">Mottagare: {{ card.order.mottagare || 'Okänd mottagare' }}</span>
                        <button
                          v-if="deliveryWorkOrderLabel(card.order)"
                          type="button"
                          class="delivery-main-work-order"
                          :aria-label="`Öppna ${deliveryWorkOrderLabel(card.order)}`"
                          @click="openWorkOrderDetail(card.order)"
                        >
                          {{ deliveryWorkOrderLabel(card.order) }}
                        </button>
                      </span>
                    </div>

                    <div class="delivery-products" :title="card.productSummary">
                      <div v-if="card.productRows.length" class="delivery-product-list">
                        <div v-for="productRow in card.productRows" :key="productRow.key" class="delivery-product-row">
                          <a v-if="productRow.imageUrl" class="delivery-product-image-link" :href="productRow.imageUrl" target="_blank" rel="noopener noreferrer" title="Öppna artikelbild">
                            <img
                              :src="productRow.imageUrl"
                              :alt="productRow.imageAlt"
                              width="36"
                              height="36"
                              loading="lazy"
                              decoding="async"
                              fetchpriority="low"
                            >
                          </a>
                          <span v-else class="delivery-product-image-placeholder">
                            <i class="pi pi-box"></i>
                          </span>
                          <span class="delivery-product-copy">
                            <strong>{{ productRow.title }}</strong>
                            <span>Artikel: {{ productRow.article }}</span>
                          </span>
                          <span class="delivery-product-quantity">
                            {{ productRow.quantityText || '-' }}
                            <small v-if="productRow.deliveredText">{{ productRow.deliveredText }}</small>
                          </span>
                        </div>
                      </div>
                      <span v-else>{{ card.productSummary }}</span>
                    </div>

                    <a v-if="phoneHref(card.order.tele)" class="delivery-phone delivery-link" :href="phoneHref(card.order.tele)" :title="card.order.tele">
                      <i class="pi pi-phone"></i>
                      <span>{{ card.order.tele }}</span>
                    </a>
                    <span v-else class="delivery-phone muted">Telefon saknas</span>

                    <a v-if="mapsHref(card.order.adress)" class="delivery-address delivery-link" :href="mapsHref(card.order.adress)" target="_blank" rel="noopener noreferrer" :title="card.order.adress">
                      <i class="pi pi-map-marker"></i>
                      <span>{{ card.order.adress }}</span>
                    </a>
                    <span v-else class="delivery-address muted" :title="card.order.adress">{{ card.order.adress || 'Adress saknas' }}</span>

                    <div class="delivery-date" :title="card.deliveryDate">
                      <i class="pi pi-calendar"></i>
                      <span>{{ card.deliveryDate }}</span>
                    </div>

                    <div class="delivery-status">
                      <Tag :value="statusLabels[card.order.status] || card.order.status" :severity="statusSeverity(card.order.status)" :class="['status-badge', `status-${card.order.status}`]" />
                    </div>

                    <div class="delivery-actions">
                      <a v-if="phoneHref(card.order.tele)" class="icon-action" :href="phoneHref(card.order.tele)" aria-label="Ring" title="Ring">
                        <i class="pi pi-phone"></i>
                      </a>
                      <a v-if="mapsHref(card.order.adress)" class="icon-action" :href="mapsHref(card.order.adress)" target="_blank" rel="noopener noreferrer" aria-label="Öppna karta" title="Öppna karta">
                        <i class="pi pi-map-marker"></i>
                      </a>
                      <Button v-if="can('deliveries.update')" icon="pi pi-pencil" text rounded aria-label="Redigera" title="Redigera" @click="openEditOrder(card.order)" />
                      <Button v-if="can('deliveries.update_status')" icon="pi pi-play" text rounded severity="success" aria-label="Starta" title="Starta" @click="startOrder(card.order)" />
                      <Button v-if="can('deliveries.update_status')" icon="pi pi-box" text rounded severity="info" aria-label="Packad" title="Markerad som packad" @click="setStatus(card.order, 'packed')" />
                      <Button v-if="can('deliveries.update_status')" icon="pi pi-check" text rounded severity="secondary" aria-label="Levererad" title="Markera levererad" @click="setStatus(card.order, 'delivered')" />
                      <Button v-if="can('deliveries.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Ta bort" title="Ta bort" @click="deleteOrder(card.order)" />
                    </div>
                  </article>
                </div>
              </div>
            </section>
          </TabPanel>

          <TabPanel v-if="can('purchases.view')" value="purchases">
            <section class="panel purchases-panel">
              <div class="purchases-panel-header">
                <div class="toolbar-title">
                  <span class="toolbar-marker"><i class="pi pi-shopping-cart"></i></span>
                  <span class="toolbar-copy">
                    <strong>Inköp</strong>
                    <span>Registrerade inköp</span>
                  </span>
                </div>
                <span class="toolbar-count purchases-summary">
                  <strong>{{ activePurchases.length }}</strong>
                  <span>st</span>
                </span>
                <Button v-if="can('purchases.create')" class="new-purchase-button" label="Nytt inköp" icon="pi pi-plus" @click="openCreatePurchase" />
              </div>

              <div class="purchases-list-wrapper">
                <div v-if="!activePurchases.length" class="purchases-empty-state">
                  <i class="pi pi-shopping-cart"></i>
                  <h3>Inga aktiva inköp</h3>
                  <p>Mottagna inköp visas under Leveranser - Inköp.</p>
                </div>
                <div v-else class="purchases-list">
                  <article v-for="purchase in activePurchases" :key="purchase.id" class="purchase-row">
                    <div class="purchase-quantity" :title="`${purchase.quantity} st`">
                      <img
                        v-if="purchase.thumbnailUrl || purchase.imageUrl"
                        :src="purchase.thumbnailUrl || purchase.imageUrl"
                        :alt="purchase.itemName || 'Inköpsbild'"
                        width="44"
                        height="44"
                        loading="lazy"
                        decoding="async"
                      >
                      <span>{{ purchase.quantity }} st</span>
                    </div>
                    <div class="purchase-item" :title="purchase.itemName">
                      <strong>{{ purchase.itemName }}</strong>
                      <small v-if="purchase.sku">Art.nr {{ purchase.sku }}</small>
                    </div>
                    <div class="purchase-store" :title="purchase.storeName || 'Butik saknas'">
                      <span>{{ purchase.storeName || 'Butik saknas' }}</span>
                      <a v-if="purchase.mapsUrl" :href="purchase.mapsUrl" target="_blank" rel="noopener noreferrer">
                        {{ purchase.mapsLabel || 'Kör med Google Maps' }}
                      </a>
                    </div>
                    <div class="purchase-delivery-address" :title="purchase.deliveryAddress || 'Leveransadress saknas'">
                      <span>{{ purchase.availabilityAtSelection || purchase.deliveryAddress || 'Lagerstatus saknas' }}</span>
                      <a v-if="purchase.productUrl" :href="purchase.productUrl" target="_blank" rel="noopener noreferrer">Öppna produkt</a>
                    </div>
                    <div class="purchase-recipient" :title="purchase.recipientName || 'Mottagare saknas'">
                      <span>{{ formatMoney(purchase.unitPrice, purchase.currency) }}</span>
                      <small v-if="purchase.totalGross">{{ formatMoney(purchase.totalGross, purchase.currency) }} inkl. moms</small>
                      <small v-if="purchase.workOrderNumber">Arbetsorder: {{ purchase.workOrderNumber }}</small>
                      <small v-if="purchase.recipientName">Mottagare: {{ purchase.recipientName }}</small>
                      <small v-if="purchase.recipientPhone" class="contact-actions">
                        <span>{{ purchase.recipientPhone }}</span>
                        <a v-if="phoneHref(purchase.recipientPhone)" :href="phoneHref(purchase.recipientPhone)">Ringa</a>
                        <a v-if="smsHref(purchase.recipientPhone)" :href="smsHref(purchase.recipientPhone)">SMS</a>
                      </small>
                    </div>
                    <div class="purchase-status">
                      <Tag :value="purchaseStatusLabels[purchase.status] || purchase.status" :severity="purchaseStatusSeverity(purchase.status)" />
                    </div>
                    <div class="purchase-actions">
                      <Button v-if="can('purchases.update')" icon="pi pi-pencil" text rounded aria-label="Redigera" title="Redigera" @click="openEditPurchase(purchase)" />
                      <Button v-if="can('purchases.update_status')" icon="pi pi-send" text rounded severity="warn" aria-label="Beställd" title="Markera beställd" @click="markPurchaseOrdered(purchase)" />
                      <Button v-if="can('purchases.update_status')" icon="pi pi-check" text rounded severity="success" aria-label="Mottagen" title="Markera mottagen" @click="markPurchaseReceived(purchase)" />
                      <Button v-if="can('purchases.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Radera" title="Radera" @click="deletePurchase(purchase)" />
                    </div>
                  </article>
                </div>
              </div>
            </section>
          </TabPanel>

          <TabPanel value="users">
            <section class="panel users-panel">
              <div class="users-panel-header">
                <div class="toolbar-title users-toolbar-title">
                  <span class="toolbar-marker"><i class="pi pi-users"></i></span>
                  <span class="toolbar-copy">
                    <strong>Användare</strong>
                    <span>Aktiva konton i systemet</span>
                  </span>
                </div>

                <div class="main-user-search">
                  <label class="admin-search-label" for="mainUserSearch">Sök användare</label>
                  <div class="admin-search-field">
                    <i class="pi pi-search"></i>
                    <InputText id="mainUserSearch" v-model="mainUserSearch" placeholder="Skriv namn, e-post, telefon eller roll..." autocomplete="off" />
                    <Button v-if="mainUserSearch" icon="pi pi-times" text rounded severity="secondary" aria-label="Rensa sök" @click="mainUserSearch = ''" />
                  </div>
                </div>

                <span class="toolbar-count users-summary">
                  <strong>{{ filteredMainUsers.length }}</strong>
                  <span>av {{ users.length }} konton</span>
                </span>

                <Button v-if="can('users.create')" class="new-user-button" label="Ny användare" icon="pi pi-user-plus" @click="openCreateUser" />
              </div>

              <div class="main-users-list-wrapper">
                <div class="main-users-list-header" role="row">
                  <span>Namn</span>
                  <span>E-post</span>
                  <span>Telefon</span>
                  <span>Roll</span>
                  <span>Synlighet</span>
                  <span>Aktiv</span>
                  <span>Åtgärder</span>
                </div>

                <div v-if="!users.length" class="users-empty-state">
                  <i class="pi pi-users"></i>
                  <h3>Inga användare registrerade</h3>
                  <p>Skapa första kontot med knappen Ny användare.</p>
                </div>
                <div v-else-if="!filteredMainUsers.length" class="users-empty-state">
                  <i class="pi pi-search"></i>
                  <h3>Ingen användare matchar sökningen</h3>
                  <p>Ändra eller rensa sökrutan.</p>
                </div>

                <div v-else class="main-users-list">
                  <article v-for="user in filteredMainUsers" :key="user.id" class="main-user-row">
                    <div class="main-user-cell main-user-name" data-label="Namn">
                      <strong>{{ user.name || 'Namnlös användare' }}</strong>
                    </div>
                    <div class="main-user-cell main-user-email" data-label="E-post">
                      <a v-if="mailHref(user.email)" :href="mailHref(user.email)">{{ user.email }}</a>
                      <span v-else>-</span>
                    </div>
                    <div class="main-user-cell main-user-phone" data-label="Telefon">
                      <span v-if="user.phone" class="contact-actions">
                        <span>{{ user.phone }}</span>
                        <a v-if="phoneHref(user.phone)" :href="phoneHref(user.phone)">Ringa</a>
                        <a v-if="smsHref(user.phone)" :href="smsHref(user.phone)">SMS</a>
                      </span>
                      <span v-else>{{ user.phone || '-' }}</span>
                    </div>
                    <div class="main-user-cell main-user-role" data-label="Roll">
                      <span>{{ roleLabel(user) || '-' }}</span>
                    </div>
                    <div class="main-user-cell main-user-visibility" data-label="Synlighet">
                      <Tag :value="user.visibility === 'online' ? 'Online' : 'Offline'" :severity="user.visibility === 'online' ? 'success' : 'secondary'" />
                    </div>
                    <div class="main-user-cell main-user-active" data-label="Aktiv">
                      <Tag :value="user.active ? 'Ja' : 'Nej'" :severity="user.active ? 'success' : 'danger'" />
                    </div>
                    <div class="main-user-actions" aria-label="Åtgärder">
                      <Button icon="pi pi-id-card" text rounded aria-label="Profil" title="Visa profil" @click="openUserProfile(user)" />
                      <Button v-if="can('users.update')" icon="pi pi-pencil" text rounded aria-label="Redigera" title="Redigera" @click="openEditUser(user)" />
                      <Button v-if="can('users.change_password')" icon="pi pi-key" text rounded severity="secondary" aria-label="Nytt lösenord" title="Nytt lösenord" @click="resetPassword(user)" />
                      <Button v-if="can('users.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Ta bort" title="Ta bort" @click="deleteUser(user)" />
                    </div>
                  </article>
                </div>
              </div>
            </section>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </main>

    <Dialog v-model:visible="orderDialog" modal :header="editingOrderId ? 'Redigera leverans' : 'Ny leverans'" class="delivery-dialog" :style="{ width: 'min(920px, 96vw)' }">
      <form class="form-grid" @submit.prevent="saveOrder">
        <div class="field">
          <label for="mottagare">Mottagare</label>
          <div ref="recipientAutocompleteRef" class="autocomplete-field">
            <InputText
              id="mottagare"
              v-model="orderForm.mottagare"
              autocomplete="off"
              aria-autocomplete="list"
              :aria-expanded="recipientAutocompleteOpen && visibleRecipientSuggestions.length > 0"
              @input="handleRecipientInput"
              @focus="handleRecipientFocus"
              @blur="handleRecipientBlur"
            />
            <div
              v-if="recipientAutocompleteOpen && visibleRecipientSuggestions.length"
              class="autocomplete-panel"
              role="listbox"
            >
              <button
                v-for="suggestion in visibleRecipientSuggestions"
                :key="suggestion.id"
                type="button"
                class="autocomplete-option"
                role="option"
                @mousedown.prevent="selectRecipientSuggestion(suggestion)"
                @touchstart.prevent="selectRecipientSuggestion(suggestion)"
              >
                <span>{{ suggestion.value }}</span>
                <small>{{ [suggestion.phone, suggestion.source].filter(Boolean).join(' · ') }}</small>
              </button>
            </div>
          </div>
          <span v-if="orderForm.errors.mottagare" class="field-error">{{ orderForm.errors.mottagare }}</span>
        </div>
        <div class="field">
          <label for="tele">Telefon</label>
          <div class="input-with-action">
            <InputText id="tele" v-model="orderForm.tele" inputmode="tel" />
            <a v-if="phoneHref(orderForm.tele)" class="inline-action" :href="phoneHref(orderForm.tele)" aria-label="Ring mottagare">
              <i class="pi pi-phone"></i>
            </a>
          </div>
        </div>
        <div class="field full-span">
          <label for="adress">Adress</label>
          <div class="input-with-action">
            <InputText id="adress" v-model="orderForm.adress" list="address-suggestions" autocomplete="off" />
            <a v-if="mapsHref(orderForm.adress)" class="inline-action" :href="mapsHref(orderForm.adress)" target="_blank" rel="noopener noreferrer" aria-label="Öppna adress i karta">
              <i class="pi pi-map-marker"></i>
            </a>
          </div>
          <datalist id="address-suggestions">
            <option v-for="address in addressSuggestions" :key="address" :value="address" />
          </datalist>
          <span v-if="orderForm.errors.adress" class="field-error">{{ orderForm.errors.adress }}</span>
        </div>
        <div class="field">
          <label for="desiredDeliveryDate">Datum</label>
          <div class="input-with-icon" @click="openNativePicker">
            <InputText
              id="desiredDeliveryDate"
              v-model="orderForm.desiredDeliveryDate"
              type="date"
              class="picker-input"
            />
            <i class="pi pi-calendar picker-icon"></i>
          </div>
        </div>
        <div class="field">
          <label for="desiredDeliveryTime">Tid</label>
          <Select
            id="desiredDeliveryTime"
            v-model="orderForm.desiredDeliveryTime"
            :options="timeOptions"
            option-label="label"
            option-value="value"
            filter
            placeholder="Välj 24h-tid"
          />
          <small v-if="deliveryTimeConflict" class="field-warning">
            Tiden är redan bokad för {{ deliveryTimeConflict.mottagare || 'en annan leverans' }}.
          </small>
        </div>
        <div class="field" style="grid-column: 1 / -1">
          <label for="notes">Anteckning</label>
          <Textarea id="notes" v-model="orderForm.notes" rows="3" auto-resize />
        </div>
        <div class="field" style="grid-column: 1 / -1">
          <label>Artiklar</label>
          <div v-for="(item, index) in orderForm.items" :key="index" class="item-row">
            <div class="item-article-cell">
              <Select
                v-if="usesWorkOrderArticleDropdown(item)"
                v-model="item.selectedArticle"
                :options="item.articleOptions"
                option-label="label"
                :option-disabled="(option) => option.disabled"
                placeholder="Välj artikel"
                filter
                :loading="item.loadingArticles"
                @change="onWorkOrderArticleSelected(item)"
              >
                <template #empty>
                  <span class="article-option-empty">
                    {{ item.loadingArticles ? 'Hämtar artiklar...' : 'Inga artiklar att visa ännu.' }}
                  </span>
                </template>
                <template #option="{ option }">
                  <div class="article-option">
                    <strong>{{ option.article_number }} — {{ option.name }}</strong>
                    <small>Kvar: {{ option.remaining_quantity ?? '?' }} {{ option.unit || 'st' }}</small>
                    <small v-if="option.warning" class="field-warning">{{ option.warning }}</small>
                  </div>
                </template>
                <template #value="{ value }">
                  <span v-if="value">{{ value.article_number }} — {{ value.name }}</span>
                  <span v-else>Välj artikel</span>
                </template>
              </Select>
              <InputText
                v-else
                v-model="item.artikel"
                placeholder="Artikel"
                autocomplete="off"
                autocorrect="off"
                autocapitalize="sentences"
                spellcheck="false"
                @input="onManualArticleInput(item)"
                @focus="openManualProductDropdown(item)"
                @blur="closeManualProductDropdown(item)"
              />
              <div v-if="!usesWorkOrderArticleDropdown(item) && item.manualProductDropdownOpen" class="manual-product-dropdown">
                <button
                  v-for="product in item.manualProductOptions"
                  :key="product.sku"
                  type="button"
                  class="manual-product-option"
                  @mousedown.prevent="selectManualProduct(item, product)"
                  @touchstart.prevent="selectManualProduct(item, product)"
                >
                  <span class="manual-product-image">
                    <img
                      v-if="product.imageUrl"
                      :src="product.imageUrl"
                      :alt="product.title || product.sku || 'Produktbild'"
                      width="44"
                      height="44"
                      loading="lazy"
                      decoding="async"
                      fetchpriority="low"
                    >
                    <i v-else class="pi pi-box"></i>
                  </span>
                  <span class="manual-product-copy">
                    <strong>{{ product.sku }}</strong>
                    <span>{{ product.title || product.sku }}</span>
                    <small>Lager {{ product.stockRemaining ?? 0 }} kvar av {{ product.stockTotal ?? 0 }}</small>
                  </span>
                </button>
              </div>
              <div v-if="item.productPreview" class="product-preview">
                <a v-if="item.productPreview.imageUrl" class="product-image-link" :href="item.productPreview.imageUrl" target="_blank" rel="noopener noreferrer" title="Öppna originalbild">
                  <img
                    :src="item.productPreview.imageUrl"
                    :alt="item.productPreview.title || item.productPreview.sku || 'Produktbild'"
                    width="52"
                    height="52"
                    loading="lazy"
                    decoding="async"
                    fetchpriority="low"
                  >
                </a>
                <div>
                  <strong>{{ item.productPreview.sku }}</strong>
                  <span>{{ item.productPreview.title }}</span>
                  <small>Lager {{ item.productPreview.stockRemaining }} kvar av {{ item.productPreview.stockTotal }}</small>
                </div>
              </div>
            </div>
            <InputText v-model="item.antal" placeholder="Antal" />
            <InputText
              v-model="item.workOrderNumber"
              placeholder="Arbetsorder"
              inputmode="numeric"
              :disabled="item.isInternalWorkOrder"
              @input="scheduleWorkOrderArticleLookup(item)"
              @blur="lookupWorkOrderForItem(item)"
            />
            <Button type="button" icon="pi pi-times" text rounded severity="danger" @click="removeItem(index)" />
            <small v-if="item.workOrderArticleInfo" class="field-info item-warning">
              {{ item.workOrderArticleInfo }}
            </small>
            <small v-if="item.workOrderMatchWarning" class="field-warning item-warning">
              {{ item.workOrderMatchWarning }}
            </small>
            <small v-if="orderItemError(index, 'artikel') || orderItemError(index, 'workOrderNumber')" class="field-error item-warning">
              {{ orderItemError(index, 'artikel') || orderItemError(index, 'workOrderNumber') }}
            </small>
          </div>
          <span v-if="orderForm.errors.items" class="field-error">{{ orderForm.errors.items }}</span>
          <Button type="button" label="Lägg till rad" icon="pi pi-plus" severity="secondary" outlined @click="addItem" />
        </div>
      </form>

      <template #footer>
        <Button
          v-if="canUndoDeliveredOrder"
          label="Ångra levererad"
          icon="pi pi-undo"
          severity="warning"
          outlined
          :disabled="orderForm.processing"
          @click="undoDeliveredOrder"
        />
        <Button label="Avbryt" severity="secondary" outlined @click="orderDialog = false" />
        <Button label="Spara" icon="pi pi-save" :loading="orderForm.processing" :disabled="cannotSaveOrder" @click="saveOrder" />
      </template>
    </Dialog>

    <Dialog
      v-model:visible="workOrderDetailDialog"
      modal
      :header="selectedWorkOrderOrder ? deliveryWorkOrderLabel(selectedWorkOrderOrder) : 'Arbetsorder'"
      class="work-order-detail-dialog"
      :style="{ width: 'min(1120px, 96vw)' }"
    >
      <section v-if="selectedWorkOrderOrder && selectedWorkOrderCard" class="work-order-detail">
        <header class="work-order-detail-header">
          <div class="work-order-detail-title">
            <span class="toolbar-marker"><i class="pi pi-briefcase"></i></span>
            <div>
              <strong>{{ deliveryWorkOrderLabel(selectedWorkOrderOrder) }}</strong>
              <span>{{ selectedWorkOrderOrder.adress || 'Adress saknas' }}</span>
            </div>
          </div>

          <div class="work-order-detail-actions">
            <a v-if="phoneHref(selectedWorkOrderOrder.tele)" class="icon-action" :href="phoneHref(selectedWorkOrderOrder.tele)" aria-label="Ring" title="Ring">
              <i class="pi pi-phone"></i>
            </a>
            <a v-if="smsHref(selectedWorkOrderOrder.tele)" class="icon-action" :href="smsHref(selectedWorkOrderOrder.tele)" aria-label="SMS" title="SMS">
              <i class="pi pi-send"></i>
            </a>
            <a v-if="mapsHref(selectedWorkOrderOrder.adress)" class="icon-action" :href="mapsHref(selectedWorkOrderOrder.adress)" target="_blank" rel="noopener noreferrer" aria-label="Öppna karta" title="Öppna karta">
              <i class="pi pi-map-marker"></i>
            </a>
            <Button v-if="can('deliveries.update')" icon="pi pi-pencil" label="Redigera" severity="secondary" outlined @click="openEditOrder(selectedWorkOrderOrder)" />
            <Button v-if="can('deliveries.update_status')" icon="pi pi-play" label="Starta" severity="success" outlined @click="startOrder(selectedWorkOrderOrder)" />
            <Button v-if="can('deliveries.update_status')" icon="pi pi-box" label="Packad bil" severity="info" outlined @click="setStatus(selectedWorkOrderOrder, 'packed')" />
            <Button v-if="can('deliveries.update_status')" icon="pi pi-check" label="Levererad" severity="secondary" outlined @click="setStatus(selectedWorkOrderOrder, 'delivered')" />
            <Button
              v-if="selectedWorkOrderOrder.status === 'delivered' && can('deliveries.update_status')"
              icon="pi pi-undo"
              label="Ångra levererad"
              severity="warning"
              outlined
              @click="undoDeliveredOrderFromDetail(selectedWorkOrderOrder)"
            />
            <Button v-if="can('deliveries.delete')" icon="pi pi-trash" label="Ta bort" severity="danger" outlined @click="deleteOrder(selectedWorkOrderOrder)" />
          </div>
        </header>

        <div class="work-order-detail-grid">
          <article class="work-order-detail-card">
            <span>Mottagare</span>
            <strong>{{ selectedWorkOrderOrder.mottagare || 'Okänd mottagare' }}</strong>
          </article>
          <article class="work-order-detail-card">
            <span>Telefon</span>
            <strong>{{ selectedWorkOrderOrder.tele || '-' }}</strong>
          </article>
          <article class="work-order-detail-card">
            <span>Status</span>
            <Tag :value="statusLabels[selectedWorkOrderOrder.status] || selectedWorkOrderOrder.status" :severity="statusSeverity(selectedWorkOrderOrder.status)" />
          </article>
          <article class="work-order-detail-card">
            <span>Planerad tid</span>
            <strong>{{ selectedWorkOrderCard.deliveryDate }}</strong>
          </article>
        </div>

        <div class="work-order-detail-body">
          <section class="work-order-detail-section">
            <h3>Artiklar</h3>
            <div class="work-order-detail-items">
              <article v-for="productRow in selectedWorkOrderCard.productRows" :key="productRow.key" class="work-order-detail-item">
                <a v-if="productRow.imageUrl" class="work-order-detail-image" :href="productRow.imageUrl" target="_blank" rel="noopener noreferrer">
                  <img :src="productRow.imageUrl" :alt="productRow.imageAlt" width="64" height="64" loading="lazy" decoding="async">
                </a>
                <span v-else class="work-order-detail-image">
                  <i class="pi pi-box"></i>
                </span>
                <div class="work-order-detail-item-copy">
                  <strong>{{ productRow.title }}</strong>
                  <span>Artikel: {{ productRow.article }}</span>
                </div>
                <div class="work-order-detail-item-quantity">
                  <strong>{{ productRow.quantityText || '-' }}</strong>
                  <span v-if="productRow.deliveredText">{{ productRow.deliveredText }}</span>
                </div>
              </article>
            </div>
          </section>

          <section class="work-order-detail-section work-order-detail-purchases">
            <h3>Inköp kopplade till arbetsorder</h3>
            <div v-if="selectedWorkOrderPurchases.length" class="work-order-detail-purchase-list">
              <article v-for="purchase in selectedWorkOrderPurchases" :key="purchase.id" class="work-order-detail-purchase">
                <img
                  v-if="purchase.thumbnailUrl || purchase.imageUrl"
                  class="work-order-detail-purchase-image"
                  :src="purchase.thumbnailUrl || purchase.imageUrl"
                  :alt="purchase.itemName || 'Inköpsbild'"
                  width="44"
                  height="44"
                  loading="lazy"
                  decoding="async"
                >
                <span v-else class="work-order-detail-purchase-image">
                  <i class="pi pi-shopping-cart"></i>
                </span>
                <div class="work-order-detail-purchase-copy">
                  <strong>{{ purchase.itemName || 'Inköp utan artikelnamn' }}</strong>
                  <span>{{ purchase.quantity }} st · {{ formatMoney(purchase.totalGross || purchase.unitPrice, purchase.currency) }}</span>
                  <small>{{ purchase.storeName || 'Butik saknas' }}</small>
                  <small v-if="purchase.availabilityAtSelection">{{ purchase.availabilityAtSelection }}</small>
                  <a v-if="purchase.productUrl" :href="purchase.productUrl" target="_blank" rel="noopener noreferrer">Öppna produkt</a>
                </div>
              </article>
            </div>
            <p v-else>Inga inköp är kopplade till denna arbetsorder.</p>
          </section>
        </div>

        <section v-if="selectedWorkOrderOrder.notes || selectedWorkOrderOrder.internalComment" class="work-order-detail-section">
          <h3>Anteckningar</h3>
          <p v-if="selectedWorkOrderOrder.notes">{{ selectedWorkOrderOrder.notes }}</p>
          <p v-if="selectedWorkOrderOrder.internalComment">{{ selectedWorkOrderOrder.internalComment }}</p>
        </section>
      </section>

      <template #footer>
        <Button label="Stäng" severity="secondary" outlined @click="workOrderDetailDialog = false" />
      </template>
    </Dialog>

    <Dialog v-model:visible="purchaseDialog" modal :header="editingPurchaseId ? 'Redigera inköp' : 'Nytt inköp'" class="form-dialog" :style="{ width: 'min(780px, 96vw)' }">
      <form class="form-grid" @submit.prevent="savePurchase">
        <div class="field">
          <label for="purchaseQuantity">Antal artiklar</label>
          <InputText id="purchaseQuantity" v-model="purchaseForm.quantity" type="number" min="1" inputmode="numeric" />
          <span v-if="purchaseForm.errors.quantity" class="field-error">{{ purchaseForm.errors.quantity }}</span>
        </div>
        <div class="field">
          <label for="purchaseItem">Artikel att köpa in</label>
          <InputText
            id="purchaseItem"
            v-model="purchaseForm.item_name"
            autocomplete="off"
            placeholder="Skriv artikel att köpa in..."
            @input="schedulePurchaseSearch"
          />
          <span v-if="purchaseForm.errors.item_name" class="field-error">{{ purchaseForm.errors.item_name }}</span>
        </div>
        <div class="field">
          <label for="purchaseSku">Artikelnummer</label>
          <InputText
            id="purchaseSku"
            v-model="purchaseForm.sku"
            autocomplete="off"
            autocapitalize="sentences"
            spellcheck="false"
            placeholder="Skriv eller skanna artikelnummer..."
            @input="schedulePurchaseSearch"
          />
          <span v-if="purchaseForm.errors.sku" class="field-error">{{ purchaseForm.errors.sku }}</span>
        </div>
        <div class="field">
          <label for="purchaseWorkOrderNumber">Arbetsorder</label>
          <InputText
            id="purchaseWorkOrderNumber"
            v-model="purchaseForm.work_order_number"
            autocomplete="off"
            list="purchaseWorkOrderNumbers"
            placeholder="Internt eller befintligt arbetsordernummer"
            @input="handlePurchaseWorkOrderInput"
            @change="applyPurchaseWorkOrderDetails"
            @blur="applyPurchaseWorkOrderDetails"
          />
          <datalist id="purchaseWorkOrderNumbers">
            <option v-for="number in purchaseWorkOrderNumbers" :key="number" :value="number" />
          </datalist>
          <span v-if="purchaseForm.errors.work_order_number" class="field-error">{{ purchaseForm.errors.work_order_number }}</span>
        </div>
        <div class="field full-span purchase-search-panel">
          <div v-if="purchaseSearch.loading" class="purchase-search-status">
            <i class="pi pi-spin pi-spinner"></i>
            <span>{{ purchaseSearch.message || 'Söker priser, varuhus och lagerstatus hos godkända leverantörer...' }}</span>
          </div>
          <div v-else-if="purchaseSearch.message" class="purchase-search-status purchase-search-warning">
            <i class="pi pi-info-circle"></i>
            <span>{{ purchaseSearch.message }}</span>
          </div>
          <div v-if="purchaseSearch.searched && !purchaseSearch.loading && !purchaseSearch.results.length" class="purchase-search-empty">
            Inga träffar hittades hos Beijer, Bygma, Bauhaus, Biltema, Jula, Swedol, XL-Bygg eller Hornbach.
          </div>
          <div v-if="purchaseSearch.results.length" class="purchase-search-results">
            <button
              v-for="result in purchaseSearch.results"
              :key="`${result.supplier}-${result.store}-${result.sku || result.productUrl || result.title}`"
              type="button"
              class="purchase-search-result"
              @click="selectPurchaseSearchResult(result)"
            >
              <span class="purchase-search-result-image">
                <img
                  v-if="result.imageUrl"
                  :src="result.imageUrl"
                  :alt="result.title || 'Produktbild'"
                  width="48"
                  height="48"
                  loading="lazy"
                  decoding="async"
                >
                <i v-else class="pi pi-image"></i>
              </span>
              <span class="purchase-search-result-main">
                <strong>{{ result.title }}</strong>
                <small>Varuhus: {{ result.supplier }} {{ result.store }}</small>
                <small v-if="result.distanceKm !== null && result.distanceKm !== undefined">Avstånd: ca {{ result.distanceKm }} km</small>
                <span class="purchase-search-result-article">
                  Artikelnummer: {{ result.articleNumber || result.sku || 'saknas' }}
                </span>
              </span>
              <span class="purchase-search-result-price">
                {{ formatMoney(result.price, result.currency) }}
                <small>{{ result.availability }}</small>
                <small v-if="purchaseStockText(result)" class="purchase-search-stock">{{ purchaseStockText(result) }}</small>
                <small v-if="purchaseStockLocations(result)" class="purchase-search-stock">{{ purchaseStockLocations(result) }}</small>
              </span>
              <span class="purchase-search-result-links" @click.stop>
                <a v-if="result.productUrl" :href="result.productUrl" target="_blank" rel="noopener noreferrer">Öppna produkt</a>
                <a :href="result.mapsUrl" target="_blank" rel="noopener noreferrer">{{ result.mapsLabel }}</a>
              </span>
            </button>
          </div>
        </div>
        <div class="field">
          <label for="purchaseStore">Butik / inköpsställe</label>
          <InputText id="purchaseStore" v-model="purchaseForm.store_name" autocomplete="off" />
          <span v-if="purchaseForm.errors.store_name" class="field-error">{{ purchaseForm.errors.store_name }}</span>
        </div>
        <div class="field">
          <label for="purchaseUnitPrice">Styckpris exkl. moms</label>
          <InputText id="purchaseUnitPrice" v-model="purchaseForm.unit_price" type="number" min="0" step="0.01" inputmode="decimal" />
          <span v-if="purchaseForm.errors.unit_price" class="field-error">{{ purchaseForm.errors.unit_price }}</span>
        </div>
        <div class="field">
          <label for="purchaseVat">Moms %</label>
          <InputText id="purchaseVat" v-model="purchaseForm.vat_rate" type="number" min="0" max="100" step="0.01" inputmode="decimal" />
          <span v-if="purchaseForm.errors.vat_rate" class="field-error">{{ purchaseForm.errors.vat_rate }}</span>
        </div>
        <div class="field">
          <label>Lagerstatus vid val</label>
          <InputText v-model="purchaseForm.availability_at_selection" autocomplete="off" />
          <span v-if="purchaseForm.errors.availability_at_selection" class="field-error">{{ purchaseForm.errors.availability_at_selection }}</span>
        </div>
        <div class="field purchase-selected-summary">
          <label>Total inkl. moms</label>
          <strong>{{ formatMoney(purchaseGrossTotal(purchaseForm.quantity, purchaseForm.unit_price, purchaseForm.vat_rate), purchaseForm.currency) }}</strong>
          <a v-if="purchaseForm.product_url" :href="purchaseForm.product_url" target="_blank" rel="noopener noreferrer">Öppna produkt</a>
          <a v-if="purchaseForm.maps_url" :href="purchaseForm.maps_url" target="_blank" rel="noopener noreferrer">{{ purchaseForm.maps_label || 'Kör med Google Maps' }}</a>
        </div>
        <div class="field">
          <label for="purchaseRecipient">Mottagare</label>
          <div ref="purchaseRecipientAutocompleteRef" class="autocomplete-field">
            <InputText
              id="purchaseRecipient"
              v-model="purchaseForm.recipient_name"
              autocomplete="off"
              aria-autocomplete="list"
              :aria-expanded="purchaseRecipientAutocompleteOpen && visiblePurchaseRecipientSuggestions.length > 0"
              @input="handlePurchaseRecipientInput"
              @focus="handlePurchaseRecipientFocus"
              @blur="handlePurchaseRecipientBlur"
            />
            <div
              v-if="purchaseRecipientAutocompleteOpen && visiblePurchaseRecipientSuggestions.length"
              class="autocomplete-panel"
              role="listbox"
            >
              <button
                v-for="suggestion in visiblePurchaseRecipientSuggestions"
                :key="suggestion.id"
                type="button"
                class="autocomplete-option"
                role="option"
                @mousedown.prevent="selectPurchaseRecipientSuggestion(suggestion)"
                @touchstart.prevent="selectPurchaseRecipientSuggestion(suggestion)"
              >
                <span>{{ suggestion.value }}</span>
                <small>{{ [suggestion.phone, suggestion.source].filter(Boolean).join(' · ') }}</small>
              </button>
            </div>
          </div>
          <span v-if="purchaseForm.errors.recipient_name" class="field-error">{{ purchaseForm.errors.recipient_name }}</span>
        </div>
        <div class="field">
          <label for="purchaseRecipientPhone">Telefon mottagare</label>
          <InputText id="purchaseRecipientPhone" v-model="purchaseForm.recipient_phone" autocomplete="tel" />
          <span v-if="purchaseForm.errors.recipient_phone" class="field-error">{{ purchaseForm.errors.recipient_phone }}</span>
          <span v-if="purchaseForm.recipient_phone" class="contact-actions">
            <a v-if="phoneHref(purchaseForm.recipient_phone)" :href="phoneHref(purchaseForm.recipient_phone)">Ringa</a>
            <a v-if="smsHref(purchaseForm.recipient_phone)" :href="smsHref(purchaseForm.recipient_phone)">SMS</a>
          </span>
        </div>
        <div class="field full-span">
          <label for="purchaseAddress">Levereras till</label>
          <InputText id="purchaseAddress" v-model="purchaseForm.delivery_address" autocomplete="off" />
          <span v-if="purchaseForm.errors.delivery_address" class="field-error">{{ purchaseForm.errors.delivery_address }}</span>
        </div>
        <div class="field">
          <label for="purchaseStatus">Status</label>
          <Select id="purchaseStatus" v-model="purchaseForm.status" :options="purchaseStatusOptions" option-label="label" option-value="value" />
          <span v-if="purchaseForm.errors.status" class="field-error">{{ purchaseForm.errors.status }}</span>
        </div>
        <div class="field full-span">
          <label for="purchaseNotes">Anteckning</label>
          <Textarea id="purchaseNotes" v-model="purchaseForm.notes" rows="3" auto-resize />
          <span v-if="purchaseForm.errors.notes" class="field-error">{{ purchaseForm.errors.notes }}</span>
        </div>
      </form>

      <template #footer>
        <Button label="Avbryt" severity="secondary" outlined @click="purchaseDialog = false" />
        <Button label="Spara" icon="pi pi-save" :loading="purchaseForm.processing" @click="savePurchase" />
      </template>
    </Dialog>

    <Dialog v-model:visible="profileDialog" modal header="Profil och meddelanden" class="form-dialog profile-dialog" :style="{ width: 'min(1040px, 96vw)' }">
      <div class="profile-mail-layout">
        <aside class="profile-sidebar" aria-label="Profilmeny">
          <button type="button" :class="profileMenuClass('profile')" @click="messageTab = 'profile'">
            <i class="pi pi-user"></i>
            <span>Min profil</span>
          </button>
          <button type="button" :class="profileMenuClass('profiles')" @click="messageTab = 'profiles'">
            <i class="pi pi-users"></i>
            <span>Profiler</span>
          </button>
          <button type="button" :class="profileMenuClass('inbox')" @click="messageTab = 'inbox'">
            <i class="pi pi-inbox"></i>
            <span>Inkorg</span>
            <strong v-if="props.messages.unreadCount" class="tab-badge">{{ props.messages.unreadCount }}</strong>
          </button>
          <button type="button" :class="profileMenuClass('outbox')" @click="messageTab = 'outbox'">
            <i class="pi pi-send"></i>
            <span>Utkorg</span>
          </button>
          <button type="button" :class="profileMenuClass('deleted')" @click="messageTab = 'deleted'">
            <i class="pi pi-trash"></i>
            <span>Raderade</span>
          </button>
          <button type="button" :class="profileMenuClass('compose')" @click="messageTab = 'compose'">
            <i class="pi pi-pencil"></i>
            <span>Nytt meddelande</span>
          </button>
        </aside>

        <section class="profile-content">
          <form v-if="messageTab === 'profile'" class="form-grid profile-form" @submit.prevent="saveProfile">
            <div class="profile-image-panel">
              <img v-if="props.user.photoUrl" class="profile-avatar-large" :src="props.user.photoUrl" :alt="props.user.name || props.user.email">
              <span v-else class="profile-avatar-large profile-avatar-empty">
                <i class="pi pi-user"></i>
              </span>
              <strong>{{ props.user.name || props.user.email }}</strong>
              <span class="muted">{{ props.user.roleLabel }}</span>
              <Button v-if="props.user.photoUrl" label="Ta bort bild" icon="pi pi-trash" severity="danger" text @click="deleteProfileImage" />
            </div>
            <div class="profile-current-info">
              <h3>Nuvarande information</h3>
              <dl class="profile-info-list">
                <div>
                  <dt>E-post</dt>
                  <dd><a v-if="mailHref(props.user.email)" :href="mailHref(props.user.email)">{{ props.user.email }}</a><span v-else>-</span></dd>
                </div>
                <div>
                  <dt>Telefon</dt>
                  <dd class="contact-actions">
                    <span>{{ props.user.phone || '-' }}</span>
                    <a v-if="phoneHref(props.user.phone)" :href="phoneHref(props.user.phone)">Ringa</a>
                    <a v-if="smsHref(props.user.phone)" :href="smsHref(props.user.phone)">SMS</a>
                  </dd>
                </div>
                <div>
                  <dt>Roll</dt>
                  <dd>{{ props.user.roleLabel || props.user.role }}</dd>
                </div>
              </dl>
            </div>
            <div class="field">
              <label for="profileEmail">Ändra e-post</label>
              <InputText id="profileEmail" v-model="profileForm.email" type="email" autocomplete="email" />
            </div>
            <div class="field">
              <label for="profilePhone">Ändra telefon</label>
              <InputText id="profilePhone" v-model="profileForm.phone" autocomplete="tel" />
            </div>
            <div class="field full-span">
              <label for="profileImage">Profilbild</label>
              <input id="profileImage" class="native-file-input" type="file" accept="image/png,image/jpeg,image/webp" @change="onProfileImageChange">
              <small class="muted">Tillåtna format: JPG, PNG och WebP. Max 4 MB.</small>
            </div>
            <div class="form-actions full-span">
              <Button label="Spara profil" icon="pi pi-save" :loading="profileForm.processing" @click="saveProfile" />
            </div>
          </form>

          <section v-else-if="messageTab === 'profiles'" class="profile-directory">
            <div class="field">
              <label for="profileUserSearch">Sök profiler</label>
              <InputText id="profileUserSearch" v-model="profileUserSearch" placeholder="Sök namn, e-post, telefon eller roll..." />
            </div>
            <div class="profile-directory-grid">
              <aside class="profile-user-list">
                <button
                  v-for="user in filteredProfileUsers"
                  :key="user.id"
                  type="button"
                  :class="['profile-user-row', { 'is-active': selectedProfileUser.id === user.id }]"
                  @click.prevent="selectProfileUser(user)"
                >
                  <img v-if="user.photoUrl" :src="user.photoUrl" :alt="user.name || user.email">
                  <span v-else><i class="pi pi-user"></i></span>
                  <strong>{{ user.name || user.email }}</strong>
                </button>
              </aside>
              <article class="profile-public-card">
                <img v-if="selectedProfileUser.photoUrl" class="profile-avatar-large" :src="selectedProfileUser.photoUrl" :alt="selectedProfileUser.name || selectedProfileUser.email">
                <span v-else class="profile-avatar-large profile-avatar-empty"><i class="pi pi-user"></i></span>
                <h3>{{ selectedProfileUser.name || selectedProfileUser.email }}</h3>
                <p class="muted">{{ selectedProfileUser.roleLabel || selectedProfileUser.role }}</p>
                <dl class="profile-info-list">
                  <div>
                    <dt>E-post</dt>
                    <dd><a v-if="mailHref(selectedProfileUser.email)" :href="mailHref(selectedProfileUser.email)">{{ selectedProfileUser.email }}</a><span v-else>-</span></dd>
                  </div>
                  <div>
                    <dt>Telefon</dt>
                    <dd class="contact-actions">
                      <span>{{ selectedProfileUser.phone || '-' }}</span>
                      <a v-if="phoneHref(selectedProfileUser.phone)" :href="phoneHref(selectedProfileUser.phone)">Ringa</a>
                      <a v-if="smsHref(selectedProfileUser.phone)" :href="smsHref(selectedProfileUser.phone)">SMS</a>
                    </dd>
                  </div>
                </dl>
                <Button
                  v-if="selectedProfileUser.id !== props.user.id"
                  label="Skicka meddelande"
                  icon="pi pi-send"
                  @click="messageForm.recipientId = selectedProfileUser.id; messageTab = 'compose'"
                />
              </article>
            </div>
          </section>

          <div v-else-if="messageTab === 'inbox'" class="message-list">
            <article v-for="message in messageRowsForTab('inbox')" :key="message.id" :class="['message-card', { 'is-unread': !message.readAt }]">
              <div class="message-card-header">
                <strong>{{ message.subject }}</strong>
                <small>{{ message.createdAt }}</small>
              </div>
              <p class="muted">Från: {{ message.senderName }}</p>
              <div v-if="packedDeliveryMessage(message)" class="packed-message-card">
                <header class="packed-message-hero">
                  <span class="packed-message-icon"><i class="pi pi-box"></i></span>
                  <div>
                    <strong>Leveransen är packad</strong>
                    <span>Bilen är packad och redo för leverans.</span>
                  </div>
                </header>
                <dl class="packed-message-meta">
                  <div>
                    <dt>Mottagare</dt>
                    <dd>{{ packedDeliveryMessage(message).recipient || '-' }}</dd>
                  </div>
                  <div>
                    <dt>Adress</dt>
                    <dd>{{ packedDeliveryMessage(message).address || '-' }}</dd>
                  </div>
                  <div>
                    <dt>Leveranstid</dt>
                    <dd>{{ packedDeliveryMessage(message).deliveryTime || '-' }}</dd>
                  </div>
                  <div>
                    <dt>Leverans-ID</dt>
                    <dd>{{ packedDeliveryMessage(message).orderId || '-' }}</dd>
                  </div>
                </dl>
                <section class="packed-message-items">
                  <h4>Packat i bilen</h4>
                  <article v-for="item in packedDeliveryMessage(message).items" :key="`${message.id}-${item.number}-${item.article}`" class="packed-message-item">
                    <span class="packed-message-item-number">{{ item.number }}</span>
                    <span class="packed-message-item-copy">
                      <strong>{{ item.article }}</strong>
                      <small>Arbetsorder: {{ item.workOrder || '-' }}</small>
                    </span>
                    <span class="packed-message-item-quantity">{{ item.quantity }} {{ item.unit }}</span>
                  </article>
                  <p v-if="!packedDeliveryMessage(message).items.length" class="packed-message-empty">Inga artiklar registrerade.</p>
                </section>
                <p v-if="packedDeliveryMessage(message).footer" class="packed-message-footer">{{ packedDeliveryMessage(message).footer }}</p>
              </div>
              <p v-else class="message-body">{{ message.body }}</p>
              <div class="actions">
                <Button v-if="!message.readAt" label="Markera läst" icon="pi pi-check" size="small" text @click="markMessageRead(message)" />
                <Button label="Radera" icon="pi pi-trash" size="small" severity="danger" text @click="deleteMessage(message)" />
              </div>
            </article>
            <p v-if="!messageRowsForTab('inbox').length" class="empty-copy">Inkorgen är tom.</p>
          </div>

          <div v-else-if="messageTab === 'outbox'" class="message-list">
            <article v-for="message in messageRowsForTab('outbox')" :key="message.id" class="message-card">
              <div class="message-card-header">
                <strong>{{ message.subject }}</strong>
                <small>{{ message.createdAt }}</small>
              </div>
              <p class="muted">Till: {{ message.recipientName }}</p>
              <div v-if="packedDeliveryMessage(message)" class="packed-message-card">
                <header class="packed-message-hero">
                  <span class="packed-message-icon"><i class="pi pi-box"></i></span>
                  <div>
                    <strong>Leveransen är packad</strong>
                    <span>Bilen är packad och redo för leverans.</span>
                  </div>
                </header>
                <dl class="packed-message-meta">
                  <div>
                    <dt>Mottagare</dt>
                    <dd>{{ packedDeliveryMessage(message).recipient || '-' }}</dd>
                  </div>
                  <div>
                    <dt>Adress</dt>
                    <dd>{{ packedDeliveryMessage(message).address || '-' }}</dd>
                  </div>
                  <div>
                    <dt>Leveranstid</dt>
                    <dd>{{ packedDeliveryMessage(message).deliveryTime || '-' }}</dd>
                  </div>
                  <div>
                    <dt>Leverans-ID</dt>
                    <dd>{{ packedDeliveryMessage(message).orderId || '-' }}</dd>
                  </div>
                </dl>
                <section class="packed-message-items">
                  <h4>Packat i bilen</h4>
                  <article v-for="item in packedDeliveryMessage(message).items" :key="`${message.id}-${item.number}-${item.article}`" class="packed-message-item">
                    <span class="packed-message-item-number">{{ item.number }}</span>
                    <span class="packed-message-item-copy">
                      <strong>{{ item.article }}</strong>
                      <small>Arbetsorder: {{ item.workOrder || '-' }}</small>
                    </span>
                    <span class="packed-message-item-quantity">{{ item.quantity }} {{ item.unit }}</span>
                  </article>
                  <p v-if="!packedDeliveryMessage(message).items.length" class="packed-message-empty">Inga artiklar registrerade.</p>
                </section>
                <p v-if="packedDeliveryMessage(message).footer" class="packed-message-footer">{{ packedDeliveryMessage(message).footer }}</p>
              </div>
              <p v-else class="message-body">{{ message.body }}</p>
              <div class="actions">
                <Button label="Radera" icon="pi pi-trash" size="small" severity="danger" text @click="deleteMessage(message)" />
              </div>
            </article>
            <p v-if="!messageRowsForTab('outbox').length" class="empty-copy">Utkorgen är tom.</p>
          </div>

          <div v-else-if="messageTab === 'deleted'" class="message-list">
            <article v-for="message in messageRowsForTab('deleted')" :key="message.id" class="message-card">
              <div class="message-card-header">
                <strong>{{ message.subject }}</strong>
                <small>{{ message.updatedAt }}</small>
              </div>
              <p class="muted">Från: {{ message.senderName }} · Till: {{ message.recipientName }}</p>
              <div v-if="packedDeliveryMessage(message)" class="packed-message-card">
                <header class="packed-message-hero">
                  <span class="packed-message-icon"><i class="pi pi-box"></i></span>
                  <div>
                    <strong>Leveransen är packad</strong>
                    <span>Bilen är packad och redo för leverans.</span>
                  </div>
                </header>
                <dl class="packed-message-meta">
                  <div>
                    <dt>Mottagare</dt>
                    <dd>{{ packedDeliveryMessage(message).recipient || '-' }}</dd>
                  </div>
                  <div>
                    <dt>Adress</dt>
                    <dd>{{ packedDeliveryMessage(message).address || '-' }}</dd>
                  </div>
                  <div>
                    <dt>Leveranstid</dt>
                    <dd>{{ packedDeliveryMessage(message).deliveryTime || '-' }}</dd>
                  </div>
                  <div>
                    <dt>Leverans-ID</dt>
                    <dd>{{ packedDeliveryMessage(message).orderId || '-' }}</dd>
                  </div>
                </dl>
                <section class="packed-message-items">
                  <h4>Packat i bilen</h4>
                  <article v-for="item in packedDeliveryMessage(message).items" :key="`${message.id}-${item.number}-${item.article}`" class="packed-message-item">
                    <span class="packed-message-item-number">{{ item.number }}</span>
                    <span class="packed-message-item-copy">
                      <strong>{{ item.article }}</strong>
                      <small>Arbetsorder: {{ item.workOrder || '-' }}</small>
                    </span>
                    <span class="packed-message-item-quantity">{{ item.quantity }} {{ item.unit }}</span>
                  </article>
                  <p v-if="!packedDeliveryMessage(message).items.length" class="packed-message-empty">Inga artiklar registrerade.</p>
                </section>
                <p v-if="packedDeliveryMessage(message).footer" class="packed-message-footer">{{ packedDeliveryMessage(message).footer }}</p>
              </div>
              <p v-else class="message-body">{{ message.body }}</p>
              <div class="actions">
                <Button label="Återställ" icon="pi pi-undo" size="small" text @click="restoreMessage(message)" />
              </div>
            </article>
            <p v-if="!messageRowsForTab('deleted').length" class="empty-copy">Inga raderade meddelanden.</p>
          </div>

          <form v-else class="form-grid" @submit.prevent="sendMessage">
            <div class="field full-span">
              <label for="messageRecipient">Mottagare</label>
              <Select
                id="messageRecipient"
                v-model="messageForm.recipientId"
                :options="messageRecipientOptions"
                option-label="name"
                option-value="id"
                placeholder="Välj användare"
                filter
              />
              <small v-if="canMessageAllUsers" class="muted">Firmatecknare kan välja Alla aktiva användare för massutskick.</small>
            </div>
            <div class="field full-span">
              <label for="messageSubject">Ämne</label>
              <InputText id="messageSubject" v-model="messageForm.subject" maxlength="160" />
            </div>
            <div class="field full-span">
              <label for="messageBody">Meddelande</label>
              <Textarea id="messageBody" v-model="messageForm.body" rows="6" auto-resize maxlength="4000" />
            </div>
            <div class="form-actions full-span">
              <Button label="Skicka" icon="pi pi-send" :loading="messageForm.processing" @click="sendMessage" />
            </div>
          </form>
        </section>
      </div>
    </Dialog>

    <Dialog v-model:visible="userDialog" modal :header="editingUserId ? 'Redigera användare' : 'Ny användare'" class="form-dialog" :style="{ width: 'min(720px, 96vw)' }">
      <form class="form-grid" @submit.prevent="saveUser">
        <div class="field">
          <label for="firstName">Förnamn</label>
          <InputText id="firstName" v-model="userForm.firstName" />
        </div>
        <div class="field">
          <label for="lastName">Efternamn</label>
          <InputText id="lastName" v-model="userForm.lastName" />
        </div>
        <div class="field">
          <label for="userEmail">E-post</label>
          <InputText id="userEmail" v-model="userForm.email" type="email" />
        </div>
        <div class="field">
          <label for="userPhone">Telefon</label>
          <InputText id="userPhone" v-model="userForm.phone" />
        </div>
        <div class="field">
          <label for="role">Roll</label>
          <Select id="role" v-model="userForm.role" :options="roleOptions" option-label="label" option-value="value" />
        </div>
        <div v-if="can('system.full_access')" class="field full-span permission-editor">
          <label>Manuella behörigheter - lägg till</label>
          <div class="permission-checkbox-grid">
            <label v-for="permission in availablePermissionOptions" :key="`allow-${permission.value}`" class="permission-checkbox">
              <Checkbox v-model="userForm.permissionAllow" :value="permission.value" />
              <span>{{ permission.label }}</span>
            </label>
          </div>
        </div>
        <div v-if="can('system.full_access')" class="field full-span permission-editor">
          <label>Manuella behörigheter - ta bort</label>
          <div class="permission-checkbox-grid">
            <label v-for="permission in availablePermissionOptions" :key="`deny-${permission.value}`" class="permission-checkbox">
              <Checkbox v-model="userForm.permissionDeny" :value="permission.value" />
              <span>{{ permission.label }}</span>
            </label>
          </div>
        </div>
        <div class="field">
          <label for="active">Aktiv</label>
          <div class="actions">
            <Checkbox id="active" v-model="userForm.active" binary />
            <span class="muted">{{ userForm.active ? 'Ja' : 'Nej' }}</span>
          </div>
        </div>
      </form>

      <template #footer>
        <Button label="Avbryt" severity="secondary" outlined @click="userDialog = false" />
        <Button label="Spara" icon="pi pi-save" :loading="userForm.processing" @click="saveUser" />
      </template>
    </Dialog>

    <Dialog
      v-model:visible="settingsDialog"
      modal
      header="Adminpanel"
      class="admin-dialog"
      :style="{ width: 'min(96vw, 1200px)', height: 'min(88dvh, 900px)', maxWidth: '96vw', maxHeight: '88dvh' }"
    >
      <Tabs v-model:value="adminTab" class="admin-tabs">
        <TabList class="admin-tabs-list">
          <Tab v-if="can('users.view')" value="users" class="admin-tab">Användare</Tab>
          <Tab v-if="can('roles.view')" value="roles" class="admin-tab">Roller</Tab>
          <Tab v-if="can('deliveries.view_all')" value="deliveries" class="admin-tab">Leveranser</Tab>
          <Tab v-if="can('purchases.view')" value="purchases" class="admin-tab">Inköp</Tab>
          <Tab v-if="can('work_orders.view')" value="workOrders" class="admin-tab">Arbetsordrar</Tab>
          <Tab v-if="can('logs.view')" value="logs" class="admin-tab">Loggar</Tab>
          <Tab v-if="can('settings.view')" value="settings" class="admin-tab">Inställningar</Tab>
          <Tab v-if="can('system.view_status')" value="system" class="admin-tab">System</Tab>
          <Tab value="overview" class="admin-tab">Översikt</Tab>
        </TabList>

        <TabPanels class="admin-tab-panels">
          <TabPanel v-if="can('users.view')" value="users" class="admin-tab-panel">
            <section class="admin-section-panel admin-users-panel">
              <div class="admin-section-header">
                <span class="admin-section-icon" aria-hidden="true">
                  <i class="pi pi-shield"></i>
                </span>
                <div class="admin-section-title">
                  <h2>Användarhantering</h2>
                  <p>Skapa, ändra roller och hantera åtkomst</p>
                </div>
                <div class="admin-user-search admin-header-user-search">
                  <label class="admin-search-label" for="adminUserSearch">Sök användare</label>
                  <div class="admin-search-field">
                    <i class="pi pi-search"></i>
                    <InputText id="adminUserSearch" v-model="adminUserSearch" placeholder="Skriv namn, e-post, telefon eller roll..." autocomplete="off" />
                    <Button v-if="adminUserSearch" icon="pi pi-times" text rounded severity="secondary" aria-label="Rensa sök" @click="adminUserSearch = ''" />
                  </div>
                </div>
                <span class="admin-section-count">
                  <strong>{{ sortedAdminUsers.length }}</strong>
                  <span>av {{ users.length }} konton</span>
                </span>
                <Button
                  v-if="can('users.create')"
                  class="new-user-button"
                  label="Ny användare"
                  icon="pi pi-user-plus"
                  @click="openCreateUser"
                />
              </div>

                <div class="admin-users-list-wrapper">
                  <div class="admin-users-header" role="row">
                  <button type="button" class="admin-sort-header" @click="setAdminUserSort('name')">
                    Namn <i :class="adminUserSortIcon('name')"></i>
                  </button>
                  <button type="button" class="admin-sort-header" @click="setAdminUserSort('email')">
                    E-post <i :class="adminUserSortIcon('email')"></i>
                  </button>
                  <button type="button" class="admin-sort-header" @click="setAdminUserSort('role')">
                    Roll <i :class="adminUserSortIcon('role')"></i>
                  </button>
                  <button type="button" class="admin-sort-header" @click="setAdminUserSort('status')">
                    Status <i :class="adminUserSortIcon('status')"></i>
                  </button>
                  <button type="button" class="admin-sort-header" @click="setAdminUserSort('createdAt')">
                    Skapad <i :class="adminUserSortIcon('createdAt')"></i>
                  </button>
                  <span>Åtgärder</span>
                </div>

                <div v-if="!users.length" class="admin-empty-state">
                  <h3>Inga användare registrerade</h3>
                  <p>Skapa första kontot med knappen Ny användare.</p>
                </div>
                <div v-else-if="!sortedAdminUsers.length" class="admin-empty-state">
                  <h3>Ingen användare matchar sökningen</h3>
                  <p>Ändra eller rensa sökrutan.</p>
                </div>

                <article v-for="user in sortedAdminUsers" :key="user.id" class="admin-user-row">
                  <button type="button" class="admin-user-cell admin-user-name admin-cell-button" :title="user.name || 'Namnlös användare'" @click="openAdminDetail('Användare', 'Namn', user, user.name || 'Namnlös användare')">
                    {{ user.name || 'Namnlös användare' }}
                  </button>
                  <button type="button" class="admin-user-cell admin-user-email admin-cell-button" :title="user.email || '-'" @click="openAdminDetail('Användare', 'E-post', user, user.email)">
                    {{ user.email || '-' }}
                  </button>
                  <button type="button" class="admin-user-cell admin-user-role admin-cell-button" :title="roleLabel(user)" @click="openAdminDetail('Användare', 'Roll', user, roleLabel(user))">
                    {{ roleLabel(user) }}
                  </button>
                  <button type="button" class="admin-user-cell admin-user-status admin-cell-button" @click="openAdminDetail('Användare', 'Status', user, user.active ? 'Aktiv' : 'Inaktiv')">
                    <Tag :value="user.active ? 'Aktiv' : 'Inaktiv'" :severity="user.active ? 'success' : 'danger'" />
                  </button>
                  <button type="button" class="admin-user-cell admin-user-date admin-cell-button" :title="formatDate(user.createdAt) || '-'" @click="openAdminDetail('Användare', 'Skapad', user, formatDate(user.createdAt))">
                    {{ formatDate(user.createdAt) || '-' }}
                  </button>
                  <div class="admin-user-actions" aria-label="Åtgärder">
                    <Button v-if="can('users.update')" icon="pi pi-pencil" text rounded aria-label="Redigera" @click="openEditUser(user)" />
                    <Button v-if="can('users.change_password')" icon="pi pi-key" text rounded severity="secondary" aria-label="Nytt lösenord" @click="resetPassword(user)" />
                    <Button v-if="can('users.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Ta bort" @click="deleteUser(user)" />
                  </div>
                </article>
              </div>
            </section>
          </TabPanel>

          <TabPanel v-if="can('roles.view')" value="roles" class="admin-tab-panel">
            <section class="admin-section">
              <h3>Roller & behörigheter</h3>
              <DataTable :value="admin.roleMatrix" size="small" responsive-layout="scroll">
                <Column field="label" header="Roll" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Roller', 'Roll', data, data.label)">{{ data.label || '-' }}</button>
                  </template>
                </Column>
                <Column field="level" header="Nivå" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Roller', 'Nivå', data, data.level)">{{ data.level ?? '-' }}</button>
                  </template>
                </Column>
                <Column field="description" header="Beskrivning">
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Roller', 'Beskrivning', data, data.description)">{{ data.description || '-' }}</button>
                  </template>
                </Column>
                <Column header="Behörigheter">
                  <template #body="{ data }">
                    <button type="button" class="permission-list admin-cell-button admin-cell-button-wrap" @click="openAdminDetail('Roller', 'Behörigheter', data, data.allowedPermissions)">
                      <Tag
                        v-for="permission in data.allowedPermissions.slice(0, 8)"
                        :key="permission"
                        :value="permission"
                        severity="secondary"
                      />
                      <span v-if="data.allowedPermissions.length > 8" class="muted">+{{ data.allowedPermissions.length - 8 }} fler</span>
                    </button>
                  </template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel v-if="can('deliveries.view_all')" value="deliveries" class="admin-tab-panel">
            <section class="admin-section">
              <h3>Leveranser</h3>
              <DataTable :value="orders" size="small" paginator :rows="10" data-key="id" responsive-layout="scroll">
                <Column field="mottagare" header="Mottagare" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Leveranser', 'Mottagare', data, data.mottagare)">{{ data.mottagare || '-' }}</button>
                  </template>
                </Column>
                <Column field="adress" header="Adress" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Leveranser', 'Adress', data, data.adress)">{{ data.adress || '-' }}</button>
                  </template>
                </Column>
                <Column field="tele" header="Telefon">
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Leveranser', 'Telefon', data, data.tele)">{{ data.tele || '-' }}</button>
                  </template>
                </Column>
                <Column field="status" header="Status" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Leveranser', 'Status', data, statusLabels[data.status] || data.status)">
                      <Tag :value="statusLabels[data.status] || data.status" :severity="statusSeverity(data.status)" :class="['status-badge', `status-${data.status}`]" />
                    </button>
                  </template>
                </Column>
                <Column field="createdAt" header="Skapad" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Leveranser', 'Skapad', data, formatDate(data.createdAt))">{{ formatDate(data.createdAt) }}</button>
                  </template>
                </Column>
                <Column header="Åtgärder" style="width: 120px">
                  <template #body="{ data }">
                    <Button v-if="can('deliveries.update')" icon="pi pi-pencil" text rounded aria-label="Redigera" @click="openEditOrder(data)" />
                    <Button v-if="can('deliveries.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Ta bort" @click="deleteOrder(data)" />
                  </template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel v-if="can('purchases.view')" value="purchases" class="admin-tab-panel">
            <section class="admin-section">
              <h3>Inköp</h3>
              <DataTable :value="purchases" size="small" paginator :rows="10" responsive-layout="scroll">
                <Column field="itemName" header="Artikel" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Inköp', 'Artikel', data, data.itemName)">{{ data.itemName || '-' }}</button>
                  </template>
                </Column>
                <Column field="sku" header="Artikelnummer" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Inköp', 'Artikelnummer', data, data.sku)">{{ data.sku || '-' }}</button>
                  </template>
                </Column>
                <Column field="storeName" header="Butik" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Inköp', 'Butik', data, data.storeName)">{{ data.storeName || '-' }}</button>
                  </template>
                </Column>
                <Column field="availabilityAtSelection" header="Lagerstatus">
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Inköp', 'Lagerstatus', data, data.availabilityAtSelection)">{{ data.availabilityAtSelection || '-' }}</button>
                  </template>
                </Column>
                <Column field="totalGross" header="Total">
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Inköp', 'Total', data, formatMoney(data.totalGross, data.currency))">{{ formatMoney(data.totalGross, data.currency) }}</button>
                  </template>
                </Column>
                <Column field="status" header="Status" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Inköp', 'Status', data, purchaseStatusLabels[data.status] || data.status)">
                      <Tag :value="purchaseStatusLabels[data.status] || data.status" :severity="purchaseStatusSeverity(data.status)" />
                    </button>
                  </template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel v-if="can('work_orders.view')" value="workOrders" class="admin-tab-panel">
            <section class="admin-section">
              <div class="admin-section-head">
                <div>
                  <h3>Arbetsordrar</h3>
                  <p class="muted">Hantera importerade och interna arbetsordrar separat.</p>
                </div>
                <div class="admin-subtabs" role="tablist" aria-label="Arbetsorder undermeny">
                  <button
                    type="button"
                    :class="['admin-subtab', { 'is-active': adminWorkOrderSubTab === 'external' }]"
                    role="tab"
                    :aria-selected="adminWorkOrderSubTab === 'external'"
                    @click="adminWorkOrderSubTab = 'external'"
                  >
                    Arbetsordrar
                  </button>
                  <button
                    type="button"
                    :class="['admin-subtab', { 'is-active': adminWorkOrderSubTab === 'internal' }]"
                    role="tab"
                    :aria-selected="adminWorkOrderSubTab === 'internal'"
                    @click="adminWorkOrderSubTab = 'internal'"
                  >
                    Interna arbetsordrar
                  </button>
                </div>
              </div>

              <div v-if="adminWorkOrderSubTab === 'external'" class="work-order-admin-panel">
                <div class="work-order-admin-toolbar">
                  <span>{{ selectedExternalWorkOrders.length }} valda av {{ externalWorkOrderRows.length }}</span>
                  <Button
                    v-if="canDeleteWorkOrders"
                    label="Radera valda"
                    icon="pi pi-trash"
                    severity="danger"
                    outlined
                    :disabled="!selectedExternalWorkOrders.length"
                    @click="openWorkOrderDeleteDialog('external')"
                  />
                </div>
                <DataTable v-model:selection="selectedExternalWorkOrders" :value="externalWorkOrderRows" data-key="id" size="small" paginator :rows="10" responsive-layout="scroll">
                  <Column v-if="canDeleteWorkOrders" selection-mode="multiple" header-style="width: 3rem" />
                  <Column field="workOrderNumber" header="Arbetsorder" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Arbetsorder', data, data.workOrderNumber)">{{ data.workOrderNumber || '-' }}</button>
                    </template>
                  </Column>
                  <Column field="source" header="Källa" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Källa', data, data.source)">{{ data.source || '-' }}</button>
                    </template>
                  </Column>
                  <Column field="recipientName" header="Mottagare" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Mottagare', data, data.recipientName)">{{ data.recipientName || '-' }}</button>
                    </template>
                  </Column>
                  <Column field="deliveryAddress" header="Adress">
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Adress', data, data.deliveryAddress)">{{ data.deliveryAddress || '-' }}</button>
                    </template>
                  </Column>
                  <Column field="status" header="Status" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Status', data, data.hiddenAt ? 'Dold' : data.status)">
                        <Tag :value="data.hiddenAt ? 'Dold' : (data.status || '-')" :severity="data.hiddenAt ? 'warning' : 'info'" />
                      </button>
                    </template>
                  </Column>
                  <Column field="deliveredQuantity" header="Avbockat" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Avbockat', data, `${data.deliveredQuantity || 0} st`)">
                        <Tag :value="`${data.deliveredQuantity || 0} st`" severity="success" />
                      </button>
                    </template>
                  </Column>
                  <Column field="receivedAt" header="Mottagen" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Mottagen', data, formatDate(data.receivedAt))">{{ formatDate(data.receivedAt) }}</button>
                    </template>
                  </Column>
                </DataTable>
              </div>

              <div v-else class="work-order-admin-panel">
                <div class="work-order-admin-toolbar">
                  <span>{{ selectedInternalWorkOrders.length }} valda av {{ internalWorkOrderRows.length }}</span>
                  <Button
                    v-if="canDeleteWorkOrders"
                    label="Radera valda"
                    icon="pi pi-trash"
                    severity="danger"
                    outlined
                    :disabled="!selectedInternalWorkOrders.length"
                    @click="openWorkOrderDeleteDialog('internal')"
                  />
                </div>
                <DataTable v-model:selection="selectedInternalWorkOrders" :value="internalWorkOrderRows" data-key="id" size="small" paginator :rows="10" responsive-layout="scroll">
                  <Column v-if="canDeleteWorkOrders" selection-mode="multiple" header-style="width: 3rem" />
                  <Column field="workOrderNumber" header="Arbetsorder" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Arbetsorder', data, data.workOrderNumber)">{{ data.workOrderNumber || '-' }}</button>
                    </template>
                  </Column>
                  <Column field="source" header="Källa" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Källa', data, data.source)">{{ data.source || '-' }}</button>
                    </template>
                  </Column>
                  <Column field="recipientName" header="Mottagare" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Mottagare', data, data.recipientName)">{{ data.recipientName || '-' }}</button>
                    </template>
                  </Column>
                  <Column field="deliveryAddress" header="Adress">
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Adress', data, data.deliveryAddress)">{{ data.deliveryAddress || '-' }}</button>
                    </template>
                  </Column>
                  <Column field="status" header="Status" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Status', data, data.hiddenAt ? 'Dold' : data.status)">
                        <Tag :value="data.hiddenAt ? 'Dold' : (data.status || '-')" :severity="data.hiddenAt ? 'warning' : 'info'" />
                      </button>
                    </template>
                  </Column>
                  <Column field="rowCount" header="Rader" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Rader', data, `${data.rowCount ?? 0} st`)">
                        <Tag :value="`${data.rowCount ?? 0} st`" :severity="data.rowCount ? 'info' : 'warning'" />
                      </button>
                    </template>
                  </Column>
                  <Column field="articleSummary" header="Artiklar">
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" :title="data.articleSummary || '-'" @click="openAdminDetail('Arbetsordrar', 'Artiklar', data, data.articleSummary)">{{ data.articleSummary || '-' }}</button>
                    </template>
                  </Column>
                  <Column field="deliveredQuantity" header="Avbockat" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Avbockat', data, `${data.deliveredQuantity || 0} st`)">
                        <Tag :value="`${data.deliveredQuantity || 0} st`" severity="success" />
                      </button>
                    </template>
                  </Column>
                  <Column field="receivedAt" header="Mottagen" sortable>
                    <template #body="{ data }">
                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Arbetsordrar', 'Mottagen', data, formatDate(data.receivedAt))">{{ formatDate(data.receivedAt) }}</button>
                    </template>
                  </Column>
                </DataTable>
              </div>
            </section>
          </TabPanel>

          <TabPanel v-if="can('logs.view')" value="logs" class="admin-tab-panel">
            <section class="admin-section">
              <div class="admin-section-head">
                <h3>Systemloggar</h3>
                <div class="log-actions">
                  <Button v-if="can('logs.export')" label="Exportera" icon="pi pi-download" severity="secondary" outlined @click="exportLogs" />
                  <Button label="Rensa alla loggar" icon="pi pi-trash" severity="danger" outlined :disabled="!visibleAdminLogs.length" @click="clearFrontendLogs" />
                </div>
              </div>
              <div class="log-filters">
                <InputText v-model="logSearch" placeholder="Sök i loggar" />
                <Select v-model="logModule" :options="logModuleOptions" option-label="label" option-value="value" placeholder="Modul" show-clear />
                <Select v-model="logStatus" :options="logStatusOptions" option-label="label" option-value="value" placeholder="Status" show-clear />
              </div>
              <Message v-if="hiddenLogCount" severity="info" :closable="false" class="mb-3">
                {{ hiddenLogCount }} loggar är rensade från denna vy. Backendloggarna finns kvar och nya förekomster visas igen.
              </Message>
              <DataTable :value="filteredLogs" size="small" paginator :rows="12" responsive-layout="scroll">
                <Column field="time" header="Datum/tid" sortable>
                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Loggar', 'Datum/tid', data, data.time)">{{ data.time || '-' }}</button></template>
                </Column>
                <Column field="user" header="Användare" sortable>
                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Loggar', 'Användare', data, data.user)">{{ data.user || '-' }}</button></template>
                </Column>
                <Column field="role" header="Roll" sortable>
                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Loggar', 'Roll', data, data.role)">{{ data.role || '-' }}</button></template>
                </Column>
                <Column field="event" header="Händelse" sortable>
                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Loggar', 'Händelse', data, data.event)">{{ data.event || '-' }}</button></template>
                </Column>
                <Column field="module" header="Modul" sortable>
                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Loggar', 'Modul', data, data.module)">{{ data.module || '-' }}</button></template>
                </Column>
                <Column field="ip" header="IP-adress">
                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Loggar', 'IP-adress', data, data.ip)">{{ data.ip || '-' }}</button></template>
                </Column>
                <Column field="status" header="Status" sortable>
                  <template #body="{ data }">
                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Loggar', 'Status', data, data.status)">
                      <Tag :value="data.status" :severity="data.status === 'error' || data.status === 'critical' ? 'danger' : 'secondary'" />
                    </button>
                  </template>
                </Column>
                <Column field="details" header="Detaljer">
                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Loggar', 'Detaljer', data, data.details)">{{ data.details || '-' }}</button></template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel v-if="can('settings.view')" value="settings" class="admin-tab-panel">
            <form class="form-grid admin-settings-form" @submit.prevent="saveSettings">
              <div class="field">
                <label for="appTitle">Appnamn</label>
                <InputText id="appTitle" v-model="settingsForm.appTitle" :disabled="!can('settings.update')" />
              </div>
              <div class="field">
                <label for="companyName">Företag</label>
                <InputText id="companyName" v-model="settingsForm.companyName" :disabled="!can('settings.update')" />
              </div>
              <div class="field">
                <label for="deliveryTitle">Leveransrubrik</label>
                <InputText id="deliveryTitle" v-model="settingsForm.deliveryTitle" :disabled="!can('settings.update')" />
              </div>
              <div class="field">
                <label for="orderNumberPrefix">Prefix</label>
                <InputText id="orderNumberPrefix" v-model="settingsForm.orderNumberPrefix" :disabled="!can('settings.update')" />
              </div>
              <div class="field">
                <label for="supportEmail">Support e-post</label>
                <InputText id="supportEmail" v-model="settingsForm.supportEmail" type="email" :disabled="!can('settings.update')" />
              </div>
              <div class="field">
                <label for="supportPhone">Support telefon</label>
                <InputText id="supportPhone" v-model="settingsForm.supportPhone" :disabled="!can('settings.update')" />
              </div>
              <div class="field full-span">
                <label for="adminMessage">Meddelande</label>
                <Textarea id="adminMessage" v-model="settingsForm.adminMessage" rows="4" auto-resize :disabled="!can('settings.update')" />
              </div>
              <div class="field full-span">
                <label for="allowPush">Pushnotiser</label>
                <div class="actions">
                  <Checkbox id="allowPush" v-model="settingsForm.allowPushNotifications" binary :disabled="!can('settings.update')" />
                  <span class="muted">{{ settingsForm.allowPushNotifications ? 'Aktivt' : 'Avstängt' }}</span>
                </div>
              </div>
            </form>
          </TabPanel>

          <TabPanel v-if="can('system.view_status')" value="system" class="admin-tab-panel">
            <section class="admin-system-grid">
              <article class="admin-section">
	                <h3>Systemstatus</h3>
	                <dl class="status-list">
	                  <div><dt>Miljö</dt><dd><button type="button" class="admin-cell-button" @click="openAdminSystemDetail('Miljö', admin.systemStatus.environment)">{{ admin.systemStatus.environment }}</button></dd></div>
	                  <div><dt>Laravel</dt><dd><button type="button" class="admin-cell-button" @click="openAdminSystemDetail('Laravel', admin.systemStatus.laravelVersion)">{{ admin.systemStatus.laravelVersion }}</button></dd></div>
	                  <div><dt>PHP</dt><dd><button type="button" class="admin-cell-button" @click="openAdminSystemDetail('PHP', admin.systemStatus.phpVersion)">{{ admin.systemStatus.phpVersion }}</button></dd></div>
	                  <div><dt>Broadcasting</dt><dd><button type="button" class="admin-cell-button" @click="openAdminSystemDetail('Broadcasting', admin.systemStatus.broadcasting)">{{ admin.systemStatus.broadcasting }}</button></dd></div>
	                  <div><dt>Kö</dt><dd><button type="button" class="admin-cell-button" @click="openAdminSystemDetail('Kö', admin.systemStatus.queue)">{{ admin.systemStatus.queue }}</button></dd></div>
	                  <div><dt>Databas</dt><dd><button type="button" class="admin-cell-button" @click="openAdminSystemDetail('Databas', admin.systemStatus.database)">{{ admin.systemStatus.database }}</button></dd></div>
	                </dl>
	              </article>

              <article class="admin-section">
	                <h3>Pushnotiser</h3>
	                <DataTable :value="admin.pushSubscriptions" size="small" :rows="5" responsive-layout="scroll">
	                  <Column field="user" header="Användare">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Pushnotiser', 'Användare', data, data.user)">{{ data.user || '-' }}</button></template>
	                  </Column>
	                  <Column field="permission" header="Tillstånd">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Pushnotiser', 'Tillstånd', data, data.permission)">{{ data.permission || '-' }}</button></template>
	                  </Column>
	                  <Column field="enabled" header="Aktiv">
	                    <template #body="{ data }">
	                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Pushnotiser', 'Aktiv', data, data.enabled ? 'Ja' : 'Nej')">
	                        <Tag :value="data.enabled ? 'Ja' : 'Nej'" :severity="data.enabled ? 'success' : 'secondary'" />
	                      </button>
	                    </template>
	                  </Column>
	                  <Column field="lastSeenAt" header="Senast sedd">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Pushnotiser', 'Senast sedd', data, formatDate(data.lastSeenAt))">{{ formatDate(data.lastSeenAt) }}</button></template>
	                  </Column>
	                </DataTable>
              </article>

              <article class="admin-section">
	                <h3>Tracking-länkar</h3>
	                <DataTable :value="admin.trackingLinks" size="small" :rows="5" responsive-layout="scroll">
	                  <Column field="orderId" header="Leverans">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Tracking-länkar', 'Leverans', data, data.orderId)">{{ data.orderId || '-' }}</button></template>
	                  </Column>
	                  <Column field="active" header="Aktiv">
	                    <template #body="{ data }">
	                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Tracking-länkar', 'Aktiv', data, data.active ? 'Ja' : 'Nej')">
	                        <Tag :value="data.active ? 'Ja' : 'Nej'" :severity="data.active ? 'success' : 'secondary'" />
	                      </button>
	                    </template>
	                  </Column>
	                  <Column field="expiresAt" header="Går ut">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Tracking-länkar', 'Går ut', data, formatDate(data.expiresAt))">{{ formatDate(data.expiresAt) }}</button></template>
	                  </Column>
	                </DataTable>
              </article>

              <article class="admin-section">
	                <h3>Artiklar</h3>
	                <DataTable :value="admin.articles" size="small" :rows="5" responsive-layout="scroll">
	                  <Column field="sku" header="Artikel">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Artiklar', 'Artikel', data, data.sku)">{{ data.sku || data.artikel || '-' }}</button></template>
	                  </Column>
	                  <Column field="title" header="Benämning">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Artiklar', 'Benämning', data, data.title)">{{ data.title || '-' }}</button></template>
	                  </Column>
	                  <Column field="stockTotal" header="Totalt">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Artiklar', 'Totalt', data, data.stockTotal)">{{ data.stockTotal ?? '-' }}</button></template>
	                  </Column>
	                  <Column field="stockDelivered" header="Levererat">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Artiklar', 'Levererat', data, data.stockDelivered)">{{ data.stockDelivered ?? '-' }}</button></template>
	                  </Column>
	                  <Column field="stockRemaining" header="Kvar">
	                    <template #body="{ data }">
	                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Artiklar', 'Kvar', data, `${data.stockRemaining || 0} st`)">
	                        <Tag :value="`${data.stockRemaining || 0} st`" severity="success" />
	                      </button>
	                    </template>
	                  </Column>
	                </DataTable>
              </article>

              <article class="admin-section">
	                <h3>Kunder/mottagare</h3>
	                <DataTable :value="admin.customers" size="small" :rows="5" responsive-layout="scroll">
	                  <Column field="name" header="Namn">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Kunder/mottagare', 'Namn', data, data.name)">{{ data.name || '-' }}</button></template>
	                  </Column>
	                  <Column field="phone" header="Telefon">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Kunder/mottagare', 'Telefon', data, data.phone)">{{ data.phone || '-' }}</button></template>
	                  </Column>
	                  <Column field="address" header="Adress">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Kunder/mottagare', 'Adress', data, data.address)">{{ data.address || '-' }}</button></template>
	                  </Column>
	                  <Column field="ordersCount" header="Leveranser">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Kunder/mottagare', 'Leveranser', data, data.ordersCount)">{{ data.ordersCount ?? '-' }}</button></template>
	                  </Column>
	                </DataTable>
              </article>

              <article class="admin-section">
	                <h3>Förare</h3>
	                <DataTable :value="admin.drivers" size="small" :rows="5" responsive-layout="scroll">
	                  <Column field="name" header="Namn">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Förare', 'Namn', data, data.name)">{{ data.name || '-' }}</button></template>
	                  </Column>
	                  <Column field="email" header="E-post">
	                    <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Förare', 'E-post', data, data.email)">{{ data.email || '-' }}</button></template>
	                  </Column>
	                  <Column field="visibility" header="Synlighet">
	                    <template #body="{ data }">
	                      <button type="button" class="admin-cell-button" @click="openAdminDetail('Förare', 'Synlighet', data, data.visibility === 'online' ? 'Online' : 'Offline')">
	                        <Tag :value="data.visibility === 'online' ? 'Online' : 'Offline'" :severity="data.visibility === 'online' ? 'success' : 'secondary'" />
	                      </button>
	                    </template>
	                  </Column>
	                </DataTable>
              </article>
            </section>
          </TabPanel>

          <TabPanel value="overview" class="admin-tab-panel">
            <section class="admin-grid">
              <article class="admin-card">
                <span>Leveranser</span>
                <strong>{{ stats.ordersTotal }}</strong>
                <small>{{ stats.activeOrders }} aktiva</small>
              </article>
              <article class="admin-card">
                <span>Användare</span>
                <strong>{{ stats.usersTotal }}</strong>
                <small>{{ admin.drivers?.length || 0 }} förare</small>
              </article>
              <article class="admin-card">
                <span>Pushnotiser</span>
                <strong>{{ admin.pushSubscriptions?.length || 0 }}</strong>
                <small>{{ settings.allowPushNotifications ? 'Aktiverat' : 'Avstängt' }}</small>
              </article>
              <article class="admin-card">
                <span>Tracking-länkar</span>
                <strong>{{ admin.trackingLinks?.length || 0 }}</strong>
                <small>Senaste 100</small>
              </article>
            </section>

	            <section class="admin-section">
	              <h3>Backendöversikt</h3>
	              <DataTable :value="admin.apiEndpoints" size="small" responsive-layout="scroll">
	                <Column field="method" header="Metod">
	                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Backendöversikt', 'Metod', data, data.method)">{{ data.method || '-' }}</button></template>
	                </Column>
	                <Column field="path" header="Endpoint">
	                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Backendöversikt', 'Endpoint', data, data.path)">{{ data.path || '-' }}</button></template>
	                </Column>
	                <Column field="module" header="Modul">
	                  <template #body="{ data }"><button type="button" class="admin-cell-button" @click="openAdminDetail('Backendöversikt', 'Modul', data, data.module)">{{ data.module || '-' }}</button></template>
	                </Column>
	                <Column field="status" header="Status">
	                  <template #body="{ data }">
	                    <button type="button" class="admin-cell-button" @click="openAdminDetail('Backendöversikt', 'Status', data, data.status)">
	                      <Tag :value="data.status" severity="success" />
	                    </button>
	                  </template>
	                </Column>
              </DataTable>
            </section>
          </TabPanel>
        </TabPanels>
      </Tabs>

      <template #footer>
        <Button label="Stäng" severity="secondary" outlined @click="settingsDialog = false" />
        <Button
          v-if="adminTab === 'settings' && can('settings.update')"
          label="Spara inställningar"
          icon="pi pi-save"
          :loading="settingsForm.processing"
          @click="saveSettings"
        />
      </template>
	    </Dialog>

	    <Dialog v-model:visible="adminDetailDialog" modal :header="adminDetail ? `${adminDetail.section} · ${adminDetail.field}` : 'Detalj'" class="admin-detail-dialog" :style="{ width: 'min(720px, 94vw)' }">
	      <div v-if="adminDetail" class="admin-detail-content">
	        <section class="admin-detail-primary">
	          <span>{{ adminDetail.field }}</span>
	          <pre>{{ adminDetail.value }}</pre>
	        </section>
	        <section class="admin-detail-list">
	          <div v-for="row in adminDetail.rows" :key="row.key" class="admin-detail-row">
	            <dt>{{ row.key }}</dt>
	            <dd>{{ row.value }}</dd>
	          </div>
	        </section>
	      </div>
	      <template #footer>
	        <Button label="Stäng" severity="secondary" outlined @click="adminDetailDialog = false" />
	      </template>
	    </Dialog>

	    <Dialog v-model:visible="workOrderDeleteDialog" modal header="Radera arbetsordrar" class="admin-detail-dialog" :style="{ width: 'min(560px, 94vw)' }">
	      <div class="work-order-delete-confirm">
	        <strong>Vill du radera helt ur databasen?</strong>
	        <p>
	          Du har valt {{ selectedWorkOrderRowsForDelete.length }} {{ pendingWorkOrderDeleteType === 'internal' ? 'interna arbetsordrar' : 'arbetsordrar' }}.
	        </p>
	        <p>
	          JA raderar valda poster permanent ur databasen. NEJ döljer dem från normal användning, men de ligger kvar i databasen och syns här för admin och firmatecknare.
	        </p>
	      </div>
	      <template #footer>
	        <Button label="Avbryt" severity="secondary" outlined @click="closeWorkOrderDeleteDialog" />
	        <Button label="NEJ - dölj" icon="pi pi-eye-slash" severity="warning" outlined @click="submitWorkOrderDelete('hide')" />
	        <Button label="JA - radera helt" icon="pi pi-trash" severity="danger" @click="submitWorkOrderDelete('delete')" />
	      </template>
	    </Dialog>

	    <Dialog v-model:visible="tempPasswordVisible" modal header="Tillfälligt lösenord" :style="{ width: 'min(520px, 94vw)' }">
	      <p class="mono">{{ flash.tempPassword }}</p>
      <template #footer>
        <Button label="Stäng" @click="tempPasswordVisible = false" />
      </template>
    </Dialog>
  </div>
</template>
