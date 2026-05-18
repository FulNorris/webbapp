# Cleanup Report

Datum: 2026-05-12

## Borttaget

- Native Android-projekt: `android/`
- Native iOS-projekt: `ios/`
- Capacitor-konfiguration: `capacitor.config.ts`
- Android appresurser: `resources/android/`
- iOS/TestFlight workflow: `.github/workflows/ios.yml`
- APK/IPA-hjälpskript i `scripts/`
- Nedladdningssida för APK/IPA: `src/downloads.html`
- Native notisplan/debug-dokument: `FIX_MOBILE_NOTIFICATIONS_PLAN.md`, `NOTIFICATIONS_DEBUG.md`
- Historiska appbygg-loggar och inaktuella inventeringsrapporter i repo-roten

## Ändrat

- `package.json` och `package-lock.json`: Capacitor-beroenden och native scripts är borttagna.
- `src/push.js`: stöder endast Web Push i webbläsare.
- `server/notifications.js`: FCM/APNs-grenar är borttagna; Web Push är kvar.
- `server/server.js`: CORS och notisregistrering accepterar webb-origin och Web Push endpoint.
- `server/db.js`: pushplattform normaliseras till `web`.
- `src/config.js`, `src/environment.js`, `src/auth.js`: native/runtime-grenar och mobilapp-API-config är borttagna.
- `README.md`, `docs/INVENTORY.md`, `docs/QA_CHECKLIST.md`, `docs/MIGRATION_PLAN.md`: uppdaterade till webb-only.

## Skyddat

- Mobilanpassad webb-layout och viewporttester.
- PWA-manifest, webbikoner och service worker.
- Web Push och backend-notiser.
- Webbläsarbaserad geolocation för leveransspårning.
- Publik trackingkarta och orderflöden.
- Backend, migrations och API-kontrakt som används av webb/desktop.

## Resultat

Repo:t har inte längre native-appbygge, appbutiksflöde, APK/IPA-publicering eller Capacitor-runtime som del av källträdet. Webbappen körs vidare som PWA/webbapp med responsiv layout.
