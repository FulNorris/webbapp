# Migration Plan

## Målarkitektur

- `client/`: React, TypeScript, Tailwind, Vite.
- `server/`: befintlig Express/PostgreSQL-backend, behålls och delas upp senare.
- `shared/`: gemensamma typer.
- `docs/`: inventering, cleanup och QA.

## Faser

1. Inventera och dokumentera legacy.
2. Lägg React-klient parallellt.
3. Flytta API-kontrakt till typed client.
4. Migrera autocomplete och orderformulär.
5. Migrera karta/tracking.
6. Migrera admin/login/notiser.
7. Kör cutover till ny webbklient först när funktionsparitet är testad.
8. Radera legacy endast efter build/test-verifiering.

## Cutover-kriterier

- `npm run client:build` passerar.
- `npm run client:typecheck` passerar.
- Autocomplete fungerar per fält.
- Karta renderar med stabil containerhöjd.
- Orderflöde fungerar på desktop och mobil.
- `npm run build`, `npm test` och relevanta viewporttester passerar efter byte till ny build.
