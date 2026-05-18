# QA Checklist

## Bas

- `npm install`
- `npm run build`
- `npm test`
- `npm run health`
- `npm run client:typecheck`
- `npm run client:build`
- `npm --workspace stuckbema-api run check`

## Backend

- `curl -fsS http://127.0.0.1:3001/api/health`
- Autocomplete endpoints kräver auth.
- Tom eller 1-teckens query returnerar inga resultat.
- Namn-endpoint returnerar bara namn.
- Email-endpoint returnerar bara email.
- Telefon-endpoint returnerar bara telefon.

## Karta

- Kartcontainer har synlig höjd på 360, 390, 414 och desktop.
- Ogiltig lat/lng ignoreras.
- Saknad position visar fallback.
- SSE/polling skapar inte parallella loopar.

## Mobil

- Formulär kan scrollas hela vägen ned.
- Bottom actions överlappar inte innehåll.
- Safe-area respekteras.
