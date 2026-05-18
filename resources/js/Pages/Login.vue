<script setup>
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
  appName: {
    type: String,
    default: 'Stuckbema Leverans',
  },
});

const form = useForm({
  email: '',
  password: '',
});

function submit() {
  form.post('/login', {
    preserveScroll: true,
  });
}
</script>

<template>
  <Head title="Inloggning" />

  <main class="login-page">
    <section class="login-shell">
      <div class="login-brand">
        <div class="brand-mark">S</div>
        <div>
          <h1 class="brand-title">{{ appName }}</h1>
          <p class="brand-subtitle">Intern leveranshantering</p>
        </div>
      </div>

      <Card>
        <template #content>
          <form class="form-grid single" @submit.prevent="submit">
            <Message v-if="form.errors.email" severity="error" :closable="false">
              {{ form.errors.email }}
            </Message>

            <div class="field">
              <label for="email">E-post</label>
              <InputText
                id="email"
                v-model="form.email"
                type="email"
                autocomplete="username"
                autofocus
              />
            </div>

            <div class="field">
              <label for="password">Lösenord</label>
              <Password
                id="password"
                v-model="form.password"
                :feedback="false"
                toggle-mask
                autocomplete="current-password"
              />
            </div>

            <Button
              type="submit"
              label="Logga in"
              icon="pi pi-lock-open"
              :loading="form.processing"
            />
          </form>
        </template>
      </Card>
    </section>
  </main>
</template>
