# Stuckbema Leverans

Detta är en intern Laravel 12-applikation där Laravel sköter routing, sessioner och PostgreSQL-data. Webbgränssnittet renderas med Inertia, Vue 3 och PrimeVue, så den interna appen behöver inte manuella `fetch`-anrop, `JSON.parse` eller API-tokenhantering.

## Ingår

- Laravel 12-applikation med webbrutter i `routes/web.php`
- Inertia + Vue 3 + PrimeVue för intern inloggning, dashboard, leve-ranser, användare, inställningar och tracking-sidor
- Livewire + Laravel Reverb för livekartan på `/live-map`
- Web Push med VAPID-nycklar och service worker på `/sw.js`
- Sessionbaserad auth med Laravel CSRF-skydd
- API-rutter i `routes/api.php` finns kvar för externa integrationer och legacy-klienter
- PHP-controller för auth, användare, admin, leveranser, förare, spårning, push-subscriptions och externa arbetsordrar
- Databasmigrationer för users, orders, order_items, settings, people, tracking_links, push_subscriptions och external_work_orders
- Seeder med första admin-konto
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
5. Kör `php artisan stuckbema:import-arbetsordrar` när intern arbetsorderdata ska importeras.
6. Peka webbserverns document root till `public/`.
7. Proxy:a `/app/` till Reverb (`127.0.0.1:8080`) med WebSocket Upgrade headers.
8. Kör `stuckbema-reverb.service` eller motsvarande process för `php artisan reverb:start`.
9. Aktivera HTTPS/Cloudflare för geolocation, service worker, WebSockets och push.

## Interna arbetsordrar

Arbetsorderdata sparas normaliserat i PostgreSQL:

- `arbetsordrar` innehåller en post per internt arbetsordernummer.
- `arbetsorder_resurser` innehåller fasta resurser per arbetsorder.
- `arbetsorder_rader` innehåller artikelrader från arbetsbeskrivningen.
- Befintliga `orders` och `order_items` används fortsatt som leveranser och leveransrader. `order_items` har kopplingar till `arbetsordrar` och `arbetsorder_rader`.

Importera eller uppdatera arbetsordrar:

```bash
php artisan migrate
php artisan stuckbema:import-arbetsordrar
php artisan test
```

Kommandot läser standardfilen `database/seeders/data/arbetsordrar.txt`. Det är idempotent: befintlig arbetsorder uppdateras på `arbetsorder_nr`, och rader/resurser för samma arbetsorder raderas och återskapas vid import.

Leveransformulärets fält `Arbetsorder` sparas på leveransraden. När ett arbetsordernummer och en artikel anges försöker backend koppla raden till rätt arbetsorderrad och beräknar beställt, tidigare levererat, levererat totalt och kvar att leverera. Om artikeln inte matchar sparas leveransraden ändå med varningen `Artikeln hittades inte på arbetsordern`.

### Serverkrav

Installera PHP-tilläggen som används i produktion:

```bash
sudo apt update
sudo apt install php-gmp php-bcmath -y
sudo systemctl restart php*-fpm
sudo systemctl reload nginx
php -m | grep -E "gmp|bcmath"
```

`php-gmp` eller `php-bcmath` krävs av Web Push-krypteringen. Appen ska inte krascha om de saknas, men push blir långsammare eller kan misslyckas beroende på servermiljö.

### Broadcasting och Reverb

Standard i `.env.example` är:

```dotenv
BROADCAST_CONNECTION=log
```

Det gör att Laravel inte försöker starta Reverb/Pusher utan nycklar. Om livekarta/WebSockets ska köras aktivt, sätt:

```dotenv
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=local-app
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```

Efter ändring:

```bash
php artisan optimize:clear
php artisan config:cache
sudo systemctl restart stuckbema-reverb
```

### Databasrättigheter

Laravel-migrationer behöver äga eller få ändra tabellerna i PostgreSQL. Om du får `must be owner of table system_settings`, logga in som databasadmin och kör:

```sql
\c stuckbema
ALTER TABLE system_settings OWNER TO <db_user>;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO <db_user>;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO <db_user>;
GRANT ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA public TO <db_user>;
```

Byt `<db_user>` mot värdet i `DB_USERNAME`.

### Lösenordshashar

Nya användare och lösenordsbyten sparas med `Hash::make()`. Om databasen innehåller gamla klartextlösenord eller hashformat Laravel inte accepterar kan de repareras säkert:

```bash
php artisan users:repair-password-hashes --dry-run
php artisan users:repair-password-hashes
```

Kommandot sätter ett temporärt bcrypt-lösenord och markerar användaren för lösenordsbyte vid nästa inloggning.

### Driftkontroller

Kör utan äldre, borttagna Artisan-flaggor:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan route:list
php artisan migrate:status
php artisan config:cache
```

## Viktigt

Den interna webbappen använder Laravel-sessioner. API-tokenflödet finns endast kvar för externa system och äldre klienter som fortfarande använder `/api/...`.
