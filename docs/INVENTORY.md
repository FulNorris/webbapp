# Inventory

Datum: 2026-05-12

## Projektrot

`/opt/webbapp`

## Snapshot 2026-05-12

Helinventering inklusive vendor, `.git` och genererat:

- 10 519 filer.
- 1 220 mappar.
- Cirka 157 911 291 bytes apparent size.
- Största ytan är `node_modules/`, cirka 177 MB på disk.

Källkod och projektfiler när `.git`, `node_modules`, `dist`, `client/dist`, `playwright-report`, `test-results` och `server/generated` exkluderas:

- 89 filer.
- 28 mappar.
- Cirka 789 288 bytes apparent size.
- Cirka 14 161 kod-/konfigurationsrader i `src/`, `server/`, `client/src`, `scripts/` och `tests/`.

Filtyper i primär projekt-/källkodsyta:

- 31 JavaScript-filer.
- 10 TypeScript-filer.
- 6 TSX-filer.
- 14 Markdown-filer.
- 8 JSON-filer.
- 4 HTML-filer.
- 3 CSS-filer.
- 2 SQL-migrationer.

## Aktiv Struktur

- `src/`: aktiv legacy-frontend i Vanilla JavaScript.
- `index.html`: aktiv webbapp-entrypoint.
- `public/`: statiska webbassets som kopieras till `dist/`.
- `dist/`: genererad webb-build från `npm run build`.
- `server/`: aktiv Express/PostgreSQL-backend.
- `tests/`: Node- och Playwright-tester.
- `client/`: parallell React/TypeScript-klient under migrering.
- `docs/`: aktuell dokumentation.
- `node_modules/`: gemensam npm workspace-installation för root, `client/` och `server/`.

## Skyddad Delad Funktionalitet

- Responsiv CSS och mobil viewport-layout i `src/style.css`.
- Playwright viewporttester i `tests/viewport/`.
- PWA-manifest och webbikoner i `src/manifest.webmanifest` och `public/icons/`.
- Service worker och Web Push i `src/sw.js`, `src/push.js` och `server/notifications.js`.
- Browser geolocation för förarspårning i `src/app.js`.
- Publik trackingkarta i `src/track.html` och `src/track.js`.
- Backend-API, migrations och PostgreSQL-adapter i `server/`.

## Borttaget Mobilappspår

- `android/`
- `ios/`
- `resources/android/`
- `capacitor.config.ts`
- `.github/workflows/ios.yml`
- APK/IPA-skript i `scripts/`
- `src/downloads.html`
- Capacitor-paket och scripts i root `package.json`
- Native push-grenar för FCM/APNs och Capacitor Push Notifications

## Frontend-entrypoints

- `index.html`: huvudapp.
- `src/login.html`: login.
- `src/track.html`: publik tracking/karta.
- `src/app.js`: huvudlogik för leveranser, formulär, GPS, local state, PDF, notiser.
- `src/auth.js`: API-base, JWT, login/logout, refresh.
- `src/admin.js`: adminpanel.
- `src/push.js`: Web Push/notiser.
- `src/search/fieldAutocomplete.js`: fältspecifik autocomplete widget.
- `src/track.js`: publik livekarta.

## Backend-entrypoints

- `server/server.js`: Express-app och routes.
- `server/db.js`: PostgreSQL-adapter och datamappning.
- `server/permissions.js`: roll/permission-kontrakt.
- `server/notifications.js`: Web Push-service.
- `server/smsService.js`, `server/mailer.js`: externa kommunikationstjänster.

## Genererad Output

- `dist/*`
- `client/dist/*`
- `playwright-report/*`
- `test-results/*`
- `server/generated/*`

Dessa är inte primär källkod och kan regenereras via build/test.

## Dependency-Layout

Projektet använder npm workspaces:

- `stuckbema-client` -> `client/`
- `stuckbema-api` -> `server/`

Installera från repo-roten med `npm ci` eller `npm install`. Separata `client/node_modules` och `server/node_modules` ska inte skapas i normal utveckling eftersom de återskapar exakta dubbletter av tunga paket.

## Inventeringsbedömning

- `src/` är källkod och ska inte tas bort även om `dist/` ibland innehåller identiska kopior.
- `dist/` och `client/dist/` är byggoutput och kan återskapas.
- `.env`-filer är lokala hemligheter och är ignorerade av Git.
- `server/generated/` innehåller temporära driftfiler, exempelvis lösenordsreset-exporter, och ska inte följa med i distribution.
- `.git/` ska inte ingå i deploy-zip eller produktionsartefakt.
