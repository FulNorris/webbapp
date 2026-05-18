<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';

const props = defineProps({
  user: { type: Object, required: true },
  orders: { type: Array, default: () => [] },
  users: { type: Array, default: () => [] },
  drivers: { type: Array, default: () => [] },
  settings: { type: Object, required: true },
  roles: { type: Array, default: () => [] },
  permissions: { type: Object, default: () => ({}) },
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
const orderDialog = ref(false);
const userDialog = ref(false);
const settingsDialog = ref(false);
const adminTab = ref('overview');
const editingOrderId = ref(null);
const editingUserId = ref(null);
const tempPasswordVisible = ref(false);
const logSearch = ref('');
const logStatus = ref('');
const logModule = ref('');
const pushState = ref({
  enabled: false,
  supported: false,
  permission: typeof Notification === 'undefined' ? 'unsupported' : Notification.permission,
  busy: false,
});
const visibilityState = ref(props.user.visibility || 'offline');
const activeTrackingOrderId = ref(null);
const locationWatchId = ref(null);
const lastLocationSentAt = ref(0);
const lastLocation = ref(null);

const statusLabels = {
  created: 'Skapad',
  assigned: 'Tilldelad',
  ongoing: 'Pågår',
  paused: 'Pausad',
  delivered: 'Levererad',
  cancelled: 'Avbruten',
};

const statusOptions = [
  { label: 'Skapad', value: 'created' },
  { label: 'Tilldelad', value: 'assigned' },
  { label: 'Pågår', value: 'ongoing' },
  { label: 'Pausad', value: 'paused' },
  { label: 'Levererad', value: 'delivered' },
  { label: 'Avbruten', value: 'cancelled' },
];

const roleOptions = computed(() => props.roles.map((role) => (typeof role === 'string' ? { label: role, value: role } : role)));
const canOpenAdmin = computed(() => ['users.view', 'settings.view', 'logs.view', 'system.view_status'].some((permission) => can(permission)));
const recipientSuggestions = computed(() => {
  const suggestions = new Map();

  props.users.forEach((user) => {
    const name = (user.name || `${user.firstName || ''} ${user.lastName || ''}`.trim()).trim();
    if (!name) return;
    suggestions.set(normalizeKey(name), {
      value: name,
      phone: user.phone || '',
      source: user.roleLabel || user.role || 'användare',
    });
  });

  props.orders.forEach((order) => {
    const name = (order.mottagare || '').trim();
    if (!name || suggestions.has(normalizeKey(name))) return;
    suggestions.set(normalizeKey(name), {
      value: name,
      phone: order.tele || '',
      source: 'tidigare leverans',
    });
  });

  return Array.from(suggestions.values()).sort((a, b) => a.value.localeCompare(b.value, 'sv'));
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
const visibilityOnline = computed(() => visibilityState.value === 'online');
const logStatusOptions = computed(() => Array.from(new Set((props.admin.logs || []).map((row) => row.status).filter(Boolean)))
  .sort((a, b) => a.localeCompare(b, 'sv'))
  .map((status) => ({ label: status, value: status })));
const logModuleOptions = computed(() => Array.from(new Set((props.admin.logs || []).map((row) => row.module).filter(Boolean)))
  .sort((a, b) => a.localeCompare(b, 'sv'))
  .map((module) => ({ label: module, value: module })));
const filteredLogs = computed(() => {
  const query = normalizeKey(logSearch.value);

  return (props.admin.logs || []).filter((row) => {
    const matchesQuery = !query || normalizeKey(Object.values(row).join(' ')).includes(query);
    const matchesStatus = !logStatus.value || row.status === logStatus.value;
    const matchesModule = !logModule.value || row.module === logModule.value;
    return matchesQuery && matchesStatus && matchesModule;
  });
});

const flash = computed(() => page.props.flash || {});
watch(
  () => flash.value.tempPassword,
  (password) => {
    if (password) tempPasswordVisible.value = true;
  },
  { immediate: true },
);

const orderForm = useForm(blankOrder());
const userForm = useForm(blankUser());
const settingsForm = useForm({
  appTitle: props.settings.appTitle,
  companyName: props.settings.companyName,
  deliveryTitle: props.settings.deliveryTitle,
  supportEmail: props.settings.supportEmail || '',
  supportPhone: props.settings.supportPhone || '',
  orderNumberPrefix: props.settings.orderNumberPrefix,
  adminMessage: props.settings.adminMessage || '',
  allowPushNotifications: props.settings.allowPushNotifications,
});

onMounted(() => {
  syncPushState();
});

onBeforeUnmount(() => {
  stopLocationWatch();
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
  const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
  return navigator.serviceWorker.ready.then(() => registration);
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
  return String(value || '').trim().toLocaleLowerCase('sv-SE');
}

function applyRecipientSuggestion() {
  const match = recipientSuggestions.value.find((suggestion) => normalizeKey(suggestion.value) === normalizeKey(orderForm.mottagare));
  if (!match) return;

  if (match.phone) {
    orderForm.tele = match.phone;
  }
}

function can(permission) {
  return props.permissions?.['system.full_access'] === true || props.permissions?.[permission] === true;
}

function roleLabel(user) {
  return user?.roleLabel || roleOptions.value.find((role) => role.value === user?.role)?.label || user?.role || '';
}

function openNativePicker(event) {
  event?.target?.showPicker?.();
}

function cleanPhone(value) {
  return String(value || '').replace(/[^\d+]/g, '');
}

function phoneHref(value) {
  const phone = cleanPhone(value);
  return phone ? `tel:${phone}` : null;
}

function mapsHref(address) {
  const query = encodeURIComponent(String(address || '').trim());
  if (!query) return null;

  const agent = navigator.userAgent || '';
  if (/android/i.test(agent)) {
    return `geo:0,0?q=${query}`;
  }

  if (/(iphone|ipad|ipod|macintosh)/i.test(agent)) {
    return `https://maps.apple.com/?q=${query}`;
  }

  return `https://www.google.com/maps/search/?api=1&query=${query}`;
}

async function togglePushNotifications() {
  if (!props.push.enabled || pushState.value.busy) return;

  if (pushState.value.enabled) {
    router.post('/push/test', {}, { preserveScroll: true });
    return;
  }

  pushState.value.busy = true;
  try {
    if (!pushSupported()) {
      window.alert('Den här webbläsaren stöder inte pushnotiser.');
      return;
    }

    const permission = Notification.permission === 'default'
      ? await Notification.requestPermission()
      : Notification.permission;
    pushState.value.permission = permission;

    if (permission !== 'granted') {
      window.alert('Pushnotiser är inte tillåtna i webbläsaren.');
      return;
    }

    const registration = await registerPushServiceWorker();
    const existing = await registration.pushManager.getSubscription();
    const subscription = existing || await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(props.push.publicKey),
    });

    router.post('/push/subscription', subscriptionPayload(subscription), {
      preserveScroll: true,
      onSuccess: () => {
        pushState.value.enabled = true;
        router.post('/push/test', {}, { preserveScroll: true });
      },
    });
  } catch (error) {
    window.alert(error.message || 'Kunde inte aktivera pushnotiser.');
  } finally {
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

  router.patch(`/orders/${encodeURIComponent(order.id)}/status`, { status: 'ongoing' }, {
    preserveScroll: true,
    onSuccess: () => {
      startLocationWatch(order.id);
      sendTrackedPosition(order.id, position, true);
    },
  });
}

function startLocationWatch(orderId) {
  stopLocationWatch();
  activeTrackingOrderId.value = orderId;
  lastLocationSentAt.value = 0;
  lastLocation.value = null;

  locationWatchId.value = navigator.geolocation.watchPosition(
    (position) => sendTrackedPosition(orderId, position),
    () => {},
    {
      enableHighAccuracy: true,
      timeout: 10000,
      maximumAge: 5000,
    },
  );
}

function stopLocationWatch() {
  if (locationWatchId.value !== null && 'geolocation' in navigator) {
    navigator.geolocation.clearWatch(locationWatchId.value);
  }

  locationWatchId.value = null;
  activeTrackingOrderId.value = null;
  lastLocationSentAt.value = 0;
  lastLocation.value = null;
}

function sendTrackedPosition(orderId, position, force = false) {
  const now = Date.now();
  const next = {
    lat: position.coords.latitude,
    lng: position.coords.longitude,
  };

  if (!force && now - lastLocationSentAt.value < 4000 && !hasMovedEnough(lastLocation.value, next)) {
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

  const url = `/orders/${encodeURIComponent(orderId)}/location`;
  if (!navigator.sendBeacon || !navigator.sendBeacon(url, form)) {
    router.post(url, Object.fromEntries(form.entries()), {
      preserveScroll: true,
      preserveState: true,
      only: [],
    });
  }
}

function hasMovedEnough(previous, next) {
  if (!previous) return true;
  return distanceMeters(previous, next) >= 15;
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

function toggleVisibility() {
  const nextVisibility = visibilityOnline.value ? 'offline' : 'online';
  router.patch('/visibility', { visibility: nextVisibility }, {
    preserveScroll: true,
    onSuccess: () => {
      visibilityState.value = nextVisibility;
      if (nextVisibility === 'offline') {
        stopLocationWatch();
      }
    },
  });
}

function blankOrder() {
  return {
    adress: '',
    tele: '',
    mottagare: '',
    desiredDeliveryDate: '',
    desiredDeliveryTime: '',
    notes: '',
    internalComment: '',
    items: [{ artikel: '', antal: '', workOrderNumber: '' }],
  };
}

function blankUser() {
  return {
    email: '',
    firstName: '',
    lastName: '',
    phone: '',
    role: 'personal',
    active: true,
  };
}

function openCreateOrder() {
  editingOrderId.value = null;
  orderForm.defaults(blankOrder());
  orderForm.reset();
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
    items: order.items?.length ? order.items.map((item) => ({
      artikel: item.artikel || '',
      antal: item.antal || '',
      workOrderNumber: item.workOrderNumber || '',
    })) : [{ artikel: '', antal: '', workOrderNumber: '' }],
  });
  orderForm.reset();
  orderDialog.value = true;
}

function saveOrder() {
  const options = {
    preserveScroll: true,
    onSuccess: () => {
      orderDialog.value = false;
      orderForm.reset();
    },
  };

  if (editingOrderId.value) {
    orderForm.put(`/orders/${encodeURIComponent(editingOrderId.value)}`, options);
  } else {
    orderForm.post('/orders', options);
  }
}

function addItem() {
  orderForm.items.push({ artikel: '', antal: '', workOrderNumber: '' });
}

function removeItem(index) {
  if (orderForm.items.length === 1) {
    orderForm.items[0] = { artikel: '', antal: '', workOrderNumber: '' };
    return;
  }
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

function deleteOrder(order) {
  if (!window.confirm(`Ta bort leveransen till ${order.mottagare}?`)) return;
  router.delete(`/orders/${encodeURIComponent(order.id)}`, { preserveScroll: true });
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
    email: user.email || '',
    firstName: user.firstName || '',
    lastName: user.lastName || '',
    phone: user.phone || '',
    role: user.roleKey || user.role || 'personal',
    active: user.active,
  });
  userForm.reset();
  userDialog.value = true;
}

function saveUser() {
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
  router.patch(`/users/${encodeURIComponent(user.id)}/password`, {}, { preserveScroll: true });
}

function deleteUser(user) {
  if (!window.confirm(`Ta bort användaren ${user.name || user.email}?`)) return;
  router.delete(`/users/${encodeURIComponent(user.id)}`, { preserveScroll: true });
}

function saveSettings() {
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

  <div class="app-shell">
    <header class="topbar">
      <div>
        <h1>{{ settings.appTitle }}</h1>
        <div class="topbar-meta">{{ user.name || user.email }} · {{ roleLabel(user) }}</div>
      </div>
      <div class="topbar-actions">
        <Button
          :label="visibilityOnline ? 'Synlighet Online' : 'Synlighet Offline'"
          :icon="visibilityOnline ? 'pi pi-eye' : 'pi pi-eye-slash'"
          :severity="visibilityOnline ? 'success' : 'secondary'"
          :outlined="!visibilityOnline"
          @click="toggleVisibility"
        />
        <Button
          :label="pushLabel"
          icon="pi pi-bell"
          :severity="pushState.enabled ? 'success' : 'secondary'"
          :outlined="!pushState.enabled"
          :loading="pushState.busy"
          :disabled="!props.push.enabled || pushState.permission === 'denied'"
          @click="togglePushNotifications"
        />
        <Button as="a" href="/live-map" label="Livekarta" icon="pi pi-map" severity="secondary" outlined />
        <Button v-if="canOpenAdmin" label="Inställningar" icon="pi pi-cog" severity="secondary" outlined @click="settingsDialog = true" />
        <form method="post" action="/logout">
          <input type="hidden" name="_token" :value="page.props.csrfToken">
          <Button type="submit" label="Logga ut" icon="pi pi-sign-out" severity="secondary" />
        </form>
      </div>
    </header>

    <main class="main">
      <Message v-if="flash.success" severity="success" :closable="false" class="mb-3">
        {{ flash.success }}
      </Message>
      <Message v-if="flash.error" severity="error" :closable="false" class="mb-3">
        {{ flash.error }}
      </Message>

      <section class="stat-grid" aria-label="Översikt">
        <div class="stat">
          <div class="stat-icon"><i class="pi pi-box"></i></div>
          <div>
            <div class="stat-label">Leveranser</div>
            <div class="stat-value">{{ stats.ordersTotal }}</div>
          </div>
        </div>
        <div class="stat">
          <div class="stat-icon"><i class="pi pi-bolt"></i></div>
          <div>
            <div class="stat-label">Aktiva</div>
            <div class="stat-value">{{ stats.activeOrders }}</div>
          </div>
        </div>
        <div class="stat">
          <div class="stat-icon"><i class="pi pi-check-circle"></i></div>
          <div>
            <div class="stat-label">Levererade</div>
            <div class="stat-value">{{ stats.deliveredOrders }}</div>
          </div>
        </div>
        <div class="stat">
          <div class="stat-icon"><i class="pi pi-users"></i></div>
          <div>
            <div class="stat-label">Användare</div>
            <div class="stat-value">{{ stats.usersTotal }}</div>
          </div>
        </div>
      </section>

      <Tabs v-model:value="activeTab">
        <TabList>
          <Tab value="orders">Leveranser</Tab>
          <Tab v-if="can('users.view')" value="users">Användare</Tab>
        </TabList>
        <TabPanels>
          <TabPanel value="orders">
            <section class="panel">
              <Toolbar>
                <template #start>
                  <strong>Leveranser</strong>
                </template>
                <template #end>
                  <Button v-if="can('deliveries.create')" label="Ny leverans" icon="pi pi-plus" @click="openCreateOrder" />
                </template>
              </Toolbar>

              <DataTable :value="orders" size="small" paginator :rows="12" data-key="id" sort-field="createdAt" :sort-order="-1" responsive-layout="scroll">
                <Column field="mottagare" header="Mottagare" sortable>
                  <template #body="{ data }">
                    <div class="document-cell">
                      <strong>{{ data.mottagare }}</strong>
                      <span v-if="data.notes">{{ data.notes }}</span>
                    </div>
                  </template>
                </Column>
                <Column field="adress" header="Adress" sortable>
                  <template #body="{ data }">
                    <a v-if="mapsHref(data.adress)" class="cell-link" :href="mapsHref(data.adress)" target="_blank" rel="noopener noreferrer">
                      <i class="pi pi-map-marker"></i>
                      <span>{{ data.adress }}</span>
                    </a>
                    <span v-else>{{ data.adress }}</span>
                  </template>
                </Column>
                <Column field="tele" header="Telefon">
                  <template #body="{ data }">
                    <a v-if="phoneHref(data.tele)" class="cell-link" :href="phoneHref(data.tele)">
                      <i class="pi pi-phone"></i>
                      <span>{{ data.tele }}</span>
                    </a>
                    <span v-else class="muted">Saknas</span>
                  </template>
                </Column>
                <Column field="desiredDeliveryDate" header="Önskat" sortable>
                  <template #body="{ data }">
                    <span>{{ data.desiredDeliveryDate || '' }} {{ data.desiredDeliveryTime || '' }}</span>
                  </template>
                </Column>
                <Column field="status" header="Status" sortable>
                  <template #body="{ data }">
                    <Tag :value="statusLabels[data.status] || data.status" :severity="statusSeverity(data.status)" />
                  </template>
                </Column>
                <Column field="createdAt" header="Skapad" sortable>
                  <template #body="{ data }">
                    {{ formatDate(data.createdAt) }}
                  </template>
                </Column>
                <Column header="" style="width: 250px">
                  <template #body="{ data }">
                    <div class="actions table-actions">
                      <a v-if="phoneHref(data.tele)" class="icon-action" :href="phoneHref(data.tele)" aria-label="Ring">
                        <i class="pi pi-phone"></i>
                      </a>
                      <a v-if="mapsHref(data.adress)" class="icon-action" :href="mapsHref(data.adress)" target="_blank" rel="noopener noreferrer" aria-label="Öppna karta">
                        <i class="pi pi-map-marker"></i>
                      </a>
                      <Button v-if="can('deliveries.update')" icon="pi pi-pencil" text rounded aria-label="Redigera" @click="openEditOrder(data)" />
                      <Button v-if="can('deliveries.update_status')" icon="pi pi-play" text rounded severity="success" aria-label="Starta" @click="startOrder(data)" />
                      <Button v-if="can('deliveries.update_status')" icon="pi pi-check" text rounded severity="secondary" aria-label="Levererad" @click="setStatus(data, 'delivered')" />
                      <Button v-if="can('deliveries.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Ta bort" @click="deleteOrder(data)" />
                    </div>
                  </template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel value="users">
            <section class="panel">
              <Toolbar>
                <template #start>
                  <strong>Användare</strong>
                </template>
                <template #end>
                  <Button v-if="can('users.create')" label="Ny användare" icon="pi pi-user-plus" @click="openCreateUser" />
                </template>
              </Toolbar>

              <DataTable :value="users" size="small" paginator :rows="14" data-key="id" responsive-layout="scroll">
                <Column field="name" header="Namn" sortable />
                <Column field="email" header="E-post" sortable />
                <Column field="phone" header="Telefon" />
                <Column field="roleLabel" header="Roll" sortable>
                  <template #body="{ data }">{{ roleLabel(data) }}</template>
                </Column>
                <Column field="visibility" header="Synlighet" sortable>
                  <template #body="{ data }">
                    <Tag :value="data.visibility === 'online' ? 'Online' : 'Offline'" :severity="data.visibility === 'online' ? 'success' : 'secondary'" />
                  </template>
                </Column>
                <Column field="active" header="Aktiv" sortable>
                  <template #body="{ data }">
                    <Tag :value="data.active ? 'Ja' : 'Nej'" :severity="data.active ? 'success' : 'danger'" />
                  </template>
                </Column>
                <Column header="" style="width: 180px">
                  <template #body="{ data }">
                    <div class="actions">
                      <Button v-if="can('users.update')" icon="pi pi-pencil" text rounded aria-label="Redigera" @click="openEditUser(data)" />
                      <Button v-if="can('users.change_password')" icon="pi pi-key" text rounded severity="secondary" aria-label="Nytt lösenord" @click="resetPassword(data)" />
                      <Button v-if="can('users.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Ta bort" @click="deleteUser(data)" />
                    </div>
                  </template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>
        </TabPanels>
      </Tabs>
    </main>

    <Dialog v-model:visible="orderDialog" modal :header="editingOrderId ? 'Redigera leverans' : 'Ny leverans'" :style="{ width: 'min(920px, 96vw)' }">
      <form class="form-grid" @submit.prevent="saveOrder">
        <div class="field">
          <label for="mottagare">Mottagare</label>
          <InputText
            id="mottagare"
            v-model="orderForm.mottagare"
            list="recipient-suggestions"
            autocomplete="off"
            @change="applyRecipientSuggestion"
            @blur="applyRecipientSuggestion"
          />
          <datalist id="recipient-suggestions">
            <option
              v-for="suggestion in recipientSuggestions"
              :key="suggestion.value"
              :value="suggestion.value"
              :label="[suggestion.phone, suggestion.source].filter(Boolean).join(' · ')"
            />
          </datalist>
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
          <div class="input-with-icon">
            <InputText
              id="desiredDeliveryDate"
              v-model="orderForm.desiredDeliveryDate"
              type="date"
              class="picker-input"
              @click="openNativePicker"
              @focus="openNativePicker"
            />
            <i class="pi pi-calendar picker-icon"></i>
          </div>
        </div>
        <div class="field">
          <label for="desiredDeliveryTime">Tid</label>
          <div class="input-with-icon">
            <InputText
              id="desiredDeliveryTime"
              v-model="orderForm.desiredDeliveryTime"
              type="time"
              class="picker-input"
              @click="openNativePicker"
              @focus="openNativePicker"
            />
            <i class="pi pi-clock picker-icon"></i>
          </div>
        </div>
        <div class="field" style="grid-column: 1 / -1">
          <label for="notes">Anteckning</label>
          <Textarea id="notes" v-model="orderForm.notes" rows="3" auto-resize />
        </div>
        <div class="field" style="grid-column: 1 / -1">
          <label>Artiklar</label>
          <div v-for="(item, index) in orderForm.items" :key="index" class="item-row">
            <InputText v-model="item.artikel" placeholder="Artikel" />
            <InputText v-model="item.antal" placeholder="Antal" />
            <InputText v-model="item.workOrderNumber" placeholder="Arbetsorder" />
            <Button type="button" icon="pi pi-times" text rounded severity="danger" @click="removeItem(index)" />
          </div>
          <Button type="button" label="Lägg till rad" icon="pi pi-plus" severity="secondary" outlined @click="addItem" />
        </div>
      </form>

      <template #footer>
        <Button label="Avbryt" severity="secondary" outlined @click="orderDialog = false" />
        <Button label="Spara" icon="pi pi-save" :loading="orderForm.processing" @click="saveOrder" />
      </template>
    </Dialog>

    <Dialog v-model:visible="userDialog" modal :header="editingUserId ? 'Redigera användare' : 'Ny användare'" :style="{ width: 'min(720px, 96vw)' }">
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

    <Dialog v-model:visible="settingsDialog" modal header="Adminpanel" class="admin-dialog" :style="{ width: 'min(1180px, 96vw)' }">
      <Tabs v-model:value="adminTab" class="admin-tabs">
        <TabList>
          <Tab value="overview">Översikt</Tab>
          <Tab v-if="can('users.view')" value="users">Användare</Tab>
          <Tab v-if="can('roles.view')" value="roles">Roller</Tab>
          <Tab v-if="can('deliveries.view_all')" value="deliveries">Leveranser</Tab>
          <Tab v-if="can('work_orders.view')" value="workOrders">Arbetsordrar</Tab>
          <Tab v-if="can('logs.view')" value="logs">Loggar</Tab>
          <Tab v-if="can('settings.view')" value="settings">Inställningar</Tab>
          <Tab v-if="can('system.view_status')" value="system">System</Tab>
        </TabList>

        <TabPanels>
          <TabPanel value="overview">
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
                <Column field="method" header="Metod" />
                <Column field="path" header="Endpoint" />
                <Column field="module" header="Modul" />
                <Column field="status" header="Status">
                  <template #body="{ data }">
                    <Tag :value="data.status" severity="success" />
                  </template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel v-if="can('users.view')" value="users">
            <section class="panel admin-panel">
              <Toolbar>
                <template #start>
                  <strong>Användarhantering</strong>
                </template>
                <template #end>
                  <Button v-if="can('users.create')" label="Ny användare" icon="pi pi-user-plus" @click="openCreateUser" />
                </template>
              </Toolbar>
              <DataTable :value="users" size="small" paginator :rows="10" data-key="id" responsive-layout="scroll">
                <Column field="name" header="Namn" sortable />
                <Column field="email" header="E-post" sortable />
                <Column field="roleLabel" header="Roll/nivå" sortable>
                  <template #body="{ data }">{{ roleLabel(data) }}</template>
                </Column>
                <Column field="active" header="Status" sortable>
                  <template #body="{ data }">
                    <Tag :value="data.active ? 'Aktiv' : 'Inaktiv'" :severity="data.active ? 'success' : 'danger'" />
                  </template>
                </Column>
                <Column field="createdAt" header="Skapad" sortable>
                  <template #body="{ data }">{{ formatDate(data.createdAt) }}</template>
                </Column>
                <Column header="Åtgärder" style="width: 180px">
                  <template #body="{ data }">
                    <div class="actions">
                      <Button v-if="can('users.update')" icon="pi pi-pencil" text rounded aria-label="Redigera" @click="openEditUser(data)" />
                      <Button v-if="can('users.change_password')" icon="pi pi-key" text rounded severity="secondary" aria-label="Nytt lösenord" @click="resetPassword(data)" />
                      <Button v-if="can('users.delete')" icon="pi pi-trash" text rounded severity="danger" aria-label="Ta bort" @click="deleteUser(data)" />
                    </div>
                  </template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel v-if="can('roles.view')" value="roles">
            <section class="admin-section">
              <h3>Roller & behörigheter</h3>
              <DataTable :value="admin.roleMatrix" size="small" responsive-layout="scroll">
                <Column field="label" header="Roll" sortable />
                <Column field="level" header="Nivå" sortable />
                <Column field="description" header="Beskrivning" />
                <Column header="Behörigheter">
                  <template #body="{ data }">
                    <div class="permission-list">
                      <Tag
                        v-for="permission in data.allowedPermissions.slice(0, 8)"
                        :key="permission"
                        :value="permission"
                        severity="secondary"
                      />
                      <span v-if="data.allowedPermissions.length > 8" class="muted">+{{ data.allowedPermissions.length - 8 }} fler</span>
                    </div>
                  </template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel v-if="can('deliveries.view_all')" value="deliveries">
            <section class="admin-section">
              <h3>Leveranser</h3>
              <DataTable :value="orders" size="small" paginator :rows="10" data-key="id" responsive-layout="scroll">
                <Column field="mottagare" header="Mottagare" sortable />
                <Column field="adress" header="Adress" sortable />
                <Column field="tele" header="Telefon" />
                <Column field="status" header="Status" sortable>
                  <template #body="{ data }">
                    <Tag :value="statusLabels[data.status] || data.status" :severity="statusSeverity(data.status)" />
                  </template>
                </Column>
                <Column field="createdAt" header="Skapad" sortable>
                  <template #body="{ data }">{{ formatDate(data.createdAt) }}</template>
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

          <TabPanel v-if="can('work_orders.view')" value="workOrders">
            <section class="admin-section">
              <h3>Arbetsordrar</h3>
              <DataTable :value="admin.workOrders" size="small" paginator :rows="10" responsive-layout="scroll">
                <Column field="workOrderNumber" header="Arbetsorder" sortable />
                <Column field="recipientName" header="Mottagare" sortable />
                <Column field="deliveryAddress" header="Adress" />
                <Column field="status" header="Status" sortable />
                <Column field="receivedAt" header="Mottagen" sortable>
                  <template #body="{ data }">{{ formatDate(data.receivedAt) }}</template>
                </Column>
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel v-if="can('logs.view')" value="logs">
            <section class="admin-section">
              <div class="admin-section-head">
                <h3>Systemloggar</h3>
                <Button v-if="can('logs.export')" label="Exportera" icon="pi pi-download" severity="secondary" outlined @click="exportLogs" />
              </div>
              <div class="log-filters">
                <InputText v-model="logSearch" placeholder="Sök i loggar" />
                <Select v-model="logModule" :options="logModuleOptions" option-label="label" option-value="value" placeholder="Modul" show-clear />
                <Select v-model="logStatus" :options="logStatusOptions" option-label="label" option-value="value" placeholder="Status" show-clear />
              </div>
              <DataTable :value="filteredLogs" size="small" paginator :rows="12" responsive-layout="scroll">
                <Column field="time" header="Datum/tid" sortable />
                <Column field="user" header="Användare" sortable />
                <Column field="role" header="Roll" sortable />
                <Column field="event" header="Händelse" sortable />
                <Column field="module" header="Modul" sortable />
                <Column field="ip" header="IP-adress" />
                <Column field="status" header="Status" sortable>
                  <template #body="{ data }">
                    <Tag :value="data.status" :severity="data.status === 'error' || data.status === 'critical' ? 'danger' : 'secondary'" />
                  </template>
                </Column>
                <Column field="details" header="Detaljer" />
              </DataTable>
            </section>
          </TabPanel>

          <TabPanel v-if="can('settings.view')" value="settings">
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

          <TabPanel v-if="can('system.view_status')" value="system">
            <section class="admin-system-grid">
              <article class="admin-section">
                <h3>Systemstatus</h3>
                <dl class="status-list">
                  <div><dt>Miljö</dt><dd>{{ admin.systemStatus.environment }}</dd></div>
                  <div><dt>Laravel</dt><dd>{{ admin.systemStatus.laravelVersion }}</dd></div>
                  <div><dt>PHP</dt><dd>{{ admin.systemStatus.phpVersion }}</dd></div>
                  <div><dt>Broadcasting</dt><dd>{{ admin.systemStatus.broadcasting }}</dd></div>
                  <div><dt>Kö</dt><dd>{{ admin.systemStatus.queue }}</dd></div>
                  <div><dt>Databas</dt><dd>{{ admin.systemStatus.database }}</dd></div>
                </dl>
              </article>

              <article class="admin-section">
                <h3>Pushnotiser</h3>
                <DataTable :value="admin.pushSubscriptions" size="small" :rows="5" responsive-layout="scroll">
                  <Column field="user" header="Användare" />
                  <Column field="permission" header="Tillstånd" />
                  <Column field="enabled" header="Aktiv">
                    <template #body="{ data }">
                      <Tag :value="data.enabled ? 'Ja' : 'Nej'" :severity="data.enabled ? 'success' : 'secondary'" />
                    </template>
                  </Column>
                  <Column field="lastSeenAt" header="Senast sedd">
                    <template #body="{ data }">{{ formatDate(data.lastSeenAt) }}</template>
                  </Column>
                </DataTable>
              </article>

              <article class="admin-section">
                <h3>Tracking-länkar</h3>
                <DataTable :value="admin.trackingLinks" size="small" :rows="5" responsive-layout="scroll">
                  <Column field="orderId" header="Leverans" />
                  <Column field="active" header="Aktiv">
                    <template #body="{ data }">
                      <Tag :value="data.active ? 'Ja' : 'Nej'" :severity="data.active ? 'success' : 'secondary'" />
                    </template>
                  </Column>
                  <Column field="expiresAt" header="Går ut">
                    <template #body="{ data }">{{ formatDate(data.expiresAt) }}</template>
                  </Column>
                </DataTable>
              </article>

              <article class="admin-section">
                <h3>Artiklar</h3>
                <DataTable :value="admin.articles" size="small" :rows="5" responsive-layout="scroll">
                  <Column field="artikel" header="Artikel" />
                  <Column field="usageCount" header="Antal rader" />
                  <Column field="updatedAt" header="Senast använd">
                    <template #body="{ data }">{{ formatDate(data.updatedAt) }}</template>
                  </Column>
                </DataTable>
              </article>

              <article class="admin-section">
                <h3>Kunder/mottagare</h3>
                <DataTable :value="admin.customers" size="small" :rows="5" responsive-layout="scroll">
                  <Column field="name" header="Namn" />
                  <Column field="phone" header="Telefon" />
                  <Column field="address" header="Adress" />
                  <Column field="ordersCount" header="Leveranser" />
                </DataTable>
              </article>

              <article class="admin-section">
                <h3>Förare</h3>
                <DataTable :value="admin.drivers" size="small" :rows="5" responsive-layout="scroll">
                  <Column field="name" header="Namn" />
                  <Column field="email" header="E-post" />
                  <Column field="visibility" header="Synlighet">
                    <template #body="{ data }">
                      <Tag :value="data.visibility === 'online' ? 'Online' : 'Offline'" :severity="data.visibility === 'online' ? 'success' : 'secondary'" />
                    </template>
                  </Column>
                </DataTable>
              </article>
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

    <Dialog v-model:visible="tempPasswordVisible" modal header="Tillfälligt lösenord" :style="{ width: 'min(520px, 94vw)' }">
      <p class="mono">{{ flash.tempPassword }}</p>
      <template #footer>
        <Button label="Stäng" @click="tempPasswordVisible = false" />
      </template>
    </Dialog>
  </div>
</template>
