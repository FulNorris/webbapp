# Master JavaScript-refaktor

## Beslut

Produktionsappen använder fortfarande `index.html` och `src/*.js` som huvudfrontend. React/TypeScript-klienten i `client/` finns kvar som migreringsspår, men den ersätter inte huvudappen ännu eftersom den inte täcker hela leveransflödet, admin, auth, tracking och mobilnotiser.

Den säkra refaktorn är därför:

- behåll fungerande backend och befintligt UI
- samla delad browserlogik i `src/core.js`
- kapsla sidkod i IIFE + strict mode
- använd gemensam event/timer-livscykel via `StuckbemaCore.createEventScope()`
- minska global DOM-manipulation utan att tappa funktion
- låt React/TypeScript-migreringen fortsätta modul för modul i `client/`

## Ändrat

- `src/core.js` skapades som gemensam browser-runtime.
- `index.html`, `src/login.html` och `src/track.html` laddar `core.js` före övriga scripts.
- `src/app.js` kapslades och använder gemensam DOM/event-scope för huvudlisteners.
- `src/login.js` kapslades och använder `ready`, `byId` och timer-cleanup.
- `src/track.js` kapslades och städar resize/SSE/timers på `pagehide`.
- `src/admin.js` kör strict mode och använder `byId`, `ready` och event-scope.
- `src/search/fieldAutocomplete.js` använder event delegation och korrekt cleanup.
- `src/auth.js` kör strict mode, använder core-normalisering och exponerar `window.StuckbemaAuth`.

## Kvar att migrera

Full React/TypeScript-migrering kräver att följande produktionsflöden byggs om i `client/` innan root-build kan pekas om:

- auth/login/password reset
- leveransformulär med artiklar
- orderlista och statusflöde
- adminpanel
- trackingkarta och förarposition
- notisinställningar
- PDF/export

Tills dessa är feature-kompletta ska root-build fortsätta använda den moderniserade legacy-frontenden.
