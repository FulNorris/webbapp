# Technical language decision

## Beslut

Projektet behåller vanilla JavaScript i denna refactor-passering.

## Motivering

- Runtime är redan en enkel Vite/webbapp som kopierar `src/` till `dist/`.
- Backend kör direkt `node server.js` via systemd.
- En full TypeScript-migrering skulle kräva compiled backend output, ny systemd start path och bredare risk mot publicering.
- Projektet behöver först modulär uppdelning, bättre tester och tydligare API-kontrakt.

## Vald förbättring

- Lägg små JS-moduler under `src/search/`.
- Fortsätt med ESLint och Playwright.
- Dokumentera typade response-kontrakt i backend och tester.

## Nästa rimliga steg

- Inför JSDoc på nya moduler.
- Flytta ren logik från `src/app.js` till små JS-moduler.
- Utvärdera TypeScript först för ren logik, inte DOM- och systemd-kritisk backend.
