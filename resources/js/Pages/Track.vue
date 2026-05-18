<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
  tracking: {
    type: Object,
    default: null,
  },
});

function statusLabel(status) {
  return {
    created: 'Skapad',
    assigned: 'Tilldelad',
    ongoing: 'Pågår',
    paused: 'Pausad',
    delivered: 'Levererad',
    cancelled: 'Avbruten',
  }[status] || status;
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
  if (/android/i.test(agent)) return `geo:0,0?q=${query}`;
  if (/(iphone|ipad|ipod|macintosh)/i.test(agent)) return `https://maps.apple.com/?q=${query}`;

  return `https://www.google.com/maps/search/?api=1&query=${query}`;
}
</script>

<template>
  <Head title="Spårning" />

  <main class="login-page">
    <section class="login-shell">
      <div class="login-brand">
        <div class="brand-mark">S</div>
        <div>
          <h1 class="brand-title">Leveransspårning</h1>
          <p class="brand-subtitle">Stuckbema</p>
        </div>
      </div>

      <Card v-if="tracking">
        <template #content>
          <div class="form-grid single">
            <div>
              <div class="muted">Mottagare</div>
              <strong>{{ tracking.mottagare }}</strong>
            </div>
            <div>
              <div class="muted">Adress</div>
              <a v-if="mapsHref(tracking.adress)" class="cell-link" :href="mapsHref(tracking.adress)" target="_blank" rel="noopener noreferrer">
                <i class="pi pi-map-marker"></i>
                <strong>{{ tracking.adress }}</strong>
              </a>
              <strong v-else>{{ tracking.adress }}</strong>
            </div>
            <div v-if="tracking.tele">
              <div class="muted">Telefon</div>
              <a v-if="phoneHref(tracking.tele)" class="cell-link" :href="phoneHref(tracking.tele)">
                <i class="pi pi-phone"></i>
                <strong>{{ tracking.tele }}</strong>
              </a>
            </div>
            <div>
              <div class="muted">Status</div>
              <Tag :value="statusLabel(tracking.status)" />
            </div>
            <div v-if="tracking.currentLocation">
              <Button
                as="a"
                :href="`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${tracking.currentLocation.lat},${tracking.currentLocation.lng}`)}`"
                label="Öppna karta"
                icon="pi pi-map-marker"
              />
            </div>
          </div>
        </template>
      </Card>

      <Message v-else severity="warn" :closable="false">
        Spårningslänken hittades inte eller har gått ut.
      </Message>
    </section>
  </main>
</template>
