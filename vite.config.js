import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';

export default defineConfig({
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (id.includes('node_modules/@primeuix') || id.includes('node_modules/primeicons')) {
            return 'primevue-theme';
          }

          if (id.includes('node_modules/primevue/datatable') || id.includes('node_modules/primevue/column')) {
            return 'primevue-table';
          }

          if (id.includes('node_modules/primevue/datepicker') || id.includes('node_modules/primevue/dialog') || id.includes('node_modules/primevue/menu')) {
            return 'primevue-overlays';
          }

          if (id.includes('node_modules/primevue')) {
            return 'primevue-core';
          }

          if (id.includes('node_modules/leaflet') || id.includes('node_modules/laravel-echo') || id.includes('node_modules/pusher-js')) {
            return 'realtime-map';
          }

          if (id.includes('node_modules/@inertiajs') || id.includes('node_modules/vue')) {
            return 'vue-inertia';
          }
        },
      },
    },
  },
  plugins: [
    laravel({
      input: ['resources/js/app.js', 'resources/js/livewire.js'],
      refresh: true,
    }),
    vue(),
  ],
});
