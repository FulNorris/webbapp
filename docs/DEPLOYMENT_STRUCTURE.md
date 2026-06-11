# Deploystruktur

Projektet ska hållas isär i tre delar:

1. Repository: källkod, låsfiler, migrations, tester, dokumentation och `.env.example`.
2. Releasepaket: byggt arkiv utan hemligheter, `node_modules`, cache, backuper eller lokala miljöer.
3. Runtime-data: `.env`, loggar, cache, backuper, importerade driftfiler och produktbilder på servern.

## Modell 2: färdig CI-byggd artefakt

Det här repositoryt använder Modell 2 som pragmatisk standard. CI bygger backend-dependencies och frontend-assets, tar bort `node_modules`, granskar innehållet och skickar en färdig releaseartefakt.

CI kör:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
rm -rf node_modules
bash scripts/build-release.sh
```

Releasepaketet innehåller:

- `app/`
- `bootstrap/` med tom `bootstrap/cache/.gitkeep`
- `config/`
- `database/`
- `public/` med `public/build`, men utan produktbildspegel
- `resources/` med aktiva Inertia/Vue-vyer och Blade-templates
- `routes/`
- tom `.gitkeep`-struktur under `storage/`
- `vendor/`
- `artisan`
- `composer.json` och `composer.lock`
- `package.json` och `package-lock.json`
- `vite.config.js`

Servern kör efter uppackning:

```bash
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Servern behöver inte Node/npm för deploy. Composer behövs inte för normal deploy om CI-artefakten redan innehåller `vendor/`.

Produktbilder hanteras separat från releasepaketet. Produktionsservern ska ha dem i:

```bash
/var/www/stuckbema/shared/produkter/
```

Deploy-scriptet symlänkar dem till:

```bash
/var/www/stuckbema/current/public/produkter
```

Det behåller befintliga URL:er under `/produkter/...` utan att göra releasepaket tunga.

Blockerat i repository och releasepaket:

- `.env`
- `.git/`
- `node_modules/`
- `gips-image-venv/`
- `gips-import-venv/`
- `storage/app/backups/`
- `storage/logs/`
- `bootstrap/cache/packages.php`
- `bootstrap/cache/services.php`
- `*.sql`, `*.sqlite`, `*.zip`, `*.tar`, `*.tar.gz`, `*.tgz`

Bygg releasepaket med:

```bash
bash scripts/build-release.sh
```

Deploya ett färdigt releasepaket på server med:

```bash
bash scripts/deploy-release.sh storage/app/releases/stuckbema-release-YYYYMMDD-HHMMSS.tar.gz
```

Serverstrukturen är:

```text
/var/www/stuckbema/
├── releases/
├── shared/
│   ├── .env
│   ├── storage/
│   └── produkter/
└── current -> releases/<release-id>
```

Granska en katalog eller ZIP manuellt med:

```bash
php artisan deploy:audit . --profile=repository
php artisan deploy:audit path/to/www.zip
```

`deploy:audit` ska gå grönt innan ett paket delas eller laddas upp.

Snabb kontroll av ett uppackat releasepaket:

```bash
find release -name ".env" -o -name ".git" -o -name "node_modules" -o -name "*.sql"
```

Kommandot ska inte returnera några filer.
