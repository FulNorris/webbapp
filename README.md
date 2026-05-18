# Stuckbema Leverans

Detta är en intern Laravel 12-applikation där Laravel sköter routing, sessioner och PostgreSQL-data. Webbgränssnittet renderas med Inertia, Vue 3 och PrimeVue, så den interna appen behöver inte manuella `fetch`-anrop, `JSON.parse` eller API-tokenhantering.

## Ingår

- Laravel 12-applikation med webbrutter i `routes/web.php`
- Inertia + Vue 3 + PrimeVue för intern inloggning, dashboard, leveranser, användare, inställningar och tracking-sidor
- Livewire + Laravel Reverb för livekartan på `/live-map`
- Web Push med VAPID-nycklar och service worker på `/sw.js`
- Sessionbaserad auth med Laravel CSRF-skydd
- API-rutter i `routes/api.php` finns kvar för externa integrationer och legacy-klienter
- PHP-controller för auth, användare, admin, leveranser, förare, spårning, push-subscriptions och externa arbetsordrar
- Databasmigrationer för users, orders, order_items, settings, people, tracking_links, push_subscriptions och external_work_orders
- Seeder med första admin-konto
- Originalkällor sparade i `resources/original/`
- Dokumentation sparad i `docs/`

## Starta lokalt

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run build
php artisan serve
php artisan reverb:start
```

Logga in med:

- E-post: `admin@example.test`
- Lösenord: `ChangeMe123!`

Byt lösenord direkt efter första inloggning.

## Produktion

1. Sätt `APP_URL`, `PUBLIC_APP_URL`, databasvariabler och säkra hemligheter i `.env`.
2. Kör `composer install --no-dev --optimize-autoloader` och `npm install`.
3. Kör `npm run build`.
4. Kör `php artisan migrate --force`.
5. Peka webbserverns document root till `public/`.
6. Proxy:a `/app/` till Reverb (`127.0.0.1:8080`) med WebSocket Upgrade headers.
7. Kör `stuckbema-reverb.service` eller motsvarande process för `php artisan reverb:start`.
8. Aktivera HTTPS/Cloudflare för geolocation, service worker, WebSockets och push.

## Viktigt

Den interna webbappen använder Laravel-sessioner. API-tokenflödet finns endast kvar för externa system och äldre klienter som fortfarande använder `/api/...`.
